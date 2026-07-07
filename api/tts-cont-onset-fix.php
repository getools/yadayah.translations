<?php
/**
 * Per-segment page-break CONTINUATION marker onset correction.
 *
 *   php tts-cont-onset-fix.php <audio_key> [--apply] [--lead-ms=30] [--quiet]
 *
 * Normal page markers are byte-offset-derived (ffprobe packet PTS) and accurate
 * — they land a beat early, but in the inter-paragraph silence, so that's fine.
 * The EXCEPTION is a page-break CONTINUATION marker: when one logical paragraph
 * is split across a page break, the build coalesces the tail into the head for
 * synthesis, so the new page's first marker falls MID-CHUNK and has no byte
 * offset (tts_audio_marker_byte_offset IS NULL). Its offset is therefore only a
 * char-ratio ESTIMATE, which can land ~0.5-1s early — inside the spoken tail of
 * the previous page (e.g. you still hear "…Yahowah's as" at the top of page 13).
 *
 * Whole-chapter apply-onsets QA cannot fix this: the chapter MP3 is a byte-concat
 * of independently-encoded per-paragraph clips, so a single continuous STT decode
 * accumulates per-chunk encoder padding and drifts progressively early. The cure
 * is PER-SEGMENT: extract just the ~window around each continuation marker, decode
 * it fresh (negligible drift), and word-align to find the true onset of the
 * continuation paragraph's first spoken words.
 *
 * Idempotent and conservative: only NULL-byte markers are touched, a new onset is
 * applied only on a confident, unique word match that lands STRICTLY between the
 * neighbouring markers; anything ambiguous is left as-is and reported. Dry-run by
 * default; pass --apply to write. Markers are served dynamically (tts-audio.php),
 * so no cache bump is needed.
 *
 * See memory: reference_tts_audio_seekable_remux_and_markers,
 *             reference_tts_sync_qa_marker_correction.
 */

require_once __DIR__ . '/config.php';          // getDb()
require_once __DIR__ . '/gpu-client.php';      // gpuTranscribe()
require_once __DIR__ . '/admin-tts-helpers.php'; // loadTtsConfig, preprocessFontFilter, segmentParagraph, ttsCollapseSkippedSegments

$audioKey = (int)($argv[1] ?? 0);
$args     = array_slice($argv, 1);
$apply    = in_array('--apply', $args, true);
$quiet    = in_array('--quiet', $args, true);
$leadMs   = 30;
foreach ($args as $a) { if (preg_match('/^--lead-ms=(\d+)$/', $a, $m)) $leadMs = (int)$m[1]; }
if ($audioKey <= 0) { fwrite(STDERR, "usage: php tts-cont-onset-fix.php <audio_key> [--apply] [--lead-ms=N]\n"); exit(2); }

/** Normalize a token to a comparable core: lowercase, fold smart quotes, strip non-alphanumerics. */
function cofNorm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-']);
    $s = preg_replace('/[^a-z0-9]+/u', '', $s);
    return (string)$s;
}
/** First N normalized content tokens of a string (drops empties). Splits on
 *  whitespace AND hyphens so a book "set-apart" matches STT's "set" + "apart". */
function cofTokens(string $s, int $n): array {
    $out = [];
    foreach (preg_split('/[\s\-–—]+/u', trim($s)) ?: [] as $w) {
        $t = cofNorm($w);
        if ($t !== '') { $out[] = $t; if (count($out) >= $n) break; }
    }
    return $out;
}

/** Per-paragraph EXPECTED SPOKEN text for a chapter — what the build actually
 *  voices, not the raw book text. Mirrors admin-tts-qa-worker's ttsQaSpokenTextMap:
 *  runs the build's own preprocessing (preprocessFontFilter strips skip-font glyph
 *  spans → segmentParagraph → ttsCollapseSkippedSegments drops read_flag=false
 *  categories like the parenthesized word definitions) so the onset words we hunt
 *  for are the words actually spoken — not a leading (definition) the engine skips.
 *  Carry threaded in reading order, exactly like the build. Falls back to raw plain
 *  text per-paragraph on any error. @return array<int,string> paragraph_number → spoken */
function cofSpokenMap(PDO $db, array $cfg, int $chapterKey): array {
    $out = [];
    $st = $db->prepare("SELECT paragraph_number, paragraph_text_html, paragraph_text_plain, paragraph_is_table
                          FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
    $st->execute([$chapterKey]);
    $carry = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $pn = (int)$p['paragraph_number'];
        if (!empty($p['paragraph_is_table'])) { $out[$pn] = ''; continue; }
        try {
            $filtered = preprocessFontFilter((string)($p['paragraph_text_html'] ?? ''), $cfg['fonts'] ?? []);
            $segs = ttsCollapseSkippedSegments($cfg, segmentParagraph($filtered, $carry));
            $out[$pn] = trim(preg_replace('/\s+/u', ' ',
                implode(' ', array_map(fn($s) => (string)($s['text'] ?? ''), $segs))));
        } catch (\Throwable $e) {
            $out[$pn] = trim((string)($p['paragraph_text_plain'] ?? ''));
        }
    }
    return $out;
}

