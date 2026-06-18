<?php
/**
 * Auto-fix runner — pattern triage + Claude Code agent for unresolved errors.
 *
 * Modes:
 *   php auto-fix-error.php                — process all unresolved (no time limit)
 *   php auto-fix-error.php <RUN_ID>       — same, but write progress JSON to
 *                                           /tmp/autofix_run_<RUN_ID>.json so the
 *                                           admin UI can poll live status.
 *
 * Cron: 15,45 * * * * docker exec yada-www-web-1 php /var/www/html/api/auto-fix-error.php
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(0); // background-safe, no PHP wall-clock limit

$WEB_ROOT = '/var/www/html';
$MAX_TRIAGE = 1000;
// run_id arrives as argv[1] from the admin-monitoring "Auto-Fix" button.
// When auto-fix-error.php runs from cron there's no caller to provide one,
// so we synthesize a stable id ourselves. Without this, claude-fix
// requests dropped into the host queue carried run_id="" and the host
// runner couldn't correlate them — events stayed unresolved silently.
$RUN_ID = $argv[1] ?? '';
if ($RUN_ID === '') {
    $RUN_ID = 'cron_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
}
// Status file lives in a bind-mounted dir so the host-side claude-fix-runner
// can also update it as it processes queued Claude events asynchronously.
$STATUS_DIR = '/var/www/html/jobs/autofix';
@mkdir($STATUS_DIR, 0775, true);
$STATUS_FILE = $RUN_ID ? "$STATUS_DIR/run_{$RUN_ID}.json" : '';
$STARTED_AT = date('c'); // captured ONCE so 'started' is stable across status writes

function writeStatus(string $file, array $data): void {
    if (!$file) return;
    $data['updated'] = date('c');
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// ── Pause flag: skip auto-fix while a developer is editing prod from VS Code. ──
// A SessionStart hook on the dev machine touches this file; we honor it for a
// bounded window so a forgotten resume self-heals instead of killing auto-fix.
$PAUSE_FLAG = "$STATUS_DIR/PAUSED";
$PAUSE_TTL  = 7200; // 2h
if (is_file($PAUSE_FLAG)) {
    $age = time() - (int)@filemtime($PAUSE_FLAG);
    if ($age >= 0 && $age < $PAUSE_TTL) {
        $reason = sprintf('Auto-fix paused by dev flag (age %ds < %ds). Skipping.', $age, $PAUSE_TTL);
        echo "[" . date('c') . "] $reason\n";
        if ($STATUS_FILE) {
            writeStatus($STATUS_FILE, [
                'state' => 'paused',
                'started' => $STARTED_AT, 'finished' => date('c'),
                'total' => 0, 'processed' => 0,
                'log_tail' => $reason,
            ]);
        }
        exit(0);
    }
}

// ── Concurrency lock: refuse to run if another auto-fix is already in progress ──
$LOCK_FILE = '/tmp/autofix.lock';
$lockFp = fopen($LOCK_FILE, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $reason = 'Another auto-fix run is already in progress (lock held). Skipping to prevent duplicates.';
    echo "[" . date('c') . "] $reason\n";
    if ($STATUS_FILE) {
        writeStatus($STATUS_FILE, [
            'state' => 'skipped',
            'started' => $STARTED_AT, 'finished' => date('c'),
            'total' => 0, 'processed' => 0,
            'log_tail' => $reason,
        ]);
    }
    exit(0);
}
// Release lock on shutdown
register_shutdown_function(function () use ($lockFp, $LOCK_FILE) {
    if ($lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
    @unlink($LOCK_FILE);
});

// ── DB ──
$host = getenv('PG_HOST') ?: 'localhost';
$port = getenv('PG_PORT') ?: '5432';
$name = getenv('PG_DB')   ?: 'yada';
$user = getenv('PG_USER') ?: 'postgres';
$pass = getenv('PG_PASS') ?: '';
$db = new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "[" . date('c') . "] Auto-fix starting" . ($RUN_ID ? " (run $RUN_ID)" : '') . "\n";
writeStatus($STATUS_FILE, ['state' => 'starting', 'started' => date('c')]);

// ── Fetch unresolved errors ──
$stmt = $db->prepare("
    SELECT event_key, event_source, event_severity, event_message, event_detail, event_file, event_referer
    FROM yy_monitor_event
    WHERE event_resolved_flag = FALSE
      AND event_source NOT IN ('agent_op', 'honeypot')
      AND event_severity IN ('error', 'warning')
    ORDER BY event_dtime DESC
    LIMIT $MAX_TRIAGE
");
$stmt->execute();
$errors = $stmt->fetchAll();

if (!$errors) {
    echo "[" . date('c') . "] No unresolved errors\n";
    writeStatus($STATUS_FILE, ['state' => 'complete', 'total' => 0, 'processed' => 0, 'started' => date('c'), 'finished' => date('c'), 'log' => 'No unresolved errors']);
    exit(0);
}

echo "[" . date('c') . "] Found " . count($errors) . " unresolved errors\n";
$total = count($errors);
$processed = 0;
$counters = ['known' => 0, 'extension' => 0, 'dedup' => 0, 'code-fix' => 0, 'claude-fix' => 0, 'pending' => 0, 'already-analyzed' => 0];
$logLines = [];
function recordCounter(string $kind, string $line, array &$counters, array &$logLines, string $statusFile, int $processed, int $total, ?string $current = null): void {
    $counters[$kind] = ($counters[$kind] ?? 0) + 1;
    $logLines[] = $line;
    writeStatus($statusFile, [
        'state' => 'running',
        'total' => $total,
        'processed' => $processed,
        'counters' => $counters,
        'current' => $current,
        'log_tail' => implode("\n", array_slice($logLines, -30)),
    ]);
}

// ── Only skip errors that are definitively from browser extensions (not our code) ──
$extensionNoise = [
    'chrome-extension', 'moz-extension', 'safari-extension',
    'window.ethereum', '__firefox__', 'Can\'t find variable: __firefox__',
    'standardSelectors', // browser extension injecting into page
    'Object Not Found Matching Id', // Blazor/SignalR browser extension
    'play method is not allowed by the user agent', // browser autoplay policy
    'AbortError: The play() request was interrupted', // autoplay race condition
];

// ── Known patterns that can be resolved without Claude ──
$knownPatterns = [
    ['match' => "/undefined is not an object.*evaluating '[a-z]\[[a-z]\]'/i", 'condition' => null,
     'resolve' => 'Auto-resolved: Minified third-party library error (likely TinyMCE) on specific browser/device.'],
    ['match' => "/Cannot read propert(y|ies) of (null|undefined).*\(reading '[a-z]{1,2}'\)/i", 'condition' => null,
     'resolve' => 'Auto-resolved: Minified library internal error on specific browser.'],
    ['match' => '/(IMG|SCRIPT|LINK|VIDEO|AUDIO|SOURCE) failed to load/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Resource failed to load — missing asset, empty src, or network issue.'],
    ['match' => '/community-sse/i',
     'condition' => function($e) { return in_array($e['event_source'] ?? '', ['fetch_error', 'network_error', 'xhr_error']); },
     'resolve' => 'Auto-resolved: SSE connection dropped — expected on page navigation.'],
    ['match' => '/client-error\.php/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Error reporter endpoint got blocked (IP ban or session expired). Cascading error.'],
    ['match' => '/HTTP 403/i',
     'condition' => function($e) { return stripos($e['event_detail'] ?? '', 'Forbidden') !== false || stripos($e['event_message'] ?? '', '403') !== false; },
     'resolve' => 'Auto-resolved: HTTP 403 — temporarily banned IP or session issue. Not a code bug.'],
    ['match' => '/HTTP 401/i', 'condition' => null,
     'resolve' => 'Auto-resolved: HTTP 401 — session expired. Expected behavior.'],
    ['match' => '/t\.yadayah\.com/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Error on deprecated domain t.yadayah.com — now redirects to yadayah.com.'],
    ['match' => '/Load failed|Failed to fetch|NetworkError|network error|ERR_CONNECTION/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Transient network error.'],
    ['match' => '/Long task:/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Long task detected — performance note, not a code error.'],
    // Match only specific bot/crawler identifiers as whole words.
    // Plain `bot` would match "you're not a bot" (YouTube error) etc., so we
    // require either a known crawler name or `bot` as a hyphen/case suffix
    // ("Googlebot", "AhrefsBot") to avoid false positives.
    ['match' => '/\b(crawler|spider|YandexBot|Googlebot|bingbot|HeadlessChrome|PhantomJS|Lighthouse|AhrefsBot|SemrushBot|DotBot|MJ12bot)\b/',
     'condition' => function($e) {
         // Require the crawler hint to appear in user-agent or referer fields,
         // not in the message body where unrelated text may match.
         $haystack = ($e['event_referer'] ?? '') . ' ' . ($e['event_file'] ?? '');
         return (bool)preg_match('/\b(crawler|spider|YandexBot|Googlebot|bingbot|HeadlessChrome|PhantomJS|Lighthouse|AhrefsBot|SemrushBot|DotBot|MJ12bot)\b/', $haystack);
     },
     'resolve' => 'Auto-resolved: Error from bot/crawler — not a real user issue.'],
    ['match' => '/loadCategories error.*DOCTYPE/i', 'condition' => null,
     'resolve' => 'Auto-resolved: API returned HTML instead of JSON — caused by 403 IP ban cascade.'],
    ['match' => '/Unexpected token.*<!DOCTYPE/i',
     'condition' => function($e) { return ($e['event_source'] ?? '') === 'console_error'; },
     'resolve' => 'Auto-resolved: API returned HTML (403/404 page) instead of JSON — transient or IP ban cascade.'],
    // Browser extension noise
    ['match' => '/window\.ethereum|MetaMask|__firefox__|__gCrWeb|did not match the expected pattern/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Browser extension or browser-internal API — not our code.'],
    ['match' => '/cdn-cgi|cloudflare/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Cloudflare internal endpoint — not our code.'],
    ['match' => '/cancelable=false|Ignored attempt to (cancel|prevent)/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Browser intervention — not actionable.'],
    // YouTube/iframe internal errors
    ['match' => '/youtube\.com\/embed.*\b(403|404)\b|youtube-nocookie/i', 'condition' => null,
     'resolve' => 'Auto-resolved: YouTube iframe internal error — not our code.'],
    // HTTP 400 on auth endpoints (bad form input from user)
    ['match' => '/HTTP 400 on .*(community-auth|community-profile|auth\.php|oauth)/i', 'condition' => null,
     'resolve' => 'Auto-resolved: HTTP 400 on auth endpoint — invalid form input from client.'],
    // HTTP 413 (payload too large — working as designed)
    ['match' => '/HTTP 413/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Upload too large — server rejected as designed.'],
    // HTTP 429 (rate limit)
    ['match' => '/HTTP 429|rate limit|too many requests/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Rate limit triggered — working as designed.'],
    // Image load failures for missing background videos / placeholder images
    ['match' => '/SOURCE failed to load.*\/u\/backgrounds/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Background video failed to load — fallback image handles this.'],
    // CSP violations from extensions
    ['match' => '/CSP.*blocked.*(chrome-extension|moz-extension|safari-extension)/i', 'condition' => null,
     'resolve' => 'Auto-resolved: CSP violation from browser extension — not our code.'],
    // Promise rejection: empty/null reason
    ['match' => '/^\s*(\[object Object\]|null|undefined)\s*$/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Empty promise rejection — non-actionable.'],
    // Honeypot hits (intentional bot traps)
    ['match' => '/honeypot/i',
     'condition' => function($e) { return ($e['event_source'] ?? '') === 'honeypot'; },
     'resolve' => 'Auto-resolved: Honeypot hit — bot detection working as designed.'],
    // Transcription: video genuinely deleted/private (cannot fix)
    ['match' => '/Transcription failed.*(private video|video unavailable|This video has been removed)/is',
     'condition' => function($e) { return ($e['event_source'] ?? '') === 'transcript_worker'; },
     'resolve' => 'Auto-resolved: YouTube video is private, deleted, or removed — cannot transcribe. Not a code issue.'],
    // Transcription: future live (cannot fix until broadcast)
    ['match' => '/Transcription failed.*(future live event|will begin in a few moments)/is',
     'condition' => function($e) { return ($e['event_source'] ?? '') === 'transcript_worker'; },
     'resolve' => 'Auto-resolved: Video is a scheduled future live stream — cannot transcribe until after broadcast.'],
];

$triageCount = 0;

foreach ($errors as $error) {
    if ($triageCount >= $MAX_TRIAGE) break;
    $processed++;

    $msg = $error['event_message'] ?? '';
    $detail = $error['event_detail'] ?? '';
    $source = $error['event_source'] ?? '';
    $currentLabel = "#{$error['event_key']} [{$source}] " . substr($msg, 0, 80);

    writeStatus($STATUS_FILE, [
        'state' => 'running', 'total' => $total, 'processed' => $processed - 1,
        'counters' => $counters, 'current' => $currentLabel,
        'log_tail' => implode("\n", array_slice($logLines, -30)),
    ]);

    // Skip only confirmed browser extension errors (definitively not our code)
    $isExtension = false;
    foreach ($extensionNoise as $pat) {
        if (stripos($msg, $pat) !== false || stripos($detail, $pat) !== false) {
            $isExtension = true;
            break;
        }
    }
    if ($isExtension) {
        $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = 'Auto-skipped: browser extension — not our code' WHERE event_key = ?")
           ->execute([$error['event_key']]);
        $line = "  [extension] #{$error['event_key']}: " . substr($msg, 0, 80);
        echo $line . "\n";
        recordCounter('extension', $line, $counters, $logLines, $STATUS_FILE, $processed, $total, $currentLabel);
        continue;
    }

    // Server-side operational sources are NEVER auto-dismissed by client-side
    // pattern matching. Their failures (DB errors, transcript jobs, sync jobs,
    // billing, etc.) are real infrastructure issues that must surface to admins
    // as-is. We only triage / claude-fix them; we do NOT silence them with
    // patterns intended for browser noise (e.g. the YouTube "you're not a bot"
    // text would match the bot/crawler rule and disappear silently).
    $operationalSources = [
        'transcript_worker', 'sync_youtube', 'sync_facebook', 'sync_rumble',
        'sync_blog', 'sync_invite', 'sync_music', 'ai_billing',
        'php_exception', 'php_error', 'php_fatal',
        'cron_email', 'youtube_cookies',
    ];
    $isOperational = in_array($source, $operationalSources, true);

    // Check known patterns before calling Claude (browser/client errors only)
    $knownResolved = false;
    if (!$isOperational) {
        $fullText = $msg . ' ' . $detail . ' ' . ($error['event_file'] ?? '') . ' ' . ($error['event_referer'] ?? '') . ' ' . $source;
        foreach ($knownPatterns as $kp) {
            if (preg_match($kp['match'], $fullText)) {
                $condFn = $kp['condition'];
                if ($condFn === null || $condFn($error)) {
                    $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                       ->execute([$kp['resolve'], $error['event_key']]);
                    $line = "  [known] #{$error['event_key']}: " . substr($kp['resolve'], 0, 80);
                    echo $line . "\n";
                    recordCounter('known', $line, $counters, $logLines, $STATUS_FILE, $processed, $total, $currentLabel);
                    $knownResolved = true;
                    break;
                }
            }
        }
    }
    if ($knownResolved) continue;

    // Skip errors already investigated by Claude (have action_taken set)
    if (!empty($error['event_action_taken'])) {
        $line = "  [already-analyzed] #{$error['event_key']}: " . substr($error['event_action_taken'], 0, 60);
        echo $line . "\n";
        recordCounter('already-analyzed', $line, $counters, $logLines, $STATUS_FILE, $processed, $total, $currentLabel);
        continue;
    }

    // Deduplicate: if another error with the same message was already fixed this run, resolve this one too
    $msgHash = md5($msg);
    static $fixedMessages = [];
    if (isset($fixedMessages[$msgHash])) {
        $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
           ->execute(["Same issue as #{$fixedMessages[$msgHash]} — resolved together", $error['event_key']]);
        $line = "  [dedup] #{$error['event_key']} same as #{$fixedMessages[$msgHash]}";
        echo $line . "\n";
        recordCounter('dedup', $line, $counters, $logLines, $STATUS_FILE, $processed, $total, $currentLabel);
        continue;
    }

    // ── Attempt pattern-based code fixes for common PHP errors ──
    $codeFixed = false;

    // Fix: Trigger function references column that doesn't exist on _rev table
    // After ALTER TABLE on a source table, the rev trigger needs rebuilding.
    if (preg_match('/column "(\w+)" of relation "(\w+_rev)" does not exist.*function\s+(trg_\w+_rev)\(\)/s', $msg . ' ' . $detail, $trigMatch)) {
        $missingCol = $trigMatch[1];
        $revTable = $trigMatch[2];
        $trigFunc = $trigMatch[3];
        try {
            // Get rev table columns
            $colStmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position");
            $colStmt->execute([$revTable]);
            $revCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

            // Get source table (strip _rev)
            $srcTable = preg_replace('/_rev$/', '', $revTable);
            $srcColStmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position");
            $srcColStmt->execute([$srcTable]);
            $srcCols = $srcColStmt->fetchAll(PDO::FETCH_COLUMN);

            // If src has columns rev table is missing, add them
            $missingFromRev = array_diff($srcCols, $revCols);
            if ($missingFromRev) {
                foreach ($missingFromRev as $col) {
                    $typeStmt = $db->prepare("SELECT data_type, character_maximum_length FROM information_schema.columns WHERE table_name = ? AND column_name = ?");
                    $typeStmt->execute([$srcTable, $col]);
                    $info = $typeStmt->fetch();
                    if ($info) {
                        $type = $info['data_type'];
                        if ($info['character_maximum_length']) $type .= '(' . $info['character_maximum_length'] . ')';
                        $db->exec("ALTER TABLE {$revTable} ADD COLUMN IF NOT EXISTS \"{$col}\" {$type}");
                    }
                }
                $action = "Added missing columns to {$revTable}: " . implode(', ', $missingFromRev);
                $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                   ->execute([$action, $error['event_key']]);
                echo "  [trigger-fix] #{$error['event_key']}: {$action}\n";
                $fixedMessages[$msgHash] = $error['event_key'];
                $codeFixed = true;
                continue;
            }
        } catch (Throwable $e) {}
    }

    // Fix: yt-dlp "Sign in to confirm you're not a bot" — bypass via alternate player_clients
    // YouTube's anti-bot check fails on `ios` client; rotating through `mweb`, `tv`, `android_vr`
    // typically gets past it. Auto-fix patches transcript-worker.php to try fallback clients.
    if ($source === 'transcript_worker' && stripos($detail, "you're not a bot") !== false) {
        $workerFile = $WEB_ROOT . '/api/transcript-worker.php';
        if (file_exists($workerFile)) {
            $worker = file_get_contents($workerFile);
            // Mark patched so we don't re-apply
            if (strpos($worker, 'YT_DLP_FALLBACK_CLIENTS') === false) {
                $marker = "// YT_DLP_FALLBACK_CLIENTS: rotating clients to bypass bot check\n";
                // Replace the single-client invocations with a rotating loop
                $needle = "--extractor-args 'youtube:player_client=ios'";
                if (strpos($worker, $needle) !== false) {
                    $replacement = "--extractor-args " . '\'youtube:player_client=\' . $YT_CLIENT';
                    $patched = $marker
                        . "\$YT_CLIENT = 'mweb'; // fallback client list: mweb, tv, android_vr, ios\n"
                        . str_replace($needle, "--extractor-args 'youtube:player_client=' . \$YT_CLIENT", $worker);
                    // Backup
                    $backupDir = '/tmp/auto-fix-backups/' . date('Y-m-d');
                    if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
                    @copy($workerFile, $backupDir . '/transcript-worker.php.' . time());
                    file_put_contents($workerFile, $patched);
                    $action = "Patched transcript-worker.php: switched yt-dlp player_client from 'ios' to 'mweb' to bypass YouTube anti-bot check. Re-run the transcription.";
                    $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                       ->execute([$action, $error['event_key']]);
                    echo "  [code-fix] #{$error['event_key']}: $action\n";
                    $fixedMessages[$msgHash] = $error['event_key'];
                    $codeFixed = true;
                    continue;
                }
            }
        }
    }

    // Fix: $_GET/$_POST parameter not validated (HTTP 500 from missing parameter)
    if ($source === 'fetch_error' && preg_match('/HTTP 500 on (\/api\/[^\s?]+)/', $msg, $endpointMatch)) {
        $endpointPath = $endpointMatch[1];
        // Just log this for routine investigation — too risky to auto-fix
        $db->prepare("UPDATE yy_monitor_event SET event_action_taken = ? WHERE event_key = ?")
           ->execute(["Endpoint {$endpointPath} returned 500 — needs investigation", $error['event_key']]);
        // Don't continue — let it fall through to other handlers
    }


    // Fix: "invalid input syntax for type integer" — a string is being passed where int is expected
    // Usually caused by a missing (int) cast on a query parameter
    if (preg_match('/invalid input syntax for type integer.*in (\/\S+?):(\d+)/s', $msg . ' ' . $detail, $sqlMatch)) {
        $fixFile = $sqlMatch[1];
        $fixLine = (int)$sqlMatch[2];
        if (file_exists($fixFile)) {
            $lines = file($fixFile);
            if (isset($lines[$fixLine - 1])) {
                $badLine = $lines[$fixLine - 1];
                // Look at surrounding lines for the execute() call and find which parameter might be wrong
                // Common pattern: a variable that should be cast to (int) isn't
                // Log the context so it can be investigated
                $contextLines = array_slice($lines, max(0, $fixLine - 10), 20);
                $contextStr = implode('', $contextLines);

                // Check if there's a $_GET or $data variable being passed without (int) cast
                if (preg_match('/\$_(GET|POST|REQUEST)\[[\'"](\w+)[\'"]\]/', $contextStr, $varMatch)) {
                    $varName = '$_' . $varMatch[1] . '[\'' . $varMatch[2] . '\']';
                    // Find the line that passes this variable and add (int) cast
                    $changed = false;
                    for ($li = max(0, $fixLine - 10); $li < min(count($lines), $fixLine + 5); $li++) {
                        // Look for the variable being used in an execute array without (int) cast
                        if (strpos($lines[$li], $varMatch[2]) !== false && strpos($lines[$li], '(int)') === false) {
                            $original = $lines[$li];
                            $fixed = preg_replace('/(\$_(?:GET|POST|REQUEST)\[\'' . preg_quote($varMatch[2], '/') . '\'\])/', '(int)$1', $lines[$li]);
                            if ($fixed !== $original) {
                                // Backup
                                $backupDir = '/tmp/auto-fix-backups/' . date('Y-m-d');
                                if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
                                @copy($fixFile, $backupDir . '/' . basename($fixFile) . '.' . time());

                                $lines[$li] = $fixed;
                                file_put_contents($fixFile, implode('', $lines));
                                $explanation = "Added (int) cast to {$varName} near line " . ($li + 1) . " in " . basename($fixFile) . " to fix SQL integer type mismatch";
                                $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                                   ->execute(["Auto-fix (code): $explanation", $error['event_key']]);
                                echo "  [code-fix] #{$error['event_key']}: $explanation\n";
                                $codeFixed = true;
                                $changed = true;
                                break;
                            }
                        }
                    }
                }

                if (!$codeFixed) {
                    // Can't auto-fix but record what we found
                    $contextSnippet = trim(implode('', array_slice($lines, max(0, $fixLine - 3), 7)));
                    $db->prepare("UPDATE yy_monitor_event SET event_action_taken = ? WHERE event_key = ?")
                       ->execute(["Triage: SQL integer type mismatch at " . basename($fixFile) . ":$fixLine. Context:\n$contextSnippet", $error['event_key']]);
                    echo "  [needs-fix] #{$error['event_key']}: SQL type mismatch at " . basename($fixFile) . ":$fixLine\n";
                }
            }
        }
    }

    // Sources that represent external infra/3rd-party issues — Claude can't fix these by editing code.
    // We mark them with an explanatory action_taken instead of burning Claude credits indefinitely.
    $infraSources = ['transcript_worker', 'sync_youtube', 'sync_facebook', 'sync_rumble', 'ai_billing'];

    if (!$codeFixed && in_array($source, $infraSources, true)) {
        // transcript_worker: $knownPatterns above are blocked by $isOperational, so handle
        // well-known permanent failures explicitly here with specific, actionable notes.
        $resolved = false;
        if ($source === 'transcript_worker') {
            $hay = $msg . ' ' . $detail;
            $itemKeyMatch = [];
            preg_match('/item (\d+)/', $msg, $itemKeyMatch);
            $ik = $itemKeyMatch[1] ?? '?';
            if (stripos($hay, 'private video') !== false || stripos($hay, 'video unavailable') !== false || stripos($hay, 'has been removed') !== false) {
                $resolved = true;
                $note = 'Auto-resolved: YouTube video is private, deleted, or unavailable — cannot transcribe. No code fix possible.';
            } elseif (stripos($hay, 'live event will begin') !== false || stripos($hay, 'future live event') !== false || stripos($hay, 'will begin in a few moments') !== false) {
                $resolved = true;
                $note = 'Auto-resolved: Scheduled future live stream — cannot transcribe until after broadcast. Retry manually after the event.';
            } elseif (stripos($hay, 'community post') !== false || stripos($hay, 'only images are available') !== false) {
                $resolved = true;
                $note = 'Auto-resolved: YouTube Community post or image-only item (no audio track) — cannot transcribe. Remove from transcription queue.';
            } elseif (stripos($hay, 'age-restricted') !== false || stripos($hay, 'sign-in or is age-restricted') !== false) {
                $note = "Needs human review: Age-restricted video. Upload YouTube cookies via admin-cookies.php, or manually upload audio/VTT to /tmp/transcript_uploads/{$ik}.vtt";
            } elseif (stripos($hay, "you're not a bot") !== false || stripos($hay, 'bot-detection blocked') !== false || stripos($hay, 'confirm you') !== false) {
                $note = "Needs human review: YouTube bot-detection blocking server IP. Upload fresh cookies via admin-cookies.php, or manually upload audio/VTT to /tmp/transcript_uploads/{$ik}.vtt";
            } else {
                $note = "Needs human review: transcript_worker failure (external/infra). Check transcript job details in admin panel. Source detail: " . substr($detail, 0, 200);
            }
        } else {
            $note = "Infra/external-service issue — not fixable by editing code. Source: $source. Needs manual review.";
        }

        if ($resolved) {
            $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
               ->execute([$note, $error['event_key']]);
            $line = "  [known-transcript] #{$error['event_key']}: " . substr($note, 0, 80);
            echo $line . "\n";
            recordCounter('known', $line, $counters, $logLines, $STATUS_FILE, $processed, $total, $currentLabel);
        } else {
            $db->prepare("UPDATE yy_monitor_event SET event_action_taken = ? WHERE event_key = ? AND event_action_taken IS NULL")
               ->execute([$note, $error['event_key']]);
            $line = "  [skip-claude] #{$error['event_key']} {$source}: " . substr($note, 0, 80);
            echo $line . "\n";
            recordCounter('pending', $line, $counters, $logLines, $STATUS_FILE, $processed, $total, $currentLabel);
        }
        continue;
    }

    if (!$codeFixed) {
        // Pattern fixer can't handle this — invoke Claude Code agent on-server to investigate & fix.
        // Auth lives on the HOST (claude-fix-runner.sh runs as user claudefix
        // who owns /opt/yada-www/claude-home/.claude). The web container only
        // checks that the queue infrastructure is in place.
        $claudeWrapper = $WEB_ROOT . '/api/claude-fix.sh';
        $hasAuth = is_dir('/var/www/html/jobs/claude-fix')
                || is_writable('/var/www/html/jobs');
        if (file_exists($claudeWrapper) && $hasAuth) {
            // Bulk-enqueue model: drop a request file and IMMEDIATELY continue
            // to the next event. The host-side claude-fix-runner picks up the
            // queue asynchronously via systemd path watcher, processes events
            // sequentially, and writes its own progress into the SAME status
            // file. PHP's part of the run finishes in seconds, releasing the
            // flock. UI polling sees the host runner's updates as Claude
            // processes each event.
            $queueDir = '/var/www/html/jobs/claude-fix';
            @mkdir($queueDir, 0775, true);
            $suffix = $RUN_ID . '_' . (int)$error['event_key'];
            $reqFile = "$queueDir/req_{$suffix}.json";
            @file_put_contents($reqFile, json_encode([
                'run_id' => $RUN_ID,
                'event_key' => (int)$error['event_key'],
                'queued_at' => date('c'),
                'source' => $source,
                'msg_preview' => substr($msg, 0, 120),
            ], JSON_PRETTY_PRINT));
            @chmod($reqFile, 0664);
            $line = "  [claude-fix] #{$error['event_key']} {$source}: enqueued for host runner";
            echo $line . "\n";
            recordCounter('claude-fix', $line, $counters, $logLines, $STATUS_FILE, $processed, $total, $currentLabel);
            $fixedMessages[$msgHash] = $error['event_key'];
        } else {
            $reason = !file_exists($claudeWrapper) ? 'wrapper missing' : 'Claude Code not authenticated';
            $line = "  [pending] #{$error['event_key']} {$source}: " . substr($msg, 0, 80) . " ({$reason})";
            echo $line . "\n";
            recordCounter('pending', $line, $counters, $logLines, $STATUS_FILE, $processed, $total, $currentLabel);
        }
    }
}

// Final status. If we enqueued any Claude events, we're NOT actually done —
// the host runner is still working. Mark the run as claude_queued so the UI
// keeps polling, and the host runner updates this same file (and ultimately
// flips state to 'complete') as it drains the queue.
$claudeQueued = $counters['claude-fix'] ?? 0;
$finalState = $claudeQueued > 0 ? 'claude_queued' : 'complete';
writeStatus($STATUS_FILE, [
    'state' => $finalState,
    'total' => $total,
    'processed' => $processed,
    'counters' => $counters,
    'started' => $STARTED_AT,
    'finished' => date('c'),
    'log_tail' => implode("\n", array_slice($logLines, -100)),
]);

echo "[" . date('c') . "] Done. Triaged: " . count($errors) . " errors\n";

// NOTE: This script NEVER calls the Anthropic API directly. It only triages
// errors with local pattern-matching and enqueues hard cases for the on-host
// Claude Code agent (claude-fix.sh), which authenticates via the Max
// subscription with the API key stripped. The former _readEnvKey() helper
// (which read ANTHROPIC_API_KEY) was removed deliberately: there must be no
// code path here that can touch the pay-per-token API key.

