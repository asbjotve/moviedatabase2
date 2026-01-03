<?php

require_once __DIR__ . '/config.php';

/**
 * Legacy TMDB search endpoint.
 *
 * The existing frontend expects a JSON response on the form:
 *   {"data": [ ... ]}
 *
 * Keep using http_json() for outgoing HTTP calls, but map results into the
 * legacy structure (including baseImageUrl + poster mapping) and add optional
 * year filtering.
 */

$query = isset($_GET['query']) ? trim((string)$_GET['query']) : '';
$year  = isset($_GET['year']) ? trim((string)$_GET['year']) : '';

if ($query === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data' => []]);
    exit;
}

// Build TMDB search URL
$params = [
    'query' => $query,
];

// Optional year filter (TMDB uses primary_release_year for movie search)
if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
    $params['primary_release_year'] = $year;
}

$url = TMDB_API_BASE_URL . '/search/movie?' . http_build_query($params);

// http_json() is expected to handle auth headers / bearer token.
$res = http_json($url);

$results = [];
if (is_array($res) && isset($res['results']) && is_array($res['results'])) {
    $results = $res['results'];
}

// Determine base image URL for poster mapping (legacy frontend expects this)
$baseImageUrl = '';
if (defined('TMDB_IMAGE_BASE_URL') && TMDB_IMAGE_BASE_URL) {
    // If config provides a base image URL, prefer it.
    $baseImageUrl = rtrim(TMDB_IMAGE_BASE_URL, '/');
} else {
    // Fallback to TMDB's default image host path
    $baseImageUrl = 'https://image.tmdb.org/t/p/w500';
}

$data = [];
foreach ($results as $m) {
    if (!is_array($m)) {
        continue;
    }

    $releaseDate = isset($m['release_date']) ? (string)$m['release_date'] : '';
    $releaseYear = '';
    if ($releaseDate !== '' && preg_match('/^(\d{4})-\d{2}-\d{2}$/', $releaseDate, $mm)) {
        $releaseYear = $mm[1];
    }

    // Apply year filter defensively as well (in case TMDB ignores/changes params)
    if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
        if ($releaseYear !== '' && $releaseYear !== $year) {
            continue;
        }
        // If no release year exists, let it pass (legacy behavior typically did).
    }

    $posterPath = isset($m['poster_path']) ? (string)$m['poster_path'] : '';
    $poster = null;
    if ($posterPath !== '') {
        // Legacy mapping: provide full poster URL
        $poster = $baseImageUrl . '/' . ltrim($posterPath, '/');
    }

    $data[] = [
        // Preserve commonly used legacy keys
        'id' => $m['id'] ?? null,
        'title' => $m['title'] ?? ($m['name'] ?? ''),
        'original_title' => $m['original_title'] ?? null,
        'overview' => $m['overview'] ?? null,
        'release_date' => $releaseDate ?: null,
        'year' => $releaseYear ?: null,
        'poster_path' => $posterPath ?: null,
        'poster' => $poster,
        // Frontend expects baseImageUrl present (previous version behavior)
        'baseImageUrl' => $baseImageUrl,
        // Keep a couple of extra fields if present
        'vote_average' => $m['vote_average'] ?? null,
        'popularity' => $m['popularity'] ?? null,
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['data' => $data]);
