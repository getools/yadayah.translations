<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && !empty($_POST['_method'])) $method = strtoupper($_POST['_method']);

$UPLOAD_DIR = $_SERVER['DOCUMENT_ROOT'] . '/u/backgrounds/';
$JOB_DIR = sys_get_temp_dir() . '/bg_jobs/';
if (!is_dir($JOB_DIR)) @mkdir($JOB_DIR, 0755, true);

switch ($method) {

case 'GET':
    // Job status check
    if (!empty($_GET['job'])) {
        $jobId = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['job']);
        $statusFile = $JOB_DIR . $jobId . '.status';
        if (!file_exists($statusFile)) {
            jsonResponse(['status' => 'unknown']);
        }
        $status = trim(file_get_contents($statusFile));

        // When done, clear converting flag and activate if user wanted it active
        if ($status === 'done') {
            $metaFile = $JOB_DIR . $jobId . '.meta';
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                if (!empty($meta['asset_key'])) {
                    $active = !empty($meta['active']) ? 't' : 'f';
                    $db->prepare("UPDATE yy_asset SET asset_active_flag = ?, asset_converting_flag = 'f' WHERE asset_key = ?")
                       ->execute([$active, $meta['asset_key']]);
                }
                @unlink($metaFile);
            }
            // Clean up job files
            @unlink($statusFile);
            $scriptFile = $JOB_DIR . $jobId . '.sh';
            if (file_exists($scriptFile)) @unlink($scriptFile);
        }

        jsonResponse(['status' => $status]);
    }
    $stmt = $db->query("SELECT asset_key, asset_name, asset_file, asset_active_flag, asset_sort, asset_converting_flag FROM yy_asset WHERE asset_group_code = 'headerfooter' ORDER BY asset_sort, asset_name");
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['converting'] = (bool)$r['asset_converting_flag'];
        unset($r['asset_converting_flag']);
    }
    unset($r);
    jsonResponse($rows);

case 'POST':
    $name = trim($_POST['asset_name'] ?? '') ?: null;
    $active = ($_POST['asset_active_flag'] ?? '1') === '1';
    $filePath = null;
    $jobId = null;

    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $result = handleUpload($_FILES['file'], $_POST);
        if (is_array($result)) {
            $filePath = $result['file'];
            $jobId = $result['job'];
        } else {
            $filePath = $result;
        }
    }

    // Force inactive while video is converting
    $dbActive = ($jobId ? false : $active);
    $converting = $jobId ? true : false;
    $stmt = $db->prepare("INSERT INTO yy_asset (asset_group_code, asset_name, asset_file, asset_active_flag, asset_converting_flag, user_key) VALUES ('headerfooter', ?, ?, ?, ?, ?) RETURNING asset_key");
    $stmt->execute([$name, $filePath, $dbActive ? 't' : 'f', $converting ? 't' : 'f', $user['user_key']]);
    $row = $stmt->fetch();
    $response = ['asset_key' => $row['asset_key']];
    if ($jobId) {
        $response['job'] = $jobId;
        // Save desired active state for after conversion
        file_put_contents($JOB_DIR . $jobId . '.meta', json_encode([
            'asset_key' => $row['asset_key'],
            'active' => $active,
        ]));
    }
    jsonResponse($response, 201);

case 'PUT':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');

    $existing = $db->prepare("SELECT asset_key, asset_file FROM yy_asset WHERE asset_key = ? AND asset_group_code = 'headerfooter'");
    $existing->execute([$key]);
    $row = $existing->fetch();
    if (!$row) errorResponse('Not found', 404);

    $name = trim($_POST['asset_name'] ?? '') ?: null;
    $active = ($_POST['asset_active_flag'] ?? '1') === '1';
    $filePath = $row['asset_file'];
    $jobId = null;

    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        if ($filePath) deleteFile($filePath);
        $result = handleUpload($_FILES['file'], $_POST);
        if (is_array($result)) {
            $filePath = $result['file'];
            $jobId = $result['job'];
        } else {
            $filePath = $result;
        }
    }

    // Force inactive while video is converting
    $dbActive = ($jobId ? false : $active);
    $converting = $jobId ? true : false;
    $stmt = $db->prepare("UPDATE yy_asset SET asset_name = ?, asset_file = ?, asset_active_flag = ?, asset_converting_flag = ?, user_key = ? WHERE asset_key = ?");
    $stmt->execute([$name, $filePath, $dbActive ? 't' : 'f', $converting ? 't' : 'f', $user['user_key'], $key]);
    $response = ['ok' => true];
    if ($jobId) {
        $response['job'] = $jobId;
        file_put_contents($JOB_DIR . $jobId . '.meta', json_encode([
            'asset_key' => $key,
            'active' => $active,
        ]));
    }
    jsonResponse($response);

case 'DELETE':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');

    $existing = $db->prepare("SELECT asset_file FROM yy_asset WHERE asset_key = ? AND asset_group_code = 'headerfooter'");
    $existing->execute([$key]);
    $row = $existing->fetch();
    if (!$row) errorResponse('Not found', 404);

    if ($row['asset_file']) deleteFile($row['asset_file']);

    $db->prepare("DELETE FROM yy_asset WHERE asset_key = ?")->execute([$key]);
    jsonResponse(['ok' => true]);

