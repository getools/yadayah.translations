<?php
/**
 * "Initialize Transcript" endpoint (async).
 *
 * Promotes a chosen auto-transcribed model into the live transcript: assemble
 * the hybrid join if needed, apply the YadaYah correction dictionary, replace
 * yy_feed_item_transcript_autoclean (tagged with the model) and the live
 * yy_feed_item_transcript. On long episodes this is 60-230s — past Cloudflare's
 * ~100s proxy window — so the heavy work runs in a detached worker:
 *
 *   POST { item_key:N, model:'…' }
 *     → { async:true, job_key:N }     // enqueued; watch admin-transcript-init-sse.php
 *   GET  ?action=status&job_key=N
 *     → { job_key, job_status, job_rows_written, job_error }   // SSE fallback
 *
 * This endpoint only validates and enqueues; admin-transcript-init-worker.php
 * does the (destructive) promote and pg_notify()s completion, which
 * admin-transcript-init-sse.php pushes to the browser. There is no undo aside
 * from the weekly DB backup — the modal confirms with the operator first.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php'; // resolveJoinSources

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

// ── GET: status fallback for the SSE client (used only if EventSource drops) ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'status') {
    $jobKey = (int)($_GET['job_key'] ?? 0);
    if (!$jobKey) errorResponse('job_key required');
    $st = $db->prepare("SELECT job_key, job_status, job_rows_written, job_error FROM yy_feed_item_transcript_init_job WHERE job_key = ?");
    $st->execute([$jobKey]);
    $job = $st->fetch();
    if (!$job) errorResponse('job not found', 404);
    jsonResponse($job);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST only', 405);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$itemKey = (int)($data['item_key'] ?? 0);
if (!$itemKey) errorResponse('item_key required');

// ── Consensus mode: seed the live transcript from a majority vote across
//    several baselines, segmented by the supplied caption params. The heavy
//    align+reflow runs in the worker; here we just validate + enqueue. ──
if (trim($data['mode'] ?? '') === 'consensus') {
    $baselines = array_values(array_unique(array_filter(array_map('trim', (array)($data['baselines'] ?? [])))));
    if (!$baselines) errorResponse('Select at least one baseline.');
    $place = implode(',', array_fill(0, count($baselines), '?'));
    $chk = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model FROM yy_feed_item_transcript_auto
                          WHERE feed_item_key = ? AND feed_item_transcript_auto_model IN ($place)");
    $chk->execute(array_merge([$itemKey], $baselines));
    $present = $chk->fetchAll(PDO::FETCH_COLUMN);
    if (!$present) errorResponse('None of the selected baselines have rows for this item.');
    $rawP = is_array($data['params'] ?? null) ? $data['params'] : [];
    $params = [
        'max_chars'      => max(10, (int)($rawP['max_chars'] ?? 42)),
        'max_lines'      => max(1, (int)($rawP['max_lines'] ?? 2)),
        'max_secs'       => (float)($rawP['max_secs'] ?? 7.0),
        'min_secs'       => (float)($rawP['min_secs'] ?? 1.2),
        'break_punct'    => array_key_exists('break_punct', $rawP) ? !empty($rawP['break_punct']) : true,
        'break_gap'      => max(0.0, (float)($rawP['break_gap'] ?? 0)),
        'use_boundaries' => !empty($rawP['use_boundaries']),
        'dedup'          => array_key_exists('dedup', $rawP) ? !empty($rawP['dedup']) : true,
    ];
    $jobParams = json_encode(['baselines' => array_values($present), 'params' => $params]);
    $ins = $db->prepare("INSERT INTO yy_feed_item_transcript_init_job (job_item_key, job_model, job_user_key, job_params)
                          VALUES (?, 'consensus', ?, ?) RETURNING job_key");
    $ins->execute([$itemKey, (int)$user['user_key'], $jobParams]);
    $jobKey = (int)$ins->fetchColumn();
    @exec('setsid php ' . escapeshellarg(__DIR__ . '/admin-transcript-init-worker.php') . ' ' . $jobKey . ' > /dev/null 2>&1 &');
    jsonResponse(['async' => true, 'job_key' => $jobKey]);
}

$model = trim($data['model'] ?? '');
if ($model === '') errorResponse('model required');

// Fast, cheap precondition checks so obvious problems (missing source feeds)
// fail immediately instead of enqueuing a job that just errors out. The heavy
// assembly/promote itself happens in the worker.
if ($model === 'whisper-1-word-join' || $model === 'whisper-1-word-join-seg') {
    $useSeg = ($model === 'whisper-1-word-join-seg');
    $src = resolveJoinSources($db, $itemKey);
    if (!$src['word'] || !$src['has_yt'] || ($useSeg && !$src['seg'])) {
        $need = 'a word-level whisper + YouTube' . ($useSeg ? ' + a segment-level whisper' : '');
        errorResponse('Cannot assemble ' . $model . ' — generate ' . $need . ' first.');
    }
} else {
    $chk = $db->prepare("SELECT 1 FROM yy_feed_item_transcript_auto WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ? LIMIT 1");
    $chk->execute([$itemKey, $model]);
    if (!$chk->fetchColumn()) errorResponse('No rows in _auto for item=' . $itemKey . ' model=' . $model);
}

// Enqueue and hand off to a detached worker (survives this request via setsid).
$ins = $db->prepare("INSERT INTO yy_feed_item_transcript_init_job (job_item_key, job_model, job_user_key) VALUES (?, ?, ?) RETURNING job_key");
$ins->execute([$itemKey, $model, (int)$user['user_key']]);
$jobKey = (int)$ins->fetchColumn();

$workerPath = __DIR__ . '/admin-transcript-init-worker.php';
@exec('setsid php ' . escapeshellarg($workerPath) . ' ' . $jobKey . ' > /dev/null 2>&1 &');

jsonResponse(['async' => true, 'job_key' => $jobKey]);
