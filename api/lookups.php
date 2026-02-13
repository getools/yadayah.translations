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
    'yah_chapter' => [
        'pk' => 'yah_chapter_key',
        'order' => 'yah_scroll_key ASC, yah_chapter_sort ASC',
        'columns' => [
            ['name' => 'yah_chapter_key',    'label' => 'Key',     'type' => 'pk'],
            ['name' => 'yah_scroll_key',     'label' => 'Scroll',  'type' => 'fk', 'fk_table' => 'yah_scroll'],
            ['name' => 'yah_chapter_number',  'label' => 'Number',  'type' => 'int'],
            ['name' => 'yah_chapter_sort',   'label' => 'Sort',    'type' => 'int'],
        ],
    ],
    'yah_verse' => [
        'pk' => 'yah_verse_key',
        'order' => 'yah_chapter_key ASC, yah_verse_sort ASC',
        'columns' => [
            ['name' => 'yah_verse_key',     'label' => 'Key',     'type' => 'pk'],
            ['name' => 'yah_chapter_key',   'label' => 'Chapter', 'type' => 'fk', 'fk_table' => 'yah_chapter'],
            ['name' => 'yah_verse_number',  'label' => 'Number',  'type' => 'int'],
            ['name' => 'yah_verse_sort',    'label' => 'Sort',    'type' => 'int'],
        ],
    ],
    'yy_chapter' => [
        'pk' => 'yy_chapter_key',
        'order' => 'yy_volume_key ASC, yy_chapter_sort ASC',
        'columns' => [
            ['name' => 'yy_chapter_key',    'label' => 'Key',     'type' => 'pk'],
            ['name' => 'yy_volume_key',     'label' => 'Volume',  'type' => 'fk', 'fk_table' => 'yy_volume'],
            ['name' => 'yy_chapter_number', 'label' => 'Number',  'type' => 'int'],
            ['name' => 'yy_chapter_page',   'label' => 'Page',    'type' => 'int'],
            ['name' => 'yy_chapter_name',   'label' => 'Name',    'type' => 'text'],
            ['name' => 'yy_chapter_label',  'label' => 'Label',   'type' => 'text'],
            ['name' => 'yy_chapter_sort',   'label' => 'Sort',    'type' => 'int'],
        ],
    ],
    'yy_letter' => [
        'pk' => 'letter_key',
        'order' => 'letter_sort ASC, letter_key ASC',
        'columns' => [
            ['name' => 'letter_key',      'label' => 'Key',      'type' => 'pk'],
            ['name' => 'letter_yt',       'label' => 'YT',       'type' => 'text'],
            ['name' => 'letter_hebrew',   'label' => 'Hebrew',   'type' => 'text'],
            ['name' => 'letter_label',    'label' => 'Label',    'type' => 'text'],
            ['name' => 'letter_overview',      'label' => 'Overview',       'type' => 'textarea'],
            ['name' => 'letter_numeric_value', 'label' => 'Numeric Value', 'type' => 'int'],
            ['name' => 'letter_sort',          'label' => 'Sort',          'type' => 'int'],
        ],
        'display' => "letter_yt || ' - ' || COALESCE(letter_label, '')",
    ],
    'yy_cite' => [
        'pk' => 'id',
        'order' => 'sort ASC, id ASC',
        'columns' => [
            ['name' => 'id',    'label' => 'ID',    'type' => 'pk'],
            ['name' => 'label', 'label' => 'Label', 'type' => 'text'],
            ['name' => 'sort',  'label' => 'Sort',  'type' => 'int'],
        ],
        'display' => 'label',
    ],
    'yy_cite_book' => [
        'pk' => 'cite_book_id',
        'order' => 'cite_book_sort ASC, cite_book_id ASC',
        'columns' => [
            ['name' => 'cite_book_id',            'label' => 'ID',             'type' => 'pk'],
            ['name' => 'yah_scroll_key',          'label' => 'Scroll',         'type' => 'fk', 'fk_table' => 'yah_scroll'],
            ['name' => 'cite_book_hebrew',        'label' => 'Hebrew Name',    'type' => 'text'],
            ['name' => 'cite_book_common',        'label' => 'Common Name',    'type' => 'text'],
            ['name' => 'cite_book_definition',    'label' => 'Definition',     'type' => 'textarea'],
            ['name' => 'cite_book_chapter_count', 'label' => 'Chapter Count',  'type' => 'int'],
            ['name' => 'cite_book_sort',          'label' => 'Sort',           'type' => 'int'],
        ],
        'display' => "cite_book_hebrew || ' / ' || cite_book_common",
    ],
    'yy_cite_book_map' => [
        'pk' => 'cite_book_map_id',
        'order' => 'cite_book_id ASC, cite_book_map_id ASC',
        'columns' => [
            ['name' => 'cite_book_map_id',      'label' => 'ID',           'type' => 'pk'],
            ['name' => 'cite_book_id',           'label' => 'Cite Book',   'type' => 'fk', 'fk_table' => 'yy_cite_book'],
            ['name' => 'cite_book_map_hebrew',   'label' => 'Hebrew Map',  'type' => 'text'],
        ],
    ],
    'yy_series' => [
        'pk' => 'yy_series_key',
        'order' => 'yy_series_sort ASC, yy_series_key ASC',
        'columns' => [
            ['name' => 'yy_series_key',    'label' => 'Key',    'type' => 'pk'],
            ['name' => 'yy_series_number', 'label' => 'Number', 'type' => 'int'],
            ['name' => 'yy_series_label',  'label' => 'Label',  'type' => 'text'],
            ['name' => 'yy_series_name',   'label' => 'Name',   'type' => 'text'],
            ['name' => 'yy_series_sort',   'label' => 'Sort',   'type' => 'int'],
        ],
        'display' => 'yy_series_label',
    ],
    'yy_user' => [
        'pk' => 'yy_user_key',
        'order' => 'yy_user_key ASC',
        'columns' => [
            ['name' => 'yy_user_key',         'label' => 'Key',         'type' => 'pk'],
            ['name' => 'yy_user_code',        'label' => 'Login Code',  'type' => 'text'],
            ['name' => 'yy_user_pass',        'label' => 'Password',    'type' => 'password'],
            ['name' => 'yy_user_name_prefix', 'label' => 'Prefix',     'type' => 'text'],
            ['name' => 'yy_user_name_first',  'label' => 'First Name', 'type' => 'text'],
            ['name' => 'yy_user_name_middle', 'label' => 'Middle Name','type' => 'text'],
            ['name' => 'yy_user_name_last',   'label' => 'Last Name',  'type' => 'text'],
            ['name' => 'yy_user_name_suffix', 'label' => 'Suffix',     'type' => 'text'],
            ['name' => 'yy_user_name_full',   'label' => 'Full Name',  'type' => 'text'],
            ['name' => 'yy_user_email',       'label' => 'Email',      'type' => 'text'],
            ['name' => 'yy_user_text',        'label' => 'Notes',      'type' => 'textarea'],
        ],
        'display' => "yy_user_code || ' - ' || COALESCE(yy_user_name_full, '')",
    ],
    'yy_volume' => [
        'pk' => 'yy_volume_key',
        'order' => 'yy_volume_sort ASC, yy_volume_key ASC',
        'columns' => [
            ['name' => 'yy_volume_key',             'label' => 'Key',             'type' => 'pk'],
            ['name' => 'yy_series_key',             'label' => 'Series',          'type' => 'fk', 'fk_table' => 'yy_series'],
            ['name' => 'yy_volume_number',          'label' => 'Number',          'type' => 'int'],
            ['name' => 'yy_volume_label',           'label' => 'Label',           'type' => 'text'],
            ['name' => 'yy_volume_flip_code',       'label' => 'Flip Code',       'type' => 'text'],
            ['name' => 'yy_volume_pdf',             'label' => 'PDF',             'type' => 'text'],
            ['name' => 'yy_volume_file',            'label' => 'File',            'type' => 'text'],
            ['name' => 'yy_volume_name',            'label' => 'Name',            'type' => 'text'],
            ['name' => 'yy_volume_page_count',      'label' => 'Page Count',      'type' => 'int'],
            ['name' => 'yy_volume_paragraph_count', 'label' => 'Paragraph Count', 'type' => 'int'],
            ['name' => 'yy_volume_sort',            'label' => 'Sort',            'type' => 'int'],
        ],
        'display' => 'yy_volume_label',
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
        jsonResponse(['table' => $table, 'pk' => $meta['pk'], 'columns' => $meta['columns']]);

    case 'list':
        if (!$table) errorResponse('table is required');
        $meta = $TABLES[$table];
        $stmt = $pdo->query("SELECT * FROM $table ORDER BY {$meta['order']}");
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
            if ($col['type'] === 'pk') continue;
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
        $stmt = $pdo->prepare("DELETE FROM $table WHERE {$meta['pk']} = ?");
        $stmt->execute([$key]);
        if ($stmt->rowCount() === 0) errorResponse('Record not found', 404);
        jsonResponse(['success' => true]);

    default:
        errorResponse('Invalid action. Use: meta, list, get, fk_options, save, delete');
}
