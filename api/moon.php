<?php
/**
 * Moon visibility API — backs /moon.
 *
 *   GET  ?action=locations                → observing sites (shared + your own)
 *   POST { action:"save-location", ... }  → create / update one of your sites
 *   POST { action:"delete-location", moon_location_key }
 *                                         → soft delete (active_flag = false)
 *   GET  ?action=events&from=&to=         → new/quarter/full moons plus perigee
 *                                           and apogee in the range. Computed
 *                                           here and cached in yy_moon_event.
 *   GET  ?action=sightings&limit=N        → logged crescent observations
 *   POST { action:"log-sighting", ... }   → record what was actually seen
 *
 * Auth: reads are public. Writes need a signed-in session. moon_location_shared_flag
 * is deliberately not settable through the API — shared sites are seeded in the
 * database so one user cannot push a site into everyone else's list.
 *
 * The event maths is Meeus, "Astronomical Algorithms" ch.25/47, with the
 * instants found by root-finding rather than the separate ch.49/50 series, so
 * these agree with public/js/moon-ephemeris.js term for term. Accuracy vs.
 * published tables: phases within ~20 s, apsides within ~3 min.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = (int)($_SESSION['user_key'] ?? 0);
$method  = $_SERVER['REQUEST_METHOD'];
$db      = getDb();

function readBody(): array {
    $b = json_decode(file_get_contents('php://input'), true);
    return is_array($b) ? $b : [];
}

$body   = $method === 'POST' ? readBody() : [];
$action = $_GET['action'] ?? ($body['action'] ?? '');

/* ═══════════════════════ ephemeris ═══════════════════════════════════ */

const SYNODIC     = 29.530588861;
const ANOMALISTIC = 27.55454989;

/** D, M, M', F, Σl (1e-6 deg), Σr (1e-3 km) — Meeus table 47.A */
function lrTerms(): array {
    static $t = null;
    if ($t !== null) return $t;
    return $t = [
        [0,0,1,0,6288774,-20905355],[2,0,-1,0,1274027,-3699111],[2,0,0,0,658314,-2955968],
        [0,0,2,0,213618,-569925],[0,1,0,0,-185116,48888],[0,0,0,2,-114332,-3149],
        [2,0,-2,0,58793,246158],[2,-1,-1,0,57066,-152138],[2,0,1,0,53322,-170733],
        [2,-1,0,0,45758,-204586],[0,1,-1,0,-40923,-129620],[1,0,0,0,-34720,108743],
        [0,1,1,0,-30383,104755],[2,0,0,-2,15327,10321],[0,0,1,2,-12528,0],
        [0,0,1,-2,10980,79661],[4,0,-1,0,10675,-34782],[0,0,3,0,10034,-23210],
        [4,0,-2,0,8548,-21636],[2,1,-1,0,-7888,24208],[2,1,0,0,-6766,30824],
        [1,0,-1,0,-5163,-8379],[1,1,0,0,4987,-16675],[2,-1,1,0,4036,-12831],
        [2,0,2,0,3994,-10445],[4,0,0,0,3861,-11650],[2,0,-3,0,3665,14403],
        [0,1,-2,0,-2689,-7003],[2,0,-1,2,-2602,0],[2,-1,-2,0,2390,10056],
        [1,0,1,0,-2348,6322],[2,-2,0,0,2236,-9884],[0,1,2,0,-2120,5751],
        [0,2,0,0,-2069,0],[2,-2,-1,0,2048,-4950],[2,0,1,-2,-1773,4130],
        [2,0,0,2,-1595,0],[4,-1,-1,0,1215,-3958],[0,0,2,2,-1110,0],
        [3,0,-1,0,-892,3258],[2,1,1,0,-810,2616],[4,-1,-2,0,759,-1897],
        [0,2,-1,0,-713,-2117],[2,2,-1,0,-700,2354],[2,1,-2,0,691,0],
        [2,-1,0,-2,596,0],[4,0,1,0,549,-1423],[0,0,4,0,537,-1117],
        [4,-1,0,0,520,-1571],[1,0,-2,0,-487,-1739],[2,1,0,-2,-399,0],
        [0,0,2,-2,-381,-4421],[1,1,1,0,351,0],[3,0,-2,0,-340,0],
        [4,0,-3,0,330,0],[2,-1,2,0,327,0],[0,2,1,0,-323,1165],
        [1,1,-1,0,299,0],[2,0,3,0,294,0],[2,0,-1,-2,0,8752],
    ];
}

