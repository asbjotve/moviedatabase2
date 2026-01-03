<?php
// TMDB search proxy
// Keeps legacy output mapping and debug output.

header('Content-Type: application/json; charset=utf-8');

$apiKey = getenv('TMDB_API_KEY');
if (!$apiKey) {
    // Legacy debug style error
    echo json_encode([
        'error' => 'TMDB_API_KEY not configured',
        'debug' => [
            'env' => 'TMDB_API_KEY missing'
        ]
    ]);
    exit;
}

// Accept query parameters (legacy)
$query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$language = isset($_GET['language']) ? trim((string)$_GET['language']) : (isset($_GET['lang']) ? trim((string)$_GET['lang']) : 'en-US');

// Honor year parameter from query string.
// Accept common aliases to be tolerant (year / y)
$yearParamRaw = null;
if (isset($_GET['year'])) {
    $yearParamRaw = (string)$_GET['year'];
} elseif (isset($_GET['y'])) {
    $yearParamRaw = (string)$_GET['y'];
}

$year = null;
if ($yearParamRaw !== null) {
    $yearParamRaw = trim($yearParamRaw);
    // Only accept a 4-digit year in a reasonable range
    if (preg_match('/^(19\d{2}|20\d{2}|2100)$/', $yearParamRaw)) {
        $year = (int)$yearParamRaw;
    }
}

// Build TMDB request
$endpoint = 'https://api.themoviedb.org/3/search/movie';
$params = [
    'api_key' => $apiKey,
    'query' => $query,
    'language' => $language,
    // Always exclude adult content
    'include_adult' => 'false',
];

// Pass year through to TMDB.
// Different media types interpret these differently; we send both to maximize compatibility.
if ($year !== null) {
    $params['year'] = (string)$year;
    $params['primary_release_year'] = (string)$year;
}

$url = $endpoint . '?' . http_build_query($params);

$debug = [
    'tmdb_url' => $url,
    'query' => $query,
    'language' => $language,
    'year' => $year,
    'include_adult' => false,
];

// Execute request
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$responseBody = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$debug['http_code'] = $httpCode;
if ($curlErr) {
    $debug['curl_error'] = $curlErr;
}

$decoded = null;
if ($responseBody !== false) {
    $decoded = json_decode($responseBody, true);
}

if (!is_array($decoded)) {
    // Legacy debug style error
    echo json_encode([
        'error' => 'Invalid response from TMDB',
        'debug' => array_merge($debug, [
            'raw' => $responseBody,
        ])
    ]);
    exit;
}

$results = isset($decoded['results']) && is_array($decoded['results']) ? $decoded['results'] : [];

// Map results to legacy fields expected by frontend
$mapped = [];
foreach ($results as $r) {
    if (!is_array($r)) continue;

    $releaseDate = isset($r['release_date']) ? (string)$r['release_date'] : '';
    $yearStr = '';
    if (preg_match('/^(\\d{4})-/', $releaseDate, $m)) {
        $yearStr = $m[1];
    }

    $posterPath = isset($r['poster_path']) ? (string)$r['poster_path'] : '';
    $image = $posterPath !== '' ? ('https://image.tmdb.org/t/p/w200' . $posterPath) : '';

    // If year filter is requested, enforce it here too (extra safety)
    if ($year !== null && $yearStr !== (string)$year) {
        continue;
    }

    $mapped[] = [
        'id' => $r['id'] ?? null,
        'title' => $r['title'] ?? '',
        'year' => $yearStr,
        'image' => $image,
        'overview' => $r['overview'] ?? '',
        'original_title' => $r['original_title'] ?? '',
        'vote_average' => $r['vote_average'] ?? null,
        'vote_count' => $r['vote_count'] ?? null,
        'popularity' => $r['popularity'] ?? null,
    ];
}

$out = [
    'data' => $mapped,
];

if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    $out['_debug'] = $debug;
}

echo json_encode($out);
