<?php
/**
 * Shared helpers for yy_feed_page filter building.
 *
 * Wildcard convention for include/exclude filter terms:
 *   term*   → starts with (ILIKE 'term%')
 *   *term   → ends with   (ILIKE '%term')
 *   *term*  → contains    (ILIKE '%term%')
 *   term    → exact match for hashtags (whole-word, comma-bounded);
 *             contains for title text [default]
 *
 * Hashtag whole-word matching: feed_item_tags is a comma-separated list like
 * "#vlog,#Music,#Shabbat". Without this, `#Music` as a filter would falsely
 * match `#MusicVideo` via substring. We require any non-wildcard tag term to
 * sit between commas / string boundaries.
 */

/**
 * Clean a feed item title for display: strip hashtags, emojis, leading/trailing ~ and -, trim whitespace.
 */
function cleanFeedTitle(string $title): string {
    // Strip emojis and symbol characters
    $title = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}\x{2300}-\x{23FF}\x{2B50}-\x{2B55}]/u', '', $title);
    // Strip hashtags
    $title = preg_replace('/#\w+\s*/u', '', $title);
    // Strip leading/trailing ~ - and whitespace
    $title = trim(preg_replace('/^[~\- ]+|[~\- ]+$/', '', trim($title)));
    // Collapse multiple spaces
    $title = preg_replace('/  +/', ' ', $title);
    return $title;
}

function filterLikePattern(string $term): string {
    $hasLeading = str_starts_with($term, '*');
    $hasTrailing = str_ends_with($term, '*');
    $core = trim($term, '*');
    if ($hasLeading && $hasTrailing) return '%' . $core . '%';
    if ($hasTrailing) return $core . '%';
    if ($hasLeading) return '%' . $core;
    return '%' . $core . '%';
}

/**
 * Build a SQL fragment + bind params for matching a single include/exclude
 * term against the comma-separated `feed_item_tags` column. Without a
 * wildcard the match is whole-token (bounded by commas / string ends) so
 * `#Music` doesn't bleed into `#MusicVideo`. With a `*` anywhere, falls
 * back to the existing ILIKE wildcard behavior.
 *
 * Returns [sqlClause, [paramValues...]].
 */
function tagFilterClause(string $col, string $term, bool $negate): array {
    if (str_contains($term, '*')) {
        $pat = filterLikePattern($term);
        if ($negate) return ["($col NOT ILIKE ? OR $col IS NULL)", [$pat]];
        return ["$col ILIKE ?", [$pat]];
    }
    // POSIX regex with comma boundaries; allow whitespace around the comma
    // so legacy tag strings stored as "#a, #b" still match. preg_quote
    // covers Postgres regex metachars too (overlapping set with PHP).
    $rx = '(^|,)\s*' . preg_quote($term, '/') . '\s*(,|$)';
    if ($negate) return ["($col !~* ? OR $col IS NULL)", [$rx]];
    return ["$col ~* ?", [$rx]];
}

function buildFeedPageFilters(string &$where, array &$params, ?string $includeStr, ?string $excludeStr, ?string $orientation = null): void {
    if ($orientation) {
        $where .= " AND feed_item_orientation = ?";
        $params[] = $orientation;
    }
    $include = splitFilterTerms($includeStr);
    if ($include) {
        $clauses = [];
        foreach ($include as $term) {
            // A capturing template filters by its leading hashtag only.
            $term = templateFilterTerm($term);
            // Tags use whole-word matching (or wildcards if the term has *).
            // Title still uses substring match — titles aren't comma-tokenised.
            [$tagSql, $tagParams] = tagFilterClause('feed_item_tags', $term, false);
            $titlePat = filterLikePattern($term);
            $clauses[] = "($tagSql OR COALESCE(feed_item_title_override, feed_item_title_import) ILIKE ?)";
            foreach ($tagParams as $p) $params[] = $p;
            $params[] = $titlePat;
        }
        $where .= " AND (" . implode(' OR ', $clauses) . ")";
    }

    $exclude = splitFilterTerms($excludeStr);
    foreach ($exclude as $term) {
        $term = templateFilterTerm($term);
        [$tagSql, $tagParams] = tagFilterClause('feed_item_tags', $term, true);
        $titlePat = filterLikePattern($term);
        $where .= " AND $tagSql AND COALESCE(feed_item_title_override, feed_item_title_import) NOT ILIKE ?";
        foreach ($tagParams as $p) $params[] = $p;
        $params[] = $titlePat;
    }
}

