<?php
/**
 * Repair transcript lines BALLOONED by the Layer-3 LLM reconcile merging lines
 * it was explicitly told not to merge.
 *
 *   php transcript-balloon-merge-fix.php [feed_item_key | --all] [--apply] [--verbose]
 *
 * WHAT IT FIXES
 * -------------
 * llmReconcileTranscript() (transcript-caption-lib.php) asks the on-box LLM to
 * repair each editable line in place, "one entry per input line, do NOT merge".
 * qwen occasionally disobeys and returns line i as the CONCATENATION of
 * i (+ i+1 [+ i+2 …]) while i+1/i+2 are returned unchanged. Line i then balloons
 * to hold the whole passage and its text repeats verbatim in the following rows
 * — the editor shows a long segment immediately followed by its own pieces.
 *
 * DETECTION
 * ---------
 * A row is "ballooned" when its text ends with the (word-level, punctuation-
 * insensitive) concatenation of one or more of the rows that follow it in time,
 * leaving a non-empty leading remainder (the row's own original text).
 *
 * REPAIR (per ballooned row, in priority order)
 * ---------------------------------------------
 *  1. SNAPSHOT restore — if the item's 'pre auto LLM reconcile (init)' snapshot
 *     holds a row at the same segment whose text is the leading remainder, use
 *     it verbatim (exact pre-corruption text).
 *  2. TRIM — otherwise strip the trailing duplicated block from the row text,
 *     keeping the leading remainder with its original punctuation.
 * A row is only rewritten when the recovered text is non-empty AND the stripped
 * suffix's normalized tokens exactly equal the following rows' concatenation.
 *
 * SAFETY
 * ------
 *  - Dry-run by default; --apply writes inside a transaction.
 *  - Every rewritten row is first copied to yy_feed_item_transcript_balloon_bak
 *    (created on demand) so the change is fully reversible.
 *  - Never touches the following (correct) rows, only the ballooned one.
 *  - Idempotent: re-running finds nothing once cleaned. Chained/nested balloons
 *    resolve over repeated runs.
 */

require_once __DIR__ . '/config.php';

const MAXK      = 10;   // max following rows a single balloon may absorb
const MIN_NEXT  = 2;    // min normalized tokens in the swallowed next row (avoid trivia)
const MIN_TAIL  = 8;    // min chars of the swallowed block (belt-and-suspenders)
const SHORT_TAIL = 3;   // when absorbed tokens < this, ONLY a snapshot-verified restore
                        // is allowed — a 2-word tail can be a genuine spoken repeat,
                        // which trim would corrupt; the snapshot is ground truth.

$args    = array_slice($argv, 1);
$apply   = in_array('--apply', $args, true);
$verbose = in_array('--verbose', $args, true);
$all     = in_array('--all', $args, true);
$single  = null;
foreach ($args as $a) { if (ctype_digit($a)) $single = (int)$a; }

if (!$all && $single === null) {
    fwrite(STDERR, "usage: php transcript-balloon-merge-fix.php [feed_item_key | --all] [--apply] [--verbose]\n");
    exit(2);
}

$db = getDb();

/* ---- helpers ---------------------------------------------------------- */

/** Canonical numeric key for an interval string ("HH:MM:SS.mmm") → integer ms. */
function bm_segkey(string $s): int {
    $s = trim($s);
    $sec = 0.0;
    if (preg_match('/^(?:(\d+):)?(\d+):(\d+(?:\.\d+)?)$/', $s, $m)) {
        $sec = ((int)($m[1] ?: 0)) * 3600 + ((int)$m[2]) * 60 + (float)$m[3];
    } else {
        $sec = (float)$s;
    }
    return (int)round($sec * 1000);
}

function bm_norm(string $s): array {
    $s = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return $s === '' ? [] : explode(' ', $s);
}

