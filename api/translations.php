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
                t.translation_key,
                t.cite_book_key, t.cite_chapter_key, t.cite_verse_key,
                t.series_key, t.volume_key, t.chapter_key,
                cb.cite_book_hebrew, cb.cite_book_common,
                c.cite_chapter_number, v.cite_verse_number,
                COALESCE(ser.series_label, ser.series_name) AS series_display,
                vol.volume_label AS volume_display,
                t.translation_page,
                t.translation_paragraph,
                ych.chapter_number AS yy_ch_number,
                cb.cite_book_sort, ser.series_sort, vol.volume_number, ych.chapter_sort
            FROM yy_translation t
            JOIN yy_cite_book cb ON cb.cite_book_key = t.cite_book_key
            JOIN yy_cite_chapter c ON c.cite_chapter_key = t.cite_chapter_key
            JOIN yy_cite_verse v ON v.cite_verse_key = t.cite_verse_key
            JOIN yy_series ser ON ser.series_key = t.series_key
            JOIN yy_volume vol ON vol.volume_key = t.volume_key
            LEFT JOIN yy_chapter ych ON ych.chapter_key = t.chapter_key
            ORDER BY cb.cite_book_sort, c.cite_chapter_number, v.cite_verse_number,
                     ser.series_sort, vol.volume_number, ych.chapter_sort, t.translation_page
        ");
        jsonResponse($stmt->fetchAll());
    }

    // Single translation by key
    if (isset($_GET['translation_key']) && ctype_digit($_GET['translation_key'])) {
        $stmt = $db->prepare("
            SELECT t.*,
                cb.cite_book_hebrew, cb.cite_book_common,
                c.cite_chapter_number, v.cite_verse_number,
                ser.series_name, vol.volume_label, vol.volume_number,
                ych.chapter_number AS yy_ch_number, ych.chapter_name AS yy_ch_name
            FROM yy_translation t
            JOIN yy_cite_book cb ON cb.cite_book_key = t.cite_book_key
            JOIN yy_cite_chapter c ON c.cite_chapter_key = t.cite_chapter_key
            JOIN yy_cite_verse v ON v.cite_verse_key = t.cite_verse_key
            JOIN yy_series ser ON ser.series_key = t.series_key
            JOIN yy_volume vol ON vol.volume_key = t.volume_key
            LEFT JOIN yy_chapter ych ON ych.chapter_key = t.chapter_key
            WHERE t.translation_key = ?
        ");
        $stmt->execute([(int)$_GET['translation_key']]);
        $row = $stmt->fetch();
        if (!$row) {
            errorResponse('Translation not found', 404);
        }
        jsonResponse($row);
    }

    // Translations with flexible filtering: cite_book_key, chapter_key, verse_key
    $citeBookKey = $_GET['cite_book_key'] ?? null;
    $chapterKey = $_GET['chapter_key'] ?? null;
    $verseKey = $_GET['verse_key'] ?? null;

    // Must have at least one filter (or use list=all above)
    if (!$verseKey && !$chapterKey && !$citeBookKey) {
        // Show all translations if 'all_translations' flag is set
        if (isset($_GET['all_translations'])) {
            $citeBookKey = null; // no filter
        } else {
            errorResponse('verse_key, chapter_key, cite_book_key, all_translations, translation_key, or list=all is required');
        }
    }

    $where = [];
    $params = [];
    if ($citeBookKey && ctype_digit($citeBookKey)) {
        $where[] = 't.cite_book_key = ?';
        $params[] = (int)$citeBookKey;
    }
    if ($chapterKey && ctype_digit($chapterKey)) {
        $where[] = 't.cite_chapter_key = ?';
        $params[] = (int)$chapterKey;
    }
    if ($verseKey && ctype_digit($verseKey)) {
        $where[] = 't.cite_verse_key = ?';
        $params[] = (int)$verseKey;
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $db->prepare("
        SELECT
            t.translation_key,
            t.cite_book_key,
            t.cite_chapter_key,
            t.cite_verse_key,
            t.series_key,
            t.volume_key,
            t.chapter_key,
            t.translation_page,
            t.translation_paragraph,
            t.translation_copy,
            t.translation_date,
            t.translation_sort,
            t.translation_dtime,
            cb.cite_book_hebrew,
            cb.cite_book_common,
            c.cite_chapter_number,
            v.cite_verse_number,
            ser.series_name,
            vol.volume_label,
            vol.volume_number,
            ych.chapter_number AS yy_ch_number,
            ych.chapter_name AS yy_ch_name
        FROM yy_translation t
        JOIN yy_cite_book cb ON cb.cite_book_key = t.cite_book_key
        JOIN yy_cite_chapter c ON c.cite_chapter_key = t.cite_chapter_key
        JOIN yy_cite_verse v ON v.cite_verse_key = t.cite_verse_key
        JOIN yy_series ser ON ser.series_key = t.series_key
        JOIN yy_volume vol ON vol.volume_key = t.volume_key
        LEFT JOIN yy_chapter ych ON ych.chapter_key = t.chapter_key
        $whereClause
        ORDER BY cb.cite_book_sort, c.cite_chapter_number, v.cite_verse_number,
                 t.translation_sort DESC, t.translation_dtime DESC
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
            (cite_book_key, cite_chapter_key, cite_verse_key, series_key, volume_key, chapter_key,
             translation_page, translation_paragraph, translation_copy, translation_date, translation_sort)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int)$data['cite_book_key'],
        (int)$data['cite_chapter_key'],
        (int)$data['cite_verse_key'],
        (int)$data['series_key'],
        (int)$data['volume_key'],
        (int)$data['chapter_key'],
        isset($data['translation_page']) && $data['translation_page'] !== '' ? (int)$data['translation_page'] : null,
        isset($data['translation_paragraph']) && $data['translation_paragraph'] !== '' ? (int)$data['translation_paragraph'] : null,
        $data['translation_copy'],
        isset($data['translation_date']) && $data['translation_date'] !== '' ? $data['translation_date'] : null,
        isset($data['translation_sort']) && $data['translation_sort'] !== '' ? (int)$data['translation_sort'] : 0,
    ]);

    $newKey = $db->lastInsertId('yy_translation_translation_key_seq');

    $stmt = $db->prepare("
        SELECT t.*, cb.cite_book_hebrew, cb.cite_book_common,
               c.cite_chapter_number, v.cite_verse_number,
               ser.series_name, vol.volume_name, vol.volume_number,
               ych.chapter_number AS yy_ch_number, ych.chapter_name AS yy_ch_name
        FROM yy_translation t
        JOIN yy_cite_book cb ON cb.cite_book_key = t.cite_book_key
        JOIN yy_cite_chapter c ON c.cite_chapter_key = t.cite_chapter_key
        JOIN yy_cite_verse v ON v.cite_verse_key = t.cite_verse_key
        JOIN yy_series ser ON ser.series_key = t.series_key
        JOIN yy_volume vol ON vol.volume_key = t.volume_key
        LEFT JOIN yy_chapter ych ON ych.chapter_key = t.chapter_key
        WHERE t.translation_key = ?
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

    $check = $db->prepare('SELECT translation_key FROM yy_translation WHERE translation_key = ?');
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
            cite_book_key = ?,
            cite_chapter_key = ?,
            cite_verse_key = ?,
            series_key = ?,
            volume_key = ?,
            chapter_key = ?,
            translation_page = ?,
            translation_paragraph = ?,
            translation_copy = ?,
            translation_date = ?,
            translation_sort = ?
        WHERE translation_key = ?
    ");
    $stmt->execute([
        (int)$data['cite_book_key'],
        (int)$data['cite_chapter_key'],
        (int)$data['cite_verse_key'],
        (int)$data['series_key'],
        (int)$data['volume_key'],
        (int)$data['chapter_key'],
        isset($data['translation_page']) && $data['translation_page'] !== '' ? (int)$data['translation_page'] : null,
        isset($data['translation_paragraph']) && $data['translation_paragraph'] !== '' ? (int)$data['translation_paragraph'] : null,
        $data['translation_copy'],
        isset($data['translation_date']) && $data['translation_date'] !== '' ? $data['translation_date'] : null,
        isset($data['translation_sort']) && $data['translation_sort'] !== '' ? (int)$data['translation_sort'] : 0,
        (int)$key,
    ]);

    $stmt = $db->prepare("
        SELECT t.*, cb.cite_book_hebrew, cb.cite_book_common,
               c.cite_chapter_number, v.cite_verse_number,
               ser.series_name, vol.volume_name, vol.volume_number,
               ych.chapter_number AS yy_ch_number, ych.chapter_name AS yy_ch_name
        FROM yy_translation t
        JOIN yy_cite_book cb ON cb.cite_book_key = t.cite_book_key
        JOIN yy_cite_chapter c ON c.cite_chapter_key = t.cite_chapter_key
        JOIN yy_cite_verse v ON v.cite_verse_key = t.cite_verse_key
        JOIN yy_series ser ON ser.series_key = t.series_key
        JOIN yy_volume vol ON vol.volume_key = t.volume_key
        LEFT JOIN yy_chapter ych ON ych.chapter_key = t.chapter_key
        WHERE t.translation_key = ?
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

    $check = $db->prepare('SELECT translation_key FROM yy_translation WHERE translation_key = ?');
    $check->execute([(int)$key]);
    if (!$check->fetch()) {
        errorResponse('Translation not found', 404);
    }

    $stmt = $db->prepare('DELETE FROM yy_translation WHERE translation_key = ?');
    $stmt->execute([(int)$key]);
    jsonResponse(['deleted' => true]);
}

function validateTranslation(array $data, PDO $db): array {
    $errors = [];

    $fkFields = [
        'cite_book_key' => ['table' => 'yy_cite_book', 'pk' => 'cite_book_key', 'label' => 'Book'],
        'cite_chapter_key' => ['table' => 'yy_cite_chapter', 'pk' => 'cite_chapter_key', 'label' => 'Chapter'],
        'cite_verse_key' => ['table' => 'yy_cite_verse', 'pk' => 'cite_verse_key', 'label' => 'Verse'],
        'series_key' => ['table' => 'yy_series', 'pk' => 'series_key', 'label' => 'Series'],
        'volume_key' => ['table' => 'yy_volume', 'pk' => 'volume_key', 'label' => 'Volume'],
        'chapter_key' => ['table' => 'yy_chapter', 'pk' => 'chapter_key', 'label' => 'YY Chapter'],
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

    if (empty($data['translation_copy']) || trim(strip_tags($data['translation_copy'])) === '') {
        $errors[] = 'Translation text is required.';
    }

    if (isset($data['translation_page']) && $data['translation_page'] !== '' && $data['translation_page'] !== null) {
        if (!is_numeric($data['translation_page']) || (int)$data['translation_page'] < 1) {
            $errors[] = 'Page must be a positive integer.';
        }
    }

    if (isset($data['translation_paragraph']) && $data['translation_paragraph'] !== '' && $data['translation_paragraph'] !== null) {
        if (!is_numeric($data['translation_paragraph']) || (int)$data['translation_paragraph'] < 1) {
            $errors[] = 'Paragraph must be a positive integer.';
        }
    }

    if (isset($data['translation_date']) && $data['translation_date'] !== '' && $data['translation_date'] !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $data['translation_date']);
        if (!$d || $d->format('Y-m-d') !== $data['translation_date']) {
            $errors[] = 'Date must be in YYYY-MM-DD format.';
        }
    }

    if (isset($data['translation_sort']) && $data['translation_sort'] !== '' && $data['translation_sort'] !== null) {
        if (!is_numeric($data['translation_sort']) || (int)$data['translation_sort'] < 0) {
            $errors[] = 'Sort must be a non-negative integer.';
        }
    }

    return $errors;
}