/**
 * Resolve page_key from page_code.
 */
function getPageKey(PDO $db, string $pageCode): ?int {
    static $cache = [];
    if (isset($cache[$pageCode])) return $cache[$pageCode];
    $stmt = $db->prepare("SELECT page_key FROM yy_page WHERE page_code = ?");
    $stmt->execute([$pageCode]);
    $key = $stmt->fetchColumn();
    $cache[$pageCode] = $key ? (int)$key : null;
    return $cache[$pageCode];
}

/**
 * Normalize a stored media URL to one the browser can resolve from any
 * page path. Feed thumbnails are stored either as absolute URLs
 * (YouTube: https://i.ytimg.com/…) or as web-root-relative paths WITHOUT
 * a leading slash (blog/Facebook images: u/blog/img_….jpg). The latter
 * break when rendered from a sub-path like /test/admin-pages.html or
 * /test/page.php (they resolve to /test/u/blog/…). Prepend a leading
 * slash so they always resolve from the web root.
 */
function normalizeMediaUrl(?string $u): ?string {
    if ($u === null || $u === '') return $u;
    if ($u[0] === '/' || preg_match('#^(https?:)?//#i', $u) || strpos($u, 'data:') === 0) return $u;
    return '/' . $u;
}

/**
 * Append the shared Items-section filter conditions to a query's WHERE.
 * Used by BOTH the public page renderer (page-render.php → resolveItems-
 * Section) and the admin "Selected Titles" typeahead (feed-items-search.php),
 * so the titles a user can pin are exactly the items the section would show.
 *
 * Item rows MUST be aliased `i` in the calling query. Does NOT handle the
 * pinned `feed_item_keys` set (render returns those verbatim; search
 * excludes already-pinned items) — that's the caller's concern.
 *
 * Recognized $cfg keys: feed_keys[], age_min_h, age_max_h, duration_min_sec,
 * duration_max_sec, content_type, orientation, pages[]/page_key+category_key,
 * include_hashtags, exclude_hashtags, title_include, title_exclude.
 */
