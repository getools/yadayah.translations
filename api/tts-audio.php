<?php
/**
 * Flipbook TTS audio endpoint. Public (read-only); used by
 * /js/flipbook-tts.js to drive the per-chapter Play/Pause button.
 *
 *   GET ?book_code=YY-…&page=N
 *
 * Resolves the chapter that contains the given page, then returns the
 * "complete" TTS audio for that chapter (if any) plus a flat list of
 * paragraph→offset markers used for page-sync during playback. Also
 * returns a hint to the next chapter (key + whether its audio is ready)
 * and the configured "New Chapter" pause length used between MP3s.
 *
 * Response shape:
 *   {
 *     available: bool,
 *     audio_url: "/u/...mp3" | null,
 *     duration_secs: int,
 *     chapter_key: int,
 *     chapter_number: string,
 *     chapter_name: string,
 *     markers: [{ paragraph_page, paragraph_number, offset_ms }, ...],
 *     next_chapter_key: int | null,
 *     next_chapter_available: bool,
 *     chapter_pause_ms: int   // playback pause between chapters
 *   }
 */
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') errorResponse('Method not allowed', 405);

$bookCode = trim((string)($_GET['book_code'] ?? ''));
$page     = max(1, (int)($_GET['page'] ?? 1));
$explicitChapter = isset($_GET['chapter_key']) && $_GET['chapter_key'] !== '' ? (int)$_GET['chapter_key'] : null;
if ($bookCode === '') errorResponse('book_code required');

$db = getDb();

// Resolve volume.
$vStmt = $db->prepare("SELECT volume_key FROM yy_volume WHERE volume_code = ? LIMIT 1");
$vStmt->execute([$bookCode]);
$volumeKey = (int)($vStmt->fetchColumn() ?: 0);
if (!$volumeKey) jsonResponse(['available' => false, 'reason' => 'book_code not found']);

