<?php
/**
 * TEST endpoint — multi-section pages CRUD.
 * Parallel to api/pages.php; writes to yy_page / yy_section.
 *
 * Routes:
 *   GET                     → list pages
 *   GET ?key=N              → page with ordered sections
 *   POST                    → create page (page_code required)
 *   PUT ?key=N              → update page metadata
 *   DELETE ?key=N           → delete page (sections cascade)
 *   POST ?key=N&action=sections
 *                           → bulk save sections; body { sections:[...], deleted:[ids] }
 *   POST ?key=N&action=aliases
 *                           → save URL aliases; body { aliases:[...], deleted_aliases:[ids] }
 *
 * A section row looks like:
 *   { section_key, section_type, section_title,
 *     section_config (object), section_active_flag, section_sort }
 */
require_once __DIR__ . '/../config.php';
define('SECTION_ITEMS_LIB', true);
require_once __DIR__ . '/section-items.php';   // rebuildSectionItems() — recompute yy_section_item on save

/**
 * Accept the pre-2026-08-23 field names from an admin tab that was loaded
 * before the page_test_* -> page_* rename, so a save already in flight still
 * lands. Safe to delete once no stale tab can be open.
 */
function normaliseLegacyPageFields(?array $in): ?array {
    if (!is_array($in)) return $in;
    foreach ($in as $k => $v) {
        if (strncmp($k, 'page_test_', 10) === 0) {
            $new = 'page_' . substr($k, 10);
            if (!array_key_exists($new, $in)) $in[$new] = $v;
        }
    }
    return $in;
}

$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Aliases live in yy_page_alias, keyed straight to yy_page — the same row the
 * builder edits since the 2026-08-23 unification. (Before it, the builder wrote
 * yy_page_test and this had to resolve an "anchor" row by code; that whole
 * indirection is gone, and a brand-new page can own aliases immediately.)
 */
/**
 * Rebuild the alias → page_code lookup that case-redirect.php reads on every
 * unmatched request. Twin of the function in api/pages.php (the legacy Page
 * Registry); kept local so retiring that file drops nothing this one needs.
 */
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
    file_put_contents(sys_get_temp_dir() . '/yada_page_aliases.json', json_encode($aliases));
}

