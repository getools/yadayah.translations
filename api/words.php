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
    case 'PATCH':
        handlePatch($db, $user);
        break;
    default:
        errorResponse('Method not allowed', 405);
}

function handleGet(PDO $db): void {
    // Single word by ID with translits
    if (isset($_GET['word_key']) && ctype_digit($_GET['word_key'])) {
        $wordId = (int)$_GET['word_key'];
        $stmt = $db->prepare("SELECT * FROM yy_word WHERE word_key = ?");
        $stmt->execute([$wordId]);
        $word = $stmt->fetch();
        if (!$word) {
            errorResponse('Word not found', 404);
        }
        $spStmt = $db->prepare("SELECT word_translit_key, word_translit_text, word_translit_sort, word_translit_count_yy FROM yy_word_translit WHERE word_key = ? ORDER BY word_translit_sort, word_translit_key");
        $spStmt->execute([$wordId]);
        $word['translits'] = $spStmt->fetchAll();
        jsonResponse($word);
    }

    // Filter word IDs by scripture scope (Book/Chapter/Verse)
    if (isset($_GET['filter_scroll']) && ctype_digit($_GET['filter_scroll'])) {
        $scrollKey = (int)$_GET['filter_scroll'];
        $chapterKey = isset($_GET['filter_chapter']) && ctype_digit($_GET['filter_chapter']) ? (int)$_GET['filter_chapter'] : null;
        $verseKey = isset($_GET['filter_verse']) && ctype_digit($_GET['filter_verse']) ? (int)$_GET['filter_verse'] : null;

        $sql = "SELECT translation_copy FROM yy_translation WHERE cite_book_key = ?";
        $params = [$scrollKey];
        if ($chapterKey) { $sql .= " AND cite_chapter_key = ?"; $params[] = $chapterKey; }
        if ($verseKey) { $sql .= " AND cite_verse_key = ?"; $params[] = $verseKey; }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $translits = [];
        while ($row = $stmt->fetch()) {
            $copy = $row['translation_copy'];
            if ($copy && preg_match_all('/<span\s+class="word"[^>]*>(.*?)<\/span>/si', $copy, $m)) {
                foreach ($m[1] as $w) {
                    $clean = strtolower(trim(strip_tags($w)));
                    if ($clean !== '') $translits[$clean] = true;
                }
            }
        }

        if (empty($translits)) { jsonResponse(['word_ids' => []]); }

        $phs = implode(',', array_fill(0, count($translits), '?'));
        $stmt2 = $db->prepare("SELECT DISTINCT word_key FROM yy_word_translit WHERE LOWER(word_translit_text) IN ($phs)");
        $stmt2->execute(array_keys($translits));
        $ids = array_map('intval', array_column($stmt2->fetchAll(), 'word_key'));

        jsonResponse(['word_ids' => $ids]);
    }

    // Search
    if (isset($_GET['search']) && trim($_GET['search']) !== '') {
        $term = '%' . trim($_GET['search']) . '%';
        $stmt = $db->prepare("
            SELECT DISTINCT w.word_key, w.word_strongs, w.word_hebrew, w.word_yt, w.word_translit, w.word_active_flag,
                   w.word_count_yy,
                   (SELECT string_agg(s.word_translit_text, ', ' ORDER BY s.word_translit_sort, s.word_translit_key)
                    FROM yy_word_translit s WHERE s.word_key = w.word_key) AS translits_display,
                   (SELECT string_agg(d.word_definition_text, ' | ' ORDER BY d.word_pos_key)
                    FROM yy_word_definition d WHERE d.word_key = w.word_key AND d.word_definition_active_flag = true
                    AND d.word_definition_text IS NOT NULL AND d.word_definition_text != '') AS definitions_display
            FROM yy_word w
            LEFT JOIN yy_word_translit s ON s.word_key = w.word_key
            LEFT JOIN yy_word_definition d2 ON d2.word_key = w.word_key AND d2.word_definition_active_flag = true
            WHERE w.word_strongs LIKE ?
               OR w.word_hebrew LIKE ?
               OR w.word_translit LIKE ?
               OR s.word_translit_text ILIKE ?
               OR d2.word_definition_text ILIKE ?
            ORDER BY w.word_strongs
            LIMIT 200
        ");
        $stmt->execute([$term, $term, $term, $term, $term]);
        jsonResponse($stmt->fetchAll());
    }

    // List all
    if (isset($_GET['list']) && $_GET['list'] === 'all') {
        $stmt = $db->query("
            SELECT w.word_key, w.word_strongs, w.word_hebrew, w.word_yt, w.word_translit, w.word_active_flag,
                   w.word_count_yy,
                   (SELECT string_agg(s.word_translit_text, ', ' ORDER BY s.word_translit_sort, s.word_translit_key)
                    FROM yy_word_translit s WHERE s.word_key = w.word_key) AS translits_display,
                   (SELECT string_agg(d.word_definition_text, ' | ' ORDER BY d.word_pos_key)
                    FROM yy_word_definition d WHERE d.word_key = w.word_key AND d.word_definition_active_flag = true
                    AND d.word_definition_text IS NOT NULL AND d.word_definition_text != '') AS definitions_display
            FROM yy_word w
            ORDER BY w.word_strongs
        ");
        jsonResponse($stmt->fetchAll());
    }

    errorResponse('Provide word_key, search, or list=all');
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
            INSERT INTO yy_word (word_strongs, word_hebrew, word_active_flag)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            str_pad(trim($data['word_strongs']), 4, '0', STR_PAD_LEFT),
            trim($data['word_hebrew']),
            !empty($data['word_active_flag']) ? 't' : 'f',
        ]);
        $wordId = (int)$db->lastInsertId('yy_word_word_key_seq');
        insertTranslits($db, $wordId, $data['translits'] ?? []);
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
    $wordId = $_GET['word_key'] ?? null;
    if (!$wordId || !ctype_digit($wordId)) {
        errorResponse('word_key is required');
    }
    $wordId = (int)$wordId;

    $check = $db->prepare('SELECT word_key FROM yy_word WHERE word_key = ?');
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
                word_active_flag = ?
            WHERE word_key = ?
        ");
        $stmt->execute([
            str_pad(trim($data['word_strongs']), 4, '0', STR_PAD_LEFT),
            trim($data['word_hebrew']),
            !empty($data['word_active_flag']) ? 't' : 'f',
            $wordId,
        ]);

        // Replace translits
        $del = $db->prepare("DELETE FROM yy_word_translit WHERE word_key = ?");
        $del->execute([$wordId]);
        insertTranslits($db, $wordId, $data['translits'] ?? []);
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        errorResponse('Failed to update word: ' . $e->getMessage(), 500);
    }

    returnWord($db, $wordId);
}