// "New Chapter" pause — single per-installation setting. We pick the
// max across any TTS voices that have it configured so disabling one
// voice's row doesn't accidentally zero this out.
$pauseStmt = $db->prepare("
    SELECT COALESCE(MAX(tts_pause_ms), 2000) AS ms
      FROM yy_tts_pause
     WHERE tts_pause_search = '__new_chapter__'
       AND tts_pause_active_flag
");
$pauseStmt->execute();
$chapterPauseMs = (int)$pauseStmt->fetchColumn();

// Find the chapter containing the given page. Use yy_paragraph as the
// source of truth — every paragraph has chapter_key + paragraph_page.
if ($explicitChapter) {
    $chapterKey = $explicitChapter;
} else {
    $cStmt = $db->prepare("
        SELECT chapter_key
          FROM yy_paragraph
         WHERE volume_key = ?
           AND paragraph_page <= ?
           AND chapter_key IS NOT NULL
         ORDER BY paragraph_page DESC, paragraph_number DESC
         LIMIT 1
    ");
    $cStmt->execute([$volumeKey, $page]);
    $chapterKey = (int)($cStmt->fetchColumn() ?: 0);
}

if (!$chapterKey) {
    jsonResponse([
        'available'        => false,
        'volume_key'       => $volumeKey,
        'chapter_pause_ms' => $chapterPauseMs,
        'reason'           => 'no chapter for page',
    ]);
}

// Fetch chapter metadata + the audio row (if any). Prefer
// status='complete'; some installs use 'ready', accept both.
// The worker only sets tts_audio_live_dtime when it atomically swaps a
// CLEAN build (zero failures) into the live MP3 path — so its presence
// is our durable signal that whatever's at tts_audio_path is safe to
// play, regardless of whether a more recent rebuild attempt failed.
// tts_audio_active_flag = FALSE hides the chapter audio from the flipbook
// without deleting the file — admin can flip it back to TRUE to re-expose
// the same MP3. The Play button on the chapter simply doesn't appear when
// the row falls out of this join.
$aStmt = $db->prepare("
    SELECT c.chapter_key, c.chapter_number, c.chapter_name,
           a.tts_audio_key, a.tts_audio_path, a.tts_audio_duration_secs, a.tts_audio_status,
           a.tts_audio_revision_dtime, a.tts_audio_live_dtime
      FROM yy_chapter c
      LEFT JOIN yy_tts_audio a
        ON a.chapter_key = c.chapter_key
       AND a.volume_key  = ?
       AND a.tts_audio_path IS NOT NULL
       AND a.tts_audio_live_dtime IS NOT NULL
       AND a.tts_audio_active_flag = TRUE
     WHERE c.chapter_key = ?
     LIMIT 1
");
$aStmt->execute([$volumeKey, $chapterKey]);
$row = $aStmt->fetch();

if (!$row) {
    jsonResponse([
        'available'        => false,
        'volume_key'       => $volumeKey,
        'chapter_pause_ms' => $chapterPauseMs,
        'reason'           => 'chapter row not found',
    ]);
}

// Compute next chapter key by paragraph order (= same logic the search
// link UI uses), then check whether its audio is ready too.
$nStmt = $db->prepare("
    SELECT p.chapter_key
      FROM yy_paragraph p
     WHERE p.volume_key = ?
       AND p.chapter_key IS NOT NULL
       AND p.chapter_key <> ?
       AND p.paragraph_number > (
            SELECT MAX(paragraph_number) FROM yy_paragraph
             WHERE volume_key = ? AND chapter_key = ?
       )
     ORDER BY p.paragraph_number
     LIMIT 1
");
$nStmt->execute([$volumeKey, $chapterKey, $volumeKey, $chapterKey]);
$nextChapterKey = (int)($nStmt->fetchColumn() ?: 0) ?: null;

$nextAvailable = false;
if ($nextChapterKey) {
    $checkStmt = $db->prepare("
        SELECT 1 FROM yy_tts_audio
         WHERE volume_key = ? AND chapter_key = ?
           AND tts_audio_path IS NOT NULL
           AND tts_audio_live_dtime IS NOT NULL
           AND tts_audio_active_flag = TRUE
         LIMIT 1
    ");
    $checkStmt->execute([$volumeKey, $nextChapterKey]);
    $nextAvailable = (bool)$checkStmt->fetchColumn();
}

// The LEFT JOIN already filtered to rows where tts_audio_live_dtime is
// set (i.e. the worker promoted a clean build into place). If $row has
// no audio_key at all, the chapter has never had a successful clean
// build — return unavailable.
$audioReady = !empty($row['tts_audio_path']) && !empty($row['tts_audio_live_dtime']);
$markers    = [];
if ($audioReady) {
    // Join each marker to its paragraph_text_plain so the viewer can
    // span-match the currently-spoken paragraph against the page's
    // text-layer and highlight only the portion on the visible page.
    // For a chapter with N paragraphs this is one extra TEXT column
    // per row — ~50-150KB per chapter, fetched once.
    // Match markers to their paragraph_text_plain via the natural key
    // (volume + chapter + paragraph_number) rather than the optional
    // paragraph_key column — older markers were inserted without
    // paragraph_key set and would otherwise come back without text.
    $mStmt = $db->prepare("
        SELECT m.paragraph_page,
               m.paragraph_number,
               m.tts_audio_marker_offset_ms AS offset_ms,
               p.paragraph_text_plain
          FROM yy_tts_audio_marker m
          LEFT JOIN yy_paragraph p
                 ON p.volume_key       = ?
                AND p.chapter_key      = ?
                AND p.paragraph_number = m.paragraph_number
         WHERE m.tts_audio_key = ?
         ORDER BY m.paragraph_number, m.paragraph_page
    ");
    $mStmt->execute([$volumeKey, $chapterKey, (int)$row['tts_audio_key']]);
    $markers = array_map(function ($m) {
        return [
            'paragraph_page'   => (int)$m['paragraph_page'],
            'paragraph_number' => (int)$m['paragraph_number'],
            'offset_ms'        => (int)$m['offset_ms'],
            'text'             => $m['paragraph_text_plain'] ?? '',
        ];
    }, $mStmt->fetchAll());
}

// Cache-bust the MP3: when the audio gets regenerated the markers update
// alongside it, but the file path stays the same and Cloudflare / the
// browser will keep serving the old MP3. Append the revision timestamp
// (unix epoch) so a rebuild forces a fresh fetch.
$audioUrl = null;
if ($audioReady) {
    $audioUrl = $row['tts_audio_path'];
    $rev = $row['tts_audio_revision_dtime'] ?? null;
    if ($rev) {
        $ts = strtotime((string)$rev);
        if ($ts) $audioUrl .= (strpos($audioUrl, '?') === false ? '?' : '&') . 'v=' . $ts;
    }
}

jsonResponse([
    'available'              => $audioReady,
    'volume_key'             => $volumeKey,
    'chapter_key'            => (int)$row['chapter_key'],
    'chapter_number'         => $row['chapter_number'],
    'chapter_name'           => $row['chapter_name'],
    'audio_url'              => $audioUrl,
    'duration_secs'          => $audioReady ? (int)$row['tts_audio_duration_secs'] : 0,
    'markers'                => $markers,
    'next_chapter_key'       => $nextChapterKey,
    'next_chapter_available' => $nextAvailable,
    'chapter_pause_ms'       => $chapterPauseMs,
]);
