<?php
/**
 * Server-Sent Events stream for live TTS build progress.
 *
 *   GET /api/admin-tts-sse.php?volume_key=N[&tts_key=M]
 *
 * Replaces the 5-second HTTP poll on the Books tab. Listens on the
 * 'tts_audio' Postgres channel (NOTIFY fired by trg_yy_tts_audio_notify
 * whenever a row in yy_tts_audio changes). Each matching notify is
 * forwarded as a single SSE event; the browser's EventSource subscriber
 * updates the chapter row + open paragraph panel surgically.
 *
 * Events emitted to the client:
 *
 *   event: snapshot    — sent once on connect with the full chapter list
 *                        (same shape as /admin-tts-books.php?action=chapters)
 *                        so the page can initialise without a separate fetch.
 *
 *   event: tts_progress — payload {audio_key, chapter_key, status, progress,
 *                                  message, op}. The frontend re-queries
 *                                  /admin-tts-books.php?action=chapters only
 *                                  when it needs the full row (status flip
 *                                  to complete, etc.) — most updates are
 *                                  handled in-place from this small payload.
 *
 *   event: ping        — sent every ~20s when no notifies arrived, so any
 *                        intermediary proxy doesn't close the idle stream.
 *
 *   event: done        — terminal event when the volume's chapters have all
 *                        settled (no running/pending). Client can disconnect
 *                        and stop the connection until the next manual action.
 *
 * Cost: one DB connection + one PHP-FPM worker held per active SSE client,
 * for as long as the build runs. With the leader-tab guard on the browser
 * side, this is usually exactly ONE connection per concurrent admin user.
 *
 * Lifecycle: we cap each connection at 10 minutes (CONNECTION_LIFETIME);
 * EventSource auto-reconnects, so the user sees an uninterrupted stream
 * but the DB connection is recycled. PHP-FPM has no equivalent of a true
 * long-lived process for this, and a 10-min cap keeps any stuck workers
 * from leaking forever.
 */
require_once __DIR__ . '/config.php';

// Tight lifetime — each SSE connection holds one Apache thread (mod_php).
// Forcing a reconnect every 90s keeps the worker pool from being held
// hostage by a few admin tabs. EventSource auto-reconnects in <1s.
const CONNECTION_LIFETIME_SECS = 90;
const LISTEN_TIMEOUT_MS        = 15000; // pgsqlGetNotify block window
const PING_EVERY_SECS          = 20;    // emit a heartbeat at least this often

$user = requireAuth();
$volumeKey = (int)($_GET['volume_key'] ?? 0);
$ttsKey    = (int)($_GET['tts_key']    ?? 0);
if (!$volumeKey) errorResponse('volume_key required');

// SSE headers. Disable any output buffering/compression so events flush
// the instant they're echoed.
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering',         '0');
@ini_set('implicit_flush',           '1');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');         // nginx/caddy: don't buffer
header('Connection: keep-alive');

/**
 * Emit a single SSE event. event name is optional; without one the
 * client's onmessage fires, with it only the matching addEventListener.
 */
function sse_emit(string $event, $data): void {
    if ($event !== '') echo "event: $event\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

/**
 * Pull the full chapter snapshot for $volumeKey in the same shape as
 * /admin-tts-books.php?action=chapters returns. Sent once on connect
 * so the client doesn't need a separate fetch round-trip.
 */
function sse_load_chapters(PDO $db, int $ttsKey, int $volumeKey): array {
    $vStmt = $db->prepare("
        SELECT v.volume_key, v.volume_code, v.volume_label
          FROM yy_volume v
         WHERE v.volume_key = ?
    ");
    $vStmt->execute([$volumeKey]);
    $volume = $vStmt->fetch();
    if (!$volume) return ['volume' => null, 'chapters' => []];
    $cStmt = $db->prepare("
        SELECT c.chapter_key, c.chapter_number, c.chapter_name AS chapter_label, c.chapter_page,
               a.tts_audio_status, a.tts_audio_progress, a.tts_audio_message,
               a.tts_audio_path, a.tts_audio_duration_secs, a.tts_audio_size_bytes,
               a.tts_audio_completed_dtime, a.tts_audio_started_dtime,
               a.tts_audio_error, a.tts_audio_key, a.tts_audio_failed_paragraphs,
               COALESCE(a.tts_audio_active_flag, TRUE) AS tts_audio_active_flag
          FROM yy_chapter c
          LEFT JOIN yy_tts_audio a
            ON a.chapter_key = c.chapter_key
           AND a.tts_key = ?
         WHERE c.volume_key = ?
         ORDER BY c.chapter_sort, c.chapter_number
    ");
    $cStmt->execute([$ttsKey, $volumeKey]);
    return ['volume' => $volume, 'chapters' => $cStmt->fetchAll()];
}

$db = getDb();
if (!$ttsKey) {
    $ttsKey = (int)$db->query("SELECT tts_key FROM yy_tts WHERE tts_active_flag = TRUE ORDER BY tts_sort, tts_key LIMIT 1")->fetchColumn();
}

// One-shot snapshot. Client renders the chapter list from this WITHOUT
// firing /admin-tts-books.php?action=chapters separately.
$snap = sse_load_chapters($db, $ttsKey, $volumeKey);
sse_emit('snapshot', ['tts_key' => $ttsKey, 'volume_key' => $volumeKey] + $snap);

// Build a chapter_key set so we can filter the channel-wide notifies down
// to just this volume's chapters. Without this filter, every concurrent
// build firing across the whole system would wake every SSE listener.
$volumeChapterKeys = [];
foreach ($snap['chapters'] as $c) $volumeChapterKeys[(int)$c['chapter_key']] = true;

$db->exec("LISTEN tts_audio");

$startedAt   = time();
$lastPingAt  = time();

// Main event loop. PDO::pgsqlGetNotify blocks for up to LISTEN_TIMEOUT_MS
// milliseconds — that's our natural cadence. If a notify arrives it
// returns immediately; otherwise we fall through, send a ping (or check
// for connection-close), and loop.
while (true) {
    if ((time() - $startedAt) >= CONNECTION_LIFETIME_SECS) {
        // 10-min cap. EventSource auto-reconnects with a fresh snapshot.
        sse_emit('reconnect', ['reason' => 'lifetime_cap']);
        break;
    }
    if (connection_aborted()) break;

    $notify = $db->pgsqlGetNotify(PDO::FETCH_ASSOC, LISTEN_TIMEOUT_MS);
    if ($notify) {
        // pgsqlGetNotify can also return a 2nd notify queued behind the
        // first on the same socket; drain them all in this iteration so
        // we never lag behind the worker.
        do {
            $payload = json_decode($notify['payload'] ?? '', true);
            if (is_array($payload)) {
                $chapterKey = (int)($payload['chapter_key'] ?? 0);
                // Filter: only push events for chapters in THIS volume.
                if ($chapterKey && isset($volumeChapterKeys[$chapterKey])) {
                    sse_emit('tts_progress', $payload);
                }
            }
            $notify = $db->pgsqlGetNotify(PDO::FETCH_ASSOC, 0);  // non-blocking drain
        } while ($notify);
        $lastPingAt = time();
    } else if ((time() - $lastPingAt) >= PING_EVERY_SECS) {
        // Proxy/server idle-timeout keepalive. Also lets the client
        // detect a dead connection faster than the default heartbeat.
        sse_emit('ping', ['ts' => time()]);
        $lastPingAt = time();
    }
}

$db->exec("UNLISTEN tts_audio");
