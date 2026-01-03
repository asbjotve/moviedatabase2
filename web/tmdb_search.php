<?php

// Keep existing headers/functionality; ensure we always return valid JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/**
 * Fetch JSON over HTTP and decode it.
 * Uses cURL (with sensible defaults) and returns an array/object.
 * On any error, returns null.
 */
function http_json(string $url, array $headers = [], int $timeout = 10)
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    $defaultHeaders = [
        'Accept: application/json',
    ];

    // Merge headers, allow caller to override default by providing same header name.
    $allHeaders = array_merge($defaultHeaders, $headers);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $allHeaders,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        // Ensure we don't print anything directly
        CURLOPT_FAILONERROR => false,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $errno !== 0) {
        return null;
    }

    // Any non-2xx should be treated as failure unless it still provides parseable JSON.
    $decoded = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    // If API returns an error body but still JSON, keep it; caller can decide.
    // However, if HTTP status indicates failure and body is empty, treat as null.
    if (($status < 200 || $status >= 300) && empty($decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * Output JSON and exit cleanly.
 */
function respond_json($payload, int $httpStatus = 200): void
{
    if (!headers_sent()) {
        http_response_code($httpStatus);
    }

    // Always output valid JSON
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // Fallback payload if encoding fails
        $json = json_encode([
            'ok' => false,
            'error' => 'Failed to encode JSON response',
        ]);
        if (!headers_sent()) {
            http_response_code(500);
        }
    }

    echo $json;
    exit;
}

// ---- Existing script logic (preserved/compatible) ----

$apiKey = "43e59c66937eff8f235c170a389fa3d1";
if (!$apiKey) {
    // Keep headers; ensure valid JSON and proper exit
    respond_json([
        'ok' => false,
        'error' => 'Missing TMDB_API_KEY',
    ], 500);
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if ($q === '') {
    respond_json([
        'ok' => true,
        'results' => [],
    ]);
}

$lang = isset($_GET['lang']) ? trim((string)$_GET['lang']) : '';
if ($lang === '') {
    $lang = 'en-US';
}

// TMDb search endpoint
$url = 'https://api.themoviedb.org/3/search/movie?api_key=' . rawurlencode($apiKey)
    . '&query=' . rawurlencode($q)
    . '&language=' . rawurlencode($lang);

$data = http_json($url);
if ($data === null) {
    respond_json([
        'ok' => false,
        'error' => 'Failed to fetch data from TMDb',
        'results' => [],
    ], 502);
}

// Normalize output to always be JSON and preserve expected fields
// Keep existing functionality: pass through TMDb response, but ensure a consistent envelope.
if (isset($data['results']) && is_array($data['results'])) {
    respond_json([
        'ok' => true,
        'results' => $data['results'],
        'tmdb' => $data,
    ]);
}

respond_json([
    'ok' => true,
    'results' => [],
    'tmdb' => $data,
]);
