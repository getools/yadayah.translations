<?php
/**
 * One-off: re-sweep already-built LOCAL-engine TTS audio for paragraphs where a
 * bold/italic-restricted pronunciation tune was errantly applied OUTSIDE its
 * qualifying (<b>/<i>) region by the pre-2026-07-10 local-engine code (the
 * blanket preg_replace that ignored match_bold/match_italic).
 *
 * A paragraph is AFFECTED iff some restricted tune's Print matches in a region
 * where the NEW gated code would NOT substitute (i.e. the mispronunciation was
 * baked into the audio and a rebuild with the fixed code will now correct it).
 * Azure/SSML audio is unaffected (that path always gated), so we scope to the
 * local Chatterbox profile (pk=8) — which is the only profile with built audio.
 *
 * For each affected paragraph we delete its positional cached part file so it
 * re-synths, and (with --apply, for 'complete' rows) flip the chapter to
 * 'pending' so the build watchdog rebuilds it, reusing every unaffected part.
 *
 *   php _matchfilter_resweep.php                 # dry-run, all local rows
 *   php _matchfilter_resweep.php --ak=NNN        # dry-run, one audio key
 *   php _matchfilter_resweep.php --apply         # delete parts + reflag complete
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$db = getDb();
$apply  = in_array('--apply', $argv, true);
$onlyAk = null;
foreach ($argv as $a) if (preg_match('/^--ak=(\d+)$/', $a, $m)) $onlyAk = (int)$m[1];
$audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
$LOCAL_PROFILE = 8;   // Chatterbox-Craig/Dionisio — the only profile with built audio

// ── Restricted tunes: build one gated matcher per tune. Uses the SAME
//    tunePrintToRegex + apostrophe-class normalisation the synth path uses. ──
$restr = [];
$rt = $db->query("SELECT tts_tune_print, tts_tune_match_bold, tts_tune_match_italic,
                         tts_tune_match_case_sensitive
                    FROM yy_tts_tune
                   WHERE tts_tune_active_flag
                     AND (tts_tune_match_bold IS TRUE OR tts_tune_match_italic IS TRUE)");
foreach ($rt as $t) {
    $print = (string)$t['tts_tune_print'];
    if ($print === '') continue;
    $restr[] = [
        're' => tunePrintToRegex($print, !empty($t['tts_tune_match_case_sensitive'])),
        'nb' => !empty($t['tts_tune_match_bold']),
        'ni' => !empty($t['tts_tune_match_italic']),
        'print' => $print,
    ];
}
if (!$restr) { fwrite(STDERR, "no restricted tunes found\n"); exit(1); }
fprintf(STDERR, "restricted tunes: %d\n", count($restr));

/**
 * True iff some restricted tune matches this HTML in a NON-qualifying region
 * — exactly where the old blanket code substituted but the new gated code
 * would not. Walks <b>/<i> depth identically to applyTuneInTaggedRegions.
 */
function paraAffected(string $html, array $restr): bool {
    if ($html === '') return false;
    if (!preg_match_all('/<\/?[bi]\b[^>]*>|[^<]+/i', $html, $m)) return false;
    $bold = 0; $italic = 0;
    foreach ($m[0] as $piece) {
        if ($piece[0] === '<') {
            $low = strtolower($piece);
            if    (strpos($low, '<b')  === 0 && $low[1] !== '/') $bold++;
            elseif (strpos($low, '</b') === 0) $bold = max(0, $bold - 1);
            elseif (strpos($low, '<i')  === 0 && $low[1] !== '/') $italic++;
            elseif (strpos($low, '</i') === 0) $italic = max(0, $italic - 1);
        } else {
            foreach ($restr as $r) {
                // Qualifying region (where the NEW code also substitutes) → no
                // difference from the old audio, so ignore matches there.
                $qual = (!$r['nb'] || $bold > 0) && (!$r['ni'] || $italic > 0);
                if ($qual) continue;
                if (preg_match($r['re'], $piece)) return true;
            }
        }
    }
    return false;
}

$sql = "SELECT tts_audio_key, chapter_key, volume_key, tts_audio_status
          FROM yy_tts_audio
         WHERE tts_audio_active_flag AND tts_audio_status <> 'running'
           AND tts_profile_key = $LOCAL_PROFILE
           AND tts_audio_status IN ('complete','paused'" . ($onlyAk ? ",'pending'" : "") . ")";
if ($onlyAk) $sql .= " AND tts_audio_key = " . $onlyAk;
$sql .= " ORDER BY tts_audio_key";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$totAudio = 0; $totParts = 0; $totParas = 0; $reflagged = 0; $primedPaused = 0; $absentTot = 0;
foreach ($rows as $a) {
    $audioKey = (int)$a['tts_audio_key'];
    $chapterKey = (int)$a['chapter_key'];
    $volumeKey = (int)$a['volume_key'];
    $status = $a['tts_audio_status'];

    // Skip-page ranges (same source the build worker honours).
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

    $pst = $db->prepare("SELECT paragraph_number, paragraph_page, paragraph_text_html,
                                paragraph_is_table, paragraph_is_continuation
                           FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
    $pst->execute([$chapterKey]);
    $prows = $pst->fetchAll(PDO::FETCH_ASSOC);

    // Positional play-index per paragraph_number — mirrors the build worker.
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

    // Affected play-indices (a continuation folds onto its head's index).
    $affIdx = []; $affParaCount = 0;
    foreach ($prows as $r) {
        $html = (string)$r['paragraph_text_html'];
        if ($html === '') continue;
        if (paraAffected($html, $restr)) {
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

    printf("ak=%-5d st=%-8s chap=%-6d aff_para=%-4d parts=%-4d absent=%d\n",
           $audioKey, $status, $chapterKey, $affParaCount, count($present), $absent);
    $totAudio++; $totParts += count($present); $totParas += $affParaCount; $absentTot += $absent;

    if ($apply) {
        foreach ($present as $idx) @unlink($partsDir . sprintf('/p%05d.mp3', $idx));
        if ($status === 'complete') {
            $db->prepare("UPDATE yy_tts_audio
                             SET tts_audio_status='pending', tts_audio_worker_pid=NULL,
                                 tts_audio_message='re-queued: bold/italic match-filter resweep'
                           WHERE tts_audio_key=? AND tts_audio_status='complete'")->execute([$audioKey]);
            $reflagged++;
        } else {
            $primedPaused++;
        }
    }
}

printf("\n%s: %d audio rows affected, %d paragraphs, %d stale parts %s (absent=%d); reflagged(complete)=%d, primed(paused)=%d\n",
       $apply ? 'APPLIED' : 'DRY-RUN',
       $totAudio, $totParas, $totParts, $apply ? 'deleted' : 'would delete', $absentTot,
       $reflagged, $primedPaused);