function appendItemsSectionFilters(array $cfg, string &$where, array &$params): void {
    // Feeds is a SOURCE LIST, not an optional narrowing filter: an Items
    // section draws from the feeds it names, so naming none selects nothing.
    // (Every other field below is a filter, where blank means "unrestricted".)
    // The distinction is between the key being ABSENT and being an EMPTY list:
    //   absent      → caller isn't scoping by feed at all (feed-items-search.php
    //                 omits the param entirely when the editor has no feeds
    //                 checked, so the pin typeahead stays searchable).
    //   empty list  → the section explicitly names zero sources → no items.
    if (array_key_exists('feed_keys', $cfg) && is_array($cfg['feed_keys'])) {
        $ids = array_values(array_filter(array_map('intval', $cfg['feed_keys'])));
        if ($ids) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND i.feed_key IN ($place)";
            array_push($params, ...$ids);
        } else {
            $where .= " AND FALSE";
        }
    }
    $ageMinH = (int)($cfg['age_min_h'] ?? 0);
    if ($ageMinH > 0) {
        $where .= " AND COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) <= NOW() - (? || ' hours')::interval";
        $params[] = (string)$ageMinH;
    }
    $ageMaxH = (int)($cfg['age_max_h'] ?? 0);
    if ($ageMaxH > 0) {
        $where .= " AND COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) >= NOW() - (? || ' hours')::interval";
        $params[] = (string)$ageMaxH;
    }
    if (isset($cfg['duration_min_sec']) && $cfg['duration_min_sec'] !== '' && $cfg['duration_min_sec'] !== null) {
        $where .= " AND i.feed_item_duration_seconds >= ?";
        $params[] = (int)$cfg['duration_min_sec'];
    }
    if (isset($cfg['duration_max_sec']) && $cfg['duration_max_sec'] !== '' && $cfg['duration_max_sec'] !== null) {
        $where .= " AND i.feed_item_duration_seconds <= ?";
        $params[] = (int)$cfg['duration_max_sec'];
    }
    if (!empty($cfg['content_type'])) {
        $where .= " AND i.feed_item_type = ?";
        $params[] = $cfg['content_type'];
    }
    if (!empty($cfg['orientation']) && in_array($cfg['orientation'], ['vertical', 'horizontal'], true)) {
        $where .= " AND i.feed_item_orientation = ?";
        $params[] = $cfg['orientation'];
    }
    // Page/category filters: cfg.pages [{page_key, category_key}], OR legacy
    // single page_key + category_key.
    $pageEntries = [];
    if (!empty($cfg['pages']) && is_array($cfg['pages'])) {
        foreach ($cfg['pages'] as $e) {
            if (!empty($e['page_key'])) {
                $pageEntries[] = ['page_key' => (int)$e['page_key'], 'category_key' => !empty($e['category_key']) ? (int)$e['category_key'] : null];
            }
        }
    } elseif (!empty($cfg['page_key'])) {
        $pageEntries[] = ['page_key' => (int)$cfg['page_key'], 'category_key' => !empty($cfg['category_key']) ? (int)$cfg['category_key'] : null];
    }
    if ($pageEntries) {
        $orParts = [];
        foreach ($pageEntries as $e) {
            if ($e['category_key']) {
                $orParts[] = "EXISTS (SELECT 1 FROM yy_feed_item_page fip JOIN yy_feed_item_category fic ON fic.feed_item_key = fip.feed_item_key WHERE fip.feed_item_key = i.feed_item_key AND fip.page_key = ? AND fic.category_key = ?)";
                $params[] = $e['page_key'];
                $params[] = $e['category_key'];
            } else {
                $orParts[] = "EXISTS (SELECT 1 FROM yy_feed_item_page fip WHERE fip.feed_item_key = i.feed_item_key AND fip.page_key = ?)";
                $params[] = $e['page_key'];
            }
        }
        $where .= " AND (" . implode(' OR ', $orParts) . ")";
    }
    // Hashtag filters (feed_item_tags only). Capturing templates filter by
    // their leading hashtag.
    foreach (splitFilterTerms($cfg['include_hashtags'] ?? '') as $term) {
        [$tagSql, $tagParams] = tagFilterClause('i.feed_item_tags', templateFilterTerm($term), false);
        $where .= " AND $tagSql";
        foreach ($tagParams as $p) $params[] = $p;
    }
    foreach (splitFilterTerms($cfg['exclude_hashtags'] ?? '') as $term) {
        [$tagSql, $tagParams] = tagFilterClause('i.feed_item_tags', templateFilterTerm($term), true);
        $where .= " AND $tagSql";
        foreach ($tagParams as $p) $params[] = $p;
    }
    // Title include/exclude (wildcard convention via filterLikePattern).
    foreach (array_filter(array_map('trim', preg_split('/[,|]/', $cfg['title_include'] ?? ''))) as $term) {
        $where .= " AND COALESCE(i.feed_item_title_override, i.feed_item_title_import) ILIKE ?";
        $params[] = filterLikePattern($term);
    }
    foreach (array_filter(array_map('trim', preg_split('/[,|]/', $cfg['title_exclude'] ?? ''))) as $term) {
        $where .= " AND COALESCE(i.feed_item_title_override, i.feed_item_title_import) NOT ILIKE ?";
        $params[] = filterLikePattern($term);
    }
}

