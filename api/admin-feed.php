<?php
/**
 * Unified Admin API for feed-based pages (Basics, Vlog, Invite, DoYouYada, Music).
 * The ?page= parameter determines which page's categories and items to manage.
 *
 * GET  ?page=X&type=categories   — list categories for this page
 * GET  ?page=X&type=videos       — list items for this page
 * POST ?page=X&type=category     — create category
 * PUT  ?page=X&type=category&key=N — update category
 * DELETE ?page=X&type=category&key=N — delete category
 * PUT  ?page=X&type=video&key=N  — update item
 * DELETE ?page=X&type=video&key=N — soft-delete item
 * POST ?page=X&type=audio&key=N  — upload MP3
 * DELETE ?page=X&type=audio&key=N — remove MP3
 * GET  ?page=X&type=config       — load page settings
 * PUT  ?page=X&type=config       — save page settings
 * POST ?page=X&type=sync         — trigger feed sync
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed-helpers.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$type = $_GET['type'] ?? '';
$key = (int)($_GET['key'] ?? 0);
$pageCode = $_GET['page'] ?? '';

if (!$pageCode) errorResponse('page parameter required');

// Resolve page_key from page_code
$pageStmt = $db->prepare("SELECT page_key, page_code, page_title FROM yy_page WHERE page_code = ?");
$pageStmt->execute([$pageCode]);
$pageRow = $pageStmt->fetch();
if (!$pageRow) errorResponse('Unknown page: ' . $pageCode);
$PAGE_KEY = (int)$pageRow['page_key'];
$PAGE_CODE = $pageRow['page_code'];
$PAGE_TITLE = $pageRow['page_title'];

// Load feed configuration for this page
function loadFeedConfig(PDO $db, string $pageCode): array {
    $stmt = $db->prepare("
        SELECT f.feed_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude, fp.feed_page_filter_orientation
        FROM yy_feed_page fp
        JOIN yy_feed f ON f.feed_key = fp.feed_key
        JOIN yy_page p ON p.page_key = fp.page_key
        WHERE p.page_code = ?
        ORDER BY fp.feed_page_sort, fp.feed_page_key
        LIMIT 1
    ");
    $stmt->execute([$pageCode]);
    $row = $stmt->fetch();
    return [
        'feed_key'    => $row ? (int)$row['feed_key'] : 1,
        'include'     => $row ? array_filter(array_map('trim', explode(',', $row['feed_page_filter_include'] ?? ''))) : [],
        'exclude'     => $row ? array_filter(array_map('trim', explode(',', $row['feed_page_filter_exclude'] ?? ''))) : [],
        'orientation' => $row['feed_page_filter_orientation'] ?? null,
    ];
}

function slugify(string $text): string {
    return trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($text)))), '-');
}

// ── Categories ──
if ($type === 'categories' && $method === 'GET') {
    $catStmt = $db->prepare("
        SELECT c.category_key, c.category_title, c.category_subtitle, c.category_slug, c.category_sort,
               (SELECT COUNT(*) FROM yy_feed_item_category fic
                JOIN yy_feed_item fi ON fi.feed_item_key = fic.feed_item_key
                WHERE fic.category_key = c.category_key AND fi.feed_item_active_flag = TRUE) AS video_count
        FROM yy_feed_page_category c
        WHERE c.page_key = ? AND c.category_active_flag = TRUE
        ORDER BY c.category_sort, c.category_title
    ");
    $catStmt->execute([$PAGE_KEY]);
    jsonResponse(['categories' => $catStmt->fetchAll()]);
}

if ($type === 'category' && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($data['category_title'] ?? '');
    if (!$title) errorResponse('Category title is required');
    $subtitle = trim($data['category_subtitle'] ?? '');
    $sort = (int)($data['category_sort'] ?? 0);
    $slug = slugify($title);
    $stmt = $db->prepare("INSERT INTO yy_feed_page_category (page_key, category_title, category_subtitle, category_slug, category_sort) VALUES (?, ?, ?, ?, ?) RETURNING category_key");
    $stmt->execute([$PAGE_KEY, $title, $subtitle ?: null, $slug, $sort]);
    jsonResponse(['saved' => true, 'category_key' => $stmt->fetchColumn()]);
}

if ($type === 'category' && $method === 'PUT') {
    if (!$key) errorResponse('Category key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $fields = [];
    $params = [];
    if (isset($data['category_title'])) {
        $fields[] = 'category_title = ?';
        $params[] = trim($data['category_title']);
        $fields[] = 'category_slug = ?';
        $params[] = slugify($data['category_title']);
    }
    if (array_key_exists('category_subtitle', $data)) {
        $fields[] = 'category_subtitle = ?';
        $params[] = trim($data['category_subtitle']) ?: null;
    }
    if (isset($data['category_sort'])) {
        $fields[] = 'category_sort = ?';
        $params[] = (int)$data['category_sort'];
    }
    if (isset($data['category_slug'])) {
        $fields[] = 'category_slug = ?';
        $params[] = slugify($data['category_slug']);
    }
    if (empty($fields)) errorResponse('Nothing to update');
    $fields[] = 'category_revision_dtime = NOW()';
    $params[] = $key;
    $db->prepare("UPDATE yy_feed_page_category SET " . implode(', ', $fields) . " WHERE category_key = ?")->execute($params);
    jsonResponse(['saved' => true]);
}

if ($type === 'category' && $method === 'DELETE') {
    if (!$key) errorResponse('Category key required');
    // yy_feed_item_category rows removed by ON DELETE CASCADE
    $db->prepare("DELETE FROM yy_feed_page_category WHERE category_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

// ── Videos / Items ──
if ($type === 'videos' && $method === 'GET') {
    // Read category from yy_feed_item_category (page-scoped to current page)
    $stmt = $db->prepare("
        SELECT fi.feed_item_key, fi.feed_item_external_id, COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS feed_item_title,
               fi.feed_item_thumbnail, fi.feed_item_url, fi.feed_item_sort, fi.feed_item_active_flag,
               fi.feed_item_episode, fi.feed_item_audio_file,
               fic.category_key, c.category_title, c.category_sort
        FROM yy_feed_item fi
        JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key AND fip.page_key = ?
        LEFT JOIN yy_feed_item_category fic ON fic.feed_item_key = fi.feed_item_key
            AND fic.category_key IN (SELECT category_key FROM yy_feed_page_category WHERE page_key = ?)
        LEFT JOIN yy_feed_page_category c ON fic.category_key = c.category_key
        ORDER BY COALESCE(c.category_sort, 999), c.category_title NULLS LAST,
                 fi.feed_item_sort,
                 CASE WHEN fi.feed_item_episode ~ '^\d+$' THEN fi.feed_item_episode::integer ELSE 2147483647 END,
                 fi.feed_item_episode NULLS LAST,
                 COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) DESC NULLS LAST, COALESCE(fi.feed_item_title_override, fi.feed_item_title_import)
    ");
    $stmt->execute([$PAGE_KEY, $PAGE_KEY]);
    $rows = $stmt->fetchAll();

    $videos = [];
    foreach ($rows as $r) {
        $videos[] = [
            'video_key'       => (int)$r['feed_item_key'],
            'video_id'        => $r['feed_item_external_id'],
            'title'           => $r['feed_item_title'],
            'thumbnail'       => $r['feed_item_thumbnail'],
            'url'             => $r['feed_item_url'],
            'sort'            => (int)$r['feed_item_sort'],
            'active_flag'     => $r['feed_item_active_flag'] === true || $r['feed_item_active_flag'] === 't',
            'category_key'    => $r['category_key'] ? (int)$r['category_key'] : null,
            'category_title'  => $r['category_title'] ?? null,
            'episode'         => $r['feed_item_episode'] ?? null,
            'audio_file'      => $r['feed_item_audio_file'] ?? null,
        ];
    }

    jsonResponse(['videos' => $videos]);
}

if ($type === 'video' && $method === 'PUT') {
    if (!$key) errorResponse('Video key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $fields = [];
    $params = [];

    if (isset($data['title'])) {
        $fields[] = 'feed_item_title_override = ?';
        $params[] = trim($data['title']);
        stampManualField($db, $key, 'title');       // most-recent-wins vs hashtag
    }
    if (isset($data['sort'])) {
        $fields[] = 'feed_item_sort = ?';
        $params[] = (int)$data['sort'];
        stampManualField($db, $key, 'sort');
    }
    if (array_key_exists('episode', $data)) {
        $fields[] = 'feed_item_episode = ?';
        $params[] = $data['episode'] !== null && $data['episode'] !== '' ? trim($data['episode']) : null;
        stampManualField($db, $key, 'episode');
    }
    if (isset($data['active_flag'])) {
        $fields[] = 'feed_item_active_flag = ?';
        $params[] = $data['active_flag'] ? 't' : 'f';
    }

    // Category goes to yy_feed_item_category (page-scoped). Each page owns its own
    // category for the same item, independent of other pages.
    if (array_key_exists('category_key', $data)) {
        $catKey = $data['category_key'] ? (int)$data['category_key'] : null;
        $db->prepare("DELETE FROM yy_feed_item_category WHERE feed_item_key = ? AND category_key IN (SELECT category_key FROM yy_feed_page_category WHERE page_key = ?)")
           ->execute([$key, $PAGE_KEY]);
        if ($catKey) {
            $db->prepare("INSERT INTO yy_feed_item_category (feed_item_key, category_key) VALUES (?, ?) ON CONFLICT (feed_item_key, category_key) DO NOTHING")
               ->execute([$key, $catKey]);
        }
        stampManualField($db, $key, 'category:' . $PAGE_KEY);
    }

    if ($fields) {
        $fields[] = 'feed_item_revision_dtime = NOW()';
        $params[] = $key;
        $db->prepare("UPDATE yy_feed_item SET " . implode(', ', $fields) . " WHERE feed_item_key = ?")->execute($params);
    }
    // Re-evaluate page associations
    require_once __DIR__ . '/feed-item-pages.php';
    updateItemPages($db, $key);
    jsonResponse(['saved' => true]);
}

if ($type === 'video' && $method === 'DELETE') {
    if (!$key) errorResponse('Video key required');
    $db->prepare("UPDATE yy_feed_item SET feed_item_active_flag = FALSE, feed_item_revision_dtime = NOW() WHERE feed_item_key = ?")->execute([$key]);
    // Remove page associations for deactivated item
    $db->prepare("DELETE FROM yy_feed_item_page WHERE feed_item_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

// ── Audio Upload ──
if ($type === 'audio' && $method === 'POST' && $key) {
    $file = $_FILES['audio'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) errorResponse('No file uploaded');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'mp3') errorResponse('Only MP3 files are allowed');

    $filename = 'audio_' . $key . '_' . time() . '.mp3';
    $destDir = '/var/www/html/u/audio';
    if (!is_dir($destDir)) $destDir = dirname(__DIR__) . '/public/u/audio';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $dest = $destDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) errorResponse('Failed to save file');

    $path = 'u/audio/' . $filename;
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ? WHERE feed_item_key = ?")->execute([$path, $key]);
    jsonResponse(['saved' => true, 'audio_file' => $path]);
}

if ($type === 'audio' && $method === 'DELETE' && $key) {
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = NULL WHERE feed_item_key = ?")->execute([$key]);
    jsonResponse(['removed' => true]);
}

// ── Page Settings (Config) ──
if ($type === 'config' && $method === 'GET') {
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'page' AND setting_group_code = ?");
    $stmt->execute([$PAGE_CODE]);
    $cfg = [];
    foreach ($stmt->fetchAll() as $r) $cfg[$r['setting_code']] = $r['setting_value'];
    jsonResponse($cfg);
}

if ($type === 'config' && $method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach ($data as $code => $value) {
        $db->prepare("
            INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_value)
            VALUES ('page', ?, ?, ?)
            ON CONFLICT (setting_scope_code, setting_group_code, setting_code)
            DO UPDATE SET setting_value = EXCLUDED.setting_value
        ")->execute([$PAGE_CODE, $code, $value]);
    }
    jsonResponse(['saved' => true]);
}

// ── Sync ──
if ($type === 'sync' && ($method === 'POST' || $method === 'GET')) {
    $cfg = loadFeedConfig($db, $PAGE_CODE);
    if ($cfg['feed_key']) {
        // Determine sync script from feed site code
        $feedStmt = $db->prepare("SELECT feed_site_code FROM yy_feed WHERE feed_key = ?");
        $feedStmt->execute([$cfg['feed_key']]);
        $siteCode = strtolower($feedStmt->fetchColumn() ?: 'youtube');
        $syncScripts = ['youtube' => 'sync-youtube.php', 'rumble' => 'sync-rumble.php', 'facebook' => 'sync-facebook.php'];
        $syncFile = $syncScripts[$siteCode] ?? null;
        if ($syncFile && file_exists(__DIR__ . '/' . $syncFile)) {
            $_GET['feed_key'] = $cfg['feed_key'];
            if (!defined('SYNC_CALLED_FROM_PARENT')) define('SYNC_CALLED_FROM_PARENT', true);
            require __DIR__ . '/' . $syncFile;
            exit;
        }
        jsonResponse(['synced' => false, 'message' => 'No sync script for site: ' . $siteCode]);
    }
    jsonResponse(['synced' => false, 'message' => 'No feed configured for ' . $PAGE_CODE]);
}

errorResponse('Invalid request', 400);
