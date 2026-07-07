<?php
/**
 * ai-schema-lib.php — fetch each AI engine's self-described input-parameter
 * schema from its /models endpoint and return a map  model_id => param_schema.
 *
 * The engine is the single source of truth (its params.get() calls); admin-ai.html
 * consumes this to gate input fields per selected service. Result is cached to a
 * tmp file with a short TTL and served STALE on fetch failure, so a briefly
 * offline engine never blanks the UI's field gating.
 *
 * Shared by admin-ai-video.php (i2v) and admin-ai-image.php (t2i).
 */

if (!function_exists('ai_fetch_param_schemas')) {

/**
 * @param string $func  cache key, 'i2v' or 't2i'
 * @param array  $rows  provider rows (each with an 'endpoint' key)
 * @return array        model_id => param_schema  (empty array on total failure)
 */
function ai_fetch_param_schemas(string $func, array $rows): array {
    $cacheFile = sys_get_temp_dir() . "/yada-ai-schema-{$func}.json";
    $ttl = 300; // seconds

    // Fresh cache → skip the network entirely.
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $c = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($c)) return $c;
    }

    // All providers for a functionality share one engine; fetch /models once.
    // Prefer a non-localhost endpoint — the web container can't reach the
    // engine's own localhost.
    $endpoint = null;
    foreach ($rows as $r) {
        $ep = trim((string)($r['endpoint'] ?? ''));
        if ($ep !== '' && !preg_match('#//(localhost|127\.0\.0\.1)\b#', $ep)) { $endpoint = $ep; break; }
    }
    if ($endpoint === null) {
        foreach ($rows as $r) { $ep = trim((string)($r['endpoint'] ?? '')); if ($ep !== '') { $endpoint = $ep; break; } }
    }

    $map = null; // null = fetch failed (distinct from an empty valid response)
    if ($endpoint !== null) {
        $ch = curl_init(rtrim($endpoint, '/') . '/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $resp) {
            $arr = json_decode((string)$resp, true);
            if (is_array($arr)) {
                $map = [];
                foreach ($arr as $m) {
                    if (!empty($m['code']) && isset($m['param_schema']) && is_array($m['param_schema'])) {
                        $map[$m['code']] = $m['param_schema'];
                    }
                }
            }
        }
    }

    if ($map !== null) {
        @file_put_contents($cacheFile, json_encode($map));
        return $map;
    }
    // Fetch failed — serve stale cache (any age) if we have one.
    if (is_file($cacheFile)) {
        $c = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($c)) return $c;
    }
    return [];
}

} // function_exists
