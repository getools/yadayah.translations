<?php
/**
 * gpu-client.php — website-side client for the Puget GPU box.
 *
 * One thin wrapper around the authenticated gateway so the rest of the site
 * never hand-rolls curl + bearer headers. Mirrors the box's gateway routes:
 *   TTS  POST /tts/synthesize   → audio bytes
 *   STT  POST /stt/transcribe   → JSON {language,duration,text,segments[]}
 *   any  GET  /<svc>/healthz     → JSON {ok,...}
 *
 * Config (add to /opt/yada-www/.env — same token as the box's puget/.env):
 *   GPU_BASE_URL=https://gpu.yadayah.com
 *   GPU_API_TOKEN=<the GPU_API_TOKEN from the box>
 *
 * Library only — include it and call the helpers. Every call returns a
 * normalized array (never throws / never fatals on a down box):
 *   ['ok'=>bool, 'status'=>int, 'error'=>?string, plus 'data'|'body'|'path'].
 * So callers can degrade gracefully (e.g. fall back to Azure/OpenAI) when the
 * box is offline or unconfigured.
 *
 * CLI self-test:  php gpu-client.php health
 *                 php gpu-client.php stt /path/to/audio.mp3
 *                 php gpu-client.php tts "Some text to speak" out.mp3
 */

if (!function_exists('readEnv')) {
    // Same loader the rest of api/ uses (env var → .env file).
    function readEnv(string $name): string {
        $val = getenv($name);
        if ($val) return $val;
        $envFile = dirname(__DIR__) . '/.env';
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, $name . '=') === 0) return trim(substr($line, strlen($name) + 1));
            }
        }
        return '';
    }
}

function gpuBaseUrl(): string { return rtrim(readEnv('GPU_BASE_URL'), '/'); }
function gpuToken():   string { return readEnv('GPU_API_TOKEN'); }
function gpuConfigured(): bool { return gpuBaseUrl() !== '' && gpuToken() !== ''; }

/**
 * Core request. $opts:
 *   json      => array        JSON body (sets Content-Type)
 *   multipart => array        multipart form (include a CURLFile for uploads)
 *   save_to   => string       stream the response body to this file (binary)
 *   timeout   => int          total timeout seconds (default 120)
 *   expect    => 'json'|'raw' how to read a 2xx body when not saving (default json)
 */
