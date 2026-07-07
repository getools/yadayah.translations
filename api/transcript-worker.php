<?php
/**
 * Transcription worker — runs in background, populates yy_feed_item_transcript.
 * Called as: php transcript-worker.php <job_key>
 *
 * Tries methods in order:
 *   1. yt-dlp auto-subs (if available)
 *   2. Existing .vtt/.srt file in /tmp/transcript_uploads/{item_key}.vtt
 *   3. Whisper local transcription (if installed)
 *   4. Whisper API via OpenAI (if OPENAI_API_KEY set)
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(1800); // 30 min max

if ($argc < 2) { fwrite(STDERR, "Usage: php transcript-worker.php <job_key>\n"); exit(1); }
$jobKey = (int)$argv[1];

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php'; // applyCorrectionDictionary() for snapshot
require_once __DIR__ . '/transcribe-providers.php'; // Groq / Deepgram / AssemblyAI / ElevenLabs
require_once __DIR__ . '/spawn-helpers.php';        // spawnCappedWorker() for post-exit dequeue
$db = getDb();

// ── Durable worker logging ───────────────────────────────────────────────
// The old play-by-play went to /tmp/transcript_<key>.log, which systemd-
// tmpfiles reaped before anyone could read why a worker died (lost the
// 2026-06-21 orphan's cause entirely). Now we (a) append a timestamped,
// phase-tagged trace to /var/tmp/transcript-worker/ — www-data-writable and
// NOT on the short /tmp reap cycle — and (b) capture the death cause durably
// in the DB via a diagnostic shutdown handler, so even an uncaught fatal, a
// signal, or an OOM leaves a record. /var/tmp is the only persistent path
// www-data can write outside the (web-served) document root.
if (!defined('WORKER_LOG_DIR')) define('WORKER_LOG_DIR', '/var/tmp/transcript-worker');
$GLOBALS['__wjob']   = $jobKey;
$GLOBALS['WPHASE']   = 'bootstrap';
$GLOBALS['WSIGNAL']  = '';
function wlog(string $msg): void {
    @mkdir(WORKER_LOG_DIR, 0775, true);
    $line = '[' . date('c') . ' pid=' . getmypid()
          . ' job=' . ($GLOBALS['__wjob'] ?? 0)
          . ' phase=' . ($GLOBALS['WPHASE'] ?? '?') . '] ' . $msg . "\n";
    @file_put_contents(WORKER_LOG_DIR . '/job_' . ($GLOBALS['__wjob'] ?? 0) . '.log',
                       $line, FILE_APPEND | LOCK_EX);
}
function wphase(string $p): void { $GLOBALS['WPHASE'] = $p; wlog('» phase: ' . $p); }

// Diagnostic shutdown handler — registered BEFORE the dequeue handler below
// so it runs first. Normal exit paths all set complete/failed/cancelled; if
// the job is still 'running' at process exit the worker died abnormally.
// Capture the PHP fatal (if any), the caught signal, or note an uncatchable
// death (SIGKILL/OOM), and persist it to job_error so "why did Whisper get
// stuck" is answerable after the fact. Also flips the row to 'failed' so the
// queue unwedges immediately (the dispatch reaper remains the net for the
// uncatchable SIGKILL/OOM case, where shutdown handlers never run).
register_shutdown_function(function () use ($jobKey) {
    $err     = error_get_last();
    $isFatal = $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
    $sig     = $GLOBALS['WSIGNAL'] ?? '';
    $phase   = $GLOBALS['WPHASE'] ?? '?';
    try {
        $db = getDb(); // fresh — the worker's own connection may be unusable at shutdown
        $st = $db->prepare("SELECT job_status FROM yy_feed_item_transcript_job WHERE feed_item_transcript_job_key = ?");
        $st->execute([$jobKey]);
        $status = $st->fetchColumn();
        if ($status !== 'running') {
            if ($isFatal) wlog('note: PHP fatal after terminal status (' . $status . '): ' . $err['message']);
            return;
        }
        if ($isFatal) {
            $reason = 'PHP fatal in phase ' . $phase . ': ' . $err['message']
                    . ' @ ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?');
        } elseif ($sig) {
            $reason = 'killed by ' . $sig . ' in phase ' . $phase;
        } else {
            $reason = 'process exited unexpectedly in phase ' . $phase
                    . ' (no PHP fatal — likely SIGKILL/OOM/CPU-limit; check host dmesg / cgroup OOM around ' . date('c') . ')';
        }
        wlog('ABNORMAL EXIT — ' . $reason);
        $db->prepare("UPDATE yy_feed_item_transcript_job
                         SET job_status = 'failed', job_completed_dtime = NOW(),
                             job_error = ?,
                             job_message = 'Worker died — see job_error & /var/tmp/transcript-worker'
                       WHERE feed_item_transcript_job_key = ? AND job_status = 'running'")
           ->execute([$reason, $jobKey]);
    } catch (Throwable $e) {
        wlog('shutdown diagnostic failed: ' . $e->getMessage());
    }
});

// On any exit (success, failure, fatal error) try to pull the next pending
// transcript job and spawn a worker for it. This keeps a bounded number of
// workers grinding through a long pending queue without ever exceeding the
// concurrency cap that admin-transcript-models.php enforces. Bounded so a
// 90-item bulk-generate doesn't crater Postgres's connection pool again.
register_shutdown_function(function () use ($jobKey) {
    try {
        $MAX_CONCURRENT_WORKERS = 1;
        $db = getDb();  // fresh connection — the worker's own may be in a bad state at shutdown
        // Serialize the dequeue against admin-transcript-models.php's run/poke
        // dispatch (same advisory-lock key) and claim the slot synchronously,
        // so a worker exit and a concurrent run-POST can never both spawn a
        // second worker. Enforces the strict "one STT worker at a time" rule.
        $WORKER_SLOT_LOCK = 742001;
        $db->query('SELECT pg_advisory_lock(' . $WORKER_SLOT_LOCK . ')');
        try {
            // Exclude self from the in-flight count. If the worker exits via a
            // fatal error before flipping its own status to completed/failed,
            // its row still reads 'running' and would deadlock the cap=1 queue.
            $stmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript_job
                                   WHERE job_status = 'running'
                                     AND feed_item_transcript_job_key != ?");
            $stmt->execute([(int)$jobKey]);
            $running = (int)$stmt->fetchColumn();
            if ($running >= $MAX_CONCURRENT_WORKERS) return;
            // Priority-aware FIFO: single-item interactive generates set
            // job_priority=1 so they jump ahead of a large bulk batch
            // (job_priority=0) without breaking the strict one-worker rule.
            // Ties fall back to creation order (job_key).
            $nextKey = $db->query("SELECT feed_item_transcript_job_key
                                      FROM yy_feed_item_transcript_job
                                     WHERE job_status = 'pending'
                                     ORDER BY job_priority DESC, feed_item_transcript_job_key
                                     LIMIT 1")->fetchColumn();
            if (!$nextKey) return;
            $workerScript = __DIR__ . '/transcript-worker.php';
            $logFile = sys_get_temp_dir() . '/transcript_' . $nextKey . '.log';
            $pid = spawnCappedWorker($workerScript, [(string)$nextKey], $logFile, [
                'cpu_secs' => 2400, 'mem_mb' => 2000, 'nice' => 10,
            ]);
            if ($pid > 0) {
                // Claim the slot now (status='running'), not just the pid, so a
                // run-POST waiting on the lock counts it before the new worker
                // reaches its own 'running' update ~1s into boot.
                // job_running_since marks when the job entered 'running' (not job_dtime
                // which is when it was created as 'pending') so orphan detection can
                // accurately measure how long the job has been in running state.
                $db->prepare("UPDATE yy_feed_item_transcript_job
                                 SET job_status = 'running', job_worker_pid = ?, job_running_since = NOW()
                               WHERE feed_item_transcript_job_key = ? AND job_status = 'pending'")
                   ->execute([$pid, $nextKey]);
            }
        } finally {
            $db->query('SELECT pg_advisory_unlock(' . $WORKER_SLOT_LOCK . ')');
        }
    } catch (Throwable $e) {
        error_log("transcript-worker shutdown dequeue failed: " . $e->getMessage());
    }
});

// Honor SIGTERM from admin-transcript.php's cancel handler. We don't try to
// flip the DB row here — admin-transcript.php already set 'cancelled' before
// sending the signal, so we just need to exit promptly.
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    // Log which signal arrived and in which phase before exiting. SIGTERM is
    // the normal cancel path (admin-transcript.php sets 'cancelled' first, so
    // the diagnostic shutdown handler won't touch it); SIGXCPU is what
    // `ulimit -t` raises when the worker blows its CPU-time cap — recording it
    // turns a silent disappearance into a named cause.
    $sigHandler = function ($signo) {
        $map = [SIGTERM => 'SIGTERM', SIGINT => 'SIGINT'];
        if (defined('SIGXCPU')) $map[SIGXCPU] = 'SIGXCPU (CPU-time limit / ulimit -t)';
        $sig = $map[$signo] ?? ('signal ' . $signo);
        $GLOBALS['WSIGNAL'] = $sig;
        wlog('received ' . $sig . ' — exiting (diagnostic shutdown handler records state if job was still running)');
        exit(($signo === SIGTERM || $signo === SIGINT) ? 0 : 1);
    };
    pcntl_signal(SIGTERM, $sigHandler);
    pcntl_signal(SIGINT,  $sigHandler);
    if (defined('SIGXCPU')) pcntl_signal(SIGXCPU, $sigHandler);
}

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

function updateJob(PDO $db, int $jobKey, array $fields): void {
    // Truncate job_message to fit the varchar(500) column constraint.
    if (isset($fields['job_message']) && mb_strlen($fields['job_message']) > 500) {
        $fields['job_message'] = mb_substr($fields['job_message'], 0, 497) . '...';
    }
    $sets = [];
    $params = [];
    foreach ($fields as $k => $v) {
        $sets[] = "$k = ?";
        $params[] = $v;
    }
    $params[] = $jobKey;
    // The job_status != 'cancelled' guard ensures a slow-running worker can't
    // resurrect a job that the user already cancelled.
    $db->prepare("UPDATE yy_feed_item_transcript_job SET " . implode(', ', $sets)
        . " WHERE feed_item_transcript_job_key = ? AND job_status != 'cancelled'")
       ->execute($params);
}

/**
 * Estimate how long this transcription will take, in seconds.
 *
 * Primary signal is HISTORY: completed jobs record their real begin→end time
 * (job_started_dtime → job_completed_dtime), so we learn each engine's actual
 * throughput instead of guessing. ETA = this episode's audio duration × the
 * median (processing ÷ audio) ratio of recent same-model jobs. Falls back to a
 * per-engine cold-start constant — then to a size-based estimate when audio
 * duration is unknown — until enough history accrues (e.g. just after the
 * timing column was added, or for a freshly added engine). The learned ratio
 * is over total job time, which is what a finish-time ETA should reflect.
 */
