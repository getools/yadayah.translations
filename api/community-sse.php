<?php
/**
 * Temporary stub. The real community-sse.php uses native pg_connect() to
 * hold a LISTEN connection via pg_socket() — but the pgsql PHP extension
 * is currently missing from the container, so every request was crashing
 * with "Call to undefined function pg_connect()" and spamming the log.
 *
 * This stub closes the SSE stream cleanly with a single "no-op" event so
 * the browser doesn't immediately reconnect at 1/sec. Once the extension
 * is installed (or community-sse is refactored to use PDO), revert this
 * file from the backup at community-sse.php.bak.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '0');
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');
header('Connection: close');
// Tell the client to wait 30 seconds before reconnecting (browsers
// honour the retry: hint from SSE).
echo "retry: 30000\n\n";
echo "event: noop\ndata: sse-disabled-until-pgsql-installed\n\n";
exit(0);
