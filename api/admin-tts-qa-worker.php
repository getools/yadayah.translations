<?php
/**
 * Detached CLI worker for TTS "Sync / QA".
 *
 *   php admin-tts-qa-worker.php <job_key>
 *
 * admin-tts-qa.php enqueues a yy_tts_qa_job row and spawns this. For each
 * selected word-level GPU STT engine it transcribes the generated chapter MP3
 * (with word timestamps), then ttsQaAnalyze() aligns every stream onto the
 * chapter book text and produces the page-timing-drift + word-mismatch report.
 * The report is stored as job_result jsonb; on finish we pg_notify()
 * 'tts_qa_<job_key>' so admin-tts-qa-sse.php pushes completion to the browser.
 *
 * No auth — local CLI process. Best-effort per engine: an engine that errors
 * (GPU down, OOM) is recorded and skipped; the job fails only if NO engine
 * produced usable words.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';   // spawnNextQaWorker lives in the endpoint; re-spawn via include
require_once __DIR__ . '/gpu-client.php';      // gpuTranscribe, gpuConfigured
require_once __DIR__ . '/tts-qa-lib.php';      // ttsQaAnalyze, intervalToSecs (via compare-lib)
require_once __DIR__ . '/admin-tts-helpers.php'; // loadTtsConfig, preprocessFontFilter, segmentParagraph, ttsCollapseSkippedSegments

/**
 * Per-paragraph EXPECTED SPOKEN TEXT for a chapter — what the build actually
 * synthesizes, not the raw book text. Runs the build's own preprocessing so the
 * QA baseline excludes content that is intentionally never voiced: skip-font
 * glyph spans (preprocessFontFilter) and unreadable categories like the
 * parenthesized word definitions (segmentParagraph → ttsCollapseSkippedSegments
 * drops read_flag=false categories). Without this, that skipped text would
 * align to nothing and flag as bogus "missing" words. Tables aren't voiced.
 * Best-effort per paragraph: on any error fall back to the raw plain text.
 *
 * @return array<int,string> paragraph_number → spoken text ('' = nothing voiced)
 */
function ttsQaSpokenTextMap(PDO $db, array $cfg, int $chapterKey): array {
    $out = [];
    $st = $db->prepare("SELECT paragraph_number, paragraph_text_html, paragraph_text_plain, paragraph_is_table
                          FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
    $st->execute([$chapterKey]);
    $carry = [];   // segmentParagraph citation state, threaded in reading order (mirrors the build)
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $pn = (int)$p['paragraph_number'];
        if (!empty($p['paragraph_is_table'])) { $out[$pn] = ''; continue; }   // tables aren't spoken
        try {
            $filtered = preprocessFontFilter((string)($p['paragraph_text_html'] ?? ''), $cfg['fonts'] ?? []);
            $segs = ttsCollapseSkippedSegments($cfg, segmentParagraph($filtered, $carry));
            $spoken = trim(preg_replace('/\s+/u', ' ',
                implode(' ', array_map(fn($s) => (string)($s['text'] ?? ''), $segs))));
        } catch (\Throwable $e) {
            $spoken = trim((string)($p['paragraph_text_plain'] ?? ''));   // fallback: raw plain text
        }
        $out[$pn] = $spoken;
    }
    return $out;
}

/**
 * Normalized single-word STT-correction map from yy_transcript_correction
 * (active, plain non-regex, single token both sides). Keys/values run through
 * ttsQaNormWord so they line up with QA token normalization. DB-backed, so it
 * lives here rather than in the pure lib.
 */
function ttsQaBuildCorrectionMap(PDO $db): array {
    $map = [];
    try {
        $rows = $db->query("SELECT correction_wrong, correction_right FROM yy_transcript_correction WHERE correction_active_flag = TRUE")
                   ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $wrong = trim((string)($r['correction_wrong'] ?? ''));
            $right = trim((string)($r['correction_right'] ?? ''));
            if ($wrong === '' || $right === '') continue;
            if (preg_match('/\s/u', $wrong) || preg_match('/\s/u', $right)) continue;   // single token only
            if (preg_match('/[\\\\^$.|?*+()\[\]{}]/', $wrong)) continue;                 // skip regex-pattern entries
            $kw = ttsQaNormWord($wrong);
            $vr = ttsQaNormWord($right);
            if ($kw === '' || $vr === '' || $kw === $vr) continue;
            $map[$kw] = $vr;
        }
    } catch (\Throwable $e) { /* best-effort: no map on failure */ }
    return $map;
}

