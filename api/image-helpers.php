<?php
/**
 * Shared image upload helpers.
 * All admin image uploads save an original + a scaled copy.
 * Scaled dimensions come from yy_setting (page/chat: image-max-width, image-max-height).
 *
 * Usage:
 *   require_once __DIR__ . '/image-helpers.php';
 *   $result = processImageUpload($db, $_FILES['file'], $destDir, $namePrefix);
 *   // $result = ['original' => '/u/.../originals/file.jpg', 'scaled' => '/u/.../file.jpg', 'filename' => 'file.jpg']
 *
 *   rescaleAllImages($db, $destDir);
 *   // Regenerates all scaled copies from originals in $destDir/originals/
 */

function getImageMaxDimensions(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $stmt = $db->query("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'page' AND setting_group_code = 'chat' AND setting_code IN ('image-max-width', 'image-max-height')");
        $cfg = [];
        foreach ($stmt->fetchAll() as $r) $cfg[$r['setting_code']] = $r['setting_value'];
    } catch (Exception $e) { $cfg = []; }
    $cache = [
        'width'  => max(100, min(4000, (int)($cfg['image-max-width'] ?? 800))),
        'height' => max(100, min(4000, (int)($cfg['image-max-height'] ?? 600))),
    ];
    return $cache;
}

/**
 * Give GD enough headroom to decode $path before it tries.
 *
 * GD holds every image as 4 bytes/pixel regardless of how well the file
 * compresses, so a 4.4 MB 4560×6840 PNG needs ~118 MB — more than the stock
 * 128 MB memory_limit once the scaled copy is allocated too. Blowing the limit
 * is a FATAL, not a false return: it kills the request mid-flight, so the
 * caller's `if (!scaleImage(...))` fallback never runs and the browser gets a
 * bodyless 500. Raise the ceiling for genuinely large images, and refuse the
 * ones too big to ever fit so the caller can fail cleanly.
 *
 * @return bool  false when the image cannot be decoded within IMG_MEMORY_CAP.
 */
const IMG_MEMORY_CAP = 1024;   // MB — refuse anything needing more than this

function imgMemoryHeadroom(string $path): bool {
    $info = @getimagesize($path);
    if (!$info || empty($info[0]) || empty($info[1])) return true;   // let imgLoad decide

    // source bitmap + scaled destination + decode scratch
    $needMb = (int)ceil(($info[0] * $info[1] * 4 * 2.2) / 1048576) + 32;

    $cur = trim((string)ini_get('memory_limit'));
    if ($cur === '-1') return true;                                   // already unlimited
    $curMb = (int)$cur;
    if (stripos($cur, 'G') !== false) $curMb *= 1024;
    if ($needMb <= $curMb) return true;
    if ($needMb > IMG_MEMORY_CAP) return false;

    return ini_set('memory_limit', $needMb . 'M') !== false;
}

function imgLoad(string $path, string $ext) {
    if (!imgMemoryHeadroom($path)) return false;

    // Dispatch on the BYTES, not the filename. Files carrying WebP content
    // under a .jpg/.png name are everywhere (phone exports, re-saved
    // downloads) — 36 of the memorial photos are exactly this. Keying off the
    // extension hands a WebP to imagecreatefromjpeg(), which fails on a
    // perfectly good image. Extension stays as the fallback for the rare file
    // getimagesize() can't type.
    $type = (@getimagesize($path))[2] ?? null;
    switch ($type) {
        case IMAGETYPE_JPEG: return @imagecreatefromjpeg($path);
        case IMAGETYPE_PNG:  return @imagecreatefrompng($path);
        case IMAGETYPE_GIF:  return @imagecreatefromgif($path);
        case IMAGETYPE_WEBP: return @imagecreatefromwebp($path);
    }
    switch ($ext) {
        case 'png':  return @imagecreatefrompng($path);
        case 'gif':  return @imagecreatefromgif($path);
        case 'webp': return @imagecreatefromwebp($path);
        default:     return @imagecreatefromjpeg($path);
    }
}

function imgSave($img, string $path, string $ext): void {
    switch ($ext) {
        case 'png':  imagepng($img, $path, 8); break;
        case 'gif':  imagegif($img, $path); break;
        case 'webp': imagewebp($img, $path, 85); break;
        default:     imagejpeg($img, $path, 85); break;
    }
}

