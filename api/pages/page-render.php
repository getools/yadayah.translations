<?php
/**
 * TEST endpoint — public renderer for a multi-section page.
 *
 *   GET ?code=foo
 *   GET ?key=N
 *
 * Returns:
 *   { page: {...}, sections: [
 *       {type, title, config, items?:[...]}, ...   // items present only for type=items
 *   ]}
 *
 * No auth required (public read), but only active pages and sections are returned.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../feed-helpers.php';

// This file is both an endpoint AND a function library (resolveItemsSection,
// below). Other endpoints (e.g. items-preview.php) require it for the
// functions; define PAGE_RENDER_LIB before requiring to skip the endpoint
// body so the GET-only method guard and request handling don't fire.
if (!defined('PAGE_RENDER_LIB')) {

if ($_SERVER['REQUEST_METHOD'] !== 'GET') errorResponse('Method not allowed', 405);

$db = getDb();

// ── Load-More paginated request ────────────────────────────────────────
// When the public renderer's Load More button is clicked, it calls this
// endpoint with ?section_key=N&offset=M&limit=K to fetch the *next* batch
// of items for one section. Response is just {items:[...]}; the caller
// appends them to the existing grid. No auth required (same as the
// non-paginated path — only active sections are returned).
$sectionKey = isset($_GET['section_key']) ? (int)$_GET['section_key'] : 0;
$paginatedLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
if ($sectionKey > 0 && $paginatedLimit > 0) {
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    if ($paginatedLimit > 200) $paginatedLimit = 200;
    $st = $db->prepare("SELECT section_config FROM yy_section WHERE section_key = ? AND section_type = 'items' AND section_active_flag = TRUE");
    $st->execute([$sectionKey]);
    $cfgRow = $st->fetchColumn();
    if ($cfgRow === false) errorResponse('Section not found', 404);
    $cfg = is_string($cfgRow) ? (json_decode($cfgRow, true) ?: []) : ($cfgRow ?: []);
    $cfg['max_count'] = $paginatedLimit;
    $cfg['_offset']   = $offset;
    // Category filter (page-code model): restrict to items filed under this
    // yy_category via yy_section_item. 0/absent = no filter (All).
    if (isset($_GET['category_key'])) $cfg['_section_category_key'] = (int)$_GET['category_key'];
    jsonResponse(['items' => resolveItemsSection($db, $cfg, $sectionKey)]);
}

if (!empty($_GET['key'])) {
    $stmt = $db->prepare("SELECT * FROM yy_page WHERE page_key = ? AND page_active_flag = TRUE");
    $stmt->execute([(int)$_GET['key']]);
} elseif (!empty($_GET['code'])) {
    // Case-insensitive, matching public/page.php. yy_page stores craig_winn
    // where the old builder table stored Craig_Winn, and the canonical URL is
    // /Craig_Winn - an exact match would 404 the sections fetch for any caller
    // that uses the mixed-case form.
    $stmt = $db->prepare("SELECT * FROM yy_page WHERE lower(page_code) = lower(?) AND page_active_flag = TRUE");
    $stmt->execute([trim($_GET['code'])]);
} else {
    errorResponse('Missing code or key');
}
$page = $stmt->fetch();
if (!$page) errorResponse('Page not found', 404);

$sec = $db->prepare("SELECT * FROM yy_section WHERE page_key = ? AND section_active_flag = TRUE ORDER BY section_sort, section_key");
$sec->execute([$page['page_key']]);

$out = [];
foreach ($sec->fetchAll() as $s) {
    $cfg = $s['section_config'];
    $cfg = is_string($cfg) ? (json_decode($cfg, true) ?: []) : ($cfg ?: []);
    $section = [
        'key'        => (int)$s['section_key'],
        'parent_key' => $s['section_parent_key'] !== null ? (int)$s['section_parent_key'] : null,
        'sort'       => (int)$s['section_sort'],
        'type'       => $s['section_type'],
        'title'      => $s['section_title'],
        'config'     => $cfg,
    ];
    if ($section['type'] === 'items') {
        // config.category_display picks how this section presents its
        // categories. GROUPED IS THE DEFAULT across Pages-New — one heading per
        // category with its items beneath, the production /vlog layout. 'chips'
        // opts back into the filter-chip row; 'none' suppresses category UI.
        // ('' and the legacy explicit 'grouped' both mean grouped.)
        $catDisplay = $cfg['category_display'] ?? '';
        $wantGrouped = ($catDisplay === '' || $catDisplay === 'grouped');
        $groups = null;
        if ($wantGrouped) {
            $g = resolveItemsSectionGrouped($db, $cfg, (int)$s['section_key']);
            // Only present groups when the section has REAL categories. With
            // none, every item falls into the single "Uncategorized" bucket —
            // grouping would add a meaningless heading AND, because grouped mode
            // renders every group in full, dump the entire feed (sections here
            // hold up to ~1300 items) in place of a max_count page + Load More.
            // So an uncategorized section falls through to the flat path and
            // behaves exactly as it did before.
            foreach ($g as $grp) {
                if ((int)$grp['category']['key'] > 0) { $groups = $g; break; }
            }
        }
        if ($groups !== null) {
            $section['groups'] = $groups;
            // Grouped renders every group in full, so the flat item list and the
            // chip row are both unused — omit them rather than pay for a second
            // query.
            $section['items'] = [];
        } else {
            $section['items'] = resolveItemsSection($db, $cfg, (int)$s['section_key']);
            // Category chips (page-code model): the section's yy_category set,
            // limited to categories that actually have linked items. Only built
            // for the opt-in 'chips' mode now.
            $section['categories'] = ($catDisplay === 'chips')
                ? sectionItemCategories($db, (int)$s['section_key'])
                : [];
        }
    }
    $out[] = $section;
}

jsonResponse(['page' => $page, 'sections' => $out]);

} // end endpoint body (skipped when included as a library)


/**
 * Translate an Items-section config object into a list of feed_item rows.
 *
 * Config shape (all fields optional unless noted):
 *   feed_keys:        [int]            — restrict to these feeds
 *   feed_item_keys:   [int]            — explicit pinned items (bypasses other filters when present)
 *   age_min_h: int total hours — items must be at least this old
 *   age_max_h: int total hours — items must be at most this old
 *   include_hashtags: 'tag1,tag2'      — passed through buildFeedPageFilters
 *   exclude_hashtags: 'tag1,tag2'
 *   duration_min_sec: int
 *   duration_max_sec: int
 *   content_type:     'video'|'image'|'audio'|...
 *   page_key:         int              — items linked to this page via yy_feed_item_page
 *   category_key:     int              — items in this yy_feed_page_category
 *   title_include:    'foo,bar'        — wildcard convention
 *   title_exclude:    'baz'
 *   sort:             'posted'|'title'|'orientation'|'page'|'category'|'random'
 *   sort_dir:         'asc'|'desc'    — ignored when sort='random'
 *   max_count:        int (default 24, capped at 200)
 */

