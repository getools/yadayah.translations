<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();
$stmt = $db->query('SELECT cite_book_key, cite_book_common, cite_book_hebrew, cite_book_sort FROM yy_cite_book ORDER BY cite_book_sort');
jsonResponse($stmt->fetchAll());
