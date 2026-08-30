<?php
/**
 * TEST helper — feed item search/lookup for the admin Items section editor.
 *
 *   GET ?q=<text>&limit=8     — title contains <text>; returns matches with thumb
 *   GET ?keys=1,2,3           — fetch by exact feed_item_keys (for chip rendering)
 *
 * Returns: { items: [{feed_item_key, title, thumbnail, feed_name, posted_dtime}] }
 *
 * Auth required (admin tool).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../feed-helpers.php';  // appendItemsSectionFilters + tag/title helpers
requireAuth();
$db = getDb();

$q     = trim($_GET['q']    ?? '');
$keys  = trim($_GET['keys'] ?? '');
$limit = (int)($_GET['limit'] ?? 8);
if ($limit < 1) $limit = 8;
if ($limit > 50) $limit = 50;

$where  = "i.feed_item_active_flag = TRUE";
$params = [];

if ($keys !== '') {
    // Chip-rendering lookup by exact keys — no filtering.
    $ids = array_values(array_filter(array_map('intval', explode(',', $keys))));
    if (!$ids) jsonResponse(['items' => []]);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $where .= " AND i.feed_item_key IN ($place)";
    array_push($params, ...$ids);
} elseif ($q !== '') {
    $where .= " AND COALESCE(i.feed_item_title_override, i.feed_item_title_import) ILIKE ?";
    $params[] = '%' . $q . '%';
    // Scope the typeahead to the SAME filters the Items section uses, so a
    // pinned title is always an item the section would actually display.
    // The editor passes the section config fields as query params; feed_keys
    // arrives comma-separated and pages as a JSON array.
    $cfg = [
        'content_type'     => $_GET['content_type']    ?? '',
        'orientation'      => $_GET['orientation']      ?? '',
        'include_hashtags' => $_GET['include_hashtags'] ?? '',
        'exclude_hashtags' => $_GET['exclude_hashtags'] ?? '',
        'title_include'    => $_GET['title_include']    ?? '',
        'title_exclude'    => $_GET['title_exclude']    ?? '',
        'duration_min_sec' => $_GET['duration_min_sec'] ?? '',
        'duration_max_sec' => $_GET['duration_max_sec'] ?? '',
        'age_min_h'        => $_GET['age_min_h']        ?? '',
        'age_max_h'        => $_GET['age_max_h']        ?? '',
    ];
    if (!empty($_GET['feed_keys'])) {
        $cfg['feed_keys'] = array_values(array_filter(array_map('intval', explode(',', $_GET['feed_keys']))));
    }
    if (!empty($_GET['pages'])) {
        $decoded = json_decode($_GET['pages'], true);
        if (is_array($decoded)) $cfg['pages'] = $decoded;
    }
    appendItemsSectionFilters($cfg, $where, $params);
} else {
    jsonResponse(['items' => []]);
}

$sql = "SELECT i.feed_item_key, i.feed_item_thumbnail, i.feed_item_embed_id,
               COALESCE(i.feed_item_title_override, i.feed_item_title_import) AS title,
               COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) AS posted_dtime,
               f.feed_name
        FROM yy_feed_item i
        JOIN yy_feed f ON f.feed_key = i.feed_key
        WHERE $where
        ORDER BY COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) DESC
        LIMIT " . (int)$limit;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
// Normalize relative thumbnail paths so they render from the web root
// (the admin page lives at /test/, where "u/blog/…" would resolve wrong).
foreach ($rows as &$row) {
    if (isset($row['feed_item_thumbnail'])) $row['feed_item_thumbnail'] = normalizeMediaUrl($row['feed_item_thumbnail']);
}
unset($row);
jsonResponse(['items' => $rows]);