$db = getDb();
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

$aStmt = $db->prepare("SELECT tts_audio_path, chapter_key, tts_key, tts_profile_key FROM yy_tts_audio WHERE tts_audio_key = ?");
$aStmt->execute([$audioKey]);
$audio = $aStmt->fetch(PDO::FETCH_ASSOC);
if (!$audio) { fwrite(STDERR, "audio_key $audioKey not found\n"); exit(1); }
$chapterKey = (int)$audio['chapter_key'];

$audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
$mp3 = $audioBase . '/' . ltrim((string)$audio['tts_audio_path'], '/');
if (!is_file($mp3) || filesize($mp3) === 0) { fwrite(STDERR, "chapter MP3 missing: $mp3\n"); exit(1); }

// Skip-aware expected-spoken text per paragraph (what the build voices). Best-
// effort: if config/helpers fail we fall back to raw plain text below.
$spokenMap = [];
try {
    $cfg = loadTtsConfig($db, (int)$audio['tts_key'], isset($audio['tts_profile_key']) ? (int)$audio['tts_profile_key'] : null);
    $spokenMap = cofSpokenMap($db, $cfg, $chapterKey);
} catch (\Throwable $e) {
    fwrite(STDERR, "spoken-text map failed (using raw plain text): " . $e->getMessage() . "\n");
}

