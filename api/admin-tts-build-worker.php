<?php
/**
 * Worker process spawned by admin-tts-build.php to generate a chapter
 * audio file. Runs through yy_paragraph rows for the chosen chapter,
 * classifies each paragraph_text_html into (voice, text) segments, calls
 * Azure TTS per paragraph, concatenates the MP3 bytes, and writes the
 * final file under /opt/yada-www/public/u/tts-audio/<volume>/ch<chN>.mp3.
 *
 * Updates yy_tts_audio.{tts_audio_status,tts_audio_progress,tts_audio_message}
 * each paragraph. Honors cancellation by re-checking tts_audio_status before
 * each paragraph synth.
 *
 * Usage:  php admin-tts-build-worker.php <tts_audio_key>
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';
require_once __DIR__ . '/spawn-helpers.php';

$audioKey = (int)($argv[1] ?? 0);
if (!$audioKey) {
    fwrite(STDERR, "tts_audio_key required\n");
    exit(2);
}

$db = getDb();

// ── Queue promotion ────────────────────────────────────────────────────
// Concurrency limit: 1 chapter build at a time. Chatterbox / CosyVoice /
// Qwen3 all share a single GPU with one loaded model — two concurrent
// model.generate() calls serialise on the CUDA stream, so the wall-clock
// gain vs running them sequentially is roughly zero, while Caddy is more
// likely to time out the second-in-line request waiting for the first.
// Azure-only builds COULD run concurrently safely, but routing decisions
// happen per-paragraph inside the worker so we cap globally for simplicity.
// When this worker exits (success, failure, or unexpected crash), promote
// the next pending row. Registered as a shutdown hook so even fatal
// errors / OOM exits trigger the promote.
$MAX_CONCURRENT_BUILDS = 1;
register_shutdown_function(function() use (&$db, $MAX_CONCURRENT_BUILDS, $audioKey) {
    // Serialize promote-next against admin-tts-build.php's dispatch (same lock
    // key 742002) and count a freshly-claimed pending row (pid set, status not
    // yet 'running') as occupying a slot — so a worker exit and a concurrent
    // build POST can't both spawn past the cap.
    $TTS_BUILD_LOCK = 742002;
    try {
        if (!$db) $db = getDb();
        // If THIS worker exited because the operator PAUSED the chapter, do
        // NOT promote the next queued chapter. Pause means "stop here" — a
        // subsequent Resume must re-spawn THIS chapter and continue from its
        // next uncached paragraph. Advancing the queue on pause was claiming
        // the single build slot, so the Resume sat behind the auto-started
        // next chapter and the operator saw that next chapter render instead
        // of their paused one continuing mid-chapter. Cancel (failed/gone) and
        // normal completion still promote — only an explicit pause halts here.
        try {
            $myStatusStmt = $db->prepare("SELECT tts_audio_status FROM yy_tts_audio WHERE tts_audio_key = ?");
            $myStatusStmt->execute([$audioKey]);
            if (($myStatusStmt->fetchColumn() ?: '') === 'paused') return;
        } catch (Throwable $e) { /* probe failed — fall through to normal promote */ }
        $db->query('SELECT pg_advisory_lock(' . $TTS_BUILD_LOCK . ')');
        try {
            $active = (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio
                                        WHERE tts_audio_status = 'running'
                                           OR (tts_audio_status = 'pending' AND tts_audio_worker_pid IS NOT NULL)")
                              ->fetchColumn();
            if ($active >= $MAX_CONCURRENT_BUILDS) return;
            // Promote by the row's LOCATION, not by when it was queued:
            // group by book (tts_sort), then by volume → chapter position
            // within that book. Paragraph order is handled inside the chapter
            // build itself (the worker iterates paragraph_number and gap-fills),
            // so book → chapter here completes book → chapter → paragraph.
            // A 'Redo' re-queues an already-built chapter; ordering by location
            // means it re-renders in reading order — typically jumping to the
            // front of the pending queue, ahead of later chapters still waiting
            // — rather than landing at the back behind everything queued before
            // it. tts_audio_dtime stays as the final tiebreaker. Whole-book rows
            // (chapter_key IS NULL) sort first within their volume.
            $nextKey = (int)$db->query("
                SELECT a.tts_audio_key
                  FROM yy_tts_audio a
                  JOIN yy_tts     t ON t.tts_key     = a.tts_key
                  JOIN yy_volume  v ON v.volume_key  = a.volume_key
             LEFT JOIN yy_chapter c ON c.chapter_key = a.chapter_key
                 WHERE a.tts_audio_status = 'pending'
                   AND a.tts_audio_worker_pid IS NULL
                 ORDER BY t.tts_sort, t.tts_name,
                          v.volume_sort, v.volume_number,
                          c.chapter_sort NULLS FIRST, c.chapter_number NULLS FIRST,
                          a.tts_audio_dtime ASC
                 LIMIT 1
            ")->fetchColumn();
            if (!$nextKey) return;
            $logFile = sys_get_temp_dir() . '/tts_build_' . $nextKey . '.log';
            $pid = spawnCappedWorker(__FILE__, [(string)$nextKey], $logFile, [
                'cpu_secs' => 3600, 'mem_mb' => 1500, 'nice' => 10,
            ]);
            if ($pid > 0) {
                $db->prepare("UPDATE yy_tts_audio SET tts_audio_worker_pid = ? WHERE tts_audio_key = ?")
                   ->execute([$pid, $nextKey]);
            }
        } finally {
            $db->query('SELECT pg_advisory_unlock(' . $TTS_BUILD_LOCK . ')');
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "promote-next failed: " . $e->getMessage() . "\n");
    }
});

function updateAudio(PDO $db, int $audioKey, array $fields): void {
    if (!$fields) return;
    $set = [];
    $params = [];
    foreach ($fields as $col => $val) {
        $set[] = "$col = ?";
        $params[] = $val;
    }
    $params[] = $audioKey;
    $db->prepare("UPDATE yy_tts_audio SET " . implode(', ', $set) . ", tts_audio_revision_dtime = NOW() WHERE tts_audio_key = ?")
       ->execute($params);
}

function bail(PDO $db, int $audioKey, string $err): void {
    updateAudio($db, $audioKey, [
        'tts_audio_status'          => 'failed',
        'tts_audio_error'           => $err,
        'tts_audio_completed_dtime' => date('Y-m-d H:i:sO'),
    ]);
    fwrite(STDERR, "FAIL: $err\n");
    exit(1);
}

// Mark running.
$row = $db->prepare("SELECT * FROM yy_tts_audio WHERE tts_audio_key = ?");
$row->execute([$audioKey]);
$job = $row->fetch();
if (!$job) { fwrite(STDERR, "tts_audio row $audioKey missing\n"); exit(2); }
updateAudio($db, $audioKey, [
    'tts_audio_status'  => 'running',
    'tts_audio_message' => 'Loading paragraphs',
    'tts_audio_progress'=> 1,
]);

$ttsKey     = (int)$job['tts_key'];
$volumeKey  = (int)$job['volume_key'];
$chapterKey = (int)$job['chapter_key'];
$settings   = json_decode($job['tts_audio_settings'] ?? 'null', true) ?: [];

