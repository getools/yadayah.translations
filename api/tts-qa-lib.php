<?php
/**
 * Pure helpers for TTS "Sync / QA": after a chapter's audio is built, run STT
 * over the generated MP3 and check it back against the book text that was
 * supposed to be spoken. Two products fall out of the same alignment:
 *
 *   1. PAGE-MARKER ONSETS — the true speech-onset time of each page's first
 *      word, used to correct yy_tts_audio_marker.tts_audio_marker_offset_ms
 *      (the byte-offset markers point at where a paragraph's MP3 chunk BEGINS,
 *      which includes leading silence / engine warmup / inter-paragraph pause,
 *      so the flipbook jumps a beat early). See ttsQaAlignOnsets().
 *
 *   2. TEXT MISMATCHES — words the STT engine(s) heard differently from the
 *      book text (dropped, substituted, hallucinated-extra). Surfaced as an
 *      ADVISORY report, gated by ENGINE CONSENSUS: this book's Hebrew names
 *      ("Yahowah") are mis-heard DIFFERENTLY by each engine on each call, so a
 *      single engine's disagreement is noise — we only flag a word when ≥2
 *      engines agree the audio diverges from the text. See ttsQaAnalyze().
 *
 * No side effects on include (no auth, no DB, no HTTP) so it is unit-testable
 * from the CLI. The alignment core is reused from transcript-compare-lib.php
 * (alignSequence / normTok / tokenize) — it is content-based and timestamp-
 * agnostic, exactly what we need to map an STT word stream onto known book text.
 */

require_once __DIR__ . '/transcript-compare-lib.php'; // alignSequence, normTok, tokenize

/**
 * Canonicalize a normalized token that represents a number so a digit and its
 * spelled-out form collapse to ONE token: "3"/"three" → "3", "3rd"/"third" →
 * "3o" (ordinals tagged 'o' so a cardinal 3 still differs from an ordinal 3rd).
 * Covers digits (with optional ordinal suffix) + single-word cardinals/ordinals
 * 0–90 and hundred/thousand/million. Returns null if it isn't a number word.
 * TTS reads digits aloud as words, so this stops "3"→"three" flagging as a
 * mismatch (operator-requested: digit and number-word are equal).
 */
function ttsQaNumCanon(string $n): ?string {
    if ($n === '') return null;
    if (preg_match('/^(\d+)(st|nd|rd|th)?$/', $n, $m)) {
        return $m[1] . (!empty($m[2]) ? 'o' : '');
    }
    static $card = [
        'zero'=>'0','one'=>'1','two'=>'2','three'=>'3','four'=>'4','five'=>'5','six'=>'6',
        'seven'=>'7','eight'=>'8','nine'=>'9','ten'=>'10','eleven'=>'11','twelve'=>'12',
        'thirteen'=>'13','fourteen'=>'14','fifteen'=>'15','sixteen'=>'16','seventeen'=>'17',
        'eighteen'=>'18','nineteen'=>'19','twenty'=>'20','thirty'=>'30','forty'=>'40',
        'fifty'=>'50','sixty'=>'60','seventy'=>'70','eighty'=>'80','ninety'=>'90',
        'hundred'=>'100','thousand'=>'1000','million'=>'1000000','billion'=>'1000000000',
    ];
    static $ord = [
        'first'=>'1','second'=>'2','third'=>'3','fourth'=>'4','fifth'=>'5','sixth'=>'6',
        'seventh'=>'7','eighth'=>'8','ninth'=>'9','tenth'=>'10','eleventh'=>'11','twelfth'=>'12',
        'thirteenth'=>'13','fourteenth'=>'14','fifteenth'=>'15','sixteenth'=>'16','seventeenth'=>'17',
        'eighteenth'=>'18','nineteenth'=>'19','twentieth'=>'20','thirtieth'=>'30','fortieth'=>'40',
        'fiftieth'=>'50','sixtieth'=>'60','seventieth'=>'70','eightieth'=>'80','ninetieth'=>'90',
        'hundredth'=>'100','thousandth'=>'1000','millionth'=>'1000000',
    ];
    if (isset($ord[$n]))  return $ord[$n] . 'o';
    if (isset($card[$n])) return $card[$n];
    return null;
}

/**
 * Normalize a word for QA comparison. Stronger than compare-lib's normTok: it
 * also folds smart quotes to ASCII and DROPS internal apostrophes and hyphens,
 * so "Apostle’s"/"Apostle's" and "cherry-picking"/"cherry picking" don't read as
 * substitutions (those are punctuation/tokenization artifacts, not TTS defects).
 * Used uniformly for alignment AND mismatch comparison so the two stay coherent.
 */
