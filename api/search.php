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

// Consonant skeleton for Hebrew-transliteration fuzzy matching. Mirrors
// the SQL normalize_consonants() function: lowercase, strip aeiou, drop
// non-alphanumerics. "shemayim" / "shamaym" / "shamayim" all collapse
// to "shmym"; "Yahowah" / "Yahuwah" / "Yahweh" all collapse to "yhwh".
// Used both for matching (ILIKE clause below) AND for highlightSnippet
// so a consonant-only match still wraps the visible word in <mark>.
function consonantSkel(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[aeiou]/u', '', $s);
    $s = preg_replace('/[^a-z0-9 ]/u', '', $s);
    return trim(preg_replace('/\s+/u', ' ', (string)$s));
}

// Walk both the snippet and a query word in lockstep, skipping any char
// in $stripChars on the snippet side, so "Miqraʿey" matches "miqraey".
// When $consonantMode is true, ALSO skip aeiou in the snippet so
// "Shamayim" matches the consonant skeleton "shmym" — that's what lets
// the highlight follow a fuzzy/transliteration match into the visible
// text. Used by every search tier so all snippets get consistent
// highlighting even when the source contains stripped characters or
// vowel-variant spellings.
function highlightSnippet(string $snippet, array $words, array $stripChars, array $consonantWords = []): string {
    if ($snippet === '') return '';
    $words = array_values(array_unique(array_filter(array_map(function($w) use ($stripChars) {
        $w = str_replace($stripChars, '', mb_strtolower((string)$w));
        return mb_strlen($w) >= 2 ? $w : null;
    }, $words))));
    $consonantWords = array_values(array_unique(array_filter(array_map(function($w) {
        $w = (string)$w;
        return mb_strlen($w) >= 3 ? mb_strtolower($w) : null;
    }, $consonantWords))));
    if (empty($words) && empty($consonantWords)) {
        return htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8');
    }
    $lower = mb_strtolower($snippet);
    $len   = mb_strlen($snippet);
    $vowels = ['a','e','i','o','u'];
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
    $scan($consonantWords, $vowels);    // consonant pass (also skip vowels)

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

    // Build a UNION of:
    //   • FTS match on paragraph_tsv (stem-aware, fast GIN index)
    //   • Consonant-skeleton ILIKE for Hebrew vowel-transliteration variants
    //     (shemayim ↔ shamayim ↔ shamaym all share skeleton "shmym"); only
    //     when the skeleton is ≥4 chars so we don't blow up on "ten"→"tn".
    //   • One ILIKE per alias target.
    // NOTE: plain ILIKE on paragraph_text_plain was removed from Tier 1 —
    // on common terms it triggered a 5000-row bitmap heap scan that took
    // 10+ seconds even with the trigram index. Stemming-asymmetry misses
    // (e.g. "Taruwah" → paragraphs containing "Taruwa") fall through to
    // Tier 2 which does a single ILIKE scan with no TSV overhead.
    $ftsMatchConditions = [
        "p.paragraph_tsv @@ $tsqSql",
    ];
    $ftsMatchParams = [
        $tsqParam,
    ];

    $qConsonants = consonantSkel($q);
    if (mb_strlen($qConsonants) >= 4) {
        $ftsMatchConditions[] = "normalize_consonants(p.paragraph_text_plain) ILIKE ?";
        $ftsMatchParams[]     = '%' . str_replace(['%', '_'], ['\%', '\_'], $qConsonants) . '%';
        foreach ($queryWords as $qw) {
            $cs = consonantSkel($qw);
            if (mb_strlen($cs) >= 3) $consonantWordsForHighlight[] = $cs;
        }
    }

    foreach ($aliasTargets as $at) {
        $ftsMatchConditions[] = "normalize_search_text(p.paragraph_text_plain) ILIKE ?";
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
        $allConditions[] = "normalize_search_text(p.paragraph_text_plain) ~* ?";
        $allParams[]     = '\m' . preg_quote(strtolower($qwTrim), '/') . '\M';
    }
}

