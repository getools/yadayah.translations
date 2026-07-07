<?php
/**
 * Backfill MISSING page-break CONTINUATION markers on already-built audio.
 *
 *   php tts-cont-marker-backfill.php [audio_key] [--apply]
 *
 * When one logical paragraph wraps across a page break, the build coalesces the
 * tail into the head for synthesis and is supposed to emit a NULL-byte
 * "continuation" marker keyed to the tail (so the flipbook seeks page N's audio
 * to where reading crosses onto page N). For a long stretch of builds the
 * emitter only fired when a fuzzy text match against the flipbook page-text
 * layer succeeded, and silently emitted nothing otherwise — so ~31% of
 * continuation pages have NO marker and the flipbook starts their audio at the
 * next full paragraph ("audio starts way down the page").
 *
 * This tool reconstructs the same logical paragraphs from yy_paragraph, and for
 * every page a logical paragraph wraps onto WITHOUT an existing marker, inserts a
 * NULL-byte marker keyed to the continuation paragraph. The provisional offset is
 * a char-ratio interpolation between the head's byte-derived marker and the next
 * paragraph's marker — exactly what the (now fixed) build worker's fallback
 * produces. Run tts-cont-onset-fix.php <audio_key> --apply afterwards to
 * STT-align each onset precisely. NO audio is regenerated.
 *
 * Idempotent: skips any continuation paragraph that already has a marker. Dry-run
 * by default; pass --apply to write. Markers are served dynamically
 * (tts-audio.php) so no cache bump is needed.
 *
 * See memory: reference_tts_audio_seekable_remux_and_markers.
 */

require_once __DIR__ . '/config.php';   // getDb()

$db        = getDb();
$args      = array_slice($argv, 1);
$apply     = in_array('--apply', $args, true);
$onlyAudio = 0;
foreach ($args as $a) { if (ctype_digit($a)) { $onlyAudio = (int)$a; break; } }

$sql = "SELECT a.tts_audio_key, a.volume_key, a.chapter_key, v.volume_code
          FROM yy_tts_audio a
          JOIN yy_volume v ON v.volume_key = a.volume_key
         WHERE a.tts_audio_live_dtime IS NOT NULL
           AND a.tts_audio_active_flag = TRUE";