// Build a config struct compatible with admin-tts-helpers — we splice the
// per-build snapshot in over the saved defaults so concurrent admin edits
// to yy_tts_category_voice don't disturb the in-flight run. Profile-aware
// load: the audio row records which profile the build was queued under,
// so we hydrate THAT profile's category voices, not the default.
$jobProfileKey = (int)($job['tts_profile_key'] ?? 0)
              ?: (int)($settings['profile_key'] ?? 0)
              ?: null;
$cfg = loadTtsConfig($db, $ttsKey, $jobProfileKey);
if (!$cfg['system']) bail($db, $audioKey, "tts_key $ttsKey not found");
if (!empty($settings['categories'])) {
    foreach ($settings['categories'] as $cat => $snap) {
        // Preserve the prior read_flag if the snapshot doesn't carry one
        // (snapshots written before this column existed). TRUE = read; the
        // build worker's segment loops gate on ttsCategoryReadable().
        $priorReadFlag = $cfg['categories'][$cat]['tts_category_voice_read_flag'] ?? true;
        $snapReadFlag  = array_key_exists('read_flag', $snap) ? (bool)$snap['read_flag'] : (bool)$priorReadFlag;
        $snapVoice     = (string)($snap['voice_code'] ?? '');
        // Defence against stale snapshots that captured every registry
        // category (yada, main, …, nt, paul, lv) with empty voice codes.
        // Persisting an empty-voice row here would block buildVoiceBlock's
        // parent walk for cat='nt' (cfg has nt → stop walking → voice='').
        // Skip when the snapshot row carries no voice AND no skip-flag —
        // let the registry parent chain decide. If the user explicitly
        // marked the row as skip, keep it so the skip still applies.
        if ($snapVoice === '' && $snapReadFlag === true && !isset($cfg['categories'][$cat])) {
            continue;
        }
        $cfg['categories'][$cat] = [
            'tts_voice_code'              => $snap['voice_code']   ?? ($cfg['categories'][$cat]['tts_voice_code'] ?? 'en-US-BrianMultilingualNeural'),
            'tts_voice_style'             => $snap['style']        ?? null,
            'tts_voice_style_degree'      => $snap['style_degree'] ?? 1.0,
            'tts_voice_rate_pct'          => (int)($snap['rate_pct'] ?? 0),
            'tts_voice_pitch_st'          => (int)($snap['pitch_st'] ?? 0),
            'tts_voice_volume'            => (int)($snap['volume']   ?? 100),
            'tts_category_voice_read_flag'=> $snapReadFlag,
        ];
    }
}
if (!empty($settings['output_format'])) {
    $cfg['system']['tts_output_format'] = $settings['output_format'];
}

// Snapshot every setting in effect right now into tts_audio_settings so a
// future re-render or audit can know exactly which voice + tunes + pauses
// produced this MP3. Concurrent admin edits to yy_tts_tune /
// yy_tts_pause / yy_tts_category_voice after this point won't be
// reflected in the snapshot — by design.
$snapshot = [
    'snapshot_dtime' => date('c'),
    'output_format'  => $cfg['system']['tts_output_format'] ?? null,
    'region'         => $cfg['system']['tts_region']        ?? null,
    'categories'     => array_values(array_map(function($code, $row) {
        return [
            'category'     => $code,
            'voice_code'   => $row['tts_voice_code']         ?? null,
            'style'        => $row['tts_voice_style']        ?? null,
            'style_degree' => $row['tts_voice_style_degree'] ?? 1.0,
            'rate_pct'     => (int)($row['tts_voice_rate_pct']  ?? 0),
            'pitch_st'     => (int)($row['tts_voice_pitch_st']  ?? 0),
            'volume'       => (int)($row['tts_voice_volume']    ?? 100),
            'read_flag'    => array_key_exists('tts_category_voice_read_flag', $row) ? (bool)$row['tts_category_voice_read_flag'] : true,
        ];
    }, array_keys($cfg['categories'] ?? []), $cfg['categories'] ?? [])),
    'tunes' => array_values(array_map(function($t) {
        return [
            'print'         => $t['tts_tune_print']         ?? '',
            'phonetic'      => $t['tts_tune_phonetic']      ?? '',
            'phonetic_type' => $t['tts_tune_phonetic_type'] ?? 'sub',
            'note'          => $t['tts_tune_note']          ?? '',
            'active'        => !empty($t['tts_tune_active_flag']),
        ];
    }, $cfg['tunes'] ?? [])),
    'pauses' => array_values(array_map(function($p) {
        return [
            'search' => $p['tts_pause_search'] ?? '',
            'ms'     => (int)($p['tts_pause_ms'] ?? 300),
            'note'   => $p['tts_pause_note']   ?? '',
            'active' => !empty($p['tts_pause_active_flag']),
        ];
    }, $cfg['pauses'] ?? [])),
];
$db->prepare("UPDATE yy_tts_audio SET tts_audio_settings = ?::jsonb WHERE tts_audio_key = ?")
   ->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $audioKey]);

// Look up the volume's URL-safe slug (volume_code) — included in the
// filename so files are self-identifying without needing the DB.
$vcRow = $db->prepare("SELECT volume_code FROM yy_volume WHERE volume_key = ?");
$vcRow->execute([$volumeKey]);
$volumeSlug = (string)($vcRow->fetchColumn() ?: '');
if ($volumeSlug === '') $volumeSlug = (string)$volumeKey;

// Flat output: every chapter is a sibling file under /u/tts-audio/.
// Filename pattern:  {volume_code}-ch{NN}.{ext}
// Avoids per-volume subdirectories (simpler permissions, simpler cleanup).
$outDirHost      = '/opt/yada-www/public/u/tts-audio';
$outDirContainer = dirname(__DIR__) . '/u/tts-audio';
$outDir = is_dir(dirname(__DIR__)) ? $outDirContainer : $outDirHost;
if (!is_dir($outDir)) @mkdir($outDir, 0775, true);

// Chapter row for filename naming + heading synthesis.
$chRow = $db->prepare("SELECT chapter_number, chapter_name FROM yy_chapter WHERE chapter_key = ?");
$chRow->execute([$chapterKey]);
$chInfo  = $chRow->fetch();
$chNum   = (int)($chInfo['chapter_number'] ?? 0);
$chName  = trim((string)($chInfo['chapter_name'] ?? ''));

// Look up named pseudo-pauses from yy_tts_pause so the heading synthesis
// below can splice in <break time="…"/> tags. Defaults match the seed
// rows in the DB so an installation missing these keys still sounds
// reasonable.
$getNamedPauseMs = function(string $key, int $default) use ($cfg): int {
    foreach (($cfg['pauses'] ?? []) as $p) {
        if (($p['tts_pause_search'] ?? '') !== $key) continue;
        if (empty($p['tts_pause_active_flag']))     continue;
        return (int)$p['tts_pause_ms'];
    }
    return $default;
};
$pauseChapBefore  = $getNamedPauseMs('__chapter_before__',  2500);
$pauseChapBetween = $getNamedPauseMs('__chapter_between__',  700);
$pauseChapAfter   = $getNamedPauseMs('__chapter_after__',   1500);
$pauseSubBefore   = $getNamedPauseMs('__subhead_before__',   800);
$pauseSubAfter    = $getNamedPauseMs('__subhead_after__',    500);
$ext = (strpos($cfg['system']['tts_output_format'], 'mp3') !== false) ? 'mp3'
     : ((strpos($cfg['system']['tts_output_format'], 'opus') !== false) ? 'opus'
     : ((strpos($cfg['system']['tts_output_format'], 'pcm') !== false) ? 'wav' : 'mp3'));
