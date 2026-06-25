<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();

$wordId = isset($_GET['word_key']) && $_GET['word_key'] !== '' ? (int)$_GET['word_key'] : null;

if ($wordId === null) {
    errorResponse('word_key parameter required');
}

// Get all spellings for this word
$stmt = $pdo->prepare("
    SELECT word_translit_text
    FROM yy_word_translit
    WHERE word_key = ?
    ORDER BY word_translit_sort, word_translit_key
");
$stmt->execute([$wordId]);
$spellings = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($spellings)) {
    jsonResponse([]);
}

// Build regex: match any spelling as whole word (case-insensitive)
// PostgreSQL \m = word start boundary, \M = word end boundary
// Words starting with apostrophe need lookbehind instead of \m
// Words ending with apostrophe need lookahead instead of \M
$patterns = [];
foreach ($spellings as $sp) {
    $escaped = preg_replace('/([.\\\\+*?\[\]^$(){}|])/', '\\\\$1', $sp);
    $prefix = '\\m';
    $suffix = '\\M';
    if (substr($sp, 0, 1) === "'") {
        $prefix = '(?<![a-zA-Z])';
    }
    if (substr($sp, -1) === "'") {
        $suffix = '(?![a-zA-Z])';
    }
    $patterns[] = $prefix . $escaped . $suffix;
}
$regexPattern = '(' . implode('|', $patterns) . ')';

$stmt = $pdo->prepare("
    SELECT t.translation_key AS translation_id,
           vol.volume_file AS translation_book,
           t.translation_page AS translation_page,
           t.translation_copy AS translation_text_word,
           vol.volume_flip_code,
           ser.series_sort,
           vol.volume_sort,
           vol.volume_key,
           (SELECT 'Chapter ' || ch.chapter_number || ':' || ch.chapter_name
            FROM yy_chapter ch
            WHERE ch.volume_key = t.volume_key
              AND ch.chapter_page <= t.translation_page
            ORDER BY ch.chapter_page DESC LIMIT 1) AS chapter_name
    FROM yy_translation t
    JOIN yy_volume vol ON vol.volume_key = t.volume_key AND vol.volume_active_flag = TRUE
    JOIN yy_series ser ON ser.series_key = vol.series_key
    JOIN yy_cite_book cb ON cb.cite_book_key = t.cite_book_key
    JOIN yy_cite_chapter c ON c.cite_chapter_key = t.cite_chapter_key
    JOIN yy_cite_verse v ON v.cite_verse_key = t.cite_verse_key
    WHERE t.translation_copy ~* ?
    ORDER BY ser.series_sort ASC, vol.volume_sort ASC, t.translation_page ASC
");
$stmt->execute([$regexPattern]);

jsonResponse($stmt->fetchAll());
