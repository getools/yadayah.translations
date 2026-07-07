<?php
/**
 * Server-Sent-Events push for TTS "Sync / QA".
 *
 *   GET admin-tts-qa-sse.php?job_key=N   (EventSource)
 *
 * Opened right after admin-tts-qa.php returns a job_key. Holds a dedicated PG
 * connection that LISTENs 'tts_qa_<job_key>' and blocks on pgsqlGetNotify; the
 * detached worker NOTIFYs on completion. We re-read the job row and push a
 * 'done' / 'failed' event (mirrors admin-transcript-init-sse.php). Each
 * connection lives ~25s, then EventSource reconnects and re-checks status, so a
 * NOTIFY missed during the gap is never lost.
 */
require_once __DIR__ . '/config.php';

$user   = requireAuth();                  // 401 (JSON) if not an admin session
$jobKey = (int)($_GET['job_key'] ?? 0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) { @ob_end_flush(); }
@set_time_limit(40);

$send = function (string $event, array $data = []) {
    echo "event: $event\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush(); @flush();
};

echo "retry: 2000\n\n";
if (!$jobKey) { $send('failed', ['error' => 'job_key required']); exit; }

$host   = getenv('PG_HOST') ?: 'localhost';
$port   = getenv('PG_PORT') ?: '5433';
$name   = getenv('PG_DB')   ?: 'yada';
$dbUser = getenv('PG_USER') ?: 'postgres';
$dbPass = getenv('PG_PASS') ?: 'yada_password';
try {
    $db = new PDO("pgsql:host=$host;port=$port;dbname=$name", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (\Throwable $e) {
    $send('failed', ['error' => 'db connect failed']);
    exit;
}

$jstmt = $db->prepare("SELECT job_status, job_progress, job_message, job_error FROM yy_tts_qa_job WHERE job_key = ?");
$readJob = function () use ($jstmt, $jobKey) { $jstmt->execute([$jobKey]); return $jstmt->fetch(); };
$emitTerminal = function ($job) use ($send) {
    if ($job['job_status'] === 'done') $send('done', ['job_status' => 'done']);
    else                               $send('failed', ['error' => $job['job_error'] ?: 'Analysis failed', 'job_status' => $job['job_status']]);
};

$job = $readJob();
if (!$job) { $send('failed', ['error' => 'job not found']); exit; }
if (in_array($job['job_status'], ['done', 'error', 'cancelled'], true)) { $emitTerminal($job); exit; }

$db->exec('LISTEN ' . 'tts_qa_' . $jobKey);
$send('working', ['status' => $job['job_status'], 'progress' => (int)$job['job_progress'], 'message' => $job['job_message']]);

$db->pgsqlGetNotify(PDO::FETCH_ASSOC, 25000);
if (connection_aborted()) { exit; }

$job = $readJob();
if ($job && in_array($job['job_status'], ['done', 'error', 'cancelled'], true)) {
    $emitTerminal($job);
} else {
    // Still running — emit a progress ping and close; the browser reconnects.
    $send('ping', ['progress' => (int)($job['job_progress'] ?? 0), 'message' => $job['job_message'] ?? '']);
}
exit;
