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
$stmt = $db->prepare('SELECT cite_verse_key, cite_chapter_key, cite_verse_number, cite_verse_sort FROM yy_cite_verse WHERE cite_chapter_key = ? ORDER BY cite_verse_sort');
$stmt->execute([(int)$chapterKey]);
jsonResponse($stmt->fetchAll());
