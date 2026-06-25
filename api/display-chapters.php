<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$citeBookKey = isset($_GET['cite_book_key']) && $_GET['cite_book_key'] !== '' ? (int)$_GET['cite_book_key'] : null;
if ($citeBookKey === null) {
    errorResponse('cite_book_key is required');
}

$pdo = getDb();
// Filter is "has at least one translation" (not cite_chapter_count > 0).
// cite_chapter_count = 0 on canonical chapters that nonetheless have
// translations parsed from YY books (Galatians 3-6, Acts 9/13/15/etc., Psalms
// 149) — hundreds of rows would be hidden by the count filter alone.
$stmt = $pdo->prepare('SELECT cite_chapter_key, cite_book_key, cite_chapter_number FROM yy_cite_chapter WHERE cite_book_key = ? AND EXISTS (SELECT 1 FROM yy_translation t WHERE t.cite_chapter_key = yy_cite_chapter.cite_chapter_key) ORDER BY cite_chapter_sort, cite_chapter_number');
$stmt->execute([$citeBookKey]);
jsonResponse($stmt->fetchAll());
