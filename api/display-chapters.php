<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$scrollKey = isset($_GET['scroll_key']) && $_GET['scroll_key'] !== '' ? (int)$_GET['scroll_key'] : null;
if ($scrollKey === null) {
    errorResponse('scroll_key is required');
}

$pdo = getDb();
$stmt = $pdo->prepare('SELECT yah_chapter_key, yah_scroll_key, yah_chapter_number FROM yah_chapter WHERE yah_scroll_key = ? ORDER BY yah_chapter_sort');
$stmt->execute([$scrollKey]);
jsonResponse($stmt->fetchAll());
