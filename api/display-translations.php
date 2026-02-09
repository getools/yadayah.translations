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

$citeBookId = isset($_GET['cite_book_id']) && $_GET['cite_book_id'] !== '' ? (int)$_GET['cite_book_id'] : null;
$chapter = isset($_GET['chapter']) && $_GET['chapter'] !== '' ? (int)$_GET['chapter'] : null;
$verse   = isset($_GET['verse']) && $_GET['verse'] !== '' ? (int)$_GET['verse'] : null;

$conditions = [];
$params = [];

if ($citeBookId !== null) {
    $conditions[] = "t.translation_cite_book_id = ?";
    $params[] = $citeBookId;
}

if ($chapter !== null) {
    $conditions[] = "t.translation_cite_chapter = ?";
    $params[] = $chapter;
}

if ($verse !== null) {
    $conditions[] = "t.translation_cite_verse = ?";
    $params[] = $verse;
}

$where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = $pdo->prepare("
    SELECT t.translation_id, t.translation_book, t.translation_page, t.translation_text_word,
           t.translation_cite, t.translation_cite_chapter, t.translation_cite_verse, t.translation_cite_book_id,
           v.yy_volume_flip_code,
           cb.cite_book_hebrew, cb.cite_book_common
    FROM translation t
    LEFT JOIN yy_volume v ON t.yy_volume_id = v.yy_volume_id
    LEFT JOIN cite_book cb ON t.translation_cite_book_id = cb.cite_book_id
    $where
    ORDER BY t.translation_cite, t.translation_cite_chapter, t.translation_cite_verse, t.translation_book, t.translation_page
");
$stmt->execute($params);

echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