/**
 * The category chips for a Pages-New Items section: its yy_category rows that
 * currently have at least one linked (active, non-restricted) feed item via
 * yy_section_item. Returned newest-config-order (category_sort, title) with a
 * live item count. Empty when nothing is filed yet — the UI then shows no
 * filter. Keyspace note: these are SECTION-scoped yy_category (keyed by
 * section_key), distinct from page-scoped yy_feed_page_category.
 */
function sectionItemCategories(PDO $db, int $sectionKey): array {
    if ($sectionKey <= 0) return [];
    $sql = "SELECT c.category_key, c.category_title, c.category_slug, COUNT(i.feed_item_key) AS item_count
              FROM yy_category c
              JOIN yy_section_item si ON si.category_key = c.category_key AND si.section_key = c.section_key
              JOIN yy_feed_item i ON i.feed_item_key = si.feed_item_key
                   AND i.feed_item_active_flag = TRUE AND i.feed_item_restricted_flag = FALSE
             WHERE c.section_key = ? AND c.category_active_flag = TRUE
             GROUP BY c.category_key, c.category_title, c.category_slug, c.category_sort
            HAVING COUNT(i.feed_item_key) > 0
             ORDER BY c.category_sort, c.category_title";
    $st = $db->prepare($sql);
    $st->execute([$sectionKey]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['key' => (int)$r['category_key'], 'title' => $r['category_title'],
                  'slug' => $r['category_slug'], 'count' => (int)$r['item_count']];
    }
    return $out;
}

