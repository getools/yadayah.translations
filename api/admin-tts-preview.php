<?php
/**
 * Generate a short preview MP3 from arbitrary text using the chosen voice
 * (or the saved category default). Returns audio/mpeg directly so the UI
 * can play it inline with new Audio(url).
 *
 *   POST  { tts_key, text, category?, voice_code?, style?, style_degree?, rate_pct?, pitch_st?, volume? }
 *     - If voice_code is given: synthesise with that voice and the prosody
 *       options from the request body (used by the live preview slider).
 *     - Else: use the saved category default from yy_tts_category_voice.
 *
 * Body limited to 1000 chars to keep previews fast and free-tier-friendly.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST required');
$data = json_decode(file_get_contents('php://input'), true) ?: [];

$ttsKey  = (int)($data['tts_key'] ?? 0);
$rawText = trim((string)($data['text'] ?? ''));   // keep paragraph breaks for the async worker
$text    = $rawText;
if (!$ttsKey) errorResponse('tts_key required');
if ($text === '') errorResponse('text required');
// Per-engine length guard is applied AFTER transport is resolved (below):
// self-hosted engines chunk + render long selections in the background, so
// they accept far more than cloud providers that synth in one request.

// Always pre-strip HTML before synthesis. Word pastes inject
// <p class="MsoNormal">, <o:p>, <span style="...">, <head>, <style>
// blocks, etc. — even when no bold/italic is present. Without this
// strip, htmlspecialchars later escapes the <p ...> brackets and
// Azure reads them as literal text ("less than p m s o normal…").
// (ttsCleanPreviewText also glues apostrophe-class modifiers back to their
// letters so apos-anchored tunes still match — see the helper.)
$text = ttsCleanPreviewText($text);
$hasFormat = (bool)preg_match('/<\s*(b|i)\b/i', $text);

$cfg = loadTtsConfig($db, $ttsKey);
if (!$cfg['system']) errorResponse('Unknown tts_key', 404);

$category = (string)($data['category'] ?? 'main');
$overrideVoice = !empty($data['voice_code']) ? (string)$data['voice_code'] : null;

// Per-row tune override: when the user clicks ▶ next to a specific tune,
// we want THAT tune to fire unconditionally — ignoring its active flag,
// B/I restrictions, and any other tunes that might match the same text.
// Replace the loaded tunes with a single synthetic rule built from the
// caller-supplied print + phonetic + type.
if (!empty($data['tune_override']) && is_array($data['tune_override'])) {
    $to = $data['tune_override'];
    $cfg['tunes'] = [[
        'tts_tune_key'           => 999999,
        'tts_tune_print'         => (string)($to['print'] ?? ''),
        'tts_tune_phonetic'      => '',
        'tts_tune_phonetic_sub'  => (string)($to['sub']  ?? ''),
        'tts_tune_phonetic_ipa'  => (string)($to['ipa']  ?? ''),
        'tts_tune_phonetic_sapi' => '',
        'tts_tune_phonetic_type' => in_array(($to['type'] ?? 'sub'), ['sub','ipa','sapi'], true) ? $to['type'] : 'sub',
        'tts_tune_active_flag'   => true,
        'tts_tune_match_bold'    => false,
        'tts_tune_match_italic'  => false,
        // Seed for stochastic local engines. Pronunciations ▶ sends the
        // row's seed_max so the audition is reproducible (book builds
        // pick a fresh random int in [seed_min..seed_max] each time —
        // see applyTunesPlain in admin-tts-helpers.php — but the
        // pronunciation preview pins to the high end so what you hear
        // is consistent across clicks). We collapse the synthetic
        // override row's range so min == max == seed.
        'tts_tune_seed_min'      => isset($to['seed']) && $to['seed'] !== '' ? (int)$to['seed'] : 0,
        'tts_tune_seed_max'      => isset($to['seed']) && $to['seed'] !== '' ? (int)$to['seed'] : 0,
    ]];
}

// When the caller supplies a voice_code, that's the picker on the
// Voice catalog row — they want ONE voice for the whole utterance,
// not the multi-voice (translation/word_definition) routing the
// actual book build would use. Splice the override into EVERY
// category so every segment still routes through the same voice
// while B/I-restricted pronunciation tunes still fire correctly.
if ($overrideVoice) {
    $overrideRow = [
        'tts_voice_code'         => $overrideVoice,
        'tts_voice_style'        => $data['style'] ?? null,
        'tts_voice_style_degree' => $data['style_degree'] ?? 1.0,
        'tts_voice_rate_pct'     => (int)($data['rate_pct'] ?? 0),
        'tts_voice_pitch_st'     => (float)($data['pitch_st'] ?? 0),
        'tts_voice_volume'       => (int)($data['volume'] ?? 100),
    ];
    foreach (array_keys($cfg['categories']) as $catCode) {
        $cfg['categories'][$catCode] = $overrideRow;
    }
    if (!isset($cfg['categories']['main'])) $cfg['categories']['main'] = $overrideRow;
}

// Dispatch by provider. ttsProviderTransport returns one of:
//   'azure-ssml'        → existing SSML path verbatim
//   'elevenlabs-cloud'  → ElevenLabs HTTP API (eleven_v3 / eleven_turbo_v2_5)
//   'inworld-cloud'     → Inworld HTTP API (inworld-tts-1.5-max / inworld-tts-2)
//   'gpu-tailnet'       → local engines (Chatterbox/CosyVoice/Qwen3/Kokoro) via gpu-client
$primaryCat  = $hasFormat ? null : $category;
$resolvedCat = $primaryCat ?? 'main';
$providerKey = ttsResolveProviderKey($cfg, $resolvedCat);
$transport   = ttsProviderTransport($cfg, $providerKey);
$usesSsml    = ($transport === 'azure-ssml');

// Chunk sizing for self-hosted engines comes from the voice's PROVIDER (single
// source of truth: yy_provider.provider_settings->chunk via ttsProviderChunkSizes
// — each engine has its own optimal batch length). These are only used on the
// gpu-tailnet paths; cloud providers (Azure/ElevenLabs/Inworld) synth the whole
// utterance in one call and don't chunk. NO hardcoded size defaults here.
$chunkMin = $chunkTarget = $chunkMax = 0;
if ($transport === 'gpu-tailnet') {
    $pcs         = ttsProviderChunkSizes($cfg, $providerKey);
    $chunkMin    = $pcs['min'];
    $chunkTarget = $pcs['target'];
    $chunkMax    = $pcs['max'];
}

// Length guard, per engine. Self-hosted engines render long selections in the
// background (chunked whole-paragraph, see below), so there's no hard preview
// cap — chunking absorbs the scale. Cloud providers synth the whole utterance
// in a single request and have their own ceilings, so keep them bounded.
$previewCharMax = ($transport === 'gpu-tailnet') ? 100000 : 8000;
if (mb_strlen($text) > $previewCharMax) {
    errorResponse('text too long (' . $previewCharMax . ' char limit for this engine)');
}

// Self-hosted engines synthesise ~real-time when the box is busy, so a preview
// longer than what fits the synchronous path (~1100 chars ≈ ~70 s of audio)
// can't return inside Cloudflare's ~100 s proxy window. Hand those off to a
// background worker that renders the FULL selection whole-paragraph (no chunk
// cap) and stash an async job; the browser polls admin-tts-preview-status.php.
// Cloud providers synth fast and keep the synchronous path at any length.
//
// A heavy engine flagged always-async (Qwen3-Omni: a ~78 GB cold load into VRAM
// can blow past Cloudflare's 100 s even for a one-word preview, → 524) ALWAYS
// goes async, regardless of length. The async worker is immune to the CDN clock,
// auto-plays when done, and reports chunk progress — so the admin clicks Play
// exactly as with any other voice; it just renders via polling.
const PREVIEW_SYNC_CHAR_MAX = 1100;
$forceAsync = ($transport === 'gpu-tailnet') && ttsProviderAlwaysAsync($cfg, $providerKey);
// When a book / chapter build is currently occupying the box, a synchronous
// GPU preview would queue behind the build's in-flight paragraph on the engine
// and risk blowing past Cloudflare's ~100 s proxy window (→ 524), so the
// audition would fail mid-render. Route GPU previews through the detached async
// worker in that case — it's immune to the CDN clock and interleaves with the
// running build, so an audition can run "in parallel" with a render instead of
// failing. (Cloud providers don't touch the GPU, so this only applies to
// gpu-tailnet.) Any running OR pending build counts: they all share the box.
if (!$forceAsync && $transport === 'gpu-tailnet') {
    try {
        if ($db->query("SELECT 1 FROM yy_tts_audio WHERE tts_audio_status IN ('running','pending') LIMIT 1")->fetchColumn()) {
            $forceAsync = true;
        }
    } catch (\Throwable $e) { /* probe failed — keep sync default */ }
}
if ($transport === 'gpu-tailnet' && ($forceAsync || mb_strlen($text) > PREVIEW_SYNC_CHAR_MAX)) {
    $jobKey = ttsEnqueuePreviewJob($db, [
        'tts_key'    => $ttsKey,
        'user_key'   => (int)$user['user_key'],
        'raw_text'   => $rawText,                 // paragraph breaks preserved
        'category'   => $resolvedCat,
        'voice_code' => $overrideVoice,
        'style'      => $data['style'] ?? null,
        'rate_pct'   => (int)($data['rate_pct'] ?? 0),
        'pitch_st'   => (float)($data['pitch_st'] ?? 0),
        'volume'     => (int)($data['volume'] ?? 100),
        'min_chars'    => $chunkMin,
        'target_chars' => $chunkTarget,
        'max_chars'    => $chunkMax,
        // Carry the per-row ▶ override (typed SUB/IPA + type + seed) so the
        // detached worker auditions exactly what's on screen instead of
        // re-deriving the tunes from the DB. Null when this isn't a tune ▶.
        'tune_override' => (!empty($data['tune_override']) && is_array($data['tune_override'])) ? $data['tune_override'] : null,
    ]);
    if ($jobKey) {
        // Spawn the detached worker inside this container; it survives the
        // request (new session via setsid) and renders without the CDN clock.
        $workerPath = __DIR__ . '/admin-tts-preview-worker.php';
        @exec('setsid php ' . escapeshellarg($workerPath) . ' ' . (int)$jobKey . ' > /dev/null 2>&1 &');
        jsonResponse(['async' => true, 'job_key' => $jobKey]);
    }
    // Enqueue failed — fall through to the (capped) synchronous path so the
    // operator still hears something rather than an error.
}