if ($onlyAudio) $sql .= " AND a.tts_audio_key = " . $onlyAudio;
$sql .= " ORDER BY a.tts_audio_key";
$audios = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$ins = $db->prepare("
    INSERT INTO yy_tts_audio_marker
        (tts_audio_key, paragraph_key, paragraph_page, paragraph_number,
         tts_audio_marker_offset_ms, tts_audio_marker_byte_offset)
    VALUES (?, ?, ?, ?, ?, NULL)
    ON CONFLICT (tts_audio_key, paragraph_number, paragraph_page) DO NOTHING
");

$grandInserted = 0;
$grandSkippedNoHead = 0;
$audiosTouched = 0;

foreach ($audios as $a) {
    $ak = (int)$a['tts_audio_key'];
    $vk = (int)$a['volume_key'];
    $ck = (int)$a['chapter_key'];

    // Paragraphs in reading order.
    $ps = $db->prepare("
        SELECT paragraph_key, paragraph_number, paragraph_page,
               paragraph_is_continuation, paragraph_text_plain
          FROM yy_paragraph
         WHERE volume_key = ? AND chapter_key = ?
         ORDER BY paragraph_number
    ");
    $ps->execute([$vk, $ck]);
    $paras = $ps->fetchAll(PDO::FETCH_ASSOC);
    if (!$paras) continue;

    // Existing markers: paragraph_number => byte-derived (preferred) offset_ms.
    // Presence is tracked by paragraph_number — a continuation paragraph maps to
    // exactly one page, so number-presence == this-crossing-present.
    $ms = $db->prepare("
        SELECT paragraph_number, tts_audio_marker_offset_ms AS off_ms,
               tts_audio_marker_byte_offset AS byte
          FROM yy_tts_audio_marker
         WHERE tts_audio_key = ?
    ");
    $ms->execute([$ak]);
    $offByNum = [];   // number => offset_ms (byte-derived wins)
    $haveNum  = [];   // number => true (any marker)
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $n = (int)$m['paragraph_number'];
        $haveNum[$n] = true;
        if ($m['byte'] !== null)      $offByNum[$n] = (int)$m['off_ms'];
        elseif (!isset($offByNum[$n])) $offByNum[$n] = (int)$m['off_ms'];
    }

    $insertedHere = 0;
    $N = count($paras);
    $i = 0;
    while ($i < $N) {
        $head = $paras[$i];
        if (!empty($head['paragraph_is_continuation'])) { $i++; continue; } // orphan tail
        $headNum  = (int)$head['paragraph_number'];
        $combined = rtrim((string)$head['paragraph_text_plain']);

        // Gather this logical paragraph's continuation crossings (first tail to
        // open a page wins), recording the char offset where each begins.
        $crossings = [];
        $j = $i + 1;
        while ($j < $N && !empty($paras[$j]['paragraph_is_continuation'])) {
            $tail    = $paras[$j];
            $charAt  = mb_strlen($combined . ' ');
            $page    = $tail['paragraph_page'] !== null ? (int)$tail['paragraph_page'] : null;
            if ($page !== null) {
                $seen = false;
                foreach ($crossings as $c) if ($c['page'] === $page) { $seen = true; break; }
                if (!$seen) {
                    $crossings[] = [
                        'page'    => $page,
                        'number'  => (int)$tail['paragraph_number'],
                        'key'     => $tail['paragraph_key'] !== null ? (int)$tail['paragraph_key'] : null,
                        'char_at' => $charAt,
                    ];
                }
            }
            $combined = rtrim($combined) . ' ' . ltrim((string)$tail['paragraph_text_plain']);
            $j++;
        }

        if ($crossings) {
            $totalLen    = max(1, mb_strlen($combined));
            $paraStartMs = $offByNum[$headNum] ?? null;         // head marker (byte-derived)
            $nextMs      = null;
            if ($j < $N) {
                $nextNum = (int)$paras[$j]['paragraph_number'];
                $nextMs  = $offByNum[$nextNum] ?? null;
            }
            if ($paraStartMs === null) {
                // Head itself has no marker — can't interpolate. Rare; report.
                foreach ($crossings as $c) if (!isset($haveNum[$c['number']])) $grandSkippedNoHead++;
            } else {
                $paragraphMs = ($nextMs !== null && $nextMs > $paraStartMs) ? ($nextMs - $paraStartMs) : null;
                foreach ($crossings as $c) {
                    if (isset($haveNum[$c['number']])) continue;   // already has a marker
                    $ratio = min(0.999, max(0.0, $c['char_at'] / $totalLen));
                    $brkMs = $paragraphMs !== null
                           ? (int)round($paraStartMs + $ratio * $paragraphMs)
                           : $paraStartMs + 1;                     // no next bound: nudge past head
                    if ($apply) $ins->execute([$ak, $c['key'], $c['page'], $c['number'], $brkMs]);
                    $insertedHere++;
                }
            }
        }
        $i = $j;
    }

    if ($insertedHere > 0) {
        $audiosTouched++;
        $grandInserted += $insertedHere;
        printf("audio %d (%s ch %d): %s %d marker(s)\n",
            $ak, $a['volume_code'], $ck, $apply ? 'inserted' : 'would-insert', $insertedHere);
    }
}

printf("\n%s %d continuation marker(s) across %d audio chapter(s).%s\n",
    $apply ? 'INSERTED' : 'WOULD-INSERT', $grandInserted, $audiosTouched,
    $grandSkippedNoHead ? " ($grandSkippedNoHead skipped — head paragraph has no marker)" : '');
if (!$apply) echo "Dry-run. Re-run with --apply to write, then run tts-cont-onset-fix.php per audio.\n";
