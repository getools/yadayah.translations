<?php
/**
 * Public API: returns footer link columns from yy_page.
 * Cached 24h, busted when pages are saved.
 */
require_once __DIR__ . '/config.php';

$CACHE_FILE = sys_get_temp_dir() . '/yada_page_footer.json';
$CACHE_TTL  = 86400;

if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    readfile($CACHE_FILE);
    exit;
}

$db = getDb();

// Footer links come from yy_menu_item (zone=footer) — the same list the page
// renderer uses — so the static pages that still call this endpoint show the
// same footer as every cut-over page. Previously this read
// yy_page.page_footer_col / page_footer_sort, which drifted from the new
// menus (it was missing Family and Grammar, for instance).
$stmt = $db->query("
    SELECT COALESCE(NULLIF(p.page_label, ''), NULLIF(p.page_title, ''), p.page_code) AS link_title,
           p.page_url, p.page_code
      FROM yy_menu_item m
      JOIN yy_page p ON p.page_key = m.page_key
     WHERE m.menu_zone = 'footer'
       AND COALESCE(m.menu_item_active_flag, TRUE) = TRUE
       AND p.page_active_flag = TRUE
     ORDER BY m.menu_item_sort, m.menu_item_key
");
$rows = $stmt->fetchAll();

// The consumer (site-footer.js) expects three columns. The menu is a single
// ordered list, so deal it into 3 balanced columns while preserving order
// top-to-bottom within each column.
$cols = [1 => [], 2 => [], 3 => []];
$total   = count($rows);
$perCol  = $total > 0 ? (int)ceil($total / 3) : 0;
foreach ($rows as $i => $r) {
    $col = $perCol > 0 ? min(3, (int)floor($i / $perCol) + 1) : 1;
    $cols[$col][] = [
        'title' => $r['link_title'],
        'url'   => $r['page_url'] ?: '/' . $r['page_code'],
    ];
}

$json = json_encode($cols, JSON_UNESCAPED_UNICODE);
file_put_contents($CACHE_FILE, $json);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $json;
