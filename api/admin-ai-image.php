<?php
/**
 * AI generation admin endpoint — Image tab (yy_t2i_job).
 *
 *   GET   ?action=list_providers           providers mapped to functionality='t2i'
 *   GET   ?action=list_jobs[&limit=N]      recent yy_t2i_job rows
 *   GET   ?action=status&t2i_job_key=N     one job row
 *   GET   ?action=list_recent_outputs[&limit=N]
 *                                          unified recent COMPLETE images + videos
 *                                          for the "use previous output as input" picker
 *   POST  multipart:
 *         provider_key, prompt, negative_prompt?, params(json),
 *         init_images[]?  optional starter image(s) for img2img
 *         mask?           optional inpainting mask PNG (white = changeable, black = preserve)
 *         prev_ref?       JSON {kind:'image'|'video', key:N, index?:N}
 *                         when set, the referenced output is copied into the job's
 *                         uploads dir (videos are first-framed via ffmpeg).
 *         → {t2i_job_key, queued}
 *
 *   POST  application/json {action:'cancel'|'delete', t2i_job_key:N}
 *
 * Concurrency cap = 1 (GPU is single-job; mirrors video side).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';
require_once __DIR__ . '/ai-schema-lib.php';

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
    return ($v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true');
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
       WHERE f.functionality_code = 't2i'
       ORDER BY fp.functionality_provider_sort, p.provider_label
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $s = is_string($r['settings']) ? (json_decode($r['settings'], true) ?: []) : ($r['settings'] ?: []);
        $r['settings']            = $s;
        $r['supports_init_image'] = !empty($s['supports_init_image']);
        $r['supports_inpainting'] = !empty($s['supports_inpainting']);
        $r['max_dim']             = (int)($s['max_dim']   ?? 1024);
        $r['max_batch']           = (int)($s['max_batch'] ?? 4);
        $r['online']              = !empty($s['online']);
        $r['provider_active']     = _flagTrue($r['provider_active']);
        $r['map_active']          = _flagTrue($r['map_active']);
        $r['sort']                = (int)$r['sort'];
    }
    unset($r);
    // Attach each engine's self-described input schema (from /models, cached).
    $schemas = ai_fetch_param_schemas('t2i', $rows);
    foreach ($rows as &$r) { $r['param_schema'] = $schemas[$r['model_id']] ?? null; }
    unset($r);
    jsonResponse(['providers' => $rows]);
}

// ── GET list_recent_outputs (cross-tab picker) ────────────────────────
if ($method === 'GET' && $action === 'list_recent_outputs') {
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 24)));

    $vStmt = $db->prepare("
      SELECT i2v_job_key AS key, i2v_job_prompt AS prompt, i2v_job_output_path AS path,
             i2v_job_completed_dtime AS completed, i2v_job_model_id AS model_id
        FROM yy_i2v_job
       WHERE i2v_job_status='complete' AND i2v_job_output_path IS NOT NULL
       ORDER BY i2v_job_completed_dtime DESC LIMIT ?
    ");
    $vStmt->execute([$limit]);
    $videos = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    $iStmt = $db->prepare("
      SELECT t2i_job_key AS key, t2i_job_prompt AS prompt, t2i_job_outputs AS outputs,
             t2i_job_completed_dtime AS completed, t2i_job_model_id AS model_id
        FROM yy_t2i_job
       WHERE t2i_job_status='complete' AND jsonb_array_length(t2i_job_outputs) > 0
       ORDER BY t2i_job_completed_dtime DESC LIMIT ?
    ");
    $iStmt->execute([$limit]);
    $images = [];
    foreach ($iStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $outs = is_string($r['outputs']) ? (json_decode($r['outputs'], true) ?: []) : ($r['outputs'] ?: []);
        foreach ($outs as $i => $out) {
            $images[] = [
                'key'       => (int)$r['key'],
                'index'     => $i,
                'prompt'    => $r['prompt'],
                'path'      => is_array($out) ? ($out['path'] ?? '') : (string)$out,
                'completed' => $r['completed'],
                'model_id'  => $r['model_id'],
            ];
        }
    }
    jsonResponse(['videos' => $videos, 'images' => $images]);
}

// ── GET list_jobs ─────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list_jobs') {
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $stmt = $db->prepare("
      SELECT j.*, p.provider_label, p.provider_model_id
        FROM yy_t2i_job j
        LEFT JOIN yy_provider p ON p.provider_key = j.provider_key
       ORDER BY j.t2i_job_dtime DESC LIMIT ?
    ");
    $stmt->execute([$limit]);
    jsonResponse(['jobs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── GET status ────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'status') {
    $jobKey = (int)($_GET['t2i_job_key'] ?? 0);
    if (!$jobKey) errorResponse('t2i_job_key required');
    $stmt = $db->prepare("
      SELECT j.*, p.provider_label, p.provider_model_id
        FROM yy_t2i_job j
        LEFT JOIN yy_provider p ON p.provider_key = j.provider_key
       WHERE j.t2i_job_key = ?
    ");
    $stmt->execute([$jobKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) errorResponse('not found', 404);
    jsonResponse($row);
}

// ── POST cancel ───────────────────────────────────────────────────────
if ($method === 'POST' && !$isMultipart && $action === 'cancel') {
    $jobKey = (int)($data['t2i_job_key'] ?? 0);
    if (!$jobKey) errorResponse('t2i_job_key required');
    $db->prepare("
      UPDATE yy_t2i_job
         SET t2i_job_status='cancelled', t2i_job_completed_dtime=NOW(),
             t2i_job_message='cancelled by admin'
       WHERE t2i_job_key=? AND t2i_job_status IN ('pending','running')
    ")->execute([$jobKey]);
    jsonResponse(['ok' => true]);
}

// ── POST delete ───────────────────────────────────────────────────────
if ($method === 'POST' && !$isMultipart && $action === 'delete') {
    $jobKey = (int)($data['t2i_job_key'] ?? 0);
    if (!$jobKey) errorResponse('t2i_job_key required');

    $hostBase = '/opt/yada-www/public';
    $contBase = dirname(__DIR__);
    $fsBase   = is_dir($contBase) ? $contBase : $hostBase;

    foreach (['/u/t2i-uploads/', '/u/t2i-outputs/'] as $base) {
        $d = $fsBase . $base . $jobKey;
        if (is_dir($d)) {
            foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
            @rmdir($d);
        }
    }
    $db->prepare("DELETE FROM yy_t2i_job WHERE t2i_job_key = ?")->execute([$jobKey]);
    jsonResponse(['ok' => true]);
}

// ── POST multipart start ──────────────────────────────────────────────
if ($method === 'POST' && $isMultipart) {
    $providerKey    = (int)($_POST['provider_key'] ?? 0);
    $prompt         = trim((string)($_POST['prompt'] ?? ''));
    $negativePrompt = trim((string)($_POST['negative_prompt'] ?? ''));
    $paramsRaw      = (string)($_POST['params'] ?? '{}');
    $prevRefRaw     = trim((string)($_POST['prev_ref'] ?? ''));
    if (!$providerKey || $prompt === '') errorResponse('provider_key and prompt required');
    $params = json_decode($paramsRaw, true);
    if (!is_array($params)) $params = [];

    // Provider must be t2i-mapped.
    $pStmt = $db->prepare("
      SELECT p.provider_model_id, p.provider_endpoint, p.provider_active_flag, p.provider_settings
        FROM yy_provider p
        JOIN yy_functionality_provider fp ON fp.provider_key = p.provider_key
        JOIN yy_functionality f ON f.functionality_key = fp.functionality_key
       WHERE p.provider_key = ? AND f.functionality_code = 't2i'
    ");
    $pStmt->execute([$providerKey]);
    $prov = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$prov) errorResponse('provider not registered for t2i', 400);

    // Insert job row.
    $ins = $db->prepare("
      INSERT INTO yy_t2i_job
        (provider_key, t2i_job_model_id, t2i_job_prompt, t2i_job_negative_prompt, t2i_job_params, t2i_job_status, t2i_job_message)
      VALUES (?, ?, ?, ?, ?::jsonb, 'pending', 'Queued')
      RETURNING t2i_job_key
    ");
    $ins->execute([
        $providerKey,
        $prov['provider_model_id'],
        $prompt,
        $negativePrompt !== '' ? $negativePrompt : null,
        json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $jobKey = (int)$ins->fetchColumn();

    // Per-job uploads dir under /public/u/t2i-uploads/<key>/
    $hostUploadsBase = '/opt/yada-www/public/u/t2i-uploads/';
    $contUploadsBase = dirname(__DIR__) . '/u/t2i-uploads/';
    $uploadsBase     = is_dir(dirname(__DIR__)) ? $contUploadsBase : $hostUploadsBase;
    $jobDir          = $uploadsBase . $jobKey;
    if (!is_dir($jobDir) && !@mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
        $db->prepare("UPDATE yy_t2i_job SET t2i_job_status='failed', t2i_job_error='cannot create uploads dir' WHERE t2i_job_key=?")->execute([$jobKey]);
        errorResponse('cannot create uploads dir');
    }

    $inputImages = [];

    // Resolve prev_ref (previously-generated image OR video → first frame)
    if ($prevRefRaw !== '') {
        $ref = json_decode($prevRefRaw, true);
        if (is_array($ref) && !empty($ref['kind']) && !empty($ref['key'])) {
            $refPath = null;
            if ($ref['kind'] === 'image') {
                $stmt = $db->prepare("SELECT t2i_job_outputs FROM yy_t2i_job WHERE t2i_job_key=?");
                $stmt->execute([(int)$ref['key']]);
                $outsRaw = $stmt->fetchColumn();
                $outs = is_string($outsRaw) ? (json_decode($outsRaw, true) ?: []) : ($outsRaw ?: []);
                $idx = (int)($ref['index'] ?? 0);
                $sel = $outs[$idx] ?? null;
                if ($sel) $refPath = is_array($sel) ? ($sel['path'] ?? null) : (string)$sel;
            } elseif ($ref['kind'] === 'video') {
                $stmt = $db->prepare("SELECT i2v_job_output_path FROM yy_i2v_job WHERE i2v_job_key=?");
                $stmt->execute([(int)$ref['key']]);
                $refPath = $stmt->fetchColumn();
            }
            if ($refPath) {
                $hostPath = '/opt/yada-www/public' . $refPath;
                $contPath = dirname(__DIR__) . $refPath;
                $absSrc   = is_file($contPath) ? $contPath : $hostPath;
                if (is_file($absSrc)) {
                    $ext = strtolower(pathinfo($absSrc, PATHINFO_EXTENSION));
                    if (in_array($ext, ['png','jpg','jpeg','webp'], true)) {
                        $dest = sprintf('%s/init_00.%s', $jobDir, $ext);
                        if (@copy($absSrc, $dest)) {
                            $inputImages[] = [
                                'path'   => '/u/t2i-uploads/' . $jobKey . '/' . basename($dest),
                                'role'   => 'init',
                                'source' => 'prev_ref',
                                'ref'    => $ref,
                            ];
                        }
                    } elseif ($ext === 'mp4') {
                        $dest = $jobDir . '/init_00.png';
                        $cmd = 'ffmpeg -y -i ' . escapeshellarg($absSrc) . ' -frames:v 1 ' . escapeshellarg($dest) . ' 2>/dev/null';
                        @exec($cmd);
                        if (is_file($dest)) {
                            $inputImages[] = [
                                'path'   => '/u/t2i-uploads/' . $jobKey . '/init_00.png',
                                'role'   => 'init',
                                'source' => 'prev_ref_video',
                                'ref'    => $ref,
                            ];
                        }
                    }
                }
            }
        }
    }

    // Handle init_images uploads (multiple optional).
    $files = $_FILES['init_images'] ?? null;
    if ($files && !empty($files['tmp_name'])) {
        $names = is_array($files['name'])     ? $files['name']     : [$files['name']];
        $tmps  = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errs  = is_array($files['error'])    ? $files['error']    : [$files['error']];
        foreach ($tmps as $i => $tmp) {
            if ($errs[$i] !== UPLOAD_ERR_OK) continue;
            $orig = (string)($names[$i] ?? "image_$i");
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png','jpg','jpeg','webp'], true)) continue;
            $idx = count(array_filter($inputImages, fn($x) => ($x['role'] ?? '') === 'init'));
            $dest = sprintf('%s/init_%02d.%s', $jobDir, $idx, $ext);
            if (@move_uploaded_file($tmp, $dest)) {
                $inputImages[] = [
                    'path'   => '/u/t2i-uploads/' . $jobKey . '/' . basename($dest),
                    'role'   => 'init',
                    'source' => 'upload',
                ];
            }
        }
    }

    // Optional mask (single PNG painted client-side).
    $maskFile = $_FILES['mask'] ?? null;
    if ($maskFile && !empty($maskFile['tmp_name']) && (int)($maskFile['error'] ?? 1) === UPLOAD_ERR_OK) {
        $maskDest = $jobDir . '/mask.png';
        if (@move_uploaded_file($maskFile['tmp_name'], $maskDest)) {
            $inputImages[] = [
                'path'   => '/u/t2i-uploads/' . $jobKey . '/mask.png',
                'role'   => 'mask',
                'source' => 'paint',
            ];
        }
    }

    $db->prepare("UPDATE yy_t2i_job SET t2i_job_input_images = ?::jsonb WHERE t2i_job_key = ?")
       ->execute([json_encode($inputImages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $jobKey]);

    // Spawn worker (cap = 1; mirrors video side).
    $maxConcurrent = 1;
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_t2i_job WHERE t2i_job_status='running'")->fetchColumn();
    $queued  = false;
    $workerScript = __DIR__ . '/admin-ai-image-build-worker.php';
    if ($running < $maxConcurrent && file_exists($workerScript)) {
        $logFile = sys_get_temp_dir() . '/ai_image_build_' . $jobKey . '.log';
        $pid = spawnCappedWorker($workerScript, [(string)$jobKey], $logFile, [
            'cpu_secs' => 7200, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_t2i_job SET t2i_job_worker_pid=?, t2i_job_started_dtime=NOW() WHERE t2i_job_key=?")
               ->execute([$pid, $jobKey]);
        }
    } else {
        $queued = true;
        $db->prepare("UPDATE yy_t2i_job SET t2i_job_message=? WHERE t2i_job_key=?")
           ->execute(["Queued — waiting for an open slot (limit: $maxConcurrent)", $jobKey]);
    }
    jsonResponse(['t2i_job_key' => $jobKey, 'queued' => $queued]);
}

errorResponse('unknown action', 400);
