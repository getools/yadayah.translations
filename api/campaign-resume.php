<?php
// One-shot campaign resumer. The transcript baseline+edit campaign was HELD on
// 2026-07-04 so the priority-1 "ready" rebuilds (existing items that already had
// baselines) could go first. This un-holds it once those rebuilds have drained,
// then kicks the single STT + init workers. Prints "RESUMED ..." on success so
// the host watchdog can self-deactivate. Idempotent: acts only on 'held' rows.
require_once __DIR__ . '/config.php';
$db = getDb();
$pending = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_init_job
                             WHERE job_status IN ('pending','running') AND COALESCE(job_priority,0)>=1")->fetchColumn();
if ($pending > 0) { fwrite(STDOUT, date('c') . " waiting: $pending priority-1 ready rebuild(s) still in flight\n"); exit(0); }
$heldInit = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_init_job WHERE job_status='held'")->fetchColumn();
$heldStt  = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status='held'")->fetchColumn();
if ($heldInit === 0 && $heldStt === 0) { fwrite(STDOUT, date('c') . " nothing held — already resumed\n"); fwrite(STDOUT, "RESUMED (noop)\n"); exit(0); }
$i = $db->exec("UPDATE yy_feed_item_transcript_init_job SET job_status='pending', job_error=NULL WHERE job_status='held'");
$s = $db->exec("UPDATE yy_feed_item_transcript_job SET job_status='pending', job_error=NULL WHERE job_status='held'");
require_once __DIR__ . '/transcript-helpers.php';
require_once __DIR__ . '/spawn-helpers.php';
spawnNextInitWorker($db);
$db->query('SELECT pg_advisory_lock(742001)');
try {
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status='running'")->fetchColumn();
    if ($running === 0) {
        $next = $db->query("SELECT feed_item_transcript_job_key FROM yy_feed_item_transcript_job
                             WHERE job_status='pending' ORDER BY job_priority DESC, feed_item_transcript_job_key LIMIT 1")->fetchColumn();
        if ($next) {
            $log = sys_get_temp_dir() . '/transcript_' . $next . '.log';
            $pid = spawnCappedWorker(__DIR__ . '/transcript-worker.php', [(string)$next], $log, ['cpu_secs'=>2400,'mem_mb'=>2000,'nice'=>10]);
            if ($pid > 0) $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status='running', job_worker_pid=? WHERE feed_item_transcript_job_key=? AND job_status='pending'")->execute([$pid,$next]);
        }
    }
} finally { $db->query('SELECT pg_advisory_unlock(742001)'); }
fwrite(STDOUT, date('c') . " RESUMED campaign: un-held init=$i stt=$s; workers kicked\n");
