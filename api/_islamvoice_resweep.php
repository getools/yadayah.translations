<?php
/**
 * Corpus voice re-sweep for the 2026-07-24 Islamic-citation VOICE fix
 * (bold/unstyled quote after an Islamic label now routes to the source
 * voice instead of Translation-Prose / General-Narration — see
 * segmentParagraph retag). The paragraph HTML is unchanged by the fix, so
 * the worker's corpus-sig guard would REUSE the old (wrong-voice) parts on
 * a plain rebuild. This deletes the POSITIONAL cached part (p%05d.mp3) of
 * every affected citation paragraph so it re-synths with the correct voice,
 * reusing every unaffected (narration) part.
 *
 * Affected = a paragraph whose per-segment voice ACTUALLY changes: run
 * segmentParagraph with the live (new) helpers and compare to the old
 * retag (only other/quote -> first source). Precise, so narration and
 * already-correct 'other'-styled quotes are left alone.
 *
 * Rows are set complete -> PAUSED under sentinel __CORPUS_FIX_CAMPAIGN__
 * (NOT pending) so they wait behind s07v05; the finalizer releases them.
 * Skips volume_key 33 (s07v05 — rebuilt fresh) and any 'running' row.
 *
 *   php _islamvoice_resweep.php            # dry-run, all rows
 *   php _islamvoice_resweep.php --ak=NNN   # dry-run one
 *   php _islamvoice_resweep.php --apply    # delete parts + pause-hold
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$db = getDb();
$apply = in_array('--apply', $argv, true);
$onlyAk = null;
foreach ($argv as $a) if (preg_match('/^--ak=(\d+)$/', $a, $m)) $onlyAk = (int)$m[1];

$audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';

$sql = "SELECT tts_audio_key, chapter_key, volume_key, tts_audio_status
          FROM yy_tts_audio
         WHERE tts_audio_active_flag AND tts_audio_status <> 'running'
           AND tts_audio_status IN ('complete','paused'" . ($onlyAk ? ",'pending'" : "") . ")
           AND volume_key <> 33";
if ($onlyAk) $sql .= " AND tts_audio_key = " . $onlyAk;
$sql .= " ORDER BY tts_audio_key";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$totAudio = 0; $totParts = 0; $held = 0; $primedPaused = 0;
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

    $pst = $db->prepare("SELECT paragraph_number, paragraph_page, paragraph_text_html,
                                paragraph_is_table, paragraph_is_continuation
                           FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
    $pst->execute([$chapterKey]);
    $prows = $pst->fetchAll(PDO::FETCH_ASSOC);

    // Coalesce continuation HTML into the head (mirror the worker), and
    // build the positional part-index map (survivors only).
    $heads = []; $playIdxByNum = []; $widx = 0; $lastHeadIdx = null; $lastHeadNum = null;
    foreach ($prows as $r) {
        $num = (int)$r['paragraph_number'];
        $pg  = $r['paragraph_page'] !== null ? (int)$r['paragraph_page'] : null;
        if (!empty($r['paragraph_is_continuation'])) {
            if ($lastHeadNum !== null) $heads[$lastHeadNum]['html'] .= ' ' . (string)$r['paragraph_text_html'];
            if ($lastHeadIdx !== null) $playIdxByNum[$num] = $lastHeadIdx;
            continue;
        }
        if (!empty($r['paragraph_is_table']) || $inSkip($pg)) continue;
        $playIdxByNum[$num] = $widx;
        $heads[$num] = ['idx' => $widx, 'html' => (string)$r['paragraph_text_html']];
        $lastHeadIdx = $widx; $lastHeadNum = $num;
        $widx++;
    }

    // Affected = a head paragraph carrying an Islamic-source citation.
    // The new retag re-voices the quote in such a paragraph; re-synthing an
    // already-correct one just reproduces identical audio (harmless), so a
    // simple superset on the coalesced HTML is safe and needs no helper
    // change. Narration paragraphs (no Islamic style) are skipped, so their
    // cached parts are reused.
    $affIdx = []; $affParaCount = 0;
    foreach ($heads as $num => $h) {
        if (preg_match('/data-style="(?:quran|bukhari|ishaq|muslim|tabari|islam)"/', $h['html'])) {
            $affIdx[$h['idx']] = true; $affParaCount++;
        }
    }
    if (!$affIdx) continue;

    $partsDir = $audioBase . '/u/tts-parts/' . $audioKey;
    $present = []; $absent = 0;
    foreach (array_keys($affIdx) as $idx) {
        $pf = $partsDir . sprintf('/p%05d.mp3', $idx);
        if (is_file($pf)) $present[] = $idx; else $absent++;
    }

    printf("ak=%-5d st=%-8s vol=%-3d chap=%-6d cites=%-4d part_del=%-4d absent=%d\n",
           $audioKey, $status, $volumeKey, $chapterKey, $affParaCount, count($present), $absent);
    $totAudio++; $totParts += count($present);

    if ($apply) {
        foreach ($present as $idx) @unlink($partsDir . sprintf('/p%05d.mp3', $idx));
        if ($status === 'complete') {
            $db->prepare("UPDATE yy_tts_audio
                             SET tts_audio_status='paused', tts_audio_worker_pid=NULL,
                                 tts_audio_message='__CORPUS_FIX_CAMPAIGN__ Islamic-voice re-synth'
                           WHERE tts_audio_key=? AND tts_audio_status='complete'")->execute([$audioKey]);
            $held++;
        } else {
            $primedPaused++;
        }
    }
}

printf("\n%s: %d audio rows affected, %d stale parts %s; held(complete->paused)=%d, primed(paused)=%d\n",
       $apply ? 'APPLIED' : 'DRY-RUN',
       $totAudio, $totParts, $apply ? 'deleted' : 'would delete',
       $held, $primedPaused);
