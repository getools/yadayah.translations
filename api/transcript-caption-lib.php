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
        'max_lines'   => 2,
        'max_secs'    => 7.0,
        'min_secs'    => 1.2,
        'cps'         => 17.0,
        'break_punct' => true,
        'break_gap'   => 0.0,   // 0 = off; else flush when the pause before a word >= this many secs
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
 *  Options: max_chars,max_lines,max_secs,min_secs,break_punct,dedup, plus
 *    break_gap   (float secs; >0 flushes the cue before a word whose pause from
 *                 the previous word is >= break_gap), and
 *    boundary_set(sorted float[] of segment-start secs; a boundary falling
 *                 between two words flushes the cue before the later word).
 *  Returns [['start'=>float, 'text'=>"line1\nline2", 'chars'=>int, 'lines'=>int], ...]. */
function cfReflow(array $words, array $o): array {
    $maxChars = max(10, (int)$o['max_chars']);
    $maxLines = max(1, (int)$o['max_lines']);
    $maxSecs  = (float)$o['max_secs'];
    $minSecs  = (float)$o['min_secs'];
    $breakP   = !empty($o['break_punct']);
    $breakGap = (float)($o['break_gap'] ?? 0);
    $bounds   = (isset($o['boundary_set']) && is_array($o['boundary_set'])) ? $o['boundary_set'] : null;
    $nb = $bounds ? count($bounds) : 0;
    $bi = 0;
    if (!empty($o['dedup'])) $words = cfDedup($words);

    $cap = $maxChars * $maxLines;
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
        $wrapped = cfWrap(array_map(fn($x) => $x['w'], $trial), $maxChars);
        $fits = count($wrapped) <= $maxLines;
        $overTime = ($wi['t'] - $curStart) > $maxSecs;
        if ($cur && (!$fits || $overTime)) {
            $flush();
            $curStart = $wi['t'];
            $cur = [$wi];
        } else {
            $cur = $trial;
        }
        // Prefer to break after sentence/clause punctuation once the cue is
        // reasonably full or has shown for the minimum dwell time.
        if ($breakP && preg_match('/[.?!…।,;:]["\')\]\x{201D}\x{2019}]?$/u', $wi['w'])) {
            $curText = implode(' ', array_map(fn($x) => $x['w'], $cur));
            $sentenceEnd = (bool)preg_match('/[.?!…।]["\')\]\x{201D}\x{2019}]?$/u', $wi['w']);
            if (($sentenceEnd && (mb_strlen($curText) >= $cap * 0.4 || ($wi['t'] - $curStart) >= $minSecs))
                || mb_strlen($curText) >= $cap * 0.85) {
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
 *  their words spread linearly across the gap to the next row. */
function cfRowsToWords(array $rows): array {
    $words = [];
    $n = count($rows);
    for ($r = 0; $r < $n; $r++) {
        $txt = trim((string)$rows[$r]['text']);
        if ($txt === '') continue;
        $parts = preg_split('/\s+/u', $txt, -1, PREG_SPLIT_NO_EMPTY);
        $cnt = count($parts);
        if ($cnt === 0) continue;
        $start = (float)$rows[$r]['secs'];
        $end = ($r + 1 < $n) ? (float)$rows[$r + 1]['secs'] : $start + max(1.0, $cnt / 3.0);
        if ($end <= $start) $end = $start + max(1.0, $cnt / 3.0);
        for ($w = 0; $w < $cnt; $w++) {
            $words[] = ['t' => $start + ($end - $start) * ($w / $cnt), 'w' => $parts[$w]];
        }
    }
    return $words;
}
