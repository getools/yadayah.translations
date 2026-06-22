<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();

$method = $_SERVER['REQUEST_METHOD'];

// GET all feed items (paginated, filterable)
if ($method === 'GET' && isset($_GET['items'])) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(200, max(10, (int)($_GET['limit'] ?? 100)));
    $offset = ($page - 1) * $limit;
    $feedKey = (int)($_GET['feed_key'] ?? 0);
    $search = trim($_GET['search'] ?? '');

    $where = ['fi.feed_item_active_flag IS NOT NULL'];
    $params = [];
    if ($feedKey) { $where[] = 'fi.feed_key = ?'; $params[] = $feedKey; }
    if ($search) { $where[] = '(COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) ILIKE ? OR fi.feed_item_tags ILIKE ?)'; $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%'; }
    $titleFilter = trim($_GET['title'] ?? '');
    $tagsFilter = trim($_GET['tags'] ?? '');
    $catKey = (int)($_GET['category_key'] ?? 0);
    if ($titleFilter) { $where[] = 'COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) ILIKE ?'; $params[] = '%'.$titleFilter.'%'; }
    $keyFilter = trim($_GET['feed_item_key'] ?? '');
    if ($keyFilter !== '') {
        if (ctype_digit($keyFilter)) { $where[] = 'fi.feed_item_key = ?'; $params[] = (int)$keyFilter; }
        else { $where[] = 'CAST(fi.feed_item_key AS TEXT) LIKE ?'; $params[] = $keyFilter . '%'; }
    }
    if ($tagsFilter) { $where[] = 'fi.feed_item_tags ILIKE ?'; $params[] = '%'.$tagsFilter.'%'; }
    $episodeFilter = trim($_GET['episode'] ?? '');
    if ($episodeFilter) { $where[] = 'fi.feed_item_episode ILIKE ?'; $params[] = '%'.$episodeFilter.'%'; }
    if ($catKey) {
        $where[] = 'fi.feed_item_key IN (SELECT feed_item_key FROM yy_feed_item_category WHERE category_key = ?)';
        $params[] = $catKey;
    }
    $pageKey = (int)($_GET['page_key'] ?? 0);
    if ($pageKey) {
        // Use yy_feed_item_page join table — this is the source of truth for which items
        // belong to which page (populated by feed-item-pages.php based on feed_page filters).
        $where[] = 'fi.feed_item_key IN (SELECT feed_item_key FROM yy_feed_item_page WHERE page_key = ?)';
        $params[] = $pageKey;
    }
    // Status filter — multi-checkbox of: active, restricted, inactive
    //   active     = active_flag = TRUE  AND restricted_flag IS NOT TRUE
    //   restricted = restricted_flag = TRUE
    //   inactive   = active_flag = FALSE OR restricted_flag = TRUE
    $statusRaw = trim($_GET['status'] ?? '');
    if ($statusRaw !== '') {
        $picks = array_filter(array_map('trim', explode(',', $statusRaw)));
        $ors = [];
        foreach ($picks as $s) {
            if ($s === 'active')     $ors[] = '(fi.feed_item_active_flag = TRUE AND COALESCE(fi.feed_item_restricted_flag, FALSE) = FALSE)';
            elseif ($s === 'restricted') $ors[] = 'fi.feed_item_restricted_flag = TRUE';
            elseif ($s === 'inactive')   $ors[] = '(fi.feed_item_active_flag = FALSE OR fi.feed_item_restricted_flag = TRUE)';
        }
        if ($ors) $where[] = '(' . implode(' OR ', $ors) . ')';
    }
    $hasMp3 = trim($_GET['has_mp3'] ?? '');
    if ($hasMp3 === 'yes') { $where[] = "fi.feed_item_audio_file IS NOT NULL AND fi.feed_item_audio_file != ''"; }
    elseif ($hasMp3 === 'no') { $where[] = "(fi.feed_item_audio_file IS NULL OR fi.feed_item_audio_file = '')"; }
    $whereStr = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item fi WHERE $whereStr");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sort = trim($_GET['sort'] ?? 'publish_dtime');
    $dir = strtoupper(trim($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $sortMap = [
        'publish_dtime' => 'COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime)',
        'feed_item_key' => 'fi.feed_item_key',
        'feed_item_title' => 'COALESCE(fi.feed_item_title_override, fi.feed_item_title_import)',
        'feed_item_tags' => 'fi.feed_item_tags',
        'feed_item_episode' => 'fi.feed_item_episode',
        'feed_item_active_flag' => 'fi.feed_item_active_flag',
        'feed_name' => 'f.feed_name',
        'status' => "CASE
                       WHEN fi.feed_item_restricted_flag = TRUE THEN 2
                       WHEN fi.feed_item_active_flag = FALSE THEN 1
                       ELSE 0 END",
    ];
    $orderCol = $sortMap[$sort] ?? $sortMap['publish_dtime'];

    $stmt = $db->prepare("
        SELECT fi.*, COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS feed_item_title, f.feed_name,
               (SELECT string_agg(DISTINCT p.page_code, ', ' ORDER BY p.page_code)
                FROM yy_feed_item_page fip JOIN yy_page p ON fip.page_key = p.page_key
                WHERE fip.feed_item_key = fi.feed_item_key
               ) AS page_codes,
               (SELECT json_agg(json_build_object('page_key', p.page_key, 'page_code', p.page_code, 'page_title', p.page_title) ORDER BY p.page_title)
                FROM (SELECT DISTINCT ON (p.page_key) p.page_key, p.page_code, p.page_title
                      FROM yy_feed_item_page fip JOIN yy_page p ON fip.page_key = p.page_key
                      WHERE fip.feed_item_key = fi.feed_item_key
                ) p) AS page_list,
               (SELECT json_agg(json_build_object('category_key', cc.category_key, 'category_title', cc.category_title, 'category_slug', cc.category_slug, 'episode', fic.feed_item_category_episode, 'page_title', pp.page_title) ORDER BY pp.page_title, cc.category_sort, cc.category_title)
                FROM yy_feed_item_category fic
                JOIN yy_feed_page_category cc ON fic.category_key = cc.category_key
                JOIN yy_page pp ON cc.page_key = pp.page_key
                WHERE fic.feed_item_key = fi.feed_item_key) AS categories_list,
               -- Most recent validation status + reviewer's resume bookmark.
               -- Two correlated subqueries to keep the change small; same row.
               (SELECT v.validation_status FROM yy_feed_item_transcript_validation v
                WHERE v.feed_item_key = fi.feed_item_key
                ORDER BY v.validation_dtime DESC LIMIT 1) AS transcript_validation_status,
               (SELECT v.validation_bookmark_seconds FROM yy_feed_item_transcript_validation v
                WHERE v.feed_item_key = fi.feed_item_key
                ORDER BY v.validation_dtime DESC LIMIT 1) AS transcript_bookmark_seconds,
               -- Whether ANY transcript rows exist (distinguishes never-transcribed from in-review)
               EXISTS (SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key) AS has_transcript
        FROM yy_feed_item fi
        JOIN yy_feed f ON fi.feed_key = f.feed_key
        WHERE $whereStr
        ORDER BY $orderCol $dir NULLS LAST
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));

    // Category hierarchy for filter dropdown
    $catStmt = $db->query("
        SELECT c.category_key, c.category_title, c.category_subtitle, c.category_slug, c.category_sort, c.page_key, p.page_code, p.page_title
        FROM yy_feed_page_category c
        JOIN yy_page p ON c.page_key = p.page_key
        WHERE c.category_active_flag = TRUE
        ORDER BY p.page_title, c.category_sort, c.category_title
    ");
    $pagesStmt = $db->query("
        SELECT p.page_key, p.page_code, p.page_title
        FROM yy_page p
        WHERE p.page_active_flag = TRUE AND p.page_key IN (SELECT DISTINCT page_key FROM yy_feed_page)
        ORDER BY p.page_title
    ");

    jsonResponse([
        'items' => $stmt->fetchAll(),
        'page' => $page,
        'total' => $total,
        'total_pages' => max(1, (int)ceil($total / $limit)),
        'categories' => $catStmt->fetchAll(),
        'pages' => $pagesStmt->fetchAll(),
    ]);
}

// GET all page feed mappings
if ($method === 'GET' && !empty($_GET['all_page_feeds'])) {
    $stmt = $db->query("
        SELECT pf.*, p.page_code, p.page_title
        FROM yy_feed_page pf
        JOIN yy_page p ON p.page_key = pf.page_key
        ORDER BY p.page_code, pf.feed_page_sort
    ");
    jsonResponse(['page_feeds' => $stmt->fetchAll()]);
}

// GET page feeds for a specific feed (must be before main GET handler)
if ($method === 'GET' && !empty($_GET['page_feeds'])) {
    $feedKey = (int)($_GET['feed_key'] ?? 0);
    $pages = $db->query("SELECT page_key, page_code, page_title FROM yy_page ORDER BY page_code")->fetchAll();
    if (!$feedKey) {
        jsonResponse(['page_feeds' => [], 'pages' => $pages]);
    }
    $stmt = $db->prepare("
        SELECT pf.*, p.page_code, p.page_title
        FROM yy_feed_page pf
        JOIN yy_page p ON p.page_key = pf.page_key
        WHERE pf.feed_key = ?
        ORDER BY pf.feed_page_sort
    ");
    $stmt->execute([$feedKey]);
    jsonResponse(['page_feeds' => $stmt->fetchAll(), 'pages' => $pages]);
}

// GET latest sync status for a feed — polled by the UI while an async
// (background) sync runs. Returns the most recent yy_feed_sync row for the
// feed plus elapsed seconds. Counts are only populated when the run finishes
// (sync-youtube.php writes them in one UPDATE at the end), so a 'running' row
// carries elapsed time only.
if ($method === 'GET' && isset($_GET['sync_status'])) {
    $feedKey = (int)($_GET['feed_key'] ?? 0);
    if (!$feedKey) errorResponse('feed_key required');
    $stmt = $db->prepare("
        SELECT feed_sync_key, feed_sync_status, feed_sync_items_found,
               feed_sync_items_inserted, feed_sync_items_updated, feed_sync_error,
               feed_sync_start_dtime, feed_sync_end_dtime,
               EXTRACT(EPOCH FROM (COALESCE(feed_sync_end_dtime, NOW()) - feed_sync_start_dtime))::int AS elapsed_secs
        FROM yy_feed_sync
        WHERE feed_key = ?
        ORDER BY feed_sync_key DESC
        LIMIT 1
    ");
    $stmt->execute([$feedKey]);
    jsonResponse(['sync' => $stmt->fetch() ?: null]);
}

// GET - list all feeds with their schedules
if ($method === 'GET') {
    $feedKey = $_GET['feed_key'] ?? null;

    if ($feedKey) {
        // Single feed with schedules
        $stmt = $db->prepare("SELECT * FROM yy_feed WHERE feed_key = ?");
        $stmt->execute([$feedKey]);
        $feed = $stmt->fetch();
        if (!$feed) errorResponse('Feed not found', 404);

        $schedStmt = $db->prepare("SELECT * FROM yy_feed_schedule WHERE feed_key = ? ORDER BY schedule_day_of_week, schedule_time");
        $schedStmt->execute([$feedKey]);
        $feed['schedules'] = $schedStmt->fetchAll();

        // Include feed_page rows
        $fpStmt = $db->prepare("
            SELECT fp.*, p.page_code, p.page_title
            FROM yy_feed_page fp
            JOIN yy_page p ON p.page_key = fp.page_key
            WHERE fp.feed_key = ?
            ORDER BY fp.feed_page_sort
        ");
        $fpStmt->execute([$feedKey]);
        $feed['feed_pages'] = $fpStmt->fetchAll();

        jsonResponse($feed);
    }

    // All feeds with first page's filters
    $stmt = $db->query("
        SELECT f.*,
            (SELECT count(*) FROM yy_feed_schedule s WHERE s.feed_key = f.feed_key AND s.schedule_active_flag = true) as schedule_count,
            (SELECT max(s.schedule_last_run) FROM yy_feed_schedule s WHERE s.feed_key = f.feed_key) as last_run,
            (SELECT count(*) FROM yy_feed_page fp WHERE fp.feed_key = f.feed_key) as page_count,
            (SELECT fp.feed_page_filter_include FROM yy_feed_page fp WHERE fp.feed_key = f.feed_key ORDER BY fp.feed_page_sort LIMIT 1) as feed_page_filter_include,
            (SELECT fp.feed_page_filter_exclude FROM yy_feed_page fp WHERE fp.feed_key = f.feed_key ORDER BY fp.feed_page_sort LIMIT 1) as feed_page_filter_exclude
        FROM yy_feed f
        ORDER BY f.feed_name
    ");
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        // Per-feed YouTube captions OAuth state. Expose as boolean only —
        // the actual refresh token never leaves the server. Channel id +
        // title are safe to send (used for the row badge).
        $r["feed_yt_caption_connected"] = !empty($r["feed_yt_caption_refresh_token"]);
        unset($r["feed_yt_caption_refresh_token"]);
    }
    unset($r);
    jsonResponse($rows);
}

// POST - create or update
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) errorResponse('Invalid JSON');

    // Page feed mapping create/update
    if (!empty($data['feed_page_action'])) {
        $action = $data['feed_page_action'];

        if ($action === 'create') {
            $db->prepare("INSERT INTO yy_feed_page (page_key, feed_key, feed_page_filter_include, feed_page_filter_exclude, feed_page_listing_type, feed_page_filter_orientation, feed_page_sort) VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   (int)$data['page_key'],
                   (int)$data['feed_key'],
                   $data['feed_page_filter_include'] ?? null,
                   $data['feed_page_filter_exclude'] ?? null,
                   $data['feed_page_listing_type'] ?? null,
                   $data['feed_page_filter_orientation'] ?? null,
                   (int)($data['feed_page_sort'] ?? 0),
               ]);
            // Re-evaluate page associations
            require_once __DIR__ . '/feed-item-pages.php';
            updatePageItems($db, (int)$data['page_key']);
            jsonResponse(['created' => true]);
        }

        if ($action === 'update') {
            $db->prepare("UPDATE yy_feed_page SET feed_key = ?, page_key = ?, feed_page_filter_include = ?, feed_page_filter_exclude = ?, feed_page_listing_type = ?, feed_page_filter_orientation = ?, feed_page_sort = ?, feed_page_revision_dtime = NOW() WHERE feed_page_key = ?")
               ->execute([
                   (int)$data['feed_key'],
                   (int)$data['page_key'],
                   $data['feed_page_filter_include'] ?? null,
                   $data['feed_page_filter_exclude'] ?? null,
                   $data['feed_page_listing_type'] ?? null,
                   $data['feed_page_filter_orientation'] ?? null,
                   (int)($data['feed_page_sort'] ?? 0),
                   (int)$data['feed_page_key'],
               ]);
            // Re-evaluate page associations
            require_once __DIR__ . '/feed-item-pages.php';
            updatePageItems($db, (int)$data['page_key']);
            jsonResponse(['updated' => true]);
        }

        errorResponse('Invalid feed_page_action');
    }

    $feedKey = $data['feed_key'] ?? null;

    $fields = [
        'feed_name', 'feed_site_code', 'feed_account_id', 'feed_source_url', 'feed_public_url', 'feed_api_key', 'feed_active_flag', 'feed_stream_flag', 'feed_stream_dtime'
    ];

    if ($feedKey) {
        // Update
        $sets = [];
        $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = ?";
                $v = $data[$f];
                // Cast booleans explicitly for PostgreSQL
                if (is_bool($v)) $v = $v ? 't' : 'f';
                $vals[] = $v;
            }
        }
        if ($sets) {
            // Log any change to feed_stream_flag so we can trace who's toggling it
            if (array_key_exists('feed_stream_flag', $data)) {
                $caller = $_SERVER['HTTP_REFERER'] ?? 'unknown';
                $userKey = $_SESSION['user_key'] ?? 0;
                $newVal = $data['feed_stream_flag'] ? 'TRUE' : 'FALSE';
                $db->prepare("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag) VALUES ('feed_stream_flag', 'info', ?, ?, TRUE)")
                   ->execute(["feed_stream_flag set to $newVal on feed_key=$feedKey by user $userKey", "Referer: $caller\nFull payload: " . json_encode($data)]);
            }
            $vals[] = $feedKey;
            $db->prepare("UPDATE yy_feed SET " . implode(', ', $sets) . " WHERE feed_key = ?")->execute($vals);
        }

        // Update schedules if provided
        if (isset($data['schedules'])) {
            $db->prepare("DELETE FROM yy_feed_schedule WHERE feed_key = ?")->execute([$feedKey]);
            foreach ($data['schedules'] as $sched) {
                $db->prepare("INSERT INTO yy_feed_schedule (feed_key, schedule_day_of_week, schedule_time, schedule_interval_minutes, schedule_active_flag) VALUES (?, ?, ?, ?, ?)")
                    ->execute([
                        $feedKey,
                        $sched['schedule_day_of_week'] ?? null,
                        $sched['schedule_time'] ?? null,
                        $sched['schedule_interval_minutes'] ?? null,
                        $sched['schedule_active_flag'] ?? true
                    ]);
            }
        }

        jsonResponse(['updated' => true, 'feed_key' => $feedKey]);
    } else {
        // Insert
        $cols = [];
        $placeholders = [];
        $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $cols[] = $f;
                $placeholders[] = '?';
                $vals[] = $data[$f];
            }
        }
        $db->prepare("INSERT INTO yy_feed (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")")->execute($vals);
        $newKey = $db->lastInsertId('yy_feed_feed_key_seq');

        // Insert schedules if provided
        if (isset($data['schedules'])) {
            foreach ($data['schedules'] as $sched) {
                $db->prepare("INSERT INTO yy_feed_schedule (feed_key, schedule_day_of_week, schedule_time, schedule_interval_minutes, schedule_active_flag) VALUES (?, ?, ?, ?, ?)")
                    ->execute([
                        $newKey,
                        $sched['schedule_day_of_week'] ?? null,
                        $sched['schedule_time'] ?? null,
                        $sched['schedule_interval_minutes'] ?? null,
                        $sched['schedule_active_flag'] ?? true
                    ]);
            }
        }

        jsonResponse(['created' => true, 'feed_key' => $newKey]);
    }
}