/* ===================================================================== *
 *  Hashtag-template parsing engine
 *  --------------------------------------------------------------------
 *  A filter term can be a *capturing template* like
 *      #family|[category]|[episode]
 *  Authored in an Items section's "Include hashtags" field, a template
 *  does double duty:
 *    • Filter — items whose tags contain the leading hashtag (#family).
 *    • Parse  — on import, the [name] segments are captured into the
 *               feed-item: [category] → yy_feed_page_category /
 *               yy_feed_item_category (page-scoped, auto-created),
 *               [episode]/[date]/[title]/[sort] → feed_item columns.
 *  Literal text matches literally; a bare '*' is a non-capturing
 *  segment wildcard. Each [name] captures one pipe/whitespace-bounded
 *  segment. Field provenance (yy_feed_item_field_source) implements
 *  "most-recent edit wins" between hashtag and admin.
 * ===================================================================== */

/** Normalize a captured category name to a slug (matches the admin tslug()). */
function feedCatSlug(string $t): string {
    return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($t))), '-');
}

/** True if a filter term is a capturing hashtag template (has a [name]). */
function isHashtagTemplate(string $term): bool {
    return strpos($term, '[') !== false && (bool)preg_match('/\[[a-zA-Z][a-zA-Z0-9_:-]*\]/', $term);
}

/**
 * Split an include/exclude field into terms. Identical to the legacy
 * preg_split('/[,|]/') for ordinary terms (comma OR pipe separates), but a
 * template term (one containing '[') keeps its internal pipes intact so
 * '#family|[category]|[episode]' stays a single term instead of three.
 */
function splitFilterTerms(?string $str): array {
    $out = [];
    foreach (explode(',', (string)($str ?? '')) as $piece) {
        $piece = trim($piece);
        if ($piece === '') continue;
        if (strpos($piece, '[') !== false) {       // template: pipes are literal
            $out[] = $piece;
        } else {                                    // legacy: pipe also separates
            foreach (explode('|', $piece) as $sub) {
                $sub = trim($sub);
                if ($sub !== '') $out[] = $sub;
            }
        }
    }
    return $out;
}

/**
 * Reduce a template to the plain leading hashtag used for *filtering*
 * ('#family|[category]|[episode]' → '#family'), since feed_item_tags stores
 * only the bare hashtag (no pipe form). Non-templates pass through unchanged.
 */
function templateFilterTerm(string $term): string {
    if (!isHashtagTemplate($term)) return $term;
    $cut = preg_split('/[|\[]/', $term)[0];
    $cut = trim($cut, '* ');
    return $cut !== '' ? $cut : $term;
}

/**
 * Compile a template into a case-insensitive PCRE plus the ordered list of
 * captured field names. Returns null if the template has no [placeholders].
 *
 * Two kinds of bracketed token are recognized:
 *   • [name]  (name starts with a letter) — a *capturing* placeholder that
 *     matches one pipe/whitespace-bounded segment and writes it to that field.
 *   • [sep]   (bracket wrapping only separator/punctuation chars, e.g. '[|]')
 *     — an *optional marker*: it emits its literal contents AND makes the
 *     remainder of the template optional. Markers nest, so trailing segments
 *     become progressively optional. This lets ONE template match a hashtag
 *     with or without its trailing pieces.
 *
 *   '#vlog|[category]|[episode]'        (strict — both segments required)
 *     → ['regex' => '/\#vlog\|([^|\s#]+)\|([^|\s#]+)/iu',
 *        'fields' => ['category','episode']]
 *   '#vlog[|][category][|][episode]'    (optional — category/episode optional)
 *     → ['regex' => '/\#vlog(?:\|([^|\s#]+)(?:\|([^|\s#]+))?)?(?=$|[\s#|])/iu',
 *        'fields' => ['category','episode']]
 *   matches '#vlog', '#vlog|qatsyr', '#vlog|muhammad|004'.
 */
