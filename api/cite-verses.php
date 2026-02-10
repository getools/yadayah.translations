<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();
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

jsonResponse($stmt->fetchAll());
