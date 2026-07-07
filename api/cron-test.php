<?php
/**
 * Runs scheduled site checks/tests and logs results.
 * Submits failures to the error monitoring system for AI auto-fix.
 *
 * Usage: php cron-test.php
 * Or:    GET /api/cron-test.php?key=yada2026test
 * Or:    GET /api/cron-test.php?key=yada2026test&test_key=N  (run one test)
 */
if (php_sapi_name() !== 'cli') {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026test') {
        require_once __DIR__ . '/config.php';
        requireAuth();
    } else {
        require_once __DIR__ . '/config.php';
    }
} else {
    require_once __DIR__ . '/config.php';
}

$db = getDb();
$now = date('Y-m-d H:i:s');
$isCli = php_sapi_name() === 'cli';
$singleKey = (int)($_GET['test_key'] ?? 0);

// Fetch tests to run
if ($singleKey) {
    $stmt = $db->prepare("SELECT * FROM yy_test WHERE test_key = ? AND test_active_flag = TRUE");
    $stmt->execute([$singleKey]);
} else {
    // Find tests that are due based on their schedule
    $stmt = $db->query("
        SELECT * FROM yy_test
        WHERE test_active_flag = TRUE
          AND (
            test_last_run IS NULL
            OR (test_schedule_interval_minutes IS NOT NULL
                AND test_last_run + (test_schedule_interval_minutes || ' minutes')::interval <= NOW())
            OR (test_schedule_time IS NOT NULL
                AND test_last_run::date < CURRENT_DATE
                AND CURRENT_TIME >= test_schedule_time)
          )
        ORDER BY test_sort, test_key
    ");
}
$tests = $stmt->fetchAll();

if ($isCli) echo "[{$now}] Running " . count($tests) . " test(s)...\n";

$results = [];
$logStmt = $db->prepare("
    INSERT INTO yy_test_log (test_key, test_log_status, test_log_message, test_log_detail, test_log_duration_ms, test_log_http_status)
    VALUES (?, ?, ?, ?, ?, ?)
");
$updateStmt = $db->prepare("
    UPDATE yy_test SET test_last_run = NOW(), test_last_status = ?, test_last_message = ? WHERE test_key = ?
");

// Monitor event insert for failures
$monitorStmt = $db->prepare("
    INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_file, event_referer)
    VALUES ('site_test', 'error', ?, ?, ?, ?)
");

foreach ($tests as $test) {
    $key = (int)$test['test_key'];
    $type = $test['test_type'] ?? 'url_check';
    $config = $test['test_config'] ? json_decode($test['test_config'], true) : [];
    $title = $test['test_title'];

    if ($isCli) echo "  [{$key}] {$title} ({$type})... ";

    $startMs = microtime(true);
    $status = 'pass';
    $message = '';
    $detail = '';
    $httpStatus = null;

    try {
        switch ($type) {
            case 'url_check':
                $result = runUrlCheck($config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                $httpStatus = $result['http_status'] ?? null;
                break;

            case 'api_check':
                $result = runApiCheck($config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                $httpStatus = $result['http_status'] ?? null;
                break;

            case 'db_check':
                $result = runDbCheck($db, $config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                break;

            case 'content_check':
                $result = runContentCheck($config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                $httpStatus = $result['http_status'] ?? null;
                break;

            case 'feed_freshness':
                require_once __DIR__ . '/test-feed-freshness.php';
                $result = runFeedFreshnessCheck($db, $config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                break;

            case 'index_integrity':
                $result = runIndexIntegrityCheck($db, $config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                break;

            case 'backup_freshness':
                $result = runBackupFreshnessCheck($db, $config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                break;

            case 'recent_run':
                $result = runRecentRunCheck($db, $config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                break;

            case 'flipbook_assets':
                $result = runFlipbookAssetsCheck($config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                break;

            default:
                $status = 'skip';
                $message = "Unknown test type: {$type}";
        }
    } catch (Throwable $e) {
        $status = 'fail';
        $message = $e->getMessage();
        $detail = $e->getTraceAsString();
    }

    $durationMs = (int)((microtime(true) - $startMs) * 1000);

    // Log result
    $logStmt->execute([$key, $status, substr($message, 0, 2000), substr($detail, 0, 4000), $durationMs, $httpStatus]);
    $updateStmt->execute([$status, substr($message, 0, 2000), $key]);

    // Submit failure to monitoring system
    if ($status === 'fail') {
        $monitorStmt->execute([
            "Test failed: {$title}",
            substr($message . "\n" . $detail, 0, 4000),
            "test:{$key}:{$type}",
            $config['url'] ?? null,
        ]);
    }

    if ($isCli) echo "{$status} ({$durationMs}ms)" . ($status === 'fail' ? " — {$message}" : '') . "\n";

    $results[] = ['test_key' => $key, 'title' => $title, 'status' => $status, 'message' => $message, 'duration_ms' => $durationMs];
}

// Prune old test logs (keep 90 days)
$db->exec("DELETE FROM yy_test_log WHERE test_log_dtime < NOW() - INTERVAL '90 days'");

// If failures were logged, trigger AI auto-fix
if (count(array_filter($results, function($r) { return $r['status'] === 'fail'; })) > 0) {
    if (file_exists(__DIR__ . '/auto-fix-error.php')) {
        include __DIR__ . '/auto-fix-error.php';
    }
}

$summary = [
    'timestamp' => $now,
    'tests_run' => count($results),
    'passed' => count(array_filter($results, function($r) { return $r['status'] === 'pass'; })),
    'failed' => count(array_filter($results, function($r) { return $r['status'] === 'fail'; })),
    'results' => $results,
];

if ($isCli) {
    echo "[{$now}] Done: " . $summary['passed'] . " passed, " . $summary['failed'] . " failed\n";
} else {
    jsonResponse($summary);
}

// ── Test type implementations ──

function runUrlCheck(array $config): array {
    $url = $config['url'] ?? '';
    if (!$url) return ['status' => 'fail', 'message' => 'No URL configured'];

    $expectedStatus = $config['expected_status'] ?? 200;
    $timeout = $config['timeout'] ?? 15;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOBODY => !isset($config['expect_content']),
    ]);
    $body = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['status' => 'fail', 'message' => "Connection failed: {$error}", 'http_status' => 0];
    }
    if ($httpStatus !== $expectedStatus) {
        return ['status' => 'fail', 'message' => "Expected HTTP {$expectedStatus}, got {$httpStatus}", 'http_status' => $httpStatus, 'detail' => substr($body, 0, 500)];
    }
    if (isset($config['expect_content']) && strpos($body, $config['expect_content']) === false) {
        return ['status' => 'fail', 'message' => "Expected content not found: " . substr($config['expect_content'], 0, 100), 'http_status' => $httpStatus, 'detail' => substr($body, 0, 500)];
    }

    return ['status' => 'pass', 'message' => "HTTP {$httpStatus} OK", 'http_status' => $httpStatus];
}

function runApiCheck(array $config): array {
    $url = $config['url'] ?? '';
    if (!$url) return ['status' => 'fail', 'message' => 'No URL configured'];

    $timeout = $config['timeout'] ?? 15;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['status' => 'fail', 'message' => "Connection failed: {$error}", 'http_status' => 0];
    }
    if ($httpStatus >= 400) {
        return ['status' => 'fail', 'message' => "HTTP {$httpStatus}", 'http_status' => $httpStatus, 'detail' => substr($body, 0, 500)];
    }

    $json = json_decode($body, true);
    if ($json === null && $body !== 'null') {
        return ['status' => 'fail', 'message' => 'Invalid JSON response', 'http_status' => $httpStatus, 'detail' => substr($body, 0, 500)];
    }
    if (isset($json['error'])) {
        return ['status' => 'fail', 'message' => "API error: {$json['error']}", 'http_status' => $httpStatus];
    }
    if (isset($config['expect_key']) && !isset($json[$config['expect_key']])) {
        return ['status' => 'fail', 'message' => "Missing expected key: {$config['expect_key']}", 'http_status' => $httpStatus];
    }
    if (isset($config['min_count']) && isset($config['count_key'])) {
        $count = $json[$config['count_key']] ?? (is_array($json[$config['expect_key']] ?? null) ? count($json[$config['expect_key']]) : 0);
        if ($count < $config['min_count']) {
            return ['status' => 'fail', 'message' => "{$config['count_key']} = {$count}, expected >= {$config['min_count']}", 'http_status' => $httpStatus];
        }
    }

    return ['status' => 'pass', 'message' => "HTTP {$httpStatus} OK", 'http_status' => $httpStatus];
}

function runDbCheck(PDO $db, array $config): array {
    $query = $config['query'] ?? '';
    if (!$query) return ['status' => 'fail', 'message' => 'No query configured'];

    // Safety: allow SELECT and read-only CTEs (WITH ... SELECT). No DML.
    if (!preg_match('/^\s*(SELECT|WITH)\s/i', $query)) {
        return ['status' => 'fail', 'message' => 'Only SELECT/WITH queries are allowed'];
    }

    // Cap db_check queries so a slow test can't become a long-running query event.
    $timeoutS = (int)($config['timeout_s'] ?? 30);
    $db->exec("SET LOCAL statement_timeout = '{$timeoutS}s'");

    try {
        $stmt = $db->query($query);
        $rows = $stmt->fetchAll();
    } finally {
        $db->exec("SET LOCAL statement_timeout = DEFAULT");
    }
    $count = count($rows);

    if (isset($config['expect_min']) && $count < $config['expect_min']) {
        return ['status' => 'fail', 'message' => "Got {$count} rows, expected >= {$config['expect_min']}", 'detail' => json_encode($rows[0] ?? null)];
    }
    if (isset($config['expect_max']) && $count > $config['expect_max']) {
        return ['status' => 'fail', 'message' => "Got {$count} rows, expected <= {$config['expect_max']}", 'detail' => json_encode($rows[0] ?? null)];
    }
    if (isset($config['expect_zero']) && $config['expect_zero'] && $count > 0) {
        return ['status' => 'fail', 'message' => "Expected 0 rows, got {$count}", 'detail' => json_encode($rows[0] ?? null)];
    }

    return ['status' => 'pass', 'message' => "{$count} row(s) OK"];
}

function runContentCheck(array $config): array {
    $url = $config['url'] ?? '';
    if (!$url) return ['status' => 'fail', 'message' => 'No URL configured'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $config['timeout'] ?? 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['status' => 'fail', 'message' => "Connection failed: {$error}", 'http_status' => 0];
    if ($httpStatus >= 400) return ['status' => 'fail', 'message' => "HTTP {$httpStatus}", 'http_status' => $httpStatus];

    $checks = $config['checks'] ?? [];
    foreach ($checks as $check) {
        $pattern = $check['contains'] ?? null;
        $notContains = $check['not_contains'] ?? null;
        if ($pattern && strpos($body, $pattern) === false) {
            return ['status' => 'fail', 'message' => "Missing: " . substr($pattern, 0, 100), 'http_status' => $httpStatus];
        }
        if ($notContains && strpos($body, $notContains) !== false) {
            return ['status' => 'fail', 'message' => "Unwanted content found: " . substr($notContains, 0, 100), 'http_status' => $httpStatus];
        }
    }

    return ['status' => 'pass', 'message' => "HTTP {$httpStatus} OK — all checks passed", 'http_status' => $httpStatus];
}

/**
 * Verify every B-tree index in the public schema is internally consistent.
 *
 * Runs amcheck's bt_index_check (light, shared lock only) over every valid
 * btree index. Catches the kind of index/heap divergence we hit on
 * 2026-05-05 where 6 indexes silently allowed duplicate INSERTs and lied
 * to the query planner. Adds ~1-2s per 1000 rows; fine to run daily.
 *
 * Requires: CREATE EXTENSION amcheck (one-time, already done on prod).
 *
 * Config (all optional):
 *   - schema:      schema to scan (default 'public')
 *   - exclude:     array of index name patterns (regex) to skip
 */
function runIndexIntegrityCheck(PDO $db, array $config): array {
    $schema = $config['schema'] ?? 'public';
    $excludePatterns = $config['exclude'] ?? [];

    $stmt = $db->prepare("
        SELECT n.nspname || '.' || c.relname AS qualified_name, c.relname AS short_name
          FROM pg_index i
          JOIN pg_class c ON c.oid = i.indexrelid
          JOIN pg_class t ON t.oid = i.indrelid
          JOIN pg_namespace n ON n.oid = c.relnamespace
          JOIN pg_am am ON am.oid = c.relam
         WHERE am.amname = 'btree'
           AND i.indisvalid
           AND t.relkind = 'r'
           AND n.nspname = ?
         ORDER BY n.nspname, c.relname
    ");
    $stmt->execute([$schema]);
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $checked = 0;
    $skipped = 0;
    $failures = [];

    // Raise timeout for the whole amcheck pass — large indexes (100+ MB) take
    // longer than the 120s global default set in config.php.
    $perIndexTimeout = (int)($config['per_index_timeout_s'] ?? 120);
    $totalTimeout = max(300, count($indexes) * $perIndexTimeout);
    $db->exec("SET statement_timeout = '{$totalTimeout}s'");

    foreach ($indexes as $idx) {
        $skip = false;
        foreach ($excludePatterns as $pat) {
            if (@preg_match("/$pat/", $idx['short_name'])) { $skip = true; break; }
        }
        if ($skip) { $skipped++; continue; }

        try {
            $check = $db->prepare("SELECT bt_index_check(?::regclass)");
            $check->execute([$idx['qualified_name']]);
            $check->fetchAll();
            $checked++;
        } catch (PDOException $e) {
            $failures[] = $idx['qualified_name'] . ': ' . $e->getMessage();
        }
    }

    $db->exec("SET statement_timeout = '120s'");

    if (empty($failures)) {
        return [
            'status' => 'pass',
            'message' => "{$checked} indexes OK" . ($skipped ? " ({$skipped} skipped)" : ''),
        ];
    }

    return [
        'status' => 'fail',
        'message' => count($failures) . " corrupt index(es): "
                   . implode('; ', array_map(fn($f) => preg_replace('/\s+/', ' ', substr($f, 0, 120)), array_slice($failures, 0, 3))),
        'detail' => implode("\n\n", $failures),
    ];
}

/**
 * Verify a recent successful database backup exists.
 *
 * Catches silent failures of the daily 03:00 pg_dump cron. By default checks
 * for any .sql.gz / .dump file modified within the last 30 hours under the
 * configured backup directory.
 *
 * Config:
 *   - dir:        backup directory (default: /backups/yada/ in container,
 *                 mapped from host)
 *   - max_age_h:  max acceptable age in hours (default 30)
 *   - min_bytes:  minimum acceptable backup size (default 1 MB) to catch
 *                 truncated dumps
 *   - patterns:   filename glob (default '*.sql.gz')
 */
function runBackupFreshnessCheck(PDO $db, array $config): array {
    $dir = $config['dir'] ?? '/backups/yada';
    $maxAgeH = (int)($config['max_age_h'] ?? 30);
    $minBytes = (int)($config['min_bytes'] ?? 1048576);
    $pattern = $config['patterns'] ?? '*.sql.gz';

    if (!is_dir($dir)) {
        return ['status' => 'fail', 'message' => "Backup dir not found: {$dir}"];
    }

    $files = glob(rtrim($dir, '/') . '/' . $pattern) ?: [];
    if (!$files) {
        return ['status' => 'fail', 'message' => "No backup files matching '{$pattern}' in {$dir}"];
    }

    // Most recent file by mtime
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $latest = $files[0];
    $ageH = (time() - filemtime($latest)) / 3600;
    $size = filesize($latest);
    $name = basename($latest);

    if ($ageH > $maxAgeH) {
        return [
            'status' => 'fail',
            'message' => "Latest backup {$name} is " . round($ageH, 1) . "h old (max " . $maxAgeH . "h)",
        ];
    }
    if ($size < $minBytes) {
        return [
            'status' => 'fail',
            'message' => "Latest backup {$name} is only " . round($size / 1024 / 1024, 2) . " MB (min " . round($minBytes / 1024 / 1024, 2) . " MB)",
        ];
    }

    return [
        'status' => 'pass',
        'message' => "{$name}: " . round($size / 1024 / 1024, 1) . " MB, " . round($ageH, 1) . "h old",
    ];
}

/**
 * Verify another scheduled job has run recently.
 *
 * Used as a meta-test: confirm cron-page-health, cron-fliphtml5-match etc.
 * are still firing. The signal is either a logfile mtime or a DB row
 * timestamp. Either parameter set works; whichever is present is checked.
 *
 * Config (one of):
 *   - log_file + max_age_h:   stat the log file, fail if older than max_age_h
 *   - sql_query + max_age_h:  run a query that returns one timestamp column,
 *                             fail if older than max_age_h
 */
function runRecentRunCheck(PDO $db, array $config): array {
    $maxAgeH = (float)($config['max_age_h'] ?? 25);

    if (!empty($config['log_file'])) {
        $f = $config['log_file'];
        if (!is_file($f)) {
            return ['status' => 'fail', 'message' => "Log file missing: {$f}"];
        }
        $ageH = (time() - filemtime($f)) / 3600;
        if ($ageH > $maxAgeH) {
            return ['status' => 'fail', 'message' => basename($f) . " last touched " . round($ageH, 1) . "h ago (max {$maxAgeH}h)"];
        }
        return ['status' => 'pass', 'message' => basename($f) . " touched " . round($ageH, 1) . "h ago"];
    }

    if (!empty($config['sql_query'])) {
        $q = $config['sql_query'];
        if (!preg_match('/^\s*SELECT\s/i', $q)) {
            return ['status' => 'fail', 'message' => 'recent_run sql_query must be SELECT'];
        }
        $stmt = $db->query($q);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $ts = $row[0] ?? null;
        if ($ts === null) {
            return ['status' => 'fail', 'message' => 'sql_query returned no rows / NULL timestamp'];
        }
        $ageH = (time() - strtotime($ts)) / 3600;
        if ($ageH > $maxAgeH) {
            return ['status' => 'fail', 'message' => "Last run {$ts} ({$ageH}h ago, max {$maxAgeH}h)"];
        }
        return ['status' => 'pass', 'message' => "Last run " . round($ageH, 1) . "h ago"];
    }

    return ['status' => 'fail', 'message' => 'recent_run needs log_file or sql_query in config'];
}

/**
 * Probe one asset per built flipbook to catch perm/serving regressions —
 * e.g. the 2026-05-11 incident where `pages/` directories were left at mode
 * 700 after a bulk re-render, so every page JPG 403'd while the JPGs sat
 * on disk readable to nobody but root. The asset failures were invisible
 * to client-side error-reporter (silent <img> errors filtered out) and to
 * the Apache error-log monitor (403s go to access.log, not error.log).
 *
 * Config:
 *   asset           Path inside each book dir to probe (default
 *                   "pages/page-001.jpg"). Use a thumb or text JSON instead
 *                   if you want a cheaper check.
 *   webroot         Filesystem root used to enumerate book dirs (default
 *                   "/var/www/html"). Each subdir matching YY-* with an
 *                   index.html is considered a built book.
 *   url_base        Public URL prefix to probe against (default
 *                   "https://yadayah.com"). Trailing slash optional.
 *   timeout         Per-request curl timeout in seconds (default 5).
 *
 * Fails listing every non-200 book + status code, so auto-fix has enough
 * to act on without re-deriving which books are broken.
 */
function runFlipbookAssetsCheck(array $config): array {
    // Probe one asset of EACH bundle type per book — pages/text/thumbs/toc/
    // search. Previously only checked pages/page-001.jpg, which let the
    // 2026-05-12 text/ permissions regression slip through unnoticed because
    // pages/ was already fixed. Each book × asset is a separate HEAD.
    $assets  = isset($config['assets']) && is_array($config['assets'])
             ? $config['assets']
             : [
                 'pages/page-001.jpg',
                 'thumbs/thumb-001.jpg',
                 'text/page-001.json',
                 'toc.json',
                 'search.json',
             ];
    // Back-compat: older configs may still set `asset` (singular).
    if (!empty($config['asset'])) $assets = [$config['asset']];

    $webroot = $config['webroot']  ?? '/var/www/html';
    $urlBase = rtrim($config['url_base'] ?? 'https://yadayah.com', '/');
    $timeout = (int)($config['timeout'] ?? 5);

    $books = [];
    foreach (glob($webroot . '/YY-*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (is_file($dir . '/index.php') || is_file($dir . '/index.html')) {
            $books[] = basename($dir);
        }
    }
    if (!$books) {
        return ['status' => 'fail', 'message' => "No YY-* book dirs with index.php or index.html found under {$webroot}"];
    }

    // Use curl_multi so all book × asset HEAD requests run in parallel.
    $mh = curl_multi_init();
    $handles = [];
    foreach ($books as $b) {
        foreach ($assets as $a) {
            $url = "{$urlBase}/{$b}/{$a}";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = ['ch' => $ch, 'book' => $b, 'asset' => $a];
        }
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.2);
    } while ($running > 0);

    $bad = [];
    foreach ($handles as $h) {
        $code = (int)curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
        $err  = curl_error($h['ch']);
        if ($code !== 200) {
            $bad[] = "{$h['book']}/{$h['asset']}: HTTP {$code}" . ($err ? " ({$err})" : '');
        }
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
    }
    curl_multi_close($mh);

    $totalProbes = count($books) * count($assets);
    if ($bad) {
        return [
            'status'  => 'fail',
            'message' => count($bad) . ' of ' . $totalProbes . ' bundle assets returning non-200',
            'detail'  => implode("\n", $bad),
        ];
    }
    return [
        'status'  => 'pass',
        'message' => "All {$totalProbes} bundle assets (" . count($books) . " books × " . count($assets) . " types) OK",
    ];
}