function fetchPageWithSections(PDO $db, int $key): ?array {
    $stmt = $db->prepare("SELECT * FROM yy_page WHERE page_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $sec = $db->prepare("SELECT * FROM yy_section WHERE page_key = ? ORDER BY section_sort, section_key");
    $sec->execute([$key]);
    $sections = [];
    foreach ($sec->fetchAll() as $s) {
        $cfg = $s['section_config'];
        if (is_string($cfg) && $cfg !== '') {
            $decoded = json_decode($cfg, true);
            $s['section_config'] = is_array($decoded) ? $decoded : new stdClass();
        } elseif (!$cfg) {
            $s['section_config'] = new stdClass();
        }
        $sections[] = $s;
    }
    $row['sections'] = $sections;

    $al = $db->prepare("SELECT * FROM yy_page_alias WHERE page_key = ? ORDER BY alias_key");
    $al->execute([$key]);
    $row['aliases'] = $al->fetchAll();
    return $row;
}

switch ($method) {

case 'GET':
    if (!empty($_GET['key'])) {
        $page = fetchPageWithSections($db, (int)$_GET['key']);
        if (!$page) errorResponse('Not found', 404);
        jsonResponse($page);
    }
    $list = $db->query("SELECT p.*, (SELECT COUNT(*) FROM yy_section s WHERE s.page_key = p.page_key) AS section_count FROM yy_page p ORDER BY page_code")->fetchAll();
    jsonResponse($list);

case 'POST':
    $input = normaliseLegacyPageFields(json_decode(file_get_contents('php://input'), true));
    if (!$input) errorResponse('Invalid JSON');

    // Bulk save sections for a page
    if (($_GET['action'] ?? '') === 'sections' && !empty($_GET['key'])) {
        $pageKey = (int)$_GET['key'];
        $check = $db->prepare("SELECT page_key FROM yy_page WHERE page_key = ?");
        $check->execute([$pageKey]);
        if (!$check->fetch()) errorResponse('Page not found', 404);

        $allowedTypes = ['static', 'carousel', 'items', 'custom', 'layout'];
        $sections = $input['sections'] ?? [];
        $deleted  = $input['deleted']  ?? [];
        if (!is_array($sections)) errorResponse('sections must be an array');

        // Sections arrive in pre-order traversal (parent before child) so a
        // single forward pass can resolve parent_key references via the
        // local_id → db_key map we build as we go.
        $db->beginTransaction();
        try {
            foreach ($deleted as $delKey) {
                $db->prepare("DELETE FROM yy_section WHERE section_key = ? AND page_key = ?")
                   ->execute([(int)$delKey, $pageKey]);
            }
            // Two UPDATE variants:
            //   updWith — touches parent_key (used when the payload declares
            //             tree info via parent_local_id / parent_key /
            //             parent_resolved).
            //   updWithout — leaves parent_key alone (legacy payload from an
            //             editor build that doesn't know about layouts).
            // Avoids accidentally NULL'ing parent_key when an old editor
            // saves over a migrated row.
            $updWith    = $db->prepare("UPDATE yy_section SET section_type = ?, section_title = ?, section_config = ?::jsonb, section_active_flag = ?, section_sort = ?, section_parent_key = ? WHERE section_key = ? AND page_key = ?");
            $updWithout = $db->prepare("UPDATE yy_section SET section_type = ?, section_title = ?, section_config = ?::jsonb, section_active_flag = ?, section_sort = ? WHERE section_key = ? AND page_key = ?");
            $ins = $db->prepare("INSERT INTO yy_section (page_key, section_type, section_title, section_config, section_active_flag, section_sort, section_parent_key) VALUES (?, ?, ?, ?::jsonb, ?, ?, ?) RETURNING section_key");
            $newKeys = [];
            $localMap = []; // local_id (string|int) → db_key
            foreach ($sections as $i => $s) {
                $type = $s['section_type'] ?? '';
                if (!in_array($type, $allowedTypes, true)) {
                    throw new RuntimeException("Invalid section type at index $i: $type");
                }
                $title  = isset($s['section_title']) ? trim((string)$s['section_title']) : '';
                $config = $s['section_config'] ?? new stdClass();
                $cfgJson = json_encode($config, JSON_UNESCAPED_UNICODE) ?: '{}';
                $active = !empty($s['section_active_flag']) ? 't' : 'f';
                $sort   = (int)($s['section_sort'] ?? $i);

                // Tree info detection: the editor build that understands
                // layouts sets parent_local_id (string) or
                // section_parent_key (int/null) or sends an
                // explicit parent_resolved=true marker. Old payloads have
                // none of these — we preserve the existing parent_key.
                $hasTreeInfo = array_key_exists('parent_local_id', $s)
                            || array_key_exists('section_parent_key', $s)
                            || !empty($s['parent_resolved']);
                $parentKey = null;
                if (isset($s['parent_local_id']) && $s['parent_local_id'] !== null && $s['parent_local_id'] !== '') {
                    $pid = (string)$s['parent_local_id'];
                    if (isset($localMap[$pid])) {
                        $parentKey = $localMap[$pid];
                    } elseif (ctype_digit($pid)) {
                        $parentKey = (int)$pid;
                    }
                } elseif (array_key_exists('section_parent_key', $s)
                          && $s['section_parent_key'] !== null
                          && $s['section_parent_key'] !== '') {
                    $parentKey = (int)$s['section_parent_key'];
                }

                if (!empty($s['section_key'])) {
                    $dbKey = (int)$s['section_key'];
                    if ($hasTreeInfo) {
                        $updWith->execute([$type, $title ?: null, $cfgJson, $active, $sort, $parentKey, $dbKey, $pageKey]);
                    } else {
                        $updWithout->execute([$type, $title ?: null, $cfgJson, $active, $sort, $dbKey, $pageKey]);
                    }
                } else {
                    $ins->execute([$pageKey, $type, $title ?: null, $cfgJson, $active, $sort, $parentKey]);
                    $dbKey = (int)$ins->fetchColumn();
                }
                $newKeys[] = $dbKey;
                $localId = isset($s['local_id']) ? (string)$s['local_id'] : null;
                if ($localId !== null && $localId !== '') $localMap[$localId] = $dbKey;
                // Also map by old db_key so a child referencing an existing
                // parent by parent_local_id = old_db_key still resolves.
                $localMap[(string)$dbKey] = $dbKey;
            }
            $db->commit();
            // Recompute the materialized yy_section_item pool for any Items
            // section just saved (config now persisted). Each rebuild runs in
            // its own transaction; a failure is logged but never blocks the
            // save response.
            foreach ($sections as $i => $s) {
                if (($s['section_type'] ?? '') === 'items' && isset($newKeys[$i])) {
                    try { rebuildSectionItems($db, (int)$newKeys[$i]); }
                    catch (Exception $e) { error_log('yy_section_item rebuild failed for section ' . $newKeys[$i] . ': ' . $e->getMessage()); }
                }
            }
            jsonResponse(['ok' => true, 'section_keys' => $newKeys, 'local_map' => $localMap]);
        } catch (Exception $e) {
            $db->rollBack();
            errorResponse('Save failed: ' . $e->getMessage());
        }
    }

    // Bulk save URL aliases for a page (301 redirects → this page's code).
    // Mirrors the sections route: full replace-in-place, with an explicit
    // deleted list, then one cache rebuild.
    if (($_GET['action'] ?? '') === 'aliases' && !empty($_GET['key'])) {
        $anchorKey = (int)$_GET['key'];
        $chk = $db->prepare("SELECT 1 FROM yy_page WHERE page_key = ?");
        $chk->execute([$anchorKey]);
        if (!$chk->fetchColumn()) errorResponse('Page not found', 404);

        $db->beginTransaction();
        try {
            foreach (($input['aliases'] ?? []) as $a) {
                // Stored without surrounding slashes — case-redirect.php compares
                // against trim($path, '/').
                $path = trim($a['alias_path'] ?? '', " /");
                if ($path === '') continue;
                $active = (($a['alias_active_flag'] ?? true) ? 't' : 'f');
                if (!empty($a['alias_key'])) {
                    // The IS DISTINCT FROM guard makes an unchanged alias a
                    // no-op row-wise, so the rev trigger never fires. Without
                    // it every debounced autosave of any page field would bump
                    // alias_revision_num on every alias the page owns.
                    $db->prepare("UPDATE yy_page_alias SET alias_path = ?, alias_active_flag = ? WHERE alias_key = ? AND page_key = ? AND (alias_path IS DISTINCT FROM ? OR alias_active_flag IS DISTINCT FROM ?)")
                       ->execute([$path, $active, (int)$a['alias_key'], $anchorKey, $path, $active]);
                } else {
                    // ON CONFLICT on the unique alias_path: re-pointing an alias
                    // that currently belongs to another page moves it here.
                    $db->prepare("INSERT INTO yy_page_alias (page_key, alias_path, alias_active_flag) VALUES (?, ?, ?) ON CONFLICT (alias_path) DO UPDATE SET page_key = EXCLUDED.page_key, alias_active_flag = EXCLUDED.alias_active_flag")
                       ->execute([$anchorKey, $path, $active]);
                }
            }
            foreach (($input['deleted_aliases'] ?? []) as $delKey) {
                $db->prepare("DELETE FROM yy_page_alias WHERE alias_key = ? AND page_key = ?")
                   ->execute([(int)$delKey, $anchorKey]);
            }
            rebuildAliasCache($db);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            errorResponse('Save failed: ' . $e->getMessage());
        }

        // Return the stored rows so the editor can pick up server-assigned
        // alias_keys without a reload (otherwise every autosave re-INSERTs).
        $al = $db->prepare("SELECT * FROM yy_page_alias WHERE page_key = ? ORDER BY alias_key");
        $al->execute([$anchorKey]);
        jsonResponse(['ok' => true, 'aliases' => $al->fetchAll()]);
    }

    // Create new page
    $code = trim($input['page_code'] ?? '');
    if (!$code) errorResponse('page_code is required');

    $stmt = $db->prepare("INSERT INTO yy_page (page_code, page_title, page_label, page_meta_description, page_url, page_heading, page_subheading, page_description, page_heading_color, page_heading_size, page_subheading_color, page_subheading_size, page_description_color, page_description_size, page_description_class, page_description_style, page_background_color, page_background_image, page_background_repeat, page_background_size, page_background_position, page_background_attachment, page_background_blend, page_text_color, page_heading_font, page_subheading_font, page_body_font, page_subnav_color, page_subnav_font, page_subnav_size, page_subnav_gap, page_subnav_width, page_item_search_flag, page_header_sort, page_active_flag, page_revision_dtime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now()) RETURNING page_key, page_revision_dtime");
    $stmt->execute([
        $code,
        trim($input['page_title'] ?? '') ?: null,
        trim($input['page_label'] ?? '') ?: null,
        trim($input['page_meta_description'] ?? '') ?: null,
        trim($input['page_url'] ?? '') ?: null,
        trim($input['page_heading'] ?? '') ?: null,
        trim($input['page_subheading'] ?? '') ?: null,
        trim($input['page_description'] ?? '') ?: null,
        trim($input['page_heading_color'] ?? '') ?: null,
        trim($input['page_heading_size'] ?? '') ?: null,
        trim($input['page_subheading_color'] ?? '') ?: null,
        trim($input['page_subheading_size'] ?? '') ?: null,
        trim($input['page_description_color'] ?? '') ?: null,
        trim($input['page_description_size'] ?? '') ?: null,
        trim($input['page_description_class'] ?? '') ?: null,
        trim($input['page_description_style'] ?? '') ?: null,
        trim($input['page_background_color'] ?? '') ?: null,
        // Page background image laid OVER the background colour. Every CSS
        // background sub-property is stored verbatim (repeat / size / position
        // / attachment / blend-mode) so the renderer can reproduce any
        // combination; blank = that sub-property is simply not emitted.
        trim($input['page_background_image'] ?? '') ?: null,
        trim($input['page_background_repeat'] ?? '') ?: null,
        trim($input['page_background_size'] ?? '') ?: null,
        trim($input['page_background_position'] ?? '') ?: null,
        trim($input['page_background_attachment'] ?? '') ?: null,
        trim($input['page_background_blend'] ?? '') ?: null,
        trim($input['page_text_color'] ?? '') ?: null,
        trim($input['page_heading_font'] ?? '') ?: null,
        trim($input['page_subheading_font'] ?? '') ?: null,
        trim($input['page_body_font'] ?? '') ?: null,
        // Subheader-menu (sub-toolbar) style overrides — mirror the Category
        // list style set on Items sections. Blank = fall back to the built-in
        // gold .page-tabs treatment.
        trim($input['page_subnav_color'] ?? '') ?: null,
        trim($input['page_subnav_font'] ?? '') ?: null,
        trim($input['page_subnav_size'] ?? '') ?: null,
        trim($input['page_subnav_gap'] ?? '') ?: null,
        trim($input['page_subnav_width'] ?? '') ?: null,
        // Opt-in to the site search bar's Video Group dropdown, and the sort
        // within it. Blank sort stores 0 so ordering stays deterministic.
        ($input['page_item_search_flag'] ?? false) ? 't' : 'f',
        (int)($input['page_header_sort'] ?? 0),
        ($input['page_active_flag'] ?? true) ? 't' : 'f',
    ]);
    $created = $stmt->fetch();
    jsonResponse(['page_key' => (int)$created['page_key'], 'saved_at' => $created['page_revision_dtime']], 201);

case 'PUT':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');
    $input = normaliseLegacyPageFields(json_decode(file_get_contents('php://input'), true));
    if (!$input) errorResponse('Invalid JSON');

    $check = $db->prepare("SELECT page_key FROM yy_page WHERE page_key = ?");
    $check->execute([$key]);
    if (!$check->fetch()) errorResponse('Not found', 404);

    $code = trim($input['page_code'] ?? '');
    if (!$code) errorResponse('page_code is required');

    // NOTE: the per-page font columns (page_*_font) are no longer edited
    // from the page form — default fonts now live globally in yy_page_style
    // (Styles tab). COALESCE keeps any legacy per-page override in place when the
    // payload omits the key, so saving a page never wipes an existing override.
    $stmt = $db->prepare("UPDATE yy_page SET page_code = ?, page_title = ?, page_label = ?, page_meta_description = ?, page_url = ?, page_heading = ?, page_subheading = ?, page_description = ?, page_heading_color = ?, page_heading_size = ?, page_subheading_color = ?, page_subheading_size = ?, page_description_color = ?, page_description_size = ?, page_description_class = ?, page_description_style = ?, page_background_color = ?, page_background_image = ?, page_background_repeat = ?, page_background_size = ?, page_background_position = ?, page_background_attachment = ?, page_background_blend = ?, page_text_color = ?, page_heading_font = COALESCE(?, page_heading_font), page_subheading_font = COALESCE(?, page_subheading_font), page_body_font = COALESCE(?, page_body_font), page_subnav_color = ?, page_subnav_font = ?, page_subnav_size = ?, page_subnav_gap = ?, page_subnav_width = ?, page_item_search_flag = ?, page_header_sort = ?, page_active_flag = ?, page_revision_dtime = now() WHERE page_key = ? RETURNING page_revision_dtime");
    $stmt->execute([
        $code,
        trim($input['page_title'] ?? '') ?: null,
        trim($input['page_label'] ?? '') ?: null,
        trim($input['page_meta_description'] ?? '') ?: null,
        trim($input['page_url'] ?? '') ?: null,
        trim($input['page_heading'] ?? '') ?: null,
        trim($input['page_subheading'] ?? '') ?: null,
        trim($input['page_description'] ?? '') ?: null,
        trim($input['page_heading_color'] ?? '') ?: null,
        trim($input['page_heading_size'] ?? '') ?: null,
        trim($input['page_subheading_color'] ?? '') ?: null,
        trim($input['page_subheading_size'] ?? '') ?: null,
        trim($input['page_description_color'] ?? '') ?: null,
        trim($input['page_description_size'] ?? '') ?: null,
        trim($input['page_description_class'] ?? '') ?: null,
        trim($input['page_description_style'] ?? '') ?: null,
        trim($input['page_background_color'] ?? '') ?: null,
        // Page background image laid OVER the background colour. Every CSS
        // background sub-property is stored verbatim (repeat / size / position
        // / attachment / blend-mode) so the renderer can reproduce any
        // combination; blank = that sub-property is simply not emitted.
        trim($input['page_background_image'] ?? '') ?: null,
        trim($input['page_background_repeat'] ?? '') ?: null,
        trim($input['page_background_size'] ?? '') ?: null,
        trim($input['page_background_position'] ?? '') ?: null,
        trim($input['page_background_attachment'] ?? '') ?: null,
        trim($input['page_background_blend'] ?? '') ?: null,
        trim($input['page_text_color'] ?? '') ?: null,
        trim($input['page_heading_font'] ?? '') ?: null,
        trim($input['page_subheading_font'] ?? '') ?: null,
        trim($input['page_body_font'] ?? '') ?: null,
        trim($input['page_subnav_color'] ?? '') ?: null,
        trim($input['page_subnav_font'] ?? '') ?: null,
        trim($input['page_subnav_size'] ?? '') ?: null,
        trim($input['page_subnav_gap'] ?? '') ?: null,
        trim($input['page_subnav_width'] ?? '') ?: null,
        // Opt-in to the site search bar's Video Group dropdown, and the sort
        // within it. Blank sort stores 0 so ordering stays deterministic.
        ($input['page_item_search_flag'] ?? false) ? 't' : 'f',
        (int)($input['page_header_sort'] ?? 0),
        ($input['page_active_flag'] ?? true) ? 't' : 'f',
        $key,
    ]);
    jsonResponse(['ok' => true, 'saved_at' => $stmt->fetchColumn()]);

case 'DELETE':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');

    // Since the 2026-08-23 unification this row IS the site's feed anchor, and
    // yy_feed_item_page / yy_feed_page / yy_menu_item / yy_page_alias all
    // cascade off it. Deleting a busy page used to cost a yy_page_test row and
    // its sections; it now destroys every feed association too. Refuse unless
    // the caller has seen the damage and passed force=1.
    $dep = $db->prepare("
        SELECT (SELECT count(*) FROM yy_feed_item_page     WHERE page_key = :k) AS feed_items,
               (SELECT count(*) FROM yy_feed_page          WHERE page_key = :k) AS feeds,
               (SELECT count(*) FROM yy_feed_page_category WHERE page_key = :k) AS feed_categories,
               (SELECT count(*) FROM yy_menu_item          WHERE page_key = :k) AS menu_items,
               (SELECT count(*) FROM yy_page_alias         WHERE page_key = :k) AS aliases");
    $dep->execute([':k' => $key]);
    $counts = $dep->fetch(PDO::FETCH_ASSOC) ?: [];
    $blocking = array_filter($counts, static fn($n) => (int)$n > 0);

    if ($blocking && empty($_GET['force'])) {
        $parts = [];
        foreach ($blocking as $what => $n) $parts[] = $n . ' ' . str_replace('_', ' ', $what);
        errorResponse('Deleting this page would also destroy ' . implode(', ', $parts)
            . '. Re-send with force=1 to confirm.', 409);
    }

    $db->prepare("DELETE FROM yy_page WHERE page_key = ?")->execute([$key]);
    jsonResponse(['ok' => true, 'destroyed' => $blocking]);

default:
    errorResponse('Method not allowed', 405);
}
