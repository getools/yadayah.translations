<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/search-log-helpers.php';   // logSearch(), session-key + IP helpers

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$_t0 = microtime(true);

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    errorResponse('Search query is required', 400);
}

// Strip half-rings, modifiers, apostrophes, and single quotes from search input.
// Same list as normalize_search_text() on the SQL side so query "miqra'ey"
// matches paragraph_text_plain "Miqraʿey" via the normalized comparison.
$stripChars = ["\u{02BF}","\u{02BE}","\u{02BC}","\u{02BB}","\u{02B9}","\u{02BA}","\u{2018}","\u{2019}","\u{201C}","\u{201D}","\u{2013}","\u{2014}","'"];
$q = str_replace($stripChars, '', $q);
$q = preg_replace('/\s{2,}/', ' ', trim($q));

// Phonetic skeleton for Hebrew-transliteration matching. MUST stay byte-for-byte
// identical to the SQL phonetic_skeleton() function (used to build the indexed
// paragraph_consonants column) or the query skeleton won't match the stored one.
// Folds the consonant classes that vary in romanization to canonical UPPERCASE
// tokens, drops vowels, preserves word spacing:
//   tsade ts/tz -> C, shin sh -> J, chet/khaf ch/kh -> H, fe ph -> P,
//   tav th -> T, then qof/kaf q/k/c -> K, vav v/w -> V, fe f/p -> P, etc.
// "etsah"/"etzah"/"ʿEtsah" -> "CH"; "Yahowah"/"Yahuwah"/"Yahweh" -> "YHVH";
// "mitzvah"/"mitsvah" -> "MCVH"; "qodesh"/"kodesh" -> "KDJ".
// Digraph alternations are ordered longest-first so PCRE's first-match matches
// Postgres's POSIX leftmost-longest. The strip set mirrors normalize_search_text().
function phoneticSkel(string $s): string {
    $s = mb_strtolower($s);
    $s = str_replace(
        ["\u{02BF}","\u{02BE}","\u{02BC}","\u{02BB}","\u{02B9}","\u{02BA}","\u{2018}","\u{2019}","\u{201C}","\u{201D}","\u{2013}","\u{2014}","'"],
        '', $s);
    $s = preg_replace('/tsch|tch|tz|ts/', 'C', $s);   // tsade
    $s = preg_replace('/sch|sh/', 'J', $s);            // shin
    $s = preg_replace('/ch|kh/', 'H', $s);             // chet / khaf-soft
    $s = str_replace('ph', 'P', $s);                   // fe
    $s = str_replace('th', 'T', $s);                   // tav-spirant
    $s = str_replace('ck', 'K', $s);                   // hard k
    $s = str_replace('gh', 'G', $s);                   // gimel
    $s = strtr($s, 'qckwvfpbgdtszjxhrlmny', 'KKKVVPPBGDTSZJHHRLMNY');
    $s = preg_replace('/[aeiou]/', '', $s);            // drop vowels
    $s = preg_replace('/[^A-Z0-9 ]/u', '', $s);        // keep class tokens / digits / space
    return trim(preg_replace('/ {2,}/', ' ', (string)$s));
}

