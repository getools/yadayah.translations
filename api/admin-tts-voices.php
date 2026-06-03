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
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $where  = ['tts_key = ?'];
    $params = [$ttsKey];
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
    // JOIN yy_tts so each row carries its provider code/label — the Voices
    // catalog table renders that in the Provider column (in front of Voice).
    $sql = "SELECT v.*, t.tts_code, t.tts_name
              FROM yy_tts_voice v
         LEFT JOIN yy_tts t USING (tts_key)
             WHERE " . implode(' AND ', $where)
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
    // dropdown and the header summary row.
    $sumStmt = $db->prepare("
        SELECT COUNT(*) AS total,
               COUNT(*) FILTER (WHERE tts_voice_active_flag) AS active_count,
               MAX(tts_voice_download_dtime) AS last_refresh
          FROM yy_tts_voice WHERE tts_key = ?
    ");
    $sumStmt->execute([$ttsKey]);
    $summary = $sumStmt->fetch();

    $localesStmt = $db->prepare("
        SELECT tts_voice_locale, tts_voice_locale_name, COUNT(*) AS n
          FROM yy_tts_voice WHERE tts_key = ? AND tts_voice_locale IS NOT NULL
         GROUP BY tts_voice_locale, tts_voice_locale_name
         ORDER BY tts_voice_locale
    ");
    $localesStmt->execute([$ttsKey]);
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

if ($action === 'save_note') {
    $voiceKey = (int)($data['tts_voice_key'] ?? 0);
    if (!$voiceKey) errorResponse('tts_voice_key required');
    $note = trim((string)($data['note'] ?? ''));
    $db->prepare("UPDATE yy_tts_voice SET tts_voice_note = ?, tts_voice_revision_dtime = NOW() WHERE tts_voice_key = ?")
       ->execute([$note === '' ? null : $note, $voiceKey]);
    jsonResponse(['ok' => true]);
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
    if (!$ttsKey) errorResponse('tts_key required');

    // Only Azure is implemented today. If the registry ever adds other
    // providers, branch on $cfg['system']['tts_code'].
    $cfg = loadTtsConfig($db, $ttsKey);
    if (!$cfg['system'] || $cfg['system']['tts_code'] !== 'azure') {
        errorResponse('refresh only supports tts_code=azure currently');
    }
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
                $ttsKey, $code, $label,
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
        errorResponse('refresh failed: ' . $e->getMessage());
    }

    // Total count after merge.
    $tot = (int)$db->prepare("SELECT COUNT(*) FROM yy_tts_voice WHERE tts_key = ?")
                  ->execute([$ttsKey]) ? null : null;
    $totStmt = $db->prepare("SELECT COUNT(*) FROM yy_tts_voice WHERE tts_key = ?");
    $totStmt->execute([$ttsKey]);
    jsonResponse([
        'ok'      => true,
        'added'   => $added,
        'updated' => $updated,
        'total'   => (int)$totStmt->fetchColumn(),
    ]);
}

errorResponse('Unknown action: ' . $action);
