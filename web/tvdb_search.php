<?php
// Accept both "query" (preferred) and "q" (alias) as the search parameter.
// Keep existing behavior: if "query" is present, it takes precedence.
if (!isset($_GET['query']) && isset($_GET['q'])) {
    $_GET['query'] = $_GET['q'];
}

require_once __DIR__ . '/tvdb_search.php';
