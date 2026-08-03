<?php
/**
 * admin-transcript-captionfit.php — "Smart Captions" panel backend.
 *
 * Two post-processing layers over an existing transcript, plus safe undo:
 *
 *   1. AI cleanup (correction loop) — runs a local LLM on the Puget box
 *      (Ollama, qwen2.5:72b by default) line-by-line. It is primed IN-CONTEXT
 *      with the admins' accumulated corrections (yy_transcript_correction) and
 *      glossary (yy_transcript_glossary), so it learns from past edits with no
 *      training. Applied corrections feed autoLearnCorrections() so the loop
 *      tightens over time. (Fine-tune path: action=export_finetune dumps the
 *      raw->corrected pairs as JSONL for a later on-box fine-tune.)
 *
 *   2. Caption re-flow (sizing) — re-segments the transcript into broadcast-
 *      sized cues (default 42 chars x 2 lines, 1.2-7s, ~17 cps), breaking at
 *      punctuation and collapsing YouTube-style overlap/duplication. Pure PHP,
 *      no model needed. Can draw timing from the live transcript or from any
 *      word-level auto run already in the DB (best precision).
 *
 * Every destructive apply snapshots the full live transcript first
 * (yy_transcript_snapshot) so it is one-click reversible.
 *
 * Auth + DB + helpers mirror the other admin-transcript-*.php endpoints.
 * CLI self-test (pure re-flow, no auth/db):  php admin-transcript-captionfit.php selftest
 */
require_once __DIR__ . '/transcript-caption-lib.php';   // pure caption helpers (cfReflow etc.) - CLI-safe

// Pure-logic CLI self-test needs none of the site framework; skip the requires
// so it runs anywhere (the web path below always loads them).
$__cfSelftest = (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === 'selftest');
if (!$__cfSelftest) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/transcript-helpers.php';
    require_once __DIR__ . '/gpu-client.php';
}

// ── Whitelisted on-box LLMs (must already be pulled in Ollama) ───────────────
$LLM_MODELS = [
    ['code' => 'qwen2.5:72b',  'label' => 'Qwen2.5 72B — best Hebrew (heavier, ~45s cold start)'],
    ['code' => 'llama3.3:70b', 'label' => 'Llama 3.3 70B — strong general'],
    ['code' => 'mistral-nemo', 'label' => 'Mistral Nemo 12B — fast & light (weaker Hebrew)'],
];
$LLM_CODES = array_column($LLM_MODELS, 'code');

// Pure caption helpers (cfReflow, cfWrap, cfDedup, cfRowsToWords, timestamp
// math) and $CAPTION_DEFAULTS now live in transcript-caption-lib.php
// (required above) so the consensus Initialize-Edits worker can reuse them.

// ── CLI self-test for the pure re-flow (no auth, no DB) ──────────────────────
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (($argv[1] ?? '') === 'selftest') {
        $rows = [
            ['secs' => 0.0,  'text' => 'so the teacher began to explain the concept of teshuvah which is repentance and the deep importance of doing mitzvot every single day of our lives without exception'],
            ['secs' => 11.0, 'text' => 'and he said that the path the path the path of righteousness begins with a single honest step'],
        ];
        $opts = cfDefaults();
        // pause-aware spread so break_gap can fire on the edited multi-word rows
        $words = cfRowsToWords($rows, ['cps' => (float)$opts['cps']]);
        $cues = cfReflow($words, $opts);
        foreach ($cues as $i => $c) {
            fwrite(STDOUT, sprintf("[%2d] %s  (%dc/%dL)\n     %s\n",
                $i, cfSecsToInterval($c['start']), $c['chars'], $c['lines'],
                str_replace("\n", " ⏎ ", $c['text'])));
        }
        fwrite(STDOUT, count($cues) . " cues from " . count($words) . " words (dedup of 'the path' rollup should leave one)\n");
        exit(0);
    }
    fwrite(STDERR, "usage: php admin-transcript-captionfit.php selftest\n");
    exit(1);
}

// ── Web entrypoint ───────────────────────────────────────────────────────────
$user = requireAuth();
$db = getDb();
if (function_exists('setCurrentUser')) setCurrentUser($db, (int)$user['user_key']);

