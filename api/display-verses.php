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
$stmt = $pdo->prepare('SELECT yah_verse_key, yah_chapter_key, yah_verse_number FROM yah_verse WHERE yah_chapter_key = ? ORDER BY yah_verse_sort');
$stmt->execute([$chapterKey]);
jsonResponse($stmt->fetchAll());
