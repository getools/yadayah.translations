<?php
/**
 * TTS "Sync / QA" endpoint.
 *
 * After a chapter's audio is built, this drives the QA tab: it enqueues a job
 * that runs word-level STT over the generated MP3 and checks it back against the
 * book text — reporting page-marker timing drift and (consensus) word
 * mismatches. The heavy STT (one pass per selected engine, 30-120s) runs in a
 * detached worker, so this endpoint only validates + enqueues; the work and the
 * pg_notify() live in admin-tts-qa-worker.php, pushed to the browser by
 * admin-tts-qa-sse.php (mirrors the Initialize-Transcript async pattern).
 *
 *   GET  ?action=engines                         → selectable word-level engines + GPU health
 *   GET  ?action=list&volume_key=N&tts_key=N     → built chapters + latest QA job each
 *   GET  ?action=status&job_key=N                → { job_status, job_progress, job_message, job_error } (SSE fallback)
 *   GET  ?action=result&job_key=N                → { job_status, job_result, ... }
 *   POST { audio_key:N, engines:[...] }          → { async:true, job_key:N }
 *   POST { action:'cancel', job_key:N }          → { ok:true }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';      // spawnCappedWorker
require_once __DIR__ . '/tts-qa-lib.php';         // ttsQaEngineCodes (no side effects on include)

$user = requireAuth();
$db   = getDb();
setCurrentUser($db, (int)$user['user_key']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

/** Detached single-worker dispatcher: run QA jobs one at a time (STT competes
 *  for the same GPU as builds), oldest pending first; reap stuck >30min jobs. */
