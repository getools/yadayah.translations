<?php
// CLI wrapper for buildWhisperWordJoin() — see transcript-helpers.php for
// the full algorithm. Useful for one-off manual rebuilds; the transcript
// worker calls the same helper inline whenever a successful Transcribe
// Audio run leaves both whisper-1-word and youtube populated.
//
// Usage (in the web container):
//   docker exec yada-www-web-1 php /var/www/html/api/build_whisper_word_join.php <feed_item_key> [reference_model] [seg]
//   reference_model defaults to 'youtube'.
//   3rd arg is optional — pass 'seg' (or '1'/'true') to build the 3-feed
//   variant ('whisper-1-word-join-seg'); omit it for the 2-feed join. The
//   actual word/segment whisper engine (OpenAI or GPU) is auto-detected.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php';

$itemKey  = (int)($argv[1] ?? 0);
$refModel = trim($argv[2] ?? 'youtube');
$useSeg   = isset($argv[3]) && in_array(strtolower(trim($argv[3])), ['seg', '1', 'true', 'yes', 'whisper-1-segment', 'gpu-whisper-large-v3'], true);
if (!$itemKey) {
    fwrite(STDERR, "usage: php build_whisper_word_join.php <feed_item_key> [reference_model=youtube] [seg]\n");
    exit(1);
}

$db = getDb();
$count = buildWhisperWordJoin($db, $itemKey, $refModel, $useSeg);
$outModel = $useSeg ? 'whisper-1-word-join-seg' : 'whisper-1-word-join';
if ($count === 0) {
    fwrite(STDERR, "no rows written — source data is missing for item $itemKey (needs a word-level whisper + $refModel" . ($useSeg ? " + a segment-level whisper" : '') . ") or the build failed (check error_log).\n");
    exit(2);
}
echo "wrote $count $outModel rows (ref=$refModel" . ($useSeg ? ", seg=on" : '') . ")\n";