$err = '';
$mp3 = '';

try {
    if ($transport === 'elevenlabs-cloud') {
        // ElevenLabs cloud API. The build worker uses buildElevenLabsSegment
        // because ElevenLabs v3 / flash_v2 / turbo_v2 understand inline
        // <phoneme alphabet="ipa" ph="…"> and <break time="…"/> tags. The
        // earlier preview code here called buildLocalSegment, which is the
        // shaper for engines that DON'T speak SSML (Chatterbox / CosyVoice /
        // Qwen3) — it flattens every <phoneme> tag back to the ASCII sub
        // respelling. Net effect: every ElevenLabs preview silently played
        // the phonetic spelling no matter what IPA the operator typed.
        // Match the build worker so the preview reflects what a real book
        // build will hear.
        $elSeg = buildElevenLabsSegment($text, $cfg, $resolvedCat);
        $elSeg['provider_key'] = $providerKey;
        if ($overrideVoice !== null) $elSeg['voice'] = $overrideVoice;
        $outputFormat = $cfg['system']['tts_output_format'] ?? 'audio-24khz-96kbitrate-mono-mp3';
        $mp3 = elevenlabsTtsSynthesize($cfg, $elSeg, $outputFormat, $err);
    } elseif ($transport === 'inworld-cloud') {
        // Inworld cloud API — uses inline /IPA/ slash notation for ipa-typed
        // tunes via buildInworldSegment (was buildLocalSegment, which used the
        // sub respelling for everything and caused mid-word-stress pauses).
        $iwSeg = buildInworldSegment($text, $cfg, $resolvedCat);
        $iwSeg['provider_key'] = $providerKey;
        if ($overrideVoice !== null) $iwSeg['voice'] = $overrideVoice;
        $outputFormat = $cfg['system']['tts_output_format'] ?? 'audio-24khz-96kbitrate-mono-mp3';
        $mp3 = inworldTtsSynthesize($cfg, $iwSeg, $outputFormat, $err);
    } elseif ($usesSsml) {
        if ($hasFormat) {
            $segs = segmentParagraph($text);
            if (!$segs) errorResponse('no audible content after segmentation');
            // Drop skipped categories + merge adjacent same-category runs so
            // the preview matches the build worker's per-paragraph behaviour
            // (one fluent translation read, not 6 chopped fragments).
            $segs = ttsCollapseSkippedSegments($cfg, $segs);
            if (!$segs) errorResponse('every segment was marked skip; nothing to synthesise');
            $voiceBlock = '';
            foreach ($segs as $seg) {
                $voiceBlock .= buildVoiceBlock($seg['text'], $cfg, $seg['category']);
            }
        } else {
            $voiceBlock = buildVoiceBlock($text, $cfg, $category, $overrideVoice);
        }
        $ssml = wrapSsml($voiceBlock);
        if (function_exists('azureTtsSynthesizeRetry')) {
            $mp3 = azureTtsSynthesizeRetry($ssml, $cfg, $err);
        } else {
            $mp3 = azureTtsSynthesize($ssml, $cfg, $err);
        }
    } else {
        // Local engine. Preview uses the single category voice for the whole
        // utterance (matches the picker semantics; segment-level B/I tune
        // routing is a build-worker concern).
        //
        // NO WARMUP. localTtsSynthesizePreview merges to whole-paragraph chunks
        // (≤590 chars) and synthesises each verbatim with only the gentle
        // leading/trailing silence trim — same as the book-build path. A short
        // single word is just one plain call. The old "uh."/"Seed N." warmup
        // and the warmup-word-handoff/cut were removed 2026-06-18: they cut text
        // off and repeated words. Any seed (tune_override) still APPLIES via the
        // seg; it's just no longer spoken aloud.
        $localSeg     = buildLocalSegment($text, $cfg, $resolvedCat);
        $outputFormat = $cfg['system']['tts_output_format'] ?? 'audio-24khz-96kbitrate-mono-mp3';
        if (function_exists('localTtsSynthesizePreview')) {
            $mp3 = localTtsSynthesizePreview($cfg, $localSeg, $outputFormat, $err, 60, $chunkMin, $chunkTarget, $chunkMax);
        } elseif (function_exists('localTtsSynthesizeChunked')) {
            $mp3 = localTtsSynthesizeChunked($cfg, $localSeg, $outputFormat, $err);
        } else {
            $mp3 = localTtsSynthesize($cfg, $localSeg, $outputFormat, $err);
        }
    }
} catch (Throwable $e) {
    error_log('admin-tts-preview fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    errorResponse('TTS preview crashed: ' . $e->getMessage(), 500);
}
if ($mp3 === '') {
    error_log('admin-tts-preview empty audio: provider_key=' . $providerKey . ' usesSsml=' . ($usesSsml ? '1' : '0') . ' voice=' . ($overrideVoice ?? '(category default)') . ' err=' . $err);
    // 500, not 502: Cloudflare intercepts 5xx >= 502 from the origin and
    // replaces the response body with its own branded "Bad gateway" page,
    // hiding our actual error message ("ELEVENLABS_API_KEY not set" etc.)
    // from the operator. 500 passes through with the JSON intact.
    errorResponse('TTS failed: ' . ($err ?: 'engine returned no audio'), 500);
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($mp3));
header('Cache-Control: no-store');
echo $mp3;
