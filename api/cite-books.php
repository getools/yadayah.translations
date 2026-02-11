<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();
$stmt = $pdo->query("
    SELECT cite_book_id, cite_book_hebrew, cite_book_common, cite_book_sort
    FROM yy_cite_book
    ORDER BY cite_book_sort ASC, cite_book_hebrew ASC
");
jsonResponse($stmt->fetchAll());
