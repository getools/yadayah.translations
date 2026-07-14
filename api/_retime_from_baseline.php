<?php
/**
 * SURGICAL RE-TIME of a live transcript from a fine-grained baseline.
 *
 * Fixes the legacy "30-second-grid" bug: caption segments that were split
 * finely but stamped with a coarse 30s-window engine timestamp, so runs of
 * segments share one time. This tool KEEPS the existing live text, segmentation
 * and manual edits, and ONLY re-stamps each row's start time by aligning its
 * opening words to a fine baseline (a word-level STT engine, or fine YouTube
 * captions). Snapshots first (one-click undo via the normal restore path).
 *
 * Usage (run INSIDE yada-www-web-1):
 *   php _retime_from_baseline.php <item_key>            # dry-run one item
 *   php _retime_from_baseline.php <item_key> --apply
 *   php _retime_from_baseline.php --list-affected       # print the backlog + chosen source
 *   php _retime_from_baseline.php --all [--apply] [--tier=AB|A|B]
 *   options: --source=<model>  --min-match=0.70  --limit=N  --json
 *
 * Dry-run by default. Never touches an item whose alignment match-rate is below
 * --min-match (those are left for a word-level STT pass + re-run).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-caption-lib.php';

$ARGS = array_slice($argv, 1);
$opt = ['apply'=>false, 'source'=>null, 'min'=>0.70, 'limit'=>0, 'all'=>false,
        'list'=>false, 'tier'=>'AB', 'json'=>false, 'item'=>null];
foreach ($ARGS as $a) {
    if ($a === '--apply') $opt['apply'] = true;
    elseif ($a === '--all') $opt['all'] = true;
    elseif ($a === '--list-affected') $opt['list'] = true;
    elseif ($a === '--json') $opt['json'] = true;
    elseif (str_starts_with($a, '--source=')) $opt['source'] = substr($a, 9);
    elseif (str_starts_with($a, '--min-match=')) $opt['min'] = (float)substr($a, 12);
    elseif (str_starts_with($a, '--limit=')) $opt['limit'] = (int)substr($a, 8);
    elseif (str_starts_with($a, '--tier=')) $opt['tier'] = strtoupper(substr($a, 7));
    elseif (ctype_digit($a)) $opt['item'] = (int)$a;
}

$db = getDb();

/** Word-level baselines, best first. */
const WORD_PREF = ['gpu-whisperx-word','gpu-whisper-large-v3-word',
                   'gpu-whisper-large-v3-turbo-word','gpu-parakeet-tdt-0.6b-v2-word',
                   'whisper-1-word-join'];

function onGrid(string $seg): bool {
    // seg = HH:MM:SS.mmm ; on the 30s grid iff seconds%30==0 and ms==0
    $s = cfIntervalToSecs($seg);
    return (fmod($s, 30.0) === 0.0);
}