function scaleImage(string $srcPath, string $destPath, int $maxW, int $maxH): bool {
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    $img = imgLoad($srcPath, $ext);
    if (!$img) return false;

    $origW = imagesx($img);
    $origH = imagesy($img);

    if ($origW <= $maxW && $origH <= $maxH) {
        // No scaling needed, just copy
        imagedestroy($img);
        return copy($srcPath, $destPath);
    }

    $ratio = min($maxW / $origW, $maxH / $origH);
    $newW = (int)round($origW * $ratio);
    $newH = (int)round($origH * $ratio);

    $scaled = imagecreatetruecolor($newW, $newH);
    if ($ext === 'png' || $ext === 'webp') {
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefilledrectangle($scaled, 0, 0, $newW, $newH, $transparent);
    }
    imagecopyresampled($scaled, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

    if (!is_dir(dirname($destPath))) {
        mkdir(dirname($destPath), 0775, true);
    }

    imgSave($scaled, $destPath, $ext);
    imagedestroy($img);
    imagedestroy($scaled);
    return true;
}

// ─────────────────────────────────────────────────────────────────────────
// Derivative size set
//
// Every uploaded image keeps its untouched original in originals/ and gets
// four derivatives beside the base file, named <stem>-<size>.<ext>:
//
//   icon  300px  search results, table thumbnails, avatars
//   sm    640px  card grids, feed images
//   md   1280px  article/book body images — the common case
//   lg   2000px  lightbox, retina srcset, print
//
// Boxes are max-edge and aspect is preserved, so a 2:3 book cover at 'icon'
// is 200×300. scaleImage() never upscales; when a source already fits a box
// we hardlink instead of writing a second copy of the same bytes, so the
// variant URL is always valid at zero disk cost (same directory, same fs).
// ─────────────────────────────────────────────────────────────────────────

const IMG_SIZE_SET = [
    'icon' => [300,  300],
    'sm'   => [640,  640],
    'md'   => [1280, 1280],
    'lg'   => [2000, 2000],
];

/** 'vol33-front_2d-abc.jpg' + 'sm' → 'vol33-front_2d-abc-sm.jpg' */
function imgVariantName(string $filename, string $size): string {
    return preg_replace('/(\.[A-Za-z0-9]+)$/', '-' . $size . '$1', $filename, 1)
        ?: $filename . '-' . $size;
}

/**
 * Web path of a variant: imageSizeUrl('/u/covers/x.jpg', 'sm') → '/u/covers/x-sm.jpg'.
 * Unknown sizes and 'orig' return the path untouched, so callers can pass a
 * size straight through from config without special-casing.
 */
function imageSizeUrl(?string $webPath, string $size): ?string {
    if (!$webPath || !isset(IMG_SIZE_SET[$size])) return $webPath;
    return imgVariantName($webPath, $size);
}

/**
 * Ready-made srcset for a base web path, e.g.
 *   '/u/covers/x-sm.jpg 640w, /u/covers/x-md.jpg 1280w, /u/covers/x-lg.jpg 2000w'
 */
function imageSrcset(?string $webPath, array $sizes = ['sm', 'md', 'lg']): string {
    if (!$webPath) return '';
    $out = [];
    foreach ($sizes as $s) {
        if (!isset(IMG_SIZE_SET[$s])) continue;
        $out[] = imageSizeUrl($webPath, $s) . ' ' . IMG_SIZE_SET[$s][0] . 'w';
    }
    return implode(', ', $out);
}

/**
 * Write the size set for $srcAbs into $destDir.
 *
 * @param string $srcAbs   Source image (normally the untouched original).
 * @param string $destDir  Directory the derivatives go in.
 * @param string $filename Base filename to derive variant names from;
 *                         defaults to the source's own name.
 * @return array           [size => filename] for every variant now on disk.
 */
function makeImageSizes(string $srcAbs, string $destDir, ?string $filename = null): array {
    if (!is_file($srcAbs)) return [];
    $filename = $filename ?: basename($srcAbs);
    $destDir  = rtrim($destDir, '/');
    if (!is_dir($destDir)) @mkdir($destDir, 02775, true);

    // Not a raster GD can read (SVG, PDF, a mislabelled file) — leave it as the
    // single stored file rather than writing four broken derivatives.
    $info = @getimagesize($srcAbs);
    if (!$info || empty($info[0]) || empty($info[1])) return [];

    $made = [];

    foreach (IMG_SIZE_SET as $size => [$maxW, $maxH]) {
        $out = $destDir . '/' . imgVariantName($filename, $size);

        // Source already inside the box — link rather than duplicate bytes.
        if ($info[0] <= $maxW && $info[1] <= $maxH) {
            if (is_file($out)) @unlink($out);
            if (@link($srcAbs, $out) || @copy($srcAbs, $out)) $made[$size] = basename($out);
            continue;
        }
        if (scaleImage($srcAbs, $out, $maxW, $maxH)) $made[$size] = basename($out);
    }
    return $made;
}

/** Remove a base image's whole size set (used when a slot is replaced/deleted). */
function unlinkImageSizes(string $destDir, string $filename): void {
    $destDir = rtrim($destDir, '/');
    foreach (array_keys(IMG_SIZE_SET) as $size) {
        $p = $destDir . '/' . imgVariantName($filename, $size);
        if (is_file($p)) @unlink($p);
    }
}

/**
 * Process an uploaded image file.
 *
 * @param PDO    $db
 * @param array  $file       Entry from $_FILES
 * @param string $destDir    Absolute filesystem path to destination directory
 * @param string $namePrefix Optional prefix for the generated filename
 * @return array|false  ['original'=>'...','scaled'=>'...','filename'=>'...'] or false on failure
 */
function processImageUpload(PDO $db, array $file, string $destDir, string $namePrefix = '') {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return false;
    }

    $allowed = ['jpg','jpeg','png','gif','webp'];
    $origName = $file['name'] ?? 'upload';
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return false;
    }

    $uniqueName = ($namePrefix ? $namePrefix . '-' : '') . uniqid('', true) . '.' . $ext;

    $originalsDir = rtrim($destDir, '/') . '/originals';
    if (!is_dir($originalsDir)) {
        mkdir($originalsDir, 0755, true);
    }

    $originalPath = $originalsDir . '/' . $uniqueName;
    if (!move_uploaded_file($file['tmp_name'], $originalPath)) {
        return false;
    }

    $scaledPath = rtrim($destDir, '/') . '/' . $uniqueName;
    $dims = getImageMaxDimensions($db);
    $ok = scaleImage($originalPath, $scaledPath, $dims['width'], $dims['height']);
    if (!$ok) {
        // If scaling fails, use the original as the scaled copy
        copy($originalPath, $scaledPath);
    }

    // icon/sm/md/lg beside the scaled copy. Derived from the untouched
    // original, never from $scaledPath, so a variant is never a rescale of a
    // rescale. Callers that only want the legacy two keys can ignore 'sizes'.
    $sizes = makeImageSizes($originalPath, $destDir, $uniqueName);

    return [
        'original' => $originalPath,
        'scaled'   => $scaledPath,
        'filename' => $uniqueName,
        'sizes'    => $sizes,
    ];
}

