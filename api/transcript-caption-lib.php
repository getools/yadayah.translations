<?php
/**
 * transcript-caption-lib.php — pure caption timing + re-flow helpers.
 *
 * Side-effect-free (no auth, DB, or HTTP dispatch) so it can be require'd from
 * the Smart Captions endpoint (admin-transcript-captionfit.php), the consensus
 * "Initialize Edits" worker (admin-transcript-init-worker.php), and CLI tests.
 * Extracted from admin-transcript-captionfit.php on 2026-06-21; cfReflow gained
 * break_gap (pause breaks) + boundary_set (baseline segment-boundary breaks).
 */

/** Broadcast 42x2 defaults (every value is tunable from the UI). */
function cfDefaults(): array {
    return [
        'max_chars'   => 42,
        'max_lines'   => 2,      // HARD ceiling: a cue is never wrapped to more lines than this.
        'preferred_lines' => 1,  // Soft target: re-flow aims for this many lines, breaking at the
                                 // next natural boundary (punctuation/pause) once a cue fills it,
                                 // and only grows toward max_lines when no boundary is available.
                                 // Clamped into [1, max_lines].
        'max_secs'    => 7.0,
        'min_secs'    => 1.2,
        'cps'         => 17.0,
        'break_punct' => true,
        'break_gap'   => 0.6,   // 0 = off; else flush when the pause before a word >= this many secs
        // Soft character ceiling: the char cap (max_chars*max_lines) is a
        // TARGET, not a wall. A cue may run up to soft_overflow x the target
        // while it waits for the next natural boundary (punctuation/pause), so
        // sentences break on a comma/pause/period instead of mid-clause. Only
        // when even the slack is exceeded (or max_secs is hit) does it hard
        // break. 1.0 = old strict behaviour; ~1.5 = strongly boundary-first.
        'soft_overflow' => 1.5,
        'dedup'       => true,
    ];
}
// Global kept for the captionfit endpoint, which references `global $CAPTION_DEFAULTS`.
$CAPTION_DEFAULTS = cfDefaults();

function cfIntervalToSecs(string $s): float {
    $s = trim($s);
    if ($s === '') return 0.0;
    $p = explode(':', $s);
    $n = count($p);
    if ($n === 3) return (float)$p[0] * 3600 + (float)$p[1] * 60 + (float)$p[2];
    if ($n === 2) return (float)$p[0] * 60 + (float)$p[1];
    return (float)$p[0];
}

function cfSecsToInterval(float $secs): string {
    if ($secs < 0) $secs = 0;
    $whole = (int)floor($secs);
    $h = intdiv($whole, 3600);
    $m = intdiv($whole % 3600, 60);
    $sec = $whole % 60;
    $ms = (int)round(($secs - $whole) * 1000);
    if ($ms >= 1000) { $ms = 0; $sec++; }
    return sprintf('%02d:%02d:%02d.%03d', $h, $m, $sec, $ms);
}

/** Greedy word-wrap into lines of <= $maxChars. A word longer than the limit
 *  gets its own line. Returns the line array (length is checked by the caller). */
function cfWrap(array $words, int $maxChars): array {
    $lines = [];
    $line = '';
    foreach ($words as $w) {
        if ($line === '') { $line = $w; continue; }
        if (mb_strlen($line) + 1 + mb_strlen($w) <= $maxChars) {
            $line .= ' ' . $w;
        } else {
            $lines[] = $line;
            $line = $w;
        }
    }
    if ($line !== '') $lines[] = $line;
    return $lines;
}

/** Collapse immediate repeats of a >=3-word run (YouTube rollup duplication).
 *  Conservative on purpose: only exact (letters/digits, case-insensitive)
 *  back-to-back repeats are removed, never genuine rhetorical repetition. */
function cfDedup(array $words): array {
    $norm = function ($w) { return mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $w)); };
    $eq = function ($a, $b) use ($words, $norm) { return $norm($words[$a]['w']) === $norm($words[$b]['w']); };
    $n = count($words);
    $out = [];
    $i = 0;
    while ($i < $n) {
        $dropped = false;
        $maxL = (int)min(8, intdiv($n - $i, 2));
        for ($L = $maxL; $L >= 2; $L--) {
            $same = true;
            for ($k = 0; $k < $L; $k++) {
                if (!$eq($i + $k, $i + $L + $k)) { $same = false; break; }
            }
            if ($same && $norm($words[$i]['w']) !== '') {
                for ($k = 0; $k < $L; $k++) $out[] = $words[$i + $k]; // emit the unit once
                // Skip every further back-to-back copy of the same unit (handles N-peat).
                $j = $i + $L;
                while ($j + $L <= $n) {
                    $rep = true;
                    for ($k = 0; $k < $L; $k++) { if (!$eq($j + $k, $i + $k)) { $rep = false; break; } }
                    if (!$rep) break;
                    $j += $L;
                }
                $i = $j;
                $dropped = true;
                break;
            }
        }
        if (!$dropped) { $out[] = $words[$i]; $i++; }
    }
    return $out;
}

/** Re-flow a timed word stream into caption cues.
 *  $words: [['t'=>float startSec, 'w'=>string], ...] sorted ascending by t.
 *  Options: max_chars,max_lines,preferred_lines,max_secs,min_secs,break_punct,dedup, plus
 *    (preferred_lines: soft line target in [1,max_lines], default 1; a cue is
 *     never wrapped to more than max_lines lines regardless of the char slack)
 *    break_gap   (float secs; >0 flushes the cue before a word whose pause from
 *                 the previous word is >= break_gap), and
 *    boundary_set(sorted float[] of segment-start secs; a boundary falling
 *                 between two words flushes the cue before the later word).
 *  Returns [['start'=>float, 'text'=>"line1\nline2", 'chars'=>int, 'lines'=>int], ...]. */