// Extract inserted+updated counts from sync response (handles flat or nested results)
function parseSyncCounts(array $data): int {
    if (isset($data['inserted']) || isset($data['updated'])) {
        return ($data['inserted'] ?? 0) + ($data['updated'] ?? 0);
    }
    // YouTube returns {results: [{inserted, updated}, ...]}
    if (isset($data['results']) && is_array($data['results'])) {
        $total = 0;
        foreach ($data['results'] as $r) {
            $total += ($r['inserted'] ?? 0) + ($r['updated'] ?? 0);
        }
        return $total;
    }
    return 0;
}

// SYNC - force refresh a feed
// PUT: update a feed item
if ($method === 'PUT' && isset($_GET['item_update'])) {
    $data = json_decode(file_get_contents('php://input'), true);
    $itemKey = (int)($data['feed_item_key'] ?? 0);
    if (!$itemKey) errorResponse('feed_item_key required');
    $allowed = ['feed_item_title_import','feed_item_title_override','feed_item_publish_override_dtime','feed_item_url','feed_item_thumbnail','feed_item_tags','feed_item_episode','feed_item_sort','feed_item_orientation','feed_item_type','feed_item_audio_file','feed_item_active_flag'];
    $sets = []; $vals = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "$f = ?";
            $v = $data[$f];
            if (is_bool($v)) $v = $v ? 't' : 'f';
            $vals[] = $v;
        }
    }
    if ($sets) {
        $vals[] = $itemKey;
        $db->prepare("UPDATE yy_feed_item SET " . implode(', ', $sets) . " WHERE feed_item_key = ?")->execute($vals);
    }
    // Multi-category assignments — replace all if 'categories' array is provided
    if (array_key_exists('categories', $data) && is_array($data['categories'])) {
        $db->prepare("DELETE FROM yy_feed_item_category WHERE feed_item_key = ?")->execute([$itemKey]);
        $catUp = $db->prepare("INSERT INTO yy_feed_item_category (feed_item_key, category_key, feed_item_category_episode) VALUES (?, ?, ?) ON CONFLICT (feed_item_key, category_key) DO UPDATE SET feed_item_category_episode = EXCLUDED.feed_item_category_episode");
        foreach ($data['categories'] as $ca) {
            $catKey = (int)($ca['category_key'] ?? 0);
            if (!$catKey) continue;
            $ep = isset($ca['episode']) && $ca['episode'] !== '' ? trim((string)$ca['episode']) : null;
            $catUp->execute([$itemKey, $catKey, $ep]);
        }
    }
    if (!$sets && !array_key_exists('categories', $data)) errorResponse('Nothing to update');
    // Re-evaluate page associations if tags, title, or orientation changed
    $pageRelevant = ['feed_item_tags', 'feed_item_title_override', 'feed_item_title_import', 'feed_item_orientation', 'feed_item_active_flag'];
    if (array_intersect(array_keys($data), $pageRelevant)) {
        require_once __DIR__ . '/feed-item-pages.php';
        updateItemPages($db, $itemKey);
    }
    jsonResponse(['saved' => true]);
}

