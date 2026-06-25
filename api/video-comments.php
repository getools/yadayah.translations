<?php
/**
 * Video comments API — facade over community topic/reply tables.
 * Each video gets an auto-created topic; comments are replies.
 *
 * GET ?sections                    — list page sections with comment counts
 * GET ?page_code=Y                — list videos with comments for a section
 * GET ?video_id=X&page_code=Y     — list comments for a video
 * POST action=add                 — add comment (creates/activates topic)
 * POST action=ensure_topics       — pre-create inactive topics for feed items
 * POST action=edit                — edit own comment
 * POST action=like                — toggle like
 * POST action=delete              — soft delete (author or mod)
 * POST action=restore             — restore (mod only)
 * POST action=ban / action=unban  — mod ban/unban user
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/community-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$userKey = $_SESSION['user_key'] ?? null;

function canonicalVideoId(string $source, string $videoId): string {
    if ($source === 'rumble' && strlen($videoId) > 1 && $videoId[0] === 'v') {
        return substr($videoId, 1);
    }
    return $videoId;
}

function buildVideoEmbedHtml(string $source, string $videoId, string $thumbnail, string $title): string {
    if ($thumbnail) {
        $imgSrc = (strpos($thumbnail, '/') === 0 || strpos($thumbnail, 'http') === 0) ? $thumbnail : '/' . $thumbnail;
        return '<div class="video-embed"><img src="' . htmlspecialchars($imgSrc) . '" alt="' . htmlspecialchars($title) . '" style="max-height:300px;width:auto;"></div>';
    }
    if ($source === 'youtube') {
        return '<div class="video-embed"><iframe width="560" height="315" src="https://www.youtube.com/embed/' . htmlspecialchars($videoId) . '" frameborder="0" allowfullscreen allow="autoplay; encrypted-media"></iframe></div>';
    }
    if ($source === 'rumble') {
        $embedId = $videoId;
        if (strpos($embedId, 'v') !== 0) $embedId = 'v' . $embedId;
        return '<div class="video-embed"><iframe width="560" height="315" src="https://rumble.com/embed/' . htmlspecialchars($embedId) . '/" frameborder="0" allowfullscreen></iframe></div>';
    }
    if ($source === 'facebook') {
        $fbUrl = urlencode('https://www.facebook.com/watch/?v=' . $videoId);
        return '<div class="video-embed"><iframe width="560" height="315" src="https://www.facebook.com/plugins/video.php?href=' . $fbUrl . '&show_text=false" frameborder="0" allowfullscreen></iframe></div>';
    }
    return '<p>' . htmlspecialchars($title ?: $videoId) . '</p>';
}

// ── GET ──
if ($method === 'GET') {

    // List sections with comment counts
    if (isset($_GET['sections'])) {
        $stmt = $db->query("
            SELECT t.page_code, p.page_title,
                   COUNT(*) AS video_count,
                   SUM(t.topic_reply_count) AS comment_count
            FROM yy_community_topic t
            LEFT JOIN yy_page p ON p.page_code = t.page_code
            WHERE t.video_id IS NOT NULL AND t.topic_active_flag = TRUE AND t.topic_delete_dtime IS NULL
            GROUP BY t.page_code, p.page_title
            ORDER BY comment_count DESC
        ");
        jsonResponse(['sections' => $stmt->fetchAll()]);
    }

    $pageCode = trim($_GET['page_code'] ?? '');
    $videoId = trim($_GET['video_id'] ?? '');
    $videoSourceHint = trim($_GET['video_source'] ?? '');
    if ($videoId && $videoSourceHint === 'rumble') {
        $videoId = canonicalVideoId('rumble', $videoId);
    }

    // List videos with comments for a section
    if ($pageCode && !$videoId) {
        $stmt = $db->prepare("
            SELECT t.video_id, t.video_source, t.page_code, t.video_title,
                   t.topic_reply_count AS comment_count,
                   (SELECT r.reply_dtime FROM yy_community_reply r WHERE r.topic_key = t.topic_key AND r.reply_active_flag = TRUE AND r.reply_delete_dtime IS NULL ORDER BY r.reply_dtime DESC LIMIT 1) AS last_comment_dtime,
                   (SELECT r.reply_body FROM yy_community_reply r WHERE r.topic_key = t.topic_key AND r.reply_active_flag = TRUE AND r.reply_delete_dtime IS NULL ORDER BY r.reply_dtime DESC LIMIT 1) AS last_comment,
                   (SELECT u.user_display_name FROM yy_community_reply r JOIN yy_user u ON r.user_key = u.user_key WHERE r.topic_key = t.topic_key AND r.reply_active_flag = TRUE AND r.reply_delete_dtime IS NULL ORDER BY r.reply_dtime DESC LIMIT 1) AS last_comment_user,
                   (SELECT u.user_avatar FROM yy_community_reply r JOIN yy_user u ON r.user_key = u.user_key WHERE r.topic_key = t.topic_key AND r.reply_active_flag = TRUE AND r.reply_delete_dtime IS NULL ORDER BY r.reply_dtime DESC LIMIT 1) AS last_comment_avatar
            FROM yy_community_topic t
            WHERE t.page_code = ? AND t.video_id IS NOT NULL AND t.topic_active_flag = TRUE AND t.topic_delete_dtime IS NULL AND t.topic_reply_count > 0
            ORDER BY last_comment_dtime DESC
        ");
        $stmt->execute([$pageCode]);
        jsonResponse(['videos' => $stmt->fetchAll()]);
    }

    // List comments for a specific video
    if ($videoId) {
        // Find topic
        $where = "t.video_id = ?";
        $params = [$videoId];
        if ($pageCode) { $where .= " AND t.page_code = ?"; $params[] = $pageCode; }

        $topicStmt = $db->prepare("SELECT topic_key FROM yy_community_topic t WHERE $where LIMIT 1");
        $topicStmt->execute($params);
        $topicKey = $topicStmt->fetchColumn();

        if (!$topicKey) {
            jsonResponse(['comments' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'user_likes' => [], 'is_mod' => false]);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $isMod = $userKey ? isModOrAdmin($db, $userKey) : false;
        $replyWhere = $isMod
            ? "r.topic_key = ? AND r.reply_active_flag = TRUE"
            : "r.topic_key = ? AND r.reply_active_flag = TRUE AND r.reply_delete_dtime IS NULL";

        $countStmt = $db->prepare("SELECT COUNT(*) FROM yy_community_reply r WHERE $replyWhere");
        $countStmt->execute([$topicKey]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT r.reply_key AS comment_key, r.user_key,
                   r.reply_body AS comment_body, r.reply_body_html AS comment_body_html,
                   r.reply_like_count AS comment_like_count,
                   r.reply_dtime AS comment_dtime,
                   r.reply_edit_dtime AS comment_edit_dtime,
                   r.reply_delete_dtime AS comment_delete_dtime,
                   r.reply_delete_note AS comment_delete_note,
                   u.user_display_name, u.user_avatar, u.user_handle
            FROM yy_community_reply r
            LEFT JOIN yy_user u ON r.user_key = u.user_key
            WHERE $replyWhere
            ORDER BY r.reply_dtime ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$topicKey, $limit, $offset]);
        $comments = $stmt->fetchAll();

        // User likes
        $userLikes = [];
        if ($userKey && $comments) {
            $keys = array_column($comments, 'comment_key');
            $in = implode(',', array_map('intval', $keys));
            $likeStmt = $db->prepare("SELECT target_key FROM yy_community_like WHERE user_key = ? AND target_type = 'reply' AND target_key IN ({$in})");
            $likeStmt->execute([$userKey]);
            $userLikes = $likeStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        jsonResponse([
            'comments' => $comments,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'user_likes' => $userLikes,
            'is_mod' => $isMod,
        ]);
    }

    errorResponse('video_id or page_code required');
}

// ── POST ──
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? '';

    // ensure_topics does not require login
    if ($action === 'ensure_topics') {
        $items = $data['items'] ?? [];
        $pageCode = trim($data['page_code'] ?? '');
        $videoSource = trim($data['video_source'] ?? 'youtube');
        if (!$pageCode || !$items) jsonResponse(['created' => 0]);

        $catSlug = 'comment-' . $pageCode;
        $catStmt = $db->prepare("SELECT category_key FROM yy_community_category WHERE category_slug = ? LIMIT 1");
        $catStmt->execute([$catSlug]);
        $categoryKey = $catStmt->fetchColumn() ?: null;

        $checkStmt = $db->prepare("SELECT topic_key FROM yy_community_topic WHERE video_id = ? AND page_code = ?");
        $insStmt = $db->prepare("INSERT INTO yy_community_topic (topic_title, topic_body, topic_body_html, video_id, video_source, page_code, video_title, category_key, topic_thumbnail, topic_active_flag, user_key) VALUES (?, '', ?, ?, ?, ?, NULLIF(?, ''), ?, ?, FALSE, NULL)");

        $created = 0;
        foreach ($items as $item) {
            $vid = trim($item['video_id'] ?? '');
            if (!$vid) continue;
            $vid = canonicalVideoId($videoSource, $vid);
            $checkStmt->execute([$vid, $pageCode]);
            if ($checkStmt->fetchColumn()) continue;

            $title = trim($item['title'] ?? '') ?: $vid;
            $thumb = trim($item['thumbnail'] ?? '');
            $bodyHtml = buildVideoEmbedHtml($videoSource, $vid, $thumb, $title);

            $insStmt->execute([$title, $bodyHtml, $vid, $videoSource, $pageCode, $title, $categoryKey, $thumb ?: null]);
            $created++;
        }
        jsonResponse(['created' => $created]);
    }

    if (!$userKey) errorResponse('Login required', 401);

    if ($action === 'add') {
        $videoId = trim($data['video_id'] ?? '');
        $videoSource = trim($data['video_source'] ?? 'youtube');
        $pageCode = trim($data['page_code'] ?? '');
        $videoTitle = trim($data['video_title'] ?? '');
        $body = trim($data['body'] ?? '');
        $bodyHtml = isset($data['body_html']) ? sanitizeHtml($data['body_html']) : null;

        $thumbnail = trim($data['thumbnail'] ?? '');

        if (!$videoId) errorResponse('video_id required');
        if (!$pageCode) errorResponse('page_code required');
        $videoId = canonicalVideoId($videoSource, $videoId);
        if (!$body && !$bodyHtml) errorResponse('Comment body required');

        checkBanned($db, $userKey);
        checkWordFilter($db, $body . ' ' . ($bodyHtml ?? ''));

        // Find or create topic for this video
        $topicStmt = $db->prepare("SELECT topic_key, topic_active_flag FROM yy_community_topic WHERE video_id = ? AND page_code = ?");
        $topicStmt->execute([$videoId, $pageCode]);
        $topicRow = $topicStmt->fetch();
        $topicKey = $topicRow ? (int)$topicRow['topic_key'] : null;

        if (!$topicKey) {
            $title = $videoTitle ?: $videoId;
            $topicBodyHtml = buildVideoEmbedHtml($videoSource, $videoId, $thumbnail, $videoTitle);

            $catSlug = 'comment-' . $pageCode;
            $catStmt = $db->prepare("SELECT category_key FROM yy_community_category WHERE category_slug = ? LIMIT 1");
            $catStmt->execute([$catSlug]);
            $categoryKey = $catStmt->fetchColumn() ?: null;

            $thumbStore = $thumbnail ?: null;
            $ins = $db->prepare("INSERT INTO yy_community_topic (topic_title, topic_body, topic_body_html, video_id, video_source, page_code, video_title, user_key, category_key, topic_thumbnail, topic_active_flag) VALUES (?, '', ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, TRUE) RETURNING topic_key");
            $ins->execute([$title, $topicBodyHtml, $videoId, $videoSource, $pageCode, $videoTitle, $userKey, $categoryKey, $thumbStore]);
            $topicKey = $ins->fetchColumn();
        } else {
            // Activate topic if it was inactive
            $db->prepare("UPDATE yy_community_topic SET topic_active_flag = TRUE WHERE topic_key = ? AND topic_active_flag = FALSE")->execute([$topicKey]);
        }

        // Insert reply
        $stmt = $db->prepare("INSERT INTO yy_community_reply (topic_key, user_key, reply_body, reply_body_html) VALUES (?, ?, ?, ?) RETURNING reply_key");
        $stmt->execute([$topicKey, $userKey, $body, $bodyHtml]);
        $replyKey = $stmt->fetchColumn();

        // Update topic counters
        $db->prepare("UPDATE yy_community_topic SET topic_reply_count = topic_reply_count + 1, topic_last_reply_dtime = NOW() WHERE topic_key = ?")->execute([$topicKey]);

        jsonResponse(['saved' => true, 'comment_key' => $replyKey]);
    }

    if ($action === 'edit') {
        checkBanned($db, $userKey); // banned users can't edit comments either
        $commentKey = (int)($data['comment_key'] ?? 0);
        $body = trim($data['body'] ?? '');
        $bodyHtml = isset($data['body_html']) ? sanitizeHtml($data['body_html']) : null;
        if (!$commentKey) errorResponse('comment_key required');
        if (!$body && !$bodyHtml) errorResponse('Comment body required');

        $stmt = $db->prepare("SELECT user_key FROM yy_community_reply WHERE reply_key = ?");
        $stmt->execute([$commentKey]);
        $authorKey = (int)$stmt->fetchColumn();
        if ($authorKey !== $userKey) errorResponse('Only the author can edit', 403);

        checkWordFilter($db, $body . ' ' . ($bodyHtml ?? ''));

        $db->prepare("UPDATE yy_community_reply SET reply_body = ?, reply_body_html = ?, reply_edit_dtime = NOW() WHERE reply_key = ?")
           ->execute([$body, $bodyHtml, $commentKey]);
        jsonResponse(['saved' => true]);
    }

    if ($action === 'like') {
        $commentKey = (int)($data['comment_key'] ?? 0);
        if (!$commentKey) errorResponse('comment_key required');

        $existing = $db->prepare("SELECT like_key FROM yy_community_like WHERE target_type = 'reply' AND target_key = ? AND user_key = ?");
        $existing->execute([$commentKey, $userKey]);

        if ($existing->fetchColumn()) {
            $db->prepare("DELETE FROM yy_community_like WHERE target_type = 'reply' AND target_key = ? AND user_key = ?")->execute([$commentKey, $userKey]);
            $db->prepare("UPDATE yy_community_reply SET reply_like_count = GREATEST(0, reply_like_count - 1) WHERE reply_key = ?")->execute([$commentKey]);
            $liked = false;
        } else {
            $db->prepare("INSERT INTO yy_community_like (target_type, target_key, user_key) VALUES ('reply', ?, ?)")->execute([$commentKey, $userKey]);
            $db->prepare("UPDATE yy_community_reply SET reply_like_count = reply_like_count + 1 WHERE reply_key = ?")->execute([$commentKey]);
            $liked = true;
        }
        $cnt = $db->prepare("SELECT reply_like_count FROM yy_community_reply WHERE reply_key = ?");
        $cnt->execute([$commentKey]);
        jsonResponse(['liked' => $liked, 'count' => (int)$cnt->fetchColumn()]);
    }

    if ($action === 'delete') {
        $commentKey = (int)($data['comment_key'] ?? 0);
        $note = trim($data['note'] ?? '');
        if (!$commentKey) errorResponse('comment_key required');

        $isMod = isModOrAdmin($db, $userKey);
        $stmt = $db->prepare("SELECT user_key, topic_key FROM yy_community_reply WHERE reply_key = ?");
        $stmt->execute([$commentKey]);
        $row = $stmt->fetch();
        if (!$row) errorResponse('Comment not found', 404);
        if (!$isMod && (int)$row['user_key'] !== $userKey) errorResponse('Not authorized', 403);

        $db->prepare("UPDATE yy_community_reply SET reply_delete_dtime = NOW(), reply_delete_note = NULLIF(?, '') WHERE reply_key = ?")
           ->execute([$note, $commentKey]);
        $db->prepare("UPDATE yy_community_topic SET topic_reply_count = GREATEST(0, topic_reply_count - 1) WHERE topic_key = ?")->execute([$row['topic_key']]);

        // Deactivate topic if no visible comments remain (only for video/comment topics)
        $activeReplies = $db->prepare("SELECT COUNT(*) FROM yy_community_reply WHERE topic_key = ? AND reply_active_flag = TRUE AND reply_delete_dtime IS NULL");
        $activeReplies->execute([$row['topic_key']]);
        if ((int)$activeReplies->fetchColumn() === 0) {
            $db->prepare("UPDATE yy_community_topic SET topic_active_flag = FALSE WHERE topic_key = ? AND video_id IS NOT NULL")->execute([$row['topic_key']]);
        }

        jsonResponse(['deleted' => true]);
    }

    if ($action === 'restore') {
        $commentKey = (int)($data['comment_key'] ?? 0);
        if (!$commentKey) errorResponse('comment_key required');
        if (!isModOrAdmin($db, $userKey)) errorResponse('Not authorized', 403);

        $stmt = $db->prepare("SELECT topic_key FROM yy_community_reply WHERE reply_key = ?");
        $stmt->execute([$commentKey]);
        $topicKey = $stmt->fetchColumn();

        $db->prepare("UPDATE yy_community_reply SET reply_delete_dtime = NULL, reply_delete_note = NULL WHERE reply_key = ?")
           ->execute([$commentKey]);
        if ($topicKey) {
            $db->prepare("UPDATE yy_community_topic SET topic_reply_count = topic_reply_count + 1, topic_active_flag = TRUE, topic_last_reply_dtime = NOW() WHERE topic_key = ?")->execute([$topicKey]);
        }
        jsonResponse(['restored' => true]);
    }

    if ($action === 'ban') {
        $targetUserKey = (int)($data['user_key'] ?? 0);
        $reason = trim($data['reason'] ?? '');
        if (!$targetUserKey) errorResponse('user_key required');
        if (!isModOrAdmin($db, $userKey)) errorResponse('Not authorized', 403);
        $db->prepare("UPDATE yy_user SET user_banned_flag = TRUE, user_ban_reason = NULLIF(?, '') WHERE user_key = ?")
           ->execute([$reason, $targetUserKey]);
        jsonResponse(['banned' => true]);
    }

    if ($action === 'unban') {
        $targetUserKey = (int)($data['user_key'] ?? 0);
        if (!$targetUserKey) errorResponse('user_key required');
        if (!isModOrAdmin($db, $userKey)) errorResponse('Not authorized', 403);
        $db->prepare("UPDATE yy_user SET user_banned_flag = FALSE, user_ban_reason = NULL WHERE user_key = ?")
           ->execute([$targetUserKey]);
        jsonResponse(['unbanned' => true]);
    }

    errorResponse('Unknown action');
}

errorResponse('Method not allowed', 405);
