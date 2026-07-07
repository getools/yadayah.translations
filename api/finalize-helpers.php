<?php
/**
 * Shared helpers for transcript upload + the finalize worker.
 *
 * Originally these lived inline in admin-transcript-upload.php. Extracted so
 * the new async finalize worker (finalize-worker.php) can reuse them without
 * dragging in the HTTP request-handling code.
 */

if (!function_exists('existingCaptionFiles')) {
    function existingCaptionFiles(string $dir, int $itemKey): array {
        $files = [];
        foreach (glob("$dir/{$itemKey}.*") as $f) {
            if (is_file($f)) {
                $files[] = [
                    'name' => basename($f),
                    'size' => filesize($f),
                    'modified_dtime' => date('c', filemtime($f)),
                    'extension' => strtolower(pathinfo($f, PATHINFO_EXTENSION)),
                ];
            }
        }
        return $files;
    }
}

if (!function_exists('durableAudioInfo')) {
    function durableAudioInfo(PDO $db, int $itemKey): ?array {
        $stmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
        $stmt->execute([$itemKey]);
        $rel = $stmt->fetchColumn();
        if (!$rel) return null;
        $abs = dirname(__DIR__) . '/' . ltrim($rel, '/');
        if (!is_file($abs)) return ['path' => $rel, 'missing' => true];
        return [
            'path' => $rel,
            'size' => filesize($abs),
            'modified_dtime' => date('c', filemtime($abs)),
            'extension' => strtolower(pathinfo($abs, PATHINFO_EXTENSION)),
        ];
    }
}

if (!function_exists('validateAudioFile')) {
    /**
     * Validate an audio file using ffprobe. Returns:
     *   ['ok' => true,  'duration' => float, 'mean_db' => float|null]
     *   ['ok' => false, 'reason' => string]
     *
     * $allowSilent — when true, the -70 dB mean-volume rejection is skipped.
     * Used for items flagged feed_item_allow_silent_recording = TRUE; the
     * container-corrupt and no-audio-stream checks still run.
     */
    function validateAudioFile(string $absPath, bool $allowSilent = false): array {
        if (!is_file($absPath) || filesize($absPath) < 256) {
            return ['ok' => false, 'reason' => 'file too small (' . (is_file($absPath) ? filesize($absPath) : 0) . ' bytes)'];
        }
        $ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?: '');
        if (!$ffprobe) {
            return ['ok' => true, 'duration' => 0.0, 'mean_db' => null];
        }
        $cmd = escapeshellcmd($ffprobe)
             . ' -v error -select_streams a:0 -show_entries stream=codec_type,duration'
             . ' -of default=noprint_wrappers=1:nokey=1 '
             . escapeshellarg($absPath) . ' 2>&1';
        $out = trim(shell_exec($cmd) ?: '');
        if (stripos($out, 'audio') === false) {
            return ['ok' => false, 'reason' => 'no audio stream: ' . substr($out, 0, 200)];
        }
        $lines = preg_split('/\r?\n/', $out);
        $duration = 0.0;
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l !== '' && $l !== 'audio' && is_numeric($l)) { $duration = (float)$l; break; }
        }
        $ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
        $meanDb = null;
        if ($ffmpeg) {
            $cmd2 = escapeshellcmd($ffmpeg) . ' -nostdin -i ' . escapeshellarg($absPath)
                  . ' -af volumedetect -vn -sn -dn -f null - 2>&1';
            $out2 = shell_exec($cmd2) ?: '';
            if (preg_match('/mean_volume:\s*(-?\d+(?:\.\d+)?)\s*dB/', $out2, $m)) {
                $meanDb = (float)$m[1];
            }
        }
        if (!$allowSilent && $meanDb !== null && $meanDb < -70.0) {
            return ['ok' => false, 'reason' => 'silent recording (mean ' . $meanDb . ' dB) — tab share probably did not capture audio'];
        }
        return ['ok' => true, 'duration' => $duration, 'mean_db' => $meanDb];
    }
}

if (!function_exists('listParts')) {
    function listParts(string $dir, int $itemKey): array {
        $parts = [];
        foreach (glob("$dir/audio_{$itemKey}_part_*.{webm,mp3,m4a,opus,wav,ogg,aac}", GLOB_BRACE) as $f) {
            if (preg_match('/_part_(\d+)\.[a-z0-9]+$/i', $f, $m)) {
                $parts[(int)$m[1]] = $f;
            }
        }
        ksort($parts);
        return $parts;
    }
}