function ttsQaNormWord(string $w, ?array $map = null): string {
    $w = strtr($w, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '—' => '-', '–' => '-', '‑' => '-']);
    // Drop internal apostrophes/hyphens AND the Hebrew-transliteration modifier
    // letters this book uses (ʾ U+02BE, ʿ U+02BF, ʼ U+02BC) — STT can't produce
    // them, so "Yisraʾel" must fold to the same token as the engine's "Yisrael".
    $w = str_replace(["'", '-', "\u{02BE}", "\u{02BF}", "\u{02BC}", "\u{02C8}", "\u{02D0}"], '', $w);
    $n = normTok($w);
    // Digit ↔ spelled-out number equivalence (both sides): "3" == "three".
    $num = ttsQaNumCanon($n);
    if ($num !== null) return $num;
    // Optional STT-correction map (normalized wrong→right, single-token only):
    // folds the way each engine mis-hears the book's transliterations
    // ("yahweh"→"yahowah", "torah"→"towrah") so they stop reading as mismatches
    // and the alignment anchors on more words. Applied to STT tokens ONLY — the
    // book text is already canonical, so it's never passed a map.
    if ($map !== null && $n !== '') {
        if (isset($map[$n])) return $map[$n];
        // Possessive/plural fallback: "yahwehs" → map "yahweh" + "s" so the
        // possessive ("Yahowah's") folds without a separate dictionary entry.
        if (strlen($n) > 2 && $n[strlen($n) - 1] === 's' && isset($map[substr($n, 0, -1)])) {
            return $map[substr($n, 0, -1)] . 's';
        }
    }
    return $n;
}

/** Word-level GPU STT engines eligible as cross-check sources (segment-only
 *  engines are intentionally excluded — onset timing needs word timestamps). */
function ttsQaEngineCodes(): array {
    return [
        'gpu-whisperx-word',                 // wav2vec2 forced alignment — tightest timing → preferred onset spine
        'gpu-whisper-large-v3-word',
        'gpu-whisper-large-v3-turbo-word',
        'gpu-parakeet-tdt-0.6b-v2-word',
    ];
}

/** Preference order for the engine whose word timing drives onset correction. */
function ttsQaOnsetPreference(): array {
    return ttsQaEngineCodes();   // whisperx-word first
}

/**
 * Tokenize a chapter's page markers into one ordered book-token stream, tagging
 * where each marker's text begins so we can read off a per-page onset.
 *
 * @param array $markers Ordered (by audio position) list of
 *        [ 'text' => plain paragraph text, ... ]. Extra keys are ignored here.
 * @return array{raw:string[], norm:string[], starts:int[], ends:int[]}
 *         raw/norm = parallel token arrays for the whole chapter;
 *         starts[$mi]/ends[$mi] = [start,end) token range of marker $mi.
 */
function ttsQaTokenizeMarkers(array $markers): array {
    $raw = []; $norm = []; $starts = []; $ends = [];
    foreach ($markers as $mi => $m) {
        $starts[$mi] = count($raw);
        foreach (tokenize((string)($m['text'] ?? '')) as $tok) {
            $n = ttsQaNormWord($tok);
            if ($n === '') continue;            // drop pure-punctuation tokens (no STT counterpart)
            $raw[]  = $tok;
            $norm[] = $n;
        }
        $ends[$mi] = count($raw);
    }
    return ['raw' => $raw, 'norm' => $norm, 'starts' => $starts, 'ends' => $ends];
}

/**
 * Align one STT word stream onto the book-token stream by content.
 *
 * @param string[] $bookNorm normalized book tokens (from ttsQaTokenizeMarkers)
 * @param array    $stream   STT words [{ 'w'=>string, 't'=>float secs }, ...]
 * @return int[]   indexed like $bookNorm; each entry is the matched STT word
 *                 index, or -1 for a gap (book word the engine didn't place).
 */
function ttsQaAlignStream(array $bookNorm, array $stream, ?array $map = null): array {
    $sttNorm = []; $sttIdx = [];
    foreach ($stream as $j => $wd) {
        $sttNorm[] = ttsQaNormWord((string)($wd['w'] ?? ''), $map);
        $sttIdx[]  = (string)$j;                // carry the STT index through alignSequence's refRaw
    }
    $aligned = alignSequence($bookNorm, $sttNorm, $sttIdx);  // book index → STT index string ('' = gap)
    $out = [];
    foreach ($aligned as $i => $v) $out[$i] = ($v === '') ? -1 : (int)$v;
    return $out;
}