function compileHashtagTemplate(string $tpl): ?array {
    // Split on any bracketed token; classify each part below.
    $parts = preg_split('/(\[[^\]]*\])/', $tpl, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (count($parts) < 2) return null;
    $fields = [];
    $rx = '';
    $openOptional = 0;                              // nested (?:…)? groups still open
    $litToRx = function ($lit) {                    // '*' → non-capturing wildcard
        $esc = array_map(function ($c) { return preg_quote($c, '/'); }, explode('*', $lit));
        return implode('[^|\s]*', $esc);
    };
    foreach ($parts as $p) {
        if ($p === '') continue;
        if (preg_match('/^\[([a-zA-Z][a-zA-Z0-9_:-]*)\]$/', $p, $m)) {
            $fields[] = strtolower($m[1]);
            $rx .= '([^|\s#]+)';                     // one captured segment
        } elseif (preg_match('/^\[([|\/.,;:_\-\s]+)\]$/', $p, $m)) {
            // Optional marker: open a nested optional group that runs to the end
            // of the template, emitting the marker's literal separator(s).
            $rx .= '(?:' . $litToRx($m[1]);
            $openOptional++;
        } else {
            // Any other chunk (plain literal, or a bracketed token that is
            // neither a field nor a separator) is matched literally.
            $rx .= $litToRx($p);
        }
    }
    if (!$fields) return null;
    $rx .= str_repeat(')?', $openOptional);         // close optional groups
    // With optional trailing segments the bare literal prefix can match inside
    // a longer word (e.g. '#vlog' inside '#vlogging'); require a segment
    // boundary after the match so it can't.
    if ($openOptional > 0) $rx .= '(?=$|[\s#|])';
    return ['regex' => '/' . $rx . '/iu', 'fields' => $fields];
}

/**
 * Collect the active hashtag parse rules: every Items section's include-
 * hashtag templates, paired with that section's page_key (the feed-page its
 * categories live on). Seeded with the original hardcoded vlog(1)/basics(20)
 * rules so existing behavior is preserved even before any section declares
 * them. Cached per request.
 *   → [ ['template'=>..., 'page_key'=>int, 'regex'=>..., 'fields'=>[...]], ... ]
 */
function getHashtagParseRules(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rules = [];
    $seen = [];
    // page_key is OPTIONAL: a template with no scope still captures item-level
    // fields ([episode]/[date]/[title]/[sort]); only [category] needs a page.
    $add = function ($tpl, $pageKey, $sectionKey = 0) use (&$rules, &$seen) {
        $tpl = trim((string)$tpl);
        $pageKey = (int)$pageKey;
        $sectionKey = (int)$sectionKey;
        if ($tpl === '') return;
        $compiled = compileHashtagTemplate($tpl);
        if (!$compiled) return;
        $k = $pageKey . '|' . $sectionKey . '|' . strtolower($tpl);
        if (isset($seen[$k])) return;
        $seen[$k] = true;
        $rules[] = ['template' => $tpl, 'page_key' => $pageKey, 'section_key' => $sectionKey,
                    'regex' => $compiled['regex'], 'fields' => $compiled['fields']];
    };
    // Back-compat defaults (Vlog=1, Basics=20). Kept in the strict form these
    // pages have always used; a section can opt into the optional-marker form
    // ('#vlog[|][category][|][episode]') via its own include-hashtags field.
    $add('#vlog|[category]|[episode]', 1);
    $add('#basics|[category]|[episode]', 20);
    // Data-driven rules from active Items sections (yy_section). Category scope
    // = the section's page_key column, else cfg.page_key, else cfg.pages[] (all
    // page-scoped, yy_feed_page_category). Independently, the section_key scopes
    // the Pages-New page-code model: a captured [category] is also filed under
    // this section's own yy_category set via yy_section_item.
    try {
        $st = $db->query("SELECT section_key, page_key, section_config FROM yy_section WHERE section_type = 'items' AND section_active_flag = TRUE");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cfgRaw = $row['section_config'];
            $cfg = is_string($cfgRaw) ? json_decode($cfgRaw, true) : $cfgRaw;
            if (!is_array($cfg)) continue;
            $sectionKey = (int)$row['section_key'];
            $pageKeys = [];
            if (!empty($row['page_key'])) $pageKeys[] = (int)$row['page_key'];
            if (!empty($cfg['page_key'])) $pageKeys[] = (int)$cfg['page_key'];
            if (!empty($cfg['pages']) && is_array($cfg['pages'])) {
                foreach ($cfg['pages'] as $e) if (!empty($e['page_key'])) $pageKeys[] = (int)$e['page_key'];
            }
            $pageKeys = array_values(array_unique($pageKeys));
            if (!$pageKeys) $pageKeys = [0];   // scopeless page: still does section-scoped category
            foreach (splitFilterTerms($cfg['include_hashtags'] ?? '') as $term) {
                if (!isHashtagTemplate($term)) continue;
                foreach ($pageKeys as $pk) $add($term, $pk, $sectionKey);
            }
        }
    } catch (\Throwable $e) {
        // yy_section may be absent in some envs; defaults still apply.
    }
    $cache = $rules;
    return $rules;
}

