<?php
/**
 * Status / result endpoint for async preview jobs (see admin-tts-preview.php
 * + admin-tts-preview-worker.php).
 *
 *   GET ?job_key=N            -> {status, para_done, para_total, error}
 *   GET ?job_key=N&fetch=1    -> audio/mpeg (only when status === 'done')
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$user = requireAuth();
$db   = getDb();

$jobKey = (int)($_GET['job_key'] ?? 0);
if (!$jobKey) errorResponse('job_key required');

$stmt = $db->prepare("SELECT job_status, job_para_done, job_para_total, job_error
                        FROM yy_tts_preview_job WHERE job_key = ?");
$stmt->execute([$jobKey]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) errorResponse('job not found', 404);

if (!empty($_GET['fetch'])) {
    if ($job['job_status'] !== 'done') errorResponse('not ready', 409);
    $path = ttsPreviewJobMp3Path($jobKey);
    if (!is_file($path)) errorResponse('rendered audio expired', 410);
    $bytes = (string)file_get_contents($path);
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    echo $bytes;
    exit;
}

jsonResponse([
    'status'     => $job['job_status'],
    'para_done'  => (int)$job['job_para_done'],
    'para_total' => $job['job_para_total'] !== null ? (int)$job['job_para_total'] : null,
    'error'      => $job['job_error'],
]);