function gpuRequest(string $method, string $path, array $opts = []): array {
    if (!gpuConfigured()) {
        return ['ok' => false, 'status' => 0,
                'error' => 'GPU box not configured (set GPU_BASE_URL and GPU_API_TOKEN in .env)'];
    }
    $url = gpuBaseUrl() . '/' . ltrim($path, '/');
    $timeout = (int)($opts['timeout'] ?? 120);
    $saveTo  = $opts['save_to'] ?? null;

    $headers = ['Authorization: Bearer ' . gpuToken()];
    $co = [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if (isset($opts['json'])) {
        $headers[] = 'Content-Type: application/json';
        $co[CURLOPT_POSTFIELDS] = json_encode($opts['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif (isset($opts['multipart'])) {
        $co[CURLOPT_POSTFIELDS] = $opts['multipart'];   // array incl. CURLFile → multipart/form-data
    }

    $fp = null;
    if ($saveTo) {
        $fp = @fopen($saveTo, 'wb');
        if (!$fp) return ['ok' => false, 'status' => 0, 'error' => 'cannot open save_to: ' . $saveTo];
        $co[CURLOPT_FILE] = $fp;            // body streams to file
        $co[CURLOPT_RETURNTRANSFER] = false;
    }

    $ch = curl_init($url);
    $co[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $co);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr   = curl_error($ch);
    curl_close($ch);
    if ($fp) fclose($fp);

    // Transport failure (DNS, refused, TLS, timeout).
    if (($body === false || $status === 0) && $cerr) {
        if ($saveTo) @unlink($saveTo);
        return ['ok' => false, 'status' => $status, 'error' => 'connection failed: ' . $cerr];
    }

    if ($status >= 400) {
        // Pull a message out of the (JSON or text) error body for context.
        $msg = '';
        if ($saveTo) {
            $msg = @file_get_contents($saveTo); @unlink($saveTo);
        } else {
            $msg = is_string($body) ? $body : '';
        }
        $decoded = json_decode((string)$msg, true);
        $detail  = is_array($decoded) ? ($decoded['detail'] ?? $decoded['error'] ?? '') : trim((string)$msg);
        return ['ok' => false, 'status' => $status, 'error' => 'HTTP ' . $status . ($detail ? ' — ' . $detail : '')];
    }

    if ($saveTo) {
        return ['ok' => true, 'status' => $status, 'path' => $saveTo,
                'bytes' => (is_file($saveTo) ? filesize($saveTo) : 0)];
    }
    if (($opts['expect'] ?? 'json') === 'raw') {
        return ['ok' => true, 'status' => $status, 'body' => $body];
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'status' => $status, 'error' => 'non-JSON response', 'body' => $body];
    }
    return ['ok' => true, 'status' => $status, 'data' => $data];
}

/** Health check for a service: gpuHealth('tts') / gpuHealth('stt'). */
function gpuHealth(string $service, int $timeout = 15): array {
    return gpuRequest('GET', '/' . trim($service, '/') . '/healthz', ['timeout' => $timeout]);
}

/**
 * Transcribe an audio file via STT.
 *   $opts: language ('' = auto), word_timestamps (bool), vad_filter (bool),
 *          initial_prompt (string), timeout (s; default 900 for long audio).
 * On success returns ['ok'=>true,'data'=>{language,duration,text,segments[]}].
 */
function gpuTranscribe(string $audioPath, array $opts = []): array {
    if (!is_file($audioPath)) {
        return ['ok' => false, 'status' => 0, 'error' => 'audio file not found: ' . $audioPath];
    }
    $mime = function_exists('mime_content_type') ? (mime_content_type($audioPath) ?: 'application/octet-stream')
                                                 : 'application/octet-stream';
    $form = [
        'file'            => new CURLFile($audioPath, $mime, basename($audioPath)),
        'language'        => $opts['language']        ?? '',
        'word_timestamps' => !empty($opts['word_timestamps'] ?? true) ? 'true' : 'false',
        'vad_filter'      => !empty($opts['vad_filter']      ?? true) ? 'true' : 'false',
    ];
    if (!empty($opts['initial_prompt'])) $form['initial_prompt'] = $opts['initial_prompt'];

    return gpuRequest('POST', '/stt/transcribe', [
        'multipart' => $form,
        'timeout'   => (int)($opts['timeout'] ?? 900),
    ]);
}

/**
 * Enumerate the FULL voice catalog a Puget-side provider offers — the
 * candidate pool that admin-tts-voices.php upserts into yy_tts_voice when an
 * admin clicks "Check for new voices" for that provider.
 *
 * Currently meaningful for 'xtts' (58 XTTS v2 built-in speakers) and 'coqui'
 * (Coqui TTS toolkit model list); other providers fall back to whatever the
 * engine has registered in voices.json.
 *
 * Returns the engine's response: ['ok'=>true, 'provider'=>…, 'voices'=>[...]].
 */
function gpuProviderCatalog(string $provider, int $timeout = 600): array {
    // Caddy strips the '/tts/' prefix and forwards to tts-engine:8801/catalog.
    return gpuRequest('GET', '/tts/catalog?provider=' . urlencode($provider), [
        'timeout' => $timeout,
    ]);
}

/**
 * Synthesize speech via TTS. $params mirror the engine contract, e.g.
 *   ['provider'=>'chatterbox','voice'=>'cb-quran-ar-en','text'=>'…','format'=>'mp3']
 * If $saveTo is given the audio is streamed to that path
 * (['ok'=>true,'path'=>…,'bytes'=>N]); otherwise raw bytes are returned in
 * ['ok'=>true,'body'=>…].
 */
function gpuSynthesize(array $params, ?string $saveTo = null, int $timeout = 600): array {
    $opts = ['json' => $params, 'timeout' => $timeout];
    if ($saveTo !== null) $opts['save_to'] = $saveTo;
    else                  $opts['expect']  = 'raw';
    return gpuRequest('POST', '/tts/synthesize', $opts);
}

/**
 * Register a custom voice with the box's TTS engine. Saves the reference
 * audio clip on the box (/srv/tts/voices/<code>.<ext>) and upserts the entry
 * in /srv/tts/voices.json so the engine can synth with the new voice code
 * immediately (in-memory registry is hot-reloaded engine-side).
 *
 *   $params:   ['provider'=>'cosyvoice', 'code'=>'craig', 'label'=>'…',
 *               'language'=>'en', 'gender'=>'male',
 *               'description'=>'…',                   // optional
 *               'prompt_text'=>'…transcript of clip…',// optional; CosyVoice
 *               'style'=>'warm, measured']            // optional; Qwen3-Omni
 *   $audioPath: local path on the website server to the reference clip.
 *
 * Returns the engine's response: ['ok'=>true, 'voice'=>{...}, 'voices_total'=>N]
 * (or the normalized error array from gpuRequest()).
 */
function gpuRegisterVoice(array $params, string $audioPath, int $timeout = 60): array {
    if (!is_file($audioPath)) {
        return ['ok' => false, 'status' => 0, 'error' => 'audio file not found: ' . $audioPath];
    }
    foreach (['provider', 'code'] as $req) {
        if (empty($params[$req])) {
            return ['ok' => false, 'status' => 0, 'error' => "missing required field '$req'"];
        }
    }
    $mime = function_exists('mime_content_type')
        ? (mime_content_type($audioPath) ?: 'application/octet-stream')
        : 'application/octet-stream';
    $form = [];
    foreach (['provider','code','label','description','language','gender','prompt_text','style'] as $k) {
        if (isset($params[$k])) $form[$k] = (string)$params[$k];
    }
    $form['file'] = new CURLFile($audioPath, $mime, basename($audioPath));
    return gpuRequest('POST', '/tts/voices', ['multipart' => $form, 'timeout' => $timeout]);
}

/**
 * Edit top-level metadata on an existing voice (label / description /
 * language / gender). Empty fields are not applied. To change clips,
 * upload a new file via gpuRegisterVoice() with the same code + style.
 */
function gpuEditVoice(array $params, int $timeout = 30): array {
    if (empty($params['code'])) {
        return ['ok' => false, 'status' => 0, 'error' => "missing required field 'code'"];
    }
    $form = [];
    foreach (['code','label','description','language','gender'] as $k) {
        if (isset($params[$k])) $form[$k] = (string)$params[$k];
    }
    return gpuRequest('POST', '/tts/voices/edit', ['multipart' => $form, 'timeout' => $timeout]);
}

/**
 * Rename a voice's code on the box: moves its clip file(s) and rewrites the
 * clone_ref paths + code key in voices.json. Engine route POST /tts/voices/rename.
 * Returns ['ok'=>bool, ...]; caller MUST treat a non-ok local-engine response as
 * fatal (don't change the DB) to avoid a code that the engine can't synth.
 */
function gpuRenameVoice(string $code, string $newCode, int $timeout = 60): array {
    if ($code === '' || $newCode === '') {
        return ['ok' => false, 'status' => 0, 'error' => "code and new_code required"];
    }
    return gpuRequest('POST', '/tts/voices/rename',
        ['multipart' => ['code' => $code, 'new_code' => $newCode], 'timeout' => $timeout]);
}

/**
 * Delete a voice (style omitted) or remove one of its styles. Removes the
 * orphaned clip(s) on the box. Deleting 'default' from a multi-style voice
 * promotes one of the remaining styles to default.
 */
function gpuDeleteVoice(string $code, string $style = '', int $timeout = 30): array {
    $form = ['code' => $code];
    if ($style !== '') $form['style'] = $style;
    return gpuRequest('POST', '/tts/voices/delete', ['multipart' => $form, 'timeout' => $timeout]);
}

/**
 * Register a voice that maps to a provider's BUILT-IN speaker (no clip).
 * Used for engines without voice cloning — Qwen3-Omni (chelsie/ethan/aiden)
 * and Kokoro (af_bella / af_sky / ...). Required: provider, code, voice_id.
 */
function gpuRegisterBuiltinVoice(array $params, int $timeout = 30): array {
    foreach (['provider', 'code', 'voice_id'] as $req) {
        if (empty($params[$req])) {
            return ['ok' => false, 'status' => 0, 'error' => "missing required field '$req'"];
        }
    }
    $form = [];
    foreach (['provider','code','label','description','language','gender','voice_id','lang_code'] as $k) {
        if (isset($params[$k])) $form[$k] = (string)$params[$k];
    }
    return gpuRequest('POST', '/tts/voices/builtin', ['multipart' => $form, 'timeout' => $timeout]);
}

// ── CLI self-test ───────────────────────────────────────────────────────────
// `php gpu-client.php health` | `... stt <audio>` | `... tts "<text>" <out.mp3>`
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $cmd = $argv[1] ?? 'health';
    $show = function ($r) { fwrite(STDOUT, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"); };
    if (!gpuConfigured()) { $show(['ok'=>false,'error'=>'set GPU_BASE_URL and GPU_API_TOKEN in .env first']); exit(2); }
    if ($cmd === 'health') {
        $show(['tts' => gpuHealth('tts'), 'stt' => gpuHealth('stt')]);
    } elseif ($cmd === 'stt') {
        $show(gpuTranscribe($argv[2] ?? '', ['language' => $argv[3] ?? '']));
    } elseif ($cmd === 'tts') {
        $out = $argv[3] ?? 'gpu-tts-test.mp3';
        $show(gpuSynthesize(['provider'=>'kokoro','voice'=>($argv[4] ?? 'default'),'text'=>($argv[2] ?? 'Hello from the box.'),'format'=>'mp3'], $out));
    } else {
        fwrite(STDERR, "usage: php gpu-client.php health | stt <audio> [lang] | tts \"<text>\" [out.mp3] [voice]\n");
        exit(1);
    }
}