function estimateTranscriptionEtaSecs(PDO $db, string $family, string $model, int $audioDurSec, float $audioSizeMB): int {
    // Cold-start real-time factors (processing ÷ audio duration), used until
    // history exists. GPU runs ~30-50× real-time; the cloud APIs ~8-12×.
    $coldFactors = [
        'gpu' => 0.03, 'groq' => 0.05, 'deepgram' => 0.04, 'assemblyai' => 0.06,
        'openai' => 0.10, 'elevenlabs' => 0.10, 'azure' => 0.12,
    ];
    $factor = $coldFactors[$family] ?? 0.10;
    if ($family === 'gpu' && (strpos($model, 'turbo') !== false || strpos($model, 'parakeet') !== false)) {
        $factor = 0.02;   // turbo / Parakeet are faster again
    }
    // Learn from history when we have enough clean samples for this exact model.
    try {
        $st = $db->prepare(
            "SELECT EXTRACT(EPOCH FROM (j.job_completed_dtime - j.job_started_dtime))
                      / NULLIF(fi.feed_item_duration_seconds, 0) AS ratio
               FROM yy_feed_item_transcript_job j
               JOIN yy_feed_item fi ON fi.feed_item_key = j.feed_item_key
              WHERE j.job_status = 'complete'
                AND j.job_model = ?
                AND j.job_started_dtime IS NOT NULL
                AND j.job_completed_dtime > j.job_started_dtime
                AND fi.feed_item_duration_seconds > 0
              ORDER BY j.job_completed_dtime DESC
              LIMIT 20");
        $st->execute([$model]);
        // Drop outliers (bad/zero data, or jobs that stalled past real-time).
        $ratios = array_values(array_filter(
            array_map('floatval', $st->fetchAll(PDO::FETCH_COLUMN)),
            function ($r) { return $r > 0.001 && $r < 1.0; }));
        if (count($ratios) >= 3) {
            sort($ratios);
            $mid = intdiv(count($ratios), 2);
            $median = (count($ratios) % 2) ? $ratios[$mid] : ($ratios[$mid - 1] + $ratios[$mid]) / 2;
            if ($median > 0) $factor = $median;
        }
    } catch (Throwable $e) { /* fall back to the cold-start factor */ }
    return $audioDurSec > 0
        ? max(10, (int)ceil($audioDurSec * $factor))
        : max(15, (int)ceil($audioSizeMB * 8));   // no duration → size-based
}

/**
 * Returns true if this job has been cancelled. Worker should bail when this returns true.
 * Caches a stmt for cheap repeated polling.
 */
function isJobCancelled(PDO $db, int $jobKey): bool {
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $db->prepare("SELECT job_status FROM yy_feed_item_transcript_job WHERE feed_item_transcript_job_key = ?");
    }
    $stmt->execute([$jobKey]);
    return $stmt->fetchColumn() === 'cancelled';
}

/**
 * Bail out cleanly: clean up temp files, exit. Job row is already 'cancelled'
 * (set by admin-transcript.php) and updateJob's guard prevents overwriting.
 */
function bailIfCancelled(PDO $db, int $jobKey, array $tempPaths = []): void {
    if (!isJobCancelled($db, $jobKey)) return;
    foreach ($tempPaths as $p) {
        if (is_file($p)) @unlink($p);
        if (is_dir($p)) {
            foreach (glob("$p/*") as $f) @unlink($f);
            @rmdir($p);
        }
    }
    exit(0);
}