$ftsWhere = 'WHERE ' . implode(' AND ', $allConditions);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM yy_paragraph p JOIN yy_volume v ON v.volume_key = p.volume_key $ftsWhere");
$countStmt->execute($allParams);
$total = (int)$countStmt->fetchColumn();

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
    // ts_headline tokenizes paragraph_text_plain (which still contains
    // half-rings/curly apostrophes), so a stripped query like "miqraey"
    // never matches "Miqraʿey" and the snippet falls back to the head
    // of the paragraph with no highlight. Switch to the same substring-
    // around-normalized-position approach Tier 2/3 use, and highlight
    // in PHP via highlightSnippet() which walks past strip chars.
    $stmt = $pdo->prepare("
        SELECT v.volume_label AS volume_label,
               v.volume_code AS volume_code,
               v.volume_flip_code AS flip_code,
               v.volume_pdf AS volume_pdf,
               s.series_label AS series_label,
               ch.chapter_name AS chapter_name,
               ch.chapter_number AS chapter_number,
               p.paragraph_page AS page,
               p.paragraph_number AS paragraph_number,
               ts_rank(p.paragraph_tsv, $tsqSql) AS rank,
               CASE WHEN length(p.paragraph_text_plain) > $snippetLen
                    THEN substring(p.paragraph_text_plain
                         FROM greatest(1, position(lower(?) in lower(normalize_search_text(p.paragraph_text_plain))) - $snippetLead)
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

    // Anchor the substring on the first query word (or the stripped query
    // itself if it's a single token). Params: ts_rank, snippet-anchor,
    // WHERE, LIMIT, OFFSET.
    $snippetAnchor = $queryWords[0] ?? $q;
    $snippetAnchor = str_replace($stripChars, '', $snippetAnchor);
    $stmtParams = array_merge([$tsqParam, $snippetAnchor], $allParams, [$limit, $offset]);
    $stmt->execute($stmtParams);
    $results = $stmt->fetchAll();

    // Highlight using the strip-aware matcher so "Miqraʿey" gets wrapped
    // even though the query stripped the half-ring out.
    $highlightWords = array_merge($queryWords, $aliasTargets);
    foreach ($results as &$row) {
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
    // --- TIER 2: ILIKE substring search ---
    $fuzzy = true;
    $likePattern = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $fuzzyConditions = array_merge(["normalize_search_text(p.paragraph_text_plain) ILIKE ?"], $filterConditions);
    $fuzzyWhere = 'WHERE ' . implode(' AND ', $fuzzyConditions);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM yy_paragraph p JOIN yy_volume v ON v.volume_key = p.volume_key $fuzzyWhere");
    $countStmt->execute(array_merge([$likePattern], $filterParams));
    $total = (int)$countStmt->fetchColumn();

    if ($total > 0) {
        $stmt = $pdo->prepare("
            SELECT v.volume_label AS volume_label,
                   v.volume_code AS volume_code,
                   v.volume_flip_code AS flip_code,
                   v.volume_pdf AS volume_pdf,
                   s.series_label AS series_label,
                   ch.chapter_name AS chapter_name,
                   ch.chapter_number AS chapter_number,
                   p.paragraph_page AS page,
                   p.paragraph_number AS paragraph_number,
                   similarity(normalize_search_text(p.paragraph_text_plain), ?) AS rank,
                   CASE WHEN length(p.paragraph_text_plain) > $snippetLen
                        THEN substring(p.paragraph_text_plain FROM greatest(1, position(lower(?) in lower(normalize_search_text(p.paragraph_text_plain))) - $snippetLead) FOR $snippetLen)
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
        // --- TIER 3: Per-word ILIKE fallback (OR logic, uses GiST idx_paragraph_norm_gist) ---
        // word_similarity (%>) never uses GIN/GiST indexes in PG 15, causing full seq scans.
        // Per-word ILIKE on normalize_search_text() DOES use idx_paragraph_norm_gist and is fast.
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
                $simConditions[] = "normalize_search_text(p.paragraph_text_plain) ILIKE ?";
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
            $rankCases = implode(' + ', array_fill(0, count($tier3Words), '(CASE WHEN normalize_search_text(p.paragraph_text_plain) ILIKE ? THEN 1 ELSE 0 END)'));
            $firstWord = $tier3Words[0];
            $stmt = $pdo->prepare("
                SELECT v.volume_label AS volume_label,
                       v.volume_code AS volume_code,
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
                                 FROM greatest(1, position(lower(?) in lower(normalize_search_text(p.paragraph_text_plain))) - $snippetLead)
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
            $stmt->execute(array_merge(
                $simParams,             // CASE WHEN rank conditions
                [count($tier3Words)],   // divisor for rank normalization
                [$firstWord],           // snippet anchor word
                $simParams,             // WHERE conditions
                $filterParams,
                [$limit + 1, $offset]   // fetch one extra to detect more pages
            ));
            $results = $stmt->fetchAll();

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