function handleDelete(PDO $db, array $user): void {
    setCurrentUser($db, $user['user_key']);
    $wordId = $_GET['word_key'] ?? null;
    if (!$wordId || !ctype_digit($wordId)) {
        errorResponse('word_key is required');
    }
    $wordId = (int)$wordId;

    $check = $db->prepare('SELECT word_key FROM yy_word WHERE word_key = ?');
    $check->execute([$wordId]);
    if (!$check->fetch()) {
        errorResponse('Word not found', 404);
    }

    $db->beginTransaction();
    try {
        $del = $db->prepare("DELETE FROM yy_word_translit WHERE word_key = ?");
        $del->execute([$wordId]);
        $del2 = $db->prepare("DELETE FROM yy_word WHERE word_key = ?");
        $del2->execute([$wordId]);
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        errorResponse('Failed to delete word: ' . $e->getMessage(), 500);
    }

    jsonResponse(['deleted' => true]);
}

function handlePatch(PDO $db, array $user): void {
    setCurrentUser($db, $user['user_key']);
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) errorResponse('Invalid JSON');

    // Reorder translits: expects { word_key: N, translit_order: [key1, key2, ...] }
    if (isset($data['translit_order'])) {
        $wordKey = (int)($data['word_key'] ?? 0);
        if (!$wordKey) errorResponse('word_key is required');
        $order = $data['translit_order'];
        if (!is_array($order)) errorResponse('translit_order must be an array');

        $stmt = $db->prepare("UPDATE yy_word_translit SET word_translit_sort = ? WHERE word_translit_key = ? AND word_key = ?");
        foreach ($order as $sort => $translitKey) {
            $stmt->execute([$sort, (int)$translitKey, $wordKey]);
        }
        jsonResponse(['success' => true]);
    }

    // Update individual word fields: expects { word_key: N, fields: { col: val, ... } }
    if (isset($data['fields'])) {
        $wordKey = (int)($data['word_key'] ?? 0);
        if (!$wordKey) errorResponse('word_key is required');

        $allowed = ['word_strongs', 'word_hebrew', 'word_active_flag'];
        $sets = [];
        $params = [];
        foreach ($data['fields'] as $col => $val) {
            if (!in_array($col, $allowed)) continue;
            if ($col === 'word_strongs') {
                $sets[] = "$col = ?";
                $params[] = str_pad(trim($val), 4, '0', STR_PAD_LEFT);
            } elseif ($col === 'word_active_flag') {
                $sets[] = "$col = ?";
                $params[] = $val ? 't' : 'f';
            } else {
                $sets[] = "$col = ?";
                $params[] = trim($val);
            }
        }
        if (empty($sets)) errorResponse('No valid fields to update');

        $params[] = $wordKey;
        $stmt = $db->prepare("UPDATE yy_word SET " . implode(', ', $sets) . " WHERE word_key = ?");
        $stmt->execute($params);

        // Return updated word
        $stmt = $db->prepare("SELECT * FROM yy_word WHERE word_key = ?");
        $stmt->execute([$wordKey]);
        jsonResponse($stmt->fetch());
    }

    errorResponse('Unknown PATCH operation');
}

