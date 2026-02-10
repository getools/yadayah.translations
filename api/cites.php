<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();
$stmt = $pdo->query("SELECT id, label, sort FROM cite ORDER BY sort DESC, label ASC");
jsonResponse($stmt->fetchAll());
