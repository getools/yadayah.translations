<?php
/**
 * Admin settings API.
 *
 *   GET  /api/admin-settings.php          — current autofix settings + live status
 *   POST /api/admin-settings.php          — save autofix settings (JSON body)
 *
 * Settings are stored in yy_setting under scope 'autofix'. The host-side
 * auto-fix scripts read these same rows at runtime (with fallbacks), so the
 * cron cadence, resource caps, run timeout, attempt cap, and pause are all
 * driven from here. Auth: admin only.
 */
require_once __DIR__ . '/config.php';
requireAuth();
$db = getDb();

$SCOPE = 'autofix';
$AUTOFIX_DIR = '/var/www/html/jobs/autofix';
$QUEUE_DIR   = '/var/www/html/jobs/claude-fix';

// Whitelist of editable keys with validation. Each entry: [min, max] for
// numerics, or 'bool' for 0/1. Anything not listed here is ignored on save so
// the endpoint can never write an arbitrary setting row.
$FIELDS = [
    'paused'           => 'bool',
    'interval_minutes' => [5, 1440],
    'timeout_seconds'  => [60, 3600],
    'cpu_quota_pct'    => [10, 100],
    'mem_max_mb'       => [256, 8192],
    'max_fix_attempts' => [1, 10],
];

function readSettings(PDO $db, string $scope): array {
    $stmt = $db->prepare("SELECT setting_code, setting_value, setting_label, setting_value_code, setting_sort
                          FROM yy_setting WHERE setting_scope_code = ? ORDER BY setting_sort, setting_code");
    $stmt->execute([$scope]);
    return $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = readSettings($db, $SCOPE);

    // Live status (read-only; surfaced in the UI so the operator sees the real
    // state, not just the configured values).
    $pauseFile = "$AUTOFIX_DIR/PAUSED";
    $pauseFlagAge = is_file($pauseFile) ? (time() - (int)@filemtime($pauseFile)) : null;
    // The dev-session PAUSED flag self-expires after 2h (mirrors the host TTL).
    $devPauseActive = ($pauseFlagAge !== null && $pauseFlagAge >= 0 && $pauseFlagAge < 7200);

    $lastRunFile = "$AUTOFIX_DIR/last_run.txt";
    $lastRun = is_file($lastRunFile) ? (int)trim(@file_get_contents($lastRunFile)) : null;

    $queueDepth = 0;
    if (is_dir($QUEUE_DIR)) {
        $g = glob("$QUEUE_DIR/req_*.json");
        $queueDepth = $g ? count($g) : 0;
    }

    $maxAttempts = 3;
    foreach ($rows as $r) { if ($r['setting_code'] === 'max_fix_attempts') $maxAttempts = (int)$r['setting_value']; }
    $unresolved = null;
    try {
        $st = $db->prepare("SELECT count(*) FROM yy_monitor_event
                            WHERE event_resolved_flag = FALSE
                              AND event_source NOT IN ('agent_op','honeypot')
                              AND event_severity IN ('error','warning')
                              AND COALESCE(event_fix_attempts,0) < ?");
        $st->execute([$maxAttempts]);
        $unresolved = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* column may not exist yet — leave null */ }

    jsonResponse([
        'settings' => $rows,
        'status' => [
            'dev_pause_active'    => $devPauseActive,
            'dev_pause_age_secs'  => $pauseFlagAge,
            'last_run_epoch'      => $lastRun,
            'last_run_ago_secs'   => $lastRun ? (time() - $lastRun) : null,
            'queue_depth'         => $queueDepth,
            'unresolved_pending'  => $unresolved,
            'now_epoch'           => time(),
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) errorResponse('Invalid JSON body');

    $upd = $db->prepare("UPDATE yy_setting SET setting_value = ?
                         WHERE setting_scope_code = ? AND setting_code = ?");
    $saved = [];
    foreach ($FIELDS as $code => $rule) {
        if (!array_key_exists($code, $input)) continue;
        $raw = $input[$code];
        if ($rule === 'bool') {
            $val = (!empty($raw) && $raw !== '0' && $raw !== 0 && $raw !== false) ? '1' : '0';
        } else {
            // Numeric, clamped to [min,max].
            if (!is_numeric($raw)) errorResponse("Invalid value for $code");
            $n = (int)round((float)$raw);
            [$min, $max] = $rule;
            if ($n < $min) $n = $min;
            if ($n > $max) $n = $max;
            $val = (string)$n;
        }
        $upd->execute([$val, $SCOPE, $code]);
        $saved[$code] = $val;
    }
    if (!$saved) errorResponse('No recognized settings in request');
    jsonResponse(['saved' => true, 'values' => $saved]);
}

errorResponse('Method not allowed', 405);
