<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$IS_CLI = (php_sapi_name() === 'cli');

if (!$IS_CLI) {
    ini_set('session.gc_maxlifetime', (string)(315360000)); // 10 years
    session_set_cookie_params([
        'lifetime' => 315360000, // 10 years
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!session_save_path()) session_save_path('/tmp');
    session_start();

    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

if (!function_exists('readEnv')) {
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
}

function getDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('PG_HOST') ?: 'localhost';
        $port = getenv('PG_PORT') ?: '5433';
        $name = getenv('PG_DB')   ?: 'yada';
        $user = getenv('PG_USER') ?: 'postgres';
        $pass = getenv('PG_PASS') ?: 'yada_password';
        $dsn = "pgsql:host=$host;port=$port;dbname=$name";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Pass session user_key to PostgreSQL so revision triggers can record who made the change
        $sessionUserKey = $_SESSION['user_key'] ?? 0;
        $pdo->exec("SET app.user_key = " . (int)$sessionUserKey);
        // Kill any query that exceeds 2 minutes — user requests time out at the
        // HTTP layer well before this, so a longer-running query is orphaned.
        $pdo->exec("SET statement_timeout = '120s'");
    }
    return $pdo;
}

function jsonResponse($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function errorResponse(string $message, int $status = 400): void {
    // Surface unexpected server-side failures (5xx) in Monitoring. 4xx
    // errors are normal user-input rejections and would just create noise.
    if ($status >= 500 && function_exists('logMonitorEvent')) {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = !empty($bt[0]['file']) ? basename($bt[0]['file']) . ':' . ($bt[0]['line'] ?? '') : '';
        @logMonitorEvent('admin_error', 'error', $message,
            'Caller: ' . $caller . ' Status: ' . $status,
            false);
    }
    jsonResponse(['error' => $message], $status);
}

/**
 * Is the currently logged-in user banned? Banned users stay fully logged in
 * and tracked — this does NOT touch the session. It only lets the restricted
 * features (Ask Yada, Chat) refuse the action. Result is cached per request;
 * timed bans auto-lift via user_banned_until; fails open on any DB error.
 */
function currentUserBanned(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = false;
    if (empty($_SESSION['user_key'])) return $cached;
    try {
        $st = getDb()->prepare(
            "SELECT 1 FROM yy_user
              WHERE user_key = ?
                AND user_banned_flag = TRUE
                AND (user_banned_until IS NULL OR user_banned_until > NOW())"
        );
        $st->execute([(int)$_SESSION['user_key']]);
        $cached = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Refuse a participatory feature (Ask Yada, Chat) for a banned user. They stay
 * logged in — only this action is blocked. Call in write paths of those
 * features. $feature is woven into the message shown to the user.
 */
function denyIfBanned(string $feature = 'this feature'): void {
    if (currentUserBanned()) {
        jsonResponse([
            'error' => 'Your account is currently suspended from ' . $feature . '.',
            'banned' => true,
        ], 403);
    }
}

function requireAuth(): array {
    if (empty($_SESSION['user_key'])) {
        jsonResponse(['error' => 'Authentication required'], 401);
    }
    $user = [
        'user_key' => $_SESSION['user_key'],
        'user_code' => $_SESSION['user_code'] ?? '',
        'user_name' => $_SESSION['user_name'] ?? $_SESSION['user_code'] ?? '',
    ];
    // Release the session file lock so parallel AJAX requests on the
    // same admin session run concurrently. Without this, every request
    // serialises behind the previous one — polling the chapters list,
    // the lazy paragraph-count fetch, the list_paragraphs expand fetch,
    // and the per-paragraph audio GETs all wait for each other, which
    // is what made the page feel like it was "taking forever to load".
    // None of the admin endpoints write back to $_SESSION after auth,
    // so closing the lock here is safe.
    if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    return $user;
}

function setCurrentUser(PDO $db, int $userKey): void {
    $db->exec("SET app.current_user_key = '" . intval($userKey) . "'");
}

function requireCommunityAuth(): array {
    if (empty($_SESSION['account_key'])) {
        jsonResponse(['error' => 'Sign in required'], 401);
    }
    return [
        'account_key' => $_SESSION['account_key'],
        'account_name' => $_SESSION['account_name'] ?? '',
        'account_avatar' => $_SESSION['account_avatar'] ?? '',
    ];
}

function getCommunitySession(): ?array {
    if (empty($_SESSION['account_key'])) return null;
    return [
        'account_key' => $_SESSION['account_key'],
        'account_name' => $_SESSION['account_name'] ?? '',
        'account_avatar' => $_SESSION['account_avatar'] ?? '',
    ];
}

/**
 * Log an event to yy_monitor_event so the auto-fix loop and admin monitor can see it.
 *
 * - Dedupes against identical (source, severity, message) within the last 5 minutes
 *   so a tight failure loop doesn't flood the table.
 * - Auto-populates event_file (caller), event_referer (current request URI or "cli:script.php"),
 *   and event_client_ip when in a web context.
 * - Tolerates a missing/broken DB by falling back to error_log() — never throws.
 *
 * @param string $source   short tag, e.g. 'transcript_worker', 'sync_youtube', 'cron_email'
 * @param string $severity 'info' | 'warning' | 'error' | 'critical'
 * @param string $message  one-line summary, what failed
 * @param string $detail   stack trace, command output, exception detail
 * @param bool   $resolved mark resolved on insert (use TRUE for informational events)
 */
function logMonitorEvent(string $source, string $severity, string $message, string $detail = '', bool $resolved = false): void {
    try {
        $db = getDb();
        $caller = '';
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        if (!empty($bt[0]['file'])) {
            $caller = basename($bt[0]['file']) . ':' . ($bt[0]['line'] ?? '');
        }
        if (php_sapi_name() === 'cli') {
            $referer = 'cli:' . basename($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? '?'));
            $clientIp = null;
        } else {
            $referer = ($_SERVER['REQUEST_URI'] ?? '') ?: ($_SERVER['HTTP_REFERER'] ?? null);
            $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
            if ($clientIp && strpos($clientIp, ',') !== false) $clientIp = trim(explode(',', $clientIp)[0]);
        }

        // Dedupe: skip if an identical unresolved event was logged in the last 5 minutes
        $dup = $db->prepare("
            SELECT event_key FROM yy_monitor_event
            WHERE event_source = ? AND event_severity = ? AND event_message = ?
              AND event_dtime > NOW() - INTERVAL '5 minutes'
            LIMIT 1
        ");
        $dup->execute([$source, $severity, $message]);
        if ($dup->fetchColumn()) return;

        $db->prepare("
            INSERT INTO yy_monitor_event
                (event_source, event_severity, event_message, event_detail,
                 event_resolved_flag, event_file, event_referer, event_client_ip)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            substr($source, 0, 50),
            substr($severity, 0, 20),
            substr($message, 0, 1000),
            $detail !== '' ? substr($detail, 0, 8000) : null,
            $resolved ? 't' : 'f',
            $caller ? substr($caller, 0, 500) : null,
            $referer ? substr($referer, 0, 1000) : null,
            $clientIp ? substr($clientIp, 0, 45) : null,
        ]);
    } catch (\Throwable $e) {
        // Never let monitoring fail the caller. Drop to error_log as last resort.
        error_log("logMonitorEvent failed: " . $e->getMessage() . " | original: [$source/$severity] $message");
    }
}

// ─────────────────────────────────────────────────────
// Global error / exception capture → yy_monitor_event
//
// Catches:
//  - Uncaught exceptions
//  - Fatal errors via shutdown hook
//  - PHP warnings/notices that are typically silently logged
//
// Skips OPTIONS / 204s and noisy notices we don't want flooding the table.
// ─────────────────────────────────────────────────────
set_exception_handler(function (\Throwable $e) {
    $script = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['SCRIPT_FILENAME'] ?? 'unknown');
    logMonitorEvent(
        'php_exception',
        'error',
        get_class($e) . ': ' . $e->getMessage(),
        $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\nscript: $script"
    );
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
    if (!(error_reporting() & $errno)) return false; // suppressed via @
    // 'Command line code' is PHP's filename for `php -r '...'` inline snippets.
    // These are one-off debugging invocations by operators, not production failures.
    if ($errfile === 'Command line code') return false;
    // Only escalate the loud ones; notices/strict are too noisy
    $severityMap = [
        E_WARNING        => 'warning',
        E_USER_WARNING   => 'warning',
        E_RECOVERABLE_ERROR => 'error',
        E_USER_ERROR    => 'error',
        E_CORE_ERROR    => 'critical',
        E_COMPILE_ERROR => 'critical',
    ];
    if (!isset($severityMap[$errno])) return false; // let PHP handle notices/deprecations
    logMonitorEvent('php_error', $severityMap[$errno], $errstr, "$errfile:$errline");
    return false; // also let PHP's normal error handling run (logs to stderr)
});

register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    if (in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        logMonitorEvent(
            'php_fatal',
            'critical',
            $err['message'],
            ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?')
        );
    }
});
