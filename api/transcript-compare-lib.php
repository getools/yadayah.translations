<?php
/**
 * Pure helpers for transcript comparison + consensus correction.
 *
 * No side effects on include (no auth, no dispatch) so it can be unit-tested
 * from the CLI and reused. The HTTP endpoint admin-transcript-compare.php
 * require_once's this and does auth + request dispatch.
 */

function compareLabels(): array {
    return [
        'gpu-whisper-large-v3'            => 'faster-whisper large-v3 (seg)',
        'gpu-whisper-large-v3-word'       => 'faster-whisper large-v3 (word)',
        'gpu-whisper-large-v3-turbo'      => 'faster-whisper large-v3-turbo (seg)',
        'gpu-whisper-large-v3-turbo-word' => 'faster-whisper large-v3-turbo (word)',
        'gpu-parakeet-tdt-0.6b-v2'        => 'Parakeet TDT 0.6B v2 (seg)',
        'gpu-parakeet-tdt-0.6b-v2-word'   => 'Parakeet TDT 0.6B v2 (word)',
        'gpu-whisperx'                    => 'WhisperX (seg)',
        'gpu-whisperx-word'               => 'WhisperX (word)',
        'gpu-whisperx-diarize'            => 'WhisperX + diarization',
        'gpu-canary-1b-flash'             => 'Canary 1B Flash (text)',
        'gpu-qwen2-audio'                 => 'Qwen2-Audio 7B (text)',
        'whisper-1-segment'               => 'OpenAI whisper-1 (seg)',
        'whisper-1-word'                  => 'OpenAI whisper-1 (word)',
        'groq-whisper-large-v3-turbo'     => 'Groq large-v3-turbo',
        'groq-whisper-large-v3'           => 'Groq large-v3',
        'deepgram-nova-3'                 => 'Deepgram Nova-3',
        'assemblyai-universal-2'          => 'AssemblyAI Universal-2',
        'elevenlabs-scribe'               => 'ElevenLabs Scribe',
        'azure-speech-stt'                => 'Azure Fast Transcription',
        'youtube'                         => 'YouTube captions',
        'consensus-corrected'             => 'Consensus-corrected',
    ];
}

function compareLabelFor(string $code): string {
    $m = compareLabels();
    return $m[$code] ?? $code;
}

/** interval string 'HH:MM:SS[.ffff]' → seconds (float). */
function intervalToSecs(string $iv): float {
    $iv = trim($iv);
    if ($iv === '') return 0.0;
    $parts = explode(':', $iv);
    if (count($parts) === 3) return (float)$parts[0] * 3600 + (float)$parts[1] * 60 + (float)$parts[2];
    if (count($parts) === 2) return (float)$parts[0] * 60 + (float)$parts[1];
    return (float)$iv;
}

/** Normalize a token for comparison: lowercase, strip surrounding punctuation. */
function normTok(string $w): string {
    $w = mb_strtolower(trim($w));
    $w = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $w);
    return $w ?? '';
}

/** Split text into display tokens (whitespace). */
function tokenize(string $text): array {
    $text = trim($text);
    if ($text === '') return [];
    return preg_split('/\s+/u', $text);
}

/**
 * LCS opcodes between two normalized-token arrays. Returns list of
 * [tag, a1, a2, b1, b2], tag in equal|replace|delete|insert. O(n*m) DP —
 * windowing upstream keeps n,m small.
 */
function lcsOpcodes(array $a, array $b): array {
    $n = count($a); $m = count($b);
    if ($n === 0 && $m === 0) return [];
    if ($n === 0) return [['insert', 0, 0, 0, $m]];
    if ($m === 0) return [['delete', 0, $n, 0, 0]];
    $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $dp[$i][$j] = ($a[$i] === $b[$j])
                ? $dp[$i + 1][$j + 1] + 1
                : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
        }
    }
    $ops = [];
    $i = 0; $j = 0; $pendA1 = 0; $pendB1 = 0;
    $flush = function ($a2, $b2) use (&$ops, &$pendA1, &$pendB1) {
        if ($a2 > $pendA1 && $b2 > $pendB1)      $ops[] = ['replace', $pendA1, $a2, $pendB1, $b2];
        elseif ($a2 > $pendA1)                   $ops[] = ['delete',  $pendA1, $a2, $pendB1, $b2];
        elseif ($b2 > $pendB1)                   $ops[] = ['insert',  $pendA1, $a2, $pendB1, $b2];
    };
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) {
            $flush($i, $j);
            $eqA = $i; $eqB = $j;
            while ($i < $n && $j < $m && $a[$i] === $b[$j]) { $i++; $j++; }
            $ops[] = ['equal', $eqA, $i, $eqB, $j];
            $pendA1 = $i; $pendB1 = $j;
        } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
            $i++;
        } else {
            $j++;
        }
    }
    $flush($n, $m);
    return $ops;
}