$baseName  = sprintf('%s-ch%02d.%s', $volumeSlug, $chNum, $ext);
$finalPath = $outDir . '/' . $baseName;
$relPath   = '/u/tts-audio/' . $baseName;

// Load paragraphs. paragraph_page is included so we can emit a row in
// yy_tts_audio_marker tying each paragraph's audio offset to its page
// — flipbook-tts.js uses these to auto-turn pages as playback advances.
$pStmt = $db->prepare("SELECT paragraph_key, paragraph_number, paragraph_page, paragraph_text_html, paragraph_text_plain, paragraph_is_table, paragraph_is_continuation FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
$pStmt->execute([$chapterKey]);
$paragraphs = $pStmt->fetchAll();

// ── Coalesce page-break continuations ────────────────────────────────
// A paragraph with paragraph_is_continuation=true is the tail of a
// single logical paragraph that wrapped across a page break in the
// rendered PDF. The parser (bundle_paragraphs.py) detected the wrap
// and flagged the tail row. We append its text into the preceding
// "head" paragraph's text_html / text_plain, then drop the tail from
// the working list so synthesis treats the whole logical paragraph
// as one block (one audio file, one marker).
//
// The tail row stays in the DB — display/search/translations still
// see it. Only the TTS worker opts into the coalesce.
$coalesced = 0;
$mergedList = [];
foreach ($paragraphs as $p) {
    $isCont = !empty($p['paragraph_is_continuation']);
    if ($isCont && !empty($mergedList)) {
        $headIdx = count($mergedList) - 1;
        $head =& $mergedList[$headIdx];
        $tailHtml  = (string)($p['paragraph_text_html']  ?? '');
        $tailPlain = (string)($p['paragraph_text_plain'] ?? '');
        // Single-space separator preserves natural spacing without
        // letting two spaces double up.
        if ($tailHtml !== '') {
            $head['paragraph_text_html']  = rtrim((string)$head['paragraph_text_html'])  . ' ' . ltrim($tailHtml);
        }
        if ($tailPlain !== '') {
            $head['paragraph_text_plain'] = rtrim((string)$head['paragraph_text_plain']) . ' ' . ltrim($tailPlain);
        }
        unset($head);
        $coalesced++;
        continue;
    }
    $mergedList[] = $p;
}
if ($coalesced > 0) {
    fwrite(STDERR, "coalesced $coalesced page-break continuation paragraph(s) into their heads\n");
}
$paragraphs = $mergedList;

// Skip filters:
//   • paragraph_is_table — auto-flagged at parse time by PyMuPDF's
//     find_tables() (see bundle_paragraphs.py). Tables of dates,
//     visibility percentages, etc. don't read well aloud.
//   • volume_skip_pages — admin-managed comma-separated page ranges
//     on yy_volume. Manual escape hatch for sections the auto-detector
//     misses (front matter, appendices, calendar tables, etc.).
$skipRangesStmt = $db->prepare("SELECT volume_skip_pages FROM yy_volume WHERE volume_key = ?");
$skipRangesStmt->execute([$volumeKey]);
$skipPagesRaw = (string)($skipRangesStmt->fetchColumn() ?: '');
$skipRanges = [];
foreach (preg_split('/\s*,\s*/', $skipPagesRaw, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
    if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $tok, $m))      $skipRanges[] = [(int)$m[1], (int)$m[2]];
    elseif (preg_match('/^\s*(\d+)\s*$/', $tok, $m))              $skipRanges[] = [(int)$m[1], (int)$m[1]];
}
$beforeCount = count($paragraphs);
$paragraphs = array_values(array_filter($paragraphs, function ($p) use ($skipRanges) {
    if (!empty($p['paragraph_is_table'])) return false;
    $pg = (int)($p['paragraph_page'] ?? 0);
    foreach ($skipRanges as $r) if ($pg >= $r[0] && $pg <= $r[1]) return false;
    return true;
}));
$skipped = $beforeCount - count($paragraphs);
if ($skipped > 0) {
    fwrite(STDERR, "skipped $skipped paragraph(s): " . count(array_filter($paragraphs, fn($p) => !empty($p['paragraph_is_table']))) . " table-flagged, ranges=" . $skipPagesRaw . "\n");
}
$nPara = count($paragraphs);
if (!$nPara) bail($db, $audioKey, "no paragraphs for chapter_key=$chapterKey (all $beforeCount filtered as table/skipped-pages)");

