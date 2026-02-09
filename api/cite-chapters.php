<?php
header('Content-Type: application/json; charset=utf-8');

$host = getenv('PG_HOST') ?: 'postgres';
$db   = getenv('PG_DB')   ?: 'yada';
$user = getenv('PG_USER') ?: 'postgres';
$pass = getenv('PG_PASS') ?: 'yada_password';

$pdo = new PDO("pgsql:host=$host;dbname=$db", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$citeIds = isset($_GET['cite_ids']) ? $_GET['cite_ids'] : '';

if ($citeIds === '') {
    $stmt = $pdo->query("SELECT DISTINCT translation_cite_chapter FROM translation WHERE translation_cite_chapter IS NOT NULL ORDER BY translation_cite_chapter");
} else {
    $ids = array_map('intval', explode(',', $citeIds));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT DISTINCT translation_cite_chapter
        FROM translation
        WHERE translation_cite_chapter IS NOT NULL
          AND translation_cite IN (SELECT label FROM cite WHERE id IN ($placeholders))
        ORDER BY translation_cite_chapter
    ");
    $stmt->execute($ids);
}

echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
