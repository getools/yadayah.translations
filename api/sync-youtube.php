<?php
/**
 * YouTube feed sync — fetches videos from YouTube RSS/API and stores in yy_feed_item.
 * Syncs ALL active YouTube feeds (channels and playlists).
 *
 * CLI:   php sync-youtube.php
 * Web:   GET /api/sync-youtube.php?key=yada2026sync
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDb();
$isCli = php_sapi_name() === 'cli';
// Sync legitimately takes longer than the 120s web-request safety net in
// config.php (playlist rebuilds, privacy sweeps). Override to 10 minutes for
// both CLI and web-triggered runs.
$db->exec("SET statement_timeout = '600s'");

// Hashtag parse rules (shared engine): seeded vlog/basics defaults + every
// active Items section's include-hashtag template. Cached per request.
$parseRules = getHashtagParseRules($db);

if (!$isCli && !defined('SYNC_CALLED_FROM_PARENT')) {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') {
        requireAuth();
    }
}

// Optional single-feed scope. CLI: argv[1]; web: ?feed_key=N. 0/absent = all
// active YouTube feeds (the cron behavior). The admin "Sync" button passes one
// key so a manual sync only touches the clicked channel (and its privacy sweep
// + page rebuild stay scoped, keeping the run short).
$onlyFeedKey = (int)($isCli ? ($argv[1] ?? 0) : ($_GET['feed_key'] ?? 0));

// Find active YouTube feeds (all, or just the requested one)
$feedWhere = "lower(feed_site_code) = 'youtube' AND feed_active_flag = TRUE";
if ($onlyFeedKey) $feedWhere .= " AND feed_key = " . $onlyFeedKey;
$feeds = $db->query("SELECT feed_key, feed_name, feed_account_id, feed_api_key, feed_api_endpoint FROM yy_feed WHERE $feedWhere ORDER BY feed_key")->fetchAll();
if (!$feeds) {
    $msg = 'No active YouTube feeds found';
    if ($isCli) { echo "$msg\n"; exit; }
    jsonResponse(['error' => $msg]);
}

$results = [];

foreach ($feeds as $feed) {
    $feedKey = (int)$feed['feed_key'];
    $accountId = $feed['feed_account_id'];
    $apiKey = $feed['feed_api_key'] ?: getenv('YOUTUBE_API_KEY') ?: '';
    $isPlaylist = stripos($feed['feed_api_endpoint'] ?? '', 'playlist') !== false || substr($accountId, 0, 2) === 'PL';

    // Start sync log
    $db->prepare("INSERT INTO yy_feed_sync (feed_key, feed_sync_status) VALUES (?, 'running')")->execute([$feedKey]);
    $syncKey = $db->lastInsertId('yy_feed_sync_feed_sync_key_seq');

    $totalFound = 0;
    $totalInserted = 0;
    $totalUpdated = 0;
    $error = null;

    try {
        $videos = [];

        if ($isPlaylist) {
            $videos = fetchYouTubeRss("https://www.youtube.com/feeds/videos.xml?playlist_id=" . urlencode($accountId));
        } else {
            $videos = fetchYouTubeRss("https://www.youtube.com/feeds/videos.xml?channel_id=" . urlencode($accountId));

            // RSS only returns ~15 videos. If API key available, fetch more via API
            if ($apiKey) {
                $apiVideos = fetchYouTubeApi($accountId, $apiKey);
                // Merge, dedup by ID
                $existing = array_column($videos, null, 'id');
                foreach ($apiVideos as $v) {
                    if (!isset($existing[$v['id']])) {
                        $videos[] = $v;
                    }
                }
            }
        }

        // Fetch durations + descriptions from YouTube Data API (batches of 50)
        if ($apiKey && $videos) {
            $details = fetchYouTubeVideoDetails(array_column($videos, 'id'), $apiKey);
            foreach ($videos as &$v) {
                if (isset($details[$v['id']])) {
                    $v['duration'] = $details[$v['id']]['seconds'];
                    $v['durationStr'] = $details[$v['id']]['formatted'];
                    $v['orientation'] = $details[$v['id']]['orientation'] ?? null;
                    if (empty($v['description'])) {
                        $v['description'] = $details[$v['id']]['description'];
                    }
                }
            }
            unset($v);
        }

        $totalFound = count($videos);

        // All YouTube items are videos — shorts are identified by duration at query time
        // via yy_feed_page.feed_page_filter_duration_max.
        $type = 'video';

        foreach ($videos as $v) {
            $videoId = $v['id'];
            if (!$videoId) continue;
            $itemType = $type;
            $durationSeconds = isset($v['duration']) ? (int)$v['duration'] : null;
            // Detect orientation: #shorts in title/description = vertical, otherwise use thumbnail aspect ratio
            $searchText = strtolower(($v['title'] ?? '') . ' ' . ($v['description'] ?? ''));
            $isShort = strpos($searchText, '#shorts') !== false;
            if ($isShort) {
                $orientation = 'vertical';
            } else {
                $orientation = $v['orientation'] ?? null;
                // Fallback: if no thumbnail orientation and very short duration, leave as null (unknown)
            }

            // Defensively decode HTML entities (handles double-encoding)
            $cleanTitle = (string)$v['title'];
            do {
                $prev = $cleanTitle;
                $cleanTitle = html_entity_decode($cleanTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } while ($cleanTitle !== $prev);

            // Parse hashtag templates (e.g. #vlog|[category]|[episode]) from the
            // title + description. Categories are resolved/auto-created per page;
            // captured episode/date/title/sort are applied below via
            // applyParsedItemFields with most-recent-wins arbitration.
            $searchText = $cleanTitle . "\n" . ($v['description'] ?? '');
            $parse = applyHashtagTemplates($db, $searchText, $parseRules);
            // feed_item_episode is owned by applyParsedItemFields (provenance-
            // aware). Pass NULL into the upsert so COALESCE preserves whatever
            // is there; the reconcile step sets/keeps the winning value.
            $episode = null;

            // Build tags from hashtags found in title + description
            // Place #vlog first so the starts-with filter (#vlog*) works on the comma-separated string
            $itemTags = null;
            if (preg_match_all('/#[a-zA-Z][a-zA-Z0-9_-]+/', $searchText, $tagMatches)) {
                $tags = array_unique($tagMatches[0]);
                $vlogTag = null;
                $otherTags = [];
                foreach ($tags as $tag) {
                    if (strtolower($tag) === '#vlog') $vlogTag = $tag;
                    else $otherTags[] = $tag;
                }
                if ($vlogTag) array_unshift($otherTags, $vlogTag);
                $itemTags = implode(',', $otherTags);
            }

            // If title contains hashtags (or #vlog|...|... patterns), pre-compute a cleaned
            // override that strips them out. Stored on insert; on update we only set it if
            // the existing override was previously the auto-generated cleaned form of the
            // OLD imported title (so manual edits are never overwritten).
            $autoOverride = null;
            if (preg_match('/#\w/', $cleanTitle)) {
                $autoOverride = $cleanTitle;
                foreach ($parseRules as $pr) {                                   // remove any matched #tag|…|… template
                    $autoOverride = preg_replace($pr['regex'], '', $autoOverride);
                }
                $autoOverride = preg_replace('/#[a-zA-Z][a-zA-Z0-9_-]+/', '', $autoOverride); // remove plain hashtags
                $autoOverride = preg_replace('/\s{2,}/', ' ', $autoOverride);
                $autoOverride = trim(preg_replace('/^[~\-\s]+|[~\-\s]+$/', '', $autoOverride));
                if ($autoOverride === '' || $autoOverride === $cleanTitle) $autoOverride = null;
            }

            $stmt = $db->prepare("
                INSERT INTO yy_feed_item (feed_key, feed_item_external_id, feed_item_title_import, feed_item_title_override, feed_item_url, feed_item_thumbnail, feed_item_embed_id, feed_item_publish_import_dtime, feed_item_active_flag, feed_item_type, feed_item_duration, feed_item_duration_seconds, feed_item_orientation, feed_item_episode, feed_item_tags)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (feed_key, feed_item_external_id) DO UPDATE SET
                    feed_item_title_import = EXCLUDED.feed_item_title_import,
                    feed_item_thumbnail = COALESCE(EXCLUDED.feed_item_thumbnail, yy_feed_item.feed_item_thumbnail),
                    feed_item_publish_import_dtime = COALESCE(EXCLUDED.feed_item_publish_import_dtime, yy_feed_item.feed_item_publish_import_dtime),
                    feed_item_type = EXCLUDED.feed_item_type,
                    feed_item_duration = COALESCE(EXCLUDED.feed_item_duration, yy_feed_item.feed_item_duration),
                    feed_item_duration_seconds = COALESCE(EXCLUDED.feed_item_duration_seconds, yy_feed_item.feed_item_duration_seconds),
                    feed_item_orientation = COALESCE(EXCLUDED.feed_item_orientation, yy_feed_item.feed_item_orientation),
                    feed_item_episode = COALESCE(EXCLUDED.feed_item_episode, yy_feed_item.feed_item_episode),
                    feed_item_tags = COALESCE(EXCLUDED.feed_item_tags, yy_feed_item.feed_item_tags),
                    feed_item_revision_dtime = NOW()
                WHERE yy_feed_item.feed_item_title_import IS DISTINCT FROM EXCLUDED.feed_item_title_import
                   OR yy_feed_item.feed_item_thumbnail IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_thumbnail, yy_feed_item.feed_item_thumbnail)
                   OR yy_feed_item.feed_item_type IS DISTINCT FROM EXCLUDED.feed_item_type
                   OR yy_feed_item.feed_item_duration IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_duration, yy_feed_item.feed_item_duration)
                   OR yy_feed_item.feed_item_episode IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_episode, yy_feed_item.feed_item_episode)
                   OR yy_feed_item.feed_item_tags IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_tags, yy_feed_item.feed_item_tags)
                   OR yy_feed_item.feed_item_orientation IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_orientation, yy_feed_item.feed_item_orientation)
                   OR (EXCLUDED.feed_item_publish_import_dtime IS NOT NULL AND yy_feed_item.feed_item_publish_import_dtime IS DISTINCT FROM EXCLUDED.feed_item_publish_import_dtime)
                RETURNING (xmax = 0) AS inserted
            ");

            $stmt->execute([
                $feedKey, $videoId, $cleanTitle, $autoOverride,
                'https://www.youtube.com/watch?v=' . $videoId,
                $v['thumbnail'], $videoId,
                $v['published'] ?: null,
                $itemType,
                $v['durationStr'] ?? null,
                $durationSeconds,
                $orientation,
                $episode,
                $itemTags,
            ]);
            $row = $stmt->fetch();
            if ($row) {
                if ($row['inserted']) $totalInserted++;
                else $totalUpdated++;
            }

            // Apply parsed captures (episode/date/title/sort) + category
            // assignments. Each field is arbitrated by most-recent-wins
            // (hashtag vs. admin edit) and category rows are replaced only for
            // the page_keys our templates actually touched, so other pages'
            // admin assignments are never wiped.
            if (($parse['fields'] || $parse['categories'])) {
                $itemKeyStmt = $db->prepare("SELECT feed_item_key FROM yy_feed_item WHERE feed_key = ? AND feed_item_external_id = ?");
                $itemKeyStmt->execute([$feedKey, $videoId]);
                $itemKey = (int)($itemKeyStmt->fetchColumn() ?: 0);
                if ($itemKey) applyParsedItemFields($db, $itemKey, $parse);
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    // Update sync log
    $status = $error ? 'error' : 'success';
    $db->prepare("UPDATE yy_feed_sync SET feed_sync_status = ?, feed_sync_items_found = ?, feed_sync_items_inserted = ?, feed_sync_items_updated = ?, feed_sync_error = ?, feed_sync_end_dtime = NOW() WHERE feed_sync_key = ?")
       ->execute([$status, $totalFound, $totalInserted, $totalUpdated, $error, $syncKey]);

    if ($error) {
        logMonitorEvent('sync_youtube', 'error',
            'YouTube sync failed for feed "' . $feed['feed_name'] . '": ' . $error,
            "feed_key={$feed['feed_key']} found=$totalFound inserted=$totalInserted updated=$totalUpdated\nfeed_sync_key=$syncKey");
    }

    $results[] = [
        'feed' => $feed['feed_name'],
        'found' => $totalFound,
        'inserted' => $totalInserted,
        'updated' => $totalUpdated,
        'error' => $error,
    ];

    if ($isCli) {
        echo "{$feed['feed_name']}: found=$totalFound inserted=$totalInserted updated=$totalUpdated" . ($error ? " error=$error" : '') . "\n";
    }
}

// ── Check for restricted/private videos ──
// Rotate through items across successive sync runs (least-recently-checked
// first). Preferred path: pull privacy status straight from the YouTube Data
// API (videos.list?part=status) — the same API that brings the rest of the
// video info. A public/unlisted video returns privacyStatus; a private or
// deleted video is omitted from the response entirely. Either omission or
// privacyStatus 'private' => Restricted. Falls back to a thumbnail-404 probe
// only when no API key is configured. yy_feed_item_check records the per-item
// last-checked time + result so the queue cycles fairly (200/run × 3/day).
$restrictFeedFilter = $onlyFeedKey ? (" AND fi.feed_key = " . $onlyFeedKey) : "";
$restrictStmt = $db->query("
    SELECT fi.feed_item_key, fi.feed_item_thumbnail, fi.feed_item_external_id
    FROM yy_feed_item fi
    LEFT JOIN yy_feed_item_check c USING (feed_item_key)
    WHERE fi.feed_item_active_flag = TRUE AND fi.feed_item_restricted_flag = FALSE
      AND fi.feed_item_thumbnail LIKE 'https://i.ytimg.com/%'$restrictFeedFilter
    ORDER BY c.feed_item_last_checked_dtime ASC NULLS FIRST,
             fi.feed_item_publish_import_dtime DESC
    LIMIT 200
");
$markRestrictedStmt = $db->prepare(
    "UPDATE yy_feed_item SET feed_item_restricted_flag = TRUE WHERE feed_item_key = ?"
);
$recordCheckStmt = $db->prepare(
    "INSERT INTO yy_feed_item_check (feed_item_key, feed_item_last_checked_dtime, feed_item_check_result)
     VALUES (?, NOW(), ?)
     ON CONFLICT (feed_item_key) DO UPDATE
        SET feed_item_last_checked_dtime = EXCLUDED.feed_item_last_checked_dtime,
            feed_item_check_result = EXCLUDED.feed_item_check_result"
);
$restrictCount = 0;
$checkedCount  = 0;
$sweepRows = $restrictStmt->fetchAll();
// API key for the sweep: env first, else the last active YouTube feed's key
// ($apiKey survives the per-feed loop above).
$sweepApiKey = getenv('YOUTUBE_API_KEY') ?: ($apiKey ?? '');

if ($sweepApiKey && $sweepRows) {
    // Map external_id (= YouTube video id) → feed_item_key for the batch.
    $byExt = [];
    foreach ($sweepRows as $ri) {
        if (!empty($ri['feed_item_external_id'])) $byExt[$ri['feed_item_external_id']] = $ri['feed_item_key'];
    }
    $probe = fetchYouTubePrivacyStatus(array_keys($byExt), $sweepApiKey);
    foreach ($byExt as $ext => $fik) {
        // Only act on IDs whose API chunk actually succeeded. A failed or
        // quota'd call leaves the id out of `checked` — never read that as
        // "missing", or a transient error would mass-restrict the library.
        if (empty($probe['checked'][$ext])) continue;
        $ps = $probe['status'][$ext] ?? null;     // null = omitted by API = private/deleted
        $restricted = ($ps === null || $ps === 'private');
        $result = ($ps === null) ? 'api_missing' : ('api_' . $ps);
        if ($restricted) {
            $markRestrictedStmt->execute([$fik]);
            $restrictCount++;
            if ($isCli) echo "Restricted: $ext ($result)\n";
        }
        $recordCheckStmt->execute([$fik, $result]);
        $checkedCount++;
    }
    if ($isCli) echo "Privacy check (API): {$checkedCount} probed, {$restrictCount} marked restricted\n";
} else {
    // Fallback (no API key): probe the thumbnail URL — 404 = gone/private.
    foreach ($sweepRows as $ri) {
        $headers = @get_headers($ri['feed_item_thumbnail'], true);
        $httpCode = $headers ? (int)substr($headers[0], 9, 3) : 0;
        $result = $httpCode === 404 ? '404' : ($httpCode === 200 ? 'ok' : ('http_' . $httpCode));
        if ($httpCode === 404) {
            $markRestrictedStmt->execute([$ri['feed_item_key']]);
            $restrictCount++;
            if ($isCli) echo "Restricted: {$ri['feed_item_external_id']} (thumbnail 404)\n";
        }
        $recordCheckStmt->execute([$ri['feed_item_key'], $result]);
        $checkedCount++;
    }
    if ($isCli) echo "Privacy check (thumbnail): {$checkedCount} item(s) probed, {$restrictCount} marked restricted\n";
}

// Update feed item → page associations after sync
require_once __DIR__ . '/feed-item-pages.php';
foreach ($feeds as $feed) {
    updateItemPagesForFeed($db, (int)$feed['feed_key']);
}

if (!$isCli) {
    jsonResponse(['synced' => true, 'results' => $results]);
}

// ── Helper: Fetch videos from YouTube RSS ──
function fetchYouTubeRss(string $url): array {
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0']]);
    $xml = @file_get_contents($url, false, $ctx);
    if (!$xml) return [];

    $feed = @simplexml_load_string($xml);
    if (!$feed) return [];

    $videos = [];
    foreach ($feed->entry as $entry) {
        $ns = $entry->children('yt', true);
        $videoId = (string)$ns->videoId;
        if (!$videoId) continue;

        $videos[] = [
            'id' => $videoId,
            'title' => (string)$entry->title,
            'published' => (string)$entry->published,
            'thumbnail' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
        ];
    }
    return $videos;
}

// ── Helper: Fetch videos from YouTube Data API (channel uploads) ──
function fetchYouTubeApi(string $channelId, string $apiKey): array {
    // Get uploads playlist ID
    $url = "https://www.googleapis.com/youtube/v3/channels?" . http_build_query([
        'part' => 'contentDetails',
        'id' => $channelId,
        'key' => $apiKey,
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return [];
    $data = json_decode($json, true);
    $uploadsId = $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '';
    if (!$uploadsId) return [];

    // Fetch all pages of uploads
    $videos = [];
    $pageToken = '';
    $maxPages = 40; // ~2000 videos max

    for ($p = 0; $p < $maxPages; $p++) {
        $url = "https://www.googleapis.com/youtube/v3/playlistItems?" . http_build_query(array_filter([
            'part' => 'snippet',
            'playlistId' => $uploadsId,
            'maxResults' => 50,
            'pageToken' => $pageToken,
            'key' => $apiKey,
        ]));
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) break;
        $result = json_decode($json, true);

        foreach (($result['items'] ?? []) as $item) {
            $s = $item['snippet'] ?? [];
            $videoId = $s['resourceId']['videoId'] ?? '';
            if (!$videoId) continue;
            $videos[] = [
                'id' => $videoId,
                'title' => $s['title'] ?? '',
                'description' => $s['description'] ?? '',
                'published' => $s['publishedAt'] ?? '',
                'thumbnail' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            ];
        }

        $pageToken = $result['nextPageToken'] ?? '';
        if (!$pageToken) break;
    }

    return $videos;
}

// ── Helper: Fetch durations + descriptions for a list of video IDs (batches of 50) ──
// Probe privacy status for a set of video IDs via the YouTube Data API.
// Returns ['status' => [id => privacyStatus], 'checked' => [id => true]].
// `checked` marks IDs whose API chunk returned cleanly; an ID present in
// `checked` but absent from `status` is private or deleted (the API omits
// such videos for a non-owner key). Callers MUST ignore IDs not in `checked`
// (network failure / quota error) so a transient API problem can't be
// misread as "video gone" and wrongly mark items restricted.
function fetchYouTubePrivacyStatus(array $videoIds, string $apiKey): array {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $status  = [];
    $checked = [];
    foreach (array_chunk($videoIds, 50) as $batch) {
        $url = "https://www.googleapis.com/youtube/v3/videos?" . http_build_query([
            'part' => 'status',
            'id'   => implode(',', $batch),
            'key'  => $apiKey,
        ]);
        $json = @file_get_contents($url, false, $ctx);
        if ($json === false) continue;                          // network failure → inconclusive
        $res = json_decode($json, true);
        if (!is_array($res) || isset($res['error'])) continue;  // quota / API error → inconclusive
        // Chunk succeeded — every requested id is now conclusively checked.
        foreach ($batch as $id) $checked[$id] = true;
        foreach (($res['items'] ?? []) as $it) {
            $id = $it['id'] ?? '';
            if ($id) $status[$id] = $it['status']['privacyStatus'] ?? 'public';
        }
    }
    return ['status' => $status, 'checked' => $checked];
}

function fetchYouTubeVideoDetails(array $videoIds, string $apiKey): array {
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $details = [];

    foreach (array_chunk($videoIds, 50) as $batch) {
        $url = "https://www.googleapis.com/youtube/v3/videos?" . http_build_query([
            'part' => 'contentDetails,snippet',
            'id' => implode(',', $batch),
            'key' => $apiKey,
        ]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) continue;
        $result = json_decode($json, true);

        foreach (($result['items'] ?? []) as $item) {
            $id = $item['id'] ?? '';
            if (!$id) continue;
            $iso = $item['contentDetails']['duration'] ?? '';
            $seconds = $iso ? parseIsoDuration($iso) : 0;
            // A currently-broadcasting stream reports contentDetails.duration
            // of "P0D"/PT0S (parses to 0). Surface it as "LIVE" instead of
            // 0:00; a later sync (after the stream ends) replaces it with the
            // real runtime via the COALESCE upsert.
            $isLive = (($item['snippet']['liveBroadcastContent'] ?? 'none') === 'live');
            // Determine orientation from thumbnail aspect ratio
            $thumbs = $item['snippet']['thumbnails'] ?? [];
            $thumbInfo = $thumbs['high'] ?? $thumbs['medium'] ?? $thumbs['default'] ?? [];
            $tw = (int)($thumbInfo['width'] ?? 0);
            $th = (int)($thumbInfo['height'] ?? 0);
            $orient = null;
            if ($tw > 0 && $th > 0) {
                $orient = ($th > $tw) ? 'vertical' : 'horizontal';
            }

            $details[$id] = [
                'seconds' => $isLive ? 0 : $seconds,
                'formatted' => $isLive ? 'LIVE' : formatDuration($seconds),
                'description' => $item['snippet']['description'] ?? '',
                'orientation' => $orient,
            ];
        }
    }

    return $details;
}

function fetchYouTubeDurations(array $videoIds, string $apiKey): array {
    return fetchYouTubeVideoDetails($videoIds, $apiKey);
}

function parseIsoDuration(string $iso): int {
    $seconds = 0;
    if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m)) {
        $seconds += (int)($m[1] ?? 0) * 3600;
        $seconds += (int)($m[2] ?? 0) * 60;
        $seconds += (int)($m[3] ?? 0);
    }
    return $seconds;
}

function formatDuration(int $seconds): string {
    if ($seconds >= 3600) {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}
