<?php
/**
 * Delete SPURIOUS page-crossing markers created by a running-header match.
 *
 *   php tts-header-marker-cleanup.php [audio_key] [--apply]
 *
 * The build worker emits a page-crossing marker for a paragraph that overflows
 * onto the next page by finding the LARGEST suffix of the paragraph that also
 * appears near the top of page N+1 (ttsFindPageBreakRatios). It used to accept a
 * match of the WHOLE paragraph, which is not a crossing at all — if every
 * character is on page N+1 then none of it is on page N. Two things produce a
 * whole-paragraph match:
 *
 *   1. The running header. Every page repeats the chapter title verbatim at the
 *      top, and the chapter TITLE paragraph's text IS that header. So the title
 *      paragraph got a crossing marker at ratio 0 — i.e. at its own start time.
 *   2. A repeated refrain (e.g. "Quran 055.0NN So which favors of your Lord
 *      will you both deny") recurring on the following page.
 *
 * Symptom (reported on YY-s02v04 page 7): the flipbook turns FORWARD to page 8
 * the moment the chapter title starts being read, then snaps BACK to page 7 at
 * the next paragraph ("Man's World..."). The client's pageAtMs() takes the
 * largest paragraph_page among markers at or before the playhead, so a ratio-0
 * page-8 marker sharing the title's start time wins until the next marker.
 *
 * The build worker now rejects a whole-paragraph match (`$bestL >= $plen`).
 * This tool repairs audio already built, WITHOUT re-synthesising: for every
 * byte-NULL marker that the fallback (head-keyed) path could have produced, it
 * re-reads the flipbook page-text layer and deletes the marker when the whole
 * normalized paragraph is present near the top of the marked page.
 *
 * Only head-keyed fallback markers are considered — a marker is a candidate
 * ONLY if the same (audio, paragraph_number) also has a byte-anchored marker on
 * an EARLIER page. Genuine continuation markers are keyed to the continuation
 * paragraph, which is coalesced into its head for synthesis and therefore never
 * has a byte-anchored marker of its own, so they can't match this shape and are
 * never touched.
 *
 * Dry-run by default; pass --apply to delete. Markers are served dynamically
 * (tts-audio.php) so no cache bump is needed.
 *
 * See memory: reference_tts_audio_seekable_remux_and_markers,
 *             reference_pdf_running_header_leak.
 */

require_once __DIR__ . '/config.php';   // getDb()

$db        = getDb();
$args      = array_slice($argv, 1);
$apply     = in_array('--apply', $args, true);
$onlyAudio = 0;
foreach ($args as $a) { if (ctype_digit($a)) { $onlyAudio = (int)$a; break; } }

// Same normalization the build worker matches with (ttsNormalizeForMatch).
function hmcNormalize(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[\x{02BF}\x{02BE}\x{02BC}\x{02BB}\x{02B9}\x{02BA}\x{2018}\x{2019}\x{201C}\x{201D}\x{2013}\x{2014}\x{0027}]/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim((string)$s);
}

function hmcPageText(string $bundleDir, int $page, array &$cache): string {
    if (isset($cache[$page])) return $cache[$page];
    $f = sprintf('%s/text/page-%03d.json', $bundleDir, $page);
    if (!is_file($f)) return $cache[$page] = '';
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j) || empty($j['spans'])) return $cache[$page] = '';
    $buf = '';
    foreach ($j['spans'] as $sp) {
        if (isset($sp[4])) $buf .= $sp[4] . ' ';
    }
    return $cache[$page] = $buf;
}

$sql = "SELECT a.tts_audio_key, a.volume_key, a.chapter_key, v.volume_code
          FROM yy_tts_audio a
          JOIN yy_volume v ON v.volume_key = a.volume_key
         WHERE a.tts_audio_live_dtime IS NOT NULL
           AND a.tts_audio_active_flag = TRUE";
