<?php
/**
 * Re-audit still-applied TRIM repairs and (with --revert) restore any that are
 * not POSITIVELY confirmed as reconcile balloons by the snapshot.
 *
 *   php transcript-balloon-audit.php [--revert]
 *
 * A trim is confirmed CLEAN iff a pre-reconcile snapshot line at the same segment
 * is a token-prefix of the trimmed (kept) text: pre-reconcile the row was that
 * shorter line, reconcile grew it, and the removed block was purely reconcile-
 * added. Anything else — misaligned snapshot fragment, block content already
 * present pre-reconcile (genuine repeat / song), or reconcile-edited prefix we
 * cannot align — is SUSPICIOUS and reverted to old_text (restores the exact
 * pre-sweep state; at worst leaves benign duplication, never loses content).
 * Snapshot-restore repairs are exact and are never touched.
 */
require_once __DIR__ . '/config.php';

function ab_norm(string $s): array {
    $s = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return $s === '' ? [] : explode(' ', $s);
}
function ab_segkey(string $s): int {
    $s = trim($s);
    if (preg_match('/^(?:(\d+):)?(\d+):(\d+(?:\.\d+)?)$/', $s, $m))
        return (int)round((((int)($m[1] ?: 0))*3600 + ((int)$m[2])*60 + (float)$m[3]) * 1000);
    return (int)round(((float)$s) * 1000);
}
/**
 * Confirm a clean reconcile balloon: the snapshot line $L contains the kept
 * prefix $P as a contiguous run, and the removed block $B does NOT begin
 * immediately after that run (so $B was reconcile-added, not pre-existing).
 * Returns true when at least one occurrence of $P in $L qualifies.
 */
function ab_confirms(array $L, array $P, array $B): bool {
    $p = count($P); $h = count($L);
    if ($p === 0 || $p > $h) return false;
    $b0 = $B[0] ?? null;
    for ($i = 0; $i + $p <= $h; $i++) {
        $match = true;
        for ($j = 0; $j < $p; $j++) if ($L[$i+$j] !== $P[$j]) { $match = false; break; }
        if (!$match) continue;
        $after = $i + $p;
        if ($after >= $h) return true;          // prefix ends the line — clean
        if ($b0 === null || $L[$after] !== $b0) return true; // block not next — clean
        // else: block content already present after prefix → not this occurrence
    }
    return false;
}