function cfReflow(array $words, array $o): array {
    $maxChars = max(10, (int)$o['max_chars']);
    $maxLines = max(1, (int)$o['max_lines']);
    // Preferred (soft) line target — the re-flow biases its natural-boundary
    // breaks toward this many lines, but never lets a cue exceed $maxLines.
    // Clamped into [1, $maxLines]; defaults to 1.
    $prefLines = max(1, min($maxLines, (int)($o['preferred_lines'] ?? 1)));
    $maxSecs  = (float)$o['max_secs'];
    $minSecs  = (float)$o['min_secs'];
    $breakP   = !empty($o['break_punct']);
    $breakGap = (float)($o['break_gap'] ?? 0);
    $softOver = max(1.0, (float)($o['soft_overflow'] ?? 1.0));
    $bounds   = (isset($o['boundary_set']) && is_array($o['boundary_set'])) ? $o['boundary_set'] : null;
    $nb = $bounds ? count($bounds) : 0;
    $bi = 0;
    if (!empty($o['dedup'])) $words = cfDedup($words);

    $cap = $maxChars * $maxLines;
    // Preferred char target — one $prefLines-tall cue's worth of text. The
    // punctuation/pause breaks aim for THIS so cues settle near the preferred
    // height, not the max. Equal to $cap when preferred_lines == max_lines
    // (i.e. old behaviour is preserved for that configuration).
    $prefCap = $maxChars * $prefLines;
    // The char cap is a TARGET the punctuation/pause breaks aim for; the hard
    // wall sits at soft_overflow x that, so a boundary-less run only gets cut
    // mid-clause once it exceeds the slack (or trips max_secs). Independently,
    // the wrapped line count is hard-capped at $maxLines below so a cue can
    // never render taller than the configured maximum.
    $softCap = (int)round($cap * $softOver);
    $cues = [];
    $cur = [];
    $curStart = null;
    $prevT = null;

    $flush = function () use (&$cues, &$cur, &$curStart, $maxChars) {
        if (!$cur) return;
        $lines = cfWrap(array_map(fn($x) => $x['w'], $cur), $maxChars);
        $text = implode("\n", $lines);
        $cues[] = ['start' => $curStart, 'text' => $text,
                   'chars' => mb_strlen(str_replace("\n", '', $text)), 'lines' => count($lines)];
        $cur = [];
        $curStart = null;
    };

    foreach ($words as $wi) {
        // Pre-add break: a long pause (break_gap) or a baseline segment boundary
        // between the previous word and this one ends the current cue early —
        // but only once the cue has dwelt long enough to avoid tiny fragments.
        if ($cur && $prevT !== null) {
            $hitGap = ($breakGap > 0 && ($wi['t'] - $prevT) >= $breakGap);
            $hitBoundary = false;
            if ($nb) {
                while ($bi < $nb && $bounds[$bi] <= $prevT) $bi++;
                if ($bi < $nb && $bounds[$bi] <= $wi['t']) $hitBoundary = true;
            }
            if (($hitGap || $hitBoundary) && ($prevT - $curStart) >= 0.4) {
                $flush();
            }
        }
        if ($curStart === null) $curStart = $wi['t'];
        $trial = $cur;
        $trial[] = $wi;
        // Hard break is char-based against the soft ceiling (so the cue can
        // overflow the char target while hunting for the next natural
        // boundary) AND line-based against $maxLines (so the cue can NEVER be
        // wrapped to more rows than the configured maximum — the char slack is
        // only ever spent within that line budget). Punctuation/pause breaks
        // below still end most cues near the preferred height before either
        // wall is reached. A lone word already at $curStart is kept even if it
        // alone exceeds a wall — cfWrap gives an over-long word its own single
        // line, so the $maxLines guarantee still holds.
        $trialWords = array_map(fn($x) => $x['w'], $trial);
        $trialChars = mb_strlen(implode(' ', $trialWords));
        $trialLines = count(cfWrap($trialWords, $maxChars));
        $fits = $trialChars <= $softCap && $trialLines <= $maxLines;
        $overTime = ($wi['t'] - $curStart) > $maxSecs;
        if ($cur && (!$fits || $overTime)) {
            $flush();
            $curStart = $wi['t'];
            $cur = [$wi];
        } else {
            $cur = $trial;
        }
        // Prefer to break after sentence/clause punctuation once the cue is
        // reasonably full or has shown for the minimum dwell time. Fullness is
        // measured against the PREFERRED height ($prefCap), so cues settle near
        // preferred_lines and only grow toward max_lines when no punctuation
        // arrives. (When preferred_lines == max_lines, $prefCap == $cap and
        // this matches the previous behaviour exactly.)
        if ($breakP && preg_match('/[.?!…।,;:]["\')\]\x{201D}\x{2019}]?$/u', $wi['w'])) {
            $curText = implode(' ', array_map(fn($x) => $x['w'], $cur));
            $sentenceEnd = (bool)preg_match('/[.?!…।]["\')\]\x{201D}\x{2019}]?$/u', $wi['w']);
            if (($sentenceEnd && (mb_strlen($curText) >= $prefCap * 0.4 || ($wi['t'] - $curStart) >= $minSecs))
                || mb_strlen($curText) >= $prefCap * 0.85) {
                $flush();
            }
        }
        $prevT = $wi['t'];
    }
    $flush();
    return $cues;
}

/** Expand timed transcript rows into a per-word timed stream. Word-level rows
 *  (already one word each) keep their exact timestamps; multi-word rows get
 *  their words spread linearly across the gap to the next row.
 *
 *  Optional $o['cps'] (chars/sec) makes the spread *pause-aware*: a multi-word
 *  row is only stretched across the time its text actually takes to read
 *  (chars / cps); any leftover before the next row is left as a real silent
 *  gap. This is what lets cfReflow's break_gap fire on pauses in an EDITED
 *  (current) transcript, whose multi-word rows otherwise fill every gap and so
 *  hide all pauses. Default (no $o) keeps the original fill-the-gap behaviour,
 *  so every existing single-arg caller is unchanged. $o['min_word_secs'] floors
 *  the per-word slot so a tiny row still spans a sensible duration. */
function cfRowsToWords(array $rows, array $o = []): array {
    $cps     = (float)($o['cps'] ?? 0);                 // >0 enables pause-aware trailing gaps
    $minWord = (float)($o['min_word_secs'] ?? 0.18);    // seconds of read time per word floor
    $words = [];
    $n = count($rows);
    for ($r = 0; $r < $n; $r++) {
        $txt = trim((string)$rows[$r]['text']);
        if ($txt === '') continue;
        $parts = preg_split('/\s+/u', $txt, -1, PREG_SPLIT_NO_EMPTY);
        $cnt = count($parts);
        if ($cnt === 0) continue;
        $start = (float)$rows[$r]['secs'];
        $next  = ($r + 1 < $n) ? (float)$rows[$r + 1]['secs'] : $start + max(1.0, $cnt / 3.0);
        if ($next <= $start) $next = $start + max(1.0, $cnt / 3.0);
        $end = $next;
        if ($cps > 0 && $cnt > 1) {
            // Estimated time to actually speak this row's text; if the slot to
            // the next row is longer, stop the words early and leave a pause.
            $estDur  = max($cnt * $minWord, mb_strlen($txt) / $cps);
            $spokenEnd = $start + $estDur;
            if ($spokenEnd < $end) $end = $spokenEnd;
        }
        for ($w = 0; $w < $cnt; $w++) {
            $words[] = ['t' => $start + ($end - $start) * ($w / $cnt), 'w' => $parts[$w]];
        }
    }
    return $words;
}

// ── Word-baseline timestamp snapping ────────────────────────────────────────
// When segments are (re)organised, a new segment's start is most accurate when
// READ from a word-level baseline at the position of the segment's first
// word(s), instead of interpolated from the old (possibly coarse) row times.
// These helpers pick the best word-level baseline, build its word stream, and
// re-anchor a sequence of cues onto it. FAIL-SAFE: if the baseline does not
// align well (sparse/clustered anchors) the original starts are returned
// unchanged, so a good interpolation is never degraded. Same technique the
// 30s-grid re-time remediation used.

// Word-level baselines, best first (mirrors the consensus init spine order).
const CF_WORD_BASELINES = ['gpu-whisperx-word', 'gpu-whisper-large-v3-word',
    'gpu-whisper-large-v3-turbo-word', 'gpu-parakeet-tdt-0.6b-v2-word', 'whisper-1-word-join'];

/** Best word-level baseline model present for an item, or null. */
function cfBestWordBaseline(PDO $db, int $itemKey): ?string {
    $st = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model
                          FROM yy_feed_item_transcript_auto WHERE feed_item_key = ?");
    $st->execute([$itemKey]);
    $have = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    foreach (CF_WORD_BASELINES as $m) if (isset($have[$m])) return $m;
    return null;
}

/** Ordered baseline WORD stream [['t'=>secs,'w'=>token], ...] from a model.
 *  Word-level rows are used as-is; multi-word rows are spread via cfRowsToWords. */
function cfBaselineWordStream(PDO $db, int $itemKey, string $model): array {
    $rows = cfLoadAuto($db, $itemKey, $model);
    if (!$rows) return [];
    $tw = 0;
    foreach ($rows as $r) $tw += max(1, count(preg_split('/\s+/u', trim($r['text']), -1, PREG_SPLIT_NO_EMPTY)));
    if ($tw / count($rows) <= 1.3) {                    // already word-level
        $stream = [];
        foreach ($rows as $r) { $w = trim($r['text']); if ($w !== '') $stream[] = ['t' => (float)$r['secs'], 'w' => $w]; }
        usort($stream, fn($a, $b) => $a['t'] <=> $b['t']);
        return $stream;
    }
    return cfRowsToWords($rows);
}

/** Longest strictly-increasing subsequence by the SECOND element of each [a,b]
 *  pair (the first element is assumed already non-decreasing). O(K log K) via
 *  patience sorting. Returns the kept pairs in order. */
