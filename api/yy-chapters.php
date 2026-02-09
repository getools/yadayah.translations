<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$volumeKey = $_GET['volume_key'] ?? null;
if (!$volumeKey || !ctype_digit($volumeKey)) {
    errorResponse('volume_key is required and must be an integer');
}

$db = getDb();
$stmt = $db->prepare('SELECT yy_chapter_key, yy_volume_key, yy_chapter_number, yy_chapter_name, yy_chapter_label, yy_chapter_sort FROM yy_chapter WHERE yy_volume_key = ? ORDER BY yy_chapter_sort');
$stmt->execute([(int)$volumeKey]);
jsonResponse($stmt->fetchAll());
