<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$chapterKey = $_GET['chapter_key'] ?? null;
if (!$chapterKey || !ctype_digit($chapterKey)) {
    errorResponse('chapter_key is required and must be an integer');
}

$db = getDb();
$stmt = $db->prepare('SELECT yah_verse_key, yah_chapter_key, yah_verse_number, yah_verse_sort FROM yah_verse WHERE yah_chapter_key = ? ORDER BY yah_verse_sort');
$stmt->execute([(int)$chapterKey]);
jsonResponse($stmt->fetchAll());
