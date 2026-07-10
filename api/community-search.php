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

// Match mode — mirrors search.php so Chat honors the global bar's
// All Words / Exact Phrase / Any Word picker instead of always AND-ing.
//   phrase → phraseto_tsquery (adjacency, real exact-phrase match)
//   any    → to_tsquery with '|'-joined sanitized lexemes
//   all    → plainto_tsquery (AND of lexemes; the default)
$mode = $_GET['mode'] ?? 'all';
$tsqParam = $q;
switch ($mode) {
    case 'phrase':
        $tsqSql = "phraseto_tsquery('english', ?)";
        break;
    case 'any':
        $anyWords = array_filter(array_map(function ($w) {
            return preg_replace('/[^a-zA-Z0-9]/', '', $w);
        }, preg_split('/\s+/', $q)));
        if ($anyWords) {
            $tsqParam = implode(' | ', $anyWords);
            $tsqSql = "to_tsquery('english', ?)";
        } else {
            $tsqSql = "plainto_tsquery('english', ?)"; // nothing usable → AND fallback
        }
        break;
    default:
        $mode = 'all';
        $tsqSql = "plainto_tsquery('english', ?)";
        break;
}

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
           ts_headline('english', COALESCE(t.topic_body, t.topic_body_html, ''), {$tsqSql},
               'MaxWords=40, MinWords=20, StartSel=<mark>, StopSel=</mark>') AS snippet,
           'topic' AS result_type
    FROM yy_community_topic t
    LEFT JOIN yy_user u ON t.user_key = u.user_key
    {$categoryJoin}
    WHERE t.topic_active_flag = TRUE
      AND t.topic_search_vector @@ {$tsqSql}
      {$categoryWhere}
    ORDER BY t.topic_dtime DESC
    LIMIT ? OFFSET ?
");

// Build params: headline query, tsquery, optional category, limit, offset
$execParams = [$tsqParam, $tsqParam];
if ($categorySlug) $execParams[] = $categorySlug;
$execParams[] = $limit;
$execParams[] = $offset;

$stmt->execute($execParams);
$topicResults = $stmt->fetchAll();

// Search replies
$replyParams = [$tsqParam, $tsqParam];
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
           ts_headline('english', COALESCE(r.reply_body, r.reply_body_html, ''), {$tsqSql},
               'MaxWords=40, MinWords=20, StartSel=<mark>, StopSel=</mark>') AS snippet,
           'reply' AS result_type
    FROM yy_community_reply r
    {$replyCategoryJoin}
    LEFT JOIN yy_user u ON r.user_key = u.user_key
    WHERE r.reply_active_flag = TRUE
      AND r.reply_search_vector @@ {$tsqSql}
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
    'mode' => $mode,
    'category' => $categorySlug ?: null,
]);
