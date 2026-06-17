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

/**
 * Resolve the active profile for $ttsKey, with optional caller override.
 *
 * If $profileKey is null/0 we pick the default profile (yy_tts_profile row
 * with tts_profile_default_flag=TRUE for this tts_key). Returns the
 * profile's key, or 0 if the tts_key has no profile rows yet (legacy
 * tenants before the migration ran).
 */
function ttsResolveProfileKey(PDO $db, int $ttsKey, ?int $profileKey = null): int {
    if ($profileKey !== null && $profileKey > 0) {
        $st = $db->prepare("SELECT tts_profile_key FROM yy_tts_profile WHERE tts_profile_key = ? AND tts_key = ? AND tts_profile_active_flag = TRUE");
        $st->execute([$profileKey, $ttsKey]);
        $found = (int)$st->fetchColumn();
        if ($found > 0) return $found;
    }
    $st = $db->prepare("SELECT tts_profile_key FROM yy_tts_profile WHERE tts_key = ? AND tts_profile_default_flag = TRUE AND tts_profile_active_flag = TRUE LIMIT 1");
    $st->execute([$ttsKey]);
    return (int)$st->fetchColumn();
}

/**
 * Load the full TTS config for a system + a specific profile.
 *
 * $profileKey: 0/null = default profile for this tts_key. A user-supplied
 * profile_key is honoured (validated against yy_tts_profile to prevent
 * cross-tts contamination). The returned config carries 'profile_key' so
 * downstream callers (build worker, preview) can persist which profile
 * a synthesised audio file was rendered with.
 */
