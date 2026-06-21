<?php
/**
 * Transcript comparison + consensus correction (HTTP endpoint).
 * Pure logic lives in transcript-compare-lib.php (CLI-testable).
 *
 *   GET  ?item_key=N&action=engines
 *     → { engines:[{code,label,word_level,rows}], suggested_primary }
 *
 *   GET  ?item_key=N&action=compare&primary=CODE&refs=c1,c2,...
 *     → { primary, refs, slots:[{i,t,primary,refs:{code:word},consensus,agree}], stats }
 *
 *   POST { action:'apply', item_key:N, primary:CODE, overrides:{ "<i>":"word" } }
 *     → writes model 'consensus-corrected' (primary timestamps + chosen words).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-compare-lib.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);
@set_time_limit(300);  // content alignment over long transcripts: a few seconds per ref

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body = [];
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? $action;
}

if ($method === 'GET' && $action === 'engines') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $st = $db->prepare("
        SELECT feed_item_transcript_auto_model AS model, COUNT(*) AS rows
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ?
         GROUP BY feed_item_transcript_auto_model
         ORDER BY 1");
    $st->execute([$itemKey]);
    $engines = []; $suggested = null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $wl = (strpos($r['model'], '-word') !== false);
        $engines[] = ['code' => $r['model'], 'label' => compareLabelFor($r['model']),
                      'word_level' => $wl, 'rows' => (int)$r['rows']];
        if ($wl && !$suggested) $suggested = $r['model'];
    }
    jsonResponse(['engines' => $engines, 'suggested_primary' => $suggested]);
}

if ($method === 'GET' && $action === 'compare') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    $primary = trim($_GET['primary'] ?? '');
    $refs = array_values(array_filter(array_map('trim', explode(',', $_GET['refs'] ?? ''))));
    if (!$itemKey || $primary === '') errorResponse('item_key and primary required');

    $cmp = buildComparison($db, $itemKey, $primary, $refs);
    if (isset($cmp['error'])) errorResponse($cmp['error']);

    jsonResponse([
        'primary' => ['code' => $primary, 'label' => compareLabelFor($primary)],
        'refs' => array_map(fn($c) => ['code' => $c, 'label' => compareLabelFor($c)],
                            array_keys($cmp['refAligned'])),
        'slots' => $cmp['slots'],
        'stats' => ['words' => count($cmp['primaryWords']), 'disagreements' => $cmp['disagreements']],
    ]);
}

if ($method === 'POST' && $action === 'apply') {
    $itemKey = (int)($body['item_key'] ?? 0);
    $primary = trim($body['primary'] ?? '');
    $overrides = $body['overrides'] ?? [];
    if (!$itemKey || $primary === '') errorResponse('item_key and primary required');

    $pRows = loadCompareRows($db, $itemKey, $primary);
    if (!$pRows) errorResponse('primary has no rows');
    $pWords = primaryWords($pRows);
    foreach ($overrides as $k => $v) {
        $i = (int)$k;
        if (isset($pWords[$i])) $pWords[$i]['word'] = (string)$v;
    }

    $db->beginTransaction();
    $db->prepare("DELETE FROM yy_feed_item_transcript_auto
                   WHERE feed_item_key = ? AND feed_item_transcript_auto_model = 'consensus-corrected'")
       ->execute([$itemKey]);
    $ins = $db->prepare("INSERT INTO yy_feed_item_transcript_auto
                         (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text,
                          feed_item_transcript_sort, feed_item_transcript_auto_model)
                         VALUES (?, (?::text)::interval, ?, ?, 'consensus-corrected')");
    $written = 0;
    foreach ($pWords as $sort => $w) {
        $word = trim($w['word']);
        if ($word === '') continue;
        $t = $w['t'];
        $h = (int)($t / 3600); $m = (int)(($t - $h * 3600) / 60); $s = $t - $h * 3600 - $m * 60;
        $iv = sprintf('%02d:%02d:%06.3f', $h, $m, $s);
        $ins->execute([$itemKey, $iv, $word, $sort]);
        $written++;
    }
    $db->commit();
    jsonResponse(['written' => $written, 'model' => 'consensus-corrected']);
}

errorResponse('unknown action');