function insertTranslits(PDO $db, int $wordId, array $translits): void {
    if (empty($translits)) return;
    $stmt = $db->prepare("INSERT INTO yy_word_translit (word_key, word_translit_text, word_translit_sort, word_translit_count_yy) VALUES (?, ?, ?, ?)");
    $sort = 0;
    foreach ($translits as $sp) {
        $text = is_array($sp) ? ($sp['word_translit_text'] ?? '') : (string)$sp;
        $text = strtolower(trim($text));
        if ($text !== '') {
            $count = countTranslitInTranslations($db, $text);
            $stmt->execute([$wordId, $text, $sort, $count]);
            $sort++;
        }
    }
}

function countTranslitInTranslations(PDO $db, string $translit): int {
    // Extract italicized text from all translations and count occurrences
    $stmt = $db->query("SELECT translation_copy FROM yy_translation WHERE translation_copy IS NOT NULL");
    $pattern = '/(?<![a-zA-Z\'])' . preg_quote(strtolower($translit), '/') . '(?![a-zA-Z\'])/i';
    $count = 0;
    while ($row = $stmt->fetch()) {
        $copy = $row['translation_copy'];
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
    $stmt = $db->prepare("SELECT * FROM yy_word WHERE word_key = ?");
    $stmt->execute([$wordId]);
    $word = $stmt->fetch();
    $spStmt = $db->prepare("SELECT word_translit_key, word_translit_text, word_translit_sort, word_translit_count_yy FROM yy_word_translit WHERE word_key = ? ORDER BY word_translit_sort, word_translit_key");
    $spStmt->execute([$wordId]);
    $word['translits'] = $spStmt->fetchAll();
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