$method = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input');
$data = $raw ? (json_decode($raw, true) ?: []) : [];
$action = $data['action'] ?? ($_GET['action'] ?? '');
$itemKey = (int)($data['item_key'] ?? $_GET['item_key'] ?? 0);

// ── Auto-Fix scoping ─────────────────────────────────────────────────────────
// The "Auto-Fix" button (formerly "Smart Captions") runs on only the segments
// the operator ticked in the transcript grid's Excerpt column. The frontend
// sends their feed_item_transcript_key values as scope_keys; an empty/absent
// list means the whole transcript (the operator confirmed a full-transcript
// run at the button). The Excerpt selection is always a contiguous run, so the
// scoped rows form a single time window.
function cfParseScope(array $data): array {
    $out = [];
    $raw = $data['scope_keys'] ?? null;
    if (is_array($raw)) {
        foreach ($raw as $k) { $k = (int)$k; if ($k > 0) $out[$k] = true; }
    }
    return array_keys($out);
}
// Given the full live rows and the ticked scope keys, return
// [selectedRows, windowStartSecs, windowEndSecs]. The end is the start of the
// first live row AFTER the last selected one (INF when the selection reaches
// the end) so a timing-source re-flow can be clipped to exactly the span.
function cfSelectScope(array $live, array $scopeKeys): array {
    $set = array_flip(array_map('intval', $scopeKeys));
    $sel = []; $lastIdx = -1;
    foreach ($live as $i => $r) {
        if (isset($set[(int)$r['key']])) { $sel[] = $r; $lastIdx = $i; }
    }
    if (!$sel) return [[], null, null];
    $t0 = (float)$sel[0]['secs'];
    $t1 = isset($live[$lastIdx + 1]) ? (float)$live[$lastIdx + 1]['secs'] : INF;
    return [$sel, $t0, $t1];
}
$scopeKeys = cfParseScope($data);   // ticked Excerpt rows; [] = whole transcript

// ── DB helpers ───────────────────────────────────────────────────────────────

// DB + alignment + prompt helpers (cfLoadLive … cfBuildMessages) now live in
// transcript-caption-lib.php (required above) so the consensus init worker can
// reuse the exact same multi-baseline reconciliation logic (Layer 3).

// ── Actions ──────────────────────────────────────────────────────────────────

if ($action === 'config') {
    if (!$itemKey) errorResponse('item_key required');
    global $LLM_MODELS, $CAPTION_DEFAULTS;
    $live = cfLoadLive($db, $itemKey);
    jsonResponse([
        'item_key'        => $itemKey,
        'live_rows'       => count($live),
        'llm_models'      => $LLM_MODELS,
        'caption_defaults'=> $CAPTION_DEFAULTS,
        'timing_sources'  => array_merge(
            [['code' => 'current', 'rows' => count($live), 'word_level' => false, 'label' => 'Current transcript']],
            array_map(fn($m) => $m + ['label' => $m['code'] . ($m['word_level'] ? ' (word-level)' : '')],
                      cfAutoModels($db, $itemKey))
        ),
        // Per-engine STT baselines the AI cleanup can cross-reference for
        // consensus (word-level ones align tightest). Same list the view
        // selector shows, minus the live/current transcript.
        'baselines'       => cfAutoModels($db, $itemKey),
    ]);
}