function cfLisBySecond(array $pairs): array {
    $K = count($pairs);
    if ($K <= 1) return $pairs;
    $vals = array_map(fn($p) => $p[1], $pairs);
    $tailIdx = [];                      // tailIdx[L] = pair index of smallest tail of an increasing run of length L+1
    $prev = array_fill(0, $K, -1);
    for ($i = 0; $i < $K; $i++) {
        $v = $vals[$i];
        $lo = 0; $hi = count($tailIdx);
        while ($lo < $hi) { $mid = ($lo + $hi) >> 1; if ($vals[$tailIdx[$mid]] < $v) $lo = $mid + 1; else $hi = $mid; }
        $prev[$i] = $lo > 0 ? $tailIdx[$lo - 1] : -1;
        if ($lo === count($tailIdx)) $tailIdx[] = $i; else $tailIdx[$lo] = $i;
    }
    $out = [];
    for ($cur = end($tailIdx); $cur >= 0; $cur = $prev[$cur]) $out[] = $pairs[$cur];
    return array_reverse($out);
}

/** Re-anchor an ordered list of cues onto a baseline word stream.
 *  $cues: [['text'=>string,'start'=>float, ...], ...] in time order.
 *  Returns cues with 'start' (+ 'secs'/'segment' if present) re-derived from the
 *  baseline — or the originals unchanged when alignment is too sparse to trust.
 *
 *  Anchoring runs over the FULL concatenated word sequence of all cues (not just
 *  cue-boundary words) so it works regardless of how the cues are segmented —
 *  short caption fragments that start mid-sentence have weak first-word bigrams,
 *  but the full stream always has distinctive bigrams nearby. Each word position
 *  is matched to the baseline in a drift-tracked centered window (monotonic
 *  floor, no teleport); then every cue's FIRST-word offset is piecewise-linear
 *  mapped (in word space) to a baseline token index and its smooth time is read
 *  there (interp the INDEX, never the time). Fills &$stats. */
function cfSnapStartsToBaseline(array $cues, array $stream, array &$stats = []): array {
    $R = count($cues);
    $stats = ['applied' => false, 'rate' => 0.0, 'rows' => $R];
    if ($R < 4 || count($stream) < 5) return $cues;

    // baseline word tokens + times
    $tok = []; $bt = [];
    foreach ($stream as $s) foreach (cfWords($s['w']) as $ww) { $tok[] = $ww; $bt[] = (float)$s['t']; }
    $n = count($tok);
    if ($n < 5) return $cues;

    // full live word sequence + each cue's first-word offset into it
    $lw = []; $cueOff = [];
    foreach ($cues as $cu) { $cueOff[] = count($lw); foreach (cfWords((string)$cu['text']) as $w) $lw[] = $w; }
    $M = count($lw);
    if ($M < 4) return $cues;
    $scale = $n / $M;

    // (1) Candidate word->token anchors, then a Longest-Increasing-Subsequence
    //     cleanup. A predicted cursor `pt` (advances by `scale`/word, snaps to a
    //     match only when the match is CLOSE to the estimate) proposes a nearest
    //     TRIGRAM match in a centered window for each word. Trigrams rarely
    //     repeat, so false matches are few; the LIS over the candidates' token
    //     indices then discards the occasional out-of-order false anchor (e.g. a
    //     common phrase that also occurs in the outro) that would otherwise
    //     ratchet a greedy tracker to the end. Result: monotonic, clean anchors.
    $cand = []; $pt = 0.0; $W = 150; $stride = 2; $step = $scale * $stride;
    for ($w = 0; $w < $M - 2; $w += $stride) {
        $exp = (int)round($pt);
        $lo = max(0, $exp - $W); $hi = min($n - 2, $exp + $W);
        $w0 = $lw[$w]; $w1 = $lw[$w + 1]; $w2 = $lw[$w + 2];
        $best = -1; $bd = PHP_INT_MAX;
        for ($j = $lo; $j < $hi; $j++) {
            if ($tok[$j] !== $w0 || $tok[$j + 1] !== $w1 || $tok[$j + 2] !== $w2) continue;
            $d = abs($j - $exp); if ($d < $bd) { $bd = $d; $best = $j; }
        }
        if ($best >= 0) { $cand[] = [$w, $best]; if (abs($best - $exp) <= 40) $pt = $best; }
        $pt += $step;
    }
    // LIS (strictly increasing token index) over the candidates — keeps the
    // consistent monotonic backbone, drops outliers in either direction.
    $lis = cfLisBySecond($cand);
    $aW = []; $aT = [];
    foreach ($lis as $c) { $aW[] = $c[0]; $aT[] = $c[1]; }
    $A = count($aW);

    // coverage in WORD space — longest unanchored run + how close anchors reach
    // each end (extrapolated ends are the risky part → keep them short).
    $attempts = (int)ceil($M / $stride);
    $rate = $attempts > 0 ? $A / $attempts : 0.0;
    $longest = 0; $head = $M; $tail = $M;
    if ($A) {
        $prev = -1; foreach ($aW as $w) { $longest = max($longest, $w - $prev); $prev = $w; }
        $longest = max($longest, ($M - 1) - $prev);
        $head = $aW[0]; $tail = ($M - 1) - end($aW);
    }
    $runCap = max(120, (int)ceil($M * 0.05)); $endCap = max(60, (int)ceil($M * 0.03));
    $stats = ['applied' => false, 'rate' => round($rate, 3), 'rows' => $R, 'anchors' => $A,
              'words' => $M, 'longest_run' => $longest, 'head' => $head, 'tail' => $tail, 'baseline_words' => $n];
    if ($rate < 0.40 || $longest > $runCap || $head > $endCap || $tail > $endCap) return $cues;

    // (2) map each cue's first-word offset -> baseline token idx -> smooth time
    $tt = function (float $idx) use ($bt, $n): float {
        $k = (int)round($idx); if ($k < 0) $k = 0; if ($k > $n - 1) $k = $n - 1; return $bt[$k];
    };
    $g = 0;
    $mapIdx = function (int $wordPos) use ($aW, $aT, $A, $scale, &$g): float {
        if ($wordPos <= $aW[0]) return $aT[0] - ($aW[0] - $wordPos) * $scale;
        if ($wordPos >= $aW[$A - 1]) return $aT[$A - 1] + ($wordPos - $aW[$A - 1]) * $scale;
        while ($g < $A - 1 && $aW[$g + 1] <= $wordPos) $g++;
        while ($g > 0 && $aW[$g] > $wordPos) $g--;
        $den = $aW[$g + 1] - $aW[$g];
        $frac = $den > 0 ? ($wordPos - $aW[$g]) / $den : 0.0;
        return $aT[$g] + $frac * ($aT[$g + 1] - $aT[$g]);
    };
    $out = $cues; $last = -1.0;
    for ($i = 0; $i < $R; $i++) {
        $s = $tt($mapIdx($cueOff[$i]));
        if ($s <= $last) $s = $last + 0.001;            // strictly increasing (no dup segments)
        $last = $s;
        $out[$i]['start'] = $s;
        if (array_key_exists('secs', $out[$i]))    $out[$i]['secs'] = round($s, 3);
        if (array_key_exists('segment', $out[$i])) $out[$i]['segment'] = cfSecsToInterval($s);
    }
    $stats['applied'] = true;
    return $out;
}

// ── Reconciliation helpers (moved from admin-transcript-captionfit.php) ──────
//   DB loaders, baseline↔live alignment, and LLM prompt building shared by
//   Smart Captions (ai_chunk) and the consensus init worker's auto-reconcile.