function bm_find_at(array $hay, array $needle, int $at): bool {
    $n = count($needle); if ($n === 0 || $at + $n > count($hay)) return false;
    for ($j = 0; $j < $n; $j++) if ($hay[$at+$j] !== $needle[$j]) return false;
    return true;
}
function bm_find(array $hay, array $needle, int $from = 0): int {
    $n = count($needle); $h = count($hay);
    if ($n === 0 || $n > $h) return -1;
    for ($i = max(0,$from); $i + $n <= $h; $i++) if (bm_find_at($hay,$needle,$i)) return $i;
    return -1;
}
/**
 * Confirm a snapshot line $L is a clean pre-reconcile prefix: it contains the
 * kept prefix $P as a contiguous run and the removed block $B does NOT begin
 * right after it (so $B was reconcile-added, not pre-existing). Guards trims
 * against genuine repeats / misaligned snapshot fragments.
 */
function bm_confirms(array $L, array $P, array $B): bool {
    $p = count($P); $h = count($L);
    if ($p === 0 || $p > $h) return false;
    $b0 = $B[0] ?? null;
    for ($i = 0; $i + $p <= $h; $i++) {
        if (!bm_find_at($L, $P, $i)) continue;
        $after = $i + $p;
        if ($after >= $h) return true;
        if ($b0 === null || $L[$after] !== $b0) return true;
    }
    return false;
}

/** true iff $hay's tokens END WITH exactly $needle's tokens. */
function bm_ends_with(array $hay, array $needle): bool {
    $n = count($needle); $h = count($hay);
    if ($n === 0 || $n > $h) return false;
    for ($j = 0; $j < $n; $j++) {
        if ($hay[$h - $n + $j] !== $needle[$j]) return false;
    }
    return true;
}

/** Ensure the reversible backup table exists. */
function bm_ensure_bak(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS yy_feed_item_transcript_balloon_bak (
        bak_key       bigserial PRIMARY KEY,
        run_id        text        NOT NULL,
        feed_item_key integer     NOT NULL,
        transcript_key bigint     NOT NULL,
        segment       interval    NOT NULL,
        old_text      text        NOT NULL,
        new_text      text        NOT NULL,
        method        text        NOT NULL,
        absorbed      smallint     NOT NULL,
        dtime         timestamptz NOT NULL DEFAULT now()
    )");
}

/**
 * For one item, return list of repairs:
 *   ['key','segment','old','new','method','absorbed']
 */
