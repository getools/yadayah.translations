<?php
/**
 * Performance report API.
 *
 *   GET /api/admin-perf.php           — list checks + latest sample + N recent samples
 *   GET /api/admin-perf.php?key=N&limit=168
 *                                      — full samples for one check (default 168 = 1 week at hourly)
 *
 * Auth: admin only.
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') errorResponse('Method not allowed', 405);

$checkKey = isset($_GET['key']) && $_GET['key'] !== '' ? (int)$_GET['key'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(2000, (int)$_GET['limit'])) : 168;

if ($checkKey > 0) {
    // Single-check detail view: full sample series
    $checkStmt = $db->prepare("SELECT * FROM yy_perf_check WHERE perf_check_key = ?");
    $checkStmt->execute([$checkKey]);
    $check = $checkStmt->fetch();
    if (!$check) errorResponse('Check not found', 404);

    $samplesStmt = $db->prepare("
        SELECT perf_sample_key, perf_sample_dtime, perf_sample_ms, perf_sample_ok, perf_sample_data
        FROM yy_perf_sample
        WHERE perf_check_key = ?
        ORDER BY perf_sample_dtime DESC
        LIMIT $limit
    ");
    $samplesStmt->execute([$checkKey]);
    $samples = $samplesStmt->fetchAll();
    // Decode jsonb data column to nested arrays so the client doesn't have to JSON.parse it.
    foreach ($samples as &$s) {
        if (isset($s['perf_sample_data']) && is_string($s['perf_sample_data'])) {
            $decoded = json_decode($s['perf_sample_data'], true);
            $s['perf_sample_data'] = is_array($decoded) ? $decoded : null;
        }
    }
    unset($s);
    jsonResponse(['check' => $check, 'samples' => $samples]);
}

// List view: checks + latest sample + sparkline data
$listStmt = $db->query("
    SELECT c.*,
           (SELECT perf_sample_ms FROM yy_perf_sample s WHERE s.perf_check_key = c.perf_check_key ORDER BY s.perf_sample_dtime DESC LIMIT 1) AS latest_ms,
           (SELECT perf_sample_ok FROM yy_perf_sample s WHERE s.perf_check_key = c.perf_check_key ORDER BY s.perf_sample_dtime DESC LIMIT 1) AS latest_ok,
           (SELECT perf_sample_dtime FROM yy_perf_sample s WHERE s.perf_check_key = c.perf_check_key ORDER BY s.perf_sample_dtime DESC LIMIT 1) AS latest_dtime,
           (SELECT perf_sample_data FROM yy_perf_sample s WHERE s.perf_check_key = c.perf_check_key ORDER BY s.perf_sample_dtime DESC LIMIT 1) AS latest_data,
           (SELECT COUNT(*) FROM yy_perf_sample s WHERE s.perf_check_key = c.perf_check_key) AS sample_count
    FROM yy_perf_check c
    ORDER BY c.perf_check_sort, c.perf_check_key
");
$checks = $listStmt->fetchAll();

// Pull the last N samples per check for the inline sparkline.
$sparkN = isset($_GET['spark']) ? max(1, min(500, (int)$_GET['spark'])) : 48;
foreach ($checks as &$c) {
    $sst = $db->prepare("
        SELECT perf_sample_ms, perf_sample_dtime, perf_sample_ok
        FROM yy_perf_sample
        WHERE perf_check_key = ?
        ORDER BY perf_sample_dtime DESC
        LIMIT $sparkN
    ");
    $sst->execute([$c['perf_check_key']]);
    // Return oldest-first so the sparkline reads left-to-right by time.
    $c['spark'] = array_reverse($sst->fetchAll());
    if (isset($c['latest_data']) && is_string($c['latest_data'])) {
        $decoded = json_decode($c['latest_data'], true);
        $c['latest_data'] = is_array($decoded) ? $decoded : null;
    }
}
unset($c);

jsonResponse(['checks' => $checks]);