function spawnNextQaWorker(PDO $db): void {
    // Reap a job that claimed 'running' but never finished (worker died).
    $db->exec("UPDATE yy_tts_qa_job
                  SET job_status='error', job_error='worker timed out', job_completed=now()
                WHERE job_status='running' AND job_started < now() - interval '30 minutes'");
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_tts_qa_job WHERE job_status='running'")->fetchColumn();
    if ($running > 0) return;
    $next = $db->query("SELECT job_key FROM yy_tts_qa_job WHERE job_status='pending' ORDER BY job_key LIMIT 1")->fetchColumn();
    if (!$next) return;
    spawnCappedWorker(__DIR__ . '/admin-tts-qa-worker.php', [(string)(int)$next],
        sys_get_temp_dir() . '/tts_qa_' . (int)$next . '.log', ['cpu_secs' => 2400, 'nice' => 12]);
}

// ── GET: selectable engines + GPU reachability ──
if ($method === 'GET' && $action === 'engines') {
    $labels = function_exists('compareLabels') ? compareLabels() : [];
    $engines = [];
    foreach (ttsQaEngineCodes() as $code) {
        $engines[] = ['code' => $code, 'label' => $labels[$code] ?? $code];
    }
    $gpuOk = false;
    if (function_exists('gpuConfigured') && gpuConfigured()) {
        $h = function_exists('gpuHealth') ? gpuHealth('stt') : ['ok' => false];
        $gpuOk = !empty($h['ok']);
    }
    jsonResponse(['engines' => $engines, 'default_onset' => ttsQaOnsetPreference()[0], 'gpu_ok' => $gpuOk]);
}

// ── GET: built chapters in a volume + each one's latest QA job ──
if ($method === 'GET' && $action === 'list') {
    $volumeKey = (int)($_GET['volume_key'] ?? 0);
    $ttsKey    = (int)($_GET['tts_key'] ?? 0);
    if (!$volumeKey) errorResponse('volume_key required');

    $cStmt = $db->prepare("
        SELECT a.tts_audio_key, a.chapter_key, c.chapter_number, c.chapter_name AS chapter_label,
               a.tts_audio_duration_secs, a.tts_audio_completed_dtime, a.tts_audio_path,
               a.tts_profile_key, p.tts_profile_label,
               (SELECT COUNT(*) FROM yy_tts_audio_marker m WHERE m.tts_audio_key = a.tts_audio_key) AS marker_count
          FROM yy_tts_audio a
          JOIN yy_chapter c ON c.chapter_key = a.chapter_key
          LEFT JOIN yy_tts_profile p ON p.tts_profile_key = a.tts_profile_key
         WHERE a.volume_key = ?
           AND (? = 0 OR a.tts_key = ?)
           AND a.tts_audio_status = 'complete'
           AND a.chapter_key IS NOT NULL
         ORDER BY c.chapter_sort, c.chapter_number, p.tts_profile_default_flag DESC NULLS LAST, p.tts_profile_label NULLS LAST
    ");
    $cStmt->execute([$volumeKey, $ttsKey, $ttsKey]);
    $chapters = $cStmt->fetchAll();

    // Latest QA job per audio_key (one extra query, DISTINCT ON).
    $jobs = [];
    if ($chapters) {
        $keys = array_map(fn($c) => (int)$c['tts_audio_key'], $chapters);
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $jStmt = $db->prepare("
            SELECT DISTINCT ON (job_audio_key)
                   job_audio_key, job_key, job_status, job_progress, job_message,
                   job_completed, job_result
              FROM yy_tts_qa_job
             WHERE job_audio_key IN ($ph)
             ORDER BY job_audio_key, job_key DESC");
        $jStmt->execute($keys);
        foreach ($jStmt->fetchAll() as $j) {
            $res = $j['job_result'] ? json_decode($j['job_result'], true) : null;
            $jobs[(int)$j['job_audio_key']] = [
                'job_key'    => (int)$j['job_key'],
                'job_status' => $j['job_status'],
                'job_completed' => $j['job_completed'],
                'summary'    => $res['summary'] ?? null,
            ];
        }
    }
    foreach ($chapters as &$c) {
        $c['marker_count'] = (int)$c['marker_count'];
        $c['latest_job']   = $jobs[(int)$c['tts_audio_key']] ?? null;
    }
    unset($c);
    jsonResponse(['chapters' => $chapters]);
}

// ── GET: job status (SSE fallback) ──
if ($method === 'GET' && $action === 'status') {
    $jobKey = (int)($_GET['job_key'] ?? 0);
    if (!$jobKey) errorResponse('job_key required');
    $st = $db->prepare("SELECT job_key, job_status, job_progress, job_message, job_error FROM yy_tts_qa_job WHERE job_key = ?");
    $st->execute([$jobKey]);
    $job = $st->fetch();
    if (!$job) errorResponse('job not found', 404);
    jsonResponse($job);
}

// ── GET: full result ──
if ($method === 'GET' && $action === 'result') {
    $jobKey = (int)($_GET['job_key'] ?? 0);
    if (!$jobKey) errorResponse('job_key required');
    $st = $db->prepare("SELECT job_key, job_audio_key, job_engines, job_status, job_message, job_error, job_result, job_completed FROM yy_tts_qa_job WHERE job_key = ?");
    $st->execute([$jobKey]);
    $job = $st->fetch();
    if (!$job) errorResponse('job not found', 404);
    $job['job_result'] = $job['job_result'] ? json_decode($job['job_result'], true) : null;
    jsonResponse($job);
}

if ($method !== 'POST') errorResponse('POST required', 405);

$data = json_decode(file_get_contents('php://input'), true) ?: [];

// ── POST cancel ──
if (($data['action'] ?? '') === 'cancel') {
    $jobKey = (int)($data['job_key'] ?? 0);
    if (!$jobKey) errorResponse('job_key required');
    $db->prepare("UPDATE yy_tts_qa_job SET job_status='cancelled', job_completed=now()
                   WHERE job_key=? AND job_status IN ('pending','running')")->execute([$jobKey]);
    spawnNextQaWorker($db);
    jsonResponse(['ok' => true]);
}

// ── POST enqueue ──
$audioKey = (int)($data['audio_key'] ?? 0);
if (!$audioKey) errorResponse('audio_key required');

// Validate the audio row is a real, completed chapter build with markers.
$aStmt = $db->prepare("SELECT tts_audio_key, tts_audio_status, tts_audio_path FROM yy_tts_audio WHERE tts_audio_key = ?");
$aStmt->execute([$audioKey]);
$audio = $aStmt->fetch();
if (!$audio) errorResponse('audio not found', 404);
if (($audio['tts_audio_status'] ?? '') !== 'complete') errorResponse('chapter audio is not complete');
$markerN = (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio_marker WHERE tts_audio_key = " . (int)$audioKey)->fetchColumn();
if ($markerN === 0) errorResponse('this chapter has no page markers to check');

// Restrict to the allowed word-level engine codes; keep the request order.
$allowed = ttsQaEngineCodes();
$engines = array_values(array_filter(
    array_map('strval', (array)($data['engines'] ?? [])),
    fn($c) => in_array($c, $allowed, true)));
if (!$engines) errorResponse('select at least one word-level STT engine');

$onsetEngine = trim((string)($data['onset_engine'] ?? ''));
if ($onsetEngine !== '' && !in_array($onsetEngine, $engines, true)) $onsetEngine = '';
$driftMs = (int)($data['drift_threshold_ms'] ?? 350);
if ($driftMs < 50)  $driftMs = 50;
if ($driftMs > 3000) $driftMs = 3000;

// Supersede any not-yet-finished QA job for this same audio (re-run replaces it).
$db->prepare("UPDATE yy_tts_qa_job SET job_status='cancelled', job_completed=now()
               WHERE job_audio_key=? AND job_status IN ('pending','running')")->execute([$audioKey]);

$params = json_encode(['onset_engine' => $onsetEngine, 'drift_threshold_ms' => $driftMs]);
$ins = $db->prepare("INSERT INTO yy_tts_qa_job (job_audio_key, job_engines, job_user_key, job_params)
                      VALUES (?, ?, ?, ?) RETURNING job_key");
$ins->execute([$audioKey, '{' . implode(',', $engines) . '}', (int)$user['user_key'], $params]);
$jobKey = (int)$ins->fetchColumn();

spawnNextQaWorker($db);
jsonResponse(['async' => true, 'job_key' => $jobKey]);
