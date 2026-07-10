<?php
/**
 * Shared helpers for transcript editing flows. Used by:
 *   - admin-transcript.php       (manual per-row save → autoLearn)
 *   - admin-transcript-replace.php (bulk find/replace → autoLearn in regex mode)
 *   - and the feed_item link helpers below
 */

/**
 * Single-worker queue dispatcher for editable (consensus) transcript builds
 * (yy_feed_item_transcript_init_job). If no init worker is currently running,
 * spawn one on the OLDEST pending job — job_key ASC = creation order, which the
 * client creates in page order, so the editable transcripts build strictly
 * item-by-item, one at a time, and the queue survives the operator closing the
 * popover. Idempotent: safe to call from every enqueue point and from the
 * worker on exit. A double-spawn is harmless — the worker claims its row
 * atomically (pending→running) and a loser exits. Best-effort throughout.
 *
 * Also reaps a wedged job: a worker killed (OOM/SIGKILL) mid-run leaves its row
 * 'running' forever, which would stall the single-worker gate. Any 'running'
 * row whose job_started is older than 2h (well past the 1h wait_for deadline +
 * build time) is failed so the queue can advance.
 */
function spawnNextInitWorker(PDO $db): void {
    try {
        $db->exec("UPDATE yy_feed_item_transcript_init_job
                      SET job_status='error', job_error='reaped — worker died (running > 2h)', job_completed=now()
                    WHERE job_status='running'
                      AND COALESCE(job_started, job_created) < now() - interval '2 hours'");
        // Count only THIS-model running workers (job_started is set on claim).
        // Pre-migration / old-model jobs left 'running' with a NULL job_started
        // are NOT counted, so they can't wedge the gate — their own detached
        // workers (if any) finish independently; the 2h reaper clears the rest.
        $running = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_init_job WHERE job_status='running' AND job_started IS NOT NULL")->fetchColumn();
        if ($running > 0) return;   // one at a time
        $next = (int)$db->query("SELECT job_key FROM yy_feed_item_transcript_init_job WHERE job_status='pending' ORDER BY job_priority DESC, job_key LIMIT 1")->fetchColumn();
        if (!$next) return;
        // Use the same reliable detach the STT queue uses (nohup + </dev/null);
        // a bare `setsid … &` child does NOT survive when the spawning process
        // is itself a short-lived CLI worker, so the chain would stop after one.
        require_once __DIR__ . '/spawn-helpers.php';
        spawnCappedWorker(__DIR__ . '/admin-transcript-init-worker.php', [$next], '/tmp/transcript-init-' . $next . '.log');
    } catch (\Throwable $e) { /* best-effort dispatch */ }
}

/**
 * Canonical self-hosted baseline suite for the multi-engine amalgamation. A new
 * feed item generates ALL of these so maybeAutoBuildEditable() can build a full
 * consensus: three word-level spines (whisperx/whisper/parakeet) plus segment
 * refs incl. speaker diarisation. All free/self-hosted (Puget GPU) — no paid API.
 * Tune the new-item default in one place here.
 *
 * ⚠ gpu-canary-1b-flash and gpu-qwen2-audio were REMOVED (2026-07-06): they
 * hallucinate common words ("Yah" for "the", "Not" for "no", "there'll" for
 * pauses) and were poisoning the consensus. They are also excluded from the
 * vote/reconcile at use-time (cfDeniedEngines), so generating them was pure
 * wasted GPU. See reference_transcript_engine_reliability_and_denylist.
 */
const TRANSCRIPT_BASELINE_SUITE = [
    'gpu-whisperx-word', 'gpu-whisper-large-v3-word', 'gpu-parakeet-tdt-0.6b-v2-word',
    'gpu-whisperx', 'gpu-parakeet-tdt-0.6b-v2', 'gpu-whisperx-diarize',
];

/**
 * Enqueue the full baseline suite for a new feed item and opt it in for the
 * consensus editable build, so every new item ends up with an AMALGAMATED
 * transcript (L1-L4) rather than a single-engine one. Idempotent: supersedes
 * any same-model job still in flight. Returns the FIRST queued job key (for the
 * caller to spawn/kick the single STT worker, which then chains through the
 * rest); 0 if nothing was queued. The consensus itself auto-fires from
 * maybeAutoBuildEditable() in transcript-worker.php once the last baseline lands.
 */
function autoEnqueueBaselineSuite(PDO $db, int $itemKey, ?int $userKey = null, int $priority = 1): int {
    // Opt in for the auto consensus build (default row is optin=false).
    $db->prepare("INSERT INTO yy_feed_item_editable_optin (feed_item_key, optin, dtime)
                  VALUES (?, TRUE, now())
                  ON CONFLICT (feed_item_key) DO UPDATE SET optin = TRUE, dtime = now()")
       ->execute([$itemKey]);
    $supersede = $db->prepare("UPDATE yy_feed_item_transcript_job
                                  SET job_status='cancelled', job_completed_dtime=NOW(),
                                      job_message='superseded by newer run of same model'
                                WHERE feed_item_key=? AND job_model=? AND job_status IN ('pending','running')");
    $ins = $db->prepare("INSERT INTO yy_feed_item_transcript_job
                             (feed_item_key, job_status, job_message, user_key, job_model, job_priority)
                         VALUES (?, 'pending', ?, ?, ?, ?)
                         RETURNING feed_item_transcript_job_key");
    $firstKey = 0;
    foreach (TRANSCRIPT_BASELINE_SUITE as $model) {
        $supersede->execute([$itemKey, $model]);
        $ins->execute([$itemKey, 'Auto baseline suite (new-item amalgamation)', $userKey, $model, $priority]);
        $k = (int)$ins->fetchColumn();
        if (!$firstKey) $firstKey = $k;
    }
    return $firstKey;
}

/**
 * Return all feed_item_keys linked to $itemKey via yy_feed_item_link, EXCLUDING
 * $itemKey itself. By default includes pending links (confirmed_flag IS NULL)
 * AND confirmed links; explicitly-denied links (FALSE) are always excluded.
 *
 * Pass $confirmedOnly=true for callers that want strict membership only.
 *
 * Always returns an array of ints (possibly empty). Stable order (ascending).
 */
function getLinkedFeedItemKeys(PDO $db, int $itemKey, bool $confirmedOnly = false): array {
    $confirmFilter = $confirmedOnly
        ? "AND feed_item_link_confirmed_flag = TRUE"
        : "AND feed_item_link_confirmed_flag IS DISTINCT FROM FALSE";
    $stmt = $db->prepare("
        SELECT feed_item_key_b AS k FROM yy_feed_item_link
         WHERE feed_item_key_a = ? $confirmFilter
        UNION
        SELECT feed_item_key_a AS k FROM yy_feed_item_link
         WHERE feed_item_key_b = ? $confirmFilter
        ORDER BY k
    ");
    $stmt->execute([$itemKey, $itemKey]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[] = (int)$r['k'];
    return $out;
}

/**
 * Return [$itemKey, ...linked] for use directly in a `feed_item_key IN (...)` clause.
 * Convenience wrapper around getLinkedFeedItemKeys() that includes the source key.
 */
function getFeedItemKeyCluster(PDO $db, int $itemKey, bool $confirmedOnly = false): array {
    $linked = getLinkedFeedItemKeys($db, $itemKey, $confirmedOnly);
    array_unshift($linked, $itemKey);
    return array_values(array_unique($linked));
}

/**
 * Detect single-token substitutions between $oldText and $newText and
 * insert/bump rows in yy_transcript_correction so future fresh transcripts
 * benefit from the same fix.
 *
 * Skips:
 *  - structural changes (token-count mismatch — likely sentence rewrites, not corrections)
 *  - punctuation-only changes
 *  - very short (<2 chars) or very long (>60 chars) tokens
 *  - pure case changes (e.g. "yah" → "Yah")
 *  - pairs whose wrong side is a common English function word (never a real
 *    correction; a same-length reword can pair one with an unrelated word)
 *
 * New pairs are learned INACTIVE and only activate on a second independent
 * sighting (count >= 2), so a single reword can never poison the live dict.
 */
function autoLearnCorrections(PDO $db, string $oldText, string $newText): void {
    if ($oldText === $newText) return;

    $oldWords = preg_split('/(\s+)/u', $oldText, -1, PREG_SPLIT_DELIM_CAPTURE);
    $newWords = preg_split('/(\s+)/u', $newText, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (count($oldWords) !== count($newWords)) return; // structural change, skip

    // Common English function words never legitimately sit on the WRONG side of
    // a real correction (which is a mishearing → proper noun / Hebrew term).
    // A positional word-diff of a same-length reword can coincidentally pair one
    // of these with an unrelated word (e.g. "were" ↔ "ultimately", "No" ↔ "Not")
    // and — once active — that pair globally rewrites a common word everywhere.
    // Refuse to learn any pair whose wrong side is one of them.
    static $stop = [
        'a'=>1,'an'=>1,'and'=>1,'are'=>1,'as'=>1,'at'=>1,'be'=>1,'been'=>1,'but'=>1,
        'by'=>1,'can'=>1,'could'=>1,'did'=>1,'do'=>1,'does'=>1,'for'=>1,'from'=>1,
        'had'=>1,'has'=>1,'have'=>1,'he'=>1,'her'=>1,'him'=>1,'his'=>1,'i'=>1,'in'=>1,
        'is'=>1,'it'=>1,'its'=>1,'me'=>1,'my'=>1,'no'=>1,'nor'=>1,'not'=>1,'of'=>1,
        'on'=>1,'or'=>1,'our'=>1,'she'=>1,'so'=>1,'than'=>1,'that'=>1,'the'=>1,
        'their'=>1,'them'=>1,'then'=>1,'there'=>1,'they'=>1,'this'=>1,'to'=>1,
        'was'=>1,'we'=>1,'were'=>1,'will'=>1,'with'=>1,'would'=>1,'you'=>1,'your'=>1,
    ];

    // New pairs are learned INACTIVE and only graduate to active once a SECOND
    // independent edit makes the SAME change (count >= 2). A single same-length
    // reword can no longer poison the live dictionary; a genuine recurring fix
    // still activates on its next sighting.
    // A rule an admin/audit deliberately killed as bogus is marked
    // origin='blocked'; it must STAY dead — never let a recurring bad edit
    // resurrect it via the count>=2 graduation below.
    $upsert = $db->prepare("
        INSERT INTO yy_transcript_correction (correction_wrong, correction_right, correction_active_flag)
        VALUES (?, ?, FALSE)
        ON CONFLICT (correction_wrong, correction_right) DO UPDATE
            SET correction_count = yy_transcript_correction.correction_count + 1,
                correction_last_seen_dtime = NOW(),
                correction_active_flag = CASE
                    WHEN yy_transcript_correction.correction_origin = 'blocked' THEN FALSE
                    ELSE (yy_transcript_correction.correction_count + 1) >= 2 END
    ");

    for ($i = 0; $i < count($oldWords); $i++) {
        if (preg_match('/^\s*$/', $oldWords[$i])) continue; // whitespace tokens
        $a = trim($oldWords[$i], " \t\n\r.,;:!?\"'()[]");
        $b = trim($newWords[$i], " \t\n\r.,;:!?\"'()[]");
        if ($a === '' || $b === '' || $a === $b) continue;
        if (mb_strlen($a) < 2 || mb_strlen($b) < 2) continue;
        if (mb_strlen($a) > 60 || mb_strlen($b) > 60) continue;
        // Skip pure case changes — capitalization preferences clutter the dictionary
        if (mb_strtolower($a) === mb_strtolower($b)) continue;
        // Never learn a rule that would globally rewrite a common function word.
        if (isset($stop[mb_strtolower($a)])) continue;
        $upsert->execute([$a, $b]);
    }
}

/**
 * Apply active corrections from yy_transcript_correction to a single text
 * segment. Higher correction_count entries win when multiple match
 * (most-corrected first). Originally lived inside admin-transcript.php;
 * lifted to this shared file so the transcript-worker (which writes
 * Whisper output) can also produce the "auto-fix" snapshot at run time.
 *
 * Cache is per-request: the worker processes one job per process so
 * caching across calls is fine; admin-transcript.php endpoints are also
 * single-request scoped.
 */
function applyCorrectionDictionary(PDO $db, string $text): string {
    static $cache = null;
    if ($cache === null) {
        // Only 'human' corrections (deliberate dictionary / "apply to all"
        // entries) are trusted for blind global replacement. Incidentally
        // auto-learned ('learned') rules are advisory-only and must never
        // rewrite text unconditionally — they caused e.g. "were"->"ultimately"
        // and "it"->"there" corpus-wide. Multi-baseline consensus handles those.
        $stmt = $db->query("SELECT correction_wrong, correction_right, correction_case_sensitive, correction_word_boundary FROM yy_transcript_correction WHERE correction_active_flag = TRUE AND correction_origin = 'human' ORDER BY correction_count DESC, length(correction_wrong) DESC");
        $cache = $stmt->fetchAll();
    }
    foreach ($cache as $c) {
        $wrong = $c['correction_wrong'];
        $right = $c['correction_right'];
        $flags = $c['correction_case_sensitive'] ? '' : 'i';
        if ($c['correction_word_boundary']) {
            $pattern = '/\b' . preg_quote($wrong, '/') . '\b/u' . $flags;
        } else {
            $pattern = '/' . preg_quote($wrong, '/') . '/u' . $flags;
        }
        $text = preg_replace($pattern, $right, $text);
    }
    return $text;
}

/**
 * Apply one find/replace pair to a single string.
 * Pair shape: ['wrong', 'right', 'case_sensitive', 'word_boundary', 'is_regex']
 */
function applyOneReplacement(string $text, array $rep): string {
    $wrong = (string)($rep['wrong'] ?? '');
    $right = (string)($rep['right'] ?? '');
    if ($wrong === '') return $text;
    $flags = empty($rep['case_sensitive']) ? 'i' : '';
    if (!empty($rep['is_regex'])) {
        // User-authored regex — slash-escape only the delimiter.
        $pattern = '/' . str_replace('/', '\\/', $wrong) . '/u' . $flags;
    } else {
        // Fast path: a literal `wrong` can only match if it appears as a
        // substring of $text (true for word-boundary too — \bwrong\b still
        // requires the literal present). Skip the (costly /u) regex entirely
        // when it cannot possibly match. Behaviour-preserving.
        $present = empty($rep['case_sensitive'])
            ? (stripos($text, $wrong) !== false)
            : (strpos($text, $wrong) !== false);
        if (!$present) return $text;
        $escaped = preg_quote($wrong, '/');
        $pattern = !empty($rep['word_boundary'])
            ? '/\b' . $escaped . '\b/u' . $flags
            : '/' . $escaped . '/u' . $flags;
    }
    $result = @preg_replace($pattern, $right, $text);
    return $result === null ? $text : $result;
}

/**
 * Insert many rows with a handful of multi-row INSERT statements instead of
 * one network round-trip per row. On long transcripts (3k+ rows) the per-row
 * form dominated wall-clock and helped push the Initialize endpoint past
 * Cloudflare's 100s edge timeout.
 *
 * $baseSql is everything up to and including 'VALUES'. $rowPh is the per-row
 * tuple (e.g. '(?, ?::interval, ?, ?, ?)'). $rows is a list of flat parameter
 * arrays, one per row, each matching the placeholder count in $rowPh.
 */
function batchInsertRows(PDO $db, string $baseSql, string $rowPh, array $rows, int $perChunk = 500): void {
    if (!$rows) return;
    foreach (array_chunk($rows, $perChunk) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), $rowPh));
        $flat = [];
        foreach ($chunk as $r) { foreach ($r as $v) $flat[] = $v; }
        $db->prepare($baseSql . ' ' . $placeholders)->execute($flat);
    }
}

/**
 * Apply a set of find/replace pairs to a sequence of transcript rows, with
 * matches that span row boundaries collapsing the affected rows together.
 *
 * Two passes:
 *   1. Single-row pass — every replacement is applied to each row in
 *      isolation (fast path; catches every match that fits inside one row).
 *   2. Multi-row pass — for replacements whose `wrong` pattern could match
 *      across a space (literal wrongs that contain whitespace, or any
 *      regex), walk consecutive rows in windows up to $maxRowSpan and try
 *      the replacement against the space-joined window. If it changes the
 *      window, all $span rows collapse into one row at the FIRST row's
 *      segment timestamp.
 *
 * Returns a new array (possibly fewer rows than the input).
 *
 * $rows item shape: ['text' => string, 'segment' => string, 'sort' => int?]
 *
 * The $maxRowSpan default of 6 covers practical multi-word phrases (e.g.
 * "Yada Yahda" across two words, up to 5-6 word phrases). Larger windows
 * are unusual and cost more time at scan time.
 */
function applyReplacementsAcrossRows(array $rows, array $replacements, int $maxRowSpan = 6): array {
    if (!$rows || !$replacements) return $rows;

    // Pass 1: per-row substitutions (fast path, no merging).
    foreach ($rows as &$r) {
        foreach ($replacements as $rep) {
            $r['text'] = applyOneReplacement((string)$r['text'], $rep);
        }
    }
    unset($r);

    // Identify replacements that could span row boundaries.
    $spanningReps = array_values(array_filter($replacements, function($rep) {
        if (!empty($rep['is_regex'])) return true;            // any regex — conservatively eligible
        $w = (string)($rep['wrong'] ?? '');
        return $w !== '' && preg_match('/\s/u', $w) === 1;     // literal with whitespace
    }));
    if (!$spanningReps) return array_values($rows);

    // Pass 2: cross-row scan. Walk linearly; at each row try the largest
    // possible window first (so a longer match wins over a shorter false
    // positive within the same window).
    $result = [];
    $i = 0;
    $n = count($rows);
    while ($i < $n) {
        $merged = false;
        $maxSpan = min($maxRowSpan, $n - $i);
        for ($span = $maxSpan; $span >= 2 && !$merged; $span--) {
            $window = (string)$rows[$i]['text'];
            for ($k = 1; $k < $span; $k++) {
                $window .= ' ' . (string)$rows[$i + $k]['text'];
            }
            $applied = $window;
            foreach ($spanningReps as $rep) {
                $applied = applyOneReplacement($applied, $rep);
            }
            if ($applied !== $window) {
                $result[] = [
                    'segment' => $rows[$i]['segment'],
                    'sort'    => $rows[$i]['sort'] ?? null,
                    'text'    => $applied,
                ];
                $i += $span;
                $merged = true;
            }
        }
        if (!$merged) {
            $result[] = $rows[$i];
            $i++;
        }
    }
    return $result;
}

/**
 * Apply yy_transcript_correction to a row sequence, with cross-row merging.
 * Loads the active correction list once per call (no per-request cache;
 * the worker invokes this once per item).
 */
/**
 * Build a keyword-boost list from the active correction dictionary for use
 * with Deepgram (keywords[]=term:boost) and AssemblyAI (word_boost[]).
 * Returns [['term' => 'Yahowah', 'boost' => 3], ...] sorted by descending
 * correction_count. The "right" side of each correction is what we want
 * the model to RECOGNISE — that's the spelling we keep using post-fix.
 *
 * Boost tiers (Deepgram's recommended scale is roughly 1-10 with diminishing
 * returns past 3):
 *   count >= 50  → boost 3   (heavily-confirmed, e.g. Yahowah/Towrah)
 *   count >= 10  → boost 2
 *   count >= 5   → boost 1
 *   below 5      → excluded (too few confirmations to be reliable)
 *
 * Capped at $limit entries to keep request URLs/bodies reasonable —
 * Deepgram's URL gets long fast and AssemblyAI's word_boost has a 1000-word
 * hard limit. 100 is plenty given how few corrections we typically have.
 */
function buildKeywordBoostList(PDO $db, int $limit = 100): array {
    $stmt = $db->query("
        SELECT correction_right AS term, correction_count AS cnt
          FROM yy_transcript_correction
         WHERE correction_active_flag = TRUE
           AND correction_count >= 5
           AND correction_right ~ '^[[:print:]]+$'
         ORDER BY correction_count DESC
         LIMIT $limit
    ");
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $cnt = (int)$r['cnt'];
        $boost = $cnt >= 50 ? 3 : ($cnt >= 10 ? 2 : 1);
        $out[] = ['term' => (string)$r['term'], 'boost' => $boost];
    }
    return $out;
}

function applyCorrectionsAcrossRows(PDO $db, array $rows): array {
    if (!$rows) return $rows;
    $stmt = $db->query("SELECT correction_wrong, correction_right, correction_case_sensitive, correction_word_boundary FROM yy_transcript_correction WHERE correction_active_flag = TRUE ORDER BY correction_count DESC, length(correction_wrong) DESC");
    $corrections = $stmt->fetchAll();
    if (!$corrections) return $rows;
    $replacements = [];
    foreach ($corrections as $c) {
        $replacements[] = [
            'wrong'          => $c['correction_wrong'],
            'right'          => $c['correction_right'],
            'case_sensitive' => (bool)$c['correction_case_sensitive'],
            'word_boundary'  => (bool)$c['correction_word_boundary'],
            'is_regex'       => false,
        ];
    }
    return applyReplacementsAcrossRows($rows, $replacements);
}

/**
 * Resolve which already-generated _auto feeds drive the hybrid join for an
 * item. The hybrid needs WORD-LEVEL whisper timestamps for its base and can
 * optionally use SEGMENT-LEVEL whisper as a disambiguation aid. Either the
 * OpenAI (whisper-1-*) or the self-hosted GPU (gpu-whisper-large-v3*) engine
 * qualifies, so an operator who transcribed with the free GPU models gets the
 * same hybrids as one who used OpenAI.
 *
 * Returns ['word' => ?code, 'seg' => ?code, 'has_yt' => bool]; word/seg are
 * the chosen model codes (null when that family has no rows for the item).
 * Segment preference follows the chosen word engine so both readings come
 * from the same pass of the audio where possible.
 */
function resolveJoinSources(PDO $db, int $itemKey): array {
    $st = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model AS m
                          FROM yy_feed_item_transcript_auto WHERE feed_item_key = ?");
    $st->execute([$itemKey]);
    $have = [];
    foreach ($st->fetchAll() as $r) $have[$r['m']] = true;

    $word = null;
    foreach (['whisper-1-word', 'gpu-whisper-large-v3-word'] as $w) {
        if (isset($have[$w])) { $word = $w; break; }
    }
    $segPref = ($word === 'gpu-whisper-large-v3-word')
        ? ['gpu-whisper-large-v3', 'whisper-1-segment']
        : ['whisper-1-segment', 'gpu-whisper-large-v3'];
    $seg = null;
    foreach ($segPref as $s) { if (isset($have[$s])) { $seg = $s; break; } }

    return ['word' => $word, 'seg' => $seg, 'has_yt' => isset($have['youtube'])];
}

/**
 * Build the 'whisper-1-word-join' _auto rows for a feed_item from an existing
 * word-level whisper feed + reference-model (default 'youtube') _auto data.
 * The word feed is whichever word-level whisper exists for the item (OpenAI
 * or GPU — see resolveJoinSources). The resulting rows are per-phrase (not
 * per-word) but keep the word feed's word-precise start timestamps, with
 * punctuation copied off the matched reference tokens. Returns the number of
 * rows written (0 if the inputs are missing).
 *
 * Algorithm: for each reference row at time T with next ref at T', restrict
 * the candidate whisper words to those with start times in
 *   [T - SLACK_BEFORE, T' + SLACK_AFTER]
 * Then greedy-match the reference row's tokens in order. The claimed range
 * is [first matched whisper idx .. last matched]. The first match gets the
 * row's segment timestamp. See build_whisper_word_join.php for the CLI
 * wrapper.
 *
 * Optional third feed — $useSeg: when true, the output is written under the
 * model name 'whisper-1-word-join-seg' and an extra disambiguation pass kicks
 * in during anchoring, using whichever SEGMENT-level whisper feed exists.
 * Whisper-segment is the SAME audio read by whisper as the word feed, so its
 * word order agrees closely, but its rows are far too long to use as block
 * boundaries (that stays youtube's job). It is used ONLY as an alignment aid:
 *   (a) its row time-spans bucket each youtube line to the whisper segment
 *       the audio actually belongs to, so a repeated common token in youtube
 *       cannot pull the anchor into a neighbouring segment; and
 *   (b) a bigram check (first token + the next one) confirms the anchor, so a
 *       lone repeated "the"/"to" will not anchor unless the following word
 *       also lines up.
 * When $useSeg is false the function behaves exactly as the proven two-feed
 * (word + youtube) join. When $useSeg is true but no segment feed exists, it
 * returns 0 (the seg variant cannot be built without its source) so the
 * caller can report the missing feed.
 */
function buildWhisperWordJoin(PDO $db, int $itemKey, string $refModel = 'youtube', bool $useSeg = false): int {
    $loadAuto = function (string $model) use ($db, $itemKey): array {
        $st = $db->prepare("
            SELECT feed_item_transcript_segment::text AS segment,
                   feed_item_transcript_text          AS text,
                   feed_item_transcript_sort          AS sort
              FROM yy_feed_item_transcript_auto
             WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ?
             ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
        ");
        $st->execute([$itemKey, $model]);
        return $st->fetchAll();
    };
    // Resolve which engine's feeds back this join (OpenAI or GPU whisper).
    $src = resolveJoinSources($db, $itemKey);
    $wordModel = $src['word'];
    if (!$wordModel) return 0;
    $wordRows = $loadAuto($wordModel);
    $refRows  = $loadAuto($refModel);
    if (!$wordRows || !$refRows) return 0;

    // Optional whisper-segment disambiguation source (see docblock). Loaded
    // only for the 3-feed variant; a request with no segment feed available
    // is a hard miss so the caller can surface "generate segment-level
    // whisper first" rather than silently producing the 2-feed join under
    // the seg model name.
    $segModel = $useSeg ? $src['seg'] : null;
    $segRows  = $segModel ? $loadAuto($segModel) : [];
    if ($useSeg && !$segRows) return 0;

    $norm = function (string $s): string {
        return trim(mb_strtolower($s), " \t\n\r.,;:!?\"()[]<>");
    };
    $trailPunct = function (string $s): string {
        return preg_match('/([.,;:!?]+)$/u', $s, $m) ? $m[1] : '';
    };
    $secs = function (string $hms): float {
        if (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $hms, $m)) {
            return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (float)$m[3];
        }
        return 0.0;
    };

    $times = array_map(function ($r) use ($secs) { return $secs((string)$r['segment']); }, $wordRows);
    $nw = count($wordRows);
    // Precompute the normalized form of every whisper word ONCE. The anchor
    // scan below compares each whisper word against many ref tokens across
    // many ref rows, so re-running mb_strtolower per comparison dominated the
    // runtime on long episodes (~58s for a 78-min item). Same values, computed
    // once → ~10x faster.
    $wordNorm = array_map(function ($r) use ($norm) { return $norm((string)$r['text']); }, $wordRows);
    $firstIdxAtOrAfter = function (float $T, int $startIdx) use ($times, $nw): int {
        for ($i = $startIdx; $i < $nw; $i++) if ($times[$i] >= $T) return $i;
        return $nw;
    };
    $nr = count($refRows);

    // whisper-segment span lookup (3-feed variant only). Returns the
    // [thisStart, nextStart) time window of the whisper segment covering T,
    // used to bucket each youtube line to the right segment of audio.
    $segStarts = array_map(function ($r) use ($secs) { return $secs((string)$r['segment']); }, $segRows);
    $nsg = count($segStarts);
    $segSpanFor = function (float $T) use ($segStarts, $nsg): array {
        if ($nsg === 0) return [-INF, INF];
        $idx = 0;
        for ($i = 0; $i < $nsg; $i++) { if ($segStarts[$i] <= $T) $idx = $i; else break; }
        $start = $segStarts[$idx];
        $end   = ($idx + 1 < $nsg) ? $segStarts[$idx + 1] : INF;
        return [$start, $end];
    };

    // ── Phase 1: pick an anchor whisper index for each reference row ──
    // The anchor is the FIRST whisper word that "belongs" to that ref row.
    // Prefer content alignment: scan the ref row's tokens in order and look
    // for the first one that matches a nearby whisper word (within a time
    // window around the ref row's timestamp). If no content match exists,
    // fall back to the first whisper word at or after the ref row's start
    // time. Anchors are strictly increasing so neighbouring ref rows
    // partition the whisper word stream cleanly.
    //
    // This is "fuzzy" by design — when whisper and youtube disagree on a
    // word, the row's boundary still gets set; only the OUTPUT TEXT comes
    // from whisper. The goal is one join row per ref row, not perfect
    // token-level alignment.
    // Anchor search bounds. ANCHOR_BEFORE keeps the anchor from drifting
    // back into the previous ref row's content — without it, common short
    // tokens like "to" or "the" inside a later ref row can match a whisper
    // word from an earlier moment and pull the row backward.
    $ANCHOR_BEFORE = 1.5;
    $ANCHOR_AFTER  = 4.0;
    $anchors = array_fill(0, $nr, -1);
    $prevAnchor = -1;
    foreach ($refRows as $yi => $refRow) {
        if (!preg_match_all('/\S+/u', (string)$refRow['text'], $mm)) continue;
        $refTokens = $mm[0];
        $T = $secs((string)$refRow['segment']);
        $minByTime = $firstIdxAtOrAfter($T - $ANCHOR_BEFORE, max(0, $prevAnchor + 1));
        $minIdx = max($prevAnchor + 1, $minByTime);
        $maxIdx = $firstIdxAtOrAfter($T + $ANCHOR_AFTER, $minIdx);
        if ($maxIdx <= $minIdx) {
            // No whisper words in this time window; fall back to the very
            // next whisper word (if any).
            $maxIdx = min($nw, $minIdx + 1);
        }

        $anchor = -1;

        // Seg-assisted disambiguation (3-feed variant). Prefer a position
        // where the ref row's first token AND the next token both line up
        // with consecutive whisper words, and whose whisper time sits inside
        // the segment span covering T. This resolves repeated common tokens
        // that the single-token match below would anchor on the wrong
        // instance. Falls through to the base match if no bigram confirms.
        if ($segRows) {
            list($segS, $segE) = $segSpanFor($T);
            $SEG_PAD = 0.75; // tolerate small word/segment timestamp drift at the seams
            for ($ti = 0; $ti + 1 < count($refTokens) && $anchor < 0; $ti++) {
                $rn  = $norm($refTokens[$ti]);
                $rn2 = $norm($refTokens[$ti + 1]);
                if ($rn === '' || $rn2 === '') continue;
                for ($i = $minIdx; $i < $maxIdx; $i++) {
                    if ($times[$i] < $segS - $SEG_PAD || $times[$i] > $segE + $SEG_PAD) continue;
                    if ($wordNorm[$i] !== $rn) continue;
                    if ($i + 1 < $nw && $wordNorm[$i + 1] === $rn2) {
                        $anchor = $i;
                        break 2;
                    }
                }
            }
        }

        // Base content match — first ref token that matches any whisper word
        // in [minIdx, maxIdx). Also the seg variant's fallback.
        if ($anchor < 0) {
            foreach ($refTokens as $tok) {
                $rn = $norm($tok);
                if ($rn === '') continue;
                for ($i = $minIdx; $i < $maxIdx; $i++) {
                    if ($wordNorm[$i] === $rn) {
                        $anchor = $i;
                        break 2;
                    }
                }
            }
        }
        // Time fallback: first whisper word at or after the ref row's start.
        if ($anchor < 0) {
            $tIdx = $firstIdxAtOrAfter($T, $minIdx);
            if ($tIdx < $nw) $anchor = $tIdx;
        }
        if ($anchor < 0) break;  // past end of whisper — remaining ref rows have no words
        // First ref row's anchor pulled to whisper[0] so no leading words
        // get orphaned.
        if ($yi === 0 && $anchor > 0) $anchor = 0;

        $anchors[$yi] = $anchor;
        $prevAnchor = $anchor;
    }

    // ── Phase 2: each ref row's group = [anchor_i .. next_anchor - 1] ──
    // Stitch whisper words together. The anchors strictly increase and each
    // group ends one word before the next anchor, so the groups partition the
    // whisper word stream contiguously: every whisper word lands in exactly
    // one row and no word is emitted twice.
    //
    // The OUTPUT TEXT IS ALWAYS WHISPER WORDS — never the reference (youtube)
    // text. Youtube's only jobs are (a) to mark the row boundaries / timing
    // and (b) to lend trailing punctuation to the matched whisper words below.
    // It must NOT contribute the words themselves. An earlier "sparse window"
    // fallback substituted the youtube row's own text whenever its window held
    // few whisper words; on real items that fired on ~76% of rows, so the join
    // became mostly duplicated youtube captions interleaved with the whisper
    // rows. That fallback is deliberately gone: a thin window simply yields the
    // one or two whisper words it actually contains.
    $result = [];
    for ($yi = 0; $yi < $nr; $yi++) {
        $start = $anchors[$yi];
        if ($start < 0) continue;
        $end = $nw - 1;
        for ($j = $yi + 1; $j < $nr; $j++) {
            if ($anchors[$j] > $start) { $end = $anchors[$j] - 1; break; }
        }
        if ($start > $end) continue;

        preg_match_all('/\S+/u', (string)$refRows[$yi]['text'], $mm);
        $refTokens = $mm[0] ?? [];

        // Greedy match for punctuation transfer (text still comes from whisper).
        $alignMap = [];
        $localWs = $start;
        foreach ($refTokens as $rti => $tok) {
            $rn = $norm($tok);
            if ($rn === '') continue;
            for ($i = $localWs; $i <= $end; $i++) {
                if ($wordNorm[$i] === $rn) {
                    $alignMap[$i] = $rti;
                    $localWs = $i + 1;
                    break;
                }
            }
        }
        $parts = [];
        for ($i = $start; $i <= $end; $i++) {
            $w = (string)$wordRows[$i]['text'];
            if (isset($alignMap[$i])) {
                $p = $trailPunct($refTokens[$alignMap[$i]]);
                if ($p !== '' && !preg_match('/[.,;:!?]$/u', $w)) $w .= $p;
            }
            $parts[] = $w;
        }
        $result[] = [
            'segment' => $wordRows[$start]['segment'],
            'text'    => implode(' ', $parts),
        ];
    }

    $model = $useSeg ? 'whisper-1-word-join-seg' : 'whisper-1-word-join';
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM yy_feed_item_transcript_auto      WHERE feed_item_key = ? AND feed_item_transcript_auto_model      = ?")->execute([$itemKey, $model]);
        $db->prepare("DELETE FROM yy_feed_item_transcript_autoclean WHERE feed_item_key = ? AND feed_item_transcript_autoclean_model = ?")->execute([$itemKey, $model]);
        // _auto has no triggers, so batched multi-row inserts are a pure
        // round-trip win (per-row inserts were a big chunk of the 524 budget).
        $insRows = [];
        $sort = 0;
        foreach ($result as $r) {
            $insRows[] = [$itemKey, $r['segment'], mb_substr($r['text'], 0, 2000), $sort, $model];
            $sort++;
        }
        batchInsertRows($db,
            "INSERT INTO yy_feed_item_transcript_auto (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_auto_model) VALUES",
            '(?, ?::interval, ?, ?, ?)', $insRows);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return 0;
    }
    return count($result);
}

/**
 * Match a diarised item's raw SPEAKER_xx labels against the saved global voice
 * profiles (yy_speaker_profile) by embedding cosine similarity. Two-tier:
 *   • sim >= $autoTh    → APPLY: rename the label to the profile's label across
 *                         the live transcript rows + embedding store of every
 *                         cluster item, so recurring people get named for real.
 *   • sim >= $suggestTh → SUGGEST: return the near-miss (label, profile, sim)
 *                         for the UI to offer as a one-click "Assign <name>?"
 *                         chip, WITHOUT touching any data.
 * Only raw SPEAKER_xx labels are considered — a label already carrying a name is
 * left alone. Returns ['matched'=>[...], 'suggestions'=>[...]]. Used by the
 * admin-transcript.php match/fill actions AND by the build workers so freshly
 * built diarised transcripts arrive pre-named.
 */
function matchSpeakerProfilesForItem(PDO $db, int $srcItem, array $itemKeys, float $autoTh = 0.55, float $suggestTh = 0.40): array {
    $matched = [];
    $suggestions = [];
    $embStmt = $db->prepare("SELECT DISTINCT label, embedding::text AS emb FROM yy_feed_item_speaker_embedding WHERE feed_item_key = ?");
    $embStmt->execute([$srcItem]);
    $renameRow = $db->prepare("UPDATE yy_feed_item_transcript SET feed_item_transcript_speaker=? WHERE feed_item_key=? AND feed_item_transcript_speaker=?");
    // Renaming an embedding to a label another already holds (several raw labels
    // folding into one profile) would violate the (feed_item_key, model, label)
    // unique index — drop the colliding source rows first, then rename the
    // remainder so the profile stays addressable by its new label.
    $dropCollide = $db->prepare("DELETE FROM yy_feed_item_speaker_embedding a
                                  WHERE a.feed_item_key=? AND a.label=?
                                    AND EXISTS (SELECT 1 FROM yy_feed_item_speaker_embedding b
                                                 WHERE b.feed_item_key=a.feed_item_key AND b.model=a.model AND b.label=?)");
    $renameEmb = $db->prepare("UPDATE yy_feed_item_speaker_embedding SET label=? WHERE feed_item_key=? AND label=? AND NOT EXISTS (SELECT 1 FROM yy_feed_item_speaker_embedding ex WHERE ex.feed_item_key = yy_feed_item_speaker_embedding.feed_item_key AND ex.model = yy_feed_item_speaker_embedding.model AND ex.label = ?)");
    $mq = $db->prepare("SELECT speaker_profile_key AS pk, speaker_profile_label AS lbl, speaker_profile_name AS nm,
                               1 - (speaker_profile_embedding <=> ?::vector) AS sim
                          FROM yy_speaker_profile
                         ORDER BY speaker_profile_embedding <=> ?::vector LIMIT 1");
    foreach ($embStmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
        $lab = (string)$er['label'];
        if (!preg_match('/^SPEAKER_\d+$/', $lab)) continue;   // only auto-name raw labels
        $mq->execute([$er['emb'], $er['emb']]);
        $m = $mq->fetch(PDO::FETCH_ASSOC);
        if (!$m) continue;
        $sim = (float)$m['sim'];
        if ((string)$m['lbl'] === $lab) continue;
        if ($sim >= $autoTh) {
            foreach ($itemKeys as $ik) {
                $renameRow->execute([$m['lbl'], (int)$ik, $lab]);
                $dropCollide->execute([(int)$ik, $lab, $m['lbl']]);
                $renameEmb->execute([$m['lbl'], (int)$ik, $lab, $m['lbl']]);
            }
            $matched[] = ['from' => $lab, 'to' => $m['lbl'], 'name' => $m['nm'], 'sim' => round($sim, 3)];
        } elseif ($sim >= $suggestTh) {
            $suggestions[] = ['from' => $lab, 'to' => $m['lbl'], 'name' => $m['nm'],
                              'profile_key' => (int)$m['pk'], 'sim' => round($sim, 3)];
        }
    }
    return ['matched' => $matched, 'suggestions' => $suggestions];
}

/**
 * Auto-name a freshly built item's raw SPEAKER_xx labels from saved profiles by
 * running matchSpeakerProfilesForItem over every cluster item that carries voice
 * embeddings. Applies strong matches only (suggestions are ignored at build —
 * they surface interactively when an admin opens the transcript). FAIL-OPEN:
 * any error is swallowed so a match hiccup never fails a durable build.
 * Returns the count of labels auto-named.
 */
function autoNameSpeakersFromProfiles(PDO $db, int $itemKey): int {
    try {
        $itemKeys = getFeedItemKeyCluster($db, $itemKey);
        if (!$itemKeys) $itemKeys = [$itemKey];
        $ph = implode(',', array_fill(0, count($itemKeys), '?'));
        $embItems = $db->prepare("SELECT DISTINCT feed_item_key FROM yy_feed_item_speaker_embedding WHERE feed_item_key IN ($ph)");
        $embItems->execute($itemKeys);
        $named = 0;
        foreach ($embItems->fetchAll(PDO::FETCH_COLUMN) as $srcItem) {
            $r = matchSpeakerProfilesForItem($db, (int)$srcItem, $itemKeys);
            $named += count($r['matched']);
        }
        return $named;
    } catch (\Throwable $e) {
        error_log('autoNameSpeakersFromProfiles item=' . $itemKey . ' — ' . $e->getMessage());
        return 0;
    }
}