if ($action === 'fit_preview') {
    if (!$itemKey) errorResponse('item_key required');
    global $CAPTION_DEFAULTS;
    $source = (string)($data['source'] ?? 'current');
    $opts = [];
    foreach (['max_chars', 'max_lines', 'preferred_lines', 'max_secs', 'min_secs', 'cps', 'soft_overflow'] as $k) {
        $opts[$k] = array_key_exists($k, $data) ? $data[$k] : $CAPTION_DEFAULTS[$k];
    }
    $opts['break_punct'] = array_key_exists('break_punct', $data) ? (bool)$data['break_punct'] : $CAPTION_DEFAULTS['break_punct'];
    $opts['dedup']       = array_key_exists('dedup', $data) ? (bool)$data['dedup'] : $CAPTION_DEFAULTS['dedup'];
    // Pause break (soft, like punctuation): flush a cue when the silence before
    // the next word is >= break_gap secs. 0 = off. cfReflow keeps it from making
    // tiny fragments (it only breaks once the cue has dwelt >= 0.4s).
    $opts['break_gap']   = array_key_exists('break_gap', $data) ? max(0.0, (float)$data['break_gap']) : $CAPTION_DEFAULTS['break_gap'];
    // Break on speaker change (soft, like punctuation/pause): fold diarised
    // speaker-turn onsets into cfReflow's boundary set. Opt-in, default off.
    $breakSpeaker = array_key_exists('break_speaker', $data) ? (bool)$data['break_speaker'] : false;

    // Scope to the ticked Excerpt segments when present. cfSelectScope gives the
    // selected live rows and their [start,end) time window; a timing-source
    // (auto) re-flow is clipped to that window so only the selection is re-sized.
    $liveAll = cfLoadLive($db, $itemKey);
    $win = $scopeKeys ? cfSelectScope($liveAll, $scopeKeys) : null;
    if ($win !== null && !$win[0]) errorResponse('none of the selected segments were found');
    if ($source === 'current') {
        $rows = $win ? $win[0] : $liveAll;
    } else {
        $rows = cfLoadAuto($db, $itemKey, $source);
        if ($win) $rows = array_values(array_filter($rows,
            fn($r) => (float)$r['secs'] >= $win[1] && (float)$r['secs'] < $win[2]));
    }
    if (!$rows) errorResponse('no rows for source: ' . $source);
    // Speaker labels always live on the editable rows, so build the timeline
    // from the live rows in scope (the whole transcript when unscoped).
    $liveRows = $win ? $win[0] : $liveAll;
    $spkTl    = cfSpeakerTimeline($liveRows);
    if ($breakSpeaker && $spkTl) {
        $bset = []; $prev = null;
        foreach ($spkTl as $e) {
            if ($e[1] !== $prev) { $bset[] = round((float)$e[0], 2); $prev = $e[1]; }
        }
        $bset = array_values(array_unique($bset)); sort($bset);
        $opts['boundary_set'] = $bset;
    }
    // When pause-breaking is on, make the word spread pause-aware (chars/cps) so
    // genuine silences in the *edited* transcript become detectable gaps rather
    // than being absorbed by the fill-the-gap default.
    $w2wOpts = ($opts['break_gap'] > 0) ? ['cps' => (float)$opts['cps']] : [];
    $words = cfRowsToWords($rows, $w2wOpts);
    $cues = cfReflow($words, $opts);
    // Re-anchor timing to a word-level baseline. Re-flowing the EDITED text
    // (source 'current') derives each cue's start by interpolating the old row
    // times, which are only as good as the current segmentation (and are flat-
    // wrong on the legacy 30s-grid items). When a word-level baseline exists,
    // snap each new cue's start to the baseline time of its first word(s) — the
    // accurate source. FAIL-SAFE: cfSnapStartsToBaseline returns the cues
    // unchanged if the baseline can't be aligned confidently, so a good
    // interpolation is never degraded. Only for whole-transcript re-flows
    // ($win === null): a scoped slice's word-count alignment can't be seeded
    // reliably, so scoped re-sizes keep their (local, usually fine) timing.
    $snapStats = ['applied' => false];
    if ($source === 'current' && $win === null) {
        $wb = cfBestWordBaseline($db, $itemKey);
        if ($wb) {
            $bstream = cfBaselineWordStream($db, $itemKey, $wb);
            if ($bstream) { $cues = cfSnapStartsToBaseline($cues, $bstream, $snapStats); $snapStats['baseline'] = $wb; }
        }
    }
    // Stamp each cue with the speaker active at its start so the 👤 column
    // survives re-sizing (NULL when the transcript has no speaker labels).
    $out = array_map(fn($c) => [
        'segment' => cfSecsToInterval($c['start']),
        'secs'    => round((float)$c['start'], 3),
        'text'    => $c['text'],
        'chars'   => $c['chars'],
        'lines'   => $c['lines'],
        'speaker' => $spkTl ? cfSpeakerAt($spkTl, (float)$c['start']) : null,
    ], $cues);
    // The "Current" column of the side-by-side preview is always the LIVE
    // editable segmentation (what the transcript is now), regardless of which
    // timing source feeds the re-flow — so reuse the live rows loaded above.
    $beforeRows = $liveRows;
    $before = array_map(fn($r) => [
        'segment' => $r['segment'],
        'secs'    => round((float)$r['secs'], 3),
        'text'    => $r['text'],
    ], $beforeRows);
    jsonResponse(['source' => $source, 'in_rows' => count($rows), 'in_words' => count($words),
                  'cues' => $out, 'count' => count($out), 'before' => $before,
                  'timing_snap' => $snapStats]);
}

