<?php
/**
 * Production monitor — parses error logs, checks sync health, attempts known fixes.
 * Logs events to yy_monitor_event for the admin dashboard.
 *
 * Usage (crontab): every 15 min via: docker exec yada-www-web-1 php /var/www/html/api/cron-monitor.php
 * Or via web:      GET /api/cron-monitor.php?key=yada2026monitor
 */
if (php_sapi_name() !== 'cli') {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026monitor') {
        require_once __DIR__ . '/config.php';
        requireAuth();
    } else {
        require_once __DIR__ . '/config.php';
    }
} else {
    // CLI — skip session/headers
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
    function getDbCli(): PDO {
        $host = getenv('PG_HOST') ?: 'localhost';
        $port = getenv('PG_PORT') ?: '5432';
        $name = getenv('PG_DB')   ?: 'yada';
        $user = getenv('PG_USER') ?: 'postgres';
        $pass = getenv('PG_PASS') ?: '';
        return new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}

$db = function_exists('getDb') ? getDb() : getDbCli();
$now = date('Y-m-d H:i:s');
$results = [];

// ── 1. Parse PHP error log ──────────────────────────────────────
// The host-side wrapper writes recent docker logs to /tmp/php_errors.txt
$logFile = '/tmp/php_errors.txt';
$phpErrors = [];

if (file_exists($logFile) && is_readable($logFile) && filesize($logFile) > 0) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) $lines = [];

    $errorCounts = []; // group identical errors
    foreach ($lines as $line) {
        if (strpos($line, 'PHP Fatal error') !== false || strpos($line, 'PHP Warning') !== false || strpos($line, 'PHP Parse error') !== false) {
            // Extract the core message (before stack trace)
            $msg = $line;
            if (preg_match('/PHP (?:Fatal error|Warning|Parse error):\s*(.*?)(?:\s+in \/|$)/s', $line, $em)) {
                $msg = trim($em[1]);
            }

            // Extract file/line from " in /path/to/file.php on line N" or "thrown in /path..."
            $file = null;
            if (preg_match('/(?:in|thrown in)\s+(\/\S+?):(\d+)/s', $line, $fm)) {
                $file = str_replace('/var/www/html/', '', $fm[1]) . ':' . $fm[2];
            }

            // Extract client IP from "[client IP:port]"
            $clientIp = null;
            if (preg_match('/\[client\s+([\d.]+)/', $line, $im)) {
                $clientIp = $im[1];
            }

            // Extract referer from "referer: URL"
            $referer = null;
            if (preg_match('/referer:\s*(\S+)/i', $line, $rm)) {
                $referer = $rm[1];
            }

            // Truncate for grouping
            $key = substr($msg, 0, 200);
            if (!isset($errorCounts[$key])) {
                $errorCounts[$key] = ['count' => 0, 'message' => $msg, 'last_line' => $line, 'severity' => 'error', 'file' => $file, 'client_ip' => $clientIp, 'referer' => $referer];
            } else {
                // Update with latest context
                if ($file) $errorCounts[$key]['file'] = $file;
                if ($clientIp) $errorCounts[$key]['client_ip'] = $clientIp;
                if ($referer) $errorCounts[$key]['referer'] = $referer;
            }
            $errorCounts[$key]['count']++;
        }
    }

    foreach ($errorCounts as $key => $info) {
        $phpErrors[] = $info;
    }
}

// ── 2. Known auto-fixes ────────────────────────────────────────
$fixes = [];

