<?php
/**
 * transcript-retime-firstword.php  <feed_item_key> [more keys...] | --list=file | --stdin
 *                                  [--apply] [--model=CODE] [--min-anchor=0.55] [--quiet]
 *
 * Surgical timestamp repair. For each current (editable) transcript segment,
 * re-anchor its START time to the precise fractional timestamp of its FIRST
 * WORD as found in a word-level baseline (gpu-*-word) in yy_feed_item_transcript_auto.
 * Segments whose first word can't be matched are linearly interpolated between
 * neighbouring anchors, preserving order. TEXT IS NEVER TOUCHED — only the
 * segment interval — so human edits and edit-protection are respected.
 *
 * Fixes the 2026-06/07 "diarize-spine" builds where many cues collapsed onto one
 * segment start time (runs of duplicate / integer-second timestamps).
 *
 * Dry-run by default; --apply writes inside a transaction and copies every
 * changed row's old segment to yy_feed_item_transcript_retime_bak (idempotent —
 * re-runnable; converges).
 */
require_once __DIR__ . '/config.php';

$WORDPREF = ['gpu-whisper-large-v3-word','gpu-whisperx-word',
             'gpu-whisper-large-v3-turbo-word','gpu-parakeet-tdt-0.6b-v2-word'];
$BACK = 6.0;    // seconds a first-word match may sit BEFORE the old segment start
$FWD  = 30.0;   // ...and after
$LEV  = 1;      // max Levenshtein for fuzzy token match

$apply = false; $modelOverride = ''; $minAnchor = 0.55; $quiet = false; $items = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') $apply = true;
    elseif ($a === '--quiet') $quiet = true;
    elseif ($a === '--stdin') { foreach (preg_split('/\s+/', (string)stream_get_contents(STDIN)) as $t) if ($t!=='') $items[] = (int)$t; }
    elseif (strncmp($a, '--list=', 7) === 0) { foreach (preg_split('/\s+/', (string)@file_get_contents(substr($a,7))) as $t) if ($t!=='') $items[] = (int)$t; }
    elseif (strncmp($a, '--model=', 8) === 0) $modelOverride = substr($a, 8);
    elseif (strncmp($a, '--min-anchor=', 13) === 0) $minAnchor = (float)substr($a, 13);
    elseif ($a[0] !== '-') $items[] = (int)$a;
}
$items = array_values(array_unique(array_filter($items)));
if (!$items) { fwrite(STDERR, "usage: transcript-retime-firstword.php <feed_item_key...> [--apply] [--model=CODE] [--min-anchor=0.55]\n"); exit(1); }

function normTok(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    // take first whitespace-delimited chunk, strip surrounding non letter/number
    $parts = preg_split('/\s+/u', $s, 2);
    $t = $parts[0] ?? '';
    $t = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $t);
    return (string)$t;
}
function tokMatch(string $a, string $b, int $lev): bool {
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    $la = mb_strlen($a,'UTF-8'); $lb = mb_strlen($b,'UTF-8');
    if ($la >= 4 && $lb >= 4) { if (str_starts_with($a,$b) || str_starts_with($b,$a)) return true; }
    if (abs($la - $lb) <= $lev && min($la,$lb) >= 3) { if (levenshtein($a,$b) <= $lev) return true; }
    return false;
}

$db = getDb();
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

// backup table (idempotent)
$db->exec("CREATE TABLE IF NOT EXISTS yy_feed_item_transcript_retime_bak (
    bak_key bigserial PRIMARY KEY,
    run_dtime timestamptz NOT NULL DEFAULT now(),
    feed_item_key integer NOT NULL,
    feed_item_transcript_key bigint NOT NULL,
    old_segment interval NOT NULL,
    new_segment interval NOT NULL,
    model text NOT NULL)");

