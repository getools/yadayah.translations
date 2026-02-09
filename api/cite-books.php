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

$stmt = $pdo->query("
    SELECT cite_book_id, cite_book_hebrew, cite_book_common, cite_book_sort
    FROM cite_book
    ORDER BY cite_book_sort ASC, cite_book_hebrew ASC
");
echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
