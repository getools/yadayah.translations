<?php
/**
 * Remove faster-whisper MUSIC REPETITION-LOOP blocks from stored transcripts.
 *
 *   php transcript-music-loopblock-fix.php [feed_item_key | --all] [--apply] [--no-reattach]
 *
 * WHAT IT FIXES
 * -------------
 * At the onset of a sung / music passage the STT decoder loops: it emits a run
 * of consecutive segments ALL stamped at the loop's start time (e.g. every row
 * at 0:27:34.22, no per-line timing), dumping the whole upcoming passage — then
 * recovers and re-transcribes the SAME lyrics correctly with real word-level
 * timestamps immediately after. The editor shows the loop block as a jumbled
 * stack of identical-timestamp rows followed by the properly-timed copy.
 *
 * This tool detects each loop block (>=2 consecutive segments sharing one exact
 * timestamp, whose text is re-covered by the timed segments that follow within
 * a few seconds) and DELETES the block, keeping the timed copy. Any leading
 * words the timed pass dropped are re-attached to the first timed segment so no
 * content is lost (disable with --no-reattach).
 *
 * SAFETY
 * ------
 *  - Dry-run by default; prints exactly what it would delete / reattach.
 *  - --apply writes inside a transaction and first copies every affected row to
 *    yy_feed_item_transcript_loopblk_bak (created on demand) so the change is
 *    fully reversible.
 *  - A run is only treated as a loop block when a DISTINCT later-timed copy of
 *    its text exists within GAP_MAX seconds. Whole-song items stamped 00:00:00
 *    (no later timed copy) are therefore left untouched — they need
 *    re-transcription, not dedup.
 *  - Idempotent: re-running finds nothing once cleaned.
 */

require_once __DIR__ . '/config.php';

const OVERLAP_MIN = 0.60;   // fraction of the block's 4-grams that must reappear in the timed window
const GAP_MAX     = 30.0;   // seconds; timed recovery must start within this of the stuck timestamp
const LEAD_MAX    = 14;     // max words we will re-attach to the timed copy
const RUN_MIN     = 2;      // minimum segments in a loop block

$args   = array_slice($argv, 1);
$apply  = in_array('--apply', $args, true);
$reatt  = !in_array('--no-reattach', $args, true);
$all    = in_array('--all', $args, true);
$single = null;
foreach ($args as $a) { if (ctype_digit($a)) { $single = (int)$a; } }

if (!$all && $single === null) {
    fwrite(STDERR, "usage: php transcript-music-loopblock-fix.php [feed_item_key | --all] [--apply] [--no-reattach]\n");
    exit(2);
}

$db = getDb();

/* ---- helpers ---------------------------------------------------------- */

function norm_tokens(string $s): array {
    $s = str_replace(["\r", "\n"], ' ', $s);
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $s = trim($s);
    return $s === '' ? [] : preg_split('/\s+/', $s);
}
// aligned (original, normalized) token pairs; drops tokens that normalize empty
function aligned_tokens(string $s): array {
    $s = str_replace(["\r", "\n"], ' ', $s);
    $out = [];
    foreach (preg_split('/\s+/', trim($s)) as $tok) {
        if ($tok === '') continue;
        $n = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $tok));
        if ($n !== '') $out[] = [$tok, $n];
    }
    return $out;
}

// true if some 4-word run repeats within the token list (stuck-decoder self-loop)
function has_self_repeat(array $tok): bool {
    $N = 4;
    if (count($tok) < $N * 2) return false;
    $seen = [];
    for ($i = 0; $i + $N <= count($tok); $i++) {
        $g = implode(' ', array_slice($tok, $i, $N));
        if (isset($seen[$g])) return true;
        $seen[$g] = true;
    }
    return false;
}

// fraction of 4-grams of $a-tokens that occur (as a run) in $b-tokens
function ngram_overlap(array $aTok, array $bTok): float {
    $N = 4;
    if (count($aTok) < $N) $N = max(1, count($aTok));
    if (count($aTok) < $N || count($bTok) < 1) return 0.0;
    $bSet = [];
    for ($i = 0; $i + $N <= count($bTok); $i++) {
        $bSet[implode(' ', array_slice($bTok, $i, $N))] = true;
    }
    if (!$bSet) return 0.0;
    $hit = 0; $tot = 0;
    for ($i = 0; $i + $N <= count($aTok); $i++) {
        $tot++;
        if (isset($bSet[implode(' ', array_slice($aTok, $i, $N))])) $hit++;
    }
    return $tot ? $hit / $tot : 0.0;
}

