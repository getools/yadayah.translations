<?php
/**
 * Cascading dropdown source for the "Preview any voice" starting-point picker
 * on admin-tts.html. Four GET modes return the rows for each level; a fifth
 * returns the actual paragraph HTML so the UI can drop it into the preview
 * contenteditable.
 *
 *   ?mode=series                           -> [{key, number, label}]
 *   ?mode=volumes&series_key=N             -> [{key, number, label}]
 *   ?mode=chapters&volume_key=N            -> [{key, number, label}]
 *   ?mode=paragraphs&chapter_key=N         -> [{key, number, page, snippet}]
 *   ?mode=paragraph_text&paragraph_key=N   -> {key, html, plain}
 *
 * "label" is the human-friendly string the dropdown should show.
 * Inactive volumes and table paragraphs are filtered out — they would never
 * be useful starting points for a TTS read.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';
$user = requireAuth();
$db = getDb();

$mode = $_GET['mode'] ?? '';

// Verification: return the EXACT chunk strings the TTS pipeline would synth for
// a given text + Min/Target/Max, so the editor's highlight can be checked
// against reality. Runs the same clean → buildLocalSegment → chunk pipeline.
if ($mode === 'preview_chunks') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $ttsKey = (int)($body['tts_key'] ?? ($_GET['tts_key'] ?? 0));
    $text   = (string)($body['text'] ?? '');
    // Caller MAY pass explicit sizes (live Voices fields, for what-if
    // highlighting); 0/unset ⇒ resolve from the voice's PROVIDER chunk config
    // (single source of truth). No hardcoded size defaults.
    $reqMax = (int)($body['max'] ?? 0); $reqTarget = (int)($body['target'] ?? 0); $reqMin = (int)($body['min'] ?? 0);
    $clean  = ttsCleanPreviewText($text);
    $segText = $clean;
    $cs = ['min' => 0, 'target' => 0, 'max' => 0];
    if ($ttsKey) {
        $cfg = loadTtsConfig($db, $ttsKey);
        if (!empty($cfg['system'])) {
            // category 'main' with no voice override → tunes/pauses applied as in a real preview.
            $seg = buildLocalSegment($clean, $cfg, 'main');
            $segText = (string)($seg['text'] ?? $clean);
            $cs = ttsProviderChunkSizes($cfg, (int)($seg['provider_key'] ?? 0));
        }
    }
    $max    = max(40, min(600, $reqMax    > 0 ? $reqMax    : $cs['max']));
    $target = max(20, min($max, $reqTarget > 0 ? $reqTarget : $cs['target']));
    $min    = max(10, min($target, $reqMin > 0 ? $reqMin    : $cs['min']));
    $chunks = chunkTextForPreview($segText, $min, $target, $max);
    jsonResponse(['chunks' => $chunks, 'seg_text' => $segText, 'count' => count($chunks)]);
}

if ($mode === 'series') {
    // Only return series that actually have at least one volume so the
    // dropdown can't dead-end the user. Inactive volumes count: "inactive"
    // only hides a book from the public site, it is still narratable, so a
    // series whose only volume is inactive must still be reachable here.
    $rows = $db->query("
        SELECT s.series_key, s.series_number, COALESCE(s.series_label, s.series_name) AS label
          FROM yy_series s
         WHERE EXISTS (SELECT 1 FROM yy_volume v WHERE v.series_key = s.series_key)
         ORDER BY s.series_sort, s.series_number
    ")->fetchAll();
    jsonResponse(['rows' => array_map(function ($r) {
        return [
            'key'    => (int)$r['series_key'],
            'number' => (int)$r['series_number'],
            'label'  => 's0' . $r['series_number'] . ' — ' . $r['label'],
        ];
    }, $rows)]);
}

if ($mode === 'volumes') {
    $seriesKey = (int)($_GET['series_key'] ?? 0);
    if (!$seriesKey) errorResponse('series_key required');
    // Inactive volumes are included — the flag governs public visibility, not
    // narratability — but are labelled so the picker still reads honestly.
    $stmt = $db->prepare("
        SELECT volume_key, volume_number, volume_label,
               COALESCE(volume_active_flag, TRUE) AS volume_active_flag
          FROM yy_volume
         WHERE series_key = ?
         ORDER BY volume_sort, volume_number
    ");
    $stmt->execute([$seriesKey]);
    jsonResponse(['rows' => array_map(function ($r) {
        $inactive = !filter_var($r['volume_active_flag'], FILTER_VALIDATE_BOOLEAN);
        return [
            'key'    => (int)$r['volume_key'],
            'number' => (int)$r['volume_number'],
            'label'  => 'v0' . $r['volume_number'] . ' — ' . ($r['volume_label'] ?: 'untitled')
                      . ($inactive ? ' (inactive)' : ''),
        ];
    }, $stmt->fetchAll())]);
}

if ($mode === 'chapters') {
    $volumeKey = (int)($_GET['volume_key'] ?? 0);
    if (!$volumeKey) errorResponse('volume_key required');
    $stmt = $db->prepare("
        SELECT chapter_key, chapter_number, chapter_name
          FROM yy_chapter
         WHERE volume_key = ?
         ORDER BY chapter_sort, chapter_number
    ");
    $stmt->execute([$volumeKey]);
    jsonResponse(['rows' => array_map(function ($r) {
        return [
            'key'    => (int)$r['chapter_key'],
            'number' => (int)$r['chapter_number'],
            'label'  => 'ch' . str_pad((string)$r['chapter_number'], 2, '0', STR_PAD_LEFT)
                      . ($r['chapter_name'] ? ' — ' . $r['chapter_name'] : ''),
        ];
    }, $stmt->fetchAll())]);
}

// paragraph_page in yy_paragraph stores the PDF physical page index,
// not the footer page the reader sees. The YY books share a 6-page
// front matter (cover + about author + TOC) before chapter 1's footer
// page 1 begins, so we shift by this constant when surfacing pages to
// the operator and when accepting `page` as a filter input — that way
// the picker speaks the same language as the book itself.
const PV_FOOTER_OFFSET = 6;

if ($mode === 'pages') {
    // Distinct content pages, expressed as footer page numbers. Front-matter
    // pages (paragraph_page <= offset) are hidden; table-only paragraphs are
    // excluded the same way the build worker skips them. A chapter_key scopes
    // the list to only the pages spanned by that chapter — the picker passes
    // it so Page is a child of the selected Chapter.
    $volumeKey  = (int)($_GET['volume_key'] ?? 0);
    $chapterKey = (int)($_GET['chapter_key'] ?? 0);
    if (!$volumeKey && !$chapterKey) errorResponse('volume_key or chapter_key required');
    $where  = ['paragraph_is_table = FALSE', 'paragraph_page IS NOT NULL', 'paragraph_page > ?'];
    $params = [PV_FOOTER_OFFSET];
    if ($volumeKey)  { $where[] = 'volume_key = ?';  $params[] = $volumeKey; }
    if ($chapterKey) { $where[] = 'chapter_key = ?'; $params[] = $chapterKey; }
    $stmt = $db->prepare("
        SELECT DISTINCT paragraph_page
          FROM yy_paragraph
         WHERE " . implode(' AND ', $where) . "
         ORDER BY paragraph_page
    ");
    $stmt->execute($params);
    $rows = array_map(function ($r) {
        $footerPg = (int)$r['paragraph_page'] - PV_FOOTER_OFFSET;
        return ['key' => $footerPg, 'number' => $footerPg, 'label' => 'p.' . $footerPg];
    }, $stmt->fetchAll());
    jsonResponse(['rows' => $rows]);
}

if ($mode === 'paragraphs') {
    // Accept any combination of chapter_key, page, volume_key. At least one
    // scoping filter is required so we never accidentally return the whole
    // table. chapter_key and page are sibling filters — either or both
    // narrows the result; the volume_key is implied by chapter_key but is
    // also accepted directly when the operator picked Page first without a
    // chapter.
    $chapterKey = (int)($_GET['chapter_key'] ?? 0);
    $page       = (int)($_GET['page'] ?? 0);
    $volumeKey  = (int)($_GET['volume_key'] ?? 0);
    if (!$chapterKey && !$page) errorResponse('chapter_key or page required');
    $where = ['paragraph_is_table = FALSE'];
    $params = [];
    if ($chapterKey) { $where[] = 'chapter_key = ?'; $params[] = $chapterKey; }
    if ($page) {
        // `page` is the footer page (what the operator sees in the dropdown);
        // the DB stores PDF physical pages, so shift by the same constant
        // used in mode=pages.
        $where[] = 'paragraph_page = ?';
        $params[] = $page + PV_FOOTER_OFFSET;
    }
    if ($volumeKey)  { $where[] = 'volume_key = ?'; $params[] = $volumeKey; }
    // Skip table paragraphs — they were excluded from the build worker and
    // would not make a sensible TTS starting point either.
    $stmt = $db->prepare("
        SELECT paragraph_key, paragraph_number, paragraph_page, paragraph_text_plain
          FROM yy_paragraph
         WHERE " . implode(' AND ', $where) . "
         ORDER BY paragraph_number
    ");
    $stmt->execute($params);
    jsonResponse(['rows' => array_map(function ($r) {
        $plain = (string)($r['paragraph_text_plain'] ?? '');
        $snippet = mb_substr($plain, 0, 70);
        if (mb_strlen($plain) > 70) $snippet .= '…';
        $rawPg = (int)($r['paragraph_page'] ?? 0);
        $footerPg = $rawPg > PV_FOOTER_OFFSET ? ($rawPg - PV_FOOTER_OFFSET) : 0;
        return [
            'key'    => (int)$r['paragraph_key'],
            'number' => (int)$r['paragraph_number'],
            'page'   => $footerPg,
            'label'  => '#' . $r['paragraph_number']
                      . ($footerPg ? ' (p.' . $footerPg . ')' : '')
                      . ($snippet ? ' — ' . $snippet : ''),
        ];
    }, $stmt->fetchAll())]);
}

if ($mode === 'paragraph_text') {
    $paraKey = (int)($_GET['paragraph_key'] ?? 0);
    if (!$paraKey) errorResponse('paragraph_key required');
    $stmt = $db->prepare("
        SELECT paragraph_key, paragraph_text_html, paragraph_text_plain
          FROM yy_paragraph
         WHERE paragraph_key = ?
    ");
    $stmt->execute([$paraKey]);
    $r = $stmt->fetch();
    if (!$r) errorResponse('paragraph not found');
    jsonResponse([
        'key'   => (int)$r['paragraph_key'],
        'html'  => (string)($r['paragraph_text_html'] ?? ''),
        'plain' => (string)($r['paragraph_text_plain'] ?? ''),
    ]);
}

if ($mode === 'combined_text') {
    // Concatenate every in-scope paragraph's stored HTML into one blob the
    // preview field can hold — used by the Load button for page-level and
    // chapter-level reads. Same scoping rules as mode=paragraphs (chapter_key
    // and/or page, optional volume_key). Paragraphs join with a blank line so
    // the preview/build segmentation still sees paragraph boundaries.
    $chapterKey = (int)($_GET['chapter_key'] ?? 0);
    $page       = (int)($_GET['page'] ?? 0);
    $volumeKey  = (int)($_GET['volume_key'] ?? 0);
    if (!$chapterKey && !$page) errorResponse('chapter_key or page required');
    $where  = ['paragraph_is_table = FALSE'];
    $params = [];
    if ($chapterKey) { $where[] = 'chapter_key = ?'; $params[] = $chapterKey; }
    if ($page)       { $where[] = 'paragraph_page = ?'; $params[] = $page + PV_FOOTER_OFFSET; }
    if ($volumeKey)  { $where[] = 'volume_key = ?'; $params[] = $volumeKey; }
    $stmt = $db->prepare("
        SELECT paragraph_text_html, paragraph_text_plain
          FROM yy_paragraph
         WHERE " . implode(' AND ', $where) . "
         ORDER BY paragraph_number
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $htmlParts = [];
    $plainParts = [];
    foreach ($rows as $r) {
        $h = trim((string)($r['paragraph_text_html'] ?? ''));
        $p = trim((string)($r['paragraph_text_plain'] ?? ''));
        if ($h !== '') $htmlParts[] = $h;
        if ($p !== '') $plainParts[] = $p;
    }
    jsonResponse([
        'count' => count($rows),
        'html'  => implode('<br><br>', $htmlParts),
        'plain' => implode("\n\n", $plainParts),
    ]);
}

errorResponse('Unknown mode: ' . $mode);
