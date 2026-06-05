<?php
/**
 * Shared helpers for the TTS admin area.
 *
 *   loadTtsConfig($db, $ttsKey)
 *     → ['system' => row, 'categories' => [cat => row], 'tunes' => [print => row], 'pauses' => [row, …]]
 *
 *   buildSsmlForText($text, $cfg, $category)
 *     → SSML string with category voice + tunes (sub-alias) + pauses (break) applied
 *
 *   azureTtsSynthesize($ssml, $cfg, &$err)
 *     → mp3 bytes (or '' on failure; sets $err)
 *
 *   azureVoiceCatalog()
 *     → ['en-US-BrianMultilingualNeural' => ['label' => 'Brian (Multilingual)', 'gender' => 'M', 'styles' => [...]], …]
 */

if (!function_exists('readEnv')) {
    // Mirror of the one in transcript-worker.php — kept here so helpers can be
    // included from non-worker contexts (preview endpoint, etc.).
    function readEnv(string $name): string {
        $val = getenv($name);
        if ($val) return $val;
        $envFile = dirname(__DIR__) . '/.env';
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, $name . '=') === 0) return trim(substr($line, strlen($name) + 1));
            }
        }
        return '';
    }
}

function loadTtsConfig(PDO $db, int $ttsKey): array {
    $sysStmt = $db->prepare("SELECT * FROM yy_tts WHERE tts_key = ?");
    $sysStmt->execute([$ttsKey]);
    $system = $sysStmt->fetch();
    if (!$system) return ['system' => null, 'categories' => [], 'tunes' => [], 'pauses' => []];

    $catStmt = $db->prepare("SELECT * FROM yy_tts_category_voice WHERE tts_key = ? AND tts_category_voice_active_flag = TRUE");
    $catStmt->execute([$ttsKey]);
    $categories = [];
    foreach ($catStmt->fetchAll() as $r) {
        $categories[$r['tts_category']] = $r;
    }

    // Tune precedence when several rows could match the same word:
    //   1. Higher tts_tune_sort first (admin's manual override).
    //   2. A rule with an explicit per-tune voice wins over one without
    //      (the user said "specific voice model version should be implemented").
    //   3. Longer Print first (so "Yahowah's" beats "Yahowah" on the
    //      possessive form even when both are unsorted).
    $tuneStmt = $db->prepare("
        SELECT *
          FROM yy_tts_tune
         WHERE tts_key = ? AND tts_tune_active_flag = TRUE
         ORDER BY COALESCE(tts_tune_sort, 0) DESC,
                  (tts_tune_voice_code IS NOT NULL) DESC,
                  length(tts_tune_print) DESC
    ");
    $tuneStmt->execute([$ttsKey]);
    $tunes = $tuneStmt->fetchAll();

    $pauseStmt = $db->prepare("SELECT * FROM yy_tts_pause WHERE tts_key = ? AND tts_pause_active_flag = TRUE ORDER BY length(tts_pause_search) DESC, tts_pause_sort");
    $pauseStmt->execute([$ttsKey]);
    $pauses = $pauseStmt->fetchAll();

    // Font filter rules — keyed by tts_font_name for fast lookup in
    // preprocessFontFilter(). Each value holds {skip, pause_ms}.
    $fontStmt = $db->prepare("SELECT tts_font_name, tts_font_skip, tts_font_pause_ms FROM yy_tts_font WHERE tts_key = ?");
    $fontStmt->execute([$ttsKey]);
    $fonts = [];
    foreach ($fontStmt->fetchAll() as $r) {
        $fonts[$r['tts_font_name']] = [
            'skip'     => !empty($r['tts_font_skip']),
            'pause_ms' => (int)$r['tts_font_pause_ms'],
        ];
    }

    // Bible book names for the citation rewriter (Yashaʿyah 8:15
    // → "Yashaʿyah Chapter 8, Verse 15"). Hebrew and common columns
    // both get loaded so Hebrew citations AND English citations match.
    $bibleStmt = $db->query("SELECT cite_book_hebrew, cite_book_common FROM yy_cite_book WHERE cite_book_hebrew IS NOT NULL");
    $bibleBooks = [];
    foreach ($bibleStmt->fetchAll() as $b) {
        if (!empty($b['cite_book_hebrew'])) $bibleBooks[] = $b['cite_book_hebrew'];
        if (!empty($b['cite_book_common'])) $bibleBooks[] = $b['cite_book_common'];
    }
    $bibleBooks = array_values(array_unique($bibleBooks));

    // Provider registry (engine catalog) + voice→provider map, so a segment's
    // category can be routed to the engine that synthesizes it. Guarded: if the
    // provider_key column / yy_provider table aren't present (pre-migration),
    // this degrades to empty maps and callers fall back to Azure (provider 1).
    $providers = [];
    $voiceProvider = [];
    try {
        foreach ($db->query("SELECT * FROM yy_provider")->fetchAll() as $pr) {
            $providers[(int)$pr['provider_key']] = $pr;
        }
        foreach ($db->query("SELECT tts_voice_code, provider_key FROM yy_tts_voice")->fetchAll() as $vr) {
            $voiceProvider[$vr['tts_voice_code']] = (int)$vr['provider_key'];
        }
    } catch (Throwable $e) {
        $providers = []; $voiceProvider = [];
    }

    return ['system' => $system, 'categories' => $categories, 'tunes' => $tunes, 'pauses' => $pauses, 'fonts' => $fonts, 'bible_books' => $bibleBooks, 'providers' => $providers, 'voice_provider' => $voiceProvider];
}

/**
 * Rewrite Bible citations from "BookName N:M" to "BookName Chapter N,
 * Verse M" so Azure speaks the numbers naturally rather than rattling
 * them off as "eight colon fifteen". Handles:
 *   "Yashaʿyah 8:15"               → "Yashaʿyah Chapter 8, Verse 15"
 *   "Yashaʿyah / Isaiah 8:15"      → "Yashaʿyah / Isaiah Chapter 8, Verse 15"
 *   "Bareʿsyth 11:18-22"           → "Bareʿsyth Chapter 11, Verses 18 to 22"
 *
 * Book name matching uses the same apostrophe-equivalent class as the
 * tune engine — "Bare'syth" in yy_cite_book matches "Bareʿsyth" with a
 * half-ring in the source text. Apostrophes in the DB name become
 * optional apos-class positions in the match.
 *
 * Runs BEFORE applyTunes so the rewritten text is still subject to
 * pronunciation tunes — including the book name itself getting its
 * own phoneme tag.
 */
function rewriteBibleCitations(string $text, array $bookNames): string {
    if (!$bookNames) return $text;
    static $aposClassOpt = "[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{02BE}\x{02BF}\x{02C0}\x{2018}\x{2019}\x{201B}\x{2032}\x{05F3}]?";
    static $aposClass    = "[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{02BE}\x{02BF}\x{02C0}\x{2018}\x{2019}\x{201B}\x{2032}\x{05F3}]";
    $patterns = [];
    foreach ($bookNames as $name) {
        $name = (string)$name;
        if ($name === '') continue;
        $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($chars as $c) {
            if (preg_match('/' . $aposClass . '/u', $c)) $out[] = $aposClassOpt;
            else                                          $out[] = preg_quote($c, '/');
        }
        $patterns[] = implode('', $out);
    }
    // Longest first so "Yashaʿyahuw" beats "Yashaʿyah" on overlap.
    usort($patterns, function ($a, $b) { return strlen($b) - strlen($a); });
    $bookAlt = implode('|', $patterns);
    // Citation pattern: <book><optional " / English-name "><chapter>:<verse>[-<endverse>]
    $re = '/(?<![A-Za-z])(' . $bookAlt . ')(\s*(?:\/\s*[A-Za-z][A-Za-z\s]+?\s+)?)(\d+):(\d+)(?:-(\d+))?(?![\d:])/iu';
    return preg_replace_callback($re, function ($m) {
        $book   = $m[1];
        $sep    = rtrim($m[2]) === '' ? ' ' : $m[2];
        $chap   = (int)$m[3];
        $vFrom  = (int)$m[4];
        $vTo    = isset($m[5]) ? (int)$m[5] : 0;
        $verses = $vTo > 0 ? "Verses $vFrom to $vTo" : "Verse $vFrom";
        return $book . rtrim($sep) . " Chapter $chap, $verses";
    }, $text);
}

/**
 * Apply pause substring replacements. Pauses use a placeholder token so the
 * pause stays intact after XML escaping; the placeholder is rewritten back
 * to the SSML <break> tag after escaping.
 */
function applyPauses(string $text, array $pauses, string &$placeholder): string {
    foreach ($pauses as $p) {
        $needle = $p['tts_pause_search'];
        $ms = (int)$p['tts_pause_ms'];
        $token = sprintf("\x01PAUSE_%d_%d\x01", $p['tts_pause_key'], $ms);
        $text = str_replace($needle, $token, $text);
    }
    return $text;
}

/**
 * Walk paragraph_text_html and apply per-font Skip / Pause-on-switch
 * rules from $fonts (keyed by font name). For each <span data-font="X">…</span>:
 *   - If rule says skip → drop the contents entirely.
 *   - If rule says pause_ms > 0 → emit a pause placeholder (same shape
 *     as the yy_tts_pause placeholders so placeholdersToBreaks rewrites
 *     it into <break time="Nms"/> downstream).
 * The <span data-font> tag itself is always stripped — only its content
 * is conditionally kept / dropped. <b>/<i> tags pass through untouched
 * so the existing segmentParagraph + category-voice routing still works.
 */
function preprocessFontFilter(string $html, array $fonts): string {
    if (!$fonts) {
        // No rules configured — still strip data-font spans so they
        // don't leak into segmentParagraph.
        return preg_replace('/<\/?span\b[^>]*>/i', '', $html);
    }
    $out = '';
    $i = 0; $n = strlen($html);
    // Two parallel stacks pushed on every open <span> and popped on
    // </span>. $stack tracks the data-font (or empty sentinel) so
    // fontFilterEmit can decide whether to drop text. $styleStack
    // tracks the data-style attr ('kjv'/'nas'/…/null) so the close
    // tag knows whether to emit a closing Bible-style surrogate.
    $stack = [];
    $styleStack = [];
    while ($i < $n) {
        $lt = strpos($html, '<', $i);
        if ($lt === false) {
            // Trailing literal text.
            $out .= fontFilterEmit(substr($html, $i), $stack, $fonts);
            break;
        }
        if ($lt > $i) {
            $out .= fontFilterEmit(substr($html, $i, $lt - $i), $stack, $fonts);
        }
        $gt = strpos($html, '>', $lt);
        if ($gt === false) break;
        $tag = substr($html, $lt, $gt - $lt + 1);
        $low = strtolower($tag);
        if (strpos($low, '<span') === 0) {
            // Capture data-font value if present, else empty sentinel.
            if (preg_match('/data-font="([^"]*)"/', $tag, $m)) {
                $font = $m[1];
                // Pause-on-transition: emit a placeholder BEFORE the span
                // content if this font has a pause_ms set.
                if (!empty($fonts[$font]) && (int)$fonts[$font]['pause_ms'] > 0) {
                    $ms = (int)$fonts[$font]['pause_ms'];
                    $out .= sprintf("\x01PAUSE_%d_%d\x01", 99000 + crc32($font) % 1000, $ms);
                }
                $stack[] = $font;
            } else {
                $stack[] = '';
            }
            // Bible-style surrogate: emit a bare-tagged <bib-kjv> the
            // segmenter recognises, and push the style so the matching
            // </span> knows what closer to emit.
            $sty = null;
            if (preg_match('/data-style="([a-z]+)"/i', $tag, $sm)) {
                $sty = strtolower($sm[1]);
                $out .= '<bib-' . $sty . '>';
            }
            $styleStack[] = $sty;
        } elseif (strpos($low, '</span') === 0) {
            if ($styleStack) {
                $sty = array_pop($styleStack);
                if ($sty) $out .= '</bib-' . $sty . '>';
            }
            if ($stack) array_pop($stack);
        } else {
            // Any other tag (b, i, etc.) — pass through.
            $out .= $tag;
        }
        $i = $gt + 1;
    }
    return $out;
}

/**
 * Emit (or suppress) literal text based on the current font stack.
 * If the innermost data-font on the stack is marked skip, the text is
 * dropped; otherwise it passes through unchanged.
 */
function fontFilterEmit(string $text, array $stack, array $fonts): string {
    // Find the innermost non-empty font on the stack — that's the
    // active font for this text run.
    for ($j = count($stack) - 1; $j >= 0; $j--) {
        $f = $stack[$j];
        if ($f === '') continue;
        if (!empty($fonts[$f]) && !empty($fonts[$f]['skip'])) return '';
        break; // Found innermost real font; not skipped → emit text.
    }
    return $text;
}

/**
 * Rebuild the volume-level mp3.zip after a chapter audio finishes.
 *
 * Finds every chapter audio file for the given volume (matching the
 * "{volume_code}-ch{NN}.{ext}" naming convention written by the build
 * worker), bundles them into "{volume_code}.mp3.zip", and writes that
 * sibling next to the chapter files. Existing zip is overwritten, so
 * regenerated or replaced chapters always reflect the latest audio.
 *
 * Filenames inside the zip drop the volume prefix (just "ch02.mp3") so
 * the archive is tidy when unpacked.
 *
 * Safe to call repeatedly; cheap when only one chapter exists.
 */
function rebuildVolumeMp3Zip(PDO $db, int $volumeKey): bool {
    $stmt = $db->prepare("SELECT volume_code FROM yy_volume WHERE volume_key = ?");
    $stmt->execute([$volumeKey]);
    $slug = (string)($stmt->fetchColumn() ?: '');
    if ($slug === '') return false;

    // Same dual-path logic the build worker uses for the audio dir.
    $hostDir      = '/opt/yada-www/public/u/tts-audio';
    $containerDir = dirname(__DIR__) . '/u/tts-audio';
    $dir = is_dir($containerDir) ? $containerDir : $hostDir;
    if (!is_dir($dir)) return false;

    $files = glob($dir . '/' . $slug . '-ch*.{mp3,opus,wav}', GLOB_BRACE) ?: [];
    if (!$files) return false;
    sort($files);

    $zipPath = $dir . '/' . $slug . '.mp3.zip';

    // Keep filenames intact ({volume_code}-ch{NN}.{ext}) inside the zip
    // so someone unzipping multiple books in one directory ends up with
    // each chapter clearly tagged to its volume. Container PHP doesn't
    // ship ext-zip, so we shell out to /usr/bin/zip with -j (junk paths)
    // since the source files all live in the same directory anyway.
    $tmpZip = $dir . '/.tmp_' . basename($zipPath) . '.' . bin2hex(random_bytes(4));
    $cmd = 'zip -q -j ' . escapeshellarg($tmpZip);
    foreach ($files as $f) $cmd .= ' ' . escapeshellarg($f);
    $cmd .= ' 2>&1';
    $out = []; $rc = 0;
    exec($cmd, $out, $rc);
    if ($rc !== 0 || !is_file($tmpZip)) {
        @unlink($tmpZip);
        return false;
    }
    $ok = @rename($tmpZip, $zipPath);
    if (!$ok) {
        $ok = @copy($tmpZip, $zipPath);
        @unlink($tmpZip);
    }
    return $ok;
}

function placeholdersToBreaks(string $escaped): string {
    // ms > 0 → <break time="Nms"/>  (add the requested pause)
    // ms = 0 → emit NOTHING. We tried <break strength="none"/> here, but
    //          Azure treats every <break> tag — even strength=none — as
    //          a prosody-resetting hint, which produced an audible pause
    //          right where the user was trying to suppress one (most
    //          obviously around half-ring modifiers like ʿ ʾ inside a
    //          word, e.g. "ʾAbraham", "Bareʿsyth"). Dropping the marker
    //          entirely lets the surrounding letters run together cleanly.
    return preg_replace_callback('/\x01PAUSE_(\d+)_(\d+)\x01/', function ($m) {
        $ms = (int)$m[2];
        if ($ms > 0) return '<break time="' . $ms . 'ms"/>';
        return '';
    }, $escaped);
}

/**
 * Apply pronunciation substitutions. 'sub' type → <sub alias="phonetic">print</sub>,
 * unless the alias contains an ALL-CAPS run (2+ letters), in which case we
 * split the alias and wrap each caps run in <emphasis level="strong"> so
 * the engine actually stresses that syllable. "Yah-HOH-wah" → "Yah-" +
 * <emphasis>hoh</emphasis> + "-wah". 'ipa' / 'sapi' → <phoneme>.
 *
 * Uses a token round-trip so SSML markup isn't double-escaped.
 */
function applyTunes(string $text, array $tunes, array &$tokenMap): string {
    foreach ($tunes as $t) {
        $print = $t['tts_tune_print'];
        if ($print === '') continue;
        // Per-rule restrictions: the rule only fires inside <b> and/or
        // <i> contexts when these are set. Implemented as a tag-aware
        // walker because the substitution target is the same text in
        // both cases; we just need to skip non-qualifying regions.
        $needsBold      = !empty($t['tts_tune_match_bold']);
        $needsItalic    = !empty($t['tts_tune_match_italic']);
        $caseSensitive  = !empty($t['tts_tune_match_case_sensitive']);
        // Each row now stores three independent phonetic representations
        // (sub / ipa / sapi). The phonetic_type column picks which one is
        // live. Fall back to the legacy tts_tune_phonetic mirror so this
        // still works on rows that haven't been re-saved since the
        // multi-column migration.
        $type = $t['tts_tune_phonetic_type'] ?? 'sub';
        // Azure neural voices reject the 'sapi' phoneme alphabet (legacy,
        // non-neural voices only). Treat type=sapi as a *reference* setting
        // — at synth time we transparently use the IPA column instead.
        $synthType = ($type === 'sapi') ? 'ipa' : $type;
        $col  = 'tts_tune_phonetic_' . $synthType;
        $phon = trim((string)($t[$col] ?? ''));
        // If IPA is empty OR looks like obvious English-spelling rather
        // than IPA, fall back to SUB so we don't 400 Azure. Heuristic:
        // pure ASCII letters with no IPA-specific symbols (no ˈ ˌ ɑ ɛ ɪ
        // ʊ ɔ ʃ θ ð ʒ ŋ ɹ ʔ ʕ etc.) is almost certainly not real IPA.
        if ($synthType === 'ipa' && ($phon === '' || ipaLooksFake($phon))) {
            // Bad/missing IPA → try SUB. Skip the legacy-mirror fallback
            // here because for IPA-typed bulk-imported rows the mirror
            // *is* the same bad IPA, which would just round-trip the
            // problem back into the regex. SUB-typed rules still get
            // the mirror fallback below.
            $synthType = 'sub';
            $phon = trim((string)($t['tts_tune_phonetic_sub'] ?? ''));
            if ($phon === '') continue; // no usable phonetic — skip rule
        } else {
            if ($phon === '') $phon = (string)($t['tts_tune_phonetic'] ?? '');
            if ($phon === '') continue; // nothing to substitute with — skip rule
        }
        $regex = tunePrintToRegex($print, $caseSensitive);
        if (!preg_match($regex, $text)) continue;
        $token   = sprintf("\x02TUNE_%d\x02",  $t['tts_tune_key']);
        $tokenS  = sprintf("\x02TUNEs%d\x02", $t['tts_tune_key']); // possessive variant
        if ($synthType === 'ipa') {
            // Common typos: ASCII apostrophe/backtick instead of the IPA
            // primary stress mark ˈ (U+02C8); hyphen instead of the IPA
            // syllable-boundary marker . (period). Azure rejects hyphens
            // in ph="" and returns HTTP 400 with an empty body.
            $phon = strtr($phon, ["'" => "\u{02C8}", '`' => "\u{02C8}"]);
            $phon = str_replace('-', '.', $phon);
            $ph = htmlspecialchars($phon, ENT_QUOTES | ENT_XML1);
            $printEsc = htmlspecialchars($print, ENT_QUOTES | ENT_XML1);
            $repl  = "<phoneme alphabet=\"ipa\" ph=\"$ph\">$printEsc</phoneme>";
            // Possessive variant: append /z/ to the IPA, append "'s" to
            // the surface print so lipsync / display stays sensible.
            $phPoss = htmlspecialchars($phon . 'z', ENT_QUOTES | ENT_XML1);
            $replS = "<phoneme alphabet=\"ipa\" ph=\"$phPoss\">" . $printEsc . "&#39;s</phoneme>";
        } else {
            $repl  = buildSubReplSsml($print, $phon);
            // Possessive variant in SUB: append plain "s" so the alias
            // reads "Tor-ahs" as one continuous word instead of getting
            // broken up at the apostrophe.
            $replS = buildSubReplSsml($print . "'s", $phon . 's');
        }
        // Per-tune voice overrides (tts_tune_voice_code) are intentionally
        // NOT emitted as nested <voice> elements. Azure's synthesize REST
        // API rejects nested <voice> inside <voice> (and especially inside
        // <mstts:express-as>) with HTTP 400. The phoneme/sub pronunciation
        // correction from the tune is still applied; only the voice switch
        // is suppressed. A proper implementation would split the text into
        // sibling <voice> blocks at the <speak> level.
        $tokenMap[$token]  = $repl;
        $tokenMap[$tokenS] = $replS;
        // preg_replace_callback so we can choose between the plain and
        // possessive token based on whether match group 2 captured the
        // trailing 's. Group 1 is the print word, group 2 the optional 's.
        $cb = function($m) use ($token, $tokenS) {
            return !empty($m[2]) ? $tokenS : $token;
        };
        if ($needsBold || $needsItalic) {
            $text = applyTuneInTaggedRegions($text, $regex, $cb, $needsBold, $needsItalic);
        } else {
            $text = preg_replace_callback($regex, $cb, $text);
        }
    }
    return $text;
}

/**
 * Substitute matches of $regex with $token only inside text regions
 * that are currently nested under <b> (if $needsBold) and/or <i> (if
 * $needsItalic) tags. Walks the text once, tracking tag depth, and
 * applies preg_replace per qualifying text run. Tag tokens themselves
 * pass through unchanged. Words that straddle a tag boundary (e.g.
 * "Yah</b>owah") are intentionally not matched — substitution stays
 * within a single text run for predictability.
 */
function applyTuneInTaggedRegions(string $text, string $regex, $replacement, bool $needsBold, bool $needsItalic): string {
    $bold = 0; $italic = 0;
    $out = '';
    if (!preg_match_all('/<\/?[bi]\b[^>]*>|[^<]+/i', $text, $m)) return $text;
    foreach ($m[0] as $piece) {
        if ($piece[0] === '<') {
            // Tag — update depth state, emit unchanged.
            $low = strtolower($piece);
            if    (strpos($low, '<b')  === 0 && $low[1] !== '/') $bold++;
            elseif (strpos($low, '</b') === 0) $bold = max(0, $bold - 1);
            elseif (strpos($low, '<i')  === 0 && $low[1] !== '/') $italic++;
            elseif (strpos($low, '</i') === 0) $italic = max(0, $italic - 1);
            $out .= $piece;
        } else {
            // Text run — substitute only if current state qualifies.
            $qual = (!$needsBold || $bold > 0) && (!$needsItalic || $italic > 0);
            if ($qual) {
                $out .= is_callable($replacement)
                    ? preg_replace_callback($regex, $replacement, $piece)
                    : preg_replace($regex, $replacement, $piece);
            } else {
                $out .= $piece;
            }
        }
    }
    return $out;
}

/**
 * Build a regex that matches the Print field against the source text,
 * treating every single-quote-like or half-ring character as equivalent
 * AND optional. So a Print of "Miqra'ey" matches all of:
 *   Miqra'ey   Miqra’ey   Miqraʾey   Miqra`ey   ...    (any variant)
 *   Miqraey                                            (no apostrophe at all)
 * And a Print of "Miqraey" (no apostrophe) likewise matches "Miqra'ey"
 * — the apostrophe is optional in both directions.
 *
 * Set of equivalent chars covers the most common variants seen in
 * Hebrew / Greek transliterations and English typographic apostrophes:
 *   U+0027 '   ASCII apostrophe
 *   U+0060 `   grave / backtick
 *   U+00B4 ´   acute accent
 *   U+02BC ʼ   modifier letter apostrophe
 *   U+02BE ʾ   modifier letter right half ring (aleph)
 *   U+02BF ʿ   modifier letter left half ring  (ayin)
 *   U+02C0 ʔ   modifier letter glottal stop
 *   U+2018 ‘   left single curly quote
 *   U+2019 ’   right single curly quote
 *   U+201B ‛   high reversed-9 quotation
 *   U+2032 ′   prime
 *   U+05F3 ׳   Hebrew geresh
 *
 * Strategy: strip every apostrophe-class char from Print to get its
 * "core letters", then insert an optional apostrophe-class between every
 * pair of cores. The result matches the cores joined by any number of
 * apostrophe-class chars (0 or 1) at every join point.
 */
/**
 * Detect IPA values that Azure's parser would reject and that should
 * therefore fall back to SUB at synth time.
 *
 * Only checks for characters Azure refuses outright — digits, ASCII
 * capitals, and structural punctuation. Plain ASCII lowercase letters
 * like e, a, b, r are valid IPA symbols on their own (close-mid front,
 * open front, voiced bilabial plosive, alveolar trill) so we DON'T
 * reject pure-ASCII-lowercase strings. Mistyped English in the IPA
 * field still gets pronounced — usually harmlessly — rather than
 * silently falling back.
 */
function ipaLooksFake(string $phon): bool {
    if ($phon === '') return true;
    if (preg_match('/[0-9A-Z%=<>"\\\\\\/\\[\\]{}()@#&*+?]/u', $phon)) return true;
    return false;
}

function tunePrintToRegex(string $print, bool $caseSensitive = false): string {
    static $APOS_RE       = '/[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{02BE}\x{02BF}\x{02C0}\x{2018}\x{2019}\x{201B}\x{2032}\x{05F3}]/u';
    static $APOS_CLASS_OPT = "[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{02BE}\x{02BF}\x{02C0}\x{2018}\x{2019}\x{201B}\x{2032}\x{05F3}]?";
    // Apostrophe-class for matching the leading apostrophe of a
    // possessive 's — same set as above but required (not optional).
    static $APOS_CLASS     = "[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{02BE}\x{02BF}\x{02C0}\x{2018}\x{2019}\x{201B}\x{2032}\x{05F3}]";
    // 1) Drop apostrophe-class chars to get the core spelling.
    $core = preg_replace($APOS_RE, '', $print);
    $flags = $caseSensitive ? 'u' : 'iu';
    // Possessive 's tail: captured separately so applyTunes can detect
    // when the match swallowed an "'s" and append the right sound to
    // the substituted form. Optional, so plain matches still work.
    $possessiveTail = '(' . $APOS_CLASS . 's)?';
    if ($core === '' || $core === null) {
        // Degenerate: Print was entirely apostrophes. Fall back to literal match.
        return '/(?<![A-Za-z])(' . preg_quote($print, '/') . ')' . $possessiveTail . '(?![A-Za-z])/' . $flags;
    }
    // 2) Detect whether Print has leading / trailing apostrophe-class
    //    characters. If so, REQUIRE one (any of the equivalents) in
    //    the matched text — that's how Print "bowʾ" stays distinct
    //    from Print "bow". The two rules then route to different
    //    pronunciations (`boːʔ` vs `baʊ`). Print "Adam" (no leading
    //    apos) still won't match source "ʾAdam".
    // 3) Split the core letters, escape each, join with an optional
    //    apostrophe-class so internal half-rings between letters
    //    still flex (e.g. Print "Yahowahs" matches source "Yahowah's").
    // 4) Whole-word lookarounds reject both ASCII letters and any
    //    apostrophe-class char on either side, so adjacent half-rings
    //    in the source can't sneak the match through.
    // 5) /iu — case-insensitive Unicode; "Yahowah" matches "yahowah" too.
    $needsLeadingApos  = (bool)preg_match($APOS_RE, mb_substr($print, 0, 1));
    $needsTrailingApos = (bool)preg_match($APOS_RE, mb_substr($print, -1));
    $chars = preg_split('//u', $core, -1, PREG_SPLIT_NO_EMPTY);
    $escaped = array_map(function($c) { return preg_quote($c, '/'); }, $chars);
    $aposClassNoSlash = "[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{02BE}\x{02BF}\x{02C0}\x{2018}\x{2019}\x{201B}\x{2032}\x{05F3}]";
    $body = ($needsLeadingApos ? $aposClassNoSlash : '')
          . implode($APOS_CLASS_OPT, $escaped)
          . ($needsTrailingApos ? $aposClassNoSlash : '');
    return '/(?<![A-Za-z]|' . $aposClassNoSlash . ')(' . $body . ')' . $possessiveTail . '(?![A-Za-z]|' . $aposClassNoSlash . ')/' . $flags;
}

/**
 * Build the SSML for a 'sub'-type tune. Three optional in-text markers:
 *   ALL CAPS  → wrap that run in <prosody pitch="+25%" rate="92%">  (stress)
 *   [word]    → wrap "word" in <prosody rate="80%">                  (slow / drawn out)
 *   {word}    → wrap "word" in <prosody rate="130%">                 (fast / clipped)
 * Caps inside brackets/braces still trigger their own nested emphasis
 * prosody (Azure accepts nested <prosody>). When the alias contains
 * none of these markers we fall back to plain <sub alias="…">print</sub>
 * so the historical rules keep working unchanged.
 */
function buildSubReplSsml(string $print, string $alias): string {
    $printEsc = htmlspecialchars($print, ENT_QUOTES | ENT_XML1);
    if (!preg_match('/[A-Z]{2,}|[\[\]{}]/', $alias)) {
        $aliasEsc = htmlspecialchars($alias, ENT_QUOTES | ENT_XML1);
        return "<sub alias=\"$aliasEsc\">$printEsc</sub>";
    }
    return renderSubAlias($alias);
}

/**
 * Walk the alias once, splitting on [...] (slow) and {...} (fast) groups.
 * Plain runs and inner runs both get the CAPS-emphasis transform applied
 * by renderCapsEmphasis(). Bracketed groups are wrapped in a <prosody>
 * rate envelope around their (already emphasis-processed) content.
 */
function renderSubAlias(string $alias): string {
    $out = '';
    $offset = 0;
    $len = strlen($alias);
    while ($offset < $len) {
        if (preg_match('/(\[([^\]]*)\])|(\{([^}]*)\})/', $alias, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $m[0][1];
            if ($matchStart > $offset) {
                $out .= renderCapsEmphasis(substr($alias, $offset, $matchStart - $offset));
            }
            if ($m[1][0] !== '') {
                // [...] = slow
                $out .= '<prosody rate="80%">' . renderCapsEmphasis($m[2][0]) . '</prosody>';
            } else {
                // {...} = fast
                $out .= '<prosody rate="130%">' . renderCapsEmphasis($m[4][0]) . '</prosody>';
            }
            $offset = $matchStart + strlen($m[0][0]);
        } else {
            $out .= renderCapsEmphasis(substr($alias, $offset));
            break;
        }
    }
    return $out;
}

function renderCapsEmphasis(string $s): string {
    if (!preg_match('/[A-Z]{2,}/', $s)) {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
    }
    $parts = preg_split('/([A-Z]{2,})/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $piece) {
        if ($piece === '') continue;
        if (preg_match('/^[A-Z]{2,}$/', $piece)) {
            $emph = htmlspecialchars(strtolower($piece), ENT_QUOTES | ENT_XML1);
            $out .= '<prosody pitch="+25%" rate="92%">' . $emph . '</prosody>';
        } else {
            $out .= htmlspecialchars($piece, ENT_QUOTES | ENT_XML1);
        }
    }
    return $out;
}

function tokensToSsml(string $escaped, array $tokenMap): string {
    if (!$tokenMap) return $escaped;
    return strtr($escaped, $tokenMap);
}

/**
 * Build SSML for a single (text, category) pair. The text is run through:
 *   1. pause replacement (substring → placeholder)
 *   2. tune replacement  (substring → placeholder)
 *   3. XML escape
 *   4. placeholder → SSML tag
 * Wrapped in a <voice> element with prosody from the category config.
 */
function buildVoiceBlock(string $text, array $cfg, string $category, ?string $overrideVoice = null): string {
    // Resolve the category through the parent chain so segments tagged
    // with a source-specific category (kjv, bukhari, esv, …) route to
    // their generic parent voice — and ultimately to 'main' — when the
    // admin hasn't configured a dedicated voice. Parent relationships
    // are declared in ttsCategories(); this loop just walks them.
    $cat = $cfg['categories'][$category] ?? null;
    $cur = $category;
    while ($cat === null && $cur !== null) {
        $cur = ttsCategoryParent($cur);
        if ($cur !== null) $cat = $cfg['categories'][$cur] ?? null;
    }
    // Ultimate fallback: if even 'main' has no row, use whatever the
    // category catalog happens to define first; if that's also missing
    // the voiceCode default below picks up the hardcoded Brian voice.
    if ($cat === null && !empty($cfg['categories'])) {
        $cat = reset($cfg['categories']);
    }
    $voiceCode = $overrideVoice ?: ($cat['tts_voice_code'] ?? 'en-US-BrianMultilingualNeural');

    // Bible-citation rewriter runs first so the book name + chapter:verse
    // pattern is expanded BEFORE the book name gets swapped for a phoneme
    // token by applyTunes. After expansion, applyTunes can still match
    // the book name normally; the appended "Chapter N, Verse M" is plain
    // text that Azure reads naturally.
    if (!empty($cfg['bible_books'])) {
        $text = rewriteBibleCitations($text, $cfg['bible_books']);
    }
    // Apply tunes BEFORE pauses so tune regexes see the raw half-ring
    // and apostrophe-equivalent characters in the source. If pauses
    // ran first, "ʾ" (0 ms suppression pause) would have been swapped
    // for a placeholder token by the time applyTunes evaluated, and
    // a tune Print like "bowʾ" would fail to match its own half-ring.
    // Pauses still apply to any apostrophe-class chars that no tune
    // consumes — the leftover ones get the 0 ms suppression treatment.
    $tokenMap = [];
    // Resolve tunes for this segment's engine: a word's provider-specific
    // override (if any) wins over its default (provider_key=0). With only
    // default rows present this is exactly $cfg['tunes'], so Azure output is
    // unchanged.
    $segProviderKey = ttsResolveProviderKey($cfg, $category);
    $text = applyTunes($text, ttsTunesForProvider($cfg, $segProviderKey), $tokenMap);
    $placeholder = '';
    $text = applyPauses($text, $cfg['pauses'], $placeholder);
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $escaped = placeholdersToBreaks($escaped);
    $escaped = tokensToSsml($escaped, $tokenMap);

    // Wrap any contiguous Hebrew-script run in <lang xml:lang="he-IL">
    // so an outer multilingual voice (en-US-*Multilingual*) switches to
    // Hebrew pronunciation for those characters. The <lang> tag is a
    // no-op on monolingual voices but doesn't break them, so it's safe
    // to emit unconditionally. Half-ring modifiers (ʿ ʾ) are configured
    // as 0 ms pauses upstream and have already been dropped by this
    // point, so they don't get pulled into the wrap.
    if (preg_match('/[\x{0590}-\x{05FF}]/u', $escaped)) {
        $escaped = preg_replace(
            '/[\x{0590}-\x{05FF}][\x{0590}-\x{05FF}\s]*[\x{0590}-\x{05FF}]|[\x{0590}-\x{05FF}]/u',
            '<lang xml:lang="he-IL">$0</lang>',
            $escaped
        );
    }

    $inner = $escaped;
    if ($cat) {
        $rate   = (int)$cat['tts_voice_rate_pct'];
        $pitch  = (float)$cat['tts_voice_pitch_st'];
        $volume = (int)$cat['tts_voice_volume'];
        $prosodyAttrs = [];
        if ($rate   !== 0)   $prosodyAttrs[] = 'rate="'   . ($rate >= 0 ? "+$rate%" : "$rate%") . '"';
        if (abs($pitch) > 0.005) {
            // Trim trailing zeros so "1.50" emits "1.5", "2.00" emits "2".
            $pitchStr = rtrim(rtrim(number_format($pitch, 2, '.', ''), '0'), '.');
            $prosodyAttrs[] = 'pitch="' . ($pitch >= 0 ? "+{$pitchStr}st" : "{$pitchStr}st") . '"';
        }
        if ($volume !== 100) $prosodyAttrs[] = 'volume="' . $volume . '"';
        if ($prosodyAttrs) {
            $inner = '<prosody ' . implode(' ', $prosodyAttrs) . '>' . $inner . '</prosody>';
        }
        if (!empty($cat['tts_voice_style'])
            && $cat['tts_voice_style'] !== 'general'   // UI sentinel meaning "no style"
            && stripos($voiceCode, 'Multilingual') === false) {
            // Azure Multilingual neural voices don't support mstts:express-as styles.
            $style       = htmlspecialchars($cat['tts_voice_style'], ENT_QUOTES | ENT_XML1);
            $styleDegree = htmlspecialchars((string)($cat['tts_voice_style_degree'] ?? '1.0'), ENT_QUOTES | ENT_XML1);
            $inner = "<mstts:express-as style=\"$style\" styledegree=\"$styleDegree\">$inner</mstts:express-as>";
        }
    }
    $voiceCodeEsc = htmlspecialchars($voiceCode, ENT_QUOTES | ENT_XML1);
    return "<voice name=\"$voiceCodeEsc\">$inner</voice>";
}

function wrapSsml(string $voiceBlocks): string {
    return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="http://www.w3.org/2001/mstts" xml:lang="en-US">'
        . $voiceBlocks
        . '</speak>';
}

function azureTtsSynthesize(string $ssml, array $cfg, ?string &$err = null): string {
    $key = readEnv('AZURE_SPEECH_KEY');
    if (!$key) { $err = 'AZURE_SPEECH_KEY not set'; return ''; }
    $region = $cfg['system']['tts_region'] ?? (readEnv('AZURE_SPEECH_REGION') ?: 'brazilsouth');
    $format = $cfg['system']['tts_output_format'] ?? 'audio-24khz-48kbitrate-mono-mp3';

    $ch = curl_init("https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Ocp-Apim-Subscription-Key: ' . $key,
            'Content-Type: application/ssml+xml',
            'X-Microsoft-OutputFormat: ' . $format,
            'User-Agent: yada-tts',
        ],
        CURLOPT_POSTFIELDS     => $ssml,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        $body = $cerr ?: trim((string)$resp);
        if ($body === '') $body = '[empty response – voice may not support requested style or region]';
        else $body = substr($body, 0, 300);
        $err = "Azure TTS HTTP $code: $body | SSML: " . substr($ssml, 0, 400);
        return '';
    }
    return (string)$resp;
}

/**
 * Static catalog of Azure neural voices we expose in the UI. Curated rather
 * than fetched from /voices/list so the admin sees a sensible subset (~30
 * English, plus key Hebrew/Greek/Arabic for scripture-quote categories).
 * Add/remove entries as needed.
 */
// DB-backed voice catalog. Reads active rows from yy_tts_voice and
// normalises them to the shape the admin UI + worker have always
// expected (code/label/lang/gender/styles). Falls back to the
// hardcoded list below if the table is empty (fresh install / dev
// without seed) so nothing breaks when the DB isn't set up.
function azureVoiceCatalog(?PDO $db = null, bool $includeInactive = false): array {
    if ($db === null) { try { $db = getDb(); } catch (Throwable $e) { $db = null; } }
    if ($db) {
        try {
            $sql = "SELECT tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_language, tts_voice_region, tts_voice_gender, tts_voice_styles, tts_voice_secondary_locales, tts_voice_active_flag
                      FROM yy_tts_voice";
            if (!$includeInactive) $sql .= " WHERE tts_voice_active_flag = TRUE";
            // Sort by language first, then region — keeps en-* together
            // (US then GB), then he, el, ar, etc.
            $sql .= " ORDER BY tts_voice_language, tts_voice_region, tts_voice_gender DESC, tts_voice_label";
            $rows = $db->query($sql)->fetchAll();
            if ($rows) {
                $out = [];
                foreach ($rows as $r) {
                    $styles = json_decode((string)$r['tts_voice_styles'], true);
                    if (!is_array($styles)) $styles = [];
                    $secondary = json_decode((string)$r['tts_voice_secondary_locales'], true);
                    $isMulti = is_array($secondary) && !empty($secondary);
                    $g = strtoupper(substr((string)($r['tts_voice_gender'] ?? ''), 0, 1)) ?: 'N';
                    $out[] = [
                        'code'         => $r['tts_voice_code'],
                        'label'        => $r['tts_voice_label'],
                        // Keep `lang` (full locale) for back-compat with
                        // existing JS callers, and expose the two split
                        // fields for filter/sort UIs that want to group
                        // by language alone or region alone.
                        'lang'         => $r['tts_voice_locale'],
                        'language'     => $r['tts_voice_language'],
                        'region'       => $r['tts_voice_region'],
                        'gender'       => $g,
                        'styles'       => $styles,
                        'multilingual' => $isMulti,
                        'active'       => !empty($r['tts_voice_active_flag']),
                    ];
                }
                return $out;
            }
        } catch (Throwable $e) {
            // Schema missing / other DB issue — fall through to the
            // hardcoded fallback so the UI is still usable.
        }
    }
    return azureVoiceCatalogFallback();
}

// Last-resort hardcoded catalog. Mirrors the seed in yy_tts_voice so a
// freshly-cloned env without DB migration still gets a working list of
// voices. Edit BOTH places when adding/removing voices long-term.
function azureVoiceCatalogFallback(): array {
    return [
        // ── American English — male, narration-style ──
        ['code' => 'en-US-BrianMultilingualNeural',    'label' => 'Brian (Multilingual, authoritative male, US)',     'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-AndrewMultilingualNeural',   'label' => 'Andrew (Multilingual, warm male, US)',             'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-DavisNeural',                'label' => 'Davis (deep male, US)',                            'lang' => 'en-US', 'gender' => 'M', 'styles' => ['chat', 'angry', 'cheerful', 'excited', 'friendly', 'hopeful', 'sad', 'shouting', 'terrified', 'unfriendly', 'whispering']],
        ['code' => 'en-US-TonyNeural',                 'label' => 'Tony (gravelly male, US)',                         'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-RogerNeural',                'label' => 'Roger (older male, US)',                           'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-SteffanNeural',              'label' => 'Steffan (resonant male, US)',                      'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-ChristopherNeural',          'label' => 'Christopher (mature male, US)',                    'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-GuyNeural',                  'label' => 'Guy (newscaster-style male, US)',                  'lang' => 'en-US', 'gender' => 'M', 'styles' => ['newscast']],
        ['code' => 'en-US-JasonNeural',                'label' => 'Jason (younger male, US)',                         'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-EricNeural',                 'label' => 'Eric (clear male, US)',                            'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],

        // ── American English — female ──
        ['code' => 'en-US-EmmaMultilingualNeural',     'label' => 'Emma (Multilingual female, US)',                   'lang' => 'en-US', 'gender' => 'F', 'styles' => ['general']],
        ['code' => 'en-US-AvaMultilingualNeural',      'label' => 'Ava (Multilingual female, US)',                    'lang' => 'en-US', 'gender' => 'F', 'styles' => ['general']],
        ['code' => 'en-US-JennyMultilingualNeural',    'label' => 'Jenny (Multilingual female, US)',                  'lang' => 'en-US', 'gender' => 'F', 'styles' => ['chat', 'newscast', 'friendly', 'assistant']],
        ['code' => 'en-US-AriaNeural',                 'label' => 'Aria (newscast female, US)',                       'lang' => 'en-US', 'gender' => 'F', 'styles' => ['newscast', 'chat', 'friendly']],
        ['code' => 'en-US-NancyNeural',                'label' => 'Nancy (clear female, US)',                         'lang' => 'en-US', 'gender' => 'F', 'styles' => ['general']],
        ['code' => 'en-US-SaraNeural',                 'label' => 'Sara (friendly female, US)',                       'lang' => 'en-US', 'gender' => 'F', 'styles' => ['general']],

        // ── UK English — male/female ──
        ['code' => 'en-GB-RyanNeural',                 'label' => 'Ryan (male, UK)',                                  'lang' => 'en-GB', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-GB-ThomasNeural',               'label' => 'Thomas (male, UK)',                                'lang' => 'en-GB', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-GB-SoniaNeural',                'label' => 'Sonia (female, UK)',                               'lang' => 'en-GB', 'gender' => 'F', 'styles' => ['general']],

        // ── Hebrew ──
        ['code' => 'he-IL-AvriNeural',                 'label' => 'Avri (male, Hebrew)',                              'lang' => 'he-IL', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'he-IL-HilaNeural',                 'label' => 'Hila (female, Hebrew)',                            'lang' => 'he-IL', 'gender' => 'F', 'styles' => ['general']],

        // ── Greek ──
        ['code' => 'el-GR-NestorasNeural',             'label' => 'Nestoras (male, Greek)',                           'lang' => 'el-GR', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'el-GR-AthinaNeural',               'label' => 'Athina (female, Greek)',                           'lang' => 'el-GR', 'gender' => 'F', 'styles' => ['general']],

        // ── Arabic (MSA) ──
        ['code' => 'ar-SA-HamedNeural',                'label' => 'Hamed (male, Arabic MSA)',                         'lang' => 'ar-SA', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'ar-SA-ZariyahNeural',              'label' => 'Zariyah (female, Arabic MSA)',                     'lang' => 'ar-SA', 'gender' => 'F', 'styles' => ['general']],
    ];
}

function azureOutputFormats(): array {
    return [
        ['code' => 'audio-16khz-32kbitrate-mono-mp3',  'label' => 'MP3 16 kHz / 32 kbps (smallest)'],
        ['code' => 'audio-24khz-48kbitrate-mono-mp3',  'label' => 'MP3 24 kHz / 48 kbps (default)'],
        ['code' => 'audio-24khz-96kbitrate-mono-mp3',  'label' => 'MP3 24 kHz / 96 kbps'],
        ['code' => 'audio-48khz-96kbitrate-mono-mp3',  'label' => 'MP3 48 kHz / 96 kbps'],
        ['code' => 'audio-48khz-192kbitrate-mono-mp3', 'label' => 'MP3 48 kHz / 192 kbps (highest mp3)'],
        ['code' => 'riff-24khz-16bit-mono-pcm',        'label' => 'WAV 24 kHz / 16-bit'],
        ['code' => 'riff-48khz-16bit-mono-pcm',        'label' => 'WAV 48 kHz / 16-bit'],
        ['code' => 'ogg-48khz-16bit-mono-opus',        'label' => 'OGG Opus 48 kHz'],
    ];
}

/* ── segmentation ───────────────────────────────────────────────────
 * Walk paragraph_text_html and classify text into:
 *   main             — plain body text
 *   translation      — inside <b>
 *   word_definition  — inside ( ) (parenthesized definition block)
 *
 * Both '(' and ')' belong to the word_definition segment. Bible/Islam
 * detection is handled separately by per-series pre-passes in the
 * build worker — segmentParagraph stays HTML-structure-driven.
 */
function segmentParagraph(string $html): array {
    $segments = [];
    $cur = ['category' => null, 'text' => ''];

    $boldDepth = 0;
    $italicDepth = 0;
    $parenDepth = 0;
    // Bible-style surrogate stack. preprocessFontFilter rewrites colored
    // <span data-style="kjv"> from the parser into <bib-kjv>…</bib-kjv>;
    // each open tag pushes its style suffix so text inside routes to the
    // matching per-translation category (kjv/nas/nlt/jps/niv/esv). When
    // the stack is non-empty the innermost style wins; when empty we
    // fall back to no Bible context. If the style isn't a known child
    // category, buildVoiceBlock's parent-walk falls back to 'bible'.
    $bibStack = [];
    $i = 0; $n = strlen($html);
    while ($i < $n) {
        $ch = $html[$i];
        if ($ch === '<') {
            $end = strpos($html, '>', $i);
            if ($end === false) break;
            $tag = strtolower(substr($html, $i + 1, $end - $i - 1));
            $closing = (strlen($tag) > 0 && $tag[0] === '/');
            $name = $closing ? substr($tag, 1) : $tag;
            $name = preg_split('/[\s>\/]/', $name, 2)[0];
            if ($name === 'b' || $name === 'strong') {
                $closing ? ($boldDepth > 0 && $boldDepth--) : $boldDepth++;
            } elseif ($name === 'i' || $name === 'em') {
                $closing ? ($italicDepth > 0 && $italicDepth--) : $italicDepth++;
            } elseif (strpos($name, 'bib-') === 0) {
                if ($closing) {
                    if ($bibStack) array_pop($bibStack);
                } else {
                    $bibStack[] = substr($name, 4); // 'kjv', 'nas', 'esv', …
                }
            }
            $i = $end + 1;
            continue;
        }
        // Plain character (entity or literal). Decode entities one at a time.
        $piece = $ch;
        if ($ch === '&') {
            $semi = strpos($html, ';', $i);
            if ($semi !== false && $semi - $i <= 8) {
                $piece = html_entity_decode(substr($html, $i, $semi - $i + 1), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $i = $semi + 1;
            } else {
                $i++;
            }
        } else {
            $i++;
        }
        foreach (mb_str_split($piece) as $c) {
            if ($bibStack) {
                // Innermost-bib-style wins; falls back to 'bible' if the
                // tag doesn't match a known child category, via the
                // parent walk in buildVoiceBlock.
                $cat = end($bibStack) ?: 'bible';
            }
            elseif ($c === '(') { $parenDepth++; $cat = 'word_definition'; }
            elseif ($c === ')' && $parenDepth > 0) { $cat = 'word_definition'; $parenDepth--; }
            elseif ($parenDepth > 0) { $cat = 'word_definition'; }
            elseif ($boldDepth  > 0) { $cat = 'translation'; }
            else                     { $cat = 'main'; }
            if ($cat !== $cur['category']) {
                if (trim($cur['text']) !== '') $segments[] = $cur;
                $cur = ['category' => $cat, 'text' => ''];
            }
            $cur['text'] .= $c;
        }
    }
    if (trim($cur['text']) !== '') $segments[] = $cur;

    // Merge adjacent same-category, drop whitespace-only segments after merge,
    // and trim outer whitespace.
    $merged = [];
    foreach ($segments as $s) {
        if ($merged && end($merged)['category'] === $s['category']) {
            $merged[count($merged) - 1]['text'] .= $s['text'];
        } else {
            $merged[] = $s;
        }
    }
    foreach ($merged as &$s) {
        $s['text'] = preg_replace('/\s+/u', ' ', $s['text']);
        $s['text'] = trim($s['text']);
    }
    unset($s);
    return array_values(array_filter($merged, fn($s) => $s['text'] !== ''));
}

function ttsCategories(): array {
    // Four top-level parents (`parent` => null):
    //   - Yada   ── YY-book content (general narration / translation / word definition)
    //   - Bible  ── per-translation children (KJV / NAS / NLT / JPS / NIV / ESV)
    //   - Islam  ── source-specific children (Quran / Bukhari / Muslim / Tabari / Ishaq / …)
    //   - Other  ── catch-all for non-Yada, non-scriptural text (quote, mein_kampf, …)
    // Children inherit voice/style/prosody from their parent when they
    // don't have their own row in yy_tts_category_voice. The ordering
    // here drives the rendering order in the Defaults tab and the Build
    // modal; children sit directly under their parent.
    //
    // Codes 'main', 'translation', 'word_definition', 'quote' are kept
    // unchanged for backwards-compatibility with existing rows in
    // yy_tts_category_voice and with segmentParagraph's output. Only
    // their parent and label changed.
    return [
        // ── Yada ── core YY-book content categories.
        ['code' => 'yada',            'parent' => null,    'label' => 'Yada — YY-book content (default)'],
        ['code' => 'main',            'parent' => 'yada',  'label' => 'General narration (body text)'],
        ['code' => 'translation',     'parent' => 'yada',  'label' => 'Translation prose (bold text)'],
        ['code' => 'word_definition', 'parent' => 'yada',  'label' => 'Word / Hebrew definition (parenthesized)'],

        // ── Bible ── per-translation children are color-tagged by the
        // parser (data-style="kjv" etc., see BIBLE_STYLE_BY_COLOR in
        // bundle_paragraphs.py). When a translation has no specific
        // voice mapping the segment falls back to the generic Bible
        // voice — i.e. this parent.
        ['code' => 'bible',           'parent' => null,    'label' => 'Bible — generic / fallback'],
        ['code' => 'kjv',             'parent' => 'bible', 'label' => 'King James Version (KJV)'],
        ['code' => 'nas',             'parent' => 'bible', 'label' => 'New American Standard (NAS / NASB)'],
        ['code' => 'nlt',             'parent' => 'bible', 'label' => 'New Living Translation (NLT)'],
        ['code' => 'jps',             'parent' => 'bible', 'label' => 'Jewish Publication Society (JPS)'],
        ['code' => 'niv',             'parent' => 'bible', 'label' => 'New International Version (NIV)'],
        ['code' => 'esv',             'parent' => 'bible', 'label' => 'English Standard Version (ESV)'],

        // ── Islam ── source-specific children are tagged by the worker
        // (see TTS_ISLAMIC_SOURCES + the chapter-intro classifier in
        // admin-tts-build-worker.php). Quotes whose source can't be
        // identified fall back to the generic Islam voice — this parent.
        ['code' => 'islam',           'parent' => null,    'label' => 'Islam — generic / fallback'],
        ['code' => 'quran',           'parent' => 'islam', 'label' => 'Quran'],
        ['code' => 'islam_translation', 'parent' => 'islam', 'label' => 'Quran translation (Ahmed Ali, Pickthal, Shakir, Yusuf Ali, Noble Quran, Word-by-Word)'],
        ['code' => 'bukhari',         'parent' => 'islam', 'label' => 'Bukhari (Hadith)'],
        ['code' => 'muslim',          'parent' => 'islam', 'label' => 'Muslim (Sahih Muslim)'],
        ['code' => 'tabari',          'parent' => 'islam', 'label' => 'Tabari'],
        ['code' => 'ishaq',           'parent' => 'islam', 'label' => 'Ishaq'],

        // ── Other ── catch-all for non-Yada, non-scriptural text.
        // 'quote' carries the historical extended-quote category; 'mein_kampf'
        // is for citations from Hitler's Mein Kampf that the YY books quote
        // extensively (especially the s05 Babel and s06 Twistianity volumes).
        ['code' => 'other',           'parent' => null,    'label' => 'Other — generic catch-all'],
        ['code' => 'quote',           'parent' => 'other', 'label' => 'General extended quote (non-scripture)'],
        ['code' => 'mein_kampf',      'parent' => 'other', 'label' => 'Mein Kampf'],
    ];
}

/**
 * Resolve a category code back to its parent (or null for top-level).
 * Built once per request from ttsCategories(); used by buildVoiceBlock
 * to walk the parent chain when picking a voice.
 */
function ttsCategoryParent(string $code): ?string {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (ttsCategories() as $c) $map[$c['code']] = $c['parent'];
    }
    return $map[$code] ?? null;
}

/* ── provider routing & per-provider pronunciation ──────────────────────
 * These power the multi-engine swap: a segment's category resolves to a
 * voice, the voice to an engine (provider), and the provider decides both
 * the synthesis path (Azure SSML vs. local HTTP) and which pronunciation
 * override applies. Until a category is pointed at a self-hosted voice,
 * every segment resolves to Azure (provider 1) and behavior is unchanged.
 */

/** Resolve a category to its voice code, walking parents like buildVoiceBlock(). */
function ttsResolveVoiceCode(array $cfg, string $category): ?string {
    $cat = $cfg['categories'][$category] ?? null;
    $cur = $category;
    while ($cat === null && $cur !== null) {
        $cur = ttsCategoryParent($cur);
        if ($cur !== null) $cat = $cfg['categories'][$cur] ?? null;
    }
    if ($cat === null && !empty($cfg['categories'])) $cat = reset($cfg['categories']);
    return $cat['tts_voice_code'] ?? null;
}

/** Resolve a category to the provider_key of its voice's engine. Defaults to 1 (Azure). */
function ttsResolveProviderKey(array $cfg, string $category): int {
    $vc = ttsResolveVoiceCode($cfg, $category);
    if ($vc !== null && isset($cfg['voice_provider'][$vc])) {
        return (int)$cfg['voice_provider'][$vc];
    }
    return 1; // Azure — preserves historical all-Azure behavior
}

/** True when a provider uses SSML markup (Azure). Local engines (plain/ipa) → false. Unknown → true (safe Azure path). */
function ttsProviderUsesSsml(array $cfg, int $providerKey): bool {
    $p = $cfg['providers'][$providerKey] ?? null;
    if (!$p) return true;
    return (($p['provider_markup_format'] ?? 'ssml') === 'ssml');
}

/**
 * Active tune list resolved for one provider. Each Print may have a default
 * row (provider_key=0) and an optional provider-specific override; the override
 * wins. Application order from loadTtsConfig is preserved. With only default
 * rows present this returns exactly $cfg['tunes'].
 */
function ttsTunesForProvider(array $cfg, int $providerKey): array {
    $tunes = $cfg['tunes'] ?? [];
    // A provider override only suppresses the default row for the SAME rule
    // identity (Print + bold/italic/case flags) — never a different rule that
    // merely shares a Print. First pass: which rule identities have an override
    // for THIS provider.
    $overridden = [];
    foreach ($tunes as $t) {
        if ($providerKey !== 0 && (int)($t['provider_key'] ?? 0) === $providerKey) {
            $overridden[ttsTuneRuleId($t)] = true;
        }
    }
    // Second pass, preserving order: keep this provider's own rows; keep default
    // (0) rows unless an override exists for their rule identity; drop rows that
    // belong to a different engine. With no overrides present this returns the
    // full default list unchanged (so the Azure path is byte-identical).
    $out = [];
    foreach ($tunes as $t) {
        $pk = (int)($t['provider_key'] ?? 0);
        if ($pk === $providerKey && $providerKey !== 0) { $out[] = $t; continue; }
        if ($pk !== 0) continue;                                   // another engine's override
        if (isset($overridden[ttsTuneRuleId($t)])) continue;       // default suppressed by an override
        $out[] = $t;
    }
    return $out;
}

/** Stable identity of a tune rule for override matching: Print + match flags. */
function ttsTuneRuleId(array $t): string {
    return ($t['tts_tune_print'] ?? '') . "\x1f"
        . (!empty($t['tts_tune_match_bold']) ? '1' : '0')
        . (!empty($t['tts_tune_match_italic']) ? '1' : '0')
        . (!empty($t['tts_tune_match_case_sensitive']) ? '1' : '0');
}

/**
 * Plain-text pronunciation substitution for engines that don't take SSML.
 * Uses the 'sub' respelling (the portable default), stripping the SSML-only
 * [slow] / {fast} markers. IPA/SAPI rows fall back to their sub spelling.
 * (True IPA passthrough for phoneme engines like Kokoro is a future refinement.)
 */
function applyTunesPlain(string $text, array $tunes): string {
    foreach ($tunes as $t) {
        $print = (string)($t['tts_tune_print'] ?? '');
        if ($print === '') continue;
        $alias = trim((string)($t['tts_tune_phonetic_sub'] ?? ''));
        // Local engines have no SSML phoneme tag — IPA fallback would inject
        // codepoints (ɑ ʁ ʕ) the engine's text→phoneme stage can't map.
        // Skip tunes with no 'sub' so the English defaults handle them.
        if ($alias === '') continue;
        $alias = str_replace(['[', ']', '{', '}'], '', $alias);
        $regex = tunePrintToRegex($print, !empty($t['tts_tune_match_case_sensitive']));
        $text = preg_replace_callback($regex, function ($m) use ($alias) {
            return !empty($m[2]) ? $alias . 's' : $alias;   // possessive 's
        }, $text);
    }
    return $text;
}

/**
 * Build a provider-neutral plain-text request for a local-engine segment.
 * Mirrors buildVoiceBlock's citation-rewrite + tune steps but emits plain text
 * (no SSML) plus the category's prosody numbers.
 */
function buildLocalSegment(string $text, array $cfg, string $category): array {
    $providerKey = ttsResolveProviderKey($cfg, $category);
    $voiceCode   = ttsResolveVoiceCode($cfg, $category) ?? '';
    if (!empty($cfg['bible_books'])) {
        $text = rewriteBibleCitations($text, $cfg['bible_books']);
    }
    $text = applyTunesPlain($text, ttsTunesForProvider($cfg, $providerKey));
    $text = preg_replace('/\x01PAUSE_\d+_(\d+)\x01/', ' ', $text);   // drop pause placeholders
    $text = preg_replace('/[\x{02BF}\x{02BE}]/u', '', $text);        // drop half-rings ʿ ʾ
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    // Category prosody (parent-walked like buildVoiceBlock).
    $cat = $cfg['categories'][$category] ?? null;
    $cur = $category;
    while ($cat === null && $cur !== null) { $cur = ttsCategoryParent($cur); if ($cur !== null) $cat = $cfg['categories'][$cur] ?? null; }
    if ($cat === null && !empty($cfg['categories'])) $cat = reset($cfg['categories']);
    $style = trim((string)($cat['tts_voice_style'] ?? ''));
    // 'general' is the UI sentinel for "no style"; treat like empty.
    if ($style === 'general') $style = '';
    return [
        'provider_key' => $providerKey,
        'voice'        => $voiceCode,
        'text'         => $text,
        'phonemes'     => null,
        'rate'         => (int)($cat['tts_voice_rate_pct']  ?? 0),
        'pitch'        => (float)($cat['tts_voice_pitch_st'] ?? 0),
        'volume'       => (int)($cat['tts_voice_volume']     ?? 100),
        'style'        => $style,
    ];
}

/**
 * Synthesize one segment on a self-hosted engine (Puget box) via gpu-client.php's
 * authenticated tailnet gateway. Contract mirrors azureTtsSynthesize(): returns
 * audio bytes, or '' with $err set.
 *
 * The engine name (kokoro / chatterbox / cosyvoice / qwen3) is taken from the
 * provider row's settings.engine, falling back to provider_model_id. The
 * destination URL + bearer token live in gpu-client.php / .env — provider_endpoint
 * is no longer consulted for local engines, so a tenant DB never accidentally
 * points the build worker at an unauthenticated host.
 */
function localTtsSynthesize(array $cfg, array $seg, string $outputFormat, ?string &$err = null): string {
    require_once __DIR__ . '/gpu-client.php';
    $prov = $cfg['providers'][$seg['provider_key']] ?? null;
    if (!$prov) { $err = 'unknown provider_key ' . ($seg['provider_key'] ?? '?'); return ''; }
    $settings = json_decode((string)($prov['provider_settings'] ?? '{}'), true) ?: [];
    $engine   = $settings['engine'] ?? ($prov['provider_model_id'] ?? '');
    if ($engine === '') { $err = 'provider ' . $seg['provider_key'] . ' has no engine name'; return ''; }
    $fmt = (strpos($outputFormat, 'opus') !== false) ? 'opus'
         : ((strpos($outputFormat, 'pcm') !== false || strpos($outputFormat, 'wav') !== false) ? 'wav' : 'mp3');
    $payload = [
        'provider' => $engine,
        'voice'    => $seg['voice'],
        'text'     => $seg['text'],
        'phonemes' => $seg['phonemes'],
        'rate'     => (int)$seg['rate'],
        'pitch'    => (float)$seg['pitch'],
        'volume'   => (int)$seg['volume'],
        'format'   => $fmt,
    ];
    if (!empty($seg['style'])) $payload['style'] = (string)$seg['style'];
    $r = gpuSynthesize($payload, null, 300);
    if (!$r['ok']) {
        $err = 'local TTS ' . ($r['error'] ?? ('HTTP ' . ($r['status'] ?? 0)));
        return '';
    }
    return (string)($r['body'] ?? '');
}

// Azure TTS retry wrapper. 429 / 5xx / curl errors are retriable with
// exponential backoff; other 4xx (bad SSML, auth) bail immediately.
function azureTtsSynthesizeRetry(string $ssml, array $cfg, ?string &$err = null, int $maxAttempts = 6): string {
    $attempt = 0;
    $delay   = 1;
    while ($attempt < $maxAttempts) {
        $bytes = azureTtsSynthesize($ssml, $cfg, $err);
        if ($bytes !== '') return $bytes;
        $shouldRetry = false;
        if ($err === null || $err === '') {
            $shouldRetry = true;
        } else if (preg_match('/HTTP (429|5\d\d)/', $err)) {
            $shouldRetry = true;
        } else if (preg_match('/HTTP 0\b/', $err)) {
            $shouldRetry = true;
        }
        if (!$shouldRetry) return '';
        $attempt++;
        if ($attempt >= $maxAttempts) break;
        fwrite(STDERR, "azure retry $attempt after {$delay}s — $err\n");
        sleep($delay);
        $delay = min($delay * 2, 30);
    }
    return '';
}

// Local-engine retry wrapper, mirroring azureTtsSynthesizeRetry.
function localTtsSynthesizeRetry(array $cfg, array $seg, string $outputFormat, ?string &$err = null, int $maxAttempts = 4): string {
    $attempt = 0;
    $delay   = 1;
    while ($attempt < $maxAttempts) {
        $bytes = localTtsSynthesize($cfg, $seg, $outputFormat, $err);
        if ($bytes !== '') return $bytes;
        $retry = ($err === null || $err === '' || preg_match('/HTTP (0|429|5\d\d)\b/', (string)$err));
        if (!$retry) return '';
        $attempt++;
        if ($attempt >= $maxAttempts) break;
        fwrite(STDERR, "local-tts retry $attempt after {$delay}s — $err\n");
        sleep($delay);
        $delay = min($delay * 2, 15);
    }
    return '';
}