// Highlights query matches in a snippet via two passes:
//   • literal — walk the snippet and a query word in lockstep, skipping any
//     char in $stripChars on the snippet side, so the stripped query "miqraey"
//     still marks "Miqraʿey". Whole-word (\m..\M) boundaries.
//   • phonetic — for results matched via the phonetic skeleton (the visible
//     word is a transliteration variant of the query, e.g. "etsah" for a query
//     of "etzah"), mark each whole word whose phonetic_skeleton STARTS WITH a
//     query skeleton. $phoneticSkels holds the query word skeletons (uppercase
//     class tokens, e.g. ["CH"]); empty for non-phonetic tiers.
function highlightSnippet(string $snippet, array $words, array $stripChars, array $phoneticSkels = []): string {
    if ($snippet === '') return '';
    $words = array_values(array_unique(array_filter(array_map(function($w) use ($stripChars) {
        $w = str_replace($stripChars, '', mb_strtolower((string)$w));
        return mb_strlen($w) >= 2 ? $w : null;
    }, $words))));
    $phoneticSkels = array_values(array_unique(array_filter(array_map(function($w) {
        $w = trim((string)$w);
        return mb_strlen(str_replace(' ', '', $w)) >= 2 ? $w : null;
    }, $phoneticSkels))));
    if (empty($words) && empty($phoneticSkels)) {
        return htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8');
    }
    $lower = mb_strtolower($snippet);
    $len   = mb_strlen($snippet);
    $marks = []; // [start, end) character-index pairs

    // Word-boundary check that matches Postgres \m / \M semantics used by
    // the search regex side. Treats [a-z0-9_] as word chars; everything
    // else (whitespace, punctuation, non-ASCII letters) is a boundary.
    // Without this, searching for "he" highlighted the "he" inside
    // "the", "When", "them" — substring rather than whole-word.
    $isWordChar = function ($c) {
        return $c !== '' && preg_match('/[a-z0-9_]/u', $c) === 1;
    };

    // Inner: walk $snippet from index $i, trying to match $word, with
    // $extraSkip giving the additional chars to skip on the snippet side
    // beyond $stripChars. Requires a word boundary on both sides of the
    // match so highlights respect the same regex semantics as the
    // search itself (`\msurely\M`, etc.).
    $matchAt = function ($i, $word, $extraSkip) use ($lower, $len, $stripChars, $isWordChar) {
        // Boundary at start: char at $i-1 must not be a word char.
        if ($i > 0) {
            $prev = mb_substr($lower, $i - 1, 1);
            if ($isWordChar($prev)) return -1;
        }
        $j = $i; $w = 0; $wlen = mb_strlen($word);
        while ($j < $len && $w < $wlen) {
            $c = mb_substr($lower, $j, 1);
            if (in_array($c, $stripChars, true) || in_array($c, $extraSkip, true)) { $j++; continue; }
            if ($c !== mb_substr($word, $w, 1)) return -1;
            $j++; $w++;
        }
        if ($w !== $wlen) return -1;
        // Boundary at end: char at $j must not be a word char.
        if ($j < $len) {
            $next = mb_substr($lower, $j, 1);
            if ($isWordChar($next)) return -1;
        }
        return $j;
    };
    $scan = function (array $list, array $extraSkip) use (&$marks, $len, $matchAt) {
        foreach ($list as $word) {
            $i = 0;
            while ($i < $len) {
                $end = $matchAt($i, $word, $extraSkip);
                if ($end > $i) { $marks[] = [$i, $end]; $i = $end; }
                else $i++;
            }
        }
    };
    $scan($words, []);                  // literal pass (strip-chars only)

    // Phonetic pass: mark whole (whitespace-delimited) words whose phonetic
    // skeleton EQUALS a query skeleton, so an "etzah" search bolds the visible
    // spelling variants "etsah" / "ʿEtsah" but not coincidental prefix-sharers.
    // Mirrors the search-side whole-word match `paragraph_consonants ~ '(^| )SKEL( |$)'`.
    if (!empty($phoneticSkels)) {
        $chars = preg_split('//u', $snippet, -1, PREG_SPLIT_NO_EMPTY);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && preg_match('/\s/u', $chars[$i])) $i++;   // skip whitespace
            if ($i >= $len) break;
            $wstart = $i;
            while ($i < $len && !preg_match('/\s/u', $chars[$i])) $i++;  // consume non-space token
            $wskel = phoneticSkel(implode('', array_slice($chars, $wstart, $i - $wstart)));
            if ($wskel !== '') {
                foreach ($phoneticSkels as $qs) {
                    if ($wskel === $qs) { $marks[] = [$wstart, $i]; break; }  // whole-word skeleton equality
                }
            }
        }
    }

    if (empty($marks)) return htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8');
    usort($marks, function($a, $b) { return $a[0] - $b[0] ?: $a[1] - $b[1]; });
    // Merge overlapping / adjacent marks so we don't emit nested <mark>s.
    $merged = [$marks[0]];
    for ($k = 1; $k < count($marks); $k++) {
        $top = &$merged[count($merged) - 1];
        if ($marks[$k][0] <= $top[1]) { if ($marks[$k][1] > $top[1]) $top[1] = $marks[$k][1]; }
        else $merged[] = $marks[$k];
        unset($top);
    }
    $out = '';
    $cursor = 0;
    foreach ($merged as [$start, $end]) {
        $out .= htmlspecialchars(mb_substr($snippet, $cursor, $start - $cursor), ENT_QUOTES, 'UTF-8');
        $out .= '<mark>' . htmlspecialchars(mb_substr($snippet, $start, $end - $start), ENT_QUOTES, 'UTF-8') . '</mark>';
        $cursor = $end;
    }
    $out .= htmlspecialchars(mb_substr($snippet, $cursor), ENT_QUOTES, 'UTF-8');
    return $out;
}
// Cap query to 150 chars to prevent Tier-3 from expanding into dozens of trigram scans.
// Trim to last complete word so we don't split mid-token.
if (mb_strlen($q) > 150) {
    $q = trim(preg_replace('/\s+\S*$/', '', mb_substr($q, 0, 150)));
}

$mode   = $_GET['mode'] ?? 'all';
$series = isset($_GET['series']) && $_GET['series'] !== '' ? (int)$_GET['series'] : null;
$volume = isset($_GET['volume']) && $_GET['volume'] !== '' ? (int)$_GET['volume'] : null;
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 25)));
$offset = ($page - 1) * $limit;

$pdo = getDb();

