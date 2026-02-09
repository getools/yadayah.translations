<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();
$stmt = $db->query('SELECT yah_scroll_key, yah_scroll_label_common, yah_scroll_label_yy, yah_scroll_sort FROM yah_scroll ORDER BY yah_scroll_sort');
jsonResponse($stmt->fetchAll());
