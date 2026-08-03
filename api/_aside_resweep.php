<?php
/**
 * One-off resweep for the parenthetical-ASIDE narration rule change (see
 * reference_tts_parenthetical_aside_narration — 07-18 signal-based rule). For every
 * affected chapter, delete ONLY the changed paragraphs' cached part files (part
 * index is per playable-paragraph and STABLE — the rule changes segments WITHIN a
 * paragraph, a previously-silent paren now routes to 'main', never the paragraph
 * count) so they re-synth with the paren voiced, and reflag `complete` chapters to
 * `pending`. `paused` chapters (s03-s06 / s07v05 holds) keep their status but have
 * stale parts cleared, so they rebuild correctly when the hold is released.
 *
 *   php _aside_resweep.php            # dry-run
 *   php _aside_resweep.php --apply    # delete parts + reflag complete->pending
 */
require_once __DIR__ . '/config.php';
$db = getDb();
$apply = in_array('--apply', $argv, true);
$audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';

$map = json_decode((string)file_get_contents(__DIR__ . '/_aside_map.json'), true);
if (!$map) { fwrite(STDERR, "no map\n"); exit(1); }
$chapterKeys = array_map('intval', array_keys($map));

// Active audio row per affected chapter (complete/paused/pending; never running).
$in = implode(',', $chapterKeys);
$rows = $db->query("
    SELECT DISTINCT ON (chapter_key) chapter_key, tts_audio_key, volume_key, tts_audio_status
      FROM yy_tts_audio
     WHERE tts_audio_active_flag AND chapter_key IN ($in)
     ORDER BY chapter_key, tts_audio_key DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pst = $db->prepare("SELECT paragraph_number, paragraph_page, paragraph_is_table, paragraph_is_continuation
                       FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
$sr  = $db->prepare("SELECT volume_skip_pages FROM yy_volume WHERE volume_key = ?");

$totAudio = 0; $totParts = 0; $reflagged = 0; $primedHeld = 0; $skippedRunning = 0;
$byStatus = [];
foreach ($rows as $a) {
    $chapterKey = (int)$a['chapter_key'];
    $audioKey   = (int)$a['tts_audio_key'];
    $volumeKey  = (int)$a['volume_key'];
    $status     = $a['tts_audio_status'];
    $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
    if ($status === 'running') { $skippedRunning++; continue; }

    // skip-page ranges
    $skipRanges = [];
    $sr->execute([$volumeKey]);
    foreach (preg_split('/\s*,\s*/', (string)($sr->fetchColumn() ?: ''), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
        if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $tok, $m)) $skipRanges[] = [(int)$m[1], (int)$m[2]];
        elseif (preg_match('/^\s*(\d+)\s*$/', $tok, $m))       $skipRanges[] = [(int)$m[1], (int)$m[1]];
    }
    $inSkip = function (?int $pg) use ($skipRanges): bool {
        if ($pg === null) return false;
        foreach ($skipRanges as $r) if ($pg >= $r[0] && $pg <= $r[1]) return true;
        return false;
    };

    // playable-paragraph index (same contract as the build worker / _junction_resweep)
    $pst->execute([$chapterKey]);
    $prows = $pst->fetchAll(PDO::FETCH_ASSOC);
    $playIdxByNum = []; $widx = 0; $lastHeadIdx = null;
    foreach ($prows as $r) {
        $num = (int)$r['paragraph_number'];
        $pg  = $r['paragraph_page'] !== null ? (int)$r['paragraph_page'] : null;
        if (!empty($r['paragraph_is_continuation'])) { if ($lastHeadIdx !== null) $playIdxByNum[$num] = $lastHeadIdx; continue; }
        if (!empty($r['paragraph_is_table']) || $inSkip($pg)) continue;
        $playIdxByNum[$num] = $widx; $lastHeadIdx = $widx; $widx++;
    }

    // changed paras -> part indices (dedup: a continuation maps to its head idx)
    $idxSet = [];
    foreach ($map[(string)$chapterKey] as $num) {
        $num = (int)$num;
        if (isset($playIdxByNum[$num])) $idxSet[$playIdxByNum[$num]] = true;
    }
    $partsDir = $audioBase . '/u/tts-parts/' . $audioKey;
    $present = []; $absent = 0;
    foreach (array_keys($idxSet) as $idx) {
        $pf = $partsDir . sprintf('/p%05d.mp3', $idx);
        if (is_file($pf)) $present[] = $idx; else $absent++;
    }

    printf("ak=%-6d st=%-8s chap=%-5d changed=%-2d part_del=%-2d absent=%d\n",
           $audioKey, $status, $chapterKey, count($map[(string)$chapterKey]), count($present), $absent);
    $totAudio++; $totParts += count($present);

    if ($apply) {
        foreach ($present as $idx) @unlink($partsDir . sprintf('/p%05d.mp3', $idx));
        if ($status === 'complete') {
            $db->prepare("UPDATE yy_tts_audio
                             SET tts_audio_status='pending', tts_audio_worker_pid=NULL,
                                 tts_audio_message='re-queued: parenthetical aside narration (resweep)'
                           WHERE tts_audio_key=? AND tts_audio_status='complete'")->execute([$audioKey]);
            $reflagged++;
        } else {
            $primedHeld++;   // paused/pending — parts cleared, status untouched
        }
    }
}
printf("\n%s: %d chapters, %d stale parts %s; reflagged(complete->pending)=%d, primed(paused/pending)=%d, skipped(running)=%d\n",
       $apply ? 'APPLIED' : 'DRY-RUN', $totAudio, $totParts,
       $apply ? 'deleted' : 'would delete', $reflagged, $primedHeld, $skippedRunning);
printf("by status: %s\n", json_encode($byStatus));
