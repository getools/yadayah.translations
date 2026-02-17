<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();

$wordId = isset($_GET['word_id']) && $_GET['word_id'] !== '' ? (int)$_GET['word_id'] : null;

if ($wordId === null) {
    errorResponse('word_id parameter required');
}

// Get all spellings for this word
$stmt = $pdo->prepare("
    SELECT word_spelling_text
    FROM yy_word_spelling
    WHERE word_id = ?
    ORDER BY word_spelling_sort, word_spelling_id
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
    SELECT t.yy_translation_key AS translation_id,
           vol.yy_volume_file AS translation_book,
           t.yy_translation_page AS translation_page,
           t.yy_translation_copy AS translation_text_word,
           vol.yy_volume_flip_code,
           ser.yy_series_sort,
           vol.yy_volume_sort,
           vol.yy_volume_key,
           (SELECT 'Chapter ' || ch.yy_chapter_number || ':' || ch.yy_chapter_name
            FROM yy_chapter ch
            WHERE ch.yy_volume_key = t.yy_volume_key
              AND ch.yy_chapter_page <= t.yy_translation_page
            ORDER BY ch.yy_chapter_page DESC LIMIT 1) AS yy_chapter_name
    FROM yy_translation t
    JOIN yy_volume vol ON vol.yy_volume_key = t.yy_volume_key
    JOIN yy_series ser ON ser.yy_series_key = vol.yy_series_key
    JOIN yah_scroll s ON s.yah_scroll_key = t.yah_scroll_key
    JOIN yah_chapter c ON c.yah_chapter_key = t.yah_chapter_key
    JOIN yah_verse v ON v.yah_verse_key = t.yah_verse_key
    WHERE t.yy_translation_copy ~* ?
    ORDER BY ser.yy_series_sort ASC, vol.yy_volume_sort ASC, t.yy_translation_page ASC
");
$stmt->execute([$regexPattern]);

jsonResponse($stmt->fetchAll());
