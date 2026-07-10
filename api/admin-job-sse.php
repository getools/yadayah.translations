<?php
/**
 * Generic admin job-status push (SSE).
 *
 *   GET admin-job-sse.php?channel=ai_i2v_job     (EventSource)
 *
 * Relays a whitelisted Postgres NOTIFY channel to the browser so admin job
 * dashboards can replace their list_jobs setInterval polls with a push. The
 * yy_job_notify() triggers (sql/job-notify-triggers.sql) fire
 *   pg_notify(<channel>, {key, status, progress})
 * on INSERT / status-or-progress change of the job row; we forward each as an
 * SSE `job` event. Same PDO pgsqlGetNotify pattern as admin-tts-sse.php /
 * community-sse.php (the pdo_pgsql driver exposes LISTEN/NOTIFY; the bare pgsql
 * extension is absent from this container).
 *
 * Client consumes: event: job {key, status, progress}  → refresh that list.
 *                  event: reconnect                     → reopen the stream.
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();                       // 401s (as JSON) if not an admin session

// Whitelist — never LISTEN on an arbitrary client-supplied channel.
$CHANNELS = ['ai_i2v_job', 'ai_t2i_job', 'feed_transcript_job'];
$channel  = (string)($_GET['channel'] ?? '');
if (!in_array($channel, $CHANNELS, true)) {
    errorResponse('unknown channel', 400);
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_flush(); }
ob_implicit_flush(true);
@set_time_limit(0);

const CONNECTION_LIFETIME_SECS = 90;    // forced reconnect cap (frees the mod_php worker)
const LISTEN_BLOCK_MS          = 15000; // pgsqlGetNotify block window
const PING_EVERY_SECS          = 20;

function sse_emit(string $event, $data): void {
    if ($event !== '') echo "event: $event\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

echo "retry: 2000\n\n";
@flush();

// Dedicated PDO for LISTEN so we don't disturb the request singleton.
$host   = getenv('PG_HOST') ?: 'localhost';
$port   = getenv('PG_PORT') ?: '5433';
$name   = getenv('PG_DB')   ?: 'yada';
$dbUser = getenv('PG_USER') ?: 'postgres';
$dbPass = getenv('PG_PASS') ?: 'yada_password';
try {
    $db = new PDO("pgsql:host=$host;port=$port;dbname=$name", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (\Throwable $e) {
    sse_emit('reconnect', new stdClass());
    exit;
}

// LISTEN on the (validated) channel. Interpolation is safe — $channel is one
// of the whitelist literals above, not user text.
$db->exec('LISTEN ' . $channel);

// Tell the client we're live so it can drop any fallback poll immediately.
sse_emit('ready', ['channel' => $channel]);

$startedAt  = time();
$lastPingAt = time();

while (true) {
    if (connection_aborted()) break;
    if ((time() - $startedAt) >= CONNECTION_LIFETIME_SECS) {
        sse_emit('reconnect', new stdClass());
        break;
    }

    $notify = $db->pgsqlGetNotify(PDO::FETCH_ASSOC, LISTEN_BLOCK_MS);
    $got    = false;
    while ($notify) {
        if (($notify['message'] ?? '') === $channel) {
            $payload = json_decode($notify['payload'] ?? '{}', true);
            sse_emit('job', is_array($payload) ? $payload : []);
            $got = true;
        }
        $notify = $db->pgsqlGetNotify(PDO::FETCH_ASSOC, 0);   // drain burst
    }

    if ($got) {
        $lastPingAt = time();
    } elseif ((time() - $lastPingAt) >= PING_EVERY_SECS) {
        echo ": ping\n\n";
        @flush();
        $lastPingAt = time();
    }
}

$db->exec('UNLISTEN *');
