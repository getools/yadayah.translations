<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$seriesKey = $_GET['series_key'] ?? null;
if (!$seriesKey || !ctype_digit($seriesKey)) {
    errorResponse('series_key is required and must be an integer');
}

$db = getDb();
$stmt = $db->prepare("SELECT yy_volume_key, yy_series_key, yy_volume_number, yy_volume_name, yy_volume_label, yy_volume_sort, CONCAT(yy_volume_number, ' - ', COALESCE(yy_volume_label, yy_volume_name)) AS display_text FROM yy_volume WHERE yy_series_key = ? ORDER BY yy_volume_sort");
$stmt->execute([(int)$seriesKey]);
jsonResponse($stmt->fetchAll());
