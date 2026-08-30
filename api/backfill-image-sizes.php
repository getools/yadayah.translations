<?php
/**
 * Backfill the icon/sm/md/lg size set for images uploaded before the set
 * existed. CLI only — it walks directories and writes files, so it must never
 * be reachable over HTTP.
 *
 *   php api/backfill-image-sizes.php --dry                 # report, write nothing
 *   php api/backfill-image-sizes.php                       # all default dirs
 *   php api/backfill-image-sizes.php u/blog                # one dir (relative to docroot)
 *
 * Source preference is originals/<name> when it exists, else the stored file
 * itself. Idempotent: a variant that already exists and is newer than its
 * source is left alone, so re-running is cheap.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$DOCROOT = __DIR__ . '/..';

// Dirs owned by an uploader that now builds the size set. Legacy content dirs
// (u/blog, u/fb, u/invite, u/messages) are NOT here — nothing writes them
// through the shared helpers, so pass them explicitly if you want them.
$DEFAULT_DIRS = [
    'u/covers',
    'u/logo',
    'u/resources',
    'u/timeline',
    'u/community/posts',
    'u/avatars',
    'u/backgrounds',
    'u/10-7-memorial',
    'images',                 // glossary letter plates — filtered to letter-* below
];

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry', $args, true);
$dirs   = array_values(array_filter($args, fn($a) => strpos($a, '--') !== 0));
if (!$dirs) $dirs = $DEFAULT_DIRS;

$RASTER = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$VARIANT_RE = '/-(' . implode('|', array_keys(IMG_SIZE_SET)) . ')\.[A-Za-z0-9]+$/';

$totalMade = 0; $totalSkip = 0; $totalFail = 0; $bytesBefore = 0; $bytesAfter = 0;

foreach ($dirs as $rel) {
    $dir = rtrim($DOCROOT . '/' . trim($rel, '/'), '/');
    if (!is_dir($dir)) { printf("%-22s MISSING\n", $rel); continue; }

    $origDir = $dir . '/originals';
    $made = 0; $skipped = 0; $failed = 0; $before = 0; $after = 0;

    foreach (scandir($dir) ?: [] as $fname) {
        if ($fname === '.' || $fname === '..') continue;
        $path = $dir . '/' . $fname;
        if (!is_file($path)) continue;

        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        if (!in_array($ext, $RASTER, true)) continue;
        if (preg_match($VARIANT_RE, $fname)) continue;              // already a variant
        if ($rel === 'images' && strpos($fname, 'letter-') !== 0) continue;

        // Prefer the untouched original as the source.
        $src = (is_dir($origDir) && is_file($origDir . '/' . $fname))
            ? $origDir . '/' . $fname
            : $path;

        // Idempotence: every variant present and no older than the source.
        $srcMtime = filemtime($src);
        $complete = true;
        foreach (array_keys(IMG_SIZE_SET) as $size) {
            $v = $dir . '/' . imgVariantName($fname, $size);
            if (!is_file($v) || filemtime($v) < $srcMtime) { $complete = false; break; }
        }
        if ($complete) { $skipped++; continue; }

        if ($dryRun) { $made++; continue; }

        $before += disk_usage_of($dir, $fname);
        $result = makeImageSizes($src, $dir, $fname);
        $after  += disk_usage_of($dir, $fname);

        if (count($result) === count(IMG_SIZE_SET)) $made++;
        else { $failed++; fwrite(STDERR, "  partial/failed: $rel/$fname (" . count($result) . " of " . count(IMG_SIZE_SET) . ")\n"); }
    }

    $totalMade += $made; $totalSkip += $skipped; $totalFail += $failed;
    $bytesBefore += $before; $bytesAfter += $after;
    printf("%-22s built %4d   up-to-date %4d   failed %3d   +%s\n",
        $rel, $made, $skipped, $failed, human($after - $before));
}

printf("\n%s: %d built, %d already current, %d failed, +%s on disk\n",
    $dryRun ? 'DRY RUN' : 'DONE', $totalMade, $totalSkip, $totalFail, human($bytesAfter - $bytesBefore));

/** Bytes used by a base file's variants (hardlinks counted once by the fs). */
function disk_usage_of(string $dir, string $fname): int {
    $n = 0;
    foreach (array_keys(IMG_SIZE_SET) as $size) {
        $p = $dir . '/' . imgVariantName($fname, $size);
        if (is_file($p)) $n += (int)filesize($p);
    }
    return $n;
}

function human(int $b): string {
    if ($b < 1024) return $b . 'B';
    if ($b < 1048576) return round($b / 1024) . 'K';
    if ($b < 1073741824) return round($b / 1048576, 1) . 'M';
    return round($b / 1073741824, 2) . 'G';
}