function sind(float $d): float { return sin(deg2rad($d)); }
function cosd(float $d): float { return cos(deg2rad($d)); }
function norm360(float $x): float { $x = fmod($x, 360.0); return $x < 0 ? $x + 360 : $x; }
function norm180(float $x): float { $x = norm360($x); return $x > 180 ? $x - 360 : $x; }

/** ΔT in seconds (Espenak & Meeus). */
function deltaT(float $jd): float {
    $y = 2000.0 + ($jd - 2451545.0) / 365.25;
    if ($y < 1986) { $t = $y - 1975; return 45.45 + 1.067 * $t - $t * $t / 260 - $t ** 3 / 718; }
    if ($y < 2005) { $t = $y - 2000; return 63.86 + $t * (0.3345 + $t * (-0.060374 + $t * (0.0017275 + $t * (0.000651814 + $t * 0.00002373599)))); }
    if ($y < 2050) { $t = $y - 2000; return 62.92 + $t * (0.32217 + $t * 0.005589); }
    if ($y < 2150) { $u = ($y - 1820) / 100; return -20 + 32 * $u * $u - 0.5628 * (2150 - $y); }
    $u = ($y - 1820) / 100; return -20 + 32 * $u * $u;
}

function jdeOf(float $jd): float { return $jd + deltaT($jd) / 86400; }

/** Apparent geometric longitude of the sun, degrees (Meeus ch.25). */
function sunLongitude(float $jde): float {
    $T  = ($jde - 2451545.0) / 36525;
    $L0 = 280.46646 + 36000.76983 * $T + 0.0003032 * $T * $T;
    $M  = 357.52911 + 35999.05029 * $T - 0.0001537 * $T * $T;
    $C  = (1.914602 - 0.004817 * $T - 0.000014 * $T * $T) * sind($M)
        + (0.019993 - 0.000101 * $T) * sind(2 * $M) + 0.000289 * sind(3 * $M);
    $om = 125.04 - 1934.136 * $T;
    return norm360($L0 + $C - 0.00569 - 0.00478 * sind($om));
}

/** [longitude °, distance km] of the moon (Meeus ch.47). */
function moonLonDist(float $jde): array {
    $T  = ($jde - 2451545.0) / 36525;
    $Lp = 218.3164477 + 481267.88123421 * $T - 0.0015786 * $T * $T + $T ** 3 / 538841 - $T ** 4 / 65194000;
    $D  = 297.8501921 + 445267.1114034 * $T - 0.0018819 * $T * $T + $T ** 3 / 545868 - $T ** 4 / 113065000;
    $M  = 357.5291092 + 35999.0502909 * $T - 0.0001536 * $T * $T + $T ** 3 / 24490000;
    $Mp = 134.9633964 + 477198.8675055 * $T + 0.0087414 * $T * $T + $T ** 3 / 69699 - $T ** 4 / 14712000;
    $F  = 93.2720950 + 483202.0175233 * $T - 0.0036539 * $T * $T - $T ** 3 / 3526000 + $T ** 4 / 863310000;
    $A1 = 119.75 + 131.849 * $T;
    $A2 = 53.09 + 479264.290 * $T;
    $E  = 1 - 0.002516 * $T - 0.0000074 * $T * $T;

    $sl = 0.0; $sr = 0.0;
    foreach (lrTerms() as $t) {
        $arg = $t[0] * $D + $t[1] * $M + $t[2] * $Mp + $t[3] * $F;
        $f   = abs($t[1]) === 1 ? $E : (abs($t[1]) === 2 ? $E * $E : 1);
        $sl += $t[4] * $f * sind($arg);
        $sr += $t[5] * $f * cosd($arg);
    }
    $sl += 3958 * sind($A1) + 1962 * sind($Lp - $F) + 318 * sind($A2);

    return [norm360($Lp + $sl / 1e6), 385000.56 + $sr / 1000];
}

/** Moon − sun in apparent longitude, degrees. jd is UT. */
function elongationAt(float $jd): float {
    $jde = jdeOf($jd);
    [$lon, ] = moonLonDist($jde);
    return norm360($lon - sunLongitude($jde));
}

function distanceAt(float $jd): float {
    [, $d] = moonLonDist(jdeOf($jd));
    return $d;
}

/** phaseIndex 0=new 1=first quarter 2=full 3=last quarter; k counts from 2000 Jan 6. */
function phaseInstant(int $k, int $phaseIndex): float {
    $target = $phaseIndex * 90;
    $jd = 2451550.09766 + SYNODIC * ($k + $phaseIndex / 4) - deltaT(2451550) / 86400;
    for ($i = 0; $i < 8; $i++) {
        $f = norm180(elongationAt($jd) - $target);
        $jd -= $f / 12.190749;                 // mean elongation rate, °/day
        if (abs($f) < 1e-6) break;
    }
    return $jd;
}