$grandChanged = 0; $grandItems = 0; $skipped = [];
foreach ($items as $itemKey) {
    // pick word model
    $model = $modelOverride;
    if ($model === '') {
        $have = [];
        $st = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model m FROM yy_feed_item_transcript_auto WHERE feed_item_key=? AND feed_item_transcript_auto_model LIKE 'gpu-%-word'");
        $st->execute([$itemKey]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $m) $have[$m] = true;
        foreach ($GLOBALS['WORDPREF'] as $p) if (isset($have[$p])) { $model = $p; break; }
    }
    if ($model === '') { $skipped[] = "$itemKey (no word baseline)"; continue; }

    // baseline word stream
    $st = $db->prepare("SELECT EXTRACT(EPOCH FROM feed_item_transcript_segment)::float8 t, feed_item_transcript_text txt
                          FROM yy_feed_item_transcript_auto
                         WHERE feed_item_key=? AND feed_item_transcript_auto_model=?
                         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
    $st->execute([$itemKey, $model]);
    $bT = []; $bTok = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $bT[] = (float)$r['t']; $bTok[] = normTok((string)$r['txt']); }
    $nb = count($bT);
    if ($nb < 5) { $skipped[] = "$itemKey (thin baseline $model=$nb)"; continue; }

    // current segments
    $st = $db->prepare("SELECT feed_item_transcript_key k, EXTRACT(EPOCH FROM feed_item_transcript_segment)::float8 t, feed_item_transcript_text txt
                          FROM yy_feed_item_transcript
                         WHERE feed_item_key=?
                         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
    $st->execute([$itemKey]);
    $seg = $st->fetchAll(PDO::FETCH_ASSOC);
    $ns = count($seg);
    if ($ns === 0) { $skipped[] = "$itemKey (no segments)"; continue; }

    // anchor: two-pointer nearest-time monotonic first-word match
    $anchor = array_fill(0, $ns, null);
    $lo = 0; $prevT = -1e9;
    for ($i = 0; $i < $ns; $i++) {
        $ot  = (float)$seg[$i]['t'];
        $tok = normTok((string)$seg[$i]['txt']);
        if ($tok === '') continue;
        while ($lo < $nb && $bT[$lo] < $ot - $GLOBALS['BACK']) $lo++;
        $best = null; $bestD = INF;
        for ($j = $lo; $j < $nb && $bT[$j] <= $ot + $GLOBALS['FWD']; $j++) {
            if ($bT[$j] < $prevT) continue;
            if (!tokMatch($tok, $bTok[$j], $GLOBALS['LEV'])) continue;
            $d = abs($bT[$j] - $ot);
            if ($d < $bestD) { $bestD = $d; $best = $j; }
        }
        if ($best !== null) { $anchor[$i] = $bT[$best]; $prevT = $bT[$best]; if ($best >= $lo) $lo = $best + 1; }
    }
    $matched = count(array_filter($anchor, fn($v) => $v !== null));
    $rate = $ns ? $matched / $ns : 0;

    // build new times: exact at anchors, linear interpolation (by old-time span) between,
    // extrapolate the ends by preserving old-time offsets.
    $newT = array_fill(0, $ns, null);
    $idx = []; foreach ($anchor as $i => $v) if ($v !== null) $idx[] = $i;
    if (!$idx) { $skipped[] = "$itemKey (0 anchors, $model)"; continue; }
    foreach ($idx as $i) $newT[$i] = $anchor[$i];
    // Interpolation is INDEX-based (not old-time-based) so the result depends only
    // on the (stable) anchor times and row indices — making the tool idempotent:
    // a second --apply pass recomputes identical values → changed=0 → no churn.
    // stable average gap for end extrapolation
    $f = $idx[0]; $l = $idx[count($idx)-1];
    $gap = ($l > $f) ? ($anchor[$l] - $anchor[$f]) / ($l - $f) : 1.0;
    if ($gap <= 0.01) $gap = 1.0;
    // leading: evenly fill 0..firstAnchor
    for ($i = 0; $i < $f; $i++) $newT[$i] = $anchor[$f] * (($i + 1) / ($f + 1));
    // trailing: step forward by the stable average gap
    for ($i = $l + 1; $i < $ns; $i++) $newT[$i] = $anchor[$l] + ($i - $l) * $gap;
    // interior gaps: even split between the two bounding anchors
    for ($a = 0; $a < count($idx) - 1; $a++) {
        $ia = $idx[$a]; $ib = $idx[$a+1];
        if ($ib - $ia <= 1) continue;
        $ta = $anchor[$ia]; $tb = $anchor[$ib];
        for ($i = $ia + 1; $i < $ib; $i++) {
            $newT[$i] = $ta + ($tb - $ta) * (($i - $ia) / ($ib - $ia));
        }
    }
    // enforce strictly increasing (>=10ms apart) and >= 0
    for ($i = 0; $i < $ns; $i++) {
        if ($newT[$i] < 0) $newT[$i] = 0.0;
        if ($i > 0 && $newT[$i] <= $newT[$i-1]) $newT[$i] = $newT[$i-1] + 0.01;
    }

    // stats
    $changed = 0; $dupBefore = 0; $seenOld = []; $seenNew = []; $dupAfter = 0;
    for ($i = 0; $i < $ns; $i++) {
        $ov = round((float)$seg[$i]['t'], 3); $nv = round($newT[$i], 3);
        if (abs($ov - $nv) >= 0.005) $changed++;
        $ok = (string)$ov; if (isset($seenOld[$ok])) $dupBefore++; $seenOld[$ok] = 1;
        $nk = (string)$nv; if (isset($seenNew[$nk])) $dupAfter++; $seenNew[$nk] = 1;
    }

    if (!$quiet) printf("item %d  model=%s  segs=%d  anchors=%d (%.0f%%)  changed=%d  dupTime %d->%d%s\n",
        $itemKey, $model, $ns, $matched, $rate*100, $changed, $dupBefore, $dupAfter, $apply ? '  [APPLY]' : '  [dry]');

    if ($rate < $minAnchor) { $skipped[] = "$itemKey (low anchor rate ".round($rate*100)."% < ".round($minAnchor*100)."%)"; continue; }

    if ($apply && $changed > 0) {
        $db->beginTransaction();
        try {
            $bak = $db->prepare("INSERT INTO yy_feed_item_transcript_retime_bak (feed_item_key,feed_item_transcript_key,old_segment,new_segment,model)
                                 VALUES (?,?, (?||' seconds')::interval, (?||' seconds')::interval, ?)");
            $upd = $db->prepare("UPDATE yy_feed_item_transcript SET feed_item_transcript_segment=(?||' seconds')::interval
                                  WHERE feed_item_transcript_key=?");
            // Two-pass to avoid transient uq_transcript_no_dup collisions: the OLD
            // data has duplicate (segment,text) pairs, so a later row's final time
            // can momentarily equal an earlier not-yet-moved identical-text row.
            // Pass 1 parks every changed row at newTime+OFFSET (all newTimes are
            // strictly increasing → unique, and OFFSET>>episode length so nothing
            // clashes with an un-moved old value); pass 2 drops them to final.
            $OFF = 1000000.0;
            $todo = [];
            for ($i = 0; $i < $ns; $i++) {
                $ov = round((float)$seg[$i]['t'], 3); $nv = round($newT[$i], 3);
                if (abs($ov - $nv) < 0.005) continue;
                $todo[] = [$seg[$i]['k'], $ov, $nv];
            }
            foreach ($todo as $t) { $bak->execute([$itemKey, $t[0], $t[1], $t[2], $model]); $upd->execute([$t[2] + $OFF, $t[0]]); }
            foreach ($todo as $t) { $upd->execute([$t[2], $t[0]]); }
            $db->commit();
            $grandChanged += $changed; $grandItems++;
        } catch (\Throwable $e) {
            $db->rollBack();
            fwrite(STDERR, "item $itemKey APPLY FAILED: ".$e->getMessage()."\n");
            $skipped[] = "$itemKey (apply error: ".$e->getMessage().")";
        }
    }
}

if (!$quiet) {
    echo "----\n";
    printf("%s: %d item(s) updated, %d segment(s) re-timed.\n", $apply ? 'APPLIED' : 'DRY-RUN (no writes)', $grandItems, $grandChanged);
    if ($skipped) echo "skipped/needs-attention:\n  - " . implode("\n  - ", $skipped) . "\n";
}
