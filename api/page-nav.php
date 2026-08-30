<?php
/**
 * Public API: site navigation from yy_page.
 * Returns main nav (page_toolbar=1) and sub-toolbar (page_toolbar=2) items,
 * ordered by page_header_sort. Cached 24h.
 */
require_once __DIR__ . '/config.php';

$CACHE_FILE = sys_get_temp_dir() . '/yada_page_nav.json';
$CACHE_TTL  = 86400;

if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    readfile($CACHE_FILE);
    exit;
}

$db = getDb();

// Nav comes from yy_menu_item — the same table the page renderer builds its
// server-side header from. Before the Pages-New cutover this read
// yy_page.page_toolbar / page_header_sort, which meant the handful of pages
// still served as static .html showed a DIFFERENT header from every
// cut-over page. One source now drives both.
//
// The renderer marks its own <nav data-static>, so site-nav.js leaves those
// pages alone and this endpoint only feeds the remaining static pages.
// page_label is the short nav caption ("Time"), falling back to the full
// title ("Timeline").
$stmt = $db->query("
    SELECT p.page_code,
           COALESCE(NULLIF(p.page_label, ''), NULLIF(p.page_title, ''), p.page_code) AS nav_title,
           p.page_url
      FROM yy_menu_item m
      JOIN yy_page p ON p.page_key = m.page_key
     WHERE m.menu_zone = 'header'
       AND m.parent_menu_item_key IS NULL
       AND COALESCE(m.menu_item_active_flag, TRUE) = TRUE
       AND p.page_active_flag = TRUE
     ORDER BY m.menu_item_sort, m.menu_item_key
");
$pages = $stmt->fetchAll();

// 'sub' stays empty: the sub-toolbar in the new system is header-scoped (each
// header item owns its own subheader list), so it has no single global list
// to publish here. It was already returning [] before this change.
$result = ['main' => [], 'sub' => [], 'logo' => null];
foreach ($pages as $p) {
    $url = $p['page_url'] ?: '/' . $p['page_code'];
    $result['main'][] = ['title' => $p['nav_title'], 'url' => $url, 'code' => $p['page_code']];
}

$cfgStmt = $db->query("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'config' AND setting_code IN ('logo','logo-height','title-prefix','toolbar-main-text-size','toolbar-sub-text-size','logo-margin-top','logo-margin-bottom','toolbar-main-margin-top','toolbar-main-margin-bottom','toolbar-main-text-color','toolbar-sub-margin-top','toolbar-sub-margin-bottom','toolbar-sub-bg-color','toolbar-sub-text-color')");
foreach ($cfgStmt->fetchAll() as $row) {
    $v = $row['setting_value'];
    if (!strlen((string)$v)) continue;
    switch ($row['setting_code']) {
        case 'logo':                         $result['logo']                        = $v; break;
        case 'title-prefix':                 $result['title_prefix']                = $v; break;
        case 'toolbar-main-text-size':       $result['toolbar_main_text_size']      = $v; break;
        case 'toolbar-main-text-color':      $result['toolbar_main_text_color']     = $v; break;
        case 'toolbar-main-margin-top':      $result['toolbar_main_margin_top']     = $v; break;
        case 'toolbar-main-margin-bottom':   $result['toolbar_main_margin_bottom']  = $v; break;
        case 'toolbar-sub-text-size':        $result['toolbar_sub_text_size']       = $v; break;
        case 'toolbar-sub-text-color':       $result['toolbar_sub_text_color']      = $v; break;
        case 'toolbar-sub-bg-color':         $result['toolbar_sub_bg_color']        = $v; break;
        case 'toolbar-sub-margin-top':       $result['toolbar_sub_margin_top']      = $v; break;
        case 'toolbar-sub-margin-bottom':    $result['toolbar_sub_margin_bottom']   = $v; break;
        case 'logo-height':                  $result['logo_height']                 = $v; break;
        case 'logo-margin-top':              $result['logo_margin_top']             = $v; break;
        case 'logo-margin-bottom':           $result['logo_margin_bottom']          = $v; break;
    }
}

// Page Heading settings
$phStmt = $db->query("SELECT setting_code, setting_value FROM yy_setting WHERE setting_group_code = 'page-heading'");
foreach ($phStmt->fetchAll() as $row) {
    $v = $row['setting_value'];
    if (!strlen((string)$v)) continue;
    $key = str_replace('-', '_', $row['setting_code']);
    $result[$key] = $v;
}

$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($CACHE_FILE, $json);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
echo $json;
