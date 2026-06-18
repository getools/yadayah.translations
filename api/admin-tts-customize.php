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

$mode         = trim($_POST['mode']        ?? '');   // '' (default = train) or 'meta' (DB-only update)
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

$action       = trim($_POST['action']      ?? '');   // '' | ft_prepare | ft_clone | ft_start | ft_status

// ── XTTS multi-clip clone + fine-tune actions ───────────────────────────
// Power the XTTS "Add Custom Voice" flow (admin-tts.html modal):
//   ft_prepare  upload N clips → stage each on the box; if method=finetune,
//               also auto-transcribe each (STT) so the admin can review/edit.
//   ft_clone    build a multi-reference zero-shot clone from the staged clips.
//   ft_start    launch a background fine-tune over staged clips + transcripts.
//   ft_status   poll a fine-tune job; flip the voice active when it finishes.
// Everything below the existing single-file paths is untouched.
if ($action !== '') {

    // Resolve the xtts provider's tts_key + provider_key (so the catalog lists
    // the new voice under XTTS, not Azure — see the coqui/xtts provider note).
    $xttsKeys = function (PDO $db): array {
        $tk = $db->prepare("SELECT tts_key FROM yy_tts WHERE tts_code = 'xtts'");
        $tk->execute();
        $ttsKey = (int)($tk->fetchColumn() ?: 0);
        $pk = $db->prepare("SELECT provider_key FROM yy_provider
                             WHERE provider_settings->>'engine' = 'xtts'
                               AND provider_active_flag = TRUE
                             ORDER BY provider_key LIMIT 1");
        $pk->execute();
        $provKey = (int)($pk->fetchColumn() ?: 1);   // 1 = Azure fallback
        return [$ttsKey, $provKey];
    };

    // Upsert a custom XTTS voice row. $active is bound as (int) because PDO
    // renders bool false as '' which a Postgres boolean column rejects.
    $upsertVoice = function (PDO $db, int $ttsKey, int $provKey, string $code, string $label,
                             string $description, string $language, string $gender,
                             bool $active, string $status): int {
        $stmt = $db->prepare("
            INSERT INTO yy_tts_voice
                (tts_key, tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_locale_name,
                 tts_voice_gender, tts_voice_type, tts_voice_status, tts_voice_active_flag,
                 tts_voice_description, tts_voice_download_dtime, provider_key, tts_voice_language,
                 tts_voice_styles, tts_voice_custom_flag)
            VALUES (?, ?, ?, ?, ?, ?, 'Custom', ?, ?, ?, NOW(), ?, ?, ?::jsonb, TRUE)
            ON CONFLICT (tts_key, tts_voice_code) DO UPDATE SET
                tts_voice_label       = EXCLUDED.tts_voice_label,
                tts_voice_gender      = EXCLUDED.tts_voice_gender,
                tts_voice_type        = 'Custom',
                tts_voice_custom_flag  = TRUE,
                tts_voice_status      = EXCLUDED.tts_voice_status,
                tts_voice_active_flag = EXCLUDED.tts_voice_active_flag,
                tts_voice_description = COALESCE(EXCLUDED.tts_voice_description, yy_tts_voice.tts_voice_description),
                provider_key          = EXCLUDED.provider_key,
                tts_voice_language    = EXCLUDED.tts_voice_language,
                tts_voice_download_dtime = NOW()
            RETURNING tts_voice_key
        ");
        $stmt->execute([
            $ttsKey, $code, $label !== '' ? $label : $code,
            $language, strtoupper($language),
            $gender !== '' ? $gender : null,
            $status, (int)$active,
            $description !== '' ? $description : null,
            $provKey, $language !== '' ? $language : 'en',
            json_encode(['default']),
        ]);
        return (int)$stmt->fetchColumn();
    };

    if ($action === 'ft_prepare') {
        $method = trim($_POST['method'] ?? 'clone');         // clone | finetune
        $job    = trim($_POST['job'] ?? '');
        if (empty($_FILES['audio']) || empty($_FILES['audio']['name'])) {
            errorResponse('at least one audio file is required');
        }
        $files = $_FILES['audio'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        if ($count < 1) errorResponse('at least one audio file is required');
        $lang2 = substr($language ?: 'en', 0, 2);
        $clips = [];
        for ($i = 0; $i < $count; $i++) {
            if ((int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $tmp  = $files['tmp_name'][$i];
            $name = $files['name'][$i] ?: ('clip' . ($i + 1) . '.wav');
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['wav', 'mp3', 'm4a', 'flac', 'ogg'], true)) {
                errorResponse("unsupported audio extension '.$ext' in $name");
            }
            $relay = sys_get_temp_dir() . '/ftstage-' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!@copy($tmp, $relay)) errorResponse('failed to stage upload locally');
            // Stage only (fast). Transcription is kicked off asynchronously
            // after staging — synchronous STT on long audio blows past the
            // ~100s edge timeout (Cloudflare 524).
            $sr = gpuStageClip($job, $relay, 120, $name);
            @unlink($relay);
            if (!$sr['ok']) {
                errorResponse('clip staging failed: ' . ($sr['error'] ?? 'unknown'),
                              ($sr['status'] ?? 0) >= 400 ? $sr['status'] : 502);
            }
            $job = $sr['data']['job'] ?? $job;
            $clips[] = [
                'index'    => $sr['data']['index']    ?? ($i + 1),
                'stub'     => $sr['data']['stub']     ?? '',
                'filename' => $sr['data']['filename'] ?? $name,
                'seconds'  => $sr['data']['seconds']  ?? 0,
                'text'     => '',
                'segments' => [],
            ];
        }
        if (!$clips) errorResponse('no clips could be staged');
        // Fine-tune needs transcripts; start background STT on the box and let
        // the client poll ft_transcribe_status. Clone needs no transcript.
        $transcribing = false;
        if ($method === 'finetune') {
            $ts = gpuTranscribeJobStart($job, $lang2);
            if (!$ts['ok']) {
                errorResponse('could not start transcription: ' . ($ts['error'] ?? 'unknown'),
                              ($ts['status'] ?? 0) >= 400 ? $ts['status'] : 502);
            }
            $transcribing = true;
        }
        jsonResponse(['ok' => true, 'job' => $job, 'method' => $method,
                      'transcribing' => $transcribing, 'clips' => $clips]);
    }

    // Stage exactly ONE clip per request. The client uploads clips one at a
    // time (per-file progress bar) so each request stays well under the ~100s
    // Cloudflare edge timeout — batching every clip into one ft_prepare request
    // raced that limit and 524'd on long/large uploads.
    if ($action === 'ft_stage_one') {
        $job = trim($_POST['job'] ?? '');
        if (empty($_FILES['audio']) || (is_array($_FILES['audio']['name']) ? empty($_FILES['audio']['name'][0]) : $_FILES['audio']['name'] === '')) {
            errorResponse('an audio file is required');
        }
        $f = $_FILES['audio'];
        if (is_array($f['name'])) {
            $name = $f['name'][0] ?? ''; $tmp = $f['tmp_name'][0] ?? ''; $err = (int)($f['error'][0] ?? UPLOAD_ERR_NO_FILE);
        } else {
            $name = $f['name']; $tmp = $f['tmp_name']; $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        }
        if ($err !== UPLOAD_ERR_OK) errorResponse('upload error (code ' . $err . ') for ' . $name);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['wav', 'mp3', 'm4a', 'flac', 'ogg'], true)) {
            errorResponse("unsupported audio extension '.$ext' in $name");
        }
        // The push from this host to the GPU box is SLOW (remote tailnet link,
        // ~5 MB/s), so a large clip's origin→box transfer alone blows past the
        // ~100s Cloudflare edge timeout (524) even though ffmpeg is trivial.
        // So we only receive the upload here (fast browser→origin), spool it,
        // and hand the slow box push to a detached background worker. The
        // client then polls ft_stage_status until the worker reports done.
        $token = bin2hex(random_bytes(8));
        $spool = sys_get_temp_dir() . '/ftstage-' . $token . '.' . $ext;
        if (!@move_uploaded_file($tmp, $spool) && !@copy($tmp, $spool)) {
            errorResponse('failed to spool upload locally');
        }
        $statusFile = sys_get_temp_dir() . '/ftstage-' . $token . '.status.json';
        @file_put_contents($statusFile, json_encode(['ok' => true, 'state' => 'staging', 'job' => $job]));
        require_once __DIR__ . '/spawn-helpers.php';
        $pid = spawnCappedWorker(__DIR__ . '/ft-stage-worker.php', [$token, $job, $ext, $name],
                                 sys_get_temp_dir() . '/ftstage-' . $token . '.log',
                                 ['cpu_secs' => 900, 'nice' => 10]);
        if ($pid <= 0) {
            @unlink($spool);
            errorResponse('could not start the staging worker', 500);
        }
        jsonResponse(['ok' => true, 'token' => $token, 'job' => $job, 'state' => 'staging']);
    }

    // Poll the background staging worker spawned by ft_stage_one.
    if ($action === 'ft_stage_status') {
        $token = trim($_POST['token'] ?? '');
        if ($token === '' || !preg_match('/^[a-f0-9]{8,}$/', $token)) errorResponse('valid token required');
        $statusFile = sys_get_temp_dir() . '/ftstage-' . $token . '.status.json';
        if (!is_file($statusFile)) { jsonResponse(['ok' => true, 'state' => 'unknown']); }
        $j = json_decode((string)file_get_contents($statusFile), true);
        if (!is_array($j)) $j = ['state' => 'unknown'];
        $j['ok'] = true;
        jsonResponse($j);
    }

    // Start background STT for a fine-tune job whose clips were already staged
    // via ft_stage_one. Returns immediately; client polls ft_transcribe_status.
    if ($action === 'ft_transcribe') {
        $job = trim($_POST['job'] ?? '');
        if ($job === '') errorResponse('job is required');
        $lang2 = substr($language ?: 'en', 0, 2);
        $ts = gpuTranscribeJobStart($job, $lang2);
        if (!$ts['ok']) {
            errorResponse('could not start transcription: ' . ($ts['error'] ?? 'unknown'),
                          ($ts['status'] ?? 0) >= 400 ? $ts['status'] : 502);
        }
        jsonResponse(['ok' => true, 'job' => $job, 'transcribing' => true]);
    }

    if ($action === 'ft_transcribe_status') {
        $job = trim($_POST['job'] ?? '');
        if ($job === '') errorResponse('job is required');
        $r = gpuTranscribeJobStatus($job);
        if (!$r['ok']) {
            errorResponse('transcription status failed: ' . ($r['error'] ?? 'unknown'),
                          ($r['status'] ?? 0) >= 400 ? $r['status'] : 502);
        }
        jsonResponse(['ok' => true, 'status' => $r['data'] ?? []]);
    }

    if ($action === 'ft_clone') {
        $job = trim($_POST['job'] ?? '');
        if ($job === '' || $code === '') errorResponse('job and code are required');
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $code)) errorResponse('code must be alphanumeric (dashes/underscores ok)');
        $r = gpuCloneMulti([
            'provider' => 'xtts', 'code' => $code, 'label' => $label,
            'description' => $description, 'language' => $language,
            'gender' => $gender, 'job' => $job,
        ]);
        if (!$r['ok']) {
            errorResponse('engine refused the clone: ' . ($r['error'] ?? 'unknown'),
                          $r['status'] >= 400 ? $r['status'] : 502);
        }
        [$ttsKey, $provKey] = $xttsKeys($db);
        if (!$ttsKey) errorResponse("provider 'xtts' not in yy_tts — register it first", 500);
        $vk = $upsertVoice($db, $ttsKey, $provKey, $code, $label, $description, $language, $gender, true, 'GA');
        jsonResponse(['ok' => true, 'voice_key' => $vk,
                      'clips' => $r['data']['clips'] ?? null,
                      'engine' => $r['data']['voice'] ?? null]);
    }

    if ($action === 'ft_start') {
        $job    = trim($_POST['job'] ?? '');
        $preset = strtolower(trim($_POST['preset'] ?? 'balanced'));
        if ($job === '' || $code === '') errorResponse('job and code are required');
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $code)) errorResponse('code must be alphanumeric (dashes/underscores ok)');
        if (!in_array($preset, ['fast', 'balanced', 'thorough'], true)) errorResponse('invalid preset');
        $clips = json_decode($_POST['clips'] ?? '', true);
        if (!is_array($clips) || !$clips) errorResponse('clips (with transcripts) are required');
        $clean = [];
        foreach ($clips as $c) {
            $f = trim((string)($c['file'] ?? ''));
            $t = trim((string)($c['text'] ?? ''));
            if ($f === '' || $t === '') continue;
            $row = ['file' => basename($f), 'text' => $t];
            if (!empty($c['segments']) && is_array($c['segments'])) {
                $segs = [];
                foreach ($c['segments'] as $sg) {
                    $stext = trim((string)($sg['text'] ?? ''));
                    if ($stext === '') continue;
                    $segs[] = ['start' => (float)($sg['start'] ?? 0),
                               'end'   => (float)($sg['end'] ?? 0),
                               'text'  => $stext];
                }
                if ($segs) $row['segments'] = $segs;
            }
            $clean[] = $row;
        }
        if (!$clean) errorResponse('every clip needs a transcript before training');
        $r = gpuFinetuneStart([
            'job' => $job, 'code' => $code, 'label' => $label,
            'description' => $description, 'language' => $language,
            'gender' => $gender, 'preset' => $preset, 'clips' => $clean,
        ]);
        if (!$r['ok']) {
            errorResponse('engine refused the fine-tune: ' . ($r['error'] ?? 'unknown'),
                          $r['status'] >= 400 ? $r['status'] : 502);
        }
        // Create the voice row now, inactive + 'building', so its progress is
        // tracked; ft_status flips it active when training finishes.
        [$ttsKey, $provKey] = $xttsKeys($db);
        if (!$ttsKey) errorResponse("provider 'xtts' not in yy_tts — register it first", 500);
        $vk = $upsertVoice($db, $ttsKey, $provKey, $code, $label, $description, $language, $gender, false, 'building');
        jsonResponse(['ok' => true, 'job' => $r['data']['job'] ?? $job, 'voice_key' => $vk,
                      'preset' => $preset, 'state' => $r['data']['state'] ?? 'preparing']);
    }

    if ($action === 'ft_status') {
        $job = trim($_POST['job'] ?? '');
        if ($job === '') errorResponse('job is required');
        $r = gpuFinetuneStatus($job);
        if (!$r['ok']) {
            errorResponse('status check failed: ' . ($r['error'] ?? 'unknown'),
                          $r['status'] >= 400 ? $r['status'] : 502);
        }
        $st = $r['data'] ?? [];
        if (($st['state'] ?? '') === 'done' && !empty($st['code'])) {
            $codeDone = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$st['code']);
            $up = $db->prepare("
                UPDATE yy_tts_voice v
                   SET tts_voice_active_flag = TRUE,
                       tts_voice_status = 'GA',
                       tts_voice_revision_dtime = NOW()
                  FROM yy_tts t
                 WHERE v.tts_key = t.tts_key AND t.tts_code = 'xtts'
                   AND v.tts_voice_code = ?
            ");
            $up->execute([$codeDone]);
        }
        jsonResponse(['ok' => true, 'status' => $st]);
    }

    if ($action === 'ft_reconcile') {
        // Safety net: flip any 'building' custom xtts voice active once the
        // engine reports it registered with a fine-tuned model. Covers admins
        // who closed the modal before training finished (the modal poll is the
        // primary path). No job id needed — matches by code against /tts/voices.
        $rows = $db->query("
            SELECT v.tts_voice_code
              FROM yy_tts_voice v JOIN yy_tts t ON v.tts_key = t.tts_key
             WHERE t.tts_code = 'xtts' AND v.tts_voice_status = 'building'
               AND v.tts_voice_custom_flag = TRUE
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!$rows) jsonResponse(['ok' => true, 'activated' => []]);
        $list = gpuRequest('GET', '/tts/voices', ['timeout' => 20]);
        $byCode = [];
        if ($list['ok'] && is_array($list['data'] ?? null)) {
            foreach ($list['data'] as $vv) {
                if (!empty($vv['code'])) $byCode[$vv['code']] = $vv;
            }
        }
        $done = [];
        foreach ($rows as $c) {
            $ev = $byCode[$c] ?? null;
            if ($ev && !empty($ev['ft_model_dir'])) {
                $up = $db->prepare("
                    UPDATE yy_tts_voice v SET tts_voice_active_flag = TRUE,
                           tts_voice_status = 'GA', tts_voice_revision_dtime = NOW()
                      FROM yy_tts t
                     WHERE v.tts_key = t.tts_key AND t.tts_code = 'xtts'
                       AND v.tts_voice_code = ?
                ");
                $up->execute([$c]);
                $done[] = $c;
            }
        }
        jsonResponse(['ok' => true, 'activated' => $done]);
    }

    errorResponse("unknown action '$action'", 400);
}

