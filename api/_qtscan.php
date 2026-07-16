<?php
// TEMP — corpus scan for cross-paragraph "open styled quote" continuations.
// For every chapter, replay the worker's coalesce + segmentParagraph, and find
// each paragraph P that ENDS inside an open styled quote (last segment is a
// styled voice category AND the paragraph has net-open “ over ”). Report the
// carried category and whether the NEXT paragraph Q is:
//   pure-tail  = Q is entirely the quote tail (ends with ” and net-closes)
//   split      = Q closes the quote mid-paragraph then continues (narration after)
//   nostyle-Q  = Q carries its own data-style (own anchor, not a plain continuation)
//   unclosed   = Q does not net-close (would cascade) — the dangerous case
// Delete when done.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';
$db = getDB();

// Styled voice categories that can bear a spanning quotation.
$STYLED = ['kampf','quran','bukhari','muslim','tabari','ishaq','islam',
           'kjv','nas','na','nlt','jps','niv','esv','lv','nt','paul','bible',
           'quote','other'];

$chapters = $db->query("
    SELECT DISTINCT ON (c.chapter_key)
           c.chapter_key, v.volume_code, c.chapter_number, c.chapter_name,
           a.tts_key, a.tts_profile_key
      FROM yy_chapter c
      JOIN yy_volume v ON v.volume_key = c.volume_key
      LEFT JOIN yy_tts_audio a ON a.chapter_key = c.chapter_key
     ORDER BY c.chapter_key, a.tts_audio_key DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pStmt = $db->prepare("SELECT paragraph_number,paragraph_text_html,paragraph_text_plain,
                              paragraph_is_table,paragraph_is_continuation
                         FROM yy_paragraph WHERE chapter_key=? ORDER BY paragraph_number");

$cfgCache = [];
$counts = []; $rowsOut = [];
foreach ($chapters as $c) {
    $tk = (int)($c['tts_key'] ?? 0);
    $pk = $c['tts_profile_key'] !== null ? (int)$c['tts_profile_key'] : 8;
    $ck = $tk . ':' . $pk;
    try {
        if (!isset($cfgCache[$ck])) $cfgCache[$ck] = loadTtsConfig($db, $tk ?: 1, $pk);
        $cfg = $cfgCache[$ck];
    } catch (Throwable $e) { continue; }
    $fonts = $cfg['fonts'] ?? [];

    $pStmt->execute([(int)$c['chapter_key']]);
    $rows = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) continue;

    // Coalesce continuations (worker does this before segmenting).
    $merged = [];
    foreach ($rows as $r) {
        if (!empty($r['paragraph_is_continuation']) && $merged) {
            $h =& $merged[count($merged)-1];
            $h['paragraph_text_html']  = rtrim((string)$h['paragraph_text_html'])  . ' ' . ltrim((string)$r['paragraph_text_html']);
            $h['paragraph_text_plain'] = rtrim((string)$h['paragraph_text_plain']) . ' ' . ltrim((string)$r['paragraph_text_plain']);
            unset($h); continue;
        }
        $merged[] = $r;
    }
    $merged = array_values(array_filter($merged, fn($r) => empty($r['paragraph_is_table'])));

    for ($k = 0; $k + 1 < count($merged); $k++) {
        $P = $merged[$k];
        $pPlain = (string)$P['paragraph_text_plain'];
        // net-open “ over ” at paragraph end?
        $netOpen = substr_count($pPlain, "\u{201C}") - substr_count($pPlain, "\u{201D}");
        if ($netOpen <= 0) continue;
        $carry = ['bold'=>0,'italic'=>0,'paren'=>0,'bibStack'=>[]];
        $pSegs = segmentParagraph(preprocessFontFilter((string)$P['paragraph_text_html'], $fonts), $carry);
        if (!$pSegs) continue;
        $last = end($pSegs);
        $lastCat = $last['category'] ?? '';
        if (!in_array($lastCat, $STYLED, true)) continue;   // ends in narration, not a styled quote

        $Q = $merged[$k+1];
        $qPlain = trim((string)$Q['paragraph_text_plain']);
        $qHtml  = (string)$Q['paragraph_text_html'];
        $qClose = substr_count($qPlain, "\u{201D}") - substr_count($qPlain, "\u{201C}");
        $hasStyle = stripos($qHtml, 'data-style=') !== false;
        $endsClose = ($qPlain !== '' && mb_substr($qPlain,-1) === "\u{201D}");

        if ($hasStyle)                     $kind = 'nostyle-Q(has-own-style)';
        elseif ($qClose <= 0)              $kind = 'unclosed(cascade-risk)';
        elseif ($endsClose)                $kind = 'pure-tail';
        else                               $kind = 'split(tail+narration)';

        $key = $lastCat . ' / ' . $kind;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        $rowsOut[] = sprintf("%-46s ¶%s→¶%s  cat=%-8s %s",
            $c['volume_code'].' c'.$c['chapter_number'],
            $P['paragraph_number'], $Q['paragraph_number'], $lastCat, $kind);
    }
}

echo "===== SUMMARY (category / continuation-kind : count) =====\n";
ksort($counts);
foreach ($counts as $k=>$n) printf("%6d  %s\n", $n, $k);
echo "\n===== DETAIL (" . count($rowsOut) . " pairs) =====\n";
echo implode("\n", $rowsOut) . "\n";
