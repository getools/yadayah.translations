<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();
$stmt = $pdo->query("
    SELECT d.book_code, MAX(d.book_code_hebrew) AS book_code_hebrew,
           cb.cite_book_hebrew, COALESCE(cb.cite_book_sort, 9999) AS sort_order
    FROM dss_verse d
    LEFT JOIN yy_cite_book cb ON cb.cite_book_key = d.cite_book_key
    GROUP BY d.book_code, cb.cite_book_hebrew, cb.cite_book_sort
    ORDER BY sort_order, d.book_code
");
jsonResponse($stmt->fetchAll());
