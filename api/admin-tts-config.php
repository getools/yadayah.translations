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
    // Provider list for the Pronunciations Provider column. Only engines that
    // actually pronounce something — i.e. yy_provider rows whose label is
    // 'Azure Neural TTS' or whose settings.engine is a known local engine.
    // TTS engines only — local engines declare settings.engine, plus Azure
    // Neural TTS (provider_key=1). Excludes STT providers (Azure Fast
    // Transcription, Whisper variants, etc.) so the Pronunciations Provider
    // dropdown stays meaningful.
    $provStmt = $db->query("
        SELECT provider_key, provider_label, provider_settings->>'engine' AS engine
          FROM yy_provider
         WHERE provider_active_flag = TRUE
           AND (provider_settings->>'engine' IS NOT NULL OR provider_key = 1)
         ORDER BY provider_key
    ")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse([
        'voices'     => azureVoiceCatalog(),
        'formats'    => azureOutputFormats(),
        'categories' => ttsCategories(),
        'providers'  => $provStmt,
    ]);
}

if ($method === 'GET' && $action === 'overview') {
    $systems = $db->query("SELECT * FROM yy_tts WHERE tts_active_flag = TRUE ORDER BY tts_sort, tts_key")->fetchAll();
    $ttsKey = (int)($_GET['tts_key'] ?? ($systems[0]['tts_key'] ?? 0));
    $current = null;
    if ($ttsKey) {
        $cfg = loadTtsConfig($db, $ttsKey);
        $current = [
            'system'       => $cfg['system'],
            'categories'   => array_values($cfg['categories']),
            'tunes_count'  => (int)$db->query("SELECT COUNT(*) FROM yy_tts_tune  WHERE tts_key = $ttsKey")->fetchColumn(),
            'pauses_count' => (int)$db->query("SELECT COUNT(*) FROM yy_tts_pause WHERE tts_key = $ttsKey")->fetchColumn(),
        ];
    }
    jsonResponse(['systems' => $systems, 'current' => $current]);
}

if ($method === 'GET' && $action === 'tunes') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("SELECT * FROM yy_tts_tune WHERE tts_key = ? ORDER BY tts_tune_sort, tts_tune_print");
    $stmt->execute([$ttsKey]);
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

