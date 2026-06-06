<?php
/**
 * Server-Sent Events endpoint for real-time community updates.
 * Streams unread DM count, unread notification count, and new DM events.
 * Client connects via EventSource; PHP polls DB every 3 seconds.
 */

// Need session for user_key but must close it to avoid blocking other requests
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!session_save_path()) session_save_path('/tmp');
    session_start();
}

$userKey = $_SESSION['user_key'] ?? null;
session_write_close(); // Release session lock immediately

if (!$userKey) {
    http_response_code(401);
    echo "data: {\"error\":\"Login required\"}\n\n";
    exit;
}

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Nginx
header('Access-Control-Allow-Origin: *');

// Disable output buffering
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
while (ob_get_level()) ob_end_flush();

// DB connection
$host = getenv('PG_HOST') ?: 'localhost';
$port = getenv('PG_PORT') ?: '5433';
$name = getenv('PG_DB')   ?: 'yada';
$user = getenv('PG_USER') ?: 'postgres';
$pass = getenv('PG_PASS') ?: 'yada_password';
$dsn = "pgsql:host=$host;port=$port;dbname=$name";
$dbOpts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
// Lazy connection: opened at the top of each poll-loop tick, closed before
// the sleep. See the loop body below for the rationale.
$db = null;

function sse_reconnect_db($dsn, $user, $pass, $opts) {
    for ($i = 0; $i < 3; $i++) {
        try { return new PDO($dsn, $user, $pass, $opts); } catch (Exception $e) { sleep(1); }
    }
    return null;
}

// Track previous state to only send changes
$prevNotif = -1;
$prevDm = -1;
$prevLatestDmKey = 0;

// Send initial retry interval (3 seconds)
echo "retry: 3000\n\n";
flush();

$maxRuntime = 300; // 5 minute max connection, client auto-reconnects
$start = time();

while (true) {
    if (connection_aborted()) break;
    if ((time() - $start) > $maxRuntime) {
        echo "event: reconnect\ndata: {}\n\n";
        flush();
        break;
    }

    // Re-open the DB connection per tick so we don't pin a Postgres slot
    // across the 2-second sleep. With many open admin tabs each holding
    // 1 connection for the full 5-minute runtime, the pool would
    // exhaust quickly. Connection cost (~5ms) is dwarfed by the 2 s
    // sleep, so per-tick churn is fine.
    if ($db === null) {
        $db = sse_reconnect_db($dsn, $user, $pass, $dbOpts);
        if (!$db) {
            echo "event: reconnect\ndata: {}\n\n";
            flush();
            break;
        }
    }

    try {
        // Unread notification count
        $stmt = $db->prepare("SELECT COUNT(*) FROM yy_community_notification WHERE user_key = ? AND read_flag = FALSE");
        $stmt->execute([$userKey]);
        $notifCount = (int)$stmt->fetchColumn();

        // Unread DM count
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM yy_community_dm_message m
            JOIN yy_community_dm_participant p ON p.thread_key = m.thread_key AND p.user_key = ?
            WHERE m.user_key != ? AND m.message_active_flag = TRUE
              AND m.message_dtime > COALESCE(p.last_read_dtime, '1970-01-01')
        ");
        $stmt->execute([$userKey, $userKey]);
        $dmCount = (int)$stmt->fetchColumn();

        // Latest DM message key (to detect new messages in open thread)
        $stmt = $db->prepare("
            SELECT COALESCE(MAX(m.message_key), 0)
            FROM yy_community_dm_message m
            JOIN yy_community_dm_participant p ON p.thread_key = m.thread_key AND p.user_key = ?
            WHERE m.user_key != ? AND m.message_active_flag = TRUE
        ");
        $stmt->execute([$userKey, $userKey]);
        $latestDmKey = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        // Lost DB connection — attempt reconnect, otherwise let client retry
        $db = sse_reconnect_db($dsn, $user, $pass, $dbOpts);
        if (!$db) {
            echo "event: reconnect\ndata: {}\n\n";
            flush();
            break;
        }
        sleep(1);
        continue;
    }

    // Send updates only when something changed
    $changed = false;

    if ($notifCount !== $prevNotif || $dmCount !== $prevDm) {
        $payload = json_encode(['unread_notifications' => $notifCount, 'unread_dm' => $dmCount]);
        echo "event: counts\ndata: $payload\n\n";
        $prevNotif = $notifCount;
        $prevDm = $dmCount;
        $changed = true;
    }

    if ($latestDmKey > $prevLatestDmKey) {
        // Find which thread the new message is in
        if ($prevLatestDmKey > 0) {
            $stmt = $db->prepare("
                SELECT m.thread_key, m.message_key, m.user_key, m.message_body, m.message_body_html, m.message_dtime,
                       u.user_name_display, u.user_avatar
                FROM yy_community_dm_message m
                LEFT JOIN yy_user u ON m.user_key = u.user_key
                WHERE m.message_key > ? AND m.user_key != ?
                  AND m.message_active_flag = TRUE
                  AND m.thread_key IN (SELECT thread_key FROM yy_community_dm_participant WHERE user_key = ?)
                ORDER BY m.message_key ASC
            ");
            $stmt->execute([$prevLatestDmKey, $userKey, $userKey]);
            $newMessages = $stmt->fetchAll();
            foreach ($newMessages as $msg) {
                $payload = json_encode($msg);
                echo "event: dm\ndata: $payload\n\n";
            }
            $changed = true;
        }
        $prevLatestDmKey = $latestDmKey;
    }

    if ($changed) {
        flush();
    }

    // Drop the DB handle so Postgres can recycle the connection during the
    // sleep. The next loop iteration re-opens via sse_reconnect_db().
    $db = null;
    sleep(2);
}
