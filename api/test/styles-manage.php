<?php
/**
 * TEST endpoint — global style defaults for the Pages-New system.
 * Manages the singleton yy_page_test_style row (style_key = 1): the default
 * Headers / Subheaders / Body fonts applied to every /test/page.php page and
 * its sections. A page that sets its own font (page_test_*_font) still wins;
 * these are the fallback used when a page leaves a font blank.
 *
 *   GET  → { heading_font, subheading_font, body_font }
 *   PUT    body { heading_font, subheading_font, body_font }   (upsert)
 *
 * Values are CSS font stacks from the yy_font registry, same as the page
 * editor's font pickers. Edited in /test/admin-pages.html → Styles tab.
 */
require_once __DIR__ . '/../config.php';
requireAuth();

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $row = $db->query("SELECT heading_font, subheading_font, body_font FROM yy_page_test_style WHERE style_key = 1")->fetch();
    $row = $row ?: [];
    jsonResponse([
        'heading_font'    => $row['heading_font']    ?? '',
        'subheading_font' => $row['subheading_font'] ?? '',
        'body_font'       => $row['body_font']       ?? '',
    ]);
}

if ($method === 'PUT') {
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $st = $db->prepare("
        INSERT INTO yy_page_test_style (style_key, heading_font, subheading_font, body_font, updated_dtime)
        VALUES (1, ?, ?, ?, now())
        ON CONFLICT (style_key) DO UPDATE
           SET heading_font    = EXCLUDED.heading_font,
               subheading_font = EXCLUDED.subheading_font,
               body_font       = EXCLUDED.body_font,
               updated_dtime   = now()
        RETURNING updated_dtime
    ");
    $st->execute([
        trim($d['heading_font']    ?? '') ?: null,
        trim($d['subheading_font'] ?? '') ?: null,
        trim($d['body_font']       ?? '') ?: null,
    ]);
    jsonResponse(['saved' => true, 'saved_at' => $st->fetchColumn()]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
