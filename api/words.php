<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet($db);
        break;
    case 'POST':
        handlePost($db, $user);
        break;
    case 'PUT':
        handlePut($db, $user);
        break;
    case 'DELETE':
        handleDelete($db, $user);
        break;
    default:
        errorResponse('Method not allowed', 405);
}

function handleGet(PDO $db): void {
    // Single word by ID with spellings
    if (isset($_GET['word_id']) && ctype_digit($_GET['word_id'])) {
        $wordId = (int)$_GET['word_id'];
        $stmt = $db->prepare("SELECT * FROM yy_word WHERE word_id = ?");
        $stmt->execute([$wordId]);
        $word = $stmt->fetch();
        if (!$word) {
            errorResponse('Word not found', 404);
        }
        $spStmt = $db->prepare("SELECT word_spelling_id, word_spelling_text, word_spelling_sort, word_spelling_count_yy FROM yy_word_spelling WHERE word_id = ? ORDER BY word_spelling_sort, word_spelling_id");
        $spStmt->execute([$wordId]);
        $word['spellings'] = $spStmt->fetchAll();
        jsonResponse($word);
    }

    // Search
    if (isset($_GET['search']) && trim($_GET['search']) !== '') {
        $term = '%' . trim($_GET['search']) . '%';
        $stmt = $db->prepare("
            SELECT DISTINCT w.word_id, w.word_strongs, w.word_hebrew, w.word_yt, w.word_active_flag,
                   w.word_definition_kirk, w.word_definition_yy, w.word_count_yy,
                   (SELECT string_agg(s.word_spelling_text, ', ' ORDER BY s.word_spelling_sort, s.word_spelling_id)
                    FROM yy_word_spelling s WHERE s.word_id = w.word_id) AS spellings_display
            FROM yy_word w
            LEFT JOIN yy_word_spelling s ON s.word_id = w.word_id
            WHERE w.word_strongs LIKE ?
               OR w.word_hebrew LIKE ?
               OR w.word_yt LIKE ?
               OR s.word_spelling_text ILIKE ?
               OR w.word_definition_kirk ILIKE ?
               OR w.word_definition_yy ILIKE ?
            ORDER BY w.word_strongs
            LIMIT 200
        ");
        $stmt->execute([$term, $term, $term, $term, $term, $term]);
        jsonResponse($stmt->fetchAll());
    }

    // List all
    if (isset($_GET['list']) && $_GET['list'] === 'all') {
        $stmt = $db->query("
            SELECT w.word_id, w.word_strongs, w.word_hebrew, w.word_yt, w.word_active_flag,
                   w.word_definition_kirk, w.word_definition_yy, w.word_count_yy,
                   (SELECT string_agg(s.word_spelling_text, ', ' ORDER BY s.word_spelling_sort, s.word_spelling_id)
                    FROM yy_word_spelling s WHERE s.word_id = w.word_id) AS spellings_display
            FROM yy_word w
            ORDER BY w.word_strongs
        ");
        jsonResponse($stmt->fetchAll());
    }

    errorResponse('Provide word_id, search, or list=all');
}

function handlePost(PDO $db, array $user): void {
    setCurrentUser($db, $user['user_key']);
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        errorResponse('Invalid JSON body');
    }

    $errors = validateWord($data);
    if (!empty($errors)) {
        jsonResponse(['errors' => $errors], 422);
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO yy_word (word_strongs, word_hebrew,
                word_flag_gender_m, word_flag_gender_f, word_flag_plural,
                word_flag_noun, word_flag_verb, word_flag_adjective,
                word_flag_adverb, word_flag_preposition, word_flag_conjunction, word_flag_subst,
                word_definition_kirk, word_definition_yy, word_active_flag)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            str_pad(trim($data['word_strongs']), 4, '0', STR_PAD_LEFT),
            trim($data['word_hebrew']),
            toBool($data, 'word_flag_gender_m'),
            toBool($data, 'word_flag_gender_f'),
            toBool($data, 'word_flag_plural'),
            toBool($data, 'word_flag_noun'),
            toBool($data, 'word_flag_verb'),
            toBool($data, 'word_flag_adjective'),
            toBool($data, 'word_flag_adverb'),
            toBool($data, 'word_flag_preposition'),
            toBool($data, 'word_flag_conjunction'),
            toBool($data, 'word_flag_subst'),
            emptyToNull($data, 'word_definition_kirk'),
            emptyToNull($data, 'word_definition_yy'),
            !empty($data['word_active_flag']) ? 't' : 'f',
        ]);
        $wordId = (int)$db->lastInsertId('yy_word_word_id_seq');
        insertSpellings($db, $wordId, $data['spellings'] ?? []);
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        errorResponse('Failed to create word: ' . $e->getMessage(), 500);
    }

    // Return the created word
    returnWord($db, $wordId, 201);
}