foreach ($phpErrors as &$err) {
    $msg = $err['message'];

    // Fix: missing column in rev table (common after schema changes)
    if (preg_match('/column "([^"]+)" of relation "([^"]+)" does not exist/', $msg, $cm)) {
        $colName = $cm[1];
        $tableName = $cm[2];
        // Check if it's a naming mismatch (user_rev_* vs user_revision_*)
        $altName = null;
        if (strpos($colName, '_rev_') !== false) {
            $altName = str_replace('_rev_', '_revision_', $colName);
        } elseif (strpos($colName, '_revision_') !== false) {
            $altName = str_replace('_revision_', '_rev_', $colName);
        }
        if ($altName) {
            // Check which columns actually exist
            $checkCol = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name IN (?, ?)");
            $checkCol->execute([$tableName, $colName, $altName]);
            $existingCols = $checkCol->fetchAll(PDO::FETCH_COLUMN);

            if (in_array($altName, $existingCols) && !in_array($colName, $existingCols)) {
                // Alt exists, target doesn't — rename column
                try {
                    $db->exec("ALTER TABLE {$tableName} RENAME COLUMN \"{$altName}\" TO \"{$colName}\"");
                    $err['action'] = "Renamed column {$tableName}.{$altName} → {$colName}";
                    $err['resolved'] = true;
                    $fixes[] = $err['action'];
                } catch (Throwable $e) {
                    $err['action'] = "Attempted rename failed: " . $e->getMessage();
                }
            } elseif (in_array($altName, $existingCols) && in_array($colName, $existingCols)) {
                // Both exist — the trigger function references wrong column, fix it
                // Find the trigger function name from the error context
                $trigFunc = null;
                if (preg_match('/function\s+(trg_\w+)\(\)/', $err['last_line'] ?? '', $tf)) {
                    $trigFunc = $tf[1];
                }
                if ($trigFunc) {
                    try {
                        // Regenerate the function with the correct column name
                        $funcDef = $db->query("SELECT pg_get_functiondef(p.oid) FROM pg_proc p WHERE p.proname = '{$trigFunc}'")->fetchColumn();
                        if ($funcDef) {
                            $fixedDef = str_replace($colName, $altName, $funcDef);
                            if ($fixedDef !== $funcDef) {
                                $db->exec($fixedDef);
                                $err['action'] = "Fixed trigger function {$trigFunc}: {$colName} → {$altName}";
                                $err['resolved'] = true;
                                $fixes[] = $err['action'];
                            }
                        }
                    } catch (Throwable $e) {
                        $err['action'] = "Attempted trigger fix failed: " . $e->getMessage();
                    }
                }
            } else {
                // Neither exists — add the column
                try {
                    $db->exec("ALTER TABLE {$tableName} ADD COLUMN \"{$colName}\" TIMESTAMPTZ");
                    $err['action'] = "Added missing column {$tableName}.{$colName}";
                    $err['resolved'] = true;
                    $fixes[] = $err['action'];
                } catch (Throwable $e) {
                    $err['action'] = "Attempted add column failed: " . $e->getMessage();
                }
            }
        }
    }

    // Fix: permission denied / file not found for cache files
    if (preg_match('/Permission denied.*?(\/tmp\/[^\s]+|\/var\/cache[^\s]+)/i', $msg, $pm)) {
        $path = $pm[1];
        if (strpos($path, '/tmp/') === 0 && file_exists($path)) {
            @chmod($path, 0777);
            $err['action'] = "Fixed permissions on {$path}";
            $err['resolved'] = true;
            $fixes[] = $err['action'];
        }
    }

    // Fix: clear temp cache if stale
    if (preg_match('/json_decode.*?expects.*?string/i', $msg) && preg_match('/\/tmp\/[^\s"\']+\.json/i', $msg, $cm)) {
        $cacheFile = $cm[0];
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
            $err['action'] = "Deleted corrupted cache file {$cacheFile}";
            $err['resolved'] = true;
            $fixes[] = $err['action'];
        }
    }
}
unset($err);


foreach ($phpErrors as &$err) {
    if (!empty($err['resolved'])) continue; // already fixed by pattern
    if (!empty($err['ai_attempted'])) continue;
    $err['ai_attempted'] = true;

    // Extract file path and line number from the error
    $filePath = null;
    $lineNum = null;
    if (preg_match('/(?:in|thrown in)\s+(\/\S+?):(\d+)/s', $err['last_line'] ?? '', $fm)) {
        $filePath = $fm[1];
        $lineNum = (int)$fm[2];
    }

    $result = aiAutoFix($err['message'], $err['last_line'] ?? '', $filePath, $lineNum);
    if ($result['fixed']) {
        $err['action'] = 'AI: ' . $result['action'];
        $err['resolved'] = true;
        $fixes[] = $err['action'];
        if (php_sapi_name() === 'cli') echo "  AI fixed: {$result['action']}\n";
    } else {
        $existing = $err['action'] ?? '';
        $err['action'] = $existing . ($existing ? ' | ' : '') . 'AI: ' . $result['action'];
        if (php_sapi_name() === 'cli') echo "  AI could not fix: {$result['action']}\n";
    }
}
unset($err);