/** Look up a category by (page_key, slug), creating it from the slug if absent. */
function resolveOrCreateFeedCategory(PDO $db, int $pageKey, string $slug): ?int {
    if ($slug === '') return null;
    $lk = $db->prepare("SELECT category_key FROM yy_feed_page_category WHERE page_key = ? AND category_slug = ?");
    $lk->execute([$pageKey, $slug]);
    $k = $lk->fetchColumn();
    if ($k) return (int)$k;
    $title = ucwords(str_replace('-', ' ', $slug));
    $ins = $db->prepare("INSERT INTO yy_feed_page_category (page_key, category_title, category_slug, category_sort) VALUES (?, ?, ?, 0) ON CONFLICT (page_key, category_slug) DO UPDATE SET category_revision_dtime = NOW() RETURNING category_key");
    $ins->execute([$pageKey, $title, $slug]);
    return (int)$ins->fetchColumn();
}

/**
 * Look up a Pages-New *section* category by (section_key, slug), creating it
 * from the slug if absent. These live in yy_category (the page-builder's
 * per-section set, keyed by section_key) and link to feed items via
 * yy_section_item.category_key — the mechanism behind the page-code model:
 * a hashtag whose leading token is a page code (#vlog → page_code 'vlog')
 * files the item under that page's Items-section categories.
 * yy_category has no (section_key,slug) unique index, so guard with a lookup.
 */
function resolveOrCreateSectionCategory(PDO $db, int $sectionKey, string $slug): ?int {
    if (!$sectionKey || $slug === '') return null;
    $lk = $db->prepare("SELECT category_key FROM yy_category WHERE section_key = ? AND category_slug = ?");
    $lk->execute([$sectionKey, $slug]);
    $k = $lk->fetchColumn();
    if ($k) return (int)$k;
    $title = ucwords(str_replace('-', ' ', $slug));
    $ins = $db->prepare("INSERT INTO yy_category (section_key, category_title, category_slug, category_sort) VALUES (?, ?, ?, 0) RETURNING category_key");
    $ins->execute([$sectionKey, $title, $slug]);
    return (int)$ins->fetchColumn();
}

/**
 * Run every parse rule over an item's text (title + description). Returns:
 *   ['fields'            => ['episode'=>'42','date'=>'…',…],  // non-category, first match wins
 *    'categories'        => [['category_key'=>N,'episode'=>'42','page_key'=>P], …],   // page-scoped (yy_feed_page_category)
 *    'section_categories'=> [['category_key'=>N,'episode'=>'42','section_key'=>S], …]] // Pages-New section-scoped (yy_category)
 * Page categories are resolved/auto-created per page_key; section categories
 * per section_key (the page-code model — see resolveOrCreateSectionCategory).
 */
