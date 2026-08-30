<?php
// One-off: queue all 35 chapters of volume 34 (In the Company of Good and Evil)
// for TTS with EVERY category forced to cb-yy-KenPower (YY-Ken Power).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$VOLUME_KEY = 34;
$TTS_KEY    = 1;
$PROFILE    = 8;
$VOICE      = 'cb-yy-KenPower';
$dryRun     = in_array('--dry-run', $argv, true);

$db  = getDb();
$cfg = loadTtsConfig($db, $TTS_KEY, $PROFILE);
if (!$cfg['system']) { fwrite(STDERR, "unknown tts_key\n"); exit(1); }
$profileKey   = (int)$cfg['profile_key'];
$outputFormat = $cfg['system']['tts_output_format'];

// Snapshot every category, but force the voice to the single target voice so
// no quote/scripture/aside paragraph slips to a different speaker.
$catSnapshot = [];
$changed = 0;
foreach ($cfg['categories'] as $cat => $row) {
    $orig = $row['tts_voice_code'];
    if ($orig !== $VOICE) $changed++;
    $catSnapshot[$cat] = [
        'voice_code'   => $VOICE,
        'style'        => $row['tts_voice_style'],
        'style_degree' => (float)$row['tts_voice_style_degree'],
        'rate_pct'     => (int)$row['tts_voice_rate_pct'],
        'pitch_st'     => (float)$row['tts_voice_pitch_st'],
        'volume'       => (int)$row['tts_voice_volume'],
        'read_flag'    => array_key_exists('tts_category_voice_read_flag', $row) ? (bool)$row['tts_category_voice_read_flag'] : true,
    ];
}
printf("profile_key=%d  categories=%d (voice rewritten on %d)  format=%s\n",
       $profileKey, count($catSnapshot), $changed, $outputFormat);
$off = array_keys(array_filter($catSnapshot, fn($c) => !$c['read_flag']));
printf("read_flag=false (left OFF): %s\n", $off ? implode(', ', $off) : '(none)');

$settings = [
    'output_format' => $outputFormat,
    'region'        => $cfg['system']['tts_region'] ?? null,
    'categories'    => $catSnapshot,
    'tts_code'      => $cfg['system']['tts_code'],
    'profile_key'   => $profileKey,
];
$settingsJson = json_encode($settings);

$chapters = $db->query("SELECT chapter_key, chapter_sort, chapter_name FROM yy_chapter WHERE volume_key = $VOLUME_KEY ORDER BY chapter_sort")->fetchAll();
printf("chapters to queue: %d%s\n", count($chapters), $dryRun ? '  [DRY RUN]' : '');
if ($dryRun) exit(0);

$ins = $db->prepare("
    INSERT INTO yy_tts_audio
        (tts_key, volume_key, chapter_key, tts_profile_key, tts_audio_status, tts_audio_progress,
         tts_audio_message, tts_audio_settings, tts_audio_started_dtime)
    VALUES (?, ?, ?, ?, 'pending', 0, 'Queued', ?::jsonb, NOW())
    ON CONFLICT (tts_key, volume_key, chapter_key, tts_profile_key) WHERE chapter_key IS NOT NULL
    DO UPDATE SET
        tts_audio_status            = 'pending',
        tts_audio_progress          = 0,
        tts_audio_message           = 'Queued',
        tts_audio_error             = NULL,
        tts_audio_settings          = EXCLUDED.tts_audio_settings,
        tts_audio_started_dtime     = NOW(),
        tts_audio_completed_dtime   = NULL,
        tts_audio_worker_pid        = NULL,
        tts_audio_failed_paragraphs = NULL,
        tts_audio_revision_dtime    = NOW()
    RETURNING tts_audio_key
");
foreach ($chapters as $ch) {
    $ins->execute([$TTS_KEY, $VOLUME_KEY, (int)$ch['chapter_key'], $profileKey, $settingsJson]);
    printf("  sort=%-4d %-34s -> tts_audio_key %d\n", $ch['chapter_sort'], substr($ch['chapter_name'],0,34), (int)$ins->fetchColumn());
}
echo "done\n";
