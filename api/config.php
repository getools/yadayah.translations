<?php
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function getDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('PG_HOST') ?: 'postgres';
        $name = getenv('PG_DB')   ?: 'yada';
        $user = getenv('PG_USER') ?: 'postgres';
        $pass = getenv('PG_PASS') ?: 'yada_password';
        $dsn = "pgsql:host=$host;dbname=$name";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function jsonResponse($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function errorResponse(string $message, int $status = 400): void {
    jsonResponse(['error' => $message], $status);
}

function requireAuth(): array {
    if (empty($_SESSION['user_key'])) {
        jsonResponse(['error' => 'Authentication required'], 401);
    }
    return [
        'user_key' => $_SESSION['user_key'],
        'user_code' => $_SESSION['user_code'],
        'user_name' => $_SESSION['user_name'] ?? $_SESSION['user_code'],
    ];
}

function setCurrentUser(PDO $db, int $userKey): void {
    $db->exec("SET app.current_user_key = '" . intval($userKey) . "'");
}
