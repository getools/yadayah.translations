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

// ── TTS build concurrency gate ───────────────────────────────────────────
// TTS builds are serialized (cap 1) on the GPU box. A burst of build POSTs
// (e.g. start_all, or two quick clicks) would otherwise each read running=0
// — a freshly-spawned worker doesn't flip its row to 'running' until ~1s into
// boot — and all spawn at once, overloading the TTS engine. The advisory lock
// makes count-and-claim atomic. We "claim" by setting tts_audio_worker_pid;
// a pending row WITH a pid already counts as an occupied slot (the worker's
// queue-promoter only ever picks pending rows whose pid IS NULL). Separate
// lock key from STT (742001) — TTS and STT may use the GPU concurrently.
const TTS_BUILD_LOCK = 742002;

// Builds occupying a worker slot: actively running, OR pending but already
// assigned a worker pid (spawned and booting — not yet flipped to 'running').
function ttsActiveBuilds(PDO $db): int {
    return (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio
                             WHERE tts_audio_status = 'running'
                                OR (tts_audio_status = 'pending' AND tts_audio_worker_pid IS NOT NULL)")
                   ->fetchColumn();
}

// Under the build lock: if a slot is open (active < cap), spawn a worker for
// $audioKey and claim the slot by recording its pid. $extraArgs e.g. ['retry'].
// Returns true if a worker was spawned, false if it should stay queued.
function ttsClaimBuildSlot(PDO $db, int $audioKey, array $extraArgs = [], int $maxConcurrent = 1): bool {
    $workerScript = __DIR__ . '/admin-tts-build-worker.php';
    if (!file_exists($workerScript)) return false;
    $db->query('SELECT pg_advisory_lock(' . TTS_BUILD_LOCK . ')');
    try {
        if (ttsActiveBuilds($db) >= $maxConcurrent) return false;
        $logFile = sys_get_temp_dir() . '/tts_build_' . $audioKey . '.log';
        $pid = spawnCappedWorker($workerScript, array_merge([(string)$audioKey], $extraArgs), $logFile, [
            'cpu_secs' => 3600, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid <= 0) return false;
        $db->prepare("UPDATE yy_tts_audio SET tts_audio_worker_pid = ? WHERE tts_audio_key = ?")
           ->execute([$pid, $audioKey]);
        return true;
    } finally {
        $db->query('SELECT pg_advisory_unlock(' . TTS_BUILD_LOCK . ')');
    }
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
// Must sit ABOVE the POST-required gate.
//
// Each paragraph is classified into a status the admin can trust at a
// glance, so a chapter that is FULLY built doesn't look half-broken:
//
//   voiced     → has audio (a marker exists for its paragraph_number)
//   merged     → page-break continuation tail; the worker coalesces it
//                into the preceding "head" paragraph, so it shares the
//                head's clip and has no part of its own — BY DESIGN
//   skip_table → parser flagged it a table; not narrated — BY DESIGN
//   skip_page  → inside the volume's volume_skip_pages range, or part of
//                the volume's trailing RESOURCES back matter — BY DESIGN
//   skip_admin → admin Deleted/skipped it (settings.skip_paragraphs)
//   empty      → no speakable text after the synth pipeline drops word
//                definitions / unspeakable glyphs (e.g. a lone "hwhy" or
//                an all-(definition) line) — nothing to narrate, BY DESIGN
//   missing    → none of the above and still no audio → a GENUINE gap
//                that warrants a rebuild
//
// ⚠ Coverage is keyed on paragraph_NUMBER, never paragraph_key: many
// historical builds wrote markers with a NULL paragraph_key (the
// page-break path and pre-key-fix builds), and a re-parse churns keys
// while numbers are stable. The marker table is the authoritative record
// of what got voiced.
//
// ⚠ The worker's part-file index (p#####.mp3) is the position among the
// paragraphs that SURVIVE its pre-synth filter — i.e. after dropping
// continuation tails, tables, and skip-page rows. A naive row-by-row
// index desyncs at the first such paragraph and mislabels every later
// paragraph as "not synthesised". We replicate that filter below so
// part_url / redo / delete address the right file.
if ($method === 'GET' && $action === 'list_paragraphs') {
    $audioKey = (int)($_GET['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');
    $stmt = $db->prepare("SELECT chapter_key, volume_key, tts_key, tts_profile_key, tts_audio_failed_paragraphs, tts_audio_settings, tts_audio_status, tts_audio_path, tts_audio_started_dtime FROM yy_tts_audio WHERE tts_audio_key = ?");
    $stmt->execute([$audioKey]);
    $a = $stmt->fetch();
    if (!$a) errorResponse('not found', 404);
    $chapterKey = (int)$a['chapter_key'];
    $volumeKey  = (int)($a['volume_key'] ?? 0);
    // A narratable paragraph is COMPLETE (has audio you can play now),
    // PROCESSING (this chapter is building and this paragraph isn't done
    // this run) or PENDING (chapter queued). It follows the CHAPTER's build
    // state — old markers/clips from a previous version are stale (a rebuild
    // makes new ones) so they never mark a paragraph "complete" on their own.
    $chapterStatus  = (string)($a['tts_audio_status'] ?? '');
    $chapterRunning = ($chapterStatus === 'running');
    $chapterDone    = ($chapterStatus === 'complete');
    // For a RUNNING build, a clip counts as produced-this-run only if it was
    // written at/after this build started — so reused-but-stale old clips
    // from a prior build read as "processing", not a premature "complete".
    $startedEpoch = !empty($a['tts_audio_started_dtime']) ? strtotime((string)$a['tts_audio_started_dtime']) : 0;
    $failedSet = array_flip(array_map('intval',
        preg_split('/[,{}]/', (string)($a['tts_audio_failed_paragraphs'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)));
    $settings = json_decode((string)($a['tts_audio_settings'] ?? '{}'), true) ?: [];
    $skipSet = array_flip(array_map('intval', $settings['skip_paragraphs'] ?? []));

    // Voiced set — paragraph_numbers that have a marker (authoritative).
    $mkStmt = $db->prepare("SELECT DISTINCT paragraph_number FROM yy_tts_audio_marker WHERE tts_audio_key = ? AND paragraph_number IS NOT NULL");
    $mkStmt->execute([$audioKey]);
    $voicedSet = array_flip(array_map('intval', $mkStmt->fetchAll(PDO::FETCH_COLUMN)));

    // volume_skip_pages ranges — same parsing the build worker uses.
    $skipRanges = [];
    if ($volumeKey) {
        $sr = $db->prepare("SELECT volume_skip_pages FROM yy_volume WHERE volume_key = ?");
        $sr->execute([$volumeKey]);
        foreach (preg_split('/\s*,\s*/', (string)($sr->fetchColumn() ?: ''), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $tok, $m))  $skipRanges[] = [(int)$m[1], (int)$m[2]];
            elseif (preg_match('/^\s*(\d+)\s*$/', $tok, $m))         $skipRanges[] = [(int)$m[1], (int)$m[1]];
        }
    }
    $inSkipRange = function (?int $pg) use ($skipRanges): bool {
        if ($pg === null) return false;
        foreach ($skipRanges as $r) if ($pg >= $r[0] && $pg <= $r[1]) return true;
        return false;
    };

    // Trailing back matter (the closing RESOURCES page) — filtered by the
    // worker exactly like a skip-page row, so it must classify as one here
    // too, or the panel calls every line of it a GENUINE gap.
    $backMatterFrom = $volumeKey ? ttsBackMatterCutoff($db, (int)$volumeKey) : null;
    $isBackMatter = function (int $num) use ($backMatterFrom): bool {
        return $backMatterFrom !== null && $num >= $backMatterFrom;
    };

    $pStmt = $db->prepare("
        SELECT paragraph_number, paragraph_page, paragraph_text_plain, paragraph_text_html, paragraph_is_table, paragraph_is_continuation
          FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number
    ");
    $pStmt->execute([$chapterKey]);
    $rows = $pStmt->fetchAll();

    // Voice config — only needed to tell a by-design empty paragraph apart
    // from a genuinely-missing one. Best-effort: if it can't load we treat
    // every unvoiced non-skip paragraph as speakable (i.e. err toward
    // "missing" so a real gap is never hidden).
    $cfg = null; $cfgErr = '';
    try { $cfg = loadTtsConfig($db, (int)($a['tts_key'] ?? 0), $a['tts_profile_key'] !== null ? (int)$a['tts_profile_key'] : null); }
    catch (Throwable $e) { $cfgErr = $e->getMessage(); }

    // Pre-pass: which paragraph_numbers collapse to NO speakable audio?
    // This must mirror the worker exactly, because whether a paragraph
    // produces sound depends on carry state THREADED across the chapter:
    // a word-definition parenthesis (read aloud=false) opened in one
    // paragraph silences the tail that closes it in the next. Running the
    // pipeline per-paragraph in isolation (fresh carry) wrongly reports
    // those tails as speakable → false "missing". So: coalesce
    // continuation tails into their head (worker step 1), skip table /
    // skip-page rows (filtered before the worker's synth loop, carry
    // untouched), then thread one $carry through preprocessFontFilter →
    // segmentParagraph → collapse-skipped → drop-unspeakable in reading
    // order. A survivor that yields nothing is empty BY DESIGN.
    $emptyNums = [];
    if ($cfg !== null) {
        $merged = [];
        foreach ($rows as $r) {
            if (!empty($r['paragraph_is_continuation']) && $merged) {
                $h =& $merged[count($merged) - 1];
                $h['paragraph_text_html'] = rtrim((string)$h['paragraph_text_html']) . ' ' . ltrim((string)($r['paragraph_text_html'] ?? ''));
                unset($h);
                continue;
            }
            $merged[] = $r;
        }
        $fonts = $cfg['fonts'] ?? [];
        $carry = [];
        foreach ($merged as $r) {
            $pg = $r['paragraph_page'] !== null ? (int)$r['paragraph_page'] : null;
            if (!empty($r['paragraph_is_table']) || $inSkipRange($pg)
                || $isBackMatter((int)$r['paragraph_number'])) continue;   // not narrated; carry untouched
            $html = preprocessFontFilter((string)($r['paragraph_text_html'] ?? ''), $fonts);
            $segs = segmentParagraph($html, $carry);
            // Same carry bound as the worker — a COMPLETE paragraph may not leak
            // an open delimiter into the next one. Must match the worker exactly
            // or this pre-pass reports a status synthesis doesn't produce.
            ttsBoundCarryAtParagraphEnd($html, $carry);
            if ($segs) $segs = ttsCollapseSkippedSegments($cfg, $segs);
            if ($segs) $segs = ttsDropUnspeakableSegments($segs);
            if (empty($segs)) $emptyNums[(int)$r['paragraph_number']] = true;
        }
    }

    $audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
    $partsDir  = $audioBase . '/u/tts-parts/' . $audioKey;

    // Does the assembled chapter MP3 exist on disk? In a COMPLETE chapter a
    // paragraph whose own per-clip was purged is still genuinely playable
    // (inside the chapter file), so it stays "complete". Only consulted for
    // complete chapters (see $playableNow below).
    $finalPath   = (string)($a['tts_audio_path'] ?? '');
    $finalExists = $finalPath !== '' && is_file($audioBase . $finalPath) && filesize($audioBase . $finalPath) > 0;

    // Pass 1: replicate the worker's part-index assignment (survivors of
    // the coalesce + table/skip-page filter). part_idx is the paragraph's
    // OWN file index; a continuation tail borrows its head's index purely
    // so its merged audio is still playable in the panel.
    $partIdxByNum  = [];   // paragraph_number → own part idx
    $playIdxByNum  = [];   // paragraph_number → idx whose part to PLAY
    $headNumByCont = [];   // continuation paragraph_number → head paragraph_number
    $widx = 0; $lastHeadIdx = null; $lastHeadNum = null;
    foreach ($rows as $r) {
        $num = (int)$r['paragraph_number'];
        $pg  = $r['paragraph_page'] !== null ? (int)$r['paragraph_page'] : null;
        if (!empty($r['paragraph_is_continuation'])) {
            if ($lastHeadIdx !== null) { $playIdxByNum[$num] = $lastHeadIdx; $headNumByCont[$num] = $lastHeadNum; }
            continue;                                   // coalesced — no own index
        }
        if (!empty($r['paragraph_is_table']) || $inSkipRange($pg) || $isBackMatter($num)) continue;   // filtered before synth
        $partIdxByNum[$num] = $widx;
        $playIdxByNum[$num] = $widx;
        $lastHeadIdx = $widx; $lastHeadNum = $num;
        $widx++;
    }

    $out = [];
    $counts = ['complete'=>0,'processing'=>0,'pending'=>0,'merged'=>0,'skip_table'=>0,'skip_page'=>0,'skip_admin'=>0,'empty'=>0,'missing'=>0];
    $idx = 0;
    foreach ($rows as $r) {
        $num     = (int)$r['paragraph_number'];
        $pg      = $r['paragraph_page'] !== null ? (int)$r['paragraph_page'] : null;
        $isTable = !empty($r['paragraph_is_table']);
        $isCont  = !empty($r['paragraph_is_continuation']);
        $isSkipAdmin = isset($skipSet[$num]);
        $isSkipPage  = $inSkipRange($pg) || $isBackMatter($num);
        $voiced  = isset($voicedSet[$num]);

        $partIdx = $partIdxByNum[$num] ?? null;             // own file (redo/delete target)
        $playIdx = $playIdxByNum[$num] ?? null;             // file to play (own or head's)
        $hasPart = false; $partUrl = null; $partBytes = 0; $partMtime = 0;
        if ($playIdx !== null) {
            $partName = sprintf('p%05d.mp3', $playIdx);
            $partFile = $partsDir . '/' . $partName;
            if (is_file($partFile) && filesize($partFile) > 0) {
                $hasPart = true;
                $partBytes = (int)filesize($partFile);
                $partMtime = (int)@filemtime($partFile);
                // ?v=<mtime> cache-buster — a Rebuild/Redo overwrites the part
                // at the SAME path; without it the browser replays stale bytes.
                $partUrl = '/u/tts-parts/' . $audioKey . '/' . $partName . '?v=' . $partMtime;
            }
        }

        // Did this paragraph actually get VOICED? Its own per-paragraph clip, or
        // a marker (markers survive the post-assembly purge of the per-clip
        // files, so they're the authoritative record — see the header note).
        //
        // ⚠ The chapter MP3 existing is NOT evidence that THIS paragraph is in
        // it. Treating it as such ($hasPart || ($chapterDone && $finalExists))
        // made the "missing" alarm below unreachable for every complete chapter
        // — a paragraph the worker never synthesised still reported "complete",
        // which is precisely what hid the ~900 paragraphs the unclosed-paren
        // carry leak was silently dropping. Only fall back to the chapter file
        // for a LEGACY build that wrote no per-paragraph markers at all;
        // otherwise absence of a marker is a real gap and must be alarmed.
        $chapterHasMarkers = !empty($voicedSet);
        $wasVoiced = $hasPart || $voiced || ($chapterDone && $finalExists && !$chapterHasMarkers);

        // Classify. Structural not-narrated cases (admin skip / continuation
        // / table / skip-page / no speakable text) first — they're by design
        // and independent of build state. Then the narratable paragraph's
        // state follows its CHAPTER:
        //   complete chapter → complete (or a genuine gap if it produced no
        //                      audio at all → "missing", the real alarm)
        //   running chapter  → complete once its clip is produced THIS run,
        //                      else processing
        //   pending chapter  → pending (old markers/clips are stale — ignore)
        if ($isSkipAdmin)                $status = 'skip_admin';
        elseif ($isCont)                 $status = 'merged';
        elseif ($isTable)                $status = 'skip_table';
        elseif ($isSkipPage)             $status = 'skip_page';
        elseif (isset($emptyNums[$num])) $status = 'empty';
        elseif ($chapterDone)            $status = $wasVoiced ? 'complete' : 'missing';
        elseif ($chapterRunning)         $status = ($hasPart && $partMtime >= $startedEpoch) ? 'complete' : 'processing';
        else                             $status = 'pending';   // queued for generation

        // Only offer a per-paragraph player when the clip is genuinely
        // current — never for a stale clip left over on a pending rebuild.
        $showPlayer = ($status === 'complete' && $hasPart
                        && ($chapterDone || ($chapterRunning && $partMtime >= $startedEpoch)));
        if (!$showPlayer) { $partUrl = null; }

        $counts[$status] = ($counts[$status] ?? 0) + 1;

        $textPlain = (string)($r['paragraph_text_plain'] ?? '');
        $preview = mb_substr(preg_replace('/\s+/u', ' ', $textPlain), 0, 140);
        if (mb_strlen($textPlain) > 140) $preview .= '…';

        $out[] = [
            'idx'              => $idx,                       // stable per-row DOM identity
            'part_idx'         => $partIdx,                   // worker part index (redo/delete); null = no own part
            'paragraph_number' => $num,
            'paragraph_page'   => $pg,
            'status'           => $status,
            'is_table'         => $isTable,
            'is_continuation'  => $isCont,
            'is_failed'        => isset($failedSet[$num]),
            'is_skipped'       => $isSkipAdmin,
            'cont_head_number' => $isCont ? ($headNumByCont[$num] ?? null) : null,
            'preview'          => $preview,
            'has_part'         => $showPlayer,       // whether to offer a per-paragraph player (current clip only)
            'part_url'         => $partUrl,
            'part_bytes'       => $partBytes,
        ];
        $idx++;
    }
    jsonResponse(['audio_key' => $audioKey, 'paragraphs' => $out, 'counts' => $counts, 'config_error' => $cfgErr]);
}

if ($method !== 'POST') errorResponse('POST required');

if ($action === 'start') {
    $ttsKey     = (int)($data['tts_key']     ?? 0);
    $volumeKey  = (int)($data['volume_key']  ?? 0);
    $chapterKey = (int)($data['chapter_key'] ?? 0);
    // Profile selection — omitted = default profile. The build modal
    // passes the profile_key from its Profile picker so each chapter can
    // have one audio file per profile (Roger-default, Charlie-warm,
    // etc.). The audio row records which profile produced the file.
    $profileKey = (int)($data['profile_key'] ?? 0) ?: null;
    if (!$ttsKey || !$volumeKey || !$chapterKey) errorResponse('tts_key, volume_key, chapter_key required');

    // Snapshot the current category-voice config (for the chosen profile)
    // so the worker uses a stable picture even if admin edits voices mid-build.
    $cfg = loadTtsConfig($db, $ttsKey, $profileKey);
    if (!$cfg['system']) errorResponse('unknown tts_key', 404);
    $profileKey = (int)$cfg['profile_key'];  // resolved to actual key (default if not supplied)

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
            // read_flag governs whether this category gets synthesised at
            // all. Picked up by ttsCategoryReadable() in the build worker.
            // Default to TRUE for rows that pre-date the column.
            'read_flag'    => array_key_exists('tts_category_voice_read_flag', $row) ? (bool)$row['tts_category_voice_read_flag'] : true,
        ];
    }
    // Caller-supplied per-category overrides win over the saved defaults.
    foreach ((array)($data['voice_overrides'] ?? []) as $cat => $over) {
        if (!is_array($over)) continue;
        $voice    = (string)($over['voice_code'] ?? '');
        $readFlag = array_key_exists('read_flag', $over) ? (bool)$over['read_flag'] : true;
        // Skip pure-pass-through overrides — the build modal emits a row for
        // EVERY category in the registry (yada, main, translation, ..., nt,
        // paul, lv, kjv, …). Most of those categories are unconfigured
        // (voice='', read_flag=true) and don't deserve a snapshot row.
        // Persisting them anyway BLOCKS the parent-chain walk in the worker:
        // for cat='nt' the walker would see cfg['categories']['nt'] exists
        // (just with empty voice) and stop there instead of inheriting the
        // bible voice — silently routing the segment to provider 1 (Azure)
        // with an empty voice name. Only persist when the override actually
        // configures something (a voice picked, OR an explicit skip).
        if (!isset($catSnapshot[$cat]) && $voice === '' && $readFlag === true) {
            continue;
        }
        $catSnapshot[$cat] = array_merge($catSnapshot[$cat] ?? [], array_intersect_key($over, [
            'voice_code' => 1, 'style' => 1, 'style_degree' => 1, 'rate_pct' => 1, 'pitch_st' => 1, 'volume' => 1, 'read_flag' => 1,
        ]));
        if (array_key_exists('read_flag', $over)) {
            $catSnapshot[$cat]['read_flag'] = (bool)$over['read_flag'];
        }
    }

    $settings = [
        'output_format' => $outputFormat,
        'region'        => $cfg['system']['tts_region'] ?? null,
        'categories'    => $catSnapshot,
        'tts_code'      => $cfg['system']['tts_code'],
        'profile_key'   => $profileKey,
    ];

    // Cancel any existing pending/running build for this slot+profile before queuing.
    // Scope by profile_key so a build of "Default" profile doesn't kill a
    // running "Warm" profile build on the same chapter — multi-track is
    // the whole point.
    $db->prepare("
        UPDATE yy_tts_audio
           SET tts_audio_status = 'failed',
               tts_audio_error  = 'superseded by new build',
               tts_audio_completed_dtime = NOW()
         WHERE tts_key = ? AND volume_key = ? AND chapter_key = ? AND tts_profile_key = ?
           AND tts_audio_status IN ('pending', 'running')
    ")->execute([$ttsKey, $volumeKey, $chapterKey, $profileKey]);

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
            (tts_key, volume_key, chapter_key, tts_profile_key, tts_audio_status, tts_audio_progress,
             tts_audio_message, tts_audio_settings, tts_audio_started_dtime)
        VALUES (?, ?, ?, ?, 'pending', 0, 'Queued', ?::jsonb, NOW())
        ON CONFLICT (tts_key, volume_key, chapter_key, tts_profile_key) WHERE chapter_key IS NOT NULL
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
    $insStmt->execute([$ttsKey, $volumeKey, $chapterKey, $profileKey, json_encode($settings)]);
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
    $queued = !ttsClaimBuildSlot($db, $audioKey, [], $maxConcurrent);
    if ($queued) {
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
    $profileKey = (int)($data['profile_key'] ?? 0) ?: null;
    if (!$ttsKey || !$volumeKey) errorResponse('tts_key and volume_key required');
    if (!in_array($scope, ['all', 'missing', 'failed'], true)) errorResponse('scope must be all|missing|failed');

    $cfg = loadTtsConfig($db, $ttsKey, $profileKey);
    if (!$cfg['system']) errorResponse('unknown tts_key', 404);
    $profileKey = (int)$cfg['profile_key'];

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
            'read_flag'    => array_key_exists('tts_category_voice_read_flag', $row) ? (bool)$row['tts_category_voice_read_flag'] : true,
        ];
    }
    foreach ((array)($data['voice_overrides'] ?? []) as $cat => $over) {
        if (!is_array($over)) continue;
        $voice    = (string)($over['voice_code'] ?? '');
        $readFlag = array_key_exists('read_flag', $over) ? (bool)$over['read_flag'] : true;
        // Same parent-walk preservation as the single-chapter path above —
        // don't persist empty-voice overrides for unconfigured categories,
        // they'd otherwise block the registry parent chain in the worker.
        if (!isset($catSnapshot[$cat]) && $voice === '' && $readFlag === true) {
            continue;
        }
        $catSnapshot[$cat] = array_merge($catSnapshot[$cat] ?? [], array_intersect_key($over, [
            'voice_code' => 1, 'style' => 1, 'style_degree' => 1, 'rate_pct' => 1, 'pitch_st' => 1, 'volume' => 1, 'read_flag' => 1,
        ]));
        if (array_key_exists('read_flag', $over)) {
            $catSnapshot[$cat]['read_flag'] = (bool)$over['read_flag'];
        }
    }
    $settings = [
        'output_format' => $outputFormat,
        'region'        => $cfg['system']['tts_region'] ?? null,
        'categories'    => $catSnapshot,
        'tts_code'      => $cfg['system']['tts_code'],
        'profile_key'   => $profileKey,
    ];

    // Resolve chapter list to enqueue. JOIN on tts_profile_key so the
    // status returned is THIS profile's status (a chapter may have a
    // completed audio for the default profile and still need a build for
    // a new profile).
    $chStmt = $db->prepare("
        SELECT c.chapter_key, c.chapter_number, a.tts_audio_status
          FROM yy_chapter c
     LEFT JOIN yy_tts_audio a
            ON a.tts_key = ? AND a.volume_key = c.volume_key AND a.chapter_key = c.chapter_key
           AND a.tts_profile_key = ?
         WHERE c.volume_key = ?
         ORDER BY c.chapter_sort, c.chapter_number
    ");
    $chStmt->execute([$ttsKey, $profileKey, $volumeKey]);
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

    // scope='all' = "Build all chapters" = clean slate: wipe every existing
    // rendering AND queued row for this volume (all profiles) — DB rows,
    // markers, live MP3s, and parts caches — before enqueueing the fresh
    // whole-book build below. scope='missing'/'failed' deliberately skip
    // this so they preserve the chapters they aren't rebuilding.
    if ($scope === 'all') {
        ttsPurgeVolume($db, $ttsKey, $volumeKey);
    }

    // Upsert each filtered chapter's row using the same INSERT…ON CONFLICT
    // as the single-chapter path (intentionally repeated rather than
    // extracted — diverging the two paths would just be noise).
    $cancel = $db->prepare("
        UPDATE yy_tts_audio
           SET tts_audio_status = 'failed',
               tts_audio_error  = 'superseded by new build',
               tts_audio_completed_dtime = NOW()
         WHERE tts_key = ? AND volume_key = ? AND chapter_key = ? AND tts_profile_key = ?
           AND tts_audio_status IN ('pending', 'running')
    ");
    $insStmt = $db->prepare("
        INSERT INTO yy_tts_audio
            (tts_key, volume_key, chapter_key, tts_profile_key, tts_audio_status, tts_audio_progress,
             tts_audio_message, tts_audio_settings, tts_audio_started_dtime)
        VALUES (?, ?, ?, ?, 'pending', 0, 'Queued (build-all)', ?::jsonb, NOW())
        ON CONFLICT (tts_key, volume_key, chapter_key, tts_profile_key) WHERE chapter_key IS NOT NULL
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
        $cancel->execute([$ttsKey, $volumeKey, (int)$c['chapter_key'], $profileKey]);
        $insStmt->execute([$ttsKey, $volumeKey, (int)$c['chapter_key'], $profileKey, json_encode($settings)]);
        $ak = (int)$insStmt->fetchColumn();
        $audioKeys[] = $ak;
        // Fresh build per chapter — wipe parts so the worker re-synthesises
        // every paragraph (mirrors the single-chapter start branch above).
        ttsWipePartsDir($ak);
    }

    // Spawn workers up to the cap. Anything still pending is picked up by
    // the queue-promoter at the end of admin-tts-build-worker.php.
    $maxConcurrent = 1;
    $spawned = 0;
    foreach ($audioKeys as $ak) {
        // Each claim re-checks the slot under the lock; once the cap is hit the
        // rest stay 'pending' and get promoted by the worker's queue-promoter.
        if (ttsClaimBuildSlot($db, $ak, [], $maxConcurrent)) { $spawned++; }
        else { break; }
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
    $queued = !ttsClaimBuildSlot($db, $audioKey, ['retry'], $maxConcurrent);
    if ($queued) {
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
    } else if ($st === 'paused') {
        // User explicitly paused this chapter — a per-paragraph redo MUST
        // NOT override that intent and resume the worker. The part file is
        // already deleted above; that's all we do. When the user manually
        // clicks Resume on the chapter, the missing paragraph gets refilled
        // during the resumed build's first iteration (per-iter cache check
        // in the build worker loop). requeued stays false so the UI shows
        // a quiet "✓" rather than "✓ queued" — matches reality.
        $db->prepare("
            UPDATE yy_tts_audio
               SET tts_audio_message='Paused — paragraph redo deleted; click Resume to gap-fill'
             WHERE tts_audio_key = ?
        ")->execute([$audioKey]);
    } else if (in_array($st, ['complete', 'failed'], true)) {
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
// Build All = clean slate. Purge EVERY existing audio for this volume
// (all profiles): stop any in-flight worker, remove the DB rows + markers,
// the live MP3 files (+ .staging), and the per-paragraph parts caches.
// Without this a whole-book build layers a new profile's queue on top of
// stale renderings from a prior profile (the orphaned-complete-rows bug),
// instead of starting from nothing. Scoped by (tts_key, volume_key) — all
// of a volume's audio shares one tts_key, so this clears the whole book.
function ttsPurgeVolume(PDO $db, int $ttsKey, int $volumeKey): void {
    $audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
    $stmt = $db->prepare("SELECT tts_audio_key, tts_audio_path, tts_audio_worker_pid
                            FROM yy_tts_audio WHERE tts_key = ? AND volume_key = ?");
    $stmt->execute([$ttsKey, $volumeKey]);
    foreach ($stmt->fetchAll() as $r) {
        $ak  = (int)$r['tts_audio_key'];
        // Stop any worker building one of this volume's chapters so it can't
        // resurrect a parts dir or flip a row back to 'running' mid-purge.
        $pid = (int)($r['tts_audio_worker_pid'] ?? 0);
        if ($pid > 0 && function_exists('posix_kill')) {
            @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
        }
        // Live MP3 (+ staging) — strip any ?v=… cache buster defensively.
        $relPath = preg_replace('/\?.*$/', '', (string)($r['tts_audio_path'] ?? ''));
        if ($relPath !== '') {
            $diskPath = $audioBase . $relPath;
            if (is_file($diskPath)) @unlink($diskPath);
            if (is_file($diskPath . '.staging')) @unlink($diskPath . '.staging');
        }
        ttsWipePartsDir($ak);
    }
    // Markers then rows. _rev tables are trigger-maintained — no manual cleanup.
    $db->prepare("DELETE FROM yy_tts_audio_marker
                   WHERE tts_audio_key IN (SELECT tts_audio_key FROM yy_tts_audio WHERE tts_key = ? AND volume_key = ?)")
       ->execute([$ttsKey, $volumeKey]);
    $db->prepare("DELETE FROM yy_tts_audio WHERE tts_key = ? AND volume_key = ?")
       ->execute([$ttsKey, $volumeKey]);
}

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
    if (ttsClaimBuildSlot($db, $audioKey, [], $maxConcurrent)) return true;
    $db->prepare("UPDATE yy_tts_audio SET tts_audio_message = ? WHERE tts_audio_key = ?")
       ->execute(["Queued — waiting for an open slot (limit: $maxConcurrent concurrent)", $audioKey]);
    return false;
}