/**
 * Build the ORDER BY clause for an Items-section query from its config.
 *
 * Accepts either cfg.sorts: [{field, dir}, ...] (preferred) or the legacy
 * single-field cfg.sort + cfg.sort_dir. Every emitted fragment is built from a
 * fixed switch — no caller string reaches the SQL — so the result is safe to
 * interpolate. Shared by resolveItemsSection() and resolveItemsSectionGrouped()
 * so flat and grouped renders order items identically.
 *
 * ONLY the configured sorts govern. The per-section manual order
 * (section_item_sort) used to be prepended implicitly, so it silently outranked
 * whatever the admin picked; it is now applied only when they add the "Sort #"
 * (section_sort) field to the list, like any other sort field. Not listed = not
 * applied.
 */
function buildItemsOrderBy(array $cfg, int $sectionKey = 0): string {
    $sorts = [];
    if (!empty($cfg['sorts']) && is_array($cfg['sorts'])) {
        foreach ($cfg['sorts'] as $entry) {
            if (!empty($entry['field'])) {
                $sorts[] = ['field' => $entry['field'], 'dir' => $entry['dir'] ?? 'desc'];
            }
        }
    } elseif (!empty($cfg['sort'])) {
        $sorts[] = ['field' => $cfg['sort'], 'dir' => $cfg['sort_dir'] ?? 'desc'];
    }
    if (!$sorts) $sorts[] = ['field' => 'posted', 'dir' => 'desc'];

    $orderParts = [];
    foreach ($sorts as $srt) {
        $dir = strtolower($srt['dir']) === 'asc' ? 'ASC' : 'DESC';
        switch ($srt['field']) {
            case 'title':
                $orderParts[] = "COALESCE(i.feed_item_title_override, i.feed_item_title_import) $dir";
                break;
            case 'orientation':
                $orderParts[] = "i.feed_item_orientation $dir NULLS LAST";
                break;
            case 'page':
                $orderParts[] = "(SELECT MIN(page_key) FROM yy_feed_item_page WHERE feed_item_key = i.feed_item_key) $dir NULLS LAST";
                break;
            case 'category':
                $orderParts[] = "(SELECT MIN(category_key) FROM yy_feed_item_category WHERE feed_item_key = i.feed_item_key) $dir NULLS LAST";
                break;
            case 'duration':
                $orderParts[] = "i.feed_item_duration_seconds $dir NULLS LAST";
                break;
            case 'episode':
                // Episode is free text ('053', 'S2E5'); order by its digits as
                // an int so 9 sorts before 10. Mirrors the production /vlog
                // grouped ordering (rumble-videos.php), which sorts each
                // category by episode number ascending.
                $orderParts[] = "(NULLIF(regexp_replace(i.feed_item_episode, '[^0-9]', '', 'g'), ''))::int $dir NULLS LAST";
                break;
            case 'sort':
                // Manual ordering field (feed_item_sort). Mirrors the
                // production music page's primary ordering.
                $orderParts[] = "i.feed_item_sort $dir NULLS LAST";
                break;
            case 'section_sort':
                // "Sort #" — this section's own per-item order from the
                // Items+Section table (yy_section_item.section_item_sort), the
                // value the editor's Sort column writes. Only available when a
                // section_key supplied the `si` join; otherwise skip the part
                // rather than emit SQL referencing a missing alias.
                if ($sectionKey > 0) $orderParts[] = "si.section_item_sort $dir NULLS LAST";
                break;
            case 'random':
                $orderParts[] = "RANDOM()";
                break;
            case 'posted':
            default:
                $orderParts[] = "COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) $dir";
                break;
        }
    }
    return implode(', ', $orderParts);
}

