<?php
/**
 * Re-anchor page-break CONTINUATION markers to the CORRECTED audio timeline.
 *
 *   php tts-cont-marker-reanchor.php [audio_key] [--apply]
 *
 * The build worker emits each page-break continuation marker (byte_offset NULL)
 * as `paraStartMs + ratio*paragraphMs` from the summed-chunk cumulative estimate.
 * For paragraphs that carry inserted pauses (citation edge pauses, multi-voice
 * quote segments) that loop `paragraphMs` under-counts the assembled span, so the
 * crossing lands SECONDS too early and the flipbook turns the page while the
 * audio is still reading the previous page. Byte-anchored markers are rewritten
 * to true packet-PTS time at build end; these byte-NULL markers are not.
 *
 * This re-interpolates every existing byte-NULL marker between its two CORRECTED
 * byte-anchored neighbours (logical-paragraph head marker → next paragraph
 * marker). Values agree with the already-accurate markers and only move the
 * pathological ones. Touches ONLY byte-NULL markers. Dry-run by default; pass
 * --apply to write. Run tts-cont-onset-fix.php <audio_key> --apply afterwards to
 * STT-refine onsets the char-ratio can only approximate. Markers are served
 * dynamically (tts-audio.php) so no cache bump is needed.
 *
 * The build worker now calls ttsReanchorContinuationMarkers() itself after the
 * byte→time recompute, so future builds don't drift — this tool repairs already-
 * built audio. See memory: reference_tts_audio_seekable_remux_and_markers.
 */

require_once __DIR__ . '/config.php';            // getDb()
require_once __DIR__ . '/admin-tts-helpers.php'; // ttsReanchorContinuationMarkers()

$db        = getDb();
$args      = array_slice($argv, 1);
$apply     = in_array('--apply', $args, true);
$onlyAudio = 0;
foreach ($args as $a) { if (ctype_digit($a)) { $onlyAudio = (int)$a; break; } }

$sql = "SELECT a.tts_audio_key, a.chapter_key, v.volume_code
          FROM yy_tts_audio a
          JOIN yy_volume v ON v.volume_key = a.volume_key
         WHERE a.tts_audio_live_dtime IS NOT NULL
           AND a.tts_audio_active_flag = TRUE";
if ($onlyAudio) $sql .= " AND a.tts_audio_key = " . $onlyAudio;
$sql .= " ORDER BY a.tts_audio_key";
$audios = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$grandUpdated = 0; $grandBig = 0; $audiosTouched = 0; $maxDelta = 0; $maxDeltaWhere = '';
foreach ($audios as $a) {
    $ak = (int)$a['tts_audio_key'];
    $r  = ttsReanchorContinuationMarkers($db, $ak, $apply);
    if ($r['updated'] === 0) continue;
    $audiosTouched++;
    $grandUpdated += $r['updated'];
    $big = 0;
    foreach ($r['rows'] as $row) {
        if (abs($row['delta']) >= 2000) $big++;
        if (abs($row['delta']) > abs($maxDelta)) { $maxDelta = $row['delta']; $maxDeltaWhere = "audio $ak ¶{$row['number']} pg{$row['page']}"; }
    }
    $grandBig += $big;
    printf("audio %-5d (%s ch %d): %s %d marker(s), %d shifted >=2s\n",
        $ak, $a['volume_code'], (int)$a['chapter_key'], $apply ? 'updated' : 'would-update',
        $r['updated'], $big);
}

printf("\n%s %d continuation marker(s) across %d audio chapter(s); %d shifted >=2s. Max shift %+dms (%s).\n",
    $apply ? 'UPDATED' : 'WOULD-UPDATE', $grandUpdated, $audiosTouched, $grandBig, $maxDelta, $maxDeltaWhere);
if (!$apply) echo "Dry-run. Re-run with --apply to write, then run tts-cont-onset-fix.php per audio to STT-refine.\n";
