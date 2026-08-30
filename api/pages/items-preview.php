<?php
/**
 * TEST helper — live preview for the admin Items section editor.
 *
 *   POST { ...itemsConfig... }   (or { "config": {...} })
 *     → { items: [{feed_item_key, title, thumbnail, duration, posted,
 *                  sort, episode, category}], total, capped }
 *
 * Resolves the SAME filters + sort the public renderer uses (via
 * resolveItemsSection) so the editor can show exactly which feed items a
 * section will include, in order, as the admin tweaks the criteria. The
 * max_count cap is raised here so the preview surfaces the full matching
 * pool, not just the published slice. Augments each row with the manual
 * sort + episode and the item's category title(s) scoped to the section's
 * page(s) — categories are per-page in yy_feed_page_category.
 *
 * Auth required (admin tool).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../feed-helpers.php';
define('PAGE_RENDER_LIB', true);             // load page-render.php as a library only
require_once __DIR__ . '/page-render.php';   // resolveItemsSection()
requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') errorResponse('POST required', 405);

$db   = getDb();
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$cfg  = (isset($body['config']) && is_array($body['config'])) ? $body['config'] : $body;
if (!is_array($cfg)) $cfg = [];

// Show the full matching pool, independent of the section's display cap.
$PREVIEW_CAP = 500;
$cfg['max_count'] = $PREVIEW_CAP;
$cfg['_offset']   = 0;

$items = resolveItemsSection($db, $cfg);   // already filtered + ordered

// Collect the page scope so category titles resolve to the right page's set.
$pageKeys = [];
foreach (($cfg['pages'] ?? []) as $p) {
    if (!empty($p['page_key'])) $pageKeys[] = (int)$p['page_key'];
}

// One keyed query for the extra display columns, preserving render order.
$extra = [];
$keys  = array_map(static function ($i) { return (int)$i['feed_item_key']; }, $items);
if ($keys) {
    $place = implode(',', array_fill(0, count($keys), '?'));
    if ($pageKeys) {
        $pscope  = implode(',', array_fill(0, count($pageKeys), '?'));
        $catExpr = "(SELECT string_agg(DISTINCT pc.category_title, ', ')
                       FROM yy_feed_item_category fic
                       JOIN yy_feed_page_category pc ON pc.category_key = fic.category_key
                      WHERE fic.feed_item_key = fi.feed_item_key
                        AND pc.page_key IN ($pscope))";
        // $catExpr is in the SELECT (before WHERE), so its params come first.
        $params  = array_merge($pageKeys, $keys);
    } else {
        $catExpr = "NULL";
        $params  = $keys;
    }
    $st = $db->prepare("
        SELECT fi.feed_item_key, fi.feed_item_sort, fi.feed_item_episode, $catExpr AS categories
          FROM yy_feed_item fi
         WHERE fi.feed_item_key IN ($place)
    ");
    $st->execute($params);
    foreach ($st->fetchAll() as $r) $extra[(int)$r['feed_item_key']] = $r;
}

$out = array_map(static function ($i) use ($extra) {
    $k = (int)$i['feed_item_key'];
    $e = $extra[$k] ?? [];
    return [
        'feed_item_key' => $k,
        'title'         => $i['feed_item_title'] ?? '',
        'thumbnail'     => $i['feed_item_thumbnail'] ?? '',
        'duration'      => $i['feed_item_duration'] ?? '',
        'posted'        => $i['feed_item_posted_dtime'] ?? null,
        'sort'          => isset($e['feed_item_sort']) ? (int)$e['feed_item_sort'] : null,
        'episode'       => $e['feed_item_episode'] ?? null,
        'category'      => $e['categories'] ?? null,
    ];
}, $items);

jsonResponse([
    'items'  => $out,
    'total'  => count($out),
    'capped' => count($out) >= $PREVIEW_CAP,
]);
