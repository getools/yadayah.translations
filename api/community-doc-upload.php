<?php
/**
 * Community document upload API.
 * Accepts PDF, Office docs, text files, archives.
 * Saves to /u/community/docs/ and returns the URL.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);
denyIfBanned('Chat'); // banned users stay logged in, but can't upload to Chat

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No file uploaded or upload error');
}

$file = $_FILES['file'];
$maxSize = 20 * 1024 * 1024; // 20MB
if ($file['size'] > $maxSize) errorResponse('File must be under 20MB');

// Allowed extensions and MIME types
$allowed = [
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xls'  => ['application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'ppt'  => ['application/vnd.ms-powerpoint'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    'txt'  => ['text/plain'],
    'rtf'  => ['application/rtf', 'text/rtf'],
    'csv'  => ['text/csv', 'text/plain', 'application/csv'],
    'zip'  => ['application/zip', 'application/x-zip-compressed'],
];

$origName = $file['name'];
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!isset($allowed[$ext])) {
    errorResponse('File type not allowed: .' . $ext);
}

$destDir = __DIR__ . '/../u/community/docs';
if (!is_dir($destDir)) mkdir($destDir, 0775, true);

// Generate unique filename
$safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
$safeName = substr($safeName, 0, 80);
$filename = 'doc_' . $userKey . '_' . dechex(time()) . '_' . $safeName . '.' . $ext;

$destPath = $destDir . '/' . $filename;
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    errorResponse('Failed to save file');
}

jsonResponse(['url' => '/u/community/docs/' . $filename, 'name' => $origName]);