// Markers in READING (paragraph) order — NOT offset_ms order. A badly-broken
// continuation marker's char-ratio offset can sort it to the wrong place, which
// would hand it the wrong neighbours and put its window in the wrong part of the
// audio (the true onset then falls outside → no match). paragraph_number+page is
// the canonical order; the previous/next markers' (byte-accurate) offsets bracket
// the true onset regardless of how wrong this marker's own offset is. Join the
// marker's OWN paragraph text by paragraph_number (the continuation paragraph,
// whose first spoken words are exactly the onset we want to land on).
$mStmt = $db->prepare("
    SELECT m.tts_audio_marker_key AS mkey, m.paragraph_number AS pn, m.paragraph_page AS pg,
           m.tts_audio_marker_offset_ms AS off_ms, m.tts_audio_marker_byte_offset AS byte,
           p.paragraph_text_plain AS text
      FROM yy_tts_audio_marker m
      LEFT JOIN yy_paragraph p ON p.chapter_key = ? AND p.paragraph_number = m.paragraph_number
     WHERE m.tts_audio_key = ?
     ORDER BY m.paragraph_number, m.paragraph_page");
$mStmt->execute([$chapterKey, $audioKey]);
$rows = $mStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { fwrite(STDERR, "no markers for audio_key $audioKey\n"); exit(1); }

$log = function (string $s) use ($quiet) { if (!$quiet) echo $s . "\n"; };

$nCont = 0; $nApplied = 0; $nSkipped = 0; $flags = [];
$tmp = sys_get_temp_dir() . "/cof_{$audioKey}.wav";

for ($i = 0; $i < count($rows); $i++) {
    $r = $rows[$i];
    if ($r['byte'] !== null) continue;                  // byte-derived marker: leave it
    $nCont++;
    $mkey = (int)$r['mkey']; $off = (int)$r['off_ms']; $pn = (int)$r['pn'];
    // Bracket by the nearest BYTE-derived (trustworthy) markers on each side —
    // skip intervening NULL-byte continuation markers whose offsets we don't
    // trust. Fall back to the immediate neighbour / a default span at the edges.
    $prevOff = 0;
    for ($a = $i - 1; $a >= 0; $a--) { if ($rows[$a]['byte'] !== null) { $prevOff = (int)$rows[$a]['off_ms']; break; } }
    if ($prevOff === 0 && $i > 0) $prevOff = (int)$rows[$i - 1]['off_ms'];
    $nextOff = $off + 12000;
    for ($b = $i + 1; $b < count($rows); $b++) { if ($rows[$b]['byte'] !== null) { $nextOff = (int)$rows[$b]['off_ms']; break; } }
    // Prefer the skip-aware spoken text (what the build voices); fall back to raw.
    $srcText = ($spokenMap[$pn] ?? '') !== '' ? $spokenMap[$pn] : (string)($r['text'] ?? '');
    $target = cofTokens($srcText, 6);
    $label  = "marker {$mkey} (page {$r['pg']}, ¶{$r['pn']})";
    if (count($target) === 0) { $nSkipped++; $flags[] = "$label: SKIP — continuation paragraph has no spoken text"; continue; }

    // Window: from a bit before the previous marker to a bit after the next, so
    // the continuation's opening words are comfortably inside the clip.
    $winStart = max(0.0, $prevOff / 1000.0 - 1.5);
    $winEnd   = $nextOff / 1000.0 + 1.5;
    $winDur   = $winEnd - $winStart;
    @unlink($tmp);
    $cmd = sprintf('ffmpeg -y -ss %.3f -t %.3f -i %s -ar 16000 -ac 1 %s 2>/dev/null',
                   $winStart, $winDur, escapeshellarg($mp3), escapeshellarg($tmp));
    exec($cmd, $o, $rc);
    if ($rc !== 0 || !is_file($tmp) || filesize($tmp) === 0) { $nSkipped++; $flags[] = "$label: SKIP — ffmpeg window extract failed"; continue; }

    $res = gpuTranscribe($tmp, [
        'path' => '/stt-whisperx/transcribe', 'word_timestamps' => true,
        'vad_filter' => false, 'language' => 'en', 'timeout' => 300,
    ]);
    if (empty($res['ok'])) { $nSkipped++; $flags[] = "$label: SKIP — STT failed: " . ($res['error'] ?? '?'); continue; }

    // Flatten STT words → [{w(normalized), t(abs seconds)}].
    $words = [];
    foreach (($res['data']['segments'] ?? []) as $seg) {
        foreach (($seg['words'] ?? []) as $w) {
            $core = cofNorm((string)($w['word'] ?? $w['text'] ?? ''));
            if ($core === '') continue;
            $words[] = ['w' => $core, 't' => $winStart + (float)($w['start'] ?? 0)];
        }
    }
    if (!$words) { $nSkipped++; $flags[] = "$label: SKIP — no STT words in window"; continue; }

    // Find the best contiguous match of the continuation's opening tokens. Among
    // all start positions whose first token equals target[0], pick the longest
    // consecutive run (tie → earliest in time).
    $best = null; // ['i'=>, 'run'=>, 't'=>]
    for ($j = 0; $j < count($words); $j++) {
        if ($words[$j]['w'] !== $target[0]) continue;
        $run = 1;
        while ($run < count($target) && ($j + $run) < count($words) && $words[$j + $run]['w'] === $target[$run]) $run++;
        if ($best === null || $run > $best['run']) $best = ['i' => $j, 'run' => $run, 't' => $words[$j]['t']];
    }

    // Confidence: need a distinctive anchor — either a 2+ token run, or a single
    // long first token (≥5 chars). Common short stop-words alone aren't enough.
    $confident = $best !== null && ($best['run'] >= 2 || ($best['run'] >= 1 && strlen($target[0]) >= 5));
    if (!$confident) {
        $nSkipped++;
        $first = $target[0] ?? '?';
        $flags[] = "$label: SKIP — no confident match for opening token \"$first\" (run=" . ($best['run'] ?? 0) . ")";
        continue;
    }

    $newOff = (int)round($best['t'] * 1000) - $leadMs;
    // Must land strictly inside the neighbour window (with a small margin) or we
    // could make things worse; leave it for manual review.
    if ($newOff <= $prevOff + 50 || $newOff >= $nextOff - 50) {
        $nSkipped++;
        $flags[] = sprintf("$label: SKIP — onset %dms outside (%d, %d) neighbour window", $newOff, $prevOff, $nextOff);
        continue;
    }

    $delta = $newOff - $off;
    $log(sprintf("%s: %d → %d ms (Δ%+d) onset of \"%s\" (run %d)%s",
        $label, $off, $newOff, $delta, implode(' ', array_slice($target, 0, $best['run'])), $best['run'],
        $apply ? '' : '  [dry-run]'));

    if ($apply) {
        $u = $db->prepare("UPDATE yy_tts_audio_marker SET tts_audio_marker_offset_ms = ?
                            WHERE tts_audio_marker_key = ? AND tts_audio_marker_byte_offset IS NULL");
        $u->execute([$newOff, $mkey]);
        $nApplied++;
    }
}
@unlink($tmp);

$log("");
$log(sprintf("audio %d: %d continuation marker(s); %s %d, skipped %d",
    $audioKey, $nCont, $apply ? 'applied' : 'would-apply', $apply ? $nApplied : ($nCont - $nSkipped), $nSkipped));
foreach ($flags as $f) $log("  • $f");
exit(0);
