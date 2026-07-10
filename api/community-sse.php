<?php
/**
 * Server-Sent Events endpoint for real-time community updates.
 *
 * PDO LISTEN/NOTIFY edition (2026-07-10). Restores the real-time push that
 * the earlier native-pg_connect version provided — but through PDO's
 * pgsqlGetNotify(), because the bare pgsql extension (pg_connect/pg_socket)
 * is absent from this container. Same pattern as admin-tts-sse.php and
 * admin-transcript-init-sse.php, which already run on pdo_pgsql. This
 * replaces the temporary stub that closed every stream with a no-op (which
 * left the notification/DM badges effectively un-live and churned a
 * reconnect every ~5s).
 *
 * DB triggers (sql/community-sse-listen-notify.sql, live in prod) fire:
 *   - dm_new     {message_key, thread_key, user_key, message_dtime, participants}
 *   - dm_read    {user_key, thread_key}
 *   - notif_new  {user_key, notification_key}
 *   - notif_read {user_key, notification_key}
 *
 * Client (public/js/community-notifications.js) consumes:
 *   - event: counts     {unread_notifications, unread_dm}
 *   - event: dm         {full message row}
 *   - event: reconnect  (browser reopens the stream, re-snapshots counts)
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ── Session: read the community user, then RELEASE the lock immediately ──
// Holding the session lock for the whole SSE lifetime would block every
// other request from the same user (their other tabs would hang).
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!session_save_path()) session_save_path('/tmp');
    session_start();
}
$userKey = (int)($_SESSION['user_key'] ?? 0);
session_write_close();

// ── SSE headers ──
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');            // nginx/caddy: don't buffer the stream
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_flush(); }
ob_implicit_flush(true);
@set_time_limit(0);

// retry interval the browser uses on auto-reconnect
echo "retry: 3000\n\n";
@flush();

function sse_emit(string $event, $data): void {
    if ($event !== '') echo "event: $event\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

if (!$userKey) {
    sse_emit('error', ['error' => 'Login required']);
    exit;
}

// ── Dedicated PDO for the LISTEN connection (env-based, same as the other
//    SSE endpoints) so we never disturb the request singleton. ──
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
    sse_emit('reconnect', new stdClass());   // browser retries; transient DB blip
    exit;
}

// ── Tunables. Short-ish lifetime so a mod_php worker isn't pinned per open
//    tab; EventSource reconnects in <3s and we re-snapshot counts on every
//    (re)connect, so nothing is missed across the gap. ──
const CONNECTION_LIFETIME_SECS = 60;    // total SSE lifetime before forced reconnect
const LISTEN_BLOCK_MS          = 15000; // pgsqlGetNotify block window
const PING_EVERY_SECS          = 20;    // heartbeat cadence when idle

$fetchCounts = function (PDO $db, int $userKey): array {
    $n = $db->prepare("SELECT COUNT(*) FROM yy_community_notification WHERE user_key = ? AND read_flag = FALSE");
    $n->execute([$userKey]);
    $notif = (int)$n->fetchColumn();
    $d = $db->prepare("
        SELECT COUNT(*)
          FROM yy_community_dm_message m
          JOIN yy_community_dm_participant p
            ON p.thread_key = m.thread_key AND p.user_key = ?
         WHERE m.user_key <> ?
           AND m.message_active_flag = TRUE
           AND m.message_dtime > COALESCE(p.last_read_dtime, '1970-01-01'::timestamp)
    ");
    $d->execute([$userKey, $userKey]);
    $dm = (int)$d->fetchColumn();
    return ['unread_notifications' => $notif, 'unread_dm' => $dm];
};

$dmDetail = $db->prepare("
    SELECT m.thread_key, m.message_key, m.user_key, m.message_body,
           m.message_body_html, m.message_dtime,
           u.user_name_display, u.user_avatar
      FROM yy_community_dm_message m
      LEFT JOIN yy_user u ON m.user_key = u.user_key
     WHERE m.message_key = ?
");

$db->exec("LISTEN dm_new");
$db->exec("LISTEN dm_read");
$db->exec("LISTEN notif_new");
$db->exec("LISTEN notif_read");

// Snapshot on connect (covers anything that landed during the reconnect gap).
$prevCounts = ['unread_notifications' => -1, 'unread_dm' => -1];
$counts = $fetchCounts($db, $userKey);
sse_emit('counts', $counts);
$prevCounts = $counts;

$startedAt  = time();
$lastPingAt = time();

while (true) {
    if (connection_aborted()) break;
    if ((time() - $startedAt) >= CONNECTION_LIFETIME_SECS) {
        sse_emit('reconnect', new stdClass());
        break;
    }

    // Block up to LISTEN_BLOCK_MS for the next push, then drain any queued
    // behind it (0-timeout) so we never lag a burst of notifies.
    $notify  = $db->pgsqlGetNotify(PDO::FETCH_ASSOC, LISTEN_BLOCK_MS);
    $changed = false;
    while ($notify) {
        $channel = $notify['message'] ?? '';
        $payload = json_decode($notify['payload'] ?? '{}', true);
        if (is_array($payload)) {
            if ($channel === 'dm_new') {
                $participants = $payload['participants'] ?? [];
                if (!is_array($participants)) $participants = [];
                $isParticipant = in_array($userKey, array_map('intval', $participants), true);
                $isFromOther   = (int)($payload['user_key'] ?? 0) !== $userKey;
                if ($isParticipant && $isFromOther) {
                    try {
                        $dmDetail->execute([(int)($payload['message_key'] ?? 0)]);
                        $msg = $dmDetail->fetch();
                        if ($msg) sse_emit('dm', $msg);
                    } catch (\Throwable $e) { /* skip one bad row, keep stream alive */ }
                    $changed = true;
                }
            } elseif ($channel === 'dm_read' || $channel === 'notif_new' || $channel === 'notif_read') {
                if ((int)($payload['user_key'] ?? 0) === $userKey) $changed = true;
            }
        }
        $notify = $db->pgsqlGetNotify(PDO::FETCH_ASSOC, 0);   // non-blocking drain
    }

    if ($changed) {
        $counts = $fetchCounts($db, $userKey);
        if ($counts !== $prevCounts) {
            sse_emit('counts', $counts);
            $prevCounts = $counts;
        }
        $lastPingAt = time();
    } elseif ((time() - $lastPingAt) >= PING_EVERY_SECS) {
        // Heartbeat: keeps proxies awake and lets us notice a dead client
        // (connection_aborted flips on the next write attempt).
        echo ": ping\n\n";
        @flush();
        $lastPingAt = time();
    }
}

$db->exec("UNLISTEN *");