/**
 * Per-marker speech-onset time from one aligned STT stream. For each marker we
 * take the first book token at/after its start that the engine actually placed,
 * and read that STT word's start time. We do NOT search past the marker's own
 * token range (ends[$mi]) — a page with no recognized words gets null rather
 * than borrowing the next page's onset.
 *
 * @return array<int,?int> markerIndex → onset milliseconds (null if unmatched)
 */
function ttsQaStreamOnsets(array $tok, array $aligned, array $stream): array {
    $onsets = [];
    foreach ($tok['starts'] as $mi => $start) {
        $end = $tok['ends'][$mi];
        $onsets[$mi] = null;
        for ($i = $start; $i < $end; $i++) {
            $sj = $aligned[$i] ?? -1;
            if ($sj >= 0 && isset($stream[$sj]['t'])) {
                $onsets[$mi] = (int)round(((float)$stream[$sj]['t']) * 1000);
                break;
            }
        }
    }
    return $onsets;
}

/**
 * Build-path helper: corrected page onsets from a SINGLE word-level STT stream
 * (forced-alignment whisperx in the build worker). Returns markerIndex →
 * onset_ms, omitting markers the engine couldn't place. The caller writes these
 * over tts_audio_marker_offset_ms; markers absent from the result keep their
 * byte-derived offset. Best-effort by construction — an empty stream yields [].
 */
function ttsQaAlignOnsets(array $markers, array $stream, ?array $map = null): array {
    if (!$markers || !$stream) return [];
    $tok = ttsQaTokenizeMarkers($markers);
    if (!$tok['norm']) return [];
    $aligned = ttsQaAlignStream($tok['norm'], $stream, $map);
    $out = [];
    foreach (ttsQaStreamOnsets($tok, $aligned, $stream) as $mi => $ms) {
        if ($ms !== null) $out[$mi] = $ms;
    }
    return $out;
}

/**
 * Full QA analysis for the reporting tool. Aligns every selected engine's word
 * stream onto the chapter book text, then produces:
 *   - pages[]      : per-marker stored offset vs STT onset + drift + status
 *   - mismatches[] : consensus word disagreements (advisory)
 *   - summary      : roll-up counts
 *
 * @param array $markers       Ordered [{paragraph_number, paragraph_page,
 *                             offset_ms, text}], audio order.
 * @param array $engineStreams [engineCode => [{w,t}, ...]] (t in seconds).
 * @param array $opts          drift_threshold_ms (default 350),
 *                             onset_engine (default: first by preference present).
 */
