<?php
/**
 * AI generation Image build worker (yy_t2i_job).
 *
 * Spawned per yy_t2i_job by admin-ai-image.php. POSTs multipart to the image
 * engine's /generate, polls progress, downloads N output files (batch 1..4)
 * to /public/u/t2i-outputs/<key>/img_NN.{png|jpg|webp}.
 *
 * Usage:  php admin-ai-image-build-worker.php <t2i_job_key>
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';

$jobKey = (int)($argv[1] ?? 0);
if (!$jobKey) { fwrite(STDERR, "t2i_job_key required\n"); exit(2); }

$db = getDb();

$MAX_CONCURRENT = 1;
register_shutdown_function(function() use (&$db, $MAX_CONCURRENT) {
    try {
        if (!$db) $db = getDb();
        $running = (int)$db->query("SELECT COUNT(*) FROM yy_t2i_job WHERE t2i_job_status='running'")->fetchColumn();
        if ($running >= $MAX_CONCURRENT) return;
        $next = (int)$db->query("
          SELECT t2i_job_key FROM yy_t2i_job
           WHERE t2i_job_status='pending' AND t2i_job_worker_pid IS NULL
           ORDER BY t2i_job_dtime ASC LIMIT 1
        ")->fetchColumn();
        if (!$next) return;
        $logFile = sys_get_temp_dir() . '/ai_image_build_' . $next . '.log';
        $pid = spawnCappedWorker(__FILE__, [(string)$next], $logFile, [
            'cpu_secs' => 7200, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_t2i_job SET t2i_job_worker_pid=? WHERE t2i_job_key=?")
               ->execute([$pid, $next]);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "promote-next failed: " . $e->getMessage() . "\n");
    }
});

function updateJob(PDO $db, int $k, array $fields): void {
    if (!$fields) return;
    $set = []; $params = [];
    foreach ($fields as $col => $val) { $set[] = "$col = ?"; $params[] = $val; }
    $params[] = $k;
    $db->prepare("UPDATE yy_t2i_job SET " . implode(', ', $set) . ", t2i_job_revision_dtime=NOW() WHERE t2i_job_key=?")
       ->execute($params);
}

function bailJob(PDO $db, int $k, string $err): void {
    $short = trim(substr(str_replace(["\r", "\n"], ' ', $err), 0, 240));
    updateJob($db, $k, [
        't2i_job_status'          => 'failed',
        't2i_job_message'         => 'Failed: ' . $short,
        't2i_job_error'           => $err,
        't2i_job_completed_dtime' => date('Y-m-d H:i:sO'),
    ]);
    fwrite(STDERR, "FAIL: $err\n");
    exit(1);
}

$row = $db->prepare("SELECT * FROM yy_t2i_job WHERE t2i_job_key=?");
$row->execute([$jobKey]);
$job = $row->fetch(PDO::FETCH_ASSOC);
if (!$job) { fwrite(STDERR, "yy_t2i_job row $jobKey missing\n"); exit(2); }

$pStmt = $db->prepare("SELECT * FROM yy_provider WHERE provider_key=?");
$pStmt->execute([(int)$job['provider_key']]);
$prov = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$prov) bailJob($db, $jobKey, "provider missing");

$settings   = is_string($prov['provider_settings']) ? (json_decode($prov['provider_settings'], true) ?: []) : ($prov['provider_settings'] ?: []);
$endpoint   = rtrim((string)($prov['provider_endpoint'] ?? ''), '/');
$engineCode = $settings['engine'] ?? $prov['provider_model_id'];
if ($endpoint === '') bailJob($db, $jobKey, "provider has no endpoint");

updateJob($db, $jobKey, [
    't2i_job_status'  => 'running',
    't2i_job_message' => 'Submitting to engine',
    't2i_job_progress'=> 1,
]);

// Resolve input files (init_images + mask) — dual host/container path.
$inputImages = is_string($job['t2i_job_input_images']) ? (json_decode($job['t2i_job_input_images'], true) ?: []) : ($job['t2i_job_input_images'] ?: []);
$hostBase = '/opt/yada-www/public';
$contBase = dirname(__DIR__);
$fsBase   = is_dir($contBase) ? $contBase : $hostBase;

$initFiles = [];
$maskFile = null;
foreach ($inputImages as $img) {
    $rel = (string)($img['path'] ?? '');
    if ($rel === '') continue;
    $abs = $fsBase . $rel;
    if (!is_file($abs)) bailJob($db, $jobKey, "input file missing: $rel");
    if (($img['role'] ?? '') === 'mask') $maskFile = $abs;
    else                                  $initFiles[] = $abs;
}

// Submit to engine /generate as multipart.
$ch = curl_init($endpoint . '/generate');
$post = [
    'provider' => (string)$engineCode,
    'prompt'   => (string)$job['t2i_job_prompt'],
    'params'   => is_string($job['t2i_job_params']) ? $job['t2i_job_params'] : json_encode($job['t2i_job_params'] ?: new stdClass()),
];
if (!empty($job['t2i_job_negative_prompt'])) {
    $post['negative_prompt'] = (string)$job['t2i_job_negative_prompt'];
}
foreach ($initFiles as $i => $abs) {
    $post["init_images[$i]"] = new CURLFile($abs);
}
if ($maskFile !== null) {
    $post['mask'] = new CURLFile($maskFile);
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post,
    CURLOPT_TIMEOUT        => 120,
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);
if ($resp === false || $code >= 400) {
    bailJob($db, $jobKey, "submit HTTP $code: " . ($cerr ?: substr((string)$resp, 0, 200)));
}
$dec = json_decode((string)$resp, true);
$remoteJobId = is_array($dec) ? ($dec['job_id'] ?? null) : null;
$expectedN   = is_array($dec) ? (int)($dec['n'] ?? 1) : 1;
if (!$remoteJobId) bailJob($db, $jobKey, "engine did not return job_id");

updateJob($db, $jobKey, ['t2i_job_message' => 'Engine running', 't2i_job_progress' => 5]);

// Poll for progress.
$deadline = time() + 7200;
$finalStatus = null;
while (time() < $deadline) {
    $cstmt = $db->prepare("SELECT count(*) FROM yy_t2i_job WHERE t2i_job_key=? AND t2i_job_status='cancelled'");
    $cstmt->execute([$jobKey]);
    if ((int)$cstmt->fetchColumn() > 0) {
        fwrite(STDERR, "cancelled by admin\n");
        exit(0);
    }

    $ch = curl_init($endpoint . '/jobs/' . rawurlencode($remoteJobId));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        fwrite(STDERR, "poll HTTP $code; retrying\n");
        sleep(5);
        continue;
    }
    $j = json_decode((string)$resp, true) ?: [];
    $st = (string)($j['status'] ?? '');
    if ($st === 'failed') {
        bailJob($db, $jobKey, (string)($j['error'] ?? 'engine reported failed'));
    }
    if ($st === 'complete') { $finalStatus = $j; break; }
    if ($st === 'running' || $st === 'pending') {
        updateJob($db, $jobKey, [
            't2i_job_progress' => max(5, min(95, (int)($j['progress'] ?? 5))),
            't2i_job_message'  => (string)($j['message'] ?? 'Generating'),
        ]);
    }
    sleep(3);
}
if (!$finalStatus) bailJob($db, $jobKey, "engine timed out");

// Download N outputs.
$n     = (int)($finalStatus['n']     ?? $expectedN);
$files = (array)($finalStatus['files'] ?? []);

$outputsHost = '/opt/yada-www/public/u/t2i-outputs/' . $jobKey;
$outputsCont = dirname(__DIR__) . '/u/t2i-outputs/' . $jobKey;
$outDir      = is_dir($contBase) ? $outputsCont : $outputsHost;
if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    bailJob($db, $jobKey, "cannot create $outDir");
}

$outputs = [];
$totalSize = 0;
for ($i = 0; $i < $n; $i++) {
    $fileName  = (string)($files[$i] ?? sprintf('img_%02d.png', $i));
    $localPath = $outDir . '/' . $fileName;
    $relPath   = '/u/t2i-outputs/' . $jobKey . '/' . $fileName;

    $fh = fopen($localPath . '.staging', 'wb');
    if (!$fh) bailJob($db, $jobKey, "cannot open output $i");
    $ch = curl_init($endpoint . '/jobs/' . rawurlencode($remoteJobId) . '/file?index=' . $i);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 300]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    fclose($fh);
    if (!$ok || $code >= 400) {
        @unlink($localPath . '.staging');
        bailJob($db, $jobKey, "download $i HTTP $code");
    }
    if (!@rename($localPath . '.staging', $localPath)) {
        bailJob($db, $jobKey, "cannot rename staging for output $i");
    }
    $sz = (int)(filesize($localPath) ?: 0);
    $totalSize += $sz;
    $outputs[] = ['path' => $relPath, 'size_bytes' => $sz, 'index' => $i];
}

updateJob($db, $jobKey, [
    't2i_job_status'          => 'complete',
    't2i_job_progress'        => 100,
    't2i_job_message'         => sprintf('Done — %d image%s', $n, $n === 1 ? '' : 's'),
    't2i_job_outputs'         => json_encode($outputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    't2i_job_size_bytes'      => $totalSize,
    't2i_job_completed_dtime' => date('Y-m-d H:i:sO'),
]);
exit(0);