if ($action === 'fit_apply') {
    if ($method !== 'POST') errorResponse('POST only', 405);
    if (!$itemKey) errorResponse('item_key required');
    $cues = $data['cues'] ?? [];
    if (!is_array($cues) || !$cues) errorResponse('cues required');
    $clean = [];
    foreach ($cues as $c) {
        if (!isset($c['segment'], $c['text'])) continue;
        $spk = (array_key_exists('speaker', $c) && $c['speaker'] !== '' && $c['speaker'] !== null)
             ? (string)$c['speaker'] : null;
        $clean[] = ['segment' => (string)$c['segment'], 'text' => (string)$c['text'], 'speaker' => $spk];
    }
    if ($scopeKeys) {
        // Scoped re-flow: replace ONLY the selected rows with the new cues and
        // keep the rest of the transcript. Rebuild the full row set (kept rows +
        // new cues, ordered by time) and hand it to cfReplaceLive so the sort
        // sequence and de-dup guard stay identical to a whole-transcript apply.
        $liveAll = cfLoadLive($db, $itemKey);
        $set = array_flip(array_map('intval', $scopeKeys));
        $merged = [];
        foreach ($liveAll as $r) {
            if (isset($set[(int)$r['key']])) continue;          // dropped — re-flowed below
            $merged[] = ['segment' => $r['segment'], 'text' => $r['text'],
                         'speaker' => $r['speaker'], '_secs' => (float)$r['secs']];
        }
        foreach ($clean as $c) {
            $merged[] = $c + ['_secs' => cfIntervalToSecs((string)$c['segment'])];
        }
        usort($merged, fn($a, $b) => $a['_secs'] <=> $b['_secs']);
        $final = array_map(fn($c) => ['segment' => $c['segment'], 'text' => $c['text'],
                                      'speaker' => $c['speaker'] ?? null], $merged);
        $snap = cfSnapshot($db, $itemKey, (int)$user['user_key'], 'before caption re-flow (selected segments)');
        $inserted = cfReplaceLive($db, $itemKey, $final);
        jsonResponse(['inserted' => $inserted, 'snapshot_key' => $snap, 'scoped' => count($scopeKeys)]);
    }
    $snap = cfSnapshot($db, $itemKey, (int)$user['user_key'], 'before caption re-flow');
    $inserted = cfReplaceLive($db, $itemKey, $clean);
    jsonResponse(['inserted' => $inserted, 'snapshot_key' => $snap]);
}

// ── LLM warm-up handshake (prevents Cloudflare 524 on cold model load) ───────
// A cold 70B model can take >100s to load into memory on the box — longer than
// Cloudflare's proxy timeout — so the very first ai_chunk used to return a 524
// HTML page to the browser even though the load eventually succeeded. Instead
// the UI calls `warm` (fire-and-forget, returns instantly) then polls
// `llm_status` (fast) until the model is resident, so no single browser request
// ever blocks on the load.
if ($action === 'llm_status') {
    global $LLM_CODES;
    $model = (string)($data['model'] ?? $_GET['model'] ?? '');
    $r = gpuOllamaRequest('GET', '/api/ps', null, 8);   // resident models — fast
    $loaded = [];
    if (!empty($r['ok'])) {
        foreach (($r['data']['models'] ?? []) as $m) {
            $n = $m['name'] ?? ($m['model'] ?? '');
            if ($n !== '') $loaded[] = $n;
        }
    }
    // Ollama reports an untagged pull as "<name>:latest" in /api/ps, but the
    // whitelist codes are bare (e.g. "mistral-nemo"). Compare with the ":latest"
    // suffix normalized off so a resident model is correctly seen as ready.
    $normTag = fn($s) => preg_replace('/:latest$/', '', (string)$s);
    $ready = false;
    if ($model !== '') {
        foreach ($loaded as $ln) { if ($normTag($ln) === $normTag($model)) { $ready = true; break; } }
    }
    jsonResponse([
        'ok'     => !empty($r['ok']),
        'ready'  => $ready,
        'loaded' => $loaded,
        'error'  => empty($r['ok']) ? ($r['error'] ?? 'status check failed') : null,
    ]);
}