if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $feedKey = $data['feed_key'] ?? null;
    if (!$feedKey) errorResponse('feed_key required');

    $stmt = $db->prepare("SELECT * FROM yy_feed WHERE feed_key = ?");
    $stmt->execute([$feedKey]);
    $feed = $stmt->fetch();
    if (!$feed) errorResponse('Feed not found', 404);

    $result = ['feed' => $feed['feed_name'], 'action' => 'sync'];

    $site = strtolower($feed['feed_site_code'] ?? '');

    // Map sites to their sync scripts
    $syncScripts = [
        'youtube'  => __DIR__ . '/sync-youtube.php',
        'rumble'   => __DIR__ . '/sync-rumble.php',
        'facebook' => __DIR__ . '/sync-facebook.php',
    ];

    $matchedCount = 0;
    $syncScript = $syncScripts[$site] ?? null;

    if ($syncScript && file_exists($syncScript)) {
        if ($site === 'facebook' || $site === 'youtube') {
            // Long-running — run fully detached in the background. A full
            // YouTube channel sync (up to 2000 videos via the Data API +
            // privacy sweep + page rebuild) takes ~2-4 min, well past
            // Cloudflare's 100s edge timeout, so a synchronous run always
            // 524'd the browser even though it finished server-side. Detaching
            // lets the PUT return immediately; the UI polls ?sync_status=1.
            // YouTube is scoped to just the clicked feed (passed as argv[1]);
            // Facebook syncs all FB feeds. Capped at 15min CPU so a stuck sync
            // can't peg the box.
            require_once __DIR__ . '/spawn-helpers.php';
            $logFile = sys_get_temp_dir() . '/sync_feed_' . $feedKey . '.log';
            $workerArgs = ($site === 'youtube') ? [(int)$feedKey] : [];
            $pid = spawnCappedWorker($syncScript, $workerArgs, $logFile, [
                'cpu_secs' => 900, 'mem_mb' => 800, 'nice' => 10,
            ]);
            $result['sync_result'] = ['status' => 'started', 'message' => 'Sync running in background', 'log' => $logFile, 'pid' => $pid];
            $result['async'] = true;
        } else {
            // Run sync script via CLI, capture JSON output
            $output = [];
            $rc = 0;
            exec("php " . escapeshellarg($syncScript) . " 2>/dev/null", $output, $rc);
            // Sync scripts print human-readable lines to stdout; the last line or
            // a line containing JSON has the counts. Parse counts from output.
            $fullOutput = implode("\n", $output);
            $inserted = 0; $updated = 0; $found = 0;
            if (preg_match('/found[=:\s]+(\d+)/i', $fullOutput, $m)) $found = (int)$m[1];
            if (preg_match_all('/inserted[=:\s]+(\d+)/i', $fullOutput, $m)) $inserted = array_sum(array_map('intval', $m[1]));
            if (preg_match_all('/updated[=:\s]+(\d+)/i',  $fullOutput, $m)) $updated  = array_sum(array_map('intval', $m[1]));
            $matchedCount = $inserted + $updated;
            $result['sync_result'] = ['found' => $found, 'inserted' => $inserted, 'updated' => $updated, 'rc' => $rc];
        }
    } else {
        $result['error'] = 'No sync script for site: ' . $site;
    }

    $result['matched_count'] = $matchedCount;

    // Update schedule
    $db->prepare("UPDATE yy_feed_schedule SET schedule_last_run = NOW(), schedule_last_count = ?, schedule_last_status = ? WHERE feed_key = ?")
        ->execute([$matchedCount, json_encode($result), $feedKey]);

    jsonResponse($result);
}

// DELETE
if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Delete page-feed mapping
    if (!empty($data['feed_page_key'])) {
        $db->prepare("DELETE FROM yy_feed_page WHERE feed_page_key = ?")->execute([$data['feed_page_key']]);
        jsonResponse(['deleted' => true]);
    }

    // Delete feed
    $feedKey = $data['feed_key'] ?? null;
    if (!$feedKey) errorResponse('feed_key required');
    $db->prepare("DELETE FROM yy_feed_page WHERE feed_key = ?")->execute([$feedKey]);
    $db->prepare("DELETE FROM yy_feed_schedule WHERE feed_key = ?")->execute([$feedKey]);
    $db->prepare("DELETE FROM yy_feed WHERE feed_key = ?")->execute([$feedKey]);
    jsonResponse(['deleted' => true]);
}

