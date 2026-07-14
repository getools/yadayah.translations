<?php
/**
 * One-off: re-sweep already-built TTS audio after the "Arabia" (tts_tune_key 957)
 * and "arab" (767) pronunciation tunes were DELETED.
 *
 * Those two global tunes carried phonetics authored with Semitic letter values
 * ("Arabia" -> sub "ahrahb-Ihah" -> Chatterbox heard "ahrahbIhah"), which
 * overrode the engines' already-correct English pronunciation. With the tunes
 * gone, every paragraph they used to fire in must re-synth so the engine says
 * the words natively again.
 *
 * Matching uses tunePrintToRegex() -- the SAME matcher the build worker used --
 * so we regenerate exactly the paragraphs the deleted tunes actually hit:
 *   arab   -> Arab, arab, Arab's, pre-Arab      (NOT Arabs / Arabian / Arabic)
 *   Arabia -> Arabia, Arabia's                  (NOT Arabian / Arabic)
 *
 * For every active audio row that is not currently running, delete the affected
 * paragraphs' positional cached part files so they re-synth, and:
 *   complete -> flip to 'pending' so the build watchdog rebuilds the chapter
 *               (every unaffected cached part is reused -- surgical, not a full re-voice)
 *   pending  -> parts only. The row is ALREADY queued, but it still holds stale
 *               cached parts a rebuild would happily reuse; priming them is what
 *               makes the fix actually land on the 165 queued chapters.
 *   paused   -> parts only. Does NOT disturb the __HOLD_FOR_s07v05_JUMP__ hold;
 *               these re-synth correctly whenever they are released.
 *
 * MUST be run INSIDE the web container:
 *   docker exec yada-www-web-1 php /var/www/html/api/_arabia_resweep.php
 * On the HOST, dirname(__DIR__).'/u' resolves to a stray /opt/yada-www/u that is
 * NOT the real media root, so the parts dir would be wrong and this would delete
 * nothing while still burning rebuilds. The guard below aborts if that happens.
 *
 *   php _arabia_resweep.php            # dry-run, all rows
 *   php _arabia_resweep.php --ak=NNN   # dry-run, one audio key
 *   php _arabia_resweep.php --apply    # delete parts + reflag complete
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

// The exact matcher the engine used, for the two prints that were deleted.
$TUNE_RES = [
    'arab'   => tunePrintToRegex('arab', false),
    'Arabia' => tunePrintToRegex('Arabia', false),
];

$db    = getDb();
$apply = in_array('--apply', $argv, true);
$onlyAk = null;
foreach ($argv as $a) if (preg_match('/^--ak=(\d+)$/', $a, $m)) $onlyAk = (int)$m[1];

$audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';

// GUARD: refuse to run against a media root that has no tts-parts tree. Without
// this, a host-side run silently "deletes" 0 parts and reflags chapters anyway,
// producing a resweep that looks like it worked and changed nothing.
if (!is_dir($audioBase . '/u/tts-parts')) {
    fwrite(STDERR, "ABORT: no tts-parts under {$audioBase}/u -- wrong media root.\n"
                 . "Run this INSIDE the web container:\n"
                 . "  docker exec yada-www-web-1 php /var/www/html/api/_arabia_resweep.php\n");
    exit(1);
}
fwrite(STDERR, "parts base: {$audioBase}/u/tts-parts\n\n");

$sql = "SELECT tts_audio_key, chapter_key, volume_key, tts_audio_status
          FROM yy_tts_audio
         WHERE tts_audio_active_flag AND tts_audio_status <> 'running'
           AND tts_audio_status IN ('complete','paused','pending')";
if ($onlyAk) $sql .= " AND tts_audio_key = " . $onlyAk;
$sql .= " ORDER BY tts_audio_key";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$totAudio = 0; $totParts = 0; $totHits = 0;
$reflagged = 0; $primedPending = 0; $primedPaused = 0;

foreach ($rows as $a) {
    $audioKey   = (int)$a['tts_audio_key'];
    $chapterKey = (int)$a['chapter_key'];
    $volumeKey  = (int)$a['volume_key'];
    $status     = $a['tts_audio_status'];

    // Skip-page ranges are excluded from the playable index, exactly as the
    // build worker does, so positional part numbers line up.
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

    $pst = $db->prepare("SELECT paragraph_number, paragraph_page, paragraph_text_plain,
                                paragraph_is_table, paragraph_is_continuation
                           FROM yy_paragraph
                          WHERE chapter_key = ? AND paragraph_active_flag
                          ORDER BY paragraph_number");
    $pst->execute([$chapterKey]);
    $prows = $pst->fetchAll(PDO::FETCH_ASSOC);

    // Rebuild the playable index: continuations fold into their head paragraph,
    // tables and skip-pages are not synthesized at all.
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

    // Paragraphs where a deleted tune actually fired.
    $affIdx = []; $affParaCount = 0;
    foreach ($prows as $r) {
        $plain = (string)$r['paragraph_text_plain'];
        if ($plain === '') continue;
        $hit = false;
        foreach ($TUNE_RES as $re) { if (preg_match($re, $plain)) { $hit = true; break; } }
        if (!$hit) continue;
        $num = (int)$r['paragraph_number'];
        if (isset($playIdxByNum[$num])) { $affIdx[$playIdxByNum[$num]] = true; $affParaCount++; }
    }
    if (!$affIdx) continue;

    $partsDir = $audioBase . '/u/tts-parts/' . $audioKey;
    $present = []; $absent = 0;
    foreach (array_keys($affIdx) as $idx) {
        $pf = $partsDir . sprintf('/p%05d.mp3', $idx);
        if (is_file($pf)) $present[] = $idx; else $absent++;
    }

    printf("ak=%-5d st=%-8s chap=%-6d hits=%-3d part_del=%-3d absent=%d\n",
           $audioKey, $status, $chapterKey, $affParaCount, count($present), $absent);
    $totAudio++; $totParts += count($present); $totHits += $affParaCount;

    if ($apply) {
        foreach ($present as $idx) @unlink($partsDir . sprintf('/p%05d.mp3', $idx));
        if ($status === 'complete') {
            // Guarded on status so a row that started running since the SELECT is not stolen.
            $db->prepare("UPDATE yy_tts_audio
                             SET tts_audio_status='pending', tts_audio_worker_pid=NULL,
                                 tts_audio_message='re-queued: Arabia/arab tune deletion resweep'
                           WHERE tts_audio_key=? AND tts_audio_status='complete'")->execute([$audioKey]);
            $reflagged++;
        } elseif ($status === 'pending') {
            $primedPending++;   // already queued; stale parts now cleared
        } else {
            $primedPaused++;    // still held; will re-synth on release
        }
    }
}

printf("\n%s: %d audio rows, %d paragraphs, %d stale parts %s\n"
     . "reflagged(complete->pending)=%d  primed(pending)=%d  primed(paused)=%d\n",
       $apply ? 'APPLIED' : 'DRY-RUN',
       $totAudio, $totHits, $totParts, $apply ? 'deleted' : 'would delete',
       $reflagged, $primedPending, $primedPaused);
