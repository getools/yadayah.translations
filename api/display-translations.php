<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();

$citeBookId = isset($_GET['cite_book_key']) && $_GET['cite_book_key'] !== '' ? (int)$_GET['cite_book_key'] : null;
$chapter    = isset($_GET['chapter']) && $_GET['chapter'] !== '' ? (int)$_GET['chapter'] : null;
$verse      = isset($_GET['verse']) && $_GET['verse'] !== '' ? (int)$_GET['verse'] : null;

// Require at least one filter. Without this guard the endpoint returns the
// entire 23 MB translations dataset (~14k rows) and takes ~40s — long enough
// to OOM the host under any concurrency. The frontend always provides at
// minimum a cite_book_key on initial load.
if ($citeBookId === null && $chapter === null && $verse === null) {
    errorResponse('At least one of cite_book_key, chapter, or verse is required', 400);
}

$conditions = [];
$params = [];

if ($citeBookId !== null) {
    $conditions[] = "t.cite_book_key = ?";
    $params[] = $citeBookId;
}

if ($chapter !== null) {
    $conditions[] = "c.cite_chapter_number = ?";
    $params[] = $chapter;
}

if ($verse !== null) {
    $conditions[] = "v.cite_verse_number = ?";
    $params[] = $verse;
}

$where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

// chapter_name was previously a correlated subquery in the SELECT list, which
// re-planned + executed once per result row — ~14k subqueries on an unfiltered
// query, taking 40+s. LATERAL JOIN runs the same lookup but the planner sees
// it once and can pick a better strategy. With the (volume_key, chapter_page DESC)
// index it's now an index-only seek per row.
$stmt = $pdo->prepare("
    SELECT t.translation_key AS translation_id,
           vol.volume_file AS translation_book,
           t.translation_page AS translation_page,
           t.translation_paragraph AS translation_paragraph,
           t.translation_copy AS translation_text_word,
           cb.cite_book_hebrew || ' / ' || cb.cite_book_common AS translation_cite,
           cb.cite_book_hebrew AS cite_book_hebrew,
           cb.cite_book_common AS cite_book_common,
           c.cite_chapter_number AS translation_cite_chapter,
           v.cite_verse_number AS translation_cite_verse,
           NULL AS translation_cite_verse_end,
           t.cite_book_key AS translation_cite_book_key,
           vol.volume_flip_code,
           cn.chapter_name
    FROM yy_translation t
    JOIN yy_cite_book cb ON cb.cite_book_key = t.cite_book_key
    JOIN yy_cite_chapter c ON c.cite_chapter_key = t.cite_chapter_key
    JOIN yy_cite_verse v ON v.cite_verse_key = t.cite_verse_key
    JOIN yy_volume vol ON vol.volume_key = t.volume_key AND vol.volume_active_flag = TRUE
    LEFT JOIN LATERAL (
        SELECT 'Chapter ' || ch.chapter_number || ':' || ch.chapter_name AS chapter_name
        FROM yy_chapter ch
        WHERE ch.volume_key = t.volume_key
          AND ch.chapter_page <= t.translation_page
        ORDER BY ch.chapter_page DESC
        LIMIT 1
    ) cn ON TRUE
    $where
    ORDER BY cb.cite_book_sort ASC, cb.cite_book_hebrew ASC,
             c.cite_chapter_number ASC, v.cite_verse_number ASC,
             vol.volume_file, t.translation_page
");
$stmt->execute($params);

jsonResponse($stmt->fetchAll());
