<?php
/**
 * Book / chapter listing for the Admin TTS > Books tab.
 *
 *   GET  ?action=volumes
 *     → { volumes: [{ volume_key, series_key, series_number, volume_number,
 *                     volume_label, paragraph_count, chapters_total,
 *                     chapters_with_audio, last_built_dtime }] }
 *
 *   GET  ?action=chapters&volume_key=N&tts_key=N
 *     → { volume: {...}, chapters: [{ chapter_key, chapter_number, paragraph_count,
 *                     audio_status, audio_progress, audio_path, audio_duration_secs,
 *                     audio_size_bytes, audio_completed_dtime, audio_settings }] }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';  // for ttsResolveProfileKey()

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

$action = $_GET['action'] ?? 'volumes';

if ($action === 'volumes') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    $sql = "
        SELECT v.volume_key, v.series_key, v.volume_number, v.volume_label,
               v.volume_paragraph_count_live AS paragraph_count,
               s.series_number,
               (SELECT COUNT(*) FROM yy_chapter c WHERE c.volume_key = v.volume_key) AS chapters_total,
               COALESCE((
                   SELECT COUNT(*) FROM yy_tts_audio a
                    WHERE a.volume_key = v.volume_key
                      AND ($ttsKey = 0 OR a.tts_key = $ttsKey)
                      AND a.tts_audio_status = 'complete'
                      AND a.chapter_key IS NOT NULL
               ), 0) AS chapters_with_audio,
               (SELECT MAX(tts_audio_completed_dtime) FROM yy_tts_audio a
                 WHERE a.volume_key = v.volume_key
                   AND ($ttsKey = 0 OR a.tts_key = $ttsKey)) AS last_built_dtime
          FROM yy_volume v
          JOIN yy_series s ON v.series_key = s.series_key
         WHERE v.volume_active_flag = TRUE
         ORDER BY s.series_number, v.volume_number
    ";
    jsonResponse(['volumes' => $db->query($sql)->fetchAll()]);
}

if ($action === 'chapters') {
    $volumeKey = (int)($_GET['volume_key'] ?? 0);
    $ttsKey    = (int)($_GET['tts_key']    ?? 0);
    // Profile filter — when set, the chapter row's audio fields show the
    // status for THIS profile only. The full multi-profile inventory
    // arrives in the per-chapter `audios` array regardless of this filter.
    $profileKey = (int)($_GET['profile_key'] ?? 0) ?: null;
    if (!$volumeKey || !$ttsKey) errorResponse('volume_key and tts_key required');
    $profileKey = ttsResolveProfileKey($db, $ttsKey, $profileKey);

    $vStmt = $db->prepare("
        SELECT v.volume_key, v.series_key, v.volume_number, v.volume_label,
               v.volume_paragraph_count_live AS paragraph_count,
               v.volume_code, v.volume_flip_code, s.series_number
          FROM yy_volume v JOIN yy_series s ON v.series_key = s.series_key
         WHERE v.volume_key = ?
    ");
    $vStmt->execute([$volumeKey]);
    $volume = $vStmt->fetch();
    if (!$volume) errorResponse('volume not found', 404);

    // paragraph_count is intentionally omitted — fetched separately via
    // ?action=chapter_paragraph_counts. The COUNT(*) subquery per row was
    // costing ~200ms of plan time on top of the join, blocking the initial
    // render. The count is purely a display field; it's fine to lazy-fill.
    // tts_audio_settings is intentionally EXCLUDED. It snapshots the full
    // config (categories, all 3000+ tunes, all pauses) for the build, and
    // ballooned each chapter row to ~250 KB. Multiply by 9 chapters and
    // every 3-second poll was pulling 2.8 MB of unused JSON. The Books-tab
    // UI never reads it; the build modal pulls a fresh snapshot from the
    // /catalog endpoint instead.
    // The primary join is profile-scoped — the chapter row's audio fields
    // describe THIS profile's build. The Build button label and Play
    // button on the row reflect the currently-selected profile.
    $cStmt = $db->prepare("
        SELECT c.chapter_key, c.chapter_number, c.chapter_name AS chapter_label, c.chapter_page,
               a.tts_audio_status, a.tts_audio_progress, a.tts_audio_message,
               a.tts_audio_path, a.tts_audio_duration_secs, a.tts_audio_size_bytes,
               a.tts_audio_completed_dtime, a.tts_audio_started_dtime,
               a.tts_audio_error, a.tts_audio_key, a.tts_audio_failed_paragraphs,
               a.tts_profile_key,
               COALESCE(a.tts_audio_active_flag, TRUE) AS tts_audio_active_flag
          FROM yy_chapter c
          LEFT JOIN yy_tts_audio a
            ON a.chapter_key = c.chapter_key
           AND a.tts_key = ?
           AND a.tts_profile_key = ?
         WHERE c.volume_key = ?
         ORDER BY c.chapter_sort, c.chapter_number
    ");
    $cStmt->execute([$ttsKey, $profileKey, $volumeKey]);
    $chapters = $cStmt->fetchAll();

    // Fetch the per-chapter multi-profile inventory — every (chapter,
    // profile) audio row in the volume — and attach as `audios` to each
    // chapter so the UI can render a profile dropdown when N>1. One
    // query for the whole volume (idx covers it), not N per row.
    $allStmt = $db->prepare("
        SELECT a.chapter_key, a.tts_audio_key, a.tts_profile_key, a.tts_audio_status,
               a.tts_audio_path, a.tts_audio_duration_secs, a.tts_audio_size_bytes,
               COALESCE(a.tts_audio_active_flag, TRUE) AS tts_audio_active_flag,
               p.tts_profile_code, p.tts_profile_label, p.tts_profile_default_flag
          FROM yy_tts_audio a
          JOIN yy_chapter c ON c.chapter_key = a.chapter_key
          LEFT JOIN yy_tts_profile p ON p.tts_profile_key = a.tts_profile_key
         WHERE c.volume_key = ?
           AND a.tts_key = ?
         ORDER BY a.chapter_key, p.tts_profile_default_flag DESC NULLS LAST, p.tts_profile_label NULLS LAST
    ");
    $allStmt->execute([$volumeKey, $ttsKey]);
    $byChapter = [];
    foreach ($allStmt->fetchAll() as $a) {
        $byChapter[(int)$a['chapter_key']][] = $a;
    }
    foreach ($chapters as &$c) {
        $c['audios'] = $byChapter[(int)$c['chapter_key']] ?? [];
    }
    unset($c);

    jsonResponse(['volume' => $volume, 'chapters' => $chapters, 'profile_key' => $profileKey]);
}

// One-shot per-volume paragraph-count rollup. Returns {chapter_key: count}
// for every chapter in the volume in a single GROUP BY scan. The Books-tab
// calls this after rendering so counts populate without blocking initial
// display.
if ($action === 'chapter_paragraph_counts') {
    $volumeKey = (int)($_GET['volume_key'] ?? 0);
    if (!$volumeKey) errorResponse('volume_key required');
    // Per-chapter correlated subquery uses idx_paragraph_chapter (a B-tree
    // index-only scan), which is what the original chapters endpoint did
    // in ~1.7 ms. The earlier shape — `WHERE volume_key = ? GROUP BY
    // chapter_key` — picked idx_paragraph_vol_page instead, did a bitmap
    // heap fetch over 800+ blocks, and ran for 9.6 seconds on vol 7;
    // browsers timed out the fetch and the chapter list never rendered.
    $stmt = $db->prepare("
        SELECT c.chapter_key,
               (SELECT COUNT(*)::int FROM yy_paragraph p WHERE p.chapter_key = c.chapter_key) AS n
          FROM yy_chapter c
         WHERE c.volume_key = ?
    ");
    $stmt->execute([$volumeKey]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[(int)$r['chapter_key']] = (int)$r['n'];
    jsonResponse(['volume_key' => $volumeKey, 'counts' => $out]);
}

errorResponse('Unknown action');
