<?php
/**
 * TEST endpoint — site menu management for the Pages-New system.
 * Manages yy_menu_item: the Header / Subheader / Footer link lists that
 * render on every /test/page.php page. Items point at yy_page pages.
 *
 *   GET    ?action=pages                       → selectable page catalogue (yy_page)
 *   GET    ?action=footer-config               → { footer_link_columns }
 *   PUT    ?action=footer-config               body { footer_link_columns }  (1–6)
 *   GET    ?zone=header|footer                 → items in that zone (flat)
 *   GET    ?zone=subheader&parent=H            → subheader items under header item H
 *   POST   ?zone=header|subheader|footer       body {page_key, parent_menu_item_key?}
 *   PUT    ?key=K                              body {menu_item_sort}   (reorder)
 *   DELETE ?key=K
 *
 * menu_zone: 'header' / 'footer' are flat (parent_menu_item_key NULL);
 * 'subheader' rows hang off a header row via parent_menu_item_key. Sort is
 * per-zone (and per-parent for subheaders): menu_item_sort ASC, then key.
 */
require_once __DIR__ . '/../config.php';
requireAuth();

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$zone   = $_GET['zone'] ?? '';
$key    = (int)($_GET['key'] ?? 0);

const MENU_ZONES = ['header', 'subheader', 'footer'];

// Display label for a menu row / page catalogue entry (variable so it
// interpolates into the double-quoted SQL below).
$pageLabelSql = "COALESCE(NULLIF(p.page_label, ''), NULLIF(p.page_title, ''), p.page_code)";

