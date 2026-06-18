<?php
/**
 * Bulk search/replace across all transcript rows.
 *
 * POST {action:'preview', find, replace, case_sensitive, word_boundary, item_key?}
 *   → returns total match count + up to 50 sample rows showing old vs new
 *
 * POST {action:'apply',   find, replace, case_sensitive, word_boundary, item_key?}
 *   → runs the replacement, logs each row's diff to yy_transcript_edit_log,
 *     bumps yy_transcript_correction (auto-learn), returns affected row count
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php'; // autoLearnCorrections()
$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']); // for revision triggers
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') errorResponse('POST only', 405);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';
$find = (string)($data['find'] ?? '');
$replace = (string)($data['replace'] ?? '');
$caseSensitive = !empty($data['case_sensitive']);
$wordBoundary  = !empty($data['word_boundary']);
$isRegex       = !empty($data['regex']);
$itemKey = isset($data['item_key']) && $data['item_key'] !== null
    ? (int)$data['item_key'] : null;
// When the client sends a non-null `selected_keys` array, the apply path
// limits to those feed_item_transcript_key rows only (the user unchecked
// some preview boxes). null/missing means "apply to every match" — the
// pre-checkbox behavior. An explicit empty array means "user unchecked
// everything" and is treated as a no-op.
$selectedKeys = null;
if (isset($data['selected_keys']) && is_array($data['selected_keys'])) {
    $selectedKeys = [];
    foreach ($data['selected_keys'] as $k) {
        if (is_numeric($k)) $selectedKeys[] = (int)$k;
    }
}

if ($find === '') errorResponse('find string is required');
if (mb_strlen($find) > 500) errorResponse('find string too long');
if (mb_strlen($replace) > 500) errorResponse('replace string too long');

function posixRegexEscape(string $s): string {
    return preg_replace('/([.\^\$\*\+\?\(\)\[\]\{\}\|\\\\])/', '\\\\$1', $s);
}

$flags = $caseSensitive ? 'g' : 'gi';

if ($isRegex) {
    // User-authored POSIX-extended regex. Pass through verbatim and allow
    // backreferences (\1..\9) in the replacement.
    $pattern = $find;
    $safeReplace = $replace;
    // Validate by compiling against a tiny throwaway string. Postgres raises
    // a user-friendly error message we can surface.
    try {
        $test = $db->prepare("SELECT regexp_replace('test', ?, ?, ?)");
        $test->execute([$pattern, $safeReplace, $flags]);
        $test->fetchColumn();
    } catch (Exception $e) {
        $msg = $e->getMessage();
        // Postgres prefixes errors with SQLSTATE codes — strip noise
        if (preg_match('/ERROR:\s*(.+?)(?:\n|$)/', $msg, $m)) $msg = trim($m[1]);
        errorResponse('Invalid regex: ' . $msg);
    }
} else {
    // Literal find/replace. Escape regex metacharacters in `find` and any
    // backslashes in `replace` so users typing `\1` don't accidentally invoke
    // backreferences.
    $escapedFind = posixRegexEscape($find);
    $pattern = $wordBoundary ? '\m' . $escapedFind . '\M' : $escapedFind;
    $safeReplace = str_replace('\\', '\\\\', $replace);
}

// Two flavors of WHERE: $where for queries against yy_feed_item_transcript
// alone (the apply path uses this — no JOINs, so bare column refs are
// unambiguous). $whereT for queries that also JOIN yy_feed_item /
// yy_feed (the preview path uses this — feed_item_key exists on both
// yy_feed_item_transcript AND yy_feed_item, so the alias prefix is
// required to avoid SQLSTATE 42702 ambiguous-column errors).
$where  = "feed_item_transcript_text ~"   . ($caseSensitive ? '' : '*') . " ?";
$whereT = "t.feed_item_transcript_text ~" . ($caseSensitive ? '' : '*') . " ?";
$params = [$pattern];
if ($itemKey !== null) {
    $where  .= " AND feed_item_key = ?";
    $whereT .= " AND t.feed_item_key = ?";
    $params[] = $itemKey;
}

if ($action === 'preview') {
    // Total match count
    $cnt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript WHERE $where");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    // Order samples so the currently-open transcript's matches come first
    // (so the preview always shows them, even if other items have many more
    // matches and would otherwise grab all 50 sample slots), followed by
    // the rest in feed_item_key order.
    $currentKey = isset($data['current_item_key']) && is_numeric($data['current_item_key'])
        ? (int)$data['current_item_key'] : 0;

    // Sort priority for "other" transcripts (after the current one):
    // Series → Volume → Page. The video-domain analogues are
    //   Series → feed_name (e.g. "YouTube - @YahowahsHerald")
    //   Volume → feed_item_episode (the show's episode number when set)
    //   Page   → feed_item_transcript_segment (sub-row inside the item).
    // feed_item_episode is text; we extract its leading-digits component
    // for numeric ordering and fall back to lexical when no digits exist.
    // Both queries use identical ORDER BY so the group headers match the
    // order of the sample rows that fall under them.
    $sampleStmt = $db->prepare("
        SELECT t.feed_item_transcript_key,
               t.feed_item_transcript_segment::text AS segment,
               t.feed_item_transcript_text AS old_text,
               regexp_replace(t.feed_item_transcript_text, ?, ?, ?) AS new_text,
               t.feed_item_key,
               COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS title
          FROM yy_feed_item_transcript t
          JOIN yy_feed_item fi ON fi.feed_item_key = t.feed_item_key
          LEFT JOIN yy_feed   f  ON f.feed_key       = fi.feed_key
         WHERE $whereT
         ORDER BY (CASE WHEN t.feed_item_key = ? THEN 0 ELSE 1 END),
                  COALESCE(f.feed_name, ''),
                  NULLIF(regexp_replace(COALESCE(fi.feed_item_episode,''), '[^0-9]', '', 'g'), '')::int NULLS LAST,
                  COALESCE(fi.feed_item_episode, ''),
                  t.feed_item_key,
                  t.feed_item_transcript_sort,
                  t.feed_item_transcript_segment
         LIMIT 200
    ");
    $sampleStmt->execute(array_merge([$pattern, $safeReplace, $flags], $params, [$currentKey]));
    $samples = $sampleStmt->fetchAll();

    // Per-transcript hit counts. Same ORDER BY so the group headers
    // appear in the same order as the underlying sample rows.
    $perItemStmt = $db->prepare("
        SELECT t.feed_item_key,
               COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS title,
               count(*) AS hits
          FROM yy_feed_item_transcript t
          JOIN yy_feed_item fi ON fi.feed_item_key = t.feed_item_key
          LEFT JOIN yy_feed   f  ON f.feed_key       = fi.feed_key
         WHERE $whereT
         GROUP BY t.feed_item_key, fi.feed_item_title_override, fi.feed_item_title_import,
                  f.feed_name, fi.feed_item_episode
         ORDER BY (CASE WHEN t.feed_item_key = ? THEN 0 ELSE 1 END),
                  COALESCE(f.feed_name, ''),
                  NULLIF(regexp_replace(COALESCE(fi.feed_item_episode,''), '[^0-9]', '', 'g'), '')::int NULLS LAST,
                  COALESCE(fi.feed_item_episode, ''),
                  t.feed_item_key
    ");
    $perItemStmt->execute(array_merge($params, [$currentKey]));
    $per_item = $perItemStmt->fetchAll();

    jsonResponse([
        'total' => $total,
        'samples' => $samples,
        'per_item' => $per_item,
        'current_item_key' => $currentKey,
        'pattern' => $pattern,
        'flags' => $flags,
    ]);
}

if ($action === 'apply') {
    // Handle the "user unchecked everything" case before opening a transaction.
    if ($selectedKeys !== null && count($selectedKeys) === 0) {
        jsonResponse(['changed' => 0, 'message' => 'No rows selected.']);
    }

    // Decide which apply strategy to use. A pattern is "cross-row capable"
    // when matches could legitimately span row boundaries — any regex, or a
    // literal find string containing whitespace. For those we rewrite the
    // affected items wholesale via applyReplacementsAcrossRows (rows merge
    // when needed). For single-row-only patterns we keep the existing fast
    // per-row UPDATE path (which honors selected_keys for the checkbox UI).
    $crossRowCapable = $isRegex || (preg_match('/\s/u', $find) === 1);

    $db->beginTransaction();
    try {
        // Record the batch up front (both code paths reference $batchKey).
        $batchStmt = $db->prepare("
            INSERT INTO yy_transcript_bulk_replace
                (bulk_replace_find, bulk_replace_replace, bulk_replace_case_sensitive,
                 bulk_replace_word_boundary, bulk_replace_is_regex, bulk_replace_user_key)
            VALUES (?, ?, ?, ?, ?, ?)
            RETURNING bulk_replace_key
        ");
        $batchStmt->execute([$find, $replace, $caseSensitive ? 't' : 'f',
            $wordBoundary ? 't' : 'f', $isRegex ? 't' : 'f', $user['user_key']]);
        $batchKey = (int)$batchStmt->fetchColumn();

        $logStmt = $db->prepare("
            INSERT INTO yy_transcript_edit_log
                (feed_item_key, edit_segment, edit_original_text, edit_new_text,
                 edit_action, edit_user_key, edit_batch_key)
            VALUES (?, ?::interval, ?, ?, 'bulk_replace', ?, ?)
        ");
        $changed = 0;
        // Carried into auto-learn below — set inside whichever branch runs.
        $rowsForAutoLearn = [];

        if (!$crossRowCapable) {
            // ── Per-row path (existing behavior) ──
            $applyWhere  = $where;
            $applyParams = array_merge([$pattern, $safeReplace, $flags], $params);
            if ($selectedKeys !== null) {
                $placeholders = implode(',', array_fill(0, count($selectedKeys), '?'));
                $applyWhere  .= " AND feed_item_transcript_key IN ($placeholders)";
                $applyParams = array_merge($applyParams, $selectedKeys);
            }
            $rowsStmt = $db->prepare("
                SELECT feed_item_transcript_key, feed_item_key, feed_item_transcript_segment::text AS segment,
                       feed_item_transcript_text AS old_text,
                       regexp_replace(feed_item_transcript_text, ?, ?, ?) AS new_text
                  FROM yy_feed_item_transcript
                 WHERE $applyWhere
            ");
            $rowsStmt->execute($applyParams);
            $rows = $rowsStmt->fetchAll();
            if (!$rows) {
                $db->rollBack();
                jsonResponse(['changed' => 0, 'message' => 'No rows matched.']);
            }
            $updStmt = $db->prepare("
                UPDATE yy_feed_item_transcript
                   SET feed_item_transcript_text = ?,
                       feed_item_transcript_revision_dtime = NOW()
                 WHERE feed_item_transcript_key = ?
            ");
            foreach ($rows as $r) {
                if ($r['old_text'] === $r['new_text']) continue;
                $newClipped = mb_substr($r['new_text'], 0, 2000);
                $logStmt->execute([
                    $r['feed_item_key'], $r['segment'], $r['old_text'], $newClipped,
                    $user['user_key'], $batchKey
                ]);
                $updStmt->execute([$newClipped, $r['feed_item_transcript_key']]);
                $changed++;
            }
            $rowsForAutoLearn = $rows;
        } else {
            // ── Cross-row path ──
            // Identify items containing at least one match. selected_keys is
            // ignored here: cross-row matches can collapse rows, so the
            // per-row selection model from the preview UI doesn't apply.
            $affectedStmt = $db->prepare("SELECT DISTINCT feed_item_key FROM yy_feed_item_transcript WHERE $where");
            $affectedStmt->execute($params);
            $itemKeys = array_column($affectedStmt->fetchAll(), 'feed_item_key');
            if (!$itemKeys) {
                $db->rollBack();
                jsonResponse(['changed' => 0, 'message' => 'No rows matched.']);
            }
            $rep = [
                'wrong'          => $find,
                'right'          => $replace,
                'case_sensitive' => (bool)$caseSensitive,
                'word_boundary'  => (bool)$wordBoundary,
                'is_regex'       => (bool)$isRegex,
            ];
            // Targeted upsert by primary key — NOT a whole-transcript rewrite.
            // The old delete-all + insert-all per item re-indexed every row in
            // the GIN trigram/tsv indexes, which under contention made big
            // "apply to all" runs exceed Cloudflare's 100s limit (HTTP 524).
            $insStmt = $db->prepare("
                INSERT INTO yy_feed_item_transcript
                    (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort,
                     feed_item_transcript_revision_user_key)
                VALUES (?, ?::interval, ?, ?, ?)
                ON CONFLICT (feed_item_key, feed_item_transcript_segment, md5(feed_item_transcript_text)) DO NOTHING
            ");
            $updRowStmt = $db->prepare("UPDATE yy_feed_item_transcript
                   SET feed_item_transcript_text = ?, feed_item_transcript_sort = ?,
                       feed_item_transcript_revision_user_key = ?, feed_item_transcript_revision_dtime = NOW()
                 WHERE feed_item_transcript_key = ? AND feed_item_key = ?");
            $delRowStmt = $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_transcript_key = ? AND feed_item_key = ?");
            foreach ($itemKeys as $ik) {
                $allRowsStmt = $db->prepare("
                    SELECT feed_item_transcript_key            AS key,
                           feed_item_transcript_segment::text  AS segment,
                           feed_item_transcript_text           AS text,
                           feed_item_transcript_sort           AS sort
                      FROM yy_feed_item_transcript
                     WHERE feed_item_key = ?
                     ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
                ");
                $allRowsStmt->execute([$ik]);
                $origRows = $allRowsStmt->fetchAll();
                if (!$origRows) continue;
                $origByKey = [];
                foreach ($origRows as $or) $origByKey[(int)$or['key']] = $or['text'];
                // applyReplacementsAcrossRows preserves each unmerged row's key;
                // a MERGED row comes back without a key (→ inserted as new, and
                // the original keys it absorbed fall out of $keptKeys → deleted).
                $newRows = applyReplacementsAcrossRows($origRows, [$rep]);

                $keptKeys = [];
                $seenRows = [];
                $sort = 0;
                foreach ($newRows as $nr) {
                    $rowText  = mb_substr((string)$nr['text'], 0, 2000);
                    $dedupKey = (string)$nr['segment'] . "\x00" . $rowText;
                    if (isset($seenRows[$dedupKey])) continue; // never persist exact dups
                    $seenRows[$dedupKey] = true;
                    $nk = (isset($nr['key']) && $nr['key'] !== null && $nr['key'] !== '') ? (int)$nr['key'] : null;
                    if ($nk !== null && isset($origByKey[$nk])) {
                        $keptKeys[$nk] = true;
                        if ($origByKey[$nk] !== $rowText) {
                            // text changed → UPDATE this row only (minimal GIN churn)
                            $logStmt->execute([$ik, $nr['segment'], $origByKey[$nk], $rowText, $user['user_key'], $batchKey]);
                            $rowsForAutoLearn[] = ['old_text' => $origByKey[$nk], 'new_text' => $rowText];
                            $updRowStmt->execute([$rowText, $sort, $user['user_key'], $nk, $ik]);
                            $changed++;
                        }
                    } else {
                        // merged/new row → INSERT
                        $logStmt->execute([$ik, $nr['segment'], null, $rowText, $user['user_key'], $batchKey]);
                        $insStmt->execute([$ik, $nr['segment'], $rowText, $sort, $user['user_key']]);
                        $changed++;
                    }
                    $sort++;
                }
                // Original rows absorbed by a merge (key no longer present) → DELETE.
                foreach ($origRows as $or) {
                    $ok = (int)$or['key'];
                    if (!isset($keptKeys[$ok])) {
                        $logStmt->execute([$ik, $or['segment'], $or['text'], '', $user['user_key'], $batchKey]);
                        $delRowStmt->execute([$ok, $ik]);
                        $changed++;
                    }
                }
            }
        }

        $db->prepare("UPDATE yy_transcript_bulk_replace SET bulk_replace_count = ? WHERE bulk_replace_key = ?")
           ->execute([$changed, $batchKey]);

        // Auto-learn into yy_transcript_correction. Two paths:
        //   - Literal find/replace: store the (find, replace) pair as-is. One row,
        //     count = number of rows changed.
        //   - Regex/wildcard: store the actual concrete substitutions per row by
        //     diffing old_text vs new_text token-by-token. Many rows possible.
        // Pure case changes are skipped in both paths.
        $autoLearned = false;
        $isCaseOnly = mb_strtolower($find) === mb_strtolower($replace);
        if ($isCaseOnly) {
            // skip — case-only doesn't belong in the dictionary
        } elseif ($isRegex) {
            // Per-row token diff captures the literal matched→replacement pairs.
            // $rowsForAutoLearn is populated by both apply paths above with
            // {old_text, new_text} entries for every changed row (cross-row
            // collapses are skipped here — they don't fit autoLearn's word-
            // aligned model).
            foreach ($rowsForAutoLearn as $r) {
                if (!isset($r['old_text'], $r['new_text']) || $r['old_text'] === $r['new_text']) continue;
                autoLearnCorrections($db, $r['old_text'], $r['new_text']);
            }
            $autoLearned = true;
        } else {
            $db->prepare("
                INSERT INTO yy_transcript_correction
                    (correction_wrong, correction_right, correction_count,
                     correction_active_flag, correction_case_sensitive, correction_word_boundary,
                     correction_first_seen_dtime, correction_last_seen_dtime)
                VALUES (?, ?, ?, TRUE, ?, ?, NOW(), NOW())
                ON CONFLICT (correction_wrong, correction_right) DO UPDATE
                    SET correction_count = yy_transcript_correction.correction_count + EXCLUDED.correction_count,
                        correction_last_seen_dtime = NOW(),
                        correction_active_flag = TRUE
            ")->execute([$find, $replace, $changed, $caseSensitive ? 't' : 'f', $wordBoundary ? 't' : 'f']);
            $autoLearned = true;
        }

        $db->commit();
        jsonResponse([
            'changed' => $changed,
            'logged' => $changed,
            'auto_learned' => $autoLearned,
            'batch_key' => $batchKey,
            'message' => 'Updated ' . $changed . ' row(s)'
                . ($isCaseOnly ? '. (Case-only change not added to dictionary.)'
                   : ($isRegex   ? '; concrete substitutions added to correction dictionary.'
                                 : '; pattern added to correction dictionary.')),
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Apply failed: ' . $e->getMessage());
    }
}

// Undo: revert the most recent non-undone bulk replace (or a specific batch_key)
if ($action === 'undo') {
    $batchKey = isset($data['batch_key']) ? (int)$data['batch_key'] : 0;
    $db->beginTransaction();
    try {
        if ($batchKey) {
            $bStmt = $db->prepare("SELECT * FROM yy_transcript_bulk_replace WHERE bulk_replace_key = ? AND bulk_replace_undone_flag = FALSE FOR UPDATE");
            $bStmt->execute([$batchKey]);
        } else {
            $bStmt = $db->prepare("SELECT * FROM yy_transcript_bulk_replace WHERE bulk_replace_undone_flag = FALSE ORDER BY bulk_replace_dtime DESC LIMIT 1 FOR UPDATE");
            $bStmt->execute();
        }
        $batch = $bStmt->fetch();
        if (!$batch) { $db->rollBack(); jsonResponse(['ok' => false, 'message' => 'Nothing to undo.']); }

        // Revert each row to its pre-batch text, but only if the row's CURRENT
        // text still matches the batch's recorded `edit_new_text`. If a user
        // manually edited that row after the bulk replace, leave it alone.
        $reverted = $db->prepare("
            UPDATE yy_feed_item_transcript t
               SET feed_item_transcript_text = log.edit_original_text,
                   feed_item_transcript_revision_dtime = NOW()
              FROM yy_transcript_edit_log log
             WHERE log.edit_batch_key = ?
               AND t.feed_item_key = log.feed_item_key
               AND t.feed_item_transcript_segment = log.edit_segment
               AND t.feed_item_transcript_text = log.edit_new_text
            RETURNING t.feed_item_transcript_key
        ");
        $reverted->execute([$batch['bulk_replace_key']]);
        $revertedCount = $reverted->rowCount();

        $db->prepare("UPDATE yy_transcript_bulk_replace SET bulk_replace_undone_flag = TRUE, bulk_replace_undone_dtime = NOW(), bulk_replace_undone_user_key = ? WHERE bulk_replace_key = ?")
           ->execute([$user['user_key'], $batch['bulk_replace_key']]);

        $skipped = (int)$batch['bulk_replace_count'] - $revertedCount;
        $db->commit();
        jsonResponse([
            'ok' => true,
            'reverted' => $revertedCount,
            'skipped' => max(0, $skipped),
            'batch' => $batch,
            'message' => 'Reverted ' . $revertedCount . ' row(s)'
                . ($skipped > 0 ? ' (' . $skipped . ' skipped — modified after bulk replace)' : '')
                . '. The pattern "' . substr($batch['bulk_replace_find'], 0, 40) . '" → "'
                . substr($batch['bulk_replace_replace'], 0, 40) . '" can now be redone.',
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Undo failed: ' . $e->getMessage());
    }
}

// Redo: re-apply the most recent undone bulk replace (or a specific one)
if ($action === 'redo') {
    $batchKey = isset($data['batch_key']) ? (int)$data['batch_key'] : 0;
    $db->beginTransaction();
    try {
        if ($batchKey) {
            $bStmt = $db->prepare("SELECT * FROM yy_transcript_bulk_replace WHERE bulk_replace_key = ? AND bulk_replace_undone_flag = TRUE FOR UPDATE");
            $bStmt->execute([$batchKey]);
        } else {
            $bStmt = $db->prepare("SELECT * FROM yy_transcript_bulk_replace WHERE bulk_replace_undone_flag = TRUE ORDER BY bulk_replace_undone_dtime DESC LIMIT 1 FOR UPDATE");
            $bStmt->execute();
        }
        $batch = $bStmt->fetch();
        if (!$batch) { $db->rollBack(); jsonResponse(['ok' => false, 'message' => 'Nothing to redo.']); }

        // Re-apply each row: if its current text matches edit_original_text, set it back to edit_new_text.
        $reapplied = $db->prepare("
            UPDATE yy_feed_item_transcript t
               SET feed_item_transcript_text = log.edit_new_text,
                   feed_item_transcript_revision_dtime = NOW()
              FROM yy_transcript_edit_log log
             WHERE log.edit_batch_key = ?
               AND t.feed_item_key = log.feed_item_key
               AND t.feed_item_transcript_segment = log.edit_segment
               AND t.feed_item_transcript_text = log.edit_original_text
            RETURNING t.feed_item_transcript_key
        ");
        $reapplied->execute([$batch['bulk_replace_key']]);
        $reappliedCount = $reapplied->rowCount();

        $db->prepare("UPDATE yy_transcript_bulk_replace SET bulk_replace_undone_flag = FALSE, bulk_replace_undone_dtime = NULL, bulk_replace_undone_user_key = NULL WHERE bulk_replace_key = ?")
           ->execute([$batch['bulk_replace_key']]);

        $skipped = (int)$batch['bulk_replace_count'] - $reappliedCount;
        $db->commit();
        jsonResponse([
            'ok' => true,
            'reapplied' => $reappliedCount,
            'skipped' => max(0, $skipped),
            'batch' => $batch,
            'message' => 'Re-applied to ' . $reappliedCount . ' row(s)'
                . ($skipped > 0 ? ' (' . $skipped . ' skipped — diverged from original state)' : '') . '.',
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Redo failed: ' . $e->getMessage());
    }
}

// History: returns recent bulk-replace operations for the modal's status pills
if ($action === 'history') {
    $stmt = $db->query("
        SELECT b.bulk_replace_key AS batch_key,
               b.bulk_replace_find AS find,
               b.bulk_replace_replace AS replace,
               b.bulk_replace_count AS count,
               b.bulk_replace_undone_flag AS undone,
               b.bulk_replace_dtime AS applied_dtime,
               b.bulk_replace_undone_dtime AS undone_dtime,
               u1.user_code AS applied_by,
               u2.user_code AS undone_by
          FROM yy_transcript_bulk_replace b
          LEFT JOIN yy_user u1 ON u1.user_key = b.bulk_replace_user_key
          LEFT JOIN yy_user u2 ON u2.user_key = b.bulk_replace_undone_user_key
         ORDER BY b.bulk_replace_dtime DESC
         LIMIT 20
    ");
    $items = $stmt->fetchAll();
    // Identify the next-undo and next-redo targets
    $nextUndo = null; $nextRedo = null;
    foreach ($items as $i) {
        if (!$i['undone'] && !$nextUndo) $nextUndo = $i;
        if ($i['undone'] && !$nextRedo) $nextRedo = $i;
    }
    jsonResponse(['items' => $items, 'next_undo' => $nextUndo, 'next_redo' => $nextRedo]);
}

errorResponse('Unknown action');
