<?php
/**
 * Detached CLI worker that renders a queued long preview (admin-tts-preview.php
 * enqueues a yy_tts_preview_job row and spawns this via setsid when a local
 * self-hosted-voice selection is too long for the synchronous ~100 s window).
 *
 *   setsid php admin-tts-preview-worker.php <job_key> &
 *
 * Renders the FULL text one whole paragraph at a time (no chunk cap, no CDN
 * clock), writes the MP3 to /tmp/tts-preview/<job_key>.mp3, and flips the job
 * to done/error. The browser polls admin-tts-preview-status.php. No auth here —
 * it's a local CLI process, not an HTTP request.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$jobKey = (int)($argv[1] ?? 0);
if (!$jobKey) { fwrite(STDERR, "usage: admin-tts-preview-worker.php <job_key>\n"); exit(1); }

$db = getDb();

// Atomically claim the job so a double-spawn can't render it twice.
$claim = $db->prepare("UPDATE yy_tts_preview_job SET job_status='rendering'
                        WHERE job_key=? AND job_status='pending' RETURNING *");
$claim->execute([$jobKey]);
$job = $claim->fetch(PDO::FETCH_ASSOC);
if (!$job) { exit(0); }   // already claimed, or gone

$markError = function (string $msg) use ($db, $jobKey) {
    try {
        $u = $db->prepare("UPDATE yy_tts_preview_job SET job_status='error', job_error=?, job_completed=now() WHERE job_key=?");
        $u->execute([mb_substr($msg, 0, 500), $jobKey]);
    } catch (\Throwable $e) {}
};

try {
    $cfg = loadTtsConfig($db, (int)$job['job_tts_key']);
    if (empty($cfg['system'])) throw new Exception('unknown tts_key ' . $job['job_tts_key']);

    $category = (string)($job['job_category'] ?: 'main');
    // Splice the chosen voice into every category so the whole utterance reads
    // in that one voice — same as the synchronous endpoint's override path.
    if (!empty($job['job_voice_code'])) {
        $overrideRow = [
            'tts_voice_code'         => (string)$job['job_voice_code'],
            'tts_voice_style'        => $job['job_style'] ?: null,
            'tts_voice_style_degree' => 1.0,
            'tts_voice_rate_pct'     => (int)$job['job_rate_pct'],
            'tts_voice_pitch_st'     => (float)$job['job_pitch_st'],
            'tts_voice_volume'       => (int)$job['job_volume'],
        ];
        foreach (array_keys($cfg['categories']) as $cc) $cfg['categories'][$cc] = $overrideRow;
        if (!isset($cfg['categories'][$category])) $cfg['categories'][$category] = $overrideRow;
    }

    $outputFormat = $cfg['system']['tts_output_format'] ?? 'audio-24khz-96kbitrate-mono-mp3';
    $err = '';
    $mp3 = localRenderPreviewParagraphs(
        $cfg, (string)$job['job_text'], $category, $outputFormat, $err,
        function ($done, $total) use ($db, $jobKey) {
            try {
                $u = $db->prepare("UPDATE yy_tts_preview_job SET job_para_done=?, job_para_total=? WHERE job_key=?");
                $u->execute([(int)$done, (int)$total, $jobKey]);
            } catch (\Throwable $e) {}
        },
        (int)($job['job_min_chars'] ?? 80),
        (int)($job['job_target_chars'] ?? 150),
        (int)($job['job_max_chars'] ?? 250)
    );
    if ($mp3 === '') throw new Exception($err ?: 'engine returned no audio');

    $path = ttsPreviewJobMp3Path($jobKey);
    if (@file_put_contents($path, $mp3) === false) throw new Exception('could not write ' . $path);

    $u = $db->prepare("UPDATE yy_tts_preview_job SET job_status='done', job_completed=now() WHERE job_key=?");
    $u->execute([$jobKey]);
} catch (\Throwable $e) {
    error_log('admin-tts-preview-worker job ' . $jobKey . ' failed: ' . $e->getMessage());
    $markError($e->getMessage());
}
