<?php
/**
 * tts-lead-artifact.php — leading autoregressive-filler guard for short headings.
 *
 * Chatterbox (and other autoregressive local engines) STOCHASTICALLY emit a
 * leading filler vowel ("uh"/"ah") before a short STANDALONE clip — most visibly
 * a single-word chapter heading. Because plain-text segments carry seed=null,
 * every synthesis rolls the dice: e.g. the identical "BIBLIOGRAPHY" heading came
 * out "Uh, bibliography." in s07v01 (ak617) but clean in s07v05 (ak846). This is
 * a sibling defect class to reference_tts_boundary_quote_hallucination /
 * reference_tts_ellipsis_hallucination, but at the START of the clip rather than
 * the end, so none of the boundary-strip fixes catch it.
 *
 * DETECTION (STT-only — coexists in the box's 98 GB VRAM with the running TTS
 * engine, no eviction). Two AND-ed signals, both true for the confirmed ak617
 * case and each independently rejecting the clean samples (ak846, Shepherd, …):
 *   (a) a DEEP leading gap: a voiced blip, then a near-silence valley, then the
 *       real word — i.e. the real word starts LATE. (Acoustic, ffmpeg RMS.)
 *   (b) trim-survives-expected: after cutting the first ~0.20 s, STT (UNPROMPTED,
 *       vad off) still transcribes the FULL EXPECTED word — proof the lead was
 *       extra. Prompted STT over-biases (completes the partial), so never prompt.
 *
 * REPAIR is re-synth-and-verify: re-run the (stochastic) synth up to N times and
 * take the first clean result; if none come clean, keep the shortest candidate
 * (a leading filler only ADDS duration). A detected artifact therefore only ever
 * triggers a re-synth — it NEVER edits/trims audio — so a false positive costs a
 * wasted synth, never a corrupted clip. Every entry point is exception-safe: any
 * internal error returns the original bytes unchanged (never worse than today).
 */

if (!function_exists('ttsLAExpectedWord')) {

/** First spoken word (lowercased letters only) of a segment/paragraph text. */
function ttsLAExpectedWord(string $text): string {
    // Drop any markup / pause markers, then take the first run of letters.
    $t = preg_replace('/<[^>]*>/', ' ', $text);
    if (preg_match('/[\p{L}]{2,}/u', mb_strtolower($t), $m)) return $m[0];
    return '';
}

/** Heading-like gate: short, few words. Only these clips are worth checking. */
function ttsLAIsShort(string $text): bool {
    $plain = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N} ]/u', ' ', $text)));
    if ($plain === '') return false;
    if (mb_strlen($plain) > 32) return false;
    return count(preg_split('/\s+/u', $plain)) <= 3;
}

/** ffprobe duration of an mp3 file in ms (0 on error). */
function ttsLADurationMs(string $path, string $ffprobeBin = 'ffprobe'): int {
    $out = @shell_exec(escapeshellarg($ffprobeBin)
        . ' -v error -show_entries format=duration -of default=nk=1:nw=1 '
        . escapeshellarg($path) . ' 2>/dev/null');
    $d = is_string($out) ? (float)trim($out) : 0.0;
    return $d > 0 ? (int)round($d * 1000) : 0;
}

/**
 * (a) Deep leading gap in the first ~0.5 s: a voiced blip (>= -32 dB in
 * [0.02,0.10]s), then a near-silence valley (<= -42 dB in [0.10,0.34]s), then a
 * loud word (>= -26 dB after the valley, out to 0.48s). Resample to 24 kHz so
 * the 20 ms window is a fixed 480 samples regardless of the source rate.
 */
function ttsLAHasEarlyGap(string $path, string $ffmpegBin = 'ffmpeg'): bool {
    $cmd = escapeshellarg($ffmpegBin) . ' -hide_banner -nostats -i ' . escapeshellarg($path)
         . ' -af "atrim=0:0.5,aresample=24000,asetnsamples=480,'
         . 'astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level" '
         . '-f null - 2>&1';
    $out = @shell_exec($cmd);
    if (!is_string($out) || $out === '') return false;
    $rms = [];
    foreach (explode("\n", $out) as $line) {
        if (strpos($line, 'RMS_level=') !== false) {
            $v = trim(substr($line, strpos($line, 'RMS_level=') + 10));
            $rms[] = ($v === '-inf' || $v === '') ? -120.0 : (float)$v;
        }
    }
    $n = count($rms);
    if ($n < 18) return false;                      // need ~0.36 s of frames
    $maxIn = function (int $lo, int $hi) use ($rms, $n) {
        $lo = max(0, $lo); $hi = min($n - 1, $hi); $m = -120.0;
        for ($i = $lo; $i <= $hi; $i++) if ($rms[$i] > $m) $m = $rms[$i];
        return $m;
    };
    $beforeMax = $maxIn(1, 4);                       // 0.02–0.10 s: leading blip
    // valley (deepest frame) within 0.10–0.34 s
    $vIdx = 5; $vMin = 1e9;
    for ($i = 5; $i <= min($n - 1, 16); $i++) { if ($rms[$i] < $vMin) { $vMin = $rms[$i]; $vIdx = $i; } }
    $afterMax = $maxIn($vIdx + 1, 24);              // loud word after the valley
    return ($beforeMax >= -32.0) && ($vMin <= -42.0) && ($afterMax >= -26.0);
}