// ── Metadata-only update path ───────────────────────────────────────
// Customize tab auto-saves label / gender / description while the user
// edits an EXISTING custom voice. No audio upload, no GPU call — just
// UPDATE the yy_tts_voice columns the user touched. The Train button
// still handles new-voice creation (which needs audio + engine register).
//
// Only the fields actually present in $_POST get written, so the client
// can post a single field per keystroke without clobbering others.
if ($mode === 'meta') {
    if ($provider === '' || $code === '') errorResponse('provider and code are required');
    $vStmt = $db->prepare("
        SELECT v.tts_voice_key
          FROM yy_tts_voice v JOIN yy_tts t ON v.tts_key = t.tts_key
         WHERE t.tts_code = ? AND v.tts_voice_code = ?
    ");
    $vStmt->execute([$provider, $code]);
    $voiceKey = (int)($vStmt->fetchColumn() ?: 0);
    if (!$voiceKey) errorResponse("voice '$code' not found for provider '$provider'", 404);

    $sets = [];
    $args = [];
    if (array_key_exists('label', $_POST))       { $sets[] = 'tts_voice_label = ?';    $args[] = $label !== '' ? $label : $code; }
    if (array_key_exists('gender', $_POST))      { $sets[] = 'tts_voice_gender = ?';   $args[] = $gender !== '' ? $gender : null; }
    if (array_key_exists('description', $_POST)) { $sets[] = 'tts_voice_description = ?'; $args[] = $description !== '' ? $description : null; }
    if (array_key_exists('language', $_POST))    { $sets[] = 'tts_voice_language = ?'; $args[] = $language !== '' ? $language : 'en'; }
    if (!$sets) errorResponse('no editable field in request');

    $sets[] = 'tts_voice_revision_dtime = NOW()';
    $args[] = $voiceKey;
    $sql = 'UPDATE yy_tts_voice SET ' . implode(', ', $sets) . ' WHERE tts_voice_key = ?';
    $db->prepare($sql)->execute($args);
    jsonResponse(['ok' => true, 'voice_key' => $voiceKey, 'updated' => count($sets) - 1]);
}

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
    if (!$isBuiltin && !empty($origName)) $params['orig_name'] = $origName;
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
                 tts_voice_description, tts_voice_download_dtime, provider_key, tts_voice_language,
                 tts_voice_styles, tts_voice_custom_flag)
            VALUES (?, ?, ?, ?, ?, ?, 'Custom', 'GA', TRUE, ?, NOW(), ?, ?, ?::jsonb, TRUE)
            ON CONFLICT (tts_key, tts_voice_code) DO UPDATE SET
                tts_voice_label    = EXCLUDED.tts_voice_label,
                tts_voice_gender   = EXCLUDED.tts_voice_gender,
                tts_voice_type     = EXCLUDED.tts_voice_type,
                tts_voice_custom_flag = TRUE,
                tts_voice_active_flag = TRUE,
                tts_voice_description = COALESCE(EXCLUDED.tts_voice_description, yy_tts_voice.tts_voice_description),
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