function applyHashtagTemplates(PDO $db, string $searchText, array $rules): array {
    $fields = [];
    $categories = [];
    $sectionCategories = [];
    $seenCat = [];
    $seenSecCat = [];
    foreach ($rules as $rule) {
        if (!preg_match_all($rule['regex'], $searchText, $matches, PREG_SET_ORDER)) continue;
        foreach ($matches as $mm) {
            $cap = [];
            foreach ($rule['fields'] as $i => $fname) $cap[$fname] = $mm[$i + 1] ?? null;
            $episode = isset($cap['episode']) && $cap['episode'] !== '' ? $cap['episode'] : null;
            foreach ($cap as $fname => $val) {
                if ($fname === 'category' || $val === null || $val === '') continue;
                if (!array_key_exists($fname, $fields)) $fields[$fname] = $val;
            }
            if (!empty($cap['category'])) {
                $slug = feedCatSlug($cap['category']);
                if ($slug !== '') {
                    // Page-scoped assignment (production feed pages, yy_feed_page_category).
                    if (!empty($rule['page_key'])) {
                        $dedupe = $rule['page_key'] . ':' . $slug;
                        if (!isset($seenCat[$dedupe])) {
                            $seenCat[$dedupe] = true;
                            $catKey = resolveOrCreateFeedCategory($db, (int)$rule['page_key'], $slug);
                            if ($catKey) $categories[] = ['category_key' => $catKey, 'episode' => $episode, 'page_key' => (int)$rule['page_key']];
                        }
                    }
                    // Section-scoped assignment (Pages-New page-code model, yy_category).
                    if (!empty($rule['section_key'])) {
                        $sdedupe = $rule['section_key'] . ':' . $slug;
                        if (!isset($seenSecCat[$sdedupe])) {
                            $seenSecCat[$sdedupe] = true;
                            $scKey = resolveOrCreateSectionCategory($db, (int)$rule['section_key'], $slug);
                            if ($scKey) $sectionCategories[] = ['category_key' => $scKey, 'episode' => $episode, 'section_key' => (int)$rule['section_key']];
                        }
                    }
                }
            }
        }
    }
    return ['fields' => $fields, 'categories' => $categories, 'section_categories' => $sectionCategories];
}

/**
 * Decide whether a hashtag-captured value should overwrite a field, honoring
 * "most-recent edit wins". Provenance lives in yy_feed_item_field_source:
 * hashtag_dtime is bumped only when the captured value actually CHANGES (so a
 * constantly-running sync never steamrolls a manual edit); manual_dtime is set
 * by the admin edit endpoints via stampManualField(). Returns true if the
 * caller should apply $captured. Always keeps provenance current.
 */
