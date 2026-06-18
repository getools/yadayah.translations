<?php
/**
 * Edit / delete custom voices (Voices catalog row actions).
 *
 *   POST  action=edit     — relabel + edit metadata of an existing voice.
 *     { code, label?, description?, language?, gender? }
 *   POST  action=delete   — delete a voice (and all clips) OR delete one style.
 *     { code, style? }    — style omitted = delete whole voice; style present
 *                            = remove just that style from the voice (default
 *                            style removal promotes the first remaining style).
 *
 * For local engines (Chatterbox / CosyVoice 2 / Qwen3 / Kokoro) the call
 * forwards to the box via gpu-client.gpuEditVoice / gpuDeleteVoice so the
 * voices.json + on-disk clips stay in sync. Azure voices reject delete with
 * a clear "managed by Azure" message; their labels are editable in DB only.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gpu-client.php';

$user = requireAuth();
$db   = getDb();
setCurrentUser($db, (int)$user['user_key']);

// ── GET action=clips ────────────────────────────────────────────────────
// Return the reference clips the box actually holds for a voice, each with the
// original upload filename when one was recorded. Powers the "Reference clips
// used to train this voice" list in the edit modal. Read-only.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'clips') {
    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') errorResponse('code required');
    $r = gpuRequest('GET', '/tts/voices', ['timeout' => 20]);
    if (!($r['ok'] ?? false) || !is_array($r['data'] ?? null)) {
        // Box offline/unconfigured — degrade gracefully (modal falls back to styles).
        jsonResponse(['ok' => true, 'styles' => [], 'engine_offline' => true]);
    }
    $entry = null;
    foreach ($r['data'] as $v) { if (($v['code'] ?? '') === $code) { $entry = $v; break; } }
    if (!$entry) jsonResponse(['ok' => true, 'styles' => []]);
    $out = [];
    foreach (($entry['styles'] ?? []) as $styleName => $info) {
        $refs  = $info['clone_refs'] ?? ($info['clone_ref'] ? [$info['clone_ref']] : []);
        $names = $info['clip_names'] ?? [];
        $clips = [];
        foreach (array_values($refs) as $i => $path) {
            $clips[] = [
                'file' => basename((string)$path),
                'name' => $names[$i] ?? ($info['orig_name'] ?? basename((string)$path)),
            ];
        }
        $out[] = ['style' => (string)$styleName, 'clips' => $clips];
    }
    jsonResponse(['ok' => true, 'styles' => $out]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST required', 405);
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = $_POST;
$action = (string)($data['action'] ?? '');
$code   = trim((string)($data['code']   ?? ''));
if ($code === '') errorResponse('code required');

// Look up the voice + its engine. We allow editing any custom voice; deletion
// is restricted to non-Azure (Azure rows are managed by the refresh button).
$voiceStmt = $db->prepare("
    SELECT v.tts_voice_key, v.tts_key, v.tts_voice_label, v.tts_voice_styles, v.tts_voice_type,
           t.tts_code AS engine
      FROM yy_tts_voice v JOIN yy_tts t ON v.tts_key = t.tts_key
     WHERE v.tts_voice_code = ?
");
$voiceStmt->execute([$code]);
$voice = $voiceStmt->fetch();
if (!$voice) errorResponse("voice '$code' not found", 404);
$engine = (string)$voice['engine'];
$isLocal = in_array($engine, ['chatterbox', 'cosyvoice', 'qwen3', 'kokoro', 'xtts', 'coqui', 'moss'], true);

if ($action === 'edit') {
    // ── Optional rename: change the voice's code ────────────────────────
    // The engine keys clips + voices.json by code, so a rename must succeed
    // on the box BEFORE we touch the DB (otherwise the new code can't synth).
    // gpuRenameVoice moves the clip(s) + rewrites voices.json on the box.
    $newCode = isset($data['new_code']) ? trim((string)$data['new_code']) : '';
    if ($newCode !== '' && $newCode !== $code) {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $newCode)) {
            errorResponse('new code must be alphanumeric (dashes/underscores ok)');
        }
        // Uniqueness within the same TTS system (matches the DB constraint).
        $dup = $db->prepare("SELECT 1 FROM yy_tts_voice WHERE tts_key = ? AND tts_voice_code = ? AND tts_voice_key <> ?");
        $dup->execute([(int)$voice['tts_key'], $newCode, (int)$voice['tts_voice_key']]);
        if ($dup->fetchColumn()) errorResponse("a voice with code '$newCode' already exists for this provider", 409);
        if (!$isLocal) errorResponse('only self-hosted voices can be renamed');
        $rr = gpuRenameVoice($code, $newCode, 60);
        if (!($rr['ok'] ?? false)) {
            errorResponse('engine rename failed (voice unchanged): ' . ($rr['error'] ?? 'unknown'),
                          ($rr['status'] ?? 0) >= 400 ? $rr['status'] : 502);
        }
        // Box renamed — now mirror in the DB and use the new code downstream.
        $db->prepare("UPDATE yy_tts_voice SET tts_voice_code = ?, tts_voice_revision_dtime = NOW() WHERE tts_voice_key = ?")
           ->execute([$newCode, (int)$voice['tts_voice_key']]);
        $code = $newCode;
    }

    $label       = isset($data['label'])       ? trim((string)$data['label'])       : null;
    $description = isset($data['description']) ? trim((string)$data['description']) : null;
    $language    = isset($data['language'])    ? trim((string)$data['language'])    : null;
    $gender      = isset($data['gender'])      ? trim((string)$data['gender'])      : null;

    // 1) Update DB columns (only the ones the caller actually provided).
    $sets = [];
    $args = [];
    if ($label !== null)       { $sets[] = 'tts_voice_label = ?';    $args[] = $label; }
    if ($description !== null) { $sets[] = 'tts_voice_description = ?'; $args[] = $description ?: null; }
    if ($language !== null)    { $sets[] = 'tts_voice_language = ?'; $args[] = $language ?: null;
                                 $sets[] = 'tts_voice_locale = ?';   $args[] = $language ?: null; }
    if ($gender !== null)      { $sets[] = 'tts_voice_gender = ?';   $args[] = $gender ?: null; }
    if ($sets) {
        $sets[] = 'tts_voice_revision_dtime = NOW()';
        $args[] = (int)$voice['tts_voice_key'];
        $db->prepare("UPDATE yy_tts_voice SET " . implode(',', $sets) . " WHERE tts_voice_key = ?")
           ->execute($args);
    }
    // 2) Mirror to box voices.json (local engines only).
    if ($isLocal) {
        $r = gpuEditVoice([
            'code'        => $code,
            'label'       => $label ?? '',
            'description' => $description ?? '',
            'language'    => $language ?? '',
            'gender'      => $gender ?? '',
        ], 30);
        // Don't fail the request if the box is offline — DB is the canonical store.
        if (!$r['ok'] && ($r['status'] ?? 0) !== 0) {
            error_log("gpuEditVoice failed for $code: " . ($r['error'] ?? ''));
        }
    }
    jsonResponse(['ok' => true, 'code' => $code]);
}

if ($action === 'delete') {
    $style = isset($data['style']) ? trim((string)$data['style']) : '';
    if (!$isLocal && $style === '') {
        errorResponse('Azure voices are managed by the refresh button — only their labels can be edited');
    }
    if ($style !== '') {
        // Removing one reference clip / style. We allow removing the LAST one:
        // the voice row (and any fine-tuned model on the box) is kept — it just
        // ends up with no reference clip until the admin uploads a new one.
        // (Deleting the whole voice is a separate, explicit action.)
        $cur = json_decode((string)$voice['tts_voice_styles'], true) ?: [];
        $cur = array_values(array_filter($cur, function ($s) use ($style) {
            return strtolower((string)$s) !== strtolower($style);
        }));
        $db->prepare("UPDATE yy_tts_voice SET tts_voice_styles = ?::jsonb,
                                              tts_voice_revision_dtime = NOW()
                        WHERE tts_voice_key = ?")
           ->execute([json_encode($cur), (int)$voice['tts_voice_key']]);
        if ($isLocal) {
            $r = gpuDeleteVoice($code, $style, 30);
            if (!$r['ok'] && ($r['status'] ?? 0) !== 0 && ($r['status'] ?? 0) !== 404) {
                error_log("gpuDeleteVoice($code,$style) failed: " . ($r['error'] ?? ''));
            }
        }
        jsonResponse(['ok' => true, 'code' => $code, 'style' => $style, 'styles' => $cur]);
    }
    // Whole-voice delete.
    $db->prepare("DELETE FROM yy_tts_voice WHERE tts_voice_key = ?")->execute([(int)$voice['tts_voice_key']]);
    if ($isLocal) {
        $r = gpuDeleteVoice($code, '', 30);
        if (!$r['ok'] && ($r['status'] ?? 0) !== 0 && ($r['status'] ?? 0) !== 404) {
            error_log("gpuDeleteVoice($code) failed: " . ($r['error'] ?? ''));
        }
    }
    jsonResponse(['ok' => true, 'code' => $code, 'deleted' => true]);
}

errorResponse('unknown action: ' . $action);
