<?php
/**
 * CLI helper to finalize a recording bypassing the Apache/Caddy HTTP timeout.
 *
 * Mirrors the `action=finalize` block in admin-transcript-upload.php, but runs
 * from the shell so long encodes (multi-hour videos) aren't killed by upstream
 * timeouts.
 *
 * Usage:
 *   docker exec -u www-data yada-www-web-1 \
 *     php /var/www/html/api/cli-finalize.php <item_key> [--auto-transcribe] [--user-key=N]
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$itemKey = (int)($argv[1] ?? 0);
if (!$itemKey) {
    fwrite(STDERR, "usage: cli-finalize.php <item_key> [--auto-transcribe] [--user-key=N]\n");
    exit(2);
}
$autoTranscribe = in_array('--auto-transcribe', $argv, true);
$userKey = 2;
foreach ($argv as $a) {
    if (preg_match('/^--user-key=(\d+)$/', $a, $m)) $userKey = (int)$m[1];
}

require_once __DIR__ . '/config.php';
$db = getDb();

$AUDIO_DIR_ABS = dirname(__DIR__) . '/u/audio';
$AUDIO_DIR_REL = 'u/audio';
$PARTS_DIR_ABS = $AUDIO_DIR_ABS . '/parts';

$parts = glob("$PARTS_DIR_ABS/audio_{$itemKey}_part_*.webm");
if (!$parts) {
    fwrite(STDERR, "no parts for item $itemKey\n");
    exit(1);
}
usort($parts, function ($a, $b) {
    preg_match('/_part_(\d+)\.webm$/', $a, $am);
    preg_match('/_part_(\d+)\.webm$/', $b, $bm);
    return ((int)($am[1] ?? 0)) - ((int)($bm[1] ?? 0));
});
echo "[" . date('c') . "] found " . count($parts) . " parts for item $itemKey\n";

// Probe each part. NEVER unlink during validation — we need every byte if
// byte-concat recovery turns out to be the right strategy.
function probe_duration(string $f): float {
    $cmd = 'ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($f) . ' 2>/dev/null';
    $out = trim(shell_exec($cmd) ?: '');
    return is_numeric($out) ? (float)$out : 0.0;
}

$valid = [];
$invalid = [];
foreach ($parts as $f) {
    $d = probe_duration($f);
    if ($d > 0.5) $valid[] = $f;
    else $invalid[] = $f;
}
echo "valid (independently probable): " . count($valid) . " / " . count($parts) . "\n";

$stamp = time();
$finalRel = "$AUDIO_DIR_REL/audio_{$itemKey}_{$stamp}.mp3";
$finalAbs = "$AUDIO_DIR_ABS/audio_{$itemKey}_{$stamp}.mp3";

// Strategy selection (mirrors admin-transcript-upload.php finalize):
//   1. all parts probable -> concat-filter on individual files
//   2. some/all fail -> byte-concat recovery (cat all parts to merged.webm)
//   3. recovery fails -> use whatever validates; bail if nothing
$mergedTmp = null;
$encodeInputs = null;
$strategy = '';
if (count($valid) === count($parts)) {
    $encodeInputs = $valid;
    $strategy = 'concat-filter (' . count($valid) . ' independently-valid parts)';
} else {
    $mergedTmp = "$PARTS_DIR_ABS/merged_{$itemKey}_{$stamp}.webm";
    $fpOut = @fopen($mergedTmp, 'wb');
    if ($fpOut) {
        foreach ($parts as $f) {
            $fpIn = @fopen($f, 'rb');
            if ($fpIn) { stream_copy_to_stream($fpIn, $fpOut); fclose($fpIn); }
        }
        fclose($fpOut);
    }
    $mergedDur = is_file($mergedTmp) ? probe_duration($mergedTmp) : 0;
    if ($mergedDur > 0.5) {
        $encodeInputs = [$mergedTmp];
        $strategy = 'byte-concat-recovery (' . count($parts) . ' parts -> '
                  . round(filesize($mergedTmp)/1024/1024, 2) . ' MB merged.webm, '
                  . round($mergedDur) . 's)';
    } else {
        @unlink($mergedTmp);
        $mergedTmp = null;
        if (!$valid) {
            fwrite(STDERR, "no usable parts; byte-concat recovery also produced an unprobable file. Parts kept on disk.\n");
            exit(1);
        }
        $encodeInputs = $valid;
        $strategy = 'partial (' . count($valid) . '/' . count($parts) . ' valid; recovery failed)';
    }
}
echo "strategy: $strategy\n";

$inputArgs = '';
$filterIn = '';
foreach (array_values($encodeInputs) as $i => $f) {
    $inputArgs .= ' -i ' . escapeshellarg($f);
    $filterIn .= "[{$i}:a]";
}
$filter = $filterIn . 'concat=n=' . count($encodeInputs) . ':v=0:a=1[out]';
$cmd = 'ffmpeg -y' . $inputArgs
     . ' -filter_complex ' . escapeshellarg($filter)
     . ' -map ' . escapeshellarg('[out]')
     . ' -codec:a libmp3lame -qscale:a 4 -ar 44100 -ac 2 '
     . escapeshellarg($finalAbs) . ' 2>&1';

echo "[" . date('c') . "] encoding -> $finalRel\n";
$start = time();
$out = shell_exec($cmd);
$elapsed = time() - $start;
if (!file_exists($finalAbs) || filesize($finalAbs) < 1000) {
    if ($mergedTmp && is_file($mergedTmp)) @unlink($mergedTmp);
    fwrite(STDERR, "ffmpeg encode failed (ran {$elapsed}s, strategy: $strategy):\n"
                 . substr(trim($out ?? ''), -2000) . "\nOriginal parts kept on disk.\n");
    exit(1);
}
@chmod($finalAbs, 0664);

$probedDur = probe_duration($finalAbs);
echo "[" . date('c') . "] encode ok in {$elapsed}s -> " . round(filesize($finalAbs) / 1024 / 1024, 2)
     . " MB, " . round($probedDur, 1) . "s (" . gmdate('H:i:s', (int)$probedDur) . ")\n";

// Remove any prior MP3 the DB pointed to.
$prevStmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
$prevStmt->execute([$itemKey]);
$prevFile = $prevStmt->fetchColumn();
if ($prevFile) {
    $prevAbs = dirname(__DIR__) . '/' . ltrim($prevFile, '/');
    if (is_file($prevAbs)) {
        @unlink($prevAbs);
        echo "removed prior MP3: $prevFile\n";
    }
}

$db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ?, feed_item_audio_resume_seconds = NULL WHERE feed_item_key = ?")
   ->execute([$finalRel, $itemKey]);
echo "DB updated: feed_item_audio_file = $finalRel\n";

// Now safe to drop originals + any byte-concat temp file.
$dropped = 0;
foreach ($parts as $f) {
    if (@unlink($f)) $dropped++;
}
if ($mergedTmp && is_file($mergedTmp)) @unlink($mergedTmp);
echo "deleted $dropped part files\n";

if ($autoTranscribe) {
    $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'cancelled', job_completed_dtime = NOW() WHERE feed_item_key = ? AND job_status IN ('pending', 'running')")
       ->execute([$itemKey]);
    $delStmt = $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ?");
    $delStmt->execute([$itemKey]);
    echo "cleared " . $delStmt->rowCount() . " stale transcript rows\n";

    // Full self-hosted baseline suite + opt-in → amalgamated (consensus L1-L4)
    // transcript auto-builds once the baselines land (maybeAutoBuildEditable).
    require_once __DIR__ . '/transcript-helpers.php';   // autoEnqueueBaselineSuite()
    $jobKey = autoEnqueueBaselineSuite($db, $itemKey, $userKey);
    echo "queued baseline suite (first job #$jobKey)\n";

    $worker = __DIR__ . '/transcript-worker.php';
    if ($jobKey && file_exists($worker)) {
        require_once __DIR__ . '/spawn-helpers.php';
        $db->query('SELECT pg_advisory_lock(742001)');
        try {
            $running = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status = 'running'")->fetchColumn();
            if ($running === 0) {
                $logFile = sys_get_temp_dir() . "/transcript_$jobKey.log";
                $pid = spawnCappedWorker($worker, [(string)$jobKey], $logFile, [
                    'cpu_secs' => 2400, 'mem_mb' => 2000, 'nice' => 10,
                ]);
                if ($pid > 0) {
                    $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'running', job_worker_pid = ? WHERE feed_item_transcript_job_key = ? AND job_status = 'pending'")
                       ->execute([$pid, $jobKey]);
                    echo "worker pid $pid launched (log: $logFile)\n";
                } else {
                    echo "WARN: worker launch returned no pid\n";
                }
            } else {
                echo "worker already running — suite will drain in queue\n";
            }
        } finally {
            $db->query('SELECT pg_advisory_unlock(742001)');
        }
    }
}

echo "[" . date('c') . "] done.\n";
