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

// Final assembly builds a whole-chapter MP3 in memory-heavy steps (byte-concat
// + a full ffprobe packet index used to re-derive marker offsets). A multi-hour
// chapter has ~1M audio packets, so the default 128M PHP memory_limit fataled
// mid-assembly — AFTER writing the concat but BEFORE remux/completion — leaving
// the row stuck 'running' at ~97% forever (the watchdog just respawned it into
// the same fatal). This is a single, GPU-capped background job on a large-RAM
// host, so give it headroom and no wall-clock cap.
ini_set('memory_limit', '1024M');
set_time_limit(0);

// This is a background BATCH job: tell the GPU engine (via gpu-client.php's
// gpuSynthesize) to yield the single shared GPU to interactive auditions /
// previews so they never queue behind a whole chapter build. The engine's
// priority-aware synth lock lets an audition slip in between build paragraphs.
putenv('TTS_GPU_PRIORITY=batch');

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
            // strict reading order series → volume → chapter. Paragraph order is
            // handled inside the chapter build itself (the worker iterates
            // paragraph_number and gap-fills), so this completes
            // series → volume → chapter → paragraph. (Primary sort is
            // series_number, NOT tts_sort — a build must follow the books' reading
            // order regardless of which TTS voice system rendered it.)
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
                  JOIN yy_series  s ON s.series_key  = v.series_key
             LEFT JOIN yy_chapter c ON c.chapter_key = a.chapter_key
                 WHERE a.tts_audio_status = 'pending'
                   AND a.tts_audio_worker_pid IS NULL
                 ORDER BY s.series_number,
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

