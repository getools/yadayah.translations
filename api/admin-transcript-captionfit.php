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
        $words = cfRowsToWords($rows);
        $cues = cfReflow($words, ['max_chars' => 42, 'max_lines' => 2, 'max_secs' => 7.0,
                                  'min_secs' => 1.2, 'cps' => 17.0, 'break_punct' => true, 'dedup' => true]);
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

// ── DB helpers ───────────────────────────────────────────────────────────────

function cfLoadLive(PDO $db, int $itemKey): array {
    $st = $db->prepare("
        SELECT feed_item_transcript_key AS k,
               to_char(feed_item_transcript_segment, 'HH24:MI:SS.MS') AS segment,
               feed_item_transcript_text AS text,
               feed_item_transcript_sort AS sort,
               feed_item_transcript_speaker AS speaker
          FROM yy_feed_item_transcript
         WHERE feed_item_key = ?
         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
    $st->execute([$itemKey]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['key' => (int)$r['k'], 'segment' => (string)$r['segment'],
                   'secs' => cfIntervalToSecs((string)$r['segment']),
                   'text' => (string)$r['text'], 'sort' => (int)$r['sort'],
                   'speaker' => $r['speaker']];
    }
    return $rows;
}

function cfLoadAuto(PDO $db, int $itemKey, string $model): array {
    $st = $db->prepare("
        SELECT to_char(feed_item_transcript_segment, 'HH24:MI:SS.MS') AS segment,
               feed_item_transcript_text AS text
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ?
         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
    $st->execute([$itemKey, $model]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['secs' => cfIntervalToSecs((string)$r['segment']), 'text' => (string)$r['text']];
    }
    return $rows;
}

function cfAutoModels(PDO $db, int $itemKey): array {
    $st = $db->prepare("
        SELECT feed_item_transcript_auto_model AS m, COUNT(*) AS n
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ?
         GROUP BY feed_item_transcript_auto_model ORDER BY m");
    $st->execute([$itemKey]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $code = (string)$r['m'];
        $out[] = ['code' => $code, 'rows' => (int)$r['n'],
                  'word_level' => (strpos($code, 'word') !== false)];
    }
    return $out;
}

/** Capture the full live transcript before a destructive transform. */
function cfSnapshot(PDO $db, int $itemKey, ?int $userKey, string $reason): int {
    $rows = cfLoadLive($db, $itemKey);
    $payload = array_map(fn($r) => ['segment' => $r['segment'], 'text' => $r['text'],
                                    'sort' => $r['sort'], 'speaker' => $r['speaker']], $rows);
    $st = $db->prepare("
        INSERT INTO yy_transcript_snapshot
            (feed_item_key, snapshot_user_key, snapshot_reason, snapshot_rows, snapshot_json)
        VALUES (?, ?, ?, ?, ?::jsonb)
        RETURNING snapshot_key");
    $st->execute([$itemKey, $userKey, $reason, count($payload),
                  json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return (int)$st->fetchColumn();
}

/** Replace every live row for $itemKey with $cues ([['segment','text','speaker'?], ...]). */
function cfReplaceLive(PDO $db, int $itemKey, array $cues): int {
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ?")->execute([$itemKey]);
        $ins = $db->prepare("
            INSERT INTO yy_feed_item_transcript
                (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text,
                 feed_item_transcript_sort, feed_item_transcript_speaker)
            VALUES (?, ?::interval, ?, ?, ?)
            ON CONFLICT (feed_item_key, feed_item_transcript_segment, md5(feed_item_transcript_text)) DO NOTHING");
        $sort = 0;
        foreach ($cues as $c) {
            $seg = (string)$c['segment'];
            $txt = mb_substr((string)$c['text'], 0, 2000);
            if (trim($txt) === '') continue;
            $ins->execute([$itemKey, $seg, $txt, $sort++, $c['speaker'] ?? null]);
        }
        $db->commit();
        return $sort;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Build the in-context priming block from the admins' learned corrections. */
function cfCorrectionContext(PDO $db, int $maxCorr = 60, int $maxGloss = 60): array {
    $corr = [];
    try {
        $st = $db->query("SELECT correction_wrong, correction_right FROM yy_transcript_correction
                          WHERE correction_active_flag = TRUE
                          ORDER BY correction_count DESC, length(correction_wrong) DESC LIMIT $maxCorr");
        foreach ($st->fetchAll() as $r) $corr[] = $r['correction_wrong'] . ' => ' . $r['correction_right'];
    } catch (Throwable $e) { /* table optional */ }
    $gloss = [];
    try {
        $st = $db->query("SELECT glossary_term FROM yy_transcript_glossary
                          WHERE glossary_active_flag = TRUE
                          ORDER BY glossary_priority DESC, glossary_term LIMIT $maxGloss");
        foreach ($st->fetchAll() as $r) $gloss[] = $r['glossary_term'];
    } catch (Throwable $e) { /* table optional */ }
    return ['corrections' => $corr, 'glossary' => $gloss];
}

function cfBuildMessages(array $ctx, array $lines): array {
    $sys = "You proofread automatic speech-to-text transcripts of Hebrew/English religious lectures.\n"
         . "Fix ONLY transcription errors: misheard words, wrong Hebrew transliterations, proper nouns, "
         . "obvious typos, and missing or wrong punctuation.\n"
         . "STRICT RULES:\n"
         . "- Do NOT paraphrase, translate, summarize, reorder, merge, or split lines.\n"
         . "- Return EXACTLY one entry per input line, with the SAME index, in the SAME order.\n"
         . "- If a line is already correct, return it unchanged.\n"
         . "- Keep the speaker's wording; only repair errors.\n";
    if ($ctx['corrections']) {
        $sys .= "\nKnown corrections the editors have made before (wrong => right) — apply the same fixes when you see them:\n"
              . implode("\n", array_slice($ctx['corrections'], 0, 60)) . "\n";
    }
    if ($ctx['glossary']) {
        $sys .= "\nCanonical spellings of names/terms used in this material:\n"
              . implode(', ', array_slice($ctx['glossary'], 0, 60)) . "\n";
    }
    $sys .= "\nReturn ONLY a JSON object of the form: {\"lines\":[{\"i\":<index>,\"text\":\"<corrected line>\"}, ...]}";

    $payloadLines = array_map(fn($l) => ['i' => $l['i'], 'text' => $l['text']], $lines);
    $user = "Correct these lines. Return the JSON object described.\n"
          . json_encode(['lines' => $payloadLines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $user],
    ];
}

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
    ]);
}

if ($action === 'fit_preview') {
    if (!$itemKey) errorResponse('item_key required');
    global $CAPTION_DEFAULTS;
    $source = (string)($data['source'] ?? 'current');
    $opts = [];
    foreach (['max_chars', 'max_lines', 'max_secs', 'min_secs', 'cps'] as $k) {
        $opts[$k] = array_key_exists($k, $data) ? $data[$k] : $CAPTION_DEFAULTS[$k];
    }
    $opts['break_punct'] = array_key_exists('break_punct', $data) ? (bool)$data['break_punct'] : $CAPTION_DEFAULTS['break_punct'];
    $opts['dedup']       = array_key_exists('dedup', $data) ? (bool)$data['dedup'] : $CAPTION_DEFAULTS['dedup'];

    $rows = ($source === 'current') ? cfLoadLive($db, $itemKey) : cfLoadAuto($db, $itemKey, $source);
    if (!$rows) errorResponse('no rows for source: ' . $source);
    $words = cfRowsToWords($rows);
    $cues = cfReflow($words, $opts);
    $out = array_map(fn($c) => [
        'segment' => cfSecsToInterval($c['start']),
        'text'    => $c['text'],
        'chars'   => $c['chars'],
        'lines'   => $c['lines'],
    ], $cues);
    jsonResponse(['source' => $source, 'in_rows' => count($rows), 'in_words' => count($words),
                  'cues' => $out, 'count' => count($out)]);
}

if ($action === 'fit_apply') {
    if ($method !== 'POST') errorResponse('POST only', 405);
    if (!$itemKey) errorResponse('item_key required');
    $cues = $data['cues'] ?? [];
    if (!is_array($cues) || !$cues) errorResponse('cues required');
    $snap = cfSnapshot($db, $itemKey, (int)$user['user_key'], 'before caption re-flow');
    $clean = [];
    foreach ($cues as $c) {
        if (!isset($c['segment'], $c['text'])) continue;
        $clean[] = ['segment' => (string)$c['segment'], 'text' => (string)$c['text']];
    }
    $inserted = cfReplaceLive($db, $itemKey, $clean);
    jsonResponse(['inserted' => $inserted, 'snapshot_key' => $snap]);
}

if ($action === 'ai_chunk') {
    if ($method !== 'POST') errorResponse('POST only', 405);
    if (!$itemKey) errorResponse('item_key required');
    global $LLM_CODES;
    $model = (string)($data['model'] ?? 'qwen2.5:72b');
    if (!in_array($model, $LLM_CODES, true)) errorResponse('unknown model: ' . $model);
    $offset = max(0, (int)($data['offset'] ?? 0));
    $limit  = min(60, max(1, (int)($data['limit'] ?? 40)));

    $live = cfLoadLive($db, $itemKey);
    $total = count($live);
    $slice = array_slice($live, $offset, $limit);
    if (!$slice) jsonResponse(['results' => [], 'total' => $total, 'next' => null, 'done' => true]);

    $lines = [];
    foreach ($slice as $idx => $r) $lines[] = ['i' => $idx, 'text' => $r['text'], 'key' => $r['key'], 'old' => $r['text']];
    $ctx = cfCorrectionContext($db);
    $messages = cfBuildMessages($ctx, $lines);

    $resp = gpuLlmChat($model, $messages, ['json' => true, 'temperature' => 0.1,
                                           'timeout' => 280, 'keep_alive' => '20m']);
    if (!$resp['ok']) errorResponse('LLM unavailable: ' . ($resp['error'] ?? 'unknown'), 502);

    $parsed = json_decode($resp['content'], true);
    $byIdx = [];
    if (is_array($parsed) && isset($parsed['lines']) && is_array($parsed['lines'])) {
        foreach ($parsed['lines'] as $l) {
            if (isset($l['i']) && array_key_exists('text', $l)) $byIdx[(int)$l['i']] = (string)$l['text'];
        }
    }
    $results = [];
    foreach ($lines as $l) {
        $new = array_key_exists($l['i'], $byIdx) ? trim($byIdx[$l['i']]) : $l['old'];
        if ($new === '') $new = $l['old']; // never blank a line
        $results[] = ['key' => $l['key'], 'old' => $l['old'], 'new' => $new,
                      'changed' => ($new !== $l['old'])];
    }
    $next = ($offset + $limit < $total) ? $offset + $limit : null;
    jsonResponse(['results' => $results, 'total' => $total, 'next' => $next,
                  'done' => $next === null, 'model' => $model]);
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