/**
 * Align reference tokens to primary tokens within one window. Returns an array
 * indexed like $primNorm; each entry is the reference's display token for that
 * primary position ('' = gap). Extra reference tokens are appended to the
 * nearest preceding primary slot.
 */
function alignWindow(array $primNorm, array $refNorm, array $refRaw): array {
    $out = array_fill(0, count($primNorm), '');
    if (!count($primNorm)) return $out;
    foreach (lcsOpcodes($primNorm, $refNorm) as $op) {
        [$tag, $a1, $a2, $b1, $b2] = $op;
        if ($tag === 'equal') {
            for ($k = 0; $k < ($a2 - $a1); $k++) $out[$a1 + $k] = $refRaw[$b1 + $k];
        } elseif ($tag === 'replace') {
            // Map ref tokens 1:1 onto the primary span; pad short, DROP extra.
            // Appending unanchored extras onto a neighbour slot produced ugly
            // collapsed cells ("attention while we listen...") at window edges,
            // so a clean per-word grid keeps at most one ref token per slot.
            $pa = $a2 - $a1; $pb = $b2 - $b1;
            for ($k = 0; $k < $pa; $k++) $out[$a1 + $k] = ($k < $pb) ? $refRaw[$b1 + $k] : '';
        }
        // insert: ref tokens with no primary anchor (usually window-edge
        //         spillover) → drop. delete: ref missed these → leave ''.
    }
    return $out;
}

