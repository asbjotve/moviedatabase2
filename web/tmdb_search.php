<?php
// Backend for TheMovieDB v3 søk med årstall-filtrering
header('Content-Type: application/json; charset=utf-8');

$TMDB_API_KEY = '43e59c66937eff8f235c170a389fa3d1';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$year = isset($_GET['year']) ? trim($_GET['year']) : '';

if (mb_strlen($q) < 2) {
  http_response_code(400);
  echo json_encode(['error' => 'Query må være minst 2 tegn.']);
  exit;
}

$searchUrl = 'https://api.themoviedb.org/3/search/movie?' . http_build_query([
    'api_key' => $TMDB_API_KEY,
    'query' => $q,
    'year' => $year, // Valgfritt årstall-filter
]);

$baseImageUrl = 'https://image.tmdb.org/t/p/w200';

$res = http_json($searchUrl, 'GET', [
  'Accept' => 'application/json',
]);

if (!$res['ok']) {
    http_response_code($res['code'] ?: 500);
    echo json_encode(['error' => 'Search feilet', 'details' => $res['json'] ?? $res['raw']]);
    exit;
}

foreach ($res['json']['results'] as &$item) {
    $item['image'] = isset($item['poster_path']) ? $baseImageUrl . $item['poster_path'] : 'path/to/default-image.jpg';
}

echo json_encode(['data' => $res['json']['results']]);