/** Golden-section search for the distance extremum bracketing $guess. */
function refineExtremum(float $guess, bool $wantMin): float {
    $lo = $guess - 3.5; $hi = $guess + 3.5; $gr = (sqrt(5) - 1) / 2;
    $c = $hi - $gr * ($hi - $lo); $d = $lo + $gr * ($hi - $lo);
    $fc = distanceAt($c); $fd = distanceAt($d);
    for ($i = 0; $i < 60 && ($hi - $lo) > 1e-5; $i++) {
        $better = $wantMin ? ($fc < $fd) : ($fc > $fd);
        if ($better) { $hi = $d; $d = $c; $fd = $fc; $c = $hi - $gr * ($hi - $lo); $fc = distanceAt($c); }
        else         { $lo = $c; $c = $d; $fc = $fd; $d = $lo + $gr * ($hi - $lo); $fd = distanceAt($d); }
    }
    return ($lo + $hi) / 2;
}

function jdToIso(float $jd): string {
    $ms = ($jd - 2440587.5) * 86400;
    return gmdate('Y-m-d\TH:i:s\Z', (int)round($ms));
}
function isoToJd(string $iso): ?float {
    $t = strtotime($iso);
    return $t === false ? null : $t / 86400 + 2440587.5;
}

const PHASE_CODES = ['new', 'first-quarter', 'full', 'last-quarter'];

/** Every phase and apsis between two Julian days, computed fresh. */
function computeEvents(float $jdFrom, float $jdTo): array {
    $out = [];

    $k0 = (int)floor(($jdFrom - 2451550.09766) / SYNODIC) - 1;
    $k1 = (int)ceil(($jdTo - 2451550.09766) / SYNODIC) + 1;
    for ($k = $k0; $k <= $k1; $k++) {
        for ($p = 0; $p < 4; $p++) {
            $jd = phaseInstant($k, $p);
            if ($jd < $jdFrom || $jd > $jdTo) continue;
            $jde = jdeOf($jd);
            [$lon, $dist] = moonLonDist($jde);
            $out[] = [
                'type'          => PHASE_CODES[$p],
                'cycle'         => $k,
                'dtime'         => jdToIso($jd),
                'lunation'      => $k + 953,          // Brown lunation number
                'distance_km'   => round($dist, 3),
                'illumination'  => round((1 + cos(deg2rad(180 - $p * 90))) / 2 * 100, 5),
                'diameter'      => round(2 * rad2deg(asin(0.272481 * sin(asin(6378.14 / $dist)))) * 60, 4),
                'ecliptic_lon'  => round($lon, 5),
            ];
        }
    }

    $a0 = (int)floor(($jdFrom - 2451534.6698) / ANOMALISTIC) - 1;
    $a1 = (int)ceil(($jdTo - 2451534.6698) / ANOMALISTIC) + 1;
    for ($k = $a0; $k <= $a1; $k++) {
        foreach ([['perigee', true, 0.0], ['apogee', false, ANOMALISTIC / 2]] as [$type, $wantMin, $off]) {
            $jd = refineExtremum(2451534.6698 + ANOMALISTIC * $k + $off, $wantMin);
            if ($jd < $jdFrom || $jd > $jdTo) continue;
            $jde = jdeOf($jd);
            [$lon, $dist] = moonLonDist($jde);
            $out[] = [
                'type'          => $type,
                'cycle'         => $k,
                'dtime'         => jdToIso($jd),
                'lunation'      => null,
                'distance_km'   => round($dist, 3),
                'illumination'  => null,
                'diameter'      => round(2 * rad2deg(asin(0.272481 * sin(asin(6378.14 / $dist)))) * 60, 4),
                'ecliptic_lon'  => round($lon, 5),
            ];
        }
    }

    usort($out, fn($a, $b) => strcmp($a['dtime'], $b['dtime']));
    return $out;
}

/* ═══════════════════════ routes ══════════════════════════════════════ */

