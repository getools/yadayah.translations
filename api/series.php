<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();
$stmt = $db->query('SELECT yy_series_key, yy_series_name, yy_series_label, yy_series_sort FROM yy_series ORDER BY yy_series_sort');
jsonResponse($stmt->fetchAll());
