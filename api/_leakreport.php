<?php
// TEMP — definitive paren-leak report. Delete when done.
//
// Instead of guessing from punctuation, this replays the ACTUAL pre-fix
// pipeline (coalesce → font-filter → segmentParagraph with an UNBOUNDED carry)
// over every chapter and finds each point where the paren depth goes 0 -> >0
// and does NOT come back to 0 in the very next paragraph. That is a real leak:
// a page-break cut resolves immediately (the tail closes the definition), a
// source typo does not — it silences everything downstream.
//
// Reports the culprit paragraph and how many following paragraphs it silences.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';
$db = getDB();

$chapters = $db->query("
    SELECT DISTINCT ON (c.chapter_key)
           c.chapter_key, s.series_number, v.volume_number, v.volume_label,
           c.chapter_number, c.chapter_name, a.tts_key, a.tts_profile_key
      FROM yy_chapter c
      JOIN yy_volume v ON v.volume_key = c.volume_key
      JOIN yy_series s ON s.series_key = v.series_key
      LEFT JOIN yy_tts_audio a ON a.chapter_key = c.chapter_key
     ORDER BY c.chapter_key, a.tts_audio_key DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pStmt = $db->prepare("SELECT paragraph_number,paragraph_page,paragraph_text_html,paragraph_text_plain,
                              paragraph_is_table,paragraph_is_continuation
                         FROM yy_paragraph WHERE chapter_key=? ORDER BY paragraph_number");

$cfgCache = [];
$defaultCfg = null;
$out = [];

foreach ($chapters as $c) {
    $tk = (int)($c['tts_key'] ?? 0);
    $pk = $c['tts_profile_key'] !== null ? (int)$c['tts_profile_key'] : null;
    $ck = $tk . ':' . ($pk ?? '-');
    try {
        if (!isset($cfgCache[$ck])) $cfgCache[$ck] = loadTtsConfig($db, $tk ?: 1, $pk ?? 8);
        $cfg = $cfgCache[$ck];
    } catch (Throwable $e) { continue; }
    $fonts = $cfg['fonts'] ?? [];

    $pStmt->execute([(int)$c['chapter_key']]);
    $rows = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) continue;

    $merged = [];
    foreach ($rows as $r) {
        if (!empty($r['paragraph_is_continuation']) && $merged) {
            $h =& $merged[count($merged) - 1];
            $h['paragraph_text_html']  = rtrim((string)$h['paragraph_text_html'])  . ' ' . ltrim((string)$r['paragraph_text_html']);
            $h['paragraph_text_plain'] = rtrim((string)$h['paragraph_text_plain']) . ' ' . ltrim((string)$r['paragraph_text_plain']);
            unset($h); continue;
        }
        $merged[] = $r;
    }
    $merged = array_values(array_filter($merged, fn($r) => empty($r['paragraph_is_table'])));

    // Unbounded carry, exactly as the pre-fix pipeline ran.
    $carry = [];
    $depthAfter = [];   // index -> paren depth leaving that paragraph
    foreach ($merged as $i => $r) {
        $html = preprocessFontFilter((string)$r['paragraph_text_html'], $fonts);
        segmentParagraph($html, $carry);
        $depthAfter[$i] = (int)($carry['paren'] ?? 0);
    }

    $n = count($merged);
    for ($i = 0; $i < $n; $i++) {
        $before = $i === 0 ? 0 : $depthAfter[$i - 1];
        if ($before !== 0 || $depthAfter[$i] <= 0) continue;   // no new leak here
        // Resolved by the very next paragraph? -> genuine page-break cut, fine.
        if ($i + 1 < $n && $depthAfter[$i + 1] === 0) continue;
        // Real leak. How many following paragraphs stay stuck at depth > 0?
        $silenced = 0;
        for ($j = $i + 1; $j < $n && $depthAfter[$j] > 0; $j++) $silenced++;
        $plain = (string)$merged[$i]['paragraph_text_plain'];
        $out[] = [
            'series' => (int)$c['series_number'], 'volume' => (int)$c['volume_number'],
            'vlabel' => $c['volume_label'], 'chapter' => (int)$c['chapter_number'],
            'cname' => $c['chapter_name'],
            'para' => (int)$merged[$i]['paragraph_number'],
            'page' => $merged[$i]['paragraph_page'],
            'depth' => $depthAfter[$i],
            'silenced' => $silenced,
            'opens' => substr_count($plain, '('), 'closes' => substr_count($plain, ')'),
            'text' => preg_replace('/\s+/u', ' ', $plain),
        ];
    }
}

usort($out, fn($a, $b) => $b['silenced'] <=> $a['silenced']);

$mode = $argv[1] ?? 'summary';
if ($mode === 'json') { echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit; }

printf("REAL LEAKS (unclosed paren that silences downstream paragraphs): %d\n", count($out));
printf("total paragraphs silenced: %d\n\n", array_sum(array_column($out, 'silenced')));
$byVol = [];
foreach ($out as $r) {
    $k = sprintf('s%02dv%02d', $r['series'], $r['volume']);
    $byVol[$k]['n'] = ($byVol[$k]['n'] ?? 0) + 1;
    $byVol[$k]['s'] = ($byVol[$k]['s'] ?? 0) + $r['silenced'];
}
ksort($byVol);
foreach ($byVol as $k => $v) printf("  %-8s %2d leak(s), %4d paragraphs silenced\n", $k, $v['n'], $v['s']);
echo "\nTop offenders:\n";
foreach (array_slice($out, 0, 15) as $r) {
    printf("  s%02dv%02d ch%-2d p.%-4s ¶%-5d depth=+%d  (%d open/%d close)  silences %d\n",
        $r['series'], $r['volume'], $r['chapter'], $r['page'], $r['para'],
        $r['depth'], $r['opens'], $r['closes'], $r['silenced']);
}