function ttsQaAnalyze(array $markers, array $engineStreams, array $opts = []): array {
    $driftMs = (int)($opts['drift_threshold_ms'] ?? 350);
    $map     = $opts['correction_map'] ?? null;   // STT-correction lookups (normalized wrong→right)
    $codes   = array_keys($engineStreams);
    $nEng    = count($codes);

    $tok = ttsQaTokenizeMarkers($markers);
    $bookNorm = $tok['norm'];

    // Align every engine once; remember each engine's per-book-word match.
    // STT tokens pass through the correction map so a known mishearing aligns
    // (and later doesn't flag) against the book's spelling.
    $aligned = [];
    foreach ($engineStreams as $code => $stream) {
        $aligned[$code] = ttsQaAlignStream($bookNorm, $stream, $map);
    }

    // Pick the onset spine: operator-forced, else first present by preference.
    $onsetEngine = (string)($opts['onset_engine'] ?? '');
    if ($onsetEngine === '' || !isset($engineStreams[$onsetEngine])) {
        $onsetEngine = '';
        foreach (ttsQaOnsetPreference() as $c) if (isset($engineStreams[$c])) { $onsetEngine = $c; break; }
        if ($onsetEngine === '' && $codes) $onsetEngine = $codes[0];
    }
    $onsets = $onsetEngine !== ''
        ? ttsQaStreamOnsets($tok, $aligned[$onsetEngine], $engineStreams[$onsetEngine])
        : array_fill_keys(array_keys($tok['starts']), null);

    // ── Pages: stored marker offset vs measured speech onset ──
    $pages = [];
    $maxAbs = 0; $okN = 0; $driftN = 0; $noSttN = 0; $skippedN = 0;
    foreach ($markers as $mi => $m) {
        $stored = (int)($m['offset_ms'] ?? 0);
        $onset  = $onsets[$mi] ?? null;
        // No book tokens here = nothing independently checkable: an intentionally
        // unvoiced page (word-definition / glyph-font only) or a mid-paragraph
        // page continuation. Mark 'skipped' (quiet), not an alarming 'nostt'.
        $hasTokens = ($tok['ends'][$mi] ?? 0) > ($tok['starts'][$mi] ?? 0);
        $status = 'nostt'; $delta = null;
        if (!$hasTokens) {
            $status = 'skipped';
            $skippedN++;
        } elseif ($onset !== null) {
            $delta = $onset - $stored;
            if (abs($delta) > $maxAbs) $maxAbs = abs($delta);
            $status = (abs($delta) > $driftMs) ? 'drift' : 'ok';
            if ($status === 'ok') $okN++; else $driftN++;
        } else {
            $noSttN++;
        }
        $pages[] = [
            'paragraph_number' => (int)($m['paragraph_number'] ?? 0),
            'paragraph_page'   => isset($m['paragraph_page']) ? (int)$m['paragraph_page'] : null,
            'stored_ms'        => $stored,
            'onset_ms'         => $onset,
            'delta_ms'         => $delta,
            'status'           => $status,
            'preview'          => mb_substr(trim((string)($m['text'] ?? '')), 0, 60),
        ];
    }

    // ── Mismatches: consensus word disagreements (advisory) ──
    // Only meaningful with ≥2 engines: a lone engine's miss is almost always
    // its OWN mishearing of a proper noun (this book's Hebrew names get a
    // different wrong spelling from each engine on each call), not a TTS defect.
    // With one engine we'd just surface that engine's ~5-8% error rate as noise,
    // so we skip mismatch detection entirely and report a note. Page-timing
    // drift (the primary feature) works fine with a single engine.
    $mismatches = [];
    $mismatchNote = null;
    if ($nEng < 2) {
        $mismatchNote = 'Select 2+ engines to enable word-mismatch detection (single-engine results are just that engine\'s own STT errors).';
    } else {
        $minConsensus = max(2, (int)ceil($nEng / 2));
        $nBook = count($bookNorm);
        for ($i = 0; $i < $nBook; $i++) {
            $book = $bookNorm[$i];
            if ($book === '') continue;
            $heardCounts = [];   // normalized alt word → count
            $heardDisplay = [];  // normalized alt word → a display form
            $matched = 0; $missing = 0;
            foreach ($codes as $code) {
                $sj = $aligned[$code][$i] ?? -1;
                if ($sj < 0) { $missing++; continue; }
                $heard = ttsQaNormWord((string)($engineStreams[$code][$sj]['w'] ?? ''), $map);
                if ($heard === $book) { $matched++; continue; }
                $heardCounts[$heard] = ($heardCounts[$heard] ?? 0) + 1;
                $heardDisplay[$heard] = (string)$engineStreams[$code][$sj]['w'];
            }
            // Most-agreed divergent reading.
            $altWord = ''; $altCount = 0;
            foreach ($heardCounts as $w => $c) if ($c > $altCount) { $altCount = $c; $altWord = $w; }

            $kind = null;
            if ($altCount >= $minConsensus && $altCount >= $matched && $altWord !== '') {
                $kind = 'substituted';
            } elseif ($missing >= $minConsensus && $missing > $matched && $altCount === 0) {
                $kind = 'missing';   // ≥2 engines couldn't find this book word in the audio at all
            }
            if ($kind === null) continue;

            // Approx time: onset-engine match at i, else nearest preceding placed word.
            $atMs = null;
            if ($onsetEngine !== '') {
                for ($k = $i; $k >= 0; $k--) {
                    $sj = $aligned[$onsetEngine][$k] ?? -1;
                    if ($sj >= 0 && isset($engineStreams[$onsetEngine][$sj]['t'])) {
                        $atMs = (int)round(((float)$engineStreams[$onsetEngine][$sj]['t']) * 1000);
                        break;
                    }
                }
            }
            $mismatches[] = [
                'book_word' => $tok['raw'][$i],
                'heard'     => $kind === 'substituted' ? ($heardDisplay[$altWord] ?? $altWord) : '',
                'kind'      => $kind,
                'agree'     => $kind === 'substituted' ? $altCount : $missing,
                'engines'   => $nEng,
                'at_ms'     => $atMs,
            ];
        }
    }

    return [
        'onset_engine' => $onsetEngine,
        'engines'      => $codes,
        'pages'        => $pages,
        'mismatches'   => $mismatches,
        'summary'      => [
            'pages_checked'   => count($pages),
            'pages_ok'        => $okN,
            'pages_drift'     => $driftN,
            'pages_nostt'     => $noSttN,
            'pages_skipped'   => $skippedN,
            'max_abs_delta_ms'=> $maxAbs,
            'mismatch_count'  => count($mismatches),
            'mismatch_note'   => $mismatchNote,
            'corrections_used'=> $map ? count($map) : 0,
            'drift_threshold_ms' => $driftMs,
        ],
    ];
}
