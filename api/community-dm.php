<?php
/**
 * Community direct messages API.
 * Uses yy_community_dm_thread, yy_community_dm_participant, yy_community_dm_message.
 *
 * GET (no params or ?threads): list threads for current user.
 * GET ?thread=KEY: list messages in a thread.
 * POST action=new_thread: start 1:1 thread with a user.
 * POST action=create_group: create a group thread with multiple users.
 * POST action=send: send message to existing thread.
 * POST action=rename_group: rename a group (any participant).
 * POST action=add_member: add a member to a group (any participant).
 * POST action=remove_member: remove a member from a group (any participant).
 * POST action=leave_group: leave a group.
 * POST action=get_members: get group member list.
 * POST action=set_chime: set chime_pitch for a thread (0-7).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/community-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['thread'])) {
        $threadKey = (int)$_GET['thread'];

        // Verify user is participant
        $stmt = $db->prepare("SELECT 1 FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?");
        $stmt->execute([$threadKey, $userKey]);
        if (!$stmt->fetchColumn()) errorResponse('Thread not found', 404);

        // Update last read
        $db->prepare("UPDATE yy_community_dm_participant SET last_read_dtime = NOW() WHERE thread_key = ? AND user_key = ?")
           ->execute([$threadKey, $userKey]);

        // Get messages
        $stmt = $db->prepare("
            SELECT m.message_key, m.user_key, m.message_body, m.message_body_html, m.message_dtime,
                   m.message_revision_num, m.message_revision_dtime,
                   u.user_name_display, u.user_avatar
            FROM yy_community_dm_message m
            LEFT JOIN yy_user u ON m.user_key = u.user_key
            WHERE m.thread_key = ? AND m.message_active_flag = TRUE
            ORDER BY m.message_dtime ASC
        ");
        $stmt->execute([$threadKey]);
        $messages = $stmt->fetchAll();

        // Get thread info
        $threadStmt = $db->prepare("SELECT thread_name, thread_is_group, thread_creator_key FROM yy_community_dm_thread WHERE thread_key = ?");
        $threadStmt->execute([$threadKey]);
        $threadInfo = $threadStmt->fetch();

        // Get all participants (for groups) or other user (for 1:1)
        $stmt = $db->prepare("
            SELECT u.user_key, u.user_name_display, u.user_avatar, u.user_last_active_dtime
            FROM yy_community_dm_participant p
            JOIN yy_user u ON p.user_key = u.user_key
            WHERE p.thread_key = ?
        ");
        $stmt->execute([$threadKey]);
        $allParticipants = $stmt->fetchAll();

        $otherUser = null;
        $members = [];
        foreach ($allParticipants as $p) {
            $members[] = $p;
            if ($p['user_key'] != $userKey && !$otherUser) {
                $otherUser = $p;
            }
        }

        $thread = [
            'thread_key' => $threadKey,
            'thread_name' => $threadInfo['thread_name'],
            'thread_is_group' => (bool)$threadInfo['thread_is_group'],
            'thread_creator_key' => $threadInfo['thread_creator_key'],
            'other_user' => $otherUser,
            'members' => $members,
        ];

        jsonResponse([
            'messages' => $messages,
            'thread' => $thread,
        ]);
    }

    // List threads (default)
    // Get all threads where user is a participant
    $stmt = $db->prepare("
        SELECT t.thread_key, t.last_message_dtime, t.thread_name, t.thread_is_group, t.thread_creator_key,
               my_p.chime_pitch,
               last_msg.message_body AS last_message,
               (SELECT COUNT(*) FROM yy_community_dm_message m2
                WHERE m2.thread_key = t.thread_key AND m2.user_key != ?
                AND m2.message_dtime > COALESCE(my_p.last_read_dtime, '1970-01-01')
                AND m2.message_active_flag = TRUE) AS unread_count
        FROM yy_community_dm_thread t
        JOIN yy_community_dm_participant my_p ON t.thread_key = my_p.thread_key AND my_p.user_key = ?
        LEFT JOIN LATERAL (
            SELECT message_body FROM yy_community_dm_message
            WHERE thread_key = t.thread_key AND message_active_flag = TRUE
            ORDER BY message_dtime DESC LIMIT 1
        ) last_msg ON TRUE
        WHERE t.last_message_dtime IS NOT NULL
        ORDER BY t.last_message_dtime DESC
    ");
    $stmt->execute([$userKey, $userKey]);
    $threads = $stmt->fetchAll();

    // For each thread, get the other participants
    $result = [];
    foreach ($threads as $t) {
        $pStmt = $db->prepare("
            SELECT u.user_key, u.user_name_display, u.user_avatar, u.user_last_active_dtime
            FROM yy_community_dm_participant p
            JOIN yy_user u ON p.user_key = u.user_key
            WHERE p.thread_key = ? AND p.user_key != ?
        ");
        $pStmt->execute([$t['thread_key'], $userKey]);
        $others = $pStmt->fetchAll();

        $entry = [
            'thread_key' => $t['thread_key'],
            'last_message_dtime' => $t['last_message_dtime'],
            'last_message' => $t['last_message'],
            'unread_count' => (int)$t['unread_count'],
            'thread_is_group' => (bool)$t['thread_is_group'],
            'thread_name' => $t['thread_name'],
            'thread_creator_key' => $t['thread_creator_key'],
            'chime_pitch' => (int)($t['chime_pitch'] ?? 0),
        ];

        if ($t['thread_is_group']) {
            $entry['members'] = $others;
            // Use group name or fallback to member names
            $entry['other_user'] = [
                'user_key' => null,
                'user_name_display' => $t['thread_name'] ?: implode(', ', array_map(function($o) { return $o['user_name_display']; }, $others)),
                'user_avatar' => null,
                'user_last_active_dtime' => null,
            ];
        } else {
            $other = $others[0] ?? null;
            $entry['other_user'] = $other ? [
                'user_key' => $other['user_key'],
                'user_name_display' => $other['user_name_display'],
                'user_avatar' => $other['user_avatar'],
                'user_last_active_dtime' => $other['user_last_active_dtime'],
            ] : null;
        }

        $result[] = $entry;
    }

    jsonResponse(['threads' => $result]);
}

if ($method === 'POST') {
    denyIfBanned('Chat'); // banned users may read existing DMs but can't send/create
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? '';

    // ── Create 1:1 thread ──
    if ($action === 'new_thread') {
        $recipientKey = (int)($data['recipient_key'] ?? 0);
        $body = trim($data['body'] ?? '');
        $bodyHtml = trim($data['message_body_html'] ?? '');
        if (!$recipientKey) errorResponse('recipient_key is required');
        if (!$body) errorResponse('Message cannot be empty');
        if ($recipientKey === $userKey) errorResponse('Cannot message yourself');

        // Check recipient exists
        $stmt = $db->prepare("SELECT 1 FROM yy_user WHERE user_key = ? AND user_active_flag = TRUE");
        $stmt->execute([$recipientKey]);
        if (!$stmt->fetchColumn()) errorResponse('Recipient not found', 404);

        // Check if 1:1 thread already exists between these two users (not group threads)
        $stmt = $db->prepare("
            SELECT p1.thread_key FROM yy_community_dm_participant p1
            JOIN yy_community_dm_participant p2 ON p1.thread_key = p2.thread_key
            JOIN yy_community_dm_thread t ON t.thread_key = p1.thread_key
            WHERE p1.user_key = ? AND p2.user_key = ? AND t.thread_is_group = FALSE
            LIMIT 1
        ");
        $stmt->execute([$userKey, $recipientKey]);
        $existingThread = $stmt->fetchColumn();

        if ($existingThread) {
            $threadKey = (int)$existingThread;
        } else {
            // Create new thread
            $stmt = $db->prepare("INSERT INTO yy_community_dm_thread (thread_is_group, thread_creator_key) VALUES (FALSE, ?) RETURNING thread_key");
            $stmt->execute([$userKey]);
            $threadKey = (int)$stmt->fetchColumn();

            // Add participants
            $db->prepare("INSERT INTO yy_community_dm_participant (thread_key, user_key) VALUES (?, ?)")->execute([$threadKey, $userKey]);
            $db->prepare("INSERT INTO yy_community_dm_participant (thread_key, user_key) VALUES (?, ?)")->execute([$threadKey, $recipientKey]);
        }

        // Move any temp uploads to the new thread directory
        $tmpDir = __DIR__ . '/../u/messages/tmp_' . $userKey;
        $threadDir = __DIR__ . '/../u/messages/' . $threadKey;
        if (is_dir($tmpDir)) {
            if (!is_dir($threadDir)) mkdir($threadDir, 0755, true);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                $target = $threadDir . '/' . $iterator->getSubPathname();
                if ($item->isDir()) {
                    if (!is_dir($target)) mkdir($target, 0755, true);
                } else {
                    rename($item->getPathname(), $target);
                }
            }
            @array_map('rmdir', array_reverse(glob($tmpDir . '/*', GLOB_ONLYDIR) ?: []));
            @rmdir($tmpDir);

            $body = str_replace('/u/messages/tmp_' . $userKey . '/', '/u/messages/' . $threadKey . '/', $body);
            $bodyHtml = str_replace('/u/messages/tmp_' . $userKey . '/', '/u/messages/' . $threadKey . '/', $bodyHtml);
        }

        // Insert message
        $stmt = $db->prepare("INSERT INTO yy_community_dm_message (thread_key, user_key, message_body, message_body_html) VALUES (?, ?, ?, ?) RETURNING message_key");
        $stmt->execute([$threadKey, $userKey, $body, $bodyHtml ?: null]);

        // Update thread last message time
        $db->prepare("UPDATE yy_community_dm_thread SET last_message_dtime = NOW() WHERE thread_key = ?")->execute([$threadKey]);
        $db->prepare("UPDATE yy_community_dm_participant SET last_read_dtime = NOW() WHERE thread_key = ? AND user_key = ?")->execute([$threadKey, $userKey]);

        notifyUser($db, $recipientKey, $userKey, 'dm', 'dm', $threadKey, null, 'sent you a message');

        jsonResponse(['success' => true, 'thread_key' => $threadKey]);
    }

    // ── Create group thread ──
    if ($action === 'create_group') {
        $memberKeys = $data['member_keys'] ?? [];
        $groupName = trim($data['group_name'] ?? '');
        $body = trim($data['body'] ?? '');
        $bodyHtml = trim($data['message_body_html'] ?? '');

        if (!is_array($memberKeys) || count($memberKeys) < 1) {
            errorResponse('At least one other member is required');
        }
        if (count($memberKeys) > 50) errorResponse('Maximum 50 members per group');

        // Filter out self and validate members
        $memberKeys = array_unique(array_map('intval', $memberKeys));
        $memberKeys = array_filter($memberKeys, function($k) use ($userKey) { return $k !== $userKey && $k > 0; });
        if (empty($memberKeys)) errorResponse('At least one other member is required');

        $placeholders = implode(',', array_fill(0, count($memberKeys), '?'));
        $stmt = $db->prepare("SELECT user_key FROM yy_user WHERE user_key IN ($placeholders) AND user_active_flag = TRUE");
        $stmt->execute(array_values($memberKeys));
        $validKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($validKeys)) errorResponse('No valid members found');

        // Create group thread
        $stmt = $db->prepare("INSERT INTO yy_community_dm_thread (thread_is_group, thread_creator_key, thread_name) VALUES (TRUE, ?, ?) RETURNING thread_key");
        $stmt->execute([$userKey, $groupName ?: null]);
        $threadKey = (int)$stmt->fetchColumn();

        // Add creator as participant
        $db->prepare("INSERT INTO yy_community_dm_participant (thread_key, user_key) VALUES (?, ?)")->execute([$threadKey, $userKey]);

        // Add members
        foreach ($validKeys as $mk) {
            $db->prepare("INSERT INTO yy_community_dm_participant (thread_key, user_key) VALUES (?, ?)")->execute([$threadKey, (int)$mk]);
        }

        // Move temp uploads
        $tmpDir = __DIR__ . '/../u/messages/tmp_' . $userKey;
        $threadDir = __DIR__ . '/../u/messages/' . $threadKey;
        if (is_dir($tmpDir)) {
            if (!is_dir($threadDir)) mkdir($threadDir, 0755, true);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                $target = $threadDir . '/' . $iterator->getSubPathname();
                if ($item->isDir()) {
                    if (!is_dir($target)) mkdir($target, 0755, true);
                } else {
                    rename($item->getPathname(), $target);
                }
            }
            @array_map('rmdir', array_reverse(glob($tmpDir . '/*', GLOB_ONLYDIR) ?: []));
            @rmdir($tmpDir);

            $body = str_replace('/u/messages/tmp_' . $userKey . '/', '/u/messages/' . $threadKey . '/', $body);
            $bodyHtml = str_replace('/u/messages/tmp_' . $userKey . '/', '/u/messages/' . $threadKey . '/', $bodyHtml);
        }

        // Insert initial message if provided
        if ($body) {
            $stmt = $db->prepare("INSERT INTO yy_community_dm_message (thread_key, user_key, message_body, message_body_html) VALUES (?, ?, ?, ?)");
            $stmt->execute([$threadKey, $userKey, $body, $bodyHtml ?: null]);
            $db->prepare("UPDATE yy_community_dm_thread SET last_message_dtime = NOW() WHERE thread_key = ?")->execute([$threadKey]);
            $db->prepare("UPDATE yy_community_dm_participant SET last_read_dtime = NOW() WHERE thread_key = ? AND user_key = ?")->execute([$threadKey, $userKey]);
        }

        // Notify all members
        foreach ($validKeys as $mk) {
            notifyUser($db, (int)$mk, $userKey, 'dm', 'dm', $threadKey, null, 'added you to a group');
        }

        jsonResponse(['success' => true, 'thread_key' => $threadKey]);
    }

    // ── Send message to existing thread ──
    if ($action === 'send') {
        $threadKey = (int)($data['thread_key'] ?? 0);
        $body = trim($data['body'] ?? '');
        $bodyHtml = trim($data['message_body_html'] ?? '');
        if (!$threadKey) errorResponse('thread_key is required');
        if (!$body) errorResponse('Message cannot be empty');

        // Verify user is participant
        $stmt = $db->prepare("SELECT 1 FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?");
        $stmt->execute([$threadKey, $userKey]);
        if (!$stmt->fetchColumn()) errorResponse('Thread not found', 404);

        // Insert message
        $stmt = $db->prepare("INSERT INTO yy_community_dm_message (thread_key, user_key, message_body, message_body_html) VALUES (?, ?, ?, ?) RETURNING message_key");
        $stmt->execute([$threadKey, $userKey, $body, $bodyHtml ?: null]);
        $messageKey = $stmt->fetchColumn();

        // Update thread
        $db->prepare("UPDATE yy_community_dm_thread SET last_message_dtime = NOW() WHERE thread_key = ?")->execute([$threadKey]);
        $db->prepare("UPDATE yy_community_dm_participant SET last_read_dtime = NOW() WHERE thread_key = ? AND user_key = ?")->execute([$threadKey, $userKey]);

        // Notify other participants
        $stmt = $db->prepare("SELECT user_key FROM yy_community_dm_participant WHERE thread_key = ? AND user_key != ?");
        $stmt->execute([$threadKey, $userKey]);
        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $senderStmt = $db->prepare("SELECT user_display_name FROM yy_user WHERE user_key = ?");
        $senderStmt->execute([$userKey]);
        $senderName = $senderStmt->fetchColumn() ?: 'Someone';

        // Respond immediately
        $responseJson = json_encode(['success' => true, 'message_key' => $messageKey]);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($responseJson));
        echo $responseJson;
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else { ob_end_flush(); flush(); }

        // Load DM email template
        $tplStmt = $db->query("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'config' AND setting_group_code = 'comments' AND setting_code IN ('notify-dm-subject','notify-dm-body')");
        $tplCfg = [];
        foreach ($tplStmt->fetchAll() as $r) $tplCfg[$r['setting_code']] = $r['setting_value'];
        $tplSubject = $tplCfg['notify-dm-subject'] ?? 'New message from {{sender_name}}';
        $tplBody = $tplCfg['notify-dm-body'] ?? '<h2 style="color:#31345A;">New Message</h2><p><strong>{{sender_name}}</strong> sent you a private message:</p><blockquote style="border-left:3px solid #31345A;padding:8px 12px;margin:12px 0;color:#333;">{{message_body}}</blockquote><p><a href="{{message_url}}" style="color:#31345A;font-weight:600;">View Message</a></p>';

        $mergeBase = [
            '{{sender_name}}' => htmlspecialchars($senderName),
            '{{message_body}}' => htmlspecialchars(mb_substr($body, 0, 500)),
            '{{message_url}}' => 'https://yadayah.com/chat#message/' . $threadKey,
            '{{message_time}}' => date('M j, Y g:i A'),
        ];

        require_once __DIR__ . '/send-mail.php';
        foreach ($recipients as $recipientKey) {
            notifyUser($db, (int)$recipientKey, $userKey, 'dm', 'dm', $threadKey, null, 'sent you a message');

            $rcptStmt = $db->prepare("SELECT user_display_name FROM yy_user WHERE user_key = ?");
            $rcptStmt->execute([(int)$recipientKey]);
            $rcptName = $rcptStmt->fetchColumn() ?: 'User';

            $fields = array_merge($mergeBase, ['{{recipient_name}}' => htmlspecialchars($rcptName)]);
            $emailSubject = str_replace(array_keys($fields), array_values($fields), $tplSubject);
            $emailBody = str_replace(array_keys($fields), array_values($fields), $tplBody);
            sendNotificationEmail($db, (int)$recipientKey, $emailSubject, $emailBody);
        }
        exit;
    }

    // ── Rename group ──
    if ($action === 'rename_group') {
        $threadKey = (int)($data['thread_key'] ?? 0);
        $newName = trim($data['group_name'] ?? '');
        if (!$threadKey) errorResponse('thread_key is required');
        if (!$newName) errorResponse('group_name is required');
        if (mb_strlen($newName) > 100) errorResponse('Group name must be under 100 characters');

        // Verify participant
        $stmt = $db->prepare("SELECT thread_is_group FROM yy_community_dm_thread WHERE thread_key = ?");
        $stmt->execute([$threadKey]);
        $thread = $stmt->fetch();
        if (!$thread) errorResponse('Thread not found', 404);
        if (!$thread['thread_is_group']) errorResponse('Not a group thread');

        $stmt = $db->prepare("SELECT 1 FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?");
        $stmt->execute([$threadKey, $userKey]);
        if (!$stmt->fetchColumn()) errorResponse('You are not in this group');

        $db->prepare("UPDATE yy_community_dm_thread SET thread_name = ? WHERE thread_key = ?")->execute([$newName, $threadKey]);
        jsonResponse(['success' => true]);
    }

    // ── Add member to group ──
    if ($action === 'add_member') {
        $threadKey = (int)($data['thread_key'] ?? 0);
        $newMemberKey = (int)($data['user_key'] ?? 0);
        if (!$threadKey || !$newMemberKey) errorResponse('thread_key and user_key are required');

        // Verify group and participant
        $stmt = $db->prepare("SELECT thread_is_group FROM yy_community_dm_thread WHERE thread_key = ?");
        $stmt->execute([$threadKey]);
        $thread = $stmt->fetch();
        if (!$thread || !$thread['thread_is_group']) errorResponse('Not a group thread');

        $stmt = $db->prepare("SELECT 1 FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?");
        $stmt->execute([$threadKey, $userKey]);
        if (!$stmt->fetchColumn()) errorResponse('You are not in this group');

        // Check member exists
        $stmt = $db->prepare("SELECT 1 FROM yy_user WHERE user_key = ? AND user_active_flag = TRUE");
        $stmt->execute([$newMemberKey]);
        if (!$stmt->fetchColumn()) errorResponse('User not found', 404);

        // Check not already a member
        $stmt = $db->prepare("SELECT 1 FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?");
        $stmt->execute([$threadKey, $newMemberKey]);
        if ($stmt->fetchColumn()) errorResponse('User is already in this group');

        // Check member limit
        $stmt = $db->prepare("SELECT COUNT(*) FROM yy_community_dm_participant WHERE thread_key = ?");
        $stmt->execute([$threadKey]);
        if ($stmt->fetchColumn() >= 50) errorResponse('Group has reached maximum of 50 members');

        $db->prepare("INSERT INTO yy_community_dm_participant (thread_key, user_key) VALUES (?, ?)")->execute([$threadKey, $newMemberKey]);

        // System message
        $senderStmt = $db->prepare("SELECT user_name_display FROM yy_user WHERE user_key = ?");
        $senderStmt->execute([$userKey]);
        $senderName = $senderStmt->fetchColumn() ?: 'Someone';
        $newStmt = $db->prepare("SELECT user_name_display FROM yy_user WHERE user_key = ?");
        $newStmt->execute([$newMemberKey]);
        $newName = $newStmt->fetchColumn() ?: 'Someone';

        $sysMsg = $senderName . ' added ' . $newName . ' to the group';
        $db->prepare("INSERT INTO yy_community_dm_message (thread_key, user_key, message_body) VALUES (?, NULL, ?)")
           ->execute([$threadKey, $sysMsg]);
        $db->prepare("UPDATE yy_community_dm_thread SET last_message_dtime = NOW() WHERE thread_key = ?")->execute([$threadKey]);

        notifyUser($db, $newMemberKey, $userKey, 'dm', 'dm', $threadKey, null, 'added you to a group');

        jsonResponse(['success' => true]);
    }

    // ── Remove member from group ──
    if ($action === 'remove_member') {
        $threadKey = (int)($data['thread_key'] ?? 0);
        $removeMemberKey = (int)($data['user_key'] ?? 0);
        if (!$threadKey || !$removeMemberKey) errorResponse('thread_key and user_key are required');

        // Verify group and participant
        $stmt = $db->prepare("SELECT thread_is_group, thread_creator_key FROM yy_community_dm_thread WHERE thread_key = ?");
        $stmt->execute([$threadKey]);
        $thread = $stmt->fetch();
        if (!$thread || !$thread['thread_is_group']) errorResponse('Not a group thread');

        $stmt = $db->prepare("SELECT 1 FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?");
        $stmt->execute([$threadKey, $userKey]);
        if (!$stmt->fetchColumn()) errorResponse('You are not in this group');

        // Cannot remove the creator
        if ($removeMemberKey === (int)$thread['thread_creator_key']) {
            errorResponse('Cannot remove the group creator');
        }

        // Remove
        $db->prepare("DELETE FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?")->execute([$threadKey, $removeMemberKey]);

        // System message
        $senderStmt = $db->prepare("SELECT user_name_display FROM yy_user WHERE user_key = ?");
        $senderStmt->execute([$userKey]);
        $senderName = $senderStmt->fetchColumn() ?: 'Someone';
        $removedStmt = $db->prepare("SELECT user_name_display FROM yy_user WHERE user_key = ?");
        $removedStmt->execute([$removeMemberKey]);
        $removedName = $removedStmt->fetchColumn() ?: 'Someone';

        $sysMsg = $senderName . ' removed ' . $removedName . ' from the group';
        $db->prepare("INSERT INTO yy_community_dm_message (thread_key, user_key, message_body) VALUES (?, NULL, ?)")
           ->execute([$threadKey, $sysMsg]);
        $db->prepare("UPDATE yy_community_dm_thread SET last_message_dtime = NOW() WHERE thread_key = ?")->execute([$threadKey]);

        jsonResponse(['success' => true]);
    }

    // ── Leave group ──
    if ($action === 'leave_group') {
        $threadKey = (int)($data['thread_key'] ?? 0);
        if (!$threadKey) errorResponse('thread_key is required');

        $stmt = $db->prepare("SELECT thread_is_group, thread_creator_key FROM yy_community_dm_thread WHERE thread_key = ?");
        $stmt->execute([$threadKey]);
        $thread = $stmt->fetch();
        if (!$thread || !$thread['thread_is_group']) errorResponse('Not a group thread');

        $stmt = $db->prepare("SELECT 1 FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?");
        $stmt->execute([$threadKey, $userKey]);
        if (!$stmt->fetchColumn()) errorResponse('You are not in this group');

        // Remove user from participants
        $db->prepare("DELETE FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?")->execute([$threadKey, $userKey]);

        // If creator leaves, transfer to next oldest participant
        if ((int)$thread['thread_creator_key'] === $userKey) {
            $nextStmt = $db->prepare("SELECT user_key FROM yy_community_dm_participant WHERE thread_key = ? ORDER BY user_key ASC LIMIT 1");
            $nextStmt->execute([$threadKey]);
            $nextCreator = $nextStmt->fetchColumn();
            if ($nextCreator) {
                $db->prepare("UPDATE yy_community_dm_thread SET thread_creator_key = ? WHERE thread_key = ?")->execute([$nextCreator, $threadKey]);
            }
        }

        // System message
        $senderStmt = $db->prepare("SELECT user_name_display FROM yy_user WHERE user_key = ?");
        $senderStmt->execute([$userKey]);
        $senderName = $senderStmt->fetchColumn() ?: 'Someone';

        $sysMsg = $senderName . ' left the group';
        $db->prepare("INSERT INTO yy_community_dm_message (thread_key, user_key, message_body) VALUES (?, NULL, ?)")
           ->execute([$threadKey, $sysMsg]);
        $db->prepare("UPDATE yy_community_dm_thread SET last_message_dtime = NOW() WHERE thread_key = ?")->execute([$threadKey]);

        jsonResponse(['success' => true]);
    }

    // ── Edit own message ──
    if ($action === 'edit_message') {
        $messageKey = (int)($data['message_key'] ?? 0);
        $body       = trim($data['body'] ?? '');
        $bodyHtml   = trim($data['message_body_html'] ?? '');
        if (!$messageKey) errorResponse('message_key is required');
        if ($body === '') errorResponse('Message cannot be empty');

        // Verify ownership + active. System messages (user_key = 0/null) cannot be edited.
        $stmt = $db->prepare("SELECT user_key, thread_key FROM yy_community_dm_message WHERE message_key = ? AND message_active_flag = TRUE");
        $stmt->execute([$messageKey]);
        $row = $stmt->fetch();
        if (!$row) errorResponse('Message not found', 404);
        if ((int)$row['user_key'] !== $userKey) errorResponse('You can only edit your own messages', 403);

        // Confirm the user is still a participant of the thread (defends against
        // an edit attempt after being removed from a group).
        $threadKey = (int)$row['thread_key'];
        $partStmt = $db->prepare("SELECT 1 FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?");
        $partStmt->execute([$threadKey, $userKey]);
        if (!$partStmt->fetchColumn()) errorResponse('Thread not found', 404);

        // Update. trg_yy_community_dm_message_rev bumps message_revision_num and
        // message_revision_dtime, and snapshots the row into the *_rev history
        // table, but does not touch message_revision_user_key — so we set it
        // explicitly so the audit row records the editor.
        $upd = $db->prepare("UPDATE yy_community_dm_message SET message_body = ?, message_body_html = ?, message_revision_user_key = ? WHERE message_key = ?");
        $upd->execute([$body, $bodyHtml ?: null, $userKey, $messageKey]);

        // Return the new revision metadata so the UI can paint the "edited" tag
        // without an extra round-trip.
        $back = $db->prepare("SELECT message_body, message_body_html, message_revision_num, message_revision_dtime FROM yy_community_dm_message WHERE message_key = ?");
        $back->execute([$messageKey]);
        $after = $back->fetch();
        jsonResponse([
            'success'                => true,
            'message_key'            => $messageKey,
            'message_body'           => $after['message_body'],
            'message_body_html'      => $after['message_body_html'],
            'message_revision_num'   => (int)$after['message_revision_num'],
            'message_revision_dtime' => $after['message_revision_dtime'],
        ]);
    }

    if ($action === 'set_chime') {
        $threadKey = (int)($data['thread_key'] ?? 0);
        $pitch = (int)($data['chime_pitch'] ?? 0);
        if (!$threadKey) errorResponse('thread_key required');
        if ($pitch < 0 || $pitch > 7) errorResponse('chime_pitch must be 0-7');

        $db->prepare("UPDATE yy_community_dm_participant SET chime_pitch = ? WHERE thread_key = ? AND user_key = ?")
           ->execute([$pitch, $threadKey, $userKey]);
        jsonResponse(['saved' => true]);
    }

    errorResponse('Unknown action');
}

errorResponse('Method not allowed', 405);