if ($method === 'GET' && $action === 'locations') {
    $s = $db->prepare(
        "SELECT moon_location_key, moon_location_code, moon_location_name,
                moon_location_address,
                moon_location_lat, moon_location_lon, moon_location_elevation_m,
                moon_location_timezone, moon_location_shared_flag,
                moon_location_default_flag, moon_location_note, moon_location_sort
           FROM yy_moon_location
          WHERE moon_location_active_flag
            AND (moon_location_shared_flag OR moon_location_user_key = ?)
          ORDER BY moon_location_shared_flag DESC, moon_location_sort, moon_location_name");
    $s->execute([$userKey]);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['moon_location_key']         = (int)$r['moon_location_key'];
        $r['moon_location_lat']         = (float)$r['moon_location_lat'];
        $r['moon_location_lon']         = (float)$r['moon_location_lon'];
        $r['moon_location_elevation_m'] = (float)$r['moon_location_elevation_m'];
    }
    jsonResponse(['locations' => $rows, 'user_key' => $userKey]);
}

/**
 * Address → coordinates, worldwide.
 *
 * Two upstream services, because neither covers everything:
 *   Nominatim (OpenStreetMap) — global: towns, landmarks, anywhere off the US.
 *   US Census geocoder        — the TIGER address ranges, which is the only one
 *                               of the two that reliably resolves an American
 *                               residential street number.
 * A US-looking query asks the Census first and puts its answer at the top,
 * because Nominatim will happily return a same-named street in another state.
 * If nothing matches, the house number and then the street are dropped so the
 * search still lands on the right town, flagged approximate.
 *
 * Answers are cached in yy_moon_geocode and served from there ever after, which
 * is both faster and what Nominatim's usage policy asks for. Calls carry a
 * User-Agent identifying this site, as that policy also requires.
 */
const GEOCODE_UA = 'YadaYah-Moon/1.0 (https://yadayah.com; claude.ai@yadayah.com)';

function httpGetJson(string $url, int $timeout = 10) {
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => GEOCODE_UA,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en'],
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }
    if ($raw === false) {
        $raw = @file_get_contents($url, false, stream_context_create(['http' => [
            'timeout' => $timeout,
            'header'  => 'User-Agent: ' . GEOCODE_UA . "\r\nAccept: application/json\r\n",
        ]]));
    }
    return $raw === false ? null : json_decode($raw, true);
}

function geocodeNominatim(string $q): array {
    $d = httpGetJson('https://nominatim.openstreetmap.org/search?format=jsonv2'
                   . '&addressdetails=0&limit=6&q=' . rawurlencode($q));
    if (!is_array($d)) return [];
    $out = [];
    foreach ($d as $r) {
        if (!isset($r['lat'], $r['lon'])) continue;
        $out[] = [
            'address' => (string)($r['display_name'] ?? ''),
            'lat'     => round((float)$r['lat'], 6),
            'lon'     => round((float)$r['lon'], 6),
            'kind'    => (string)($r['type'] ?? ''),
            'source'  => 'osm',
        ];
    }
    return $out;
}

/** TIGER address ranges return their match in capitals; put it back to prose. */
function tidyCase(string $s): string {
    $out = preg_replace_callback('/[A-Za-z\']+/u', function ($m) {
        return mb_strtoupper(mb_substr($m[0], 0, 1)) . mb_strtolower(mb_substr($m[0], 1));
    }, $s);
    $parts = array_map('trim', explode(',', $out));
    foreach ($parts as &$p) {
        if (preg_match('/^[A-Za-z]{2}$/', $p)) $p = mb_strtoupper($p);   // state code
        // Compass points inside a street name: "Ave Nw" → "Ave NW".
        $p = preg_replace_callback('/\b(NE|NW|SE|SW|N|S|E|W)\b/i',
                 function ($m) { return mb_strtoupper($m[1]); }, $p);
    }
    return implode(', ', $parts);
}

/**
 * A timezone for a US match. The Census gives us the state, which fixes the
 * zone outright for most of them; the dozen states the zone boundary runs
 * through are settled by longitude, or latitude for the Idaho panhandle.
 * Best effort — the alternative is a clock an hour out from local civil time.
 */
