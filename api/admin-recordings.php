<?php
/**
 * Recordings queue endpoint with per-column filtering.
 *
 * GET ?page&per_page&sort&dir
 *     &key=          — substring match on feed_item_key (text)
 *     &title=        — substring match on title
 *     &pub_from=     — published date >= YYYY-MM-DD
 *     &pub_to=       — published date <= YYYY-MM-DD
 *     &feed_key=N    — exact feed match
 *     &dur_min_s=N   — duration_seconds >= N
 *     &dur_max_s=N   — duration_seconds <= N
 *     &status=...    — comma-separated subset of: complete, partial, new
 *     &item_status=  — comma-separated subset of: active, restricted, inactive
 *     &page_keys=    — comma-separated yy_page.page_key list
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(99999, (int)($_GET['per_page'] ?? 50)));
$offset  = ($page - 1) * $perPage;

$keyQ        = trim((string)($_GET['key'] ?? ''));
$titleQ      = trim((string)($_GET['title'] ?? ''));
$pubFrom     = trim((string)($_GET['pub_from'] ?? ''));
$pubTo       = trim((string)($_GET['pub_to'] ?? ''));
$feedKey     = (int)($_GET['feed_key'] ?? 0);
$durMin      = isset($_GET['dur_min_s']) && $_GET['dur_min_s'] !== '' ? (int)$_GET['dur_min_s'] : null;
$durMax      = isset($_GET['dur_max_s']) && $_GET['dur_max_s'] !== '' ? (int)$_GET['dur_max_s'] : null;
$statusRaw   = trim((string)($_GET['status'] ?? ''));
$statuses    = $statusRaw === '' ? [] : array_filter(array_map('trim', explode(',', $statusRaw)));
// page_keys filter: comma-separated list of yy_page.page_key. Items shown
// must be associated with at least one of these pages (via yy_feed_item_page).
$pageKeysRaw = trim((string)($_GET['page_keys'] ?? ''));
$pageKeys    = $pageKeysRaw === '' ? [] :
    array_values(array_filter(array_map('intval',
        array_map('trim', explode(',', $pageKeysRaw))), function($n){ return $n > 0; }));
// item_status filter: comma-separated subset of: active, restricted, inactive
//   active     = active_flag = TRUE  AND restricted_flag IS NOT TRUE
//   restricted = restricted_flag = TRUE
//   inactive   = active_flag = FALSE OR restricted_flag = TRUE
$itemStatusRaw = trim((string)($_GET['item_status'] ?? ''));
$itemStatuses  = $itemStatusRaw === '' ? [] : array_filter(array_map('trim', explode(',', $itemStatusRaw)));

// category_keys filter: comma-separated yy_feed_page_category.category_key.
// Items shown must be tagged with at least one of these categories.
$categoryKeysRaw = trim((string)($_GET['category_keys'] ?? ''));
$categoryKeys    = $categoryKeysRaw === '' ? [] :
    array_values(array_filter(array_map('intval',
        array_map('trim', explode(',', $categoryKeysRaw))), function($n){ return $n > 0; }));

// caption_status filter: comma-separated subset of: completed, queued, error, never.
//   completed = feed_item_yt_caption_status = 'success'
//   queued    = feed_item_yt_caption_status IN ('queued', NULL-but-has-transcript-on-connected-feed)
//   error     = feed_item_yt_caption_status = 'error'
//   never     = feed_item_yt_caption_status IS NULL OR no transcript / not connected
$captionStatusRaw = trim((string)($_GET['caption_status'] ?? ''));
$captionStatuses  = $captionStatusRaw === '' ? [] : array_filter(array_map('trim', explode(',', $captionStatusRaw)));

// mp3 filter: comma-separated subset of: yes, no.
//   yes = feed_item_audio_file IS NOT NULL
//   no  = feed_item_audio_file IS NULL
$mp3Raw = trim((string)($_GET['mp3'] ?? ''));
$mp3Filter = $mp3Raw === '' ? [] : array_filter(array_map('trim', explode(',', $mp3Raw)));

// episode filter: free-text ILIKE match against feed_item_episode.
$episodeQ = trim((string)($_GET['episode'] ?? ''));

$sortMap = [
    'title'      => 'COALESCE(fi.feed_item_title_override, fi.feed_item_title_import)',
    'published'  => 'COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime)',
    'duration'   => 'fi.feed_item_duration_seconds',
    'feed'       => 'f.feed_name',
    'key'        => 'fi.feed_item_key',
    'processed'  => "CASE
                       WHEN fi.feed_item_audio_file IS NOT NULL THEN 2
                       WHEN fi.feed_item_audio_resume_seconds > 0 THEN 1
                       ELSE 0 END",
];
$sort = $_GET['sort'] ?? 'published';
$sortCol = $sortMap[$sort] ?? $sortMap['published'];
$dir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$where = "fi.feed_item_type = 'video'
       AND lower(f.feed_site_code) = 'youtube'
       AND fi.feed_item_external_id IS NOT NULL
       AND fi.feed_item_external_id != ''";
$params = [];

// Apply item_status filter. Default (no checkboxes checked) = active-only,
// matching prior behavior where the recordings list was implicitly active=TRUE.
if (empty($itemStatuses)) {
    $where .= " AND fi.feed_item_active_flag = TRUE AND COALESCE(fi.feed_item_restricted_flag, FALSE) = FALSE";
} else {
    $isOrs = [];
    foreach ($itemStatuses as $s) {
        if ($s === 'active')     $isOrs[] = '(fi.feed_item_active_flag = TRUE AND COALESCE(fi.feed_item_restricted_flag, FALSE) = FALSE)';
        elseif ($s === 'restricted') $isOrs[] = 'fi.feed_item_restricted_flag = TRUE';
        elseif ($s === 'inactive')   $isOrs[] = '(fi.feed_item_active_flag = FALSE OR fi.feed_item_restricted_flag = TRUE)';
    }
    if ($isOrs) $where .= " AND (" . implode(' OR ', $isOrs) . ")";
}

if ($keyQ !== '')   { $where .= " AND CAST(fi.feed_item_key AS TEXT) LIKE ?"; $params[] = $keyQ . '%'; }
if ($titleQ !== '') { $where .= " AND COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) ILIKE ?"; $params[] = '%' . $titleQ . '%'; }
if ($pubFrom !== '') { $where .= " AND COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) >= ?"; $params[] = $pubFrom; }
if ($pubTo !== '')   { $where .= " AND COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) < (?::date + INTERVAL '1 day')"; $params[] = $pubTo; }
if ($feedKey > 0)    { $where .= " AND fi.feed_key = ?"; $params[] = $feedKey; }
if ($durMin !== null && $durMin >= 0) { $where .= " AND fi.feed_item_duration_seconds >= ?"; $params[] = $durMin; }
if ($durMax !== null && $durMax > 0)  { $where .= " AND fi.feed_item_duration_seconds <= ?"; $params[] = $durMax; }
if ($episodeQ !== '')                 { $where .= " AND fi.feed_item_episode ILIKE ?"; $params[] = '%' . $episodeQ . '%'; }
if (!empty($pageKeys)) {
    $placeholders = implode(',', array_fill(0, count($pageKeys), '?'));
    $where .= " AND EXISTS (SELECT 1 FROM yy_feed_item_page fip
                              WHERE fip.feed_item_key = fi.feed_item_key
                                AND fip.page_key IN ($placeholders))";
    foreach ($pageKeys as $pk) $params[] = $pk;
}
if (!empty($categoryKeys)) {
    $placeholders = implode(',', array_fill(0, count($categoryKeys), '?'));
    $where .= " AND EXISTS (SELECT 1 FROM yy_feed_item_category fic
                              WHERE fic.feed_item_key = fi.feed_item_key
                                AND fic.category_key IN ($placeholders))";
    foreach ($categoryKeys as $ck) $params[] = $ck;
}
if (!empty($mp3Filter)) {
    // yes/no are mutually exclusive — checking both = no filter applied.
    $hasYes = in_array('yes', $mp3Filter, true);
    $hasNo  = in_array('no',  $mp3Filter, true);
    if ($hasYes && !$hasNo)      $where .= " AND fi.feed_item_audio_file IS NOT NULL";
    else if ($hasNo && !$hasYes) $where .= " AND fi.feed_item_audio_file IS NULL";
}
if (!empty($captionStatuses)) {
    // Transcript-state cascade — every row falls into exactly one of:
    //   captions — yt_caption_uploaded_dtime IS NOT NULL AND no later
    //              Approved review (the upload is current).
    //   reviewed — latest validation = 'Approved' AND (no upload yet
    //              OR latest review is newer than the upload — i.e.
    //              upload is stale w.r.t. the current review).
    //   pending  — has transcript records, no Approved review, no
    //              upload date yet.
    //   missing  — no transcript records at all.
    //   editable — has a live editable transcript (superset of captions/
    //              reviewed/pending; OR-combined with the others).
    $hasTx        = "EXISTS(SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key)";
    $latestStatus = "(SELECT v.validation_status FROM yy_feed_item_transcript_validation v
                       WHERE v.feed_item_key = fi.feed_item_key
                       ORDER BY v.validation_dtime DESC LIMIT 1)";
    $latestDtime  = "(SELECT v.validation_dtime FROM yy_feed_item_transcript_validation v
                       WHERE v.feed_item_key = fi.feed_item_key
                       ORDER BY v.validation_dtime DESC LIMIT 1)";
    $capOrs = [];
    foreach ($captionStatuses as $cs) {
        if ($cs === 'captions') {
            $capOrs[] = "(fi.feed_item_yt_caption_uploaded_dtime IS NOT NULL
                          AND NOT ($latestStatus = 'Approved' AND $latestDtime > fi.feed_item_yt_caption_uploaded_dtime))";
        } elseif ($cs === 'reviewed') {
            $capOrs[] = "($hasTx AND $latestStatus = 'Approved'
                          AND (fi.feed_item_yt_caption_uploaded_dtime IS NULL
                            OR $latestDtime > fi.feed_item_yt_caption_uploaded_dtime))";
        } elseif ($cs === 'pending') {
            $capOrs[] = "($hasTx AND ($latestStatus IS DISTINCT FROM 'Approved')
                          AND fi.feed_item_yt_caption_uploaded_dtime IS NULL)";
        } elseif ($cs === 'editable') {
            // Has an editable (live) transcript, regardless of review/upload state.
            $capOrs[] = $hasTx;
        } elseif ($cs === 'missing') {
            $capOrs[] = "NOT $hasTx";
        }
    }
    if ($capOrs) $where .= " AND (" . implode(' OR ', $capOrs) . ")";
}

// Status filter: multi-checkbox of Complete / Partial / New
//   complete = feed_item_audio_file IS NOT NULL (or any cluster sibling has audio)
//   partial  = audio_file IS NULL AND resume_seconds > 0
//   new      = audio_file IS NULL AND (resume_seconds IS NULL OR resume_seconds = 0)
$clusterHasAudioSql = "
    EXISTS (
        SELECT 1 FROM yy_feed_item sib
         WHERE sib.feed_item_audio_file IS NOT NULL
           AND sib.feed_item_key IN (
                SELECT feed_item_key_b FROM yy_feed_item_link
                  WHERE feed_item_key_a = fi.feed_item_key
                    AND feed_item_link_confirmed_flag IS DISTINCT FROM FALSE
                UNION ALL
                SELECT feed_item_key_a FROM yy_feed_item_link
                  WHERE feed_item_key_b = fi.feed_item_key
                    AND feed_item_link_confirmed_flag IS DISTINCT FROM FALSE
           )
    )";
if (!empty($statuses)) {
    $statusOrs = [];
    foreach ($statuses as $s) {
        if ($s === 'complete') {
            $statusOrs[] = "(fi.feed_item_audio_file IS NOT NULL OR $clusterHasAudioSql)";
        } elseif ($s === 'partial') {
            $statusOrs[] = "(fi.feed_item_audio_file IS NULL AND COALESCE(fi.feed_item_audio_resume_seconds, 0) > 0)";
        } elseif ($s === 'new') {
            $statusOrs[] = "(fi.feed_item_audio_file IS NULL AND COALESCE(fi.feed_item_audio_resume_seconds, 0) = 0 AND NOT $clusterHasAudioSql)";
        }
    }
    if ($statusOrs) $where .= " AND (" . implode(' OR ', $statusOrs) . ")";
}

$cntStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item fi JOIN yy_feed f ON f.feed_key = fi.feed_key WHERE $where");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();

$orderBy = "$sortCol $dir NULLS LAST, fi.feed_item_key DESC";

$stmt = $db->prepare("
    SELECT fi.feed_item_key,
           fi.feed_item_external_id,
           fi.feed_item_url,
           COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS title,
           fi.feed_item_thumbnail,
           fi.feed_item_duration,
           fi.feed_item_duration_seconds,
           COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) AS published_dtime,
           fi.feed_item_audio_file,
           fi.feed_item_episode,
           fi.feed_item_audio_resume_seconds AS resume_seconds,
           fi.feed_item_active_flag,
           fi.feed_item_restricted_flag,
           CASE
             WHEN fi.feed_item_restricted_flag = TRUE THEN 'restricted'
             WHEN fi.feed_item_active_flag = FALSE   THEN 'inactive'
             ELSE 'active'
           END AS item_status,
           f.feed_site_code,
           f.feed_key,
           f.feed_name,
           (f.feed_yt_caption_refresh_token IS NOT NULL) AS feed_yt_connected,
           fi.feed_item_yt_caption_status         AS yt_caption_status,
           fi.feed_item_yt_caption_uploaded_dtime AS yt_caption_uploaded_dtime,
           fi.feed_item_yt_caption_message        AS yt_caption_message,
           (SELECT COUNT(*) FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key) AS yt_caption_segment_count,
           fi.feed_item_yt_caption_segments_at_upload AS yt_caption_segments_at_upload,
           -- Categories list — same shape returned by the Items tab so the
           -- same render snippet works on both sides without divergence.
           (SELECT json_agg(json_build_object('category_key', cc.category_key, 'category_title', cc.category_title, 'category_slug', cc.category_slug, 'episode', fic.feed_item_category_episode, 'page_title', pp.page_title) ORDER BY pp.page_title, cc.category_sort, cc.category_title)
            FROM yy_feed_item_category fic
            JOIN yy_feed_page_category cc ON fic.category_key = cc.category_key
            JOIN yy_page pp ON cc.page_key = pp.page_key
            WHERE fic.feed_item_key = fi.feed_item_key) AS categories_list,
           -- Transcribe-column inputs (mirrored from admin-feeds.php Items
           -- listing so the same transcribeBtnHtml() helper can render in
           -- both tabs without divergence).
           EXISTS (SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key) AS has_transcript,
           (SELECT v.validation_status FROM yy_feed_item_transcript_validation v
              WHERE v.feed_item_key = fi.feed_item_key
              ORDER BY v.validation_dtime DESC LIMIT 1) AS transcript_validation_status,
           (SELECT v.validation_dtime FROM yy_feed_item_transcript_validation v
              WHERE v.feed_item_key = fi.feed_item_key
              ORDER BY v.validation_dtime DESC LIMIT 1) AS transcript_validation_dtime,
           (SELECT v.validation_bookmark_seconds FROM yy_feed_item_transcript_validation v
              WHERE v.feed_item_key = fi.feed_item_key
              ORDER BY v.validation_dtime DESC LIMIT 1) AS transcript_bookmark_seconds,
           CASE
             WHEN fi.feed_item_audio_file IS NOT NULL THEN 'complete'
             WHEN COALESCE(fi.feed_item_audio_resume_seconds, 0) > 0 THEN 'partial'
             WHEN $clusterHasAudioSql THEN 'complete'
             ELSE 'new'
           END AS processed_status
      FROM yy_feed_item fi
      JOIN yy_feed f ON f.feed_key = fi.feed_key
     WHERE $where
     ORDER BY $orderBy
     LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$items = $stmt->fetchAll();

// Attach the list of pages each item is associated with (page_key, page_code,
// page_title) so the UI can render small badges in the new "Pages" column.
if ($items) {
    $itemKeys = array_map(fn($r) => (int)$r['feed_item_key'], $items);
    $ph = implode(',', array_fill(0, count($itemKeys), '?'));
    $pgStmt = $db->prepare("
        SELECT fip.feed_item_key, p.page_key, p.page_code, p.page_title
          FROM yy_feed_item_page fip
          JOIN yy_page p ON p.page_key = fip.page_key
         WHERE fip.feed_item_key IN ($ph)
         ORDER BY p.page_header_sort, p.page_key");
    $pgStmt->execute($itemKeys);
    $pagesByItem = [];
    foreach ($pgStmt->fetchAll() as $r) {
        $pagesByItem[(int)$r['feed_item_key']][] = [
            'page_key'   => (int)$r['page_key'],
            'page_code'  => $r['page_code'],
            'page_title' => $r['page_title'],
        ];
    }
    foreach ($items as &$it) {
        $it['pages'] = $pagesByItem[(int)$it['feed_item_key']] ?? [];
    }
    unset($it);

    // Mark items currently being processed by a finalize / transcribe
    // pipeline so the Recordings tab UI can disable selection and prevent
    // two operators from kicking off conflicting work on the same video.
    // Two signals:
    //   - finalize state file at /tmp/finalize_<key>.state with status
    //     other than complete/error (validating/encoding/finalizing)
    //   - any pending/running row in yy_feed_item_transcript_job
    $jobStmt = $db->prepare("
        SELECT feed_item_key, STRING_AGG(job_status, ',') AS statuses
        FROM yy_feed_item_transcript_job
        WHERE feed_item_key IN ($ph)
          AND job_status IN ('pending', 'running')
        GROUP BY feed_item_key
    ");
    $jobStmt->execute($itemKeys);
    $activeJobByItem = [];
    foreach ($jobStmt->fetchAll() as $r) {
        $activeJobByItem[(int)$r['feed_item_key']] = $r['statuses'];
    }

    foreach ($items as &$it) {
        $key = (int)$it['feed_item_key'];
        $reasons = [];

        $stateFile = sys_get_temp_dir() . "/finalize_$key.state";
        if (is_file($stateFile)) {
            $st = json_decode((string)@file_get_contents($stateFile), true) ?: [];
            $finStatus = $st['status'] ?? '';
            if ($finStatus !== '' && !in_array($finStatus, ['complete', 'error', 'idle'], true)) {
                $reasons[] = "finalize:$finStatus";
            }
        }
        if (!empty($activeJobByItem[$key])) {
            $reasons[] = 'transcribe:' . $activeJobByItem[$key];
        }
        // Recording-popout heartbeat: covers the whole batch the moment an
        // operator clicks "Process Selected". The popout pings
        // /api/admin-recording-heartbeat.php every ~5s with the FULL item
        // list. The file's mtime is our freshness check; the file's body
        // is JSON with the locking user_code so the UI can surface
        // "Locked by <name>" instead of just "Locked".
        $hbFile = sys_get_temp_dir() . "/recording_active_$key";
        if (is_file($hbFile)) {
            clearstatcache(true, $hbFile);
            $age = time() - (int)@filemtime($hbFile);
            if ($age <= 15) {
                $body = @file_get_contents($hbFile);
                $j = $body ? json_decode($body, true) : null;
                $by      = (is_array($j) && !empty($j['user_code']))     ? $j['user_code']         : 'unknown';
                $nowFlag = (is_array($j) && !empty($j['recording_now'])) ? 'now' : 'queued';
                $reasons[] = "recording_active:by:$by:{$age}s_ago:$nowFlag";
            } else {
                // Stale — clean up so it doesn't sit on disk forever.
                @unlink($hbFile);
            }
        }

        // (Parts-mtime fallback removed 2026-05-04. It was added for a
        // window when popouts lacked the heartbeat code, but it caused a
        // 6-minute delay between popout-close and the row clearing —
        // operators expected the lock to release immediately on close.
        // The heartbeat is now reliable for every popout opened post-
        // deploy, so the fallback is no longer needed and was harmful.)

        $it['in_progress']        = !empty($reasons);
        $it['in_progress_reason'] = implode(', ', $reasons);
    }
    unset($it);
}

// Pages that actually have at least one feed_item associated with them —
// used to render the checkbox filter under the Pages column header. Filtering
// the active-pages list by EXISTS in yy_feed_item_page avoids cluttering
// the UI with pages that would never narrow the result set.
$allPagesStmt = $db->query("
    SELECT p.page_key, p.page_code, p.page_title
      FROM yy_page p
     WHERE p.page_active_flag IS DISTINCT FROM FALSE
       AND EXISTS (SELECT 1 FROM yy_feed_item_page fip WHERE fip.page_key = p.page_key)
     ORDER BY p.page_header_sort, p.page_key");
$allPages = $allPagesStmt->fetchAll();

// Categories that have at least one feed_item tagged. Only categories
// belonging to a YouTube-bearing video appear in the recordings list, so
// EXISTS keeps the dropdown narrow.
$allCategoriesStmt = $db->query("
    SELECT c.category_key, c.category_title, c.page_key, p.page_title
      FROM yy_feed_page_category c
      JOIN yy_page p ON p.page_key = c.page_key
     WHERE EXISTS (SELECT 1 FROM yy_feed_item_category fic WHERE fic.category_key = c.category_key)
     ORDER BY p.page_title, c.category_sort, c.category_title");
$allCategories = $allCategoriesStmt->fetchAll();

$feedsStmt = $db->query("
    SELECT DISTINCT f.feed_key, f.feed_name
      FROM yy_feed f
      JOIN yy_feed_item fi ON fi.feed_key = f.feed_key
     WHERE fi.feed_item_active_flag = TRUE AND fi.feed_item_type = 'video'
     ORDER BY f.feed_name
");

jsonResponse([
    'items' => $items,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => max(1, (int)ceil($total / max(1, $perPage))),
    'sort' => $sort, 'dir' => strtolower($dir),
    'feeds' => $feedsStmt->fetchAll(),
    'pages' => $allPages,
    'categories' => $allCategories,
    'filters' => [
        'key' => $keyQ, 'title' => $titleQ,
        'pub_from' => $pubFrom, 'pub_to' => $pubTo,
        'feed_key' => $feedKey,
        'dur_min_s' => $durMin, 'dur_max_s' => $durMax,
        'status' => $statuses,
        'item_status' => $itemStatuses,
        'page_keys' => $pageKeys,
        'category_keys' => $categoryKeys,
        'mp3' => $mp3Filter,
        'caption_status' => $captionStatuses,
        'episode' => $episodeQ,
    ],
]);
