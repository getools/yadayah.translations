<?php
/**
 * Admin glossary + correction dictionary API.
 *
 * GET ?type=glossary    — list active+inactive glossary terms
 * GET ?type=corrections — list correction entries (sorted by count desc)
 * POST {type:'glossary', action:'add', term, priority} — add term
 * POST {type:'glossary', action:'toggle', key} — toggle active
 * POST {type:'glossary', action:'delete', key}
 * POST {type:'corrections', action:'add', wrong, right, case_sensitive, word_boundary}
 * POST {type:'corrections', action:'edit', key, wrong, right, case_sensitive, word_boundary} — edit an existing rule (promotes to origin='human')
 * POST {type:'corrections', action:'toggle', key}
 * POST {type:'corrections', action:'delete', key}
 * POST {type:'corrections', action:'preview_prompt'} — returns the actual prompt that will be sent to Whisper
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

// Admin views always need the latest data — no caching at any layer.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($method === 'GET') {
    $type = $_GET['type'] ?? '';
    if ($type === 'glossary') {
        $stmt = $db->query("SELECT glossary_key, glossary_term, glossary_active_flag, glossary_priority, glossary_added_dtime FROM yy_transcript_glossary ORDER BY glossary_active_flag DESC, glossary_priority DESC, glossary_term");
        jsonResponse(['items' => $stmt->fetchAll()]);
    }
    if ($type === 'corrections') {
        $stmt = $db->query("SELECT correction_key, correction_wrong, correction_right, correction_count, correction_active_flag, correction_case_sensitive, correction_word_boundary, correction_first_seen_dtime, correction_last_seen_dtime FROM yy_transcript_correction ORDER BY correction_count DESC, correction_last_seen_dtime DESC");
        jsonResponse(['items' => $stmt->fetchAll()]);
    }
    errorResponse('type must be glossary or corrections');
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $type = $data['type'] ?? '';
    $action = $data['action'] ?? '';

    if ($type === 'glossary') {
        if ($action === 'add') {
            $term = trim($data['term'] ?? '');
            $priority = (int)($data['priority'] ?? 0);
            if (!$term) errorResponse('term required');
            $db->prepare("INSERT INTO yy_transcript_glossary (glossary_term, glossary_priority, glossary_added_user_key) VALUES (?, ?, ?) ON CONFLICT (glossary_term) DO UPDATE SET glossary_active_flag = TRUE, glossary_priority = EXCLUDED.glossary_priority")
               ->execute([$term, $priority, $user['user_key']]);
            jsonResponse(['ok' => true]);
        }
        if ($action === 'toggle') {
            $db->prepare("UPDATE yy_transcript_glossary SET glossary_active_flag = NOT glossary_active_flag WHERE glossary_key = ?")
               ->execute([(int)$data['key']]);
            jsonResponse(['ok' => true]);
        }
        if ($action === 'delete') {
            $db->prepare("DELETE FROM yy_transcript_glossary WHERE glossary_key = ?")
               ->execute([(int)$data['key']]);
            jsonResponse(['ok' => true]);
        }
        if ($action === 'preview_prompt') {
            $stmt = $db->query("SELECT glossary_term FROM yy_transcript_glossary WHERE glossary_active_flag = TRUE ORDER BY glossary_priority DESC, glossary_term");
            $terms = array_column($stmt->fetchAll(), 'glossary_term');
            $prompt = '';
            foreach ($terms as $t) {
                $next = $prompt === '' ? $t : "$prompt, $t";
                if (strlen($next) > 860) break;
                $prompt = $next;
            }
            jsonResponse([
                'prompt' => $prompt,
                'char_count' => strlen($prompt),
                'estimated_tokens' => (int)ceil(strlen($prompt) / 4),
                'terms_used' => count(array_filter(explode(',', $prompt))),
                'terms_total' => count($terms),
            ]);
        }
    }

    if ($type === 'corrections') {
        if ($action === 'add') {
            $wrong = trim($data['wrong'] ?? '');
            $right = trim($data['right'] ?? '');
            if (!$wrong || !$right) errorResponse('wrong and right required');
            $db->prepare("
                INSERT INTO yy_transcript_correction (correction_wrong, correction_right, correction_case_sensitive, correction_word_boundary, correction_origin)
                VALUES (?, ?, ?, ?, 'human')
                ON CONFLICT (correction_wrong, correction_right) DO UPDATE
                    SET correction_active_flag = TRUE,
                        correction_origin = 'human',
                        correction_case_sensitive = EXCLUDED.correction_case_sensitive,
                        correction_word_boundary = EXCLUDED.correction_word_boundary
            ")->execute([$wrong, $right, (int)!empty($data['case_sensitive']), (int)!empty($data['word_boundary'])]);
            jsonResponse(['ok' => true]);
        }
        if ($action === 'edit') {
            // Deliberate human edit of an existing rule → wrong/right/flags all
            // re-settable, and the rule is promoted to origin='human' (an edited
            // learned rule has now been vetted by a person). The unique index is
            // (correction_wrong, correction_right); editing into an existing pair
            // would violate it, so report that back instead of 500-ing.
            $key = (int)($data['key'] ?? 0);
            $wrong = trim($data['wrong'] ?? '');
            $right = trim($data['right'] ?? '');
            if (!$key) errorResponse('key required');
            if (!$wrong || !$right) errorResponse('wrong and right required');
            try {
                $stmt = $db->prepare("
                    UPDATE yy_transcript_correction
                       SET correction_wrong = ?,
                           correction_right = ?,
                           correction_case_sensitive = ?,
                           correction_word_boundary = ?,
                           correction_origin = 'human'
                     WHERE correction_key = ?
                ");
                $stmt->execute([$wrong, $right, (int)!empty($data['case_sensitive']), (int)!empty($data['word_boundary']), $key]);
            } catch (\PDOException $e) {
                errorResponse('That wrong→right pair already exists.', 409);
            }
            if ($stmt->rowCount() === 0) errorResponse('Correction not found', 404);
            jsonResponse(['ok' => true]);
        }
        if ($action === 'toggle') {
            $db->prepare("UPDATE yy_transcript_correction SET correction_active_flag = NOT correction_active_flag WHERE correction_key = ?")
               ->execute([(int)$data['key']]);
            jsonResponse(['ok' => true]);
        }
        if ($action === 'delete') {
            $db->prepare("DELETE FROM yy_transcript_correction WHERE correction_key = ?")
               ->execute([(int)$data['key']]);
            jsonResponse(['ok' => true]);
        }
    }

    errorResponse('Unknown type/action');
}

errorResponse('Method not allowed', 405);