/**
 * Regenerate all scaled copies from originals.
 *
 * @param PDO    $db
 * @param string $destDir  Absolute filesystem path to destination directory
 * @return int  Number of images rescaled
 */
function rescaleAllImages(PDO $db, string $destDir): int {
    $originalsDir = rtrim($destDir, '/') . '/originals';
    if (!is_dir($originalsDir)) {
        return 0;
    }

    $dims = getImageMaxDimensions($db);
    $allowed = ['jpg','jpeg','png','gif','webp'];
    $count = 0;

    $files = scandir($originalsDir);
    if (!$files) return 0;

    foreach ($files as $fname) {
        if ($fname === '.' || $fname === '..') continue;
        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;

        $srcPath  = $originalsDir . '/' . $fname;
        $destPath = rtrim($destDir, '/') . '/' . $fname;

        // Skip the derivatives themselves — they live beside the scaled copy,
        // not in originals/, but guard anyway so a stray one is never treated
        // as a source and rescaled into '<stem>-sm-sm.jpg'.
        if (preg_match('/-(icon|sm|md|lg)\.[A-Za-z0-9]+$/', $fname)) continue;

        if (scaleImage($srcPath, $destPath, $dims['width'], $dims['height'])) {
            $count++;
        }
        makeImageSizes($srcPath, rtrim($destDir, '/'), $fname);
    }

    return $count;
}