if ($action === 'warm') {
    if ($method !== 'POST') errorResponse('POST only', 405);
    global $LLM_CODES;
    $model = (string)($data['model'] ?? '');
    if (!in_array($model, $LLM_CODES, true)) errorResponse('unknown model: ' . $model);
    // Detached preload: Ollama loads the model on an empty /api/generate and
    // holds it warm via keep_alive. The nohup + </dev/null detach (same idiom
    // as the STT queue's spawnCappedWorker) lets this HTTP response return at
    // once while the box loads in the background — a follow-up llm_status poll
    // reports when it is resident.
    // ⚠ MUST pass the same num_ctx ai_chunk will use: without it Ollama reserves
    // the model's full default KV cache (~50 GiB) and OOMs on the RAM-full box
    // (never loading); and a mismatched ctx would force ai_chunk to reload cold.
    $baselines = $data['baselines'] ?? [];
    $numCtx = (is_array($baselines) && count($baselines)) ? 16384 : 8192;
    $url     = gpuOllamaUrl() . '/api/generate';
    $payload = json_encode(['model' => $model, 'keep_alive' => '20m',
                            'options' => ['num_ctx' => $numCtx]], JSON_UNESCAPED_SLASHES);
    $inner   = 'curl -s --max-time 600 -X POST ' . escapeshellarg($url)
             . ' -H ' . escapeshellarg('Content-Type: application/json')
             . ' -d ' . escapeshellarg($payload);
    @exec('nohup bash -c ' . escapeshellarg($inner) . ' > /dev/null 2>&1 < /dev/null &');
    jsonResponse(['ok' => true, 'warming' => true, 'model' => $model]);
}

