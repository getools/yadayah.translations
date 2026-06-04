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
if (empty($_FILES['audio']['tmp_name'])) errorResponse('audio file required');

$provider     = trim($_POST['provider']    ?? '');
$code         = trim($_POST['code']        ?? '');
$label        = trim($_POST['label']       ?? '');
$description  = trim($_POST['description'] ?? '');
$promptText   = trim($_POST['prompt_text'] ?? '');
$style        = trim($_POST['style']       ?? '');
$language     = trim($_POST['language']    ?? 'en');
$gender       = trim($_POST['gender']      ?? 'unknown');

if ($provider === '' || $code === '') errorResponse('provider and code are required');
if (!preg_match('/^[A-Za-z0-9_-]+$/', $code)) errorResponse('code must be alphanumeric (dashes/underscores ok)');

// Make sure the named provider actually exists in yy_tts before we send the
// clip to the engine — saves us writing files we won't be able to upsert.
$provStmt = $db->prepare("SELECT tts_key, tts_code, tts_name FROM yy_tts WHERE tts_code = ?");
$provStmt->execute([$provider]);
$prov = $provStmt->fetch();
if (!$prov) errorResponse("provider '$provider' not in yy_tts — register it first");

// Forward the multipart upload to the box.
$audioPath = $_FILES['audio']['tmp_name'];
$origName  = $_FILES['audio']['name'] ?? 'voice.wav';
$ext       = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, ['wav', 'mp3', 'm4a', 'flac', 'ogg'], true)) {
    errorResponse("unsupported audio extension '.$ext'");
}
// PHP's tmp upload has no extension; copy to a path that ends in the right
// suffix so the engine's content-type sniff + extension check both pass.
$relayPath = sys_get_temp_dir() . '/voice-' . $code . '.' . $ext;
if (!@copy($audioPath, $relayPath)) errorResponse('failed to stage upload locally');

try {
    $params = ['provider' => $provider, 'code' => $code,
               'label' => $label ?: $code,
               'language' => $language, 'gender' => $gender];
    if ($description) $params['description'] = $description;
    if ($promptText)  $params['prompt_text'] = $promptText;
    if ($style)       $params['style']       = $style;

    $r = gpuRegisterVoice($params, $relayPath, 120);
    if (!$r['ok']) {
        errorResponse('engine refused the upload: ' . ($r['error'] ?? 'unknown'),
                      $r['status'] >= 400 ? $r['status'] : 502);
    }
    $engineVoice = $r['data']['voice'] ?? [];

    // Upsert into yy_tts_voice. tts_voice_active_flag = true so the new voice
    // shows up immediately in the Defaults voice picker.
    $up = $db->prepare("
        INSERT INTO yy_tts_voice
            (tts_key, tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_locale_name,
             tts_voice_gender, tts_voice_type, tts_voice_status, tts_voice_active_flag,
             tts_voice_note, tts_voice_download_dtime)
        VALUES (?, ?, ?, ?, ?, ?, 'Custom', 'GA', TRUE, ?, NOW())
        ON CONFLICT (tts_key, tts_voice_code) DO UPDATE SET
            tts_voice_label    = EXCLUDED.tts_voice_label,
            tts_voice_gender   = EXCLUDED.tts_voice_gender,
            tts_voice_type     = EXCLUDED.tts_voice_type,
            tts_voice_active_flag = TRUE,
            tts_voice_note     = COALESCE(EXCLUDED.tts_voice_note, yy_tts_voice.tts_voice_note),
            tts_voice_download_dtime = NOW()
        RETURNING tts_voice_key
    ");
    $up->execute([
        (int)$prov['tts_key'], $code, $label ?: $code,
        $language, strtoupper($language),
        $gender ?: null,
        $description ?: null,
    ]);
    $newKey = (int)$up->fetchColumn();

    jsonResponse([
        'ok'         => true,
        'voice_key'  => $newKey,
        'provider'   => $prov['tts_code'],
        'engine'     => $engineVoice,
    ]);
} finally {
    @unlink($relayPath);
}