// ── Series 07: chapter-intro Islamic-source detection ──────────────────
// Series 07 books ("God Damn Religion") open every chapter with a bold
// (or bold-italic) quote followed by a separate italic-only paragraph
// holding the source citation, e.g.:
//   "I pass judgment on them..."          (bold/italic — quote text)
//   "their women and children..."         (bold/italic — quote continued)
//   "and their property divided."         (bold/italic — quote ends)
//   Ishaq:463 & Tabari VIII:34            (italic-only — citation)
//
// We scan the first ~8 paragraphs of the chapter for an italic-only
// citation; if one matches, the run of bold paragraphs immediately
// before it gets retagged from the generic 'translation' category to
// the source-specific category (quran / bukhari / tabari / ishaq).
// Combos like "Ishaq & Tabari" attribute to the first-named source —
// simpler than minting a 4×4 combo matrix.
//
// $introOverrides maps paragraph_number → category code. The synth
// loop consults this before running segmentParagraph, so a matched
// paragraph emits a single voice block in the source category instead
// of segmenting into translation/main pieces.
$introOverrides = [];
$isS07 = (stripos($volumeSlug, 'YY-s07') === 0);
if ($isS07) {
    // Source name → category code. Order matters for combo matching:
    // we try the more specific multi-word forms first so they're not
    // shadowed by a substring of a longer form.
    static $ISLAMIC_SOURCES = [
        'Quran'   => 'quran',
        'Bukhari' => 'bukhari',
        'Muslim'  => 'muslim',
        'Tabari'  => 'tabari',
        'Ishaq'   => 'ishaq',
    ];
    // Citation regex anchored to a source name. We match the first source
    // name and ignore any "& OtherSource" suffix; the first source wins.
    // - Quran:    "Quran 113.001" or "Quran 17:78"
    // - Bukhari:  "Bukhari:V5B59N444"   (colon, no spaces)
    // - Muslim:   "Muslim:C9B1N31"      (colon, same shape as Bukhari)
    // - Tabari:   "Tabari VIII:28"      (space, roman, colon)
    // - Ishaq:    "Ishaq:461"           (colon)
    $citationRe = '/^(?:Quran\s+\d+[.:]\d+|Bukhari\s*:[\w:.\-]+|Muslim\s*:[\w:.\-]+|Tabari\s+[IVXLCDM]+\s*:\s*\d+|Ishaq\s*:\s*\d+)/u';

    // Helper: is this paragraph an "italic-only citation"?
    //   - Whole text wrapped in <i>…</i> (possibly with stray spaces)
    //   - Plain text starts with a source name and matches the citation regex
    //   - No <b>...</b> anywhere (so it's not the quote itself)
    $isItalicCitation = function (array $p) use ($citationRe): ?string {
        $html  = (string)$p['paragraph_text_html'];
        $plain = trim((string)$p['paragraph_text_plain']);
        if ($plain === '') return null;
        if (preg_match('/<b\b/i', $html)) return null;     // bold present → not a citation-only line
        if (!preg_match('/<i\b/i', $html)) return null;    // no italic at all → not a citation
        if (preg_match($citationRe, $plain, $m)) {
            return $m[0];
        }
        return null;
    };
    // Helper: is this paragraph entirely bold (the quote body)?
    $isBoldQuote = function (array $p): bool {
        $html = (string)$p['paragraph_text_html'];
        if (!preg_match('/<b\b/i', $html)) return false;
        // Strip every <b>...</b> chunk; anything non-whitespace left is
        // body prose, so this isn't a pure-bold quote paragraph.
        $stripped = preg_replace('/<b\b[^>]*>.*?<\/b>/is', '', $html);
        // Also strip italic tags + span tags that wrap our font markers.
        $stripped = preg_replace('/<\/?(?:i|span)\b[^>]*>/i', '', $stripped);
        return trim(strip_tags($stripped)) === '';
    };

    // Walk the first ~8 paragraphs looking for the citation.
    $scanLimit = min(8, $nPara);
    for ($k = 0; $k < $scanLimit; $k++) {
        $citationText = $isItalicCitation($paragraphs[$k]);
        if ($citationText === null) continue;
        // Map citation → category code. Match against $ISLAMIC_SOURCES
        // keys in order so longer source names win if any ever overlap.
        $sourceCat = null;
        foreach ($ISLAMIC_SOURCES as $name => $code) {
            if (stripos($citationText, $name) === 0) { $sourceCat = $code; break; }
        }
        if (!$sourceCat) break;     // citation regex matched but source unknown — leave intro alone

        // Walk backward from the citation paragraph collecting bold
        // quotes. Stop at the first non-bold paragraph or after a
        // reasonable run length (chapter quotes shouldn't span dozens
        // of paragraphs).
        for ($j = $k - 1; $j >= max(0, $k - 6); $j--) {
            if (!$isBoldQuote($paragraphs[$j])) break;
            $introOverrides[(int)$paragraphs[$j]['paragraph_number']] = $sourceCat;
        }
        fwrite(STDERR, sprintf(
            "s07 chapter intro: %d paragraph(s) tagged as %s (citation: %s)\n",
            count($introOverrides), $sourceCat, $citationText
        ));
        break; // Only the first intro block per chapter.
    }
}

// ── Multi-paragraph extended-quote detection ───────────────────────────
// Pattern (any series):
//   • A paragraph that opens with a curly opening quote “ (U+201C)
//   • That same paragraph does NOT end with a closing quote ” — i.e.
//     the quote continues to the next paragraph
//   • One or more continuation paragraphs
//   • A later paragraph that ends with a closing curly quote ” (U+201D)
// The whole span is retagged as the 'quote' category so it gets its
// own voice in the Voices tab, distinct from chapter-intro Islamic
// quotes (handled above) and from main body prose. Single-paragraph
// fully-bounded quotes ("… word "X" word …" on one line) are ignored.
//
// Note: we deliberately don't require italic formatting here, because
// the docx→pdf→PyMuPDF pipeline often drops italics on these blocks
// (verified against the s07v01 Solomon/Sheba quote at ch 359 p1236-1240).
// Inner dialogue using single curly quotes ‘ ’ is fine — those won't
// trip the U+201C / U+201D smart-double-quote check.
for ($k = 0; $k < $nPara; $k++) {
    $p = $paragraphs[$k];
    if (isset($introOverrides[(int)$p['paragraph_number']])) continue;
    $html = (string)$p['paragraph_text_html'];
    if (preg_match('/<b\b/i', $html)) continue;          // bold-led blocks belong to other classifiers
    $plain = trim((string)$p['paragraph_text_plain']);
    if ($plain === '' || mb_substr($plain, 0, 1) !== "\u{201C}") continue;
    if (mb_substr($plain, -1) === "\u{201D}") continue;  // self-contained quote — likely body prose
    // Walk forward up to 30 paragraphs looking for the closing quote.
    $end = -1;
    for ($j = $k + 1; $j < $nPara && $j < $k + 30; $j++) {
        $q = $paragraphs[$j];
        if (isset($introOverrides[(int)$q['paragraph_number']])) break;
        $qHtml  = (string)$q['paragraph_text_html'];
        if (preg_match('/<b\b/i', $qHtml)) break;        // a bold paragraph ends the quote stream
        $jPlain = trim((string)$q['paragraph_text_plain']);
        if ($jPlain === '') break;
        if (mb_substr($jPlain, -1) === "\u{201D}") { $end = $j; break; }
        // A paragraph that opens its OWN U+201C before the previous one
        // closes is a sign we're misreading the structure — bail out
        // rather than gluing two unrelated quotes together.
        if (mb_substr($jPlain, 0, 1) === "\u{201C}") break;
    }
    if ($end <= $k) continue;
    for ($j = $k; $j <= $end; $j++) {
        $introOverrides[(int)$paragraphs[$j]['paragraph_number']] = 'quote';
    }
    fwrite(STDERR, sprintf("extended quote: paragraphs %d..%d tagged as 'quote'\n",
        (int)$paragraphs[$k]['paragraph_number'], (int)$paragraphs[$end]['paragraph_number']));
    $k = $end; // skip past the block so we don't re-detect inside it
}

