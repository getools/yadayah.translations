<?php
// TEMP — detect quote-voice BLEED: a styled quote-voice segment whose “/” balance
// returns to 0 (quote closes) with non-trivial text AFTER the close still inside the
// same segment => the styled voice bleeds into narration. Runs the REAL worker
// coalesce + segmentParagraph. Delete when done.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';
$db = getDB();

$STYLED = ['kampf','quran','bukhari','muslim','tabari','ishaq','islam',
           'kjv','nas','na','nlt','jps','niv','esv','lv','nt','paul','bible','quote','other'];

$chapters = $db->query("
    SELECT DISTINCT ON (c.chapter_key)
           c.chapter_key, v.volume_code, c.chapter_number,
           a.tts_key, a.tts_profile_key, a.tts_audio_key, a.tts_audio_status
      FROM yy_chapter c
      JOIN yy_volume v ON v.volume_key = c.volume_key
      LEFT JOIN yy_tts_audio a ON a.chapter_key = c.chapter_key
     ORDER BY c.chapter_key, a.tts_audio_key DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pStmt = $db->prepare("SELECT paragraph_number,paragraph_text_html,paragraph_is_table,paragraph_is_continuation
                         FROM yy_paragraph WHERE chapter_key=? ORDER BY paragraph_number");
$cfgCache = [];
$counts = []; $detail = []; $chapHit = [];

// Does this segment's quote close mid-text with narration following?
function bleedTail(string $t): ?string {
    $chars = mb_str_split($t);
    $bal = 0; $opened = false;
    for ($i=0; $i<count($chars); $i++) {
        $c = $chars[$i];
        if ($c === "\u{201C}" || $c === '"') { $bal++; $opened = true; }
        elseif ($c === "\u{201D}") { if ($bal>0) $bal--; }
        if ($opened && $bal === 0) {
            $rest = trim(implode('', array_slice($chars, $i+1)));
            // Ignore trailing punctuation-only; require real words after the close.
            if (preg_match('/\p{L}{3,}/u', $rest)) return mb_substr($rest,0,70);
            return null;
        }
    }
    return null;
}

foreach ($chapters as $c) {
    $tk = (int)($c['tts_key'] ?? 0);
    $pk = $c['tts_profile_key'] !== null ? (int)$c['tts_profile_key'] : 8;
    $ckk = $tk.':'.$pk;
    try { if (!isset($cfgCache[$ckk])) $cfgCache[$ckk]=loadTtsConfig($db,$tk?:1,$pk); $cfg=$cfgCache[$ckk]; }
    catch (Throwable $e) { continue; }
    $fonts = $cfg['fonts'] ?? [];
    $pStmt->execute([(int)$c['chapter_key']]);
    $rows = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) continue;
    $merged=[];
    foreach ($rows as $r) {
        if (!empty($r['paragraph_is_continuation']) && $merged) {
            $merged[count($merged)-1]['paragraph_text_html'] =
                rtrim($merged[count($merged)-1]['paragraph_text_html']).' '.ltrim((string)$r['paragraph_text_html']);
            continue;
        }
        $merged[] = $r;
    }
    foreach ($merged as $m) {
        if (!empty($m['paragraph_is_table'])) continue;
        $carry = ['bold'=>0,'italic'=>0,'paren'=>0,'bibStack'=>[]];
        $segs = segmentParagraph(preprocessFontFilter((string)$m['paragraph_text_html'], $fonts), $carry);
        foreach ($segs as $s) {
            if (!in_array($s['category'], $STYLED, true)) continue;
            $tail = bleedTail($s['text']);
            if ($tail === null) continue;
            // Does this segment OPEN with a double quote? That is the retag-bleed
            // class the fix targets; anything else is parser-styled narration.
            $lead = mb_substr(ltrim($s['text']), 0, 1);
            $opensQ = ($lead === "\u{201C}" || $lead === '"');
            $bucket = $opensQ ? 'OPENS-QUOTE(target)' : 'starts-narration(parser)';
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
            if (!$opensQ) continue;   // only detail the target class
            $counts['cat:'.$s['category']] = ($counts['cat:'.$s['category']] ?? 0) + 1;
            $chapHit[$c['chapter_key']] = [$c['volume_code'],$c['chapter_number'],(int)$c['tts_audio_key'],$c['tts_audio_status']];
            $detail[] = sprintf("%-44s c%-3s ¶%-6s cat=%-8s close→ %s",
                $c['volume_code'], $c['chapter_number'], $m['paragraph_number'], $s['category'], $tail);
        }
    }
}
echo "===== BLEED COUNT by category =====\n";
ksort($counts); $tot=0;
foreach ($counts as $k=>$n){ printf("%6d  %s\n",$n,$k); $tot+=$n; }
echo "TOTAL bleeding segments: $tot   across ".count($chapHit)." chapters\n\n";
echo "===== chapters to rebuild (code,ch,ak,status) =====\n";
foreach ($chapHit as $ck=>$v) printf("ak%-6s %-44s c%s  %s\n",$v[2],$v[0],$v[1],$v[3]);
echo "\n===== DETAIL =====\n".implode("\n",$detail)."\n";
