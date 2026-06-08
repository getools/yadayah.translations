<?php
/**
 * Ban check — included via auto_prepend_file or .htaccess.
 * Checks if the requesting IP is in yy_ip_ban and hasn't expired.
 * Minimal overhead: single indexed query, exits early if not banned.
 */

// Skip for CLI
if (php_sapi_name() === 'cli') return;

// Skip for the honeypot itself and static assets
$uri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($uri, '/api/honeypot.php') === 0) return;
if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|webp|webm|woff2?|ico|mp4|mp3|vtt|pdf)(\?|$)/i', $uri)) return;

$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
if (!$ip) return;

// Never enforce bans for private/loopback/Docker IPs — banning a reverse-proxy IP blocks all traffic through it
if ($ip === '::1' || str_starts_with($ip, '127.') || str_starts_with($ip, '10.') ||
    str_starts_with($ip, '192.168.') ||
    (str_starts_with($ip, '172.') && (int)explode('.', $ip)[1] >= 16 && (int)explode('.', $ip)[1] <= 31)) {
    return;
}

// Quick file-based cache to avoid DB hit on every request
$cacheDir = sys_get_temp_dir() . '/ip_bans';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
$cacheFile = $cacheDir . '/' . md5($ip);

// Check cache first (valid for 5 minutes)
if (file_exists($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if ($cached) {
        if ($cached['banned'] && $cached['expires'] > time()) {
            http_response_code(403);
            echo '<!DOCTYPE html><html><head><title>403</title></head><body><h1>Forbidden</h1></body></html>';
            exit;
        }
        if ($cached['checked'] > time() - 300) return; // checked within 5 min, not banned
    }
}

// Check DB
try {
    require_once __DIR__ . '/config.php';
    $db = getDb();
    $stmt = $db->prepare("SELECT ban_expires_dtime FROM yy_ip_ban WHERE ban_ip = ? AND ban_expires_dtime > NOW() LIMIT 1");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();

    if ($row) {
        $expires = strtotime($row['ban_expires_dtime']);
        file_put_contents($cacheFile, json_encode(['banned' => true, 'expires' => $expires, 'checked' => time()]));
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403</title></head><body><h1>Forbidden</h1></body></html>';
        exit;
    } else {
        file_put_contents($cacheFile, json_encode(['banned' => false, 'expires' => 0, 'checked' => time()]));
    }
} catch (Exception $e) {
    // If DB is down, don't block anyone
    return;
}
