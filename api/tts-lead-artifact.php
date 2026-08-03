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
 * First token (lowercased letters) of the clip's HEAD (first $cutS s), via STT.
 * '' if empty/silent or STT unavailable. STT of a ~0.20 s fragment is noisy but
 * for a clean word it lands on the real onset ("bib"/"in"/"is") while an artifact
 * lands on a filler ("uh"/"a"/"i").
 */
function ttsLAHeadToken(string $path, string $tmpDir, float $cutS = 0.20, string $ffmpegBin = 'ffmpeg'): string {
    if (!function_exists('gpuTranscribe')) return '';
    $wav = tempnam($tmpDir, 'la-head-') . '.wav';
    $cmd = escapeshellarg($ffmpegBin) . ' -hide_banner -loglevel error -y -t '
         . sprintf('%.3f', $cutS) . ' -i ' . escapeshellarg($path) . ' ' . escapeshellarg($wav) . ' 2>/dev/null';
    @shell_exec($cmd);
    if (!is_file($wav) || filesize($wav) === 0) { @unlink($wav); return ''; }
    $r = gpuTranscribe($wav, ['vad_filter' => false, 'language' => 'en', 'word_timestamps' => false, 'timeout' => 60]);
    @unlink($wav);
    $txt = mb_strtolower(trim(preg_replace('/[^\p{L} ]/u', ' ', $r['data']['text'] ?? '')));
    return preg_match('/[a-z]+/', $txt, $m) ? $m[0] : '';
}

/**
 * First word (lowercased letters) of the WHOLE clip's STT. Unlike "uh" (which the
 * full-clip STT swallows), a leading "i"/"eye"/"my" survives in the full
 * transcript — so for the i-word ambiguous case this is what tells a clean
 * "Introduction" (full first word "introduction") from an "I, Introduction"
 * artifact (full first word "iintroduction"/"eye"/"my"). '' on error.
 */
function ttsLAFullText(string $path): string {
    if (!function_exists('gpuTranscribe')) return '';
    $r = gpuTranscribe($path, ['vad_filter' => false, 'language' => 'en', 'word_timestamps' => false, 'timeout' => 60]);
    return trim(preg_replace('/\s+/u', ' ', mb_strtolower(preg_replace('/[^\p{L} ]/u', ' ', $r['data']['text'] ?? ''))));
}

/**
 * Detect a leading filler on raw mp3 $bytes given the expected first word.
 * Returns ['artifact'=>bool, 'dur'=>int ms, 'diag'=>string]. Never throws.
 *
 * Necessary condition = TAIL-SURVIVES: after cutting the first 0.20 s, STT still
 * returns the full expected word (the lead was extra). tail alone false-positives
 * on a clean LONG word (STT reconstructs "bibliography" from the trimmed onset),
 * so when tail survives we look at the HEAD (first 0.20 s STT):
 *   - shares a 2-char prefix with the word ("bib"/"in"/"is") → real onset → CLEAN.
 *   - a 2+char head that does NOT match → a clear filler ("uh") → ARTIFACT.
 *   - a 1-char head that is NOT the word's first letter ("a" before "bibliography")
 *       → bare-vowel filler → ARTIFACT.
 *   - a 1-char head that IS the word's first letter ("i" for BOTH the "I,
 *       Introduction" filler AND a clean "Islamic" whose "Is-" onset clipped to
 *       "i") → AMBIGUOUS → decided by the acoustic early-gap: a filler leaves a
 *       leading blip + near-silence valley before the word; a clean onset is
 *       continuous. (This is the ONLY place the gap is in the decision; it is
 *       reliable for this narrow leading-vowel-vs-continuous-onset call.)
 */
function ttsLADetect(string $bytes, string $expectedWord, string $tmpDir,
                     string $ffmpegBin = 'ffmpeg', string $ffprobeBin = 'ffprobe'): array {
    $mp3 = tempnam($tmpDir, 'la-det-') . '.mp3';
    @file_put_contents($mp3, $bytes);
    $dur = ttsLADurationMs($mp3, $ffprobeBin);
    $tail = false; $art = false; $ff = '-'; $hw = '-'; $branch = '';
    try {
        // Necessary: after cutting 0.20 s, STT still returns the full expected word.
        $tail = ttsLATailSurvivesExpected($mp3, $expectedWord, $tmpDir, 0.20, $ffmpegBin);
        if ($tail && $expectedWord !== '') {
            // PRIMARY signal = the FULL-clip STT's first word. A clean clip (incl.
            // a long word whose 0.20 s-trimmed onset STT reconstructs) starts with
            // the word itself → its first word shares the expected 4-char prefix.
            // A leading "i"/"eye"/"my"/"a" filler SURVIVES in the full transcript
            // ("iIntroduction"/"Eye Introduction"/"My introduction"/"a X") so the
            // first word does NOT match → artifact. The head STT (first 0.20 s) is
            // too noisy to route on (the SAME "I,Introduction" gives head "i" on
            // one synth, "bye"/"on" on another) — it's used ONLY to recover the one
            // case the full STT can't see: a leading "uh"/"ah" that the full STT
            // SWALLOWS (full first word = the word, but head = a known filler).
            $full = ttsLAFullText($mp3);
            $ff   = preg_match('/[a-z]+/', $full, $m) ? $m[0] : '';
            $p4   = mb_substr($expectedWord, 0, 4);
            $onsetFull = ($ff !== '' && $p4 !== '' && strpos($ff, $p4) === 0);
            // Is the expected word actually PRESENT in the full transcript? A real
            // leading filler leaves it there ("i introduction"/"iintroduction");
            // a full-clip garble of a hard word drops it ("Addendum" → "keep your
            // dendem" has no "addendum") — that's a clean word STT mangled, not a
            // filler, so it must NOT flag.
            $hasWord = ($full !== '' && strpos($full, $expectedWord) !== false);
            if ($onsetFull) {
                $hw = ttsLAHeadToken($mp3, $tmpDir, 0.20, $ffmpegBin);
                $FILLERS = ['uh','ah','eh','um','oh','er','huh','hmm','mm','umm','erm','uhh','ahh','ohh','hm','uhm'];
                $art = in_array($hw, $FILLERS, true);
                $branch = $art ? "filler-swallowed[$hw]" : "onset-full[$ff]";
            } elseif ($hasWord) {
                $art = true; $branch = "lead-extra[$ff]";
            } else {
                $art = false; $branch = "full-garble[$ff]";   // word absent from full STT = mangled clean word, not a filler
            }
        }
    } catch (\Throwable $e) { $art = false; $branch = 'err'; }
    @unlink($mp3);
    return ['artifact' => $art, 'dur' => $dur,
            'diag' => 'tail=' . ($tail ? 1 : 0) . " ff=[$ff] head=[$hw] $branch dur=$dur"];
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