if ($onlyAudio) $sql .= " AND a.tts_audio_key = " . $onlyAudio;
$sql .= " ORDER BY a.tts_audio_key";
$audios = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Candidate markers: byte-NULL, and the same (audio, paragraph_number) also has
// a byte-anchored marker on an earlier page => head-keyed fallback-path marker.
$cand = $db->prepare("
    SELECT n.tts_audio_marker_key, n.paragraph_number, n.paragraph_page,
           n.tts_audio_marker_offset_ms AS bad_ms,
           b.paragraph_page             AS head_page,
           b.tts_audio_marker_offset_ms AS head_ms
      FROM yy_tts_audio_marker n
      JOIN yy_tts_audio_marker b
        ON b.tts_audio_key   = n.tts_audio_key
       AND b.paragraph_number = n.paragraph_number
       AND b.tts_audio_marker_byte_offset IS NOT NULL
       AND b.paragraph_page  < n.paragraph_page
     WHERE n.tts_audio_key = ?
       AND n.tts_audio_marker_byte_offset IS NULL
     ORDER BY n.paragraph_number, n.paragraph_page
");
$paraQ = $db->prepare("
    SELECT paragraph_text_plain
      FROM yy_paragraph
     WHERE chapter_key = ? AND paragraph_number = ?
     ORDER BY paragraph_key
     LIMIT 1
");
$del = $db->prepare("DELETE FROM yy_tts_audio_marker WHERE tts_audio_marker_key = ?");

$grandDeleted = 0;
$grandNoBundle = 0;
$audiosTouched = 0;

foreach ($audios as $a) {
    $ak   = (int)$a['tts_audio_key'];
    $ck   = (int)$a['chapter_key'];
    $slug = (string)$a['volume_code'];

    $cand->execute([$ak]);
    $rows = $cand->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) continue;

    $bundleDir = '/opt/yada-www/public/' . $slug;
    if (!is_dir($bundleDir)) $bundleDir = dirname(__DIR__) . '/' . $slug;
    if (!is_dir($bundleDir)) { $grandNoBundle += count($rows); continue; }

    $cache = [];
    $hits  = [];
    foreach ($rows as $r) {
        $paraQ->execute([$ck, (int)$r['paragraph_number']]);
        $txt = (string)($paraQ->fetchColumn() ?: '');
        if ($txt === '') continue;
        $p    = hmcNormalize($txt);
        $plen = mb_strlen($p);
        if ($plen < 30) continue;   // worker never emits below this floor

        // Primary test — the invariant the fixed worker now enforces: if the
        // whole paragraph sits on its OWN page it cannot overflow onto the
        // next, so the crossing is fictional. Compared space-INSENSITIVELY
        // because the flipbook text layer splits words around half-rings
        // ("Mow ʿ ed" for "Mowʿed"), which a whitespace-sensitive compare
        // would miss on exactly the titles this is meant to catch.
        $tight = static function (string $s): string {
            return (string)preg_replace('/\s+/u', '', $s);
        };
        $pTight = $tight($p);
        $own    = $tight(hmcNormalize(hmcPageText($bundleDir, (int)$r['head_page'], $cache)));
        $why    = '';
        if ($pTight !== '' && $own !== '' && mb_strpos($own, $pTight) !== false) {
            $why = 'whole paragraph on its own page ' . (int)$r['head_page'];
        } else {
            // Secondary test: the whole paragraph found near the top of the
            // MARKED page (running header / repeated refrain) — a duplicate,
            // not a continuation.
            $g = hmcNormalize(hmcPageText($bundleDir, (int)$r['paragraph_page'], $cache));
            if ($g === '') continue;
            $pos = mb_strpos($g, $p);
            if ($pos === false || $pos >= 400) continue;
            $why = 'whole paragraph repeated atop page ' . (int)$r['paragraph_page'];
        }

        $hits[] = $r + ['txt' => $txt, 'why' => $why];
    }
    if (!$hits) continue;

    $audiosTouched++;
    echo "audio $ak  ($slug)\n";
    foreach ($hits as $h) {
        printf("   %s marker %d  \xc2\xb6%d  page %d->%d  ms %d (head %d)  \"%s\"  [%s]\n",
            $apply ? 'DELETE' : 'would delete',
            (int)$h['tts_audio_marker_key'], (int)$h['paragraph_number'],
            (int)$h['head_page'], (int)$h['paragraph_page'],
            (int)$h['bad_ms'], (int)$h['head_ms'],
            mb_substr(preg_replace('/\s+/u', ' ', $h['txt']), 0, 60), $h['why']);
        if ($apply) $del->execute([(int)$h['tts_audio_marker_key']]);
        $grandDeleted++;
    }
}

echo "\n" . ($apply ? "DELETED" : "WOULD DELETE")
   . " $grandDeleted spurious running-header marker(s) across $audiosTouched audio(s).\n";
if ($grandNoBundle) echo "skipped $grandNoBundle candidate(s): flipbook bundle dir missing.\n";
if (!$apply) echo "(dry run — pass --apply to write)\n";