// ── Page catalogue (what you can add to a list) ───────────────────────────
if ($action === 'pages' && $method === 'GET') {
    $st = $db->query("
        SELECT p.page_key,
               p.page_code,
               p.page_url,
               $pageLabelSql AS display
          FROM yy_page p
         WHERE p.page_active_flag = TRUE
         ORDER BY display
    ");
    jsonResponse(['pages' => $st->fetchAll()]);
}

// ── Footer layout config ───────────────────────────────────────────────────
// The number of columns the Footer "Links" area flows across before wrapping to
// a new row. Global (the footer is identical on every Pages-New page), so it is
// stored on the singleton yy_page_style row alongside the global fonts.
if ($action === 'footer-config') {
    if ($method === 'GET') {
        $n = $db->query("SELECT footer_link_columns FROM yy_page_style WHERE style_key = 1")->fetchColumn();
        jsonResponse(['footer_link_columns' => ($n === false || $n === null) ? 3 : (int)$n]);
    }
    if ($method === 'PUT') {
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $n = (int)($d['footer_link_columns'] ?? 3);
        if ($n < 1) $n = 1;
        if ($n > 6) $n = 6;
        $st = $db->prepare("
            INSERT INTO yy_page_style (style_key, footer_link_columns, updated_dtime)
            VALUES (1, ?, now())
            ON CONFLICT (style_key) DO UPDATE
               SET footer_link_columns = EXCLUDED.footer_link_columns,
                   updated_dtime = now()
            RETURNING footer_link_columns
        ");
        $st->execute([$n]);
        jsonResponse(['saved' => true, 'footer_link_columns' => (int)$st->fetchColumn()]);
    }
    errorResponse('Unsupported request', 400);
}

// ── List items in a zone ──────────────────────────────────────────────────
if ($method === 'GET' && in_array($zone, MENU_ZONES, true)) {
    $sql = "
        SELECT m.menu_item_key, m.menu_zone, m.page_key, m.parent_menu_item_key,
               m.menu_item_sort, m.menu_item_active_flag,
               p.page_code, p.page_url,
               $pageLabelSql AS display
          FROM yy_menu_item m
          JOIN yy_page p ON p.page_key = m.page_key
         WHERE m.menu_zone = ?
    ";
    $params = [$zone];
    if ($zone === 'subheader') {
        $parent = (int)($_GET['parent'] ?? 0);
        if (!$parent) jsonResponse(['items' => []]);   // no header selected → empty
        $sql .= " AND m.parent_menu_item_key = ?";
        $params[] = $parent;
    } else {
        $sql .= " AND m.parent_menu_item_key IS NULL";
    }
    $sql .= " ORDER BY m.menu_item_sort, m.menu_item_key";
    $st = $db->prepare($sql);
    $st->execute($params);
    jsonResponse(['items' => $st->fetchAll()]);
}

// ── Add an item to a zone ─────────────────────────────────────────────────
if ($method === 'POST' && in_array($zone, MENU_ZONES, true)) {
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $pageKey = (int)($d['page_key'] ?? 0);
    if (!$pageKey) errorResponse('page_key required');

    // Validate the page exists.
    $chk = $db->prepare("SELECT 1 FROM yy_page WHERE page_key = ?");
    $chk->execute([$pageKey]);
    if (!$chk->fetchColumn()) errorResponse('Unknown page', 404);

    $parentKey = null;
    if ($zone === 'subheader') {
        $parentKey = (int)($d['parent_menu_item_key'] ?? 0);
        if (!$parentKey) errorResponse('parent_menu_item_key required for subheader');
        // Parent must be an existing header item.
        $pchk = $db->prepare("SELECT 1 FROM yy_menu_item WHERE menu_item_key = ? AND menu_zone = 'header'");
        $pchk->execute([$parentKey]);
        if (!$pchk->fetchColumn()) errorResponse('parent_menu_item_key must be a header item', 400);
    }

    // A page may only appear once per list (safety net; the admin UI already
    // hides used pages from the add-dropdown). Same page can live in different
    // lists — the check is scoped to this zone (+ parent for subheaders).
    if ($parentKey === null) {
        $dup = $db->prepare("SELECT 1 FROM yy_menu_item WHERE menu_zone = ? AND parent_menu_item_key IS NULL AND page_key = ?");
        $dup->execute([$zone, $pageKey]);
    } else {
        $dup = $db->prepare("SELECT 1 FROM yy_menu_item WHERE menu_zone = ? AND parent_menu_item_key = ? AND page_key = ?");
        $dup->execute([$zone, $parentKey, $pageKey]);
    }
    if ($dup->fetchColumn()) errorResponse('That page is already in this list', 409);

    // Append to the end of its list.
    if ($parentKey === null) {
        $ns = $db->prepare("SELECT COALESCE(MAX(menu_item_sort), -1) + 1 FROM yy_menu_item WHERE menu_zone = ? AND parent_menu_item_key IS NULL");
        $ns->execute([$zone]);
    } else {
        $ns = $db->prepare("SELECT COALESCE(MAX(menu_item_sort), -1) + 1 FROM yy_menu_item WHERE menu_zone = ? AND parent_menu_item_key = ?");
        $ns->execute([$zone, $parentKey]);
    }
    $sort = (int)$ns->fetchColumn();

    $ins = $db->prepare("INSERT INTO yy_menu_item (menu_zone, page_key, parent_menu_item_key, menu_item_sort) VALUES (?, ?, ?, ?) RETURNING menu_item_key");
    $ins->execute([$zone, $pageKey, $parentKey, $sort]);
    jsonResponse(['saved' => true, 'menu_item_key' => (int)$ins->fetchColumn(), 'menu_item_sort' => $sort]);
}

// ── Reorder (update sort) ─────────────────────────────────────────────────
// Two callers: the ▲▼ nudge fires paired PUTs that *swap* neighbouring sorts
// (no shift wanted), while the typed number input sends {shift:true} asking us
// to "insert" at the chosen slot — if another row in the same list already
// holds that value, push it and everything above it up by one to make room.
if ($method === 'PUT') {
    if (!$key) errorResponse('menu_item_key required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!array_key_exists('menu_item_sort', $d)) errorResponse('menu_item_sort required');
    $sort  = ($d['menu_item_sort'] === '' || $d['menu_item_sort'] === null) ? 0 : (int)$d['menu_item_sort'];
    $shift = !empty($d['shift']);

    // Locate the row's list (per-zone, and per-parent for subheaders) so any
    // shift stays scoped to its own siblings.
    $ctxSt = $db->prepare("SELECT menu_zone, parent_menu_item_key FROM yy_menu_item WHERE menu_item_key = ?");
    $ctxSt->execute([$key]);
    $ctx = $ctxSt->fetch();
    if (!$ctx) errorResponse('Unknown menu item', 404);
    $itemZone = $ctx['menu_zone'];
    $parent   = $ctx['parent_menu_item_key'];   // NULL for header/footer

    // Same-list scope fragment + params, reused for the clash check and shift.
    if ($parent === null) {
        $scope       = "menu_zone = ? AND parent_menu_item_key IS NULL AND menu_item_key <> ?";
        $scopeParams = [$itemZone, $key];
    } else {
        $scope       = "menu_zone = ? AND parent_menu_item_key = ? AND menu_item_key <> ?";
        $scopeParams = [$itemZone, $parent, $key];
    }

    $db->beginTransaction();
    try {
        if ($shift) {
            // Only make room when the slot is actually taken by another row.
            $clash = $db->prepare("SELECT 1 FROM yy_menu_item WHERE $scope AND menu_item_sort = ?");
            $clash->execute(array_merge($scopeParams, [$sort]));
            if ($clash->fetchColumn()) {
                $bump = $db->prepare("UPDATE yy_menu_item SET menu_item_sort = menu_item_sort + 1 WHERE $scope AND menu_item_sort >= ?");
                $bump->execute(array_merge($scopeParams, [$sort]));
            }
        }
        $up = $db->prepare("UPDATE yy_menu_item SET menu_item_sort = ? WHERE menu_item_key = ?");
        $up->execute([$sort, $key]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    jsonResponse(['saved' => true]);
}

// ── Delete an item (subheader children cascade for header rows) ───────────
if ($method === 'DELETE') {
    if (!$key) errorResponse('menu_item_key required');
    $db->prepare("DELETE FROM yy_menu_item WHERE menu_item_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Unsupported request', 400);