/**
 * (b) After cutting the first $cutS seconds, does STT (unprompted, vad off) still
 * return the FULL expected word? If yes, the lead was extra. STT errors → false
 * (can't confirm → treat as not-artifact, so we never re-synth on a flaky STT).
 */
function ttsLATailSurvivesExpected(string $path, string $expectedWord, string $tmpDir,
                                   float $cutS = 0.20, string $ffmpegBin = 'ffmpeg'): bool {
    if ($expectedWord === '' || !function_exists('gpuTranscribe')) return false;
    $wav = tempnam($tmpDir, 'la-tail-') . '.wav';
    $cmd = escapeshellarg($ffmpegBin) . ' -hide_banner -loglevel error -y -ss '
         . sprintf('%.3f', $cutS) . ' -i ' . escapeshellarg($path) . ' ' . escapeshellarg($wav) . ' 2>/dev/null';
    @shell_exec($cmd);
    if (!is_file($wav) || filesize($wav) === 0) { @unlink($wav); return false; }
    $r = gpuTranscribe($wav, ['vad_filter' => false, 'language' => 'en', 'word_timestamps' => false, 'timeout' => 60]);
    @unlink($wav);
    $txt = $r['data']['text'] ?? '';
    $txt = mb_strtolower(trim(preg_replace('/[^\p{L} ]/u', ' ', $txt)));
    $txt = trim(preg_replace('/\s+/u', ' ', $txt));
    if ($txt === '') return false;
    return strpos(' ' . $txt . ' ', ' ' . $expectedWord . ' ') !== false;
}

/**
 * Does the clip's HEAD (first $cutS s) begin with the expected word's onset (a
 * shared 2-char prefix), rather than a filler? STT of a ~0.20 s fragment is
 * noisy, but for a clean word it lands on the real onset ("Bib" for bibliography)
 * while an artifact lands on the filler ("uh"/"a"). Empty head (leading silence)
 * counts as a match (no filler present). STT error → true (can't confirm a filler
 * → don't flag). Returns true when the head looks like the real word onset.
 */
function ttsLAHeadMatchesOnset(string $path, string $expectedWord, string $tmpDir,
                               float $cutS = 0.20, string $ffmpegBin = 'ffmpeg'): bool {
    if ($expectedWord === '' || !function_exists('gpuTranscribe')) return true;
    $wav = tempnam($tmpDir, 'la-head-') . '.wav';
    $cmd = escapeshellarg($ffmpegBin) . ' -hide_banner -loglevel error -y -t '
         . sprintf('%.3f', $cutS) . ' -i ' . escapeshellarg($path) . ' ' . escapeshellarg($wav) . ' 2>/dev/null';
    @shell_exec($cmd);
    if (!is_file($wav) || filesize($wav) === 0) { @unlink($wav); return true; }
    $r = gpuTranscribe($wav, ['vad_filter' => false, 'language' => 'en', 'word_timestamps' => false, 'timeout' => 60]);
    @unlink($wav);
    $txt = mb_strtolower(trim(preg_replace('/[^\p{L} ]/u', ' ', $r['data']['text'] ?? '')));
    if (!preg_match('/[a-z]+/', $txt, $m)) return true;      // empty head (leading silence) = no filler
    $hw = $m[0];
    // Shared 2-char prefix ("bib"/"bibliography", "she"/"shepherd") = real onset.
    if (mb_strlen($hw) >= 2) {
        $ep = mb_substr($expectedWord, 0, 2);
        $hp = mb_substr($hw, 0, 2);
        if (strpos($expectedWord, $hp) === 0 || strpos($hw, $ep) === 0) return true;
    }
    // 1-char / no-2-prefix head (e.g. the bare "a"/"uh" filler): a match only if
    // the expected word actually starts with it ("a" onset of "afterword"), so a
    // bare filler on a non-matching word ("a" before "bibliography") stays false.
    return strpos($expectedWord, $hw) === 0;
}