/**
 * Grouped (category-sectioned) variant of resolveItemsSection — the display
 * the production /vlog page uses: one heading per category, its items beneath.
 *
 * Applies the SAME include/exclude filters as the flat render, then partitions
 * the result by this section's yy_section_item.category_key. yy_section_item's
 * PK is (section_key, feed_item_key), so an item belongs to at most one
 * category per section and appears in exactly one group.
 *
 * Returns: [ {category:{key,title,subtitle,slug,count}, items:[...]}, ... ]
 * ordered by category_sort then title, with any uncategorized group last
 * (key 0) — matching /vlog. Groups render in full (no Load More), so the query
 * is capped at GROUPED_ITEM_CAP rows as a runaway guard rather than by
 * cfg.max_count, which governs the flat first-page size.
 */
function resolveItemsSectionGrouped(PDO $db, array $cfg, int $sectionKey): array {
    if ($sectionKey <= 0) return [];
    $cap = 2000;

    $where  = "i.feed_item_active_flag = TRUE AND i.feed_item_restricted_flag = FALSE";
    $params = [];
    if (!empty($cfg['feed_item_keys']) && is_array($cfg['feed_item_keys'])) {
        $ids = array_values(array_filter(array_map('intval', $cfg['feed_item_keys'])));
        if ($ids) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND i.feed_item_key IN ($place)";
            array_push($params, ...$ids);
        }
    }
    appendItemsSectionFilters($cfg, $where, $params);

    // Order within each group by the section's configured sorts only.
    $order = buildItemsOrderBy($cfg, $sectionKey);

    $sql = "SELECT i.feed_item_key, i.feed_key, i.feed_item_external_id, i.feed_item_embed_id,
                   COALESCE(i.feed_item_title_override, i.feed_item_title_import) AS feed_item_title,
                   i.feed_item_url, i.feed_item_thumbnail, i.feed_item_duration, i.feed_item_duration_seconds,
                   i.feed_item_orientation, i.feed_item_type, i.feed_item_tags, i.feed_item_audio_file,
                   i.feed_item_episode,
                   COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) AS feed_item_posted_dtime,
                   si.category_key
            FROM yy_feed_item i
            LEFT JOIN yy_section_item si ON si.section_key = " . (int)$sectionKey . "
                 AND si.feed_item_key = i.feed_item_key
            WHERE $where
            ORDER BY $order
            LIMIT " . (int)$cap;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $buckets = [];
    foreach ($rows as $r) {
        if (isset($r['feed_item_thumbnail'])) $r['feed_item_thumbnail'] = normalizeMediaUrl($r['feed_item_thumbnail']);
        if (isset($r['feed_item_audio_file'])) $r['feed_item_audio_file'] = normalizeMediaUrl($r['feed_item_audio_file']);
        $ck = (int)($r['category_key'] ?? 0);
        unset($r['category_key']);
        $buckets[$ck][] = $r;
    }
    if (!$buckets) return [];

    // Category metadata, in the order the editor's category list defines.
    $cs = $db->prepare("SELECT category_key, category_title, category_subtitle, category_slug
                          FROM yy_category
                         WHERE section_key = ? AND category_active_flag = TRUE
                         ORDER BY category_sort, category_title");
    $cs->execute([$sectionKey]);

    $groups = [];
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $ck = (int)$c['category_key'];
        if (empty($buckets[$ck])) continue;   // hide categories with nothing to show
        $groups[] = [
            'category' => ['key' => $ck, 'title' => $c['category_title'],
                           'subtitle' => $c['category_subtitle'], 'slug' => $c['category_slug'],
                           'count' => count($buckets[$ck])],
            'items'    => $buckets[$ck],
        ];
        unset($buckets[$ck]);
    }
    // Anything left over is unfiled (category_key NULL/0, or filed under a
    // category that has since been deactivated). Shown last, like /vlog.
    $leftover = [];
    foreach ($buckets as $rows2) { foreach ($rows2 as $r2) $leftover[] = $r2; }
    if ($leftover) {
        $groups[] = [
            'category' => ['key' => 0, 'title' => 'Uncategorized', 'subtitle' => null,
                           'slug' => 'uncategorized', 'count' => count($leftover)],
            'items'    => $leftover,
        ];
    }
    return $groups;
}