function bm_plan_item(PDO $db, int $fik): array {
    $st = $db->prepare("SELECT feed_item_transcript_key AS key,
                               feed_item_transcript_segment::text AS seg,
                               feed_item_transcript_text AS txt
                          FROM yy_feed_item_transcript
                         WHERE feed_item_key = ?
                         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
    $st->execute([$fik]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $n = count($rows);
    if ($n < 2) return [];

    // Pre-reconcile snapshot: segment(text) -> list of NORMALIZED token arrays,
    // plus the whole snapshot as one ordered token stream (for alignment-robust
    // confirmation when reconcile re-segmented).
    $snap = [];          // segkey -> [ tokenArray, ... ]
    $snapStream = [];    // ordered tokens across all snapshot rows
    $ss = $db->prepare("SELECT snapshot_json FROM yy_transcript_snapshot
                         WHERE feed_item_key = ? AND snapshot_reason = 'pre auto LLM reconcile (init)'
                         ORDER BY snapshot_key DESC LIMIT 1");
    $ss->execute([$fik]);
    $sj = $ss->fetchColumn();
    if ($sj) {
        $arr = json_decode((string)$sj, true);
        if (is_array($arr)) foreach ($arr as $e) {
            $txt = (string)($e['text'] ?? '');
            $tk = bm_norm($txt);
            $seg = (string)($e['segment'] ?? '');
            if ($seg !== '') $snap[bm_segkey($seg)][] = ['tok' => $tk, 'txt' => $txt];
            foreach ($tk as $t) $snapStream[] = $t;
        }
    }
    // The balloon bug is specific to the L3 init reconcile, which ALWAYS snapshots
    // first. No snapshot ⇒ the item never ran reconcile ⇒ any "line ends with next
    // line" here is a build-time artifact or a genuine repeat, NOT a reconcile
    // balloon — out of scope, and unsafe to trim blind. Skip the whole item.
    if (!$snap) return [];

    // Pre-normalize each row once.
    $tok = [];
    foreach ($rows as $i => $r) $tok[$i] = bm_norm($r['txt']);

    $repairs = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $hay = $tok[$i];
        if (count($hay) < 5) continue;

        // Grow the swallowed block across the following rows and keep the LARGEST
        // k for which the row text ends with the accumulated block. A balloon that
        // absorbs i+1..i+k ends with i+k, so a shorter prefix (just i+1) is NOT
        // itself a suffix — we must test every k, not break on the first miss.
        // Require the FIRST absorbed row to be non-trivial (guards coincidence).
        if (count($tok[$i + 1]) < MIN_NEXT) continue;
        $cand = [];
        $absorbed = 0;
        for ($k = 1; $k <= MAXK && $i + $k < $n; $k++) {
            $nextTok = $tok[$i + $k];
            if (count($nextTok) < 1) break;
            $cand = array_merge($cand, $nextTok);
            if (bm_ends_with($hay, $cand)) $absorbed = $k;   // remember longest match
        }
        if ($absorbed === 0) continue;
        // Rebuild the confirmed block + tail-char count for the chosen k.
        $block = []; $tailChars = 0;
        for ($k = 1; $k <= $absorbed; $k++) {
            $block = array_merge($block, $tok[$i + $k]);
            $tailChars += mb_strlen($rows[$i + $k]['txt']);
        }
        if ($tailChars < MIN_TAIL) continue;

        // Leading remainder must be non-empty (row's own text survives).
        $prefixLen = count($hay) - count($block);
        if ($prefixLen < 1) continue;

        $old = $rows[$i]['txt'];
        $seg = $rows[$i]['seg'];
        $new = null; $method = null;

        // 1) Snapshot restore: a snapshot row at this segment whose tokens equal
        //    the leading remainder (exact pre-reconcile text — always safe).
        $prefixTok = array_slice($hay, 0, $prefixLen);
        $segK = bm_segkey($seg);
        if (isset($snap[$segK])) {
            foreach ($snap[$segK] as $c) {
                if ($c['tok'] === $prefixTok && trim($c['txt']) !== '') {
                    $new = $c['txt']; $method = 'snapshot'; break;
                }
            }
        }

        // 2) Trim: strip the trailing duplicated block — but ONLY when the snapshot
        //    POSITIVELY confirms this is a reconcile balloon (the block was added by
        //    reconcile, not pre-existing). Two independent confirmations, either
        //    suffices:
        //      (a) a snapshot line at this segment contains the kept prefix as a
        //          contiguous run with the block NOT beginning right after it, or
        //      (b) the whole ordered snapshot contains "prefix+block" once and the
        //          block does NOT repeat immediately after (a genuine double-
        //          utterance would repeat → left untouched).
        //    Misaligned fragments, genuine repeats and songs fail both → skipped.
        if ($new === null) {
            $confirmed = false;
            if (isset($snap[$segK])) {
                foreach ($snap[$segK] as $c) {
                    if (bm_confirms($c['tok'], $prefixTok, $block)) { $confirmed = true; break; }
                }
            }
            if (!$confirmed && $snapStream) {
                $pb = array_merge($prefixTok, $block);
                $q = bm_find($snapStream, $pb, 0);
                if ($q >= 0 && !bm_find_at($snapStream, $block, $q + count($pb))) $confirmed = true;
            }
            if (!$confirmed) continue;   // no positive proof → leave the row untouched
            $firstNext = $rows[$i + 1]['txt'];
            $pos = mb_strrpos($old, $firstNext);
            if ($pos !== false && $pos > 0) {
                $cut = rtrim(mb_substr($old, 0, $pos));
                $cut = rtrim($cut, " ,;:—-");
                if (trim($cut) !== '' && bm_norm($cut) === $prefixTok) {
                    $new = $cut; $method = 'trim';
                }
            }
        }

        if ($new === null || $new === $old) continue;
        // Short-tail safety: a 1–2 word swallowed block is only trustworthy when
        // the pre-reconcile snapshot confirms the leading remainder (trim could
        // otherwise clip a genuine spoken repetition).
        if (count($block) < SHORT_TAIL && $method !== 'snapshot') continue;
        $repairs[] = ['key' => (int)$rows[$i]['key'], 'segment' => $seg,
                      'old' => $old, 'new' => $new, 'method' => $method, 'absorbed' => $absorbed];
    }
    return $repairs;
}

/* ---- driver ----------------------------------------------------------- */

if ($all) {
    // Only items that actually have a ballooned row (fast pre-filter).
    $items = $db->query("
        WITH seq AS (
          SELECT feed_item_key AS fik, feed_item_transcript_text AS txt,
                 lead(feed_item_transcript_text) OVER (
                   PARTITION BY feed_item_key
                   ORDER BY feed_item_transcript_sort, feed_item_transcript_segment) AS nxt
          FROM yy_feed_item_transcript)
        SELECT DISTINCT fik FROM seq
        WHERE nxt IS NOT NULL AND length(nxt) >= 8
          AND length(txt) > length(nxt) + 6 AND position(nxt IN txt) > 1
        ORDER BY fik")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $items = [$single];
}

$runId = 'balloon-' . gmdate('Ymd-His') . '-' . getmypid();
$totalRows = 0; $totalItems = 0; $bySnap = 0; $byTrim = 0;
if ($apply) bm_ensure_bak($db);

foreach ($items as $fik) {
    $fik = (int)$fik;
    $repairs = bm_plan_item($db, $fik);
    if (!$repairs) continue;
    $totalItems++;
    $totalRows += count($repairs);

    echo "item $fik: " . count($repairs) . " ballooned row(s)\n";
    foreach ($repairs as $r) {
        if ($r['method'] === 'snapshot') $bySnap++; else $byTrim++;
        if ($verbose || !$apply) {
            echo "  [{$r['segment']}] ({$r['method']}, +{$r['absorbed']})\n";
            echo "    - " . mb_substr($r['old'], 0, 140) . "\n";
            echo "    + " . mb_substr($r['new'], 0, 140) . "\n";
        }
    }

    if ($apply) {
        $db->beginTransaction();
        try {
            $bak = $db->prepare("INSERT INTO yy_feed_item_transcript_balloon_bak
                (run_id, feed_item_key, transcript_key, segment, old_text, new_text, method, absorbed)
                VALUES (?, ?, ?, ?::interval, ?, ?, ?, ?)");
            $upd = $db->prepare("UPDATE yy_feed_item_transcript
                                    SET feed_item_transcript_text = ?,
                                        feed_item_transcript_revision_dtime = NOW()
                                  WHERE feed_item_transcript_key = ? AND feed_item_key = ?");
            foreach ($repairs as $r) {
                $bak->execute([$runId, $fik, $r['key'], $r['segment'], $r['old'], $r['new'], $r['method'], $r['absorbed']]);
                $upd->execute([$r['new'], $r['key'], $fik]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            fwrite(STDERR, "item $fik FAILED: " . $e->getMessage() . "\n");
        }
    }
}

echo "\n" . ($apply ? "APPLIED" : "DRY-RUN") . ": $totalRows row(s) across $totalItems item(s)"
   . " (snapshot=$bySnap, trim=$byTrim). run_id=$runId\n";
if (!$apply) echo "re-run with --apply to write (reversible via yy_feed_item_transcript_balloon_bak).\n";
