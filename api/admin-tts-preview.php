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

$ttsKey = (int)($data['tts_key'] ?? 0);
$text   = trim((string)($data['text'] ?? ''));
if (!$ttsKey) errorResponse('tts_key required');
if ($text === '') errorResponse('text required');
if (mb_strlen($text) > 8000) errorResponse('text too long (8000 char limit for preview)');

// Always pre-strip HTML before synthesis. Word pastes inject
// <p class="MsoNormal">, <o:p>, <span style="...">, <head>, <style>
// blocks, etc. — even when no bold/italic is present. Without this
// strip, htmlspecialchars later escapes the <p ...> brackets and
// Azure reads them as literal text ("less than p m s o normal…").
$text = preg_replace('/<!--[\s\S]*?-->/', '', $text);
$text = preg_replace('/<head\b[\s\S]*?<\/head>/i', '', $text);
$text = preg_replace('/<style\b[\s\S]*?<\/style>/i', '', $text);
$text = preg_replace('/<script\b[\s\S]*?<\/script>/i', '', $text);
// Strip Office namespace tags (<o:p>, <w:WordDocument>, etc.).
$text = preg_replace('/<\/?[a-z]+:[a-z]+\b[^>]*>/i', '', $text);
// Normalise <strong>/<em> → <b>/<i> so segmentParagraph routes them.
$text = preg_replace('/<\s*strong\b[^>]*>/i',  '<b>',  $text);
$text = preg_replace('/<\s*\/\s*strong\s*>/i', '</b>', $text);
$text = preg_replace('/<\s*em\b[^>]*>/i',      '<i>',  $text);
$text = preg_replace('/<\s*\/\s*em\s*>/i',     '</i>', $text);
// Drop every remaining tag except <b>, </b>, <i>, </i>. Replace with
// a single space so adjacent words don't fuse together.
$text = preg_replace('/<(?!\/?(?:b|i)\b)[^>]*>/i', ' ', $text);
$text = preg_replace('/&nbsp;/', ' ', $text);
$text = preg_replace('/\s+/u', ' ', $text);
$text = trim($text);
// Glue apostrophe-class modifiers (half-rings, curly / straight quotes,
// primes, etc.) back to adjacent letters that Word's span-per-character
// paste split apart. "rea ʿ" → "reaʿ"; "ʾ Adam" → "ʾAdam". Without this
// the apos-anchored tunes (Print "reaʿ" → IPA "ɹˈɛɑʕ") wouldn't match
// and Azure would fall back to letter-spelling the orphaned 3-letter
// fragments like "rea".
$aposCls = "[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{02BE}\x{02BF}\x{02C0}\x{2018}\x{2019}\x{201B}\x{2032}\x{05F3}]";
$text = preg_replace('/(\pL)\s+(' . $aposCls . ')/u', '$1$2', $text);
$text = preg_replace('/(' . $aposCls . ')\s+(\pL)/u', '$1$2', $text);
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
//   'gpu-tailnet'       → local engines (Chatterbox/CosyVoice/Qwen3/Kokoro) via gpu-client
$primaryCat  = $hasFormat ? null : $category;
$resolvedCat = $primaryCat ?? 'main';
$providerKey = ttsResolveProviderKey($cfg, $resolvedCat);
$transport   = ttsProviderTransport($cfg, $providerKey);
$usesSsml    = ($transport === 'azure-ssml');
$err = '';
$mp3 = '';

try {
    if ($transport === 'elevenlabs-cloud') {
        // ElevenLabs cloud API. Same per-segment payload shape as the local
        // GPU path; the synth helper handles the HTTP call. We carry the
        // provider_key on the segment so the synth picks model/voice off
        // the matching yy_provider / yy_tts_voice row.
        $elSeg = buildLocalSegment($text, $cfg, $resolvedCat);
        $elSeg['provider_key'] = $providerKey;
        if ($overrideVoice !== null) $elSeg['voice'] = $overrideVoice;
        $outputFormat = $cfg['system']['tts_output_format'] ?? 'audio-24khz-96kbitrate-mono-mp3';
        $mp3 = elevenlabsTtsSynthesize($cfg, $elSeg, $outputFormat, $err);
    } elseif ($usesSsml) {
        if ($hasFormat) {
            $segs = segmentParagraph($text);
            if (!$segs) errorResponse('no audible content after segmentation');
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
        $localSeg     = buildLocalSegment($text, $cfg, $resolvedCat);
        $outputFormat = $cfg['system']['tts_output_format'] ?? 'audio-24khz-96kbitrate-mono-mp3';
        // Preview-only warmup. For short single-word respelling previews
        // the Chatterbox engine's first ~200 ms is startup garbage and
        // would clip "Yadah" → "adah" etc. Prepend a warmup phrase that
        // travels in the same synth call so the garbage lands on the
        // warmup audio. The book-build path explicitly does NOT add this
        // warmup — see localTtsSynthesizeChunked in admin-tts-helpers.php
        // — because chapter audio can't have spoken "uh." / "Seed N."
        // mixed in. Previews announce the seed number when a seed is set
        // (tune_override carries one) so the audition tells the listener
        // which variant they're hearing.
        if (mb_strlen(trim((string)($localSeg['text'] ?? ''))) <= 120) {
            $warmup = (isset($localSeg['seed']) && $localSeg['seed'] !== null)
                ? (((int)$localSeg['seed']) . '. ')
                : 'uh. ';
            $localSeg['text'] = $warmup . $localSeg['text'];
        }
        if (function_exists('localTtsSynthesizeRetry')) {
            $mp3 = localTtsSynthesizeRetry($cfg, $localSeg, $outputFormat, $err);
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
