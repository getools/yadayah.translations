<?php
/**
 * Receives batched JS errors from the client-side error reporter.
 * Stores them in yy_monitor_event with event_source = 'js_error'.
 * No auth required — fires from public pages.
 * Rate-limited: max 50 events per IP per hour.
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => true]);
}

$input = json_decode(file_get_contents('php://input'), true);
$errors = $input['errors'] ?? [];
if (!$errors || !is_array($errors)) {
    jsonResponse(['ok' => true]);
}

$db = getDb();
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
// Take first IP if comma-separated
if (strpos($clientIp, ',') !== false) $clientIp = trim(explode(',', $clientIp)[0]);

// Rate limit: max 50 events per IP per hour
$rateStmt = $db->prepare("
    SELECT COUNT(*) FROM yy_monitor_event
    WHERE event_source IN ('js_error', 'promise_rejection', 'console_error')
      AND event_client_ip = ?
      AND event_dtime > NOW() - INTERVAL '1 hour'
");
$rateStmt->execute([$clientIp]);
$recentCount = (int)$rateStmt->fetchColumn();
if ($recentCount >= 50) {
    jsonResponse(['ok' => true, 'throttled' => true]);
}

$insert = $db->prepare("
    INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag, event_file, event_referer, event_client_ip)
    VALUES (?, ?, ?, ?, FALSE, ?, ?, ?)
");

$logged = 0;
$maxBatch = min(count($errors), 15);
for ($i = 0; $i < $maxBatch; $i++) {
    $e = $errors[$i];
    if (!is_array($e) || empty($e['message'])) continue;

    $source = $e['type'] ?? 'js_error';
    $validSources = ['js_error', 'promise_rejection', 'console_error', 'console_warn',
                     'fetch_error', 'xhr_error', 'network_error', 'timeout_error',
                     'resource_error', 'csp_violation', 'long_task', 'browser_deprecation', 'browser_intervention'];
    if (!in_array($source, $validSources)) $source = 'js_error';

    // Map severity: network/resource errors are warnings, the rest are errors
    $severity = in_array($source, ['long_task', 'console_warn', 'browser_deprecation']) ? 'warning' : 'error';

    $message = substr($e['message'] ?? '', 0, 1000);
    $file = null;
    if (!empty($e['source'])) {
        $file = $e['source'];
        if (!empty($e['line'])) $file .= ':' . $e['line'];
        if (!empty($e['col'])) $file .= ':' . $e['col'];
        $file = substr($file, 0, 500);
    }
    $detail = '';
    if (!empty($e['stack'])) $detail .= $e['stack'];
    if (!empty($e['ua'])) $detail .= "\n\nUA: " . $e['ua'];
    $detail = substr($detail, 0, 4000) ?: null;

    $page = !empty($e['page']) ? substr($e['page'], 0, 500) : null;

    $insert->execute([$source, $severity, $message, $detail, $file, $page, $clientIp]);
    $logged++;
}

// Immediately resolve only confirmed noise (browser extensions, bots) — not potential code bugs
if ($logged > 0) {
    $noiseTerms = ['chrome-extension', 'moz-extension', 'safari-extension', 'window.ethereum',
        '__firefox__', 'ResizeObserver', 'googletagmanager', 'gtag', 'standardSelectors',
        'Object Not Found Matching Id', 'The string did not match the expected pattern',
        'The operation was aborted',
        'media resource was aborted by the user agent',
        // Admin 401 session expiry — admin api() rethrows as unhandled rejection;
        // client-side filter in error-reporter.js covers most cases, but async
        // script injection creates a race where rejections fire before it loads.
        'Authentication required',
        '"error":"Authentication required"'];
    $resolveStmt = $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ? AND event_resolved_flag = FALSE");
    $pending = $db->prepare("SELECT event_key, event_message, event_detail FROM yy_monitor_event WHERE event_resolved_flag = FALSE AND event_dtime > NOW() - INTERVAL '5 minutes' ORDER BY event_dtime DESC LIMIT 30");
    $pending->execute();
    foreach ($pending->fetchAll() as $evt) {
        $text = ($evt['event_message'] ?? '') . ' ' . ($evt['event_detail'] ?? '');
        foreach ($noiseTerms as $nt) {
            if (stripos($text, $nt) !== false) {
                $resolveStmt->execute(['Auto-skipped: browser extension or noise', $evt['event_key']]);
                break;
            }
        }
    }
}

jsonResponse(['ok' => true, 'logged' => $logged]);
