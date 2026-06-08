<?php
/**
 * Honeypot auto-ban — logs and temporarily bans IPs that hit known attack paths.
 * Called from .htaccess RewriteRule for suspicious URLs.
 *
 * Bans are stored in yy_ip_ban with a 48-hour expiry.
 * The ban check runs in check-ban.php (included at top of every request via .htaccess).
 */
require_once __DIR__ . '/config.php';

$db = getDb();
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
$uri = $_SERVER['REQUEST_URI'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

if (!$ip) { http_response_code(403); exit; }

// Never ban private/loopback/Docker IPs — banning a reverse-proxy IP blocks all traffic through it
$isPrivate = $ip === '::1' || str_starts_with($ip, '127.') || str_starts_with($ip, '10.') ||
             str_starts_with($ip, '192.168.') ||
             (str_starts_with($ip, '172.') && (int)explode('.', $ip)[1] >= 16 && (int)explode('.', $ip)[1] <= 31);
if ($isPrivate) { http_response_code(403); exit; }

// Never ban Cloudflare proxy IPs — these are shared across all users
$cfPrefixes = ['104.16.', '104.17.', '104.18.', '104.19.', '104.20.', '104.21.', '104.22.', '104.23.', '104.24.', '104.25.', '104.26.', '104.27.', '172.64.', '172.65.', '172.66.', '172.67.', '172.68.', '172.69.', '172.70.', '172.71.', '141.101.', '162.158.', '190.93.', '188.114.', '197.234.', '198.41.', '173.245.'];
$isCfIp = false;
foreach ($cfPrefixes as $pfx) { if (strpos($ip, $pfx) === 0) { $isCfIp = true; break; } }
if ($isCfIp) {
    // Log but don't ban — this is a Cloudflare IP, banning it blocks all users on that edge
    $db->prepare("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag, event_client_ip) VALUES ('honeypot', 'warning', ?, ?, TRUE, ?)")
       ->execute(["Honeypot hit (Cloudflare IP, not banned): $uri", "UA: $ua\nCF-IP detected, skipping ban", $ip]);
    http_response_code(403);
    exit;
}

// Log the attempt
$db->prepare("
    INSERT INTO yy_ip_ban (ban_ip, ban_reason, ban_uri, ban_ua, ban_expires_dtime)
    VALUES (?, 'honeypot', ?, ?, NOW() + INTERVAL '48 hours')
    ON CONFLICT (ban_ip) DO UPDATE SET
        ban_hit_count = yy_ip_ban.ban_hit_count + 1,
        ban_uri = EXCLUDED.ban_uri,
        ban_ua = EXCLUDED.ban_ua,
        ban_expires_dtime = GREATEST(yy_ip_ban.ban_expires_dtime, NOW() + INTERVAL '48 hours'),
        ban_last_dtime = NOW()
")->execute([$ip, substr($uri, 0, 500), substr($ua, 0, 500)]);

// Log to monitor
$db->prepare("
    INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag, event_client_ip)
    VALUES ('honeypot', 'warning', ?, ?, TRUE, ?)
")->execute(["Honeypot hit: $uri", "UA: $ua", $ip]);

http_response_code(403);
echo '<!DOCTYPE html><html><head><title>403</title></head><body><h1>Forbidden</h1></body></html>';
