<?php
/**
 * TheTVDB v4 search endpoint proxy.
 *
 * Env vars:
 *   - TVDB_API_KEY
 *   - TVDB_PIN
 *
 * Output:
 *   JSON: {"data": [...]}
 *
 * Query params:
 *   - query (string) required
 *   - type (string) optional (movie|series|episode|person|company|all)
 *   - year (int) optional (kept for filtering)
 *   - debug=1 to include debugging info
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/**
 * Send JSON response and exit.
 */
function respond(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Minimal JSON HTTP client using cURL.
 *
 * @return array{status:int, headers:array<string,string>, body:mixed, raw:string, error:?string}
 */
function http_json(string $method, string $url, array $headers = [], $jsonBody = null, int $timeout = 20): array {
    $ch = curl_init($url);
    if ($ch === false) {
        return ['status' => 0, 'headers' => [], 'body' => null, 'raw' => '', 'error' => 'curl_init failed'];
    }

    $method = strtoupper($method);

    $curlHeaders = [];
    foreach ($headers as $k => $v) {
        if (is_int($k)) {
            $curlHeaders[] = $v;
        } else {
            $curlHeaders[] = $k . ': ' . $v;
        }
    }

    $rawBody = null;
    if ($jsonBody !== null) {
        $rawBody = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $curlHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    }

    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'headers' => [], 'body' => null, 'raw' => '', 'error' => $err];
    }

    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerText = substr($resp, 0, $headerSize);
    $bodyText = substr($resp, $headerSize);

    // Parse last header block (after redirects)
    $headerLines = preg_split('/\r\n|\n|\r/', trim($headerText));
    foreach ($headerLines as $line) {
        if ($line === '' || stripos($line, 'HTTP/') === 0) {
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            $respHeaders[$name] = $value;
        }
    }

    $decoded = null;
    $trim = ltrim($bodyText);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        $decoded = json_decode($bodyText, true);
    }

    return [
        'status' => $status,
        'headers' => $respHeaders,
        'body' => $decoded,
        'raw' => $bodyText,
        'error' => null,
    ];
}

/**
 * Read env var with fallback.
 */
function env_or(string $key, string $fallback): string {
    $v = getenv($key);
    if ($v === false) {
        return $fallback;
    }
    $v = trim($v);
    return $v !== '' ? $v : $fallback;
}

/**
 * Token cache path (in sys temp).
 */
function tvdb_token_cache_file(): string {
    $key = env_or('TVDB_API_KEY', '');
    $pin = env_or('TVDB_PIN', '');
    $suffix = substr(sha1($key . '|' . $pin), 0, 12);
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tvdb_v4_token_' . $suffix . '.json';
}

/**
 * Get a cached token or login to TVDB v4.
 *
 * TVDB v4 auth docs use /v4/login with body {apikey, pin}.
 * Response usually {"data": {"token": "..."}}
 */
