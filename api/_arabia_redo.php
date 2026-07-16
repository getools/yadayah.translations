<?php
/**
 * CORRECTED Arabia/arab re-synth (replaces the broken _arabia_resweep.php).
 *
 * The old resweep reconstructed the part index but OMITTED the back-matter
 * filter, so it deleted the wrong p<NNNNN>.mp3 (off by the number of
 * back-matter paragraphs before the target). This version reproduces the
 * admin status endpoint's Pass-1 mapping EXACTLY (admin-tts-build.php:248-262),
 * including ttsBackMatterCutoff() -> $isBackMatter. That is the same index the
 * worker uses and the same index the working per-paragraph Rebuild passes to
 * redo_paragraph.
 *
 *   php _arabia_redo.php --ak=NNN            # dry-run one chapter: show idx+page+text
 *   php _arabia_redo.php --ak=NNN --apply    # redo just that chapter's Arabia parts
 *   php _arabia_redo.php --apply             # all affected chapters
 *
 * MUST run inside the web container (media-root guard below).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$TUNE_RES = [
    'arab'   => tunePrintToRegex('arab', false),
    'Arabia' => tunePrintToRegex('Arabia', false),
];

$db     = getDb();
$apply  = in_array('--apply', $argv, true);
$onlyAk = null;
foreach ($argv as $a) if (preg_match('/^--ak=(\d+)$/', $a, $m)) $onlyAk = (int)$m[1];

$audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
if (!is_dir($audioBase . '/u/tts-parts')) {
    fwrite(STDERR, "ABORT: no tts-parts under {$audioBase}/u -- run INSIDE the web container.\n");
    exit(1);
}

$sql = "SELECT tts_audio_key, chapter_key, volume_key, tts_audio_status
          FROM yy_tts_audio
         WHERE tts_audio_active_flag AND tts_audio_status IN ('complete','paused','pending')
           AND tts_audio_status <> 'running'";
if ($onlyAk) $sql .= " AND tts_audio_key = " . $onlyAk;
$sql .= " ORDER BY tts_audio_key";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$totAudio = 0; $totParts = 0; $reflagged = 0; $primedPending = 0; $primedPaused = 0;

foreach ($rows as $a) {
    $audioKey   = (int)$a['tts_audio_key'];
    $chapterKey = (int)$a['chapter_key'];
    $volumeKey  = (int)$a['volume_key'];
    $status     = $a['tts_audio_status'];

    // --- skip-page ranges (worker/endpoint parsing) ---
    $skipRanges = [];
    if ($volumeKey) {
        $sr = $db->prepare("SELECT volume_skip_pages FROM yy_volume WHERE volume_key = ?");
        $sr->execute([$volumeKey]);
        foreach (preg_split('/\s*,\s*/', (string)($sr->fetchColumn() ?: ''), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $tok, $m)) $skipRanges[] = [(int)$m[1], (int)$m[2]];
            elseif (preg_match('/^\s*(\d+)\s*$/', $tok, $m))         $skipRanges[] = [(int)$m[1], (int)$m[1]];
        }
    }
    $inSkip = function (?int $pg) use ($skipRanges): bool {
        if ($pg === null) return false;
        foreach ($skipRanges as $r) if ($pg >= $r[0] && $pg <= $r[1]) return true;
        return false;
    };
    // --- THE FIX: back-matter cutoff, exactly like the endpoint/worker ---
    $backMatterFrom = $volumeKey ? ttsBackMatterCutoff($db, $volumeKey) : null;
    $isBackMatter = function (int $num) use ($backMatterFrom): bool {
        return $backMatterFrom !== null && $num >= $backMatterFrom;
    };

    // NB: NO paragraph_active_flag filter — the worker (admin-tts-build-worker.php:344)
    // and the status endpoint (admin-tts-build.php:176) number over ALL paragraphs.
    // Filtering active_flag here is exactly what shifted the old resweep's index.
    $pst = $db->prepare("SELECT paragraph_number, paragraph_page, paragraph_text_plain,
                                paragraph_is_table, paragraph_is_continuation
                           FROM yy_paragraph
                          WHERE chapter_key = ?
                          ORDER BY paragraph_number");
    $pst->execute([$chapterKey]);
    $prows = $pst->fetchAll(PDO::FETCH_ASSOC);

    // Pass 1: authoritative part-index assignment (mirror of endpoint Pass 1).
    $partIdxByNum = [];
    $widx = 0;
    foreach ($prows as $r) {
        $num = (int)$r['paragraph_number'];
        $pg  = $r['paragraph_page'] !== null ? (int)$r['paragraph_page'] : null;
        if (!empty($r['paragraph_is_continuation'])) continue;                 // coalesced tail, no own index
        if (!empty($r['paragraph_is_table']) || $inSkip($pg) || $isBackMatter($num)) continue;
        $partIdxByNum[$num] = $widx;
        $widx++;
    }

    // Which paragraphs did a deleted tune actually fire in?
    $targets = [];   // [ part_idx => [num, page, snippet] ]
    foreach ($prows as $r) {
        $plain = (string)$r['paragraph_text_plain'];
        if ($plain === '') continue;
        $hit = false;
        foreach ($TUNE_RES as $re) if (preg_match($re, $plain)) { $hit = true; break; }
        if (!$hit) continue;
        $num = (int)$r['paragraph_number'];
        if (!isset($partIdxByNum[$num])) continue;   // continuation/table/skip/back-matter — no own part
        $idx = $partIdxByNum[$num];
        $targets[$idx] = [$num, $r['paragraph_page'], mb_substr(preg_replace('/\s+/u',' ',$plain),0,70)];
    }
    if (!$targets) continue;

    $partsDir = $audioBase . '/u/tts-parts/' . $audioKey;
    $totAudio++;
    foreach ($targets as $idx => $info) {
        $pf = $partsDir . sprintf('/p%05d.mp3', $idx);
        $present = is_file($pf);
        printf("ak=%-5d st=%-8s chap=%-6d p#%-5d page=%-4s part=p%05d %s  :: %s\n",
               $audioKey, $status, $chapterKey, $info[0], $info[1] ?? '-', $idx,
               $present ? 'HAVE' : 'absent', $info[2]);
        if ($apply && $present) { @unlink($pf); $totParts++; }
        elseif ($apply) { $totParts += 0; }
    }

    if ($apply) {
        if ($status === 'complete') {
            $db->prepare("UPDATE yy_tts_audio
                             SET tts_audio_status='pending', tts_audio_worker_pid=NULL, tts_audio_progress=0,
                                 tts_audio_message='re-queued: Arabia redo (corrected idx)'
                           WHERE tts_audio_key=? AND tts_audio_status='complete'")->execute([$audioKey]);
            $reflagged++;
            if (function_exists('ttsTrySpawn')) { try { ttsTrySpawn($db, $audioKey); } catch (Throwable $e) {} }
        } elseif ($status === 'pending') { $primedPending++; }
        else { $primedPaused++; }
    }
}

printf("\n%s: %d chapters with Arabia parts, %d parts %s\n"
     . "reflagged(complete->pending)=%d primed(pending)=%d primed(paused)=%d\n",
       $apply ? 'APPLIED' : 'DRY-RUN', $totAudio, $totParts,
       $apply ? 'deleted' : 'matched', $reflagged, $primedPending, $primedPaused);