if (!function_exists('partialState')) {
    function partialState(PDO $db, string $partsDir, int $itemKey): array {
        $stmt = $db->prepare("SELECT feed_item_audio_resume_seconds FROM yy_feed_item WHERE feed_item_key = ?");
        $stmt->execute([$itemKey]);
        $resume = (int)($stmt->fetchColumn() ?: 0);
        $parts = listParts($partsDir, $itemKey);
        return [
            'resume_seconds' => $resume,
            'part_count'     => count($parts),
            'parts_size'     => array_sum(array_map(fn($f) => is_file($f) ? filesize($f) : 0, $parts)),
        ];
    }
}

// Kill-switch for auto-transcription after MP3 upload / finalize. Set to
// true to suppress the auto-queue of transcript-worker.php so freshly
// uploaded MP3s are NOT immediately sent to the OpenAI Whisper API.
// The "Transcribe Audio" button on admin-feeds still works for on-demand
// runs; only the implicit MP3-finalized / MP3-uploaded trigger is paused.
//
// Disabled 2026-05-11 while the OpenAI quota/cost strategy was reviewed — back
// then this fired the PAID whisper-1 engine. Re-enabled 2026-07-04: new items
// now generate the FREE self-hosted GPU baseline suite and auto-build an
// AMALGAMATED (consensus L1-L4) transcript. Flip to true to kill-switch it.
if (!defined('AUTO_TRANSCRIBE_DISABLED')) define('AUTO_TRANSCRIBE_DISABLED', false);

if (!function_exists('spawnTranscribeJob')) {
    function spawnTranscribeJob(PDO $db, int $itemKey, int $userKey): ?int {
        if (AUTO_TRANSCRIBE_DISABLED) {
            // Note in monitor_event so it's visible in admin-monitoring that
            // the trigger fired but was suppressed — no silent disappearance.
            if (function_exists('logMonitorEvent')) {
                @logMonitorEvent('transcript_upload', 'info',
                    'Auto-transcribe suppressed (kill-switch on)',
                    'item_key=' . $itemKey . ' — flip AUTO_TRANSCRIBE_DISABLED to false in finalize-helpers.php to re-enable.');
            }
            return null;
        }
        // New item → full self-hosted baseline suite + opt-in. The consensus
        // (L1-L4 amalgamation) auto-builds once the last baseline lands
        // (maybeAutoBuildEditable in transcript-worker.php). Supersede handled
        // per-model inside the helper.
        require_once __DIR__ . '/transcript-helpers.php';   // autoEnqueueBaselineSuite()
        $jobKey = autoEnqueueBaselineSuite($db, $itemKey, $userKey);
        if (!$jobKey) return null;
        // Kick the single STT worker on the first baseline (it chains the rest
        // via its priority-aware shutdown-dequeue). Guarded by the same advisory
        // lock (742001) the worker/dispatch use, so we can never start a second.
        $workerScript = __DIR__ . '/transcript-worker.php';
        if (file_exists($workerScript)) {
            require_once __DIR__ . '/spawn-helpers.php';
            $db->query('SELECT pg_advisory_lock(742001)');
            try {
                $running = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status = 'running'")->fetchColumn();
                if ($running === 0) {
                    $logFile = sys_get_temp_dir() . '/transcript_' . $jobKey . '.log';
                    $pid = spawnCappedWorker($workerScript, [(string)$jobKey], $logFile, [
                        'cpu_secs' => 2400, 'mem_mb' => 2000, 'nice' => 10,
                    ]);
                    if ($pid > 0) {
                        $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'running', job_worker_pid = ? WHERE feed_item_transcript_job_key = ? AND job_status = 'pending'")
                           ->execute([$pid, $jobKey]);
                    }
                }
            } finally {
                $db->query('SELECT pg_advisory_unlock(742001)');
            }
        }
        return $jobKey;
    }
}

/**
 * Path of the JSON state file for a finalize job. Worker writes; the HTTP
 * progress endpoint reads. Intentionally a flat, atomic-rename JSON file —
 * no DB row to worry about cleaning up if the worker crashes mid-encode.
 */
if (!function_exists('finalizeStatePath')) {
    function finalizeStatePath(int $itemKey): string {
        return sys_get_temp_dir() . '/finalize_' . $itemKey . '.state';
    }
}

if (!function_exists('writeFinalizeState')) {
    function writeFinalizeState(int $itemKey, array $patch): array {
        $path = finalizeStatePath($itemKey);
        $cur = [];
        if (is_file($path)) {
            $cur = json_decode((string)@file_get_contents($path), true) ?: [];
        }
        $cur = array_merge($cur, $patch, ['updated' => date('c')]);
        $tmp = $path . '.tmp';
        @file_put_contents($tmp, json_encode($cur, JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
        @chmod($path, 0664);
        return $cur;
    }
}

if (!function_exists('readFinalizeState')) {
    function readFinalizeState(int $itemKey): ?array {
        $path = finalizeStatePath($itemKey);
        if (!is_file($path)) return null;
        return json_decode((string)@file_get_contents($path), true) ?: null;
    }
}