function usTimezone(string $state, float $lat, float $lon): ?string {
    $state = strtoupper(trim($state));
    $fixed = [
        'AZ' => 'America/Phoenix', 'HI' => 'Pacific/Honolulu', 'AK' => 'America/Anchorage',
        'CT' => 'America/New_York', 'DC' => 'America/New_York', 'DE' => 'America/New_York',
        'GA' => 'America/New_York', 'MA' => 'America/New_York', 'MD' => 'America/New_York',
        'ME' => 'America/New_York', 'NC' => 'America/New_York', 'NH' => 'America/New_York',
        'NJ' => 'America/New_York', 'NY' => 'America/New_York', 'OH' => 'America/New_York',
        'PA' => 'America/New_York', 'RI' => 'America/New_York', 'SC' => 'America/New_York',
        'VA' => 'America/New_York', 'VT' => 'America/New_York', 'WV' => 'America/New_York',
        'AL' => 'America/Chicago', 'AR' => 'America/Chicago', 'IA' => 'America/Chicago',
        'IL' => 'America/Chicago', 'LA' => 'America/Chicago', 'MN' => 'America/Chicago',
        'MO' => 'America/Chicago', 'MS' => 'America/Chicago', 'OK' => 'America/Chicago',
        'WI' => 'America/Chicago',
        'CO' => 'America/Denver', 'MT' => 'America/Denver', 'NM' => 'America/Denver',
        'UT' => 'America/Denver', 'WY' => 'America/Denver',
        'CA' => 'America/Los_Angeles', 'NV' => 'America/Los_Angeles', 'WA' => 'America/Los_Angeles',
    ];
    if (isset($fixed[$state])) return $fixed[$state];

    // west-of-this-longitude => first zone, otherwise second
    $split = [
        'FL' => [-85.0,  'America/Chicago', 'America/New_York'],
        'IN' => [-87.3,  'America/Chicago', 'America/New_York'],
        'KY' => [-86.0,  'America/Chicago', 'America/New_York'],
        'MI' => [-90.0,  'America/Chicago', 'America/New_York'],
        'TN' => [-85.5,  'America/Chicago', 'America/New_York'],
        'TX' => [-105.0, 'America/Denver',  'America/Chicago'],
        'KS' => [-101.5, 'America/Denver',  'America/Chicago'],
        'NE' => [-101.5, 'America/Denver',  'America/Chicago'],
        'ND' => [-100.8, 'America/Denver',  'America/Chicago'],
        'SD' => [-100.0, 'America/Denver',  'America/Chicago'],
    ];
    if (isset($split[$state])) {
        [$edge, $west, $east] = $split[$state];
        return $lon < $edge ? $west : $east;
    }
    if ($state === 'ID') return $lat > 45.5 ? 'America/Los_Angeles' : 'America/Boise';
    if ($state === 'OR') return ($lon > -117.5 && $lat < 44.5) ? 'America/Boise' : 'America/Los_Angeles';
    return null;
}

function geocodeCensus(string $q): array {
    $d = httpGetJson('https://geocoding.geo.census.gov/geocoder/locations/onelineaddress'
                   . '?benchmark=Public_AR_Current&format=json&address=' . rawurlencode($q));
    $matches = $d['result']['addressMatches'] ?? null;
    if (!is_array($matches)) return [];
    $out = [];
    foreach ($matches as $m) {
        if (!isset($m['coordinates']['x'], $m['coordinates']['y'])) continue;
        $lat = round((float)$m['coordinates']['y'], 6);
        $lon = round((float)$m['coordinates']['x'], 6);
        $out[] = [
            'address'  => tidyCase((string)($m['matchedAddress'] ?? '')) . ', United States',
            'lat'      => $lat,
            'lon'      => $lon,
            'kind'     => 'address',
            'source'   => 'census',
            'timezone' => usTimezone((string)($m['addressComponents']['state'] ?? ''), $lat, $lon),
        ];
    }
    return $out;
}

/** A ZIP, a state code or a spelled-out country is enough to try the Census. */
function looksAmerican(string $q): bool {
    if (preg_match('/\b(u\.?s\.?a\.?|united states)\b/i', $q)) return true;
    if (preg_match('/\b\d{5}(-\d{4})?\b/', $q)) return true;
    return (bool)preg_match('/\b(A[KLRZ]|C[AOT]|D[CE]|FL|GA|HI|I[ADLN]|K[SY]|LA|M[ADEINOST]'
                          . '|N[CDEHJMVY]|O[HKR]|P[AR]|RI|S[CD]|T[NX]|UT|V[AT]|W[AIVY])\b/', $q);
}

/** Same spot twice from two services is one answer. */
function dedupeByPosition(array $rows): array {
    $seen = [];
    $out  = [];
    foreach ($rows as $r) {
        $k = round($r['lat'], 4) . ',' . round($r['lon'], 4);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $r;
    }
    return $out;
}

/** Rough boxes for the three parts of the US the USGS raster covers. */
function inUnitedStates(float $lat, float $lon): bool {
    if ($lat >= 24.4 && $lat <= 49.5 && $lon >= -125.0 && $lon <=  -66.9) return true;  // conterminous
    if ($lat >= 51.0 && $lat <= 71.5 && $lon >= -170.0 && $lon <= -129.0) return true;  // Alaska
    if ($lat >= 18.5 && $lat <= 22.5 && $lon >= -160.5 && $lon <= -154.5) return true;  // Hawaii
    return false;
}

