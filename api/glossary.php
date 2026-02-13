<?php
require_once __DIR__ . '/config.php';

$pdo = getDb();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'letters':
        $stmt = $pdo->query("SELECT letter_key, letter_yt, letter_hebrew, letter_label, letter_overview, letter_numeric_value, letter_sort FROM yy_letter ORDER BY letter_sort ASC, letter_key ASC");
        jsonResponse($stmt->fetchAll());

    case 'words':
        $letter = $_GET['letter'] ?? '';
        if (!$letter || strlen($letter) !== 1) errorResponse('letter parameter required (single character)');
        $stmt = $pdo->prepare("
            SELECT w.word_id, w.word_strongs, w.word_hebrew, w.word_yt,
                   w.word_definition_kirk, w.word_definition_yy,
                   w.word_flag_noun, w.word_flag_verb, w.word_flag_adjective,
                   w.word_flag_adverb, w.word_flag_preposition, w.word_flag_conjunction,
                   w.word_flag_subst, w.word_flag_plural, w.word_gender,
                   w.word_count_yy,
                   (SELECT string_agg(ws.word_spelling_text, ', ' ORDER BY ws.word_spelling_sort, ws.word_spelling_id)
                    FROM yy_word_spelling ws WHERE ws.word_id = w.word_id) AS word_spellings
            FROM yy_word w
            WHERE w.word_active_flag = true
              AND LEFT(w.word_yt, 1) = ?
            ORDER BY w.word_yt ASC
        ");
        $stmt->execute([$letter]);
        jsonResponse($stmt->fetchAll());

    default:
        errorResponse('Invalid action. Use: letters, words');
}
