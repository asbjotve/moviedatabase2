<?php
// Backend for TheTVDB v4 søk med årstall-filtrering
header('Content-Type: application/json; charset=utf-8');

$TVDB_API_KEY = '06b81317-4b49-4086-8753-3f2c59ff0479';
$TVDB_PIN     = 'NIGZGZ2D';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : '';
$year = isset($_GET['year']) ? trim($_GET['year']) : '';

if (mb_strlen($q) < 2) {
  http_response_code(400);
  echo json_encode(['error' => 'Query må være minst 2 tegn.']);
  exit;
}

$cacheFile = __DIR__ . '/tvdb_token_cache.json';

function http_json($url, $method = 'GET', $headers = [], $body = null) {
    // (Implementering — samme som før)
}

try {
    $token = get_tvdb_token($TVDB_API_KEY, $TVDB_PIN, $cacheFile);

    $queryParams = ['query' => $q];
    if ($type !== '') $queryParams['type'] = $type;

    $searchUrl = 'https://api4.thetvdb.com/v4/search?' . http_build_query($queryParams);

    $res = http_json($searchUrl, 'GET', [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $token,
    ]);

    if (!$res['ok']) {
        http_response_code($res['code'] ?: 500);
        echo json_encode(['error' => 'Search feilet', 'details' => $res['json'] ?? $res['raw']]);
        exit;
    }

    $results = $res['json']['data'];

    // Filtrer basert på år dersom `year` er spesifisert
    if ($year !== '') {
        $results = array_filter($results, function ($item) use ($year) {
            return strpos($item['firstAired'] ?? '', $year) === 0;
        });
    }

    echo json_encode(['data' => $results]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
