<?php
/**
 * Admin API for book cover artwork.
 *
 * Six artwork slots per volume — front / spine / back, each in 2D and 3D —
 * plus a derived icon that is regenerated from the 2D front on every upload.
 * The icon is the thumbnail every other surface consumes (search results
 * today); the full-size slots are for book pages and marketing.
 *
 * Uploads keep the untouched file in originals/ and serve a scaled display
 * copy, same shape as the logo/resource uploaders. Filenames are unique per
 * upload so a replacement never collides with a cached URL.
 *
 * POST multipart  volume_key, slot, image_file      — upload / replace a slot
 * POST json       {action:'delete',     volume_key, slot}
 * POST json       {action:'regen_icon', volume_key}
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';
$authUser = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

$COVER_SLOTS = ['front_2d', 'spine_2d', 'back_2d', 'front_3d', 'spine_3d', 'back_3d'];
$ICON_MAX_W  = 245;    // matches the legacy /images/covers/*-245x300.jpg thumbs
$ICON_MAX_H  = 300;
$FULL_MAX_W  = 1200;   // display copy; the untouched upload stays in originals/
$FULL_MAX_H  = 1600;

$UPLOAD_DIR = dirname(__DIR__) . '/public/u/covers';
$ORIG_DIR   = $UPLOAD_DIR . '/originals';
$WEB_DIR    = '/u/covers';

$db = getDb();

// Multipart posts leave php://input empty, so read both.
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? $_POST['action'] ?? $_GET['action'] ?? 'upload';
$key    = (int)($_POST['volume_key'] ?? $body['volume_key'] ?? $_GET['key'] ?? 0);
if (!$key) errorResponse('Volume key required');

$cur = $db->prepare("SELECT * FROM yy_volume WHERE volume_key = ?");
$cur->execute([$key]);
$vol = $cur->fetch();
if (!$vol) errorResponse('Volume not found', 404);

// Same checkout rule as a DOCX upload: another admin's lock blocks the write,
// your own lock does not. See admin-books.php toggle_lock.
$myKey = (int)($authUser['user_key'] ?? 0);
if (!empty($vol['volume_locked_flag']) && (int)$vol['volume_locked_by_key'] !== $myKey) {
    errorResponse('This book is checked out by ' . ($vol['volume_locked_by_name'] ?: 'another admin') . '.', 423);
}

/** Web path (/u/covers/x.jpg) → absolute path, or null if it isn't ours. */
function coverAbs(?string $webPath, string $uploadDir): ?string {
    if (!$webPath) return null;
    $base = basename(parse_url($webPath, PHP_URL_PATH) ?: $webPath);
    if ($base === '' || $base === '.' || $base === '..') return null;
    return rtrim($uploadDir, '/') . '/' . $base;
}

/** Remove a slot's display copy and its original. */
function coverUnlink(?string $webPath, string $uploadDir, string $origDir): void {
    $abs = coverAbs($webPath, $uploadDir);
    if ($abs && is_file($abs)) @unlink($abs);
    $orig = coverAbs($webPath, $origDir);
    if ($orig && is_file($orig)) @unlink($orig);
}

/**
 * (Re)build the icon from the 2D front original. Returns the new web path,
 * or null when there is no front image to derive from.
 */
function coverBuildIcon(?string $front2d, string $uploadDir, string $origDir, string $webDir, int $maxW, int $maxH): ?string {
    if (!$front2d) return null;
    $src = coverAbs($front2d, $origDir);
    if (!$src || !is_file($src)) $src = coverAbs($front2d, $uploadDir);   // original pruned? fall back
    if (!$src || !is_file($src)) return null;

    $base = basename($src);
    $ext  = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    $stem = preg_replace('/\.[^.]+$/', '', $base);
    $iconName = $stem . '-icon.' . $ext;
    $iconAbs  = rtrim($uploadDir, '/') . '/' . $iconName;

    if (!scaleImage($src, $iconAbs, $maxW, $maxH)) return null;
    return rtrim($webDir, '/') . '/' . $iconName;
}

