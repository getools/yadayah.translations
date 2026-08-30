<?php
/**
 * Admin TTS — Voice catalog manager.
 *
 *   GET  ?action=list&tts_key=N[&locale=en-US][&gender=Male][&type=Neural][&q=text][&active=1|0]
 *     → { total, voices: [...] }   one row per voice, all metadata + active flag
 *
 *   POST { action:'save_active',  tts_voice_key, active_flag }      single toggle
 *   POST { action:'bulk_save',    items:[{tts_voice_key, active_flag},...] }
 *   POST { action:'refresh',      tts_key }
 *     → pulls Azure /voices/list, upserts every row, never deactivates an
 *       existing row (admin's active picks stick across refreshes), returns
 *       { added, updated, total }.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data   = [];
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
}

if ($method === 'GET' && $action === 'list') {
    // tts_key is OPTIONAL — omit/zero = list across all providers (the
    // "All providers" option in the Voices catalog dropdown). The Provider
    // column distinguishes rows in the merged view.
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    $where  = [];
    $params = [];
    if ($ttsKey > 0) { $where[] = 'tts_key = ?'; $params[] = $ttsKey; }
    if (!empty($_GET['locale']))  { $where[] = 'tts_voice_locale = ?'; $params[] = $_GET['locale']; }
    if (!empty($_GET['gender']))  { $where[] = 'tts_voice_gender = ?'; $params[] = $_GET['gender']; }
    if (!empty($_GET['type']))    { $where[] = 'tts_voice_type ILIKE ?'; $params[] = '%' . $_GET['type'] . '%'; }
    if (isset($_GET['active']) && $_GET['active'] !== '') {
        $where[] = 'tts_voice_active_flag = ?';
        $params[] = ((int)$_GET['active']) ? 't' : 'f';
    }
    if (!empty($_GET['q'])) {
        $where[] = '(tts_voice_code ILIKE ? OR tts_voice_label ILIKE ? OR tts_voice_locale_name ILIKE ?)';
        $q = '%' . $_GET['q'] . '%';
        $params[] = $q; $params[] = $q; $params[] = $q;
    }
    // JOIN yy_tts for the system label AND yy_provider for the engine
    // vendor — the Voices catalog "Provider" filter wants the engine
    // (Azure / ElevenLabs / Kokoro / etc.), not the system name. Falls
    // back to tts_name in the frontend when a voice row has no
    // provider_key (legacy rows).
    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT v.*, t.tts_code, t.tts_name,
                   p.provider_label, p.provider_main, p.provider_engine
              FROM yy_tts_voice v
         LEFT JOIN yy_tts t USING (tts_key)
         LEFT JOIN yy_provider p USING (provider_key)"
         . $whereSql
         . " ORDER BY tts_voice_locale, tts_voice_gender, tts_voice_label, tts_voice_code";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['tts_voice_styles']            = json_decode($r['tts_voice_styles']            ?? '[]', true) ?: [];
        $r['tts_voice_roles']             = json_decode($r['tts_voice_roles']             ?? '[]', true) ?: [];
        $r['tts_voice_secondary_locales'] = json_decode($r['tts_voice_secondary_locales'] ?? '[]', true) ?: [];
    }
    unset($r);

    // Summary: distinct locale list + total/active counts, for the UI filter
    // dropdown and the header summary row. Scope mirrors the list above —
    // no tts_key = all providers.
    $sumWhere = $ttsKey > 0 ? 'WHERE tts_key = ?' : '';
    $sumParams = $ttsKey > 0 ? [$ttsKey] : [];
    $sumStmt = $db->prepare("
        SELECT COUNT(*) AS total,
               COUNT(*) FILTER (WHERE tts_voice_active_flag) AS active_count,
               MAX(tts_voice_download_dtime) AS last_refresh
          FROM yy_tts_voice $sumWhere
    ");
    $sumStmt->execute($sumParams);
    $summary = $sumStmt->fetch();

    $localesWhere = $ttsKey > 0 ? 'WHERE tts_key = ? AND tts_voice_locale IS NOT NULL'
                                : 'WHERE tts_voice_locale IS NOT NULL';
    $localesStmt = $db->prepare("
        SELECT tts_voice_locale, tts_voice_locale_name, COUNT(*) AS n
          FROM yy_tts_voice $localesWhere
         GROUP BY tts_voice_locale, tts_voice_locale_name
         ORDER BY tts_voice_locale
    ");
    $localesStmt->execute($sumParams);
    jsonResponse([
        'voices'  => $rows,
        'summary' => $summary,
        'locales' => $localesStmt->fetchAll(),
    ]);
}

if ($method !== 'POST') errorResponse('Unknown action');

if ($action === 'save_active') {
    $voiceKey = (int)($data['tts_voice_key'] ?? 0);
    if (!$voiceKey) errorResponse('tts_voice_key required');
    $flag = !empty($data['active_flag']) ? 't' : 'f';
    $db->prepare("UPDATE yy_tts_voice SET tts_voice_active_flag = ?, tts_voice_revision_dtime = NOW() WHERE tts_voice_key = ?")
       ->execute([$flag, $voiceKey]);
    jsonResponse(['ok' => true]);
}

// Description — the long free-form field opened via the pencil icon in
// the catalog. Schema column tts_voice_description (was tts_voice_note
// pre-rename; the popover semantically was always a description).
if ($action === 'save_description') {
    $voiceKey = (int)($data['tts_voice_key'] ?? 0);
    if (!$voiceKey) errorResponse('tts_voice_key required');
    $description = trim((string)($data['description'] ?? $data['note'] ?? ''));
    $db->prepare("UPDATE yy_tts_voice SET tts_voice_description = ?, tts_voice_revision_dtime = NOW() WHERE tts_voice_key = ?")
       ->execute([$description === '' ? null : $description, $voiceKey]);
    jsonResponse(['ok' => true]);
}

// Note — the short inline tag shown next to the voice's label in the
// catalog. Use for short admin-facing identifiers ("v3", "post-clean")
// without renaming the upstream voice.
if ($action === 'save_note') {
    $voiceKey = (int)($data['tts_voice_key'] ?? 0);
    if (!$voiceKey) errorResponse('tts_voice_key required');
    $note = trim((string)($data['note'] ?? ''));
    if (mb_strlen($note) > 60) errorResponse('note too long (60 char limit)');
    $db->prepare("UPDATE yy_tts_voice SET tts_voice_note = ?, tts_voice_revision_dtime = NOW() WHERE tts_voice_key = ?")
       ->execute([$note === '' ? null : $note, $voiceKey]);
    jsonResponse(['ok' => true]);
}

// Inline catalog edits (row-select): language / region / gender / styles /
// multilingual override. Works for ANY voice (builtin included). DB-only —
// these are catalog metadata used for display, filtering and preview; the
// engine-synced edit path for custom voices is admin-tts-voice-edit.php.
if ($action === 'save_fields') {
    $voiceKey = (int)($data['tts_voice_key'] ?? 0);
    if (!$voiceKey) errorResponse('tts_voice_key required');
    $sets = [];
    $args = [];
    if (array_key_exists('language', $data)) {
        $v = trim((string)$data['language']);
        $sets[] = 'tts_voice_language = ?'; $args[] = $v === '' ? null : $v;
    }
    if (array_key_exists('region', $data)) {
        $v = trim((string)$data['region']);
        $sets[] = 'tts_voice_region = ?'; $args[] = $v === '' ? null : $v;
    }
    if (array_key_exists('gender', $data)) {
        $v = trim((string)$data['gender']);
        $sets[] = 'tts_voice_gender = ?'; $args[] = $v === '' ? null : $v;
    }
    if (array_key_exists('styles', $data)) {
        // Accept an array or a comma-separated string; store a clean JSONB array.
        $raw = $data['styles'];
        if (!is_array($raw)) $raw = explode(',', (string)$raw);
        $clean = [];
        foreach ($raw as $s) {
            $s = trim((string)$s);
            if ($s !== '' && !in_array($s, $clean, true)) $clean[] = $s;
        }
        $sets[] = 'tts_voice_styles = ?::jsonb';
        $args[] = json_encode($clean, JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('multi_flag', $data)) {
        $mf = $data['multi_flag'];
        if ($mf === null || $mf === '') {
            $sets[] = 'tts_voice_multi_flag = NULL';   // clear override → derived
        } else {
            // bool → int 0/1 (PDO renders PHP false as '' which Postgres bool rejects).
            $sets[] = 'tts_voice_multi_flag = ?';
            $args[] = ($mf === true || $mf === 1 || $mf === '1' || $mf === 'true') ? 1 : 0;
        }
    }
    if (!$sets) errorResponse('no editable fields supplied');
    $sets[] = 'tts_voice_revision_dtime = NOW()';
    $args[] = $voiceKey;
    $db->prepare("UPDATE yy_tts_voice SET " . implode(', ', $sets) . " WHERE tts_voice_key = ?")
       ->execute($args);
    jsonResponse(['ok' => true, 'tts_voice_key' => $voiceKey]);
}

if ($action === 'bulk_save') {
    $items = $data['items'] ?? [];
    if (!is_array($items)) errorResponse('items must be an array');
    $stmt = $db->prepare("UPDATE yy_tts_voice SET tts_voice_active_flag = ?, tts_voice_revision_dtime = NOW() WHERE tts_voice_key = ?");
    $db->beginTransaction();
    try {
        foreach ($items as $it) {
            $stmt->execute([
                !empty($it['active_flag']) ? 't' : 'f',
                (int)($it['tts_voice_key'] ?? 0),
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('bulk save failed: ' . $e->getMessage());
    }
    jsonResponse(['ok' => true, 'count' => count($items)]);
}

if ($action === 'refresh') {
    $ttsKey = (int)($data['tts_key'] ?? 0);

    // tts_key optional — omit/zero = "All providers" (loop active rows in
    // yy_tts). Each provider whose tts_code we don't yet know how to refresh
    // (anything besides azure, today) is reported as skipped, not an error,
    // so the bulk refresh succeeds even when local engines are listed.
    if ($ttsKey > 0) {
        $sysStmt = $db->prepare("SELECT * FROM yy_tts WHERE tts_key = ?");
        $sysStmt->execute([$ttsKey]);
    } else {
        $sysStmt = $db->query("SELECT * FROM yy_tts WHERE tts_active_flag = TRUE ORDER BY tts_sort, tts_name");
    }
    $systems = $sysStmt->fetchAll();
    if (!$systems) errorResponse('no providers to refresh');

    $totalAdded = 0; $totalUpdated = 0; $perProvider = [];

    foreach ($systems as $sys) {
        $provKey  = (int)$sys['tts_key'];
        $provCode = $sys['tts_code'];

        // ── Inworld adapter ──────────────────────────────────────────────
        if ($provCode === 'inworld') {
            $apiKey = readEnv('INWORLD_API_KEY');
            if (!$apiKey) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => 'INWORLD_API_KEY not set'];
                continue;
            }
            $iwProviderKey = (int)$db->query("SELECT provider_key FROM yy_provider
                                                WHERE provider_main='Inworld' AND provider_engine='TTS'
                                                ORDER BY provider_key LIMIT 1")->fetchColumn();
            if ($iwProviderKey <= 0) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => 'no Inworld provider row found'];
                continue;
            }
            // GET /tts/v1/voices — single page (not paginated per docs).
            // The API key in .env is the pre-encoded Basic credential as
            // issued by Inworld's dashboard; pass it verbatim.
            $authHeader = 'Authorization: Basic ' . $apiKey;
            $ch = curl_init('https://api.inworld.ai/tts/v1/voices');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [$authHeader, 'Accept: application/json'],
                CURLOPT_TIMEOUT        => 30,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);
            if ($resp === false || $httpCode >= 400) {
                errorResponse('Inworld /v1/voices failed: HTTP ' . $httpCode . ' ' . ($cerr ?: substr((string)$resp, 0, 300)));
            }
            $body = json_decode((string)$resp, true);
            $voices = is_array($body['voices'] ?? null) ? $body['voices'] : [];

            $upsert = $db->prepare("
                INSERT INTO yy_tts_voice
                    (tts_key, tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_locale_name,
                     tts_voice_language, tts_voice_region, tts_voice_gender, tts_voice_type,
                     tts_voice_styles, tts_voice_status, provider_key, tts_voice_description,
                     tts_voice_download_dtime)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, NOW())
                ON CONFLICT (tts_key, tts_voice_code) DO UPDATE SET
                    tts_voice_label             = EXCLUDED.tts_voice_label,
                    tts_voice_locale            = EXCLUDED.tts_voice_locale,
                    tts_voice_locale_name       = EXCLUDED.tts_voice_locale_name,
                    tts_voice_language          = EXCLUDED.tts_voice_language,
                    tts_voice_region            = EXCLUDED.tts_voice_region,
                    tts_voice_gender            = EXCLUDED.tts_voice_gender,
                    tts_voice_type              = EXCLUDED.tts_voice_type,
                    tts_voice_styles            = EXCLUDED.tts_voice_styles,
                    tts_voice_status            = EXCLUDED.tts_voice_status,
                    provider_key                = EXCLUDED.provider_key,
                    tts_voice_description       = EXCLUDED.tts_voice_description,
                    tts_voice_download_dtime    = NOW()
                RETURNING (xmax = 0) AS is_insert
            ");

            $added = 0; $updated = 0;
            $db->beginTransaction();
            try {
                foreach ($voices as $v) {
                    $code = (string)($v['voiceId'] ?? '');
                    if ($code === '') continue;
                    $label = (string)($v['displayName'] ?? $code);
                    $desc  = (string)($v['description'] ?? '');
                    $tags  = is_array($v['tags'] ?? null) ? $v['tags'] : [];
                    $langs = is_array($v['languages'] ?? null) ? $v['languages'] : [];
                    $isCustom = !empty($v['isCustom']);

                    // Gender from tags ("male" / "female"). Inworld doesn't
                    // expose a dedicated gender field; tag matching is the
                    // closest signal.
                    $gender = null;
                    foreach ($tags as $t) {
                        $tl = strtolower((string)$t);
                        if ($tl === 'male' || $tl === 'female') { $gender = ucfirst($tl); break; }
                    }
                    // Inworld voices are multilingual — primary locale = en-US
                    // unless an explicit 2-letter code is listed first.
                    $primary = (string)($langs[0] ?? 'en');
                    $locale = ($primary === 'en') ? 'en-US' : $primary;
                    $localeName = ($primary === 'en') ? 'English (United States)' : strtoupper($primary);

                    $voiceType = $isCustom ? 'Custom' : 'Neural';
                    $noteParts = array_filter([
                        $desc,
                        $tags ? 'tags:' . implode(',', $tags) : '',
                        $langs ? 'langs:' . implode(',', $langs) : '',
                        $isCustom ? 'custom' : '',
                    ]);
                    $note = implode(' · ', $noteParts);

                    $upsert->execute([
                        $provKey, $code, $label,
                        $locale, $localeName,
                        substr($locale, 0, 2), substr($locale, 3),
                        $gender, $voiceType,
                        json_encode([], JSON_UNESCAPED_UNICODE),
                        $isCustom ? 'custom' : 'premade',
                        $iwProviderKey,
                        $note,
                    ]);
                    $row = $upsert->fetch();
                    if ($row && $row['is_insert']) $added++; else $updated++;
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                errorResponse('refresh failed for ' . $provCode . ': ' . $e->getMessage());
            }
            $totStmt = $db->prepare("SELECT COUNT(*) FROM yy_tts_voice WHERE tts_key = ?");
            $totStmt->execute([$provKey]);
            $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                              'added' => $added, 'updated' => $updated,
                              'total' => (int)$totStmt->fetchColumn()];
            $totalAdded   += $added;
            $totalUpdated += $updated;
            continue;
        }

        // ── Kokoro adapter (Puget box, /catalog endpoint) ─────────────────
        // Kokoro-82M ships ~54 fixed style-vector voice packs (NOT clonable);
        // the box's /catalog?provider=kokoro now enumerates the full HF repo
        // voice list (code/label/locale/locale_name/language/gender/type/note).
        // Upserted with active_flag left at its column default (false) so the
        // admin flips on only the wanted ones. ON CONFLICT deliberately does
        // NOT touch tts_voice_active_flag (admin picks stick across refreshes)
        // nor tts_voice_status (the seeded ko-af-sky/bella stay 'GA'). The box
        // keeps voice_id/lang_code in voices.json, so synth routing needs no
        // extra column here — the tts_voice_code ('ko-...') is the only key.
        // ── VibeVoice (standalone container, own /catalog) ──
        // Its catalog is the set of voices.json entries with provider=vibevoice
        // (reference clips shared with the Chatterbox voices). Modelled on the
        // kokoro branch. Imported rows come in 'active' like kokoro's, since
        // every entry here is one an admin deliberately registered.
        // Standalone gateway-routed engines that serve their own /catalog.
        // Adding another such engine = add its code here (plus the row in
        // gpuStandaloneEngines()); no new branch needs writing.
        if (in_array($provCode, ['vibevoice', 'qwen3tts'], true)) {
            require_once __DIR__ . '/gpu-client.php';
            if (!gpuConfigured()) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => 'GPU_BASE_URL / GPU_API_TOKEN not set in .env'];
                continue;
            }
            $vvStmt = $db->prepare(
                "SELECT provider_key FROM yy_provider
                  WHERE provider_settings->>'engine' = ?
                  ORDER BY provider_key LIMIT 1");
            $vvStmt->execute([$provCode]);
            $vvProvKey = (int)$vvStmt->fetchColumn();
            if ($vvProvKey <= 0) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => "no yy_provider row for engine '$provCode'"];
                continue;
            }
            try {
                $resp = gpuProviderCatalog($provCode);
            } catch (Throwable $e) {
                errorResponse("Puget /catalog failed for $provCode: " . $e->getMessage());
            }
            $voices = is_array($resp['data']['voices'] ?? null) ? $resp['data']['voices'] : [];

            $upsert = $db->prepare("
                INSERT INTO yy_tts_voice
                    (tts_key, tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_locale_name,
                     tts_voice_language, tts_voice_region, tts_voice_gender, tts_voice_type,
                     tts_voice_styles, tts_voice_status, provider_key, tts_voice_note,
                     tts_voice_download_dtime)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '[]'::jsonb, ?, ?, ?, NOW())
                ON CONFLICT (tts_key, tts_voice_code) DO UPDATE SET
                    tts_voice_label             = EXCLUDED.tts_voice_label,
                    tts_voice_locale            = EXCLUDED.tts_voice_locale,
                    tts_voice_language          = EXCLUDED.tts_voice_language,
                    tts_voice_gender            = EXCLUDED.tts_voice_gender,
                    tts_voice_type              = EXCLUDED.tts_voice_type,
                    provider_key                = EXCLUDED.provider_key,
                    tts_voice_download_dtime    = NOW()
                RETURNING (xmax = 0) AS is_insert
            ");
            $added = 0; $updated = 0;
            $db->beginTransaction();
            try {
                foreach ($voices as $v) {
                    $code = (string)($v['code'] ?? '');
                    if ($code === '') continue;
                    $locale = (string)($v['locale'] ?? '');
                    $region = (strpos($locale, '-') !== false)
                            ? substr($locale, strpos($locale, '-') + 1) : null;
                    $gender = (string)($v['gender'] ?? '');
                    $gender = ($gender === '' || strtolower($gender) === 'unknown')
                            ? null : ucfirst(strtolower($gender));
                    $upsert->execute([
                        $provKey,
                        $code,
                        substr((string)($v['label'] ?? $code), 0, 250),
                        $locale !== '' ? substr($locale, 0, 20) : null,
                        null,
                        isset($v['language']) ? substr((string)$v['language'], 0, 8) : null,
                        $region !== null ? substr($region, 0, 8) : null,
                        $gender !== null ? substr($gender, 0, 20) : null,
                        isset($v['type']) ? substr((string)$v['type'], 0, 60) : 'Cloned',
                        'active',
                        $vvProvKey,
                        (string)($v['note'] ?? ''),
                    ]);
                    $row = $upsert->fetch(PDO::FETCH_ASSOC);
                    if ($row && $row['is_insert']) $added++; else $updated++;
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                errorResponse("yy_tts_voice upsert failed for $provCode: " . $e->getMessage());
            }
            $totalAdded   += $added;
            $totalUpdated += $updated;
            $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                              'added' => $added, 'updated' => $updated, 'total' => count($voices)];
            continue;
        }

        if ($provCode === 'kokoro') {
            require_once __DIR__ . '/gpu-client.php';
            if (!gpuConfigured()) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => 'GPU_BASE_URL / GPU_API_TOKEN not set in .env'];
                continue;
            }
            $kokoroProvKey = (int)$db->query(
                "SELECT provider_key FROM yy_provider
                  WHERE provider_settings->>'engine'='kokoro'
                  ORDER BY provider_key LIMIT 1"
            )->fetchColumn();
            if ($kokoroProvKey <= 0) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => 'no Kokoro yy_provider row'];
                continue;
            }
            try {
                $resp = gpuProviderCatalog($provCode);
            } catch (Throwable $e) {
                errorResponse("Puget /catalog failed for $provCode: " . $e->getMessage());
            }
            $voices = is_array($resp['data']['voices'] ?? null) ? $resp['data']['voices'] : [];

            $upsert = $db->prepare("
                INSERT INTO yy_tts_voice
                    (tts_key, tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_locale_name,
                     tts_voice_language, tts_voice_region, tts_voice_gender, tts_voice_type,
                     tts_voice_styles, tts_voice_status, provider_key, tts_voice_note,
                     tts_voice_download_dtime)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '[]'::jsonb, ?, ?, ?, NOW())
                ON CONFLICT (tts_key, tts_voice_code) DO UPDATE SET
                    tts_voice_label             = EXCLUDED.tts_voice_label,
                    tts_voice_locale            = EXCLUDED.tts_voice_locale,
                    tts_voice_locale_name       = EXCLUDED.tts_voice_locale_name,
                    tts_voice_language          = EXCLUDED.tts_voice_language,
                    tts_voice_region            = EXCLUDED.tts_voice_region,
                    tts_voice_gender            = EXCLUDED.tts_voice_gender,
                    tts_voice_type              = EXCLUDED.tts_voice_type,
                    provider_key                = EXCLUDED.provider_key,
                    tts_voice_note              = EXCLUDED.tts_voice_note,
                    tts_voice_download_dtime    = NOW()
                RETURNING (xmax = 0) AS is_insert
            ");
            $added = 0; $updated = 0;
            $db->beginTransaction();
            try {
                foreach ($voices as $v) {
                    $code = (string)($v['code'] ?? '');
                    if ($code === '') continue;
                    $locale = (string)($v['locale'] ?? '');
                    $region = (strpos($locale, '-') !== false)
                            ? substr($locale, strpos($locale, '-') + 1) : null;
                    $gender = (string)($v['gender'] ?? '');
                    $gender = ($gender === '' || strtolower($gender) === 'unknown')
                            ? null : ucfirst(strtolower($gender));
                    $upsert->execute([
                        $provKey,
                        $code,
                        substr((string)($v['label'] ?? $code), 0, 250),
                        $locale !== '' ? substr($locale, 0, 20) : null,
                        isset($v['locale_name']) ? substr((string)$v['locale_name'], 0, 120) : null,
                        isset($v['language'])    ? substr((string)$v['language'],    0, 8)   : null,
                        $region !== null ? substr($region, 0, 8) : null,
                        $gender !== null ? substr($gender, 0, 20) : null,
                        isset($v['type']) ? substr((string)$v['type'], 0, 60) : 'Builtin',
                        'active',
                        $kokoroProvKey,
                        (string)($v['note'] ?? ''),
                    ]);
                    $row = $upsert->fetch(PDO::FETCH_ASSOC);
                    if ($row && $row['is_insert']) $added++; else $updated++;
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                errorResponse("yy_tts_voice upsert failed for $provCode: " . $e->getMessage());
            }
            $totalAdded   += $added;
            $totalUpdated += $updated;
            $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                              'added' => $added, 'updated' => $updated, 'total' => count($voices)];
            continue;
        }

        // ── Coqui xtts + coqui adapters (Puget box, /catalog endpoint) ──
        // gpuProviderCatalog() returns the FULL voice/model pool the box
        // can serve (XTTS built-in speakers, or every Coqui TTS model);
        // upserted with active_flag defaulted to false so admins flip on
        // only the ones they actually want. ON CONFLICT preserves the
        // active_flag on subsequent refreshes.
        if ($provCode === 'xtts' || $provCode === 'coqui') {
            require_once __DIR__ . '/gpu-client.php';
            if (!gpuConfigured()) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => 'GPU_BASE_URL / GPU_API_TOKEN not set in .env'];
                continue;
            }
            $engineLabel = $provCode === 'xtts' ? 'XTTS v2' : 'TTS toolkit';
            $coquiProvKey = (int)$db->query(
                "SELECT provider_key FROM yy_provider
                  WHERE provider_main='Coqui' AND provider_engine="
                . $db->quote($engineLabel)
                . " ORDER BY provider_key LIMIT 1"
            )->fetchColumn();
            if ($coquiProvKey <= 0) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => "no Coqui $engineLabel yy_provider row"];
                continue;
            }
            try {
                $resp = gpuProviderCatalog($provCode);
            } catch (Throwable $e) {
                errorResponse("Puget /catalog failed for $provCode: " . $e->getMessage());
            }
            // gpuRequest wraps the engine's JSON under 'data'.
            $voices = is_array($resp['data']['voices'] ?? null) ? $resp['data']['voices'] : [];

            $upsert = $db->prepare("
                INSERT INTO yy_tts_voice
                    (tts_key, tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_locale_name,
                     tts_voice_language, tts_voice_region, tts_voice_gender, tts_voice_type,
                     tts_voice_styles, tts_voice_status, provider_key, tts_voice_note,
                     tts_voice_download_dtime)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '[]'::jsonb, ?, ?, ?, NOW())
                ON CONFLICT (tts_key, tts_voice_code) DO UPDATE SET
                    tts_voice_label             = EXCLUDED.tts_voice_label,
                    tts_voice_locale            = EXCLUDED.tts_voice_locale,
                    tts_voice_locale_name       = EXCLUDED.tts_voice_locale_name,
                    tts_voice_language          = EXCLUDED.tts_voice_language,
                    tts_voice_region            = EXCLUDED.tts_voice_region,
                    tts_voice_gender            = EXCLUDED.tts_voice_gender,
                    tts_voice_type              = EXCLUDED.tts_voice_type,
                    tts_voice_status            = EXCLUDED.tts_voice_status,
                    provider_key                = EXCLUDED.provider_key,
                    tts_voice_note              = EXCLUDED.tts_voice_note,
                    tts_voice_download_dtime    = NOW()
                RETURNING (xmax = 0) AS is_insert
            ");
            $added = 0; $updated = 0;
            $db->beginTransaction();
            try {
                foreach ($voices as $v) {
                    $code = (string)($v['code'] ?? '');
                    if ($code === '') continue;
                    // Truncate fields with tight varchar caps so a long
                    // model-path segment can't poison the whole refresh.
                    $upsert->execute([
                        $provKey,
                        $code,
                        substr((string)($v['label'] ?? $code), 0, 250),
                        $v['locale']      !== null ? substr((string)$v['locale'],      0, 20)  : null,
                        $v['locale_name'] !== null ? substr((string)$v['locale_name'], 0, 120) : null,
                        $v['language']    !== null ? substr((string)$v['language'],    0, 8)   : null,
                        $v['region']      !== null ? substr((string)$v['region'],      0, 8)   : null,
                        $v['gender']      !== null ? substr((string)$v['gender'],      0, 20)  : null,
                        $v['type']        !== null ? substr((string)$v['type'],        0, 60)  : null,
                        'active',
                        $coquiProvKey,
                        (string)($v['note'] ?? ''),
                    ]);
                    $row = $upsert->fetch(PDO::FETCH_ASSOC);
                    if ($row && $row['is_insert']) $added++; else $updated++;
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                errorResponse("yy_tts_voice upsert failed for $provCode: " . $e->getMessage());
            }
            $totalAdded   += $added;
            $totalUpdated += $updated;
            $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                              'added' => $added, 'updated' => $updated, 'total' => count($voices)];
            continue;
        }

        // ── MOSS-TTS-Nano adapter (Puget box, /catalog endpoint) ───────────
        // MOSS is clone-first with no enumerable speaker catalog, so the box's
        // /catalog?provider=moss returns the registered voices.json entries
        // (code / label / language / gender / description). Locale + type are
        // derived here since the clone-voice payload doesn't carry them. Bundled
        // reference clips come in as type 'Clone'. ON CONFLICT preserves the
        // admin's active_flag picks across refreshes.
        if ($provCode === 'moss') {
            require_once __DIR__ . '/gpu-client.php';
            if (!gpuConfigured()) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => 'GPU_BASE_URL / GPU_API_TOKEN not set in .env'];
                continue;
            }
            $mossProvKey = (int)$db->query(
                "SELECT provider_key FROM yy_provider
                  WHERE provider_main='MOSS' AND provider_engine='TTS-Nano'
                  ORDER BY provider_key LIMIT 1"
            )->fetchColumn();
            if ($mossProvKey <= 0) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => 'no MOSS-TTS-Nano yy_provider row'];
                continue;
            }
            try {
                $resp = gpuProviderCatalog($provCode);
            } catch (Throwable $e) {
                errorResponse("Puget /catalog failed for $provCode: " . $e->getMessage());
            }
            $voices = is_array($resp['data']['voices'] ?? null) ? $resp['data']['voices'] : [];

            // MOSS clone voices only carry a 2-letter language; map it to a
            // representative locale so the catalog's locale filter groups them.
            $localeFor = static function (string $lang): array {
                switch (strtolower($lang)) {
                    case 'en':            return ['en-US', 'English (United States)'];
                    case 'ja': case 'jp': return ['ja-JP', 'Japanese (Japan)'];
                    case 'zh':            return ['zh-CN', 'Chinese (Mandarin, Simplified)'];
                    default:
                        $l = $lang !== '' ? $lang : 'en';
                        return [$l, strtoupper($l)];
                }
            };

            $upsert = $db->prepare("
                INSERT INTO yy_tts_voice
                    (tts_key, tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_locale_name,
                     tts_voice_language, tts_voice_region, tts_voice_gender, tts_voice_type,
                     tts_voice_styles, tts_voice_status, provider_key, tts_voice_description,
                     tts_voice_download_dtime)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '[]'::jsonb, ?, ?, ?, NOW())
                ON CONFLICT (tts_key, tts_voice_code) DO UPDATE SET
                    tts_voice_label             = EXCLUDED.tts_voice_label,
                    tts_voice_locale            = EXCLUDED.tts_voice_locale,
                    tts_voice_locale_name       = EXCLUDED.tts_voice_locale_name,
                    tts_voice_language          = EXCLUDED.tts_voice_language,
                    tts_voice_region            = EXCLUDED.tts_voice_region,
                    tts_voice_gender            = EXCLUDED.tts_voice_gender,
                    tts_voice_type              = EXCLUDED.tts_voice_type,
                    tts_voice_status            = EXCLUDED.tts_voice_status,
                    provider_key                = EXCLUDED.provider_key,
                    tts_voice_description       = EXCLUDED.tts_voice_description,
                    tts_voice_download_dtime    = NOW()
                RETURNING (xmax = 0) AS is_insert
            ");
            $added = 0; $updated = 0;
            $db->beginTransaction();
            try {
                foreach ($voices as $v) {
                    $code = (string)($v['code'] ?? '');
                    if ($code === '') continue;
                    $lang = (string)($v['language'] ?? 'en');
                    [$locale, $localeName] = $localeFor($lang);
                    $region = (strpos($locale, '-') !== false)
                            ? substr($locale, strpos($locale, '-') + 1) : null;
                    $gender = (string)($v['gender'] ?? '');
                    $gender = ($gender === '' || strtolower($gender) === 'unknown')
                            ? null : ucfirst(strtolower($gender));
                    $upsert->execute([
                        $provKey,
                        $code,
                        substr((string)($v['label'] ?? $code), 0, 250),
                        substr($locale, 0, 20),
                        substr($localeName, 0, 120),
                        substr($lang, 0, 8),
                        $region !== null ? substr($region, 0, 8) : null,
                        $gender !== null ? substr($gender, 0, 20) : null,
                        'Clone',
                        'active',
                        $mossProvKey,
                        (string)($v['description'] ?? ''),
                    ]);
                    $row = $upsert->fetch(PDO::FETCH_ASSOC);
                    if ($row && $row['is_insert']) $added++; else $updated++;
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                errorResponse("yy_tts_voice upsert failed for $provCode: " . $e->getMessage());
            }
            $totalAdded   += $added;
            $totalUpdated += $updated;
            $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                              'added' => $added, 'updated' => $updated, 'total' => count($voices)];
            continue;
        }

        // ── ElevenLabs adapter ───────────────────────────────────────────
        if ($provCode === 'elevenlabs') {
            $apiKey = readEnv('ELEVENLABS_API_KEY');
            if (!$apiKey) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => 'ELEVENLABS_API_KEY not set'];
                continue;
            }
            // The catalog row needs to point at the engine vendor (yy_provider)
            // so the Voice Catalog's Provider filter labels these as "ElevenLabs",
            // not "Azure" (which is the default on tts_voice.provider_key).
            $elProviderKey = (int)$db->query("SELECT provider_key FROM yy_provider
                                               WHERE provider_main='ElevenLabs' AND provider_engine='Multilingual TTS'
                                               ORDER BY provider_key LIMIT 1")->fetchColumn();
            if ($elProviderKey <= 0) {
                $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                                  'skipped' => 'no ElevenLabs provider row found'];
                continue;
            }
            // /v2/voices is paginated; loop until has_more=false.
            $allVoices = [];
            $nextToken = '';
            $pageGuard = 0;
            do {
                $url = 'https://api.elevenlabs.io/v2/voices?page_size=100'
                     . ($nextToken !== '' ? ('&next_page_token=' . urlencode($nextToken)) : '');
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['xi-api-key: ' . $apiKey, 'Accept: application/json'],
                    CURLOPT_TIMEOUT        => 30,
                ]);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $cerr = curl_error($ch);
                curl_close($ch);
                if ($resp === false || $httpCode >= 400) {
                    errorResponse('ElevenLabs /v2/voices failed: HTTP ' . $httpCode . ' ' . ($cerr ?: substr((string)$resp, 0, 300)));
                }
                $body = json_decode($resp, true);
                if (!is_array($body) || !isset($body['voices'])) break;
                foreach ($body['voices'] as $v) $allVoices[] = $v;
                $nextToken = (string)($body['next_page_token'] ?? '');
                $hasMore   = !empty($body['has_more']) && $nextToken !== '';
            } while ($hasMore && ++$pageGuard < 50);

            // Upsert. Note we INCLUDE provider_key here (the Azure block omits
            // it because the column default 1 = Azure happens to be correct).
            $upsert = $db->prepare("
                INSERT INTO yy_tts_voice
                    (tts_key, tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_locale_name,
                     tts_voice_language, tts_voice_region, tts_voice_gender, tts_voice_type,
                     tts_voice_styles, tts_voice_status, provider_key, tts_voice_description,
                     tts_voice_download_dtime)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, NOW())
                ON CONFLICT (tts_key, tts_voice_code) DO UPDATE SET
                    tts_voice_label             = EXCLUDED.tts_voice_label,
                    tts_voice_locale            = EXCLUDED.tts_voice_locale,
                    tts_voice_locale_name       = EXCLUDED.tts_voice_locale_name,
                    tts_voice_language          = EXCLUDED.tts_voice_language,
                    tts_voice_region            = EXCLUDED.tts_voice_region,
                    tts_voice_gender            = EXCLUDED.tts_voice_gender,
                    tts_voice_type              = EXCLUDED.tts_voice_type,
                    tts_voice_styles            = EXCLUDED.tts_voice_styles,
                    tts_voice_status            = EXCLUDED.tts_voice_status,
                    provider_key                = EXCLUDED.provider_key,
                    tts_voice_description       = EXCLUDED.tts_voice_description,
                    tts_voice_download_dtime    = NOW()
                RETURNING (xmax = 0) AS is_insert
            ");

            $added = 0; $updated = 0;
            $db->beginTransaction();
            try {
                foreach ($allVoices as $v) {
                    $code = $v['voice_id'] ?? '';
                    if ($code === '') continue;
                    $name = (string)($v['name'] ?? $code);
                    $labels = is_array($v['labels'] ?? null) ? $v['labels'] : [];
                    $category = (string)($v['category'] ?? 'premade'); // premade | cloned | generated | professional
                    $accent = (string)($labels['accent'] ?? '');
                    $description = (string)($labels['description'] ?? '');
                    $useCase = (string)($labels['use_case'] ?? '');
                    $age = (string)($labels['age'] ?? '');
                    $gender = $labels['gender'] ?? null;
                    if ($gender !== null && $gender !== '') $gender = ucfirst(strtolower((string)$gender));
                    else $gender = null;
                    // Map accent → locale. ElevenLabs models are multilingual; the
                    // accent label is just a hint for English voices.
                    $locale = 'en-US'; $localeName = 'English (United States)';
                    if (stripos($accent, 'british') !== false)        { $locale = 'en-GB'; $localeName = 'English (United Kingdom)'; }
                    elseif (stripos($accent, 'australian') !== false) { $locale = 'en-AU'; $localeName = 'English (Australia)'; }
                    elseif (stripos($accent, 'irish') !== false)      { $locale = 'en-IE'; $localeName = 'English (Ireland)'; }
                    elseif (stripos($accent, 'indian') !== false)     { $locale = 'en-IN'; $localeName = 'English (India)'; }
                    elseif (stripos($accent, 'transatlantic') !== false) { $locale = 'en-US'; $localeName = 'English (Transatlantic)'; }
                    $language = substr($locale, 0, 2);
                    $region   = substr($locale, 3);
                    $voiceType = match($category) {
                        'cloned'       => 'Cloned',
                        'professional' => 'Professional',
                        'generated'    => 'Generated',
                        default        => 'Neural',
                    };
                    $noteParts = array_filter([
                        $description,
                        $age ? "age:$age" : '',
                        $useCase ? "use:$useCase" : '',
                        "cat:$category",
                    ]);
                    $note = implode(' · ', $noteParts);
                    $upsert->execute([
                        $provKey, $code, $name,
                        $locale, $localeName,
                        $language, $region,
                        $gender, $voiceType,
                        json_encode([], JSON_UNESCAPED_UNICODE),
                        $category,
                        $elProviderKey,
                        $note,
                    ]);
                    $row = $upsert->fetch();
                    if ($row && $row['is_insert']) $added++; else $updated++;
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                errorResponse('refresh failed for ' . $provCode . ': ' . $e->getMessage());
            }
            $totStmt = $db->prepare("SELECT COUNT(*) FROM yy_tts_voice WHERE tts_key = ?");
            $totStmt->execute([$provKey]);
            $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                              'added' => $added, 'updated' => $updated,
                              'total' => (int)$totStmt->fetchColumn()];
            $totalAdded   += $added;
            $totalUpdated += $updated;
            continue;
        }

        if ($provCode !== 'azure') {
            $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                              'skipped' => 'refresh not yet wired for this provider'];
            continue;
        }
        // Existing Azure refresh — runs once per matching row.
        $cfg = loadTtsConfig($db, $provKey);
    $key = readEnv('AZURE_SPEECH_KEY');
    if (!$key) errorResponse('AZURE_SPEECH_KEY not set in .env');
    $region = $cfg['system']['tts_region'] ?? (readEnv('AZURE_SPEECH_REGION') ?: 'brazilsouth');

    $ch = curl_init("https://{$region}.tts.speech.microsoft.com/cognitiveservices/voices/list");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Ocp-Apim-Subscription-Key: ' . $key],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $httpCode >= 400) {
        errorResponse('Azure /voices/list failed: HTTP ' . $httpCode . ' ' . ($err ?: substr((string)$resp, 0, 200)));
    }
    $voices = json_decode($resp, true);
    if (!is_array($voices)) errorResponse('Azure /voices/list returned non-array body');

    $upsert = $db->prepare("
        INSERT INTO yy_tts_voice
            (tts_key, tts_voice_code, tts_voice_label, tts_voice_locale, tts_voice_locale_name,
             tts_voice_gender, tts_voice_type, tts_voice_styles, tts_voice_roles, tts_voice_secondary_locales,
             tts_voice_sample_rate_hz, tts_voice_words_per_minute, tts_voice_status,
             tts_voice_download_dtime)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?, ?, ?, NOW())
        ON CONFLICT (tts_key, tts_voice_code) DO UPDATE SET
            tts_voice_label              = EXCLUDED.tts_voice_label,
            tts_voice_locale             = EXCLUDED.tts_voice_locale,
            tts_voice_locale_name        = EXCLUDED.tts_voice_locale_name,
            tts_voice_gender             = EXCLUDED.tts_voice_gender,
            tts_voice_type               = EXCLUDED.tts_voice_type,
            tts_voice_styles             = EXCLUDED.tts_voice_styles,
            tts_voice_roles              = EXCLUDED.tts_voice_roles,
            tts_voice_secondary_locales  = EXCLUDED.tts_voice_secondary_locales,
            tts_voice_sample_rate_hz     = EXCLUDED.tts_voice_sample_rate_hz,
            tts_voice_words_per_minute   = EXCLUDED.tts_voice_words_per_minute,
            tts_voice_status             = EXCLUDED.tts_voice_status,
            tts_voice_download_dtime     = NOW()
        RETURNING (xmax = 0) AS is_insert
    ");

    $added = 0; $updated = 0;
    $db->beginTransaction();
    try {
        foreach ($voices as $v) {
            $code = $v['ShortName'] ?? '';
            if ($code === '') continue;
            $label = $v['DisplayName'] ?? $v['LocalName'] ?? $code;
            $secondary = is_array($v['SecondaryLocaleList'] ?? null) ? $v['SecondaryLocaleList'] : [];
            $styles    = is_array($v['StyleList'] ?? null)            ? $v['StyleList']            : [];
            $roles     = is_array($v['RolePlayList'] ?? null)         ? $v['RolePlayList']         : [];
            $upsert->execute([
                $provKey, $code, $label,
                $v['Locale']     ?? null,
                $v['LocaleName'] ?? null,
                $v['Gender']     ?? null,
                $v['VoiceType']  ?? null,
                json_encode($styles,    JSON_UNESCAPED_UNICODE),
                json_encode($roles,     JSON_UNESCAPED_UNICODE),
                json_encode($secondary, JSON_UNESCAPED_UNICODE),
                isset($v['SampleRateHertz'])  ? (int)$v['SampleRateHertz']  : null,
                isset($v['WordsPerMinute'])   ? (int)$v['WordsPerMinute']   : null,
                $v['Status'] ?? null,
            ]);
            $row = $upsert->fetch();
            if ($row && $row['is_insert']) $added++; else $updated++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('refresh failed for ' . $provCode . ': ' . $e->getMessage());
    }

        $totStmt = $db->prepare("SELECT COUNT(*) FROM yy_tts_voice WHERE tts_key = ?");
        $totStmt->execute([$provKey]);
        $perProvider[] = ['tts_key' => $provKey, 'tts_code' => $provCode,
                          'added' => $added, 'updated' => $updated,
                          'total' => (int)$totStmt->fetchColumn()];
        $totalAdded   += $added;
        $totalUpdated += $updated;
    } // end foreach provider

    // Whole-catalog totals — caller's UI shows total/active across the
    // catalog after a refresh (matches the "all providers" filter view).
    $grandStmt = $db->query("SELECT COUNT(*) FROM yy_tts_voice");
    jsonResponse([
        'ok'        => true,
        'added'     => $totalAdded,
        'updated'   => $totalUpdated,
        'total'     => (int)$grandStmt->fetchColumn(),
        'providers' => $perProvider,
    ]);
}

errorResponse('Unknown action: ' . $action);
