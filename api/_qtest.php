<?php
// TEMP diagnostic — how does the CURRENT pipeline segment a given paragraph
// range, and does the kampf continuation override fire? Delete when done.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';
$db = getDB();

$vcode = 'YY-s07v03-God-Damn-Religion-Submission';
$nums  = [4050, 4051, 4052, 4053];

$cfg = loadTtsConfig($db, 1, 8);
$fonts = $cfg['fonts'] ?? [];

$st = $db->prepare("SELECT p.paragraph_number, p.paragraph_text_html, p.paragraph_text_plain
                      FROM yy_paragraph p JOIN yy_volume v ON v.volume_key=p.volume_key
                     WHERE v.volume_code=? AND p.paragraph_number = ANY(?)
                     ORDER BY p.paragraph_number");
$st->execute([$vcode, '{' . implode(',', $nums) . '}']);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$carry = ['bold'=>0,'italic'=>0,'paren'=>0,'bibStack'=>[]];
foreach ($rows as $r) {
    $html = preprocessFontFilter((string)$r['paragraph_text_html'], $fonts);
    $carryIn = $carry;
    $segs = segmentParagraph($html, $carry);
    ttsBoundCarryAtParagraphEnd((string)$r['paragraph_text_html'], $carry);
    echo "==== ¶{$r['paragraph_number']}  carryIn=" . json_encode($carryIn) . "  carryOut=" . json_encode($carry) . "\n";
    foreach ($segs as $s) {
        printf("   [%-16s] %s\n", $s['category'], mb_substr($s['text'], 0, 90));
    }
}
