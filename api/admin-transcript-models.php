<?php
/**
 * Multi-model Transcribe Audio backend.
 *
 *   GET  ?item_key=N&action=status
 *     → {
 *         models: [
 *           { code:'whisper-1',             label:'OpenAI whisper-1',           last_run:'2026-05-09T18:30Z' | null },
 *           { code:'gpt-4o-mini-transcribe', label:'OpenAI gpt-4o-mini-transcribe', last_run: null },
 *           ...
 *         ],
 *         active_job: { job_key, job_model, job_status, job_progress, job_message } | null
 *       }
 *
 *   POST  { action:'run', item_key:N, model:'whisper-1' }
 *     → enqueues a worker job with job_model set; returns { job_key }
 *
 *   GET  ?item_key=N&action=job&job_key=K
 *     → { job_status, job_progress, job_message, job_completed_dtime } (for UI polling)
 *
 * Per the post-refactor pipeline:
 *   - Worker writes to yy_feed_item_transcript_auto  (with model)
 *     and yy_feed_item_transcript_autoclean (with model). The live table
 *     yy_feed_item_transcript is NEVER touched here.
 *   - "Initialize Transcript" (separate endpoint) is what copies a chosen
 *     model's autoclean version into the live table.
 *
 * Last-run timestamp is computed from the FIRST segment (00:00:00) row of
 * each (item, model) — that's the canonical "this model ran successfully
 * at time T" marker.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finalize-helpers.php'; // spawnCappedWorker via spawn-helpers indirectly
require_once __DIR__ . '/spawn-helpers.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

// Internal model codes map (in the worker) to the OpenAI model name plus
// a timestamp_granularities setting. whisper-1 is exposed as TWO entries:
//   whisper-1-segment — phrase-level row per Whisper segment
//   whisper-1-word    — one row per word, sub-second precision. Same
//                       OpenAI cost as whisper-1-segment; trades coarse
//                       segment rows for fine-grained alignment.
// gpt-4o-mini-transcribe and gpt-4o-transcribe removed 2026-05-11 — they
// emit one row per ~10-min chunk (no segment timestamps) and produced
// transcriptions that were not useful enough to justify the spend.
// Existing _auto rows for those models remain in the DB and show up in
// Analyze as historical columns, but they're no longer pickable here.
// Five cloud transcription provider families, each enabled by a separate
// API key in /opt/yada-www/.env. youtube uses yt-dlp + admin cookies.
$AVAILABLE_MODELS = [
    ['code' => 'gpu-whisper-large-v3',        'label' => 'Self-hosted faster-whisper large-v3 — segment timestamps (free, Puget GPU)'],
    ['code' => 'gpu-whisper-large-v3-word',   'label' => 'Self-hosted faster-whisper large-v3 — word-level timestamps (free, Puget GPU)'],
    ['code' => 'whisper-1-segment',           'label' => 'OpenAI whisper-1 — segment timestamps ($0.006/min)'],
    ['code' => 'whisper-1-word',              'label' => 'OpenAI whisper-1 — word-level timestamps ($0.006/min)'],
    ['code' => 'groq-whisper-large-v3-turbo', 'label' => 'Groq whisper-large-v3-turbo (~$0.0004/min, fastest)'],
    ['code' => 'groq-whisper-large-v3',       'label' => 'Groq whisper-large-v3 (~$0.0011/min)'],
    ['code' => 'deepgram-nova-3',             'label' => 'Deepgram Nova-3 (~$0.0043/min, smart formatting + diarisation)'],
    ['code' => 'assemblyai-universal-2',      'label' => 'AssemblyAI Universal-2 (~$0.0062/min)'],
    ['code' => 'elevenlabs-scribe',           'label' => 'ElevenLabs Scribe (~$0.0067/min)'],
    ['code' => 'azure-speech-stt',            'label' => 'Azure Speech Fast Transcription (~$0.017/min, 5h/mo free)'],
    ['code' => 'youtube',                     'label' => 'YouTube auto-captions (free, requires fresh cookies)'],
];

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data = [];
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
}

if ($method === 'GET' && $action === 'status') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');

    // Last-run timestamp per model. Was previously filtered by segment =
    // '00:00:00'::interval, but whisper-1-word and Deepgram nova-3 start
    // their first row at sub-second offsets (e.g. 00:00:00.56), so they
    // never matched and the UI mis-reported them as "never run". Just take
    // MAX(revision_dtime) across every row for the (item, model) pair.
    $stmt = $db->prepare("
        SELECT feed_item_transcript_auto_model AS model,
               MAX(feed_item_transcript_revision_dtime) AS last_run
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ?
         GROUP BY feed_item_transcript_auto_model
    ");
    $stmt->execute([$itemKey]);
    $runByModel = [];
    foreach ($stmt->fetchAll() as $r) {
        $runByModel[$r['model']] = $r['last_run'];
    }

    $models = [];
    foreach ($AVAILABLE_MODELS as $m) {
        $models[] = [
            'code'     => $m['code'],
            'label'    => $m['label'],
            'last_run' => $runByModel[$m['code']] ?? null,
        ];
    }

    // Most recent pending/running job for this item (UI shows progress).
    $jobStmt = $db->prepare("
        SELECT feed_item_transcript_job_key AS job_key, job_model, job_status, job_progress, job_message,
               job_dtime, job_completed_dtime
          FROM yy_feed_item_transcript_job
         WHERE feed_item_key = ?
           AND job_status IN ('pending', 'running')
         ORDER BY job_dtime DESC LIMIT 1
    ");
    $jobStmt->execute([$itemKey]);
    $activeJob = $jobStmt->fetch() ?: null;

    jsonResponse(['models' => $models, 'active_job' => $activeJob]);
}

if ($method === 'GET' && $action === 'existing') {
    // Which (item, model) pairs already have rows in
    // yy_feed_item_transcript_auto. Used by the Generate-Transcripts
    // popover to skip work that's already been done.
    //
    //   GET ?action=existing&keys=11,22,33&models=youtube,whisper-1-segment
    //
    // Returns: { existing: [ { item_key, model, row_count, last_dtime }, … ] }
    //
    // Only counts non-empty transcript rows, so a previously-failed run
    // that left zero rows doesn't block re-generation.
    $keysRaw   = $_GET['keys']   ?? '';
    $modelsRaw = $_GET['models'] ?? '';
    $keys = array_values(array_filter(array_map('intval', explode(',', $keysRaw)), function ($k) { return $k > 0; }));
    $models = array_values(array_filter(array_map('trim', explode(',', $modelsRaw)), function ($m) { return $m !== ''; }));
    if (!$keys || !$models) {
        jsonResponse(['existing' => []]);
    }
    $kPh = implode(',', array_fill(0, count($keys),   '?'));
    $mPh = implode(',', array_fill(0, count($models), '?'));
    $stmt = $db->prepare("
        SELECT feed_item_key                     AS item_key,
               feed_item_transcript_auto_model   AS model,
               COUNT(*)                          AS row_count,
               MAX(feed_item_transcript_revision_dtime) AS last_dtime
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key                   IN ($kPh)
           AND feed_item_transcript_auto_model IN ($mPh)
         GROUP BY feed_item_key, feed_item_transcript_auto_model
        HAVING COUNT(*) > 0
    ");
    $stmt->execute(array_merge($keys, $models));
    jsonResponse(['existing' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'GET' && $action === 'running') {
    // All in-flight transcription jobs across every item — used by the
    // Generate-Transcripts popover so closing + re-opening it shows the
    // current state of every job (not just ones dispatched this session).
    $stmt = $db->query("
        SELECT j.feed_item_transcript_job_key AS job_key,
               j.feed_item_key,
               j.job_model,
               j.job_status,
               j.job_progress,
               j.job_message,
               j.job_dtime,
               COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS item_title
          FROM yy_feed_item_transcript_job j
          LEFT JOIN yy_feed_item fi USING (feed_item_key)
         WHERE j.job_status IN ('pending', 'running')
         ORDER BY j.job_dtime DESC
         LIMIT 200
    ");
    jsonResponse(['jobs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'GET' && $action === 'job') {
    $jobKey = (int)($_GET['job_key'] ?? 0);
    if (!$jobKey) errorResponse('job_key required');
    $stmt = $db->prepare("
        SELECT feed_item_transcript_job_key AS job_key, job_model, job_status, job_progress, job_message,
               job_dtime, job_completed_dtime, job_error
          FROM yy_feed_item_transcript_job
         WHERE feed_item_transcript_job_key = ?
    ");
    $stmt->execute([$jobKey]);
    $row = $stmt->fetch();
    if (!$row) errorResponse('job not found', 404);
    jsonResponse($row);
}

if ($method === 'GET' && $action === 'health') {
    // Queue overview — counts + the currently-active worker info. The
    // Generate-Transcripts popover polls this so it can show what's
    // actually happening (live PID/item/progress) and detect the stuck
    // state (running=0, pending>0) so the auto-poke can wake the queue.
    $counts = [];
    foreach ($db->query("SELECT job_status, COUNT(*) AS n FROM yy_feed_item_transcript_job GROUP BY job_status")->fetchAll() as $r) {
        $counts[$r['job_status']] = (int)$r['n'];
    }
    $live = $db->query("
        SELECT j.feed_item_transcript_job_key AS job_key, j.feed_item_key, j.job_model,
               j.job_progress, j.job_message, j.job_worker_pid AS pid, j.job_dtime,
               EXTRACT(EPOCH FROM (now() - j.job_dtime))::int AS elapsed_secs,
               COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS item_title
          FROM yy_feed_item_transcript_job j
          LEFT JOIN yy_feed_item fi USING (feed_item_key)
         WHERE j.job_status = 'running'
         ORDER BY j.job_dtime
         LIMIT 5
    ")->fetchAll();
    jsonResponse([
        'counts'  => $counts,
        'pending' => $counts['pending']  ?? 0,
        'running' => $counts['running']  ?? 0,
        'failed'  => $counts['failed']   ?? 0,
        'stuck'   => ($counts['pending'] ?? 0) > 0 && ($counts['running'] ?? 0) === 0,
        'live'    => $live,
    ]);
}

if ($method === 'POST' && $action === 'poke') {
    // Wake a stalled queue: if no worker is currently running, spawn one on
    // the oldest pending job. Idempotent — calling it while a worker is
    // already alive is a no-op. Used by the popover's auto-recovery and
    // by an operator clicking "Wake queue" when they see it's stuck.
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status = 'running'")->fetchColumn();
    if ($running > 0) {
        jsonResponse(['ok' => true, 'spawned' => false, 'reason' => 'worker already running', 'running' => $running]);
    }
    $nextKey = (int)$db->query("SELECT feed_item_transcript_job_key
                                 FROM yy_feed_item_transcript_job
                                WHERE job_status = 'pending'
                                ORDER BY feed_item_transcript_job_key
                                LIMIT 1")->fetchColumn();
    if (!$nextKey) {
        jsonResponse(['ok' => true, 'spawned' => false, 'reason' => 'no pending jobs']);
    }
    $workerScript = __DIR__ . '/transcript-worker.php';
    if (!file_exists($workerScript)) {
        errorResponse('transcript-worker.php missing from ' . __DIR__);
    }
    $logFile = sys_get_temp_dir() . '/transcript_' . $nextKey . '.log';
    $pid = spawnCappedWorker($workerScript, [(string)$nextKey], $logFile, [
        'cpu_secs' => 2400, 'mem_mb' => 2000, 'nice' => 10,
    ]);
    if ($pid > 0) {
        $db->prepare("UPDATE yy_feed_item_transcript_job SET job_worker_pid = ? WHERE feed_item_transcript_job_key = ?")
           ->execute([$pid, $nextKey]);
    }
    jsonResponse(['ok' => true, 'spawned' => $pid > 0, 'job_key' => $nextKey, 'pid' => $pid]);
}

if ($method === 'POST' && $action === 'retry') {
    // Re-queue a failed job by flipping its status back to 'pending' and
    // clearing the error. Accepts either {job_key:N} or {job_keys:[...]}.
    // Does NOT spawn a worker — the caller can hit `poke` immediately
    // after to start processing, or wait for the next worker exit.
    $keys = [];
    if (isset($data['job_keys']) && is_array($data['job_keys'])) {
        foreach ($data['job_keys'] as $k) { $k = (int)$k; if ($k > 0) $keys[] = $k; }
    } elseif (isset($data['job_key'])) {
        $k = (int)$data['job_key']; if ($k > 0) $keys[] = $k;
    }
    if (!$keys) errorResponse('job_key or job_keys[] required');
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare("
        UPDATE yy_feed_item_transcript_job
           SET job_status = 'pending', job_progress = 0,
               job_error = NULL, job_completed_dtime = NULL,
               job_worker_pid = NULL,
               job_message = 'Re-queued by retry'
         WHERE feed_item_transcript_job_key IN ($placeholders)
           AND job_status IN ('failed', 'cancelled')
    ");
    $stmt->execute($keys);
    jsonResponse(['ok' => true, 'requeued' => $stmt->rowCount(), 'requested' => count($keys)]);
}

if ($method === 'POST' && $action === 'cancel') {
    // Worker checks job_status each chunk and bails on 'cancelled'.
    $jobKey  = (int)($data['job_key']  ?? 0);
    $itemKey = (int)($data['item_key'] ?? 0);
    if ($jobKey) {
        $db->prepare("
            UPDATE yy_feed_item_transcript_job
               SET job_status = 'cancelled', job_completed_dtime = NOW()
             WHERE feed_item_transcript_job_key = ? AND job_status IN ('pending', 'running')
        ")->execute([$jobKey]);
    } elseif ($itemKey) {
        $db->prepare("
            UPDATE yy_feed_item_transcript_job
               SET job_status = 'cancelled', job_completed_dtime = NOW()
             WHERE feed_item_key = ? AND job_status IN ('pending', 'running')
        ")->execute([$itemKey]);
    } else {
        errorResponse('job_key or item_key required');
    }
    jsonResponse(['ok' => true]);
}

if ($method === 'POST' && $action === 'run') {
    $itemKey = (int)($data['item_key'] ?? 0);
    $model   = trim($data['model'] ?? '');
    if (!$itemKey) errorResponse('item_key required');
    $valid = array_column($AVAILABLE_MODELS, 'code');
    if (!in_array($model, $valid, true)) errorResponse('invalid model: ' . $model);

    // Cancel any in-flight job for this item *of the same model* before
    // queuing a new one. The UI runs different models in parallel (e.g.
    // segment-level + word-level GPU Whisper from one click), so the
    // cancel must be scoped to the model — otherwise queuing a word job
    // for an item would kill the segment job that was queued moments
    // before (was visible in DB as ~6 segment jobs stuck in 'cancelled'
    // for every burst, while the matching word jobs ran normally).
    $db->prepare("UPDATE yy_feed_item_transcript_job
                     SET job_status = 'cancelled', job_completed_dtime = NOW(),
                         job_message = 'superseded by newer run of same model'
                   WHERE feed_item_key = ?
                     AND job_model = ?
                     AND job_status IN ('pending', 'running')")
       ->execute([$itemKey, $model]);

    $insStmt = $db->prepare("
        INSERT INTO yy_feed_item_transcript_job
            (feed_item_key, job_status, job_message, user_key, job_model)
        VALUES (?, 'pending', ?, ?, ?)
        RETURNING feed_item_transcript_job_key
    ");
    $insStmt->execute([$itemKey, 'Queued for model: ' . $model, $user['user_key'], $model]);
    $jobKey = (int)$insStmt->fetchColumn();

    // ── Concurrency cap ──────────────────────────────────────────────
    // Strict single-worker policy: never run two transcript workers at the
    // same time. Each worker can pin several Postgres connections + a heavy
    // CPU footprint; serializing them keeps the pool predictable and the
    // queue ordering deterministic. New jobs over the cap stay 'pending';
    // when the current worker exits its register_shutdown_function dequeues
    // the next one (see transcript-worker.php).
    $MAX_CONCURRENT_WORKERS = 1;
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status = 'running'")->fetchColumn();
    if ($running < $MAX_CONCURRENT_WORKERS) {
        $workerScript = __DIR__ . '/transcript-worker.php';
        if (file_exists($workerScript)) {
            $logFile = sys_get_temp_dir() . '/transcript_' . $jobKey . '.log';
            $pid = spawnCappedWorker($workerScript, [(string)$jobKey], $logFile, [
                'cpu_secs' => 2400, 'mem_mb' => 2000, 'nice' => 10,
            ]);
            if ($pid > 0) {
                $db->prepare("UPDATE yy_feed_item_transcript_job SET job_worker_pid = ? WHERE feed_item_transcript_job_key = ?")
                   ->execute([$pid, $jobKey]);
            }
        }
        jsonResponse(['job_key' => $jobKey, 'model' => $model, 'queued' => false, 'running' => $running + 1]);
    } else {
        // Leave row as 'pending' — workers picking up next job on their
        // own exit will dequeue this in FIFO order.
        $db->prepare("UPDATE yy_feed_item_transcript_job
                        SET job_message = ? WHERE feed_item_transcript_job_key = ?")
           ->execute([sprintf('Queued — %d workers busy, will start when a slot frees', $running), $jobKey]);
        jsonResponse(['job_key' => $jobKey, 'model' => $model, 'queued' => true, 'running' => $running]);
    }
}

errorResponse('Unknown action');
