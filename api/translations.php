<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet($db, $user);
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

function handleGet(PDO $db, array $user): void {
    // List all translations
    if (isset($_GET['list']) && $_GET['list'] === 'all') {
        $stmt = $db->query("
            SELECT
                t.yy_translation_key,
                t.yah_scroll_key, t.yah_chapter_key, t.yah_verse_key,
                t.yy_series_key, t.yy_volume_key, t.yy_chapter_key,
                s.yah_scroll_label_yy, s.yah_scroll_label_common,
                c.yah_chapter_number, v.yah_verse_number,
                COALESCE(ser.yy_series_label, ser.yy_series_name) AS series_display,
                COALESCE(vol.yy_volume_label, vol.yy_volume_name) AS volume_display,
                t.yy_translation_page,
                ych.yy_chapter_number AS yy_ch_number,
                s.yah_scroll_sort, ser.yy_series_sort, vol.yy_volume_number, ych.yy_chapter_sort
            FROM yy_translation t
            JOIN yah_scroll s ON s.yah_scroll_key = t.yah_scroll_key
            JOIN yah_chapter c ON c.yah_chapter_key = t.yah_chapter_key
            JOIN yah_verse v ON v.yah_verse_key = t.yah_verse_key
            JOIN yy_series ser ON ser.yy_series_key = t.yy_series_key
            JOIN yy_volume vol ON vol.yy_volume_key = t.yy_volume_key
            LEFT JOIN yy_chapter ych ON ych.yy_chapter_key = t.yy_chapter_key
            ORDER BY s.yah_scroll_sort, c.yah_chapter_number, v.yah_verse_number,
                     ser.yy_series_sort, vol.yy_volume_number, ych.yy_chapter_sort, t.yy_translation_page
        ");
        jsonResponse($stmt->fetchAll());
    }

    // Single translation by key
    if (isset($_GET['translation_key']) && ctype_digit($_GET['translation_key'])) {
        $stmt = $db->prepare("
            SELECT t.*,
                s.yah_scroll_label_yy, s.yah_scroll_label_common,
                c.yah_chapter_number, v.yah_verse_number,
                ser.yy_series_name, vol.yy_volume_name, vol.yy_volume_number,
                ych.yy_chapter_number AS yy_ch_number, ych.yy_chapter_name AS yy_ch_name
            FROM yy_translation t
            JOIN yah_scroll s ON s.yah_scroll_key = t.yah_scroll_key
            JOIN yah_chapter c ON c.yah_chapter_key = t.yah_chapter_key
            JOIN yah_verse v ON v.yah_verse_key = t.yah_verse_key
            JOIN yy_series ser ON ser.yy_series_key = t.yy_series_key
            JOIN yy_volume vol ON vol.yy_volume_key = t.yy_volume_key
            LEFT JOIN yy_chapter ych ON ych.yy_chapter_key = t.yy_chapter_key
            WHERE t.yy_translation_key = ?
        ");
        $stmt->execute([(int)$_GET['translation_key']]);
        $row = $stmt->fetch();
        if (!$row) {
            errorResponse('Translation not found', 404);
        }
        jsonResponse($row);
    }

    // Translations with flexible filtering: scroll_key, chapter_key, verse_key
    $scrollKey = $_GET['scroll_key'] ?? null;
    $chapterKey = $_GET['chapter_key'] ?? null;
    $verseKey = $_GET['verse_key'] ?? null;

    // Must have at least one filter (or use list=all above)
    if (!$verseKey && !$chapterKey && !$scrollKey) {
        // Show all translations if 'all_translations' flag is set
        if (isset($_GET['all_translations'])) {
            $scrollKey = null; // no filter
        } else {
            errorResponse('verse_key, chapter_key, scroll_key, all_translations, translation_key, or list=all is required');
        }
    }

    $where = [];
    $params = [];
    if ($scrollKey && ctype_digit($scrollKey)) {
        $where[] = 't.yah_scroll_key = ?';
        $params[] = (int)$scrollKey;
    }
    if ($chapterKey && ctype_digit($chapterKey)) {
        $where[] = 't.yah_chapter_key = ?';
        $params[] = (int)$chapterKey;
    }
    if ($verseKey && ctype_digit($verseKey)) {
        $where[] = 't.yah_verse_key = ?';
        $params[] = (int)$verseKey;
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $db->prepare("
        SELECT
            t.yy_translation_key,
            t.yah_scroll_key,
            t.yah_chapter_key,
            t.yah_verse_key,
            t.yy_series_key,
            t.yy_volume_key,
            t.yy_chapter_key,
            t.yy_translation_page,
            t.yy_translation_paragraph,
            t.yy_translation_copy,
            t.yy_translation_date,
            t.yy_translation_sort,
            t.yy_translation_dtime,
            s.yah_scroll_label_yy,
            s.yah_scroll_label_common,
            c.yah_chapter_number,
            v.yah_verse_number,
            ser.yy_series_name,
            vol.yy_volume_name,
            vol.yy_volume_number,
            ych.yy_chapter_number AS yy_ch_number,
            ych.yy_chapter_name AS yy_ch_name
        FROM yy_translation t
        JOIN yah_scroll s ON s.yah_scroll_key = t.yah_scroll_key
        JOIN yah_chapter c ON c.yah_chapter_key = t.yah_chapter_key
        JOIN yah_verse v ON v.yah_verse_key = t.yah_verse_key
        JOIN yy_series ser ON ser.yy_series_key = t.yy_series_key
        JOIN yy_volume vol ON vol.yy_volume_key = t.yy_volume_key
        LEFT JOIN yy_chapter ych ON ych.yy_chapter_key = t.yy_chapter_key
        $whereClause
        ORDER BY s.yah_scroll_sort, c.yah_chapter_number, v.yah_verse_number,
                 t.yy_translation_sort DESC, t.yy_translation_dtime DESC
    ");
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
}

function handlePost(PDO $db, array $user): void {
    setCurrentUser($db, $user['user_key']);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        errorResponse('Invalid JSON body');
    }

    $errors = validateTranslation($data, $db);
    if (!empty($errors)) {
        jsonResponse(['errors' => $errors], 422);
    }

    $stmt = $db->prepare("
        INSERT INTO yy_translation
            (yah_scroll_key, yah_chapter_key, yah_verse_key, yy_series_key, yy_volume_key, yy_chapter_key,
             yy_translation_page, yy_translation_paragraph, yy_translation_copy, yy_translation_date, yy_translation_sort)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int)$data['yah_scroll_key'],
        (int)$data['yah_chapter_key'],
        (int)$data['yah_verse_key'],
        (int)$data['yy_series_key'],
        (int)$data['yy_volume_key'],
        (int)$data['yy_chapter_key'],
        isset($data['yy_translation_page']) && $data['yy_translation_page'] !== '' ? (int)$data['yy_translation_page'] : null,
        isset($data['yy_translation_paragraph']) && $data['yy_translation_paragraph'] !== '' ? (int)$data['yy_translation_paragraph'] : null,
        $data['yy_translation_copy'],
        isset($data['yy_translation_date']) && $data['yy_translation_date'] !== '' ? $data['yy_translation_date'] : null,
        isset($data['yy_translation_sort']) && $data['yy_translation_sort'] !== '' ? (int)$data['yy_translation_sort'] : 0,
    ]);

    $newKey = $db->lastInsertId('yy_translation_yy_translation_key_seq');

    $stmt = $db->prepare("
        SELECT t.*, s.yah_scroll_label_yy, s.yah_scroll_label_common,
               c.yah_chapter_number, v.yah_verse_number,
               ser.yy_series_name, vol.yy_volume_name, vol.yy_volume_number,
               ych.yy_chapter_number AS yy_ch_number, ych.yy_chapter_name AS yy_ch_name
        FROM yy_translation t
        JOIN yah_scroll s ON s.yah_scroll_key = t.yah_scroll_key
        JOIN yah_chapter c ON c.yah_chapter_key = t.yah_chapter_key
        JOIN yah_verse v ON v.yah_verse_key = t.yah_verse_key
        JOIN yy_series ser ON ser.yy_series_key = t.yy_series_key
        JOIN yy_volume vol ON vol.yy_volume_key = t.yy_volume_key
        LEFT JOIN yy_chapter ych ON ych.yy_chapter_key = t.yy_chapter_key
        WHERE t.yy_translation_key = ?
    ");
    $stmt->execute([(int)$newKey]);
    jsonResponse($stmt->fetch(), 201);
}

function handlePut(PDO $db, array $user): void {
    setCurrentUser($db, $user['user_key']);

    $key = $_GET['key'] ?? null;
    if (!$key || !ctype_digit($key)) {
        errorResponse('key is required and must be an integer');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        errorResponse('Invalid JSON body');
    }

    $check = $db->prepare('SELECT yy_translation_key FROM yy_translation WHERE yy_translation_key = ?');
    $check->execute([(int)$key]);
    if (!$check->fetch()) {
        errorResponse('Translation not found', 404);
    }

    $errors = validateTranslation($data, $db);
    if (!empty($errors)) {
        jsonResponse(['errors' => $errors], 422);
    }

    $stmt = $db->prepare("
        UPDATE yy_translation SET
            yah_scroll_key = ?,
            yah_chapter_key = ?,
            yah_verse_key = ?,
            yy_series_key = ?,
            yy_volume_key = ?,
            yy_chapter_key = ?,
            yy_translation_page = ?,
            yy_translation_paragraph = ?,
            yy_translation_copy = ?,
            yy_translation_date = ?,
            yy_translation_sort = ?
        WHERE yy_translation_key = ?
    ");
    $stmt->execute([
        (int)$data['yah_scroll_key'],
        (int)$data['yah_chapter_key'],
        (int)$data['yah_verse_key'],
        (int)$data['yy_series_key'],
        (int)$data['yy_volume_key'],
        (int)$data['yy_chapter_key'],
        isset($data['yy_translation_page']) && $data['yy_translation_page'] !== '' ? (int)$data['yy_translation_page'] : null,
        isset($data['yy_translation_paragraph']) && $data['yy_translation_paragraph'] !== '' ? (int)$data['yy_translation_paragraph'] : null,
        $data['yy_translation_copy'],
        isset($data['yy_translation_date']) && $data['yy_translation_date'] !== '' ? $data['yy_translation_date'] : null,
        isset($data['yy_translation_sort']) && $data['yy_translation_sort'] !== '' ? (int)$data['yy_translation_sort'] : 0,
        (int)$key,
    ]);

    $stmt = $db->prepare("
        SELECT t.*, s.yah_scroll_label_yy, s.yah_scroll_label_common,
               c.yah_chapter_number, v.yah_verse_number,
               ser.yy_series_name, vol.yy_volume_name, vol.yy_volume_number,
               ych.yy_chapter_number AS yy_ch_number, ych.yy_chapter_name AS yy_ch_name
        FROM yy_translation t
        JOIN yah_scroll s ON s.yah_scroll_key = t.yah_scroll_key
        JOIN yah_chapter c ON c.yah_chapter_key = t.yah_chapter_key
        JOIN yah_verse v ON v.yah_verse_key = t.yah_verse_key
        JOIN yy_series ser ON ser.yy_series_key = t.yy_series_key
        JOIN yy_volume vol ON vol.yy_volume_key = t.yy_volume_key
        LEFT JOIN yy_chapter ych ON ych.yy_chapter_key = t.yy_chapter_key
        WHERE t.yy_translation_key = ?
    ");
    $stmt->execute([(int)$key]);
    jsonResponse($stmt->fetch());
}

function handleDelete(PDO $db, array $user): void {
    setCurrentUser($db, $user['user_key']);

    $key = $_GET['key'] ?? null;
    if (!$key || !ctype_digit($key)) {
        errorResponse('key is required and must be an integer');
    }

    $check = $db->prepare('SELECT yy_translation_key FROM yy_translation WHERE yy_translation_key = ?');
    $check->execute([(int)$key]);
    if (!$check->fetch()) {
        errorResponse('Translation not found', 404);
    }

    $stmt = $db->prepare('DELETE FROM yy_translation WHERE yy_translation_key = ?');
    $stmt->execute([(int)$key]);
    jsonResponse(['deleted' => true]);
}

function validateTranslation(array $data, PDO $db): array {
    $errors = [];

    $fkFields = [
        'yah_scroll_key' => ['table' => 'yah_scroll', 'pk' => 'yah_scroll_key', 'label' => 'Scroll'],
        'yah_chapter_key' => ['table' => 'yah_chapter', 'pk' => 'yah_chapter_key', 'label' => 'Chapter'],
        'yah_verse_key' => ['table' => 'yah_verse', 'pk' => 'yah_verse_key', 'label' => 'Verse'],
        'yy_series_key' => ['table' => 'yy_series', 'pk' => 'yy_series_key', 'label' => 'Series'],
        'yy_volume_key' => ['table' => 'yy_volume', 'pk' => 'yy_volume_key', 'label' => 'Volume'],
        'yy_chapter_key' => ['table' => 'yy_chapter', 'pk' => 'yy_chapter_key', 'label' => 'YY Chapter'],
    ];

    foreach ($fkFields as $field => $info) {
        if (empty($data[$field]) || !is_numeric($data[$field]) || (int)$data[$field] <= 0) {
            $errors[] = "{$info['label']} is required.";
        } else {
            $stmt = $db->prepare("SELECT {$info['pk']} FROM {$info['table']} WHERE {$info['pk']} = ?");
            $stmt->execute([(int)$data[$field]]);
            if (!$stmt->fetch()) {
                $errors[] = "Invalid {$info['label']} selection.";
            }
        }
    }

    if (empty($data['yy_translation_copy']) || trim(strip_tags($data['yy_translation_copy'])) === '') {
        $errors[] = 'Translation text is required.';
    }

    if (isset($data['yy_translation_page']) && $data['yy_translation_page'] !== '' && $data['yy_translation_page'] !== null) {
        if (!is_numeric($data['yy_translation_page']) || (int)$data['yy_translation_page'] < 1) {
            $errors[] = 'Page must be a positive integer.';
        }
    }

    if (isset($data['yy_translation_paragraph']) && $data['yy_translation_paragraph'] !== '' && $data['yy_translation_paragraph'] !== null) {
        if (!is_numeric($data['yy_translation_paragraph']) || (int)$data['yy_translation_paragraph'] < 1) {
            $errors[] = 'Paragraph must be a positive integer.';
        }
    }

    if (isset($data['yy_translation_date']) && $data['yy_translation_date'] !== '' && $data['yy_translation_date'] !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $data['yy_translation_date']);
        if (!$d || $d->format('Y-m-d') !== $data['yy_translation_date']) {
            $errors[] = 'Date must be in YYYY-MM-DD format.';
        }
    }

    if (isset($data['yy_translation_sort']) && $data['yy_translation_sort'] !== '' && $data['yy_translation_sort'] !== null) {
        if (!is_numeric($data['yy_translation_sort']) || (int)$data['yy_translation_sort'] < 0) {
            $errors[] = 'Sort must be a non-negative integer.';
        }
    }

    return $errors;
}
