<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';
$db = getDb();
$cfg = loadTtsConfig($db, 1);
$voice = 'cb-craigwinn';
$row = ['tts_voice_code' => $voice, 'tts_voice_style' => null, 'tts_voice_style_degree' => 1.0,
        'tts_voice_rate_pct' => 0, 'tts_voice_pitch_st' => 0, 'tts_voice_volume' => 100];
foreach (array_keys($cfg['categories']) as $c) $cfg['categories'][$c] = $row;

$text = 'yah OH wah';
$N = (int)($argv[1] ?? 30);
$hashes = [];
$sizes  = [];
$t0 = microtime(true);
echo "Running $N seeds of 'yah OH wah' through Chatterbox ($voice)...\n";
flush();
for ($seed = 0; $seed <= $N; $seed++) {
    $seg = ['provider_key' => 21, 'voice' => $voice, 'text' => $text,
            'phonemes' => null, 'rate' => 0, 'pitch' => 0, 'volume' => 100,
            'style' => '', 'seed' => $seed];
    $err = '';
    $mp3 = localTtsSynthesize($cfg, $seg, 'audio-24khz-96kbitrate-mono-mp3', $err);
    if (!$mp3) { echo "seed=$seed FAIL: $err\n"; flush(); continue; }
    $h = md5($mp3);
    $tag = isset($hashes[$h]) ? "  <-- DUPE of seed=" . $hashes[$h] : '';
    if (!isset($hashes[$h])) $hashes[$h] = $seed;
    $sizes[$seed] = strlen($mp3);
    printf("seed=%3d  size=%5d  md5=%s%s\n", $seed, strlen($mp3), substr($h, 0, 12), $tag);
    flush();
}
$dt = microtime(true) - $t0;
echo "\n=== Summary ===\n";
echo "unique outputs: " . count($hashes) . " / " . ($N+1) . "\n";
echo "elapsed: " . round($dt, 1) . "s\n";