default:
    errorResponse('Method not allowed', 405);
}

function handleUpload(array $file, array $opts = []): string|array {
    global $UPLOAD_DIR, $JOB_DIR;
    if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $imageTypes = ['jpg','jpeg','png','gif','webp','svg'];
    $videoTypes = ['mp4','mov','avi','mkv','wmv','flv','webm'];
    $allowed = array_merge($imageTypes, $videoTypes);
    if (!in_array($ext, $allowed)) {
        errorResponse('File type not allowed. Allowed: ' . implode(', ', $allowed));
    }

    $tmpPath = $UPLOAD_DIR . uniqid('tmp_') . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
        errorResponse('Upload failed');
    }

    if (in_array($ext, $videoTypes)) {
        $base = uniqid('bg_');
        $filename = $base . '.webm';
        $thumbName = $base . '.jpg';
        $dest = $UPLOAD_DIR . $filename;
        $thumbDest = $UPLOAD_DIR . $thumbName;
        $tmpEsc = escapeshellarg($tmpPath);
        $destEsc = escapeshellarg($dest);
        $thumbEsc = escapeshellarg($thumbDest);

        // Build video filters
        $filters = [];
        $vw = !empty($opts['video_width']) ? (int)$opts['video_width'] : -1;
        $vh = !empty($opts['video_height']) ? (int)$opts['video_height'] : -1;
        if ($vw > 0 || $vh > 0) {
            $sw = $vw > 0 ? $vw : -2;
            $sh = $vh > 0 ? $vh : -2;
            $filters[] = "scale={$sw}:{$sh}";
        }

        $speed = !empty($opts['video_speed']) ? (float)$opts['video_speed'] : 1.0;
        if ($speed > 0 && $speed != 1.0) {
            $ptsFactor = round(1.0 / $speed, 4);
            $filters[] = "setpts={$ptsFactor}*PTS";
        }

        $filterArg = count($filters) ? '-vf ' . escapeshellarg(implode(',', $filters)) . ' ' : '';
        $mp4Name = $base . '.mp4';
        $mp4Dest = $UPLOAD_DIR . $mp4Name;
        $mp4Esc = escapeshellarg($mp4Dest);
        $videoCmd = "ffmpeg -i $tmpEsc {$filterArg}-c:v libvpx-vp9 -crf 30 -b:v 0 -an -y $destEsc";
        $mp4Cmd = "ffmpeg -i $tmpEsc {$filterArg}-c:v libx264 -crf 28 -preset slow -an -movflags +faststart -y $mp4Esc";

        // Thumbnail with scale filter if set
        $scaleArg = '';
        if ($vw > 0 || $vh > 0) {
            $scaleArg = '-vf ' . escapeshellarg("scale={$sw}:{$sh}") . ' ';
        }
        $thumbCmd = "ffmpeg -i $tmpEsc -vframes 1 {$scaleArg}-y $thumbEsc";

        // Write background conversion script
        $statusFile = $JOB_DIR . $base . '.status';
        file_put_contents($statusFile, 'converting');

        $statusEsc = escapeshellarg($statusFile);
        $script = "#!/bin/bash\n";
        $script .= "$videoCmd 2>&1\n";
        $script .= "if [ \$? -eq 0 ]; then\n";
        $script .= "  $mp4Cmd 2>&1\n";
        $script .= "  $thumbCmd 2>&1\n";
        $script .= "  rm -f $tmpEsc\n";
        $script .= "  echo done > $statusEsc\n";
        $script .= "else\n";
        $script .= "  rm -f $tmpEsc\n";
        $script .= "  echo error > $statusEsc\n";
        $script .= "fi\n";

        $scriptFile = $JOB_DIR . $base . '.sh';
        file_put_contents($scriptFile, $script);
        chmod($scriptFile, 0755);

        // Launch in background
        exec("nohup bash " . escapeshellarg($scriptFile) . " > /dev/null 2>&1 &");

        return ['file' => 'u/backgrounds/' . $filename, 'job' => $base];
    } else {
        $filename = uniqid('bg_') . '.' . $ext;
        $dest = $UPLOAD_DIR . $filename;
        rename($tmpPath, $dest);
        // Backgrounds are the biggest images on the site — the size set is what
        // lets a phone pull '-md' instead of a 4K hero. No-op for the .svg case.
        makeImageSizes($dest, rtrim($UPLOAD_DIR, '/'), $filename);
        return 'u/backgrounds/' . $filename;
    }
}

function deleteFile(string $relPath): void {
    $full = $_SERVER['DOCUMENT_ROOT'] . '/' . $relPath;
    if (file_exists($full)) unlink($full);
    if (preg_match('/\.webm$/i', $relPath)) {
        $thumb = $_SERVER['DOCUMENT_ROOT'] . '/' . preg_replace('/\.webm$/i', '.jpg', $relPath);
        if (file_exists($thumb)) unlink($thumb);
    }
}
