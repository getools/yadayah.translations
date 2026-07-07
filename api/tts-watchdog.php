<?php
/* TTS build watchdog — CLI helper. Subcommands:
 *   check                 -> "OK ..." | "STUCK <ak> <pid> stale=<n>s" | "STALLED pending=<n>"
 *   synthtest             -> "SYNTH_OK" | "SYNTH_FAIL <err>"   (30s chatterbox probe)
 *   killreset <ak> <pid>  -> SIGKILL worker, reset chapter row to pending
 *   spawn                 -> spawn one worker for the global-first pending (series order), under the build lock
 * The host script tts-build-watchdog.sh orchestrates these + the GPU-box engine restart. */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';
require_once __DIR__ . '/spawn-helpers.php';
require_once __DIR__ . '/gpu-client.php';

const TTS_BUILD_LOCK = 742002;
const STUCK_SEC = 900;   // a 'running' chapter with no progress for 15 min = wedged

$db = getDb();
$mode = $argv[1] ?? 'check';

$activeBuilds = function () use ($db) {
    return (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio
        WHERE tts_audio_status='running' OR (tts_audio_status='pending' AND tts_audio_worker_pid IS NOT NULL)")->fetchColumn();
};
$firstPending = function () use ($db) {
    return (int)$db->query("
        SELECT a.tts_audio_key FROM yy_tts_audio a
          JOIN yy_tts t ON t.tts_key=a.tts_key
          JOIN yy_volume v ON v.volume_key=a.volume_key
          JOIN yy_series s ON s.series_key=v.series_key
     LEFT JOIN yy_chapter c ON c.chapter_key=a.chapter_key
         WHERE a.tts_audio_status='pending' AND a.tts_audio_worker_pid IS NULL
         ORDER BY s.series_number, v.volume_sort, v.volume_number,
                  c.chapter_sort NULLS FIRST, c.chapter_number NULLS FIRST, a.tts_audio_dtime ASC
         LIMIT 1")->fetchColumn();
};

if ($mode === 'check') {
    $run = $db->query("SELECT tts_audio_key ak, tts_audio_worker_pid pid, tts_audio_message msg,
        EXTRACT(EPOCH FROM (now() - GREATEST(tts_audio_revision_dtime, tts_audio_started_dtime)))::int stale
        FROM yy_tts_audio WHERE tts_audio_status='running' ORDER BY tts_audio_started_dtime LIMIT 1")->fetch();
    if ($run) {
        $stale = (int)$run['stale'];
        if ($stale > STUCK_SEC) echo "STUCK {$run['ak']} {$run['pid']} stale={$stale}s\n";
        else echo "OK running ak={$run['ak']} stale={$stale}s | {$run['msg']}\n";
    } else {
        $active = $activeBuilds();
        $pending = (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio WHERE tts_audio_status='pending'")->fetchColumn();
        if ($active === 0 && $pending > 0) echo "STALLED pending=$pending\n";
        else echo "OK idle active=$active pending=$pending\n";
    }
    exit;
}

if ($mode === 'synthtest') {
    $r = gpuSynthesize(['provider'=>'chatterbox','voice'=>'cb-craigwinn','text'=>'Watchdog test.','format'=>'mp3'], null, 30);
    echo ($r['ok'] && strlen($r['body'] ?? '') > 500) ? "SYNTH_OK\n"
        : ("SYNTH_FAIL " . ($r['error'] ?? ('status ' . ($r['status'] ?? '?'))) . "\n");
    exit;
}

if ($mode === 'killreset') {
    $ak = (int)($argv[2] ?? 0); $pid = (int)($argv[3] ?? 0);
    if ($pid > 0 && function_exists('posix_kill')) { @posix_kill($pid, 9); }
    if ($ak > 0) $db->prepare("UPDATE yy_tts_audio SET tts_audio_status='pending', tts_audio_worker_pid=NULL,
        tts_audio_message='re-queued by watchdog (GPU wedge)' WHERE tts_audio_key=?")->execute([$ak]);
    echo "killreset ak=$ak pid=$pid done\n"; exit;
}

if ($mode === 'spawn') {
    $db->query('SELECT pg_advisory_lock(' . TTS_BUILD_LOCK . ')');
    try {
        if ($activeBuilds() >= 1) { echo "noop: active build present\n"; }
        else {
            $ak = $firstPending();
            if (!$ak) { echo "noop: no pending\n"; }
            else {
                $log = sys_get_temp_dir() . '/tts_build_' . $ak . '.log';
                $pid = spawnCappedWorker(__DIR__ . '/admin-tts-build-worker.php', [(string)$ak], $log,
                    ['cpu_secs'=>3600, 'mem_mb'=>1500, 'nice'=>10]);
                if ($pid > 0) { $db->prepare("UPDATE yy_tts_audio SET tts_audio_worker_pid=? WHERE tts_audio_key=?")->execute([$pid,$ak]); echo "spawned ak=$ak pid=$pid\n"; }
                else echo "spawn_failed ak=$ak\n";
            }
        }
    } finally { $db->query('SELECT pg_advisory_unlock(' . TTS_BUILD_LOCK . ')'); }
    exit;
}
echo "usage: check|synthtest|killreset <ak> <pid>|spawn\n";
