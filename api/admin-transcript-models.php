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
require_once __DIR__ . '/transcript-helpers.php'; // resolveJoinSources() for hybrid readiness

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

// ── Orphaned-job reaper ──────────────────────────────────────────────────
// A worker that dies abnormally (killed, segfault, dropped engine
// connection) leaves its job pinned at job_status='running'. The single-
// worker concurrency gate in poke/run counts rows WHERE job_status='running',
// so ONE such orphan wedges the ENTIRE STT queue — every spawn is refused
// with "worker already running" and no transcript can ever start again until
// a human clears it by hand. Caught in the wild 2026-06-21: a
// gpu-whisper-large-v3-turbo-word worker vanished mid word-level call (0 rows
// written) and dead-locked the queue. So before any spawn-gate check we mark
// as failed any 'running' job whose worker process is no longer alive.
//
// Safety / bias:
//   - 90s grace on job_dtime so a just-spawned worker that hasn't finished
//     booting is never reaped out from under itself.
//   - Liveness via /proc/<pid> (owner-agnostic; dispatch PHP and the worker
//     share the container PID namespace), posix_kill($pid,0) as fallback.
//   - A reused PID belonging to an unrelated live process reads as "alive",
//     so we UNDER-reap (leave a stuck job) rather than ever fail a healthy
//     one. Worst case the operator re-pokes; we never kill a running worker.
function reapOrphanedJobs($db) {
    $stale = $db->query("SELECT feed_item_transcript_job_key AS k, job_worker_pid AS pid
                           FROM yy_feed_item_transcript_job
                          WHERE job_status = 'running'
                            AND job_dtime < NOW() - INTERVAL '90 seconds'")->fetchAll(PDO::FETCH_ASSOC);
    if (!$stale) return 0;
    $reap = $db->prepare("UPDATE yy_feed_item_transcript_job
                             SET job_status = 'failed',
                                 job_completed_dtime = NOW(),
                                 job_error = 'Orphaned: worker PID '
                                     || COALESCE(job_worker_pid::text, '(none)')
                                     || ' no longer alive — auto-reaped by dispatch guard',
                                 job_message = 'Failed: orphaned worker (auto-reaped)'
                           WHERE feed_item_transcript_job_key = ?
                             AND job_status = 'running'");
    $n = 0;
    foreach ($stale as $row) {
        $pid = (int)$row['pid'];
        $alive = false;
        if ($pid > 0) {
            if (@file_exists("/proc/$pid")) {
                $alive = true;
            } elseif (function_exists('posix_kill') && @posix_kill($pid, 0)) {
                $alive = true;
            }
        }
        if (!$alive) { $reap->execute([$row['k']]); $n += $reap->rowCount(); }
    }
    return $n;
}

// Serializes the "is a slot free? then spawn" decision across concurrent
// dispatches. Generate Baselines fires one run-POST per checked engine
// *simultaneously*; without this, all of them read job_status='running'
// count = 0 (a freshly-spawned worker doesn't flip its own row to 'running'
// until ~1s into boot, see transcript-worker.php) and every one spawns a
// worker — N STT models land on the single GPU at once → CUDA OOM. Holding
// this advisory lock around count-and-claim makes the cap=1 gate atomic.
// The same key is used by transcript-worker.php's shutdown-dequeue so a
// worker exit and a fresh run-POST can never both spawn.
const TRANSCRIPT_WORKER_LOCK = 742001;
function withWorkerSlotLock($db, callable $fn) {
    $db->query('SELECT pg_advisory_lock(' . TRANSCRIPT_WORKER_LOCK . ')');
    try { return $fn(); }
    finally { $db->query('SELECT pg_advisory_unlock(' . TRANSCRIPT_WORKER_LOCK . ')'); }
}

// Under the slot lock: if no worker is running, spawn one on $jobKey and
// CLAIM the slot synchronously by flipping the row to 'running' right now —
// so the next dispatch waiting on the lock counts it before the worker
// reaches its own 'running' update. Returns the running-worker count after
// the decision; $spawned is set by-ref to whether we started a worker.
function claimWorkerSlot($db, int $jobKey, bool &$spawned): int {
    return withWorkerSlotLock($db, function () use ($db, $jobKey, &$spawned) {
        $spawned = false;
        reapOrphanedJobs($db); // clear dead 'running' jobs so one orphan can't wedge the queue
        $running = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status = 'running'")->fetchColumn();
        if ($running >= 1) return $running;            // slot taken — leave $jobKey pending
        $workerScript = __DIR__ . '/transcript-worker.php';
        if (!file_exists($workerScript)) return $running;
        $logFile = sys_get_temp_dir() . '/transcript_' . $jobKey . '.log';
        $pid = spawnCappedWorker($workerScript, [(string)$jobKey], $logFile, [
            'cpu_secs' => 2400, 'mem_mb' => 2000, 'nice' => 10,
        ]);
        if ($pid <= 0) return $running;                // spawn failed — leave pending, caller reports queued
        $db->prepare("UPDATE yy_feed_item_transcript_job
                         SET job_status = 'running', job_worker_pid = ?
                       WHERE feed_item_transcript_job_key = ? AND job_status = 'pending'")
           ->execute([$pid, $jobKey]);
        $spawned = true;
        return $running + 1;
    });
}

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
    ['code' => 'gpu-whisper-large-v3',            'label' => 'Self-hosted faster-whisper large-v3 — segment timestamps (free, Puget GPU)'],
    ['code' => 'gpu-whisper-large-v3-word',       'label' => 'Self-hosted faster-whisper large-v3 — word-level timestamps (free, Puget GPU)'],
    ['code' => 'gpu-whisper-large-v3-turbo',      'label' => 'Self-hosted faster-whisper large-v3-turbo — segment timestamps (free, fast, Puget GPU)'],
    ['code' => 'gpu-whisper-large-v3-turbo-word', 'label' => 'Self-hosted faster-whisper large-v3-turbo — word-level timestamps (free, fast, Puget GPU)'],
    ['code' => 'gpu-parakeet-tdt-0.6b-v2',        'label' => 'Self-hosted NVIDIA Parakeet TDT 0.6B v2 — segment timestamps (free, English, top accuracy, Puget GPU)'],
    ['code' => 'gpu-parakeet-tdt-0.6b-v2-word',   'label' => 'Self-hosted NVIDIA Parakeet TDT 0.6B v2 — word-level timestamps (free, English, top accuracy, Puget GPU)'],
    ['code' => 'gpu-whisperx',                    'label' => 'Self-hosted WhisperX (large-v3 + forced alignment) — segment timestamps (free, tight word timing, Puget GPU)'],
    ['code' => 'gpu-whisperx-word',               'label' => 'Self-hosted WhisperX (large-v3 + forced alignment) — word-level timestamps (free, tight word timing, Puget GPU)'],
    ['code' => 'gpu-whisperx-diarize',            'label' => 'Self-hosted WhisperX + speaker diarization (large-v3 + pyannote) — segment timestamps with speaker labels (free, Puget GPU)'],
    ['code' => 'gpu-canary-1b-flash',             'label' => 'Self-hosted NVIDIA Canary 1B Flash — high-accuracy text (correction source; coarse 30s segments, free, Puget GPU)'],
    ['code' => 'gpu-qwen2-audio',                 'label' => 'Self-hosted Qwen2-Audio 7B — high-accuracy text (correction source; audio-LLM, coarse 30s segments, free, Puget GPU)'],
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

// Derived "hybrid" models. These are NOT transcription jobs — they assemble
// existing _auto rows into a combined transcript (see buildWhisperWordJoin()
// in transcript-helpers.php). They are surfaced in the status list so the
// Initialize-Transcript and view pickers can offer them, and accepted by the
// run action (which enqueues a job the worker recognises and builds inline
// rather than calling any STT provider). Kept OUT of $AVAILABLE_MODELS so the
// Generate-Transcripts bulk popover never tries to "transcribe" them.
$DERIVED_MODELS = [
    ['code' => 'whisper-1-word-join',     'label' => 'Hybrid: word-level Whisper + YouTube (word timing, YouTube blocks)'],
    ['code' => 'whisper-1-word-join-seg', 'label' => 'Hybrid: word-level Whisper + YouTube + segment-level Whisper (segment-disambiguated)'],
];

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data = [];
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
}

if ($method === 'GET' && $action === 'live_count') {
    // Row count of the LIVE editable transcript for an item (to warn before
    // a consensus Initialize Edits would reset existing edits).
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $cnt = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript WHERE feed_item_key = " . $itemKey)->fetchColumn();
    $status = $db->query("SELECT edit_status FROM yy_feed_item_transcript_status WHERE feed_item_key = " . $itemKey)->fetchColumn();
    jsonResponse(['live_rows' => $cnt, 'edit_status' => $status ?: null]);
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
               MAX(feed_item_transcript_revision_dtime) AS last_run,
               COUNT(*) AS rows
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ?
         GROUP BY feed_item_transcript_auto_model
    ");
    $stmt->execute([$itemKey]);
    $runByModel = [];
    $rowsByModel = [];
    foreach ($stmt->fetchAll() as $r) {
        $runByModel[$r['model']]  = $r['last_run'];
        $rowsByModel[$r['model']] = (int)$r['rows'];
    }

    $models = [];
    foreach ($AVAILABLE_MODELS as $m) {
        $lr = $runByModel[$m['code']] ?? null;
        $models[] = [
            'code'     => $m['code'],
            'label'    => $m['label'],
            'last_run' => $lr,
            'rows'     => $rowsByModel[$m['code']] ?? 0,
            'derived'  => false,
            'ready'    => $lr !== null, // a raw model is usable once it has rows
        ];
    }
    // Derived hybrids listed after the raw models. `ready` does NOT require the
    // join to have been assembled yet — it's true as soon as the SOURCE feeds
    // exist, because Initialize (and the worker) assemble the join on demand.
    // `last_run` still reflects the last actual assembly (null if never built).
    // Engine-agnostic readiness: a word/segment feed from EITHER the OpenAI
    // (whisper-1-*) or self-hosted GPU (gpu-whisper-large-v3*) engine counts.
    $jsrc = resolveJoinSources($db, $itemKey);
    foreach ($DERIVED_MODELS as $m) {
        $ready = ($m['code'] === 'whisper-1-word-join-seg')
            ? ($jsrc['word'] && $jsrc['has_yt'] && $jsrc['seg'])
            : ($jsrc['word'] && $jsrc['has_yt']);
        $models[] = [
            'code'     => $m['code'],
            'label'    => $m['label'],
            'last_run' => $runByModel[$m['code']] ?? null,
            'rows'     => $rowsByModel[$m['code']] ?? 0,
            'derived'  => true,
            'ready'    => $ready,
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
    //
    // With ?include_recent=1 the result ALSO includes jobs that finished
    // (complete/failed/cancelled) within the last hour. The batch popover
    // uses this when it rebuilds its tracking list on (re)open so a job that
    // completed while the popover was closed still shows as ✓ Complete and is
    // counted — without it, the in-flight-only snapshot drops finished jobs
    // and the "Complete:" tally under-counts. The default (no param) is
    // unchanged so the 30s background badge poller keeps counting in-flight
    // jobs only.
    $includeRecent = !empty($_GET['include_recent']);
    // Optional: the item keys the operator just selected to Generate. The
    // unified view ALWAYS shows these (even ones whose baselines already all
    // exist, so they have no jobs) plus everything else in the queue — so the
    // initiate view and the come-back-later monitor are byte-for-byte the same.
    $selItems = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['items'] ?? ''))), function ($k) { return $k > 0; }));
    $where = $includeRecent
        ? "j.job_status IN ('pending', 'running')
             OR j.job_completed_dtime > NOW() - INTERVAL '60 minutes'"
        : "j.job_status IN ('pending', 'running')";
    // True active total (uncapped) so the badge / monitor header report the
    // real queue depth instead of the LIMIT. Without this the badge read "400"
    // when ~940 were actually queued.
    $activeCount = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status IN ('pending', 'running')")->fetchColumn();
    // ⚠ ORDER active (pending/running) FIRST, then by job_key (= worker run
    // order). With include_recent the recently-cancelled/completed rows would
    // otherwise sort ahead (job_dtime DESC) and consume the whole LIMIT,
    // hiding the still-pending queue — which left the monitor blank while 939
    // jobs waited. Recent-finished rows now only fill leftover slots.
    $stmt = $db->query("
        SELECT j.feed_item_transcript_job_key AS job_key,
               j.feed_item_key,
               j.job_model,
               j.job_status,
               j.job_progress,
               j.job_message,
               j.job_error,
               j.job_dtime,
               j.job_completed_dtime,
               COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS item_title
          FROM yy_feed_item_transcript_job j
          LEFT JOIN yy_feed_item fi USING (feed_item_key)
         WHERE $where
         ORDER BY (j.job_status IN ('pending', 'running')) DESC,
                  j.feed_item_transcript_job_key ASC
         LIMIT 400
    ");
    $jobsArr = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Guarantee the SELECTED items are fully represented even if >400 jobs are
    // active (the global LIMIT could otherwise push a freshly-dispatched item's
    // high-keyed jobs past the cap). Pull their active+recent jobs directly and
    // merge, de-duplicating by job_key.
    if ($selItems) {
        $siPh = implode(',', array_fill(0, count($selItems), '?'));
        $selStmt = $db->prepare("
            SELECT j.feed_item_transcript_job_key AS job_key, j.feed_item_key, j.job_model,
                   j.job_status, j.job_progress, j.job_message, j.job_error,
                   j.job_dtime, j.job_completed_dtime,
                   COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS item_title
              FROM yy_feed_item_transcript_job j
              LEFT JOIN yy_feed_item fi USING (feed_item_key)
             WHERE j.feed_item_key IN ($siPh)
               AND ( j.job_status IN ('pending','running')
                     OR j.job_completed_dtime > NOW() - INTERVAL '60 minutes' )
             ORDER BY j.feed_item_transcript_job_key ASC");
        $selStmt->execute($selItems);
        $haveKeys = [];
        foreach ($jobsArr as $jr) { $haveKeys[$jr['job_key']] = true; }
        foreach ($selStmt->fetchAll(PDO::FETCH_ASSOC) as $jr) {
            if (empty($haveKeys[$jr['job_key']])) { $jobsArr[] = $jr; $haveKeys[$jr['job_key']] = true; }
        }
    }
    // Already-generated baselines (model + row count + generated date) for every
    // item now on screen = selected items ∪ items that have a job. Lets the view
    // render "⏭ generated <date>" rows for engines that were skipped because
    // they already exist — the same in both the initiate and monitor flows.
    // Only computed for the monitor/unified view (include_recent or items); the
    // lightweight 30s badge poll (neither) skips this extra GROUP BY.
    $existing = [];
    $existItems = ($includeRecent || $selItems) ? $selItems : [];
    if ($includeRecent || $selItems) {
        foreach ($jobsArr as $jr) { $existItems[] = (int)$jr['feed_item_key']; }
        $existItems = array_values(array_unique(array_filter($existItems)));
    }
    if ($existItems) {
        $ePh = implode(',', array_fill(0, count($existItems), '?'));
        $exStmt = $db->prepare("
            SELECT feed_item_key                          AS item_key,
                   feed_item_transcript_auto_model        AS model,
                   COUNT(*)                               AS row_count,
                   MAX(feed_item_transcript_revision_dtime) AS last_dtime
              FROM yy_feed_item_transcript_auto
             WHERE feed_item_key IN ($ePh)
             GROUP BY feed_item_key, feed_item_transcript_auto_model
            HAVING COUNT(*) > 0");
        $exStmt->execute($existItems);
        $existing = $exStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // Editable-transcript (consensus) builds — the SEPARATE init queue, so the
    // monitor can show an "Editable" line per item with its live row count.
    $ewhere = $includeRecent
        ? "job_status IN ('pending', 'running') OR job_completed > NOW() - INTERVAL '60 minutes'"
        : "job_status IN ('pending', 'running')";
    $eStmt = $db->query("
        SELECT j.job_key,
               j.job_item_key AS feed_item_key,
               j.job_status,
               j.job_rows_written,
               j.job_error,
               j.job_completed,
               COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS item_title
          FROM yy_feed_item_transcript_init_job j
          LEFT JOIN yy_feed_item fi ON fi.feed_item_key = j.job_item_key
         WHERE $ewhere
         ORDER BY (job_status IN ('pending', 'running')) DESC, j.job_key ASC
         LIMIT 400
    ");
    // Items opted in to auto-build the editable transcript (for the checkbox).
    $optin = $db->query("SELECT feed_item_key FROM yy_feed_item_editable_optin WHERE optin = TRUE")->fetchAll(PDO::FETCH_COLUMN);
    // Editable-transcript edit status per item (Pending overwritable / Editing protected).
    $tstatus = $db->query("SELECT feed_item_key, edit_status FROM yy_feed_item_transcript_status")->fetchAll(PDO::FETCH_KEY_PAIR);
    // Running totals for the queue header: baselines done (complete) vs queued
    // (active = $activeCount); feed-items done (have an editable transcript) vs
    // queued (distinct items with a baseline still pending/running). All cheap.
    $baselinesDone = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status='complete'")->fetchColumn();
    $itemsQueued   = (int)$db->query("SELECT COUNT(DISTINCT feed_item_key) FROM yy_feed_item_transcript_job WHERE job_status IN ('pending','running')")->fetchColumn();
    $itemsDone     = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_status")->fetchColumn();
    $editJobs = $eStmt->fetchAll(PDO::FETCH_ASSOC);
    // Same guarantee for the selected items' editable (consensus) build rows.
    if ($selItems) {
        $haveE = [];
        foreach ($editJobs as $er) { $haveE[$er['job_key']] = true; }
        $selE = $db->prepare("
            SELECT j.job_key, j.job_item_key AS feed_item_key, j.job_status,
                   j.job_rows_written, j.job_error, j.job_completed,
                   COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS item_title
              FROM yy_feed_item_transcript_init_job j
              LEFT JOIN yy_feed_item fi ON fi.feed_item_key = j.job_item_key
             WHERE j.job_item_key IN ($siPh)
               AND ( j.job_status IN ('pending','running')
                     OR j.job_completed > NOW() - INTERVAL '60 minutes' )
             ORDER BY j.job_key ASC");
        $selE->execute($selItems);
        foreach ($selE->fetchAll(PDO::FETCH_ASSOC) as $er) {
            if (empty($haveE[$er['job_key']])) { $editJobs[] = $er; $haveE[$er['job_key']] = true; }
        }
    }
    jsonResponse([
        'jobs'           => $jobsArr,
        'existing'       => $existing,
        'selected'       => $selItems,
        'active_count'   => $activeCount,
        'edit_jobs'      => $editJobs,
        'optin'          => array_map('intval', $optin),
        'tstatus'        => $tstatus,
        'baselines_done' => $baselinesDone,
        'items_done'     => $itemsDone,
        'items_queued'   => $itemsQueued,
    ]);
}

if ($method === 'GET' && $action === 'item_jobs') {
    // Latest job per engine for ONE item — lets the Generate-Baselines popover
    // reconnect to in-flight + recently-finished jobs after it's been closed
    // and reopened (the session-local _gbJobs state is gone by then). Returns
    // the most recent job per job_model that is either still active OR finished
    // within the last hour, so reopening mid-run shows running/queued engines
    // and reopening just after shows the success/error of each. Reopening much
    // later returns nothing here (the ✓-done baseline badges cover that case).
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $stmt = $db->prepare("
        SELECT DISTINCT ON (job_model)
               feed_item_transcript_job_key AS job_key, job_model, job_status,
               job_progress, job_message, job_error, job_dtime, job_completed_dtime
          FROM yy_feed_item_transcript_job
         WHERE feed_item_key = ?
           AND ( job_status IN ('pending', 'running')
                 OR job_completed_dtime > NOW() - INTERVAL '60 minutes' )
         ORDER BY job_model, job_dtime DESC
    ");
    $stmt->execute([$itemKey]);
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
    $reaped = reapOrphanedJobs($db); // clear dead 'running' jobs that would wedge the gate
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status = 'running'")->fetchColumn();
    if ($running > 0) {
        jsonResponse(['ok' => true, 'spawned' => false, 'reason' => 'worker already running', 'running' => $running, 'reaped' => $reaped]);
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
    // Spawn under the same atomic gate as `run` so a manual/auto poke can't
    // race a concurrent run-POST into a second worker.
    $spawned = false;
    $running = claimWorkerSlot($db, $nextKey, $spawned);
    jsonResponse(['ok' => true, 'spawned' => $spawned, 'job_key' => $nextKey, 'running' => $running, 'reaped' => $reaped]);
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
               job_error = NULL, job_started_dtime = NULL, job_completed_dtime = NULL,
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
    // Clear Queue (monitor): cancel EVERY pending job site-wide in one shot so
    // a 939-deep queue empties with a single click (the per-job_keys path only
    // covers the ≤400 the UI had loaded). 'running' jobs are left to finish.
    if (!empty($data['all_pending'])) {
        $stmt = $db->prepare("
            UPDATE yy_feed_item_transcript_job
               SET job_status = 'cancelled', job_completed_dtime = NOW()
             WHERE job_status = 'pending'
        ");
        $stmt->execute();
        jsonResponse(['ok' => true, 'cancelled' => $stmt->rowCount()]);
    }
    // Bulk path (Pause / Clear Queue): cancel many waiting jobs in ONE request.
    // Restricted to 'pending' ONLY so an in-flight 'running' job is never
    // killed mid-chunk — "let the currently active process finish".
    if (isset($data['job_keys']) && is_array($data['job_keys'])) {
        $keys = [];
        foreach ($data['job_keys'] as $k) { $k = (int)$k; if ($k > 0) $keys[] = $k; }
        if (!$keys) { jsonResponse(['ok' => true, 'cancelled' => 0]); }
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("
            UPDATE yy_feed_item_transcript_job
               SET job_status = 'cancelled', job_completed_dtime = NOW()
             WHERE feed_item_transcript_job_key IN ($ph) AND job_status = 'pending'
        ");
        $stmt->execute($keys);
        jsonResponse(['ok' => true, 'cancelled' => $stmt->rowCount(), 'requested' => count($keys)]);
    }
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

if ($method === 'POST' && $action === 'editable_optin') {
    // Per-item opt-in toggle for auto-building the editable (consensus)
    // transcript: { item_keys:[...], optin:bool }. When opting in and the
    // item's baselines are ALREADY done, enqueue the build immediately;
    // otherwise transcript-worker enqueues it when the last baseline lands.
    $optin = !empty($data['optin']);
    $keys = [];
    foreach ((array)($data['item_keys'] ?? []) as $k) { $k = (int)$k; if ($k > 0) $keys[] = $k; }
    // Bulk: every item that currently has a pending/running baseline. They all
    // still have baselines in flight, so opting in just sets the flag — the
    // editable build auto-fires per item when its last baseline lands.
    if (empty($keys) && !empty($data['all_queued'])) {
        $keys = array_map('intval', $db->query("SELECT DISTINCT feed_item_key FROM yy_feed_item_transcript_job WHERE job_status IN ('pending','running')")->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!$keys) errorResponse('item_keys[] or all_queued required');
    $up = $db->prepare("INSERT INTO yy_feed_item_editable_optin (feed_item_key, optin, dtime)
                         VALUES (?, ?, now())
                         ON CONFLICT (feed_item_key) DO UPDATE SET optin = EXCLUDED.optin, dtime = now()");
    $enqueued = [];
    foreach ($keys as $k) {
        $up->execute([$k, (int)$optin]);   // (int) — PDO renders bool false as '' which boolean rejects
        if ($optin) { $ek = maybeEnqueueEditableNow($db, $k); if ($ek) $enqueued[] = $ek; }
    }
    jsonResponse(['ok' => true, 'updated' => count($keys), 'optin' => $optin, 'enqueued' => $enqueued]);
}

// Enqueue a consensus editable-transcript build for $itemKey RIGHT NOW, but only
// when it's ready and safe: baselines all done, no existing live transcript to
// clobber, and nothing already building/built. Returns the new init job_key or
// null. (When baselines are still pending, returns null — transcript-worker's
// on-complete hook will enqueue it instead.) Mirrors maybeAutoBuildEditable().
function maybeEnqueueEditableNow(PDO $db, int $itemKey): ?int {
    $st = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE feed_item_key = ? AND job_status IN ('pending','running')");
    $st->execute([$itemKey]); if ((int)$st->fetchColumn() > 0) return null;
    // Overwrite allowed only with no transcript yet, or a 'Pending' one
    // (consensus-built, not human-edited); 'Editing' is protected.
    $st = $db->prepare("SELECT (SELECT COUNT(*) FROM yy_feed_item_transcript WHERE feed_item_key = ?) AS rown,
                               (SELECT edit_status FROM yy_feed_item_transcript_status WHERE feed_item_key = ?) AS st");
    $st->execute([$itemKey, $itemKey]); $pr = $st->fetch(PDO::FETCH_ASSOC);
    if ((int)$pr['rown'] > 0 && (string)($pr['st'] ?? '') !== 'Pending') return null;   // protected (Editing)
    $st = $db->prepare("SELECT 1 FROM yy_feed_item_transcript_init_job WHERE job_item_key = ? AND job_status IN ('pending','running') LIMIT 1");
    $st->execute([$itemKey]); if ($st->fetchColumn()) return null;
    $st = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model FROM yy_feed_item_transcript_auto WHERE feed_item_key = ?");
    $st->execute([$itemKey]); $baselines = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
    if (!$baselines) return null;
    $params = ['max_chars' => 42, 'max_lines' => 2, 'max_secs' => 7.0, 'min_secs' => 1.2, 'break_punct' => true, 'break_gap' => 0, 'dedup' => true];
    $jp = json_encode(['baselines' => $baselines, 'primary' => '', 'params' => $params, 'wait_for' => []]);
    $ins = $db->prepare("INSERT INTO yy_feed_item_transcript_init_job (job_item_key, job_model, job_user_key, job_params) VALUES (?, 'consensus', NULL, ?) RETURNING job_key");
    $ins->execute([$itemKey, $jp]); $ik = (int)$ins->fetchColumn();
    spawnNextInitWorker($db);   // single-worker editable-build queue
    return $ik;
}

if ($method === 'POST' && $action === 'run') {
    $itemKey = (int)($data['item_key'] ?? 0);
    $model   = trim($data['model'] ?? '');
    if (!$itemKey) errorResponse('item_key required');
    // Derived hybrids are valid run targets too — the worker recognises them
    // and assembles existing _auto rows instead of calling an STT provider.
    $valid = array_merge(array_column($AVAILABLE_MODELS, 'code'), array_column($DERIVED_MODELS, 'code'));
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
    // Atomic cap=1 gate. claimWorkerSlot() holds an advisory lock while it
    // counts running workers and (if the slot is free) spawns + claims it,
    // so a burst of parallel run-POSTs can never start more than one worker.
    $spawned = false;
    $running = claimWorkerSlot($db, $jobKey, $spawned);
    if ($spawned) {
        jsonResponse(['job_key' => $jobKey, 'model' => $model, 'queued' => false, 'running' => $running]);
    } else {
        // Slot busy (or spawn failed) — leave row 'pending'. The running
        // worker's shutdown-dequeue will pick this up in FIFO order.
        $db->prepare("UPDATE yy_feed_item_transcript_job
                        SET job_message = ? WHERE feed_item_transcript_job_key = ?")
           ->execute([sprintf('Queued — %d worker busy, will start when the slot frees', $running), $jobKey]);
        jsonResponse(['job_key' => $jobKey, 'model' => $model, 'queued' => true, 'running' => $running]);
    }
}

errorResponse('Unknown action');
