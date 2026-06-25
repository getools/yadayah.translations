<?php
/**
 * Community image upload API.
 * POST: upload image — saves original + scaled copy.
 * POST {action: rescale}: regenerate all scaled copies from originals.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);
denyIfBanned('Chat'); // banned users stay logged in, but can't upload to Chat

$db = getDb();
$destDir = __DIR__ . '/../u/community';
if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}

// Rescale action
$input = json_decode(file_get_contents('php://input'), true);
if (($input['action'] ?? '') === 'rescale') {
    if (!function_exists('rescaleAllImages')) {
        errorResponse('Rescale function not available', 500);
    }
    $count = rescaleAllImages($db, $destDir);
    jsonResponse(['rescaled' => true, 'count' => $count]);
}

// Upload
if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No file uploaded or upload error');
}
$file = $_FILES['image'];
if ($file['size'] > 5 * 1024 * 1024) errorResponse('Image must be under 5MB');

$result = processImageUpload($db, $file, $destDir, 'img_' . $userKey . '_');
if (!$result) errorResponse('Invalid image type');

jsonResponse(['url' => '/u/community/' . $result['filename']]);
