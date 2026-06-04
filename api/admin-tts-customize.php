<?php
/**
 * Admin TTS — custom voice training endpoint.
 *
 * POST /api/admin-tts-customize.php  (multipart/form-data)
 *
 *   provider:      one of the providers in CUSTOMIZE_PROVIDERS in admin-tts.html
 *                  (chatterbox / cosyvoice / qwen3)
 *   code:          slug (the engine prefixes it during registration display)
 *   label:         display name (e.g. "Craig — calm narrator")
 *   description:   optional notes (when to pick this voice)
 *   prompt_text:   optional; transcript of the reference clip
 *                  (CosyVoice / clone-by-transcript path)
 *   style:         optional; natural-language style descriptor (Qwen3-Omni)
 *   audio:         the reference audio file (wav/mp3/m4a/flac/ogg)
 *
 * Flow:
 *   1. Auth + basic validation.
 *   2. Save the upload to a temp path on the website server.
 *   3. Call gpuRegisterVoice() over the gateway → POST /tts/voices on the box.
 *      The engine writes the clip to /srv/tts/voices/<code>.<ext> and updates
 *      voices.json. No persistent state on the website server.
 *   4. On success, upsert yy_tts_voice so the new voice shows in the
 *      Voices catalog with active_flag=true and the right provider key.
 *
 * Notes:
 *   - GPU_BASE_URL + GPU_API_TOKEN must be set in /opt/yada-www/.env for the
 *     gpu-client call to fire. Until then the endpoint returns ok=false with
 *     a clear "GPU box not configured" message and never touches the DB.
 *   - The endpoint does NOT block on Tailscale being up; gpuRegisterVoice's
 *     connection failure surfaces as a regular HTTP error.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gpu-client.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST required', 405);

$provider     = trim($_POST['provider']    ?? '');
$code         = trim($_POST['code']        ?? '');
$label        = trim($_POST['label']       ?? '');
$description  = trim($_POST['description'] ?? '');
$promptText   = trim($_POST['prompt_text'] ?? '');
$instruct     = trim($_POST['instruct']    ?? '');   // natural-lang style hint (CV2/Qwen3)
$styleName    = trim($_POST['style_name']  ?? 'default');  // style slot (default/happy/...)
$voiceId      = trim($_POST['voice_id']    ?? '');   // built-in-speaker path
$langCode     = trim($_POST['lang_code']   ?? '');   // Kokoro-specific
$language     = trim($_POST['language']    ?? 'en');
$gender       = trim($_POST['gender']      ?? 'unknown');

// Normalize style name.
$styleName = strtolower($styleName ?: 'default');
if (!preg_match('/^[a-z0-9_-]+$/', $styleName)) errorResponse('style_name must be alphanumeric (dashes/underscores ok)');

// Engines whose voices are SELECTED from built-ins (no clip): Qwen3-Omni
// (only 3 hard-coded speakers) and Kokoro (50+ preset voice IDs). Cloning
// engines (Chatterbox, CosyVoice 2) keep the audio-upload path.
$BUILTIN_PROVIDERS = ['qwen3', 'kokoro'];
$isBuiltin = in_array($provider, $BUILTIN_PROVIDERS, true);

if (!$isBuiltin && empty($_FILES['audio']['tmp_name'])) errorResponse('audio file required');
if ($isBuiltin && $voiceId === '')                     errorResponse('voice_id (base speaker) required for built-in providers');

if ($provider === '' || $code === '') errorResponse('provider and code are required');
if (!preg_match('/^[A-Za-z0-9_-]+$/', $code)) errorResponse('code must be alphanumeric (dashes/underscores ok)');

// Multi-style rule: any non-default style requires the voice to already exist.
// (Engine-side enforces this too, but catching here gives a friendlier message
// and lets us skip a pointless DB upsert.)
$existingStmt = $db->prepare("
    SELECT v.tts_voice_key, v.tts_voice_styles
      FROM yy_tts_voice v JOIN yy_tts t ON v.tts_key = t.tts_key
     WHERE t.tts_code = ? AND v.tts_voice_code = ?
");
$existingStmt->execute([$provider, $code]);
$existingVoice = $existingStmt->fetch();
if ($styleName !== 'default' && !$existingVoice) {
    errorResponse("voice '$code' doesn't exist yet — upload the 'default' style first");
}

$provStmt = $db->prepare("SELECT tts_key, tts_code, tts_name FROM yy_tts WHERE tts_code = ?");
$provStmt->execute([$provider]);
$prov = $provStmt->fetch();
if (!$prov) errorResponse("provider '$provider' not in yy_tts — register it first");

// Resolve the matching yy_provider row by engine name. Used to set the
// voice's provider_key so the Voices catalog filter lists the new voice
// under its actual engine (not under Azure, which is the default 1).
$provKeyStmt = $db->prepare("
    SELECT provider_key FROM yy_provider
     WHERE provider_settings ->> 'engine' = ?
       AND provider_active_flag = TRUE
     ORDER BY provider_key LIMIT 1
");
$provKeyStmt->execute([$provider]);
$providerKey = (int)($provKeyStmt->fetchColumn() ?: 1);   // 1 = Azure fallback

$relayPath = null;
if (!$isBuiltin) {
    $audioPath = $_FILES['audio']['tmp_name'];
    $origName  = $_FILES['audio']['name'] ?? 'voice.wav';
    $ext       = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['wav', 'mp3', 'm4a', 'flac', 'ogg'], true)) {
        errorResponse("unsupported audio extension '.$ext'");
    }
    $relayPath = sys_get_temp_dir() . '/voice-' . $code . '.' . $ext;
    if (!@copy($audioPath, $relayPath)) errorResponse('failed to stage upload locally');
}

try {
    $params = ['provider' => $provider, 'code' => $code,
               'label' => $label ?: $code,
               'language' => $language, 'gender' => $gender,
               'style' => $styleName];
    if ($description) $params['description']  = $description;
    if ($promptText)  $params['prompt_text']  = $promptText;
    if ($instruct)    $params['instruct']     = $instruct;
    if ($voiceId)     $params['voice_id']     = $voiceId;
    if ($langCode)    $params['lang_code']    = $langCode;

    if ($isBuiltin) {
        $r = gpuRegisterBuiltinVoice($params, 30);
    } else {
        $r = gpuRegisterVoice($params, $relayPath, 120);
    }
    if (!$r['ok']) {
        errorResponse('engine refused the registration: ' . ($r['error'] ?? 'unknown'),
                      $r['status'] >= 400 ? $r['status'] : 502);
    }
    $engineVoice = $r['data']['voice'] ?? [];

    // For non-default style additions: just update the JSONB array in DB.
    if ($existingVoice && $styleName !== 'default') {
        $cur = json_decode((string)$existingVoice['tts_voice_styles'], true) ?: [];
        if (!in_array($styleName, $cur, true)) $cur[] = $styleName;
        $up = $db->prepare("UPDATE yy_tts_voice SET tts_voice_styles = ?::jsonb,
                                                    tts_voice_revision_dtime = NOW()
                              WHERE tts_voice_key = ?
                          RETURNING tts_voice_key");
        $up->execute([json_encode($cur), (int)$existingVoice['tts_voice_key']]);
        $newKey = (int)$up->fetchColumn();
    } else {
        // First style ("default") — upsert the voice row. tts_voice_styles
        // tracks the available style names; starts with ['default'].
        $initStyles = json_encode(['default']);
        $up = $db->prepare("
            INSERT INTO yy_tts_voice
                (tts_key, tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_locale_name,
                 tts_voice_gender, tts_voice_type, tts_voice_status, tts_voice_active_flag,
                 tts_voice_note, tts_voice_download_dtime, provider_key, tts_voice_language,
                 tts_voice_styles)
            VALUES (?, ?, ?, ?, ?, ?, 'Custom', 'GA', TRUE, ?, NOW(), ?, ?, ?::jsonb)
            ON CONFLICT (tts_key, tts_voice_code) DO UPDATE SET
                tts_voice_label    = EXCLUDED.tts_voice_label,
                tts_voice_gender   = EXCLUDED.tts_voice_gender,
                tts_voice_type     = EXCLUDED.tts_voice_type,
                tts_voice_active_flag = TRUE,
                tts_voice_note     = COALESCE(EXCLUDED.tts_voice_note, yy_tts_voice.tts_voice_note),
                provider_key       = EXCLUDED.provider_key,
                tts_voice_language = EXCLUDED.tts_voice_language,
                tts_voice_styles   = CASE
                    WHEN jsonb_array_length(COALESCE(yy_tts_voice.tts_voice_styles,'[]'::jsonb)) = 0
                    THEN EXCLUDED.tts_voice_styles
                    ELSE yy_tts_voice.tts_voice_styles
                END,
                tts_voice_download_dtime = NOW()
            RETURNING tts_voice_key
        ");
        $up->execute([
            (int)$prov['tts_key'], $code, $label ?: $code,
            $language, strtoupper($language),
            $gender ?: null,
            $description ?: null,
            $providerKey,
            $language ?: 'en',
            $initStyles,
        ]);
        $newKey = (int)$up->fetchColumn();
    }

    jsonResponse([
        'ok'         => true,
        'voice_key'  => $newKey,
        'provider'   => $prov['tts_code'],
        'style'      => $styleName,
        'engine'     => $engineVoice,
    ]);
} finally {
    if ($relayPath) @unlink($relayPath);
}
