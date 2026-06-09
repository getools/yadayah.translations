<?php
/**
 * Kick off / monitor / cancel a chapter audio build.
 *
 *   POST  { action:'start',  tts_key, volume_key, chapter_key,
 *           output_format?, voice_overrides? }
 *     → { tts_audio_key }
 *     output_format and voice_overrides snapshot the settings into
 *     yy_tts_audio.tts_audio_settings; the worker reads from there rather
 *     than from yy_tts_category_voice so concurrent admin edits don't
 *     affect an in-flight build.
 *
 *   GET   ?action=status&tts_audio_key=N
 *     → status row from yy_tts_audio
 *
 *   POST  { action:'cancel', tts_audio_key }
 *     → marks job 'failed' with note; worker checks this each paragraph
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data = [];
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
}

if ($method === 'GET' && $action === 'status') {
    $audioKey = (int)($_GET['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');
    $stmt = $db->prepare("SELECT * FROM yy_tts_audio WHERE tts_audio_key = ?");
    $stmt->execute([$audioKey]);
    $row = $stmt->fetch();
    if (!$row) errorResponse('not found', 404);
    jsonResponse($row);
}

// GET: list paragraphs of a chapter — used by the Books-tab expand panel.
// Must sit ABOVE the POST-required gate. The full implementation (with
// failed-set, skip-set, part-file enumeration) lives down with the other
// per-paragraph handlers so it's read alongside redo / delete; this stub
// just hands control to the bottom of the file via a short-circuit.
if ($method === 'GET' && $action === 'list_paragraphs') {
    $audioKey = (int)($_GET['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');
    $stmt = $db->prepare("SELECT chapter_key, tts_audio_failed_paragraphs, tts_audio_settings FROM yy_tts_audio WHERE tts_audio_key = ?");
    $stmt->execute([$audioKey]);
    $a = $stmt->fetch();
    if (!$a) errorResponse('not found', 404);
    $chapterKey = (int)$a['chapter_key'];
    $failedSet = array_flip(array_map('intval',
        preg_split('/[,{}]/', (string)($a['tts_audio_failed_paragraphs'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)));
    $settings = json_decode((string)($a['tts_audio_settings'] ?? '{}'), true) ?: [];
    $skipSet = array_flip(array_map('intval', $settings['skip_paragraphs'] ?? []));
    $pStmt = $db->prepare("
        SELECT paragraph_number, paragraph_page, paragraph_text_plain, paragraph_is_table
          FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number
    ");
    $pStmt->execute([$chapterKey]);
    $rows = $pStmt->fetchAll();
    $audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
    $partsDir  = $audioBase . '/u/tts-parts/' . $audioKey;
    $out = [];
    $idx = 0;
    foreach ($rows as $r) {
        $partName = sprintf('p%05d.mp3', $idx);
        $partFile = $partsDir . '/' . $partName;
        $hasPart  = is_file($partFile) && filesize($partFile) > 0;
        $textPlain = (string)($r['paragraph_text_plain'] ?? '');
        $preview = mb_substr(preg_replace('/\s+/u', ' ', $textPlain), 0, 140);
        if (mb_strlen($textPlain) > 140) $preview .= '…';
        $out[] = [
            'idx'              => $idx,
            'paragraph_number' => (int)$r['paragraph_number'],
            'paragraph_page'   => $r['paragraph_page'] !== null ? (int)$r['paragraph_page'] : null,
            'is_table'         => !empty($r['paragraph_is_table']),
            'is_failed'        => isset($failedSet[(int)$r['paragraph_number']]),
            'is_skipped'       => isset($skipSet[(int)$r['paragraph_number']]),
            'preview'          => $preview,
            'has_part'         => $hasPart,
            'part_url'         => $hasPart ? ('/u/tts-parts/' . $audioKey . '/' . $partName) : null,
            'part_bytes'       => $hasPart ? (int)filesize($partFile) : 0,
        ];
        $idx++;
    }
    jsonResponse(['audio_key' => $audioKey, 'paragraphs' => $out]);
}

if ($method !== 'POST') errorResponse('POST required');

if ($action === 'start') {
    $ttsKey     = (int)($data['tts_key']     ?? 0);
    $volumeKey  = (int)($data['volume_key']  ?? 0);
    $chapterKey = (int)($data['chapter_key'] ?? 0);
    if (!$ttsKey || !$volumeKey || !$chapterKey) errorResponse('tts_key, volume_key, chapter_key required');

    // Snapshot the current category-voice config so the worker uses a
    // stable picture even if admin edits voices mid-build.
    $cfg = loadTtsConfig($db, $ttsKey);
    if (!$cfg['system']) errorResponse('unknown tts_key', 404);

    $outputFormat = !empty($data['output_format']) ? (string)$data['output_format'] : $cfg['system']['tts_output_format'];

    $catSnapshot = [];
    foreach ($cfg['categories'] as $cat => $row) {
        $catSnapshot[$cat] = [
            'voice_code'   => $row['tts_voice_code'],
            'style'        => $row['tts_voice_style'],
            'style_degree' => (float)$row['tts_voice_style_degree'],
            'rate_pct'     => (int)$row['tts_voice_rate_pct'],
            'pitch_st'     => (float)$row['tts_voice_pitch_st'],
            'volume'       => (int)$row['tts_voice_volume'],
        ];
    }
    // Caller-supplied per-category overrides win over the saved defaults.
    foreach ((array)($data['voice_overrides'] ?? []) as $cat => $over) {
        if (!is_array($over)) continue;
        $catSnapshot[$cat] = array_merge($catSnapshot[$cat] ?? [], array_intersect_key($over, [
            'voice_code' => 1, 'style' => 1, 'style_degree' => 1, 'rate_pct' => 1, 'pitch_st' => 1, 'volume' => 1,
        ]));
    }

    $settings = [
        'output_format' => $outputFormat,
        'region'        => $cfg['system']['tts_region'] ?? null,
        'categories'    => $catSnapshot,
        'tts_code'      => $cfg['system']['tts_code'],
    ];

    // Cancel any existing pending/running build for this slot before queuing.
    $db->prepare("
        UPDATE yy_tts_audio
           SET tts_audio_status = 'failed',
               tts_audio_error  = 'superseded by new build',
               tts_audio_completed_dtime = NOW()
         WHERE tts_key = ? AND volume_key = ? AND chapter_key = ?
           AND tts_audio_status IN ('pending', 'running')
    ")->execute([$ttsKey, $volumeKey, $chapterKey]);

    // INSERT … ON CONFLICT reuses the existing row when a (tts_key,
    // volume_key, chapter_key) record already exists from a prior build
    // attempt (failed or complete). The partial unique index includes
    // a WHERE chapter_key IS NOT NULL predicate, so the ON CONFLICT
    // target must repeat it.
    // On a re-build we KEEP tts_audio_path, _duration_secs, _size_bytes,
    // _live_dtime and the existing markers untouched so the prior live
    // MP3 (if any) stays playable during the rebuild AND remains the
    // fallback when the rebuild has failures. The worker swaps in the
    // fresh audio + markers only when it produces a clean build.
    // tts_audio_failed_paragraphs is cleared so the queue promoter
    // treats this pending row as a fresh build, not a retry.
    $insStmt = $db->prepare("
        INSERT INTO yy_tts_audio
            (tts_key, volume_key, chapter_key, tts_audio_status, tts_audio_progress,
             tts_audio_message, tts_audio_settings, tts_audio_started_dtime)
        VALUES (?, ?, ?, 'pending', 0, 'Queued', ?::jsonb, NOW())
        ON CONFLICT (tts_key, volume_key, chapter_key) WHERE chapter_key IS NOT NULL
        DO UPDATE SET
            tts_audio_status             = 'pending',
            tts_audio_progress           = 0,
            tts_audio_message            = 'Queued',
            tts_audio_error              = NULL,
            tts_audio_settings           = EXCLUDED.tts_audio_settings,
            tts_audio_started_dtime      = NOW(),
            tts_audio_completed_dtime    = NULL,
            tts_audio_worker_pid         = NULL,
            tts_audio_failed_paragraphs  = NULL,
            tts_audio_revision_dtime     = NOW()
        RETURNING tts_audio_key
    ");
    $insStmt->execute([$ttsKey, $volumeKey, $chapterKey, json_encode($settings)]);
    $audioKey = (int)$insStmt->fetchColumn();

    // Fresh build / rebuild — wipe the per-paragraph parts cache so the
    // worker re-synthesises every paragraph against the new voice config.
    // (retry_failed and resume deliberately keep the parts dir; only
    // start, start_all, and restart clear it.)
    ttsWipePartsDir($audioKey);

    // Concurrency cap: at most TTS_MAX_CONCURRENT chapter builds may run
    // simultaneously. If we're at the cap, leave the row in 'pending'
    // status; the next worker to finish will pick it up via the queue
    // promotion at the end of admin-tts-build-worker.php.
    $maxConcurrent = 1;
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio WHERE tts_audio_status = 'running'")->fetchColumn();
    $queued  = false;
    $workerScript = __DIR__ . '/admin-tts-build-worker.php';
    if ($running < $maxConcurrent && file_exists($workerScript)) {
        $logFile = sys_get_temp_dir() . '/tts_build_' . $audioKey . '.log';
        $pid = spawnCappedWorker($workerScript, [(string)$audioKey], $logFile, [
            'cpu_secs' => 3600, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_tts_audio SET tts_audio_worker_pid = ? WHERE tts_audio_key = ?")
               ->execute([$pid, $audioKey]);
        }
    } else {
        $queued = true;
        $db->prepare("UPDATE yy_tts_audio SET tts_audio_message = ? WHERE tts_audio_key = ?")
           ->execute(["Queued — waiting for an open slot (limit: $maxConcurrent concurrent)", $audioKey]);
    }
    jsonResponse(['tts_audio_key' => $audioKey, 'queued' => $queued]);
}

if ($action === 'start_all') {
    // Whole-book build: enqueue every chapter in $volume_key that matches
    // the scope filter, with one shared snapshot of the voice config + the
    // caller's per-category overrides. Up to TTS_MAX_CONCURRENT workers
    // spawn immediately; the rest stay 'pending' and get promoted by
    // admin-tts-build-worker.php's queue-promotion tail.
    //
    //   scope = 'all'        (default) — every chapter, even if 'complete'
    //         = 'missing'    — chapters with no audio row OR status in (failed, none)
    //         = 'failed'     — only chapters with status='failed'
    $ttsKey    = (int)($data['tts_key']    ?? 0);
    $volumeKey = (int)($data['volume_key'] ?? 0);
    $scope     = (string)($data['scope']   ?? 'all');
    if (!$ttsKey || !$volumeKey) errorResponse('tts_key and volume_key required');
    if (!in_array($scope, ['all', 'missing', 'failed'], true)) errorResponse('scope must be all|missing|failed');

    $cfg = loadTtsConfig($db, $ttsKey);
    if (!$cfg['system']) errorResponse('unknown tts_key', 404);

    $outputFormat = !empty($data['output_format']) ? (string)$data['output_format'] : $cfg['system']['tts_output_format'];
    $catSnapshot = [];
    foreach ($cfg['categories'] as $cat => $row) {
        $catSnapshot[$cat] = [
            'voice_code'   => $row['tts_voice_code'],
            'style'        => $row['tts_voice_style'],
            'style_degree' => (float)$row['tts_voice_style_degree'],
            'rate_pct'     => (int)$row['tts_voice_rate_pct'],
            'pitch_st'     => (float)$row['tts_voice_pitch_st'],
            'volume'       => (int)$row['tts_voice_volume'],
        ];
    }
    foreach ((array)($data['voice_overrides'] ?? []) as $cat => $over) {
        if (!is_array($over)) continue;
        $catSnapshot[$cat] = array_merge($catSnapshot[$cat] ?? [], array_intersect_key($over, [
            'voice_code' => 1, 'style' => 1, 'style_degree' => 1, 'rate_pct' => 1, 'pitch_st' => 1, 'volume' => 1,
        ]));
    }
    $settings = [
        'output_format' => $outputFormat,
        'region'        => $cfg['system']['tts_region'] ?? null,
        'categories'    => $catSnapshot,
        'tts_code'      => $cfg['system']['tts_code'],
    ];

    // Resolve chapter list to enqueue.
    $chStmt = $db->prepare("
        SELECT c.chapter_key, c.chapter_number, a.tts_audio_status
          FROM yy_chapter c
     LEFT JOIN yy_tts_audio a
            ON a.tts_key = ? AND a.volume_key = c.volume_key AND a.chapter_key = c.chapter_key
         WHERE c.volume_key = ?
         ORDER BY c.chapter_sort, c.chapter_number
    ");
    $chStmt->execute([$ttsKey, $volumeKey]);
    $chapters = $chStmt->fetchAll();
    if (!$chapters) errorResponse('volume has no chapters', 404);

    $filtered = array_values(array_filter($chapters, function ($c) use ($scope) {
        $st = $c['tts_audio_status'];
        if ($scope === 'all')     return true;
        if ($scope === 'failed')  return $st === 'failed';
        // 'missing': enqueue when there's no row, or when last attempt was
        // failed/none. 'complete' rows are preserved.
        return $st === null || in_array($st, ['failed', 'none'], true);
    }));
    if (!$filtered) jsonResponse(['queued' => 0, 'spawned' => 0, 'audio_keys' => [], 'message' => 'no chapters matched the scope filter']);

    // Upsert each filtered chapter's row using the same INSERT…ON CONFLICT
    // as the single-chapter path (intentionally repeated rather than
    // extracted — diverging the two paths would just be noise).
    $cancel = $db->prepare("
        UPDATE yy_tts_audio
           SET tts_audio_status = 'failed',
               tts_audio_error  = 'superseded by new build',
               tts_audio_completed_dtime = NOW()
         WHERE tts_key = ? AND volume_key = ? AND chapter_key = ?
           AND tts_audio_status IN ('pending', 'running')
    ");
    $insStmt = $db->prepare("
        INSERT INTO yy_tts_audio
            (tts_key, volume_key, chapter_key, tts_audio_status, tts_audio_progress,
             tts_audio_message, tts_audio_settings, tts_audio_started_dtime)
        VALUES (?, ?, ?, 'pending', 0, 'Queued (build-all)', ?::jsonb, NOW())
        ON CONFLICT (tts_key, volume_key, chapter_key) WHERE chapter_key IS NOT NULL
        DO UPDATE SET
            tts_audio_status             = 'pending',
            tts_audio_progress           = 0,
            tts_audio_message            = 'Queued (build-all)',
            tts_audio_error              = NULL,
            tts_audio_settings           = EXCLUDED.tts_audio_settings,
            tts_audio_started_dtime      = NOW(),
            tts_audio_completed_dtime    = NULL,
            tts_audio_worker_pid         = NULL,
            tts_audio_failed_paragraphs  = NULL,
            tts_audio_revision_dtime     = NOW()
        RETURNING tts_audio_key
    ");
    $audioKeys = [];
    foreach ($filtered as $c) {
        $cancel->execute([$ttsKey, $volumeKey, (int)$c['chapter_key']]);
        $insStmt->execute([$ttsKey, $volumeKey, (int)$c['chapter_key'], json_encode($settings)]);
        $ak = (int)$insStmt->fetchColumn();
        $audioKeys[] = $ak;
        // Fresh build per chapter — wipe parts so the worker re-synthesises
        // every paragraph (mirrors the single-chapter start branch above).
        ttsWipePartsDir($ak);
    }

    // Spawn workers up to the cap. Anything still pending is picked up by
    // the queue-promoter at the end of admin-tts-build-worker.php.
    $maxConcurrent = 1;
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio WHERE tts_audio_status = 'running'")->fetchColumn();
    $workerScript = __DIR__ . '/admin-tts-build-worker.php';
    $spawned = 0;
    if (file_exists($workerScript)) {
        foreach ($audioKeys as $ak) {
            if ($running >= $maxConcurrent) break;
            $logFile = sys_get_temp_dir() . '/tts_build_' . $ak . '.log';
            $pid = spawnCappedWorker($workerScript, [(string)$ak], $logFile, [
                'cpu_secs' => 3600, 'mem_mb' => 1500, 'nice' => 10,
            ]);
            if ($pid > 0) {
                $db->prepare("UPDATE yy_tts_audio SET tts_audio_worker_pid = ? WHERE tts_audio_key = ?")
                   ->execute([$pid, $ak]);
                $spawned++;
                $running++;
            }
        }
    }
    jsonResponse([
        'queued'     => count($audioKeys),
        'spawned'    => $spawned,
        'audio_keys' => $audioKeys,
        'scope'      => $scope,
        'cap'        => $maxConcurrent,
    ]);
}

if ($action === 'cancel') {
    // Cancel semantics depend on whether a prior complete MP3 exists:
    //   - First-time build (no tts_audio_path): DELETE the row entirely so
    //     the chapter reverts to "Build" in the UI without forcing the
    //     admin to manually Delete a failed-looking row.
    //   - Rebuild over an existing MP3: restore status='complete' and
    //     clear the running-state fields so the prior audio stays
    //     playable. The worker preserves tts_audio_path during a rebuild
    //     specifically for this fallback case.
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');
    $row = $db->prepare("SELECT tts_audio_status, tts_audio_path, tts_audio_worker_pid FROM yy_tts_audio WHERE tts_audio_key = ?");
    $row->execute([$audioKey]);
    $r = $row->fetch();
    if (!$r) jsonResponse(['ok' => true, 'action' => 'noop']);
    if (!in_array($r['tts_audio_status'], ['pending', 'running'], true)) {
        jsonResponse(['ok' => true, 'action' => 'noop']);
    }
    // Best-effort kill of the running worker — guarded by pcntl_kill being
    // available and ignoring failure (worker may already have exited).
    $pid = (int)($r['tts_audio_worker_pid'] ?? 0);
    if ($pid > 0 && function_exists('posix_kill')) {
        @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
    }
    $hasPriorAudio = !empty($r['tts_audio_path']);
    if ($hasPriorAudio) {
        $db->prepare("
            UPDATE yy_tts_audio
               SET tts_audio_status   = 'complete',
                   tts_audio_progress = 100,
                   tts_audio_message  = 'cancelled by admin — prior audio preserved',
                   tts_audio_error    = NULL,
                   tts_audio_worker_pid       = NULL,
                   tts_audio_completed_dtime  = NOW(),
                   tts_audio_failed_paragraphs = NULL
             WHERE tts_audio_key = ?
        ")->execute([$audioKey]);
        jsonResponse(['ok' => true, 'action' => 'restored_complete']);
    }
    // No prior audio — drop the row.
    $db->prepare("DELETE FROM yy_tts_audio WHERE tts_audio_key = ?")->execute([$audioKey]);
    jsonResponse(['ok' => true, 'action' => 'deleted']);
}

// Retry only the paragraphs listed in tts_audio_failed_paragraphs. The
// worker reads cached per-paragraph bytes for everything else, so the
// caller doesn't re-pay Azure for already-good paragraphs.
if ($action === 'retry_failed') {
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');

    $row = $db->prepare("SELECT tts_audio_status, tts_audio_failed_paragraphs FROM yy_tts_audio WHERE tts_audio_key = ?");
    $row->execute([$audioKey]);
    $r = $row->fetch();
    if (!$r) errorResponse('not found', 404);
    if ($r['tts_audio_status'] !== 'complete') {
        errorResponse('retry_failed: chapter status must be complete (currently ' . $r['tts_audio_status'] . ')');
    }
    $failed = $r['tts_audio_failed_paragraphs'];
    if ($failed === null || $failed === '' || $failed === '{}') {
        errorResponse('retry_failed: no failed paragraphs on this chapter');
    }

    $db->prepare("
        UPDATE yy_tts_audio
           SET tts_audio_status        = 'pending',
               tts_audio_progress      = 0,
               tts_audio_message       = 'Queued for retry',
               tts_audio_error         = NULL,
               tts_audio_started_dtime = NOW(),
               tts_audio_completed_dtime = NULL,
               tts_audio_revision_dtime  = NOW(),
               tts_audio_worker_pid    = NULL
         WHERE tts_audio_key = ?
    ")->execute([$audioKey]);

    $maxConcurrent = 1;
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio WHERE tts_audio_status = 'running'")->fetchColumn();
    $queued = false;
    $workerScript = __DIR__ . '/admin-tts-build-worker.php';
    if ($running < $maxConcurrent && file_exists($workerScript)) {
        $logFile = sys_get_temp_dir() . '/tts_build_' . $audioKey . '.log';
        $pid = spawnCappedWorker($workerScript, [(string)$audioKey, 'retry'], $logFile, [
            'cpu_secs' => 3600, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_tts_audio SET tts_audio_worker_pid = ? WHERE tts_audio_key = ?")
               ->execute([$pid, $audioKey]);
        }
    } else {
        $queued = true;
        $db->prepare("UPDATE yy_tts_audio SET tts_audio_message = ? WHERE tts_audio_key = ?")
           ->execute(["Queued — waiting for an open slot (limit: $maxConcurrent concurrent)", $audioKey]);
    }
    jsonResponse(['tts_audio_key' => $audioKey, 'queued' => $queued, 'retrying' => $failed]);
}

// Delete a chapter's audio entirely — the row, the markers, the live MP3
// file on disk, and the per-paragraph parts cache. Disallowed while a
// build is in-flight; admin should hit Cancel first.
if ($action === 'set_active') {
    // Toggle the tts_audio_active_flag without touching the MP3 file. When
    // FALSE, the public tts-audio.php endpoint pretends the audio doesn't
    // exist (no Play button in the flipbook for that chapter). Flipping
    // back to TRUE re-exposes the same file with no rebuild needed.
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');
    // Cast bool to int per the project's PDO+Postgres convention (see
    // feedback_pdo_bool_to_int): PHP `false` binds as "" which Postgres
    // boolean columns reject; default-true columns mask the bug until
    // someone explicitly sets FALSE.
    $newActive = (int)!empty($data['active_flag']);
    $upd = $db->prepare("UPDATE yy_tts_audio SET tts_audio_active_flag = ? WHERE tts_audio_key = ?");
    $upd->execute([$newActive, $audioKey]);
    if ($upd->rowCount() === 0) errorResponse('audio row not found', 404);
    jsonResponse(['ok' => true, 'tts_audio_key' => $audioKey, 'active_flag' => (bool)$newActive]);
}

if ($action === 'delete') {
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');

    $row = $db->prepare("SELECT tts_audio_status, tts_audio_path FROM yy_tts_audio WHERE tts_audio_key = ?");
    $row->execute([$audioKey]);
    $r = $row->fetch();
    if (!$r) errorResponse('not found', 404);
    if (in_array($r['tts_audio_status'], ['pending', 'running'], true)) {
        errorResponse('cannot delete while build is in-flight — cancel first');
    }

    // Wipe the markers, then the audio row. _rev tables are written by
    // triggers — no manual cleanup required.
    $db->prepare("DELETE FROM yy_tts_audio_marker WHERE tts_audio_key = ?")->execute([$audioKey]);
    $db->prepare("DELETE FROM yy_tts_audio WHERE tts_audio_key = ?")->execute([$audioKey]);

    // Remove the on-disk artifacts. Resolve via the same path-resolution
    // logic the worker uses so this works whether PHP is in the
    // container (/var/www/html bind mount) or on the host.
    $audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
    $relPath   = (string)($r['tts_audio_path'] ?? '');
    if ($relPath !== '') {
        // tts_audio_path may have a ?v=… cache buster appended by tts-audio.php.
        // The DB column itself shouldn't, but strip defensively just in case.
        $clean = preg_replace('/\?.*$/', '', $relPath);
        $diskPath = $audioBase . $clean;
        if (is_file($diskPath)) @unlink($diskPath);
        if (is_file($diskPath . '.staging')) @unlink($diskPath . '.staging');
    }
    // Per-paragraph parts directory: u/tts-parts/<audio_key>/
    $partsDir = $audioBase . '/u/tts-parts/' . $audioKey;
    if (is_dir($partsDir)) {
        foreach (glob($partsDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($partsDir);
    }

    jsonResponse(['ok' => true, 'tts_audio_key' => $audioKey]);
}

// ── Pause / Resume / Restart ───────────────────────────────────────
// Pause: ask the worker to stop after its current paragraph. The worker
// checks tts_audio_status at the start of each iteration; flipping to
// 'paused' makes the next iteration exit cleanly with the parts cache
// intact. SIGTERM is intentionally NOT sent — we want the in-flight
// paragraph to finish so its bytes land in the cache.
if ($action === 'pause') {
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');
    $row = $db->prepare("SELECT tts_audio_status FROM yy_tts_audio WHERE tts_audio_key = ?");
    $row->execute([$audioKey]);
    $r = $row->fetch();
    if (!$r) errorResponse('not found', 404);
    if (!in_array($r['tts_audio_status'], ['pending', 'running'], true)) {
        errorResponse('pause only valid while pending or running (currently ' . $r['tts_audio_status'] . ')');
    }
    $db->prepare("
        UPDATE yy_tts_audio
           SET tts_audio_status  = 'paused',
               tts_audio_message = 'Pause requested — finishing current paragraph'
         WHERE tts_audio_key = ?
    ")->execute([$audioKey]);
    jsonResponse(['ok' => true, 'tts_audio_key' => $audioKey]);
}

// Resume: re-queue a paused row. Worker scans the parts cache on
// startup and picks up from the first missing paragraph idx — no
// per-paragraph state in the DB beyond the parts dir itself.
if ($action === 'resume') {
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');
    $row = $db->prepare("SELECT tts_audio_status FROM yy_tts_audio WHERE tts_audio_key = ?");
    $row->execute([$audioKey]);
    $r = $row->fetch();
    if (!$r) errorResponse('not found', 404);
    if ($r['tts_audio_status'] !== 'paused') {
        errorResponse('resume only valid on paused rows (currently ' . $r['tts_audio_status'] . ')');
    }
    $db->prepare("
        UPDATE yy_tts_audio
           SET tts_audio_status        = 'pending',
               tts_audio_message       = 'Queued for resume',
               tts_audio_error         = NULL,
               tts_audio_started_dtime = NOW(),
               tts_audio_worker_pid    = NULL
         WHERE tts_audio_key = ?
    ")->execute([$audioKey]);

    $spawned = ttsTrySpawn($db, $audioKey);
    jsonResponse(['ok' => true, 'tts_audio_key' => $audioKey, 'spawned' => $spawned]);
}

// Restart: wipe parts cache + markers and start over. Same semantics as
// a fresh Build, just keyed off an existing row rather than re-running
// the start action's voice/format snapshot. Settings on the row are
// preserved so the user's original config applies.
if ($action === 'restart') {
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');
    $row = $db->prepare("SELECT tts_audio_status FROM yy_tts_audio WHERE tts_audio_key = ?");
    $row->execute([$audioKey]);
    $r = $row->fetch();
    if (!$r) errorResponse('not found', 404);
    if (in_array($r['tts_audio_status'], ['running'], true)) {
        errorResponse('cannot restart a running build — pause or cancel first');
    }
    ttsWipePartsDir($audioKey);
    $db->prepare("DELETE FROM yy_tts_audio_marker WHERE tts_audio_key = ?")->execute([$audioKey]);
    $db->prepare("
        UPDATE yy_tts_audio
           SET tts_audio_status         = 'pending',
               tts_audio_progress       = 0,
               tts_audio_message        = 'Queued for restart',
               tts_audio_error          = NULL,
               tts_audio_started_dtime  = NOW(),
               tts_audio_completed_dtime = NULL,
               tts_audio_worker_pid     = NULL,
               tts_audio_failed_paragraphs = NULL
         WHERE tts_audio_key = ?
    ")->execute([$audioKey]);
    $spawned = ttsTrySpawn($db, $audioKey);
    jsonResponse(['ok' => true, 'tts_audio_key' => $audioKey, 'spawned' => $spawned]);
}

// ── Per-paragraph redo / delete ────────────────────────────
// (list_paragraphs lives above the POST-required gate — see top of file.)
//
// Redo: delete a single paragraph's part file so the worker re-synths it
// on the next run. If the chapter is complete / paused / failed, the row
// is reset to 'pending' so a fresh worker re-attempts the missing part.
if ($action === 'redo_paragraph') {
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    $idx      = (int)($data['idx']          ?? -1);
    if (!$audioKey || $idx < 0) errorResponse('tts_audio_key and idx required');
    $audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
    $partFile  = $audioBase . '/u/tts-parts/' . $audioKey . '/' . sprintf('p%05d.mp3', $idx);
    if (is_file($partFile)) @unlink($partFile);
    $stmt = $db->prepare("SELECT tts_audio_status, tts_audio_worker_pid FROM yy_tts_audio WHERE tts_audio_key = ?");
    $stmt->execute([$audioKey]);
    $row = $stmt->fetch();
    if (!$row) errorResponse('not found', 404);
    $st  = $row['tts_audio_status'];
    $pid = (int)($row['tts_audio_worker_pid'] ?? 0);
    $requeued = false;
    if ($st === 'running') {
        // Current worker is iterating forward past this paragraph and
        // won't backtrack to refill it. Kill it; the new worker we
        // spawn below will hit the gap on its first pass and synth
        // just the missing parts (per-iter cache check in the worker
        // loop). Cached parts after the deleted one stay valid.
        if ($pid > 0 && function_exists('posix_kill')) {
            @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
        }
        $db->prepare("
            UPDATE yy_tts_audio
               SET tts_audio_status='pending', tts_audio_progress=0,
                   tts_audio_message='Queued — paragraph redo (gap fill)',
                   tts_audio_error=NULL, tts_audio_worker_pid=NULL
             WHERE tts_audio_key = ?
        ")->execute([$audioKey]);
        ttsTrySpawn($db, $audioKey);
        $requeued = true;
    } else if (in_array($st, ['complete', 'paused', 'failed'], true)) {
        $db->prepare("
            UPDATE yy_tts_audio
               SET tts_audio_status='pending', tts_audio_progress=0,
                   tts_audio_message='Queued — paragraph redo',
                   tts_audio_error=NULL, tts_audio_worker_pid=NULL
             WHERE tts_audio_key = ?
        ")->execute([$audioKey]);
        ttsTrySpawn($db, $audioKey);
        $requeued = true;
    } else if ($st === 'pending') {
        // Pending row with no worker assigned (the queue promoter only
        // fires on worker exit, so a chapter cancelled then re-queued can
        // sit indefinitely with no one to pick it up). Try to spawn now
        // — ttsTrySpawn no-ops if the concurrency cap is full.
        ttsTrySpawn($db, $audioKey);
        $requeued = true;
    }
    jsonResponse(['ok' => true, 'audio_key' => $audioKey, 'idx' => $idx, 'requeued' => $requeued, 'prior_status' => $st]);
}

// Delete: same as redo (remove the part file), and ALSO add this paragraph
// to skip_paragraphs in tts_audio_settings so subsequent builds skip it.
// The worker honours this list — see build worker's iteration loop.
if ($action === 'delete_paragraph') {
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    $idx      = (int)($data['idx']          ?? -1);
    $paragraphNumber = (int)($data['paragraph_number'] ?? 0);
    if (!$audioKey || $idx < 0 || !$paragraphNumber) errorResponse('tts_audio_key, idx, paragraph_number required');
    $audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
    $partFile  = $audioBase . '/u/tts-parts/' . $audioKey . '/' . sprintf('p%05d.mp3', $idx);
    if (is_file($partFile)) @unlink($partFile);
    $stmt = $db->prepare("SELECT tts_audio_settings FROM yy_tts_audio WHERE tts_audio_key = ?");
    $stmt->execute([$audioKey]);
    $row = $stmt->fetch();
    if (!$row) errorResponse('not found', 404);
    $settings = json_decode((string)($row['tts_audio_settings'] ?? '{}'), true) ?: [];
    $skip = $settings['skip_paragraphs'] ?? [];
    if (!in_array($paragraphNumber, $skip, true)) $skip[] = $paragraphNumber;
    sort($skip);
    $settings['skip_paragraphs'] = $skip;
    $db->prepare("UPDATE yy_tts_audio SET tts_audio_settings = ?::jsonb WHERE tts_audio_key = ?")
       ->execute([json_encode($settings), $audioKey]);
    jsonResponse(['ok' => true, 'audio_key' => $audioKey, 'idx' => $idx, 'paragraph_number' => $paragraphNumber, 'skip_count' => count($skip)]);
}

errorResponse('Unknown action');

// ── helpers ────────────────────────────────────────────────────────
function ttsWipePartsDir(int $audioKey): void {
    $audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
    $dir = $audioBase . '/u/tts-parts/' . $audioKey;
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}

// Spawn a worker for a 'pending' row if a concurrency slot is open.
// Returns true if spawned, false if left queued.
function ttsTrySpawn(PDO $db, int $audioKey): bool {
    $maxConcurrent = 1;
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio WHERE tts_audio_status = 'running'")->fetchColumn();
    $workerScript = __DIR__ . '/admin-tts-build-worker.php';
    if ($running >= $maxConcurrent || !file_exists($workerScript)) {
        $db->prepare("UPDATE yy_tts_audio SET tts_audio_message = ? WHERE tts_audio_key = ?")
           ->execute(["Queued — waiting for an open slot (limit: $maxConcurrent concurrent)", $audioKey]);
        return false;
    }
    $logFile = sys_get_temp_dir() . '/tts_build_' . $audioKey . '.log';
    $pid = spawnCappedWorker($workerScript, [(string)$audioKey], $logFile, [
        'cpu_secs' => 3600, 'mem_mb' => 1500, 'nice' => 10,
    ]);
    if ($pid > 0) {
        $db->prepare("UPDATE yy_tts_audio SET tts_audio_worker_pid = ? WHERE tts_audio_key = ?")
           ->execute([$pid, $audioKey]);
        return true;
    }
    return false;
}
