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
$chapter = isset($_GET['chapter']) && $_GET['chapter'] !== '' ? (int)$_GET['chapter'] : null;

$conditions = ["translation_cite_verse IS NOT NULL"];
$params = [];

if ($citeIds !== '') {
    $ids = array_map('intval', explode(',', $citeIds));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $conditions[] = "translation_cite IN (SELECT label FROM cite WHERE id IN ($placeholders))";
    $params = $ids;
}

if ($chapter !== null) {
    $conditions[] = "translation_cite_chapter = ?";
    $params[] = $chapter;
}

$where = implode(' AND ', $conditions);
$stmt = $pdo->prepare("SELECT DISTINCT translation_cite_verse FROM translation WHERE $where ORDER BY translation_cite_verse");
$stmt->execute($params);

echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
