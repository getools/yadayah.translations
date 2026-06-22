<?php
/**
 * Sync Rumble videos into yy_feed_item.
 *
 * Data source: /var/www/html/api/rumble-cache.json which is produced on the
 * host by a cron that runs scrape-rumble.cjs inside the rsshub container
 * (which has Chrome + puppeteer-real-browser to bypass Cloudflare).
 *
 * Run via CLI: php sync-rumble.php
 * Or via API: GET /api/sync-rumble.php?key=yada2026sync
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed-helpers.php';

// Auth check for web requests
if (php_sapi_name() !== 'cli') {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') {
        $user = requireAuth();
    }
}

$db = getDb();

// Get Rumble feed record
$feedStmt = $db->query("SELECT feed_key, feed_account_id FROM yy_feed WHERE lower(feed_site_code) = 'rumble' AND feed_active_flag = true LIMIT 1");
$feed = $feedStmt->fetch();
if (!$feed) {
    $msg = 'No active Rumble feed found';
    if (php_sapi_name() === 'cli') { echo "$msg\n"; exit(1); }
    errorResponse($msg);
}

$feedKey = (int)$feed['feed_key'];

// Start sync log
$db->prepare("INSERT INTO yy_feed_sync (feed_key, feed_sync_status) VALUES (?, 'running')")
   ->execute([$feedKey]);
$syncKey = $db->lastInsertId('yy_feed_sync_feed_sync_key_seq');

$totalFound = 0;
$totalInserted = 0;
$totalUpdated = 0;
$error = null;

try {
    $cacheFile = __DIR__ . '/rumble-cache.json';
    if (!file_exists($cacheFile)) {
        throw new Exception('Rumble cache file not found: ' . $cacheFile);
    }

    $cacheAge = time() - filemtime($cacheFile);
    if (php_sapi_name() === 'cli') {
        echo "Cache file age: " . round($cacheAge / 60) . " minutes\n";
    }

    $json = file_get_contents($cacheFile);
    $videos = json_decode($json, true);
    if (!is_array($videos)) {
        throw new Exception('Invalid JSON in cache file');
    }

    // Hashtag parse rules (shared engine): seeded vlog/basics defaults + every
    // active Items section's include-hashtag template.
    $parseRules = getHashtagParseRules($db);

    foreach ($videos as $v) {
        $vidId = $v['video_id'] ?? '';
        $title = $v['title'] ?? '';
        $vidUrl = $v['url'] ?? '';
        $thumb = $v['thumbnail'] ?? '';
        $embedId = $v['embed_id'] ?? '';
        $publishDate = $v['date'] ?? null;
        $description = $v['description'] ?? '';

        if (!$vidId || !$title || !$vidUrl) continue;
        $totalFound++;

        // Parse hashtag templates (#vlog|[category]|[episode], etc.) from the
        // title + description. feed_item_episode + category assignments are
        // applied below via applyParsedItemFields (most-recent-wins). Pass NULL
        // episode into the upsert so COALESCE preserves the existing value.
        $tags = '';
        $parse = applyHashtagTemplates($db, $title . "\n" . $description, $parseRules);
        $episode = null;

        // Extract all hashtags from description for feed_item_tags.
        // Joined with bare commas (no space) to match the format produced by
        // sync-youtube/sync-facebook and consumed by tagFilterClause's
        // whole-word regex.
        if ($description && preg_match_all('/#[a-zA-Z0-9_]+/', $description, $tagMatches)) {
            $tags = implode(',', array_unique($tagMatches[0]));
        }

        $stmt = $db->prepare("
            INSERT INTO yy_feed_item (feed_key, feed_item_external_id, feed_item_title_import, feed_item_url, feed_item_thumbnail, feed_item_embed_id, feed_item_publish_import_dtime, feed_item_description, feed_item_tags, feed_item_episode)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?)
            ON CONFLICT (feed_key, feed_item_external_id) DO UPDATE SET
                feed_item_title_import = EXCLUDED.feed_item_title_import,
                feed_item_url = EXCLUDED.feed_item_url,
                feed_item_thumbnail = EXCLUDED.feed_item_thumbnail,
                feed_item_embed_id = EXCLUDED.feed_item_embed_id,
                feed_item_publish_import_dtime = COALESCE(EXCLUDED.feed_item_publish_import_dtime, yy_feed_item.feed_item_publish_import_dtime),
                feed_item_description = COALESCE(EXCLUDED.feed_item_description, yy_feed_item.feed_item_description),
                feed_item_tags = COALESCE(EXCLUDED.feed_item_tags, yy_feed_item.feed_item_tags),
                feed_item_episode = COALESCE(EXCLUDED.feed_item_episode, yy_feed_item.feed_item_episode),
                feed_item_revision_dtime = NOW()
            WHERE yy_feed_item.feed_item_title_import IS DISTINCT FROM EXCLUDED.feed_item_title_import
               OR yy_feed_item.feed_item_url IS DISTINCT FROM EXCLUDED.feed_item_url
               OR yy_feed_item.feed_item_thumbnail IS DISTINCT FROM EXCLUDED.feed_item_thumbnail
               OR yy_feed_item.feed_item_embed_id IS DISTINCT FROM EXCLUDED.feed_item_embed_id
               OR yy_feed_item.feed_item_publish_import_dtime IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_publish_import_dtime, yy_feed_item.feed_item_publish_import_dtime)
               OR yy_feed_item.feed_item_description IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_description, yy_feed_item.feed_item_description)
               OR yy_feed_item.feed_item_tags IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_tags, yy_feed_item.feed_item_tags)
               OR yy_feed_item.feed_item_episode IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_episode, yy_feed_item.feed_item_episode)
            RETURNING (xmax = 0) as is_insert
        ");
        $stmt->execute([$feedKey, $vidId, $title, $vidUrl, $thumb, $embedId, $publishDate, $description, $tags, $episode]);
        $row = $stmt->fetch();
        if ($row) {
            if ($row['is_insert']) $totalInserted++;
            else $totalUpdated++;
        }

        // Apply parsed captures (episode/date/title/sort) + category
        // assignments via the shared engine (most-recent-wins arbitration;
        // page-scoped category replacement).
        if ($parse['fields'] || $parse['categories']) {
            $itemKeyStmt = $db->prepare("SELECT feed_item_key FROM yy_feed_item WHERE feed_key = ? AND feed_item_external_id = ?");
            $itemKeyStmt->execute([$feedKey, $vidId]);
            $itemKey = (int)($itemKeyStmt->fetchColumn() ?: 0);
            if ($itemKey) applyParsedItemFields($db, $itemKey, $parse);
        }
    }
} catch (\Exception $e) {
    $error = $e->getMessage();
}

// Deactivate duplicate items (same feed + same title, keep earliest)
$deduped = 0;
$dedupStmt = $db->prepare("
    UPDATE yy_feed_item SET feed_item_active_flag = FALSE
    WHERE feed_item_key IN (
        SELECT fi.feed_item_key
        FROM yy_feed_item fi
        INNER JOIN (
            SELECT feed_key, feed_item_title_import, MIN(feed_item_key) as keep_key
            FROM yy_feed_item
            WHERE feed_key = ? AND feed_item_active_flag = TRUE
            GROUP BY feed_key, feed_item_title_import
            HAVING COUNT(*) > 1
        ) dups ON fi.feed_key = dups.feed_key AND fi.feed_item_title_import = dups.feed_item_title_import AND fi.feed_item_key != dups.keep_key
    )
");
$dedupStmt->execute([$feedKey]);
$deduped = $dedupStmt->rowCount();
if ($deduped > 0 && php_sapi_name() === 'cli') echo "Deactivated {$deduped} duplicate(s)\n";

// Update sync log
$status = $error ? 'error' : 'success';
$db->prepare("UPDATE yy_feed_sync SET feed_sync_status = ?, feed_sync_items_found = ?, feed_sync_items_inserted = ?, feed_sync_items_updated = ?, feed_sync_error = ?, feed_sync_end_dtime = NOW() WHERE feed_sync_key = ?")
   ->execute([$status, $totalFound, $totalInserted, $totalUpdated, $error, $syncKey]);

if ($error) {
    logMonitorEvent('sync_rumble', 'error', 'Rumble sync failed: ' . $error,
        "found=$totalFound inserted=$totalInserted updated=$totalUpdated\nfeed_sync_key=$syncKey");
}

// Update feed item → page associations after sync
require_once __DIR__ . '/feed-item-pages.php';
updateItemPagesForFeed($db, $feedKey);

$result = [
    'synced' => !$error,
    'found' => $totalFound,
    'inserted' => $totalInserted,
    'updated' => $totalUpdated,
    'error' => $error,
];

if (php_sapi_name() === 'cli') {
    echo "\nDone: found={$totalFound} inserted={$totalInserted} updated={$totalUpdated}\n";
    if ($error) echo "Error: {$error}\n";
} else {
    jsonResponse($result);
}
