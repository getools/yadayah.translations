<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$pdo = getDb();
setCurrentUser($pdo, $user['user_key']);

$action = $_GET['action'] ?? '';
$table  = $_GET['table'] ?? '';

// ── Table metadata ──
$TABLES = [
    'yah_scroll' => [
        'pk' => 'yah_scroll_key',
        'order' => 'yah_scroll_sort ASC, yah_scroll_key ASC',
        'columns' => [
            ['name' => 'yah_scroll_key',          'label' => 'Key',    'type' => 'pk'],
            ['name' => 'yah_scroll_label_common',  'label' => 'Common Name', 'type' => 'text'],
            ['name' => 'yah_scroll_label_yy',      'label' => 'YY Name',     'type' => 'text'],
            ['name' => 'yah_scroll_sort',          'label' => 'Sort',         'type' => 'int'],
        ],
        'display' => "yah_scroll_label_yy || ' / ' || yah_scroll_label_common",
    ],
    'yy_cite_chapter' => [
        'pk' => 'cite_chapter_key',
        'order' => '(SELECT cb.cite_book_sort FROM yy_cite_book cb WHERE cb.cite_book_key = yy_cite_chapter.cite_book_key) ASC, (SELECT cb.cite_book_hebrew FROM yy_cite_book cb WHERE cb.cite_book_key = yy_cite_chapter.cite_book_key) ASC, cite_chapter_sort ASC, cite_chapter_number ASC',
        'columns' => [
            ['name' => 'cite_chapter_key',    'label' => 'Key',     'type' => 'pk'],
            ['name' => 'cite_book_key',      'label' => 'Book',    'type' => 'fk', 'fk_table' => 'yy_cite_book'],
            ['name' => 'cite_chapter_number',  'label' => 'Number',  'type' => 'int'],
            ['name' => 'cite_chapter_sort',   'label' => 'Sort',    'type' => 'int'],
        ],
    ],
    'yy_cite_verse' => [
        'pk' => 'cite_verse_key',
        'select' => "yy_cite_verse.*, (SELECT cb.cite_book_hebrew || ' / ' || cb.cite_book_common FROM yy_cite_book cb JOIN yy_cite_chapter c ON c.cite_book_key = cb.cite_book_key WHERE c.cite_chapter_key = yy_cite_verse.cite_chapter_key) AS scroll_label, (SELECT c.cite_chapter_number FROM yy_cite_chapter c WHERE c.cite_chapter_key = yy_cite_verse.cite_chapter_key) AS chapter_number",
        'order' => '(SELECT cb.cite_book_sort FROM yy_cite_book cb JOIN yy_cite_chapter c ON c.cite_book_key = cb.cite_book_key WHERE c.cite_chapter_key = yy_cite_verse.cite_chapter_key) ASC, (SELECT cb.cite_book_hebrew FROM yy_cite_book cb JOIN yy_cite_chapter c ON c.cite_book_key = cb.cite_book_key WHERE c.cite_chapter_key = yy_cite_verse.cite_chapter_key) ASC, (SELECT c.cite_chapter_sort FROM yy_cite_chapter c WHERE c.cite_chapter_key = yy_cite_verse.cite_chapter_key) ASC, (SELECT c.cite_chapter_number FROM yy_cite_chapter c WHERE c.cite_chapter_key = yy_cite_verse.cite_chapter_key) ASC, cite_verse_sort ASC, cite_verse_number ASC',
        'columns' => [
            ['name' => 'cite_verse_key',     'label' => 'Key',     'type' => 'pk'],
            ['name' => 'scroll_label',      'label' => 'Scroll',  'type' => 'readonly'],
            ['name' => 'chapter_number',    'label' => 'Chapter', 'type' => 'readonly'],
            ['name' => 'cite_verse_number',  'label' => 'Verse',   'type' => 'int'],
            ['name' => 'cite_verse_sort',    'label' => 'Sort',    'type' => 'int'],
        ],
    ],
    'yy_chapter' => [
        'pk' => 'chapter_key',
        'order' => 'volume_key ASC, chapter_sort ASC',
        'columns' => [
            ['name' => 'chapter_key',    'label' => 'Key',     'type' => 'pk'],
            ['name' => 'volume_key',     'label' => 'Volume',  'type' => 'fk', 'fk_table' => 'yy_volume'],
            ['name' => 'chapter_number', 'label' => 'Number',  'type' => 'int'],
            ['name' => 'chapter_page',   'label' => 'Page',    'type' => 'int'],
            ['name' => 'chapter_name',   'label' => 'Name',    'type' => 'text'],
            ['name' => 'chapter_label',  'label' => 'Label',   'type' => 'text'],
            ['name' => 'chapter_sort',   'label' => 'Sort',    'type' => 'int'],
        ],
    ],
    'yy_letter' => [
        'pk' => 'letter_key',
        'order' => 'letter_sort ASC, letter_key ASC',
        'columns' => [
            ['name' => 'letter_key',      'label' => 'Key',      'type' => 'pk'],
            ['name' => 'letter_label',    'label' => 'Label',    'type' => 'text'],
            ['name' => 'letter_yt',       'label' => 'YT',       'type' => 'text'],
            ['name' => 'letter_hebrew',   'label' => 'Hebrew',   'type' => 'hebrew'],
            ['name' => 'letter_overview',      'label' => 'Overview',       'type' => 'textarea'],
            ['name' => 'letter_numeric_value', 'label' => 'Numeric Value', 'type' => 'int'],
            ['name' => 'letter_sort',          'label' => 'Sort',          'type' => 'int'],
        ],
        'display' => "letter_yt || ' - ' || COALESCE(letter_label, '')",
        'edit_layout' => [
            ['letter_key'],
            [['letter_label', 'letter_yt'], 'letter_hebrew'],
            ['letter_overview'],
            ['letter_numeric_value', 'letter_sort'],
        ],
    ],
    'yy_cite' => [
        'pk' => 'cite_key',
        'order' => 'sort ASC, cite_key ASC',
        'columns' => [
            ['name' => 'cite_key', 'label' => 'ID',    'type' => 'pk'],
            ['name' => 'label',    'label' => 'Label', 'type' => 'text'],
            ['name' => 'sort',     'label' => 'Sort',  'type' => 'int'],
        ],
        'display' => 'label',
    ],
    'yy_cite_book' => [
        'pk' => 'cite_book_key',
        'order' => 'cite_book_sort ASC, cite_book_key ASC',
        'columns' => [
            ['name' => 'cite_book_key',            'label' => 'ID',             'type' => 'pk'],
            ['name' => 'cite_book_hebrew',        'label' => 'Hebrew Name',    'type' => 'hebrew'],
            ['name' => 'cite_book_common',        'label' => 'Common Name',    'type' => 'text'],
            ['name' => 'cite_book_definition',    'label' => 'Definition',     'type' => 'textarea'],
            ['name' => 'cite_book_chapter_count', 'label' => 'Chapter Count',  'type' => 'int'],
            ['name' => 'cite_book_sort',          'label' => 'Sort',           'type' => 'int'],
        ],
        'display' => "cite_book_hebrew || ' / ' || cite_book_common",
    ],
    'yy_cite_book_map' => [
        'pk' => 'cite_book_map_key',
        'order' => 'cite_book_key ASC, cite_book_map_key ASC',
        'columns' => [
            ['name' => 'cite_book_map_key',      'label' => 'ID',           'type' => 'pk'],
            ['name' => 'cite_book_key',           'label' => 'Cite Book',   'type' => 'fk', 'fk_table' => 'yy_cite_book'],
            ['name' => 'cite_book_map_hebrew',   'label' => 'Hebrew Map',  'type' => 'hebrew'],
        ],
    ],
    'yy_series' => [
        'pk' => 'series_key',
        'order' => 'series_sort ASC, series_key ASC',
        'columns' => [
            ['name' => 'series_key',    'label' => 'Key',    'type' => 'pk'],
            ['name' => 'series_number', 'label' => 'Number', 'type' => 'int'],
            ['name' => 'series_label',  'label' => 'Label',  'type' => 'text'],
            ['name' => 'series_name',   'label' => 'Name',   'type' => 'text'],
            ['name' => 'series_sort',   'label' => 'Sort',   'type' => 'int'],
        ],
        'display' => 'series_label',
    ],
    'yy_user' => [
        'pk' => 'user_key',
        'order' => 'user_key ASC',
        'columns' => [
            ['name' => 'user_key',         'label' => 'Key',         'type' => 'pk'],
            ['name' => 'user_code',        'label' => 'Login Code',  'type' => 'text'],
            ['name' => 'user_pass',        'label' => 'Password',    'type' => 'password'],
            ['name' => 'user_name_prefix', 'label' => 'Prefix',     'type' => 'text'],
            ['name' => 'user_name_first',  'label' => 'First Name', 'type' => 'text'],
            ['name' => 'user_name_middle', 'label' => 'Middle Name','type' => 'text'],
            ['name' => 'user_name_last',   'label' => 'Last Name',  'type' => 'text'],
            ['name' => 'user_name_suffix', 'label' => 'Suffix',     'type' => 'text'],
            ['name' => 'user_name_display',   'label' => 'Full Name',  'type' => 'text'],
            ['name' => 'user_email',       'label' => 'Email',      'type' => 'text'],
            ['name' => 'user_text',        'label' => 'Notes',      'type' => 'textarea'],
        ],
        'display' => "user_code || ' - ' || COALESCE(user_name_display, '')",
    ],
    'yy_volume' => [
        'pk' => 'volume_key',
        'order' => '(SELECT s.series_number FROM yy_series s WHERE s.series_key = yy_volume.series_key) ASC, (SELECT s.series_label FROM yy_series s WHERE s.series_key = yy_volume.series_key) ASC, volume_number ASC',
        'select' => "yy_volume.*, (SELECT s.series_number FROM yy_series s WHERE s.series_key = yy_volume.series_key) AS series_number",
        'columns' => [
            ['name' => 'volume_key',             'label' => 'Key',             'type' => 'pk'],
            ['name' => 'series_number',              'label' => 'Series #',        'type' => 'readonly'],
            ['name' => 'series_key',             'label' => 'Series',          'type' => 'fk', 'fk_table' => 'yy_series'],
            ['name' => 'volume_number',          'label' => 'Number',          'type' => 'int'],
            ['name' => 'volume_label',           'label' => 'Label',           'type' => 'text', 'wide' => true],
            ['name' => 'volume_flip_code',       'label' => 'Flip Code',       'type' => 'text'],
            ['name' => 'volume_pdf',             'label' => 'PDF',             'type' => 'text', 'wide' => true],
            ['name' => 'volume_file',            'label' => 'File',            'type' => 'text', 'wide' => true],
            ['name' => 'volume_page_count',      'label' => 'Page Count',      'type' => 'int'],
            ['name' => 'volume_paragraph_count', 'label' => 'Paragraph Count', 'type' => 'int'],
            ['name' => 'volume_sort',            'label' => 'Sort',            'type' => 'int'],
            ['name' => 'volume_active_flag',        'label' => 'Active',          'type' => 'bool'],
        ],
        'display' => 'volume_label',
        'edit_layout' => [
            ['volume_key'],
            [['series_key', 'volume_number', 'volume_label', 'volume_pdf', 'volume_file'], ['volume_flip_code', 'volume_page_count', 'volume_paragraph_count', 'volume_sort', 'volume_active_flag']],
        ],
    ],
    'yy_word_pos' => [
        'pk' => 'word_pos_key',
        'order' => 'word_pos_key ASC',
        'columns' => [
            ['name' => 'word_pos_key',   'label' => 'Key',   'type' => 'pk'],
            ['name' => 'word_pos_code',  'label' => 'Code',  'type' => 'text'],
            ['name' => 'word_pos_label', 'label' => 'Label', 'type' => 'text'],
        ],
        'display' => "word_pos_code || ' - ' || word_pos_label",
    ],
    'yy_word_source' => [
        'pk' => 'word_source_key',
        'order' => 'word_source_sort ASC, word_source_key ASC',
        'columns' => [
            ['name' => 'word_source_key',   'label' => 'Key',   'type' => 'pk'],
            ['name' => 'word_source_code',  'label' => 'Code',  'type' => 'text'],
            ['name' => 'word_source_label', 'label' => 'Label', 'type' => 'text'],
            ['name' => 'word_source_sort',  'label' => 'Sort',  'type' => 'int'],
        ],
        'display' => "word_source_code || ' - ' || word_source_label",
        'sortable' => 'word_source_sort',
    ],
    'yy_word_gender' => [
        'pk' => 'word_gender_key',
        'order' => 'word_gender_key ASC',
        'columns' => [
            ['name' => 'word_gender_key',   'label' => 'Key',   'type' => 'pk'],
            ['name' => 'word_gender_code',  'label' => 'Code',  'type' => 'text'],
            ['name' => 'word_gender_label', 'label' => 'Label', 'type' => 'text'],
        ],
        'display' => "word_gender_code || ' - ' || word_gender_label",
    ],
];

