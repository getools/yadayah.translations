<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $name = $_GET['name'] ?? null;
        if (!$name) {
            errorResponse('name parameter is required');
        }

        $stmt = $db->prepare('SELECT yy_preference_value FROM yy_user_preference WHERE yy_user_key = ? AND yy_preference_name = ?');
        $stmt->execute([$user['user_key'], $name]);
        $row = $stmt->fetch();

        if ($row) {
            $decoded = json_decode($row['yy_preference_value'], true);
            jsonResponse(['name' => $name, 'value' => $decoded !== null ? $decoded : $row['yy_preference_value']]);
        } else {
            jsonResponse(['name' => $name, 'value' => null]);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['name'])) {
            errorResponse('name is required');
        }

        $value = is_array($data['value']) || is_object($data['value'])
            ? json_encode($data['value'], JSON_UNESCAPED_UNICODE)
            : ($data['value'] ?? '');

        $stmt = $db->prepare("
            INSERT INTO yy_user_preference (yy_user_key, yy_preference_name, yy_preference_value)
            VALUES (?, ?, ?)
            ON CONFLICT (yy_user_key, yy_preference_name) DO UPDATE SET yy_preference_value = EXCLUDED.yy_preference_value
        ");
        $stmt->execute([$user['user_key'], $data['name'], $value]);
        jsonResponse(['saved' => true]);
        break;

    default:
        errorResponse('Method not allowed', 405);
}