// A chapter with no narratable paragraphs (bibliography / back-matter /
// all-table / all-skip-paged) is NOT a build failure — there is simply
// nothing to synthesize. Marking it 'failed' left a red error in the
// admin panel and made the shutdown promoter + build watchdog retry it
// on every pass. Complete it benignly instead: 100%, zero audio, a
// message that explains why, and NO audio path (so no broken player).
function completeEmpty(PDO $db, int $audioKey, string $why): void {
    updateAudio($db, $audioKey, [
        'tts_audio_status'          => 'complete',
        'tts_audio_progress'        => 100,
        'tts_audio_message'         => $why,
        'tts_audio_error'           => null,
        'tts_audio_duration_secs'   => 0,
        'tts_audio_paragraph_count' => 0,
        'tts_audio_completed_dtime' => date('Y-m-d H:i:sO'),
    ]);
    fwrite(STDERR, "EMPTY-OK: $why\n");
    exit(0);
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
// Output filename keys on (volume, chapter POSITION, profile) so it is
// unique across three collision sources: (1) named front/back-matter
// chapters that all carry chapter_number 0, (2) duplicate chapter numbers,
// and (3) the same chapter built under different voice profiles. We use
// the chapter's 1-based offset in the volume's ordered chapter list — the
// same ordering the TTS endpoints use — NOT chapter_number (which is
// 0/duplicate for named chapters). Per-paragraph parts live under
// u/tts-parts/<audioKey>/ keyed by the audio row, so they never collide
// regardless of this name.
$chapterIndex = 0;
if ($chapterKey > 0) {
    $ordStmt = $db->prepare("SELECT chapter_key FROM yy_chapter WHERE volume_key = ? ORDER BY chapter_sort NULLS FIRST, chapter_number NULLS FIRST, chapter_key");
    $ordStmt->execute([$volumeKey]);
    $pos = 0;
    foreach ($ordStmt->fetchAll(PDO::FETCH_COLUMN) as $ck) {
        $pos++;
        if ((int)$ck === $chapterKey) { $chapterIndex = $pos; break; }
    }
}
$profPart = 'p' . (int)$jobProfileKey;
$baseName = ($chapterKey > 0 && $chapterIndex > 0)
    ? sprintf('%s-c%02d-%s.%s', $volumeSlug, $chapterIndex, $profPart, $ext)
    : sprintf('%s-cbook-%s.%s', $volumeSlug, $profPart, $ext);
$finalPath = $outDir . '/' . $baseName;
$relPath   = '/u/tts-audio/' . $baseName;

// Load paragraphs. paragraph_page is included so we can emit a row in
// yy_tts_audio_marker tying each paragraph's audio offset to its page
// — flipbook-tts.js uses these to auto-turn pages as playback advances.
$pStmt = $db->prepare("SELECT paragraph_key, paragraph_number, paragraph_page, paragraph_text_html, paragraph_text_plain, paragraph_is_table, paragraph_is_continuation FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
$pStmt->execute([$chapterKey]);
$paragraphs = $pStmt->fetchAll();

// ── Full-chapter paragraph numbering (for the live progress message) ──
// The Books-tab paragraph panel numbers EVERY paragraph in the chapter
// 1..N by position (its "#" column) and reports the full count as the
// total — including the continuations, tables and skip-page rows that the
// synth loop below coalesces or filters out. Capture that full ordering
// NOW, before the coalesce/filter shrinks $paragraphs, so the "Paragraph
// N / M" progress the worker writes lines up exactly with what the admin
// sees when they expand the chapter (the "working item" indicator jumps to
// that row by position). $fullParaPos maps paragraph_number → 1-based
// position across the whole chapter; $fullChapterCount is the total.
$fullChapterCount = count($paragraphs);
$fullParaPos = [];
foreach ($paragraphs as $fi => $fp) { $fullParaPos[(int)$fp['paragraph_number']] = $fi + 1; }

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
        // Char offset where THIS tail begins within the combined plain text —
        // captured BEFORE the append below. Used as the fallback page-break
        // ratio (char_at / total) when the fuzzy text match can't pin the
        // offset, so a failed match never drops the continuation marker.
        $headCharAt = mb_strlen(rtrim((string)$head['paragraph_text_plain'])
                                . ($tailPlain !== '' ? ' ' : ''));
        // Single-space separator preserves natural spacing without
        // letting two spaces double up.
        if ($tailHtml !== '') {
            $head['paragraph_text_html']  = rtrim((string)$head['paragraph_text_html'])  . ' ' . ltrim($tailHtml);
        }
        if ($tailPlain !== '') {
            $head['paragraph_text_plain'] = rtrim((string)$head['paragraph_text_plain']) . ' ' . ltrim($tailPlain);
        }
        // Remember which continuation paragraph BEGINS each page this
        // logical paragraph wraps onto, keyed by that page. The page-break
        // marker emitted further down references this tail (its
        // paragraph_key/number) rather than the head, so the viewer joins
        // the page's real text for read-along highlight and QA aligns the
        // onset to the right paragraph. First tail to open a page wins.
        $tailPage = $p['paragraph_page'] !== null ? (int)$p['paragraph_page'] : null;
        if ($tailPage !== null && !isset($head['_cont_by_page'][$tailPage])) {
            $head['_cont_by_page'][$tailPage] = [
                'key'     => $p['paragraph_key'] !== null ? (int)$p['paragraph_key'] : null,
                'number'  => (int)$p['paragraph_number'],
                'char_at' => $headCharAt,
            ];
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
//   • trailing back matter — the closing RESOURCES page (site links,
//     contact, cover credit, version stamp). It has no numeric heading,
//     so the parser maps it into the LAST chapter; without this it gets
//     narrated as that chapter's ending. Detected by content, so it stays
//     out even if a re-parse shifts the page numbers.
$backMatterFrom = ttsBackMatterCutoff($db, (int)$volumeKey);
$skipRangesStmt = $db->prepare("SELECT volume_skip_pages FROM yy_volume WHERE volume_key = ?");
$skipRangesStmt->execute([$volumeKey]);
$skipPagesRaw = (string)($skipRangesStmt->fetchColumn() ?: '');
$skipRanges = [];
foreach (preg_split('/\s*,\s*/', $skipPagesRaw, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
    if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $tok, $m))      $skipRanges[] = [(int)$m[1], (int)$m[2]];
    elseif (preg_match('/^\s*(\d+)\s*$/', $tok, $m))              $skipRanges[] = [(int)$m[1], (int)$m[1]];
}
$beforeCount = count($paragraphs);
$paragraphs = array_values(array_filter($paragraphs, function ($p) use ($skipRanges, $backMatterFrom) {
    if (!empty($p['paragraph_is_table'])) return false;
    if ($backMatterFrom !== null && (int)$p['paragraph_number'] >= $backMatterFrom) return false;
    $pg = (int)($p['paragraph_page'] ?? 0);
    foreach ($skipRanges as $r) if ($pg >= $r[0] && $pg <= $r[1]) return false;
    return true;
}));
$skipped = $beforeCount - count($paragraphs);
if ($skipped > 0) {
    fwrite(STDERR, "skipped $skipped paragraph(s): " . count(array_filter($paragraphs, fn($p) => !empty($p['paragraph_is_table']))) . " table-flagged, ranges=" . $skipPagesRaw
        . ($backMatterFrom !== null ? ", back-matter from paragraph $backMatterFrom" : '') . "\n");
}
$nPara = count($paragraphs);
if (!$nPara) completeEmpty($db, $audioKey, "No narratable content (bibliography / back-matter / table / skip pages) — nothing to synthesize; chapter_key=$chapterKey, $beforeCount paragraph(s) all filtered.");

// Map a filtered (synth-loop) index to the paragraph's full-chapter
// position, so progress messages report the same numbering the expanded
// Books-tab panel shows. Falls back to filtered index + 1 if a number is
// somehow absent from the map. $paragraphs is the post-filter list here.
$fullPosOfFiltered = function (int $filteredIdx) use (&$paragraphs, $fullParaPos, $fullChapterCount) {
    if ($filteredIdx < 0) return 0;
    if ($filteredIdx >= count($paragraphs)) return $fullChapterCount;
    $pn = (int)$paragraphs[$filteredIdx]['paragraph_number'];
    return $fullParaPos[$pn] ?? ($filteredIdx + 1);
};

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
    // Skip paragraphs the parser already colour-tagged (data-style="kampf"/
    // "kjv"/"nt"/…). Those carry their own category voice through
    // segmentParagraph's per-span classifier; this fallback exists only for
    // quotes whose italic/colour styling was DROPPED in docx→pdf conversion.
    // Firing here would flatten a styled quote (e.g. olive Mein-Kampf /
    // citation text → Voice A) to the generic 'quote' voice AND swallow any
    // plain narration that shares the paragraph. (s06 Twistianity ch.3 p44-45.)
    if (stripos($html, 'data-style=') !== false) continue;
    $plain = trim((string)$p['paragraph_text_plain']);
    if ($plain === '' || mb_substr($plain, 0, 1) !== "\u{201C}") continue;
    if (mb_substr($plain, -1) === "\u{201D}") continue;  // self-contained quote — likely body prose
    // Require a genuinely OPEN quote: more opening “ than closing ” in this
    // paragraph. A paragraph that merely starts with a short quoted word
    // (“Christ” is not a last name…) is fully balanced and must NOT be read
    // as the head of a multi-paragraph block quote. (s06 Twistianity p56.)
    if (substr_count($plain, "\u{201C}") <= substr_count($plain, "\u{201D}")) continue;
    // Walk forward up to 30 paragraphs looking for the closing quote.
    $end = -1;
    for ($j = $k + 1; $j < $nPara && $j < $k + 30; $j++) {
        $q = $paragraphs[$j];
        if (isset($introOverrides[(int)$q['paragraph_number']])) break;
        $qHtml  = (string)$q['paragraph_text_html'];
        if (preg_match('/<b\b/i', $qHtml)) break;        // a bold paragraph ends the quote stream
        if (stripos($qHtml, 'data-style=') !== false) break; // styled → segmentParagraph handles it
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

// ── Multi-paragraph Mein Kampf quote continuation ──────────────────────
// A Hitler quotation can span a real paragraph break: the "Mein Kampf:<ref>"
// label + opening “ sit in paragraph P (already voiced Adolph by
// segmentParagraph's kampf retag), but the closing ” lands in the NEXT
// paragraph Q, which carries no kampf style and would otherwise route to
// 'main' (the Winn narrator) — only half the quote reads in Hitler's voice.
//
// The source colour-styles Hitler quotes but is INCONSISTENT about closing ”
// marks, so an open-quote state CANNOT be carried safely across paragraphs: a
// dropped ” would cascade Adolph through pages of narration (verified: ck345
// ¶3936 leaves a quote unclosed and would swallow ¶3938-3949). Instead this
// uses tight, adjacent-pair guards that a narration paragraph cannot satisfy:
//   • P ends INSIDE an open MK quote — its last segment is 'kampf' and, after
//     P's last "Mein Kampf:" label, its kampf spans hold more “ than ”;
//   • Q has NO styled content (no data-style, no bold) — a plain continuation;
//   • Q ENDS with ” and net-CLOSES the quote (more ” than “).
// Verified corpus-wide: 1 match, 0 false positives (s07v03 ck348 ¶371→372).
$mkIsLabel = function (array $s): bool {
    return $s['category'] === 'kampf' && preg_match('/^\s*Mein\s+Kampf\s*:/iu', $s['text']);
};
for ($k = 0; $k + 1 < $nPara; $k++) {
    $P = $paragraphs[$k];
    if (!empty($P['paragraph_is_table'])) continue;
    $pHtml = (string)$P['paragraph_text_html'];
    if (stripos($pHtml, 'kampf') === false) continue;          // cheap precheck
    $tmpCarry = [];
    $pSegs = segmentParagraph(preprocessFontFilter($pHtml, $cfg['fonts'] ?? []), $tmpCarry);
    if (!$pSegs) continue;
    $last = end($pSegs);
    if (($last['category'] ?? '') !== 'kampf') continue;
    $labelIdx = -1;
    foreach ($pSegs as $ix => $s) if ($mkIsLabel($s)) $labelIdx = $ix;
    if ($labelIdx < 0) continue;
    $bal = 0;                                                  // net “/” in P's kampf spans after its last label
    for ($ix = $labelIdx + 1; $ix < count($pSegs); $ix++) {
        if (($pSegs[$ix]['category'] ?? '') === 'kampf') {
            $bal += substr_count($pSegs[$ix]['text'], "\u{201C}") - substr_count($pSegs[$ix]['text'], "\u{201D}");
        }
    }
    if ($bal <= 0) continue;                                   // P closes its own quote — nothing carries
    // Next non-table paragraph is the candidate continuation Q.
    $j = $k + 1;
    while ($j < $nPara && !empty($paragraphs[$j]['paragraph_is_table'])) $j++;
    if ($j >= $nPara) continue;
    $Q = $paragraphs[$j];
    if (isset($introOverrides[(int)$Q['paragraph_number']])) continue;
    $qHtml = (string)$Q['paragraph_text_html'];
    if (stripos($qHtml, 'data-style=') !== false) continue;    // styled → not a plain continuation
    if (preg_match('/<b\b/i', $qHtml)) continue;               // bold → translation, not a Hitler quote
    $qPlain = trim((string)$Q['paragraph_text_plain']);
    if ($qPlain === '' || mb_substr($qPlain, -1) !== "\u{201D}") continue;                 // must close with ”
    if (substr_count($qPlain, "\u{201D}") <= substr_count($qPlain, "\u{201C}")) continue;  // must net-close
    $introOverrides[(int)$Q['paragraph_number']] = 'kampf';
    fwrite(STDERR, sprintf("mein-kampf continuation: paragraph %d tagged 'kampf' (continues %d)\n",
        (int)$Q['paragraph_number'], (int)$P['paragraph_number']));
}

// ── Quran multi-translation block detection ────────────────────────────
// A single Quran verse is sometimes quoted with several named English
// translations, each on its own line: "Yusuf Ali: …", "Pickthal: …",
// "Ahmed Ali: …", etc. (Surah 111 in s07v02 p213-214; Al-Ikhlas in
// several volumes). Each such line is tagged with its per-translator
// category (a child of 'quran' in ttsCategories) so it reads in that
// translator's voice, falling back child → quran → islam when the child
// has no configured voice. The verse-citation line itself ("Quran
// 111.001") is data-style="quran" and already routes to the Quran voice
// through segmentParagraph.
//
// Runs for ALL series (these blocks also appear in s06). Page-break
// continuation tails were already merged into their head paragraph by the
// coalesce pass above, so a translation that wraps a page is one
// contiguous line here — no continuation handling needed.
//
// Rule (verified corpus-wide: 7 blocks / 56 lines, 0 misses / 0 false
// positives): a translation line is a paragraph whose plain text begins
// with an optional "The " + a translator name from the CLOSED dictionary
// below, then ":". A block is a run of >=2 such lines; a verse citation
// or any other paragraph ends the run. The >=2 requirement stops a stray
// prose colon ("Muslim: they said…") from hijacking a lone paragraph.
static $QURAN_TRANSLATORS = [
    'Yusuf Ali'   => 'yusuf_ali',
    'Noble Quran' => 'noble_quran',
    'Pickthal'    => 'pickthal',
    'Shakir'      => 'shakir',
    'Ahmed Ali'   => 'ahmed_ali',
];
$qtAlt = implode('|', array_map(function ($n) { return preg_quote($n, '/'); },
    array_keys($QURAN_TRANSLATORS)));
$qtCat = function (string $plain) use ($qtAlt, $QURAN_TRANSLATORS): ?string {
    if (preg_match('/^\s*(?:The\s+)?(' . $qtAlt . ')\s*:/u', $plain, $m)) {
        return $QURAN_TRANSLATORS[$m[1]];
    }
    // Verbose Word-by-Word form: "The Quran Word By Word is also mistaken:"
    if (preg_match('/^\s*(?:The\s+)?(?:Quran\s+)?Word[\s-]?by[\s-]?Word\b[^:]{0,40}:/ui', $plain)) {
        return 'word_by_word';
    }
    return null;
};
$qtRun = [];  // pending run: list of [paragraph_index, category]
$qtFlush = function () use (&$qtRun, &$introOverrides, &$paragraphs) {
    if (count($qtRun) >= 2) {
        foreach ($qtRun as $e) {
            $pn = (int)$paragraphs[$e[0]]['paragraph_number'];
            // Don't clobber a category an earlier prepass already assigned.
            if (!isset($introOverrides[$pn])) $introOverrides[$pn] = $e[1];
        }
        fwrite(STDERR, sprintf(
            "quran translations: %d line(s) tagged as per-translator voices (paras %d..%d)\n",
            count($qtRun),
            (int)$paragraphs[$qtRun[0][0]]['paragraph_number'],
            (int)$paragraphs[$qtRun[count($qtRun) - 1][0]]['paragraph_number']
        ));
    }
    $qtRun = [];
};
for ($k = 0; $k < $nPara; $k++) {
    $p = $paragraphs[$k];
    if (isset($introOverrides[(int)$p['paragraph_number']])) { $qtFlush(); continue; }
    $cat = $qtCat(trim((string)$p['paragraph_text_plain']));
    if ($cat !== null) { $qtRun[] = [$k, $cat]; continue; }
    $qtFlush();
}
$qtFlush();

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
        // Binary-search the LARGEST suffix length whose text still appears in
        // the next page (within the ~400-char header-noise window). "Matches"
        // is monotonic — any suffix longer than this page's own portion reaches
        // back into the PREVIOUS page's text, which isn't present here — so the
        // largest matching suffix pins the break to the exact character. The
        // old geometric ×0.85 shrink stopped at the first match on a coarse
        // ladder, which routinely overshot the boundary by up to ~15% of the
        // tail and dropped the first words of the new page from its audio.
        // Floor at 25 chars to avoid spurious matches on common short phrases.
        $lo = 25; $hi = $plen; $bestL = -1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $pos = mb_strpos($g, mb_substr($p, -$mid));
            if ($pos !== false && $pos < 400) { $bestL = $mid; $lo = $mid + 1; }
            else                              { $hi  = $mid - 1; }
        }
        if ($bestL < 0) break;   // paragraph doesn't extend further
        $bestRatio = ($plen - $bestL) / $plen;
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

// Pre-loop resume scan — COUNT ONLY. Find the length of the contiguous run
// of already-cached parts so we can show a meaningful "Resuming at N" message
// and progress %. Do NOT accumulate cumulative offsets or insert markers here:
// the main loop below reprocesses EVERY paragraph (cached parts via its
// reuse branch, missing parts via synth) and is the single source of
// cumulative byte/ms offsets and of all markers. Accumulating in both the
// scan AND the loop double-counted the offsets — inflating every marker's
// byte_offset past EOF, which made the post-loop byte→pts remap clamp most
// offset_ms values to the chapter end (and the scan also skipped page-break
// continuation markers for cached parts). See the loop's reuse branch.
$startIdx        = 0;
$cumulativeBytes = 0;
$cumulativeMs    = 0;
for ($i = 0; $i < $nPara; $i++) {
    $pp = $partPath($i);
    if (!is_file($pp) || filesize($pp) === 0) break;
    $startIdx = $i + 1;
}
if ($startIdx > 0) {
    fwrite(STDERR, "resuming from paragraph $startIdx (found $startIdx contiguous cached parts)\n");
    updateAudio($db, $audioKey, [
        'tts_audio_message'  => 'Resuming at paragraph ' . $fullPosOfFiltered($startIdx) . ' / ' . $fullChapterCount,
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

// "Earlier redo jumps ahead of our later paragraphs." A 'Redo' re-queues an
// already-built chapter/paragraph as 'pending'; with one build slot it then
// waits until THIS worker exits. To honour reading order without interrupting
// the paragraph that's already rendering, the per-paragraph status check below
// asks once per iteration: is any 'pending' build waiting on the slot whose
// reading-order location (series → volume → chapter, NULL chapter = whole-book =
// first) sorts strictly before ours? This mirrors the promote-next ORDER BY in
// the shutdown hook exactly. The comparison is column-vs-column (self-join to
// our own row) so column types line up and there's no param-cast ambiguity;
// '-Infinity' replays the promoter's "chapter NULLS FIRST". Strict '<' over a
// total order means no two rows can yield to each other → no ping-pong.
$earlierPendingStmt = $db->prepare("
    SELECT a.tts_audio_key
      FROM yy_tts_audio a
      JOIN yy_tts     t ON t.tts_key     = a.tts_key
      JOIN yy_volume  v ON v.volume_key  = a.volume_key
      JOIN yy_series  s ON s.series_key  = v.series_key
 LEFT JOIN yy_chapter c ON c.chapter_key = a.chapter_key,
           yy_tts_audio m
      JOIN yy_tts     mt ON mt.tts_key     = m.tts_key
      JOIN yy_volume  mv ON mv.volume_key  = m.volume_key
      JOIN yy_series  ms ON ms.series_key  = mv.series_key
 LEFT JOIN yy_chapter mc ON mc.chapter_key = m.chapter_key
     WHERE m.tts_audio_key = :me
       AND a.tts_audio_status = 'pending'
       AND a.tts_audio_worker_pid IS NULL
       AND a.tts_audio_key <> :me
       AND ROW(s.series_number, v.volume_sort, v.volume_number,
               COALESCE(c.chapter_sort,  '-Infinity'::double precision),
               COALESCE(c.chapter_number,'-Infinity'::double precision))
         < ROW(ms.series_number, mv.volume_sort, mv.volume_number,
               COALESCE(mc.chapter_sort,  '-Infinity'::double precision),
               COALESCE(mc.chapter_number,'-Infinity'::double precision))
     LIMIT 1
");

// Emit any page-break-within-paragraph continuation markers (byte_offset
// NULL, time interpolated by char-ratio) for one paragraph. Factored out so
// BOTH the synth path and the cache-reuse path call it — previously only the
// synth path emitted these, so a gap-fill / resume that reused cached parts
// silently dropped every continuation marker for those paragraphs (and the
// "Retry/Redo" path, which resumes, dropped the whole chapter's set).
$emitPageBreakMarkers = function (array $p, ?int $paraStartPage, int $paraStartMs, int $paragraphMs)
        use ($audioKey, $insertMarker, $bundleDir, &$pageTextCache): void {
    if ($paraStartPage === null || $paragraphMs <= 0) return;
    $paraTextPlain = (string)($p['paragraph_text_plain'] ?? '');
    if ($paraTextPlain === '') return;
    $totalLen = max(1, mb_strlen($paraTextPlain));
    $nextPageTexts = [];
    for ($k = 1; $k <= 5; $k++) {
        $pt = ttsLoadPageText($bundleDir, $paraStartPage + $k, $pageTextCache);
        if ($pt === '') break;
        $nextPageTexts[$k] = $pt;
    }
    // Text-matched ratios pin the exact break point per page; keyed by page
    // OFFSET (1..5) from $paraStartPage. May be empty/partial when the flipbook
    // text layer diverges (special glyphs, Hebrew transliteration, headers).
    $ratios = $nextPageTexts ? ttsFindPageBreakRatios($paraTextPlain, $nextPageTexts) : [];

    // The AUTHORITATIVE set of page crossings is $_cont_by_page, recorded at
    // coalesce time from the parser's continuation flags — we KNOW a
    // continuation paragraph begins on each of these pages regardless of whether
    // the fuzzy text match succeeded. Emit a marker for every one: use the
    // text-matched ratio when available, else fall back to the char-offset ratio
    // (char_at / total) so a failed match NEVER drops the marker. Historically
    // this relied solely on $ratios, so ~31% of continuation pages got no marker
    // and the flipbook started their audio at the next full paragraph.
    $byPage = $p['_cont_by_page'] ?? [];
    if (!$byPage) {
        // Un-split paragraph that merely overflows a page (no continuation row):
        // keep the prior behaviour — matched ratios only, keyed to the head.
        foreach ($ratios as $kOffset => $ratio) {
            $crossedPage = $paraStartPage + $kOffset;
            $brkMs = (int)round($paraStartMs + $ratio * $paragraphMs);
            $mkKey = $p['paragraph_key'] !== null ? (int)$p['paragraph_key'] : null;
            $mkNum = (int)$p['paragraph_number'];
            try {
                $insertMarker->execute([$audioKey, $mkKey, $crossedPage, $mkNum, $brkMs, null]);
            } catch (Exception $e) { /* skip */ }
        }
        return;
    }
    foreach ($byPage as $crossedPage => $cont) {
        $kOffset = (int)$crossedPage - $paraStartPage;
        // The coalesce pass recorded the EXACT character at which this
        // continuation (and therefore this page) begins in the combined text
        // ($cont['char_at']) — that is authoritative. The fuzzy text match only
        // approximates it, so prefer char_at here and fall back to the matched
        // ratio (then to head start) only when char_at is somehow unavailable.
        // Both paths feed the same linear char→time interpolation below, so the
        // exact character is strictly the better input.
        $ratio = (isset($cont['char_at']) && $totalLen > 0)
               ? min(0.999, max(0.0, $cont['char_at'] / $totalLen))
               : ($ratios[$kOffset] ?? null);
        if ($ratio === null) {
            continue;   // no exact break and no text match — don't guess
        }
        $brkMs = (int)round($paraStartMs + $ratio * $paragraphMs);
        // Key to the continuation paragraph that begins this page (not the head)
        // so tts-audio.php joins the page's own text for read-along highlight and
        // the per-segment STT onset fixer aligns to the right paragraph.
        $mkKey = $cont['key'] ?? ($p['paragraph_key'] !== null ? (int)$p['paragraph_key'] : null);
        $mkNum = (int)$cont['number'];
        try {
            $insertMarker->execute([$audioKey, $mkKey, (int)$crossedPage, $mkNum, $brkMs, null]);
        } catch (Exception $e) { /* skip */ }
    }
};

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
            $paragraphMs      = probeDurationMs($ffprobeBin, $paraBytes, $tmpDir);
            $cumulativeMs    += $paragraphMs;
            // Same page-break continuation markers the synth path emits — so a
            // gap-fill / resume that reuses this cached part keeps them.
            $emitPageBreakMarkers($p, $paraStartPage, $paraStartMs, $paragraphMs);
            // Light progress update — same shape as the post-synth path.
            $pct = (int)floor(($idx + 1) / max(1, $nPara) * 95) + 2;
            updateAudio($db, $audioKey, [
                'tts_audio_progress' => min(99, $pct),
                'tts_audio_message'  => sprintf('Paragraph %d / %d (synthed %d, reused %d, %d fails)', $fullPosOfFiltered($idx), $fullChapterCount, $synthCount, $reusedCount, $failureCount),
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
            'tts_audio_message'    => sprintf('Paused at paragraph %d / %d', $fullPosOfFiltered($idx), $fullChapterCount),
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
    // Yield to an earlier-in-the-book redo (see $earlierPendingStmt above).
    // We don't touch the paragraph that already rendered — we stop at this
    // boundary, re-queue ourselves as 'pending' (parts stay cached, so the
    // resume scan gap-fills) and exit. The shutdown promoter then spawns the
    // earlier 'pending' row (it sorts first); when it completes, the promoter
    // picks us back up as the new earliest pending and we resume from the
    // first uncached paragraph. Runs only at the synth frontier (cached parts
    // already 'continue'd above), so a worker walking its cache doesn't yield
    // until it's actually about to start new synthesis.
    $earlierPendingStmt->execute([':me' => $audioKey]);
    $earlierKey = (int)$earlierPendingStmt->fetchColumn();
    if ($earlierKey > 0) {
        $pct = (int)floor($idx / max(1, $nPara) * 95) + 2;
        updateAudio($db, $audioKey, [
            'tts_audio_status'     => 'pending',
            'tts_audio_progress'   => min(99, $pct),
            'tts_audio_message'    => sprintf('Yielded at paragraph %d / %d — earlier redo (#%d) jumps ahead', $fullPosOfFiltered($idx), $fullChapterCount, $earlierKey),
            'tts_audio_worker_pid' => null,
        ]);
        fwrite(STDERR, "yielding at idx=$idx to earlier pending build $earlierKey\n");
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
    // Chapter heading (idx 0). Which treatment it gets is decided by the
    // heading's OWN text, not chapter_number:
    //
    //   • Heading OPENS WITH A NUMBER → a numbered chapter. Announce
    //     "Chapter N" using that number. Two sub-structures:
    //       (A) Legacy:  idx=0 is JUST the number ("6"); the title is idx=1
    //                    ("A Voice") and the subhead handler wraps it.
    //       (B) Newer:   idx=0 merges number AND title ("1 Babel ~ Confusion");
    //                    read "Chapter 1" then "Babel ~ Confusion". (idx=1 is
    //                    then the subtitle.) Found in s02v03 chapter 1.
    //   • Heading is NON-NUMERIC ("Afterword", "Topical Appendix",
    //     "Bibliography") → a NAMED front/back-matter section. Do NOT say
    //     "Chapter N"; fall through to the normal path so the name is spoken
    //     verbatim as written.
    //
    // Keyed on the heading TEXT (not $chNum) so named sections read correctly
    // regardless of the ordinal we store for sorting/display.
    $headingPlain = ($idx === 0) ? trim(preg_replace('/\s+/u', ' ', strip_tags($rawHtml))) : '';
    if ($idx === 0 && preg_match('/^(\d+)\s*[.\-:)]?\s*(.*)$/u', $headingPlain, $hm)) {
        $headNum   = $hm[1];
        $remainder = trim($hm[2]);
        if ($remainder !== '' && preg_match('/[\p{L}]/u', $remainder)) {
            // (B) Number AND title on one line — "Chapter N" then the title.
            $headingText =
                  "\x01PAUSE_0_{$pauseChapBefore}\x01"
                . "Chapter $headNum"
                . "\x01PAUSE_0_{$pauseChapBetween}\x01"
                . $remainder
                . "\x01PAUSE_0_{$pauseChapAfter}\x01";
        } else {
            // (A) Number-only heading — title comes through idx=1.
            $headingText =
                  "\x01PAUSE_0_{$pauseChapBefore}\x01"
                . "Chapter $headNum"
                . "\x01PAUSE_0_{$pauseChapAfter}\x01";
        }
        // Advance the segmentation carry through the heading's real markup so
        // an open delimiter can't leak into the body (see the introOverride
        // note below). Headings sit at chapter start so this is normally a
        // no-op, but it keeps the carry contract identical across all branches.
        segmentParagraph($rawHtml, $carryState);
        ttsBoundCarryAtParagraphEnd($rawHtml, $carryState);
        $segs = [['category' => 'main', 'text' => $headingText]];
    } else if ($idx === 0 && $headingPlain !== '') {
        // Named front/back-matter heading ("AFTERWORD", "TOPICAL APPENDIX",
        // "BIBLIOGRAPHY"). Read the name verbatim — but normalize an ALL-CAPS
        // heading to Title Case first, so the engine voices it as a word
        // ("Afterword") instead of mis-reading the all-caps run and prepending
        // a spurious letter ("A Afterword"). Mixed-case headings are left
        // exactly as written. Wrap in the chapter pauses like the numbered
        // branch for a consistent lead-in / lead-out.
        $named = (mb_strtoupper($headingPlain, 'UTF-8') === $headingPlain)
                 ? mb_convert_case($headingPlain, MB_CASE_TITLE, 'UTF-8')
                 : $headingPlain;
        $headingText =
              "\x01PAUSE_0_{$pauseChapBefore}\x01"
            . $named
            . "\x01PAUSE_0_{$pauseChapAfter}\x01";
        segmentParagraph($rawHtml, $carryState);
        ttsBoundCarryAtParagraphEnd($rawHtml, $carryState);
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
        // ⚠ Advance the segmentation carry through this paragraph's REAL markup
        // even though we override its category. Otherwise an open delimiter
        // (paren / bib / bold) opened BEFORE this quote/intro block leaks
        // straight past it — the next body paragraph inherits the open state,
        // routes to word_definition (read_flag=false) and is dropped with NO
        // audio, cascading to the end of the chapter. This silently lost
        // paras 207-352 of s02v01 "Composition and Methodology" (body after an
        // extended-quote block whose preceding paragraph ended mid-definition).
        segmentParagraph($rawHtml, $carryState);
        ttsBoundCarryAtParagraphEnd($rawHtml, $carryState);
        $segs = [['category' => $introOverrides[(int)$p['paragraph_number']], 'text' => $plainText]];
    } else {
        $segs = segmentParagraph($rawHtml, $carryState);
        // A complete paragraph may not leak an open delimiter into the next one
        // — a source typo (a definition missing its ")") would otherwise silence
        // every paragraph after it. See ttsBoundCarryAtParagraphEnd().
        ttsBoundCarryAtParagraphEnd($rawHtml, $carryState);
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
    // Drop orphan segments with no speakable content (lone "ʾ"/"ʿ" transliteration
    // marks or stray punctuation stranded between two dropped word definitions).
    // The local engine 400s on these as "empty text" and the whole paragraph
    // would be marked failed → "— not yet synthesised —". See helper for detail.
    $segs = ttsDropUnspeakableSegments($segs);
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

    // Page-break-within-paragraph markers — same emitter the reuse path uses.
    $emitPageBreakMarkers($p, $paraStartPage, $paraStartMs, $paragraphMs);

    // Per-paragraph progress flush. Old throttle was every 5th iteration,
    // which on slow engines (Chatterbox ~5-20s/paragraph) left the UI
    // stuck on a stale percentage for a minute+ at a time. One write per
    // paragraph is ~1ms vs the seconds we just spent on synth — invisible.
    $pct = (int)floor(($idx + 1) / max(1, $nPara) * 95) + 2;
    updateAudio($db, $audioKey, [
        'tts_audio_progress'      => min(99, $pct),
        'tts_audio_message'       => sprintf('Paragraph %d / %d (synthed %d, reused %d, %d fails)', $fullPosOfFiltered($idx), $fullChapterCount, $synthCount, $reusedCount, $failureCount),
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

// Re-derive marker offsets from the ACTUAL audio timeline. The per-chunk
// ffprobe durations summed into $cumulativeMs during the loop drift a few
// seconds over a chapter (MP3 encoder padding isn't perfectly additive),
// so the page→time markers run progressively early. Each marker already
// stored its exact byte position in the concatenated file
// (tts_audio_marker_byte_offset); map that byte → true presentation time
// via ffprobe's packet table — done HERE on the pre-remux $finalPath,
// whose byte layout the offsets match — and rewrite the offset_ms.
// Best-effort: on any failure the loop-derived offsets simply stand.
$ffprobeForMarkers = trim(shell_exec('which ffprobe 2>/dev/null') ?: '');
if ($ffprobeForMarkers) {
    // Stream the packet table line-by-line via popen rather than slurping the
    // whole ~25MB CSV into a string and explode()-ing it into a ~1M-element
    // array: for a multi-hour chapter that transient array alone blew the old
    // memory_limit. Streaming keeps only the two parallel index arrays.
    $pkPos = []; $pkPts = [];
    $pkPipe = popen(escapeshellcmd($ffprobeForMarkers)
        . ' -v error -select_streams a:0 -show_entries packet=pts_time,pos -of csv=p=0 '
        . escapeshellarg($finalPath) . ' 2>/dev/null', 'r');
    if ($pkPipe) {
        while (($ln = fgets($pkPipe)) !== false) {
            $ln = rtrim($ln, "\r\n");
            if ($ln === '') continue;
            $c = explode(',', $ln);
            if (count($c) < 2 || !is_numeric($c[0]) || !is_numeric($c[1])) continue;
            $pkPts[] = (float)$c[0]; $pkPos[] = (int)$c[1];
        }
        pclose($pkPipe);
        $nPk = count($pkPos);
        if ($nPk) {
            $timeAtByte = function ($byte) use ($pkPos, $pkPts, $nPk) {
                $lo = 0; $hi = $nPk - 1; $ans = $nPk - 1;
                while ($lo <= $hi) {
                    $mid = intdiv($lo + $hi, 2);
                    if ($pkPos[$mid] >= $byte) { $ans = $mid; $hi = $mid - 1; }
                    else { $lo = $mid + 1; }
                }
                return $pkPts[$ans];
            };
            try {
                $mSel = $db->prepare("SELECT paragraph_number, paragraph_page, tts_audio_marker_byte_offset bo
                                        FROM yy_tts_audio_marker
                                       WHERE tts_audio_key = ? AND tts_audio_marker_byte_offset IS NOT NULL");
                $mSel->execute([$audioKey]);
                $markerRows = $mSel->fetchAll();   // buffer before issuing UPDATEs on same conn
                $mUpd = $db->prepare("UPDATE yy_tts_audio_marker SET tts_audio_marker_offset_ms = ?
                                       WHERE tts_audio_key = ? AND paragraph_number = ? AND paragraph_page = ?");
                foreach ($markerRows as $mk) {
                    $newMs = (int)round($timeAtByte((int)$mk['bo']) * 1000);
                    $mUpd->execute([$newMs, $audioKey, $mk['paragraph_number'], $mk['paragraph_page']]);
                }
            } catch (Exception $e) { /* keep loop-derived offsets on failure */ }
        }
    }
}

// ── Re-anchor page-break continuation markers to the corrected timeline ──
// The synth loop emitted each byte-NULL continuation marker from the summed-
// chunk cumulative estimate, whose `paragraphMs` under-counts any inserted
// pauses (citation edge pauses, multi-voice quote segments) — landing the
// crossing seconds too EARLY so the flipbook turns the page while the audio is
// still on the previous page. The byte→time recompute above only fixed the
// byte-anchored markers; re-interpolate the byte-NULL ones between their now-
// corrected neighbours. The detached tts-cont-onset-fix.php spawned below then
// STT-refines every crossing it can confidently match, leaving this char-ratio
// value only where STT can't. See reference_tts_audio_seekable_remux_and_markers.
try {
    $reanchor = ttsReanchorContinuationMarkers($db, $audioKey, true);
    if (($reanchor['updated'] ?? 0) > 0) {
        fwrite(STDERR, "re-anchored {$reanchor['updated']} continuation marker(s) to corrected timeline\n");
    }
} catch (\Throwable $e) {
    error_log("[tts-build $audioKey] continuation re-anchor failed (non-fatal): " . $e->getMessage());
}

// Re-mux the byte-concatenated MP3 so it's actually SEEKABLE in browsers.
// A naive byte-concat of per-paragraph MP3s produces a stream with no
// (or a wrong, first-segment-only) Xing/TOC header. Chrome can't seek
// into such a file — currentTime updates but the decoder stalls or
// restarts from 0, so the flipbook's "jump audio to this page" lands at
// the beginning. `ffmpeg -c copy -write_xing 1` rewrites a correct Xing
// header WITHOUT re-encoding (fast, lossless, frames untouched), making
// arbitrary seeks work. Best-effort: if ffmpeg is missing or errors,
// keep the concatenated file rather than failing the build.
$ffmpegBin = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
if ($ffmpegBin) {
    $remuxPath = $finalPath . '.remux.mp3';
    $cmd = escapeshellcmd($ffmpegBin) . ' -y -loglevel error -i ' . escapeshellarg($finalPath)
         . ' -c copy -write_xing 1 -f mp3 ' . escapeshellarg($remuxPath) . ' 2>&1';
    shell_exec($cmd);
    if (is_file($remuxPath) && filesize($remuxPath) > 0) {
        @chmod($remuxPath, 0664);
        if (!@rename($remuxPath, $finalPath)) { @unlink($remuxPath); }
    } else {
        @unlink($remuxPath);
    }
}

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

// ── Auto page-break continuation onset correction (best-effort, detached) ──
// Normal page markers are byte-offset-derived (ffprobe packet PTS) and accurate
// — they land a beat early, but inside the inter-paragraph silence, so they need
// no fixing. The EXCEPTION is a page-break CONTINUATION marker: when one logical
// paragraph splits across a page break, the tail is coalesced into the head for
// synthesis, so the new page's first marker falls MID-CHUNK with no byte offset
// and only a char-ratio ESTIMATE for its offset — which drifts progressively
// early through the chapter (seconds, by the back pages), landing inside the
// previous page's SPOKEN words. tts-cont-onset-fix.php re-derives each such
// marker per-segment: it extracts just that marker's window, decodes it fresh
// (so it avoids the cumulative byte-concat drift that makes whole-chapter
// apply-onsets QA UNUSABLE here), and word-aligns the continuation paragraph's
// opening words to find the true onset. Touches ONLY NULL-byte markers and only
// on a confident, in-window match. Detached so it never blocks this build or the
// queue; wrapped so a failure can't touch the already-completed build. Markers
// are served dynamically (tts-audio.php), so no cache bump is needed.
//
// NOTE: do NOT auto-enqueue whole-chapter apply-onsets QA here — on these
// byte-concatenated chapter MP3s it drags every marker progressively early
// (accumulated per-chunk encoder padding). The Sync/QA tab can still be run
// manually for the advisory word-mismatch report. Only for clean builds.
if ($liveNow) {
    try {
        spawnCappedWorker(__DIR__ . '/tts-cont-onset-fix.php', [(string)$audioKey, '--apply', '--quiet'],
            sys_get_temp_dir() . '/tts_cont_onset_' . $audioKey . '.log', ['cpu_secs' => 1200, 'nice' => 12]);
    } catch (\Throwable $e) {
        error_log("[tts-build $audioKey] continuation onset-correction spawn failed (non-fatal): " . $e->getMessage());
    }
}

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