// Load job + item info
$jobStmt = $db->prepare("
    SELECT j.feed_item_key, j.job_status, j.job_model, fi.feed_item_external_id, fi.feed_item_url, fi.feed_item_type,
           fi.feed_item_duration_seconds, fi.feed_item_audio_file,
           fi.feed_key, f.feed_site_code, f.feed_account_id, f.feed_api_key
    FROM yy_feed_item_transcript_job j
    JOIN yy_feed_item fi ON j.feed_item_key = fi.feed_item_key
    JOIN yy_feed f ON fi.feed_key = f.feed_key
    WHERE j.feed_item_transcript_job_key = ?
");
$jobStmt->execute([$jobKey]);
$job = $jobStmt->fetch();
if (!$job) { fwrite(STDERR, "Job $jobKey not found\n"); exit(1); }
if ($job['job_status'] === 'cancelled') exit(0);

// Stamp the begin-processing time the instant THIS worker picks the job up.
// Timeline columns: job_dtime = queued, job_started_dtime = began, and
// job_completed_dtime = finished (set on every terminal path below). The
// NULL guard keeps the original start if a job is ever re-spawned/retried,
// and makes this independent of the dispatcher's pre-claim 'running' flip.
$db->prepare("UPDATE yy_feed_item_transcript_job
                 SET job_started_dtime = NOW()
               WHERE feed_item_transcript_job_key = ? AND job_started_dtime IS NULL")
   ->execute([$jobKey]);

$itemKey = (int)$job['feed_item_key'];
$videoId = $job['feed_item_external_id'];
$videoUrl = $job['feed_item_url'];
$site = strtolower($job['feed_site_code']);
// Which model this job is for. 'youtube' means caption-import only (no
// OpenAI call). The OpenAI variants pick the API model and granularity
// (see whisperApiTranscribe for the mapping). Pre-existing jobs with
// NULL default to whisper-1-segment for safety.
$jobModel = $job['job_model'] ?: 'whisper-1-segment';
$wantYoutubeCaptions = ($jobModel === 'youtube');

// ── Derived join models are NOT transcription jobs ──────────────────────
// 'whisper-1-word-join'      = whisper-1-word + youtube (block boundaries)
// 'whisper-1-word-join-seg'  = the above + whisper-1-segment disambiguation
// They assemble existing _auto rows; there is no audio fetch or STT call.
// Build inline and complete so the existing run→poll→done UI works for them
// unchanged (this is the on-demand "Generate" path from the view/Initialize
// pickers; the worker also auto-derives them after a word/youtube/segment
// run completes — see the block near the end of this file).
if ($jobModel === 'whisper-1-word-join' || $jobModel === 'whisper-1-word-join-seg') {
    $useSeg = ($jobModel === 'whisper-1-word-join-seg');
    updateJob($db, $jobKey, ['job_status' => 'running', 'job_progress' => 20,
        'job_worker_pid' => getmypid(),
        'job_message' => 'Assembling ' . $jobModel . ' from existing auto rows…']);
    try {
        $n = buildWhisperWordJoin($db, $itemKey, 'youtube', $useSeg);
        if ($n === 0) {
            $need = 'a word-level whisper + youtube' . ($useSeg ? ' + a segment-level whisper' : '');
            updateJob($db, $jobKey, ['job_status' => 'failed', 'job_progress' => 100,
                'job_error'   => 'Join produced 0 rows — generate ' . $need . ' first.',
                'job_message' => 'Missing source rows for ' . $jobModel,
                'job_completed_dtime' => date('c')]);
        } else {
            updateJob($db, $jobKey, ['job_status' => 'complete', 'job_progress' => 100,
                'job_message' => 'Built ' . $n . ' ' . $jobModel . ' rows',
                'job_completed_dtime' => date('c')]);
            error_log("transcript-worker: built $n $jobModel rows for item $itemKey");
        }
    } catch (Throwable $e) {
        updateJob($db, $jobKey, ['job_status' => 'failed', 'job_progress' => 100,
            'job_error'   => 'Join build error: ' . $e->getMessage(),
            'job_message' => 'Join build failed',
            'job_completed_dtime' => date('c')]);
        error_log("transcript-worker: $jobModel build failed for item $itemKey: " . $e->getMessage());
    }
    exit(0); // register_shutdown_function dequeues the next pending job
}

// NOTE: We used to override model='youtube' → 'whisper-1-segment' whenever
// an uploaded MP3 existed, on the theory that "we have the audio, just
// re-transcribe it". That conflated two different things — the durable
// memory `feedback_transcripts_use_mp3.md` only forbids using YouTube as
// an *audio* source when an MP3 exists. Importing YouTube *captions* is
// independent: when explicitly chosen as the model, it's a way to seed
// the editable transcript with whatever YouTube auto-generated. If
// yt-dlp gets bot-blocked the job fails cleanly with the actual yt-dlp
// error in methodFailures, and the operator can pick a different model.

updateJob($db, $jobKey, ['job_status' => 'running', 'job_progress' => 5, 'job_message' => 'Starting transcription...']);
wphase('start:' . $jobModel);
wlog('item=' . $itemKey . ' model=' . $jobModel . ' site=' . ($site ?? '?') . ' video=' . ($videoId ?? '?'));

$rows = [];
$methodFailures = []; // collects "method_name: reason" so the final error is actionable

// ── Method 1: Pre-uploaded VTT/SRT file ──
// Only consulted when the job's chosen model is 'youtube' — these uploaded
// VTT/SRT files are typically exported YouTube captions. When an OpenAI
// model is selected, the operator has explicitly asked for a fresh
// machine transcription against the MP3; skip the uploaded captions.
$uploadDir = sys_get_temp_dir() . '/transcript_uploads';
$uploadVtt = "$uploadDir/{$itemKey}.vtt";
$uploadSrt = "$uploadDir/{$itemKey}.srt";
$uploadedSourceLabel = '';
if ($wantYoutubeCaptions === false) {
    // Force the method-1 block below to no-op by emptying the candidate paths.
    $uploadVtt = $uploadSrt = '/dev/null/skipped-by-job-model';
}
$uploadedSourcePath = '';
if (file_exists($uploadVtt)) {
    updateJob($db, $jobKey, ['job_progress' => 50, 'job_message' => 'Parsing uploaded VTT...']);
    $rows = parseVtt(file_get_contents($uploadVtt));
    $uploadedSourceLabel = 'uploaded_vtt';
    $uploadedSourcePath = $uploadVtt;
} elseif (file_exists($uploadSrt)) {
    updateJob($db, $jobKey, ['job_progress' => 50, 'job_message' => 'Parsing uploaded SRT...']);
    $rows = parseSrt(file_get_contents($uploadSrt));
    $uploadedSourceLabel = 'uploaded_srt';
    $uploadedSourcePath = $uploadSrt;
}

if (!$rows && file_exists($uploadVtt)) $methodFailures[] = "uploaded_vtt: parsed 0 rows";
elseif (!$rows && file_exists($uploadSrt)) $methodFailures[] = "uploaded_srt: parsed 0 rows";

// Coverage gate for uploaded VTT/SRT — mirrors the yt-dlp captions check so a
// stale partial caption file doesn't get accepted as a complete transcript.
// (Bit us once via /tmp/transcript_uploads/791503.vtt covering 9.5% of a 52-min
// video; same shape as the partial-audio bug the worker already guards against.)
if ($rows && $uploadedSourceLabel) {
    $videoDur = (int)($job['feed_item_duration_seconds'] ?? 0);
    if ($videoDur > 60) {
        $lastEnd = 0;
        foreach ($rows as $r) {
            $end = isset($r['end_seconds']) ? (int)$r['end_seconds'] : 0;
            if (!$end && isset($r['segment'])) {
                $parts = explode(':', $r['segment']);
                if (count($parts) === 3) $end = (int)$parts[0]*3600 + (int)$parts[1]*60 + (int)$parts[2];
            }
            if ($end > $lastEnd) $lastEnd = $end;
        }
        $coverage = $lastEnd / $videoDur;
        if ($coverage < 0.80) {
            $methodFailures[] = $uploadedSourceLabel . ": only " . round($coverage * 100) . "% coverage ($lastEnd s of $videoDur s) — discarding partial upload";
            $rows = [];
            // Remove the stale partial file so it doesn't keep poisoning future
            // runs for this item — fall through to yt-dlp / Whisper which will
            // get full coverage.
            @unlink($uploadedSourcePath);
        }
    }
}

bailIfCancelled($db, $jobKey);

// Cookies file used to bypass YouTube's bot-detection. If present, both yt-dlp
// invocations below get `--cookies <file>`. Admin uploads/refreshes via admin-feeds.
// Path matches admin-cookies.php — /tmp is shared inside the same container.
// Only treat the file as "have auth cookies" when it contains a YouTube session
// token (SAPISID or __Secure-3PSID). Analytics-only cookies (_ga etc.) don't
// help with Botguard and incorrectly trigger the web-client path which requires
// real auth — ios client works better without auth for non-restricted videos.
$cookiesPath = '/tmp/youtube-cookies.txt';
// yt-dlp always tries to write the cookies file back after use. If the file
// is owned by root (or otherwise not writable by www-data), yt-dlp aborts with
// PermissionError. Guard: only pass --cookies when the file is actually writable
// by the current process; otherwise fall back to the ios client (no auth).
$cookiesContent = '';
if (file_exists($cookiesPath) && filesize($cookiesPath) > 0) {
    if (!is_writable($cookiesPath)) {
        wlog('WARNING: cookies file not writable by www-data — skipping auth cookies '
           . '(fix with: chown www-data:www-data /tmp/youtube-cookies.txt)');
    } else {
        $cookiesContent = (string)@file_get_contents($cookiesPath);
    }
}
$haveCookies = $cookiesContent !== ''
    && (strpos($cookiesContent, 'SAPISID') !== false
        || strpos($cookiesContent, '__Secure-3PSID') !== false);
unset($cookiesContent);
$cookiesArg  = $haveCookies ? ' --cookies ' . escapeshellarg($cookiesPath) : '';
// Player client choice:
//   - With auth cookies or POT provider: prefer `web` (full formats, accepts
//     cookies and PO tokens). Other clients fall back automatically.
//   - Without either: try `ios` to evade bot-detection.
// PO Token provider (bgutil sidecar) defeats Botguard for the `web` client.
// Fallback to well-known Docker service hostname when env var not set.
// Joined into the same --extractor-args so yt-dlp parses both keys.
$potUrl = getenv('POT_PROVIDER_URL') ?: '';
if (!$potUrl) {
    // Auto-detect the bgutil sidecar via its Docker-network hostname.
    // The ping endpoint is cheap; if it responds the provider is ready.
    $pingCtx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $ping = @file_get_contents('http://pot-provider:4416/ping', false, $pingCtx);
    if ($ping && strpos($ping, 'version') !== false) {
        $potUrl = 'http://pot-provider:4416';
    }
}
// The youtubepot-bgutilhttp plugin needs its own --extractor-args flag (not
// semicolon-appended to the youtube args) so yt-dlp parses it as a separate
// ie_key and the plugin's _configuration_arg('base_url') returns the correct URL
// rather than falling back to the default http://127.0.0.1:4416.
$potBgutil = $potUrl ? " --extractor-args 'youtubepot-bgutilhttp:base_url=" . $potUrl . "'" : '';
$playerArg   = ($haveCookies || $potUrl)
    ? " --extractor-args 'youtube:player_client=web,mweb,web_safari'" . $potBgutil
    : " --extractor-args 'youtube:player_client=ios'";

// YouTube's modern n-challenge requires a JS solver. Deno is installed in the
// container, and `--remote-components ejs:github` lets yt-dlp auto-fetch the
// challenge-solver lib from the yt-dlp/ejs releases. Without this, all formats
// after the n-param get stripped and only image storyboards are returned.
$remoteComponentsArg = ' --remote-components ejs:github';

// ── Method 2: yt-dlp auto-captions (host) ──
// Only when the chosen model is 'youtube'. For OpenAI models we want a
// fresh API transcription, not YouTube's captions.
if (!$rows && $site === 'youtube' && $wantYoutubeCaptions) {
    updateJob($db, $jobKey, ['job_progress' => 15, 'job_message' => 'Fetching YouTube captions...']);
    $tmpFile = sys_get_temp_dir() . "/transcript_$jobKey";
    // Prefer pip-based yt-dlp wrapper: it loads the bgutil POT provider plugin
    // needed to bypass YouTube's Botguard. The compiled binary at
    // /usr/local/bin/yt-dlp cannot load external Python plugins.
    $ytDlpWrapper = __DIR__ . '/yt-dlp-plugin';
    $ytDlp = is_executable($ytDlpWrapper)
        ? $ytDlpWrapper
        : trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
    if (!$ytDlp) {
        $methodFailures[] = "yt_dlp_captions: yt-dlp binary not found in PATH";
    } elseif (!$videoId) {
        $methodFailures[] = "yt_dlp_captions: no videoId on item";
    } else {
        // Serialize concurrent yt-dlp YouTube fetches with a flock so that
        // even when multiple `youtube`-model jobs are spawned in parallel,
        // only one yt-dlp call is in flight at a time. Two reasons:
        //   1. Parallel requests from the same IP get bot-blocked faster
        //      than serial ones — YouTube's anti-abuse heuristics
        //      penalise bursts.
        //   2. The cookies file at /tmp/youtube-cookies.txt is per-session;
        //      yt-dlp can write back to it on close, and racing writers
        //      could corrupt it.
        // The job's progress message updates so the admin sees "waiting
        // for yt-dlp lock" rather than appearing to stall silently.
        $ytLockPath = sys_get_temp_dir() . '/yt-dlp-youtube.lock';
        $ytLockFp = @fopen($ytLockPath, 'c');
        if ($ytLockFp) {
            // Try non-blocking once so we can surface "waiting" status
            // before settling in for a blocking wait.
            if (!flock($ytLockFp, LOCK_EX | LOCK_NB)) {
                updateJob($db, $jobKey, ['job_progress' => 12,
                    'job_message' => 'Waiting for another YouTube caption job to finish (serialised to avoid bot-detection)…']);
                flock($ytLockFp, LOCK_EX);
                updateJob($db, $jobKey, ['job_progress' => 15,
                    'job_message' => 'Fetching YouTube captions...']);
            }
        }
        $cmd = escapeshellcmd($ytDlp) . $cookiesArg . $playerArg . $remoteComponentsArg . " --skip-download --write-auto-subs --sub-lang en --sub-format vtt --output " . escapeshellarg("$tmpFile.%(ext)s") . " " . escapeshellarg("https://www.youtube.com/watch?v=$videoId") . " 2>&1";
        $output = shell_exec($cmd);
        // Release the yt-dlp lock immediately after the shell call returns
        // so the next queued YouTube job can start while this one parses /
        // saves rows. Parsing is local CPU work — no need to hold the
        // network-fairness lock through it.
        if ($ytLockFp) {
            flock($ytLockFp, LOCK_UN);
            fclose($ytLockFp);
        }
        $vttFiles = glob("$tmpFile*.vtt");
        if ($vttFiles && file_exists($vttFiles[0])) {
            updateJob($db, $jobKey, ['job_progress' => 70, 'job_message' => 'Parsing captions...']);
            $rows = parseVtt(file_get_contents($vttFiles[0]));
            foreach ($vttFiles as $f) @unlink($f);
            if (!$rows) {
                $methodFailures[] = "yt_dlp_captions: VTT parsed but produced 0 rows";
            } else {
                // Coverage check: if last caption ends well before the video duration,
                // the captions are partial (e.g. YouTube auto-captions still generating
                // for a recent upload). Fall through to Whisper instead of treating
                // truncated captions as success.
                $videoDur = (int)($job['feed_item_duration_seconds'] ?? 0);
                if ($videoDur > 60) {
                    $lastEnd = 0;
                    foreach ($rows as $r) {
                        $end = isset($r['end_seconds']) ? (int)$r['end_seconds'] : 0;
                        if (!$end && isset($r['segment'])) {
                            // segment is "HH:MM:SS" or interval — convert to seconds
                            $parts = explode(':', $r['segment']);
                            if (count($parts) === 3) $end = (int)$parts[0]*3600 + (int)$parts[1]*60 + (int)$parts[2];
                        }
                        if ($end > $lastEnd) $lastEnd = $end;
                    }
                    $coverage = $lastEnd / $videoDur;
                    if ($coverage < 0.8) {
                        $methodFailures[] = "yt_dlp_captions: only " . round($coverage * 100) . "% coverage ($lastEnd s of $videoDur s) — likely partial captions";
                        $rows = []; // discard partial — fall through to Whisper
                    }
                }
            }
        } else {
            // Extract the actionable ERROR line if present; fall back to first 300 chars.
            // Mirrors what the whisper_api section does so captions failures are equally readable.
            $captErrLine = '';
            if ($output && preg_match('/^ERROR:.*$/m', $output, $captM)) $captErrLine = $captM[0];
            $captTail = $captErrLine ?: substr(trim($output), 0, 300);
            $captReason = 'yt-dlp produced no VTT';
            $botBlocked = stripos($output, 'not a bot') !== false || stripos($output, 'confirm you') !== false;
            $cookiesRotated = $haveCookies
                && (stripos($output, 'no longer valid') !== false
                    || stripos($output, 'rotated in the browser') !== false);
            if ($botBlocked) {
                $captReason = ($cookiesRotated || $haveCookies)
                    ? 'cookies stale/rotated — bot-detection blocked captions download'
                    : 'bot-detection blocked captions download';
            } elseif ($cookiesRotated) {
                $captReason = 'cookies are stale/rotated';
            } elseif (stripos($output, 'age-restricted') !== false || stripos($output, 'age restricted') !== false) {
                $captReason = 'video is age-restricted';
            } elseif (stripos($output, 'private video') !== false) {
                $captReason = 'video is private';
            } elseif (stripos($output, 'video unavailable') !== false || stripos($output, 'has been removed') !== false) {
                $captReason = 'video is deleted or unavailable';
            } elseif (stripos($output, 'no subtitles') !== false || stripos($output, 'no automatic captions') !== false) {
                $captReason = 'no auto-captions available';
            }
            $methodFailures[] = "yt_dlp_captions: $captReason — " . $captTail;

            // When cookies are stale/expired (bot-detection fires despite having a
            // cookies file), retry once with the ios client (no auth). ios sometimes
            // bypasses Botguard without a session when the web client is rejected.
            // Previously only triggered on explicit "rotated" messages; widened to
            // cover the common case where YouTube just says "Sign in to confirm
            // you're not a bot" without including the rotation verbiage.
            if ($cookiesRotated || ($haveCookies && $botBlocked)) {
                updateJob($db, $jobKey, ['job_progress' => 18,
                    'job_message' => 'Cookies were stale — retrying with ios client (no auth)...']);
                $cmdRetry = escapeshellcmd($ytDlp)
                    . " --extractor-args 'youtube:player_client=ios'"
                    . $remoteComponentsArg
                    . ' --skip-download --write-auto-subs --sub-lang en --sub-format vtt --output '
                    . escapeshellarg("$tmpFile.%(ext)s") . ' '
                    . escapeshellarg("https://www.youtube.com/watch?v=$videoId") . ' 2>&1';
                $outputRetry = shell_exec($cmdRetry);
                $vttRetry = glob("$tmpFile*.vtt");
                if ($vttRetry && file_exists($vttRetry[0])) {
                    updateJob($db, $jobKey, ['job_progress' => 70, 'job_message' => 'Parsing captions (ios retry)...']);
                    $rows = parseVtt(file_get_contents($vttRetry[0]));
                    foreach ($vttRetry as $f) @unlink($f);
                    if (!$rows) {
                        $methodFailures[] = 'yt_dlp_captions_ios_retry: VTT parsed but produced 0 rows';
                    }
                } else {
                    $retryErrLine = '';
                    if ($outputRetry && preg_match('/^ERROR:.*$/m', $outputRetry, $retryM)) $retryErrLine = $retryM[0];
                    $methodFailures[] = 'yt_dlp_captions_ios_retry: '
                        . ($retryErrLine ?: substr(trim($outputRetry), 0, 300) ?: 'no VTT produced');
                }
            }
        }
    }
}

bailIfCancelled($db, $jobKey);

// ── Method 3: Cloud transcription provider ──
// Skip entirely when the chosen model is 'youtube' (caption-import only).
// $providerFamily routes the API call to the right vendor — OpenAI for
// whisper-1-*, Groq for groq-*, Deepgram for deepgram-*, etc. See
// transcribe-providers.php for the per-provider helpers.
$providerFamily = providerFamilyForModel($jobModel);
$providerKeyEnv = [
    'openai'      => 'OPENAI_API_KEY',
    'groq'        => 'GROQ_API_KEY',
    'deepgram'    => 'DEEPGRAM_API_KEY',
    'assemblyai'  => 'ASSEMBLYAI_API_KEY',
    'elevenlabs'  => 'ELEVENLABS_API_KEY',
    'azure'       => 'AZURE_SPEECH_KEY',
    'gpu'         => 'GPU_API_TOKEN',
][$providerFamily] ?? 'OPENAI_API_KEY';
if (!$rows && !$wantYoutubeCaptions) {
    $providerKey = readEnv($providerKeyEnv);
    if (!$providerKey) {
        $methodFailures[] = "$providerFamily: $providerKeyEnv not set in .env";
    } else {
        // Backward-compat alias so existing OpenAI block code still reads $openaiKey.
        $openaiKey = $providerKey;
        updateJob($db, $jobKey, ['job_progress' => 20, 'job_message' => 'Preparing audio...']);
        $audioPath = sys_get_temp_dir() . "/transcript_audio_$jobKey.mp3";
        $usedUploadedAudio = false;

        // Source-of-truth precedence:
        //   1. yy_feed_item.feed_item_audio_file (durable, browser-recorded
        //      uploads or rumble-imported MP3s)
        //   2. /tmp/transcript_uploads/ (legacy / ephemeral admin uploads)
        //   3. yt-dlp download (last resort, often blocked by bot detection)
        $uploadedAudio = null;
        $rel = $job['feed_item_audio_file'] ?? null;
        if ($rel) {
            $candAbs = dirname(__DIR__) . '/' . ltrim($rel, '/');
            if (is_file($candAbs) && filesize($candAbs) > 10000) {
                $uploadedAudio = $candAbs;
            }
        }
        if (!$uploadedAudio) {
            foreach (['mp3', 'm4a', 'opus', 'wav', 'ogg', 'aac', 'webm'] as $ext) {
                $cand = "$uploadDir/{$itemKey}.{$ext}";
                if (file_exists($cand) && filesize($cand) > 10000) { $uploadedAudio = $cand; break; }
            }
        }
        if ($uploadedAudio) {
            // Verify the upload actually covers the full video. Browser-recorded
            // .webm captures and partial admin uploads sometimes land here much
            // shorter than the source video — using them silently produces a
            // transcript that only covers the first minute or two.
            $videoDur = (int)($job['feed_item_duration_seconds'] ?? 0);
            $audioDur = 0;
            $probe = shell_exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($uploadedAudio) . ' 2>/dev/null');
            if (is_numeric(trim($probe ?? ''))) {
                $audioDur = (int)trim($probe);
            } else {
                $probe2 = shell_exec('ffmpeg -i ' . escapeshellarg($uploadedAudio) . ' -f null - 2>&1 | tail -2');
                if ($probe2 && preg_match('/time=(\d+):(\d+):(\d+)/', $probe2, $tm)) {
                    $audioDur = (int)$tm[1]*3600 + (int)$tm[2]*60 + (int)$tm[3];
                }
            }
            if ($videoDur > 60 && $audioDur > 0 && ($audioDur / $videoDur) < 0.8) {
                $methodFailures[] = "$providerFamily: uploaded audio (" . basename($uploadedAudio) . ") is only " . round($audioDur / $videoDur * 100) . "% of video duration ($audioDur s of $videoDur s) — likely partial recording";
                $uploadedAudio = null;
            }
        }
        if ($uploadedAudio) {
            $audioPath = $uploadedAudio;
            $usedUploadedAudio = true;
            updateJob($db, $jobKey, ['job_progress' => 30, 'job_message' => 'Using audio file: ' . basename($uploadedAudio)]);
        }

        // Prefer pip-based yt-dlp wrapper: it loads the bgutil POT provider plugin
        // needed to bypass YouTube's Botguard. The compiled binary at
        // /usr/local/bin/yt-dlp cannot load external Python plugins.
        $ytDlpWrapper = __DIR__ . '/yt-dlp-plugin';
        $ytDlp = is_executable($ytDlpWrapper)
            ? $ytDlpWrapper
            : trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
        if (!$usedUploadedAudio && !$ytDlp) {
            $methodFailures[] = "whisper_api: yt-dlp binary not found (needed to download audio)";
        } elseif (!$usedUploadedAudio && !$videoUrl) {
            $methodFailures[] = "whisper_api: feed_item_url is empty";
        } else {
            // Skip yt-dlp download if we already have an uploaded audio file
            $dlOutput = '';
            if (!$usedUploadedAudio) {
                // --newline forces yt-dlp to emit one progress line per update
                // (instead of CR-overwriting the same line), so we can stream
                // and parse it. Map yt-dlp's 0-100% download progress into the
                // 30-65% slice of our overall job_progress.
                $cmd = escapeshellcmd($ytDlp) . $cookiesArg . $playerArg . $remoteComponentsArg
                     . " --newline -x --audio-format mp3 --audio-quality 5 --output "
                     . escapeshellarg($audioPath) . " " . escapeshellarg($videoUrl) . " 2>&1";
                $proc = popen($cmd, 'r');
                if ($proc) {
                    $lastReportedPct = -1;
                    while (($line = fgets($proc)) !== false) {
                        $dlOutput .= $line;
                        // [download]  23.5% of 12.30MiB at 1.40MiB/s ETA 00:08
                        if (preg_match('/^\[download\]\s+([\d.]+)%/', $line, $m)) {
                            $dlPct = (float)$m[1];
                            $jobPct = 30 + (int)round($dlPct * 0.35); // 30..65
                            if ($jobPct !== $lastReportedPct) {
                                $lastReportedPct = $jobPct;
                                $msg = 'Downloading audio… ' . number_format($dlPct, 0) . '%';
                                if (preg_match('/ETA\s+([\d:]+)/', $line, $eta)) {
                                    $msg .= ' (ETA ' . $eta[1] . ')';
                                }
                                updateJob($db, $jobKey, ['job_progress' => $jobPct, 'job_message' => $msg]);
                            }
                        }
                    }
                    pclose($proc);
                }
            }
            if (!file_exists($audioPath) || filesize($audioPath) <= 10000) {
                // Extract the actionable ERROR line if present; otherwise use last ~400 chars
                // (yt-dlp emits progress on early lines and the real failure on the last).
                $errLine = '';
                if ($dlOutput && preg_match('/^ERROR:.*$/m', $dlOutput, $m)) $errLine = $m[0];
                $tail = $errLine ?: substr(trim($dlOutput ?? ''), -400);

                $reason = 'audio download failed';
                $hint = '';
                $haystack = $dlOutput ?? '';
                if (stripos($haystack, "not a bot") !== false || stripos($haystack, 'confirm you') !== false) {
                    $reason = "YouTube bot-detection blocked this server's IP";
                    $hint = ' [Fix: supply --cookies-from-browser, route through a residential proxy, or upload audio/VTT manually to /tmp/transcript_uploads/' . $itemKey . '.vtt]';
                } elseif (stripos($haystack, 'live event will begin') !== false) {
                    $reason = 'video is a scheduled future live event — not yet available';
                } elseif (stripos($haystack, 'live event has ended') !== false) {
                    $reason = 'live event has ended but recording is not yet processed by YouTube';
                } elseif (stripos($haystack, 'private video') !== false) {
                    $reason = 'video is private';
                } elseif (stripos($haystack, 'video unavailable') !== false || stripos($haystack, 'has been removed') !== false) {
                    $reason = 'video is deleted or unavailable';
                } elseif (stripos($haystack, 'age-restricted') !== false || stripos($haystack, 'age restricted') !== false) {
                    $reason = 'video is age-restricted';
                } elseif (stripos($haystack, 'only images are available') !== false) {
                    $reason = 'YouTube Community post or image-only item — no audio track available';
                }
                $methodFailures[] = "$providerFamily: $reason$hint — " . $tail;
            } else {
                // Estimate transcription turnaround. Learns each engine's real
                // throughput from the begin→end times of past completed jobs
                // (see estimateTranscriptionEtaSecs); until history exists it
                // uses per-engine constants. The old single "8 s per MB" factor
                // was calibrated for the OpenAI Whisper API and over-stated the
                // self-hosted GPU box (~30-50× real-time) by ~3-5× — a 90 s job
                // advertising a 5 min ETA.
                $audioSizeMB = filesize($audioPath) / 1024 / 1024;
                $audioDurSec = (int)($job['feed_item_duration_seconds'] ?? 0);
                $etaSeconds = estimateTranscriptionEtaSecs($db, $providerFamily, $jobModel, $audioDurSec, $audioSizeMB);
                updateJob($db, $jobKey, [
                    'job_progress' => 70,
                    'job_message' => 'Transcribing… (ETA ~' . $etaSeconds . 's)',
                ]);
                $glossaryPrompt = buildGlossaryPrompt($db);
                $whisperErr = '';
                $whisperChunked = filesize($audioPath) > 24 * 1024 * 1024;

                // Public URL for the audio file — used by Deepgram and
                // AssemblyAI, which prefer to fetch from URL rather than
                // accept large uploads. Only available when the source was
                // a durable /opt/yada-www/public/u/audio/... upload (the
                // common case for admin-recorded MP3s). yt-dlp downloads
                // land in /tmp and have no public URL.
                $publicAudioUrl = '';
                if ($usedUploadedAudio && !empty($job['feed_item_audio_file'])) {
                    $rel = ltrim((string)$job['feed_item_audio_file'], '/');
                    $rel = preg_replace('#^public/#', '', $rel);
                    $publicAudioUrl = 'https://yadayah.com/' . $rel;
                }

                // Keyword-boost list from the correction dictionary —
                // sent on every Deepgram / AssemblyAI run so the model
                // biases toward our confirmed spellings (Yahowah, Towrah,
                // Yada Yah, etc.) without us paying for fine-tuning.
                // OpenAI/Groq already get the equivalent via the
                // glossary `prompt` parameter.
                $boostList = ($providerFamily === 'deepgram' || $providerFamily === 'assemblyai')
                    ? buildKeywordBoostList($db, 100)
                    : [];

                wphase('engine:' . $providerFamily . ':' . $jobModel);
                wlog('calling provider=' . $providerFamily . ' audioMB=' . round($audioSizeMB, 1)
                    . ' chunked=' . ($whisperChunked ? 'y' : 'n') . ' eta~' . $etaSeconds . 's — this is where the 2026-06-21 orphan died');
                $__engineT0 = microtime(true);
                $speakerEmb = null;   // per-speaker voice embeddings (whisperx diarize) → stored post-insert
                switch ($providerFamily) {
                    case 'openai':
                        if ($whisperChunked) {
                            $rows = whisperApiTranscribeChunked($db, $jobKey, $audioPath, $openaiKey, $glossaryPrompt, $whisperErr, $jobModel);
                        } else {
                            $rows = whisperApiTranscribe($audioPath, $openaiKey, $glossaryPrompt, 0, $whisperErr, $jobModel);
                        }
                        break;
                    case 'groq':
                        // Groq's free tier caps uploads at 25 MB — always
                        // chunk, regardless of file size.
                        $rows = groqTranscribeChunked($db, $jobKey, $audioPath, $providerKey, $glossaryPrompt, $whisperErr, providerNativeModel($jobModel));
                        break;
                    case 'deepgram':
                        if ($publicAudioUrl === '') {
                            $whisperErr = 'Deepgram needs a public audio URL — only durable uploads supported';
                            $rows = [];
                        } else {
                            $rows = deepgramTranscribe($publicAudioUrl, $providerKey, $whisperErr, $boostList);
                        }
                        break;
                    case 'assemblyai':
                        if ($publicAudioUrl === '') {
                            $whisperErr = 'AssemblyAI needs a public audio URL — only durable uploads supported';
                            $rows = [];
                        } else {
                            $rows = assemblyaiTranscribe($publicAudioUrl, $providerKey, $whisperErr, $boostList);
                        }
                        break;
                    case 'elevenlabs':
                        $rows = elevenlabsScribeTranscribe($audioPath, $providerKey, $whisperErr);
                        break;
                    case 'azure':
                        $azureRegion = readEnv('AZURE_SPEECH_REGION') ?: 'brazilsouth';
                        $rows = azureSpeechTranscribe($audioPath, $providerKey, $azureRegion, $whisperErr);
                        break;
                    case 'gpu':
                        // Self-hosted faster-whisper on the Puget box via tailnet
                        // gateway. The helper handles its own OpenAI fallback if
                        // the box is unreachable (and OPENAI_API_KEY is set).
                        // Pass $db and $jobKey so the helper's OpenAI fallback
                        // can chunk via whisperApiTranscribeChunked when the
                        // audio exceeds the 25 MB OpenAI upload limit. Without
                        // these the unchunked fallback hits HTTP 413.
                        $rows = gpuWhisperTranscribe($audioPath, $glossaryPrompt, 0, $whisperErr, $jobModel, $db, $jobKey, $speakerEmb);
                        break;
                    default:
                        $whisperErr = "unknown provider family: $providerFamily";
                        $rows = [];
                }
                wlog('provider=' . $providerFamily . ' returned ' . count($rows ?: []) . ' rows in '
                    . round(microtime(true) - $__engineT0, 1) . 's' . ($whisperErr ? ' (err: ' . $whisperErr . ')' : ''));
                // Only delete the audio if we downloaded it ourselves; preserve admin uploads
                if (!$usedUploadedAudio) @unlink($audioPath);
                if (!$rows) {
                    $methodFailures[] = "$providerFamily: " . ($whisperErr ?: 'API returned no segments');
                } else {
                    // Coverage gate: 80% for chunked paths where chunk failures can leave
                    // the transcript truncated. For non-chunked paths Whisper processes the
                    // full audio end-to-end; coverage below 100% means silent/non-speech
                    // content at the end (music, credits, silence) — use 70% to avoid
                    // falsely rejecting transcripts of short videos with silent endings.
                    $videoDur = (int)($job['feed_item_duration_seconds'] ?? 0);
                    if ($videoDur > 60) {
                        $lastEnd = 0;
                        foreach ($rows as $r) {
                            $end = isset($r['end_seconds']) ? (int)$r['end_seconds'] : 0;
                            if (!$end && isset($r['segment'])) {
                                $parts = explode(':', $r['segment']);
                                if (count($parts) === 3) $end = (int)$parts[0]*3600 + (int)$parts[1]*60 + (int)$parts[2];
                            }
                            if ($end > $lastEnd) $lastEnd = $end;
                        }
                        $coverage = $lastEnd / $videoDur;
                        $coverageThreshold = $whisperChunked ? 0.80 : 0.70;
                        if ($coverage < $coverageThreshold) {
                            $methodFailures[] = "$providerFamily: only " . round($coverage * 100) . "% coverage ($lastEnd s of $videoDur s)" . ($whisperErr ? " ($whisperErr)" : '');
                            $rows = [];
                        } elseif ($whisperErr) {
                            // Partial chunk failures — keep the rows but log.
                            error_log("transcript job $jobKey whisper partial: $whisperErr");
                        }
                    }
                }
                // Corrections applied below for any method that produced rows
            }
        }
    }
}

// Apply correction dictionary to whatever rows we got, regardless of source.
// This ensures uploaded VTT/SRT captions and yt-dlp captions both get the
// same Yahowah/Towrah-style normalization that Whisper output gets.
if ($rows) {
    // Surface the segment count the instant transcription returns (it's a
    // single blocking provider call — no count is knowable before this), so
    // the queue's running row shows "N segments" through correction + save
    // instead of an opaque "Transcribing…".
    updateJob($db, $jobKey, ['job_progress' => 85, 'job_message' => 'Transcribed ' . count($rows) . ' segments — applying corrections...']);
    $rows = applyCorrectionsToRows($db, $rows);
}

if (!$rows) {
    $detail = "Item: $itemKey ($videoId, $site, " . ($videoUrl ?: 'no url') . ")\n"
            . "Methods tried, in order:\n  - " . implode("\n  - ", $methodFailures ?: ['no methods attempted']);
    updateJob($db, $jobKey, [
        'job_status' => 'failed',
        'job_progress' => 0,
        'job_message' => 'Failed: ' . ($methodFailures[count($methodFailures)-1] ?? 'unknown'),
        'job_error' => $detail,
        'job_completed_dtime' => date('c'),
    ]);
    wlog('FAILED (no rows) — ' . ($methodFailures[count($methodFailures)-1] ?? 'unknown'));
    logMonitorEvent('transcript_worker', 'error',
        'Transcription failed for item ' . $itemKey,
        $detail);
    exit(1);
}

bailIfCancelled($db, $jobKey);

// ── Save rows ──
// Single write target now: yy_feed_item_transcript_auto, tagged with the
// job's model. The corresponding _autoclean rows are NO LONGER produced
// here automatically — applying the YadaYah correction pass is an
// explicit step (Initialize Transcript, the rebuild_autoclean.php CLI,
// or any future on-demand cleaner button). We DO still purge any prior
// _autoclean rows for this (item, model) because they were derived from
// the previous _auto data and would otherwise be misleading after a
// fresh transcription run.
//
// The live yy_feed_item_transcript is also never written here — that
// table is reserved for human edits and the Initialize Transcript path.
wphase('save');
wlog('saving ' . count($rows) . ' rows (model=' . $jobModel . ')');
$total = count($rows);
updateJob($db, $jobKey, ['job_progress' => 90, 'job_message' => 'Saving ' . $total . ' segments (model=' . $jobModel . ')...']);
// Independent connection for LIVE save progress: the main $db is inside the
// insert transaction below, so progress writes on it stay invisible to the
// polling UI until commit. Best-effort — a failure here never blocks the save.
$progressUpd = null;
try {
    $ph = getenv('PG_HOST') ?: 'localhost'; $pp = getenv('PG_PORT') ?: '5433';
    $pn = getenv('PG_DB') ?: 'yada'; $pu = getenv('PG_USER') ?: 'postgres';
    $pwd = getenv('PG_PASS') ?: 'yada_password';
    $progressDb = new PDO("pgsql:host=$ph;port=$pp;dbname=$pn", $pu, $pwd, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $progressUpd = $progressDb->prepare("UPDATE yy_feed_item_transcript_job SET job_progress = ?, job_message = ? WHERE feed_item_transcript_job_key = ?");
} catch (Exception $e) { $progressUpd = null; }
$db->beginTransaction();
try {
    $db->prepare("DELETE FROM yy_feed_item_transcript_auto      WHERE feed_item_key = ? AND feed_item_transcript_auto_model      = ?")->execute([$itemKey, $jobModel]);
    $db->prepare("DELETE FROM yy_feed_item_transcript_autoclean WHERE feed_item_key = ? AND feed_item_transcript_autoclean_model = ?")->execute([$itemKey, $jobModel]);
    // Speaker column carries diarisation output when the provider supplies
    // it (Deepgram + AssemblyAI today). Other providers leave it NULL.
    $insAuto = $db->prepare("INSERT INTO yy_feed_item_transcript_auto (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_auto_model, feed_item_transcript_speaker) VALUES (?, ?::interval, ?, ?, ?, ?)");
    $sort = 0;
    foreach ($rows as $r) {
        // Speaker is now a varchar (could be "0" / "A" / "Craig"); keep as
        // string, treating "" the same as missing.
        $speaker = (isset($r['speaker']) && $r['speaker'] !== null && $r['speaker'] !== '')
            ? (string)$r['speaker'] : null;
        $insAuto->execute([$itemKey, $r['segment'], mb_substr($r['text'], 0, 2000), $sort, $jobModel, $speaker]);
        $sort++;
        // Live "K / N segments" tick (every 20 rows + the final row) on the side
        // connection so the admin watches the count climb next to the ETA during
        // long GIN-index saves. Best-effort; never disturbs the insert transaction.
        if ($progressUpd && (($sort % 20) === 0 || $sort === $total)) {
            try {
                $pct = 90 + (int)floor(9 * $sort / max(1, $total));
                $progressUpd->execute([$pct, 'Saving ' . $sort . ' / ' . $total . ' segments (model=' . $jobModel . ')...', $jobKey]);
            } catch (Exception $e) { /* best-effort progress */ }
        }
    }
    // Store per-speaker voice embeddings (whisperx diarize) for speaker
    // recognition. Keyed by (item, model, engine label SPEAKER_xx); replaced
    // wholesale for this model so a re-run refreshes them.
    if (!empty($speakerEmb) && is_array($speakerEmb)) {
        $db->prepare("DELETE FROM yy_feed_item_speaker_embedding WHERE feed_item_key = ? AND model = ?")->execute([$itemKey, $jobModel]);
        $insEmb = $db->prepare("INSERT INTO yy_feed_item_speaker_embedding (feed_item_key, model, label, embedding) VALUES (?, ?, ?, ?::vector) ON CONFLICT (feed_item_key, model, label) DO UPDATE SET embedding = EXCLUDED.embedding, captured_dtime = now()");
        foreach ($speakerEmb as $label => $vec) {
            if (!is_array($vec) || !$vec) continue;
            $insEmb->execute([$itemKey, $jobModel, (string)$label, '[' . implode(',', array_map('floatval', $vec)) . ']']);
        }
    }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    updateJob($db, $jobKey, [
        'job_status' => 'failed',
        'job_message' => 'Save failed',
        'job_error' => $e->getMessage(),
        'job_completed_dtime' => date('c'),
    ]);
    exit(1);
}

// Auto-derive the hybrid join models when this run completed any of their
// source feeds AND those sources now have rows for this item. The word/segment
// feeds may be OpenAI (whisper-1-*) or self-hosted GPU (gpu-whisper-large-v3*)
// — resolveJoinSources() picks whichever exists:
//   whisper-1-word-join      ← word-level whisper + youtube
//   whisper-1-word-join-seg  ← word-level whisper + youtube + segment whisper
// The CLI script build_whisper_word_join.php remains available for manual
// runs; the worker calls the same buildWhisperWordJoin() helper inline.
if (in_array($jobModel, ['whisper-1-word', 'gpu-whisper-large-v3-word', 'youtube', 'whisper-1-segment', 'gpu-whisper-large-v3'], true)) {
    $src = resolveJoinSources($db, $itemKey);
    if ($src['word'] && $src['has_yt']) {
        updateJob($db, $jobKey, ['job_progress' => 95, 'job_message' => 'Deriving whisper-1-word-join…']);
        try {
            $joinedCount = buildWhisperWordJoin($db, $itemKey, 'youtube', false);
            error_log("transcript-worker: built $joinedCount whisper-1-word-join rows for item $itemKey");
        } catch (Throwable $e) {
            error_log("transcript-worker: whisper-1-word-join build failed for item $itemKey: " . $e->getMessage());
        }
        // The 3-feed variant additionally needs a segment-level whisper feed.
        if ($src['seg']) {
            updateJob($db, $jobKey, ['job_progress' => 97, 'job_message' => 'Deriving whisper-1-word-join-seg…']);
            try {
                $segJoinedCount = buildWhisperWordJoin($db, $itemKey, 'youtube', true);
                error_log("transcript-worker: built $segJoinedCount whisper-1-word-join-seg rows for item $itemKey");
            } catch (Throwable $e) {
                error_log("transcript-worker: whisper-1-word-join-seg build failed for item $itemKey: " . $e->getMessage());
            }
        }
    }
}

updateJob($db, $jobKey, [
    'job_status' => 'complete',
    'job_progress' => 100,
    'job_message' => 'Done — ' . count($rows) . ' segments',
    'job_completed_dtime' => date('c'),
]);
wphase('complete');
wlog('DONE — ' . count($rows) . ' segments saved for item ' . $itemKey . ' model ' . $jobModel);

// Auto-build the editable (consensus) transcript once this item's baselines are
// all done — but only when the operator opted the item in, and never over an
// existing transcript. Best-effort: any failure here is logged, never fatal
// (the baseline is already marked complete above).
maybeAutoBuildEditable($db, $itemKey, $jobKey);

// ─────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────

// Auto-enqueue a consensus editable-transcript build for $itemKey when, and
// only when, ALL of these hold:
//   • this was the last baseline still in flight for the item,
//   • the operator opted the item in (yy_feed_item_editable_optin.optin=true),
//   • the item has NO live transcript yet (never clobber human edits),
//   • no editable build is already pending/running/done for the item.
// The consensus uses every baseline model that landed in _auto; the worker
// picks the spine by its preference list. Detached + staggered (baselines run
// one-at-a-time), so no worker stampede. Wrapped so a failure can't break the
// just-completed baseline job.
function maybeAutoBuildEditable(PDO $db, int $itemKey, int $completedJobKey): void {
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript_job
                             WHERE feed_item_key = ? AND job_status IN ('pending','running')
                               AND feed_item_transcript_job_key <> ?");
        $st->execute([$itemKey, $completedJobKey]);
        if ((int)$st->fetchColumn() > 0) return;                 // more baselines still queued

        $st = $db->prepare("SELECT optin::int FROM yy_feed_item_editable_optin WHERE feed_item_key = ?");
        $st->execute([$itemKey]);
        if ((int)$st->fetchColumn() !== 1) return;               // not opted in (absent row → off)

        // Overwrite is allowed only when there's no transcript yet, or it's
        // 'Pending' (consensus-built, not human-edited). 'Editing' is protected.
        $st = $db->prepare("SELECT (SELECT COUNT(*) FROM yy_feed_item_transcript WHERE feed_item_key = ?) AS rown,
                                   (SELECT edit_status FROM yy_feed_item_transcript_status WHERE feed_item_key = ?) AS st");
        $st->execute([$itemKey, $itemKey]);
        $pr = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$pr['rown'] > 0 && (string)($pr['st'] ?? '') !== 'Pending') return;   // protected (Editing) — never overwrite

        $st = $db->prepare("SELECT 1 FROM yy_feed_item_transcript_init_job
                             WHERE job_item_key = ? AND job_status IN ('pending','running') LIMIT 1");
        $st->execute([$itemKey]);
        if ($st->fetchColumn()) return;                          // a build is already pending/running

        $st = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model
                              FROM yy_feed_item_transcript_auto WHERE feed_item_key = ?");
        $st->execute([$itemKey]);
        $baselines = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
        if (!$baselines) return;                                 // nothing to build from

        $params = ['max_chars' => 42, 'max_lines' => 2, 'max_secs' => 7.0, 'min_secs' => 1.2,
                   'break_punct' => true, 'break_gap' => 0, 'dedup' => true];
        $jobParams = json_encode(['baselines' => $baselines, 'primary' => '', 'params' => $params, 'wait_for' => []]);
        $ins = $db->prepare("INSERT INTO yy_feed_item_transcript_init_job (job_item_key, job_model, job_user_key, job_params)
                              VALUES (?, 'consensus', NULL, ?) RETURNING job_key");
        $ins->execute([$itemKey, $jobParams]);
        $initKey = (int)$ins->fetchColumn();
        spawnNextInitWorker($db);   // single-worker editable-build queue (one at a time, in order)
        wlog('auto-build editable: enqueued init job ' . $initKey . ' for item ' . $itemKey
             . ' (baselines: ' . implode(',', $baselines) . ')');
    } catch (\Throwable $e) {
        wlog('auto-build editable: skipped item ' . $itemKey . ' (' . $e->getMessage() . ')');
    }
}

function parseVtt(string $content): array {
    $rows = [];
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $i = 0;
    while ($i < count($lines)) {
        $line = trim($lines[$i]);
        if (preg_match('/^(\d+:\d+:\d+\.\d+|\d+:\d+\.\d+)\s+-->/', $line, $m)) {
            $startTime = $m[1];
            // Normalize to HH:MM:SS
            if (substr_count($startTime, ':') === 1) $startTime = '00:' . $startTime;
            // Strip milliseconds for display
            $secs = parseTimeToSeconds($startTime);
            $segment = secsToInterval($secs);

            // Collect text lines until blank
            $text = [];
            $i++;
            while ($i < count($lines) && trim($lines[$i]) !== '') {
                $t = trim($lines[$i]);
                $t = preg_replace('/<[^>]*>/', '', $t); // strip VTT tags
                $t = html_entity_decode($t, ENT_QUOTES);
                if ($t !== '') $text[] = $t;
                $i++;
            }
            $textStr = implode(' ', $text);
            // Dedupe consecutive identical segments (YouTube auto-captions repeat)
            if ($textStr && (!end($rows) || $rows[count($rows)-1]['text'] !== $textStr)) {
                $rows[] = ['segment' => $segment, 'text' => $textStr];
            }
        }
        $i++;
    }
    return $rows;
}

function parseSrt(string $content): array {
    $rows = [];
    $blocks = preg_split('/\r?\n\r?\n/', $content);
    foreach ($blocks as $block) {
        $lines = preg_split('/\r?\n/', trim($block));
        if (count($lines) < 2) continue;
        // Find timestamp line
        foreach ($lines as $idx => $l) {
            if (preg_match('/^(\d+:\d+:\d+),\d+\s+-->/', $l, $m)) {
                $segment = $m[1];
                $text = implode(' ', array_slice($lines, $idx + 1));
                $text = preg_replace('/<[^>]*>/', '', $text);
                $text = html_entity_decode($text, ENT_QUOTES);
                if (trim($text) !== '') $rows[] = ['segment' => $segment, 'text' => trim($text)];
                break;
            }
        }
    }
    return $rows;
}

function parseTimeToSeconds(string $t): int {
    $parts = explode(':', $t);
    if (count($parts) === 3) return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)round((float)$parts[2]);
    if (count($parts) === 2) return (int)$parts[0] * 60 + (int)round((float)$parts[1]);
    return (int)round((float)$t);
}

function secsToInterval(int $secs): string {
    return sprintf('%02d:%02d:%02d', (int)($secs/3600), (int)(($secs%3600)/60), $secs%60);
}

// Sub-second-precision variant for word-level rows where multiple words can
// share the same whole-second start. Postgres INTERVAL accepts fractional
// seconds with milliseconds.
function secsToIntervalFrac(float $secs): string {
    $whole = (int)$secs;
    $h = (int)($whole / 3600);
    $m = (int)(($whole % 3600) / 60);
    $s = $whole % 60;
    $ms = (int)round(($secs - $whole) * 1000);
    if ($ms >= 1000) { $ms = 0; $s++; }
    return sprintf('%02d:%02d:%02d.%03d', $h, $m, $s, $ms);
}

// Map internal model code → { openai_model, granularity }. Centralized so
// the chunked wrapper, the per-chunk caller, and any future probe code all
// agree on the rules.
function mapModelToOpenai(string $internal): array {
    switch ($internal) {
        case 'whisper-1-segment':
            return ['openai_model' => 'whisper-1', 'granularity' => 'segment'];
        case 'whisper-1-word':
            return ['openai_model' => 'whisper-1', 'granularity' => 'word'];
        case 'gpt-4o-mini-transcribe':
        case 'gpt-4o-transcribe':
            return ['openai_model' => $internal, 'granularity' => null];
        default:
            // Unknown — assume the caller wrote in the actual OpenAI model
            // name and try a plain json call (no segment metadata).
            return ['openai_model' => $internal, 'granularity' => null];
    }
}

function whisperApiTranscribe(string $audioPath, string $apiKey, string $prompt = '', int $offsetSecs = 0, ?string &$err = null, string $model = 'whisper-1-segment'): array {
    // Map our internal code to (openai_model, granularity).
    //   segment → verbose_json with segment-level timestamps (whisper-1 only)
    //   word    → verbose_json with word-level timestamps (whisper-1 only)
    //   null    → response_format=json, one row per chunk at offsetSecs
    //             (gpt-4o-* models and any unknown model)
    $map = mapModelToOpenai($model);
    $openaiModel = $map['openai_model'];
    $granularity = $map['granularity'];

    $fields = [
        'file' => new CURLFile($audioPath),
        'model' => $openaiModel,
        'response_format' => $granularity !== null ? 'verbose_json' : 'json',
    ];
    if ($granularity !== null) {
        $fields['timestamp_granularities[]'] = $granularity;
    }
    if ($prompt !== '') $fields['prompt'] = $prompt;

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 600,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($code !== 200) {
        $err = "HTTP $code" . ($curlErr ? " ($curlErr)" : '') . ': ' . substr($resp ?? '', 0, 500);
        error_log("whisper API error $code: " . substr($resp ?? '', 0, 500));
        return [];
    }
    $data = json_decode($resp, true);
    $rows = [];
    if ($granularity === 'segment') {
        foreach ($data['segments'] ?? [] as $seg) {
            $text = trim($seg['text'] ?? '');
            // Drop empty-text rows. Whisper occasionally returns whitespace
            // entries for silent / non-speech audio chunks; storing those as
            // "captions" pollutes search and falsely marks the job successful.
            if ($text === '') continue;
            $rows[] = ['segment' => secsToInterval((int)$seg['start'] + $offsetSecs), 'text' => $text];
        }
    } elseif ($granularity === 'word') {
        foreach ($data['words'] ?? [] as $w) {
            $text = trim($w['word'] ?? '');
            if ($text === '') continue;
            // Sub-second precision is essential here — many words land in
            // the same whole second.
            $rows[] = ['segment' => secsToIntervalFrac(((float)$w['start']) + (float)$offsetSecs), 'text' => $text];
        }
    }
    if (!$rows && isset($data['text'])) {
        // No-segments path (gpt-4o-* models, or whisper-1 returning only
        // a top-level "text" field). One row at the chunk's offset.
        $fallback = trim($data['text']);
        if ($fallback !== '') {
            $rows[] = ['segment' => secsToInterval($offsetSecs), 'text' => $fallback];
        }
    }
    if (!$rows) {
        $err = 'API returned no speech (silent / non-speech audio?)';
    }
    return $rows;
}

/**
 * Split audio into ~10-minute chunks via ffmpeg, transcribe each, stitch results
 * with timestamp offsets so segments line up to the original timeline.
 */
function whisperApiTranscribeChunked(PDO $db, int $jobKey, string $audioPath, string $apiKey, string $prompt, ?string &$err = null, string $model = 'whisper-1-segment'): array {
    $ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
    if (!$ffmpeg) {
        $err = 'ffmpeg not available — cannot chunk audio over 25MB';
        return [];
    }
    $chunkDir = sys_get_temp_dir() . "/transcript_chunks_$jobKey";
    @mkdir($chunkDir, 0700, true);
    $chunkSecs = 600; // 10 min
    $cmd = escapeshellcmd($ffmpeg) . ' -i ' . escapeshellarg($audioPath)
         . ' -f segment -segment_time ' . $chunkSecs
         . ' -c copy ' . escapeshellarg("$chunkDir/chunk_%03d.mp3") . ' 2>&1';
    shell_exec($cmd);

    $chunks = glob("$chunkDir/chunk_*.mp3");
    sort($chunks);
    if (!$chunks) {
        $err = 'ffmpeg produced no chunks';
        @rmdir($chunkDir);
        return [];
    }
    // Per-chunk ETA estimates: ~8s of API time per MB of audio, floor 10s.
    // Pre-compute the full array so the in-loop message can report the
    // remaining total (current + upcoming chunks), not just this chunk.
    $chunkEtas = array_map(function ($p) {
        $sz = file_exists($p) ? filesize($p) / 1024 / 1024 : 5;
        return max(10, (int)ceil($sz * 8));
    }, $chunks);
    $totalEta = array_sum($chunkEtas);
    $fmtEta = function (int $secs): string {
        if ($secs < 60) return $secs . 's';
        $m = intdiv($secs, 60); $s = $secs % 60;
        if ($m < 60) return $s ? ($m . 'm ' . $s . 's') : ($m . 'm');
        $h = intdiv($m, 60); $m = $m % 60;
        return $m ? ($h . 'h ' . $m . 'm') : ($h . 'h');
    };
    $allRows = [];
    $chunkFailures = [];
    foreach ($chunks as $idx => $chunkPath) {
        bailIfCancelled($db, $jobKey, [$chunkDir]);
        // Remaining ETA = sum of this chunk's estimate + every chunk after
        // it. As chunks finish, the number shrinks naturally.
        $remainingEta = array_sum(array_slice($chunkEtas, $idx));
        updateJob($db, $jobKey, [
            'job_progress' => 40 + (int)(40 * $idx / max(1, count($chunks))),
            'job_message' => 'Transcribing chunk ' . ($idx + 1) . '/' . count($chunks)
                           . '… (ETA ~' . $fmtEta($remainingEta) . ' remaining, ~' . $fmtEta($totalEta) . ' total)',
        ]);
        $chunkErr = '';
        $rows = whisperApiTranscribe($chunkPath, $apiKey, $prompt, $idx * $chunkSecs, $chunkErr, $model);
        if (!$rows && $chunkErr) {
            $chunkFailures[] = "chunk " . ($idx + 1) . ": $chunkErr";
            // Bail immediately on permanent API errors — retrying remaining chunks
            // will produce the same error (quota exhausted, invalid key, etc.).
            if (strpos($chunkErr, 'insufficient_quota') !== false
                || strpos($chunkErr, 'invalid_api_key') !== false
                || strpos($chunkErr, 'account_deactivated') !== false) {
                @unlink($chunkPath);
                foreach (array_slice($chunks, $idx + 1) as $remaining) @unlink($remaining);
                break;
            }
            // continue rather than abort — partial transcript better than none
        }
        $allRows = array_merge($allRows, $rows);
        @unlink($chunkPath);
    }
    @rmdir($chunkDir);
    if (!$allRows) {
        $err = $chunkFailures
            ? 'all chunks empty: ' . implode('; ', $chunkFailures)
            : 'Whisper returned no speech segments across all chunks';
    } elseif ($chunkFailures) {
        // Partial success — surface failures so caller / methodFailures has context.
        $err = 'partial: ' . implode('; ', $chunkFailures);
    }
    return $allRows;
}

/**
 * Build the Whisper `prompt` parameter from yy_transcript_glossary.
 * Whisper uses this as a hint for unusual vocabulary/spellings; the model only
 * looks at the last ~224 tokens (~890 chars), so prioritize by glossary_priority
 * then alphabetical and clip to fit.
 */
function buildGlossaryPrompt(PDO $db): string {
    $stmt = $db->query("SELECT glossary_term FROM yy_transcript_glossary WHERE glossary_active_flag = TRUE ORDER BY glossary_priority DESC, glossary_term");
    $terms = array_column($stmt->fetchAll(), 'glossary_term');
    if (!$terms) return '';
    // ~4 chars per token; budget ~860 chars to stay safely under 224 tokens.
    $prompt = '';
    foreach ($terms as $t) {
        $next = $prompt === '' ? $t : "$prompt, $t";
        if (strlen($next) > 860) break;
        $prompt = $next;
    }
    return $prompt;
}

/**
 * Run each transcribed segment through the active correction dictionary.
 */
function applyCorrectionsToRows(PDO $db, array $rows): array {
    $stmt = $db->query("SELECT correction_wrong, correction_right, correction_case_sensitive, correction_word_boundary FROM yy_transcript_correction WHERE correction_active_flag = TRUE ORDER BY correction_count DESC, length(correction_wrong) DESC");
    $corrections = $stmt->fetchAll();
    if (!$corrections) return $rows;
    foreach ($rows as &$r) {
        foreach ($corrections as $c) {
            $flags = $c['correction_case_sensitive'] ? 'u' : 'iu';
            $pattern = $c['correction_word_boundary']
                ? '/\b' . preg_quote($c['correction_wrong'], '/') . '\b/' . $flags
                : '/' . preg_quote($c['correction_wrong'], '/') . '/' . $flags;
            $r['text'] = preg_replace($pattern, $c['correction_right'], $r['text']);
        }
    }
    unset($r);
    return $rows;
}
