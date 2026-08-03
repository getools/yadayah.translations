<?php
/**
 * Feed freshness checker — compares the latest published item in yy_feed_item
 * against the latest item available at the external feed source.
 *
 * Called by cron-test.php for tests with type = 'feed_freshness'.
 *
 * Config expects:
 *   feed_key: int — which yy_feed to check
 *   max_age_hours: int — max hours behind before failing (default 24)
 *
 * Returns: ['status' => 'pass'|'fail', 'message' => string, 'detail' => string]
 */

function runFeedFreshnessCheck(PDO $db, array $config): array {
    $feedKey = (int)($config['feed_key'] ?? 0);
    $maxAgeHours = (int)($config['max_age_hours'] ?? 24);
    if (!$feedKey) return ['status' => 'fail', 'message' => 'No feed_key configured'];

    // Get feed info
    $feed = $db->prepare("SELECT * FROM yy_feed WHERE feed_key = ?");
    $feed->execute([$feedKey]);
    $feed = $feed->fetch();
    if (!$feed) return ['status' => 'fail', 'message' => "Feed {$feedKey} not found"];
    if (!$feed['feed_active_flag']) {
        return ['status' => 'skip', 'message' => "Feed {$feed['feed_name']} is inactive — skipping freshness check"];
    }

    // Get our latest imported item. Column was renamed
    // feed_item_publish_dtime -> feed_item_publish_import_dtime; the override
    // sibling holds admin-set publish dates and should also win when present.
    $latestStmt = $db->prepare("
        SELECT MAX(COALESCE(feed_item_publish_override_dtime, feed_item_publish_import_dtime))
          FROM yy_feed_item WHERE feed_key = ?
    ");
    $latestStmt->execute([$feedKey]);
    $latestImported = $latestStmt->fetchColumn();

    $site = strtolower($feed['feed_site_code'] ?? '');
    $publicUrl = $feed['feed_public_url'] ?? $feed['feed_source_url'] ?? '';
    $accountId = $feed['feed_account_id'] ?? '';

    // Fetch latest from external source
    $externalLatest = null;
    $externalTitle = '';
    $checkDetail = '';

    switch ($site) {
        case 'youtube':
            $result = checkYouTubeFeed($accountId, $publicUrl);
            $externalLatest = $result['latest'];
            $externalTitle = $result['title'] ?? '';
            $checkDetail = $result['detail'] ?? '';
            break;

        case 'rumble':
            $result = checkRumbleFeed($publicUrl);
            $externalLatest = $result['latest'];
            $externalTitle = $result['title'] ?? '';
            $checkDetail = $result['detail'] ?? '';
            break;

        case 'facebook':
            $result = checkFacebookFeed($publicUrl, $accountId, $feedKey);
            if (isset($result['status'])) {
                return $result;
            }
            $externalLatest = $result['latest'];
            $externalTitle = $result['title'] ?? '';
            $checkDetail = $result['detail'] ?? '';
            break;

        default:
            return ['status' => 'skip', 'message' => "No freshness checker for site: {$site}"];
    }

    if (!$externalLatest) {
        return [
            'status' => 'fail',
            'message' => "Could not determine latest item from external feed ({$feed['feed_name']})",
            'detail' => $checkDetail,
        ];
    }

    // Compare
    $importedTs = $latestImported ? strtotime($latestImported) : 0;
    $externalTs = strtotime($externalLatest);
    $diffHours = round(($externalTs - $importedTs) / 3600, 1);

    $detail = "Feed: {$feed['feed_name']}\n"
        . "Latest imported: " . ($latestImported ?: 'none') . "\n"
        . "Latest external: {$externalLatest}\n"
        . "External title: {$externalTitle}\n"
        . "Difference: {$diffHours} hours\n"
        . $checkDetail;

    if ($diffHours > $maxAgeHours) {
        return [
            'status' => 'fail',
            'message' => "{$feed['feed_name']} is {$diffHours}h behind. External latest: {$externalTitle}",
            'detail' => $detail,
        ];
    }

    return [
        'status' => 'pass',
        'message' => "{$feed['feed_name']}: up to date (diff: {$diffHours}h)",
        'detail' => $detail,
    ];
}

function checkYouTubeFeed(string $accountId, string $publicUrl): array {
    // YouTube RSS feed
    $channelId = $accountId;
    if (!$channelId) return ['latest' => null, 'detail' => 'No channel/playlist ID'];

    // Determine if channel or playlist
    if (strpos($channelId, 'PL') === 0 || strpos($publicUrl, 'playlist') !== false) {
        $rssUrl = "https://www.youtube.com/feeds/videos.xml?playlist_id={$channelId}";
    } else {
        $rssUrl = "https://www.youtube.com/feeds/videos.xml?channel_id={$channelId}";
    }

    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0']]);
    $xml = @file_get_contents($rssUrl, false, $ctx);
    if (!$xml) return ['latest' => null, 'detail' => "Failed to fetch RSS: {$rssUrl}"];

    $feed = @simplexml_load_string($xml);
    if (!$feed) return ['latest' => null, 'detail' => 'Failed to parse RSS XML'];

    $ns = $feed->getNamespaces(true);
    $entries = $feed->entry ?? [];
    if (empty($entries)) return ['latest' => null, 'detail' => 'No entries in RSS feed'];

    $first = $entries[0];
    $published = (string)($first->published ?? $first->updated ?? '');
    $title = (string)($first->title ?? '');

    return ['latest' => $published, 'title' => $title, 'detail' => "RSS: {$rssUrl}"];
}

function checkRumbleFeed(string $publicUrl): array {
    // Check the Rumble cache file age and latest item
    $cacheFile = __DIR__ . '/rumble-cache.json';
    if (!file_exists($cacheFile)) {
        return ['latest' => null, 'detail' => 'Rumble cache file not found'];
    }

    $json = json_decode(file_get_contents($cacheFile), true);
    if (!is_array($json) || empty($json)) {
        return ['latest' => null, 'detail' => 'Rumble cache is empty or invalid'];
    }

    // Find the most recent video by date
    $latest = null;
    $latestTitle = '';
    foreach ($json as $v) {
        $date = $v['date'] ?? '';
        if ($date && (!$latest || $date > $latest)) {
            $latest = $date;
            $latestTitle = $v['title'] ?? '';
        }
    }

    $cacheAge = round((time() - filemtime($cacheFile)) / 3600, 1);

    return [
        'latest' => $latest,
        'title' => $latestTitle,
        'detail' => "Cache file age: {$cacheAge}h, " . count($json) . " videos in cache",
    ];
}

function checkFacebookFeed(string $publicUrl, string $accountId, int $knownFeedKey = 0): array {
    // Facebook has no public RSS; verify health via sync log.
    // When sync is recent and successful, we can't distinguish "no new posts" from
    // "sync missed something", so return our own latest item time as the baseline
    // (diff = 0h → pass). Only fail when sync itself is stale or errored.
    try {
        $db = function_exists('getDb') ? getDb() : null;
        if ($db) {
            // Use the caller-supplied feed key directly; fall back to account_id lookup only when needed.
            $feedKey = $knownFeedKey ?: null;
            if (!$feedKey && $accountId) {
                $feedStmt = $db->prepare("SELECT feed_key FROM yy_feed WHERE feed_account_id = ? LIMIT 1");
                $feedStmt->execute([$accountId]);
                $feedKey = $feedStmt->fetchColumn() ?: null;
            }

            if ($feedKey) {
                $syncStmt = $db->prepare("
                    SELECT feed_sync_status, feed_sync_end_dtime, feed_sync_items_found
                    FROM yy_feed_sync
                    WHERE feed_key = ?
                      AND feed_sync_status = 'success'
                      AND feed_sync_end_dtime IS NOT NULL
                    ORDER BY feed_sync_key DESC LIMIT 1
                ");
                $syncStmt->execute([$feedKey]);
                $sync = $syncStmt->fetch();
                if ($sync) {
                    $age = round((time() - strtotime($sync['feed_sync_end_dtime'])) / 3600, 1);

                    $latestStmt = $db->prepare("
                        SELECT MAX(COALESCE(feed_item_publish_override_dtime, feed_item_publish_import_dtime))
                        FROM yy_feed_item WHERE feed_key = ?
                    ");
                    $latestStmt->execute([$feedKey]);
                    $latestImported = $latestStmt->fetchColumn() ?: null;

                    if ($age < 48) {
                        if (!$latestImported) {
                            // Sync is healthy but feed has no items yet (new or empty page) — not a freshness failure.
                            return [
                                'status' => 'pass',
                                'message' => "Facebook sync healthy, no items yet (sync ran {$age}h ago)",
                                'detail' => "Sync ran {$age}h ago (status: {$sync['feed_sync_status']}, items in last run: {$sync['feed_sync_items_found']}) — feed may be new or empty",
                            ];
                        }
                        return [
                            'latest' => $latestImported,
                            'title' => "Last sync: {$sync['feed_sync_items_found']} items found",
                            'detail' => "Sync status: {$sync['feed_sync_status']}, age: {$age}h",
                        ];
                    }

                    // Sync is stale (≥ 48h)
                    if (!$latestImported) {
                        // No items + stale sync — fail directly to avoid "56 years behind" diff from epoch 0.
                        return [
                            'status' => 'fail',
                            'message' => "Facebook sync stale ({$age}h ago) and no items in DB",
                            'detail' => "Last sync {$age}h ago — feed may be misconfigured (no account ID or inactive)",
                        ];
                    }
                    return [
                        'latest' => $sync['feed_sync_end_dtime'],
                        'title' => "Sync {$sync['feed_sync_status']}",
                        'detail' => "Last sync {$age}h ago, status: {$sync['feed_sync_status']}",
                    ];
                }
            }
        }
    } catch (Throwable $e) {}

    return ['latest' => null, 'detail' => 'No sync history available for Facebook feed'];
}