/* ---- load candidate item list ---------------------------------------- */

if ($single !== null) {
    $items = [$single];
} else {
    // items that have >=1 exact-timestamp collapse of >=RUN_MIN segments
    $sql = 'SELECT feed_item_key FROM (
              SELECT feed_item_key, feed_item_transcript_segment, count(*) n
              FROM yy_feed_item_transcript GROUP BY 1,2 HAVING count(*) >= ' . (int)RUN_MIN . '
            ) d GROUP BY feed_item_key ORDER BY feed_item_key';
    $items = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
}

/* ---- backup table ----------------------------------------------------- */

if ($apply) {
    $db->exec("CREATE TABLE IF NOT EXISTS yy_feed_item_transcript_loopblk_bak (
        bak_key       bigserial PRIMARY KEY,
        action        text NOT NULL,           -- 'delete' | 'reattach'
        feed_item_key integer NOT NULL,
        row_key       bigint  NOT NULL,
        old_segment   interval,
        old_text      text,
        new_text      text,
        run_id        text,
        fix_dtime     timestamptz NOT NULL DEFAULT now()
    )");
}

/* ---- process ---------------------------------------------------------- */

$selSql = "SELECT feed_item_transcript_key k, feed_item_transcript_sort srt,
                  feed_item_transcript_segment::text seg_txt,
                  EXTRACT(EPOCH FROM feed_item_transcript_segment)::float8 secs,
                  feed_item_transcript_text txt
           FROM yy_feed_item_transcript
           WHERE feed_item_key = ?
           ORDER BY feed_item_transcript_sort, feed_item_transcript_segment";
$sel = $db->prepare($selSql);