function cfLoadLive(PDO $db, int $itemKey): array {
    $st = $db->prepare("
        SELECT feed_item_transcript_key AS k,
               to_char(feed_item_transcript_segment, 'HH24:MI:SS.MS') AS segment,
               feed_item_transcript_text AS text,
               feed_item_transcript_sort AS sort,
               feed_item_transcript_speaker AS speaker
          FROM yy_feed_item_transcript
         WHERE feed_item_key = ?
         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
    $st->execute([$itemKey]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['key' => (int)$r['k'], 'segment' => (string)$r['segment'],
                   'secs' => cfIntervalToSecs((string)$r['segment']),
                   'text' => (string)$r['text'], 'sort' => (int)$r['sort'],
                   'speaker' => $r['speaker']];
    }
    return $rows;
}

function cfLoadAuto(PDO $db, int $itemKey, string $model): array {
    $st = $db->prepare("
        SELECT to_char(feed_item_transcript_segment, 'HH24:MI:SS.MS') AS segment,
               feed_item_transcript_text AS text
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ?
         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
    $st->execute([$itemKey, $model]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['secs' => cfIntervalToSecs((string)$r['segment']), 'text' => (string)$r['text']];
    }
    return $rows;
}

function cfAutoModels(PDO $db, int $itemKey): array {
    $st = $db->prepare("
        SELECT feed_item_transcript_auto_model AS m, COUNT(*) AS n
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ?
         GROUP BY feed_item_transcript_auto_model ORDER BY m");
    $st->execute([$itemKey]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $code = (string)$r['m'];
        $out[] = ['code' => $code, 'rows' => (int)$r['n'],
                  'word_level' => (strpos($code, 'word') !== false)];
    }
    return $out;
}

/**
 * Speaker timeline for "break on speaker change": sorted [secs, label] pairs
 * from the editable rows that carry a diarised speaker label. Lets caption
 * re-flow both (a) break a cue whenever the speaker changes and (b) stamp each
 * resulting cue with the speaker active at its start. Empty when the transcript
 * has no speaker labels → the feature is a no-op and output is unchanged.
 */
function cfSpeakerTimeline(array $liveRows): array {
    $tl = [];
    foreach ($liveRows as $r) {
        $spk = $r['speaker'] ?? null;
        if ($spk !== null && $spk !== '') $tl[] = [(float)$r['secs'], (string)$spk];
    }
    usort($tl, fn($a, $b) => $a[0] <=> $b[0]);
    return $tl;
}

/** Speaker active at $secs = the last turn whose onset is at or before it. */
function cfSpeakerAt(array $tl, float $secs): ?string {
    $lab = null;
    foreach ($tl as $e) { if ($e[0] <= $secs + 0.001) $lab = $e[1]; else break; }
    return $lab;
}

/**
 * Time-align a baseline engine run to the live rows. For each live row we
 * gather the baseline text whose timestamps fall in that row's
 * [start, nextStart) span and concatenate it — giving, per live line, the
 * "same span, other engine" reading the LLM can cross-reference. Both inputs
 * are already ordered by time, so a single forward scan suffices.
 * Returns a map: live-row-index => aligned baseline text ('' if none).
 */
function cfAlignBaselineToLive(array $live, array $baseline, float $shift = 0.0): array {
    $n = count($live);
    $out = array_fill(0, $n, '');
    if (!$n || !$baseline) return $out;
    $bn = count($baseline);
    $bi = 0;
    for ($i = 0; $i < $n; $i++) {
        $start = $live[$i]['secs'] + $shift;
        $end   = (($i + 1 < $n) ? $live[$i + 1]['secs'] : INF) + $shift;
        // Skip baseline rows that start before this live span (already consumed
        // by an earlier line, or leading pre-roll for the very first line).
        while ($bi < $bn && $baseline[$bi]['secs'] < $start) $bi++;
        $parts = [];
        $j = $bi;
        while ($j < $bn && $baseline[$j]['secs'] < $end) {
            $t = trim((string)$baseline[$j]['text']);
            if ($t !== '') $parts[] = $t;
            $j++;
        }
        $out[$i] = trim(implode(' ', $parts));
    }
    return $out;
}

/** Lowercase word tokens, punctuation stripped — the unit for overlap math. */
function cfWords(string $s): array {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
    return preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** Jaccard similarity of the two strings' word sets (0..1). */
function cfWordJaccard(string $x, string $y): float {
    $a = array_unique(cfWords($x));
    $b = array_unique(cfWords($y));
    if (!$a || !$b) return 0.0;
    $inter = count(array_intersect($a, $b));
    $union = count(array_unique(array_merge($a, $b)));
    return $union > 0 ? $inter / $union : 0.0;
}

/**
 * Guard against timestamp drift. The live transcript's segment times are not
 * always tightly aligned to the audio (manually-timed lyrics, imported cues,
 * etc.), so a purely time-bucketed baseline can land a completely different
 * span onto a line. We only trust an alternate that shares enough words with
 * the current line to be plausibly the SAME span — which is exactly the case
 * consensus helps with (same line, a word or two differ). Gross mismatches
 * (near-zero word overlap) are dropped so the LLM never sees a wrong-span
 * "alternate" it might act on.
 */
function cfAltLooksAligned(string $current, string $alt): bool {
    return cfWordJaccard($current, $alt) >= 0.34;
}

/**
 * Estimate a single global time offset (seconds) that best aligns a baseline
 * to the live rows — correcting constant lead/lag between the live transcript's
 * segment clock and the audio-accurate baseline. Returns the shift to ADD to
 * each live window before bucketing. Falls back to 0.0 when no shift clearly
 * beats no-shift (already aligned, or timestamps too broken to help) so it
 * never degrades an already-good alignment or "invents" one from noise.
 */
function cfEstimateShift(array $live, array $baseline): float {
    $n = count($live); $bn = count($baseline);
    if ($n < 4 || $bn < 4) return 0.0;
    $btimes = array_column($baseline, 'secs');

    // Sample up to 40 content-bearing live lines (>=4 words) spread across the item.
    $samples = [];
    for ($i = 0; $i < $n; $i++) if (count(cfWords($live[$i]['text'])) >= 4) $samples[] = $i;
    if (!$samples) return 0.0;
    if (count($samples) > 40) {
        $step = intdiv(count($samples), 40); $thin = [];
        for ($k = 0; $k < count($samples); $k += $step) $thin[] = $samples[$k];
        $samples = $thin;
    }

    // First baseline index with secs >= $v (binary search over the sorted times).
    $lowerBound = function (float $v) use ($btimes, $bn): int {
        $lo = 0; $hi = $bn;
        while ($lo < $hi) { $mid = ($lo + $hi) >> 1; if ($btimes[$mid] < $v) $lo = $mid + 1; else $hi = $mid; }
        return $lo;
    };
    $winText = function (float $lo, float $hi) use ($lowerBound, $baseline, $btimes, $bn): string {
        $p = []; $k = $lowerBound($lo);
        while ($k < $bn && $btimes[$k] < $hi) { $t = trim((string)$baseline[$k]['text']); if ($t !== '') $p[] = $t; $k++; }
        return implode(' ', $p);
    };
    $scoreAt = function (float $sh) use ($samples, $live, $n, $winText): float {
        $s = 0.0;
        foreach ($samples as $i) {
            $lo = $live[$i]['secs'] + $sh;
            $hi = (($i + 1 < $n) ? $live[$i + 1]['secs'] : $live[$i]['secs'] + 4.0) + $sh;
            $s += cfWordJaccard($live[$i]['text'], $winText($lo, $hi));
        }
        return $s;
    };

    $score0 = $scoreAt(0.0);
    $best = 0.0; $bestScore = $score0;
    for ($sh = -8.0; $sh <= 8.0 + 1e-9; $sh += 0.5) {
        if (abs($sh) < 1e-9) continue;                 // already have no-shift baseline
        $sc = $scoreAt($sh);
        if ($sc > $bestScore) { $bestScore = $sc; $best = $sh; }
    }
    // Only adopt a nonzero shift if it clearly beats no-shift (margin guards
    // against chasing sampling noise on an already-aligned or broken item).
    return ($best != 0.0 && $bestScore >= $score0 + 0.5) ? $best : 0.0;
}

/** Capture the full live transcript before a destructive transform. */
function cfSnapshot(PDO $db, int $itemKey, ?int $userKey, string $reason): int {
    $rows = cfLoadLive($db, $itemKey);
    $payload = array_map(fn($r) => ['segment' => $r['segment'], 'text' => $r['text'],
                                    'sort' => $r['sort'], 'speaker' => $r['speaker']], $rows);
    $st = $db->prepare("
        INSERT INTO yy_transcript_snapshot
            (feed_item_key, snapshot_user_key, snapshot_reason, snapshot_rows, snapshot_json)
        VALUES (?, ?, ?, ?, ?::jsonb)
        RETURNING snapshot_key");
    $st->execute([$itemKey, $userKey, $reason, count($payload),
                  json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return (int)$st->fetchColumn();
}

/** Replace every live row for $itemKey with $cues ([['segment','text','speaker'?], ...]). */
function cfReplaceLive(PDO $db, int $itemKey, array $cues): int {
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ?")->execute([$itemKey]);
        $ins = $db->prepare("
            INSERT INTO yy_feed_item_transcript
                (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text,
                 feed_item_transcript_sort, feed_item_transcript_speaker)
            VALUES (?, ?::interval, ?, ?, ?)
            ON CONFLICT (feed_item_key, feed_item_transcript_segment, md5(feed_item_transcript_text)) DO NOTHING");
        $sort = 0;
        foreach ($cues as $c) {
            $seg = (string)$c['segment'];
            $txt = mb_substr((string)$c['text'], 0, 2000);
            if (trim($txt) === '') continue;
            $ins->execute([$itemKey, $seg, $txt, $sort++, $c['speaker'] ?? null]);
        }
        $db->commit();
        return $sort;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Build the in-context priming block from the admins' corrections + glossary.
 * Only 'human' corrections (deliberate dictionary / "apply to all" entries) are
 * surfaced as canonical: incidentally auto-learned ('learned') rules are NOT
 * authoritative and must not steer the model — the multi-baseline consensus is
 * the reliable signal for ordinary words.
 */
function cfCorrectionContext(PDO $db, int $maxCorr = 60, int $maxGloss = 60): array {
    $corr = [];
    try {
        $st = $db->query("SELECT correction_wrong, correction_right FROM yy_transcript_correction
                          WHERE correction_active_flag = TRUE AND correction_origin = 'human'
                          ORDER BY correction_count DESC, length(correction_wrong) DESC LIMIT $maxCorr");
        foreach ($st->fetchAll() as $r) $corr[] = $r['correction_wrong'] . ' => ' . $r['correction_right'];
    } catch (Throwable $e) { /* table optional */ }
    $gloss = [];
    try {
        $st = $db->query("SELECT glossary_term FROM yy_transcript_glossary
                          WHERE glossary_active_flag = TRUE
                          ORDER BY glossary_priority DESC, glossary_term LIMIT $maxGloss");
        foreach ($st->fetchAll() as $r) $gloss[] = $r['glossary_term'];
    } catch (Throwable $e) { /* table optional */ }
    return ['corrections' => $corr, 'glossary' => $gloss];
}

/**
 * Engines EXCLUDED from consensus voting + LLM reconcile — now a THIN VIEW over
 * the unified weighting system: an engine is "denied" iff its effective weight
 * (cfEngineWeights) is ≤ CF_ENGINE_DENY_EPS. There is no separate binary
 * denylist any more; a zero weight IS the denial.
 *
 * How an engine reaches weight 0: cfEngineWeights lets the *automated* edit-grade
 * only move an engine within [0.5,1.5] (a thin-data fluke can never nuke one), so
 * a true deny requires an explicit row in yy_transcript_engine_weight_override
 * (weight 0) — which is where the old hardcoded pair now lives, seeded from the
 * 2026-07-06 artifact analysis. ⚠ Why they still need an override rather than
 * falling out on grade: gpu-canary-1b-flash / gpu-qwen2-audio hallucinate
 * high-frequency common words (the→Yah, no→Not), but that damage is a small % of
 * tokens, so their whole-transcript edit-grade sits mid-pack (~0.85–0.90) and
 * even a per-confusion sweep over the current edited corpus can't isolate them.
 * When the grade can justify an engine on its own the override is simply removed
 * and the exclusion lifts automatically. The legacy yy_transcript_engine_deny
 * table is still honoured (treated as weight 0) for backward compatibility.
 */
if (!defined('CF_ENGINE_DENY_EPS')) define('CF_ENGINE_DENY_EPS', 0.05);
function cfDeniedEngines(PDO $db): array {
    $deny = [];
    foreach (cfEngineWeights($db) as $engine => $wt) {
        if ((float)$wt <= CF_ENGINE_DENY_EPS) $deny[(string)$engine] = true;
    }
    // Legacy runtime denylist table = weight-0 override (belt-and-suspenders).
    try {
        foreach ($db->query("SELECT engine FROM yy_transcript_engine_deny")->fetchAll(PDO::FETCH_COLUMN) as $e)
            $deny[(string)$e] = true;
    } catch (\Throwable $e) { /* table optional */ }
    return array_keys($deny);
}

function cfBuildMessages(array $ctx, array $lines): array {
    $sys = "You proofread automatic speech-to-text transcripts of Hebrew/English religious lectures.\n"
         . "Fix ONLY transcription errors: misheard words, wrong Hebrew transliterations, proper nouns, "
         . "obvious typos, and missing or wrong punctuation.\n"
         . "STRICT RULES:\n"
         . "- Do NOT paraphrase, translate, summarize, reorder, merge, or split lines.\n"
         . "- Return EXACTLY one entry per input line, with the SAME index, in the SAME order.\n"
         . "- If a line is already correct, return it unchanged.\n"
         . "- Keep the speaker's wording; only repair errors.\n";
    $hasAlts = false;
    foreach ($lines as $l) { if (!empty($l['alts'])) { $hasAlts = true; break; } }
    if ($hasAlts) {
        $sys .= "\nSome lines include \"alternates\": transcriptions of the SAME audio span produced by other "
              . "speech-to-text engines. They are your PRIMARY evidence for the wording:\n"
              . "- When two or more alternates agree on a word the current line gets wrong, use the agreed reading.\n"
              . "- Treat a word the alternates agree on as correct — do NOT change an ordinary word the baselines support.\n"
              . "- When they conflict, use the canonical spellings, glossary, and context to choose.\n"
              . "- The alternates are also imperfect ASR — never copy one blindly, and keep the current wording when nothing is clearly wrong.\n";
    }
    if ($ctx['corrections']) {
        $sys .= "\nCanonical spellings the editors enforce (wrong => right) — apply the right-hand form only when "
              . "you see that SAME word (names, Hebrew terms, known misspellings). For ordinary words the agreed "
              . "baseline readings above win; never rewrite a word to match this list if the baselines don't support it:\n"
              . implode("\n", array_slice($ctx['corrections'], 0, 60)) . "\n";
    }
    if ($ctx['glossary']) {
        $sys .= "\nCanonical spellings of names/terms used in this material:\n"
              . implode(', ', array_slice($ctx['glossary'], 0, 60)) . "\n";
    }
    $sys .= "\nReturn ONLY a JSON object of the form: {\"lines\":[{\"i\":<index>,\"text\":\"<corrected line>\"}, ...]}";

    $payloadLines = array_map(function ($l) {
        $e = ['i' => $l['i'], 'text' => $l['text']];
        if (!empty($l['alts'])) $e['alternates'] = $l['alts'];
        return $e;
    }, $lines);
    $user = "Correct these lines. Return the JSON object described.\n"
          . json_encode(['lines' => $payloadLines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $user],
    ];
}

/**
 * Merge-guard for the LLM reconcile pass. Returns true when the model's reply
 * for line i ($new) has swallowed the NEXT line's wording ($next) that the
 * original line i ($old) did not already contain — the tell-tale of a forbidden
 * merge that leaves i ballooned while i+1 stays put (duplicated text in the
 * editor). Word-level, punctuation-insensitive contiguous-run match; ignores
 * trivially short next lines to avoid rejecting legitimate small repairs.
 */
function cfMergeNorm(string $s): array {
    $s = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return $s === '' ? [] : explode(' ', $s);
}
function cfContiguousContains(array $hay, array $needle): bool {
    $n = count($needle); $h = count($hay);
    if ($n === 0 || $n > $h) return false;
    for ($i = 0; $i + $n <= $h; $i++) {
        $ok = true;
        for ($j = 0; $j < $n; $j++) { if ($hay[$i + $j] !== $needle[$j]) { $ok = false; break; } }
        if ($ok) return true;
    }
    return false;
}
function cfReplySwallowsNext(string $new, string $old, string $next): bool {
    $nextTok = cfMergeNorm($next);
    if (count($nextTok) < 3) return false;                 // too short to be sure
    $newTok = cfMergeNorm($new);
    $oldTok = cfMergeNorm($old);
    if (cfContiguousContains($oldTok, $nextTok)) return false; // i already had it
    return cfContiguousContains($newTok, $nextTok);            // i now swallows i+1
}

// ── Auto multi-baseline LLM reconciliation (Layer 3) ─────────────────────────
//   Runs the SAME baseline-aware consensus-decode that Smart Captions' ai_chunk
//   does, but server-side over a freshly-built transcript so the *starting*
//   editable transcript is already LLM-reconciled. For each chunk of live lines
//   it attaches the other engines' time-aligned readings as "alternates" and
//   asks the on-box LLM to pick/repair, primed with the admins' learned
//   corrections + glossary (which Layer 4 now feeds from every segment edit).
//
//   FAIL-OPEN by contract: the transcript already exists and is valid, so any
//   LLM/GPU problem logs and stops — it never throws and never blanks a line.
//   A snapshot is taken first (yy_transcript_snapshot) so a bad pass is one
//   click to undo. Returns ['ok','changed','chunks','error'].
function llmReconcileTranscript(PDO $db, int $itemKey, array $baselineCodes,
                                string $model = 'qwen2.5:72b', ?callable $notify = null,
                                array $opts = []): array {
    require_once __DIR__ . '/gpu-client.php';
    $emit  = function (string $s) use ($notify) { if ($notify) $notify($s); };
    $limit = max(1, min(60, (int)($opts['chunk'] ?? 40)));

    $live  = cfLoadLive($db, $itemKey);
    $total = count($live);
    if ($total === 0) return ['ok' => true, 'changed' => 0, 'chunks' => 0];

    // Only baselines that actually have rows for this item.
    $avail = array_column(cfAutoModels($db, $itemKey), 'code');
    $baselineCodes = array_values(array_filter(
        array_map('strval', $baselineCodes), fn($c) => in_array($c, $avail, true)));
    // Drop low-reliability engines so their hallucinations aren't offered to the
    // LLM as "primary evidence" (see cfDeniedEngines). Keep them only if removing
    // them would leave no alternates at all (degraded-but-something).
    $deny = cfDeniedEngines($db);
    $kept = array_values(array_filter($baselineCodes, fn($c) => !in_array($c, $deny, true)));
    if ($kept) $baselineCodes = $kept;

    // Pre-align every baseline to the full live row set once (per-item global
    // time-shift corrected), then read the slice's indices per chunk.
    $alignByModel = [];
    foreach ($baselineCodes as $code) {
        $brows = cfLoadAuto($db, $itemKey, $code);
        $alignByModel[$code] = cfAlignBaselineToLive($live, $brows, cfEstimateShift($live, $brows));
    }

    $ctx    = cfCorrectionContext($db);
    $numCtx = $baselineCodes ? 16384 : 8192;
    try { cfSnapshot($db, $itemKey, null, 'pre auto LLM reconcile (init)'); } catch (\Throwable $e) {}

    $upd = $db->prepare("UPDATE yy_feed_item_transcript
                            SET feed_item_transcript_text = ?,
                                feed_item_transcript_revision_dtime = NOW()
                          WHERE feed_item_transcript_key = ? AND feed_item_key = ?");

    $changed = 0; $chunks = 0;
    for ($offset = 0; $offset < $total; $offset += $limit) {
        $slice = array_slice($live, $offset, $limit);
        $lines = [];
        foreach ($slice as $idx => $r) {
            $g = $offset + $idx;
            $alts = [];
            foreach ($baselineCodes as $code) {
                $t = $alignByModel[$code][$g] ?? '';
                if ($t !== '' && $t !== $r['text'] && cfAltLooksAligned($r['text'], $t)) {
                    $alts[] = ['engine' => $code, 'text' => mb_substr($t, 0, 400)];
                }
            }
            $lines[] = ['i' => $idx, 'text' => $r['text'], 'key' => $r['key'], 'old' => $r['text'], 'alts' => $alts];
        }
        $resp = gpuLlmChat($model, cfBuildMessages($ctx, $lines),
            ['json' => true, 'temperature' => 0.1, 'timeout' => 280, 'keep_alive' => '20m', 'num_ctx' => $numCtx]);
        if (empty($resp['ok'])) {
            error_log("llmReconcileTranscript: LLM error at offset $offset item=$itemKey: " . ($resp['error'] ?? 'unknown'));
            return ['ok' => false, 'changed' => $changed, 'chunks' => $chunks, 'error' => ($resp['error'] ?? 'LLM error')];
        }
        $parsed = json_decode((string)$resp['content'], true);
        $byIdx  = [];
        if (is_array($parsed) && isset($parsed['lines']) && is_array($parsed['lines'])) {
            foreach ($parsed['lines'] as $l) {
                if (isset($l['i']) && array_key_exists('text', $l)) $byIdx[(int)$l['i']] = (string)$l['text'];
            }
        }
        $nLines = count($lines);
        foreach ($lines as $pos => $l) {
            $new = array_key_exists($l['i'], $byIdx) ? trim($byIdx[$l['i']]) : $l['old'];
            if ($new === '') $new = $l['old'];               // never blank a line
            // Merge-guard: the prompt forbids merging lines, but the model will
            // occasionally return line i as the CONCATENATION of i (+i+1[+i+2]…)
            // while i+1/i+2 stay put — line i then balloons and its text repeats
            // in the following rows (visible duplication). Reject any reply that
            // swallows the next input line's wording and keep the original.
            if ($new !== $l['old'] && $pos + 1 < $nLines
                && cfReplySwallowsNext($new, $l['old'], (string)$lines[$pos + 1]['old'])) {
                $new = $l['old'];
            }
            if ($new !== $l['old']) { $upd->execute([$new, $l['key'], $itemKey]); $changed++; }
        }
        $chunks++;
        $emit('llm-reconcile:' . min($offset + $limit, $total) . '/' . $total . ':' . $changed);
    }
    return ['ok' => true, 'changed' => $changed, 'chunks' => $chunks];
}
/* ===================================================================== *
 *  Music / STT repetition-LOOP-block removal.
 *
 *  At the onset of a sung passage (and, on badly-decoded audio, ordinary
 *  speech) faster-whisper loops: it emits a run of consecutive segments ALL
 *  stamped at the loop's start time (no per-line timing), dumping the whole
 *  upcoming passage — then recovers and re-transcribes the SAME text with real
 *  word-level timestamps immediately after. The editor shows the loop block as
 *  a jumbled stack of identical-timestamp rows followed by the timed copy.
 *
 *  transcriptCollapseLoopBlocks() detects each block (>=2 consecutive segments
 *  sharing one exact timestamp whose text is re-covered by the timed segments
 *  that follow within GAP_MAX seconds, OR is internally self-repeating and still
 *  partly overlaps them) and DELETES it, re-attaching any lead words the timed
 *  pass dropped so no content is lost. Whole-song items stamped 00:00:00 (no
 *  distinct later timed copy) are left untouched — they need re-transcription.
 *
 *  Used as an ingest guard (admin-transcript-init-worker.php, post-commit) and
 *  by the standalone sweep api/transcript-music-loopblock-fix.php.
 * ===================================================================== */

function tclbNormTokens(string $s): array {
    $s = str_replace(["\r", "\n"], ' ', $s);
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $s = trim($s);
    return $s === '' ? [] : preg_split('/\s+/', $s);
}
function tclbAlignedTokens(string $s): array {
    $s = str_replace(["\r", "\n"], ' ', $s);
    $out = [];
    foreach (preg_split('/\s+/', trim($s)) as $tok) {
        if ($tok === '') continue;
        $nn = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $tok));
        if ($nn !== '') $out[] = [$tok, $nn];
    }
    return $out;
}
function tclbHasSelfRepeat(array $tok): bool {
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
function tclbNgramOverlap(array $aTok, array $bTok): float {
    $N = 4;
    if (count($aTok) < $N) $N = max(1, count($aTok));
    if (count($aTok) < $N || count($bTok) < 1) return 0.0;
    $bSet = [];
    for ($i = 0; $i + $N <= count($bTok); $i++) $bSet[implode(' ', array_slice($bTok, $i, $N))] = true;
    if (!$bSet) return 0.0;
    $hit = 0; $tot = 0;
    for ($i = 0; $i + $N <= count($aTok); $i++) {
        $tot++;
        if (isset($bSet[implode(' ', array_slice($aTok, $i, $N))])) $hit++;
    }
    return $tot ? $hit / $tot : 0.0;
}

/**
 * Remove repetition-loop blocks from ONE item's live yy_feed_item_transcript.
 * $opts: apply(bool=false), reattach(bool=true), backup(bool=apply).
 * Returns ['blocks'=>int,'removed'=>int,'reattached'=>int,'plan'=>[...]].
 * Transaction-safe: joins the caller's open transaction, else runs its own.
 */
function transcriptCollapseLoopBlocks(PDO $db, int $fik, array $opts = []): array {
    $apply  = !empty($opts['apply']);
    $reatt  = !array_key_exists('reattach', $opts) || $opts['reattach'];
    $backup = array_key_exists('backup', $opts) ? (bool)$opts['backup'] : $apply;
    $OVERLAP_MIN = 0.60; $GAP_MAX = 30.0; $LEAD_MAX = 14; $RUN_MIN = 2;

    $sel = $db->prepare(
        "SELECT feed_item_transcript_key k, feed_item_transcript_segment::text seg_txt,
                EXTRACT(EPOCH FROM feed_item_transcript_segment)::float8 secs,
                feed_item_transcript_text txt
         FROM yy_feed_item_transcript WHERE feed_item_key = ?
         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
    $sel->execute([$fik]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    $n = count($rows);

    $plan = [];
    $i = 0;
    while ($i < $n) {
        $j = $i;
        while ($j + 1 < $n && abs($rows[$j+1]['secs'] - $rows[$i]['secs']) < 0.005) $j++;
        if ($j - $i + 1 < $RUN_MIN) { $i = $j + 1; continue; }

        $T = $rows[$i]['secs'];
        $f = $j + 1;
        while ($f < $n && $rows[$f]['secs'] <= $T + 0.005) $f++;
        if ($f >= $n || $rows[$f]['secs'] - $T > $GAP_MAX) { $i = $j + 1; continue; }

        $runTxt = '';
        for ($r = $i; $r <= $j; $r++) $runTxt .= ' ' . $rows[$r]['txt'];
        $runTok = tclbNormTokens($runTxt);
        $runWords = count($runTok);

        $winTxt = ''; $w = $f; $winWords = 0;
        while ($w < $n && $winWords < $runWords * 1.8 && ($rows[$w]['secs'] - $T) <= $GAP_MAX + 90) {
            $winTxt .= ' ' . $rows[$w]['txt'];
            $winWords = count(tclbNormTokens($winTxt));
            $w++;
        }
        $winTok = tclbNormTokens($winTxt);
        $ov = tclbNgramOverlap($runTok, $winTok);
        if (!(($ov >= $OVERLAP_MIN) || ($ov > 0.0 && tclbHasSelfRepeat($runTok)))) { $i = $j + 1; continue; }

        $entry = ['run' => range($i, $j), 'seg' => $rows[$i]['seg_txt'], 'ov' => $ov, 'reattach' => null];
        if ($reatt) {
            $runAl = tclbAlignedTokens($runTxt);
            $follNorm = tclbNormTokens($rows[$f]['txt']);
            $m = min(6, count($follNorm));
            if ($m >= 3) {
                $runNorm = array_map(fn($p) => $p[1], $runAl);
                $foundP = -1;
                for ($p = 1; $p + $m <= count($runNorm) && $p <= $LEAD_MAX + 2; $p++) {
                    $eq = true;
                    for ($q = 0; $q < $m; $q++) { if ($runNorm[$p+$q] !== $follNorm[$q]) { $eq = false; break; } }
                    if ($eq) { $foundP = $p; break; }
                }
                if ($foundP >= 1 && $foundP <= $LEAD_MAX) {
                    $lead = [];
                    for ($p = 0; $p < $foundP; $p++) $lead[] = $runAl[$p][0];
                    $lead = trim(implode(' ', $lead));
                    if ($lead !== '') $entry['reattach'] = ['k' => $rows[$f]['k'], 'old' => $rows[$f]['txt'], 'new' => $lead . ' ' . $rows[$f]['txt']];
                }
            }
        }
        $plan[] = $entry;
        $i = $j + 1;
    }

    $removed = 0; $reattached = 0;
    foreach ($plan as $e) { $removed += count($e['run']); if ($e['reattach']) $reattached++; }

    if ($apply && $plan) {
        $ownTx = !$db->inTransaction();
        if ($backup) {
            $db->exec("CREATE TABLE IF NOT EXISTS yy_feed_item_transcript_loopblk_bak (
                bak_key bigserial PRIMARY KEY, action text NOT NULL, feed_item_key integer NOT NULL,
                row_key bigint NOT NULL, old_segment interval, old_text text, new_text text,
                run_id text, fix_dtime timestamptz NOT NULL DEFAULT now())");
        }
        if ($ownTx) $db->beginTransaction();
        try {
            $bak = $backup ? $db->prepare("INSERT INTO yy_feed_item_transcript_loopblk_bak
                (action, feed_item_key, row_key, old_segment, old_text, new_text, run_id)
                VALUES (?,?,?,?::interval,?,?,?)") : null;
            $del = $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_transcript_key = ?");
            $upd = $db->prepare("UPDATE yy_feed_item_transcript SET feed_item_transcript_text = ? WHERE feed_item_transcript_key = ?");
            $runId = $fik . '-guard-' . substr(md5($fik . '|' . $plan[0]['seg']), 0, 8);
            foreach ($plan as $e) {
                if ($e['reattach']) {
                    $ra = $e['reattach'];
                    if ($bak) $bak->execute(['reattach', $fik, $ra['k'], null, $ra['old'], $ra['new'], $runId]);
                    $upd->execute([$ra['new'], $ra['k']]);
                }
                foreach ($e['run'] as $ri) {
                    $row = $rows[$ri];
                    if ($bak) $bak->execute(['delete', $fik, $row['k'], $row['seg_txt'], $row['txt'], null, $runId]);
                    $del->execute([$row['k']]);
                }
            }
            if ($ownTx) $db->commit();
        } catch (\Throwable $ex) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $ex;
        }
    }

    return ['blocks' => count($plan), 'removed' => $removed, 'reattached' => $reattached, 'plan' => $plan];
}

// ── Edit-weighted engine grading (learn-from-edits reliability) ──────────────
//   The system already knew which engines to DISTRUST via a hardcoded denylist
//   and a self-supervised cross-engine agreement grade. This adds the missing
//   signal the operator asked for: grade every baseline by how closely it
//   matches the HUMAN-EDITED final transcript (the ideal), accumulate that over
//   every edited item, and expose it as a per-engine weight the consensus build
//   uses to trust the closest engines more. Two tables:
//     yy_transcript_engine_edit_grade_item  — idempotent per-item ledger
//                                              (samples/agree per engine×category)
//     yy_transcript_engine_edit_grade        — rolled-up cache read at build time
//   Fail-open + backward-compatible: when the cache is empty cfEngineWeights()
//   returns [] and every downstream weight defaults to 1.0 → byte-identical to
//   the old unweighted vote. See transcript-engine-grade.php (the sweep CLI).

/** Bucket a raw display token for per-category grading. 'all' is tracked too. */
function cfCategorizeToken(string $rawTok): string {
    $n = mb_strtolower(trim($rawTok));
    $n = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $n) ?? '';
    if ($n === '') return 'punct';
    if (preg_match('/\d/u', $n)) return 'number';
    static $stop = [
        'a'=>1,'an'=>1,'and'=>1,'are'=>1,'as'=>1,'at'=>1,'be'=>1,'been'=>1,'but'=>1,
        'by'=>1,'can'=>1,'could'=>1,'did'=>1,'do'=>1,'does'=>1,'for'=>1,'from'=>1,
        'had'=>1,'has'=>1,'have'=>1,'he'=>1,'her'=>1,'him'=>1,'his'=>1,'i'=>1,'in'=>1,
        'is'=>1,'it'=>1,'its'=>1,'me'=>1,'my'=>1,'no'=>1,'nor'=>1,'not'=>1,'of'=>1,
        'on'=>1,'or'=>1,'our'=>1,'she'=>1,'so'=>1,'than'=>1,'that'=>1,'the'=>1,
        'their'=>1,'them'=>1,'then'=>1,'there'=>1,'they'=>1,'this'=>1,'to'=>1,
        'was'=>1,'we'=>1,'were'=>1,'will'=>1,'with'=>1,'would'=>1,'you'=>1,'your'=>1,
    ];
    if (isset($stop[$n])) return 'function';
    // Proper-noun proxy (no sentence context here): internal caps (YHWH, McX) or
    // a leading capital. Over-counts sentence-initial words as names, but that
    // only affects the per-category split — the 'all' bucket used for weighting
    // is unaffected.
    if (preg_match('/^\p{Lu}/u', $rawTok) || preg_match('/\p{Lu}/u', mb_substr($rawTok, 1))) return 'name';
    return 'content';
}

/**
 * Grade every baseline in _auto for $itemKey against that item's human-edited
 * FINAL live transcript, writing the per-item ledger (replace-in-place so a
 * re-grade after further edits is idempotent). Each engine's token stream is
 * content-aligned to the final (alignSequence — timing-independent, the same
 * aligner the consensus vote uses), then agreement is counted per category.
 * Returns [engine => [category => ['s'=>samples,'a'=>agree]]].
 */
function cfGradeItemAgainstEdits(PDO $db, int $itemKey): array {
    require_once __DIR__ . '/transcript-compare-lib.php';
    $rs = $db->prepare("SELECT feed_item_transcript_text txt
                          FROM yy_feed_item_transcript
                         WHERE feed_item_key = ?
                      ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
    $rs->execute([$itemKey]);
    $refRaw = [];
    foreach ($rs->fetchAll(PDO::FETCH_COLUMN) as $txt) {
        foreach (tokenize((string)$txt) as $t) $refRaw[] = $t;
    }
    $nRef = count($refRaw);
    if ($nRef < 50) return ['skipped' => 'reference transcript too short (' . $nRef . ' tokens)'];
    $refNorm = array_map('normTok', $refRaw);
    $refCat  = array_map('cfCategorizeToken', $refRaw);

    $eq = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model m
                          FROM yy_feed_item_transcript_auto WHERE feed_item_key = ?");
    $eq->execute([$itemKey]);
    $out = [];
    foreach ($eq->fetchAll(PDO::FETCH_COLUMN) as $code) {
        $eRaw = [];
        foreach (loadCompareRows($db, $itemKey, (string)$code) as $r) {
            foreach (tokenize((string)$r['txt']) as $t) $eRaw[] = $t;
        }
        if (!$eRaw) continue;
        $eNorm = array_map('normTok', $eRaw);
        // For each FINAL token, what did this engine say there ('' = engine gap).
        $aligned = alignSequence($refNorm, $eNorm, $eRaw);
        $stats = [];
        for ($i = 0; $i < $nRef; $i++) {
            if ($refNorm[$i] === '') continue;             // punctuation-only ref token
            $cat = $refCat[$i];
            $hit = (normTok($aligned[$i] ?? '') === $refNorm[$i]) ? 1 : 0;
            foreach ([$cat, 'all'] as $c) {
                if (!isset($stats[$c])) $stats[$c] = ['s' => 0, 'a' => 0];
                $stats[$c]['s']++; $stats[$c]['a'] += $hit;
            }
        }
        if ($stats) $out[(string)$code] = $stats;
    }

    $ownTx = !$db->inTransaction();
    if ($ownTx) $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM yy_transcript_engine_edit_grade_item WHERE feed_item_key = ?")->execute([$itemKey]);
        $ins = $db->prepare("INSERT INTO yy_transcript_engine_edit_grade_item
                                 (feed_item_key, engine, category, samples, agree, graded)
                             VALUES (?, ?, ?, ?, ?, now())");
        foreach ($out as $code => $stats) {
            foreach ($stats as $cat => $sa) $ins->execute([$itemKey, $code, $cat, $sa['s'], $sa['a']]);
        }
        if ($ownTx) $db->commit();
    } catch (\Throwable $e) {
        if ($ownTx && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $out;
}

/** Roll the per-item ledger up into the read-at-build-time cache table. */
function cfRecomputeEngineEditGradeCache(PDO $db): void {
    $db->exec("DELETE FROM yy_transcript_engine_edit_grade");
    $db->exec("INSERT INTO yy_transcript_engine_edit_grade (engine, category, samples, agree, grade, items, updated)
               SELECT engine, category, SUM(samples), SUM(agree),
                      CASE WHEN SUM(samples) > 0 THEN SUM(agree)::float / SUM(samples) ELSE NULL END,
                      COUNT(DISTINCT feed_item_key), now()
                 FROM yy_transcript_engine_edit_grade_item
             GROUP BY engine, category");
}

/**
 * Per-engine consensus weight from the learned grades (category 'all').
 * weight = grade / corpus-mean-grade, clamped to [$floor,$ceil]; engines below
 * $minSamples (thin evidence) stay neutral at 1.0. Returns engine=>weight, or []
 * when there isn't enough graded data yet (→ callers fall back to unweighted).
 */
function cfEngineWeights(PDO $db, float $floor = 0.5, float $ceil = 1.5, int $minSamples = 3000): array {
    $w = [];
    // 1) Automated edit-grade weights (only when there's enough graded spread to
    //    weight against). These are CLAMPED to [$floor,$ceil] — the automated
    //    signal can nudge trust but can NEVER deny an engine (drive it to 0); a
    //    thin-data fluke must not silently remove a whole engine from the vote.
    try {
        $rows = $db->query("SELECT engine, samples, grade FROM yy_transcript_engine_edit_grade WHERE category = 'all'")
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $rows = []; }
    if ($rows) {
        $trusted = [];
        foreach ($rows as $r) {
            if ((int)$r['samples'] >= $minSamples && $r['grade'] !== null) $trusted[] = (float)$r['grade'];
        }
        if (count($trusted) >= 2) {
            $mean = array_sum($trusted) / count($trusted);
            if ($mean > 0) {
                foreach ($rows as $r) {
                    if ((int)$r['samples'] < $minSamples || $r['grade'] === null) { $w[(string)$r['engine']] = 1.0; continue; }
                    $w[(string)$r['engine']] = max($floor, min($ceil, (float)$r['grade'] / $mean));
                }
            }
        }
    }
    // 2) Explicit effective-weight overrides (analysis/operator-set) applied LAST
    //    and NOT floored: this is the ONLY path to a 0 weight = a full deny within
    //    the unified system. Seeded with the former hardcoded denylist
    //    (canary/qwen2 = 0). See cfDeniedEngines() + yy_transcript_engine_weight_override.
    try {
        foreach ($db->query("SELECT engine, weight FROM yy_transcript_engine_weight_override")->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $w[(string)$o['engine']] = max(0.0, (float)$o['weight']);
        }
    } catch (\Throwable $e) { /* table optional */ }
    return $w;
}
