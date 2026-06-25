<?php
/**
 * Community session check. Returns current user info or null.
 * Includes unread notification count.
 * Updates user_last_active_dtime on every check.
 * Also handles logout via POST with action=logout.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($data['action'] ?? '') === 'logout') {
        session_destroy();
        jsonResponse(['logged_out' => true]);
    }
}

if (!empty($_SESSION['user_key'])) {
    $db = getDb();
    $userKey = $_SESSION['user_key'];

    // Update last active timestamp
    $db->prepare("UPDATE yy_user SET user_last_active_dtime = NOW() WHERE user_key = ?")
       ->execute([$userKey]);

    $stmt = $db->prepare("
        SELECT u.user_key, u.user_name_display, u.user_handle, u.user_avatar, u.user_email,
               u.user_oauth_provider, u.user_verified, u.user_reputation, u.user_email_notifications,
               u.user_banned_flag, u.user_banned_until, u.user_ban_reason,
               array_agg(r.role_code) as roles
        FROM yy_user u
        LEFT JOIN yy_user_role ur ON u.user_key = ur.user_key
        LEFT JOIN yy_role r ON ur.role_key = r.role_key
        WHERE u.user_key = ?
        GROUP BY u.user_key
    ");
    $stmt->execute([$userKey]);
    $user = $stmt->fetch();
    if ($user) {
        // Parse PostgreSQL array
        $roles = trim($user['roles'], '{}');
        $user['roles'] = $roles ? explode(',', $roles) : [];

        // Ban status: the user stays logged in and tracked, but the UI uses
        // this to disable the restricted features (Ask Yada, Chat). Timed bans
        // (user_banned_until) auto-expire.
        $banned = ($user['user_banned_flag'] === true || $user['user_banned_flag'] === 't')
            && (empty($user['user_banned_until']) || strtotime($user['user_banned_until']) > time());
        $user['banned'] = $banned;
        $user['ban_reason'] = $banned ? ($user['user_ban_reason'] ?: null) : null;

        // Unread notification count
        $nStmt = $db->prepare("SELECT COUNT(*) FROM yy_community_notification WHERE user_key = ? AND read_flag = FALSE");
        $nStmt->execute([$userKey]);
        $user['unread_notifications'] = (int)$nStmt->fetchColumn();

        // Linked auth providers
        $authStmt = $db->prepare("SELECT auth_provider FROM yy_user_auth WHERE user_key = ? AND auth_active_flag = TRUE ORDER BY auth_linked_dtime");
        $authStmt->execute([$userKey]);
        $user['auth_providers'] = $authStmt->fetchAll(PDO::FETCH_COLUMN);

        // Pending link info
        $user['pending_link'] = null;
        if (!empty($_SESSION['pending_link']) && $_SESSION['pending_link']['expires'] > time()) {
            $p = $_SESSION['pending_link'];
            $user['pending_link'] = [
                'provider' => $p['provider'],
                'email' => $p['email'],
                'existing_name' => $p['existing_name'] ?? null,
            ];
        }

        // Unread DM count (messages from others after my last_read_dtime)
        $dmStmt = $db->prepare("
            SELECT COUNT(*) FROM yy_community_dm_message m
            JOIN yy_community_dm_participant p ON p.thread_key = m.thread_key AND p.user_key = ?
            WHERE m.user_key != ? AND m.message_active_flag = TRUE
              AND m.message_dtime > COALESCE(p.last_read_dtime, '1970-01-01')
        ");
        $dmStmt->execute([$userKey, $userKey]);
        $user['unread_dm'] = (int)$dmStmt->fetchColumn();

        jsonResponse(['user' => $user]);
    }
}

jsonResponse(['user' => null]);
