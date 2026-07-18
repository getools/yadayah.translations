<?php
/**
 * Generalised surgical per-paragraph re-synth by whole word, e.g. "criminal".
 * Same CORRECTED part-index logic as _arabia_redo.php (mirrors the status
 * endpoint / build worker: NO paragraph_active_flag filter, WITH back-matter
 * cutoff). Deletes only the matched paragraphs' own part files and reflags the
 * chapter to 'pending' so the worker gap-fills exactly those clips.
 *
 *   php _word_redo.php --word=criminal              # dry-run all chapters
 *   php _word_redo.php --word=criminal --ak=NNN      # dry-run one chapter
 *   php _word_redo.php --word=criminal --apply       # do it
 *
 * Matches the word plus plural / possessive (criminal, criminals, criminal's)
 * but NOT longer words (criminality, criminalize). MUST run in the container.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$word = null;
$apply  = in_array('--apply', $argv, true);
$onlyAk = null;
foreach ($argv as $a) {
    if (preg_match('/^--word=(.+)$/', $a, $m)) $word = $m[1];
    if (preg_match('/^--ak=(\d+)$/', $a, $m))  $onlyAk = (int)$m[1];
}
if ($word === null || $word === '') { fwrite(STDERR, "--word=<word> required\n"); exit(1); }
// whole-word, case-insensitive, allow plural / possessive; unicode apostrophe too
$RE = '/\b' . preg_quote($word, '/') . "(?:s|['\xe2\x80\x99]s)?\b/iu";

$db    = getDb();
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
    $backMatterFrom = $volumeKey ? ttsBackMatterCutoff($db, $volumeKey) : null;
    $isBackMatter = function (int $num) use ($backMatterFrom): bool {
        return $backMatterFrom !== null && $num >= $backMatterFrom;
    };

    // NO active_flag filter — matches worker/endpoint numbering.
    $pst = $db->prepare("SELECT paragraph_number, paragraph_page, paragraph_text_plain,
                                paragraph_is_table, paragraph_is_continuation
                           FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
    $pst->execute([$chapterKey]);
    $prows = $pst->fetchAll(PDO::FETCH_ASSOC);

    $partIdxByNum = [];
    $widx = 0;
    foreach ($prows as $r) {
        $num = (int)$r['paragraph_number'];
        $pg  = $r['paragraph_page'] !== null ? (int)$r['paragraph_page'] : null;
        if (!empty($r['paragraph_is_continuation'])) continue;
        if (!empty($r['paragraph_is_table']) || $inSkip($pg) || $isBackMatter($num)) continue;
        $partIdxByNum[$num] = $widx;
        $widx++;
    }

    $targets = [];
    foreach ($prows as $r) {
        $plain = (string)$r['paragraph_text_plain'];
        if ($plain === '' || !preg_match($RE, $plain)) continue;
        $num = (int)$r['paragraph_number'];
        if (!isset($partIdxByNum[$num])) continue;
        $targets[$partIdxByNum[$num]] = [$num, $r['paragraph_page'], mb_substr(preg_replace('/\s+/u',' ',$plain),0,60)];
    }
    if (!$targets) continue;

    $partsDir = $audioBase . '/u/tts-parts/' . $audioKey;
    $totAudio++;
    foreach ($targets as $idx => $info) {
        $pf = $partsDir . sprintf('/p%05d.mp3', $idx);
        $present = is_file($pf);
        printf("ak=%-5d st=%-8s p#%-5d page=%-4s part=p%05d %s :: %s\n",
               $audioKey, $status, $info[0], $info[1] ?? '-', $idx, $present ? 'HAVE' : 'absent', $info[2]);
        if ($apply && $present) { @unlink($pf); $totParts++; }
    }

    if ($apply) {
        if ($status === 'complete') {
            $db->prepare("UPDATE yy_tts_audio
                             SET tts_audio_status='pending', tts_audio_worker_pid=NULL, tts_audio_progress=0,
                                 tts_audio_message=?
                           WHERE tts_audio_key=? AND tts_audio_status='complete'")
               ->execute(["re-queued: '{$word}' redo", $audioKey]);
            $reflagged++;
            if (function_exists('ttsTrySpawn')) { try { ttsTrySpawn($db, $audioKey); } catch (Throwable $e) {} }
        } elseif ($status === 'pending') { $primedPending++; }
        else { $primedPaused++; }
    }
}

printf("\n%s (word='%s'): %d chapters, %d parts %s\nreflagged(complete->pending)=%d primed(pending)=%d primed(paused)=%d\n",
       $apply ? 'APPLIED' : 'DRY-RUN', $word, $totAudio, $totParts,
       $apply ? 'deleted' : 'matched', $reflagged, $primedPending, $primedPaused);