/**
 * Ground height for a point, in metres.
 *
 *   USGS EPQS  — the national elevation dataset, 1 m resolution, US only.
 *   Open-Meteo — Copernicus DEM GLO-90, everywhere else.
 * Neither needs a key. This is the height of the ground, not of a rooftop or
 * of the observer's eye.
 */
if ($method === 'GET' && $action === 'elevation') {
    $lat = (float)($_GET['lat'] ?? 999);
    $lon = (float)($_GET['lon'] ?? 999);
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        errorResponse('Latitude and longitude are required', 400);
    }

    $elev = null; $source = null;

    if (inUnitedStates($lat, $lon)) {
        $d = httpGetJson('https://epqs.nationalmap.gov/v1/json?units=Meters&wkid=4326'
                       . '&x=' . rawurlencode((string)$lon) . '&y=' . rawurlencode((string)$lat));
        $v = $d['value'] ?? null;
        // The service answers -1000000 where it has no data.
        if (is_numeric($v) && (float)$v > -1000) { $elev = round((float)$v, 1); $source = 'USGS'; }
    }

    if ($elev === null) {
        $d = httpGetJson('https://api.open-meteo.com/v1/elevation'
                       . '?latitude=' . rawurlencode((string)$lat)
                       . '&longitude=' . rawurlencode((string)$lon));
        $v = $d['elevation'][0] ?? null;
        if (is_numeric($v)) { $elev = round((float)$v, 1); $source = 'Copernicus DEM'; }
    }

    if ($elev === null) errorResponse('No elevation is published for that point', 503);
    jsonResponse(['elevation' => $elev, 'source' => $source]);
}

