<?php
/**
 * TEST helper — return all pages with their categories nested, for the
 * Items-section "Page + Category" cascading multi-selector.
 *
 *   GET → [ { page_key, page_title, page_code, categories: [{category_key, category_title}] }, ... ]
 *
 * Active pages and active categories only. Auth required.
 */
require_once __DIR__ . '/../config.php';
requireAuth();
$db = getDb();

// Only include pages that have at least one feed_item linked to them via yy_feed_item_page.
// EXISTS keeps the planner cheap on the (page_key)-indexed join table.
$pages = $db->query("
    SELECT p.page_key, p.page_title, p.page_code
    FROM yy_page p
    WHERE p.page_active_flag = TRUE
      AND EXISTS (SELECT 1 FROM yy_feed_item_page fip WHERE fip.page_key = p.page_key)
    ORDER BY COALESCE(NULLIF(p.page_title, ''), p.page_code)
")->fetchAll();

$cats = $db->query("
    SELECT category_key, page_key, category_title, category_sort
    FROM yy_feed_page_category
    WHERE category_active_flag = TRUE
    ORDER BY page_key, category_sort, category_title
")->fetchAll();

$byPage = [];
foreach ($cats as $c) {
    $byPage[(int)$c['page_key']][] = [
        'category_key'   => (int)$c['category_key'],
        'category_title' => $c['category_title'],
    ];
}
foreach ($pages as &$p) {
    $p['categories'] = $byPage[(int)$p['page_key']] ?? [];
}
jsonResponse($pages);
