<?php
/**
 * Admin transcription API.
 *
 * GET ?item_key=N         — get transcript rows + active job status for an item
 * POST {action:start, item_key:N} — kick off a transcription job (background)
 * POST {action:cancel, item_key:N} — cancel running job
 * POST {action:save, item_key:N, rows:[{segment, text, sort}, ...]} — save edited transcript
 * DELETE ?item_key=N — clear transcript
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$userKey = $user['user_key'] ?? null;

function intervalToSeconds(string $interval): int {
    if (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $interval, $m)) {
        return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)round((float)$m[3]);
    }
    if (is_numeric($interval)) return (int)$interval;
    return 0;
}

function secondsToInterval(int $secs): string {
    $h = (int)($secs / 3600);
    $m = (int)(($secs % 3600) / 60);
    $s = $secs % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

// applyCorrectionDictionary() and autoLearnCorrections() both live in
// transcript-helpers.php now — the worker also needs applyCorrection so
// it can snapshot the "auto-fix" pass alongside Whisper's raw output.
require_once __DIR__ . '/transcript-helpers.php';

// ── GET: load transcript + job status ──
if ($method === 'GET') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');

    // Get item info
    $itemStmt = $db->prepare("SELECT feed_item_key, feed_item_external_id, COALESCE(feed_item_title_override, feed_item_title_import) AS title, feed_item_type, feed_item_url, feed_item_duration_seconds, fi.feed_key, f.feed_site_code, f.feed_account_id, f.feed_api_key FROM yy_feed_item fi JOIN yy_feed f ON fi.feed_key = f.feed_key WHERE fi.feed_item_key = ?");
    $itemStmt->execute([$itemKey]);
    $item = $itemStmt->fetch();
    if (!$item) errorResponse('Item not found', 404);

    // Load the transcript STRICTLY for this feed_item by its primary key — never
    // the linked/duplicate-import cluster. Merging a cluster on read is unsafe for
    // editing: the save path writes back to this single key (DELETE+INSERT), so a
    // merged read collapses every linked/duplicate item's rows into this one item
    // and re-renders them as duplicates (3 imports of one video => 3x rows). Each
    // feed_item's transcript must round-trip by its own key alone.
    $itemKeys = [$itemKey];
    $placeholders = '?';
    // Optional view: when ?view=<model_code> is supplied, return rows from
    // yy_feed_item_transcript_auto for that model instead of the live
    // table. The client puts the editor into read-only mode for these
    // views (no save, no row-add/split/delete). Default view '' means
    // editable live rows.
    $view = trim((string)($_GET['view'] ?? ''));
    if ($view !== '') {
        $rowsStmt = $db->prepare("
            SELECT NULL AS feed_item_transcript_key,
                   feed_item_transcript_segment,
                   feed_item_transcript_text,
                   feed_item_transcript_sort,
                   feed_item_transcript_speaker
              FROM yy_feed_item_transcript_auto
             WHERE feed_item_key IN ($placeholders)
               AND feed_item_transcript_auto_model = ?
             ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
        ");
        $rowsStmt->execute(array_merge($itemKeys, [$view]));
    } else {
        $rowsStmt = $db->prepare("
            SELECT feed_item_transcript_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort,
                   feed_item_transcript_speaker
            FROM yy_feed_item_transcript
            WHERE feed_item_key IN ($placeholders)
            ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
        ");
        $rowsStmt->execute($itemKeys);
    }
    $rows = $rowsStmt->fetchAll();

    // Distinct auto-model codes available for the view dropdown.
    $viewsStmt = $db->prepare("
        SELECT DISTINCT feed_item_transcript_auto_model AS code
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key IN ($placeholders)
         ORDER BY feed_item_transcript_auto_model
    ");
    $viewsStmt->execute($itemKeys);
    $availableViews = array_column($viewsStmt->fetchAll(), 'code');

    // Convert intervals to display strings (HH:MM:SS)
    foreach ($rows as &$r) {
        $r['feed_item_transcript_segment'] = $r['feed_item_transcript_segment'] ?: '00:00:00';
    }
    unset($r);

    // Get latest job status
    $jobStmt = $db->prepare("
        SELECT feed_item_transcript_job_key, job_status, job_progress, job_message, job_error, job_dtime, job_completed_dtime
        FROM yy_feed_item_transcript_job
        WHERE feed_item_key = ?
        ORDER BY job_dtime DESC LIMIT 1
    ");
    $jobStmt->execute([$itemKey]);
    $job = $jobStmt->fetch();

    // Get current validation (one row per item via UNIQUE constraint)
    $valStmt = $db->prepare("
        SELECT v.validation_status, v.validation_note, v.validation_dtime, v.validation_user_key,
               v.validation_bookmark_seconds, u.user_code AS validation_user_code
        FROM yy_feed_item_transcript_validation v
        LEFT JOIN yy_user u ON u.user_key = v.validation_user_key
        WHERE v.feed_item_key = ?
    ");
    $valStmt->execute([$itemKey]);
    $validation = $valStmt->fetch() ?: null;

    // Do _auto AND _autoclean both have rows for any item in this cluster?
    // Both must be present for the three-version analysis to be meaningful;
    // the UI uses this flag to enable/disable the "Analyze Changes" button.
    $snapStmt = $db->prepare("
        SELECT EXISTS (SELECT 1 FROM yy_feed_item_transcript_auto      WHERE feed_item_key IN ($placeholders))
           AND EXISTS (SELECT 1 FROM yy_feed_item_transcript_autoclean WHERE feed_item_key IN ($placeholders))
    ");
    $snapStmt->execute(array_merge($itemKeys, $itemKeys));
    $hasSnapshot = (bool)$snapStmt->fetchColumn();

    jsonResponse([
        'item' => $item,
        'rows' => $rows,
        'job' => $job ?: null,
        'validation' => $validation,
        'has_snapshot' => $hasSnapshot,
        'view' => $view,
        'available_views' => $availableViews,
    ]);
}

// ── POST: actions ──
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? '';
    $itemKey = (int)($data['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');

    if ($action === 'start') {
        // If there's already an active job for this item, attach to it instead
        // of spawning a duplicate. Prevents two workers racing on the same
        // item (clicking Transcribe in a second tab, double-clicking, etc.) —
        // both would hit yt-dlp / Whisper, wasting download bandwidth and
        // incurring the API cost twice.
        $existing = $db->prepare("SELECT feed_item_transcript_job_key, job_status, job_worker_pid FROM yy_feed_item_transcript_job WHERE feed_item_key = ? AND job_status IN ('pending', 'running') ORDER BY job_dtime DESC LIMIT 1");
        $existing->execute([$itemKey]);
        $row = $existing->fetch();
        if ($row) {
            // Verify the worker is actually alive — if the PID is dead or
            // recycled, treat the job as orphaned and fall through to a
            // fresh start instead of attaching to a ghost.
            $pid = (int)$row['job_worker_pid'];
            $alive = false;
            if ($pid > 0) {
                $cmdline = @file_get_contents("/proc/$pid/cmdline");
                $alive = ($cmdline && strpos($cmdline, 'transcript-worker') !== false);
            }
            if ($alive) {
                jsonResponse([
                    'job_key' => (int)$row['feed_item_transcript_job_key'],
                    'status' => $row['job_status'],
                    'worker_pid' => $pid,
                    'attached' => true,
                ]);
            }
            // Orphaned: row says running but no live worker. Flip to cancelled
            // so the new job below is the only active one.
            $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'cancelled', job_completed_dtime = NOW(), job_message = 'Worker died — restarting' WHERE feed_item_transcript_job_key = ?")
               ->execute([(int)$row['feed_item_transcript_job_key']]);
        }

        // Create new job
        $jobStmt = $db->prepare("INSERT INTO yy_feed_item_transcript_job (feed_item_key, job_status, job_message, user_key) VALUES (?, 'pending', 'Queued for transcription', ?) RETURNING feed_item_transcript_job_key");
        $jobStmt->execute([$itemKey, $userKey]);
        $jobKey = (int)$jobStmt->fetchColumn();

        // Kick off the worker in the background, fully detached.
        // The trailing `echo $!` returns the worker's PID so we can kill it on cancel.
        $workerScript = __DIR__ . '/transcript-worker.php';
        $workerPid = 0;
        if (file_exists($workerScript)) {
            require_once __DIR__ . '/spawn-helpers.php';
            $logFile = sys_get_temp_dir() . '/transcript_' . $jobKey . '.log';
            // yt-dlp + Whisper for long recordings can take 30min CPU when
            // chunked. 40min cap with 2GB virt covers chunked Whisper buffers.
            $workerPid = spawnCappedWorker($workerScript, [(string)$jobKey], $logFile, [
                'cpu_secs' => 2400, 'mem_mb' => 2000, 'nice' => 10,
            ]);
            if ($workerPid > 0) {
                $db->prepare("UPDATE yy_feed_item_transcript_job SET job_worker_pid = ? WHERE feed_item_transcript_job_key = ?")
                   ->execute([$workerPid, $jobKey]);
            }
        }

        jsonResponse(['job_key' => $jobKey, 'status' => 'pending', 'worker_pid' => $workerPid]);
    }

    if ($action === 'cancel') {
        // Look up active job + PID before flipping status, so we can kill the worker process.
        $stmt = $db->prepare("SELECT feed_item_transcript_job_key, job_worker_pid FROM yy_feed_item_transcript_job WHERE feed_item_key = ? AND job_status IN ('pending', 'running') ORDER BY job_dtime DESC LIMIT 1");
        $stmt->execute([$itemKey]);
        $row = $stmt->fetch();

        // Mark cancelled first — worker's next updateJob() will be no-op'd by the
        // job_status guard, so even if the kill races we won't overwrite final state.
        $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'cancelled', job_completed_dtime = NOW(), job_message = 'Cancelled by user' WHERE feed_item_key = ? AND job_status IN ('pending', 'running')")
           ->execute([$itemKey]);

        $killed = false;
        if ($row && (int)$row['job_worker_pid'] > 0) {
            $pid = (int)$row['job_worker_pid'];
            // Verify the PID still belongs to OUR worker before killing (proc names contain
            // 'transcript-worker'). Avoids killing an unrelated PHP process if PID was reused.
            $procName = @file_get_contents("/proc/$pid/cmdline");
            if ($procName && strpos($procName, 'transcript-worker') !== false) {
                if (function_exists('posix_kill')) {
                    posix_kill($pid, 15); // SIGTERM
                } else {
                    @exec("kill -TERM " . (int)$pid . " 2>/dev/null");
                }
                $killed = true;
            }
        }
        jsonResponse(['cancelled' => true, 'killed_pid' => $killed ? $row['job_worker_pid'] : null]);
    }

    if ($action === 'save') {
        $rows = $data['rows'] ?? [];
        if (!is_array($rows)) errorResponse('rows must be an array');

        // Diff-based save keyed by feed_item_transcript_key (the editor sends
        // each row's key; rows it added/split have none). Rows with a known key
        // are UPDATEd in place by primary key; keyless rows are INSERTed; and
        // existing keys absent from the posted set are DELETEd. This replaces the
        // old DELETE-all + INSERT-all save, which reissued every row's key and
        // history on each save and — under READ COMMITTED — could lose a
        // concurrent save's DELETE and duplicate rows. UPDATE-by-key is race-safe
        // and leaves untouched columns (e.g. speaker/diarisation) intact.
        $existingStmt = $db->prepare("SELECT feed_item_transcript_key AS k, feed_item_transcript_segment::text AS segment, feed_item_transcript_text AS text, feed_item_transcript_sort AS sort FROM yy_feed_item_transcript WHERE feed_item_key = ?");
        $existingStmt->execute([$itemKey]);
        $existingByKey = [];
        foreach ($existingStmt->fetchAll() as $r) {
            $existingByKey[(int)$r['k']] = ['segment' => $r['segment'], 'text' => $r['text'], 'sort' => (int)$r['sort']];
        }
        // Parse "H:M:S(.fff)" / "M:S" / bare seconds to a float so a timestamp
        // edit is detected reliably despite display-vs-interval formatting
        // (e.g. "0:00:41.9" from the input vs "00:00:41.9" from interval::text).
        $segToSec = function ($s) {
            $p = array_map('floatval', explode(':', trim((string)$s)));
            $n = count($p);
            if ($n === 3) return $p[0]*3600 + $p[1]*60 + $p[2];
            if ($n === 2) return $p[0]*60 + $p[1];
            return $p[0] ?? 0.0;
        };

        $db->beginTransaction();
        try {
            $updStmt = $db->prepare("UPDATE yy_feed_item_transcript
                   SET feed_item_transcript_segment = ?::interval,
                       feed_item_transcript_text    = ?,
                       feed_item_transcript_sort    = ?,
                       feed_item_transcript_revision_user_key = ?,
                       feed_item_transcript_revision_dtime    = NOW()
                 WHERE feed_item_transcript_key = ? AND feed_item_key = ?");
            // New rows only. ON CONFLICT guards uq_transcript_no_dup so a racing
            // insert becomes a no-op instead of a duplicate / aborting error.
            $insStmt = $db->prepare("INSERT INTO yy_feed_item_transcript (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_revision_user_key) VALUES (?, ?::interval, ?, ?, ?) ON CONFLICT (feed_item_key, feed_item_transcript_segment, md5(feed_item_transcript_text)) DO NOTHING");
            $delStmt = $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_transcript_key = ? AND feed_item_key = ?");
            $logStmt = $db->prepare("INSERT INTO yy_transcript_edit_log (feed_item_key, edit_segment, edit_original_text, edit_new_text, edit_action, edit_user_key) VALUES (?, ?::interval, ?, ?, ?, ?)");

            $sort = 0;
            $keptKeys = [];
            $inserted = 0; $deleted = 0; $updated = 0;
            foreach ($rows as $r) {
                $segment = trim($r['segment'] ?? '00:00:00');
                $text    = trim($r['text'] ?? '');
                if ($text === '') continue;
                if (!preg_match('/^\d+:\d+:\d+(\.\d+)?$|^\d+$/', $segment)) $segment = '00:00:00';
                $textTrim = mb_substr($text, 0, 2000);
                $rowSort  = isset($r['sort']) ? (int)$r['sort'] : $sort;
                $sort++;
                $key = (isset($r['key']) && $r['key'] !== null && $r['key'] !== '') ? (int)$r['key'] : null;

                if ($key !== null && isset($existingByKey[$key])) {
                    // Known row → UPDATE in place by primary key. Skip the write
                    // entirely when nothing changed so unedited rows don't churn
                    // their revision history on every save.
                    $keptKeys[$key] = true;
                    $old = $existingByKey[$key];
                    $txtChanged  = $old['text'] !== $textTrim;
                    $segChanged  = abs($segToSec($old['segment']) - $segToSec($segment)) > 0.01;
                    $sortChanged = $old['sort'] !== $rowSort;
                    if ($txtChanged || $segChanged || $sortChanged) {
                        $updStmt->execute([$segment, $textTrim, $rowSort, $userKey, $key, $itemKey]);
                        if ($txtChanged || $segChanged) {
                            $logStmt->execute([$itemKey, $segment, $old['text'], $textTrim, 'edit', $userKey]);
                        }
                        $updated++;
                    }
                } else {
                    // New row (no key, or a key that no longer exists) → INSERT.
                    $insStmt->execute([$itemKey, $segment, $textTrim, $rowSort, $userKey]);
                    $logStmt->execute([$itemKey, $segment, null, $textTrim, 'add', $userKey]);
                    $inserted++;
                }
            }
            // DELETE rows the editor dropped (existing key absent from the post).
            foreach ($existingByKey as $key => $old) {
                if (!isset($keptKeys[$key])) {
                    $delStmt->execute([$key, $itemKey]);
                    $logStmt->execute([$itemKey, $old['segment'], $old['text'], null, 'delete', $userKey]);
                    $deleted++;
                }
            }
            $db->commit();
            // inserted/deleted signal a structural change → the client reloads to
            // pick up the new rows' primary keys (so a follow-up save UPDATEs
            // them instead of re-INSERTing).
            jsonResponse(['saved' => true, 'count' => count($rows),
                          'updated' => $updated, 'inserted' => $inserted, 'deleted' => $deleted]);
        } catch (Exception $e) {
            $db->rollBack();
            errorResponse('Save failed: ' . $e->getMessage());
        }
    }

    if ($action === 'save_validation') {
        $status = trim($data['status'] ?? 'Pending');
        $note   = trim($data['note'] ?? '');
        if (!in_array($status, ['Pending', 'Approved', 'Errors'], true)) {
            errorResponse('status must be Pending, Approved, or Errors');
        }
        // Approving the transcript clears any in-progress validation bookmark
        // — the user is done with this item, so the resume marker is moot.
        $clearBookmark = ($status === 'Approved');
        $db->prepare("
            INSERT INTO yy_feed_item_transcript_validation
                (feed_item_key, validation_status, validation_note, validation_dtime, validation_user_key, validation_bookmark_seconds)
            VALUES (?, ?, NULLIF(?, ''), NOW(), ?, NULL)
            ON CONFLICT (feed_item_key) DO UPDATE SET
                validation_status   = EXCLUDED.validation_status,
                validation_note     = EXCLUDED.validation_note,
                validation_dtime    = NOW(),
                validation_user_key = EXCLUDED.validation_user_key,
                validation_bookmark_seconds = CASE WHEN ?::boolean THEN NULL ELSE yy_feed_item_transcript_validation.validation_bookmark_seconds END
        ")->execute([$itemKey, $status, $note, $userKey, $clearBookmark ? 't' : 'f']);
        jsonResponse(['saved' => true]);
    }

    if ($action === 'save_bookmark') {
        // Lightweight write — auto-fired every ~15s while the user is reviewing
        // the video against the transcript. UPSERTs into the same validation row
        // so closing the popover and reopening lands the user back where they
        // were. Doesn't touch validation_status/note.
        $sec = max(0, (int)($data['seconds'] ?? 0));
        $db->prepare("
            INSERT INTO yy_feed_item_transcript_validation
                (feed_item_key, validation_status, validation_user_key, validation_bookmark_seconds)
            VALUES (?, 'Pending', ?, ?)
            ON CONFLICT (feed_item_key) DO UPDATE SET
                validation_bookmark_seconds = EXCLUDED.validation_bookmark_seconds,
                validation_user_key         = COALESCE(yy_feed_item_transcript_validation.validation_user_key, EXCLUDED.validation_user_key)
        ")->execute([$itemKey, $userKey, $sec]);
        jsonResponse(['saved' => true, 'seconds' => $sec]);
    }

    if ($action === 'rename_speaker') {
        // Bulk-rename one speaker label across every row in this item.
        // POST { action:'rename_speaker', item_key:N, from:'1', to:'Craig' }
        // Either side may be the empty string; `to=''` clears the label.
        // Returns the number of rows that changed.
        $from = trim((string)($data['from'] ?? ''));
        $to   = trim((string)($data['to']   ?? ''));
        if ($from === '') errorResponse("'from' speaker label required");
        $to = $to === '' ? null : mb_substr($to, 0, 64);
        // The cluster of feed_item_keys keeps linked duplicates in sync,
        // matching the read path's getFeedItemKeyCluster behavior.
        $itemKeys = getFeedItemKeyCluster($db, $itemKey);
        $placeholders = implode(',', array_fill(0, count($itemKeys), '?'));
        $upd = $db->prepare("
            UPDATE yy_feed_item_transcript
               SET feed_item_transcript_speaker = ?
             WHERE feed_item_key IN ($placeholders)
               AND feed_item_transcript_speaker = ?
        ");
        $upd->execute(array_merge([$to], $itemKeys, [$from]));
        $changed = $upd->rowCount();
        jsonResponse(['renamed' => $changed, 'from' => $from, 'to' => $to]);
    }

    if ($action === 'apply_corrections_now') {
        // Re-apply current correction dictionary to existing transcript
        $rowsStmt = $db->prepare("SELECT feed_item_transcript_key, feed_item_transcript_text FROM yy_feed_item_transcript WHERE feed_item_key = ?");
        $rowsStmt->execute([$itemKey]);
        $changed = 0;
        $upd = $db->prepare("UPDATE yy_feed_item_transcript SET feed_item_transcript_text = ?, feed_item_transcript_revision_dtime = NOW(), feed_item_transcript_revision_num = feed_item_transcript_revision_num + 1 WHERE feed_item_transcript_key = ?");
        foreach ($rowsStmt->fetchAll() as $row) {
            $newText = applyCorrectionDictionary($db, $row['feed_item_transcript_text']);
            if ($newText !== $row['feed_item_transcript_text']) {
                $upd->execute([mb_substr($newText, 0, 2000), $row['feed_item_transcript_key']]);
                $changed++;
            }
        }
        jsonResponse(['changed' => $changed]);
    }

    errorResponse('Unknown action');
}

// ── DELETE: clear transcript ──
if ($method === 'DELETE') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ?")->execute([$itemKey]);
    jsonResponse(['cleared' => true]);
}

errorResponse('Method not allowed', 405);