if ($method === 'GET' && $action === 'geocode') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 3) jsonResponse(['results' => []]);
    if (mb_strlen($q) > 200) $q = mb_substr($q, 0, 200);
    $key = mb_strtolower(preg_replace('/\s+/', ' ', $q));

    $s = $db->prepare("SELECT moon_geocode_result FROM yy_moon_geocode
                        WHERE moon_geocode_query = ? AND moon_geocode_active_flag");
    $s->execute([$key]);
    $hit = $s->fetchColumn();
    if ($hit !== false) {
        $cached = json_decode($hit, true) ?: [];
        // Older rows were cached before the Census was added; an empty one is
        // worth asking again rather than serving "no match" for ever.
        if ($cached) jsonResponse(['results' => $cached, 'cached' => true]);
    }

    $results = looksAmerican($q) ? geocodeCensus($q) : [];
    $results = array_merge($results, geocodeNominatim($q));

    // Nothing at all: give up the house number, then the street, so the answer
    // at least lands in the right place.
    if (!$results) {
        $noNumber = preg_replace('/^\s*\d+[A-Za-z]?\s+/', '', $q);
        if ($noNumber !== $q) {
            foreach (geocodeNominatim($noNumber) as $r) { $r['approx'] = true; $results[] = $r; }
        }
    }
    if (!$results && strpos($q, ',') !== false) {
        $tail = trim(substr($q, strpos($q, ',') + 1));
        if (mb_strlen($tail) >= 3) {
            foreach (geocodeNominatim($tail) as $r) { $r['approx'] = true; $results[] = $r; }
        }
    }

    $results = array_slice(dedupeByPosition($results), 0, 6);

    // Insert-only cache; a repeat of the same search is a no-op, so the
    // revision history stays at one row per distinct query.
    if ($results) {
        $ins = $db->prepare(
            "INSERT INTO yy_moon_geocode (moon_geocode_query, moon_geocode_result)
             VALUES (?, ?::jsonb) ON CONFLICT (moon_geocode_query) DO NOTHING");
        $ins->execute([$key, json_encode($results, JSON_UNESCAPED_UNICODE)]);
    }

    jsonResponse(['results' => $results, 'cached' => false]);
}

if ($method === 'GET' && $action === 'events') {
    $from = isoToJd(($_GET['from'] ?? '') ?: gmdate('Y-m-d'));
    $to   = isoToJd(($_GET['to'] ?? '') ?: gmdate('Y-m-d', time() + 86400 * 60));
    if ($from === null || $to === null) errorResponse('Bad from/to date', 400);
    if ($to <= $from) errorResponse('to must be after from', 400);
    if ($to - $from > 800) errorResponse('Range limited to about two years', 400);

    $events = computeEvents($from, $to);

    // Cache only what is missing. A plain ON CONFLICT DO NOTHING would still
    // fire the BEFORE INSERT revision trigger on every discarded row, so each
    // page load would pile identical history rows into yy_moon_event_rev.
    // Reading the existing keys first keeps the history one row per event.
    $sel = $db->prepare(
        "SELECT moon_event_type || ':' || moon_event_cycle
           FROM yy_moon_event
          WHERE moon_event_dtime BETWEEN ? AND ?");
    $sel->execute([jdToIso($from), jdToIso($to)]);
    $have = array_flip($sel->fetchAll(PDO::FETCH_COLUMN));

    // DO NOTHING remains as a guard against two requests racing on the same
    // new event; that is rare enough not to matter for the history.
    $ins = $db->prepare(
        "INSERT INTO yy_moon_event
             (moon_event_type, moon_event_cycle, moon_event_dtime, moon_event_lunation,
              moon_event_distance_km, moon_event_illumination, moon_event_diameter_arcmin,
              moon_event_ecliptic_lon, moon_event_source)
         VALUES (?,?,?,?,?,?,?,?,'computed')
         ON CONFLICT (moon_event_type, moon_event_cycle) DO NOTHING");

    $added = 0;
    foreach ($events as $e) {
        if (isset($have[$e['type'] . ':' . $e['cycle']])) continue;
        $ins->execute([
            $e['type'], $e['cycle'], $e['dtime'], $e['lunation'],
            $e['distance_km'], $e['illumination'], $e['diameter'],
            $e['ecliptic_lon'],
        ]);
        $added++;
    }
    jsonResponse(['events' => $events, 'cached' => $added]);
}

if ($method === 'GET' && $action === 'sightings') {
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $s = $db->prepare(
        "SELECT s.moon_sighting_key, s.moon_location_key, s.moon_sighting_dtime,
                s.moon_sighting_seen_flag, s.moon_sighting_method, s.moon_sighting_lunation,
                s.moon_sighting_age_hours, s.moon_sighting_illumination, s.moon_sighting_altitude,
                s.moon_sighting_elongation, s.moon_sighting_q_value, s.moon_sighting_criterion,
                s.moon_sighting_note, s.moon_sighting_user_key, l.moon_location_name
           FROM yy_moon_sighting s
           LEFT JOIN yy_moon_location l ON l.moon_location_key = s.moon_location_key
          WHERE s.moon_sighting_active_flag
          ORDER BY s.moon_sighting_dtime DESC
          LIMIT $limit");
    $s->execute();
    jsonResponse(['sightings' => $s->fetchAll()]);
}

if ($method !== 'POST') errorResponse('Unsupported request', 405);
if (!$userKey)          errorResponse('Sign in to save locations and sightings', 401);

if ($action === 'save-location') {
    $name = trim((string)($body['moon_location_name'] ?? ''));
    if ($name === '') errorResponse('A name is required', 400);
    $lat = (float)($body['moon_location_lat'] ?? 0);
    $lon = (float)($body['moon_location_lon'] ?? 0);
    if ($lat < -90 || $lat > 90)   errorResponse('Latitude must be between -90 and 90', 400);
    if ($lon < -180 || $lon > 180) errorResponse('Longitude must be between -180 and 180', 400);
    $elev = (float)($body['moon_location_elevation_m'] ?? 0);
    $tz   = trim((string)($body['moon_location_timezone'] ?? '')) ?: null;
    $note = trim((string)($body['moon_location_note'] ?? '')) ?: null;
    $addr = trim((string)($body['moon_location_address'] ?? '')) ?: null;
    $key  = (int)($body['moon_location_key'] ?? 0);

    if ($key) {
        // Own rows only — a shared site cannot be edited through the API.
        $s = $db->prepare(
            "UPDATE yy_moon_location
                SET moon_location_name = ?, moon_location_address = ?,
                    moon_location_lat = ?, moon_location_lon = ?,
                    moon_location_elevation_m = ?, moon_location_timezone = ?, moon_location_note = ?
              WHERE moon_location_key = ? AND moon_location_user_key = ?
                AND NOT moon_location_shared_flag");
        $s->execute([$name, $addr, $lat, $lon, $elev, $tz, $note, $key, $userKey]);
        if (!$s->rowCount()) errorResponse('That location is not yours to edit', 403);
        jsonResponse(['ok' => true, 'moon_location_key' => $key]);
    }

    // Slug has to be unique across the table, so scope it to the owner.
    $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $code = trim($base, '-') . '-u' . $userKey;
    $s = $db->prepare(
        "INSERT INTO yy_moon_location
             (moon_location_code, moon_location_name, moon_location_address,
              moon_location_lat, moon_location_lon,
              moon_location_elevation_m, moon_location_timezone, moon_location_note,
              moon_location_user_key, moon_location_shared_flag)
         VALUES (?,?,?,?,?,?,?,?,?,false)
         ON CONFLICT (moon_location_code) DO UPDATE
                SET moon_location_name    = EXCLUDED.moon_location_name,
                    moon_location_address = EXCLUDED.moon_location_address,
                    moon_location_lat  = EXCLUDED.moon_location_lat,
                    moon_location_lon  = EXCLUDED.moon_location_lon,
                    moon_location_elevation_m = EXCLUDED.moon_location_elevation_m,
                    moon_location_timezone    = EXCLUDED.moon_location_timezone,
                    moon_location_note        = EXCLUDED.moon_location_note,
                    moon_location_active_flag = true
              WHERE yy_moon_location.moon_location_user_key = EXCLUDED.moon_location_user_key
         RETURNING moon_location_key");
    $s->execute([$code, $name, $addr, $lat, $lon, $elev, $tz, $note, $userKey]);
    $newKey = $s->fetchColumn();
    if ($newKey === false) errorResponse('A location with that name already exists', 409);
    jsonResponse(['ok' => true, 'moon_location_key' => (int)$newKey]);
}

if ($action === 'delete-location') {
    $key = (int)($body['moon_location_key'] ?? 0);
    if (!$key) errorResponse('moon_location_key is required', 400);
    $s = $db->prepare(
        "UPDATE yy_moon_location SET moon_location_active_flag = false
          WHERE moon_location_key = ? AND moon_location_user_key = ?
            AND NOT moon_location_shared_flag");
    $s->execute([$key, $userKey]);
    if (!$s->rowCount()) errorResponse('That location is not yours to delete', 403);
    jsonResponse(['ok' => true]);
}

if ($action === 'log-sighting') {
    $dtime = trim((string)($body['moon_sighting_dtime'] ?? ''));
    if ($dtime === '' || strtotime($dtime) === false) errorResponse('A valid observation time is required', 400);

    $num = function ($v) { return $v === null || $v === '' ? null : (float)$v; };
    $s = $db->prepare(
        "INSERT INTO yy_moon_sighting
             (moon_location_key, moon_sighting_dtime, moon_sighting_lat, moon_sighting_lon,
              moon_sighting_elevation_m, moon_sighting_timezone, moon_sighting_seen_flag,
              moon_sighting_method, moon_sighting_lunation, moon_sighting_age_hours,
              moon_sighting_illumination, moon_sighting_altitude, moon_sighting_azimuth,
              moon_sighting_sun_altitude, moon_sighting_elongation, moon_sighting_arcv,
              moon_sighting_daz, moon_sighting_q_value, moon_sighting_criterion,
              moon_sighting_note, moon_sighting_user_key)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         RETURNING moon_sighting_key");
    $s->execute([
        ((int)($body['moon_location_key'] ?? 0)) ?: null,
        $dtime,
        $num($body['moon_sighting_lat'] ?? null),
        $num($body['moon_sighting_lon'] ?? null),
        $num($body['moon_sighting_elevation_m'] ?? null),
        (trim((string)($body['moon_sighting_timezone'] ?? '')) ?: null),
        // PDO sends PHP false as '' which Postgres rejects for boolean — cast to int.
        isset($body['moon_sighting_seen_flag']) ? (int)(bool)$body['moon_sighting_seen_flag'] : null,
        (trim((string)($body['moon_sighting_method'] ?? '')) ?: null),
        isset($body['moon_sighting_lunation']) ? (int)$body['moon_sighting_lunation'] : null,
        $num($body['moon_sighting_age_hours'] ?? null),
        $num($body['moon_sighting_illumination'] ?? null),
        $num($body['moon_sighting_altitude'] ?? null),
        $num($body['moon_sighting_azimuth'] ?? null),
        $num($body['moon_sighting_sun_altitude'] ?? null),
        $num($body['moon_sighting_elongation'] ?? null),
        $num($body['moon_sighting_arcv'] ?? null),
        $num($body['moon_sighting_daz'] ?? null),
        $num($body['moon_sighting_q_value'] ?? null),
        (trim((string)($body['moon_sighting_criterion'] ?? '')) ?: null),
        (trim((string)($body['moon_sighting_note'] ?? '')) ?: null),
        $userKey,
    ]);
    jsonResponse(['ok' => true, 'moon_sighting_key' => (int)$s->fetchColumn()]);
}

errorResponse('Unknown action', 400);
