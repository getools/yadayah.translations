<?php
/**
 * TEST endpoint — feed-item + category management for the prototype page
 * editor's Items section. Keyed by page_key (the feed/yy_page key the
 * section pulls from). Mirrors /api/admin-feed.php's writes exactly, but
 * page_key-based and scoped to the /test editor:
 *
 *   GET    ?action=categories&page_key=N
 *   POST   ?action=category&page_key=N    body {category_title, category_subtitle?, category_sort?}
 *   PUT    ?action=category&key=K         body {category_title?, category_subtitle?, category_sort?}
 *   DELETE ?action=category&key=K
 *   PUT    ?action=item&key=K&page_key=N   body {sort?, episode?, category_key?}
 *
 * Sort & Episode are item-level (yy_feed_item). Category assignment lives in
 * yy_feed_item_category, page-scoped (one category per page per item).
 *
 * ── New-system category list (yy_category, scoped per section_key) ──
 * The prototype editor's Categories panel manages each Items section's OWN
 * category set in yy_category. These are display/definition only for now:
 * item COUNTS are bridged from the matching page category (page_key + slug),
 * and per-item ASSIGNMENT still writes yy_feed_item_category (FK →
 * yy_feed_page_category), so the 1,074 live assignments and production
 * category pages are untouched.
 *   GET    ?action=section_categories&section_key=K&page_key=N
 *   POST   ?action=section_category&section_key=K   body {category_title, category_subtitle?, category_sort?}
 *   PUT    ?action=section_category&key=K           body {category_title?, category_subtitle?, category_sort?}
 *   DELETE ?action=section_category&key=K
 *
 * ── New-system item list (yy_section_item, the materialized matching pool) ──
 * The editor's Matching-items list reads these (membership + order from the
 * filter rebuild) and assigns each item's section-scoped category directly on
 * yy_section_item.category_key (NOT yy_feed_item_category). Sort/episode stay
 * item-level on yy_feed_item via ?action=item.
 *   GET    ?action=section_items&section_key=K
 *   PUT    ?action=section_item&section_key=K&key=FEED_ITEM_KEY  body {category_key}
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../feed-helpers.php';   // normalizeMediaUrl()
requireAuth();

$db      = getDb();
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? '';
$key     = (int)($_GET['key'] ?? 0);
$pageKey = (int)($_GET['page_key'] ?? 0);

function tslug(string $t): string {
    return trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($t)))), '-');
}

// ── Categories ──────────────────────────────────────────────────────────
if ($action === 'categories' && $method === 'GET') {
    if (!$pageKey) errorResponse('page_key required');
    $st = $db->prepare("
        SELECT c.category_key, c.category_title, c.category_subtitle, c.category_slug, c.category_sort,
               (SELECT COUNT(*) FROM yy_feed_item_category fic
                  JOIN yy_feed_item fi ON fi.feed_item_key = fic.feed_item_key
                 WHERE fic.category_key = c.category_key AND fi.feed_item_active_flag = TRUE) AS item_count
          FROM yy_feed_page_category c
         WHERE c.page_key = ? AND c.category_active_flag = TRUE
         ORDER BY c.category_sort, c.category_title
    ");
    $st->execute([$pageKey]);
    jsonResponse(['categories' => $st->fetchAll()]);
}

if ($action === 'category' && $method === 'POST') {
    if (!$pageKey) errorResponse('page_key required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($d['category_title'] ?? '');
    if ($title === '') errorResponse('Category title is required');
    $sub  = trim($d['category_subtitle'] ?? '');
    $sort = (int)($d['category_sort'] ?? 0);
    $st = $db->prepare("INSERT INTO yy_feed_page_category (page_key, category_title, category_subtitle, category_slug, category_sort) VALUES (?, ?, ?, ?, ?) RETURNING category_key");
    $st->execute([$pageKey, $title, $sub ?: null, tslug($title), $sort]);
    jsonResponse(['saved' => true, 'category_key' => (int)$st->fetchColumn()]);
}

if ($action === 'category' && $method === 'PUT') {
    if (!$key) errorResponse('category key required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $f = []; $p = [];
    if (isset($d['category_title'])) {
        $f[] = 'category_title = ?'; $p[] = trim($d['category_title']);
        $f[] = 'category_slug = ?';  $p[] = tslug($d['category_title']);
    }
    if (array_key_exists('category_subtitle', $d)) {
        $f[] = 'category_subtitle = ?'; $p[] = trim($d['category_subtitle']) ?: null;
    }
    if (isset($d['category_sort'])) {
        $f[] = 'category_sort = ?'; $p[] = (int)$d['category_sort'];
    }
    if (!$f) errorResponse('Nothing to update');
    $f[] = 'category_revision_dtime = NOW()'; $p[] = $key;
    $db->prepare("UPDATE yy_feed_page_category SET " . implode(', ', $f) . " WHERE category_key = ?")->execute($p);
    jsonResponse(['saved' => true]);
}

if ($action === 'category' && $method === 'DELETE') {
    if (!$key) errorResponse('category key required');
    // yy_feed_item_category rows removed by ON DELETE CASCADE.
    $db->prepare("DELETE FROM yy_feed_page_category WHERE category_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

// ── Section categories (NEW system — yy_category, scoped by section_key) ──
// The editor's Categories panel reads/writes these. Item counts are bridged
// from the page's yy_feed_page_category (matched by page_key + slug, which is
// unique within a page) so the column stays meaningful without re-pointing the
// live yy_feed_item_category FK.
if ($action === 'section_categories' && $method === 'GET') {
    $sectionKey = (int)($_GET['section_key'] ?? 0);
    if (!$sectionKey) errorResponse('section_key required');
    $st = $db->prepare("
        SELECT yc.category_key, yc.category_title, yc.category_subtitle, yc.category_slug,
               yc.category_description, yc.category_sort,
               COALESCE((
                   SELECT COUNT(*) FROM yy_feed_item_category fic
                     JOIN yy_feed_item fi ON fi.feed_item_key = fic.feed_item_key
                     JOIN yy_feed_page_category pc ON pc.category_key = fic.category_key
                    WHERE pc.page_key = ? AND pc.category_slug = yc.category_slug
                      AND fi.feed_item_active_flag = TRUE
               ), 0) AS item_count
          FROM yy_category yc
         WHERE yc.section_key = ? AND yc.category_active_flag = TRUE
         ORDER BY yc.category_sort, yc.category_title
    ");
    $st->execute([$pageKey, $sectionKey]);
    jsonResponse(['categories' => $st->fetchAll()]);
}

if ($action === 'section_category' && $method === 'POST') {
    $sectionKey = (int)($_GET['section_key'] ?? 0);
    if (!$sectionKey) errorResponse('section_key required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($d['category_title'] ?? '');
    if ($title === '') errorResponse('Category title is required');
    $sub  = trim($d['category_subtitle'] ?? '');
    $desc = trim($d['category_description'] ?? '');
    $sort = (int)($d['category_sort'] ?? 0);
    // Slug defaults to a slugified title but may be supplied explicitly.
    $slug = isset($d['category_slug']) && trim($d['category_slug']) !== ''
        ? tslug($d['category_slug']) : tslug($title);
    $st = $db->prepare("INSERT INTO yy_category (section_key, category_title, category_subtitle, category_slug, category_description, category_sort) VALUES (?, ?, ?, ?, ?, ?) RETURNING category_key");
    $st->execute([$sectionKey, $title, $sub ?: null, $slug, $desc ?: null, $sort]);
    jsonResponse(['saved' => true, 'category_key' => (int)$st->fetchColumn()]);
}

if ($action === 'section_category' && $method === 'PUT') {
    if (!$key) errorResponse('category key required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $f = []; $p = [];
    // Title and slug are now independently editable. Updating the title no
    // longer rewrites the slug; the slug is only changed when sent explicitly.
    if (isset($d['category_title'])) {
        $f[] = 'category_title = ?'; $p[] = trim($d['category_title']);
    }
    if (array_key_exists('category_slug', $d)) {
        // Normalise to a valid slug; fall back to the title's slug if cleared.
        $slug = tslug($d['category_slug']);
        if ($slug === '' && isset($d['category_title'])) $slug = tslug($d['category_title']);
        $f[] = 'category_slug = ?'; $p[] = $slug;
    }
    if (array_key_exists('category_subtitle', $d)) {
        $f[] = 'category_subtitle = ?'; $p[] = trim($d['category_subtitle']) ?: null;
    }
    if (array_key_exists('category_description', $d)) {
        $f[] = 'category_description = ?'; $p[] = trim($d['category_description']) ?: null;
    }
    if (isset($d['category_sort'])) {
        $f[] = 'category_sort = ?'; $p[] = (int)$d['category_sort'];
    }
    if (!$f) errorResponse('Nothing to update');
    $p[] = $key;
    // category_revision_dtime/num are bumped by the yy_category _rev trigger.
    $db->prepare("UPDATE yy_category SET " . implode(', ', $f) . " WHERE category_key = ?")->execute($p);
    jsonResponse(['saved' => true]);
}

if ($action === 'section_category' && $method === 'DELETE') {
    if (!$key) errorResponse('category key required');
    // yy_category is NOT referenced by yy_feed_item_category, so removing a
    // section category leaves live item assignments intact (interim design).
    // The _rev trigger records the delete.
    $db->prepare("DELETE FROM yy_category WHERE category_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

// ── Section items (NEW system — yy_section_item: the materialized matching pool) ──
if ($action === 'section_items' && $method === 'GET') {
    $sectionKey = (int)($_GET['section_key'] ?? 0);
    if (!$sectionKey) errorResponse('section_key required');
    $st = $db->prepare("
        SELECT si.feed_item_key,
               COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS title,
               fi.feed_item_thumbnail AS thumbnail,
               fi.feed_item_duration  AS duration,
               COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) AS posted,
               fi.feed_item_sort      AS sort,
               fi.feed_item_episode   AS episode,
               si.category_key,
               c.category_title       AS category
          FROM yy_section_item si
          JOIN yy_feed_item fi ON fi.feed_item_key = si.feed_item_key
          LEFT JOIN yy_category c ON c.category_key = si.category_key
         WHERE si.section_key = ?
         ORDER BY si.section_item_sort, si.feed_item_key
    ");
    $st->execute([$sectionKey]);
    $items = $st->fetchAll();
    foreach ($items as &$it) {
        if (isset($it['thumbnail'])) $it['thumbnail'] = normalizeMediaUrl($it['thumbnail']);
        $it['feed_item_key'] = (int)$it['feed_item_key'];
        $it['sort']          = $it['sort'] !== null ? (int)$it['sort'] : null;
        $it['category_key']  = $it['category_key'] !== null ? (int)$it['category_key'] : null;
    }
    unset($it);
    jsonResponse(['items' => $items, 'total' => count($items)]);
}

// Update an item's section-scoped fields on yy_section_item: category_key
// and/or section_item_sort (the per-section manual order — the Items+Section
// table, independent of the global feed_item_sort). PATCH style: only fields
// present in the body are touched, so the editor can save just Sort or just
// Category.
if ($action === 'section_item' && $method === 'PUT') {
    $sectionKey = (int)($_GET['section_key'] ?? 0);
    if (!$sectionKey) errorResponse('section_key required');
    if (!$key)        errorResponse('item (feed_item_key) required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];

    $sets = [];
    $vals = [];
    if (array_key_exists('category_key', $d)) {
        $catKey = ($d['category_key'] !== null && $d['category_key'] !== '') ? (int)$d['category_key'] : null;
        // Only categories belonging to this section may be assigned (or NULL).
        if ($catKey !== null) {
            $chk = $db->prepare("SELECT 1 FROM yy_category WHERE category_key = ? AND section_key = ?");
            $chk->execute([$catKey, $sectionKey]);
            if (!$chk->fetchColumn()) errorResponse('Category does not belong to this section');
        }
        $sets[] = 'category_key = ?';
        $vals[] = $catKey;
    }
    if (array_key_exists('section_item_sort', $d)) {
        $sv = $d['section_item_sort'];
        $sets[] = 'section_item_sort = ?';
        $vals[] = ($sv === null || $sv === '') ? 0 : (int)$sv;
    }
    if (!$sets) errorResponse('category_key or section_item_sort required');

    // The row must already exist (the list is the materialized pool).
    $sets[] = 'section_item_dtime = NOW()';
    $vals[] = $sectionKey;
    $vals[] = $key;
    $upd = $db->prepare("UPDATE yy_section_item SET " . implode(', ', $sets) . " WHERE section_key = ? AND feed_item_key = ?");
    $upd->execute($vals);
    jsonResponse(['saved' => true, 'updated' => $upd->rowCount()]);
}

// ── Item (sort / episode / category) ─────────────────────────────────────
if ($action === 'item' && $method === 'PUT') {
    if (!$key)     errorResponse('item key required');
    if (!$pageKey) errorResponse('page_key required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $f = []; $p = [];
    if (isset($d['sort'])) {
        $f[] = 'feed_item_sort = ?'; $p[] = (int)$d['sort'];
        stampManualField($db, $key, 'sort');        // most-recent-wins vs hashtag
    }
    if (array_key_exists('episode', $d)) {
        $f[] = 'feed_item_episode = ?';
        $p[] = ($d['episode'] !== null && $d['episode'] !== '') ? trim($d['episode']) : null;
        stampManualField($db, $key, 'episode');
    }
    // Category → yy_feed_item_category, page-scoped (one per page per item).
    if (array_key_exists('category_key', $d)) {
        $catKey = $d['category_key'] ? (int)$d['category_key'] : null;
        $db->prepare("DELETE FROM yy_feed_item_category WHERE feed_item_key = ? AND category_key IN (SELECT category_key FROM yy_feed_page_category WHERE page_key = ?)")
           ->execute([$key, $pageKey]);
        if ($catKey) {
            $db->prepare("INSERT INTO yy_feed_item_category (feed_item_key, category_key) VALUES (?, ?) ON CONFLICT (feed_item_key, category_key) DO NOTHING")
               ->execute([$key, $catKey]);
        }
        stampManualField($db, $key, 'category:' . $pageKey);
    }
    if ($f) {
        $f[] = 'feed_item_revision_dtime = NOW()'; $p[] = $key;
        $db->prepare("UPDATE yy_feed_item SET " . implode(', ', $f) . " WHERE feed_item_key = ?")->execute($p);
    }
    jsonResponse(['saved' => true]);
}

errorResponse('Unknown action');
