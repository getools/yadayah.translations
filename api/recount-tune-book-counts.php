<?php
/**
 * recount-tune-book-counts.php  —  CLI only
 *
 * Recomputes yy_tts_tune.tts_tune_book_count (the "# occurrences in the books"
 * column in admin-tts) for EVERY tune of a profile, corpus-wide and gate-aware,
 * by streaming yy_paragraph once and delegating each hit test to the real synth
 * matcher (ttsCountTuneInHtml → substituteTunes). Apostrophe/half-ring variants
 * are one character, and the count honours each row's bold/italic/case gate.
 *
 * Two callers:
 *   • One-time BACKFILL to fix the stale seed values on the existing ~3.4K rows.
 *   • The book pipeline worker, after a volume re-parse (paragraphs changed →
 *     counts changed), invoked detached so the parse stays fast.
 *
 * Usage:
 *   php recount-tune-book-counts.php [--tts-key=1] [--write] [--print=WORD]
 *                                    [--limit-paras=N] [--quiet]
 *
 *   --write        actually UPDATE (default is a dry-run diff preview)
 *   --print=WORD   recount only this one Print (fast path; uses the SQL
 *                  pre-filter in recountTuneBookCountForPrint)
 *   --limit-paras  cap paragraphs scanned (smoke-testing only)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$opts = getopt('', ['tts-key::', 'write', 'print::', 'limit-paras::', 'quiet']);
$ttsKey    = isset($opts['tts-key']) && $opts['tts-key'] !== '' ? (int)$opts['tts-key'] : 1;
$write     = array_key_exists('write', $opts);
$onlyPrint = isset($opts['print']) && $opts['print'] !== '' ? (string)$opts['print'] : null;
$limitPara = isset($opts['limit-paras']) && $opts['limit-paras'] !== '' ? (int)$opts['limit-paras'] : 0;
$quiet     = array_key_exists('quiet', $opts);

$log = function (string $m) use ($quiet) { if (!$quiet) fwrite(STDERR, $m . "\n"); };

$db = getDb();

/* ---- single-Print fast path -------------------------------------------- */
if ($onlyPrint !== null) {
    if (!$write) {
        $log("--print with dry-run just previews; pass --write to persist.");
    }
    $n = recountTuneBookCountForPrint($db, $ttsKey, $onlyPrint);
    $chk = $db->prepare("SELECT tts_tune_key, tts_tune_book_count FROM yy_tts_tune WHERE tts_key = ? AND tts_tune_print = ?");
    $chk->execute([$ttsKey, $onlyPrint]);
    foreach ($chk->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $log(sprintf("  tune %-6d  %s = %d", $r['tts_tune_key'], $onlyPrint, $r['tts_tune_book_count']));
    }
    $log("Recounted $n row(s) for Print \"$onlyPrint\".");
    exit(0);
}

/* ---- full corpus sweep -------------------------------------------------- */
$sel = $db->prepare("SELECT * FROM yy_tts_tune WHERE tts_key = ? ORDER BY tts_tune_key");
$sel->execute([$ttsKey]);
$tunes = $sel->fetchAll(PDO::FETCH_ASSOC);
$fonts = ttsLoadFontRules($db, $ttsKey);   // for preprocessFontFilter, per paragraph
$log(sprintf("Loaded %d tunes for tts_key=%d (%d font rules). Precomputing cores…",
    count($tunes), $ttsKey, count($fonts)));

// Precompute each tune's normalised core once. Skip empty cores (degenerate
// all-apostrophe Prints — they never pre-filter cleanly and are vanishingly
// rare); their count stays whatever it was.
$counts = [];   // tune_key => running count
$live   = [];   // index list of tunes that have a usable core
foreach ($tunes as $i => &$t) {
    $t['_core']  = ttsNormalizeCore((string)$t['tts_tune_print']);
    $t['_old']   = (int)$t['tts_tune_book_count'];
    $t['_gated'] = ttsTuneIsFormatGated($t);   // bold/italic → count against tagged HTML
    $counts[(int)$t['tts_tune_key']] = 0;
    if ($t['_core'] !== '') $live[] = $i;
}
unset($t);
$log(sprintf("%d tunes have a matchable core. Streaming paragraphs…", count($live)));

// Stream paragraphs by keyset pagination so PHP never holds the whole 100MB
// corpus in memory. Per paragraph: normalise the PLAIN text once (whole words,
// formatting-free — exactly what the build's segmentParagraph feeds applyTunes),
// a cheap byte strpos pre-filter per tune (cores are ASCII transliterations),
// then count. Un-gated tunes count over plain text so every occurrence counts
// regardless of bold/italic; the rare bold/italic-gated tune counts against the
// font-filtered HTML, built lazily only when such a tune is actually a candidate.
$batchSize = 2000;
$lastKey   = 0;
$scanned   = 0;
$q = $db->prepare(
    "SELECT paragraph_key, paragraph_text_plain, paragraph_text_html
       FROM yy_paragraph
      WHERE paragraph_key > :k AND paragraph_text_html <> ''
      ORDER BY paragraph_key
      LIMIT :lim"
);
while (true) {
    $q->bindValue(':k', $lastKey, PDO::PARAM_INT);
    $q->bindValue(':lim', $batchSize, PDO::PARAM_INT);
    $q->execute();
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) break;

    foreach ($rows as $row) {
        $lastKey = (int)$row['paragraph_key'];
        $plain = (string)$row['paragraph_text_plain'];
        $norm  = ttsNormalizeCore($plain);   // apostrophe-stripped, lowercased
        $ppHtml = null;                       // font-filtered HTML, built on demand
        foreach ($live as $i) {
            $t = $tunes[$i];
            if (strpos($norm, $t['_core']) === false) continue;
            if ($t['_gated']) {
                if ($ppHtml === null) $ppHtml = preprocessFontFilter((string)$row['paragraph_text_html'], $fonts);
                $counts[(int)$t['tts_tune_key']] += ttsCountTuneInHtml($ppHtml, $t);
            } else {
                $counts[(int)$t['tts_tune_key']] += ttsCountTuneInHtml($plain, $t);
            }
        }
        $scanned++;
        if ($limitPara && $scanned >= $limitPara) break 2;
    }
    $log(sprintf("  … %d paragraphs scanned (key %d)", $scanned, $lastKey));
}
$log(sprintf("Scanned %d paragraphs.", $scanned));

/* ---- diff + optional write --------------------------------------------- */
$changed = 0;
$upd = $db->prepare("UPDATE yy_tts_tune SET tts_tune_book_count = ? WHERE tts_tune_key = ?");
if ($write) $db->beginTransaction();
foreach ($tunes as $t) {
    $key = (int)$t['tts_tune_key'];
    $new = $counts[$key];
    $old = (int)$t['_old'];
    if ($new === $old) continue;
    $changed++;
    if ($changed <= 40) {
        $log(sprintf("  %-6d %-24s %6d -> %6d", $key, $t['tts_tune_print'], $old, $new));
    }
    if ($write) $upd->execute([$new, $key]);
}
if ($write) $db->commit();

$log(sprintf("%s %d of %d rows would change (%d unchanged).",
    $write ? 'WROTE' : 'DRY-RUN:', $changed, count($tunes), count($tunes) - $changed));
if (!$write) $log("Re-run with --write to persist.");
exit(0);