function resolveItemsSection(PDO $db, array $cfg, int $sectionKey = 0): array {
    // Exclude restricted items everywhere (private/deleted on YouTube set
    // feed_item_restricted_flag during sync). Treated like active_flag — a
    // hard gate on every section query, pinned items included.
    $where  = "i.feed_item_active_flag = TRUE AND i.feed_item_restricted_flag = FALSE";
    $params = [];
    $joins  = "";
    // Per-section ordering source: the Items+Section table (yy_section_item).
    // When a section_key is supplied, LEFT JOIN it so the ORDER BY can lead
    // with this section's own section_item_sort — the same value the editor's
    // Matching-items list edits. Falls back to the configured sorts for items
    // not yet in the materialized pool (NULLS LAST).
    $secJoin = '';
    if ($sectionKey > 0) {
        $secJoin = " LEFT JOIN yy_section_item si ON si.section_key = " . (int)$sectionKey
                 . " AND si.feed_item_key = i.feed_item_key";
    }

    if (!empty($cfg['feed_item_keys']) && is_array($cfg['feed_item_keys'])) {
        $ids = array_values(array_filter(array_map('intval', $cfg['feed_item_keys'])));
        if ($ids) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND i.feed_item_key IN ($place)";
            array_push($params, ...$ids);
        }
    }
    // All non-pinned filters (feeds, age, duration, content_type,
    // orientation, pages/categories, hashtags, title include/exclude) are
    // built by the shared helper in feed-helpers.php — the SAME helper the
    // admin "Selected Titles" typeahead uses, so the pinned-item search
    // results always match what this section would render.
    appendItemsSectionFilters($cfg, $where, $params);

    // Category filter (page-code model): restrict to items filed under one of
    // this section's yy_category rows via yy_section_item. Both ids are cast to
    // int, so inlining them is injection-safe.
    $catFilter = (int)($cfg['_section_category_key'] ?? 0);
    if ($sectionKey > 0 && $catFilter > 0) {
        $where .= " AND EXISTS (SELECT 1 FROM yy_section_item sif WHERE sif.section_key = "
                . (int)$sectionKey . " AND sif.feed_item_key = i.feed_item_key AND sif.category_key = "
                . $catFilter . ")";
    }

    $order = buildItemsOrderBy($cfg, $sectionKey);

    $maxCount = (int)($cfg['max_count'] ?? 24);
    if ($maxCount < 1) $maxCount = 24;
    if ($maxCount > 200) $maxCount = 200;
    // _offset is set by the Load More paginated path above to fetch the
    // next batch. Not exposed as a UI field — it's a transient runtime hint.
    $offsetVal = max(0, (int)($cfg['_offset'] ?? 0));

    $sql = "SELECT i.feed_item_key, i.feed_key, i.feed_item_external_id, i.feed_item_embed_id,
                   COALESCE(i.feed_item_title_override, i.feed_item_title_import) AS feed_item_title,
                   i.feed_item_url, i.feed_item_thumbnail, i.feed_item_duration, i.feed_item_duration_seconds,
                   i.feed_item_orientation, i.feed_item_type, i.feed_item_tags, i.feed_item_audio_file,
                   COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) AS feed_item_posted_dtime
            FROM yy_feed_item i
            $joins
            $secJoin
            WHERE $where
            ORDER BY $order
            LIMIT " . (int)$maxCount . " OFFSET " . (int)$offsetVal;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // Normalize relative thumbnail paths (e.g. "u/blog/…") to absolute so
    // they render from the web root, not relative to /test/page.php.
    foreach ($rows as &$r) {
        if (isset($r['feed_item_thumbnail'])) $r['feed_item_thumbnail'] = normalizeMediaUrl($r['feed_item_thumbnail']);
        // Same normalization for the attached MP3 (u/audio/… → /u/audio/…) so
        // the client can link straight to it — mirrors the vlog audio icon.
        if (isset($r['feed_item_audio_file'])) $r['feed_item_audio_file'] = normalizeMediaUrl($r['feed_item_audio_file']);
    }
    unset($r);
    return $rows;
}
