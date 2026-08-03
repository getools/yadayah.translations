<?php
/**
 * One-off: re-sweep already-built TTS audio for comma-grouped thousands numbers
 * now fixed in rewriteBareNumbers() — Chatterbox dropped the leading place, so
 * "1,500" was voiced "five hundred" (reported 2026-07-18). A thousands separator
 * marks the value as a count (never a year), so it is now spelled in full
 * ("one thousand five hundred"). Same positional-part-index technique as
 * _numfix_resweep.php / _clocktime_resweep.php: for every active audio row built
 * with the OLD code (complete or paused), find the paragraphs whose synthesized
 * text now changes, delete their cached p%05d.mp3 parts so they re-synth, and
 * (only for 'complete' rows, with --apply) flip the chapter to 'pending' for the
 * build watchdog.
 *
 * Detection runs the citation and clock rewrites FIRST, then asks whether
 * rewriteBareNumbers() changes anything further, gated on the paragraph actually
 * containing a comma-grouped number — so this stays scoped to the comma fix and
 * does NOT re-flag chapters that only differ under the separate three-digit
 * bare-number sweep (_numfix_resweep.php).
 *
 * All detection uses the local-engine ($spell=true) form, since Azure's
 * normaliser reads digits correctly and is untouched by the fix.
 *
 *   php _commanum_resweep.php                 # dry-run, all rows
 *   php _commanum_resweep.php --ak=NNN        # dry-run, one audio key
 *   php _commanum_resweep.php --apply         # delete parts + reflag complete
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$db = getDb();
$apply = in_array('--apply', $argv, true);
$onlyAk = null;
foreach ($argv as $a) if (preg_match('/^--ak=(\d+)$/', $a, $m)) $onlyAk = (int)$m[1];

$audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';

/** Does the comma-grouped-number fix change this paragraph's synthesized text?
 *  Gated on a comma-grouped number being present so a paragraph whose only
 *  change comes from the separate three-digit sweep is not flagged here. */
$changes = function (string $plain): bool {
    if (!preg_match('/(?<![\d.,:_\x01])[1-9]\d{0,2}(?:,\d{3})+(?![\d.])/u', $plain)) return false;
    $before = rewriteClockTimes(rewriteIslamicCitations($plain, 0, true), true);
    return rewriteBareNumbers($before, true) !== $before;
};

$sql = "SELECT tts_audio_key, chapter_key, volume_key, tts_audio_status
          FROM yy_tts_audio
         WHERE tts_audio_active_flag AND tts_audio_status <> 'running'
           AND tts_audio_status IN ('complete','paused'" . ($onlyAk ? ",'pending'" : "") . ")";
if ($onlyAk) $sql .= " AND tts_audio_key = " . $onlyAk;
$sql .= " ORDER BY tts_audio_key";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$totAudio = 0; $totParts = 0; $reflagged = 0; $primedPaused = 0;
foreach ($rows as $a) {
    $audioKey = (int)$a['tts_audio_key'];
    $chapterKey = (int)$a['chapter_key'];
    $volumeKey = (int)$a['volume_key'];
    $status = $a['tts_audio_status'];

    $skipRanges = [];
    if ($volumeKey) {
        $sr = $db->prepare("SELECT volume_skip_pages FROM yy_volume WHERE volume_key = ?");
        $sr->execute([$volumeKey]);
        foreach (preg_split('/\s*,\s*/', (string)($sr->fetchColumn() ?: ''), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $tok, $m)) $skipRanges[] = [(int)$m[1], (int)$m[2]];
            elseif (preg_match('/^\s*(\d+)\s*$/', $tok, $m))       $skipRanges[] = [(int)$m[1], (int)$m[1]];
        }
    }
    $inSkip = function (?int $pg) use ($skipRanges): bool {
        if ($pg === null) return false;
        foreach ($skipRanges as $r) if ($pg >= $r[0] && $pg <= $r[1]) return true;
        return false;
    };

    $pst = $db->prepare("SELECT paragraph_number, paragraph_page, paragraph_text_plain,
                                paragraph_is_table, paragraph_is_continuation
                           FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
    $pst->execute([$chapterKey]);
    $prows = $pst->fetchAll(PDO::FETCH_ASSOC);

    // Part-index pass — EXACT copy of admin-tts-build.php lines 234-246.
    $playIdxByNum = []; $widx = 0; $lastHeadIdx = null;
    foreach ($prows as $r) {
        $num = (int)$r['paragraph_number'];
        $pg  = $r['paragraph_page'] !== null ? (int)$r['paragraph_page'] : null;
        if (!empty($r['paragraph_is_continuation'])) {
            if ($lastHeadIdx !== null) $playIdxByNum[$num] = $lastHeadIdx;
            continue;
        }
        if (!empty($r['paragraph_is_table']) || $inSkip($pg)) continue;
        $playIdxByNum[$num] = $widx;
        $lastHeadIdx = $widx;
        $widx++;
    }

    // Which paragraphs' synthesized text changes under the bare-number rule?
    $affIdx = []; $affParaCount = 0;
    foreach ($prows as $r) {
        $plain = (string)$r['paragraph_text_plain'];
        if ($plain === '') continue;
        if ($changes($plain)) {
            $num = (int)$r['paragraph_number'];
            if (isset($playIdxByNum[$num])) { $affIdx[$playIdxByNum[$num]] = true; $affParaCount++; }
        }
    }
    if (!$affIdx) continue;

    $partsDir = $audioBase . '/u/tts-parts/' . $audioKey;
    $present = []; $absent = 0;
    foreach (array_keys($affIdx) as $idx) {
        $pf = $partsDir . sprintf('/p%05d.mp3', $idx);
        if (is_file($pf)) $present[] = $idx; else $absent++;
    }

    printf("ak=%-5d st=%-8s chap=%-6d nums=%-3d part_del=%-3d absent=%d\n",
           $audioKey, $status, $chapterKey, $affParaCount, count($present), $absent);
    $totAudio++; $totParts += count($present);

    if ($apply) {
        foreach ($present as $idx) @unlink($partsDir . sprintf('/p%05d.mp3', $idx));
        if ($status === 'complete') {
            $db->prepare("UPDATE yy_tts_audio
                             SET tts_audio_status='pending', tts_audio_worker_pid=NULL,
                                 tts_audio_message='re-queued: comma-grouped number pronunciation fix (resweep)'
                           WHERE tts_audio_key=? AND tts_audio_status='complete'")->execute([$audioKey]);
            $reflagged++;
        } else {
            $primedPaused++;
        }
    }
}

printf("\n%s: %d audio rows affected, %d stale parts %s; reflagged(complete)=%d, primed(paused)=%d\n",
       $apply ? 'APPLIED' : 'DRY-RUN',
       $totAudio, $totParts, $apply ? 'deleted' : 'would delete',
       $reflagged, $primedPaused);
