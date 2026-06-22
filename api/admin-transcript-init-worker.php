<?php
/**
 * Detached CLI worker for "Initialize Transcript".
 *
 *   setsid php admin-transcript-init-worker.php <job_key> &
 *
 * admin-transcript-init.php enqueues a yy_feed_item_transcript_init_job row and
 * spawns this worker so the (heavy, ~60-230s on long episodes) promote runs
 * outside Cloudflare's ~100s proxy window. The work is identical to the old
 * synchronous endpoint: (re)assemble the hybrid join if needed, apply the
 * correction dictionary across rows, and replace the live transcript — using
 * the batched/trigger-skip fast path (see transcript-helpers.php). On finish it
 * flips the job to done/error and pg_notify()s 'transcript_init_<job_key>' so
 * admin-transcript-init-sse.php can push the result to the browser.
 *
 * No auth here — it's a local CLI process, not an HTTP request.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php'; // applyCorrectionsAcrossRows, buildWhisperWordJoin, batchInsertRows

$jobKey = (int)($argv[1] ?? 0);
if (!$jobKey) { fwrite(STDERR, "usage: admin-transcript-init-worker.php <job_key>\n"); exit(1); }

$db = getDb();
// CLI worker is immune to the HTTP clock; don't let the request-scoped 120s
// statement_timeout kill a long batched insert on a very long episode.
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

// Atomically claim the job so a double-spawn can't run it twice.
$claim = $db->prepare("UPDATE yy_feed_item_transcript_init_job SET job_status='running'
                        WHERE job_key=? AND job_status='pending' RETURNING *");
$claim->execute([$jobKey]);
$job = $claim->fetch();
if (!$job) { exit(0); }   // already claimed, or gone

$itemKey = (int)$job['job_item_key'];
$model   = (string)$job['job_model'];
$userKey = (int)$job['job_user_key'];

$notify = function (string $payload) use ($db, $jobKey) {
    try { $db->prepare("SELECT pg_notify(?, ?)")->execute(['transcript_init_' . $jobKey, $payload]); }
    catch (\Throwable $e) {}
};
$fail = function (string $msg) use ($db, $jobKey, $notify) {
    try {
        $db->prepare("UPDATE yy_feed_item_transcript_init_job SET job_status='error', job_error=?, job_completed=now() WHERE job_key=?")
           ->execute([mb_substr($msg, 0, 500), $jobKey]);
    } catch (\Throwable $e) {}
    $notify('error');
};

try {
    if ($userKey) { setCurrentUser($db, $userKey); }

    // ── Consensus mode: majority vote across several baselines, then re-flow
    //    into editable rows by the supplied caption params (see init endpoint). ──
    if ($model === 'consensus') {
        require_once __DIR__ . '/transcript-compare-lib.php';
        require_once __DIR__ . '/transcript-caption-lib.php';
        $jp = json_decode((string)($job['job_params'] ?? ''), true) ?: [];
        $baselines = array_values(array_filter((array)($jp['baselines'] ?? [])));
        $params    = (array)($jp['params'] ?? []);
        if (!$baselines) throw new Exception('consensus: no baselines supplied');
        // Timing spine = best word-level baseline among those checked.
        $pref = ['gpu-whisperx-word','gpu-whisper-large-v3-word','gpu-whisper-large-v3-turbo-word','gpu-parakeet-tdt-0.6b-v2-word'];
        $spine = null;
        foreach ($pref as $c) if (in_array($c, $baselines, true)) { $spine = $c; break; }
        if (!$spine) foreach ($baselines as $c) if (strpos($c, '-word') !== false) { $spine = $c; break; }
        if (!$spine) $spine = $baselines[0];
        $refs = array_values(array_diff($baselines, [$spine]));
        $cmp = buildComparison($db, $itemKey, $spine, $refs);
        if (isset($cmp['error'])) throw new Exception('consensus: ' . $cmp['error']);
        $stream = [];
        foreach ($cmp['slots'] as $sl) { $w = trim((string)$sl['consensus']); if ($w !== '') $stream[] = ['t' => $sl['t'], 'w' => $w]; }
        if (!$stream) throw new Exception('consensus: empty word stream');
        $opts = [
            'max_chars'   => (int)($params['max_chars'] ?? 42),
            'max_lines'   => (int)($params['max_lines'] ?? 2),
            'max_secs'    => (float)($params['max_secs'] ?? 7.0),
            'min_secs'    => (float)($params['min_secs'] ?? 1.2),
            'break_punct' => array_key_exists('break_punct', $params) ? (bool)$params['break_punct'] : true,
            'break_gap'   => (float)($params['break_gap'] ?? 0),
            'dedup'       => array_key_exists('dedup', $params) ? (bool)$params['dedup'] : true,
        ];
        if (!empty($params['use_boundaries'])) {
            $bset = [];
            foreach ($baselines as $bcode) {
                $bs = $db->prepare("SELECT feed_item_transcript_segment::text AS seg FROM yy_feed_item_transcript_auto WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ?");
                $bs->execute([$itemKey, $bcode]);
                foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $br) $bset[] = round(cfIntervalToSecs($br['seg']), 2);
            }
            $bset = array_values(array_unique($bset)); sort($bset);
            $opts['boundary_set'] = $bset;
        }
        $cues = cfReflow($stream, $opts);
        if (!$cues) throw new Exception('consensus: re-flow produced no cues');
        $crows = [];
        foreach ($cues as $cue) {
            $crows[] = ['segment' => cfSecsToInterval((float)$cue['start']),
                        'text'    => str_replace("\n", ' ', (string)$cue['text'])];
        }
        $cleanedRows = applyCorrectionsAcrossRows($db, $crows);
    } else {
    // Derived hybrid models are assembled fresh from the current source feeds
    // every run, so a stale join can never reach the live transcript.
    if ($model === 'whisper-1-word-join' || $model === 'whisper-1-word-join-seg') {
        $useSeg = ($model === 'whisper-1-word-join-seg');
        $built = buildWhisperWordJoin($db, $itemKey, 'youtube', $useSeg);
        if ($built === 0) {
            $need = 'a word-level whisper + YouTube' . ($useSeg ? ' + a segment-level whisper' : '');
            throw new Exception('Cannot assemble ' . $model . ' — generate ' . $need . ' first.');
        }
    }

    $srcStmt = $db->prepare("
        SELECT feed_item_transcript_segment::text AS segment,
               feed_item_transcript_text          AS text,
               feed_item_transcript_sort          AS sort
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ?
           AND feed_item_transcript_auto_model = ?
         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
    ");
    $srcStmt->execute([$itemKey, $model]);
    $rows = $srcStmt->fetchAll();
    if (!$rows) throw new Exception('No rows in _auto for item=' . $itemKey . ' model=' . $model);

    // YadaYah enhancement: correction dictionary across the full row sequence
    // (multi-word substitutions that straddle row boundaries). Pure-PHP, done
    // before the transaction so the write window stays short.
    $cleanedRows = applyCorrectionsAcrossRows($db, $rows);
    }

    $db->beginTransaction();
    // Bulk-load fast path: suppress the live table's two per-row triggers
    // (tsv build + caption-queue mark) during the load, then replicate them
    // set-based. SET LOCAL reverts at transaction end.
    $db->exec("SET LOCAL session_replication_role = replica");

    $db->prepare("DELETE FROM yy_feed_item_transcript_autoclean WHERE feed_item_key = ? AND feed_item_transcript_autoclean_model = ?")
       ->execute([$itemKey, $model]);
    $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ?")
       ->execute([$itemKey]);

    $cleanRows = [];
    $liveRows  = [];
    $count = 0;
    foreach ($cleanedRows as $i => $r) {
        $clean = mb_substr((string)$r['text'], 0, 2000);
        $cleanRows[] = [$itemKey, $r['segment'], $clean, $i, $model];
        $liveRows[]  = [$itemKey, $r['segment'], $clean, $i, $userKey];
        $count++;
    }
    batchInsertRows($db,
        "INSERT INTO yy_feed_item_transcript_autoclean (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_autoclean_model) VALUES",
        '(?, ?::interval, ?, ?, ?)', $cleanRows);
    batchInsertRows($db,
        "INSERT INTO yy_feed_item_transcript (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_revision_user_key) VALUES",
        '(?, ?::interval, ?, ?, ?)', $liveRows);

    // Re-enable triggers and reproduce, set-based, exactly what
    // trg_yy_feed_item_transcript_tsv and trg_yy_feed_item_caption_queue
    // would have written per row.
    $db->exec("SET LOCAL session_replication_role = DEFAULT");
    $db->prepare("UPDATE yy_feed_item_transcript SET feed_item_transcript_tsv = to_tsvector('english', COALESCE(feed_item_transcript_text, '')) WHERE feed_item_key = ? AND feed_item_transcript_tsv IS NULL")
       ->execute([$itemKey]);
    $db->prepare("UPDATE yy_feed_item SET feed_item_yt_caption_status = 'queued' WHERE feed_item_key = ? AND COALESCE(feed_item_yt_caption_status, 'never') <> 'queued'")
       ->execute([$itemKey]);

    $db->commit();

    $db->prepare("UPDATE yy_feed_item_transcript_init_job SET job_status='done', job_rows_written=?, job_completed=now() WHERE job_key=?")
       ->execute([$count, $jobKey]);
    $notify('done:' . $count);
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('admin-transcript-init-worker job ' . $jobKey . ' failed: ' . $e->getMessage());
    $fail($e->getMessage());
}
