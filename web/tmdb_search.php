<?php
/**
 * TMDB movie search proxy.
 *
 * Legacy response shape:
 *   {"data": [ { id, title, year, image, overview, ... } ] }
 *
 * Supports debug=1 to output additional diagnostic information.
 */

header('Content-Type: application/json; charset=utf-8');

$debug = isset($_GET['debug']) && ($_GET['debug'] === '1' || strtolower((string)$_GET['debug']) === 'true');

// Query parameter (support a few common names for backwards compatibility)
$q = '';
if (isset($_GET['q'])) {
    $q = trim((string)$_GET['q']);
} elseif (isset($_GET['query'])) {
    $q = trim((string)$_GET['query']);
} elseif (isset($_GET['term'])) {
    $q = trim((string)$_GET['term']);
}

if ($q === '') {
    $out = [ 'data' => [] ];
    if ($debug) {
        $out['_debug'] = [
            'error' => 'Missing query parameter (q/query/term).',
            'params' => $_GET,
        ];
    }
    echo json_encode($out);
    exit;
}

// TMDB API key: expect it in environment variable TMDB_API_KEY.
// If the project uses another mechanism, adapt accordingly.
$apiKey = getenv('TMDB_API_KEY');
if ($apiKey === false || $apiKey === '') {
    $out = [ 'data' => [] ];
    if ($debug) {
        $out['_debug'] = [
            'error' => 'TMDB_API_KEY not configured in environment.',
        ];
    }
    echo json_encode($out);
    exit;
}

// Use TMDB v3 search movie endpoint
$endpoint = 'https://api.themoviedb.org/3/search/movie';

$params = [
    'api_key' => $apiKey,
    'query'   => $q,
    // Optional language; keep if provided by client
];
if (isset($_GET['language']) && trim((string)$_GET['language']) !== '') {
    $params['language'] = trim((string)$_GET['language']);
}
if (isset($_GET['page']) && is_numeric($_GET['page'])) {
    $params['page'] = (int)$_GET['page'];
}

$url = $endpoint . '?' . http_build_query($params);

// Fetch
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
$raw = curl_exec($ch);
$curlErrNo = curl_errno($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = null;
if ($raw !== false) {
    $data = json_decode($raw, true);
}

$results = [];
if (is_array($data) && isset($data['results']) && is_array($data['results'])) {
    foreach ($data['results'] as $r) {
        if (!is_array($r)) continue;

        $releaseDate = isset($r['release_date']) ? (string)$r['release_date'] : '';
        $year = '';
        if ($releaseDate !== '' && strlen($releaseDate) >= 4) {
            $year = substr($releaseDate, 0, 4);
        }

        $posterPath = isset($r['poster_path']) ? (string)$r['poster_path'] : '';
        $image = '';
        if ($posterPath !== '' && $posterPath !== 'null') {
            $image = 'https://image.tmdb.org/t/p/w200' . $posterPath;
        }

        // Map into legacy-ish fields used by existing frontend/backend
        $results[] = [
            'id' => isset($r['id']) ? $r['id'] : null,
            'title' => isset($r['title']) ? $r['title'] : (isset($r['name']) ? $r['name'] : ''),
            'year' => $year,
            'image' => $image,
            'overview' => isset($r['overview']) ? $r['overview'] : '',
            // Keep some extra fields for compatibility (harmless if unused)
            'original_title' => isset($r['original_title']) ? $r['original_title'] : '',
            'popularity' => isset($r['popularity']) ? $r['popularity'] : null,
            'vote_average' => isset($r['vote_average']) ? $r['vote_average'] : null,
            'vote_count' => isset($r['vote_count']) ? $r['vote_count'] : null,
        ];
    }
}

$out = [ 'data' => $results ];

if ($debug) {
    $out['_debug'] = [
        'endpoint' => $endpoint,
        'url' => $url,
        'http_code' => $httpCode,
        'curl_errno' => $curlErrNo,
        'curl_error' => $curlErr,
        'raw' => $raw,
        'decoded' => $data,
    ];
}

echo json_encode($out);
