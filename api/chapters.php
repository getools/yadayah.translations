<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$scrollKey = $_GET['scroll_key'] ?? null;
if (!$scrollKey || !ctype_digit($scrollKey)) {
    errorResponse('scroll_key is required and must be an integer');
}

$db = getDb();
$stmt = $db->prepare('SELECT yah_chapter_key, yah_scroll_key, yah_chapter_number, yah_chapter_sort FROM yah_chapter WHERE yah_scroll_key = ? ORDER BY yah_chapter_sort');
$stmt->execute([(int)$scrollKey]);
jsonResponse($stmt->fetchAll());