if ($action === 'ai_chunk') {
    if ($method !== 'POST') errorResponse('POST only', 405);
    if (!$itemKey) errorResponse('item_key required');
    global $LLM_CODES;
    $model = (string)($data['model'] ?? 'qwen2.5:72b');
    if (!in_array($model, $LLM_CODES, true)) errorResponse('unknown model: ' . $model);
    $offset = max(0, (int)($data['offset'] ?? 0));
    $limit  = min(60, max(1, (int)($data['limit'] ?? 40)));

    // Optional cross-reference baselines: other STT engines' runs of the same
    // audio, time-aligned per live line so the LLM can reconcile disagreements
    // (consensus decoding) rather than proofread one noisy hypothesis blind.
    $baselineCodes = $data['baselines'] ?? [];
    if (!is_array($baselineCodes)) $baselineCodes = [];
    $availCodes = array_column(cfAutoModels($db, $itemKey), 'code');
    $baselineCodes = array_values(array_filter(
        array_map('strval', $baselineCodes),
        fn($c) => in_array($c, $availCodes, true)
    ));

    $live = cfLoadLive($db, $itemKey);
    // Scope to the ticked Excerpt segments when present — the chunk loop then
    // walks only the selection (baseline alignment re-indexes against it).
    if ($scopeKeys) {
        $live = array_values(array_filter($live, fn($r) => in_array((int)$r['key'], $scopeKeys, true)));
    }
    $total = count($live);
    $slice = array_slice($live, $offset, $limit);
    if (!$slice) jsonResponse(['results' => [], 'total' => $total, 'next' => null, 'done' => true]);

    // Align each selected baseline to the full live row set (indexed globally),
    // then read off the slice's global indices below. Each baseline gets a
    // per-item global time-shift correction first so a constantly lead/lagged
    // live clock still lines up.
    $alignByModel = [];
    foreach ($baselineCodes as $code) {
        $brows = cfLoadAuto($db, $itemKey, $code);
        $alignByModel[$code] = cfAlignBaselineToLive($live, $brows, cfEstimateShift($live, $brows));
    }

    $lines = [];
    foreach ($slice as $idx => $r) {
        $globalIdx = $offset + $idx;
        $alts = [];
        foreach ($baselineCodes as $code) {
            $t = $alignByModel[$code][$globalIdx] ?? '';
            // Surface an alternate only when it exists, differs from the current
            // line (identical = no signal, just bulk), and looks like the same
            // span (guards against live-transcript timestamp drift).
            if ($t !== '' && $t !== $r['text'] && cfAltLooksAligned($r['text'], $t)) {
                $alts[] = ['engine' => $code, 'text' => mb_substr($t, 0, 400)];
            }
        }
        $lines[] = ['i' => $idx, 'text' => $r['text'], 'key' => $r['key'], 'old' => $r['text'], 'alts' => $alts];
    }
    $ctx = cfCorrectionContext($db);
    $messages = cfBuildMessages($ctx, $lines);
    // Widen context when alternates are attached — each line carries 1-N extra
    // readings, so the default 8k can clip a 40-line chunk.
    $numCtx = $baselineCodes ? 16384 : 8192;

    $resp = gpuLlmChat($model, $messages, ['json' => true, 'temperature' => 0.1,
                                           'timeout' => 280, 'keep_alive' => '20m', 'num_ctx' => $numCtx]);
    if (!$resp['ok']) {
        $llmErr = $resp['error'] ?? 'unknown';
        // 424 for upstream GPU/LLM outages (timeouts, connection failures) — keeps them out of
        // yy_monitor_event (logMonitorEvent only fires on 5xx). 502 for unexpected non-connection errors.
        $llmStatus = preg_match('/connection failed|timed out|HTTP [45]\d\d\b/i', $llmErr) ? 424 : 502;
        errorResponse('LLM unavailable: ' . $llmErr, $llmStatus);
    }

    $parsed = json_decode($resp['content'], true);
    $byIdx = [];
    if (is_array($parsed) && isset($parsed['lines']) && is_array($parsed['lines'])) {
        foreach ($parsed['lines'] as $l) {
            if (isset($l['i']) && array_key_exists('text', $l)) $byIdx[(int)$l['i']] = (string)$l['text'];
        }
    }
    $results = [];
    $nLines = count($lines);
    foreach ($lines as $pos => $l) {
        $new = array_key_exists($l['i'], $byIdx) ? trim($byIdx[$l['i']]) : $l['old'];
        if ($new === '') $new = $l['old']; // never blank a line
        // Merge-guard (see cfReplySwallowsNext): don't surface a suggestion that
        // has swallowed the next line's wording — a forbidden merge that balloons
        // line i while i+1 stays put, showing duplicated text to the admin.
        if ($new !== $l['old'] && $pos + 1 < $nLines
            && cfReplySwallowsNext($new, $l['old'], (string)$lines[$pos + 1]['old'])) {
            $new = $l['old'];
        }
        $results[] = ['key' => $l['key'], 'old' => $l['old'], 'new' => $new,
                      'changed' => ($new !== $l['old'])];
    }
    $next = ($offset + $limit < $total) ? $offset + $limit : null;
    jsonResponse(['results' => $results, 'total' => $total, 'next' => $next,
                  'done' => $next === null, 'model' => $model,
                  'used_baselines' => $baselineCodes]);
}

