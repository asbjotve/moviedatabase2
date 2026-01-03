<?php
// TMDB search proxy
// Legacy output: {"data": [...]} for non-debug requests

header('Content-Type: application/json; charset=utf-8');

$apiKey = getenv('TMDB_API_KEY');
if (!$apiKey) {
  http_response_code(500);
  echo json_encode(["error" => "TMDB API key not configured"], JSON_UNESCAPED_SLASHES);
  exit;
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';

// Build TMDB request
$baseUrl = 'https://api.themoviedb.org/3/search/multi';
$params = [
  'api_key' => $apiKey,
  'query' => $q,
  'include_adult' => 'false',
  'language' => 'en-US'
];

$tmdbUrl = $baseUrl . '?' . http_build_query($params);

// Helper: redact api_key in URL for debug output
$redactedUrl = preg_replace('/([?&]api_key=)[^&]*/', '$1REDACTED', $tmdbUrl);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tmdbUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

$body = curl_exec($ch);
$curlErrNo = curl_errno($ch);
$curlErr = curl_error($ch);
$httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = null;
if ($body !== false && $body !== '') {
  $decoded = json_decode($body, true);
}

$ok = ($curlErrNo === 0) && ($httpStatus >= 200 && $httpStatus < 300) && is_array($decoded);

if (!$ok) {
  // Debug mode: include URL (redacted), status, and error/body details
  if ($debug) {
    http_response_code(502);
    echo json_encode([
      'debug' => [
        'tmdb_url' => $redactedUrl,
        'http_status' => $httpStatus,
        'curl_errno' => $curlErrNo,
        'curl_error' => $curlErr,
      ],
      'error' => 'TMDB request failed',
      'tmdb_body' => $body,
      'tmdb_json' => $decoded,
    ], JSON_UNESCAPED_SLASHES);
    exit;
  }

  // Legacy non-debug behavior: keep {"data": [...]} shape
  http_response_code(200);
  echo json_encode(['data' => []], JSON_UNESCAPED_SLASHES);
  exit;
}

$results = $decoded['results'] ?? [];
if (!is_array($results)) {
  $results = [];
}

// Legacy response shape
http_response_code(200);
echo json_encode(['data' => $results], JSON_UNESCAPED_SLASHES);
