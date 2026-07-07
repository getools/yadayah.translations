<?php
/**
 * Community members API.
 * GET: paginated member list with search and sort.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') errorResponse('Method not allowed', 405);

$db = getDb();

// Single user lookup
if (isset($_GET['user_key'])) {
    $uk = (int)$_GET['user_key'];
    $stmt = $db->prepare("
        SELECT u.user_key, u.user_name_display, u.user_handle, u.user_avatar, u.user_bio,
               u.user_email, u.user_reputation, u.user_last_active_dtime, u.user_dtime AS user_created_dtime,
               (SELECT COUNT(*) FROM yy_community_topic t WHERE t.user_key = u.user_key AND t.topic_active_flag = TRUE) AS topic_count,
               (SELECT COUNT(*) FROM yy_community_reply r WHERE r.user_key = u.user_key AND r.reply_active_flag = TRUE) AS reply_count
        FROM yy_user u
        WHERE u.user_key = ? AND u.user_active_flag = TRUE
    ");
    $stmt->execute([$uk]);
    $member = $stmt->fetch();
    if (!$member) errorResponse('User not found', 404);
    jsonResponse(['member' => $member]);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 32;
$offset = ($page - 1) * $limit;
$search = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'reputation';

// Build WHERE clause
$where = "WHERE u.user_active_flag = TRUE AND u.user_banned_flag = FALSE AND EXISTS (SELECT 1 FROM yy_user_role ur JOIN yy_role r ON ur.role_key = r.role_key WHERE ur.user_key = u.user_key AND r.role_code IN ('public', 'moderator'))";
$params = [];

if ($search) {
    // Match against every piece of text associated with a member:
    // display name, handle, nickname/display name, first/middle/last/full name,
    // email address, free-text field, bio, and the login code (holds email/oauth id).
    $where .= " AND (
        COALESCE(u.user_name_display,'') || ' ' || COALESCE(u.user_handle,'') || ' ' ||
        COALESCE(u.user_display_name,'') || ' ' || COALESCE(u.user_name_first,'') || ' ' ||
        COALESCE(u.user_name_middle,'') || ' ' || COALESCE(u.user_name_last,'') || ' ' ||
        COALESCE(u.user_name_full,'') || ' ' || COALESCE(u.user_email,'') || ' ' ||
        COALESCE(u.user_text,'') || ' ' || COALESCE(u.user_bio,'') || ' ' ||
        COALESCE(u.user_code,'')
    ) ILIKE ?";
    $params[] = '%' . $search . '%';
}

// Build ORDER BY
$orderBy = match ($sort) {
    'newest' => 'u.user_key DESC',
    'name' => 'u.user_name_display ASC',
    default => 'u.user_reputation DESC, u.user_key DESC',
};

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM yy_user u {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Fetch
$allParams = array_merge($params, [$limit, $offset]);
$stmt = $db->prepare("
    SELECT u.user_key, COALESCE(NULLIF(u.user_name_display,''), u.user_name_display, 'Anonymous') AS user_name_display, u.user_handle, u.user_avatar,
           u.user_reputation, u.user_topic_count AS topic_count, u.user_reply_count AS reply_count, u.user_last_active_dtime
    FROM yy_user u
    {$where}
    ORDER BY {$orderBy}
    LIMIT ? OFFSET ?
");
$stmt->execute($allParams);

jsonResponse([
    'members' => $stmt->fetchAll(),
    'total' => $total,
    'page' => $page,
    'pages' => (int)ceil($total / $limit),
]);
