<?php
/**
 * ft-stage-worker.php — detached worker that pushes one spooled reference clip
 * from this host up to the GPU box (/tts/voices/stage).
 *
 * Spawned by admin-tts-customize.php's ft_stage_one action via spawnCappedWorker.
 * The origin→box link is a slow remote tailnet (~5 MB/s), so a large clip's
 * push takes far longer than Cloudflare's ~100s edge timeout. Doing it here, in
 * a background process the edge isn't waiting on, keeps the upload request fast
 * and lets the browser poll ft_stage_status instead of 524'ing.
 *
 * argv: <token> <job> <ext> <filename>
 * Reads  : <tmp>/ftstage-<token>.<ext>          (the spooled upload)
 * Writes : <tmp>/ftstage-<token>.status.json    ({state: staging|done|error, ...})
 */

require_once __DIR__ . '/gpu-client.php';

$token = $argv[1] ?? '';
$job   = $argv[2] ?? '';
$ext   = $argv[3] ?? 'wav';
$name  = $argv[4] ?? '';

$tmp        = sys_get_temp_dir();
$spool      = $tmp . '/ftstage-' . $token . '.' . $ext;
$statusFile = $tmp . '/ftstage-' . $token . '.status.json';

function writeStatus(string $f, array $a): void { @file_put_contents($f, json_encode($a)); }

if ($token === '' || !preg_match('/^[a-f0-9]{8,}$/', $token) || !is_file($spool)) {
    writeStatus($statusFile, ['ok' => true, 'state' => 'error', 'error' => 'spooled clip not found']);
    exit(1);
}

// Generous timeout — this is NOT under the Cloudflare edge, only bounded by the
// worker's CPU-time cap (network waits don't accrue CPU time).
$r = gpuStageClip($job, $spool, 600, $name);
@unlink($spool);

if (!$r['ok']) {
    writeStatus($statusFile, ['ok' => true, 'state' => 'error',
                              'error' => $r['error'] ?? 'clip staging failed']);
    exit(1);
}

$d = $r['data'] ?? [];
writeStatus($statusFile, [
    'ok'    => true,
    'state' => 'done',
    'job'   => $d['job'] ?? $job,
    'clip'  => [
        'index'    => $d['index']    ?? 0,
        'stub'     => $d['stub']     ?? '',
        'filename' => $d['filename'] ?? $name,
        'seconds'  => $d['seconds']  ?? 0,
        'text'     => '',
        'segments' => [],
    ],
]);
