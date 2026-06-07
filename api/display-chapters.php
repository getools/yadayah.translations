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
// Filter is "has at least one translation" (not yah_chapter_count > 0).
// yah_chapter_count = 0 on canonical chapters that nonetheless have
// translations parsed from YY books (Galatians 3-6, Acts 9/13/15/etc., Psalms
// 149) — hundreds of rows would be hidden by the count filter alone.
$stmt = $pdo->prepare('SELECT yah_chapter_key, yah_scroll_key, yah_chapter_number FROM yah_chapter WHERE yah_scroll_key = ? AND EXISTS (SELECT 1 FROM yy_translation t WHERE t.yah_chapter_key = yah_chapter.yah_chapter_key) ORDER BY yah_chapter_sort, yah_chapter_number');
$stmt->execute([$scrollKey]);
jsonResponse($stmt->fetchAll());
