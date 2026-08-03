<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);
$method = $_SERVER['REQUEST_METHOD'];

function rebuildAliasCache(PDO $db): void {
    $stmt = $db->query("
        SELECT a.alias_path, p.page_code
        FROM yy_page_alias a
        JOIN yy_page p ON a.page_key = p.page_key
        WHERE a.alias_active_flag = TRUE AND p.page_active_flag = TRUE
    ");
    $aliases = [];
    foreach ($stmt->fetchAll() as $row) {
        $aliases[$row['alias_path']] = $row['page_code'];
    }
    $cacheFile = sys_get_temp_dir() . '/yada_page_aliases.json';
    file_put_contents($cacheFile, json_encode($aliases));
}

switch ($method) {

case 'GET':
    // Single page with sections
    if (!empty($_GET['key'])) {
        $key = (int)$_GET['key'];
        $page = $db->prepare("SELECT * FROM yy_page WHERE page_key = ?");
        $page->execute([$key]);
        $row = $page->fetch();
        if (!$row) errorResponse('Not found', 404);

        $sections = $db->prepare("SELECT * FROM yy_section WHERE page_key = ? ORDER BY section_sort, section_key");
        $sections->execute([$key]);
        $row['sections'] = $sections->fetchAll();

        $aliases = $db->prepare("SELECT * FROM yy_page_alias WHERE page_key = ? ORDER BY alias_key");
        $aliases->execute([$key]);
        $row['aliases'] = $aliases->fetchAll();
        jsonResponse($row);
    }

    // List all pages
    $stmt = $db->query("SELECT p.*, (SELECT COUNT(*) FROM yy_section ps WHERE ps.page_key = p.page_key) AS section_count FROM yy_page p ORDER BY page_code");
    jsonResponse($stmt->fetchAll());

case 'POST':
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) errorResponse('Invalid JSON');

    // Save sections for a page
    if (!empty($input['page_key']) && isset($input['sections'])) {
        $pageKey = (int)$input['page_key'];

        // Verify page exists
        $check = $db->prepare("SELECT page_key FROM yy_page WHERE page_key = ?");
        $check->execute([$pageKey]);
        if (!$check->fetch()) errorResponse('Page not found', 404);

        $db->beginTransaction();
        try {
            foreach ($input['sections'] as $s) {
                // Blank/absent sort saves as NULL, not 0
                $sort = (!isset($s['section_sort']) || $s['section_sort'] === '' || $s['section_sort'] === null)
                    ? null : (int)$s['section_sort'];
                if (!empty($s['section_key'])) {
                    // Update existing
                    $stmt = $db->prepare("UPDATE yy_section SET section_code = ?, section_value = ?, section_active_flag = ?, section_sort = ? WHERE section_key = ? AND page_key = ?");
                    $stmt->execute([
                        $s['section_code'] ?? '',
                        $s['section_value'] ?? '',
                        ($s['section_active_flag'] ?? true) ? 't' : 'f',
                        $sort,
                        (int)$s['section_key'],
                        $pageKey,
                    ]);
                } else {
                    // Insert new
                    $stmt = $db->prepare("INSERT INTO yy_section (page_key, section_code, section_value, section_active_flag, section_sort) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $pageKey,
                        $s['section_code'] ?? '',
                        $s['section_value'] ?? '',
                        ($s['section_active_flag'] ?? true) ? 't' : 'f',
                        $sort,
                    ]);
                }
            }

            // Delete removed sections
            if (isset($input['deleted_sections'])) {
                foreach ($input['deleted_sections'] as $delKey) {
                    $db->prepare("DELETE FROM yy_section WHERE section_key = ? AND page_key = ?")
                       ->execute([(int)$delKey, $pageKey]);
                }
            }

            $db->commit();
            jsonResponse(['ok' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            errorResponse('Save failed: ' . $e->getMessage());
        }
    }

    // Save aliases for a page
    if (!empty($input['page_key']) && isset($input['aliases'])) {
        $pageKey = (int)$input['page_key'];
        $check = $db->prepare("SELECT page_key FROM yy_page WHERE page_key = ?");
        $check->execute([$pageKey]);
        if (!$check->fetch()) errorResponse('Page not found', 404);

        $db->beginTransaction();
        try {
            foreach ($input['aliases'] as $a) {
                $path = trim($a['alias_path'] ?? '', ' /');
                if (!$path) continue;
                if (!empty($a['alias_key'])) {
                    $db->prepare("UPDATE yy_page_alias SET alias_path = ?, alias_active_flag = ? WHERE alias_key = ? AND page_key = ?")
                       ->execute([$path, ($a['alias_active_flag'] ?? true) ? 't' : 'f', (int)$a['alias_key'], $pageKey]);
                } else {
                    $db->prepare("INSERT INTO yy_page_alias (page_key, alias_path, alias_active_flag) VALUES (?, ?, ?) ON CONFLICT (alias_path) DO UPDATE SET page_key = EXCLUDED.page_key, alias_active_flag = EXCLUDED.alias_active_flag")
                       ->execute([$pageKey, $path, ($a['alias_active_flag'] ?? true) ? 't' : 'f']);
                }
            }
            if (isset($input['deleted_aliases'])) {
                foreach ($input['deleted_aliases'] as $delKey) {
                    $db->prepare("DELETE FROM yy_page_alias WHERE alias_key = ? AND page_key = ?")
                       ->execute([(int)$delKey, $pageKey]);
                }
            }
            // Rebuild alias redirect cache
            rebuildAliasCache($db);
            $db->commit();
            jsonResponse(['ok' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            errorResponse('Save failed: ' . $e->getMessage());
        }
    }

    // Create new page
    $code = trim($input['page_code'] ?? '');
    if (!$code) errorResponse('page_code is required');

    $title = trim($input['page_title'] ?? '') ?: null;
    $active = ($input['page_active_flag'] ?? true) ? 't' : 'f';
    $toolbar = (int)($input['page_toolbar'] ?? 1);
    $headerSort = (int)($input['page_header_sort'] ?? 0);
    $footerSort = (int)($input['page_footer_sort'] ?? 0);
    $url = trim($input['page_url'] ?? '') ?: null;

    $heading = trim($input['page_heading'] ?? '') ?: null;
    $subheading = trim($input['page_subheading'] ?? '') ?: null;
    $description = trim($input['page_description'] ?? '') ?: null;
    $body = trim($input['page_body'] ?? '') ?: null;
    $footerCol = (int)($input['page_footer_col'] ?? 0);

    $headingColor = trim($input['page_heading_color'] ?? '') ?: null;
    $headingSize = trim($input['page_heading_size'] ?? '') ?: null;
    $subheadingColor = trim($input['page_subheading_color'] ?? '') ?: null;
    $subheadingSize = trim($input['page_subheading_size'] ?? '') ?: null;
    $descColor = trim($input['page_description_color'] ?? '') ?: null;
    $descSize = trim($input['page_description_size'] ?? '') ?: null;
    $bgColor = trim($input['page_background_color'] ?? '') ?: null;

    // Per-page flags that control which pages contribute "items" to
    // higher-level UIs. allow_flag = page can carry feed items at all;
    // search_flag = page's items appear in the global Search → Video
    // Group dropdown. Both default to FALSE so a fresh page is opted-out.
    $itemAllow  = !empty($input['page_item_allow_flag'])  ? 't' : 'f';
    $itemSearch = !empty($input['page_item_search_flag']) ? 't' : 'f';

    // Sub-toolbar parent / child wiring. toolbar_flag marks a page as a
    // parent that renders a sub-toolbar of its children; parent_key + sort
    // place a child within that parent's sub-toolbar.
    $toolbarFlag = !empty($input['page_toolbar_flag']) ? 't' : 'f';
    $parentKey   = isset($input['page_parent_key']) && $input['page_parent_key'] !== '' && $input['page_parent_key'] !== null
                    ? (int)$input['page_parent_key'] : null;
    $parentSort  = (int)($input['page_parent_sort'] ?? 0);

    $stmt = $db->prepare("INSERT INTO yy_page (page_code, page_title, page_active_flag, page_toolbar, page_header_sort, page_footer_sort, page_footer_col, page_url, page_heading, page_subheading, page_description, page_body, page_heading_color, page_heading_size, page_subheading_color, page_subheading_size, page_description_color, page_description_size, page_background_color, page_item_allow_flag, page_item_search_flag, page_toolbar_flag, page_parent_key, page_parent_sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING page_key");
    $stmt->execute([$code, $title, $active, $toolbar, $headerSort, $footerSort, $footerCol, $url, $heading, $subheading, $description, $body, $headingColor, $headingSize, $subheadingColor, $subheadingSize, $descColor, $descSize, $bgColor, $itemAllow, $itemSearch, $toolbarFlag, $parentKey, $parentSort]);
    $row = $stmt->fetch();
    $navCache = sys_get_temp_dir() . '/yada_page_nav.json';
    if (file_exists($navCache)) @unlink($navCache);
    jsonResponse(['page_key' => $row['page_key']], 201);

case 'PUT':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) errorResponse('Invalid JSON');

    $existing = $db->prepare("SELECT page_key FROM yy_page WHERE page_key = ?");
    $existing->execute([$key]);
    if (!$existing->fetch()) errorResponse('Not found', 404);

    if (!isset($input['page_code']) || trim($input['page_code']) === '') {
        errorResponse('page_code is required');
    }

    // PATCH semantics: only update fields the caller actually sent. The
    // admin-links UI saves routing fields (code/title/toolbar/parent) but
    // doesn't ship page_body / page_heading / colors. If we treated missing
    // fields as "set to empty", every save through admin-links would WIPE
    // the rich content authored in the admin-pages editor. That happened
    // to /grammar on 2026-05-13 and the body had to be restored from
    // yy_page_rev. Never again — only touch what was provided.
    //
    // Mapping from JSON key → (sql column, transform). For booleans we use
    // an array form so missing keys get skipped.
    $updates = [];
    $params  = [];
    $maybe = function (string $jsonKey, string $col, callable $xform) use (&$updates, &$params, $input) {
        if (array_key_exists($jsonKey, $input)) {
            $updates[] = "$col = ?";
            $params[]  = $xform($input[$jsonKey]);
        }
    };
    $trimOrNull = function ($v) { $t = trim((string)$v); return $t === '' ? null : $t; };
    $intOr0     = function ($v) { return (int)$v; };
    $boolFlag   = function ($v) { return $v ? 't' : 'f'; };
    $parentKeyXform = function ($v) {
        return ($v === '' || $v === null) ? null : (int)$v;
    };

    $maybe('page_code',              'page_code',              fn($v) => trim((string)$v));
    $maybe('page_title',             'page_title',             $trimOrNull);
    $maybe('page_active_flag',       'page_active_flag',       $boolFlag);
    $maybe('page_toolbar',           'page_toolbar',           $intOr0);
    $maybe('page_header_sort',       'page_header_sort',       $intOr0);
    $maybe('page_footer_sort',       'page_footer_sort',       $intOr0);
    $maybe('page_footer_col',        'page_footer_col',        $intOr0);
    $maybe('page_url',               'page_url',               $trimOrNull);
    $maybe('page_heading',           'page_heading',           $trimOrNull);
    $maybe('page_subheading',        'page_subheading',        $trimOrNull);
    $maybe('page_description',       'page_description',       $trimOrNull);
    $maybe('page_body',              'page_body',              $trimOrNull);
    $maybe('page_heading_color',     'page_heading_color',     $trimOrNull);
    $maybe('page_heading_size',      'page_heading_size',      $trimOrNull);
    $maybe('page_subheading_color',  'page_subheading_color',  $trimOrNull);
    $maybe('page_subheading_size',   'page_subheading_size',   $trimOrNull);
    $maybe('page_description_color', 'page_description_color', $trimOrNull);
    $maybe('page_description_size',  'page_description_size',  $trimOrNull);
    $maybe('page_background_color',  'page_background_color',  $trimOrNull);
    $maybe('page_item_allow_flag',   'page_item_allow_flag',   $boolFlag);
    $maybe('page_item_search_flag',  'page_item_search_flag',  $boolFlag);
    $maybe('page_toolbar_flag',      'page_toolbar_flag',      $boolFlag);
    $maybe('page_parent_key',        'page_parent_key',        $parentKeyXform);
    $maybe('page_parent_sort',       'page_parent_sort',       $intOr0);

    if (!$updates) { jsonResponse(['ok' => true, 'unchanged' => true]); }

    $params[] = $key;
    $stmt = $db->prepare("UPDATE yy_page SET " . implode(', ', $updates) . " WHERE page_key = ?");
    $stmt->execute($params);
    $navCache = sys_get_temp_dir() . '/yada_page_nav.json';
    if (file_exists($navCache)) @unlink($navCache);
    jsonResponse(['ok' => true]);

case 'DELETE':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');

    $db->prepare("DELETE FROM yy_page WHERE page_key = ?")->execute([$key]);
    $navCache = sys_get_temp_dir() . '/yada_page_nav.json';
    if (file_exists($navCache)) @unlink($navCache);
    jsonResponse(['ok' => true]);

default:
    errorResponse('Method not allowed', 405);
}