// Clear any stale markers for this audio row — easier than upserting
// across schema changes and the table is small.
$db->prepare("DELETE FROM yy_tts_audio_marker WHERE tts_audio_key = ?")->execute([$audioKey]);
$insertMarker = $db->prepare("
    INSERT INTO yy_tts_audio_marker (tts_audio_key, paragraph_key, paragraph_page, paragraph_number, tts_audio_marker_offset_ms, tts_audio_marker_byte_offset)
    VALUES (?, ?, ?, ?, ?, ?)
    ON CONFLICT (tts_audio_key, paragraph_number, paragraph_page) DO UPDATE
        SET tts_audio_marker_offset_ms   = EXCLUDED.tts_audio_marker_offset_ms,
            tts_audio_marker_byte_offset = EXCLUDED.tts_audio_marker_byte_offset,
            paragraph_key                = EXCLUDED.paragraph_key
");

// ffprobe path — used to measure each paragraph's mp3 chunk duration
// so we know its offset into the cumulative file. ffprobe isn't strictly
// required (we can fall back to a character-count estimate) but it's
// always installed in the prod container and gives sample-accurate
// offsets that align with what the browser sees.
$ffprobeBin = trim(shell_exec('which ffprobe 2>/dev/null') ?: '');
$tmpDir = sys_get_temp_dir();
function probeDurationMs(string $bin, string $bytes, string $tmpDir): int {
    if ($bin === '' || $bytes === '') return 0;
    $tmp = tempnam($tmpDir, 'tts-chunk-');
    file_put_contents($tmp, $bytes);
    $cmd = escapeshellcmd($bin) . ' -v error -show_entries format=duration -of default=nokey=1:noprint_wrappers=1 ' . escapeshellarg($tmp) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    @unlink($tmp);
    $sec = $out !== null ? (float)trim($out) : 0.0;
    return (int)round($sec * 1000);
}
$cumulativeMs    = 0;
$cumulativeBytes = 0;

// Page-break-within-paragraph detection. Reads text/page-NNN.json from
// the flipbook bundle to locate where in the paragraph a page break
// occurs (when the paragraph spans into the next page). The build
// worker stores an extra marker for each crossed page so playback
// auto-turns mid-paragraph rather than only at the next paragraph's
// boundary.
$bundleDir = '/opt/yada-www/public/' . $volumeSlug;
if (!is_dir($bundleDir)) {
    $bundleDir = dirname(__DIR__) . '/' . $volumeSlug;
}
$pageTextCache = [];

// Normalize text for fuzzy match — same strip-set the search code uses
// (curly apostrophes, half-rings, en/em-dashes), plus whitespace collapse.
function ttsNormalizeForMatch(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[\x{02BF}\x{02BE}\x{02BC}\x{02BB}\x{02B9}\x{02BA}\x{2018}\x{2019}\x{201C}\x{201D}\x{2013}\x{2014}\x{0027}]/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim((string)$s);
}

// Returns the concatenated text of a page from text/page-NNN.json
// (whitespace-separated). Empty string if the file is missing.
function ttsLoadPageText(string $bundleDir, int $page, array &$cache): string {
    if (isset($cache[$page])) return $cache[$page];
    $f = sprintf('%s/text/page-%03d.json', $bundleDir, $page);
    if (!is_file($f)) return $cache[$page] = '';
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j) || empty($j['spans'])) return $cache[$page] = '';
    $buf = '';
    foreach ($j['spans'] as $sp) {
        if (isset($sp[4])) $buf .= $sp[4] . ' ';
    }
    return $cache[$page] = $buf;
}

// findPageBreakRatios: for a paragraph that may span multiple pages,
// returns a map of [k_offset => ratio_in_paragraph] indicating that
// page (P_start + k) gets the chars from ratio*len onward. Allows up
// to ~300 chars of page-header noise (chapter title, page number)
// before the matched suffix starts on the next page. Returns [] when
// the paragraph fits on a single page or no good match was found.
function ttsFindPageBreakRatios(string $paraText, array $nextPageTexts): array {
    $out = [];
    $p = ttsNormalizeForMatch($paraText);
    $plen = mb_strlen($p);
    if ($plen < 30) return $out;
    foreach ($nextPageTexts as $k => $pageText) {
        $g = ttsNormalizeForMatch($pageText);
        if ($g === '') break;
        $bestRatio = -1.0;
        // Shrink the suffix length geometrically until we either match or
        // hit the floor. Floor at 25 chars to avoid spurious matches on
        // common short phrases.
        $minL = 25;
        for ($L = $plen; $L >= $minL; ) {
            $suffix = mb_substr($p, -$L);
            $pos = mb_strpos($g, $suffix);
            if ($pos !== false && $pos < 400) {
                $bestRatio = ($plen - $L) / $plen;
                break;
            }
            $next = (int)floor($L * 0.85);
            if ($next === $L) $next--;
            $L = $next;
        }
        if ($bestRatio < 0) break;   // paragraph doesn't extend further
        $out[$k] = $bestRatio;
    }
    return $out;
}

updateAudio($db, $audioKey, [
    'tts_audio_message'        => "Synthesizing $nPara paragraphs",
    'tts_audio_paragraph_count'=> $nPara,
]);

// Per-paragraph parts cache. Each paragraph's MP3 bytes are written to
// $partsDir/p<NNNNN>.mp3 as soon as Azure returns them. On the next run
// the worker scans this directory to determine the resume point — any
// paragraph whose part already exists is skipped (no re-synth, no
// re-charge). The final concat happens after the loop. Pause / resume
// / restart all key off this directory:
//   pause   — DB status flips to 'paused'; worker exits at the next
//             iteration boundary; parts stay on disk.
//   resume  — worker re-spawns; pre-loop scan finds parts; loop picks
//             up from the first missing idx.
//   restart — endpoint wipes the parts dir before re-spawning, so the
//             scan finds nothing and the loop runs from idx=0.
$audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
$partsDir  = $audioBase . '/u/tts-parts/' . $audioKey;
// umask 0002 so subsequent file_put_contents writes part files with mode
// 0664 (group-writable). The worker runs as root via PHP CLI, so without
// this the parts are written 0644 and the Apache user (www-data) can't
// delete them via the redo_paragraph endpoint — Redo would silently
// fail. The directory needs to be www-data-group-writable too.
umask(0002);
if (!is_dir($partsDir)) @mkdir($partsDir, 02775, true);
@chgrp($partsDir, 'www-data');
$partPath = function (int $i) use ($partsDir): string {
    return $partsDir . '/' . sprintf('p%05d.mp3', $i);
};

// Pre-loop resume scan. Re-probe each cached part's duration so the
// cumulative offsets line up with what the build would have produced
// in one go, and re-insert each cached paragraph's marker — the
// DELETE-all just above is the worker's standard "clean slate" step,
// so on resume we need to repopulate. Idempotent via ON CONFLICT.
$startIdx        = 0;
$cumulativeBytes = 0;
$cumulativeMs    = 0;
for ($i = 0; $i < $nPara; $i++) {
    $pp = $partPath($i);
    if (!is_file($pp) || filesize($pp) === 0) break;
    $bytes = file_get_contents($pp);
    if ($bytes === false || $bytes === '') break;
    $p = $paragraphs[$i];
    try {
        $insertMarker->execute([
            $audioKey,
            $p['paragraph_key']  !== null ? (int)$p['paragraph_key']  : null,
            $p['paragraph_page'] !== null ? (int)$p['paragraph_page'] : null,
            (int)$p['paragraph_number'],
            $cumulativeMs,
            $cumulativeBytes,
        ]);
    } catch (Exception $e) { /* don't fail resume if marker write fails */ }
    $cumulativeBytes += strlen($bytes);
    $cumulativeMs    += probeDurationMs($ffprobeBin, $bytes, $tmpDir);
    $startIdx = $i + 1;
}
if ($startIdx > 0) {
    fwrite(STDERR, "resuming from paragraph $startIdx (found $startIdx cached parts)\n");
    updateAudio($db, $audioKey, [
        'tts_audio_message'  => "Resuming at paragraph $startIdx / $nPara",
        'tts_audio_progress' => (int)floor($startIdx / max(1, $nPara) * 95) + 2,
    ]);
}

$charsBilled = 0;
$failureCount = 0;
$failures = [];

// Per-paragraph skip list: admins may delete an individual paragraph's
// audio via the Books-tab paragraph view. Those paragraph_numbers go into
// tts_audio_settings.skip_paragraphs and we honour them here.
$skipParaNums = array_flip(array_map('intval', $settings['skip_paragraphs'] ?? []));

