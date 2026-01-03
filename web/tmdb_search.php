<?php
// TMDB movie search endpoint (legacy JSON format: {"data": [...]})
// - No config.php dependency
// - Uses $_GET['q'] and optional $_GET['year']
// - Calls TMDB v3 search/movie
// - Returns valid JSON for all error cases and exits

header('Content-Type: application/json; charset=utf-8');

/**
 * Output JSON and exit.
 */
function respond($payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Fetch and decode JSON via cURL.
 *
 * @return array{ok:bool,status:int,error?:string,body?:string,json?:mixed}
 */
function http_json(string $url, int $timeoutSeconds = 10): array {
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'Failed to initialize cURL'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'moviedatabase2-tmdb-search/1.0',
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $errstr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => $status ?: 0, 'error' => $errno ? ($errstr ?: ('cURL error ' . $errno)) : 'Request failed'];
    }

    $json = json_decode($body, true);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'status' => $status ?: 0, 'error' => 'Invalid JSON response', 'body' => $body];
    }

    return ['ok' => ($status >= 200 && $status < 300), 'status' => $status, 'json' => $json, 'body' => $body];
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$year = isset($_GET['year']) ? trim((string)$_GET['year']) : '';

if ($q === '') {
    respond(['data' => []]);
}

// Prefer environment variable; fall back to existing hardcoded key.
$apiKey = getenv('TMDB_API_KEY');
if ($apiKey === false || trim($apiKey) === '') {
    // Legacy hardcoded key (kept for backward compatibility with previous config.php usage)
    $apiKey = '2a4d9a050d6d63fce7fc42f4ab06b3ea';
}

$params = [
    'api_key' => $apiKey,
    'query' => $q,
];

// Optional year filter.
if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
    $params['year'] = $year;
}

$url = 'https://api.themoviedb.org/3/search/movie?' . http_build_query($params);

$res = http_json($url);
if (!$res['ok'] || !is_array($res['json'] ?? null)) {
    // Always return valid legacy JSON.
    respond(['data' => []]);
}

$results = $res['json']['results'] ?? [];
if (!is_array($results)) {
    respond(['data' => []]);
}

$data = [];
foreach ($results as $item) {
    if (!is_array($item)) {
        continue;
    }

    $posterPath = isset($item['poster_path']) && is_string($item['poster_path']) ? $item['poster_path'] : '';
    $image = $posterPath !== '' ? ('https://image.tmdb.org/t/p/w200' . $posterPath) : '';

    // Legacy-ish fields: keep broadly compatible and include the requested 'image' field.
    $data[] = [
        'id' => $item['id'] ?? null,
        'title' => $item['title'] ?? ($item['name'] ?? ''),
        'original_title' => $item['original_title'] ?? '',
        'release_date' => $item['release_date'] ?? '',
        'year' => (isset($item['release_date']) && is_string($item['release_date']) && strlen($item['release_date']) >= 4)
            ? substr($item['release_date'], 0, 4)
            : '',
        'overview' => $item['overview'] ?? '',
        'vote_average' => $item['vote_average'] ?? null,
        'image' => $image,
    ];
}

respond(['data' => $data]);
