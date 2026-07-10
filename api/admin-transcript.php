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

// An admin changed this item's editable transcript → flag it 'Editing' so the
// consensus (re)build never overwrites it. Best-effort; never breaks the save.
function markTranscriptEditing(PDO $db, int $itemKey): void {
    if ($itemKey <= 0) return;
    try {
        $db->prepare("INSERT INTO yy_feed_item_transcript_status (feed_item_key, edit_status, dtime)
                       VALUES (?, 'Editing', now())
                       ON CONFLICT (feed_item_key) DO UPDATE SET edit_status = 'Editing', dtime = now()")
           ->execute([$itemKey]);
    } catch (\Throwable $e) { /* status is advisory — don't fail the edit */ }
}

// matchSpeakerProfilesForItem() now lives in transcript-helpers.php (required
// above) so the build workers can auto-name speakers at build time too.

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
    // Snippet actions are library-wide (not tied to a feed item), so they carry
    // no item_key. Every other POST action operates on a specific transcript.
    $itemlessActions = ['create_snippet', 'list_snippets', 'get_snippet',
                        'rename_snippet', 'delete_snippet',
                        'list_speaker_profiles', 'rename_speaker_profile',
                        'delete_speaker_profile', 'merge_speaker_profiles'];
    if (!$itemKey && !in_array($action, $itemlessActions, true)) errorResponse('item_key required');

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
        $existingStmt = $db->prepare("SELECT feed_item_transcript_key AS k, feed_item_transcript_segment::text AS segment, feed_item_transcript_text AS text, feed_item_transcript_sort AS sort, feed_item_transcript_speaker AS speaker FROM yy_feed_item_transcript WHERE feed_item_key = ?");
        $existingStmt->execute([$itemKey]);
        $existingByKey = [];
        foreach ($existingStmt->fetchAll() as $r) {
            $existingByKey[(int)$r['k']] = ['segment' => $r['segment'], 'text' => $r['text'], 'sort' => (int)$r['sort'], 'speaker' => $r['speaker']];
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
                       feed_item_transcript_speaker  = ?,
                       feed_item_transcript_revision_user_key = ?,
                       feed_item_transcript_revision_dtime    = NOW()
                 WHERE feed_item_transcript_key = ? AND feed_item_key = ?");
            // New rows only. ON CONFLICT guards uq_transcript_no_dup so a racing
            // insert becomes a no-op instead of a duplicate / aborting error.
            $insStmt = $db->prepare("INSERT INTO yy_feed_item_transcript (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_speaker, feed_item_transcript_revision_user_key) VALUES (?, ?::interval, ?, ?, ?, ?) ON CONFLICT (feed_item_key, feed_item_transcript_segment, md5(feed_item_transcript_text)) DO NOTHING");
            $delStmt = $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_transcript_key = ? AND feed_item_key = ?");
            $logStmt = $db->prepare("INSERT INTO yy_transcript_edit_log (feed_item_key, edit_segment, edit_original_text, edit_new_text, edit_action, edit_user_key) VALUES (?, ?::interval, ?, ?, ?, ?)");

            $sort = 0;
            $inserted = 0; $deleted = 0; $updated = 0;
            // Which existing keys the post still carries (non-empty text only —
            // a blanked row is dropped, mirroring the `if ($text === '') continue`
            // skip below). Computed up front so the DELETE pass can run BEFORE the
            // INSERTs: a replacement row that reuses a removed row's
            // (segment, md5(text)) would otherwise hit ON CONFLICT DO NOTHING
            // against the not-yet-deleted original and be silently dropped. That
            // is exactly how the first segment of an inserted snippet (offset 0 →
            // it lands on the first replaced row's timestamp) went missing.
            $keptKeys = [];
            foreach ($rows as $r) {
                if (trim((string)($r['text'] ?? '')) === '') continue;
                $k = (isset($r['key']) && $r['key'] !== null && $r['key'] !== '') ? (int)$r['key'] : null;
                if ($k !== null && isset($existingByKey[$k])) $keptKeys[$k] = true;
            }
            // DELETE rows the editor dropped (existing key absent from the post)
            // FIRST, so their (segment, text) frees up before any INSERT reuses it.
            foreach ($existingByKey as $key => $old) {
                if (!isset($keptKeys[$key])) {
                    $delStmt->execute([$key, $itemKey]);
                    $logStmt->execute([$itemKey, $old['segment'], $old['text'], null, 'delete', $userKey]);
                    $deleted++;
                }
            }
            foreach ($rows as $r) {
                $segment = trim($r['segment'] ?? '00:00:00');
                $text    = trim($r['text'] ?? '');
                if ($text === '') continue;
                if (!preg_match('/^\d+:\d+:\d+(\.\d+)?$|^\d+$/', $segment)) $segment = '00:00:00';
                $textTrim = mb_substr($text, 0, 2000);
                $rowSort  = isset($r['sort']) ? (int)$r['sort'] : $sort;
                $sort++;
                $key = (isset($r['key']) && $r['key'] !== null && $r['key'] !== '') ? (int)$r['key'] : null;
                // Speaker is only touched when the client actually sends the
                // field — otherwise unsupplied means "leave as-is" so a caller
                // that doesn't manage speakers can't blank the diarised value.
                // An empty string is an explicit "no speaker" → stored as NULL.
                $hasSpk = array_key_exists('speaker', $r);
                $spkVal = $hasSpk ? (trim((string)$r['speaker']) === '' ? null : mb_substr(trim((string)$r['speaker']), 0, 64)) : null;

                if ($key !== null && isset($existingByKey[$key])) {
                    // Known row → UPDATE in place by primary key. Skip the write
                    // entirely when nothing changed so unedited rows don't churn
                    // their revision history on every save.
                    $keptKeys[$key] = true;
                    $old = $existingByKey[$key];
                    $txtChanged  = $old['text'] !== $textTrim;
                    $segChanged  = abs($segToSec($old['segment']) - $segToSec($segment)) > 0.01;
                    $sortChanged = $old['sort'] !== $rowSort;
                    // Unsupplied speaker → keep the existing value (no change).
                    $newSpk = $hasSpk ? $spkVal : $old['speaker'];
                    $spkChanged = $hasSpk && (($old['speaker'] ?? '') !== ($newSpk ?? ''));
                    if ($txtChanged || $segChanged || $sortChanged || $spkChanged) {
                        $updStmt->execute([$segment, $textTrim, $rowSort, $newSpk, $userKey, $key, $itemKey]);
                        if ($txtChanged || $segChanged) {
                            $logStmt->execute([$itemKey, $segment, $old['text'], $textTrim, 'edit', $userKey]);
                        }
                        // Layer 4: learn from the admin's own segment edits, not
                        // just find/replace (admin-transcript-replace.php) and
                        // Smart Captions applies. Every in-place text correction
                        // feeds the shared wrong→right dictionary so the STT
                        // pipeline (worker auto-fix + Smart Captions priming)
                        // stops repeating the same mistakes. autoLearnCorrections
                        // self-guards: it only records aligned word substitutions
                        // (same word count, skips case/punctuation-only), so
                        // rewrites and re-timings add no noise.
                        if ($txtChanged) {
                            autoLearnCorrections($db, $old['text'], $textTrim);
                        }
                        $updated++;
                    }
                } else {
                    // New row (no key, or a key that no longer exists) → INSERT.
                    $insStmt->execute([$itemKey, $segment, $textTrim, $rowSort, $spkVal, $userKey]);
                    $logStmt->execute([$itemKey, $segment, null, $textTrim, 'add', $userKey]);
                    $inserted++;
                }
            }
            $db->commit();
            // A real change → the transcript is now human-edited ('Editing'),
            // which protects it from being overwritten by a consensus rebuild.
            if (($updated + $inserted + $deleted) > 0) markTranscriptEditing($db, $itemKey);
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
        if ($changed > 0) foreach ($itemKeys as $ik) markTranscriptEditing($db, (int)$ik);
        // Keep captured voice embeddings addressable by the new label so
        // "save as profile" and auto-match keep working after a rename.
        if ($to !== null) {
            // The embedding table is keyed (feed_item_key, model, label). If the
            // target label already has an embedding on the same item+model,
            // renaming the source into it violates the primary key. Drop the
            // colliding source rows first (the target keeps its sample), then
            // rename the remainder. Without this, merging two speakers that both
            // carry embeddings throws AFTER the transcript rows are already
            // updated, so the client sees an error and treats the merge as
            // failed even though the segments changed.
            $eDel = $db->prepare("DELETE FROM yy_feed_item_speaker_embedding s
                                   WHERE s.feed_item_key IN ($placeholders) AND s.label = ?
                                     AND EXISTS (SELECT 1 FROM yy_feed_item_speaker_embedding t
                                                  WHERE t.feed_item_key = s.feed_item_key
                                                    AND t.model = s.model AND t.label = ?)");
            $eDel->execute(array_merge($itemKeys, [$from, $to]));
            $eUp = $db->prepare("UPDATE yy_feed_item_speaker_embedding SET label = ? WHERE feed_item_key IN ($placeholders) AND label = ? AND NOT EXISTS (SELECT 1 FROM yy_feed_item_speaker_embedding ex WHERE ex.feed_item_key = yy_feed_item_speaker_embedding.feed_item_key AND ex.model = yy_feed_item_speaker_embedding.model AND ex.label = ?)");
            $eUp->execute(array_merge([$to], $itemKeys, [$from, $to]));
        }
        jsonResponse(['renamed' => $changed, 'from' => $from, 'to' => $to]);
    }

    if ($action === 'save_speaker_profile') {
        // Snapshot a named speaker's captured voice embedding into the reusable
        // profile library so future transcripts can be auto-identified.
        // POST { action:'save_speaker_profile', item_key:N, label:'Y|Yada|#..', name?:'Yada' }
        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') errorResponse('label required');
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') { $p = explode('|', $label); $name = trim($p[1] ?? ($p[0] ?? $label)); }
        if ($name === '') errorResponse('a speaker name is required to save a profile');
        $itemKeys = getFeedItemKeyCluster($db, $itemKey);
        $ph = implode(',', array_fill(0, count($itemKeys), '?'));
        $eq = $db->prepare("SELECT embedding::text AS e FROM yy_feed_item_speaker_embedding WHERE feed_item_key IN ($ph) AND label = ? ORDER BY captured_dtime DESC LIMIT 1");
        $eq->execute(array_merge($itemKeys, [$label]));
        $erow = $eq->fetch(PDO::FETCH_ASSOC);
        if (!$erow) errorResponse('No voice embedding captured for this speaker yet. Regenerate the WhisperX + speaker-diarization baseline so voice embeddings are stored, then try again.');
        $newVec = array_map('floatval', explode(',', trim((string)$erow['e'], '[] ')));
        $l2 = function(array $v) { $n = sqrt(array_sum(array_map(function($x){ return $x*$x; }, $v))); return $n > 0 ? array_map(function($x) use ($n){ return $x/$n; }, $v) : $v; };
        $pq = $db->prepare("SELECT speaker_profile_key AS k, speaker_profile_embedding::text AS e, speaker_profile_sample_count AS c FROM yy_speaker_profile WHERE lower(speaker_profile_name) = lower(?) AND speaker_profile_feed_key IS NULL LIMIT 1");
        $pq->execute([$name]);
        $prof = $pq->fetch(PDO::FETCH_ASSOC);
        if ($prof) {
            $old = array_map('floatval', explode(',', trim((string)$prof['e'], '[] ')));
            $c = max(1, (int)$prof['c']);
            $merged = [];
            for ($i = 0; $i < count($newVec); $i++) { $merged[$i] = ((($old[$i] ?? 0.0) * $c) + $newVec[$i]) / ($c + 1); }
            $vecLit = '[' . implode(',', $l2($merged)) . ']';
            $db->prepare("UPDATE yy_speaker_profile SET speaker_profile_label=?, speaker_profile_embedding=?::vector, speaker_profile_sample_count=?, speaker_profile_updated_dtime=now() WHERE speaker_profile_key=?")
               ->execute([$label, $vecLit, $c + 1, $prof['k']]);
            jsonResponse(['saved' => true, 'profile_key' => (int)$prof['k'], 'name' => $name, 'samples' => $c + 1, 'updated' => true]);
        }
        $vecLit = '[' . implode(',', $l2($newVec)) . ']';
        $ins = $db->prepare("INSERT INTO yy_speaker_profile (speaker_profile_name, speaker_profile_label, speaker_profile_embedding) VALUES (?, ?, ?::vector) RETURNING speaker_profile_key");
        $ins->execute([$name, $label, $vecLit]);
        jsonResponse(['saved' => true, 'profile_key' => (int)$ins->fetchColumn(), 'name' => $name, 'samples' => 1, 'updated' => false]);
    }

    if ($action === 'list_speaker_profiles') {
        $rows = $db->query("SELECT speaker_profile_key AS key, speaker_profile_name AS name, speaker_profile_label AS label, speaker_profile_sample_count AS samples FROM yy_speaker_profile ORDER BY lower(speaker_profile_name)")->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['profiles' => $rows]);
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
        if ($changed > 0) markTranscriptEditing($db, $itemKey);
        jsonResponse(['changed' => $changed]);
    }

    if ($action === 'fill_speakers') {
        // Annotate the editable transcript's speaker column from a diarised
        // baseline, WITHOUT touching text or segmentation. Each live row gets
        // the speaker whose diarised turn has the greatest overlap with the
        // row's [start,next-start) span — not merely the turn active at the
        // start instant, which stamps the previous speaker onto a row whose
        // boundary precedes the true onset (the one-row speaker lag). Non-destructive: fills only
        // rows whose speaker is currently NULL unless overwrite=true, so it
        // never clobbers manually renamed labels — and it works on human-edited
        // ('Editing') transcripts a full rebuild is not allowed to overwrite.
        // POST { action:'fill_speakers', item_key:N, overwrite?:bool }
        $overwrite = !empty($data['overwrite']) ? 1 : 0;
        $itemKeys = getFeedItemKeyCluster($db, $itemKey);
        $ph = implode(',', array_fill(0, count($itemKeys), '?'));
        // Pick the diarised source: prefer whisperx-diarize, else the baseline
        // (any engine — assemblyai/deepgram also carry speakers) with the most
        // labelled rows, taken from whichever cluster item actually has it.
        $srcStmt = $db->prepare("
            SELECT feed_item_key AS ik, feed_item_transcript_auto_model AS model, COUNT(*) AS c
              FROM yy_feed_item_transcript_auto
             WHERE feed_item_key IN ($ph) AND feed_item_transcript_speaker IS NOT NULL
             GROUP BY feed_item_key, feed_item_transcript_auto_model
             ORDER BY (feed_item_transcript_auto_model = 'gpu-whisperx-diarize') DESC, COUNT(*) DESC
             LIMIT 1");
        $srcStmt->execute($itemKeys);
        $src = $srcStmt->fetch(PDO::FETCH_ASSOC);
        if (!$src) errorResponse('No diarised baseline found for this item — generate a WhisperX + speaker-diarization baseline first.');
        $srcItem  = (int)$src['ik'];
        $srcModel = (string)$src['model'];
        $filled = 0;
        // Overlap-based: build the diarised speaker TURNS [onset, next-onset)
        // and the live rows' SPANS [start, next-start), then assign each row the
        // turn whose overlap with its span is largest. Rows with no overlapping
        // turn (before the first onset) are left untouched.
        $upd = $db->prepare("
            WITH turns AS (
                SELECT feed_item_transcript_speaker AS spk,
                       extract(epoch FROM feed_item_transcript_segment) AS ts,
                       COALESCE(extract(epoch FROM lead(feed_item_transcript_segment)
                                OVER (ORDER BY feed_item_transcript_sort, feed_item_transcript_segment)), 1e9) AS te
                  FROM yy_feed_item_transcript_auto
                 WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ?
                   AND feed_item_transcript_speaker IS NOT NULL
            ),
            live AS (
                SELECT feed_item_transcript_key AS k,
                       extract(epoch FROM feed_item_transcript_segment) AS s,
                       COALESCE(extract(epoch FROM lead(feed_item_transcript_segment)
                                OVER (ORDER BY feed_item_transcript_sort, feed_item_transcript_segment)),
                                extract(epoch FROM feed_item_transcript_segment) + 5) AS e
                  FROM yy_feed_item_transcript
                 WHERE feed_item_key = ?
            ),
            best AS (
                SELECT DISTINCT ON (l.k) l.k AS k, tu.spk AS spk
                  FROM live l
                  JOIN turns tu ON least(l.e, tu.te) - greatest(l.s, tu.ts) > 0
                 ORDER BY l.k, (least(l.e, tu.te) - greatest(l.s, tu.ts)) DESC
            )
            UPDATE yy_feed_item_transcript t
               SET feed_item_transcript_speaker = b.spk
              FROM best b
             WHERE t.feed_item_transcript_key = b.k
               AND (? = 1 OR t.feed_item_transcript_speaker IS NULL)");
        foreach ($itemKeys as $ik) {
            $upd->execute([$srcItem, $srcModel, (int)$ik, $overwrite]);
            $filled += $upd->rowCount();
        }
        if ($filled > 0) foreach ($itemKeys as $ik) markTranscriptEditing($db, (int)$ik);

        // Auto-identify: match each still-generic diarised label (SPEAKER_xx)
        // against the saved global voice profiles. Strong matches are renamed in
        // place; near-misses come back as suggestions the UI offers one-click.
        $match = matchSpeakerProfilesForItem($db, $srcItem, $itemKeys);
        $matched = $match['matched'];
        $suggestions = $match['suggestions'];
        if ($matched) foreach ($itemKeys as $ik) markTranscriptEditing($db, (int)$ik);

        // Report the resulting label distribution so the UI can refresh chips.
        $dist = $db->prepare("SELECT feed_item_transcript_speaker AS spk, COUNT(*) AS c
                                FROM yy_feed_item_transcript
                               WHERE feed_item_key = ? AND feed_item_transcript_speaker IS NOT NULL
                               GROUP BY 1 ORDER BY 2 DESC");
        $dist->execute([$itemKey]);
        jsonResponse(['filled' => $filled, 'source_model' => $srcModel, 'matched' => $matched,
                      'suggestions' => $suggestions, 'speakers' => $dist->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // Match this item's raw SPEAKER_xx labels against the saved global profiles
    // WITHOUT first re-filling from the diarised baseline — used both on-demand
    // ("Match against saved speakers") and automatically when the editor opens a
    // transcript that still has generic labels. Applies strong matches, returns
    // near-miss suggestions. No diarised baseline / no embeddings → no-op.
    if ($action === 'match_speaker_profiles') {
        $itemKeys = getFeedItemKeyCluster($db, $itemKey);
        // Voice embeddings can live on any cluster item; match each that has them.
        $embItems = $db->prepare("SELECT DISTINCT feed_item_key FROM yy_feed_item_speaker_embedding WHERE feed_item_key IN (" . implode(',', array_fill(0, count($itemKeys), '?')) . ")");
        $embItems->execute($itemKeys);
        $matched = [];
        $suggestions = [];
        foreach ($embItems->fetchAll(PDO::FETCH_COLUMN) as $srcItem) {
            $r = matchSpeakerProfilesForItem($db, (int)$srcItem, $itemKeys);
            $matched = array_merge($matched, $r['matched']);
            $suggestions = array_merge($suggestions, $r['suggestions']);
        }
        if ($matched) foreach ($itemKeys as $ik) markTranscriptEditing($db, (int)$ik);
        // De-dup suggestions by source label (nearest profile already picked).
        $seen = [];
        $suggestions = array_values(array_filter($suggestions, function($s) use (&$seen) {
            if (isset($seen[$s['from']])) return false; $seen[$s['from']] = true; return true;
        }));
        jsonResponse(['matched' => $matched, 'suggestions' => $suggestions]);
    }

    // Apply a specific saved global profile to one raw label in this item —
    // powers the one-click "Assign <name>?" suggestion chip and manual
    // assignment. Renames the label to the profile's label across the live
    // rows + embedding store of every cluster item.
    if ($action === 'assign_speaker_profile') {
        $fromLabel = trim((string)($data['from_label'] ?? ''));
        $profileKey = (int)($data['profile_key'] ?? 0);
        if ($fromLabel === '' || !$profileKey) errorResponse('from_label and profile_key required');
        $pq = $db->prepare("SELECT speaker_profile_label AS lbl, speaker_profile_name AS nm FROM yy_speaker_profile WHERE speaker_profile_key = ?");
        $pq->execute([$profileKey]);
        $prof = $pq->fetch(PDO::FETCH_ASSOC);
        if (!$prof) errorResponse('Speaker profile not found', 404);
        $toLabel = (string)$prof['lbl'];
        $itemKeys = getFeedItemKeyCluster($db, $itemKey);
        $changed = 0;
        foreach ($itemKeys as $ik) {
            $u = $db->prepare("UPDATE yy_feed_item_transcript SET feed_item_transcript_speaker=? WHERE feed_item_key=? AND feed_item_transcript_speaker=?");
            $u->execute([$toLabel, (int)$ik, $fromLabel]);
            $changed += $u->rowCount();
            // Drop embedding rows that would collide with the target label
            // (feed_item_key, model, label unique), then rename the rest.
            $db->prepare("DELETE FROM yy_feed_item_speaker_embedding a WHERE a.feed_item_key=? AND a.label=? AND EXISTS (SELECT 1 FROM yy_feed_item_speaker_embedding b WHERE b.feed_item_key=a.feed_item_key AND b.model=a.model AND b.label=?)")->execute([(int)$ik, $fromLabel, $toLabel]);
            $db->prepare("UPDATE yy_feed_item_speaker_embedding SET label=? WHERE feed_item_key=? AND label=? AND NOT EXISTS (SELECT 1 FROM yy_feed_item_speaker_embedding ex WHERE ex.feed_item_key = yy_feed_item_speaker_embedding.feed_item_key AND ex.model = yy_feed_item_speaker_embedding.model AND ex.label = ?)")->execute([$toLabel, (int)$ik, $fromLabel, $toLabel]);
        }
        if ($changed > 0) foreach ($itemKeys as $ik) markTranscriptEditing($db, (int)$ik);
        jsonResponse(['assigned' => true, 'from' => $fromLabel, 'to' => $toLabel, 'name' => $prof['nm'], 'changed' => $changed]);
    }

    // ── Global speaker-profile management (itemless) ─────────────────────
    // Rename a saved profile (its display name and/or its stored label).
    if ($action === 'rename_speaker_profile') {
        $profileKey = (int)($data['profile_key'] ?? 0);
        if (!$profileKey) errorResponse('profile_key required');
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') errorResponse('a name is required');
        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') $label = $name . '|' . $name;
        $db->prepare("UPDATE yy_speaker_profile SET speaker_profile_name=?, speaker_profile_label=?, speaker_profile_updated_dtime=now() WHERE speaker_profile_key=?")
           ->execute([$name, $label, $profileKey]);
        jsonResponse(['renamed' => true, 'profile_key' => $profileKey, 'name' => $name, 'label' => $label]);
    }

    // Delete a saved profile. Existing transcript labels are untouched (they
    // keep the name already stamped on their rows) — only the reusable voice
    // template is removed, so it stops auto-matching future transcripts.
    if ($action === 'delete_speaker_profile') {
        $profileKey = (int)($data['profile_key'] ?? 0);
        if (!$profileKey) errorResponse('profile_key required');
        $db->prepare("DELETE FROM yy_speaker_profile WHERE speaker_profile_key=?")->execute([$profileKey]);
        jsonResponse(['deleted' => true, 'profile_key' => $profileKey]);
    }

    // Merge one profile into another (e.g. an accidental double-save). The
    // survivor's embedding becomes the sample-count-weighted average of both,
    // its sample_count sums, and the merged-away row is deleted.
    if ($action === 'merge_speaker_profiles') {
        $fromKey = (int)($data['from_key'] ?? 0);
        $intoKey = (int)($data['into_key'] ?? 0);
        if (!$fromKey || !$intoKey || $fromKey === $intoKey) errorResponse('distinct from_key and into_key required');
        $q = $db->prepare("SELECT speaker_profile_key AS k, speaker_profile_embedding::text AS e, speaker_profile_sample_count AS c FROM yy_speaker_profile WHERE speaker_profile_key IN (?, ?)");
        $q->execute([$fromKey, $intoKey]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['k']] = $r;
        if (!isset($rows[$fromKey], $rows[$intoKey])) errorResponse('one or both profiles not found', 404);
        $va = array_map('floatval', explode(',', trim((string)$rows[$fromKey]['e'], '[] ')));
        $vb = array_map('floatval', explode(',', trim((string)$rows[$intoKey]['e'], '[] ')));
        $ca = max(1, (int)$rows[$fromKey]['c']);
        $cb = max(1, (int)$rows[$intoKey]['c']);
        $merged = [];
        for ($i = 0; $i < count($vb); $i++) { $merged[$i] = ((($va[$i] ?? 0.0) * $ca) + (($vb[$i] ?? 0.0) * $cb)) / ($ca + $cb); }
        $n = sqrt(array_sum(array_map(function($x){ return $x*$x; }, $merged)));
        if ($n > 0) $merged = array_map(function($x) use ($n){ return $x/$n; }, $merged);
        $vecLit = '[' . implode(',', $merged) . ']';
        $db->prepare("UPDATE yy_speaker_profile SET speaker_profile_embedding=?::vector, speaker_profile_sample_count=?, speaker_profile_updated_dtime=now() WHERE speaker_profile_key=?")
           ->execute([$vecLit, $ca + $cb, $intoKey]);
        $db->prepare("DELETE FROM yy_speaker_profile WHERE speaker_profile_key=?")->execute([$fromKey]);
        jsonResponse(['merged' => true, 'from_key' => $fromKey, 'into_key' => $intoKey, 'samples' => $ca + $cb]);
    }

    // ── Excerpt snippets ─────────────────────────────────────────────────
    // A "snippet" is a reusable, named group of transcript segments captured
    // from the editor's Excerpt column. Segment offsets are stored relative to
    // the first captured segment (which is therefore always offset 0), so a
    // snippet can be pasted over any point in any transcript later.

    // Create a snippet + its segments. segments[] = [{offset (seconds, float,
    // relative to the first), speaker, text}, ...] already normalised client-side.
    if ($action === 'create_snippet') {
        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') errorResponse('A snippet title is required');
        $label = mb_substr($label, 0, 250);
        $segments = $data['segments'] ?? [];
        if (!is_array($segments) || !count($segments)) errorResponse('At least one segment must be selected');

        $db->beginTransaction();
        try {
            $snipStmt = $db->prepare("INSERT INTO yy_transcript_snippet (transcript_snippet_label, transcript_snippet_revision_user_key)
                                       VALUES (?, ?) RETURNING transcript_snippet_key");
            $snipStmt->execute([$label, $userKey]);
            $snippetKey = (int)$snipStmt->fetchColumn();

            $segStmt = $db->prepare("INSERT INTO yy_transcript_snippet_segment
                    (transcript_snippet_key, transcript_snippet_segment_speaker, transcript_snippet_segment_offset,
                     transcript_snippet_segment_text, transcript_snippet_segment_revision_user_key)
                    VALUES (?, ?, make_interval(secs => ?), ?, ?)");
            $saved = 0;
            foreach ($segments as $seg) {
                $text = trim((string)($seg['text'] ?? ''));
                if ($text === '') continue;
                $offset = (float)($seg['offset'] ?? 0);
                if ($offset < 0) $offset = 0;
                $spk = trim((string)($seg['speaker'] ?? ''));
                $spk = ($spk === '') ? null : mb_substr($spk, 0, 64);
                $segStmt->execute([$snippetKey, $spk, $offset, mb_substr($text, 0, 4000), $userKey]);
                $saved++;
            }
            if ($saved === 0) { $db->rollBack(); errorResponse('No non-empty segments to save'); }
            $db->commit();
            jsonResponse(['saved' => true, 'snippet_key' => $snippetKey, 'segments' => $saved]);
        } catch (Exception $e) {
            $db->rollBack();
            errorResponse('Could not create snippet: ' . $e->getMessage());
        }
    }

    // List existing snippets (most-recent first) for the popover.
    if ($action === 'list_snippets') {
        $stmt = $db->query("SELECT s.transcript_snippet_key, s.transcript_snippet_label,
                                   s.transcript_snippet_revision_dtime,
                                   COUNT(seg.transcript_snippet_segment_key) AS segment_count
                              FROM yy_transcript_snippet s
                              LEFT JOIN yy_transcript_snippet_segment seg
                                     ON seg.transcript_snippet_key = s.transcript_snippet_key
                             GROUP BY s.transcript_snippet_key
                             ORDER BY s.transcript_snippet_key DESC");
        jsonResponse(['snippets' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // Fetch one snippet's segments (offsets as seconds) so the client can paste
    // them over the selected transcript rows.
    if ($action === 'get_snippet') {
        $snippetKey = (int)($data['snippet_key'] ?? 0);
        if (!$snippetKey) errorResponse('snippet_key required');
        $s = $db->prepare("SELECT transcript_snippet_key, transcript_snippet_label FROM yy_transcript_snippet WHERE transcript_snippet_key = ?");
        $s->execute([$snippetKey]);
        $snip = $s->fetch();
        if (!$snip) errorResponse('Snippet not found', 404);
        $segStmt = $db->prepare("SELECT transcript_snippet_segment_speaker AS speaker,
                                        EXTRACT(EPOCH FROM transcript_snippet_segment_offset) AS offset_seconds,
                                        transcript_snippet_segment_text AS text
                                   FROM yy_transcript_snippet_segment
                                  WHERE transcript_snippet_key = ?
                                  ORDER BY transcript_snippet_segment_offset, transcript_snippet_segment_key");
        $segStmt->execute([$snippetKey]);
        jsonResponse([
            'snippet_key' => (int)$snip['transcript_snippet_key'],
            'label'       => $snip['transcript_snippet_label'],
            'segments'    => $segStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    // Rename a saved snippet (library-wide). Only the label changes; the
    // segments are untouched, so already-inserted copies are unaffected.
    if ($action === 'rename_snippet') {
        $snippetKey = (int)($data['snippet_key'] ?? 0);
        if (!$snippetKey) errorResponse('snippet_key required');
        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') errorResponse('A snippet title is required');
        $label = mb_substr($label, 0, 250);
        $upd = $db->prepare("UPDATE yy_transcript_snippet
                                SET transcript_snippet_label = ?,
                                    transcript_snippet_revision_user_key = ?,
                                    transcript_snippet_revision_dtime = now()
                              WHERE transcript_snippet_key = ?");
        $upd->execute([$label, $userKey, $snippetKey]);
        if (!$upd->rowCount()) errorResponse('Snippet not found', 404);
        jsonResponse(['renamed' => true, 'snippet_key' => $snippetKey, 'label' => $label]);
    }

    // Delete a saved snippet. Its segments go with it via the segment table's
    // ON DELETE CASCADE FK. Transcripts that already had the snippet inserted
    // keep their rows — only the reusable template is removed.
    if ($action === 'delete_snippet') {
        $snippetKey = (int)($data['snippet_key'] ?? 0);
        if (!$snippetKey) errorResponse('snippet_key required');
        $del = $db->prepare("DELETE FROM yy_transcript_snippet WHERE transcript_snippet_key = ?");
        $del->execute([$snippetKey]);
        if (!$del->rowCount()) errorResponse('Snippet not found', 404);
        jsonResponse(['deleted' => true, 'snippet_key' => $snippetKey]);
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
