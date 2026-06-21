<?php
/**
 * Server-Sent-Events push for "Initialize Transcript".
 *
 *   GET admin-transcript-init-sse.php?job_key=N   (EventSource)
 *
 * The browser opens this right after admin-transcript-init.php returns a
 * job_key. We hold a dedicated PG connection that LISTENs on
 * 'transcript_init_<job_key>' and blocks on PDO::pgsqlGetNotify (the pdo_pgsql
 * driver exposes LISTEN/NOTIFY even though the bare pgsql extension — and thus
 * community-sse's pg_connect path — is absent from this container). When the
 * detached worker finishes it NOTIFYs; we re-read the job row and push a
 * 'done' or 'failed' event, then the client closes the stream.
 *
 * Each connection holds at most ~25s (one notify-wait) before returning, so an
 * abandoned modal can't pin a PHP worker; EventSource auto-reconnects and we
 * re-check job_status on every (re)connect, so a completion is never missed
 * even if its NOTIFY landed during the reconnect gap.
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();                       // 401s (as JSON) if not an admin session
$jobKey = (int)($_GET['job_key'] ?? 0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');             // tell any proxy not to buffer the stream
header('Connection: keep-alive');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) { @ob_end_flush(); }
@set_time_limit(40);

$send = function (string $event, array $data = []) {
    echo "event: $event\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush(); @flush();
};

// Reconnect hint for the browser (short, so a missed-NOTIFY gap closes fast).
echo "retry: 2000\n\n";

if (!$jobKey) { $send('failed', ['error' => 'job_key required']); exit; }

// Dedicated connection for LISTEN so we don't disturb the request singleton.
$host = getenv('PG_HOST') ?: 'localhost';
$port = getenv('PG_PORT') ?: '5433';
$name = getenv('PG_DB')   ?: 'yada';
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

$jstmt = $db->prepare("SELECT job_status, job_rows_written, job_error FROM yy_feed_item_transcript_init_job WHERE job_key = ?");

$readJob = function () use ($jstmt, $jobKey) {
    $jstmt->execute([$jobKey]);
    return $jstmt->fetch();
};
$emitTerminal = function ($job) use ($send) {
    if ($job['job_status'] === 'done') {
        $send('done', ['rows_written' => (int)$job['job_rows_written']]);
    } else {
        $send('failed', ['error' => $job['job_error'] ?: 'Initialize failed']);
    }
};

$job = $readJob();
if (!$job) { $send('failed', ['error' => 'job not found']); exit; }
if ($job['job_status'] === 'done' || $job['job_status'] === 'error') { $emitTerminal($job); exit; }

// Still pending/running — wait for the worker's NOTIFY (or fall through after
// ~25s and let EventSource reconnect, re-checking status).
$db->exec('LISTEN ' . 'transcript_init_' . $jobKey);
$send('working', ['status' => $job['job_status']]);

$db->pgsqlGetNotify(PDO::FETCH_ASSOC, 25000);   // block up to 25s for the push
if (connection_aborted()) { exit; }

$job = $readJob();
if ($job && ($job['job_status'] === 'done' || $job['job_status'] === 'error')) {
    $emitTerminal($job);
} else {
    // Not done yet — close cleanly; the browser reconnects and re-checks.
    $send('ping', []);
}
exit;
