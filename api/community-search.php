<?php
/**
 * Community search API.
 * GET: full-text search across topics and replies.
 * Uses PostgreSQL plainto_tsquery.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') errorResponse('Method not allowed', 405);

$q = trim($_GET['q'] ?? '');
if (!$q || mb_strlen($q) < 2) errorResponse('Search query must be at least 2 characters');

$categorySlug = trim($_GET['category'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$db = getDb();

// Search topics
$categoryJoin = '';
$categoryWhere = '';
$params = [$q];

if ($categorySlug) {
    $categoryJoin = "JOIN yy_community_category c ON t.category_key = c.category_key";
    $categoryWhere = "AND c.category_slug = ?";
    $params[] = $categorySlug;
}

$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare("
    SELECT t.topic_key, t.topic_title, t.topic_dtime, t.topic_reply_count,
           u.user_name_display, u.user_avatar,
           ts_headline('english', COALESCE(t.topic_body, t.topic_body_html, ''), plainto_tsquery('english', ?),
               'MaxWords=40, MinWords=20, StartSel=<mark>, StopSel=</mark>') AS snippet,
           'topic' AS result_type
    FROM yy_community_topic t
    LEFT JOIN yy_user u ON t.user_key = u.user_key
    {$categoryJoin}
    WHERE t.topic_active_flag = TRUE
      AND t.topic_search_vector @@ plainto_tsquery('english', ?)
      {$categoryWhere}
    ORDER BY t.topic_dtime DESC
    LIMIT ? OFFSET ?
");

// Build params: headline query, tsquery, optional category, limit, offset
$execParams = [$q, $q];
if ($categorySlug) $execParams[] = $categorySlug;
$execParams[] = $limit;
$execParams[] = $offset;

$stmt->execute($execParams);
$topicResults = $stmt->fetchAll();

// Search replies
$replyParams = [$q, $q];
if ($categorySlug) {
    $replyCategoryJoin = "JOIN yy_community_topic t ON r.topic_key = t.topic_key AND t.topic_active_flag = TRUE JOIN yy_community_category c ON t.category_key = c.category_key";
    $replyCategoryWhere = "AND c.category_slug = ?";
    $replyParams[] = $categorySlug;
} else {
    $replyCategoryJoin = "JOIN yy_community_topic t ON r.topic_key = t.topic_key AND t.topic_active_flag = TRUE";
    $replyCategoryWhere = "";
}
$replyParams[] = $limit;
$replyParams[] = $offset;

$stmt = $db->prepare("
    SELECT r.reply_key, r.topic_key, t.topic_title, r.reply_dtime,
           u.user_name_display, u.user_avatar,
           ts_headline('english', COALESCE(r.reply_body, r.reply_body_html, ''), plainto_tsquery('english', ?),
               'MaxWords=40, MinWords=20, StartSel=<mark>, StopSel=</mark>') AS snippet,
           'reply' AS result_type
    FROM yy_community_reply r
    {$replyCategoryJoin}
    LEFT JOIN yy_user u ON r.user_key = u.user_key
    WHERE r.reply_active_flag = TRUE
      AND r.reply_search_vector @@ plainto_tsquery('english', ?)
      {$replyCategoryWhere}
    ORDER BY r.reply_dtime DESC
    LIMIT ? OFFSET ?
");
$stmt->execute($replyParams);
$replyResults = $stmt->fetchAll();

jsonResponse([
    'topics' => $topicResults,
    'replies' => $replyResults,
    'query' => $q,
    'category' => $categorySlug ?: null,
]);
