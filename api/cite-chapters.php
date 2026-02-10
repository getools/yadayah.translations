<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();
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

jsonResponse($stmt->fetchAll());