/** Load ordered rows for one (item, model). */
function loadCompareRows(PDO $db, int $itemKey, string $model): array {
    $st = $db->prepare("
        SELECT feed_item_transcript_segment::text AS seg,
               feed_item_transcript_text AS txt
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ?
         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
    $st->execute([$itemKey, $model]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Primary word list: [{i, t, word}]. Word-level → 1 row/word; else tokenized. */
function primaryWords(array $rows): array {
    $words = [];
    foreach ($rows as $r) {
        $t = intervalToSecs($r['seg']);
        $toks = tokenize($r['txt']);
        if (count($toks) <= 1) {
            $words[] = ['t' => $t, 'word' => trim($r['txt'])];
        } else {
            foreach ($toks as $tok) $words[] = ['t' => $t, 'word' => $tok];
        }
    }
    foreach ($words as $i => &$w) $w['i'] = $i;
    return $words;
}

/**
 * Content-based alignment of a full reference token stream onto the full
 * primary token stream, INDEPENDENT of reference timestamps. Anchored
 * divide-and-conquer (patience-diff style): recursively split on a word that is
 * unique in BOTH sides, then LCS the small spans between anchors. Robust to
 * timing drift and rolling-caption duplication - extra ref tokens with no
 * primary anchor are dropped. Returns an array indexed like $primNorm; each
 * entry is the ref display token aligned to that primary word ('' = gap).
 */
function alignSequence(array $primNorm, array $refNorm, array $refRaw): array {
    $out = array_fill(0, count($primNorm), '');
    if (!count($primNorm) || !count($refNorm)) return $out;
    $stack = [[0, count($primNorm), 0, count($refNorm)]];
    while ($stack) {
        [$pLo, $pHi, $rLo, $rHi] = array_pop($stack);
        if ($pLo >= $pHi || $rLo >= $rHi) continue;
        $pLen = $pHi - $pLo; $rLen = $rHi - $rLo;
        if ($pLen * $rLen <= 50000) {                 // small enough for direct LCS
            $pn = array_slice($primNorm, $pLo, $pLen);
            $rn = array_slice($refNorm,  $rLo, $rLen);
            $rr = array_slice($refRaw,   $rLo, $rLen);
            $win = alignWindow($pn, $rn, $rr);
            for ($k = 0; $k < $pLen; $k++) if (($win[$k] ?? '') !== '') $out[$pLo + $k] = $win[$k];
            continue;
        }
        $anchor = findAnchor($primNorm, $refNorm, $pLo, $pHi, $rLo, $rHi);
        if ($anchor === null) {                       // no unique anchor: split prim at
            $pMid = intdiv($pLo + $pHi, 2);            // mid, ref proportionally
            $rMid = $rLo + (int) round(($pMid - $pLo) / $pLen * $rLen);
            $stack[] = [$pLo, $pMid, $rLo, $rMid];
            $stack[] = [$pMid, $pHi, $rMid, $rHi];
            continue;
        }
        [$pa, $ra] = $anchor;
        $out[$pa] = $refRaw[$ra];
        $stack[] = [$pLo, $pa, $rLo, $ra];
        $stack[] = [$pa + 1, $pHi, $ra + 1, $rHi];
    }
    return $out;
}

/**
 * Find a token unique in BOTH primary[pLo,pHi) and ref[rLo,rHi), searched from
 * the middle of the primary range outward. Such a word is an unambiguous,
 * order-preserving alignment anchor. Returns [pIdx, rIdx] or null.
 */
function findAnchor(array $primNorm, array $refNorm, int $pLo, int $pHi, int $rLo, int $rHi) {
    $pf = [];
    for ($i = $pLo; $i < $pHi; $i++) { $w = $primNorm[$i]; if ($w === '') continue; $pf[$w] = ($pf[$w] ?? 0) + 1; }
    $rIdx = [];
    for ($j = $rLo; $j < $rHi; $j++) { $w = $refNorm[$j]; if ($w === '') continue; $rIdx[$w][] = $j; }
    $mid = intdiv($pLo + $pHi, 2);
    $span = $pHi - $pLo;
    for ($d = 0; $d <= $span; $d++) {
        foreach ([$mid + $d, $mid - $d] as $i) {
            if ($i < $pLo || $i >= $pHi) continue;
            $w = $primNorm[$i];
            if ($w === '' || ($pf[$w] ?? 0) !== 1) continue;
            if (isset($rIdx[$w]) && count($rIdx[$w]) === 1) return [$i, $rIdx[$w][0]];
        }
    }
    return null;
}

/**
 * Core comparison: align each ref to the primary (windowed by ref rows) and
 * build per-primary-word slots with a consensus vote. Returns
 * [primaryWords, refAligned(code→[tokens]), slots, disagreements].
 */
/**
 * Coverage-union spine: start from the chosen word spine, then fill spans the
 * spine never covered (VAD-dropped songs, engine dropouts) with words from the
 * baseline that best covers each gap. Plain consensus builds exactly one slot
 * per spine word and drops any ref token with no spine anchor (see
 * buildComparison), so a hole in the spine is a permanent hole in the output no
 * matter how many baselines heard the passage. This pre-pass gives those
 * passages spine slots so the vote can include them.
 *
 * Returns a primaryWords()-shaped list [{i,t,word}] with gap words spliced in
 * (time-ordered, re-indexed). $filled is populated with {start,end,code,words}
 * entries describing what was injected, for logging/telemetry. Pure DB read:
 * the "another engine produced >= $minWords words here" requirement is itself a
 * strong sound gate (engines with their own VAD do not fabricate a passage into
 * true silence) — pass $soundGate to add an ffmpeg audible-content check too.
 */
function unionSpineWords(PDO $db, int $itemKey, string $spineCode, array $baselineCodes,
                         array &$filled = [], float $minGapSecs = 8.0, int $minWords = 3,
                         ?callable $soundGate = null, ?array $weights = null): array {
    $spineRows = loadCompareRows($db, $itemKey, $spineCode);
    $pWords = primaryWords($spineRows);
    $filled = [];
    if (count($pWords) < 2) return $pWords;

    $cands = array_values(array_filter($baselineCodes, fn($c) => $c !== $spineCode));
    if (!$cands) return $pWords;
    // Lazy-load each candidate's word stream once.
    $wordsByCode = [];
    $loadWords = function (string $c) use (&$wordsByCode, $db, $itemKey): array {
        if (!array_key_exists($c, $wordsByCode)) {
            $wordsByCode[$c] = primaryWords(loadCompareRows($db, $itemKey, $c));
        }
        return $wordsByCode[$c];
    };

    $inserts = [];
    $n = count($pWords);
    for ($k = 0; $k + 1 < $n; $k++) {
        $gapStart = (float)$pWords[$k]['t'];
        $gapEnd   = (float)$pWords[$k + 1]['t'];
        if ($gapEnd - $gapStart < $minGapSecs) continue;
        if ($soundGate && !$soundGate($gapStart, $gapEnd)) continue;
        // Choose the baseline to fill this gap by (quality tier, word count):
        // tier 3 = word-level engines (accurate per-word timing), tier 2 =
        // segment engines (whisperx/parakeet), tier 1 = coarse chunkers
        // (canary/qwen, ~30s blocks — usable text but poor timing). A word-level
        // engine that covers the gap always beats a coarse one that merely dumps
        // more tokens at one timestamp. Only baselines with >= $minWords qualify.
        $scored = [];
        foreach ($cands as $c) {
            $ws = [];
            foreach ($loadWords($c) as $w) {
                $t = (float)$w['t'];
                if ($t > $gapStart + 0.05 && $t < $gapEnd - 0.05) $ws[] = $w;
            }
            if (count($ws) < $minWords) continue;
            $tier = in_array($c, ['gpu-canary-1b-flash', 'gpu-qwen2-audio'], true) ? 1
                  : (str_ends_with($c, '-word') ? 3 : 2);
            // Learned edit-weight (1.0 = neutral / no data) decides between
            // candidates of the SAME tier: the engine that historically lands
            // closest to the human-edited final fills the gap. Null $weights →
            // every w=1.0 → sort falls through to tier+count = old behaviour.
            $gw = ($weights && isset($weights[$c])) ? (float)$weights[$c] : 1.0;
            $scored[] = ['code' => $c, 'tier' => $tier, 'words' => $ws, 'w' => $gw];
        }
        if ($scored) {
            usort($scored, fn($a, $b) => [$b['tier'], round($b['w'], 3), count($b['words'])]
                                     <=> [$a['tier'], round($a['w'], 3), count($a['words'])]);
            $pick = $scored[0];
            $inserts[] = ['start' => $gapStart, 'end' => $gapEnd, 'code' => $pick['code'], 'words' => $pick['words']];
        }
    }
    if (!$inserts) return $pWords;

    $merged = $pWords;
    foreach ($inserts as $ins) {
        foreach ($ins['words'] as $w) $merged[] = ['t' => (float)$w['t'], 'word' => $w['word']];
        $filled[] = ['start' => $ins['start'], 'end' => $ins['end'],
                     'code' => $ins['code'], 'words' => count($ins['words'])];
    }
    usort($merged, fn($a, $b) => ($a['t'] <=> $b['t']));
    foreach ($merged as $i => &$w) $w['i'] = $i;
    unset($w);
    return $merged;
}

function buildComparison(PDO $db, int $itemKey, string $primary, array $refs, ?array $spineWords = null, ?array $weights = null): array {
    // $spineWords (optional): a prebuilt primaryWords()-shaped spine, used by the
    // coverage-union path so voting can run over a spine whose gaps were filled
    // from other baselines. When null, the spine is loaded from $primary's rows
    // as before.
    if ($spineWords !== null) {
        $pWords = $spineWords;
        if (!$pWords) return ['error' => 'primary has no rows for this item'];
    } else {
        $pRows = loadCompareRows($db, $itemKey, $primary);
        if (!$pRows) return ['error' => 'primary has no rows for this item'];
        $pWords = primaryWords($pRows);
    }
    $pNorm = array_map(fn($w) => normTok($w['word']), $pWords);

    $np = count($pWords);
    $refAligned = [];
    foreach ($refs as $code) {
        if ($code === $primary) continue;
        $rRows = loadCompareRows($db, $itemKey, $code);
        // Content-based alignment - ignores the reference's (often unreliable)
        // timestamps. The old time-windowing broke on YouTube: rolling captions
        // repeat words (~3x) and their timing drifts, so the right word landed
        // in the wrong slot, manufacturing offset "disagreements". Aligning the
        // ref's full token stream to the primary by CONTENT (unique-anchor LCS)
        // fixes it - duplicate/extra ref tokens with no primary anchor drop out.
        $refRaw = []; $refNorm = [];
        foreach ($rRows as $rr) {
            foreach (tokenize($rr['txt']) as $tok) { $refRaw[] = $tok; $refNorm[] = normTok($tok); }
        }
        $refAligned[$code] = alignSequence($pNorm, $refNorm, $refRaw);
    }

    $slots = [];
    $disagreements = 0;
    foreach ($pWords as $w) {
        $i = $w['i'];
        $pn = $pNorm[$i];
        $rowRefs = [];
        $votes = [];
        // Weighted majority vote: each engine casts its learned edit-weight
        // (how closely it tracks the human-edited final), not a flat 1. The
        // spine votes its own weight; refs add theirs. Null $weights → every
        // weight is 1.0 → identical to the old flat count. The spine is seeded
        // FIRST so an exact tie still resolves to the spine word (stable).
        $spineW = ($weights && isset($weights[$primary])) ? (float)$weights[$primary] : 1.0;
        if ($pn !== '') $votes[$pn] = ['count' => $spineW, 'display' => $w['word']];
        $agree = true;
        foreach ($refAligned as $code => $arr) {
            $rv = $arr[$i] ?? '';
            $rowRefs[$code] = $rv;
            $rn = normTok($rv);
            if ($rv !== '') {
                $rWt = ($weights && isset($weights[$code])) ? (float)$weights[$code] : 1.0;
                if (!isset($votes[$rn])) $votes[$rn] = ['count' => 0, 'display' => $rv];
                $votes[$rn]['count'] += $rWt;
            }
            if ($rn !== $pn) $agree = false;
        }
        $consensus = $w['word']; $best = -1;
        foreach ($votes as $v) if ($v['count'] > $best) { $best = $v['count']; $consensus = $v['display']; }
        if (!$agree) $disagreements++;
        $slots[] = ['i' => $i, 't' => round($w['t'], 2), 'primary' => $w['word'],
                    'refs' => $rowRefs, 'consensus' => $consensus, 'agree' => $agree];
    }
    return ['primaryWords' => $pWords, 'refAligned' => $refAligned,
            'slots' => $slots, 'disagreements' => $disagreements];
}