// ── 3. Check sync health ───────────────────────────────────────
$syncIssues = [];
try {
    $syncStmt = $db->query("
        SELECT f.feed_site_code, s.schedule_last_run, s.schedule_last_status,
               EXTRACT(EPOCH FROM (NOW() - s.schedule_last_run)) / 3600 AS hours_since
        FROM yy_feed_schedule s
        JOIN yy_feed f USING (feed_key)
        WHERE s.schedule_active_flag = TRUE AND f.feed_active_flag = TRUE
    ");
    foreach ($syncStmt->fetchAll() as $s) {
        $hours = round((float)$s['hours_since'], 1);
        $status = $s['schedule_last_status'] ? json_decode($s['schedule_last_status'], true) : null;
        $rc = $status['rc'] ?? null;

        // Alert if sync hasn't run in 24+ hours
        if ($hours > 24) {
            $syncIssues[] = [
                'message' => "{$s['feed_site_code']} sync hasn't run in {$hours}h",
                'severity' => $hours > 48 ? 'error' : 'warning',
                'detail' => "Last run: " . ($s['schedule_last_run'] ?: 'never'),
            ];
        }

        // Alert if last sync failed
        if ($rc !== null && $rc !== 0) {
            $tail = $status['log_tail'] ?? '';
            $syncIssues[] = [
                'message' => "{$s['feed_site_code']} sync failed (rc={$rc})",
                'severity' => 'error',
                'detail' => substr($tail, -500),
            ];

            // Auto-fix: retry the sync
            $site = strtolower($s['feed_site_code']);
            $script = __DIR__ . '/sync-' . $site . '.php';
            if (file_exists($script)) {
                $retryLog = sys_get_temp_dir() . '/monitor_retry_' . $site . '.log';
                exec("php " . escapeshellarg($script) . " > " . escapeshellarg($retryLog) . " 2>&1", $out, $retryRc);
                $retryOutput = @file_get_contents($retryLog) ?: '';
                if ($retryRc === 0) {
                    $syncIssues[count($syncIssues) - 1]['action'] = "Auto-retried sync — succeeded";
                    $syncIssues[count($syncIssues) - 1]['resolved'] = true;
                    $fixes[] = "Retried {$site} sync successfully";
                } else {
                    $syncIssues[count($syncIssues) - 1]['action'] = "Auto-retried sync — still failing (rc={$retryRc})";
                }
            }
        }
    }
} catch (Throwable $e) {
    $syncIssues[] = ['message' => 'Could not check sync health: ' . $e->getMessage(), 'severity' => 'error'];
}

// ── 4. Check DB health ─────────────────────────────────────────
$dbIssues = [];
try {
    // Check for long-running queries (>60s)
    $longQ = $db->query("
        SELECT pid, state, EXTRACT(EPOCH FROM (NOW() - query_start))::int AS seconds, LEFT(query, 200) AS query
        FROM pg_stat_activity
        WHERE state = 'active' AND query NOT LIKE '%pg_stat_activity%'
          AND application_name != 'pg_dump'
          AND EXTRACT(EPOCH FROM (NOW() - query_start)) > 60
    ")->fetchAll();
    foreach ($longQ as $q) {
        $dbIssues[] = [
            'message' => "Long-running query ({$q['seconds']}s) pid={$q['pid']}",
            'severity' => $q['seconds'] > 300 ? 'error' : 'warning',
            'detail' => $q['query'],
        ];
        // Auto-fix: cancel queries running >300s
        if ($q['seconds'] > 300) {
            try {
                $db->exec("SELECT pg_cancel_backend({$q['pid']})");
                $dbIssues[count($dbIssues) - 1]['action'] = "Cancelled query (pid={$q['pid']})";
                $dbIssues[count($dbIssues) - 1]['resolved'] = true;
                $fixes[] = "Cancelled long query pid={$q['pid']}";
            } catch (Throwable $e) {}
        }
    }

    // Check disk usage (table bloat indicator)
    // Threshold: >50000 dead rows AND >5% of live rows AND autovacuum not run in last 2h.
    // The percentage guard prevents false positives on large tables (e.g. 7M-row tables
    // where autovacuum's own 20% scale-factor threshold hasn't been reached yet).
    $deadRows = $db->query("
        SELECT relname, n_dead_tup FROM pg_stat_user_tables
        WHERE n_dead_tup > 50000
          AND n_live_tup > 0
          AND (n_dead_tup::float / n_live_tup) > 0.05
          AND (last_autovacuum IS NULL OR last_autovacuum < NOW() - interval '2 hours')
        ORDER BY n_dead_tup DESC LIMIT 5
    ")->fetchAll();
    foreach ($deadRows as $t) {
        $dbIssues[] = [
            'message' => "Table {$t['relname']} has {$t['n_dead_tup']} dead rows — consider VACUUM",
            'severity' => 'warning',
        ];
    }
} catch (Throwable $e) {}

// ── 5. Log all events to DB ────────────────────────────────────
$insert = $db->prepare("
    INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_action_taken, event_resolved_flag, event_file, event_referer, event_client_ip)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$totalLogged = 0;

foreach ($phpErrors as $e) {
    $insert->execute([
        'php_error', $e['severity'],
        substr($e['message'], 0, 1000),
        substr($e['last_line'] ?? '', 0, 4000),
        $e['action'] ?? null,
        !empty($e['resolved']) ? 't' : 'f',
        $e['file'] ?? null,
        isset($e['referer']) ? substr($e['referer'], 0, 1000) : null,
        $e['client_ip'] ?? null,
    ]);
    $totalLogged++;
}

foreach ($syncIssues as $e) {
    $insert->execute([
        'sync', $e['severity'] ?? 'warning',
        substr($e['message'], 0, 1000),
        substr($e['detail'] ?? '', 0, 4000),
        $e['action'] ?? null,
        !empty($e['resolved']) ? 't' : 'f',
        null, null, null,
    ]);
    $totalLogged++;
}

foreach ($dbIssues as $e) {
    $insert->execute([
        'db', $e['severity'] ?? 'warning',
        substr($e['message'], 0, 1000),
        substr($e['detail'] ?? '', 0, 4000),
        $e['action'] ?? null,
        !empty($e['resolved']) ? 't' : 'f',
        null, null, null,
    ]);
    $totalLogged++;
}

// ── 6. Auto-resolve known client-side noise ────────────────────
$noisePatterns = [
    ["event_source IN ('network_error') AND event_message ILIKE '%Failed to fetch%'", 'client-side network issue'],
    ["event_source = 'resource_error' AND event_message ILIKE '%failed to load%'", 'client-side resource load failure'],
    ["event_source = 'console_error' AND event_message ILIKE '%Failed to fetch%'", 'client-side fetch failure'],
    ["event_message ILIKE '%standardSelectors%'", 'browser extension'],
    ["event_message ILIKE '%cdn-cgi/rum%'", 'CDN telemetry'],
    ["event_message ILIKE '%HTTP 525%' OR event_message ILIKE '%HTTP 522%' OR event_message ILIKE '%HTTP 524%'", 'Cloudflare transient'],
    ["event_message ILIKE '%ResizeObserver%'", 'browser noise'],
    ["event_message ILIKE '%googletagmanager%' OR event_message ILIKE '%adsbygoogle%'", 'third-party script'],
];
foreach ($noisePatterns as $np) {
    try {
        $db->exec("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = 'Auto-resolved: " . $np[1] . "' WHERE event_resolved_flag = FALSE AND (" . $np[0] . ")");
    } catch (Throwable $e) {}
}

// ── 7. Prune old events (keep 30 days) ─────────────────────────
$db->exec("DELETE FROM yy_monitor_event WHERE event_dtime < NOW() - INTERVAL '30 days'");

// ── Output ─────────────────────────────────────────────────────
$summary = [
    'timestamp' => $now,
    'php_errors' => count($phpErrors),
    'sync_issues' => count($syncIssues),
    'db_issues' => count($dbIssues),
    'fixes_applied' => $fixes,
    'events_logged' => $totalLogged,
];

// Run auto-fix on all unresolved errors
require_once __DIR__ . '/auto-fix-error.php';

if (php_sapi_name() === 'cli') {
    echo "[{$now}] Monitor: " . count($phpErrors) . " PHP errors, " . count($syncIssues) . " sync issues, " . count($dbIssues) . " DB issues";
    if ($fixes) echo " | Fixes: " . implode('; ', $fixes);
    echo "\n";
} else {
    jsonResponse($summary);
}
