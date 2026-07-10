<?php
/**
 * Edit-weighted engine grading — the "constantly evaluate manual edits" loop.
 *
 *   php transcript-engine-grade.php --backfill      # grade all edited items
 *   php transcript-engine-grade.php --sweep         # grade only items edited
 *                                                   #   since their last grade
 *   php transcript-engine-grade.php --item=1234     # grade one item
 *
 * "Gradeable" = an item that has real HUMAN edits (a row in yy_transcript_edit_log
 * by a real user) AND still has both baseline _auto rows and a live transcript to
 * compare. For each, cfGradeItemAgainstEdits() aligns every baseline to the
 * human-edited final text and records per-engine agreement; the rolled-up cache
 * (yy_transcript_engine_edit_grade) then feeds cfEngineWeights() at build time.
 *
 * Idempotent: re-grading an item replaces its ledger rows, so --sweep (cron) can
 * run forever without double-counting. No auth — local CLI only.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-caption-lib.php';   // cfGradeItemAgainstEdits, cfRecomputeEngineEditGradeCache

$db = getDb();
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

$mode = '--sweep';
$only = 0;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--backfill' || $a === '--sweep') $mode = $a;
    elseif (strpos($a, '--item=') === 0) { $only = (int)substr($a, 7); $mode = '--item'; }
}

$GRADEABLE = "
    SELECT DISTINCT el.feed_item_key AS k
      FROM yy_transcript_edit_log el
     WHERE el.edit_user_key IS NOT NULL
       AND el.edit_action IN ('edit','add')
       AND EXISTS (SELECT 1 FROM yy_feed_item_transcript_auto a WHERE a.feed_item_key = el.feed_item_key)
       AND EXISTS (SELECT 1 FROM yy_feed_item_transcript      t WHERE t.feed_item_key = el.feed_item_key)";

if ($only) {
    $items = [$only];
} else {
    $all = $db->query($GRADEABLE)->fetchAll(PDO::FETCH_COLUMN);
    if ($mode === '--sweep') {
        // Only items whose live transcript was revised since we last graded them.
        $lg  = $db->prepare("SELECT MAX(graded) FROM yy_transcript_engine_edit_grade_item WHERE feed_item_key = ?");
        $rev = $db->prepare("SELECT MAX(feed_item_transcript_revision_dtime) FROM yy_feed_item_transcript WHERE feed_item_key = ?");
        $items = [];
        foreach ($all as $k) {
            $lg->execute([$k]);  $graded  = $lg->fetchColumn();
            $rev->execute([$k]); $revised = $rev->fetchColumn();
            if (!$graded || ($revised && $revised > $graded)) $items[] = $k;
        }
    } else {
        $items = $all;   // --backfill
    }
}

$ok = 0; $skip = 0; $fail = 0;
foreach ($items as $k) {
    try {
        $r = cfGradeItemAgainstEdits($db, (int)$k);
        if (isset($r['skipped'])) { $skip++; echo "skip  $k — {$r['skipped']}\n"; }
        else { $ok++; echo "grade $k — " . count($r) . " engine(s)\n"; }
    } catch (\Throwable $e) {
        $fail++; echo "FAIL  $k — " . $e->getMessage() . "\n";
    }
}

cfRecomputeEngineEditGradeCache($db);

echo "\n== graded=$ok skipped=$skip failed=$fail (mode $mode) ==\n";
echo "== engine leaderboard (category 'all', vs human-edited finals) ==\n";
$lb = $db->query("SELECT engine, samples, grade, items FROM yy_transcript_engine_edit_grade
                   WHERE category='all' ORDER BY grade DESC NULLS LAST");
foreach ($lb as $r) {
    printf("  %-36s grade=%s  samples=%-7d items=%d\n",
        $r['engine'],
        $r['grade'] === null ? '  n/a ' : sprintf('%.4f', (float)$r['grade']),
        (int)$r['samples'], (int)$r['items']);
}
// Show the weights the build would actually apply right now.
echo "== weights cfEngineWeights() would apply (1.0 = neutral) ==\n";
foreach (cfEngineWeights($db) as $eng => $wt) printf("  %-36s w=%.3f\n", $eng, $wt);