function handlePut(PDO $db, array $user): void {
    setCurrentUser($db, $user['user_key']);
    $wordId = $_GET['word_id'] ?? null;
    if (!$wordId || !ctype_digit($wordId)) {
        errorResponse('word_id is required');
    }
    $wordId = (int)$wordId;

    $check = $db->prepare('SELECT word_id FROM yy_word WHERE word_id = ?');
    $check->execute([$wordId]);
    if (!$check->fetch()) {
        errorResponse('Word not found', 404);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        errorResponse('Invalid JSON body');
    }

    $errors = validateWord($data);
    if (!empty($errors)) {
        jsonResponse(['errors' => $errors], 422);
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            UPDATE yy_word SET
                word_strongs = ?, word_hebrew = ?,
                word_flag_gender_m = ?, word_flag_gender_f = ?, word_flag_plural = ?,
                word_flag_noun = ?, word_flag_verb = ?, word_flag_adjective = ?,
                word_flag_adverb = ?, word_flag_preposition = ?, word_flag_conjunction = ?, word_flag_subst = ?,
                word_definition_kirk = ?, word_definition_yy = ?, word_active_flag = ?
            WHERE word_id = ?
        ");
        $stmt->execute([
            str_pad(trim($data['word_strongs']), 4, '0', STR_PAD_LEFT),
            trim($data['word_hebrew']),
            toBool($data, 'word_flag_gender_m'),
            toBool($data, 'word_flag_gender_f'),
            toBool($data, 'word_flag_plural'),
            toBool($data, 'word_flag_noun'),
            toBool($data, 'word_flag_verb'),
            toBool($data, 'word_flag_adjective'),
            toBool($data, 'word_flag_adverb'),
            toBool($data, 'word_flag_preposition'),
            toBool($data, 'word_flag_conjunction'),
            toBool($data, 'word_flag_subst'),
            emptyToNull($data, 'word_definition_kirk'),
            emptyToNull($data, 'word_definition_yy'),
            !empty($data['word_active_flag']) ? 't' : 'f',
            $wordId,
        ]);

        // Replace spellings
        $del = $db->prepare("DELETE FROM yy_word_spelling WHERE word_id = ?");
        $del->execute([$wordId]);
        insertSpellings($db, $wordId, $data['spellings'] ?? []);
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        errorResponse('Failed to update word: ' . $e->getMessage(), 500);
    }

    returnWord($db, $wordId);
}

function handleDelete(PDO $db, array $user): void {
    setCurrentUser($db, $user['user_key']);
    $wordId = $_GET['word_id'] ?? null;
    if (!$wordId || !ctype_digit($wordId)) {
        errorResponse('word_id is required');
    }
    $wordId = (int)$wordId;

    $check = $db->prepare('SELECT word_id FROM yy_word WHERE word_id = ?');
    $check->execute([$wordId]);
    if (!$check->fetch()) {
        errorResponse('Word not found', 404);
    }

    $db->beginTransaction();
    try {
        $del = $db->prepare("DELETE FROM yy_word_spelling WHERE word_id = ?");
        $del->execute([$wordId]);
        $del2 = $db->prepare("DELETE FROM yy_word WHERE word_id = ?");
        $del2->execute([$wordId]);
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        errorResponse('Failed to delete word: ' . $e->getMessage(), 500);
    }

    jsonResponse(['deleted' => true]);
}

function insertSpellings(PDO $db, int $wordId, array $spellings): void {
    if (empty($spellings)) return;
    $stmt = $db->prepare("INSERT INTO yy_word_spelling (word_id, word_spelling_text, word_spelling_sort, word_spelling_count_yy) VALUES (?, ?, ?, ?)");
    $sort = 0;
    foreach ($spellings as $sp) {
        $text = is_array($sp) ? ($sp['word_spelling_text'] ?? '') : (string)$sp;
        $text = trim($text);
        if ($text !== '') {
            $count = countSpellingInTranslations($db, $text);
            $stmt->execute([$wordId, $text, $sort, $count]);
            $sort++;
        }
    }
}

function countSpellingInTranslations(PDO $db, string $spelling): int {
    // Extract italicized text from all translations and count occurrences
    $stmt = $db->query("SELECT yy_translation_copy FROM yy_translation WHERE yy_translation_copy IS NOT NULL");
    $pattern = '/(?<![a-zA-Z\'])' . preg_quote(strtolower($spelling), '/') . '(?![a-zA-Z\'])/i';
    $count = 0;
    while ($row = $stmt->fetch()) {
        $copy = $row['yy_translation_copy'];
        // Extract text from <i>...</i> tags
        if (preg_match_all('/<i[^>]*>(.*?)<\/i>/si', $copy, $matches)) {
            foreach ($matches[1] as $italic) {
                $clean = strip_tags($italic);
                $count += preg_match_all($pattern, $clean);
            }
        }
        // Also check <span class="word">...</span>
        if (preg_match_all('/<span\s+class="word"[^>]*>(.*?)<\/span>/si', $copy, $matches)) {
            foreach ($matches[1] as $word) {
                $clean = strip_tags($word);
                $count += preg_match_all($pattern, $clean);
            }
        }
    }
    return min($count, 32767); // smallint max
}

function returnWord(PDO $db, int $wordId, int $status = 200): void {
    $stmt = $db->prepare("SELECT * FROM yy_word WHERE word_id = ?");
    $stmt->execute([$wordId]);
    $word = $stmt->fetch();
    $spStmt = $db->prepare("SELECT word_spelling_id, word_spelling_text, word_spelling_sort, word_spelling_count_yy FROM yy_word_spelling WHERE word_id = ? ORDER BY word_spelling_sort, word_spelling_id");
    $spStmt->execute([$wordId]);
    $word['spellings'] = $spStmt->fetchAll();
    jsonResponse($word, $status);
}

function validateWord(array $data): array {
    $errors = [];
    if (empty($data['word_strongs']) || !preg_match('/^\d{1,4}$/', trim($data['word_strongs']))) {
        $errors[] = "Strong's number is required (1-4 digits).";
    }
    if (empty($data['word_hebrew']) || trim($data['word_hebrew']) === '') {
        $errors[] = "Hebrew text is required.";
    }
    return $errors;
}

function toBool(array $data, string $key): string {
    return !empty($data[$key]) ? 't' : 'f';
}

function emptyToNull(array $data, string $key): ?string {
    $val = $data[$key] ?? null;
    return ($val !== null && $val !== '') ? $val : null;
}

function intOrNull(array $data, string $key): ?int {
    $val = $data[$key] ?? null;
    return ($val !== null && $val !== '' && is_numeric($val)) ? (int)$val : null;
}
