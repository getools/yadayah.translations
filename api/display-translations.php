<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();

$citeBookId = isset($_GET['cite_book_id']) && $_GET['cite_book_id'] !== '' ? (int)$_GET['cite_book_id'] : null;
$scrollKey  = isset($_GET['scroll_key']) && $_GET['scroll_key'] !== '' ? (int)$_GET['scroll_key'] : null;
$chapter    = isset($_GET['chapter']) && $_GET['chapter'] !== '' ? (int)$_GET['chapter'] : null;
$verse      = isset($_GET['verse']) && $_GET['verse'] !== '' ? (int)$_GET['verse'] : null;

$conditions = [];
$params = [];

if ($citeBookId !== null) {
    // Map cite_book_id to yah_scroll_key via yy_cite_book
    $conditions[] = "t.yah_scroll_key IN (SELECT yah_scroll_key FROM yy_cite_book WHERE cite_book_id = ?)";
    $params[] = $citeBookId;
} elseif ($scrollKey !== null) {
    $conditions[] = "t.yah_scroll_key = ?";
    $params[] = $scrollKey;
}

if ($chapter !== null) {
    $conditions[] = "c.yah_chapter_number = ?";
    $params[] = $chapter;
}

if ($verse !== null) {
    $conditions[] = "v.yah_verse_number = ?";
    $params[] = $verse;
}

$where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = $pdo->prepare("
    SELECT t.yy_translation_key AS translation_id,
           vol.yy_volume_file AS translation_book,
           t.yy_translation_page AS translation_page,
           t.yy_translation_copy AS translation_text_word,
           s.yah_scroll_label_yy || ' / ' || s.yah_scroll_label_common AS translation_cite,
           s.yah_scroll_label_yy AS cite_book_hebrew,
           s.yah_scroll_label_common AS cite_book_common,
           c.yah_chapter_number AS translation_cite_chapter,
           v.yah_verse_number AS translation_cite_verse,
           NULL AS translation_cite_verse_end,
           t.yah_scroll_key AS translation_cite_book_id,
           vol.yy_volume_flip_code
    FROM yy_translation t
    JOIN yah_scroll s ON s.yah_scroll_key = t.yah_scroll_key
    JOIN yah_chapter c ON c.yah_chapter_key = t.yah_chapter_key
    JOIN yah_verse v ON v.yah_verse_key = t.yah_verse_key
    JOIN yy_volume vol ON vol.yy_volume_key = t.yy_volume_key
    $where
    ORDER BY s.yah_scroll_sort ASC, s.yah_scroll_label_yy ASC,
             c.yah_chapter_number ASC, v.yah_verse_number ASC,
             vol.yy_volume_file, t.yy_translation_page
");
$stmt->execute($params);

jsonResponse($stmt->fetchAll());