function reconcileHashtagField(PDO $db, int $itemKey, string $fieldName, ?string $captured): bool {
    if (!$itemKey || $captured === null || $captured === '') return false;
    $st = $db->prepare("SELECT manual_dtime, hashtag_value, hashtag_dtime FROM yy_feed_item_field_source WHERE feed_item_key = ? AND field_name = ?");
    $st->execute([$itemKey, $fieldName]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $valueChanged = !$row || ((string)$row['hashtag_value'] !== $captured);
    if ($valueChanged) {
        // The parsed value just changed → hashtag is the most-recent edit.
        $db->prepare("INSERT INTO yy_feed_item_field_source (feed_item_key, field_name, hashtag_value, hashtag_dtime)
                      VALUES (?, ?, ?, NOW())
                      ON CONFLICT (feed_item_key, field_name) DO UPDATE SET hashtag_value = EXCLUDED.hashtag_value, hashtag_dtime = NOW()")
           ->execute([$itemKey, $fieldName, $captured]);
        return true;
    }
    // Unchanged since last sync: hashtag wins only if no manual edit is newer
    // than the last time the hashtag value changed.
    if (empty($row['manual_dtime']))  return true;
    if (empty($row['hashtag_dtime'])) return false;
    return strcmp((string)$row['hashtag_dtime'], (string)$row['manual_dtime']) >= 0;
}

/**
 * Record that an admin manually set a field, for most-recent-wins arbitration
 * against hashtag parsing. Called by the item-edit endpoints. fieldName is a
 * feed_item field ('episode','title','date','sort') or 'category:<page_key>'.
 */
function stampManualField(PDO $db, int $itemKey, string $fieldName): void {
    if (!$itemKey) return;
    $db->prepare("INSERT INTO yy_feed_item_field_source (feed_item_key, field_name, manual_dtime)
                  VALUES (?, ?, NOW())
                  ON CONFLICT (feed_item_key, field_name) DO UPDATE SET manual_dtime = NOW()")
       ->execute([$itemKey, $fieldName]);
}

/**
 * Apply parsed captures + category assignments to a freshly-upserted item,
 * arbitrating each field via reconcileHashtagField. Shared by every sync
 * source so YouTube/Rumble/etc. behave identically. $parse is the return of
 * applyHashtagTemplates().
 */
function applyParsedItemFields(PDO $db, int $itemKey, array $parse): void {
    if (!$itemKey) return;
    $captures = $parse['fields'] ?? [];
    $colMap = [
        'episode' => 'feed_item_episode',
        'date'    => 'feed_item_publish_override_dtime',
        'title'   => 'feed_item_title_override',
        'sort'    => 'feed_item_sort',
    ];
    foreach ($colMap as $fname => $col) {
        if (!array_key_exists($fname, $captures)) continue;
        $val = $captures[$fname];
        if ($fname === 'date') { $ts = strtotime((string)$val); $val = $ts ? date('Y-m-d H:i:s', $ts) : null; }
        if ($fname === 'sort') { $val = is_numeric($val) ? (string)(int)$val : null; }
        if ($val === null || $val === '') continue;
        if (reconcileHashtagField($db, $itemKey, $fname, (string)$val)) {
            $db->prepare("UPDATE yy_feed_item SET $col = ?, feed_item_revision_dtime = NOW() WHERE feed_item_key = ?")
               ->execute([$val, $itemKey]);
        }
    }
    // Category assignments, grouped + arbitrated per page.
    $byPage = [];
    foreach ($parse['categories'] ?? [] as $ca) $byPage[(int)$ca['page_key']][] = $ca;
    foreach ($byPage as $pk => $assigns) {
        $sigParts = [];
        foreach ($assigns as $a) $sigParts[] = $a['category_key'] . ':' . ($a['episode'] ?? '');
        sort($sigParts);
        if (!reconcileHashtagField($db, $itemKey, 'category:' . $pk, implode(',', $sigParts))) continue;
        // Replace only this page's rows (other pages' admin assignments untouched).
        $db->prepare("DELETE FROM yy_feed_item_category WHERE feed_item_key = ? AND category_key IN (SELECT category_key FROM yy_feed_page_category WHERE page_key = ?)")
           ->execute([$itemKey, $pk]);
        $catUp = $db->prepare("INSERT INTO yy_feed_item_category (feed_item_key, category_key, feed_item_category_episode) VALUES (?, ?, ?) ON CONFLICT (feed_item_key, category_key) DO UPDATE SET feed_item_category_episode = EXCLUDED.feed_item_category_episode");
        foreach ($assigns as $a) {
            $catUp->execute([$itemKey, $a['category_key'], $a['episode'] !== null ? (string)$a['episode'] : null]);
        }
    }
    // Pages-New section-scoped category links (page-code model). One row per
    // (section, item) in yy_section_item; the item's category for that section.
    // Provenance-arbitrated per section so a constantly-running sync never
    // steamrolls an admin's manual category pick in the page-builder editor
    // (which stamps 'section_category:<section_key>' via stampManualField).
    foreach ($parse['section_categories'] ?? [] as $sc) {
        $sk = (int)($sc['section_key'] ?? 0);
        $ck = (int)($sc['category_key'] ?? 0);
        if (!$sk || !$ck) continue;
        if (!reconcileHashtagField($db, $itemKey, 'section_category:' . $sk, (string)$ck)) continue;
        $db->prepare("INSERT INTO yy_section_item (section_key, feed_item_key, category_key)
                      VALUES (?, ?, ?)
                      ON CONFLICT (section_key, feed_item_key) DO UPDATE SET category_key = EXCLUDED.category_key")
           ->execute([$sk, $itemKey, $ck]);
    }
}
