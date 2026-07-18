<?php
/**
 * Admin TTS config endpoint. One PHP file handles all CRUD for the four
 * configuration entities (system, category voices, tunes, pauses), plus
 * a 'catalog' action that returns voice + format + category lists for UI.
 *
 *   GET  ?action=catalog
 *     → { voices: [...], formats: [...], categories: [...] }
 *
 *   GET  ?action=overview&tts_key=N
 *     → { systems: [...], current: { system, categories, tunes_count, pauses_count } }
 *
 *   GET  ?action=tunes&tts_key=N
 *   GET  ?action=pauses&tts_key=N
 *   GET  ?action=category_voices&tts_key=N
 *
 *   POST { action:'save_system',         tts_key, output_format, region }
 *   POST { action:'save_audition_frame', tts_key, warmup, cooldown }
 *   POST { action:'save_category_voice', tts_key, tts_category, voice_code, style, style_degree, rate_pct, pitch_st, volume }
 *   POST { action:'save_tune',           tts_key, tts_tune_key?, print, phonetic, phonetic_type, note, active }
 *   POST { action:'delete_tune',         tts_tune_key }
 *   POST { action:'save_pause',          tts_key, tts_pause_key?, search, ms, note, sort, active }
 *   POST { action:'delete_pause',        tts_pause_key }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data = [];
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
}

if ($method === 'GET' && $action === 'catalog') {
    // Provider list for the per-tune Provider dropdown (+ tune-table sort).
    // TTS engines ONLY — must mirror the list_providers filter, else non-speech
    // self-hosted providers leak in: image/video gen engines (Flux, CogVideoX,
    // …) also declare provider_settings.engine, so the old "engine IS NOT NULL"
    // test wrongly offered them as pronunciation providers. Gate on "has a voice
    // OR is registered for the 'tts' functionality" (Azure 1 stays via voices).
    $provStmt = $db->query("
        SELECT provider_key, provider_label, provider_settings->>'engine' AS engine,
               provider_phonetic_capable, provider_ipa_capable
          FROM yy_provider p
         WHERE provider_active_flag = TRUE
           AND (
                provider_key IN (SELECT DISTINCT provider_key FROM yy_tts_voice)
             OR provider_key IN (
                    SELECT fp.provider_key
                      FROM yy_functionality_provider fp
                      JOIN yy_functionality f USING (functionality_key)
                     WHERE f.functionality_code = 'tts'
                       AND fp.functionality_provider_active_flag = TRUE
                )
           )
         ORDER BY provider_sort, provider_label
    ")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse([
        'voices'     => azureVoiceCatalog(),
        'formats'    => azureOutputFormats(),
        'categories' => ttsCategories(),
        'providers'  => $provStmt,
    ]);
}

if ($method === 'GET' && $action === 'overview') {
    $systems = $db->query("SELECT * FROM yy_tts WHERE tts_active_flag = TRUE ORDER BY tts_sort, tts_name")->fetchAll();
    $ttsKey = (int)($_GET['tts_key'] ?? ($systems[0]['tts_key'] ?? 0));
    // Optional profile selection — UI passes the profile_key the user is
    // viewing/editing. Omitted = default profile for this tts_key.
    $profileKey = (int)($_GET['profile_key'] ?? 0) ?: null;
    $current = null;
    $profiles = [];
    if ($ttsKey) {
        $cfg = loadTtsConfig($db, $ttsKey, $profileKey);
        // Profile list for the UI Profile picker.
        $pStmt = $db->prepare("SELECT tts_profile_key, tts_profile_code, tts_profile_label, tts_profile_default_flag FROM yy_tts_profile WHERE tts_key = ? AND tts_profile_active_flag = TRUE ORDER BY tts_profile_default_flag DESC, tts_profile_label");
        $pStmt->execute([$ttsKey]);
        $profiles = $pStmt->fetchAll();
        $current = [
            'system'       => $cfg['system'],
            'categories'   => array_values($cfg['categories']),
            'profile_key'  => $cfg['profile_key'],
            'profiles'     => $profiles,
            'tunes_count'  => (int)$db->query("SELECT COUNT(*) FROM yy_tts_tune  WHERE tts_key = $ttsKey")->fetchColumn(),
            'pauses_count' => (int)$db->query("SELECT COUNT(*) FROM yy_tts_pause WHERE tts_key = $ttsKey")->fetchColumn(),
        ];
    }
    jsonResponse(['systems' => $systems, 'current' => $current]);
}

// ── Profile management ─────────────────────────────────────────────────
// CRUD on yy_tts_profile. A profile is a named bundle of category-voice
// mappings (different voices per category). The build modal lets you pick
// which profile a chapter audio is rendered with; a chapter can have one
// audio file per profile.
if ($method === 'GET' && $action === 'list_profiles') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("SELECT tts_profile_key, tts_profile_code, tts_profile_label, tts_profile_default_flag FROM yy_tts_profile WHERE tts_key = ? AND tts_profile_active_flag = TRUE ORDER BY tts_profile_default_flag DESC, tts_profile_label");
    $stmt->execute([$ttsKey]);
    jsonResponse(['profiles' => $stmt->fetchAll()]);
}

if ($action === 'create_profile') {
    $ttsKey  = (int)($data['tts_key'] ?? 0);
    $label   = trim((string)($data['label'] ?? ''));
    $cloneFrom = (int)($data['clone_from'] ?? 0);  // optional source profile to copy from
    if (!$ttsKey)  errorResponse('tts_key required');
    if ($label === '') errorResponse('label required');
    if (mb_strlen($label) > 120) errorResponse('label too long (120 char limit)');
    // Derive a slug from the label; append numeric suffix on collision.
    $base = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($label));
    $base = trim($base, '_') ?: 'profile';
    $code = $base;
    $i = 2;
    while (true) {
        $st = $db->prepare("SELECT 1 FROM yy_tts_profile WHERE tts_key = ? AND tts_profile_code = ?");
        $st->execute([$ttsKey, $code]);
        if (!$st->fetchColumn()) break;
        $code = $base . '_' . $i++;
        if ($i > 99) errorResponse('cannot derive unique code');
    }
    $db->prepare("INSERT INTO yy_tts_profile (tts_key, tts_profile_code, tts_profile_label) VALUES (?, ?, ?) RETURNING tts_profile_key")
       ->execute([$ttsKey, $code, $label]);
    $newKey = (int)$db->lastInsertId('yy_tts_profile_tts_profile_key_seq');
    // Optional clone — copy every category row from $cloneFrom into the
    // new profile so the user starts from a known-good baseline rather
    // than an empty profile that routes every segment to a fallback.
    if ($cloneFrom > 0) {
        $st = $db->prepare("SELECT 1 FROM yy_tts_profile WHERE tts_profile_key = ? AND tts_key = ?");
        $st->execute([$cloneFrom, $ttsKey]);
        if ($st->fetchColumn()) {
            $db->prepare("
                INSERT INTO yy_tts_category_voice
                    (tts_key, tts_profile_key, tts_category, tts_voice_code, tts_voice_style, tts_voice_style_degree,
                     tts_voice_rate_pct, tts_voice_pitch_st, tts_voice_volume, tts_category_voice_read_flag)
                SELECT tts_key, ?, tts_category, tts_voice_code, tts_voice_style, tts_voice_style_degree,
                       tts_voice_rate_pct, tts_voice_pitch_st, tts_voice_volume, tts_category_voice_read_flag
                  FROM yy_tts_category_voice
                 WHERE tts_profile_key = ?
            ")->execute([$newKey, $cloneFrom]);
        }
    }
    jsonResponse(['tts_profile_key' => $newKey, 'tts_profile_code' => $code, 'tts_profile_label' => $label]);
}

if ($action === 'rename_profile') {
    $key   = (int)($data['tts_profile_key'] ?? 0);
    $label = trim((string)($data['label'] ?? ''));
    if (!$key)  errorResponse('tts_profile_key required');
    if ($label === '') errorResponse('label required');
    if (mb_strlen($label) > 120) errorResponse('label too long');
    $stmt = $db->prepare("UPDATE yy_tts_profile SET tts_profile_label = ?, tts_profile_revision_dtime = NOW() WHERE tts_profile_key = ?");
    $stmt->execute([$label, $key]);
    jsonResponse(['ok' => true]);
}

if ($action === 'set_default_profile') {
    $key = (int)($data['tts_profile_key'] ?? 0);
    if (!$key) errorResponse('tts_profile_key required');
    // Look up the tts_key so we can clear the prior default in the same tx.
    $row = $db->prepare("SELECT tts_key FROM yy_tts_profile WHERE tts_profile_key = ?");
    $row->execute([$key]);
    $ttsKey = (int)$row->fetchColumn();
    if (!$ttsKey) errorResponse('profile not found', 404);
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE yy_tts_profile SET tts_profile_default_flag = FALSE WHERE tts_key = ? AND tts_profile_default_flag = TRUE")
           ->execute([$ttsKey]);
        $db->prepare("UPDATE yy_tts_profile SET tts_profile_default_flag = TRUE, tts_profile_revision_dtime = NOW() WHERE tts_profile_key = ?")
           ->execute([$key]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        errorResponse('failed: ' . $e->getMessage(), 500);
    }
    jsonResponse(['ok' => true]);
}

if ($action === 'delete_profile') {
    $key = (int)($data['tts_profile_key'] ?? 0);
    if (!$key) errorResponse('tts_profile_key required');
    // Refuse to delete the default — user must promote another first.
    $row = $db->prepare("SELECT tts_profile_default_flag FROM yy_tts_profile WHERE tts_profile_key = ?");
    $row->execute([$key]);
    $isDefault = (bool)$row->fetchColumn();
    if ($isDefault) errorResponse('cannot delete the default profile — set another as default first');
    // Soft-delete via active_flag so existing audio rows that reference
    // this profile_key keep their link (we set ON DELETE SET NULL on the
    // audio FK, but soft-delete preserves the historical mapping).
    $db->prepare("UPDATE yy_tts_profile SET tts_profile_active_flag = FALSE, tts_profile_revision_dtime = NOW() WHERE tts_profile_key = ?")
       ->execute([$key]);
    jsonResponse(['ok' => true]);
}

if ($method === 'GET' && $action === 'tunes') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    // Show ALL pronunciation records — the lexicon is shared across engines,
    // so the list isn't filtered by the selected engine (no per-tab filters).
    $stmt = $db->query("SELECT * FROM yy_tts_tune ORDER BY tts_tune_sort, tts_tune_print");
    jsonResponse(['rows' => $stmt->fetchAll()]);
}

if ($method === 'GET' && $action === 'pauses') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("SELECT * FROM yy_tts_pause WHERE tts_key = ? ORDER BY tts_pause_sort, tts_pause_search");
    $stmt->execute([$ttsKey]);
    jsonResponse(['rows' => $stmt->fetchAll()]);
}

if ($method === 'GET' && $action === 'fonts') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("SELECT * FROM yy_tts_font WHERE tts_key = ? ORDER BY tts_font_skip, tts_font_name");
    $stmt->execute([$ttsKey]);
    jsonResponse(['rows' => $stmt->fetchAll()]);
}

if ($action === 'save_font') {
    $ttsKey   = (int)($data['tts_key'] ?? 0);
    $fontKey  = (int)($data['tts_font_key'] ?? 0);
    $name     = trim((string)($data['name'] ?? ''));
    $skip     = !empty($data['skip']);
    $pauseMs  = (int)($data['pause_ms'] ?? 0);
    if (!$ttsKey || $name === '') errorResponse('tts_key and name required');
    if ($fontKey > 0) {
        $stmt = $db->prepare("
            UPDATE yy_tts_font
               SET tts_font_name = ?, tts_font_skip = ?, tts_font_pause_ms = ?,
                   tts_font_revision_dtime = NOW()
             WHERE tts_font_key = ? AND tts_key = ?
        ");
        $stmt->execute([$name, (int)$skip, $pauseMs, $fontKey, $ttsKey]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO yy_tts_font (tts_key, tts_font_name, tts_font_skip, tts_font_pause_ms)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (tts_key, tts_font_name) DO UPDATE SET
                tts_font_skip = EXCLUDED.tts_font_skip,
                tts_font_pause_ms = EXCLUDED.tts_font_pause_ms,
                tts_font_revision_dtime = NOW()
            RETURNING tts_font_key
        ");
        $stmt->execute([$ttsKey, $name, (int)$skip, $pauseMs]);
        $fontKey = (int)$stmt->fetchColumn();
    }
    jsonResponse(['ok' => true, 'tts_font_key' => $fontKey]);
}

if ($action === 'delete_font') {
    $fontKey = (int)($data['tts_font_key'] ?? 0);
    if (!$fontKey) errorResponse('tts_font_key required');
    $db->prepare("DELETE FROM yy_tts_font WHERE tts_font_key = ?")->execute([$fontKey]);
    jsonResponse(['ok' => true]);
}

if ($method === 'GET' && $action === 'category_voices') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("SELECT * FROM yy_tts_category_voice WHERE tts_key = ? ORDER BY tts_category_voice_key");
    $stmt->execute([$ttsKey]);
    jsonResponse(['rows' => $stmt->fetchAll()]);
}

// Providers list — TTS-scoped. Includes any active provider that EITHER
// has voices in the catalog OR is mapped to the 'tts' functionality
// (with the mapping active). The union ensures newly-added cloud
// providers like Inworld show up immediately — before the operator has
// run "Check for new voices" — and that locally-defined engines that
// already have voices (CosyVoice) remain visible even if their tts
// mapping was never explicitly recorded. Must live above the POST-only
// gate below. See the matching save_provider POST handler near the
// bottom.
if ($method === 'GET' && $action === 'list_providers') {
    // The Providers table in the Voices tab passes include_inactive=1 so it can
    // still render (and re-enable) providers that have been switched off; the
    // active-flag filter is what the toggle controls, so an inactive provider
    // must not disappear from the very list used to flip it back on. All other
    // callers (Defaults/Pronunciations dropdowns, catalog refresh scope) omit
    // the flag and keep getting active-only providers.
    $includeInactive = !empty($_GET['include_inactive']);
    $activeClause = $includeInactive ? '' : 'p.provider_active_flag = TRUE AND ';
    // Provider 0 ("Default / fallback") is ALWAYS returned — it holds the global
    // default chunk config the client uses when no specific voice is selected.
    // The Voices UI hides it from the providers table + filters.
    $rows = $db->query("
        SELECT p.provider_key, p.provider_label, p.provider_main,
               p.provider_phonetic_type, p.provider_active_flag,
               p.provider_custom_voice_flag,
               p.provider_phonetic_capable, p.provider_ipa_capable,
               p.provider_settings ->> 'engine' AS provider_engine_code,
               p.provider_settings -> 'chunk'   AS provider_chunk,
               (p.provider_settings ->> 'always_async') AS provider_always_async,
               (SELECT COUNT(*) FROM yy_tts_voice v
                 WHERE v.provider_key = p.provider_key) AS voice_count
          FROM yy_provider p
         WHERE p.provider_key = 0 OR (" . $activeClause . "(
                p.provider_key IN (SELECT DISTINCT provider_key FROM yy_tts_voice)
             OR p.provider_key IN (
                    SELECT fp.provider_key
                      FROM yy_functionality_provider fp
                      JOIN yy_functionality f USING (functionality_key)
                     WHERE f.functionality_code = 'tts'
                       AND fp.functionality_provider_active_flag = TRUE
                )
           ))
         ORDER BY p.provider_sort, p.provider_label
    ")->fetchAll();
    jsonResponse(['providers' => $rows]);
}

// Series → Volumes → Chapters tree of everywhere ONE tune matches, with per
// chapter occurrence counts + built-audio status. Powers the click-through on
// the "# occurrences" column: the operator sees exactly where a word lives and
// which chapters can be re-queued so their audio picks up a new pronunciation.
if ($method === 'GET' && $action === 'word_locations') {
    $tuneKey = (int)($_GET['tts_tune_key'] ?? 0);
    if (!$tuneKey) errorResponse('tts_tune_key required');
    $st = $db->prepare("SELECT * FROM yy_tts_tune WHERE tts_tune_key = ?");
    $st->execute([$tuneKey]);
    $tune = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tune) errorResponse('tune not found', 404);
    $ttsKey = (int)($_GET['tts_key'] ?? 0) ?: (int)$tune['tts_key'];
    jsonResponse(ttsWordLocations($db, $ttsKey, $tune));
}

if ($method !== 'POST') errorResponse('Unknown action');

if ($action === 'save_system') {
    $ttsKey = (int)($data['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("UPDATE yy_tts SET tts_output_format = ?, tts_region = COALESCE(NULLIF(?, ''), tts_region) WHERE tts_key = ?");
    $stmt->execute([
        trim((string)($data['output_format'] ?? 'audio-24khz-48kbitrate-mono-mp3')),
        trim((string)($data['region'] ?? '')),
        $ttsKey,
    ]);
    jsonResponse(['ok' => true]);
}

// Persist the single-word audition carrier ("Warmup <word> Cooldown") for this
// tts_key. Surfaces as the Warmup / Cooldown fields on the Pronunciations
// preview bar; consumed by ttsWordAuditionFrame in admin-tts-preview.php. Stored
// trimmed (the frame re-joins the parts with single spaces); clearing both
// disables framing.
if ($action === 'save_audition_frame') {
    $ttsKey = (int)($data['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("UPDATE yy_tts SET tts_audition_warmup = ?, tts_audition_cooldown = ? WHERE tts_key = ?");
    $stmt->execute([
        mb_substr(trim((string)($data['warmup']   ?? '')), 0, 120),
        mb_substr(trim((string)($data['cooldown'] ?? '')), 0, 120),
        $ttsKey,
    ]);
    jsonResponse(['ok' => true]);
}

if ($action === 'save_category_voice') {
    $ttsKey   = (int)($data['tts_key'] ?? 0);
    $category = trim((string)($data['tts_category'] ?? ''));
    $voice    = trim((string)($data['voice_code'] ?? ''));
    // Profile: omitted = default profile for the tts_key. The Defaults
    // tab passes the user's currently-selected profile_key.
    $profileKey = ttsResolveProfileKey($db, $ttsKey, (int)($data['profile_key'] ?? 0) ?: null);
    if (!$ttsKey || $category === '') errorResponse('tts_key, tts_category required');
    if (!$profileKey) errorResponse('no profile resolved for tts_key (run profile migration?)');
    // Blank voice_code means "inherit from parent" — drop any existing
    // row for this (tts_key, profile, category) so buildVoiceBlock's
    // parent walk resolves to the parent's voice. No row = no override.
    if ($voice === '') {
        $db->prepare("DELETE FROM yy_tts_category_voice WHERE tts_key = ? AND tts_profile_key = ? AND tts_category = ?")
           ->execute([$ttsKey, $profileKey, $category]);
        jsonResponse(['ok' => true, 'cleared' => true]);
    }
    // read_flag controls whether content tagged with this category is
    // synthesised at all. When false, the build/preview pipeline skips
    // the segment entirely — no audio AND no inter-segment pause. Default
    // true keeps old callers that don't post the field behaving the same.
    $readFlag = array_key_exists('read_flag', $data) ? (bool)$data['read_flag'] : true;
    $stmt = $db->prepare("
        INSERT INTO yy_tts_category_voice
            (tts_key, tts_profile_key, tts_category, tts_voice_code, tts_voice_style, tts_voice_style_degree,
             tts_voice_rate_pct, tts_voice_pitch_st, tts_voice_volume, tts_category_voice_read_flag)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (tts_key, tts_profile_key, tts_category) DO UPDATE SET
            tts_voice_code = EXCLUDED.tts_voice_code,
            tts_voice_style = EXCLUDED.tts_voice_style,
            tts_voice_style_degree = EXCLUDED.tts_voice_style_degree,
            tts_voice_rate_pct = EXCLUDED.tts_voice_rate_pct,
            tts_voice_pitch_st = EXCLUDED.tts_voice_pitch_st,
            tts_voice_volume = EXCLUDED.tts_voice_volume,
            tts_category_voice_read_flag = EXCLUDED.tts_category_voice_read_flag,
            tts_category_voice_revision_dtime = NOW()
    ");
    $pitchSt    = max(-99.99, min(99.99, (float)($data['pitch_st']     ?? 0)));
    $styleDeg   = max(0,      min(9.99,  (float)($data['style_degree'] ?? 1.0)));
    $stmt->execute([
        $ttsKey, $profileKey, $category, $voice,
        $data['style'] ?? null,
        $styleDeg,
        (int)($data['rate_pct'] ?? 0),
        $pitchSt,
        (int)($data['volume'] ?? 100),
        (int)$readFlag,
    ]);
    jsonResponse(['ok' => true]);
}

if ($action === 'save_tune') {
    $ttsKey = (int)($data['tts_key'] ?? 0);
    $print  = trim((string)($data['print'] ?? ''));
    $sub    = trim((string)($data['phonetic_sub']  ?? ''));
    $ipa    = trim((string)($data['phonetic_ipa']  ?? ''));
    $sapi   = trim((string)($data['phonetic_sapi'] ?? ''));
    $type   = in_array(($data['phonetic_type'] ?? 'sub'), ['sub', 'ipa', 'sapi'], true) ? $data['phonetic_type'] : 'sub';
    $note   = trim((string)($data['note'] ?? ''));
    $active = !empty($data['active']);
    $mBold   = !empty($data['match_bold']);
    $mItalic = !empty($data['match_italic']);
    $mCase   = !empty($data['match_case_sensitive']);
    // Optional per-tune voice override + manual sort priority.
    // voice_code: when set, the substituted text is wrapped in a nested
    // <voice name="..."> so just THIS word switches voices; everything
    // else stays on the surrounding category voice.
    // sort:       higher value = higher priority when multiple tunes
    // match overlapping text. NULL/0 means default order (by length).
    $voiceCode = trim((string)($data['voice_code'] ?? ''));
    if ($voiceCode === '') $voiceCode = null;
    // Optional provider scoping. 0 (default) = applies to all providers.
    // When set, the tune only matches when synthesizing through that engine.
    // FK to yy_provider; rows with this column set are filtered in
    // ttsTunesForProvider in admin-tts-helpers.php.
    $providerKey = isset($data['provider_key']) ? (int)$data['provider_key'] : 0;
    $sort      = isset($data['sort']) && $data['sort'] !== '' ? (int)$data['sort'] : 0;
    // Seed range. Synth picks a random int in [min..max] for each
    // occurrence; deterministic when min == max. Pronunciation preview
    // uses seed_max so auditions are reproducible. Default is DETERMINISTIC
    // (min=max=0): a chunk's seed is set by the first tuned word that matches
    // it, so a random-range default (old 0/999) made whole paragraphs re-roll
    // every render regardless of a pinned word's own seed. Opt into variability
    // per-row by setting max>min explicitly.
    $seedMin = isset($data['seed_min']) && $data['seed_min'] !== '' ? (int)$data['seed_min'] : 0;
    $seedMax = isset($data['seed_max']) && $data['seed_max'] !== '' ? (int)$data['seed_max'] : 0;
    if ($seedMin < 0) $seedMin = 0;
    if ($seedMax < $seedMin) $seedMax = $seedMin;  // never let max fall below min
    if (!$ttsKey || $print === '') errorResponse('tts_key, print required');

    // book_count belongs to the Text (Print), not to the individual row —
    // any other row sharing the same (tts_key, print) already has the right
    // count from the parsed-book scanner, so inherit it for new inserts.
    $peerCountStmt = $db->prepare("SELECT MAX(tts_tune_book_count) FROM yy_tts_tune WHERE tts_key = ? AND tts_tune_print = ?");
    $peerCountStmt->execute([$ttsKey, $print]);
    $peerBookCount = (int)($peerCountStmt->fetchColumn() ?: 0);
    // Legacy tts_tune_phonetic mirror — kept in sync with whichever type
    // is currently chosen so older code paths keep working. If the chosen
    // column is empty, fall back to whichever of the three is populated.
    $chosen = ['sub' => $sub, 'ipa' => $ipa, 'sapi' => $sapi][$type] ?? '';
    $mirror = $chosen !== '' ? $chosen : ($sub !== '' ? $sub : ($ipa !== '' ? $ipa : $sapi));
    if ($mirror === '') $mirror = $print; // never let the not-null column go empty

    $tuneKey = (int)($data['tts_tune_key'] ?? 0);
    $cloned  = false;
    $deactivatedKeys = [];

    // Look up the row's prior voice_code so we can detect a NULL→set
    // transition. That transition triggers CLONE-and-keep-original
    // behavior — the original stays voice=NULL (the catch-all) and a
    // new row is inserted carrying the chosen voice. Also grab the prior
    // Print + match flags: the occurrence count depends ONLY on those, so
    // we recount solely when one of them changed (or the row is new) —
    // editing phonetic/IPA/seed/provider/voice/note leaves the count intact.
    $priorVoice = null;
    $needsRecount = ($tuneKey === 0);   // brand-new pronunciation always needs a count
    if ($tuneKey > 0) {
        $pv = $db->prepare("SELECT tts_tune_voice_code, tts_tune_print, tts_tune_match_bold, tts_tune_match_italic, tts_tune_match_case_sensitive FROM yy_tts_tune WHERE tts_tune_key = ? AND tts_key = ?");
        $pv->execute([$tuneKey, $ttsKey]);
        $priorRow = $pv->fetch(PDO::FETCH_ASSOC);
        if ($priorRow) {
            $priorVoice = $priorRow['tts_tune_voice_code'];
            if ($priorVoice === false) $priorVoice = null;
            $needsRecount =
                   ((string)$priorRow['tts_tune_print'] !== $print)
                || ((bool)$priorRow['tts_tune_match_bold']           !== $mBold)
                || ((bool)$priorRow['tts_tune_match_italic']         !== $mItalic)
                || ((bool)$priorRow['tts_tune_match_case_sensitive'] !== $mCase);
        }
    }
    $voiceTransitionNullToSet = ($tuneKey > 0 && $priorVoice === null && $voiceCode !== null && $voiceCode !== '');
    // A voice-transition clone inserts a NEW row that needs its own count.
    if ($voiceTransitionNullToSet) $needsRecount = true;

    if ($voiceTransitionNullToSet) {
        // Don't touch the original (voice-NULL) row's voice — only update
        // its non-voice columns from the form. Insert a CLONE that carries
        // the new voice. The catch-all stays usable; the clone is the
        // per-voice override.
        $upd = $db->prepare("
            UPDATE yy_tts_tune
               SET tts_tune_print = ?, tts_tune_phonetic = ?,
                   tts_tune_phonetic_sub = ?, tts_tune_phonetic_ipa = ?, tts_tune_phonetic_sapi = ?,
                   tts_tune_phonetic_type = ?, tts_tune_note = ?, tts_tune_active_flag = ?,
                   tts_tune_match_bold = ?, tts_tune_match_italic = ?, tts_tune_match_case_sensitive = ?,
                   tts_tune_sort = ?, provider_key = ?,
                   tts_tune_seed_min = ?, tts_tune_seed_max = ?,
                   tts_tune_revision_dtime = NOW()
             WHERE tts_tune_key = ? AND tts_key = ?
        ");
        $upd->execute([$print, $mirror, $sub, $ipa, $sapi, $type, $note ?: null, (int)$active, (int)$mBold, (int)$mItalic, (int)$mCase, $sort, $providerKey, $seedMin, $seedMax, $tuneKey, $ttsKey]);

        $ins = $db->prepare("
            INSERT INTO yy_tts_tune
                (tts_key, tts_tune_print, tts_tune_phonetic,
                 tts_tune_phonetic_sub, tts_tune_phonetic_ipa, tts_tune_phonetic_sapi,
                 tts_tune_phonetic_type, tts_tune_note, tts_tune_active_flag,
                 tts_tune_match_bold, tts_tune_match_italic, tts_tune_match_case_sensitive,
                 tts_tune_voice_code, tts_tune_sort, provider_key,
                 tts_tune_seed_min, tts_tune_seed_max,
                 tts_tune_book_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (tts_tune_print, provider_key, (COALESCE(tts_tune_voice_code, ''::character varying))) DO UPDATE SET
                tts_tune_phonetic             = EXCLUDED.tts_tune_phonetic,
                tts_tune_phonetic_sub         = EXCLUDED.tts_tune_phonetic_sub,
                tts_tune_phonetic_ipa         = EXCLUDED.tts_tune_phonetic_ipa,
                tts_tune_phonetic_sapi        = EXCLUDED.tts_tune_phonetic_sapi,
                tts_tune_phonetic_type        = EXCLUDED.tts_tune_phonetic_type,
                tts_tune_note                 = EXCLUDED.tts_tune_note,
                tts_tune_active_flag          = TRUE,
                tts_tune_match_bold           = EXCLUDED.tts_tune_match_bold,
                tts_tune_match_italic         = EXCLUDED.tts_tune_match_italic,
                tts_tune_match_case_sensitive = EXCLUDED.tts_tune_match_case_sensitive,
                tts_tune_sort                 = EXCLUDED.tts_tune_sort,
                provider_key                  = EXCLUDED.provider_key,
                tts_tune_seed_min             = EXCLUDED.tts_tune_seed_min,
                tts_tune_seed_max             = EXCLUDED.tts_tune_seed_max,
                tts_tune_revision_dtime       = NOW()
            RETURNING tts_tune_key
        ");
        $ins->execute([$ttsKey, $print, $mirror, $sub, $ipa, $sapi, $type, $note ?: null, (int)$mBold, (int)$mItalic, (int)$mCase, $voiceCode, $sort, $providerKey, $seedMin, $seedMax, $peerBookCount]);
        $tuneKey = (int)$ins->fetchColumn();
        $cloned  = true;
    } elseif ($tuneKey > 0) {
        // If renaming print / changing voice_code would land on a tuple already
        // held by another row, delete that row first (mirrors the ON CONFLICT DO
        // UPDATE on the unique index yy_tts_tune_print_voice_uq which covers
        // (tts_tune_print, provider_key, COALESCE(voice,''))).
        $clearColliding = $db->prepare("
            DELETE FROM yy_tts_tune
             WHERE tts_tune_print = ?
               AND provider_key = ?
               AND COALESCE(tts_tune_voice_code, '') = COALESCE(?, '')
               AND tts_tune_key != ?
        ");
        $clearColliding->execute([$print, $providerKey, $voiceCode, $tuneKey]);

        $stmt = $db->prepare("
            UPDATE yy_tts_tune
               SET tts_tune_print = ?, tts_tune_phonetic = ?,
                   tts_tune_phonetic_sub = ?, tts_tune_phonetic_ipa = ?, tts_tune_phonetic_sapi = ?,
                   tts_tune_phonetic_type = ?, tts_tune_note = ?, tts_tune_active_flag = ?,
                   tts_tune_match_bold = ?, tts_tune_match_italic = ?, tts_tune_match_case_sensitive = ?,
                   tts_tune_voice_code = ?, tts_tune_sort = ?, provider_key = ?,
                   tts_tune_seed_min = ?, tts_tune_seed_max = ?,
                   tts_tune_revision_dtime = NOW()
             WHERE tts_tune_key = ? AND tts_key = ?
        ");
        // Cast PHP booleans to int (0/1) because PDO's PostgreSQL driver
        // serialises bool false as "" which Postgres rejects.
        $stmt->execute([$print, $mirror, $sub, $ipa, $sapi, $type, $note ?: null, (int)$active, (int)$mBold, (int)$mItalic, (int)$mCase, $voiceCode, $sort, $providerKey, $seedMin, $seedMax, $tuneKey, $ttsKey]);
    } else {
        // Brand-new row. Use ON CONFLICT on (tts_tune_print, provider_key, voice_code)
        // which matches the unique index yy_tts_tune_print_voice_uq:
        //   (tts_tune_print, provider_key, COALESCE(tts_tune_voice_code, ''))
        // Allows multiple rows per Print as long as each has a distinct voice_code.
        $stmt = $db->prepare("
            INSERT INTO yy_tts_tune
                (tts_key, tts_tune_print, tts_tune_phonetic,
                 tts_tune_phonetic_sub, tts_tune_phonetic_ipa, tts_tune_phonetic_sapi,
                 tts_tune_phonetic_type, tts_tune_note, tts_tune_active_flag,
                 tts_tune_match_bold, tts_tune_match_italic, tts_tune_match_case_sensitive,
                 tts_tune_voice_code, tts_tune_sort, provider_key,
                 tts_tune_seed_min, tts_tune_seed_max,
                 tts_tune_book_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (tts_tune_print, provider_key, (COALESCE(tts_tune_voice_code, ''::character varying))) DO UPDATE SET
                tts_tune_phonetic              = EXCLUDED.tts_tune_phonetic,
                tts_tune_phonetic_sub          = EXCLUDED.tts_tune_phonetic_sub,
                tts_tune_phonetic_ipa          = EXCLUDED.tts_tune_phonetic_ipa,
                tts_tune_phonetic_sapi         = EXCLUDED.tts_tune_phonetic_sapi,
                tts_tune_phonetic_type         = EXCLUDED.tts_tune_phonetic_type,
                tts_tune_note                  = EXCLUDED.tts_tune_note,
                tts_tune_active_flag           = EXCLUDED.tts_tune_active_flag,
                tts_tune_match_bold            = EXCLUDED.tts_tune_match_bold,
                tts_tune_match_italic          = EXCLUDED.tts_tune_match_italic,
                tts_tune_match_case_sensitive  = EXCLUDED.tts_tune_match_case_sensitive,
                tts_tune_sort                  = EXCLUDED.tts_tune_sort,
                provider_key                   = EXCLUDED.provider_key,
                tts_tune_seed_min              = EXCLUDED.tts_tune_seed_min,
                tts_tune_seed_max              = EXCLUDED.tts_tune_seed_max,
                tts_tune_revision_dtime = NOW()
            RETURNING tts_tune_key
        ");
        $stmt->execute([$ttsKey, $print, $mirror, $sub, $ipa, $sapi, $type, $note ?: null, (int)$active, (int)$mBold, (int)$mItalic, (int)$mCase, $voiceCode, $sort, $providerKey, $seedMin, $seedMax, $peerBookCount]);
        $tuneKey = (int)$stmt->fetchColumn();
    }

    // After any save where this row ends up with a voice override,
    // mark OTHER active same-Print voice-override rows inactive so only
    // ONE voice-specific override remains active per Print (alongside
    // the voice-NULL catch-all, which stays untouched). Scoped to the SAME
    // provider_key — tunes are per-provider, so a voice override for one
    // provider must not deactivate another provider's override for the
    // same Print.
    if ($voiceCode !== null && $voiceCode !== '') {
        $deact = $db->prepare("
            UPDATE yy_tts_tune
               SET tts_tune_active_flag = FALSE,
                   tts_tune_revision_dtime = NOW()
             WHERE tts_key = ?
               AND tts_tune_print = ?
               AND provider_key = ?
               AND tts_tune_voice_code IS NOT NULL
               AND tts_tune_key <> ?
               AND tts_tune_active_flag = TRUE
            RETURNING tts_tune_key
        ");
        $deact->execute([$ttsKey, $print, $providerKey, $tuneKey]);
        $deactivatedKeys = array_map('intval', $deact->fetchAll(PDO::FETCH_COLUMN));
    }

    // Recompute the occurrence count (# column) for every row of this Print,
    // but ONLY when it can actually have changed: a brand-new pronunciation,
    // a Print rename, or a bold/italic/case FILTER change. Editing phonetic/
    // IPA/seed/provider/voice/note leaves the count untouched, so we skip the
    // corpus scan there. Best effort: a counting hiccup must not fail the save.
    $bookCount = null;
    try {
        if ($needsRecount) {
            recountTuneBookCountForPrint($db, $ttsKey, $print);
        }
        $bcStmt = $db->prepare("SELECT tts_tune_book_count FROM yy_tts_tune WHERE tts_tune_key = ?");
        $bcStmt->execute([$tuneKey]);
        $bookCount = (int)$bcStmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('recountTuneBookCountForPrint failed for print "' . $print . '": ' . $e->getMessage());
    }

    jsonResponse([
        'ok'                 => true,
        'tts_tune_key'       => $tuneKey,
        'cloned'             => $cloned,
        'deactivated_keys'   => $deactivatedKeys,
        'book_count'         => $bookCount,
    ]);
}

if ($action === 'delete_tune') {
    $tuneKey = (int)($data['tts_tune_key'] ?? 0);
    if (!$tuneKey) errorResponse('tts_tune_key required');
    // No recount needed: each row's tts_tune_book_count is computed
    // independently (one tune vs the corpus), so removing a row never changes
    // any sibling's count.
    $db->prepare("DELETE FROM yy_tts_tune WHERE tts_tune_key = ?")->execute([$tuneKey]);
    jsonResponse(['ok' => true]);
}

// Re-queue the checked chapters so paragraphs matching ONE tune re-render with
// the current pronunciation. Mirrors the _*_resweep.php scripts: delete only
// the matching paragraphs' positional part files and flip a 'complete' chapter
// to 'pending' so the build watchdog rebuilds it, reusing every unaffected
// cached part. Send apply=false for a dry run (counts only, no disk/DB writes).
if ($action === 'queue_word_regen') {
    $tuneKey     = (int)($data['tts_tune_key'] ?? 0);
    $chapterKeys = $data['chapter_keys'] ?? [];
    $apply       = array_key_exists('apply', $data) ? !empty($data['apply']) : true;
    if (!$tuneKey) errorResponse('tts_tune_key required');
    if (!is_array($chapterKeys) || !$chapterKeys) errorResponse('chapter_keys required');

    $st = $db->prepare("SELECT * FROM yy_tts_tune WHERE tts_tune_key = ?");
    $st->execute([$tuneKey]);
    $tune = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tune) errorResponse('tune not found', 404);
    $ttsKey = (int)$tune['tts_key'];

    // Resolve the repo root that holds /u (mirrors the resweep scripts).
    $audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__)
               : (is_dir('/var/www/html/u') ? '/var/www/html' : dirname(__DIR__));

    $totals = ['chapters' => 0, 'chapters_touched' => 0, 'audio_rows' => 0,
               'affected_paragraphs' => 0, 'requeued' => 0, 'already_pending' => 0,
               'primed_paused' => 0, 'parts_deleted' => 0, 'reflagged' => 0];
    $perChapter = [];
    foreach ($chapterKeys as $ck) {
        $ck = (int)$ck;
        if ($ck <= 0) continue;
        $r = ttsQueueWordRegenForChapter($db, $audioBase, $ttsKey, $ck, $tune, $apply);
        $perChapter[] = $r;
        $totals['chapters']            += 1;
        if ($r['requeued'] > 0) $totals['chapters_touched'] += 1;
        $totals['audio_rows']          += $r['audio_rows'];
        $totals['affected_paragraphs'] += $r['affected_paragraphs'];
        $totals['requeued']            += $r['requeued'];
        $totals['already_pending']     += $r['already_pending'];
        $totals['primed_paused']       += $r['primed_paused'];
        $totals['parts_deleted']       += $r['parts_deleted'];
        $totals['reflagged']           += $r['reflagged'];
    }
    jsonResponse(['ok' => true, 'applied' => $apply, 'print' => (string)$tune['tts_tune_print'],
                  'totals' => $totals, 'chapters' => $perChapter]);
}

if ($action === 'save_pause') {
    $ttsKey = (int)($data['tts_key'] ?? 0);
    $search = (string)($data['search'] ?? '');  // preserve spaces, don't trim
    $ms     = (int)($data['ms'] ?? 300);
    $note   = trim((string)($data['note'] ?? ''));
    $sort   = (int)($data['sort'] ?? 0);
    $active = !empty($data['active']);
    if (!$ttsKey || $search === '') errorResponse('tts_key, search required');

    $pauseKey = (int)($data['tts_pause_key'] ?? 0);
    if ($pauseKey > 0) {
        $stmt = $db->prepare("
            UPDATE yy_tts_pause
               SET tts_pause_search = ?, tts_pause_ms = ?, tts_pause_note = ?,
                   tts_pause_sort = ?, tts_pause_active_flag = ?,
                   tts_pause_revision_dtime = NOW()
             WHERE tts_pause_key = ? AND tts_key = ?
        ");
        $stmt->execute([$search, $ms, $note ?: null, $sort, (int)$active, $pauseKey, $ttsKey]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO yy_tts_pause
                (tts_key, tts_pause_search, tts_pause_ms, tts_pause_note, tts_pause_sort, tts_pause_active_flag)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT (tts_key, tts_pause_search) DO UPDATE SET
                tts_pause_ms = EXCLUDED.tts_pause_ms,
                tts_pause_note = EXCLUDED.tts_pause_note,
                tts_pause_sort = EXCLUDED.tts_pause_sort,
                tts_pause_active_flag = EXCLUDED.tts_pause_active_flag,
                tts_pause_revision_dtime = NOW()
            RETURNING tts_pause_key
        ");
        $stmt->execute([$ttsKey, $search, $ms, $note ?: null, $sort, (int)$active]);
        $pauseKey = (int)$stmt->fetchColumn();
    }
    jsonResponse(['ok' => true, 'tts_pause_key' => $pauseKey]);
}

if ($action === 'delete_pause') {
    $pauseKey = (int)($data['tts_pause_key'] ?? 0);
    if (!$pauseKey) errorResponse('tts_pause_key required');
    $db->prepare("DELETE FROM yy_tts_pause WHERE tts_pause_key = ?")->execute([$pauseKey]);
    jsonResponse(['ok' => true]);
}

// ── save_provider (POST) ─────────────────────────────────────────────
// Editable per-provider properties. Whitelist-driven so we don't expose
// every yy_provider column to the client; new fields are added by
// extending $allowed. The matching list_providers GET handler lives
// above the POST-only gate near the top of this file.
if ($action === 'save_provider') {
    $providerKey = (int)($data['provider_key'] ?? 0);
    if (!$providerKey) errorResponse('provider_key required');
    // Whitelist of editable fields. New fields can be added here
    // without changing the calling JS, which sends only the fields it
    // wants to update.
    // Bool → int 0/1. PDO renders PHP bool false as '' which Postgres boolean
    // columns reject (see project rule), so we never bind a raw bool. Accepts
    // JSON true/false, 1/0, "true"/"false". Never returns null (no invalid case).
    $boolToInt = function ($v) {
        if (is_string($v)) {
            $lv = strtolower(trim($v));
            return ($lv === 'false' || $lv === '0' || $lv === '') ? 0 : 1;
        }
        return $v ? 1 : 0;
    };
    $allowed = [
        'provider_phonetic_type' => function ($v) {
            $v = strtolower(trim((string)$v));
            return in_array($v, ['sub', 'ipa'], true) ? $v : null;
        },
        // Active/Inactive toggle.
        'provider_active_flag' => $boolToInt,
        // Whether custom voices can be trained/cloned for this provider.
        'provider_custom_voice_flag' => $boolToInt,
        // Pronunciation CAPABILITY flags (distinct from the phonetic_type
        // preference above): does this engine actually consume the SUB
        // respelling / IPA at synth time. The Pronunciations tab gates its
        // IPA<->Phonetic column toggle on these.
        'provider_phonetic_capable' => $boolToInt,
        'provider_ipa_capable' => $boolToInt,
    ];
    $sets = [];
    $params = [];
    foreach ($allowed as $col => $sanitize) {
        if (!array_key_exists($col, $data)) continue;
        $clean = $sanitize($data[$col]);
        if ($clean === null) errorResponse('invalid value for ' . $col);
        $sets[] = "$col = ?";
        $params[] = $clean;
    }
    // Per-provider chunk sizing lives in provider_settings (jsonb), not a plain
    // column — merge it in. This is the single source of truth the Voices form
    // edits. Validation rails only (10..600), ordered (max≥target≥min).
    if (array_key_exists('chunk', $data) && is_array($data['chunk'])) {
        $c    = $data['chunk'];
        $cmax = max(40, min(600, (int)($c['max'] ?? 0)));
        $ctar = max(20, min($cmax, (int)($c['target'] ?? 0)));
        $cmin = max(10, min($ctar, (int)($c['min'] ?? 0)));
        $sets[]   = "provider_settings = COALESCE(provider_settings, '{}'::jsonb) || jsonb_build_object('chunk', jsonb_build_object('min', ?::int, 'target', ?::int, 'max', ?::int))";
        $params[] = $cmin; $params[] = $ctar; $params[] = $cmax;
    }
    if (!$sets) errorResponse('no editable fields supplied');
    $sets[] = "provider_revision_dtime = NOW()";
    $sets[] = "provider_revision_num = COALESCE(provider_revision_num, 0) + 1";
    $params[] = $providerKey;
    $sql = "UPDATE yy_provider SET " . implode(', ', $sets) . " WHERE provider_key = ?";
    $db->prepare($sql)->execute($params);
    jsonResponse(['ok' => true, 'provider_key' => $providerKey]);
}

errorResponse('Unknown action: ' . $action);