/** Whole pre-reconcile snapshot as one ordered normalized token stream. */
function ab_stream(PDO $db, array &$cache, int $fik): array {
    if (isset($cache[$fik])) return $cache[$fik];
    $ss = $db->prepare("SELECT snapshot_json FROM yy_transcript_snapshot
                         WHERE feed_item_key=? AND snapshot_reason='pre auto LLM reconcile (init)'
                         ORDER BY snapshot_key DESC LIMIT 1");
    $ss->execute([$fik]);
    $out = []; $sj = $ss->fetchColumn();
    if ($sj) { $arr = json_decode((string)$sj, true);
        if (is_array($arr)) foreach ($arr as $e)
            foreach (ab_norm((string)($e['text']??'')) as $t) $out[] = $t; }
    return $cache[$fik] = $out;
}
function ab_find_at(array $hay, array $needle, int $at): bool {
    $n = count($needle); if ($n === 0 || $at + $n > count($hay)) return false;
    for ($j = 0; $j < $n; $j++) if ($hay[$at+$j] !== $needle[$j]) return false;
    return true;
}
function ab_find(array $hay, array $needle, int $from): int {
    $n = count($needle); $h = count($hay);
    if ($n === 0 || $n > $h) return -1;
    for ($i = max(0,$from); $i + $n <= $h; $i++) if (ab_find_at($hay,$needle,$i)) return $i;
    return -1;
}

$revert = in_array('--revert', array_slice($argv, 1), true);
$db = getDb();
$streamCache = [];
$streamConfirmed = 0; $susp_noline = 0; $susp_noconfirm = 0;

$rows = $db->query("
    SELECT b.transcript_key, b.feed_item_key, b.segment::text AS seg, b.old_text, b.new_text
    FROM yy_feed_item_transcript_balloon_bak b
    WHERE b.method='trim'
    ORDER BY b.feed_item_key, b.segment")->fetchAll(PDO::FETCH_ASSOC);

$snapCache = [];
function ab_snap(PDO $db, array &$cache, int $fik): array {
    if (isset($cache[$fik])) return $cache[$fik];
    $ss = $db->prepare("SELECT snapshot_json FROM yy_transcript_snapshot
                         WHERE feed_item_key=? AND snapshot_reason='pre auto LLM reconcile (init)'
                         ORDER BY snapshot_key DESC LIMIT 1");
    $ss->execute([$fik]);
    $map = []; $sj = $ss->fetchColumn();
    if ($sj) { $arr = json_decode((string)$sj, true);
        if (is_array($arr)) foreach ($arr as $e) {
            $seg=(string)($e['segment']??''); if($seg==='')continue;
            $map[ab_segkey($seg)][] = ab_norm((string)($e['text']??''));
        } }
    return $cache[$fik] = $map;
}

// live text check so we never revert a row a human touched after the sweep
$live = $db->prepare("SELECT feed_item_transcript_text FROM yy_feed_item_transcript
                       WHERE feed_item_transcript_key=? AND feed_item_key=?");
$upd  = $db->prepare("UPDATE yy_feed_item_transcript
                         SET feed_item_transcript_text=?, feed_item_transcript_revision_dtime=NOW()
                       WHERE feed_item_transcript_key=? AND feed_item_key=? AND feed_item_transcript_text=?");

$applied=0; $clean=0; $suspicious=0; $reverted=0;
foreach ($rows as $r) {
    $live->execute([(int)$r['transcript_key'], (int)$r['feed_item_key']]);
    $cur = $live->fetchColumn();
    if ($cur === false || $cur !== $r['new_text']) continue;  // already reverted / edited
    $applied++;

    $prefixTok = ab_norm($r['new_text']);
    $oldTok    = ab_norm($r['old_text']);
    $block     = array_slice($oldTok, count($prefixTok));   // new is a literal prefix of old
    $snap = ab_snap($db, $snapCache, (int)$r['feed_item_key']);
    $segK = ab_segkey($r['seg']);
    $ok = false; $hasSnapLine = isset($snap[$segK]);
    if ($hasSnapLine && $block) {
        foreach ($snap[$segK] as $L) { if (ab_confirms($L, $prefixTok, $block)) { $ok = true; break; } }
    }
    if ($ok) { $clean++; continue; }
    // stream fallback: search the whole ordered snapshot for "prefix block" and
    // require the block NOT to repeat immediately after (that would be a genuine
    // double-utterance, not a reconcile balloon).
    if ($block) {
        $stream = ab_stream($db, $streamCache, (int)$r['feed_item_key']);
        $pb = array_merge($prefixTok, $block);
        $q = ab_find($stream, $pb, 0);
        if ($q >= 0) {
            $afterPos = $q + count($pb);
            $repeats = ab_find_at($stream, $block, $afterPos);
            if (!$repeats) { $clean++; $streamConfirmed++; continue; }
        }
    }
    $suspicious++;
    if (!$hasSnapLine) $susp_noline++; else $susp_noconfirm++;
    if ($revert) { $upd->execute([$r['old_text'], (int)$r['transcript_key'], (int)$r['feed_item_key'], $r['new_text']]);
                   $reverted += $upd->rowCount(); }
}
echo ($revert?"REVERT":"AUDIT").": applied_trims=$applied clean_keep=$clean (stream_confirmed=$streamConfirmed)"
   . " suspicious=$suspicious [no_snapline=$susp_noline, has_line_no_confirm=$susp_noconfirm]"
   . ($revert ? " reverted=$reverted" : "") . "\n";