if ($action === 'ai_apply') {
    if ($method !== 'POST') errorResponse('POST only', 405);
    if (!$itemKey) errorResponse('item_key required');
    $changes = $data['changes'] ?? [];
    if (!is_array($changes) || !$changes) jsonResponse(['updated' => 0]);
    $snap = cfSnapshot($db, $itemKey, (int)$user['user_key'], 'before AI cleanup');

    $fetch = $db->prepare("SELECT to_char(feed_item_transcript_segment,'HH24:MI:SS.MS') AS seg, feed_item_transcript_text AS t
                             FROM yy_feed_item_transcript
                            WHERE feed_item_transcript_key = ? AND feed_item_key = ?");
    $upd = $db->prepare("UPDATE yy_feed_item_transcript
                            SET feed_item_transcript_text = ?,
                                feed_item_transcript_revision_user_key = ?,
                                feed_item_transcript_revision_dtime = NOW(),
                                feed_item_transcript_revision_num = COALESCE(feed_item_transcript_revision_num,0)+1
                          WHERE feed_item_transcript_key = ? AND feed_item_key = ?");
    $log = $db->prepare("INSERT INTO yy_transcript_edit_log
                            (feed_item_key, edit_segment, edit_original_text, edit_new_text, edit_action, edit_user_key, edit_batch_key)
                         VALUES (?, ?::interval, ?, ?, 'ai_cleanup', ?, NULL)");
    $updated = 0;
    $db->beginTransaction();
    try {
        foreach ($changes as $c) {
            $k = (int)($c['key'] ?? 0);
            $new = trim((string)($c['text'] ?? ''));
            if (!$k || $new === '') continue;
            $fetch->execute([$k, $itemKey]);
            $row = $fetch->fetch();
            if (!$row) continue;
            $old = (string)$row['t'];
            if ($old === $new) continue;
            $upd->execute([mb_substr($new, 0, 2000), (int)$user['user_key'], $k, $itemKey]);
            $log->execute([$itemKey, (string)$row['seg'], $old, $new, (int)$user['user_key']]);
            autoLearnCorrections($db, $old, $new); // feed the loop
            $updated++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        errorResponse('apply failed: ' . $e->getMessage(), 500);
    }
    jsonResponse(['updated' => $updated, 'snapshot_key' => $snap]);
}

if ($action === 'snapshots') {
    if (!$itemKey) errorResponse('item_key required');
    $st = $db->prepare("SELECT snapshot_key, to_char(snapshot_dtime,'YYYY-MM-DD HH24:MI') AS dtime,
                               snapshot_reason, snapshot_rows
                          FROM yy_transcript_snapshot WHERE feed_item_key = ?
                         ORDER BY snapshot_dtime DESC LIMIT 25");
    $st->execute([$itemKey]);
    jsonResponse(['items' => $st->fetchAll()]);
}

if ($action === 'restore') {
    if ($method !== 'POST') errorResponse('POST only', 405);
    if (!$itemKey) errorResponse('item_key required');
    $snapKey = (int)($data['snapshot_key'] ?? 0);
    if (!$snapKey) errorResponse('snapshot_key required');
    $st = $db->prepare("SELECT snapshot_json FROM yy_transcript_snapshot WHERE snapshot_key = ? AND feed_item_key = ?");
    $st->execute([$snapKey, $itemKey]);
    $json = $st->fetchColumn();
    if ($json === false) errorResponse('snapshot not found');
    $rows = json_decode((string)$json, true);
    if (!is_array($rows)) errorResponse('corrupt snapshot');
    // Snapshot the current state too, so the restore is itself reversible.
    cfSnapshot($db, $itemKey, (int)$user['user_key'], 'before restore #' . $snapKey);
    $cues = array_map(fn($r) => ['segment' => (string)$r['segment'], 'text' => (string)$r['text'],
                                 'speaker' => $r['speaker'] ?? null], $rows);
    $n = cfReplaceLive($db, $itemKey, $cues);
    jsonResponse(['restored' => $n, 'from_snapshot' => $snapKey]);
}

if ($action === 'export_finetune') {
    // Fine-tune data path: aligned raw(auto) -> corrected(live) pairs as JSONL,
    // for a later on-box fine-tune. One item if item_key given, else all.
    $where = $itemKey ? 'WHERE a.feed_item_key = ' . $itemKey : '';
    $sql = "SELECT to_char(a.feed_item_transcript_segment,'HH24:MI:SS') AS seg,
                   a.feed_item_transcript_text AS raw, l.feed_item_transcript_text AS fixed
              FROM yy_feed_item_transcript_auto a
              JOIN yy_feed_item_transcript l
                ON l.feed_item_key = a.feed_item_key
               AND to_char(l.feed_item_transcript_segment,'HH24:MI:SS') = to_char(a.feed_item_transcript_segment,'HH24:MI:SS')
              $where
             LIMIT 50000";
    $st = $db->query($sql);
    $lines = [];
    foreach ($st->fetchAll() as $r) {
        $raw = trim((string)$r['raw']);
        $fixed = trim((string)$r['fixed']);
        if ($raw === '' || $fixed === '' || $raw === $fixed) continue;
        $lines[] = json_encode(['input' => $raw, 'output' => $fixed], JSON_UNESCAPED_UNICODE);
    }
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Content-Disposition: attachment; filename="transcript_finetune' . ($itemKey ? "_$itemKey" : '') . '.jsonl"');
    echo implode("\n", $lines);
    exit;
}

errorResponse('unknown action: ' . $action);
