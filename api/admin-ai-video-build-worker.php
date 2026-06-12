<?php
/**
 * AI generation Video build worker (yy_i2v_job).
 *
 * Spawned per yy_i2v_job by admin-ai-video.php. POSTs multipart to the
 * provider's /generate, polls for progress, downloads the finished MP4 to
 * /public/u/i2v-videos/i2v_<job_key>.mp4 .
 *
 * Mirrors admin-tts-build-worker's shape: queue-promotion shutdown hook,
 * cancellation re-checks, dual host/container paths, ffprobe duration.
 *
 * Usage:  php admin-ai-video-build-worker.php <i2v_job_key>
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';

$jobKey = (int)($argv[1] ?? 0);
if (!$jobKey) {
    fwrite(STDERR, "i2v_job_key required\n");
    exit(2);
}

$db = getDb();

// ── Queue promotion ───────────────────────────────────────────────────
// Single GPU; single concurrent build. When this worker exits (success,
// failure, OOM), promote the next pending row.
$MAX_CONCURRENT = 1;
register_shutdown_function(function() use (&$db, $MAX_CONCURRENT) {
    try {
        if (!$db) $db = getDb();
        $running = (int)$db->query("SELECT COUNT(*) FROM yy_i2v_job WHERE i2v_job_status='running'")->fetchColumn();
        if ($running >= $MAX_CONCURRENT) return;
        $next = (int)$db->query("
          SELECT i2v_job_key FROM yy_i2v_job
           WHERE i2v_job_status='pending' AND i2v_job_worker_pid IS NULL
           ORDER BY i2v_job_dtime ASC LIMIT 1
        ")->fetchColumn();
        if (!$next) return;
        $logFile = sys_get_temp_dir() . '/ai_video_build_' . $next . '.log';
        $pid = spawnCappedWorker(__FILE__, [(string)$next], $logFile, [
            'cpu_secs' => 7200, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_i2v_job SET i2v_job_worker_pid=? WHERE i2v_job_key=?")
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
    $db->prepare("UPDATE yy_i2v_job SET " . implode(', ', $set) . ", i2v_job_revision_dtime=NOW() WHERE i2v_job_key=?")
       ->execute($params);
}

function bailJob(PDO $db, int $k, string $err): void {
    $short = trim(substr(str_replace(["\r", "\n"], ' ', $err), 0, 240));
    updateJob($db, $k, [
        'i2v_job_status'          => 'failed',
        'i2v_job_message'         => 'Failed: ' . $short,
        'i2v_job_error'           => $err,
        'i2v_job_completed_dtime' => date('Y-m-d H:i:sO'),
    ]);
    fwrite(STDERR, "FAIL: $err\n");
    exit(1);
}

// Load the job.
$row = $db->prepare("SELECT * FROM yy_i2v_job WHERE i2v_job_key=?");
$row->execute([$jobKey]);
$job = $row->fetch(PDO::FETCH_ASSOC);
if (!$job) { fwrite(STDERR, "yy_i2v_job row $jobKey missing\n"); exit(2); }

// Load provider.
$pStmt = $db->prepare("SELECT * FROM yy_provider WHERE provider_key=?");
$pStmt->execute([(int)$job['provider_key']]);
$prov = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$prov) bailJob($db, $jobKey, "provider missing");

$settings   = is_string($prov['provider_settings']) ? (json_decode($prov['provider_settings'], true) ?: []) : ($prov['provider_settings'] ?: []);
$endpoint   = rtrim((string)($prov['provider_endpoint'] ?? ''), '/');
$engineCode = $settings['engine'] ?? $prov['provider_model_id'];
if ($endpoint === '') bailJob($db, $jobKey, "provider has no endpoint");

updateJob($db, $jobKey, [
    'i2v_job_status'  => 'running',
    'i2v_job_message' => 'Submitting to engine',
    'i2v_job_progress'=> 1,
]);

// Resolve input image files (dual host/container path).
$inputImages = is_string($job['i2v_job_input_images']) ? (json_decode($job['i2v_job_input_images'], true) ?: []) : ($job['i2v_job_input_images'] ?: []);
if (!$inputImages) bailJob($db, $jobKey, "no input images recorded");

$hostBase = '/opt/yada-www/public';
$contBase = dirname(__DIR__);
$fsBase   = is_dir($contBase) ? $contBase : $hostBase;
$resolved = [];
foreach ($inputImages as $img) {
    $rel = (string)($img['path'] ?? '');
    if ($rel === '') continue;
    $abs = $fsBase . $rel;
    if (!is_file($abs)) bailJob($db, $jobKey, "input image missing: $rel");
    $resolved[] = $abs;
}
if (!$resolved) bailJob($db, $jobKey, "no readable input files");

// Submit to engine /generate as multipart.
$ch = curl_init($endpoint . '/generate');
$post = [
    'provider' => (string)$engineCode,
    'prompt'   => (string)$job['i2v_job_prompt'],
    'params'   => is_string($job['i2v_job_params']) ? $job['i2v_job_params'] : json_encode($job['i2v_job_params'] ?: new stdClass()),
];
if (!empty($job['i2v_job_negative_prompt'])) {
    $post['negative_prompt'] = $job['i2v_job_negative_prompt'];
}
foreach ($resolved as $i => $abs) {
    $post["images[$i]"] = new CURLFile($abs);
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
if (!$remoteJobId) bailJob($db, $jobKey, "engine did not return job_id");

updateJob($db, $jobKey, ['i2v_job_message' => 'Engine running', 'i2v_job_progress' => 5]);

// Poll for progress. 2-hour ceiling; re-check cancellation every loop.
$deadline = time() + 7200;
while (time() < $deadline) {
    $cstmt = $db->prepare("SELECT count(*) FROM yy_i2v_job WHERE i2v_job_key=? AND i2v_job_status='cancelled'");
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
    if ($st === 'complete') break;
    if ($st === 'running' || $st === 'pending') {
        // Preserve engine-reported fractional progress (column is
        // numeric(5,2) so values like 12.7 survive instead of being
        // truncated to 12). Cap with float arithmetic, not (int).
        $progRaw = (float)($j['progress'] ?? 5);
        $prog = max(5.0, min(95.0, $progRaw));
        $update = [
            'i2v_job_progress' => $prog,
            'i2v_job_message'  => (string)($j['message'] ?? 'Generating'),
        ];
        // Engine may also report eta_seconds / phase / step / total — pull
        // them into the message when present so the UI can show actionable
        // detail without a schema change per field.
        $extras = [];
        if (isset($j['eta_seconds']) && (int)$j['eta_seconds'] > 0) {
            $eta = (int)$j['eta_seconds'];
            $mm = intdiv($eta, 60); $ss = $eta % 60;
            $extras[] = 'ETA ~' . ($mm > 0 ? "{$mm}m {$ss}s" : "{$ss}s");
        }
        if (!empty($j['phase']))             $extras[] = 'phase=' . (string)$j['phase'];
        if (isset($j['step'], $j['total']))  $extras[] = "step {$j['step']}/{$j['total']}";
        if ($extras) {
            $update['i2v_job_message'] = $update['i2v_job_message'] . ' · ' . implode(' · ', $extras);
        }
        updateJob($db, $jobKey, $update);
    }
    sleep(3);
}

// Download the MP4 to a .staging sibling then atomically rename.
$videosHost = '/opt/yada-www/public/u/i2v-videos';
$videosCont = dirname(__DIR__) . '/u/i2v-videos';
$videosDir  = is_dir($contBase) ? $videosCont : $videosHost;
if (!is_dir($videosDir) && !@mkdir($videosDir, 0775, true) && !is_dir($videosDir)) {
    bailJob($db, $jobKey, "cannot create $videosDir");
}
$outName = sprintf('i2v_%d.mp4', $jobKey);
$outAbs  = $videosDir . '/' . $outName;
$outRel  = '/u/i2v-videos/' . $outName;

$fh = fopen($outAbs . '.staging', 'wb');
if (!$fh) bailJob($db, $jobKey, "cannot open $outAbs.staging");
$ch = curl_init($endpoint . '/jobs/' . rawurlencode($remoteJobId) . '/file');
// Surface the in-flight download as i2v_job_size_bytes (already a real
// schema column the UI reads in the expanded detail block). The UI polls
// list_jobs every 4 s, so we only need to flush on the first byte and
// every ~1 s after — anything more is wasted DB load.
$lastFlush = 0.0;
$progressCb = function ($curl, $dltot, $dlnow, $ultot, $ulnow) use (&$lastFlush, $db, $jobKey) {
    $now = microtime(true);
    if ($dlnow > 0 && ($now - $lastFlush) >= 1.0) {
        $lastFlush = $now;
        $totalKnown = $dltot > 0 ? (int)$dltot : 0;
        $bytesNow   = (int)$dlnow;
        $pct = ($totalKnown > 0) ? (96.0 + min(3.9, ($bytesNow / $totalKnown) * 3.9)) : 96.0;
        $msg = $totalKnown > 0
            ? sprintf('Downloading MP4 — %.1f MB / %.1f MB', $bytesNow / 1048576.0, $totalKnown / 1048576.0)
            : sprintf('Downloading MP4 — %.1f MB',           $bytesNow / 1048576.0);
        try {
            updateJob($db, $jobKey, [
                'i2v_job_size_bytes' => $bytesNow,
                'i2v_job_progress'   => $pct,
                'i2v_job_message'    => $msg,
            ]);
        } catch (Throwable $e) { /* db hiccup — don't abort the download */ }
    }
    return 0; // continue
};
curl_setopt_array($ch, [
    CURLOPT_FILE              => $fh,
    CURLOPT_TIMEOUT           => 600,
    CURLOPT_NOPROGRESS        => false,
    CURLOPT_XFERINFOFUNCTION  => $progressCb,
]);
$ok   = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
fclose($fh);
if (!$ok || $code >= 400) {
    @unlink($outAbs . '.staging');
    bailJob($db, $jobKey, "download HTTP $code");
}
if (!@rename($outAbs . '.staging', $outAbs)) {
    bailJob($db, $jobKey, "cannot rename staging into place");
}

// Probe duration with ffprobe if available.
$duration = null;
$ffprobe = trim((string)shell_exec('which ffprobe 2>/dev/null'));
if ($ffprobe !== '') {
    $out = shell_exec(escapeshellcmd($ffprobe) . ' -v error -show_entries format=duration -of default=nokey=1:noprint_wrappers=1 ' . escapeshellarg($outAbs) . ' 2>/dev/null');
    if ($out !== null && trim((string)$out) !== '') $duration = (float)trim((string)$out);
}

updateJob($db, $jobKey, [
    'i2v_job_status'          => 'complete',
    'i2v_job_progress'        => 100,
    'i2v_job_message'         => 'Done',
    'i2v_job_output_path'     => $outRel,
    'i2v_job_duration_secs'   => $duration,
    'i2v_job_size_bytes'      => filesize($outAbs) ?: null,
    'i2v_job_completed_dtime' => date('Y-m-d H:i:sO'),
]);
exit(0);
