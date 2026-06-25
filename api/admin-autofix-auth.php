<?php
/**
 * Auto-Fix authentication API.
 *
 *   GET  /api/admin-autofix-auth.php             — current sanitized auth status
 *   GET  /api/admin-autofix-auth.php?result=<id> — poll an enqueued action's result
 *   POST /api/admin-autofix-auth.php             — enqueue an action (JSON body)
 *
 * The auto-fix runner authenticates as the host 'claudefix' user from
 * /opt/yada-www/claude-home/.claude/ — a directory PHP can neither read nor
 * write (not mounted in the container; 0600 claudefix-owned). So every
 * privileged operation is delegated to claude-auth-helper.sh on the host via
 * a bind-mounted queue, exactly like claude-fix-runner.sh. PHP only ever:
 *   - writes a request file (req_<id>.json) into the queue, and
 *   - reads back the sanitized status.json / res_<id>.json the helper writes.
 *
 * No token or credential value is ever returned to the browser.
 * Auth: admin only.
 */
require_once __DIR__ . '/config.php';
$AUTH_USER = requireAuth();

// Container path for the bind-mounted queue (host:
// /opt/yada-www/public/jobs/claude-auth -> container: /var/www/html/jobs/claude-auth).
$QUEUE = '/var/www/html/jobs/claude-auth';

$ACTIONS = ['status', 'test', 'apply_token', 'apply_creds', 'clear_token'];

function newId(): string {
    // 12 hex chars; time-prefixed so processed files sort chronologically.
    return dechex(time()) . bin2hex(random_bytes(3));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Poll a specific enqueued action's result.
    if (isset($_GET['result'])) {
        $id = preg_replace('/[^a-f0-9]/', '', (string)$_GET['result']);
        $f = "$QUEUE/res_$id.json";
        if ($id !== '' && is_file($f)) {
            $d = json_decode((string)@file_get_contents($f), true);
            jsonResponse(['ready' => true, 'result' => $d]);
        }
        jsonResponse(['ready' => false]);
    }

    // Default: the latest sanitized status the helper computed.
    $sf = "$QUEUE/status.json";
    $status = null; $ageSecs = null;
    if (is_file($sf)) {
        $status = json_decode((string)@file_get_contents($sf), true);
        $ageSecs = time() - (int)@filemtime($sf);
    }
    jsonResponse([
        'status'         => $status,
        'status_age_secs'=> $ageSecs,
        'queue_writable' => is_dir($QUEUE) && is_writable($QUEUE),
        'now_epoch'      => time(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) errorResponse('Invalid JSON body');

    $action = $input['action'] ?? '';
    if (!in_array($action, $ACTIONS, true)) errorResponse('Unknown action');

    if (!is_dir($QUEUE)) {
        // The host helper creates this on first run; if it is missing the
        // systemd units almost certainly aren't installed yet.
        errorResponse('Auth queue not present — host helper not installed', 503);
    }
    if (!is_writable($QUEUE)) errorResponse('Auth queue not writable by web user', 500);

    global $AUTH_USER;
    $req = ['action' => $action, 'id' => newId(),
            'requested_by' => $AUTH_USER['user_name'] ?? 'admin', 'ts' => date('c')];

    if ($action === 'apply_token') {
        $tok = trim((string)($input['token'] ?? ''));
        if ($tok === '') errorResponse('Token is empty');
        if (strlen($tok) > 4096) errorResponse('Token too long');
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $tok)) errorResponse('Token has invalid characters');
        $req['token'] = $tok;
    } elseif ($action === 'apply_creds') {
        $creds = (string)($input['creds'] ?? '');
        if (trim($creds) === '') errorResponse('Credentials are empty');
        if (strlen($creds) > 16384) errorResponse('Credentials too large');
        $parsed = json_decode($creds, true);
        if (!is_array($parsed) || empty($parsed['claudeAiOauth']['accessToken'])) {
            errorResponse('Must be JSON containing claudeAiOauth.accessToken');
        }
        $req['creds'] = $creds;
    }

    $tmp  = "$QUEUE/.req_{$req['id']}.tmp";
    $dest = "$QUEUE/req_{$req['id']}.json";
    if (@file_put_contents($tmp, json_encode($req)) === false || !@rename($tmp, $dest)) {
        @unlink($tmp);
        errorResponse('Could not enqueue request', 500);
    }

    jsonResponse(['queued' => true, 'id' => $req['id'], 'action' => $action]);
}

errorResponse('Method not allowed', 405);