// Carry the open bold/italic/paren state across paragraphs so a
// translation block that the PDF parser cut at a page break keeps its
// translation voice when it resumes in the next paragraph. Reset whenever
// the next paragraph is a chapter heading or an introOverride (those
// don't continue the prior format context).
$carryState = ['bold' => 0, 'italic' => 0, 'paren' => 0, 'bibStack' => []];

foreach ($paragraphs as $idx => $p) {
    // Honour admin's per-paragraph skip list.
    if (isset($skipParaNums[(int)$p['paragraph_number']])) {
        @unlink($partPath($idx));
        continue;
    }
    // Per-iteration cache check. If this paragraph's part already exists
    // (resume scenarios — pause/resume, restart-after-pause, or a single
    // missing part deleted by the admin's Redo button), reuse the cached
    // bytes instead of re-synthesising. The pre-loop scan above sets
    // startIdx and cumulative state for the contiguous-cached prefix; this
    // check covers the gap-fill case where parts exist NON-contiguously
    // (e.g. parts 0,2,3,4 cached but 1 was deleted by Redo).
    // Track reused-vs-synthed so the UI message shows "synthed 3 / reused 47"
    // rather than just "Paragraph 50 / 326" — which made a worker rapidly
    // walking through cached parts look like it was re-synthing each one.
    if (!isset($reusedCount)) $reusedCount = 0;
    if (!isset($synthCount))  $synthCount  = 0;
    $partFile = $partPath($idx);
    if (is_file($partFile) && filesize($partFile) > 0) {
        $paraBytes = file_get_contents($partFile);
        if ($paraBytes !== false && $paraBytes !== '') {
            $reusedCount++;
            $paraStartMs    = $cumulativeMs;
            $paraStartBytes = $cumulativeBytes;
            $paraStartPage  = $p['paragraph_page'] !== null ? (int)$p['paragraph_page'] : null;
            try {
                $insertMarker->execute([
                    $audioKey,
                    $p['paragraph_key']  !== null ? (int)$p['paragraph_key']  : null,
                    $paraStartPage,
                    (int)$p['paragraph_number'],
                    $paraStartMs,
                    $paraStartBytes,
                ]);
            } catch (Exception $e) { /* idempotent */ }
            $cumulativeBytes += strlen($paraBytes);
            $cumulativeMs    += probeDurationMs($ffprobeBin, $paraBytes, $tmpDir);
            // Light progress update — same shape as the post-synth path.
            $pct = (int)floor(($idx + 1) / max(1, $nPara) * 95) + 2;
            updateAudio($db, $audioKey, [
                'tts_audio_progress' => min(99, $pct),
                'tts_audio_message'  => sprintf('Paragraph %d / %d (synthed %d, reused %d, %d fails)', $idx + 1, $nPara, $synthCount, $reusedCount, $failureCount),
            ]);
            continue;
        }
    }

    // Status + supersession check every iteration. Pause must be
    // responsive, and the round-trip cost (~1ms) is dwarfed by the
    // Azure synth call we're about to make.
    //   'paused'  → exit cleanly; parts on disk stay for resume.
    //   'failed' / row gone → cancellation; ditto on parts.
    //   tts_audio_worker_pid != getmypid() → a Resume/Restart raced us
    //     and spawned a new worker; the old one bails so two workers
    //     never write to $partsDir simultaneously.
    $statusCheck = $db->prepare("SELECT tts_audio_status, tts_audio_worker_pid FROM yy_tts_audio WHERE tts_audio_key = ?");
    $statusCheck->execute([$audioKey]);
    $row = $statusCheck->fetch();
    $cur = $row ? $row['tts_audio_status'] : null;
    if ($cur === 'paused') {
        $pct = (int)floor($idx / max(1, $nPara) * 95) + 2;
        updateAudio($db, $audioKey, [
            'tts_audio_progress'   => min(99, $pct),
            'tts_audio_message'    => sprintf('Paused at paragraph %d / %d', $idx, $nPara),
            'tts_audio_worker_pid' => null,
        ]);
        fwrite(STDERR, "paused at idx=$idx — cached " . count(glob($partsDir . '/p*.mp3') ?: []) . " parts\n");
        exit(0);
    }
    if ($cur === 'failed' || $cur === null) {
        fwrite(STDERR, "cancelled at idx=$idx\n");
        exit(0);
    }
    $regPid = isset($row['tts_audio_worker_pid']) ? (int)$row['tts_audio_worker_pid'] : 0;
    if ($regPid > 0 && $regPid !== getmypid()) {
        fwrite(STDERR, "superseded by worker $regPid at idx=$idx — exiting\n");
        exit(0);
    }

    // Apply per-font filtering (skip + pause-on-switch) BEFORE
    // segmenting. The filter strips <span data-font="…"> tags either
    // way; skipped-font content is dropped; pause-marked fonts get a
    // PAUSE placeholder inserted that placeholdersToBreaks rewrites
    // into <break time="Nms"/> further down the pipeline.
    $rawHtml = (string)$p['paragraph_text_html'];
    $rawHtml = preprocessFontFilter($rawHtml, $cfg['fonts'] ?? []);

    // Chapter heading (the first paragraph in the chapter — typically
    // text like "1 Babel ~ Confusion"). Replace it with a synthesized
    // "Chapter N <pause> <title> <pause>" line so listeners hear the
    // chapter number announced. The configured __chapter_before__,
    // __chapter_between__, and __chapter_after__ pauses wrap the line.
    if ($idx === 0 && $chNum > 0) {
        // Detect which YY structure variant this paragraph follows:
        //
        //   (A) Legacy:  idx=0 is JUST the chapter number ("1"), idx=1 is
        //                the italic title line ("Babel ~ Confusion").
        //   (B) Newer:   idx=0 merges the number AND the title on one
        //                line ("1 Babel ~ Confusion"), idx=1 is the
        //                italic *subtitle* ("Corrupting by Commingling…").
        //
        // Found in s02v03 chapter 1: paragraph #44 = "1 Babel ~ Confusion",
        // paragraph #45 = "Corrupting by Commingling…". The old code path
        // dropped everything after the number, so the title was never
        // spoken (idx=1 was the subtitle, not the title — nothing read it).
        //
        // Rule: strip the leading "<num><sep>" prefix from the heading
        // paragraph; if anything alphabetic remains, that's the title
        // and it gets read after the "Chapter N" announcement. If only
        // the number was present, the title comes through idx=1 as before.
        $headingPlain = trim(preg_replace('/\s+/u', ' ', strip_tags($rawHtml)));
        $remainder    = preg_replace('/^\s*' . preg_quote((string)$chNum, '/') . '\s*[.\-:)]?\s*/u', '', $headingPlain);
        if ($remainder !== '' && $remainder !== $headingPlain && preg_match('/[\p{L}]/u', $remainder)) {
            // (B) Newer structure — read "Chapter N" then the merged title.
            $headingText =
                  "\x01PAUSE_0_{$pauseChapBefore}\x01"
                . "Chapter $chNum"
                . "\x01PAUSE_0_{$pauseChapBetween}\x01"
                . $remainder
                . "\x01PAUSE_0_{$pauseChapAfter}\x01";
        } else {
            // (A) Legacy structure — heading paragraph IS just the number;
            // the title is in idx=1 and will be wrapped by the subhead handler.
            $headingText =
                  "\x01PAUSE_0_{$pauseChapBefore}\x01"
                . "Chapter $chNum"
                . "\x01PAUSE_0_{$pauseChapAfter}\x01";
        }
        $segs = [['category' => 'main', 'text' => $headingText]];
    } else if (isset($introOverrides[(int)$p['paragraph_number']])) {
        // Paragraph was pre-classified by one of the prepass detectors:
        //   - Series-07 chapter intros → quran/bukhari/muslim/tabari/ishaq
        //   - Extended multi-paragraph italic blocks → quote
        // Either way, route the whole paragraph as a single voice block
        // in the matched category, bypassing segmentParagraph's default
        // bold-→-translation classifier.
        $plainText = trim(preg_replace('/\s+/u', ' ', strip_tags($rawHtml)));
        if ($plainText === '') continue;
        $segs = [['category' => $introOverrides[(int)$p['paragraph_number']], 'text' => $plainText]];
    } else {
        $segs = segmentParagraph($rawHtml, $carryState);
        if (!$segs) continue;

        // Subhead (italic paragraph right after the chapter heading).
        // YY chapters typically have a short italic subhead at idx=1
        // (e.g. "Corrupting by Commingling…"). Wrap it with the
        // configured __subhead_before__/_after__ pauses so it sits as a
        // clear beat between heading and body.
        if ($idx === 1 && preg_match('/<i\b/i', $rawHtml)) {
            $segs[0]['text']                       = "\x01PAUSE_0_{$pauseSubBefore}\x01" . $segs[0]['text'];
            $segs[count($segs) - 1]['text']       .= "\x01PAUSE_0_{$pauseSubAfter}\x01";
        }
    }

    // Does every segment route to an SSML (Azure) provider? If so, use the
    // original Azure path verbatim. Only a paragraph containing a self-hosted
    // engine segment takes the per-segment local path. Today all voices are
    // Azure (provider 1), so the original path always runs → byte-identical.
    $allSsml = true;
    foreach ($segs as $seg) {
        if (!ttsProviderUsesSsml($cfg, ttsResolveProviderKey($cfg, $seg['category']))) { $allSsml = false; break; }
    }

    // Drop skipped categories AND merge any adjacent same-category survivors
    // into a single segment. Without the merge, dropping interleaved word
    // definitions ((kai), (en), (hemera) ...) would leave 6+ tiny translation
    // fragments per paragraph; ElevenLabs hallucinates / fails on short
    // fragments and individual paragraphs would lose all their audio.
    $segs = ttsCollapseSkippedSegments($cfg, $segs);
    if (!$segs) continue;
    $paraBytes = '';
    if ($allSsml) {
        $blocks = '';
        foreach ($segs as $seg) {
            $blocks .= buildVoiceBlock($seg['text'], $cfg, $seg['category']);
        }
        if ($blocks === '') continue;
        $ssml = wrapSsml($blocks);
        if (strlen($ssml) > 9500) {
            // Over Azure's per-request limit — split into one synth call per segment instead.
            foreach ($segs as $seg) {
                $oneSsml = wrapSsml(buildVoiceBlock($seg['text'], $cfg, $seg['category']));
                $err = '';
                $b = azureTtsSynthesizeRetry($oneSsml, $cfg, $err);
                if ($b === '') {
                    $failures[] = "para {$p['paragraph_number']} seg: $err";
                    $failureCount++;
                    error_log("[tts-build $audioKey] para {$p['paragraph_number']} (idx $idx) SEG-AZURE failure: $err | text(120)=" . substr($seg['text'], 0, 120));
                    continue;
                }
                $paraBytes .= $b;
                $charsBilled += mb_strlen($seg['text']);
            }
        } else {
            $err = '';
            $b = azureTtsSynthesizeRetry($ssml, $cfg, $err);
            if ($b === '') {
                $failures[] = "para {$p['paragraph_number']}: $err";
                $failureCount++;
                error_log("[tts-build $audioKey] para {$p['paragraph_number']} (idx $idx) AZURE failure: $err | ssml(200)=" . substr($ssml, 0, 200));
                continue;
            }
            $paraBytes = $b;
            foreach ($segs as $seg) {
                $charsBilled += mb_strlen($seg['text']);
            }
        }
    } else {
        // Mixed / local path — synth each segment on its own engine and concat.
        // DORMANT until a category is pointed at a self-hosted voice whose engine
        // server is online. Naive byte-concat mirrors the Azure >9500 split;
        // cross-format / sample-rate normalization is a TODO before first real
        // local use.
        $nSeg = count($segs);
        foreach ($segs as $segI => $seg) {
            $pk = ttsResolveProviderKey($cfg, $seg['category']);
            $err = '';
            $transport = ttsProviderTransport($cfg, $pk);
            if ($transport === 'elevenlabs-cloud') {
                // buildElevenLabsSegment preserves <phoneme alphabet="ipa">
                // and <break time="..."/> tags inline (which ElevenLabs v3 /
                // flash_v2 / turbo_v2 all honor), unlike buildLocalSegment
                // which flattens phoneme tags to ASCII fallback for the
                // local engines (Chatterbox / CosyVoice) that don't speak SSML.
                $elSeg = buildElevenLabsSegment($seg['text'], $cfg, $seg['category']);
                $elSeg['provider_key'] = $pk;
                // Retry transient 429/5xx/network errors — long-form runs
                // routinely hit ElevenLabs' per-minute rate limit and would
                // otherwise leak ~10% of paragraphs to permanent failure.
                $b = elevenlabsTtsSynthesizeRetry($cfg, $elSeg, $cfg['system']['tts_output_format'] ?? 'audio-24khz-96kbitrate-mono-mp3', $err);
            } elseif ($transport === 'inworld-cloud') {
                // Inworld accepts plain text with inline /IPA/ slash notation.
                // buildInworldSegment emits /IPA/ for tunes typed 'ipa' (more
                // precise than the sub respelling and avoids the mid-word
                // stress-capital pause issue) and falls back to sub for tunes
                // typed 'sub'. 2k-char cap is enforced inside inworldTtsSynthesize.
                $iwSeg = buildInworldSegment($seg['text'], $cfg, $seg['category']);
                $iwSeg['provider_key'] = $pk;
                $b = inworldTtsSynthesize($cfg, $iwSeg, $cfg['system']['tts_output_format'] ?? 'audio-24khz-96kbitrate-mono-mp3', $err);
            } elseif ($transport === 'azure-ssml') {
                $b = azureTtsSynthesizeRetry(wrapSsml(buildVoiceBlock($seg['text'], $cfg, $seg['category'])), $cfg, $err);
            } else {
                // Chunk to keep each model.generate() call below the quadratic-
                // attention cliff, and assemble with a leading-only trim + clean
                // ffmpeg concat (see localTtsSynthesizeChunked). The trailing pad
                // protects the byte-concat joins downstream: a small breath mid-
                // paragraph at a voice switch, a longer one at the paragraph end
                // (where the part is byte-concatenated to the next paragraph).
                $isLastSeg = ($segI === $nSeg - 1);
                $b = localTtsSynthesizeChunked($cfg, buildLocalSegment($seg['text'], $cfg, $seg['category']), $cfg['system']['tts_output_format'], $err, $isLastSeg ? 240 : 90);
            }
            if ($b === '') {
                $failures[] = "para {$p['paragraph_number']} seg: $err";
                $failureCount++;
                error_log("[tts-build $audioKey] para {$p['paragraph_number']} (idx $idx) LOCAL/EL/IW failure: $err | cat={$seg['category']} transport=$transport text(120)=" . substr($seg['text'], 0, 120));
                continue;
            }
            $paraBytes .= $b;
            $charsBilled += mb_strlen($seg['text']);
        }
    }
    if ($paraBytes === '') continue;

    // Write this paragraph's marker BEFORE appending its bytes to the
    // output file, so the offset captures the position at which the
    // paragraph's audio starts in the concatenated file. ffprobe's
    // per-chunk read is roughly 5-15ms per paragraph — small compared
    // to Azure's network call so it doesn't materially slow the build.
    $paraStartMs    = $cumulativeMs;
    $paraStartBytes = $cumulativeBytes;
    $paraStartPage  = $p['paragraph_page'] !== null ? (int)$p['paragraph_page'] : null;
    try {
        $insertMarker->execute([
            $audioKey,
            $p['paragraph_key'] !== null ? (int)$p['paragraph_key'] : null,
            $paraStartPage,
            (int)$p['paragraph_number'],
            $paraStartMs,
            $paraStartBytes,
        ]);
    } catch (Exception $e) { /* don't fail the build if marker write fails */ }

    // Cache this paragraph's bytes BEFORE updating cumulative offsets, so
    // a crash between synth and write doesn't leave the offsets pointing
    // at a part file that doesn't exist on the next resume.
    $pp = $partPath($idx);
    @file_put_contents($pp, $paraBytes);
    @chmod($pp, 0664);   // belt-and-suspenders alongside the umask above
    $synthCount = ($synthCount ?? 0) + 1;
    $cumulativeBytes += strlen($paraBytes);
    $paragraphMs      = probeDurationMs($ffprobeBin, $paraBytes, $tmpDir);
    $cumulativeMs    += $paragraphMs;

    // Page-break-within-paragraph markers. Look ahead up to 5 pages —
    // far more than any real paragraph spans — and emit one marker per
    // crossed page boundary at the interpolated time offset.
    if ($paraStartPage !== null && $paragraphMs > 0) {
        $paraTextPlain = (string)($p['paragraph_text_plain'] ?? '');
        if ($paraTextPlain !== '') {
            $nextPageTexts = [];
            for ($k = 1; $k <= 5; $k++) {
                $pt = ttsLoadPageText($bundleDir, $paraStartPage + $k, $pageTextCache);
                if ($pt === '') break;
                $nextPageTexts[$k] = $pt;
            }
            if ($nextPageTexts) {
                $ratios = ttsFindPageBreakRatios($paraTextPlain, $nextPageTexts);
                foreach ($ratios as $kOffset => $ratio) {
                    $brkMs = (int)round($paraStartMs + $ratio * $paragraphMs);
                    try {
                        $insertMarker->execute([
                            $audioKey,
                            $p['paragraph_key'] !== null ? (int)$p['paragraph_key'] : null,
                            $paraStartPage + $kOffset,
                            (int)$p['paragraph_number'],
                            $brkMs,
                            null,
                        ]);
                    } catch (Exception $e) { /* skip */ }
                }
            }
        }
    }

    // Per-paragraph progress flush. Old throttle was every 5th iteration,
    // which on slow engines (Chatterbox ~5-20s/paragraph) left the UI
    // stuck on a stale percentage for a minute+ at a time. One write per
    // paragraph is ~1ms vs the seconds we just spent on synth — invisible.
    $pct = (int)floor(($idx + 1) / max(1, $nPara) * 95) + 2;
    updateAudio($db, $audioKey, [
        'tts_audio_progress'      => min(99, $pct),
        'tts_audio_message'       => sprintf('Paragraph %d / %d (synthed %d, reused %d, %d fails)', $idx + 1, $nPara, $synthCount, $reusedCount, $failureCount),
        'tts_audio_chars_billed'  => $charsBilled,
    ]);
}
// Concatenate every cached part into the final MP3. Parts are the
// authoritative source — they survive pause/resume and crashes, while
// the final file is just the byte-concat of whatever's in $partsDir
// at this moment. MP3 frames concatenate cleanly without re-encoding;
// the per-part probeDurationMs() above already accounted for any
// per-frame padding so $cumulativeMs is accurate.
$fh = fopen($finalPath, 'wb');
if (!$fh) bail($db, $audioKey, "cannot open $finalPath for write");
$concatBytes = 0;
for ($i = 0; $i < $nPara; $i++) {
    $pp = $partPath($i);
    if (!is_file($pp) || filesize($pp) === 0) continue;
    $bytes = file_get_contents($pp);
    if ($bytes === false || $bytes === '') continue;
    fwrite($fh, $bytes);
    $concatBytes += strlen($bytes);
}
fclose($fh);