function get_tvdb_token(bool $debug = false, array &$debugInfo = []): string {
    $apiKey = env_or('TVDB_API_KEY', '');
    $pin = env_or('TVDB_PIN', '');

    // Fallback to existing hardcoded values if env not set.
    // NOTE: These can be replaced/removed later.
    if ($apiKey === '') {
        $apiKey = 'YOUR_TVDB_API_KEY';
    }
    if ($pin === '') {
        $pin = 'YOUR_TVDB_PIN';
    }

    $cacheFile = tvdb_token_cache_file();
    $now = time();

    // Read cache
    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $cache = json_decode($raw, true);
            if (is_array($cache) && !empty($cache['token']) && !empty($cache['expires_at'])) {
                if ((int)$cache['expires_at'] > ($now + 60)) { // 60s safety margin
                    if ($debug) {
                        $debugInfo['token_cache'] = ['hit' => true, 'file' => $cacheFile, 'expires_at' => (int)$cache['expires_at']];
                    }
                    return (string)$cache['token'];
                }
                if ($debug) {
                    $debugInfo['token_cache'] = ['hit' => false, 'reason' => 'expired', 'file' => $cacheFile, 'expires_at' => (int)$cache['expires_at']];
                }
            } elseif ($debug) {
                $debugInfo['token_cache'] = ['hit' => false, 'reason' => 'invalid_cache', 'file' => $cacheFile];
            }
        } elseif ($debug) {
            $debugInfo['token_cache'] = ['hit' => false, 'reason' => 'read_failed', 'file' => $cacheFile];
        }
    } elseif ($debug) {
        $debugInfo['token_cache'] = ['hit' => false, 'reason' => 'no_file', 'file' => $cacheFile];
    }

    // Login
    $url = 'https://api4.thetvdb.com/v4/login';
    $resp = http_json('POST', $url, [], ['apikey' => $apiKey, 'pin' => $pin], 20);

    if ($debug) {
        $debugInfo['login_request'] = ['url' => $url];
        $debugInfo['login_response'] = [
            'status' => $resp['status'],
            'error' => $resp['error'],
            'body' => $resp['body'] ?? null,
            'raw' => $resp['body'] === null ? $resp['raw'] : null,
        ];
    }

    if ($resp['error'] !== null || $resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException('TVDB login failed');
    }

    $token = null;
    if (is_array($resp['body'])) {
        if (isset($resp['body']['data']['token'])) {
            $token = $resp['body']['data']['token'];
        } elseif (isset($resp['body']['token'])) {
            $token = $resp['body']['token'];
        }
    }

    if (!is_string($token) || $token === '') {
        throw new RuntimeException('TVDB login returned no token');
    }

    // TVDB v4 tokens are JWTs; exp is embedded. If we can decode exp, use it; otherwise default 23h.
    $expiresAt = $now + 23 * 3600;
    $parts = explode('.', $token);
    if (count($parts) === 3) {
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payloadJson !== false) {
            $payload = json_decode($payloadJson, true);
            if (is_array($payload) && isset($payload['exp']) && is_numeric($payload['exp'])) {
                $expiresAt = (int)$payload['exp'];
            }
        }
    }

    $cacheData = ['token' => $token, 'expires_at' => $expiresAt, 'cached_at' => $now];
    @file_put_contents($cacheFile, json_encode($cacheData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($debug) {
        $debugInfo['token_cache_write'] = ['file' => $cacheFile, 'expires_at' => $expiresAt];
    }

    return $token;
}

// ---- Request handling ----

$query = '';
if (isset($_GET['query'])) {
    $query = trim((string)$_GET['query']);
} elseif (isset($_GET['q'])) {
    $query = trim((string)$_GET['q']);
}
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';

if ($query === '') {
    respond(['data' => [], 'error' => 'Missing query parameter'], 400);
}

$debugInfo = [];

try {
    $token = get_tvdb_token($debug, $debugInfo);

    $params = ['query' => $query];
    // Pass through type parameter
    if ($type !== '') {
        $params['type'] = $type;
    }

    $url = 'https://api4.thetvdb.com/v4/search?' . http_build_query($params);

    $resp = http_json('GET', $url, [
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json',
    ], null, 20);

    if ($debug) {
        $debugInfo['search_request'] = ['url' => $url, 'params' => $params];
        $debugInfo['search_response'] = [
            'status' => $resp['status'],
            'error' => $resp['error'],
            'body' => $resp['body'] ?? null,
            'raw' => $resp['body'] === null ? $resp['raw'] : null,
        ];
    }

    if ($resp['error'] !== null) {
        respond(['data' => [], 'error' => 'TVDB request error', 'debug' => $debug ? $debugInfo : null], 502);
    }

    if ($resp['status'] === 401 || $resp['status'] === 403) {
        // Token may have been revoked; drop cache and retry once.
        $cacheFile = tvdb_token_cache_file();
        @unlink($cacheFile);
        if ($debug) {
            $debugInfo['retry'] = ['reason' => 'unauthorized', 'cache_deleted' => $cacheFile];
        }

        $token = get_tvdb_token($debug, $debugInfo);
        $resp = http_json('GET', $url, [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ], null, 20);

        if ($debug) {
            $debugInfo['search_response_retry'] = [
                'status' => $resp['status'],
                'error' => $resp['error'],
                'body' => $resp['body'] ?? null,
                'raw' => $resp['body'] === null ? $resp['raw'] : null,
            ];
        }
    }

    if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['body'])) {
        respond(['data' => [], 'error' => 'TVDB returned non-OK response', 'debug' => $debug ? $debugInfo : null], 502);
    }

    $data = $resp['body']['data'] ?? [];
    if (!is_array($data)) {
        $data = [];
    }

    // Keep year filtering behavior
    if ($year > 0) {
        $data = array_values(array_filter($data, function ($item) use ($year) {
            if (!is_array($item)) {
                return false;
            }
            // Try common fields
            foreach (['year', 'firstAired', 'releaseDate', 'premiereDate'] as $k) {
                if (!isset($item[$k]) || $item[$k] === null || $item[$k] === '') {
                    continue;
                }
                $v = $item[$k];
                if (is_numeric($v)) {
                    return (int)$v === $year;
                }
                if (is_string($v) && preg_match('/^(\d{4})/', $v, $m)) {
                    return (int)$m[1] === $year;
                }
            }
            return true; // if we cannot determine year, keep it
        }));
    }

// Map TVDB image fields to legacy 'image' used by frontend
foreach ($data as &$item) {
    if (!is_array($item)) continue;

    // Prefer thumbnail when present; otherwise image_url
    if (!isset($item['image']) || $item['image'] === '') {
        if (isset($item['thumbnail']) && is_string($item['thumbnail']) && $item['thumbnail'] !== '') {
            $item['image'] = $item['thumbnail'];
        } elseif (isset($item['image_url']) && is_string($item['image_url']) && $item['image_url'] !== '') {
            $item['image'] = $item['image_url'];
        }
    }
}
unset($item);
    
    $out = ['data' => $data];
    if ($debug) {
        $out['debug'] = $debugInfo;
    }
    respond($out);

} catch (Throwable $e) {
    $payload = ['data' => [], 'error' => 'Internal error'];
    if ($debug) {
        $debugInfo['exception'] = ['message' => $e->getMessage(), 'type' => get_class($e)];
        $payload['debug'] = $debugInfo;
    }
    respond($payload, 500);
}
