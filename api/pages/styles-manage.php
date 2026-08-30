<?php
/**
 * TEST endpoint — global style defaults for the Pages-New system.
 * Manages the singleton yy_page_style row (style_key = 1): the default
 * Headers / Subheaders / Body fonts applied to every /test/page.php page and
 * its sections. A page that sets its own font (page_*_font) still wins;
 * these are the fallback used when a page leaves a font blank.
 *
 *   GET  → { heading_font, subheading_font, body_font, search_band_bg }
 *   PUT    body { heading_font, subheading_font, body_font, search_band_bg }
 *
 * search_band_bg is NOT a yy_page_style column. The search toolbar colour is a
 * site-wide setting that already exists in yy_setting
 * (config / page-heading / page-heading-search-bg-color) — site-nav.js reads it
 * from /api/page-nav.php and sets --search-band-bg on <html> for EVERY page,
 * built or static. Editing it here writes that same row rather than adding a
 * second, competing value; Admin -> Site -> Search edits the identical setting.
 *
 * Values are CSS font stacks from the yy_font registry, same as the page
 * editor's font pickers. Edited in /test/admin-pages.html → Styles tab.
 */
require_once __DIR__ . '/../config.php';
requireAuth();

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

// The search-band colour lives in yy_setting, not yy_page_style.
const SEARCH_BG_SCOPE = 'config';
const SEARCH_BG_GROUP = 'page-heading';
const SEARCH_BG_CODE  = 'page-heading-search-bg-color';

function searchBandBg(PDO $db): string {
    $st = $db->prepare("SELECT setting_value FROM yy_setting
                         WHERE setting_scope_code = ? AND setting_group_code = ? AND setting_code = ?
                         ORDER BY setting_key LIMIT 1");
    $st->execute([SEARCH_BG_SCOPE, SEARCH_BG_GROUP, SEARCH_BG_CODE]);
    return (string)($st->fetchColumn() ?: '');
}

if ($method === 'GET') {
    $row = $db->query("SELECT heading_font, subheading_font, body_font FROM yy_page_style WHERE style_key = 1")->fetch();
    $row = $row ?: [];
    jsonResponse([
        'heading_font'    => $row['heading_font']    ?? '',
        'subheading_font' => $row['subheading_font'] ?? '',
        'body_font'       => $row['body_font']       ?? '',
        'search_band_bg'  => searchBandBg($db),
    ]);
}

if ($method === 'PUT') {
    $d = json_decode(file_get_contents('php://input'), true);
    if (!is_array($d)) errorResponse('Invalid JSON');

    // Only columns actually present in the payload are written. These fonts are
    // the site-wide defaults for EVERY page, and the previous unconditional
    // write meant a truncated or mis-encoded body silently nulled all three —
    // headings then fell back to page.php's built-in default with no error
    // anywhere. COALESCE(?, col) keeps whatever is stored when a key is absent.
    $fontVal = function ($k) use ($d) {
        return array_key_exists($k, $d) ? (trim((string)$d[$k]) ?: null) : null;
    };
    $st = $db->prepare("
        INSERT INTO yy_page_style (style_key, heading_font, subheading_font, body_font, updated_dtime)
        VALUES (1, ?, ?, ?, now())
        ON CONFLICT (style_key) DO UPDATE
           SET heading_font    = CASE WHEN ? THEN EXCLUDED.heading_font    ELSE yy_page_style.heading_font    END,
               subheading_font = CASE WHEN ? THEN EXCLUDED.subheading_font ELSE yy_page_style.subheading_font END,
               body_font       = CASE WHEN ? THEN EXCLUDED.body_font       ELSE yy_page_style.body_font       END,
               updated_dtime   = now()
        RETURNING updated_dtime
    ");
    $st->execute([
        $fontVal('heading_font'),
        $fontVal('subheading_font'),
        $fontVal('body_font'),
        array_key_exists('heading_font', $d)    ? 't' : 'f',
        array_key_exists('subheading_font', $d) ? 't' : 'f',
        array_key_exists('body_font', $d)       ? 't' : 'f',
    ]);
    $savedAt = $st->fetchColumn();

    // Search toolbar background. Only written when the key is actually sent AND
    // the value changed: yy_setting carries a revision trigger, so a no-op write
    // would still spool a junk _rev row on every save of this tab.
    if (array_key_exists('search_band_bg', $d)) {
        $want = trim((string)$d['search_band_bg']);
        if ($want !== searchBandBg($db)) {
            // No unique constraint on (scope, group, code) and duplicates DO
            // exist in this table, so update every matching row — leaving one
            // behind would let page-nav.php pick the stale one (its loop keeps
            // whichever row it reads last).
            $upd = $db->prepare("UPDATE yy_setting SET setting_value = ?, setting_revision_dtime = now()
                                  WHERE setting_scope_code = ? AND setting_group_code = ? AND setting_code = ?");
            $upd->execute([$want, SEARCH_BG_SCOPE, SEARCH_BG_GROUP, SEARCH_BG_CODE]);
            if ($upd->rowCount() === 0) {
                $ins = $db->prepare("INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_value_code, setting_sort, setting_value)
                                     VALUES (?, ?, ?, 'text', 0, ?)");
                $ins->execute([SEARCH_BG_SCOPE, SEARCH_BG_GROUP, SEARCH_BG_CODE, $want]);
            }
            // /api/page-nav.php caches its payload for 24h in the container's
            // temp dir. Without this the new colour would not reach any page
            // until that file expired.
            @unlink(sys_get_temp_dir() . '/yada_page_nav.json');
        }
    }

    jsonResponse(['saved' => true, 'saved_at' => $savedAt]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
