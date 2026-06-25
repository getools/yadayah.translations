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
// Filter is "has at least one translation" (not cite_verse_count > 0).
// 724 translations live in verses where cite_verse_count = 0 — the count
// filter alone would hide them.
$stmt = $pdo->prepare('SELECT cite_verse_key, cite_chapter_key, cite_verse_number FROM yy_cite_verse WHERE cite_chapter_key = ? AND EXISTS (SELECT 1 FROM yy_translation t WHERE t.cite_verse_key = yy_cite_verse.cite_verse_key) ORDER BY cite_verse_sort, cite_verse_number');
$stmt->execute([$chapterKey]);
jsonResponse($stmt->fetchAll());
