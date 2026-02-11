<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();

$citeBookId = isset($_GET['cite_book_id']) && $_GET['cite_book_id'] !== '' ? (int)$_GET['cite_book_id'] : null;
$scrollKey  = isset($_GET['scroll_key']) && $_GET['scroll_key'] !== '' ? (int)$_GET['scroll_key'] : null;
$chapter = isset($_GET['chapter']) && $_GET['chapter'] !== '' ? (int)$_GET['chapter'] : null;
$verse   = isset($_GET['verse']) && $_GET['verse'] !== '' ? (int)$_GET['verse'] : null;

$conditions = [];
$params = [];

if ($citeBookId !== null) {
    $conditions[] = "t.translation_cite_book_id = ?";
    $params[] = $citeBookId;
} elseif ($scrollKey !== null) {
    $conditions[] = "t.translation_cite_book_id IN (SELECT cite_book_id FROM yy_cite_book WHERE yah_scroll_key = ?)";
    $params[] = $scrollKey;
}

if ($chapter !== null) {
    $conditions[] = "t.translation_cite_chapter = ?";
    $params[] = $chapter;
}

if ($verse !== null) {
    $conditions[] = "t.translation_cite_verse = ?";
    $params[] = $verse;
}

$where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = $pdo->prepare("
    SELECT t.translation_id, t.translation_book, t.translation_page, t.translation_text_word,
           t.translation_cite, t.translation_cite_chapter, t.translation_cite_verse, t.translation_cite_verse_end,
           t.translation_cite_book_id,
           v.yy_volume_flip_code,
           cb.cite_book_hebrew, cb.cite_book_common
    FROM translation t
    LEFT JOIN yy_volume v ON v.yy_volume_file = t.translation_book
    LEFT JOIN yy_cite_book cb ON t.translation_cite_book_id = cb.cite_book_id
    $where
    ORDER BY cb.cite_book_sort ASC, cb.cite_book_hebrew ASC, t.translation_cite_chapter ASC, t.translation_cite_verse ASC, t.translation_book, t.translation_page
");
$stmt->execute($params);

jsonResponse($stmt->fetchAll());