$jobKey = (int)($argv[1] ?? 0);
if (!$jobKey) { fwrite(STDERR, "usage: admin-tts-qa-worker.php <job_key>\n"); exit(1); }

$db = getDb();
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

// Drain the single-worker queue on exit (done/error/fatal), like the init worker.
register_shutdown_function(function () use ($jobKey) {
    try {
        $db = getDb();
        $db->exec("UPDATE yy_tts_qa_job SET job_status='error', job_error=COALESCE(job_error,'worker exited unexpectedly'), job_completed=now()
                    WHERE job_key=" . (int)$jobKey . " AND job_status='running'");
        // Promote the next pending job (oldest first), respecting the 1-at-a-time gate.
        $running = (int)$db->query("SELECT COUNT(*) FROM yy_tts_qa_job WHERE job_status='running'")->fetchColumn();
        if ($running === 0) {
            $next = $db->query("SELECT job_key FROM yy_tts_qa_job WHERE job_status='pending' ORDER BY job_key LIMIT 1")->fetchColumn();
            if ($next) {
                spawnCappedWorker(__DIR__ . '/admin-tts-qa-worker.php', [(string)(int)$next],
                    sys_get_temp_dir() . '/tts_qa_' . (int)$next . '.log', ['cpu_secs' => 2400, 'nice' => 12]);
            }
        }
    } catch (\Throwable $e) {}
});

// Atomically claim.
$claim = $db->prepare("UPDATE yy_tts_qa_job SET job_status='running', job_started=now(), job_progress=1, job_message='Starting…'
                        WHERE job_key=? AND job_status='pending' RETURNING *");
$claim->execute([$jobKey]);
$job = $claim->fetch();
if (!$job) { exit(0); }   // already claimed/cancelled

$audioKey = (int)$job['job_audio_key'];
$engines  = array_values(array_filter(array_map('trim',
    preg_split('/[,{}]/', (string)($job['job_engines'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))));
$params   = json_decode((string)($job['job_params'] ?? ''), true) ?: [];

$notify = function (string $payload) use ($db, $jobKey) {
    try { $db->prepare("SELECT pg_notify(?, ?)")->execute(['tts_qa_' . $jobKey, $payload]); }
    catch (\Throwable $e) {}
};
$progress = function (int $pct, string $msg) use ($db, $jobKey) {
    try { $db->prepare("UPDATE yy_tts_qa_job SET job_progress=?, job_message=? WHERE job_key=?")
             ->execute([max(0, min(99, $pct)), mb_substr($msg, 0, 300), $jobKey]); } catch (\Throwable $e) {}
};
$fail = function (string $msg) use ($db, $jobKey, $notify) {
    try { $db->prepare("UPDATE yy_tts_qa_job SET job_status='error', job_error=?, job_completed=now() WHERE job_key=?")
             ->execute([mb_substr($msg, 0, 500), $jobKey]); } catch (\Throwable $e) {}
    $notify('error');
};

// Route table for the four allowed word-level engines (mirrors gpuEngineRoute,
// inlined so we don't pull the whole transcribe-providers include chain).
$ROUTES = [
    'gpu-whisperx-word'               => ['path' => '/stt-whisperx/transcribe', 'model' => ''],
    'gpu-whisper-large-v3-word'       => ['path' => '/stt/transcribe',          'model' => 'large-v3'],
    'gpu-whisper-large-v3-turbo-word' => ['path' => '/stt/transcribe',          'model' => 'large-v3-turbo'],
    'gpu-parakeet-tdt-0.6b-v2-word'   => ['path' => '/stt-parakeet/transcribe', 'model' => ''],
];

try {
    if (!$engines) throw new Exception('no engines selected');
    if (!function_exists('gpuConfigured') || !gpuConfigured()) throw new Exception('GPU gateway not configured');

    // Resolve the chapter MP3 + page markers (with their book text).
    $aStmt = $db->prepare("SELECT tts_audio_path, tts_audio_duration_secs, chapter_key, tts_key, tts_profile_key FROM yy_tts_audio WHERE tts_audio_key = ?");
    $aStmt->execute([$audioKey]);
    $audio = $aStmt->fetch();
    if (!$audio) throw new Exception('audio row not found');
    $chapterKey = (int)($audio['chapter_key'] ?? 0);

    // Expected SPOKEN text per paragraph (skip-aware): excludes glyph-font spans
    // and word-definition / other unreadable categories so intentionally-silent
    // text never flags as a missing word. Best-effort — on config load failure
    // we fall back to raw plain text (the LEFT JOIN below).
    $spokenMap = [];
    try {
        $cfg = loadTtsConfig($db, (int)($audio['tts_key'] ?? 0), (int)($audio['tts_profile_key'] ?? 0) ?: null);
        if (!empty($cfg['system'])) $spokenMap = ttsQaSpokenTextMap($db, $cfg, $chapterKey);
    } catch (\Throwable $e) {
        error_log('admin-tts-qa-worker job ' . $jobKey . ' spoken-text map failed (using raw text): ' . $e->getMessage());
    }
    $rel = ltrim((string)$audio['tts_audio_path'], '/');
    // tts_audio_path is web-root-relative. The public root is api's parent dir
    // (/var/www/html in the container, where /u lives) — same base-detection the
    // build worker / list_paragraphs handler use; fall back to the host path.
    $audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
    $mp3 = $audioBase . '/' . $rel;
    if (!is_file($mp3) || filesize($mp3) === 0) throw new Exception('chapter MP3 missing: ' . $mp3);

    // Join book text by (chapter_key, paragraph_number) — the key the build
    // worker iterates. paragraph_key on the marker is NULL on older builds, so
    // we can't rely on it; (chapter_key, paragraph_number) is always populated.
    $mStmt = $db->prepare("
        SELECT m.paragraph_number, m.paragraph_page, m.tts_audio_marker_offset_ms AS offset_ms,
               p.paragraph_text_plain AS text
          FROM yy_tts_audio_marker m
          LEFT JOIN yy_paragraph p
                 ON p.chapter_key = ? AND p.paragraph_number = m.paragraph_number
         WHERE m.tts_audio_key = ?
         ORDER BY m.tts_audio_marker_offset_ms, m.paragraph_number");
    $mStmt->execute([$chapterKey, $audioKey]);
    $markers = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$markers) throw new Exception('no page markers for this chapter');

    // Prefer the skip-aware spoken text; fall back to the joined raw plain text.
    // A paragraph that spans multiple pages emits one marker PER page — assign
    // its text only to the FIRST of those markers so the book token stream isn't
    // DUPLICATED. (Duplication breaks content alignment: the repeated block
    // matches nothing the second time → a bogus "missing" run, and the repeats
    // poison unique-anchor finding nearby.) Later same-paragraph markers get no
    // text → no onset → they keep their byte-derived offset.
    $seenPara = [];
    foreach ($markers as &$mk) {
        $pn = (int)$mk['paragraph_number'];
        if (isset($seenPara[$pn])) { $mk['text'] = ''; continue; }
        $seenPara[$pn] = true;
        if (array_key_exists($pn, $spokenMap)) $mk['text'] = $spokenMap[$pn];
    }
    unset($mk);

    // One STT pass per engine. Words come back as canonical interval rows
    // ('HH:MM:SS.mmm' + word); convert to [{w, t-seconds}].
    $streams = [];
    $engineErrors = [];
    $nEng = count($engines);
    foreach ($engines as $ei => $code) {
        $route = $ROUTES[$code] ?? null;
        if (!$route) { $engineErrors[$code] = 'unknown engine'; continue; }
        $progress((int)round(5 + ($ei / max(1, $nEng)) * 80), 'Transcribing with ' . $code . '…');
        $opts = [
            'path'            => $route['path'],
            'word_timestamps' => true,
            // VAD OFF: the chapter contains deliberate inter-paragraph pauses.
            // With VAD on, faster-whisper/whisperx collapses those silences and
            // returns word times on a compressed timeline — onsets then run
            // progressively early (cumulative pause time), wrecking the drift
            // measurement. Off keeps timestamps on the true audio timeline; the
            // pauses are silent so they add no spurious words.
            'vad_filter'      => false,
            'language'        => 'en',
            'timeout'         => 900,
        ];
        if ($route['model'] !== '') $opts['model'] = $route['model'];
        $res = gpuTranscribe($mp3, $opts);
        if (empty($res['ok'])) { $engineErrors[$code] = $res['error'] ?? ('status ' . ($res['status'] ?? '?')); continue; }
        $words = [];
        foreach (($res['data']['segments'] ?? []) as $seg) {
            if (!empty($seg['words'])) {
                foreach ($seg['words'] as $w) {
                    $txt = trim((string)($w['word'] ?? ''));
                    if ($txt === '') continue;
                    $words[] = ['w' => $txt, 't' => (float)($w['start'] ?? 0)];
                }
            } elseif (trim((string)($seg['text'] ?? '')) !== '') {
                // No word array (engine fell back to segment) — last resort, one
                // entry at the segment start so alignment still anchors coarsely.
                $words[] = ['w' => trim((string)$seg['text']), 't' => (float)($seg['start'] ?? 0)];
            }
        }
        if ($words) $streams[$code] = $words;
        else $engineErrors[$code] = 'no words returned';
    }

    if (!$streams) throw new Exception('all engines failed: ' . json_encode($engineErrors));

    // STT-correction lookups: the transcript pipeline's yy_transcript_correction
    // dictionary already maps how engines mis-hear this book's transliterations
    // ("Yahweh"→"Yahowah", "Torah"→"Towrah"). Fold the SINGLE-WORD, plain (non-
    // regex) entries into a normalized map and apply it to STT tokens, so those
    // known mishearings align to the book spelling instead of flagging as
    // mismatches. Single-token only keeps the STT word→timestamp mapping 1:1.
    $correctionMap = ttsQaBuildCorrectionMap($db);

    $progress(90, 'Aligning against book text…');
    $report = ttsQaAnalyze($markers, $streams, [
        'drift_threshold_ms' => (int)($params['drift_threshold_ms'] ?? 350),
        'onset_engine'       => (string)($params['onset_engine'] ?? ''),
        'correction_map'     => $correctionMap,
    ]);
    $report['engine_errors'] = $engineErrors;
    $report['duration_secs'] = (float)($audio['tts_audio_duration_secs'] ?? 0);

    // Auto-correction (build-triggered jobs): overwrite each page marker with
    // its measured speech onset. Applied in audio order with a MONOTONIC guard
    // so a single bad alignment can't scramble page order — an onset that would
    // step backwards is skipped, leaving that marker's byte-derived offset. The
    // report's drift numbers reflect the PRE-correction state (what we fixed).
    if (!empty($job['job_apply_onsets'])) {
        $progress(96, 'Applying corrected page markers…');
        $upd = $db->prepare("UPDATE yy_tts_audio_marker SET tts_audio_marker_offset_ms = ?
                              WHERE tts_audio_key = ? AND paragraph_number = ?
                                AND paragraph_page IS NOT DISTINCT FROM ?");
        $prev = -1; $nFix = 0;
        foreach (($report['pages'] ?? []) as $pg) {
            if ($pg['onset_ms'] === null) continue;
            $ms = (int)$pg['onset_ms'];
            if ($ms < $prev) continue;                 // keep page order monotonic
            $prev = $ms;
            $upd->execute([$ms, $audioKey, (int)$pg['paragraph_number'], $pg['paragraph_page']]);
            $nFix++;
        }
        $report['onsets_applied'] = $nFix;
        error_log('admin-tts-qa-worker job ' . $jobKey . ' applied ' . $nFix . ' corrected page markers to audio ' . $audioKey);
    }

    $db->prepare("UPDATE yy_tts_qa_job
                     SET job_status='done', job_progress=100, job_message='Done', job_result=?, job_completed=now()
                   WHERE job_key=?")
       ->execute([json_encode($report, JSON_UNESCAPED_UNICODE), $jobKey]);
    $notify('done');
} catch (\Throwable $e) {
    error_log('admin-tts-qa-worker job ' . $jobKey . ' failed: ' . $e->getMessage());
    $fail($e->getMessage());
}