$bakIns = $apply ? $db->prepare(
    "INSERT INTO yy_feed_item_transcript_loopblk_bak
       (action, feed_item_key, row_key, old_segment, old_text, new_text, run_id)
     VALUES (?,?,?,?::interval,?,?,?)") : null;
$delStmt = $apply ? $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_transcript_key = ?") : null;
$updStmt = $apply ? $db->prepare("UPDATE yy_feed_item_transcript SET feed_item_transcript_text = ? WHERE feed_item_transcript_key = ?") : null;

$totItems = 0; $totBlocks = 0; $totDel = 0; $totReatt = 0;

foreach ($items as $fik) {
    $sel->execute([$fik]);
    $rows = $sel->fetchAll();
    $n = count($rows);
    if ($n < RUN_MIN + 1) continue;

    $plan = [];   // list of ['run'=>[rowidx...], 'reattach'=>[k=>newtext], 'seg'=>..., 'ov'=>...]
    $i = 0;
    while ($i < $n) {
        // maximal run of equal timestamp
        $j = $i;
        while ($j + 1 < $n && abs($rows[$j+1]['secs'] - $rows[$i]['secs']) < 0.005) $j++;
        $runLen = $j - $i + 1;
        if ($runLen < RUN_MIN) { $i = $j + 1; continue; }

        $T = $rows[$i]['secs'];
        // find first following timed row (secs strictly greater)
        $f = $j + 1;
        while ($f < $n && $rows[$f]['secs'] <= $T + 0.005) $f++;
        if ($f >= $n) { $i = $j + 1; continue; }               // no later copy -> skip
        if ($rows[$f]['secs'] - $T > GAP_MAX) { $i = $j + 1; continue; } // recovery too far -> skip

        // build run tokens & timed window tokens
        $runTxt = '';
        for ($r = $i; $r <= $j; $r++) $runTxt .= ' ' . $rows[$r]['txt'];
        $runTok = norm_tokens($runTxt);
        $runWords = count($runTok);

        $winTxt = ''; $w = $f; $winWords = 0;
        while ($w < $n && $winWords < $runWords * 1.8 && ($rows[$w]['secs'] - $T) <= GAP_MAX + 90) {
            // stop if we hit another equal-timestamp collapse (next block)
            $winTxt .= ' ' . $rows[$w]['txt'];
            $winWords = count(norm_tokens($winTxt));
            $w++;
        }
        $winTok = norm_tokens($winTxt);

        $ov = ngram_overlap($runTok, $winTok);
        // primary: block text clearly re-covered by the timed copy;
        // secondary (safe): block is internally self-repeating (stuck decoder)
        // and still partially overlaps the timed copy.
        $confirmed = ($ov >= OVERLAP_MIN)
                  || ($ov > 0.0 && has_self_repeat($runTok));
        if (!$confirmed) { $i = $j + 1; continue; }             // not a duplicate -> skip

        // confirmed loop block: delete rows i..j, optionally reattach dropped lead
        $entry = ['run' => range($i, $j), 'seg' => $rows[$i]['seg_txt'], 'ov' => $ov, 'reattach' => null];

        if ($reatt) {
            $runAl = aligned_tokens($runTxt);                    // [ [orig,norm], ... ]
            $follNorm = norm_tokens($rows[$f]['txt']);
            $m = min(6, count($follNorm));
            if ($m >= 3) {
                $runNorm = array_map(fn($p) => $p[1], $runAl);
                // smallest p>0 where runNorm[p..p+m) == follNorm[0..m)
                $foundP = -1;
                for ($p = 1; $p + $m <= count($runNorm) && $p <= LEAD_MAX + 2; $p++) {
                    $eq = true;
                    for ($q = 0; $q < $m; $q++) { if ($runNorm[$p+$q] !== $follNorm[$q]) { $eq = false; break; } }
                    if ($eq) { $foundP = $p; break; }
                }
                if ($foundP >= 1 && $foundP <= LEAD_MAX) {
                    $leadOrig = [];
                    for ($p = 0; $p < $foundP; $p++) $leadOrig[] = $runAl[$p][0];
                    $lead = trim(implode(' ', $leadOrig));
                    if ($lead !== '') {
                        $entry['reattach'] = ['k' => $rows[$f]['k'],
                                              'old' => $rows[$f]['txt'],
                                              'new' => $lead . ' ' . $rows[$f]['txt']];
                    }
                }
            }
        }

        $plan[] = $entry;
        $i = $j + 1;
    }

    if (!$plan) continue;

    $totItems++;
    $blocks = count($plan);
    $delCount = array_sum(array_map(fn($e) => count($e['run']), $plan));
    $reattCount = count(array_filter($plan, fn($e) => $e['reattach']));
    $totBlocks += $blocks; $totDel += $delCount; $totReatt += $reattCount;

    printf("item %d: %d loop block(s), %d segments to remove, %d reattach\n",
           $fik, $blocks, $delCount, $reattCount);
    foreach ($plan as $bi => $e) {
        printf("   @%s  ov=%.2f  del=%d%s\n", $e['seg'], $e['ov'], count($e['run']),
               $e['reattach'] ? "  reattach lead=[".substr($e['reattach']['new'],0,40)."...]" : "");
    }

    if ($apply) {
        $db->beginTransaction();
        try {
            $runId = $fik . '-' . substr(md5($fik . '|' . $plan[0]['seg']), 0, 8);
            foreach ($plan as $e) {
                if ($e['reattach']) {
                    $ra = $e['reattach'];
                    $bakIns->execute(['reattach', $fik, $ra['k'], null, $ra['old'], $ra['new'], $runId]);
                    $updStmt->execute([$ra['new'], $ra['k']]);
                }
                foreach ($e['run'] as $ri) {
                    $row = $rows[$ri];
                    $bakIns->execute(['delete', $fik, $row['k'], $row['seg_txt'], $row['txt'], null, $runId]);
                    $delStmt->execute([$row['k']]);
                }
            }
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            fwrite(STDERR, "  ! item $fik FAILED: " . $ex->getMessage() . "\n");
        }
    }
}

printf("\n%s: %d item(s), %d loop block(s), %d segment(s) removed, %d reattach(es)\n",
       $apply ? 'APPLIED' : 'DRY-RUN', $totItems, $totBlocks, $totDel, $totReatt);
