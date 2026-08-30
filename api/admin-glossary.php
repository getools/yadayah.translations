<?php
/**
 * Admin API for Glossary (Hebrew letter) management.
 * GET                  — list all letters
 * GET ?key=N           — single letter
 * PUT ?key=N           — update letter fields
 * POST ?action=upload  — upload background image
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$key = (int)($_GET['key'] ?? 0);

if ($method === 'GET' && !$key) {
    $stmt = $db->query("
        SELECT letter_key, letter_sort, letter_label, letter_yt, letter_hebrew,
               letter_numeric_value, letter_info, letter_overview, letter_image, letter_image_scale, letter_active
        FROM yy_letter
        ORDER BY letter_sort ASC, letter_key ASC
    ");
    jsonResponse(['letters' => $stmt->fetchAll()]);
}

if ($method === 'GET' && $key) {
    $stmt = $db->prepare("
        SELECT letter_key, letter_sort, letter_label, letter_yt, letter_hebrew,
               letter_numeric_value, letter_info, letter_overview, letter_image, letter_image_scale, letter_active
        FROM yy_letter WHERE letter_key = ?
    ");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) errorResponse('Letter not found', 404);
    jsonResponse($row);
}

if ($method === 'PUT' && $key) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $allowed = [
        'letter_sort' => 'int',
        'letter_label' => 'text',
        'letter_yt' => 'text',
        'letter_hebrew' => 'text',
        'letter_numeric_value' => 'int',
        'letter_info' => 'text',
        'letter_overview' => 'text',
        'letter_image' => 'text',
        'letter_image_scale' => 'int',
        'letter_active' => 'bool',
    ];
    $fields = [];
    $params = [];
    foreach ($allowed as $col => $type) {
        if (!array_key_exists($col, $data)) continue;
        $fields[] = "$col = ?";
        if ($type === 'int') $params[] = (int)$data[$col];
        elseif ($type === 'bool') $params[] = $data[$col] ? 't' : 'f';
        else $params[] = $data[$col];
    }
    if (empty($fields)) errorResponse('Nothing to update');
    $params[] = $key;
    $db->prepare("UPDATE yy_letter SET " . implode(', ', $fields) . " WHERE letter_key = ?")->execute($params);
    jsonResponse(['saved' => true]);
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if ($action === 'upload' && $key) {
        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) errorResponse('No file uploaded');

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'])) errorResponse('Invalid file type');

        // Get image dimensions for filename
        $info = @getimagesize($file['tmp_name']);
        $w = $info ? $info[0] : 0;
        $h = $info ? $info[1] : 0;

        // Get letter label for filename
        $stmt = $db->prepare("SELECT letter_label FROM yy_letter WHERE letter_key = ?");
        $stmt->execute([$key]);
        $label = $stmt->fetchColumn();
        if (!$label) errorResponse('Letter not found');

        $filename = 'letter-' . $label . '.' . sprintf('%05d', $w) . 'x' . sprintf('%05d', $h) . '.' . $ext;
        $dest = dirname(__DIR__) . '/public/images/' . $filename;

        // In Docker, the public dir is at /var/www/html
        if (is_dir('/var/www/html/images')) {
            $dest = '/var/www/html/images/' . $filename;
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) errorResponse('Failed to save file');

        // Letter images live in /images, not /u, but get the same size set so
        // glossary lists can pull '-icon' instead of the full-resolution plate.
        makeImageSizes($dest, dirname($dest), $filename);

        // Update DB
        $db->prepare("UPDATE yy_letter SET letter_image = ? WHERE letter_key = ?")->execute([$filename, $key]);
        jsonResponse(['saved' => true, 'filename' => $filename]);
    }
}

errorResponse('Invalid request', 400);