// Snippet length cap — configurable via Admin → Search → Result Snippets.
// Clamped to a sane range so a misconfigured value can't blow up
// memory or return absurdly short slices.
$snippetLen = 500;
try {
    $sl = $pdo->prepare("SELECT setting_value FROM yy_setting WHERE setting_scope_code = 'page' AND setting_group_code = 'search' AND setting_code = 'snippet-length' LIMIT 1");
    $sl->execute();
    $v = (int)($sl->fetchColumn() ?: 0);
    if ($v >= 80 && $v <= 2000) $snippetLen = $v;
} catch (Exception $e) { /* fall through to default */ }
// Substring window is anchored ~30% before the match, so the matched
// term sits in the front third of the snippet.
$snippetLead = (int)floor($snippetLen * 0.32);

// --- Alias expansion: look up alternate forms for each word in the query ---
$queryWords = preg_split('/\s+/', $q);
$aliasTargets = [];
// Auto-detected aliases must be corroborated by at least 3 distinct sessions
// before they affect anyone's results. A single user pivoting between thematic
// terms (e.g. nakam → nacham → shaar) used to be enough to poison the search;
// this gate keeps weak rows in the table for tracking without applying them.
// alias_curated_flag = TRUE always wins (admin-curated entries are trusted).
foreach ($queryWords as $w) {
    $aliasStmt = $pdo->prepare("
        SELECT alias_target FROM yy_search_alias
         WHERE lower(alias_term) = lower(?)
           AND (alias_curated_flag = TRUE OR alias_session_count >= 3)
    ");
    $aliasStmt->execute([$w]);
    $targets = $aliasStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($targets as $t) {
        $aliasTargets[] = $t;
    }
}

// Build filter conditions (shared between all tiers)
$filterConditions = ["p.paragraph_active_flag = true", "v.volume_active_flag = true"];
$filterParams = [];
if ($series !== null) {
    $filterConditions[] = "p.series_key = ?";
    $filterParams[] = $series;
}
if ($volume !== null) {
    $filterConditions[] = "p.volume_key = ?";
    $filterParams[] = $volume;
}

// --- TIER 1: mode-specific match conditions ---
// "Exact Phrase" is intentionally narrower than the other modes — it uses
// a literal whole-word(s) regex match on the plain text (no stemming, no
// substring, no consonant skeleton, no alias broadening). Anything looser
// would defeat what users expect from a quoted-phrase search and would
// produce the same row count as "All Words" for short queries (the ILIKE
// substring branch in the loose path drowns the FTS difference).
$consonantWordsForHighlight = [];
if ($mode === 'phrase') {
    // \m / \M are Postgres word boundaries. preg_quote covers all regex
    // metachars Postgres recognises (overlapping set with PCRE).
    $rxPattern = '\m' . preg_quote($q, '/') . '\M';
    $ftsMatchConditions = ["p.paragraph_text_plain ~* ?"];
    $ftsMatchParams    = [$rxPattern];
    // Stub these so the ts_rank/snippet SELECT downstream still binds —
    // ranking is unused for phrase mode (results are all equal-rank), but
    // the SQL references $tsqSql / $tsqParam unconditionally.
    $tsqSql   = "plainto_tsquery('english', ?)";
    $tsqParam = $q;
} else {
    $tsqParam = $q;
    if ($mode === 'any') {
        $tsqParam = implode(' | ', array_map(function($w) {
            return preg_replace('/[^a-zA-Z0-9]/', '', $w);
        }, $queryWords));
        $tsqSql = "to_tsquery('english', ?)";
    } else {
        $tsqSql = "plainto_tsquery('english', ?)";
    }

    // Build an OR group of:
    //   • FTS match on paragraph_tsv (stem-aware, fast GIN index)
    //   • Word-anchored phonetic-skeleton regex per query word on the indexed
    //     paragraph_consonants column (Hebrew-transliteration variants:
    //     etsah↔etzah, Yahowah↔Yahuwah↔Yahweh, mitzvah↔mitsvah, …).
    //   • One ILIKE per alias target.
    // NOTE: plain ILIKE on paragraph_text_plain was removed from Tier 1 —
    // on common terms it triggered a 5000-row bitmap heap scan that took
    // 10+ seconds even with the trigram index. Stemming-asymmetry misses
    // (e.g. "Taruwah" → paragraphs containing "Taruwa") fall through to
    // Tier 2 which does a single ILIKE scan with no TSV overhead.
    // PERF: paragraph_norm / paragraph_consonants are materialized columns
    // (maintained by the BEFORE tsv trigger) so the trigram-index bitmap
    // recheck reads a stored value instead of recomputing normalize_search_text()
    // / phonetic_skeleton() per row — the fix that took common-term searches
    // from 40s+ (and 8s-timeout tier fallthrough) down to sub-second.
    $ftsMatchConditions = [
        "p.paragraph_tsv @@ $tsqSql",
    ];
    $ftsMatchParams = [
        $tsqParam,
    ];

    // Per-word phonetic (Hebrew-transliteration) skeletons, computed once and
    // used by the PHONETIC fallback tier (below) — NOT OR'd into this Tier-1
    // gate. Reason: OR-ing a word-anchored consonants regex into the common
    // path forces a regex recheck over every tsv candidate (thousands of rows
    // for common terms like "covenant"), pushing the count past the 8s budget.
    // Phonetic only needs to fire when the literal/stem path finds nothing
    // (e.g. "etzah" → corpus spelling "etsah"), so it runs as a fallback.
    // Gated at >=2 class tokens so single-consonant words don't match the world.
    $phonSkels = [];   // trimmed-word => skeleton
    foreach ($queryWords as $qw) {
        $t = preg_replace('/[^A-Za-z0-9]/', '', $qw);
        if ($t === '' || isset($phonSkels[$t])) continue;
        $ps = phoneticSkel($t);
        if (mb_strlen(str_replace(' ', '', $ps)) >= 2) $phonSkels[$t] = $ps;
    }

    foreach ($aliasTargets as $at) {
        $ftsMatchConditions[] = "p.paragraph_norm ILIKE ?";
        $ftsMatchParams[]     = '%' . str_replace(['%', '_'], ['\%', '\_'], $at) . '%';
    }
}

$allConditions = array_merge(['(' . implode(' OR ', $ftsMatchConditions) . ')'], $filterConditions);
$allParams = array_merge($ftsMatchParams, $filterParams);

// "All Words" gate: in addition to the OR'd FTS/ILIKE conditions above,
// require each query word to appear as a literal word in the paragraph.
// Postgres `plainto_tsquery('english', …)` drops common stopwords ('he',
// 'and', 'the', …), so without this gate a search for "surely he would"
// would match paragraphs containing only "surely" and "would" — which
// users correctly identify as "Any Word"-like behavior, not "All Words".
// \m and \M are Postgres word-boundary anchors (start/end of word).
if ($mode === 'all') {
    foreach ($queryWords as $qw) {
        $qwTrim = preg_replace('/[^A-Za-z0-9]/', '', $qw);
        if ($qwTrim === '') continue;
        $allConditions[] = "p.paragraph_norm ~* ?";
        $allParams[]     = '\m' . preg_quote(strtolower($qwTrim), '/') . '\M';
    }
}

$ftsWhere = 'WHERE ' . implode(' AND ', $allConditions);

// Tier 1 runs as ONE windowed query: COUNT(*) OVER() returns the exact total
// alongside the page of rows, so the matched set is scanned/re-checked ONCE
// instead of twice (a separate COUNT then SELECT each re-checked thousands of
// rows for common terms — "covenant" ~11.7s→~5.5s). 8s budget: if the scan is
// too slow (very abundant terms like "yahowah", 34k matches) it times out and
// we fall through to Tier 2/3 ($tier1TimedOut also suppresses the phonetic tier).
$tier1TimedOut = false;
$results = [];
$total = 0;
$snippetAnchor = str_replace($stripChars, '', $queryWords[0] ?? $q);
try {
    // 12s (vs the tier-2/3 8s): the single windowed query does the count's work
    // too, so it needs more headroom than the old bare COUNT did — otherwise
    // common terms straddle the limit and inconsistently fall to Tier 2,
    // yielding different totals page-to-page. Genuinely abundant terms
    // (yahowah, 34k) still exceed it and fall through.
    $pdo->exec("SET statement_timeout = '12s'");
    $stmt = $pdo->prepare("
        SELECT v.volume_label AS volume_label,
               v.volume_code AS volume_code,
               v.volume_img_icon AS volume_img_icon,
               v.volume_flip_code AS flip_code,
               v.volume_pdf AS volume_pdf,
               s.series_label AS series_label,
               ch.chapter_name AS chapter_name,
               ch.chapter_number AS chapter_number,
               p.paragraph_page AS page,
               p.paragraph_number AS paragraph_number,
               COUNT(*) OVER() AS total_count,
               ts_rank(p.paragraph_tsv, $tsqSql) AS rank,
               CASE WHEN length(p.paragraph_text_plain) > $snippetLen
                    THEN substring(p.paragraph_text_plain
                         FROM greatest(1, position(lower(?) in lower(p.paragraph_norm)) - $snippetLead)
                         FOR $snippetLen)
                    ELSE p.paragraph_text_plain
               END AS snippet,
               p.paragraph_text_html AS html
        FROM yy_paragraph p
        JOIN yy_volume v ON v.volume_key = p.volume_key
        JOIN yy_series s ON s.series_key = p.series_key
        LEFT JOIN yy_chapter ch ON ch.chapter_key = p.chapter_key
        $ftsWhere
        ORDER BY rank DESC, v.volume_sort, p.paragraph_page, p.paragraph_number
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge([$tsqParam, $snippetAnchor], $allParams, [$limit, $offset]));
    $results = $stmt->fetchAll();
    $total = $results ? (int)$results[0]['total_count'] : 0;
} catch (PDOException $e) {
    $results = []; $total = 0; $tier1TimedOut = true; // too slow (abundant term); fall through
} finally {
    try { $pdo->exec("SET statement_timeout = '600s'"); } catch (PDOException $_) {}
}

// ── Search-log + auto-alias learning ─────────────────────────────────
// Log every query (anonymous-safe fingerprint by IP+UA) so we can
// detect "Q1 returned few hits, then Q2 returned many, in the same
// session within ~90s" → auto-promote Q1→Q2 as an alias. Each
// re-detection bumps alias_weight; admin-curated rows start at
// weight=10 and stay above auto-detected ones (which start at 1).
$_logSession = searchLogSessionKey();   // same algorithm as before — reused below for the alias lookback
try {
    // Skip logging only for explicit Tier-1-only paging — page>1 is
    // a follow-up to the same query and not a new "attempt".
    if ($page === 1) {
        $_filters = array_filter([
            'series' => $series,
            'volume' => $volume,
            'limit'  => $limit,
        ], function ($v) { return $v !== null; });
        logSearch($pdo, 'books', $q, $mode, $total, $_filters, (int)((microtime(true) - $_t0) * 1000));

        // Detect Q1→Q2 escalation: previous query in this session had <5 hits
        // AND was made within 90 seconds AND wasn't the same string. If so,
        // upsert the alias with weight tally.
        if ($total >= 20) {
            $prev = $pdo->prepare("
                SELECT search_log_query
                  FROM yy_search_log
                 WHERE search_log_session = ?
                   AND lower(search_log_query) != lower(?)
                   AND search_log_result_count < 5
                   AND search_log_dtime > NOW() - INTERVAL '90 seconds'
                 ORDER BY search_log_dtime DESC
                 LIMIT 1
            ");
            $prev->execute([$_logSession, $q]);
            $prevQ = $prev->fetchColumn();
            if ($prevQ !== false && $prevQ !== '' && mb_strlen($prevQ) <= 100 && mb_strlen($q) <= 100) {
                // Upsert on the lower(term)/lower(target) unique index. On
                // re-detection, weight + session_count only bump when the
                // detecting session differs from the last one that bumped it,
                // so a single user exploring a thematic neighborhood can't
                // ratchet a bad alias above the runtime gate of 3. Curated
                // rows are still skipped by the WHERE clause.
                $pdo->prepare("
                    INSERT INTO yy_search_alias
                        (alias_term, alias_target, alias_weight, alias_session_count,
                         alias_curated_flag, alias_auto_dtime, alias_last_session)
                    VALUES (?, ?, 1, 1, FALSE, NOW(), ?)
                    ON CONFLICT (lower(alias_term), lower(alias_target)) DO UPDATE
                        SET alias_weight =
                                yy_search_alias.alias_weight +
                                CASE WHEN yy_search_alias.alias_last_session IS DISTINCT FROM EXCLUDED.alias_last_session THEN 1 ELSE 0 END,
                            alias_session_count =
                                yy_search_alias.alias_session_count +
                                CASE WHEN yy_search_alias.alias_last_session IS DISTINCT FROM EXCLUDED.alias_last_session THEN 1 ELSE 0 END,
                            alias_last_session  = EXCLUDED.alias_last_session,
                            alias_auto_dtime    = NOW()
                        WHERE NOT yy_search_alias.alias_curated_flag
                ")->execute([$prevQ, $q, $_logSession]);
            }
        }
    }
} catch (Exception $e) { /* never let logging break search */ }

$fuzzy = false;

if ($total > 0) {
    // $results were already fetched by the single windowed Tier-1 query above.
    // Drop the window-count column and highlight (strip-aware; the snippet is a
    // substring around the match position, so "Miqraʿey" still gets wrapped).
    $highlightWords = array_merge($queryWords, $aliasTargets);
    foreach ($results as &$row) {
        unset($row['total_count']);
        if ($row['snippet']) {
            $row['snippet'] = highlightSnippet((string)$row['snippet'], $highlightWords, $stripChars, $consonantWordsForHighlight);
        }
    }
    unset($row);
} else if ($mode === 'phrase') {
    // Exact Phrase intentionally has no fuzzy fallback — falling through
    // to Tier 2/3 ILIKE/trigram would defeat the strict literal contract.
    // Return zero results so the user sees "no exact phrase match" instead
    // of a misleading substring-fuzzed count.
    $results = [];
} else {
    // --- TIER 1.5: PHONETIC (Hebrew transliteration variants) ---
    // Reached when the literal/stem Tier 1 was sparse (0..$limit-1 hits). Match
    // each query word by its phonetic skeleton against the indexed
    // paragraph_consonants column, so "etzah"→"etsah" (skeleton "CH") and
    // "yahweh"→every "Yahowah" (skeleton "YHVH"). Phonetic is a superset of the
    // literal match, so this only ever broadens. AND across words for "all"
    // mode, OR for "any". If it finds nothing, fall through to the Tier 2/3
    // substring fuzzy search below.
    $fuzzy = true;
    $total = 0;
    $phonParams = [];
    // Skip phonetic when Tier 1 *timed out* (abundant term) rather than
    // genuinely finding nothing — the phonetic skeleton for a common word is
    // just as abundant and would burn another 8s before falling to Tier 2.
    if (!empty($phonSkels) && !$tier1TimedOut) {
        $phonConds = [];
        foreach ($phonSkels as $ps) {
            // Whole-word skeleton equality (not just prefix): the word's whole
            // phonetic skeleton must equal the query's, so "etzah" (CH) matches
            // spelling variants "etsah"/"ʿetsah" but NOT coincidental prefix
            // sharers like "tsachaq" (CHK) or "tsahorym" (CHRM).
            $phonConds[]  = "p.paragraph_consonants ~ ?";
            $phonParams[] = '(^| )' . $ps . '( |$)';
        }
        $glue = ($mode === 'any') ? ' OR ' : ' AND ';
        $phonWhere = 'WHERE (' . implode($glue, $phonConds) . ') AND ' . implode(' AND ', $filterConditions);
        try {
            $pdo->exec("SET statement_timeout = '8s'");
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM yy_paragraph p JOIN yy_volume v ON v.volume_key = p.volume_key $phonWhere");
            $countStmt->execute(array_merge($phonParams, $filterParams));
            $total = (int)$countStmt->fetchColumn();
        } catch (PDOException $e) {
            $total = 0;
        } finally {
            try { $pdo->exec("SET statement_timeout = '600s'"); } catch (PDOException $_) {}
        }
    }
    if ($total > 0) {
        // The matched word is a spelling variant of the query, so anchoring the
        // snippet on the literal query word may miss — position() returns 0 and
        // the snippet falls back to the paragraph head, which is acceptable.
        $stmt = $pdo->prepare("
            SELECT v.volume_label AS volume_label,
                   v.volume_code AS volume_code,
                   v.volume_img_icon AS volume_img_icon,
                   v.volume_flip_code AS flip_code,
                   v.volume_pdf AS volume_pdf,
                   s.series_label AS series_label,
                   ch.chapter_name AS chapter_name,
                   ch.chapter_number AS chapter_number,
                   p.paragraph_page AS page,
                   p.paragraph_number AS paragraph_number,
                   0::float AS rank,
                   CASE WHEN length(p.paragraph_text_plain) > $snippetLen
                        THEN substring(p.paragraph_text_plain FROM greatest(1, position(lower(?) in lower(p.paragraph_norm)) - $snippetLead) FOR $snippetLen)
                        ELSE p.paragraph_text_plain
                   END AS snippet,
                   p.paragraph_text_html AS html
            FROM yy_paragraph p
            JOIN yy_volume v ON v.volume_key = p.volume_key
            JOIN yy_series s ON s.series_key = p.series_key
            LEFT JOIN yy_chapter ch ON ch.chapter_key = p.chapter_key
            $phonWhere
            ORDER BY v.volume_sort, p.paragraph_page, p.paragraph_number
            LIMIT ? OFFSET ?
        ");
        $snippetAnchor = str_replace($stripChars, '', $queryWords[0] ?? $q);
        $stmt->execute(array_merge([$snippetAnchor], $phonParams, $filterParams, [$limit, $offset]));
        $results = $stmt->fetchAll();
        // Pass the query phonetic skeletons so the variant spelling that
        // actually matched (e.g. "etsah" for "etzah") gets <mark>'d.
        $highlightWords = array_merge($queryWords, $aliasTargets);
        foreach ($results as &$row) {
            if ($row['snippet']) {
                $row['snippet'] = highlightSnippet((string)$row['snippet'], $highlightWords, $stripChars, array_values($phonSkels));
            }
        }
        unset($row);
    } else {
    // --- TIER 2: ILIKE substring search ---
    $fuzzy = true;
    $likePattern = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $fuzzyConditions = array_merge(["p.paragraph_norm ILIKE ?"], $filterConditions);
    $fuzzyWhere = 'WHERE ' . implode(' AND ', $fuzzyConditions);

    // Short timeout on Tier 2 count — full-string ILIKE on large tables can
    // be slow for broad patterns. On timeout fall through to Tier 3.
    try {
        $pdo->exec("SET statement_timeout = '8s'");
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM yy_paragraph p JOIN yy_volume v ON v.volume_key = p.volume_key $fuzzyWhere");
        $countStmt->execute(array_merge([$likePattern], $filterParams));
        $total = (int)$countStmt->fetchColumn();
    } catch (PDOException $e) {
        $total = 0; // Count too slow; fall through to Tier 3
    } finally {
        try { $pdo->exec("SET statement_timeout = '600s'"); } catch (PDOException $_) {}
    }

    if ($total > 0) {
        $stmt = $pdo->prepare("
            SELECT v.volume_label AS volume_label,
                   v.volume_code AS volume_code,
                   v.volume_img_icon AS volume_img_icon,
                   v.volume_flip_code AS flip_code,
                   v.volume_pdf AS volume_pdf,
                   s.series_label AS series_label,
                   ch.chapter_name AS chapter_name,
                   ch.chapter_number AS chapter_number,
                   p.paragraph_page AS page,
                   p.paragraph_number AS paragraph_number,
                   similarity(p.paragraph_norm, ?) AS rank,
                   CASE WHEN length(p.paragraph_text_plain) > $snippetLen
                        THEN substring(p.paragraph_text_plain FROM greatest(1, position(lower(?) in lower(p.paragraph_norm)) - $snippetLead) FOR $snippetLen)
                        ELSE p.paragraph_text_plain
                   END AS snippet,
                   p.paragraph_text_html AS html
            FROM yy_paragraph p
            JOIN yy_volume v ON v.volume_key = p.volume_key
            JOIN yy_series s ON s.series_key = p.series_key
            LEFT JOIN yy_chapter ch ON ch.chapter_key = p.chapter_key
            $fuzzyWhere
            ORDER BY rank DESC, v.volume_sort, p.paragraph_page, p.paragraph_number
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge([$q, $q], [$likePattern], $filterParams, [$limit, $offset]));
        $results = $stmt->fetchAll();

        $highlightWords = array_merge($queryWords, $aliasTargets);
        foreach ($results as &$row) {
            if ($row['snippet']) {
                $row['snippet'] = highlightSnippet((string)$row['snippet'], $highlightWords, $stripChars, $consonantWordsForHighlight);
            }
        }
        unset($row);
    } else {
        // --- TIER 3: Per-word ILIKE fallback (OR logic, uses GIN idx_paragraph_norm2_trgm) ---
        // word_similarity (%>) never uses GIN/GiST indexes in PG 15, causing full seq scans.
        // Per-word ILIKE on the materialized paragraph_norm column DOES use the
        // GIN trigram index and — because the bitmap recheck reads a stored value
        // instead of recomputing normalize_search_text() per row — is now fast.
        // Semantics: any query word present in a paragraph (OR logic), ranked by word-match count.
        $tier3Words = array_values(array_filter(
            array_slice($queryWords, 0, 8),
            fn($w) => mb_strlen($w) >= 3
        ));
        if (empty($tier3Words)) {
            $total = 0;
            $results = [];
        } else {
            $simConditions = [];
            $simParams = [];
            foreach ($tier3Words as $w) {
                $simConditions[] = "p.paragraph_norm ILIKE ?";
                $simParams[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $w) . '%';
            }
            $simWhere = 'WHERE (' . implode(' OR ', $simConditions) . ')';
            if (count($filterConditions) > 0) {
                $simWhere .= ' AND ' . implode(' AND ', $filterConditions);
            }

            // Skip the COUNT for Tier 3 — the multi-word OR ILIKE on the
            // trigram index hits hundreds of thousands of bitmap entries for
            // common words and timed out in production (30+ seconds).
            // Instead fetch $limit+1 rows; if we get $limit+1 back there are
            // more pages and we signal that via $total = null. The frontend
            // (site-search.js) already handles null total gracefully.

            // Rank by how many query words appear; snippet anchored to first matching word.
            $rankCases = implode(' + ', array_fill(0, count($tier3Words), '(CASE WHEN p.paragraph_norm ILIKE ? THEN 1 ELSE 0 END)'));
            $firstWord = $tier3Words[0];
            $stmt = $pdo->prepare("
                SELECT v.volume_label AS volume_label,
                       v.volume_code AS volume_code,
                       v.volume_img_icon AS volume_img_icon,
                       v.volume_flip_code AS flip_code,
                       v.volume_pdf AS volume_pdf,
                       s.series_label AS series_label,
                       ch.chapter_name AS chapter_name,
                       ch.chapter_number AS chapter_number,
                       p.paragraph_page AS page,
                       p.paragraph_number AS paragraph_number,
                       ($rankCases)::float / ? AS rank,
                       CASE WHEN length(p.paragraph_text_plain) > $snippetLen
                            THEN substring(p.paragraph_text_plain
                                 FROM greatest(1, position(lower(?) in lower(p.paragraph_norm)) - $snippetLead)
                                 FOR $snippetLen)
                            ELSE p.paragraph_text_plain
                       END AS snippet,
                       p.paragraph_text_html AS html
                FROM yy_paragraph p
                JOIN yy_volume v ON v.volume_key = p.volume_key
                JOIN yy_series s ON s.series_key = p.series_key
                LEFT JOIN yy_chapter ch ON ch.chapter_key = p.chapter_key
                $simWhere
                ORDER BY rank DESC, v.volume_sort, p.paragraph_page, p.paragraph_number
                LIMIT ? OFFSET ?
            ");
            try {
                $stmt->execute(array_merge(
                    $simParams,             // CASE WHEN rank conditions
                    [count($tier3Words)],   // divisor for rank normalization
                    [$firstWord],           // snippet anchor word
                    $simParams,             // WHERE conditions
                    $filterParams,
                    [$limit + 1, $offset]   // fetch one extra to detect more pages
                ));
                $results = $stmt->fetchAll();
            } catch (PDOException $e) {
                $results = [];
                $total = 0;
            }

            // Derive total from the +1 sentinel row.
            if (count($results) > $limit) {
                array_pop($results);
                // Exact count unknown; use minimum lower bound so the
                // frontend still shows a Next button and correct page count.
                $total = $offset + $limit + 1;
            } else {
                $total = $offset + count($results);
            }

            $highlightWords = array_merge($queryWords, $aliasTargets);
            foreach ($results as &$row) {
                if ($row['snippet']) {
                    $row['snippet'] = highlightSnippet((string)$row['snippet'], $highlightWords, $stripChars, $consonantWordsForHighlight);
                }
            }
            unset($row);
        }
    }
    } // end Tier 2/3 (phonetic fallback found nothing)
}

// Build per-result links.
//
// Page numbers in yy_paragraph come from the source docx footer. The new
// self-hosted flipbook viewer reads pages straight from the Word-COM-
// generated PDF, so PDF page = docx footer page = the page stored here
// (provided the volume's PDF was produced by the desktop "YY PDF
// Generator App"). The viewer accepts `#chapter=slug&page=N` in the URL
// hash and seeks to that page on load; if `page=N` exceeds the actual
// page count it silently clamps, so older volumes whose PDF hasn't been
// regenerated yet fail gracefully toward the chapter or book home.

// Slugify a chapter's display title ("1 Babel ~ Confusion") to the
// stable URL slug the flipbook viewer recognizes ("1-babel-confusion").
// Mirrors the JS slugify in extract_toc_v3.py / the new flipbook code.
function chapterSlug(string $title): string {
    $s = mb_strtolower($title, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $s);
    $s = preg_replace('/[\s_-]+/u', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? $s : 'section';
}

foreach ($results as &$row) {
    // Strip apostrophe-ish glyphs from the canonical book code to get
    // the public-facing slug. The named-path FlipHTML5 bundles on disk
    // (e.g., /opt/yada-www/public/YY-s02v01-Yada-Yahowah-Baresyth-Beginning/)
    // strip apostrophes; volume_code preserves them to match the docx
    // filename, so we need the strip step here.
    $bookSlug = $row['volume_code']
        ? preg_replace("/[\u{0027}\u{2018}\u{2019}\u{02BC}]/u", '', $row['volume_code'])
        : null;

    $bookUrl = $bookSlug ? '/' . $bookSlug . '/' : null;
    $row['book_url'] = $bookUrl;

    // Build the deep-link hash from whatever location info we have for
    // this paragraph: chapter slug and/or page. Both keys are honored by
    // the new flipbook viewer (`page` takes precedence over `chapter`
    // when both are present, which is what we want — page is the more
    // specific destination).
    // URL convention (see flipbook-viewer.js): `p` = page, `h` = paragraph
    // (used for the URL-driven highlight), `q` = search query for in-book
    // re-search, `chapter` = slug fallback when page isn't known. Older
    // `page=` URLs are still parsed by the viewer for backwards compat.
    $hashParts = [];
    if ($row['chapter_number'] && $row['chapter_name']) {
        $title = $row['chapter_number'] . ' ' . $row['chapter_name'];
        $hashParts[] = 'chapter=' . chapterSlug($title);
    }
    if (!empty($row['page'])) {
        $hashParts[] = 'p=' . (int)$row['page'];
    }
    if (!empty($row['paragraph_number'])) {
        $hashParts[] = 'h=' . (int)$row['paragraph_number'];
    }
    if ($q !== '') {
        $hashParts[] = 'q=' . rawurlencode($q);
    }
    $hash = $hashParts ? '#' . implode('&', $hashParts) : '';

    // chapter_url is the URL site-search.js wraps the location text in
    // ("Ch 3 · Foo · Page 47"). Includes both chapter and page when
    // available so the deep-link is as precise as possible. Falls back
    // to null if neither is known; the renderer then uses bookHref.
    $row['chapter_url'] = ($bookUrl && $hashParts) ? ($bookUrl . $hash) : null;

    // flip_url retained for backwards compatibility (book.html, search
    // prototype). Always populated; carries the same hash as chapter_url
    // when location info is present.
    $row['flip_url'] = $bookUrl ? ($bookUrl . $hash) : null;

    unset($row['rank']);
    unset($row['paragraph_number']);
}
unset($row);

jsonResponse([
    'total'   => $total,
    'page'    => $page,
    'limit'   => $limit,
    'pages'   => (int)ceil($total / $limit),
    'fuzzy'   => $fuzzy,
    'results' => $results,
]);
