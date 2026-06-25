<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$citeBookKey = $_GET['cite_book_key'] ?? null;
if (!$citeBookKey || !ctype_digit($citeBookKey)) {
    errorResponse('cite_book_key is required and must be an integer');
}

$db = getDb();
$stmt = $db->prepare('SELECT cite_chapter_key, cite_book_key, cite_chapter_number, cite_chapter_sort FROM yy_cite_chapter WHERE cite_book_key = ? ORDER BY cite_chapter_sort');
$stmt->execute([(int)$citeBookKey]);
jsonResponse($stmt->fetchAll());
