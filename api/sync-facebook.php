<?php
/**
 * Facebook feed sync — fetches posts from Facebook Graph API and stores in yy_feed_item.
 * Syncs ALL Facebook feeds, tagging items with hashtags for page-level filtering.
 *
 * CLI:   php sync-facebook.php
 * Web:   GET /api/sync-facebook.php?key=yada2026sync
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDb();
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// Find all active Facebook feeds
$feeds = $db->query("SELECT feed_key, feed_name, feed_account_id, feed_api_key FROM yy_feed WHERE feed_site_code = 'Facebook' AND feed_active_flag = TRUE")->fetchAll();
if (!$feeds) {
    $msg = 'No active Facebook feeds found';
    if ($isCli) { echo "$msg\n"; exit; }
    jsonResponse(['error' => $msg]);
}

$results = [];

foreach ($feeds as $feed) {
    $feedKey = (int)$feed['feed_key'];
    $pageId = $feed['feed_account_id'];
    $token = $feed['feed_api_key'] ?: getenv('FB_PAGE_TOKEN') ?: '';

    if (!$token) {
        $results[] = ['feed' => $feed['feed_name'], 'error' => 'No API token'];
        continue;
    }

    // Start sync log
    $db->prepare("INSERT INTO yy_feed_sync (feed_key, feed_sync_status) VALUES (?, 'running')")->execute([$feedKey]);
    $syncKey = $db->lastInsertId('yy_feed_sync_feed_sync_key_seq');

    $totalFound = 0;
    $totalInserted = 0;
    $totalUpdated = 0;
    $error = null;

    // Thumbnail directory — use u/blog/ for consistency with legacy data
    $thumbDir = __DIR__ . '/../u/blog';
    if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

    // Pre-check: load existing post IDs with their completion status
    // "complete" means we have a non-text type AND a thumbnail, OR we've established it's text-only
    $existingStmt = $db->prepare("
        SELECT feed_item_external_id,
               feed_item_type,
               feed_item_thumbnail
        FROM yy_feed_item WHERE feed_key = ?
    ");
    $existingStmt->execute([$feedKey]);
    $existing = [];
    foreach ($existingStmt->fetchAll() as $row) {
        $existing[$row['feed_item_external_id']] = [
            'type' => $row['feed_item_type'],
            'has_thumb' => !empty($row['feed_item_thumbnail']),
        ];
    }

    try {
        // Request attachments with media_type so we can detect videos vs photos
        $url = "https://graph.facebook.com/v23.0/$pageId/posts"
             . "?fields=message,full_picture,created_time,parent_id,attachments{media_type,media,subattachments,url,target}"
             . "&access_token=$token&limit=25";

        while ($url) {
            $response = @file_get_contents($url);
            if ($response === false) break;

            $json = json_decode($response, true);
            if (!empty($json['error'])) {
                $error = "Facebook API error: " . ($json['error']['message'] ?? 'unknown') . " (code: " . ($json['error']['code'] ?? '?') . ")";
                break;
            }
            if (empty($json['data'])) break;

            foreach ($json['data'] as $post) {
                $postId = $post['id'] ?? '';
                if (!$postId) continue;

                $message = $post['message'] ?? '';
                if (!$message) continue;

                $totalFound++;

                // Skip fully-synced posts: already has a media type + working thumbnail
                $existingRow = $existing[$postId] ?? null;
                $isComplete = $existingRow
                    && in_array($existingRow['type'], ['photo', 'video'], true)
                    && $existingRow['has_thumb'];
                // Also verify thumbnail file still exists on disk
                if ($isComplete) {
                    $expectedPath = $thumbDir . "/img_$postId.jpg";
                    if (!file_exists($expectedPath) || filesize($expectedPath) < 1000) {
                        $isComplete = false;
                    }
                }
                if ($isComplete) continue;

                // Extract hashtags for tagging
                preg_match_all('/#\w+/', $message, $hashMatches);
                $tags = implode(',', array_unique($hashMatches[0] ?? []));

                // Detect media type from attachments
                $postType = 'text';
                $videoId = null;
                $fullPicture = $post['full_picture'] ?? '';

                if (!empty($post['attachments']['data'])) {
                    foreach ($post['attachments']['data'] as $att) {
                        $mediaType = $att['media_type'] ?? '';
                        if ($mediaType === 'video' || $mediaType === 'video_inline' || $mediaType === 'video_autoplay') {
                            $postType = 'video';
                            if (!empty($att['target']['id'])) $videoId = $att['target']['id'];
                            elseif (!empty($att['url']) && preg_match('/\/videos\/(\d+)/', $att['url'], $m)) $videoId = $m[1];
                            $img = $att['media']['image']['src'] ?? '';
                            if ($img && !$fullPicture) $fullPicture = $img;
                            break;
                        } elseif ($mediaType === 'photo' || $mediaType === 'album') {
                            $postType = 'photo';
                            $img = $att['media']['image']['src'] ?? '';
                            if ($img && !$fullPicture) $fullPicture = $img;
                        }
                    }
                }

                // If listing didn't provide an image (common for shared posts with media_type=link),
                // query the individual post which resolves full_picture from parent
                if (!$fullPicture) {
                    $detailUrl = "https://graph.facebook.com/v23.0/$postId?fields=full_picture,parent_id&access_token=$token";
                    $detailResp = @file_get_contents($detailUrl);
                    if ($detailResp !== false) {
                        $detail = json_decode($detailResp, true);
                        $fullPicture = $detail['full_picture'] ?? '';
                    }
                }

                // Fallback: if we have a picture but no type, it's a photo
                if ($postType === 'text' && $fullPicture) $postType = 'photo';

                // For shared posts without local media, try parent's image
                if (!$fullPicture && !empty($post['parent_id'])) {
                    $parentResp = @file_get_contents("https://graph.facebook.com/v23.0/{$post['parent_id']}?fields=full_picture&access_token=$token");
                    if ($parentResp !== false) {
                        $parentData = json_decode($parentResp, true);
                        $fullPicture = $parentData['full_picture'] ?? '';
                        if ($fullPicture && $postType === 'text') $postType = 'photo';
                    }
                }

                // Download image locally to u/blog/ — only set $localThumb if download actually succeeded AND file exists on disk
                $localThumb = null;
                if ($fullPicture) {
                    $thumbFile = "img_$postId.jpg";
                    $thumbPath = "$thumbDir/$thumbFile";
                    $downloaded = file_exists($thumbPath) && filesize($thumbPath) >= 1000;
                    if (!$downloaded) {
                        $imgData = @file_get_contents($fullPicture);
                        if ($imgData !== false && strlen($imgData) >= 1000) {
                            $bytesWritten = @file_put_contents($thumbPath, $imgData);
                            // Verify the write actually succeeded and the file exists with correct size
                            if ($bytesWritten === strlen($imgData) && file_exists($thumbPath) && filesize($thumbPath) >= 1000) {
                                $downloaded = true;
                            } else {
                                @unlink($thumbPath); // remove partial file
                                if ($isCli) echo "  [warn] Thumbnail write failed for $postId: bytes=$bytesWritten expected=" . strlen($imgData) . "\n";
                            }
                        } elseif ($isCli && $imgData === false) {
                            echo "  [warn] Thumbnail fetch failed for $postId: $fullPicture\n";
                        }
                    }
                    if ($downloaded) {
                        $localThumb = "u/blog/$thumbFile";
                    } else {
                        // Download failed — leave thumbnail null so next run retries
                        $postType = 'text';
                    }
                }

                // Clean title: remove pure-hashtag lines, but keep the original if nothing substantive remains
                $original = trim($message);
                $cleaned = preg_replace('/^[#\w\s.]+$/m', '', $original);
                $cleaned = trim($cleaned);
                $title = mb_strlen($cleaned) >= 10 ? $cleaned : $original;
                if (!$title) $title = 'Facebook Post';
                if (mb_strlen($title) > 300) $title = mb_substr($title, 0, 297) . '...';

                $createdTime = $post['created_time'] ?? null;

                // Upsert into yy_feed_item
                $stmt = $db->prepare("
                    INSERT INTO yy_feed_item (feed_key, feed_item_external_id, feed_item_title_import, feed_item_url, feed_item_thumbnail, feed_item_publish_import_dtime, feed_item_active_flag, feed_item_type, feed_item_tags, feed_item_embed_id)
                    VALUES (?, ?, ?, ?, ?, ?, TRUE, ?, ?, ?)
                    ON CONFLICT (feed_key, feed_item_external_id) DO UPDATE SET
                        feed_item_title_import = CASE WHEN EXCLUDED.feed_item_title_import = 'Facebook Post' THEN yy_feed_item.feed_item_title_import ELSE EXCLUDED.feed_item_title_import END,
                        feed_item_thumbnail = COALESCE(NULLIF(EXCLUDED.feed_item_thumbnail, ''), yy_feed_item.feed_item_thumbnail),
                        feed_item_publish_import_dtime = COALESCE(EXCLUDED.feed_item_publish_import_dtime, yy_feed_item.feed_item_publish_import_dtime),
                        feed_item_type = CASE WHEN EXCLUDED.feed_item_type IN ('photo','video') THEN EXCLUDED.feed_item_type ELSE yy_feed_item.feed_item_type END,
                        feed_item_tags = EXCLUDED.feed_item_tags,
                        feed_item_embed_id = COALESCE(EXCLUDED.feed_item_embed_id, yy_feed_item.feed_item_embed_id),
                        feed_item_revision_dtime = NOW()
                    WHERE yy_feed_item.feed_item_title_import IS DISTINCT FROM CASE WHEN EXCLUDED.feed_item_title_import = 'Facebook Post' THEN yy_feed_item.feed_item_title_import ELSE EXCLUDED.feed_item_title_import END
                       OR yy_feed_item.feed_item_thumbnail IS DISTINCT FROM COALESCE(NULLIF(EXCLUDED.feed_item_thumbnail, ''), yy_feed_item.feed_item_thumbnail)
                       OR yy_feed_item.feed_item_type IS DISTINCT FROM CASE WHEN EXCLUDED.feed_item_type IN ('photo','video') THEN EXCLUDED.feed_item_type ELSE yy_feed_item.feed_item_type END
                       OR yy_feed_item.feed_item_tags IS DISTINCT FROM EXCLUDED.feed_item_tags
                       OR yy_feed_item.feed_item_embed_id IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_embed_id, yy_feed_item.feed_item_embed_id)
                    RETURNING (xmax = 0) AS inserted
                ");
                // Determine the best URL for embedding
                // If we have a video/reel ID (embed_id) different from the post ID, use reel URL
                $isDirectId = strpos($postId, '_') === false;
                if ($isDirectId) {
                    $postUrl = 'https://www.facebook.com/reel/' . $postId;
                    if ($postType === 'photo' || $postType === 'text') $postType = 'video';
                } elseif ($videoId && $videoId !== $postId) {
                    // Post has an embedded reel/video — use the reel URL for proper embedding
                    $postUrl = 'https://www.facebook.com/reel/' . $videoId;
                } else {
                    $postUrl = 'https://www.facebook.com/' . $postId;
                }
                $stmt->execute([
                    $feedKey, $postId, $title,
                    $postUrl,
                    $localThumb, $createdTime, $postType, $tags, $videoId
                ]);
                $row = $stmt->fetch();
                if ($row) {
                    if ($row['inserted']) $totalInserted++;
                    else $totalUpdated++;
                }

                // Capture video duration via the Graph API `length` field
                // (seconds). The posts edge doesn't include it, so it needs a
                // per-video call — but only when we don't already have it, so
                // established items don't re-hit the API on every sync.
                if ($postType === 'video' && $videoId) {
                    // Re-fetch while duration is missing/zero OR currently
                    // flagged LIVE — so an in-progress broadcast flips to its
                    // real runtime once it ends.
                    $needDur = $db->prepare("SELECT 1 FROM yy_feed_item WHERE feed_key = ? AND feed_item_external_id = ? AND (feed_item_duration_seconds IS NULL OR feed_item_duration_seconds = 0 OR feed_item_duration = 'LIVE')");
                    $needDur->execute([$feedKey, $postId]);
                    if ($needDur->fetchColumn()) {
                        $lenResp = @file_get_contents("https://graph.facebook.com/v23.0/" . rawurlencode($videoId) . "?fields=length,live_status&access_token=" . urlencode($token));
                        if ($lenResp !== false) {
                            $lenData = json_decode($lenResp, true);
                            if (is_array($lenData)) {
                                $upd = $db->prepare("UPDATE yy_feed_item SET feed_item_duration = ?, feed_item_duration_seconds = ? WHERE feed_key = ? AND feed_item_external_id = ?");
                                if (($lenData['live_status'] ?? '') === 'LIVE') {
                                    // Currently broadcasting — show "LIVE" rather than 0:00.
                                    $upd->execute(['LIVE', 0, $feedKey, $postId]);
                                } elseif (isset($lenData['length']) && (float)$lenData['length'] > 0) {
                                    $secs = (int)round((float)$lenData['length']);
                                    $h = intdiv($secs, 3600); $mm = intdiv($secs % 3600, 60); $ss = $secs % 60;
                                    $durStr = $h > 0 ? sprintf('%d:%02d:%02d', $h, $mm, $ss) : sprintf('%d:%02d', $mm, $ss);
                                    $upd->execute([$durStr, $secs, $feedKey, $postId]);
                                }
                            }
                        }
                    }
                }
            }

            $url = $json['paging']['next'] ?? null;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    // Update sync log
    $status = $error ? 'error' : 'success';
    $db->prepare("UPDATE yy_feed_sync SET feed_sync_status = ?, feed_sync_items_found = ?, feed_sync_items_inserted = ?, feed_sync_items_updated = ?, feed_sync_error = ?, feed_sync_end_dtime = NOW() WHERE feed_sync_key = ?")
       ->execute([$status, $totalFound, $totalInserted, $totalUpdated, $error, $syncKey]);

    if ($error) {
        logMonitorEvent('sync_facebook', 'error',
            'Facebook sync failed for feed "' . $feed['feed_name'] . '": ' . $error,
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

// Sanity check: clear thumbnails from DB that don't exist on disk (prevents broken-image display)
$thumbDir = __DIR__ . '/../u/blog';
$brokenStmt = $db->query("
    SELECT feed_item_key, feed_item_thumbnail
    FROM yy_feed_item
    WHERE feed_item_thumbnail LIKE 'u/blog/%'
");
$cleared = 0;
foreach ($brokenStmt->fetchAll() as $row) {
    $relPath = $row['feed_item_thumbnail'];
    $absPath = __DIR__ . '/../' . $relPath;
    if (!file_exists($absPath) || filesize($absPath) < 1000) {
        $db->prepare("UPDATE yy_feed_item SET feed_item_thumbnail = NULL WHERE feed_item_key = ?")->execute([$row['feed_item_key']]);
        $cleared++;
    }
}
if ($cleared > 0 && $isCli) echo "Cleared {$cleared} thumbnail paths pointing to missing files\n";

// Update feed item → page associations after sync
require_once __DIR__ . '/feed-item-pages.php';
foreach ($feeds as $feed) {
    updateItemPagesForFeed($db, (int)$feed['feed_key']);
}

if (!$isCli) {
    jsonResponse(['synced' => true, 'results' => $results]);
}
