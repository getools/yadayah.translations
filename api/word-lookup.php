<?php
require_once __DIR__ . '/config.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    errorResponse('Method not allowed', 405);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $words = file_get_contents('php://input');
} else {
    $words = isset($_GET['words']) ? $_GET['words'] : '';
}
if (!$words) {
    jsonResponse([]);
}

// Normalize curly quotes to ASCII apostrophes
$words = str_replace(["\u{2018}", "\u{2019}", "\u{2032}"], "'", $words);
$wordList = array_filter(array_map('trim', explode('|', $words)));
if (empty($wordList)) {
    jsonResponse([]);
}

$pg = getDb();

$placeholders = implode(',', array_fill(0, count($wordList), 'LOWER(?)'));
$params = array_map('strtolower', $wordList);

$sql = "
    SELECT w.word_id, s.word_spelling_text,
           w.word_yt, w.word_hebrew, w.word_strongs,
           w.word_flag_gender_m, w.word_flag_gender_f, w.word_flag_plural,
           w.word_flag_noun, w.word_flag_verb, w.word_flag_adjective,
           w.word_flag_adverb, w.word_flag_preposition, w.word_flag_conjunction,
           w.word_flag_subst, w.word_definition_kirk, w.word_definition_yy
    FROM yy_word_spelling s
    JOIN yy_word w ON s.word_id = w.word_id
    WHERE w.word_active_flag = true
      AND LOWER(s.word_spelling_text) IN ($placeholders)
    ORDER BY s.word_spelling_sort
";

$stmt = $pg->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Index by lowercase word_spelling_text (first match wins per word)
// Also collect word_ids to fetch all spellings
$result = [];
$wordIds = [];
foreach ($rows as $row) {
    $key = strtolower($row['word_spelling_text']);
    if (!isset($result[$key])) {
        $result[$key] = $row;
        $wordIds[$row['word_id']] = true;
    }
}

// Fetch all spellings for matched word_ids
if (!empty($wordIds)) {
    $idList = array_keys($wordIds);
    $idPlaceholders = implode(',', array_fill(0, count($idList), '?'));
    $spSql = "SELECT word_id, word_spelling_text FROM yy_word_spelling WHERE word_id IN ($idPlaceholders) ORDER BY word_id, word_spelling_sort";
    $spStmt = $pg->prepare($spSql);
    $spStmt->execute($idList);
    $spRows = $spStmt->fetchAll();

    // Group spellings by word_id
    $spByWord = [];
    foreach ($spRows as $sp) {
        $spByWord[$sp['word_id']][] = $sp['word_spelling_text'];
    }

    // Attach spellings to each result
    foreach ($result as $key => &$entry) {
        $wid = $entry['word_id'];
        $entry['word_spellings'] = isset($spByWord[$wid]) ? $spByWord[$wid] : [];
    }
    unset($entry);
}

jsonResponse($result);