// ── Validate table ──
if ($table !== '' && !isset($TABLES[$table])) {
    errorResponse('Invalid table');
}

// ── Actions ──
switch ($action) {
    case 'meta':
        if (!$table) errorResponse('table is required');
        $meta = $TABLES[$table];
        $resp = ['table' => $table, 'pk' => $meta['pk'], 'columns' => $meta['columns']];
        if (isset($meta['edit_layout'])) $resp['edit_layout'] = $meta['edit_layout'];
        if (isset($meta['sortable'])) $resp['sortable'] = $meta['sortable'];
        jsonResponse($resp);

    case 'list':
        if (!$table) errorResponse('table is required');
        $meta = $TABLES[$table];
        $select = $meta['select'] ?? '*';
        $stmt = $pdo->query("SELECT $select FROM $table ORDER BY {$meta['order']}");
        jsonResponse($stmt->fetchAll());

    case 'get':
        if (!$table) errorResponse('table is required');
        $key = $_GET['key'] ?? null;
        if ($key === null) errorResponse('key is required');
        $meta = $TABLES[$table];
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE {$meta['pk']} = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) errorResponse('Record not found', 404);
        jsonResponse($row);

    case 'fk_options':
        $fkTable = $_GET['fk_table'] ?? '';
        if (!isset($TABLES[$fkTable])) errorResponse('Invalid fk_table');
        $fkMeta = $TABLES[$fkTable];
        $pk = $fkMeta['pk'];
        $display = $fkMeta['display'] ?? $pk;
        $stmt = $pdo->query("SELECT $pk AS value, $display AS label FROM $fkTable ORDER BY {$fkMeta['order']}");
        jsonResponse($stmt->fetchAll());

    case 'save':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST required', 405);
        if (!$table) errorResponse('table is required');
        $meta = $TABLES[$table];
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) errorResponse('Invalid JSON body');

        $pkValue = $input[$meta['pk']] ?? null;
        $cols = [];
        $vals = [];
        foreach ($meta['columns'] as $col) {
            if ($col['type'] === 'pk' || $col['type'] === 'readonly') continue;
            if (!array_key_exists($col['name'], $input)) continue;
            $v = $input[$col['name']];
            if ($v === '' && in_array($col['type'], ['int', 'fk'])) $v = null;
            // Hash password if provided and not empty
            if ($col['type'] === 'password') {
                if ($v === '' || $v === null) continue; // skip empty password (don't overwrite)
                $v = password_hash($v, PASSWORD_DEFAULT);
            }
            $cols[] = $col['name'];
            $vals[] = $v;
        }

        if (empty($cols)) errorResponse('No data to save');

        if ($pkValue !== null && $pkValue !== '') {
            // UPDATE
            $sets = [];
            foreach ($cols as $c) $sets[] = "$c = ?";
            $vals[] = $pkValue;
            $sql = "UPDATE $table SET " . implode(', ', $sets) . " WHERE {$meta['pk']} = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($vals);
            jsonResponse(['success' => true, 'key' => $pkValue]);
        } else {
            // INSERT
            $placeholders = array_fill(0, count($cols), '?');
            $sql = "INSERT INTO $table (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ") RETURNING {$meta['pk']}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($vals);
            $newKey = $stmt->fetchColumn();
            jsonResponse(['success' => true, 'key' => $newKey]);
        }
        break;

    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            errorResponse('POST or DELETE required', 405);
        }
        if (!$table) errorResponse('table is required');
        $key = $_GET['key'] ?? null;
        if ($key === null) errorResponse('key is required');
        $meta = $TABLES[$table];
        try {
            $stmt = $pdo->prepare("DELETE FROM $table WHERE {$meta['pk']} = ?");
            $stmt->execute([$key]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'foreign key') !== false || strpos($e->getMessage(), '23503') !== false) {
                errorResponse('Cannot delete: this record is referenced by other records');
            }
            errorResponse('Delete failed: ' . $e->getMessage());
        }
        if ($stmt->rowCount() === 0) errorResponse('Record not found', 404);
        jsonResponse(['success' => true]);

    case 'reorder':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST required', 405);
        if (!$table) errorResponse('table is required');
        $meta = $TABLES[$table];
        if (!isset($meta['sortable'])) errorResponse('Table does not support reorder');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['order']) || !is_array($input['order'])) {
            errorResponse('order array is required');
        }
        $sortCol = $meta['sortable'];
        $stmt = $pdo->prepare("UPDATE $table SET $sortCol = ? WHERE {$meta['pk']} = ?");
        foreach ($input['order'] as $sort => $pk) {
            $stmt->execute([$sort, (int)$pk]);
        }
        jsonResponse(['success' => true]);

    default:
        errorResponse('Invalid action. Use: meta, list, get, fk_options, save, delete, reorder');
}
