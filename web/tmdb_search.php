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
$endpoint = 'https://api.themoviedb.org/3/search/multi';
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

// Client-side filtering by year: parse best available year per item.
// - movie: release_date
// - tv: first_air_date
// - person/others: no date -> no year, cannot match
if ($year !== null) {
    $results = array_values(array_filter($results, function ($item) use ($year) {
        if (!is_array($item)) {
            return false;
        }
        $mediaType = isset($item['media_type']) ? (string)$item['media_type'] : '';

        $dateStr = '';
        if ($mediaType === 'movie') {
            $dateStr = isset($item['release_date']) ? (string)$item['release_date'] : '';
        } elseif ($mediaType === 'tv') {
            $dateStr = isset($item['first_air_date']) ? (string)$item['first_air_date'] : '';
        } else {
            // For person/others we can't reliably match year; exclude when year is requested.
            return false;
        }

        if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $dateStr, $m)) {
            return ((int)$m[1]) === $year;
        }
        // If date missing/unparseable, exclude when filtering by year.
        return false;
    }));
}

// Keep legacy output mapping:
// return array of simplified results, and include debug output.
$out = [];
foreach ($results as $r) {
    if (!is_array($r)) continue;

    $mediaType = isset($r['media_type']) ? (string)$r['media_type'] : '';

    // Legacy mapping fields
    $title = '';
    $originalTitle = '';
    $date = '';

    if ($mediaType === 'movie') {
        $title = isset($r['title']) ? (string)$r['title'] : '';
        $originalTitle = isset($r['original_title']) ? (string)$r['original_title'] : $title;
        $date = isset($r['release_date']) ? (string)$r['release_date'] : '';
    } elseif ($mediaType === 'tv') {
        $title = isset($r['name']) ? (string)$r['name'] : '';
        $originalTitle = isset($r['original_name']) ? (string)$r['original_name'] : $title;
        $date = isset($r['first_air_date']) ? (string)$r['first_air_date'] : '';
    } else {
        // Keep legacy behavior: skip non movie/tv
        continue;
    }

    $out[] = [
        'id' => isset($r['id']) ? $r['id'] : null,
        'type' => $mediaType,
        'title' => $title,
        'original_title' => $originalTitle,
        'overview' => isset($r['overview']) ? (string)$r['overview'] : '',
        'poster_path' => isset($r['poster_path']) ? (string)$r['poster_path'] : null,
        'backdrop_path' => isset($r['backdrop_path']) ? (string)$r['backdrop_path'] : null,
        'vote_average' => isset($r['vote_average']) ? $r['vote_average'] : null,
        'vote_count' => isset($r['vote_count']) ? $r['vote_count'] : null,
        'popularity' => isset($r['popularity']) ? $r['popularity'] : null,
        'date' => $date,
    ];
}

echo json_encode([
    'results' => $out,
    'debug' => $debug,
]);
