<?php
/**
 * Community media upload API — video, audio, and (future) other media.
 *
 *   POST  $_FILES['media']
 *   →     { url: '/u/community/<vid|aud>_<user>_<ts>_<rand>.<ext>' }
 *
 * Requires login.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

if (empty($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No file uploaded or upload error');
}
$file = $_FILES['media'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

$allowedVideo = ['mp4', 'webm', 'mov', 'ogg', 'avi', 'mkv'];
$allowedAudio = ['mp3', 'wav'];
$allowed = array_merge($allowedVideo, $allowedAudio);
if (!in_array($ext, $allowed)) {
    errorResponse('Invalid file type. Allowed: ' . implode(', ', $allowed));
}
if ($file['size'] > 100 * 1024 * 1024) {
    errorResponse('File must be under 100MB');
}

$dir = __DIR__ . '/../u/community';
if (!is_dir($dir)) mkdir($dir, 0755, true);

// Prefix distinguishes audio uploads from video at a glance in storage.
$prefix = in_array($ext, $allowedAudio, true) ? 'aud_' : 'vid_';
$filename = $prefix . $userKey . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = $dir . '/' . $filename;

move_uploaded_file($file['tmp_name'], $dest);

$url = '/u/community/' . $filename;
jsonResponse(['url' => $url]);
