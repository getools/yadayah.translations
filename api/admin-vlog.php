<?php
/**
 * Admin API for Vlog categories and video management.
 * Uses yy_feed_page_category (page_key=1) for categories
 * and feed_item_category_key/feed_item_episode on yy_feed_item.
 *
 * GET ?type=categories — list categories with video counts
 * GET ?type=videos — list vlog videos
 * POST ?type=category — create category
 * PUT ?type=category&key=N — update category
 * DELETE ?type=category&key=N — delete category
 * PUT ?type=video&key=N — update video
 * DELETE ?type=video&key=N — soft-delete video
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed-helpers.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$type = $_GET['type'] ?? '';
$key = (int)($_GET['key'] ?? 0);

$PAGE_KEY = 1; // vlog

function loadVlogFeedConfig(PDO $db): array {
    $stmt = $db->query("
        SELECT f.feed_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude, fp.feed_page_filter_orientation
        FROM yy_feed_page fp
        JOIN yy_feed f ON f.feed_key = fp.feed_key
        JOIN yy_page p ON p.page_key = fp.page_key
        WHERE p.page_code = 'vlog'
        ORDER BY fp.feed_page_sort, fp.feed_page_key
        LIMIT 1
    ");
    $row = $stmt->fetch();
    return [
        'feed_key' => $row ? (int)$row['feed_key'] : 1,
        'include'  => $row ? array_filter(array_map('trim', explode(',', $row['feed_page_filter_include'] ?? ''))) : [],
        'exclude'  => $row ? array_filter(array_map('trim', explode(',', $row['feed_page_filter_exclude'] ?? ''))) : [],
        'orientation' => $row ? ($row['feed_page_filter_orientation'] ?? null) : null,
    ];
}

function slugify(string $text): string {
    return trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($text)))), '-');
}

// ── Categories ──
if ($type === 'categories' && $method === 'GET') {
    global $PAGE_KEY;
    $stmt = $db->prepare("
        SELECT c.category_key, c.category_title, c.category_subtitle, c.category_slug, c.category_sort,
               (SELECT COUNT(*) FROM yy_feed_item_category fic
                JOIN yy_feed_item fi ON fi.feed_item_key = fic.feed_item_key
                WHERE fic.category_key = c.category_key AND fi.feed_item_active_flag = TRUE) AS video_count
        FROM yy_feed_page_category c
        WHERE c.page_key = ? AND c.category_active_flag = TRUE
        ORDER BY c.category_sort, c.category_title
    ");
    $stmt->execute([$PAGE_KEY]);
    jsonResponse(['categories' => $stmt->fetchAll()]);
}

if ($type === 'category' && $method === 'POST') {
    global $PAGE_KEY;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($data['category_title'] ?? '');
    if (!$title) errorResponse('Category title is required');
    $subtitle = trim($data['category_subtitle'] ?? '');
    $sort = (int)($data['category_sort'] ?? 0);
    $slug = slugify($title);
    $stmt = $db->prepare("INSERT INTO yy_feed_page_category (page_key, category_title, category_subtitle, category_slug, category_sort) VALUES (?, ?, ?, ?, ?) ON CONFLICT (page_key, category_slug) DO NOTHING RETURNING category_key");
    $stmt->execute([$PAGE_KEY, $title, $subtitle ?: null, $slug, $sort]);
    $newKey = $stmt->fetchColumn();
    if (!$newKey) errorResponse('Category slug already exists');
    jsonResponse(['saved' => true, 'category_key' => $newKey]);
}

if ($type === 'category' && $method === 'PUT') {
    if (!$key) errorResponse('Category key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $fields = [];
    $params = [];
    if (isset($data['category_title'])) {
        $fields[] = 'category_title = ?';
        $params[] = trim($data['category_title']);
        if (!isset($data['category_slug'])) {
            $fields[] = 'category_slug = ?';
            $params[] = slugify($data['category_title']);
        }
    }
    if (isset($data['category_slug'])) {
        $fields[] = 'category_slug = ?';
        $params[] = slugify($data['category_slug']);
    }
    if (isset($data['category_subtitle'])) {
        $fields[] = 'category_subtitle = ?';
        $params[] = trim($data['category_subtitle']) ?: null;
    }
    if (isset($data['category_sort'])) {
        $fields[] = 'category_sort = ?';
        $params[] = (int)$data['category_sort'];
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

// ── Videos ──
if ($type === 'videos' && $method === 'GET') {
    global $PAGE_KEY;
    $cfg = loadVlogFeedConfig($db);

    $where = "feed_key = ? AND feed_item_active_flag = TRUE";
    $params = [$cfg['feed_key']];
    $rawInclude = implode(',', $cfg['include']);
    $rawExclude = implode(',', $cfg['exclude']);
    buildFeedPageFilters($where, $params, $rawInclude, $rawExclude, $cfg['orientation'] ?? null);
    $where = str_replace('feed_item_', 'fi.feed_item_', $where);
    $where = str_replace('fi.fi.', 'fi.', $where);
    $where = str_replace('feed_key', 'fi.feed_key', $where);

    // Read Vlog category from yy_feed_item_category (page-scoped multi-cat)
    $stmt = $db->prepare("
        SELECT fi.feed_item_key, fi.feed_item_external_id, COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS feed_item_title,
               fi.feed_item_thumbnail, fi.feed_item_url, fi.feed_item_sort, fi.feed_item_active_flag,
               fi.feed_item_episode, fi.feed_item_audio_file,
               fic.category_key, c.category_title, c.category_sort
        FROM yy_feed_item fi
        LEFT JOIN yy_feed_item_category fic ON fic.feed_item_key = fi.feed_item_key
            AND fic.category_key IN (SELECT category_key FROM yy_feed_page_category WHERE page_key = ?)
        LEFT JOIN yy_feed_page_category c ON fic.category_key = c.category_key
        WHERE $where
        ORDER BY COALESCE(c.category_sort, 999), c.category_title NULLS LAST, fi.feed_item_episode NULLS LAST, COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) DESC NULLS LAST
    ");
    $stmt->execute(array_merge([$PAGE_KEY], $params));
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
            'episode'         => $r['feed_item_episode'] ? (int)$r['feed_item_episode'] : null,
            'active_flag'     => $r['feed_item_active_flag'] === true || $r['feed_item_active_flag'] === 't',
            'category_key'    => $r['category_key'] ? (int)$r['category_key'] : null,
            'category_title'  => $r['category_title'] ?? null,
            'audio_file'      => $r['feed_item_audio_file'] ?? null,
        ];
    }

    jsonResponse(['videos' => $videos]);
}

if ($type === 'video' && $method === 'PUT') {
    if (!$key) errorResponse('Video key required');
    global $PAGE_KEY;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $fields = [];
    $params = [];

    if (isset($data['title'])) {
        $fields[] = 'feed_item_title_override = ?';
        $params[] = trim($data['title']);
        stampManualField($db, $key, 'title');       // most-recent-wins vs hashtag
    }
    if (isset($data['episode'])) {
        $fields[] = 'feed_item_episode = ?';
        $params[] = (int)$data['episode'];
        stampManualField($db, $key, 'episode');
    }
    if (isset($data['sort'])) {
        $fields[] = 'feed_item_sort = ?';
        $params[] = (int)$data['sort'];
        stampManualField($db, $key, 'sort');
    }
    if (isset($data['active_flag'])) {
        $fields[] = 'feed_item_active_flag = ?';
        $params[] = (bool)$data['active_flag'] ? 'true' : 'false';
    }

    // Vlog category goes to yy_feed_item_category (page-scoped to Vlog page=1)
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
    jsonResponse(['saved' => true]);
}

// Upload MP3 for a feed item
if ($type === 'audio' && $method === 'POST' && $key) {
    $file = $_FILES['audio'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) errorResponse('No file uploaded');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'mp3') errorResponse('Only MP3 files are allowed');

    $filename = 'audio_' . $key . '_' . time() . '.mp3';
    $destDir = dirname(__DIR__) . '/public/u/audio';
    if (is_dir('/var/www/html/u/audio')) $destDir = '/var/www/html/u/audio';
    $dest = $destDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) errorResponse('Failed to save file');

    $path = 'u/audio/' . $filename;
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ? WHERE feed_item_key = ?")->execute([$path, $key]);
    jsonResponse(['saved' => true, 'audio_file' => $path]);
}

// Remove MP3 from a feed item
if ($type === 'audio' && $method === 'DELETE' && $key) {
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = NULL WHERE feed_item_key = ?")->execute([$key]);
    jsonResponse(['removed' => true]);
}

if ($type === 'video' && $method === 'DELETE') {
    if (!$key) errorResponse('Video key required');
    $db->prepare("UPDATE yy_feed_item SET feed_item_active_flag = FALSE, feed_item_revision_dtime = NOW() WHERE feed_item_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Invalid request', 400);