// ── Delete a slot ────────────────────────────────────────────────────────
if ($action === 'delete') {
    $slot = (string)($body['slot'] ?? $_POST['slot'] ?? '');
    if (!in_array($slot, $COVER_SLOTS, true)) errorResponse('Unknown slot');
    $col = 'volume_img_' . $slot;                       // whitelisted above

    coverUnlink($vol[$col] ?? null, $UPLOAD_DIR, $ORIG_DIR);

    // Dropping the 2D front also drops the icon it fed.
    if ($slot === 'front_2d') {
        coverUnlink($vol['volume_img_icon'] ?? null, $UPLOAD_DIR, $ORIG_DIR);
        $db->prepare("UPDATE yy_volume SET $col = NULL, volume_img_icon = NULL WHERE volume_key = ?")->execute([$key]);
        jsonResponse(['deleted' => true, 'slot' => $slot, 'path' => null, 'icon' => null]);
    }

    $db->prepare("UPDATE yy_volume SET $col = NULL WHERE volume_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true, 'slot' => $slot, 'path' => null]);
}

// ── Rebuild the icon from the existing 2D front ──────────────────────────
if ($action === 'regen_icon') {
    $icon = coverBuildIcon($vol['volume_img_front_2d'] ?? null, $UPLOAD_DIR, $ORIG_DIR, $WEB_DIR, $ICON_MAX_W, $ICON_MAX_H);
    if (!$icon) errorResponse('No 2D front image to derive an icon from');
    if (($vol['volume_img_icon'] ?? null) && $vol['volume_img_icon'] !== $icon) {
        coverUnlink($vol['volume_img_icon'], $UPLOAD_DIR, $ORIG_DIR);
    }
    $db->prepare("UPDATE yy_volume SET volume_img_icon = ? WHERE volume_key = ?")->execute([$icon, $key]);
    jsonResponse(['icon' => $icon]);
}

// ── Upload / replace a slot ──────────────────────────────────────────────
$slot = (string)($_POST['slot'] ?? '');
if (!in_array($slot, $COVER_SLOTS, true)) errorResponse('Unknown slot');
$col = 'volume_img_' . $slot;                           // whitelisted above

$file = $_FILES['image_file'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        errorResponse('Image is larger than the server upload limit (' . ini_get('upload_max_filesize') . ')');
    }
    errorResponse('No file uploaded');
}
if (!is_uploaded_file($file['tmp_name'])) errorResponse('Invalid upload');

$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
if ($ext === 'jpeg') $ext = 'jpg';
if (!in_array($ext, $allowed, true)) errorResponse('Image must be a JPG, PNG, GIF or WEBP');
if (!@getimagesize($file['tmp_name'])) errorResponse('That file is not a readable image');

foreach ([$UPLOAD_DIR, $ORIG_DIR] as $d) {
    if (!is_dir($d) && !mkdir($d, 0775, true) && !is_dir($d)) {
        errorResponse('Upload directory could not be created: ' . basename($d), 500);
    }
}

$name = 'vol' . $key . '-' . $slot . '-' . uniqid('', false) . '.' . $ext;
$origAbs = $ORIG_DIR . '/' . $name;
if (!move_uploaded_file($file['tmp_name'], $origAbs)) errorResponse('Could not store the upload', 500);

// Scaled display copy; if GD can't handle it, serve the original bytes.
$destAbs = $UPLOAD_DIR . '/' . $name;
if (!scaleImage($origAbs, $destAbs, $FULL_MAX_W, $FULL_MAX_H)) {
    copy($origAbs, $destAbs);
}
$webPath = $WEB_DIR . '/' . $name;

$oldPath = $vol[$col] ?? null;
$resp = ['saved' => true, 'slot' => $slot, 'path' => $webPath];

if ($slot === 'front_2d') {
    // The icon is always derived, never uploaded — rebuild it from the new front.
    $icon = coverBuildIcon($webPath, $UPLOAD_DIR, $ORIG_DIR, $WEB_DIR, $ICON_MAX_W, $ICON_MAX_H);
    if (($vol['volume_img_icon'] ?? null) && $vol['volume_img_icon'] !== $icon) {
        coverUnlink($vol['volume_img_icon'], $UPLOAD_DIR, $ORIG_DIR);
    }
    $db->prepare("UPDATE yy_volume SET $col = ?, volume_img_icon = ? WHERE volume_key = ?")->execute([$webPath, $icon, $key]);
    $resp['icon'] = $icon;
} else {
    $db->prepare("UPDATE yy_volume SET $col = ? WHERE volume_key = ?")->execute([$webPath, $key]);
}

// Replaced file goes last, so a failed UPDATE never leaves the row pointing
// at bytes we already deleted.
if ($oldPath && $oldPath !== $webPath) coverUnlink($oldPath, $UPLOAD_DIR, $ORIG_DIR);

jsonResponse($resp);