/**
 * Detect a leading filler on raw mp3 $bytes given the expected first word.
 * Returns ['artifact'=>bool, 'dur'=>int ms, 'diag'=>string]. Never throws.
 *
 * DECISION = tail-survives-expected AND NOT head-matches-onset. Neither signal is
 * reliable alone: tail-survives false-positives on a clean LONG word (STT
 * reconstructs "bibliography" from the 0.20 s-trimmed onset), and a head STT is
 * noisy on fricative onsets. Together they are precise: an artifact has the full
 * word after the cut ($tail) AND a filler, not the onset, at the head; a clean
 * long word has the word after the cut but its real onset ("bib") at the head, so
 * head-matches vetoes it. The acoustic early-gap is diagnostic only (it misses
 * gaps past a fixed window — see git history — so it is NOT in the decision).
 */
function ttsLADetect(string $bytes, string $expectedWord, string $tmpDir,
                     string $ffmpegBin = 'ffmpeg', string $ffprobeBin = 'ffprobe'): array {
    $mp3 = tempnam($tmpDir, 'la-det-') . '.mp3';
    @file_put_contents($mp3, $bytes);
    $dur = ttsLADurationMs($mp3, $ffprobeBin);
    $tail = false; $headOk = true; $gap = false;
    try {
        $tail = ttsLATailSurvivesExpected($mp3, $expectedWord, $tmpDir, 0.20, $ffmpegBin);
        // Only pay for the head STT when the tail survived (the only case that
        // could be an artifact); otherwise it's already clean.
        $headOk = $tail ? ttsLAHeadMatchesOnset($mp3, $expectedWord, $tmpDir, 0.20, $ffmpegBin) : true;
        $gap    = ttsLAHasEarlyGap($mp3, $ffmpegBin);
    } catch (\Throwable $e) { /* fall through as not-artifact */ }
    @unlink($mp3);
    $art = $tail && !$headOk;
    return ['artifact' => $art, 'dur' => $dur,
            'diag' => 'tail=' . ($tail ? 1 : 0) . ' headOk=' . ($headOk ? 1 : 0) . ' gap=' . ($gap ? 1 : 0) . ' dur=' . $dur];
}

/**
 * Guard a freshly-synthesised short-heading part. $firstBytes is the initial
 * synth; $resynth is a closure returning fresh (stochastic) bytes each call.
 * If $firstBytes has a leading filler, re-synth up to $maxRetries times and take
 * the first clean result; else keep the shortest candidate. Exception-safe:
 * ANY failure returns $firstBytes unchanged.
 */
function ttsLAGuardHeading(string $firstBytes, string $expectedWord, callable $resynth,
                           string $tmpDir, int $maxRetries = 3, ?string &$diag = null,
                           string $ffmpegBin = 'ffmpeg', string $ffprobeBin = 'ffprobe'): string {
    try {
        if ($firstBytes === '' || $expectedWord === '') { $diag = 'skip(empty)'; return $firstBytes; }
        $d0 = ttsLADetect($firstBytes, $expectedWord, $tmpDir, $ffmpegBin, $ffprobeBin);
        if (!$d0['artifact']) { $diag = 'clean(' . $d0['diag'] . ')'; return $firstBytes; }
        $cands = [['bytes' => $firstBytes, 'dur' => $d0['dur'] ?: PHP_INT_MAX]];
        for ($i = 1; $i <= $maxRetries; $i++) {
            $nb = '';
            try { $nb = (string)$resynth(); } catch (\Throwable $e) { $nb = ''; }
            if ($nb === '') continue;
            $d = ttsLADetect($nb, $expectedWord, $tmpDir, $ffmpegBin, $ffprobeBin);
            if (!$d['artifact']) { $diag = "fixed(try=$i " . $d['diag'] . ')'; return $nb; }
            $cands[] = ['bytes' => $nb, 'dur' => $d['dur'] ?: PHP_INT_MAX];
        }
        // None clean — keep the shortest (a leading filler only adds duration).
        usort($cands, function ($a, $b) { return $a['dur'] <=> $b['dur']; });
        $diag = 'unresolved(kept-shortest dur=' . $cands[0]['dur'] . ' of ' . count($cands) . ')';
        return $cands[0]['bytes'];
    } catch (\Throwable $e) {
        $diag = 'error(' . $e->getMessage() . ' -> kept original)';
        return $firstBytes;
    }
}

}
