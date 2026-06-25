<?php
/**
 * Community likes API.
 * POST: toggle like on a topic or reply.
 * Body: {target_type: 'topic'|'reply', target_key: int}
 * Returns: {liked: bool, count: int}
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);
denyIfBanned('Chat'); // banned users stay logged in, but can't participate in Chat

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') errorResponse('Method not allowed', 405);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$targetType = $data['target_type'] ?? '';
$targetKey = (int)($data['target_key'] ?? 0);

if (!in_array($targetType, ['topic', 'reply'])) errorResponse('target_type must be topic or reply');
if (!$targetKey) errorResponse('target_key is required');

// Determine table and column names
if ($targetType === 'topic') {
    $table = 'yy_community_topic';
    $keyCol = 'topic_key';
    $countCol = 'topic_like_count';
} else {
    $table = 'yy_community_reply';
    $keyCol = 'reply_key';
    $countCol = 'reply_like_count';
}

// Check target exists
$stmt = $db->prepare("SELECT {$keyCol} FROM {$table} WHERE {$keyCol} = ?");
$stmt->execute([$targetKey]);
if (!$stmt->fetchColumn()) errorResponse(ucfirst($targetType) . ' not found', 404);

// Check if already liked
$stmt = $db->prepare("
    SELECT like_key FROM yy_community_like
    WHERE user_key = ? AND target_type = ? AND target_key = ?
");
$stmt->execute([$userKey, $targetType, $targetKey]);
$existing = $stmt->fetchColumn();

if ($existing) {
    // Unlike
    $db->prepare("DELETE FROM yy_community_like WHERE like_key = ?")->execute([$existing]);
    $db->prepare("UPDATE {$table} SET {$countCol} = GREATEST(0, {$countCol} - 1) WHERE {$keyCol} = ?")->execute([$targetKey]);
    $liked = false;
} else {
    // Like
    $db->prepare("INSERT INTO yy_community_like (user_key, target_type, target_key) VALUES (?, ?, ?)")
       ->execute([$userKey, $targetType, $targetKey]);
    $db->prepare("UPDATE {$table} SET {$countCol} = {$countCol} + 1 WHERE {$keyCol} = ?")->execute([$targetKey]);
    $liked = true;
}

// Get updated count
$stmt = $db->prepare("SELECT {$countCol} FROM {$table} WHERE {$keyCol} = ?");
$stmt->execute([$targetKey]);
$count = (int)$stmt->fetchColumn();

jsonResponse(['liked' => $liked, 'count' => $count]);
