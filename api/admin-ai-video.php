<?php
/**
 * AI generation admin endpoint — Video tab (yy_i2v_job).
 *
 *   GET   ?action=list_providers
 *     → providers mapped to functionality='i2v' (with caps/active/sort/online)
 *
 *   GET   ?action=list_jobs[&limit=N]
 *     → recent yy_i2v_job rows (newest first)
 *
 *   GET   ?action=status&i2v_job_key=N
 *     → one job row
 *
 *   POST  multipart: provider_key, prompt, negative_prompt?, params(json), images[]
 *     → {i2v_job_key, queued}
 *     Saves uploaded images under public/u/i2v-uploads/<job_key>/ and spawns the
 *     CLI build-worker. Concurrency cap = 1 (GPU is single-job; mirrors TTS).
 *
 *   POST  application/json {action:'cancel'|'delete', i2v_job_key:N}
 *     cancel — mark pending/running as cancelled (worker re-checks each poll).
 *     delete — remove the row + uploads dir + output file.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';

$user = requireAuth();
$db   = getDb();
setCurrentUser($db, (int)$user['user_key']);

$method      = $_SERVER['REQUEST_METHOD'];
$action      = $_GET['action'] ?? '';
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$isMultipart = stripos($contentType, 'multipart/form-data') === 0;
$data        = [];
if ($method === 'POST' && !$isMultipart) {
    $data   = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
}

function _flagTrue($v): bool {
    if ($v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true') return true;
    return false;
}

// ── GET list_providers ────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list_providers') {
    $stmt = $db->prepare("
      SELECT p.provider_key, p.provider_label AS label, p.provider_model_id AS model_id,
             p.provider_endpoint AS endpoint, p.provider_settings AS settings,
             p.provider_active_flag AS provider_active,
             fp.functionality_provider_sort AS sort,
             fp.functionality_provider_active_flag AS map_active
        FROM yy_functionality f
        JOIN yy_functionality_provider fp ON fp.functionality_key = f.functionality_key
        JOIN yy_provider p ON p.provider_key = fp.provider_key
       WHERE f.functionality_code = 'i2v'
       ORDER BY fp.functionality_provider_sort, p.provider_label
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $s = is_string($r['settings']) ? (json_decode($r['settings'], true) ?: []) : ($r['settings'] ?: []);
        $r['settings']                  = $s;
        $r['supports_single_image']     = !empty($s['supports_single_image']);
        $r['supports_first_last_frame'] = !empty($s['supports_first_last_frame']);
        $r['max_duration_sec']          = $s['max_duration_sec'] ?? null;
        $r['online']                    = !empty($s['online']);
        $r['provider_active']           = _flagTrue($r['provider_active']);
        $r['map_active']                = _flagTrue($r['map_active']);
        $r['sort']                      = (int)$r['sort'];
    }
    unset($r);
    jsonResponse(['providers' => $rows]);
}

// ── GET list_jobs ──────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list_jobs') {
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $stmt = $db->prepare("
      SELECT j.*, p.provider_label, p.provider_model_id
        FROM yy_i2v_job j
        LEFT JOIN yy_provider p ON p.provider_key = j.provider_key
       ORDER BY j.i2v_job_dtime DESC
       LIMIT ?
    ");
    $stmt->execute([$limit]);
    jsonResponse(['jobs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── GET status ─────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'status') {
    $jobKey = (int)($_GET['i2v_job_key'] ?? 0);
    if (!$jobKey) errorResponse('i2v_job_key required');
    $stmt = $db->prepare("
      SELECT j.*, p.provider_label, p.provider_model_id
        FROM yy_i2v_job j
        LEFT JOIN yy_provider p ON p.provider_key = j.provider_key
       WHERE j.i2v_job_key = ?
    ");
    $stmt->execute([$jobKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) errorResponse('not found', 404);
    jsonResponse($row);
}

// ── POST cancel ────────────────────────────────────────────────────────
if ($method === 'POST' && !$isMultipart && $action === 'cancel') {
    $jobKey = (int)($data['i2v_job_key'] ?? 0);
    if (!$jobKey) errorResponse('i2v_job_key required');
    $db->prepare("
      UPDATE yy_i2v_job
         SET i2v_job_status='cancelled',
             i2v_job_completed_dtime=NOW(),
             i2v_job_message='cancelled by admin'
       WHERE i2v_job_key=? AND i2v_job_status IN ('pending','running')
    ")->execute([$jobKey]);
    jsonResponse(['ok' => true]);
}

// ── POST delete ────────────────────────────────────────────────────────
if ($method === 'POST' && !$isMultipart && $action === 'delete') {
    $jobKey = (int)($data['i2v_job_key'] ?? 0);
    if (!$jobKey) errorResponse('i2v_job_key required');
    $jobRow = $db->prepare("SELECT i2v_job_output_path FROM yy_i2v_job WHERE i2v_job_key=?");
    $jobRow->execute([$jobKey]);
    $outRel = (string)$jobRow->fetchColumn();

    $hostUploads = '/opt/yada-www/public/u/i2v-uploads/' . $jobKey;
    $contUploads = dirname(__DIR__) . '/u/i2v-uploads/' . $jobKey;
    $uploads = is_dir($contUploads) ? $contUploads : $hostUploads;
    if (is_dir($uploads)) {
        foreach (glob($uploads . '/*') ?: [] as $f) @unlink($f);
        @rmdir($uploads);
    }

    if ($outRel !== '') {
        $hostVideo = '/opt/yada-www/public' . $outRel;
        $contVideo = dirname(__DIR__) . $outRel;
        $video = is_file($contVideo) ? $contVideo : $hostVideo;
        if (is_file($video)) @unlink($video);
    }

    $db->prepare("DELETE FROM yy_i2v_job WHERE i2v_job_key = ?")->execute([$jobKey]);
    jsonResponse(['ok' => true]);
}

// ── POST multipart start ───────────────────────────────────────────────
if ($method === 'POST' && $isMultipart) {
    $providerKey    = (int)($_POST['provider_key'] ?? 0);
    $prompt         = trim((string)($_POST['prompt'] ?? ''));
    $negativePrompt = trim((string)($_POST['negative_prompt'] ?? ''));
    $paramsRaw      = (string)($_POST['params'] ?? '{}');
    if (!$providerKey || $prompt === '') errorResponse('provider_key and prompt required');
    $params = json_decode($paramsRaw, true);
    if (!is_array($params)) $params = [];

    // Provider must be mapped to the i2v functionality.
    $pStmt = $db->prepare("
      SELECT p.provider_model_id, p.provider_endpoint, p.provider_active_flag, p.provider_settings
        FROM yy_provider p
        JOIN yy_functionality_provider fp ON fp.provider_key = p.provider_key
        JOIN yy_functionality f ON f.functionality_key = fp.functionality_key
       WHERE p.provider_key = ? AND f.functionality_code = 'i2v'
    ");
    $pStmt->execute([$providerKey]);
    $prov = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$prov) errorResponse('provider not registered for i2v', 400);

    // Insert job row first so we have the key for the uploads dir.
    $ins = $db->prepare("
      INSERT INTO yy_i2v_job
        (provider_key, i2v_job_model_id, i2v_job_prompt, i2v_job_negative_prompt, i2v_job_params, i2v_job_status, i2v_job_message)
      VALUES (?, ?, ?, ?, ?::jsonb, 'pending', 'Queued')
      RETURNING i2v_job_key
    ");
    $ins->execute([
        $providerKey,
        $prov['provider_model_id'],
        $prompt,
        $negativePrompt !== '' ? $negativePrompt : null,
        json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $jobKey = (int)$ins->fetchColumn();

    // Save uploads under public/u/i2v-uploads/<job_key>/ (dual host/container path).
    $hostUploadsBase = '/opt/yada-www/public/u/i2v-uploads/';
    $contUploadsBase = dirname(__DIR__) . '/u/i2v-uploads/';
    $uploadsBase     = is_dir(dirname(__DIR__)) ? $contUploadsBase : $hostUploadsBase;
    $jobDir          = $uploadsBase . $jobKey;
    if (!is_dir($jobDir) && !@mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
        $db->prepare("UPDATE yy_i2v_job SET i2v_job_status='failed', i2v_job_error='cannot create uploads dir' WHERE i2v_job_key=?")->execute([$jobKey]);
        errorResponse('cannot create uploads dir');
    }

    $files = $_FILES['images'] ?? null;
    if (!$files || empty($files['tmp_name'])) {
        $db->prepare("UPDATE yy_i2v_job SET i2v_job_status='failed', i2v_job_error='no images uploaded' WHERE i2v_job_key=?")->execute([$jobKey]);
        errorResponse('no images uploaded');
    }
    $names = is_array($files['name'])     ? $files['name']     : [$files['name']];
    $tmps  = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $sizes = is_array($files['size'])     ? $files['size']     : [$files['size']];
    $errs  = is_array($files['error'])    ? $files['error']    : [$files['error']];
    $n     = count($tmps);

    $imageRows = [];
    foreach ($tmps as $i => $tmp) {
        if ($errs[$i] !== UPLOAD_ERR_OK) continue;
        $orig = (string)($names[$i] ?? "image_$i");
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png','jpg','jpeg','webp'], true)) {
            $db->prepare("UPDATE yy_i2v_job SET i2v_job_status='failed', i2v_job_error=? WHERE i2v_job_key=?")
               ->execute(["unsupported image extension '$ext'", $jobKey]);
            errorResponse("unsupported image extension '$ext'");
        }
        $dest = sprintf('%s/frame_%02d.%s', $jobDir, $i, $ext);
        if (!@move_uploaded_file($tmp, $dest)) {
            $db->prepare("UPDATE yy_i2v_job SET i2v_job_status='failed', i2v_job_error='upload save failed' WHERE i2v_job_key=?")->execute([$jobKey]);
            errorResponse('upload save failed');
        }
        $imageRows[] = [
            'path' => '/u/i2v-uploads/' . $jobKey . '/' . basename($dest),
            'role' => ($i === 0)               ? 'first'
                    : (($i === $n - 1 && $n > 1) ? 'last' : 'frame'),
            'size_bytes' => (int)$sizes[$i],
        ];
    }
    if (!$imageRows) {
        $db->prepare("UPDATE yy_i2v_job SET i2v_job_status='failed', i2v_job_error='all uploads failed' WHERE i2v_job_key=?")->execute([$jobKey]);
        errorResponse('all uploads failed');
    }
    $db->prepare("UPDATE yy_i2v_job SET i2v_job_input_images = ?::jsonb WHERE i2v_job_key = ?")
       ->execute([json_encode($imageRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $jobKey]);

    // Spawn the build worker. Concurrency cap = 1 (GPU is single-job, mirrors TTS).
    $maxConcurrent = 1;
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_i2v_job WHERE i2v_job_status='running'")->fetchColumn();
    $queued  = false;
    $workerScript = __DIR__ . '/admin-ai-video-build-worker.php';
    if ($running < $maxConcurrent && file_exists($workerScript)) {
        $logFile = sys_get_temp_dir() . '/ai_video_build_' . $jobKey . '.log';
        $pid = spawnCappedWorker($workerScript, [(string)$jobKey], $logFile, [
            'cpu_secs' => 7200, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_i2v_job SET i2v_job_worker_pid=?, i2v_job_started_dtime=NOW() WHERE i2v_job_key=?")
               ->execute([$pid, $jobKey]);
        }
    } else {
        $queued = true;
        $db->prepare("UPDATE yy_i2v_job SET i2v_job_message=? WHERE i2v_job_key=?")
           ->execute(["Queued — waiting for an open slot (limit: $maxConcurrent)", $jobKey]);
    }
    jsonResponse(['i2v_job_key' => $jobKey, 'queued' => $queued]);
}

errorResponse('unknown action', 400);
