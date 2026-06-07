<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$chapterKey = isset($_GET['chapter_key']) && $_GET['chapter_key'] !== '' ? (int)$_GET['chapter_key'] : null;
if ($chapterKey === null) {
    errorResponse('chapter_key is required');
}

$pdo = getDb();
// Filter is "has at least one translation" (not yah_verse_count > 0).
// 724 translations live in verses where yah_verse_count = 0 — the count
// filter alone would hide them.
$stmt = $pdo->prepare('SELECT yah_verse_key, yah_chapter_key, yah_verse_number FROM yah_verse WHERE yah_chapter_key = ? AND EXISTS (SELECT 1 FROM yy_translation t WHERE t.yah_verse_key = yah_verse.yah_verse_key) ORDER BY yah_verse_sort, yah_verse_number');
$stmt->execute([$chapterKey]);
jsonResponse($stmt->fetchAll());