if ($action === 'save_category_voice') {
    $ttsKey   = (int)($data['tts_key'] ?? 0);
    $category = trim((string)($data['tts_category'] ?? ''));
    $voice    = trim((string)($data['voice_code'] ?? ''));
    if (!$ttsKey || $category === '') errorResponse('tts_key, tts_category required');
    // Blank voice_code means "inherit from parent" — drop any existing
    // row for this (tts_key, category) so buildVoiceBlock's parent walk
    // resolves to the parent's voice. No row = no override.
    if ($voice === '') {
        $db->prepare("DELETE FROM yy_tts_category_voice WHERE tts_key = ? AND tts_category = ?")
           ->execute([$ttsKey, $category]);
        jsonResponse(['ok' => true, 'cleared' => true]);
    }
    $stmt = $db->prepare("
        INSERT INTO yy_tts_category_voice
            (tts_key, tts_category, tts_voice_code, tts_voice_style, tts_voice_style_degree,
             tts_voice_rate_pct, tts_voice_pitch_st, tts_voice_volume)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (tts_key, tts_category) DO UPDATE SET
            tts_voice_code = EXCLUDED.tts_voice_code,
            tts_voice_style = EXCLUDED.tts_voice_style,
            tts_voice_style_degree = EXCLUDED.tts_voice_style_degree,
            tts_voice_rate_pct = EXCLUDED.tts_voice_rate_pct,
            tts_voice_pitch_st = EXCLUDED.tts_voice_pitch_st,
            tts_voice_volume = EXCLUDED.tts_voice_volume,
            tts_category_voice_revision_dtime = NOW()
    ");
    $stmt->execute([
        $ttsKey, $category, $voice,
        $data['style'] ?? null,
        $data['style_degree'] ?? 1.0,
        (int)($data['rate_pct'] ?? 0),
        (float)($data['pitch_st'] ?? 0),
        (int)($data['volume'] ?? 100),
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
    if (!$ttsKey || $print === '') errorResponse('tts_key, print required');
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
    // new row is inserted carrying the chosen voice.
    $priorVoice = null;
    if ($tuneKey > 0) {
        $pv = $db->prepare("SELECT tts_tune_voice_code FROM yy_tts_tune WHERE tts_tune_key = ? AND tts_key = ?");
        $pv->execute([$tuneKey, $ttsKey]);
        $priorVoice = $pv->fetchColumn();
        if ($priorVoice === false) $priorVoice = null;
    }
    $voiceTransitionNullToSet = ($tuneKey > 0 && $priorVoice === null && $voiceCode !== null && $voiceCode !== '');

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
                   tts_tune_revision_dtime = NOW()
             WHERE tts_tune_key = ? AND tts_key = ?
        ");
        $upd->execute([$print, $mirror, $sub, $ipa, $sapi, $type, $note ?: null, (int)$active, (int)$mBold, (int)$mItalic, (int)$mCase, $sort, $providerKey, $tuneKey, $ttsKey]);

        $ins = $db->prepare("
            INSERT INTO yy_tts_tune
                (tts_key, tts_tune_print, tts_tune_phonetic,
                 tts_tune_phonetic_sub, tts_tune_phonetic_ipa, tts_tune_phonetic_sapi,
                 tts_tune_phonetic_type, tts_tune_note, tts_tune_active_flag,
                 tts_tune_match_bold, tts_tune_match_italic, tts_tune_match_case_sensitive,
                 tts_tune_voice_code, tts_tune_sort, provider_key)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?, ?, ?, ?, ?, ?)
            RETURNING tts_tune_key
        ");
        $ins->execute([$ttsKey, $print, $mirror, $sub, $ipa, $sapi, $type, $note ?: null, (int)$mBold, (int)$mItalic, (int)$mCase, $voiceCode, $sort, $providerKey]);
        $tuneKey = (int)$ins->fetchColumn();
        $cloned  = true;
    } elseif ($tuneKey > 0) {
        $stmt = $db->prepare("
            UPDATE yy_tts_tune
               SET tts_tune_print = ?, tts_tune_phonetic = ?,
                   tts_tune_phonetic_sub = ?, tts_tune_phonetic_ipa = ?, tts_tune_phonetic_sapi = ?,
                   tts_tune_phonetic_type = ?, tts_tune_note = ?, tts_tune_active_flag = ?,
                   tts_tune_match_bold = ?, tts_tune_match_italic = ?, tts_tune_match_case_sensitive = ?,
                   tts_tune_voice_code = ?, tts_tune_sort = ?, provider_key = ?,
                   tts_tune_revision_dtime = NOW()
             WHERE tts_tune_key = ? AND tts_key = ?
        ");
        // Cast PHP booleans to int (0/1) because PDO's PostgreSQL driver
        // serialises bool false as "" which Postgres rejects.
        $stmt->execute([$print, $mirror, $sub, $ipa, $sapi, $type, $note ?: null, (int)$active, (int)$mBold, (int)$mItalic, (int)$mCase, $voiceCode, $sort, $providerKey, $tuneKey, $ttsKey]);
    } else {
        // Brand-new row. Use ON CONFLICT on the (tts_key, Print, voice_code)
        // tuple — that matches the new unique index that allows multiple
        // rows per Print as long as each has a distinct voice_code.
        $stmt = $db->prepare("
            INSERT INTO yy_tts_tune
                (tts_key, tts_tune_print, tts_tune_phonetic,
                 tts_tune_phonetic_sub, tts_tune_phonetic_ipa, tts_tune_phonetic_sapi,
                 tts_tune_phonetic_type, tts_tune_note, tts_tune_active_flag,
                 tts_tune_match_bold, tts_tune_match_italic, tts_tune_match_case_sensitive,
                 tts_tune_voice_code, tts_tune_sort, provider_key)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (tts_key, tts_tune_print, (COALESCE(tts_tune_voice_code, ''))) DO UPDATE SET
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
                tts_tune_revision_dtime = NOW()
            RETURNING tts_tune_key
        ");
        $stmt->execute([$ttsKey, $print, $mirror, $sub, $ipa, $sapi, $type, $note ?: null, (int)$active, (int)$mBold, (int)$mItalic, (int)$mCase, $voiceCode, $sort, $providerKey]);
        $tuneKey = (int)$stmt->fetchColumn();
    }

    // After any save where this row ends up with a voice override,
    // mark OTHER active same-Print voice-override rows inactive so only
    // ONE voice-specific override remains active per Print (alongside
    // the voice-NULL catch-all, which stays untouched).
    if ($voiceCode !== null && $voiceCode !== '') {
        $deact = $db->prepare("
            UPDATE yy_tts_tune
               SET tts_tune_active_flag = FALSE,
                   tts_tune_revision_dtime = NOW()
             WHERE tts_key = ?
               AND tts_tune_print = ?
               AND tts_tune_voice_code IS NOT NULL
               AND tts_tune_key <> ?
               AND tts_tune_active_flag = TRUE
            RETURNING tts_tune_key
        ");
        $deact->execute([$ttsKey, $print, $tuneKey]);
        $deactivatedKeys = array_map('intval', $deact->fetchAll(PDO::FETCH_COLUMN));
    }

    jsonResponse([
        'ok'                 => true,
        'tts_tune_key'       => $tuneKey,
        'cloned'             => $cloned,
        'deactivated_keys'   => $deactivatedKeys,
    ]);
}

if ($action === 'delete_tune') {
    $tuneKey = (int)($data['tts_tune_key'] ?? 0);
    if (!$tuneKey) errorResponse('tts_tune_key required');
    $db->prepare("DELETE FROM yy_tts_tune WHERE tts_tune_key = ?")->execute([$tuneKey]);
    jsonResponse(['ok' => true]);
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

errorResponse('Unknown action: ' . $action);
