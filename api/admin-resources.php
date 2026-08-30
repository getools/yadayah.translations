<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';
$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);
$method = $_SERVER['REQUEST_METHOD'];

$UPLOAD_DIR = __DIR__ . '/../u/resources';

// Support _method override for PUT via POST
if ($method === 'POST' && !empty($_POST['_method']) && strtoupper($_POST['_method']) === 'PUT') {
    $method = 'PUT';
}

function parseData(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'multipart/form-data') !== false) {
        return $_POST;
    }
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

function handleImageUpload(array $data, string $uploadDir): ?string {
    if (!empty($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['image_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        if (!in_array($ext, $allowed)) {
            errorResponse('Image must be: ' . implode(', ', $allowed));
        }
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
        $dest = "$uploadDir/$safeName";
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            errorResponse('Failed to save image');
        }
        // icon/sm/md/lg beside the stored file (no-op for the allowed .svg).
        makeImageSizes($dest, $uploadDir, $safeName);
        return "u/resources/$safeName";
    }
    return !empty($data['resource_image']) ? $data['resource_image'] : null;
}

if ($method === 'GET') {
    if (isset($_GET['key'])) {
        $stmt = $db->prepare("SELECT * FROM yy_resource WHERE resource_key = ?");
        $stmt->execute([(int)$_GET['key']]);
        $row = $stmt->fetch();
        if (!$row) errorResponse('Not found', 404);
        jsonResponse($row);
    }
    $stmt = $db->query("SELECT * FROM yy_resource ORDER BY resource_sort ASC, resource_key ASC");
    jsonResponse(['resources' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $data = parseData();
    if (empty($data['resource_title'])) errorResponse('Title is required');
    $imageValue = handleImageUpload($data, $UPLOAD_DIR);

    $stmt = $db->prepare("INSERT INTO yy_resource (resource_image, resource_title, resource_subtitle, resource_summary, resource_link_title, resource_link_url, resource_sort, resource_active_flag) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING *");
    $stmt->execute([
        $imageValue,
        $data['resource_title'],
        $data['resource_subtitle'] ?: null,
        $data['resource_summary'] ?: null,
        $data['resource_link_title'] ?: null,
        $data['resource_link_url'] ?: null,
        (int)($data['resource_sort'] ?? 0),
        !empty($data['resource_active_flag']) && $data['resource_active_flag'] !== '0',
    ]);
    jsonResponse($stmt->fetch(), 201);
}

if ($method === 'PUT') {
    $data = parseData();
    if (empty($data['resource_key'])) errorResponse('resource_key required');
    $imageValue = handleImageUpload($data, $UPLOAD_DIR);

    $stmt = $db->prepare("UPDATE yy_resource SET resource_image = ?, resource_title = ?, resource_subtitle = ?, resource_summary = ?, resource_link_title = ?, resource_link_url = ?, resource_sort = ?, resource_active_flag = ? WHERE resource_key = ? RETURNING *");
    $stmt->execute([
        $imageValue,
        $data['resource_title'],
        $data['resource_subtitle'] ?: null,
        $data['resource_summary'] ?: null,
        $data['resource_link_title'] ?: null,
        $data['resource_link_url'] ?: null,
        (int)($data['resource_sort'] ?? 0),
        !empty($data['resource_active_flag']) && $data['resource_active_flag'] !== '0',
        (int)$data['resource_key'],
    ]);
    $row = $stmt->fetch();
    if (!$row) errorResponse('Not found', 404);
    jsonResponse($row);
}

if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['resource_key'])) errorResponse('resource_key required');
    $stmt = $db->prepare('DELETE FROM yy_resource WHERE resource_key = ?');
    $stmt->execute([(int)$data['resource_key']]);
    jsonResponse(['deleted' => $stmt->rowCount()]);
}

errorResponse('Method not allowed', 405);