function loadTtsConfig(PDO $db, int $ttsKey, ?int $profileKey = null): array {
    $sysStmt = $db->prepare("SELECT * FROM yy_tts WHERE tts_key = ?");
    $sysStmt->execute([$ttsKey]);
    $system = $sysStmt->fetch();
    if (!$system) return ['system' => null, 'categories' => [], 'tunes' => [], 'pauses' => [], 'profile_key' => 0];

    // Pick the profile (caller-supplied or default). Categories load
    // scoped to that profile so different profiles can have completely
    // different voice maps for the same tts_key.
    $resolvedProfile = ttsResolveProfileKey($db, $ttsKey, $profileKey);

    $catStmt = $db->prepare("SELECT * FROM yy_tts_category_voice WHERE tts_key = ? AND tts_profile_key = ? AND tts_category_voice_active_flag = TRUE");
    $catStmt->execute([$ttsKey, $resolvedProfile]);
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
    //   4. Newer key wins on full tie — tunePrintToRegex normalises every
    //      apostrophe-class char (ʾ ʿ ʼ ' etc.) into one class, so two
    //      rows like "Yisraʾel"→"Yisrael" (new) and "Yisraʿel"→"yihsr-Ahehl"
    //      (old IPA fragment) BOTH match source "Yisraʾel". Without the
    //      key tiebreaker the older row wins on physical order and Chatterbox
    //      reads "yihsr Ahehl". Newest-wins matches user expectation when
    //      they refine a respelling.
    $tuneStmt = $db->prepare("
        SELECT *
          FROM yy_tts_tune
         WHERE tts_key = ? AND tts_tune_active_flag = TRUE
         ORDER BY COALESCE(tts_tune_sort, 0) DESC,
                  (tts_tune_voice_code IS NOT NULL) DESC,
                  length(tts_tune_print) DESC,
                  tts_tune_key DESC
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

    return ['system' => $system, 'categories' => $categories, 'tunes' => $tunes, 'pauses' => $pauses, 'fonts' => $fonts, 'bible_books' => $bibleBooks, 'providers' => $providers, 'voice_provider' => $voiceProvider, 'profile_key' => $resolvedProfile];
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

/**
 * Convert user-typed inline pause markers in the Phonetic / source text to
 * the same placeholder token shape that applyPauses uses. The shared format
 * lets placeholdersToBreaks (Azure) and the local-engine drop in
 * buildLocalSegment treat both kinds of pauses identically downstream.
 *
 * Supported syntax (any of):
 *   [500]    [500ms]    [1s]    [1.5s]    [2s]
 * Whole-number ms, 'ms' suffix optional, or seconds with 's' suffix.
 * 0 / negative / >5000 ms get clamped to the safe range (0..5000).
 * The token's "rule id" portion uses 0 so it doesn't collide with any
 * real yy_tts_pause row id (sequence starts at 1).
 */
function applyInlinePauses(string $text): string {
    return preg_replace_callback(
        '/\[(\d+(?:\.\d+)?)(ms|s)?\]/i',
        function ($m) {
            $n = (float)$m[1];
            $unit = strtolower($m[2] ?? '');
            $ms = ($unit === 's') ? (int)round($n * 1000) : (int)round($n);
            $ms = max(0, min(5000, $ms));
            return sprintf("\x01PAUSE_%d_%d\x01", 0, $ms);
        },
        $text
    );
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
            // Strip half-rings (ʾ U+02BE, ʿ U+02BF) from the *surface text*
            // of the phoneme tag. The `ph=` attribute already encodes the
            // correct pronunciation in IPA; the surface text is only a visual
            // / accessibility fallback. Azure ignores it entirely (so output
            // stays byte-identical) but stricter engines like Inworld TTS try
            // to articulate the U+02BF and produce an audible delay / glottal
            // stop. Stripping keeps the SSML clean for everyone.
            $printSurface = preg_replace('/[\x{02BE}\x{02BF}]/u', '', $print);
            $printEsc = htmlspecialchars($printSurface, ENT_QUOTES | ENT_XML1);
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
    // Hyphens in a SUB are syllable separators for human readability
    // (e.g. "yah-Hoe-wah") — Azure interprets them as compound-word
    // boundaries with a brief pause. Strip them out so the syllables
    // run together, leaving a space behind for clean phonemization.
    $alias = str_replace('-', ' ', $alias);
    $alias = trim((string)preg_replace('/\s+/u', ' ', $alias));
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
    // Skipped categories: if the admin unchecked "read" on this category
    // (or an ancestor it inherits from), emit nothing at all — no <voice>
    // wrapper, no whitespace — so neighbouring blocks concatenate directly
    // with zero added pause. The override path is exempt because the
    // caller already chose a specific voice and the skip flag is meant
    // to suppress AUTOMATIC routing, not explicit picks.
    if ($overrideVoice === null && !ttsCategoryReadable($cfg, $category)) {
        return '';
    }
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
    // Inline pause markers (e.g. "[500]" / "[1s]") get converted to the same
    // placeholder format as global yy_tts_pause rules so placeholdersToBreaks
    // turns both into <break time="..."/> SSML tags.
    $text = applyInlinePauses($text);
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

/**
 * Synthesize one segment via the ElevenLabs cloud TTS API. Same return
 * contract as azureTtsSynthesize() / localTtsSynthesize(): mp3 bytes on
 * success, '' with $err set on failure.
 *
 * Voice + model come from the provider row: tts_voice_code is the
 * ElevenLabs voice_id (e.g. "21m00Tcm4TlvDq8ikWAM"), provider_model_id
 * names the synthesis model ("eleven_v3" / "eleven_turbo_v2_5"). When
 * the model is v3 we pass any inline audio tags through verbatim — they
 * survive the buildLocalSegment normalisation that already strips SSML
 * for non-SSML providers. Turbo gets plain text.
 *
 * Rate/pitch from the per-category prosody knobs map onto ElevenLabs's
 * `voice_settings.speed` (Turbo accepts it; v3 ignores it gracefully) and
 * we leave stability/similarity at the provider's saner defaults.
 */
/**
 * Synthesize one segment via the Inworld TTS cloud API. Same return
 * contract as azureTtsSynthesize() / elevenlabsTtsSynthesize() /
 * localTtsSynthesize(): mp3 bytes on success, '' with $err set on
 * failure.
 *
 * Inworld API quirks vs ElevenLabs:
 *   - Auth header is `Basic <base64(apiKey:)>` not Bearer or xi-api-key.
 *   - Response is JSON with `audioContent` base64-encoded, NOT raw bytes.
 *   - 2,000-char per-request cap — strictly enforced server-side.
 *   - speakingRate range is 0.5..1.5 (narrower than ElevenLabs's 0.5..2).
 *   - No pitch knob; we drop the pitch field same as ElevenLabs.
 *
 * Voice + model come from the provider row: tts_voice_code = Inworld
 * voiceId (e.g. "Dennis"), provider_model_id = "inworld-tts-1.5-max"
 * (or another Inworld model when we eventually have multiple rows).
 */
function inworldTtsSynthesize(array $cfg, array $seg, string $outputFormat, ?string &$err = null): string {
    $key = readEnv('INWORLD_API_KEY');
    if (!$key) { $err = 'INWORLD_API_KEY not set in .env'; return ''; }

    $prov = $cfg['providers'][$seg['provider_key']] ?? null;
    if (!$prov) { $err = 'unknown provider_key ' . ($seg['provider_key'] ?? '?'); return ''; }
    $modelId = (string)($prov['provider_model_id'] ?? 'inworld-tts-1.5-max');
    $voiceId = (string)($seg['voice'] ?? '');
    if ($voiceId === '') { $err = 'no voiceId on segment'; return ''; }

    $text = (string)($seg['text'] ?? '');
    if ($text === '') { $err = 'empty text'; return ''; }
    // Inworld interprets a mid-word uppercase letter as a stress / emphasis
    // marker and inserts an audible pause around it. The shared local-engine
    // sub respellings ('shahBueah', 'YahHOHwah') deliberately use mid-word
    // capitals as stress hints for Chatterbox / CosyVoice / Qwen3, which
    // benefit from them. Inworld doesn't — so right before posting we
    // lowercase any uppercase letter that follows another letter, leaving
    // word-initial capitalisation of proper nouns ("Joshua", "Israel")
    // intact. This is the fix for the audible delay around transliterated
    // words like "Shabuwʿah" where the ʿ has already been substituted out
    // by applyTunesPlain but the stress capital remains.
    $text = preg_replace_callback('/(?<=\p{L})\p{Lu}/u', function ($m) {
        return mb_strtolower($m[0], 'UTF-8');
    }, $text);
    // Per-call cap. We don't chunk here — the build worker already chunks
    // at paragraph boundaries and the preview limit is 8,000 chars, so a
    // single >2k segment is a misuse; surface it as a clear error.
    if (mb_strlen($text) > 2000) {
        $err = 'Inworld TTS-1.5 Max caps at 2000 chars; got ' . mb_strlen($text);
        return '';
    }

    // Output format. Inworld supports MP3 / OGG_OPUS / WAV / LINEAR16 etc.
    // The internal $outputFormat hint maps to a family; default MP3.
    $encoding = (strpos($outputFormat, 'opus') !== false) ? 'OGG_OPUS'
              : ((strpos($outputFormat, 'pcm') !== false || strpos($outputFormat, 'wav') !== false) ? 'WAV' : 'MP3');

    // Rate from category settings → 0.5..1.5 multiplier. Clamp tight per
    // Inworld's accepted range.
    $ratePct = (int)($seg['rate'] ?? 0);
    $speakingRate = max(0.5, min(1.5, 1.0 + ($ratePct / 100.0)));

    $payload = [
        'text'        => $text,
        'voiceId'     => $voiceId,
        'modelId'     => $modelId,
        'audioConfig' => [
            'audioEncoding'   => $encoding,
            'sampleRateHertz' => 48000,
            'speakingRate'    => $speakingRate,
        ],
    ];
    // We still SEND the seed (and a low temperature) when one is supplied
    // even though Inworld TTS-1.5 Max + TTS-2 currently ignore both fields
    // (verified empirically — same payload returns different audio). Two
    // reasons to keep sending: (a) if Inworld ever wires up determinism we
    // get it for free, (b) lower temperature does still nudge their sampler
    // toward less expressive output on average even when not bit-exact.
    if (isset($seg['seed']) && $seg['seed'] !== null) {
        $payload['seed']        = (int)$seg['seed'];
        $payload['temperature'] = 0.3;
    }
    // Inworld's dashboard issues the API key as a pre-base64-encoded
    // "<keyId>:<secret>" Basic-auth credential — already padded with `==`
    // when you copy it. We send it verbatim. If a future key happens to
    // contain a literal ':' (i.e. raw keyId:secret form), encode it once
    // before pasting into .env: `echo -n 'kid:sec' | base64`.
    $authHeader = 'Authorization: Basic ' . $key;

    $ch = curl_init('https://api.inworld.ai/tts/v1/voice');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            $authHeader,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 180,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        $body = $cerr ?: trim((string)$resp);
        $err = "Inworld TTS HTTP $code: " . substr($body, 0, 400);
        return '';
    }
    $data = json_decode((string)$resp, true);
    if (!is_array($data) || empty($data['audioContent'])) {
        $err = 'Inworld TTS returned no audioContent: ' . substr((string)$resp, 0, 300);
        return '';
    }
    $bytes = base64_decode((string)$data['audioContent'], true);
    if ($bytes === false || $bytes === '') {
        $err = 'Inworld TTS audioContent failed base64 decode';
        return '';
    }
    return $bytes;
}

function elevenlabsTtsSynthesize(array $cfg, array $seg, string $outputFormat, ?string &$err = null): string {
    $key = readEnv('ELEVENLABS_API_KEY');
    if (!$key) { $err = 'ELEVENLABS_API_KEY not set in .env'; return ''; }

    $prov = $cfg['providers'][$seg['provider_key']] ?? null;
    if (!$prov) { $err = 'unknown provider_key ' . ($seg['provider_key'] ?? '?'); return ''; }
    $modelId = (string)($prov['provider_model_id'] ?? 'eleven_turbo_v2_5');
    $voiceId = (string)($seg['voice'] ?? '');
    if ($voiceId === '') { $err = 'no voice_id on segment'; return ''; }

    // Map our internal output_format hint to one ElevenLabs accepts via
    // the `output_format` query param. ElevenLabs uses its own constant
    // names; we pick the closest match per family.
    $fmtMap = [
        'mp3'  => 'mp3_44100_128',
        'opus' => 'opus_48000_128',
        'wav'  => 'pcm_24000',
        'pcm'  => 'pcm_24000',
    ];
    $family = (strpos($outputFormat, 'opus') !== false) ? 'opus'
            : ((strpos($outputFormat, 'pcm') !== false || strpos($outputFormat, 'wav') !== false) ? 'wav' : 'mp3');
    $elFmt = $fmtMap[$family] ?? 'mp3_44100_128';

    // Rate from category settings → 0.5–2.0 speed multiplier. Pitch can't
    // map cleanly (ElevenLabs has no pitch knob today) so we just drop it.
    $ratePct = (int)($seg['rate'] ?? 0);
    $speed = max(0.5, min(2.0, 1.0 + ($ratePct / 100.0)));

    // ── Lockdown voice settings (no hallucinations) ──────────────────────
    // 'stability' is the single most important knob for "say EXACTLY what
    // I typed, no more, no less." ElevenLabs documents 0.5 as "mid" where
    // the sampler has the most freedom to add emotion AND to invent filler
    // on short segments (autoregressive continuation hallucinations like
    // "and another" / "here's another" after a one-word fragment). 0.85+
    // pins the model tight to the transcript; 1.0 reads as monotone but
    // refuses to deviate from the input. We sit at 0.85 — strict enough
    // to suppress hallucinations on the short main-voice fragments that
    // book paragraphs are split into (e.g. "thereof " alone between two
    // parenthetical translations), expressive enough that single-voice
    // long-form narration still sounds natural.
    // 'similarity_boost' clamps to the chosen voice — high value prevents
    // the model from drifting toward a generic voice on out-of-distribution
    // text (Hebrew transliterations). 'style' = 0 disables style exaggeration
    // (we want neutral narration). 'use_speaker_boost' = true strengthens
    // similarity-locking further.
    $voiceSettings = [
        'stability'         => 0.85,
        'similarity_boost'  => 0.85,
        'style'             => 0.0,
        'use_speaker_boost' => true,
    ];
    if ($modelId === 'eleven_turbo_v2_5' || $modelId === 'eleven_flash_v2_5') {
        $voiceSettings['speed'] = $speed;
    }

    $payload = [
        'text'           => (string)($seg['text'] ?? ''),
        'model_id'       => $modelId,
        'voice_settings' => $voiceSettings,
    ];
    if ($payload['text'] === '') { $err = 'empty text'; return ''; }
    // Per-occurrence seed for determinism. ElevenLabs accepts uint32
    // [0..4294967295] and makes "best effort" deterministic sampling.
    // Caller passes null for stochastic; an int pins the synth so a tune
    // word with a hand-tuned seed sounds the same on every encounter.
    if (array_key_exists('seed', $seg) && $seg['seed'] !== null) {
        $payload['seed'] = (int)$seg['seed'];
    }

    $url = 'https://api.elevenlabs.io/v1/text-to-speech/'
         . rawurlencode($voiceId) . '?output_format=' . rawurlencode($elFmt);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'xi-api-key: ' . $key,
            'Content-Type: application/json',
            'Accept: audio/mpeg',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 180,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        $body = $cerr ?: trim((string)$resp);
        if ($body === '') $body = '[empty response]';
        else $body = substr($body, 0, 400);
        $err = "ElevenLabs TTS HTTP $code: $body";
        return '';
    }
    return (string)$resp;
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
            $sql = "SELECT tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_language, tts_voice_region, tts_voice_gender, tts_voice_styles, tts_voice_secondary_locales, tts_voice_active_flag, provider_key
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
                        // Provider this voice belongs to (FK → yy_provider).
                        // The Pronunciations tab's Voice dropdown filters on
                        // this so only voices in the picked engine show up.
                        'provider_key' => (int)($r['provider_key'] ?? 0),
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
function segmentParagraph(string $html, array &$carry = []): array {
    $segments = [];
    $cur = ['category' => null, 'text' => ''];

    // Carry-over state from the previous paragraph. When a YY paragraph
    // ends mid-bold or mid-paren because the PDF parser cut at a page
    // break, the next paragraph's continuation text needs to start in
    // the SAME format state. Without this carry-over, that content
    // routes to 'main' voice and the listener hears the translation
    // suddenly drop into general narration mid-definition.
    // Format: $carry = ['bold'=>N, 'italic'=>N, 'paren'=>N, 'bibStack'=>[...]]
    $boldDepth   = (int)($carry['bold']   ?? 0);
    $italicDepth = (int)($carry['italic'] ?? 0);
    $parenDepth  = (int)($carry['paren']  ?? 0);
    $bibStack    = (array)($carry['bibStack'] ?? []);
    // (above $italicDepth, $parenDepth, $boldDepth, $bibStack already
    // initialised from the $carry array.)
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
    // Hand the final depths back to the caller so it can pass them in to
    // the next paragraph's call. Continuation handling lives in the worker
    // loop — see the $carryState variable around the segmentParagraph
    // call in admin-tts-build-worker.php.
    $carry = [
        'bold'     => $boldDepth,
        'italic'   => $italicDepth,
        'paren'    => $parenDepth,
        'bibStack' => $bibStack,
    ];
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
        ['code' => 'na',              'parent' => 'bible', 'label' => 'New American (NA)'],
        ['code' => 'nlt',             'parent' => 'bible', 'label' => 'New Living Translation (NLT)'],
        ['code' => 'jps',             'parent' => 'bible', 'label' => 'Jewish Publication Society (JPS)'],
        ['code' => 'niv',             'parent' => 'bible', 'label' => 'New International Version (NIV)'],
        ['code' => 'esv',             'parent' => 'bible', 'label' => 'English Standard Version (ESV)'],
        ['code' => 'lv',              'parent' => 'bible', 'label' => 'Latin Vulgate (LV)'],
        // The parser tags YY's NT/Pauline bold prose with data-style="nt" /
        // "paul" via color detection (see STYLE_BY_COLOR in
        // bundle_paragraphs.py); these are scripture sources, so they
        // inherit the Bible voice through this parent chain.
        ['code' => 'nt',              'parent' => 'bible', 'label' => 'New Testament (NT)'],
        ['code' => 'paul',            'parent' => 'bible', 'label' => 'Pauline Epistles'],

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
        // Parser emits cat='kampf' (5 chars) from data-style="kampf". Map
        // to the same voice slot mein_kampf would use — they're the
        // same content type, just different short-codes.
        ['code' => 'kampf',           'parent' => 'other', 'label' => 'Mein Kampf'],
        ['code' => 'mein_kampf',      'parent' => 'other', 'label' => 'Mein Kampf (legacy alias)'],
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

/**
 * Drop segments whose category is marked skip and stitch their readable
 * neighbours back into a single segment per category run. Without this,
 * a bold paragraph like
 *
 *   <b>"Indeed</b> (kai)<b>, on</b> (en)<b> the Day</b> ...
 *
 * would survive as [translation, word_definition, translation, word_definition,
 * translation, ...] — and after dropping the unreadable word_definition
 * entries, the worker would still synthesise SIX tiny translation fragments
 * separately (`"Indeed`, `, on`, ` the Day`, ...). ElevenLabs hallucinates
 * filler on short fragments and outright fails some of them, so individual
 * paragraphs lose all their audio and Play returns 404.
 *
 * Merging adjacent same-category survivors produces ONE call per category
 * run ("Indeed, on the Day of Fifty, it was fulfilled...") and the prose
 * reads naturally.
 *
 * Idempotent: a no-op when nothing is marked skip.
 */
function ttsCollapseSkippedSegments(array $cfg, array $segments): array {
    $out = [];
    foreach ($segments as $s) {
        $cat = $s['category'] ?? '';
        if (!ttsCategoryReadable($cfg, $cat)) continue;
        if ($out && end($out)['category'] === $cat) {
            // Glue with a single space so "Indeed" + ", on" reads
            // "Indeed, on" rather than running together as "Indeed,on".
            // segmentParagraph already trimmed each segment's outer
            // whitespace; we restore the boundary explicitly here.
            $tail = $out[count($out) - 1]['text'];
            $glue = ($tail !== '' && substr($tail, -1) !== ' ') ? ' ' : '';
            $out[count($out) - 1]['text'] = $tail . $glue . $s['text'];
        } else {
            $out[] = $s;
        }
    }
    // Normalise whitespace inside each merged run (runs of >1 space →
    // single space; trim outer).
    foreach ($out as &$s) {
        $s['text'] = preg_replace('/\s+/u', ' ', $s['text']);
        $s['text'] = trim($s['text']);
    }
    unset($s);
    // Drop any that ended up empty after the trim.
    return array_values(array_filter($out, function ($s) { return ($s['text'] ?? '') !== ''; }));
}

/**
 * Should content tagged with $category be synthesised at all?
 *
 * Walks the parent chain like buildVoiceBlock() and inspects
 * `tts_category_voice_read_flag` on the matched row. FALSE → caller should
 * skip the entire segment so no audio is emitted AND no inter-segment pause
 * is introduced. Unknown / no-row categories default to TRUE (read).
 *
 * Caller pattern (build worker / preview loop):
 *   foreach ($segs as $seg) {
 *     if (!ttsCategoryReadable($cfg, $seg['category'])) continue;
 *     ... synthesise ...
 *   }
 */
function ttsCategoryReadable(array $cfg, string $category): bool {
    $cat = $cfg['categories'][$category] ?? null;
    $cur = $category;
    while ($cat === null && $cur !== null) {
        $cur = ttsCategoryParent($cur);
        if ($cur !== null) $cat = $cfg['categories'][$cur] ?? null;
    }
    if ($cat === null) return true;
    $flag = $cat['tts_category_voice_read_flag'] ?? null;
    if ($flag === null) return true;
    if (is_bool($flag)) return $flag;
    if ($flag === 'f' || $flag === '0' || $flag === 0) return false;
    return true;
}

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
 * Classify a provider by where its synthesis call lands:
 *   'azure-ssml'        → azureTtsSynthesize via the SSML path
 *   'elevenlabs-cloud'  → elevenlabsTtsSynthesize via the ElevenLabs HTTP API
 *   'inworld-cloud'     → inworldTtsSynthesize via the Inworld HTTP API
 *   'gpu-tailnet'       → localTtsSynthesize via the Puget gateway (Chatterbox / CosyVoice / Qwen3 / Kokoro)
 *
 * Used by preview + build worker to pick the right synth function. Unknown
 * providers fall back to 'azure-ssml' (same safety as ttsProviderUsesSsml).
 */
function ttsProviderTransport(array $cfg, int $providerKey): string {
    $p = $cfg['providers'][$providerKey] ?? null;
    if (!$p) return 'azure-ssml';
    $main = strtolower((string)($p['provider_main'] ?? ''));
    if ($main === 'elevenlabs') return 'elevenlabs-cloud';
    if ($main === 'inworld')    return 'inworld-cloud';
    if (($p['provider_markup_format'] ?? 'ssml') === 'ssml') return 'azure-ssml';
    return 'gpu-tailnet';
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
function applyTunesPlain(string $text, array $tunes, ?int &$count = null, &$matchedSeed = null): string {
    $count = 0;
    $matchedSeed = null;   // first matched tune's seed wins (FIFO across the prioritised list)
    // Token round-trip prevents tune-N cascading onto tune-M's substitution.
    // tunePrintToRegex normalises every apostrophe-class char into one class,
    // so "Yisraʿel"→"yihsr-Ahehl" and "Yisraʾel"→"Yisrael" BOTH match source
    // "Yisraʾel" — and BOTH match each other's plain-letter output. Replace
    // each hit with \x02PT<n>\x02 (letterless), then strtr() the tokens back
    // to their aliases at the end. The SSML path applyTunes() does the same.
    $tokenMap = [];
    $tokenIdx = 0;
    // Fast pre-filter. tunePrintToRegex + preg_replace_callback on 3,000+
    // tunes took ~11 seconds per paragraph; the vast majority of tunes'
    // Print word doesn't appear in the text at all. A cheap stripos check
    // on the Print's apostrophe-stripped lowercase core (Print minus the
    // apostrophe-class chars that tunePrintToRegex makes optional) skips
    // ~95% of tunes before we touch their regex.
    $textLower = mb_strtolower($text, 'UTF-8');
    static $APOS_RE_FAST = '/[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{02BE}\x{02BF}\x{02C0}\x{2018}\x{2019}\x{201B}\x{2032}\x{05F3}]/u';
    // Also strip apostrophe-class chars from the text-side of the pre-filter
    // check. tunePrintToRegex treats those chars as optional in matching, so
    // Print "Shabuwʿah" (core "Shabuwah") must be searchable against source
    // text "Shabuwʿah" too — without this, half-ring words silently skip
    // their tune and the raw character falls through to applyPauses, which
    // emits an audible space where the ʿ used to be.
    $textLowerCore = preg_replace($APOS_RE_FAST, '', $textLower);
    foreach ($tunes as $t) {
        $print = (string)($t['tts_tune_print'] ?? '');
        if ($print === '') continue;
        $alias = trim((string)($t['tts_tune_phonetic_sub'] ?? ''));
        // Local engines have no SSML phoneme tag — IPA fallback would inject
        // codepoints (ɑ ʁ ʕ) the engine's text→phoneme stage can't map.
        // Skip tunes with no 'sub' so the English defaults handle them.
        if ($alias === '') continue;
        // Fast pre-filter: strip apostrophe-class chars from Print and
        // check if the resulting core appears in the (also-stripped)
        // lowercased text. If not, no chance of a match — skip the regex.
        $core = preg_replace($APOS_RE_FAST, '', $print);
        if ($core === '' || mb_stripos($textLowerCore, $core, 0, 'UTF-8') === false) continue;
        $alias = str_replace(['[', ']', '{', '}'], '', $alias);
        // Hyphens in a SUB are syllable separators for human readability
        // ("yah-Hoe-wah"). Local engines like Chatterbox would treat a
        // whitespace token boundary as a word break with a small audible
        // pause between syllables — a 3-syllable respelling renders as
        // three mini-pauses, which sounds wrong. Strip the hyphen entirely
        // so the syllables fuse into a single token ("yahHoewah") that
        // the engine pronounces continuously. Case preservation gives the
        // engine a soft hint that "Hoe" is the stressed syllable, which
        // Chatterbox's text-token model picks up on.
        $alias = str_replace('-', '', $alias);
        $regex = tunePrintToRegex($print, !empty($t['tts_tune_match_case_sensitive']));
        $hits = 0;
        $text = preg_replace_callback($regex, function ($m) use ($alias, &$tokenMap, &$tokenIdx) {
            $tok = "\x02PT" . ($tokenIdx++) . "\x02";
            $tokenMap[$tok] = !empty($m[2]) ? $alias . 's' : $alias;   // possessive 's
            return $tok;
        }, $text, -1, $hits);
        if ($hits > 0) {
            $count += $hits;
            // First matched tune's seed wins. Each tune carries a
            // [seed_min, seed_max] range; we pick a fresh random int in
            // that range on every synth, so the same word can ring in
            // multiple variants across a long book. Deterministic case
            // (min == max) collapses to a single fixed seed.
            if ($matchedSeed === null) {
                $sMin = isset($t['tts_tune_seed_min']) ? (int)$t['tts_tune_seed_min'] : 0;
                $sMax = isset($t['tts_tune_seed_max']) ? (int)$t['tts_tune_seed_max'] : $sMin;
                if ($sMax < $sMin) $sMax = $sMin;
                $matchedSeed = ($sMin === $sMax) ? $sMin : random_int($sMin, $sMax);
            }
        }
    }
    if (!empty($tokenMap)) $text = strtr($text, $tokenMap);
    return $text;
}

/**
 * Tune-substitution for Inworld TTS. Inworld accepts plain text with inline
 * /IPA/ slash notation (per their docs: "Crete /kriːt/" → reads /kriːt/).
 * For each matched tune:
 *   - phonetic_type='ipa' AND phonetic_ipa non-empty  →  substitute "/IPA/"
 *   - else if phonetic_sub non-empty                  →  substitute sub respelling
 *   - else                                            →  skip (default English handles it)
 *
 * Same fast pre-filter + token round-trip as applyTunesPlain so the cost is
 * identical. The IPA path avoids the mid-word-capital pause issue entirely
 * (no respelling means no stress capitals in the output).
 */
function applyTunesInworld(string $text, array $tunes, ?int &$count = null, &$matchedSeed = null): string {
    $count = 0;
    $matchedSeed = null;
    $tokenMap = [];
    $tokenIdx = 0;
    $textLower = mb_strtolower($text, 'UTF-8');
    static $APOS_RE_FAST = '/[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{02BE}\x{02BF}\x{02C0}\x{2018}\x{2019}\x{201B}\x{2032}\x{05F3}]/u';
    $textLowerCore = preg_replace($APOS_RE_FAST, '', $textLower);
    foreach ($tunes as $t) {
        $print = (string)($t['tts_tune_print'] ?? '');
        if ($print === '') continue;
        $type = (string)($t['tts_tune_phonetic_type'] ?? 'sub');
        $ipa  = trim((string)($t['tts_tune_phonetic_ipa'] ?? ''));
        $sub  = trim((string)($t['tts_tune_phonetic_sub'] ?? ''));
        // Choose the alias per the admin's phonetic_type setting.
        if ($type === 'ipa' && $ipa !== '') {
            // Inworld supports STANDARD ENGLISH IPA only (per their docs).
            // Hebrew/Arabic-specific IPA glyphs that mark Semitic phonemes
            // not present in English phonology cause the engine to guess —
            // ʕ (voiced pharyngeal, ayin) comes out as /k/, χ (voiceless
            // uvular, chaf) likewise. Map each to the closest sound English
            // IPA can express. The lexicon's IPA strings keep their accurate
            // Semitic glyphs — this normalisation is Inworld-only.
            $ipaForInworld = strtr($ipa, [
                // ʕ (voiced pharyngeal, ayin) — was mapped to ʔ (glottal stop)
                // but Inworld's English sampler treats ʔ allophonically as /t/
                // some fraction of calls ("Yisrael" → "Yis-RAH-tail"). Dropping
                // entirely is the only Inworld-safe option; the silent-ayin
                // pronunciation matches how English speakers actually say
                // Hebrew names ("Israel", "Asaph"). Where the resulting
                // vowel-vowel hiatus is itself a problem (e.g. /ɑɛ/ in
                // "Yisrael" collapsing to a diphthong) the per-tune IPA needs
                // an explicit syllable separator inserted in the lexicon.
                "\u{0295}" => '',            // ʕ voiced pharyngeal (ayin) → drop
                "\u{0127}" => 'h',           // ħ voiceless pharyngeal (chet) → h
                "\u{0281}" => "\u{0279}",   // ʁ voiced uvular fricative → ɹ English r
                "\u{03C7}" => 'h',           // χ voiceless uvular (chaf) → h
                "\u{0263}" => 'g',           // ɣ voiced velar fricative → g
            ]);
            // Inworld's inline-IPA syntax: wrap in slashes.
            $alias = '/' . $ipaForInworld . '/';
        } elseif ($sub !== '') {
            $alias = $sub;
        } else {
            continue;
        }
        // Fast pre-filter (same as applyTunesPlain).
        $core = preg_replace($APOS_RE_FAST, '', $print);
        if ($core === '' || mb_stripos($textLowerCore, $core, 0, 'UTF-8') === false) continue;
        // SUB-style stress markers ([slow] / {fast} braces) are noise to
        // Inworld; strip them. Hyphens are syllable separators in sub
        // respellings — strip so the engine doesn't break on them. (IPA
        // already lacks these markers.)
        $alias = str_replace(['[', ']', '{', '}', '-'], '', $alias);
        $regex = tunePrintToRegex($print, !empty($t['tts_tune_match_case_sensitive']));
        $hits = 0;
        $text = preg_replace_callback($regex, function ($m) use ($alias, &$tokenMap, &$tokenIdx) {
            $tok = "\x02PT" . ($tokenIdx++) . "\x02";
            $tokenMap[$tok] = !empty($m[2]) ? $alias . 's' : $alias;
            return $tok;
        }, $text, -1, $hits);
        if ($hits > 0) {
            $count += $hits;
            if ($matchedSeed === null) {
                $sMin = isset($t['tts_tune_seed_min']) ? (int)$t['tts_tune_seed_min'] : 0;
                $sMax = isset($t['tts_tune_seed_max']) ? (int)$t['tts_tune_seed_max'] : $sMin;
                if ($sMax < $sMin) $sMax = $sMin;
                $matchedSeed = ($sMin === $sMax) ? $sMin : random_int($sMin, $sMax);
            }
        }
    }
    if (!empty($tokenMap)) $text = strtr($text, $tokenMap);
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
    $tuneHits = 0;
    $matchedTuneSeed = null;
    $text = applyTunesPlain($text, ttsTunesForProvider($cfg, $providerKey), $tuneHits, $matchedTuneSeed);
    // Apply yy_tts_pause-defined pauses (e.g. " | " → 300ms, " / " → 150ms,
    // "—" → 225ms). Without this the configured pauses are silently lost
    // on the local-engine path — Azure's buildVoiceBlock calls applyPauses
    // too but the local path was skipping it, so "Moseh | Moses" ran with
    // no pause between Hebrew and English forms.
    if (!empty($cfg['pauses'])) {
        $localPlaceholder = '';
        $text = applyPauses($text, $cfg['pauses'], $localPlaceholder);
    }
    // Inline pause markers ([500] / [1s] / etc.) get the same placeholder
    // treatment as Azure's path, then we convert them to natural punctuation
    // that local engines (Chatterbox/CosyVoice/Kokoro/Qwen3) actually pause
    // on — they don't speak SSML break tags.
    $text = applyInlinePauses($text);
    // Convert pause placeholders (both inline and yy_tts_pause-defined) into
    // natural punctuation proportional to the requested ms. Roughly:
    //   < 100ms → just a space (no perceived pause)
    //   100-300ms → comma
    //   300-1500ms → period
    //   > 1500ms → multiple periods (one per ~700ms)
    // We deliberately avoid '…' (U+2026): Chatterbox doesn't recognise it
    // and verbalises each ellipsis as the literal letter "Oh", producing
    // a "Oh Oh Oh Oh" stream at the start of every chapter heading. ASCII
    // periods are universally interpreted as natural pauses by every
    // local engine we use.
    $text = preg_replace_callback('/\x01PAUSE_\d+_(\d+)\x01/', function ($m) {
        $ms = (int)$m[1];
        if ($ms < 100)  return ' ';
        if ($ms < 300)  return ', ';
        if ($ms < 1500) return '. ';
        $n = (int)ceil($ms / 700);
        return str_repeat('. ', $n);
    }, $text);
    $text = preg_replace('/[\x{02BF}\x{02BE}]/u', '', $text);        // drop half-rings ʿ ʾ
    // YY uses '~' as the Hebrew-word / English-meaning separator in chapter
    // titles ("Pesach ~ Passover"). Chatterbox can't say it; drop it so the
    // title reads naturally as two phrases.
    $text = str_replace('~', ' ', $text);
    // Strip <b>/<i>/<em>/<strong> markup. admin-tts-preview.php preserves these
    // so Azure SSML can route segments; local engines speak them as literal
    // letters ("B", "I") otherwise. Stripped here so every local-engine code
    // path is covered (preview + build worker).
    $text = preg_replace('/<\/?(?:b|i|em|strong)\b[^>]*>/i', '', $text);
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    // Category prosody (parent-walked like buildVoiceBlock).
    $cat = $cfg['categories'][$category] ?? null;
    $cur = $category;
    while ($cat === null && $cur !== null) { $cur = ttsCategoryParent($cur); if ($cur !== null) $cat = $cfg['categories'][$cur] ?? null; }
    if ($cat === null && !empty($cfg['categories'])) $cat = reset($cfg['categories']);
    $style = trim((string)($cat['tts_voice_style'] ?? ''));
    // 'general' is the UI sentinel for "no style"; treat like empty.
    if ($style === 'general') $style = '';
    // Determinism for tune-substituted segments. Hebrew / proper-noun
    // respellings are extremely sensitive to Chatterbox's stochastic
    // sampling — the same input produces wildly different output across
    // runs. Each tune row carries its own seed (yy_tts_tune.tts_tune_seed,
    // default 0) so an admin can dial in the seed that sounds best for
    // that particular word. The matched tune's seed is captured by
    // applyTunesPlain and passed through to the engine here. Normal
    // English text (no tune match) gets seed=null → stochastic.
    $seedHint = $tuneHits > 0 ? (int)$matchedTuneSeed : null;
    return [
        'provider_key' => $providerKey,
        'voice'        => $voiceCode,
        'text'         => $text,
        'phonemes'     => null,
        'rate'         => (int)($cat['tts_voice_rate_pct']  ?? 0),
        'pitch'        => (float)($cat['tts_voice_pitch_st'] ?? 0),
        'volume'       => (int)($cat['tts_voice_volume']     ?? 100),
        'style'        => $style,
        'seed'         => $seedHint,
    ];
}

/**
 * Build a plain-text + inline-/IPA/ segment for Inworld TTS. Mirrors
 * buildLocalSegment but routes through applyTunesInworld so ipa-typed
 * tunes are emitted as Inworld's inline slash notation rather than the
 * sub respelling. Result text is what inworldTtsSynthesize POSTs.
 */
function buildInworldSegment(string $text, array $cfg, string $category): array {
    $providerKey = ttsResolveProviderKey($cfg, $category);
    $voiceCode   = ttsResolveVoiceCode($cfg, $category) ?? '';
    if (!empty($cfg['bible_books'])) {
        $text = rewriteBibleCitations($text, $cfg['bible_books']);
    }
    $tuneHits = 0;
    $matchedTuneSeed = null;
    $text = applyTunesInworld($text, ttsTunesForProvider($cfg, $providerKey), $tuneHits, $matchedTuneSeed);
    if (!empty($cfg['pauses'])) {
        $localPlaceholder = '';
        $text = applyPauses($text, $cfg['pauses'], $localPlaceholder);
    }
    $text = applyInlinePauses($text);
    // Same pause-as-punctuation strategy as buildLocalSegment — Inworld
    // honours commas / periods for natural pacing without needing SSML breaks.
    $text = preg_replace_callback('/\x01PAUSE_\d+_(\d+)\x01/', function ($m) {
        $ms = (int)$m[1];
        if ($ms < 100)  return ' ';
        if ($ms < 300)  return ', ';
        if ($ms < 1500) return '. ';
        $n = (int)ceil($ms / 700);
        return str_repeat('. ', $n);
    }, $text);
    // Strip half-rings that escaped a tune (e.g. words with no tune row).
    $text = preg_replace('/[\x{02BF}\x{02BE}]/u', '', $text);
    $text = str_replace('~', ' ', $text);
    $text = preg_replace('/<\/?(?:b|i|em|strong)\b[^>]*>/i', '', $text);
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    $cat = $cfg['categories'][$category] ?? null;
    $cur = $category;
    while ($cat === null && $cur !== null) { $cur = ttsCategoryParent($cur); if ($cur !== null) $cat = $cfg['categories'][$cur] ?? null; }
    if ($cat === null && !empty($cfg['categories'])) $cat = reset($cfg['categories']);
    $style = trim((string)($cat['tts_voice_style'] ?? ''));
    if ($style === 'general') $style = '';
    // Reproducible audio: forward the first matching tune's seed to
    // inworldTtsSynthesize, which adds it to the API payload. When no
    // tune matched (regular text), leave seed null so Inworld picks one
    // randomly per call (its default behaviour). yy_tts_tune.tts_tune_seed_min
    // == _max gives byte-identical playback every time for that word; min < max
    // gives controlled variability inside that range — same logic
    // buildLocalSegment uses for Chatterbox / CosyVoice.
    $seedHint = ($tuneHits > 0 && $matchedTuneSeed !== null) ? (int)$matchedTuneSeed : null;
    return [
        'provider_key' => $providerKey,
        'voice'        => $voiceCode,
        'text'         => $text,
        'phonemes'     => null,
        'rate'         => (int)($cat['tts_voice_rate_pct']  ?? 0),
        'pitch'        => (float)($cat['tts_voice_pitch_st'] ?? 0),
        'volume'       => (int)($cat['tts_voice_volume']     ?? 100),
        'style'        => $style,
        'seed'         => $seedHint,
    ];
}

/**
 * Build a segment payload for ElevenLabs — same preprocessing chain as
 * buildVoiceBlock() up through phoneme/break tag emission, but WITHOUT the
 * Azure-specific outer markup (<voice>, <prosody>, <mstts:express-as>,
 * <lang>). ElevenLabs v3 + flash_v2 + turbo_v2 honor inline <phoneme
 * alphabet="ipa" ph="..."> and <break time="Nms"/> tags directly; the
 * outer Azure wrapper tags would be ignored at best and cause 400s at
 * worst. We deliberately keep the <phoneme>/<break>/<sub> tokens that
 * tokensToSsml + placeholdersToBreaks produce — those are exactly what
 * ElevenLabs accepts as pronunciation + pacing controls.
 *
 * Returns the same shape as buildLocalSegment so the build worker can
 * pass it straight through, but the 'text' field carries SSML-style
 * inline tags instead of flat-punctuation text.
 */
function buildElevenLabsSegment(string $text, array $cfg, string $category): array {
    $providerKey = ttsResolveProviderKey($cfg, $category);
    $voiceCode   = ttsResolveVoiceCode($cfg, $category) ?? '';
    if (!empty($cfg['bible_books'])) {
        $text = rewriteBibleCitations($text, $cfg['bible_books']);
    }
    // Same applyTunes path Azure uses — IPA columns produce <phoneme> tags,
    // SUB columns produce <sub alias="..."> tags. Both render correctly on
    // ElevenLabs v3 / flash_v2 / turbo_v2.
    $tokenMap = [];
    $text = applyTunes($text, ttsTunesForProvider($cfg, $providerKey), $tokenMap);
    // Inline [500] / [1s] markers + yy_tts_pause-defined pauses both become
    // placeholder tokens that placeholdersToBreaks() resolves to <break>.
    $text = applyInlinePauses($text);
    $placeholder = '';
    $text = applyPauses($text, $cfg['pauses'], $placeholder);
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $escaped = placeholdersToBreaks($escaped);
    $escaped = tokensToSsml($escaped, $tokenMap);
    // Strip half-rings (ʿ ʾ) like the local path does — they have no
    // meaningful pronunciation; the surrounding yy_tts_pause rules
    // already handled their timing.
    $escaped = preg_replace('/[\x{02BF}\x{02BE}]/u', '', $escaped);
    // Drop ~ separator (Hebrew word ~ English meaning) — ElevenLabs
    // would read it as "tilde". The pause is already captured upstream.
    $escaped = str_replace('~', ' ', $escaped);
    // Strip raw <b>/<i>/<em>/<strong> markup that admin-tts-preview leaves
    // in for the SSML voice-routing path — ElevenLabs would speak them
    // as literal letters.
    $escaped = preg_replace('/<\/?(?:b|i|em|strong)\b[^>]*>/i', '', $escaped);
    // Collapse whitespace — multiple spaces around stripped chars look
    // sloppy in the request body and can confuse the tokenizer.
    $escaped = trim((string)preg_replace('/[ \t]+/', ' ', $escaped));

    // Category prosody — ElevenLabs has no <prosody> tag but exposes a
    // `speed` knob on voice_settings for flash/turbo models (v3 ignores
    // it for now). We pass rate through; pitch and volume have no
    // ElevenLabs equivalent and are dropped (warned in the docs).
    $cat = $cfg['categories'][$category] ?? null;
    $cur = $category;
    while ($cat === null && $cur !== null) { $cur = ttsCategoryParent($cur); if ($cur !== null) $cat = $cfg['categories'][$cur] ?? null; }
    if ($cat === null && !empty($cfg['categories'])) $cat = reset($cfg['categories']);
    return [
        'provider_key' => $providerKey,
        'voice'        => $voiceCode,
        'text'         => $escaped,
        'phonemes'     => null,
        'rate'         => (int)($cat['tts_voice_rate_pct']  ?? 0),
        'pitch'        => (float)($cat['tts_voice_pitch_st'] ?? 0),
        'volume'       => (int)($cat['tts_voice_volume']     ?? 100),
        'style'        => '',
        // Caller supplies seed range from per-tune seed_min/max; pinning to
        // null here means stochastic. The build worker passes a per-occurrence
        // random int in [seed_min..seed_max] when one is configured.
        'seed'         => null,
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
    // Optional deterministic-seed flag — caller sets this when reproducibility
    // matters (e.g. Pronunciations preview, where the same SUB text should
    // produce the same audio so admins can A/B their edits). Chatterbox is
    // the engine that actually honours it today; others ignore.
    if (array_key_exists('seed', $seg) && $seg['seed'] !== null) {
        $payload['seed'] = (int)$seg['seed'];
    }
    $r = gpuSynthesize($payload, null, 300);
    if (!$r['ok']) {
        $err = 'local TTS ' . ($r['error'] ?? ('HTTP ' . ($r['status'] ?? 0)));
        return '';
    }
    $bytes = (string)($r['body'] ?? '');
    // Strip leading + trailing silence / low-amplitude hallucinated garbage
    // ("GP" / "GP2" sounds Chatterbox emits during sampler startup and EOS-
    // confusion). Adaptive — uses an energy threshold instead of a fixed
    // millisecond cut, so paragraphs WITHOUT garbage don't get audio trimmed
    // and paragraphs WITH longer garbage runs still get fully cleaned.
    if ($bytes !== '' && strpos($fmt, 'mp3') !== false) {
        $trimmed = trimLeadingTrailingSilence($bytes);
        if ($trimmed !== '') $bytes = $trimmed;
    }
    return $bytes;
}

/**
 * Strip leading + trailing low-energy audio (<-30dB) via ffmpeg's
 * silenceremove filter. Used to remove Chatterbox's startup-sampler
 * hallucinations and EOS-confusion garbage from synth output.
 * Returns original bytes if ffmpeg fails or isn't available.
 */
function trimLeadingTrailingSilence(string $mp3): string {
    static $ffmpegBin = null;
    if ($ffmpegBin === null) {
        $ffmpegBin = trim((string)shell_exec('which ffmpeg 2>/dev/null'));
        if ($ffmpegBin === '') { $ffmpegBin = false; return $mp3; }
    }
    if ($ffmpegBin === false) return $mp3;
    $tmpIn  = tempnam(sys_get_temp_dir(), 'tts_trim_');
    if ($tmpIn === false) return $mp3;
    $tmpOut = $tmpIn . '.mp3';
    @file_put_contents($tmpIn, $mp3);
    // silenceremove leading: stop after first non-silent moment.
    // areverse → trim leading (now original trailing) → areverse back.
    // Threshold -30dB catches both pure silence and quiet hallucinated
    // tokens; real speech sits well above -20dB. 0.05s window so a brief
    // breath isn't enough to retrigger.
    $filter = 'silenceremove=start_periods=1:start_silence=0.05:start_threshold=-30dB,'
            . 'areverse,'
            . 'silenceremove=start_periods=1:start_silence=0.05:start_threshold=-30dB,'
            . 'areverse';
    $cmd = sprintf('%s -loglevel error -y -i %s -af %s -acodec libmp3lame -ab 64k %s 2>&1',
        escapeshellarg($ffmpegBin),
        escapeshellarg($tmpIn),
        escapeshellarg($filter),
        escapeshellarg($tmpOut));
    @shell_exec($cmd);
    $out = @file_get_contents($tmpOut);
    @unlink($tmpIn);
    @unlink($tmpOut);
    return ($out !== false && $out !== '') ? $out : $mp3;
}

// Azure TTS retry wrapper. 429 / 5xx / curl errors are retriable with
// exponential backoff; other 4xx (bad SSML, auth) bail immediately.
/**
 * Retry wrapper for elevenlabsTtsSynthesize — mirrors azureTtsSynthesizeRetry.
 *
 * Without this, transient ElevenLabs 429 (rate limit), 5xx, and network
 * timeouts cause a paragraph to be marked permanently failed and the
 * part file is never written. Roughly 10% of paragraphs in a 600-para
 * chapter hit this without retries because long-form runs eventually
 * trip the per-minute quota.
 *
 * Retries on HTTP 0 (network), 429 (rate limit), 5xx (server side).
 * Exponential backoff capped at 30s; 6 attempts total (~63s worst case).
 */
function elevenlabsTtsSynthesizeRetry(array $cfg, array $seg, string $outputFormat, ?string &$err = null, int $maxAttempts = 6): string {
    $attempt = 0;
    $delay   = 1;
    while ($attempt < $maxAttempts) {
        $bytes = elevenlabsTtsSynthesize($cfg, $seg, $outputFormat, $err);
        if ($bytes !== '') return $bytes;
        $retry = ($err === null || $err === '' || preg_match('/HTTP (0|429|5\d\d)\b/', (string)$err));
        if (!$retry) return '';
        $attempt++;
        if ($attempt >= $maxAttempts) break;
        if (defined('STDERR')) fwrite(STDERR, "elevenlabs retry $attempt after {$delay}s — $err\n");
        else error_log("elevenlabs retry $attempt after {$delay}s — $err");
        sleep($delay);
        $delay = min($delay * 2, 30);
    }
    return '';
}

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
        if (defined('STDERR')) fwrite(STDERR, "azure retry $attempt after {$delay}s — $err\n");
        else error_log("azure retry $attempt after {$delay}s — $err");
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
        if (defined('STDERR')) fwrite(STDERR, "local-tts retry $attempt after {$delay}s — $err\n");
        else error_log("local-tts retry $attempt after {$delay}s — $err");
        sleep($delay);
        $delay = min($delay * 2, 15);
    }
    return '';
}

/**
 * Sentence-chunked local-engine synth. Splits the segment's text at
 * sentence boundaries, synthesises each sentence on its own, and
 * concatenates the MP3 (or opus) bytes.
 *
 * Why: Chatterbox / CosyVoice / Qwen3's T3-style autoregressive samplers
 * have no KV cache, so per-iteration time grows quadratically with the
 * generated sequence length. A YY paragraph of 6-8 sentences at 500
 * max_new_tokens takes ~40 min as one synth call; the same text split
 * into 6-8 short sentence-level calls finishes in ~20-30 s total because
 * each call's sequence stays in the cheap (~0.07 s/iter) regime.
 *
 * MP3 frames concatenate cleanly without re-encoding, so the byte
 * concat is lossless. Opus the same. WAV would corrupt (per-file RIFF
 * headers) — we fall back to single-call for wav/pcm.
 */
function localTtsSynthesizeChunked(array $cfg, array $seg, string $outputFormat, ?string &$err = null): string {
    $isMp3OrOpus = (strpos($outputFormat, 'mp3')  !== false)
                || (strpos($outputFormat, 'opus') !== false);
    if (!$isMp3OrOpus) return localTtsSynthesizeRetry($cfg, $seg, $outputFormat, $err);

    // Chatterbox produces ~200 ms of startup garbage at the front of
    // EVERY synth call. Splitting the input into sentence chunks meant
    // each chunk had its OWN startup garbage — the warmup phrase only
    // protected itself, not the next chunk. Fix: for short text that
    // would otherwise be ONE chunk, prepend the warmup INLINE so it
    // and the target word travel as a single Chatterbox call. The
    // call's startup garbage eats the warmup; the target word comes
    // through clean. We drop the now-shared chunk's leading "uh. "
    // audio implicitly because the engine spends its first 200 ms on
    // garbage that's audibly nothing (cb's "Tallee W" / hum noise).
    // No warmup in the book-build synth path. The user clearly does NOT
    // want "uh." or "Seed N." audible in audiobook content, and the
    // startup-clipping of short paragraphs is a price they'd rather pay
    // than spoken-out warmup nonsense. Preview-time warmup lives in
    // admin-tts-preview.php so it doesn't bleed into chapter builds.
    $sentences = splitSentencesForLocalTts((string)($seg['text'] ?? ''));
    if (count($sentences) <= 1) return localTtsSynthesizeRetry($cfg, $seg, $outputFormat, $err);
    $warmupChunks = 0;

    $bytes = '';
    foreach ($sentences as $i => $sent) {
        $sentSeg = $seg;
        $sentSeg['text'] = $sent;
        // Vary the seed per sentence so adjacent sentences don't sound
        // mechanically identical, while staying reproducible from the
        // base seed if the caller supplied one (tune-driven previews).
        if (isset($seg['seed']) && $seg['seed'] !== null) {
            $sentSeg['seed'] = ((int)$seg['seed'] + $i) % 1000;
        }
        $sentErr = '';
        $b = localTtsSynthesizeRetry($cfg, $sentSeg, $outputFormat, $sentErr);
        if ($b === '') {
            $err = "sentence " . ($i + 1) . " of " . count($sentences) . ": $sentErr";
            return '';
        }
        // Drop the warmup chunk's audio (and seek to discard the leading
        // clipped frames the engine would have produced anyway).
        if ($i < $warmupChunks) continue;
        $bytes .= $b;
    }
    return $bytes;
}

/**
 * Split text at sentence boundaries, guarding common English abbreviations
 * so "Dr. Smith" / "U.S." / "e.g." don't trigger a split. Sentences
 * longer than 400 chars get a comma-level backstop split so we don't
 * accidentally hand Chatterbox an extreme outlier.
 */
function splitSentencesForLocalTts(string $text): array {
    $text = trim($text);
    if ($text === '') return [];
    static $ABBREVS = [
        'Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Jr.', 'Sr.', 'St.',
        'vs.', 'etc.', 'e.g.', 'i.e.', 'cf.',
        'Prof.', 'No.', 'Co.', 'Inc.', 'Ltd.',
        'a.m.', 'p.m.', 'U.S.', 'U.K.', 'B.C.', 'A.D.',
    ];
    $marks = [];
    foreach ($ABBREVS as $i => $abbr) {
        $tok = "\x02AB" . $i . "\x02";
        $marks[$tok] = $abbr;
        $text = str_ireplace($abbr, $tok, $text);
    }
    // Sentence boundary: . ! ? followed by whitespace, then a non-lowercase
    // character (the start of the next sentence — uppercase letter, quote,
    // numeral, etc.). We don't require uppercase outright because YY content
    // sometimes opens with "—" or a quotation mark.
    $parts = preg_split('/(?<=[.!?])\s+(?=[^\s\p{Ll}])/u', $text);
    $out = [];
    foreach ($parts as $p) {
        foreach ($marks as $tok => $abbr) $p = str_replace($tok, $abbr, $p);
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    // Backstop: cap individual chunks at ~250 chars by splitting on commas.
    // YY paragraphs frequently have very long compound sentences with deep
    // embedded parentheticals — a single sentence can easily exceed 250
    // chars / 40+ words. Chatterbox's max_new_tokens caps a single synth
    // at ~32 s of audio at 400 tokens (current setting), so chunks much
    // over 250 chars get truncated mid-sentence ("not being read all the
    // way to the end"). 250 chars ≈ 17-20s, safely under the cap.
    $capped = [];
    foreach ($out as $s) {
        if (mb_strlen($s) <= 250) { $capped[] = $s; continue; }
        foreach (preg_split('/(?<=,)\s+/u', $s) as $sp) {
            $sp = trim($sp);
            if ($sp !== '') $capped[] = $sp;
        }
    }
    // Drop punctuation-only chunks. The local-engine pause converter (in
    // buildLocalSegment) emits runs of "." for long pauses; the sentence
    // splitter then treats each "." as a sentence boundary, producing
    // tiny "."-only entries here. Synthesising silence costs an engine
    // round-trip and contributes no audio — drop them. The neighbouring
    // sentence already carries the trailing period so the natural pause
    // is preserved.
    $final = [];
    foreach ($capped as $s) {
        if (preg_match('/\p{L}|\d/u', $s)) $final[] = $s;
    }
    return $final ?: [$text];
}