/** All models present for an item, with a fine/coarse flag + row count. */
function itemModels(PDO $db, int $item): array {
    $st = $db->prepare("
        SELECT feed_item_transcript_auto_model m, count(*) n,
               count(*) FILTER (WHERE NOT (extract(second from feed_item_transcript_segment)::int%30=0
                        AND date_part('milliseconds',feed_item_transcript_segment)::int%1000=0)) offgrid
          FROM yy_feed_item_transcript_auto WHERE feed_item_key=? GROUP BY 1");
    $st->execute([$item]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(string)$r['m']] = ['n'=>(int)$r['n'], 'offgrid'=>(int)$r['offgrid']];
    return $out;
}

/** Choose the best fine baseline model for an item. Returns [model, tier] or [null,null]. */
function chooseSource(PDO $db, int $item, ?string $forced): array {
    $models = itemModels($db, $item);
    if ($forced) return isset($models[$forced]) ? [$forced, 'X'] : [null, null];
    foreach (WORD_PREF as $m) if (isset($models[$m]) && $models[$m]['offgrid'] > 0) return [$m, 'A'];
    if (isset($models['youtube']) && $models['youtube']['offgrid'] > 10) return ['youtube', 'B'];
    return [null, null];
}

/** Build an ordered baseline WORD stream [['t'=>secs,'w'=>token], ...] from a model. */
function baselineWordStream(PDO $db, int $item, string $model): array {
    $rows = cfLoadAuto($db, $item, $model); // [['secs'=>float,'text'=>string], ...] ordered
    if (!$rows) return [];
    $totalWords = 0;
    foreach ($rows as $r) $totalWords += max(1, count(preg_split('/\s+/u', trim($r['text']), -1, PREG_SPLIT_NO_EMPTY)));
    $avg = $totalWords / count($rows);
    if ($avg <= 1.3) {
        // already word-level: one row == one word
        $stream = [];
        foreach ($rows as $r) {
            $w = trim($r['text']);
            if ($w !== '') $stream[] = ['t'=>(float)$r['secs'], 'w'=>$w];
        }
        // ensure monotone t
        usort($stream, fn($a,$b)=> $a['t'] <=> $b['t']);
        return $stream;
    }
    // multi-word rows (youtube): interpolate word times across each row's span
    return cfRowsToWords($rows); // [['t'=>..,'w'=>..], ...]
}

/**
 * Re-time live rows from a baseline word stream.
 *
 * Two stages:
 *  (1) ANCHOR — find reliable bigram matches (row -> baseline token index). The
 *      live rows, in order, concatenate to ~the same word sequence as the
 *      baseline, so row i (cumulative live-word offset cum[i]) anchors near
 *      cum[i]*scale + drift; drift tracks residual offset from edits. Search a
 *      centered window BOTH directions for the nearest bigram; a monotonic floor
 *      forbids backward teleports; only bigrams (unambiguous) become anchors.
 *  (2) MAP — every row's baseline token index is piecewise-linear interpolated
 *      (in cumulative-WORD space) between its bracketing anchors, then its start
 *      time is READ from the baseline at that index. Because baseline word times
 *      are dense and smooth, reading time-at-index (instead of interpolating
 *      time) keeps the result smooth and monotonic even between sparse anchors.
 *
 * Returns [starts (float per row), anchorRows (list of anchored row idx), content, R].
 */
function alignStarts(array $live, array $stream): array {
    $tok = []; $bt = [];
    foreach ($stream as $s) {
        foreach (cfWords($s['w']) as $ww) { $tok[] = $ww; $bt[] = (float)$s['t']; }
    }
    $n = count($tok);
    $R = count($live);
    $cum = []; $lwAll = []; $c = 0;
    foreach ($live as $row) { $w = cfWords($row['text']); $lwAll[] = $w; $cum[] = $c; $c += count($w); }
    $totalLive = max(1, $c);
    $scale = $n / $totalLive;

    // ---- (1) anchors ----
    $anchRow = []; $anchTok = []; $content = 0; $drift = 0.0; $lastAnchor = -1;
    $W = 220;
    for ($i = 0; $i < $R; $i++) {
        $lw = $lwAll[$i];
        if (!$lw) continue;
        $content++;
        if (count($lw) < 2) continue;
        $expected = (int)round($cum[$i] * $scale + $drift);
        $lo = max(0, $expected - $W); $hi = min($n - 1, $expected + $W);
        $floor = ($lastAnchor >= 0) ? $lastAnchor - 40 : -1;
        $best = -1; $bestDist = PHP_INT_MAX;
        for ($j = $lo; $j < $hi; $j++) {
            if ($j < $floor || $tok[$j] !== $lw[0] || $tok[$j+1] !== $lw[1]) continue;
            $d = abs($j - $expected);
            if ($d < $bestDist) { $best = $j; $bestDist = $d; }
        }
        if ($best < 0) continue;
        $anchRow[] = $i; $anchTok[] = $best;
        $lastAnchor = $best;
        $drift = 0.5 * $drift + 0.5 * ($best - $cum[$i] * $scale);
    }

    // ---- (2) map every row -> baseline token index -> baseline time ----
    $starts = array_fill(0, $R, 0.0);
    $A = count($anchRow);
    $tokAt = function (float $idx) use ($bt, $n): float {
        $k = (int)round($idx); if ($k < 0) $k = 0; if ($k > $n-1) $k = $n-1;
        return $bt[$k];
    };
    if ($A === 0) {
        for ($i = 0; $i < $R; $i++) $starts[$i] = $tokAt($cum[$i] * $scale);
    } else {
        for ($i = 0; $i < $R; $i++) {
            if ($i <= $anchRow[0]) {                       // head extrapolation
                $idx = $anchTok[0] - ($cum[$anchRow[0]] - $cum[$i]) * $scale;
            } elseif ($i >= $anchRow[$A-1]) {              // tail extrapolation
                $idx = $anchTok[$A-1] + ($cum[$i] - $cum[$anchRow[$A-1]]) * $scale;
            } else {                                       // interior: bracket + interp
                $g = 0; while ($g < $A-1 && $anchRow[$g+1] <= $i) $g++;
                $r0=$anchRow[$g]; $r1=$anchRow[$g+1]; $t0=$anchTok[$g]; $t1=$anchTok[$g+1];
                $den = $cum[$r1] - $cum[$r0];
                $frac = $den > 0 ? ($cum[$i] - $cum[$r0]) / $den : 0.0;
                $idx = $t0 + $frac * ($t1 - $t0);
            }
            $starts[$i] = $tokAt($idx);
        }
    }
    return [$starts, $anchRow, $content, $R];
}

/** Fill null starts by linear interpolation over row index; enforce strict increase. */
function fillAndMonotone(array $starts): array {
    $n = count($starts);
    $known = [];
    for ($i=0;$i<$n;$i++) if ($starts[$i] !== null) $known[] = $i;
    if (!$known) { for ($i=0;$i<$n;$i++) $starts[$i] = (float)$i; return $starts; }
    // leading
    $first = $known[0];
    for ($i=$first-1;$i>=0;$i--) $starts[$i] = max(0.0, $starts[$i+1] - 0.6);
    // trailing
    $last = end($known);
    for ($i=$last+1;$i<$n;$i++) $starts[$i] = $starts[$i-1] + 1.0;
    // interior gaps
    for ($g=0; $g<count($known)-1; $g++) {
        $a=$known[$g]; $b=$known[$g+1];
        if ($b - $a <= 1) continue;
        $ta=$starts[$a]; $tb=$starts[$b];
        if ($tb <= $ta) $tb = $ta + ($b-$a)*0.4;
        for ($i=$a+1;$i<$b;$i++) $starts[$i] = $ta + ($tb-$ta)*(($i-$a)/($b-$a));
    }
    // strictly increasing (distinct segments => no ON CONFLICT drop in cfReplaceLive)
    for ($i=1;$i<$n;$i++) if ($starts[$i] <= $starts[$i-1]) $starts[$i] = $starts[$i-1] + 0.001;
    return $starts;
}

function retimeItem(PDO $db, int $item, array $opt): array {
    $live = cfLoadLive($db, $item);
    if (!$live) return ['item'=>$item, 'skip'=>'no live rows'];
    [$model, $tier] = chooseSource($db, $item, $opt['source']);
    if (!$model) return ['item'=>$item, 'skip'=>'no fine baseline'];
    if ($opt['all'] && $opt['tier'] !== 'AB' && $tier !== 'X' && $tier !== $opt['tier'])
        return ['item'=>$item, 'skip'=>"tier $tier not selected"];

    $stream = baselineWordStream($db, $item, $model);
    if (count($stream) < 5) return ['item'=>$item, 'skip'=>"baseline $model too small"];

    [$starts, $anchorIdx, $content, $R] = alignStarts($live, $stream);
    $matched = count($anchorIdx);
    $rate = $content > 0 ? $matched / $content : 0.0;

    // Coverage metrics — a well-distributed set of anchors interpolates cleanly
    // even at a modest rate; a clustered set does not. Judge on distribution,
    // not raw rate: longest gap BETWEEN anchors + how close anchors reach each end.
    $longestRun = 0;
    if ($anchorIdx) {
        $prev = -1;
        foreach ($anchorIdx as $ai) { $longestRun = max($longestRun, $ai - $prev - 1); $prev = $ai; }
        $longestRun = max($longestRun, $R - 1 - $prev);
        $headGap = $anchorIdx[0]; $tailGap = $R - 1 - end($anchorIdx);
    } else { $longestRun = $R; $headGap = $R; $tailGap = $R; }
    $runCap = max(20, (int)ceil($R * 0.06));
    $endCap = max(8, (int)ceil($R * 0.06));

    $res = ['item'=>$item, 'model'=>$model, 'tier'=>$tier, 'rows'=>$R,
            'content'=>$content, 'matched'=>$matched, 'rate'=>round($rate,3),
            'longest_run'=>$longestRun, 'head_gap'=>$headGap, 'tail_gap'=>$tailGap];

    $why = [];
    if ($rate < 0.30) $why[] = sprintf('rate %.0f%%<30%%', $rate*100);
    if ($longestRun > $runCap) $why[] = "gap $longestRun>$runCap rows";
    if ($headGap > $endCap) $why[] = "head $headGap>$endCap";
    if ($tailGap > $endCap) $why[] = "tail $tailGap>$endCap";
    if ($why) { $res['skip'] = implode(', ', $why); return $res; }

    $starts = fillAndMonotone($starts);
    // build cues preserving text/speaker/order
    $cues = [];
    foreach ($live as $i => $row) {
        $cues[] = ['segment'=>cfSecsToInterval((float)$starts[$i]),
                   'text'=>$row['text'], 'speaker'=>$row['speaker']];
    }
    // stats
    $offGrid = 0; $distinct = [];
    foreach ($cues as $c) { if (!onGrid($c['segment'])) $offGrid++; $distinct[$c['segment']] = 1; }
    $res['new_offgrid_pct'] = round(100.0*$offGrid/max(1,count($cues)),1);
    $res['new_distinct_ts'] = count($distinct);
    $res['first_ts'] = $cues[0]['segment'];
    $res['last_ts']  = $cues[count($cues)-1]['segment'];
    $res['old_last_ts'] = $live[count($live)-1]['segment'];

    if ($opt['apply']) {
        $snap = cfSnapshot($db, $item, null, "surgical re-time from $model (30s-grid fix)");
        $wrote = cfReplaceLive($db, $item, $cues);
        $res['snapshot'] = $snap; $res['wrote'] = $wrote;
        $res['rowcount_ok'] = ($wrote === count($cues));
    }
    return $res;
}

// ---- affected-item discovery (same predicate used in analysis) ----
function affectedItems(PDO $db): array {
    $sql = "WITH g AS (
              SELECT feed_item_key, count(*) rows,
                 count(*) FILTER (WHERE extract(second from feed_item_transcript_segment)::int%30=0
                    AND date_part('milliseconds',feed_item_transcript_segment)::int%1000=0) on_grid
                FROM yy_feed_item_transcript GROUP BY feed_item_key)
            SELECT feed_item_key FROM g WHERE rows>=20 AND on_grid::float/rows>0.6 ORDER BY feed_item_key";
    return array_map('intval', $db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
}

// ---- drivers ----
$out = [];
if ($opt['list']) {
    foreach (affectedItems($db) as $it) {
        [$m,$t] = chooseSource($db, $it, null);
        $out[] = ['item'=>$it, 'tier'=>$t ?? 'C', 'source'=>$m ?? '(none - needs STT)'];
    }
    if ($opt['json']) { echo json_encode($out, JSON_PRETTY_PRINT); exit; }
    $c=['A'=>0,'B'=>0,'C'=>0];
    foreach ($out as $r) { printf("%-9d  tier %s  %s\n", $r['item'], $r['tier'], $r['source']); $c[$r['tier']]=($c[$r['tier']]??0)+1; }
    printf("\n== %d affected: A(word)=%d  B(youtube)=%d  C(needs STT)=%d ==\n", count($out), $c['A'], $c['B'], $c['C']);
    exit;
}

$targets = [];
if ($opt['item']) $targets = [$opt['item']];
elseif ($opt['all']) $targets = affectedItems($db);
else { fwrite(STDERR, "give an <item_key>, --all, or --list-affected\n"); exit(1); }
if ($opt['limit'] > 0) $targets = array_slice($targets, 0, $opt['limit']);

$done=0;$applied=0;$skipped=0;
foreach ($targets as $it) {
    $r = retimeItem($db, $it, $opt);
    $out[] = $r;
    if (isset($r['skip'])) { $skipped++; printf("SKIP  %-9d  %s\n", $it, $r['skip']); continue; }
    $done++;
    if ($opt['apply'] && !empty($r['wrote'])) $applied++;
    printf("%s %-9d  src=%-28s rate=%3.0f%%  off-grid %s%%->%s%%  distinct %d->%d  span %s..%s  snap=%s%s\n",
        $opt['apply'] ? 'APPLIED' : 'DRYRUN ', $it, $r['model'],
        $r['rate']*100, '~0', $r['new_offgrid_pct'],
        '~few', $r['new_distinct_ts'], $r['first_ts'], $r['last_ts'],
        $r['snapshot'] ?? '-', isset($r['rowcount_ok']) && !$r['rowcount_ok'] ? '  !!ROWCOUNT_MISMATCH' : '');
}
if ($opt['json']) echo json_encode($out, JSON_PRETTY_PRINT), "\n";
printf("\n== targets=%d  processed=%d  applied=%d  skipped=%d ==\n", count($targets), $done, $applied, $skipped);