$finalSize = filesize($finalPath);
if (!$finalSize) bail($db, $audioKey, "output file is empty (every paragraph failed); first errs: " . implode(' | ', array_slice($failures, 0, 3)));

// Probe duration with ffprobe if available.
$duration = null;
$ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?: '');
if ($ffprobe) {
    $out = shell_exec(escapeshellcmd($ffprobe) . ' -v error -show_entries format=duration -of default=nokey=1:noprint_wrappers=1 ' . escapeshellarg($finalPath) . ' 2>/dev/null');
    if ($out) $duration = (int)round((float)trim($out));
}

// Mark the file "live" only on a clean build (zero paragraph failures).
// The flipbook tts-audio.php endpoint gates the Play button on
// tts_audio_live_dtime being set — so a partial / error-laden build
// stays complete-but-not-live and the prior known-good audio (if any)
// keeps serving until a clean rebuild promotes the new file.
$liveNow = ($failureCount === 0) ? date('Y-m-d H:i:sO') : null;
updateAudio($db, $audioKey, [
    'tts_audio_status'          => 'complete',
    'tts_audio_progress'        => 100,
    'tts_audio_message'         => $failureCount ? "Done with $failureCount paragraph failure(s)" : 'Done',
    'tts_audio_path'            => $relPath,
    'tts_audio_size_bytes'      => $finalSize,
    'tts_audio_duration_secs'   => $duration,
    'tts_audio_completed_dtime' => date('Y-m-d H:i:sO'),
    'tts_audio_live_dtime'      => $liveNow,
    'tts_audio_error'           => $failures ? implode(' | ', array_slice($failures, 0, 5)) : null,
]);

// Refresh the volume's bundled mp3.zip so the flipbook's "Download MP3"
// button always serves the latest chapter set. Best-effort — a zip
// failure is logged but doesn't fail the build.
try {
    if (!rebuildVolumeMp3Zip($db, $volumeKey)) {
        fwrite(STDERR, "rebuildVolumeMp3Zip returned false for volume $volumeKey\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "rebuildVolumeMp3Zip failed: " . $e->getMessage() . "\n");
}

exit(0);

// segmentParagraph() lives in admin-tts-helpers.php so the preview
// endpoint can reuse the same bold/italic/parens-aware classifier the
// worker uses to route segments to category voices.
