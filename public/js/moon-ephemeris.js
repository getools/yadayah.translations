/* ═══════════════════════════════════════════════════════════════════════
 * moon-ephemeris.js — self-contained solar/lunar ephemeris. No deps.
 *
 * Algorithms: Jean Meeus, "Astronomical Algorithms" (2nd ed.)
 *   ch.12 sidereal time      ch.22 nutation        ch.25 solar position
 *   ch.40 topocentric coords ch.47 ELP-2000/82 truncated lunar position
 *   ch.48 illuminated fraction + position angle of the bright limb
 *   ch.53 libration, position angle of the axis, selenographic colongitude
 * Crescent visibility: B.D. Yallop, NAO Technical Note No. 69 (1997).
 *
 * Rise / set / transit / twilight, and the instants of the phases and of
 * perigee/apogee, are found by sampling and bisecting THIS engine rather
 * than by separate series, so every number on the page comes from one
 * consistent model.
 *
 * Accuracy vs. JPL: moon ~10" in longitude, ~4" in latitude, ~30 km in
 * distance; sun ~0.01°; rise/set within a few seconds.
 * ═══════════════════════════════════════════════════════════════════════ */
(function (root) {
'use strict';

var D2R = Math.PI / 180, R2D = 180 / Math.PI;
var AU_KM = 149597870.7, EARTH_R = 6378.14;

function sind(d) { return Math.sin(d * D2R); }
function cosd(d) { return Math.cos(d * D2R); }
function tand(d) { return Math.tan(d * D2R); }
function asind(x) { return Math.asin(Math.max(-1, Math.min(1, x))) * R2D; }
function acosd(x) { return Math.acos(Math.max(-1, Math.min(1, x))) * R2D; }
function atan2d(y, x) { return Math.atan2(y, x) * R2D; }
function norm360(x) { x = x % 360; return x < 0 ? x + 360 : x; }
function norm180(x) { x = norm360(x); return x > 180 ? x - 360 : x; }

/* ── Time ─────────────────────────────────────────────────────────────── */

function jdFromDate(d) { return d.getTime() / 86400000 + 2440587.5; }
function dateFromJD(jd) { return new Date(Math.round((jd - 2440587.5) * 86400000)); }

/* Decimal year, good enough for the ΔT polynomials. */
function decimalYear(jd) { return 2000.0 + (jd - 2451545.0) / 365.25; }

/* ΔT = TD − UT, seconds. Espenak & Meeus polynomial expressions. */
function deltaT(jd) {
    var y = decimalYear(jd), t, u;
    if (y < 1900) { u = (y - 1820) / 100; return -20 + 32 * u * u - 15; }
    if (y < 1920) { t = y - 1900; return -2.79 + t * (1.494119 + t * (-0.0598939 + t * (0.0061966 - t * 0.000197))); }
    if (y < 1941) { t = y - 1920; return 21.20 + t * (0.84493 + t * (-0.076100 + t * 0.0020936)); }
    if (y < 1961) { t = y - 1950; return 29.07 + 0.407 * t - t * t / 233 + t * t * t / 2547; }
    if (y < 1986) { t = y - 1975; return 45.45 + 1.067 * t - t * t / 260 - t * t * t / 718; }
    if (y < 2005) { t = y - 2000; return 63.86 + t * (0.3345 + t * (-0.060374 + t * (0.0017275 + t * (0.000651814 + t * 0.00002373599)))); }
    if (y < 2050) { t = y - 2000; return 62.92 + t * (0.32217 + t * 0.005589); }
    if (y < 2150) { u = (y - 1820) / 100; return -20 + 32 * u * u - 0.5628 * (2150 - y); }
    u = (y - 1820) / 100; return -20 + 32 * u * u;
}

/* JD(UT) → JDE(TD) */
function jdeOf(jd) { return jd + deltaT(jd) / 86400; }

/* Apparent sidereal time at Greenwich, degrees. jd is UT. */
function siderealTime(jd, dpsi, eps) {
    var T = (jd - 2451545.0) / 36525;
    var th = 280.46061837 + 360.98564736629 * (jd - 2451545.0)
           + 0.000387933 * T * T - T * T * T / 38710000;
    return norm360(th + dpsi * cosd(eps));
}

/* ── Nutation and obliquity (ch. 22) ──────────────────────────────────── */

function nutation(T) {
    var om = 125.04452 - 1934.136261 * T + 0.0020708 * T * T + T * T * T / 450000;
    var Ls = 280.4665 + 36000.7698 * T;
    var Lm = 218.3165 + 481267.8813 * T;
    var dpsi = (-17.20 * sind(om) - 1.32 * sind(2 * Ls)
               - 0.23 * sind(2 * Lm) + 0.21 * sind(2 * om)) / 3600;
    var deps = (9.20 * cosd(om) + 0.57 * cosd(2 * Ls)
               + 0.10 * cosd(2 * Lm) - 0.09 * cosd(2 * om)) / 3600;
    var U = T / 100;
    var eps0 = 23 + 26 / 60 + (21.448 - U * (4680.93 + U * (1.55 - U * (1999.25
              - U * (51.38 + U * (249.67 + U * (39.05 - U * (7.12 + U * (27.87
              + U * (5.79 + U * 2.45)))))))))) / 3600;
    return { dpsi: dpsi, deps: deps, eps0: eps0, eps: eps0 + deps, omega: om };
}

/* ── Sun (ch. 25) ─────────────────────────────────────────────────────── */

function sunPosition(jde, nut) {
    var T = (jde - 2451545.0) / 36525;
    var L0 = 280.46646 + 36000.76983 * T + 0.0003032 * T * T;
    var M  = 357.52911 + 35999.05029 * T - 0.0001537 * T * T;
    var e  = 0.016708634 - 0.000042037 * T - 0.0000001267 * T * T;
    var C  = (1.914602 - 0.004817 * T - 0.000014 * T * T) * sind(M)
           + (0.019993 - 0.000101 * T) * sind(2 * M)
           + 0.000289 * sind(3 * M);
    var trueLon = L0 + C, v = M + C;
    var R = 1.000001018 * (1 - e * e) / (1 + e * cosd(v));      // AU
    var om = 125.04 - 1934.136 * T;
    var lonApp = trueLon - 0.00569 - 0.00478 * sind(om);         // aberration + nutation
    var eps = nut.eps + 0.00256 * cosd(om);
    var ra = atan2d(cosd(eps) * sind(lonApp), cosd(lonApp));
    var dec = asind(sind(eps) * sind(lonApp));
    return {
        lon: norm360(lonApp), lonGeom: norm360(trueLon), lat: 0,
        ra: norm360(ra), dec: dec, distAu: R, distKm: R * AU_KM,
        eps: eps, meanAnom: M
    };
}

/* ── Moon (ch. 47, ELP-2000/82 truncated) ─────────────────────────────── */

/* D, M, M', F, Σl (1e-6 deg), Σr (1e-3 km) */
var LR_TERMS = [
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
 [1,1,-1,0,299,0],[2,0,3,0,294,0],[2,0,-1,-2,0,8752]
];

/* D, M, M', F, Σb (1e-6 deg) */
var B_TERMS = [
 [0,0,0,1,5128122],[0,0,1,1,280602],[0,0,1,-1,277693],[2,0,0,-1,173237],
 [2,0,-1,1,55413],[2,0,-1,-1,46271],[2,0,0,1,32573],[0,0,2,1,17198],
 [2,0,1,-1,9266],[0,0,2,-1,8822],[2,-1,0,-1,8216],[2,0,-2,-1,4324],
 [2,0,1,1,4200],[2,1,0,-1,-3359],[2,-1,-1,1,2463],[2,-1,0,1,2211],
 [2,-1,-1,-1,2065],[0,1,-1,-1,-1870],[4,0,-1,-1,1828],[0,1,0,1,-1794],
 [0,0,0,3,-1749],[0,1,-1,1,-1565],[1,0,0,1,-1491],[0,1,1,1,-1475],
 [0,1,1,-1,-1410],[0,1,0,-1,-1344],[1,0,0,-1,-1335],[0,0,3,1,1107],
 [4,0,0,-1,1021],[4,0,-1,1,833],[0,0,1,-3,777],[4,0,-2,1,671],
 [2,0,0,-3,607],[2,0,2,-1,596],[2,-1,1,-1,491],[2,0,-2,1,-451],
 [0,0,3,-1,439],[2,0,2,1,422],[2,0,-3,-1,421],[2,1,-1,1,-366],
 [2,1,0,1,-351],[4,0,0,1,331],[2,-1,1,1,315],[2,-2,0,-1,302],
 [0,0,1,3,-283],[2,1,1,-1,-229],[1,1,0,-1,223],[1,1,0,1,223],
 [0,1,-2,-1,-220],[2,1,-1,-1,-220],[1,0,1,1,-185],[2,-1,-2,-1,181],
 [0,1,2,1,-177],[4,0,-2,-1,176],[4,-1,-1,-1,166],[1,0,1,-1,-164],
 [4,0,1,-1,132],[1,0,-1,-1,-119],[4,-1,0,-1,115],[2,-2,0,1,107]
];

/* Fundamental lunar arguments (47.1–47.6), degrees. */
function moonArgs(T) {
    return {
        Lp: norm360(218.3164477 + 481267.88123421 * T - 0.0015786 * T * T
                    + T * T * T / 538841 - T * T * T * T / 65194000),
        D:  norm360(297.8501921 + 445267.1114034 * T - 0.0018819 * T * T
                    + T * T * T / 545868 - T * T * T * T / 113065000),
        M:  norm360(357.5291092 + 35999.0502909 * T - 0.0001536 * T * T
                    + T * T * T / 24490000),
        Mp: norm360(134.9633964 + 477198.8675055 * T + 0.0087414 * T * T
                    + T * T * T / 69699 - T * T * T * T / 14712000),
        F:  norm360(93.2720950 + 483202.0175233 * T - 0.0036539 * T * T
                    - T * T * T / 3526000 + T * T * T * T / 863310000),
        A1: norm360(119.75 + 131.849 * T),
        A2: norm360(53.09 + 479264.290 * T),
        A3: norm360(313.45 + 481266.484 * T),
        E:  1 - 0.002516 * T - 0.0000074 * T * T
    };
}

function moonPosition(jde, nut) {
    var T = (jde - 2451545.0) / 36525;
    var a = moonArgs(T), E = a.E, E2 = E * E;
    var sl = 0, sr = 0, sb = 0, i, t, arg, f;

    for (i = 0; i < LR_TERMS.length; i++) {
        t = LR_TERMS[i];
        arg = t[0] * a.D + t[1] * a.M + t[2] * a.Mp + t[3] * a.F;
        f = Math.abs(t[1]) === 1 ? E : (Math.abs(t[1]) === 2 ? E2 : 1);
        sl += t[4] * f * sind(arg);
        sr += t[5] * f * cosd(arg);
    }
    for (i = 0; i < B_TERMS.length; i++) {
        t = B_TERMS[i];
        arg = t[0] * a.D + t[1] * a.M + t[2] * a.Mp + t[3] * a.F;
        f = Math.abs(t[1]) === 1 ? E : (Math.abs(t[1]) === 2 ? E2 : 1);
        sb += t[4] * f * sind(arg);
    }

    /* Additive terms from Venus, Jupiter and the flattening of the Earth. */
    sl += 3958 * sind(a.A1) + 1962 * sind(a.Lp - a.F) + 318 * sind(a.A2);
    sb += -2235 * sind(a.Lp) + 382 * sind(a.A3) + 175 * sind(a.A1 - a.F)
        + 175 * sind(a.A1 + a.F) + 127 * sind(a.Lp - a.Mp) - 115 * sind(a.Lp + a.Mp);

    var lon = norm360(a.Lp + sl / 1e6);          // geometric, of date
    var lat = sb / 1e6;
    var dist = 385000.56 + sr / 1000;            // km, centre to centre
    var par = asind(EARTH_R / dist);             // equatorial horizontal parallax
    var lonApp = lon + nut.dpsi;
    var eps = nut.eps;
    var ra = norm360(atan2d(sind(lonApp) * cosd(eps) - tand(lat) * sind(eps), cosd(lonApp)));
    var dec = asind(sind(lat) * cosd(eps) + cosd(lat) * sind(eps) * sind(lonApp));

    return {
        lon: lonApp, lonGeom: lon, lat: lat, distKm: dist,
        ra: ra, dec: dec, parallax: par,
        semidiameter: asind(0.272481 * sind(par)),   // degrees
        args: a, T: T
    };
}

/* ── Topocentric correction (ch. 40) ──────────────────────────────────── */

function siteConstants(latDeg, elevM) {
    var u = Math.atan(0.99664719 * tand(latDeg));
    return {
        rhoSin: 0.99664719 * Math.sin(u) + (elevM / 6378140) * sind(latDeg),
        rhoCos: Math.cos(u) + (elevM / 6378140) * cosd(latDeg)
    };
}

function topocentric(ra, dec, parallaxDeg, siteC, lstDeg) {
    var H = norm360(lstDeg - ra);
    var sp = sind(parallaxDeg);
    var dRa = atan2d(-siteC.rhoCos * sp * sind(H),
                      cosd(dec) - siteC.rhoCos * sp * cosd(H));
    var decT = atan2d((sind(dec) - siteC.rhoSin * sp) * cosd(dRa),
                       cosd(dec) - siteC.rhoCos * sp * cosd(H));
    return { ra: norm360(ra + dRa), dec: decT, dRa: dRa };
}

/* Altitude / azimuth. Azimuth measured from north, increasing eastward. */
function horizontal(ra, dec, latDeg, lstDeg) {
    var H = lstDeg - ra;
    var alt = asind(sind(latDeg) * sind(dec) + cosd(latDeg) * cosd(dec) * cosd(H));
    var az = norm360(atan2d(sind(H), cosd(H) * sind(latDeg) - tand(dec) * cosd(latDeg)) + 180);
    return { alt: alt, az: az, ha: norm180(H) };
}

/* Bennett refraction, true altitude → apparent (arcminutes → degrees). */
function refraction(altDeg) {
    if (altDeg < -2) return 0;
    var R = 1.02 / tand(altDeg + 10.3 / (altDeg + 5.11));
    return R / 60;
}

/* ── Libration and selenographic coordinates (ch. 53) ─────────────────── */

var MOON_I = 1.54242;   // inclination of the lunar equator to the ecliptic

function opticalLibration(lonApp, lat, dpsi, omega, F) {
    var W = norm360(lonApp - dpsi - omega);
    var A = atan2d(sind(W) * cosd(lat) * cosd(MOON_I) - sind(lat) * sind(MOON_I),
                   cosd(W) * cosd(lat));
    return {
        W: W, A: norm360(A),
        l: norm180(A - F),
        b: asind(-sind(W) * cosd(lat) * sind(MOON_I) - sind(lat) * cosd(MOON_I))
    };
}

function physicalLibration(args, T, opt) {
    var Mp = args.Mp, M = args.M, F = args.F, D = args.D, E = args.E;
    var om = 125.0445479 - 1934.1362891 * T;
    var K1 = 119.75 + 131.849 * T, K2 = 72.56 + 20.186 * T;

    var rho = -0.02752 * cosd(Mp) - 0.02245 * sind(F) + 0.00684 * cosd(Mp - 2 * F)
            - 0.00293 * cosd(2 * F) - 0.00085 * cosd(2 * F - 2 * D)
            - 0.00054 * cosd(Mp - 2 * D) - 0.00020 * sind(Mp + F)
            - 0.00020 * cosd(Mp + 2 * F) - 0.00020 * cosd(Mp - F)
            + 0.00014 * cosd(Mp + 2 * F - 2 * D);

    var sig = -0.02816 * sind(Mp) + 0.02244 * cosd(F) - 0.00682 * sind(Mp - 2 * F)
            - 0.00279 * sind(2 * F) - 0.00083 * sind(2 * F - 2 * D)
            + 0.00069 * sind(Mp - 2 * D) + 0.00040 * cosd(Mp + F)
            - 0.00025 * sind(2 * Mp) - 0.00023 * sind(Mp + 2 * F)
            + 0.00020 * cosd(Mp - F) + 0.00019 * sind(Mp - F)
            + 0.00013 * sind(Mp + 2 * F - 2 * D) - 0.00010 * cosd(Mp - 3 * F);

    var tau = 0.02520 * E * sind(M) + 0.00473 * sind(2 * Mp - 2 * F)
            - 0.00467 * sind(Mp) + 0.00396 * sind(K1)
            + 0.00276 * sind(2 * Mp - 2 * D) + 0.00196 * sind(om)
            - 0.00183 * cosd(Mp - F) + 0.00115 * sind(Mp - 2 * D)
            - 0.00096 * sind(Mp - D) + 0.00046 * sind(2 * F - 2 * D)
            - 0.00039 * sind(Mp - F) - 0.00032 * sind(Mp - M - D)
            + 0.00027 * sind(2 * Mp - M - 2 * D) + 0.00023 * sind(K2)
            - 0.00014 * sind(2 * D) + 0.00014 * cosd(2 * Mp - 2 * F)
            - 0.00012 * sind(Mp - 2 * F) - 0.00012 * sind(2 * Mp)
            + 0.00011 * sind(2 * Mp - 2 * M - 2 * D);

    var lpp = -tau + (rho * cosd(opt.A) + sig * sind(opt.A)) * tand(opt.b);
    var bpp = sig * cosd(opt.A) - rho * sind(opt.A);
    return { rho: rho, sigma: sig, tau: tau, l: lpp, b: bpp };
}

/* ── One complete snapshot ────────────────────────────────────────────── */

/* site = { lat, lon, elev }  (lon positive east, elev in metres) */
function compute(dateOrJd, site) {
    var jd = (typeof dateOrJd === 'number') ? dateOrJd : jdFromDate(dateOrJd);
    var jde = jdeOf(jd), T = (jde - 2451545.0) / 36525;
    var nut = nutation(T);
    var sun = sunPosition(jde, nut);
    var moon = moonPosition(jde, nut);
    var lst = siderealTime(jd, nut.dpsi, nut.eps);
    var lstLocal = norm360(lst + site.lon);

    /* Illumination (ch. 48), geocentric. */
    var dRa = sun.ra - moon.ra;
    var elong = acosd(sind(sun.dec) * sind(moon.dec)
              + cosd(sun.dec) * cosd(moon.dec) * cosd(dRa));
    var phaseAngle = atan2d(sun.distKm * sind(elong),
                            moon.distKm - sun.distKm * cosd(elong));
    phaseAngle = norm360(phaseAngle);
    var fraction = (1 + cosd(phaseAngle)) / 2;
    var limbPA = norm360(atan2d(cosd(sun.dec) * sind(dRa),
                 sind(sun.dec) * cosd(moon.dec) - cosd(sun.dec) * sind(moon.dec) * cosd(dRa)));

    /* Waxing when the moon leads the sun in ecliptic longitude. */
    var phaseDiff = norm360(moon.lon - sun.lon);
    var waxing = phaseDiff < 180;

    /* Libration (ch. 53). */
    var optL = opticalLibration(moon.lon, moon.lat, nut.dpsi, nut.omega, moon.args.F);
    var phyL = physicalLibration(moon.args, T, optL);
    var lib = { l: optL.l + phyL.l, b: optL.b + phyL.b, optical: optL, physical: phyL };

    /* Position angle of the moon's axis of rotation. */
    var V = nut.omega + nut.dpsi + phyL.sigma / sind(MOON_I);
    var X = sind(MOON_I + phyL.rho) * sind(V);
    var Y = sind(MOON_I + phyL.rho) * cosd(V) * cosd(nut.eps)
          - cosd(MOON_I + phyL.rho) * sind(nut.eps);
    var omegaA = atan2d(X, Y);
    lib.pa = norm360(asind(Math.sqrt(X * X + Y * Y) * cosd(moon.ra - omegaA) / cosd(lib.b)));
    /* Libration expressed as one displacement: magnitude, and the position
       angle of that displacement measured from the moon's north. */
    lib.total = Math.sqrt(lib.l * lib.l + lib.b * lib.b);
    lib.totalPA = norm360(-atan2d(lib.l, lib.b));

    /* Selenographic coordinates of the sub-solar point → colongitude. */
    var lonH = sun.lonGeom + 180
             + (moon.distKm / sun.distKm) * R2D * cosd(moon.lat) * sind(sun.lonGeom - moon.lonGeom);
    var latH = (moon.distKm / sun.distKm) * moon.lat;
    var optS = opticalLibration(lonH + nut.dpsi, latH, nut.dpsi, nut.omega, moon.args.F);
    var l0 = optS.l + (-phyL.tau + (phyL.rho * cosd(optS.A) + phyL.sigma * sind(optS.A)) * tand(optS.b));
    var b0 = optS.b + (phyL.sigma * cosd(optS.A) - phyL.rho * sind(optS.A));
    var colongitude = norm360(90 - l0);

    /* Topocentric places and horizon coordinates. */
    var sc = siteConstants(site.lat, site.elev || 0);
    var moonTopo = topocentric(moon.ra, moon.dec, moon.parallax, sc, lstLocal);
    var sunPar = asind(EARTH_R / sun.distKm);
    var sunTopo = topocentric(sun.ra, sun.dec, sunPar, sc, lstLocal);

    var moonHz = horizontal(moonTopo.ra, moonTopo.dec, site.lat, lstLocal);
    var sunHz = horizontal(sunTopo.ra, sunTopo.dec, site.lat, lstLocal);
    var moonGeoHz = horizontal(moon.ra, moon.dec, site.lat, lstLocal);

    /* Topocentric semidiameter grows as the moon rises toward the zenith. */
    var sdTopo = moon.semidiameter * (1 + sind(moonHz.alt) * sind(moon.parallax));

    /* Topocentric libration: the shift of the sub-earth point for an
       observer off the geocentre, up to ~1° near the horizon. */
    var libTopo = topocentricLibration(moon, lib, moonHz, site, lstLocal);

    return {
        jd: jd, jde: jde, deltaT: deltaT(jd), date: dateFromJD(jd),
        lst: lst, lstLocal: lstLocal, nutation: nut, site: site,
        sun: {
            ra: sun.ra, dec: sun.dec, lon: sun.lon, distAu: sun.distAu, distKm: sun.distKm,
            alt: sunHz.alt, altApparent: sunHz.alt + refraction(sunHz.alt),
            az: sunHz.az, ha: sunHz.ha,
            semidiameter: 959.63 / sun.distAu / 3600
        },
        moon: {
            ra: moon.ra, dec: moon.dec, raTopo: moonTopo.ra, decTopo: moonTopo.dec,
            lon: moon.lon, lat: moon.lat, distKm: moon.distKm,
            parallax: moon.parallax, semidiameter: moon.semidiameter,
            semidiameterTopo: sdTopo, diameter: 2 * sdTopo,
            alt: moonHz.alt, altApparent: moonHz.alt + refraction(moonHz.alt),
            altGeocentric: moonGeoHz.alt, az: moonHz.az, ha: moonHz.ha
        },
        illum: {
            fraction: fraction, percent: fraction * 100,
            phaseAngle: phaseAngle, elongation: elong,
            limbPA: limbPA, waxing: waxing, phaseDiff: phaseDiff,
            name: phaseName(phaseDiff)
        },
        libration: lib, librationTopo: libTopo,
        selenographic: { sunLon: norm360(l0), sunLat: b0, colongitude: colongitude },
        eclipticLon: norm360(moon.lon)
    };
}

/* Topocentric libration (ch. 53, "topocentric librations"). */
function topocentricLibration(moon, lib, moonHz, site, lstLocal) {
    var H = norm360(lstLocal - moon.ra);
    var q = atan2d(cosd(site.lat) * sind(H),
                   cosd(moon.dec) * sind(site.lat) - sind(moon.dec) * cosd(site.lat) * cosd(H));
    var z = 90 - moonHz.alt;
    var pi = moon.parallax;
    var dl = -sind(pi) * sind(z) * sind(q - lib.pa) * R2D / cosd(lib.b);
    var db =  sind(pi) * sind(z) * cosd(q - lib.pa) * R2D;
    var dc =  dl * sind(lib.b + db) - sind(pi) * sind(z) * cosd(q) * R2D * tand(moon.dec);
    return { l: lib.l + dl, b: lib.b + db, pa: norm360(lib.pa + dc),
             total: Math.sqrt(Math.pow(lib.l + dl, 2) + Math.pow(lib.b + db, 2)),
             totalPA: norm360(-atan2d(lib.l + dl, lib.b + db)) };
}

function phaseName(diff) {
    if (diff < 1.5 || diff > 358.5) return 'New Moon';
    if (Math.abs(diff - 90) < 1.5) return 'First Quarter';
    if (Math.abs(diff - 180) < 1.5) return 'Full Moon';
    if (Math.abs(diff - 270) < 1.5) return 'Last Quarter';
    if (diff < 90) return 'Waxing Crescent';
    if (diff < 180) return 'Waxing Gibbous';
    if (diff < 270) return 'Waning Gibbous';
    return 'Waning Crescent';
}

/* ── Phases, by root-finding on the elongation in longitude ───────────── */

var SYNODIC = 29.530588861;

function elongationAt(jd) {
    var jde = jdeOf(jd), T = (jde - 2451545.0) / 36525;
    var nut = nutation(T);
    return norm360(moonPosition(jde, nut).lon - sunPosition(jde, nut).lon);
}

/* phaseIndex: 0 new, 1 first quarter, 2 full, 3 last quarter.
   k counts synodic months from the new moon of 2000 Jan 6. */
function phaseInstant(k, phaseIndex) {
    var target = phaseIndex * 90;
    var jd = 2451550.09766 + SYNODIC * (k + phaseIndex / 4) - deltaT(2451550) / 86400;
    for (var i = 0; i < 8; i++) {
        var f = norm180(elongationAt(jd) - target);
        jd -= f / 12.190749;                    // mean elongation rate, °/day
        if (Math.abs(f) < 1e-6) break;
    }
    return jd;
}

function lunationNumber(k) { return k + 953; }   // Brown lunation number

/* k of the phase nearest to jd. */
function phaseK(jd, phaseIndex) {
    return Math.round((jd - 2451550.09766) / SYNODIC - phaseIndex / 4);
}

/* The new moon that starts the lunation containing jd. */
function lastNewMoon(jd) {
    var k = phaseK(jd, 0);
    var t = phaseInstant(k, 0);
    while (t > jd) { k -= 1; t = phaseInstant(k, 0); }
    while (phaseInstant(k + 1, 0) <= jd) { k += 1; t = phaseInstant(k, 0); }
    return { jd: t, k: k, lunation: lunationNumber(k) };
}

/* ── Perigee / apogee, by locating extrema of the distance ────────────── */

var ANOMALISTIC = 27.55454989;

function distanceAt(jd) {
    var jde = jdeOf(jd), T = (jde - 2451545.0) / 36525;
    return moonPosition(jde, nutation(T)).distKm;
}

/* type: 'perigee' | 'apogee'. Returns the first one strictly after jd. */
function nextApsis(jd, type) {
    var wantMin = (type === 'perigee');
    var k = Math.floor((jd - 2451534.6698) / ANOMALISTIC) - 1;
    for (var n = 0; n < 4; n++, k++) {
        var guess = 2451534.6698 + ANOMALISTIC * k + (wantMin ? 0 : ANOMALISTIC / 2);
        var t = refineExtremum(guess, wantMin);
        if (t > jd) {
            return {
                jd: t, date: dateFromJD(t), type: type,
                cycle: wantMin ? k : k,
                distanceKm: distanceAt(t)
            };
        }
    }
    return null;
}

/* Golden-section search for the extremum bracketing `guess` (± 3 days). */
function refineExtremum(guess, wantMin) {
    var lo = guess - 3.5, hi = guess + 3.5, gr = (Math.sqrt(5) - 1) / 2;
    var c = hi - gr * (hi - lo), d = lo + gr * (hi - lo);
    var fc = distanceAt(c), fd = distanceAt(d);
    for (var i = 0; i < 60 && (hi - lo) > 1e-5; i++) {
        var better = wantMin ? (fc < fd) : (fc > fd);
        if (better) { hi = d; d = c; fd = fc; c = hi - gr * (hi - lo); fc = distanceAt(c); }
        else        { lo = c; c = d; fc = fd; d = lo + gr * (hi - lo); fd = distanceAt(d); }
    }
    return (lo + hi) / 2;
}

/* ── Rise, set, transit, twilight ─────────────────────────────────────── */

/* Horizon conventions, all applied to the TOPOCENTRIC centre of the disc.
 *   sun  −0.8333° : upper limb on an ideal horizon, allowing 34' refraction
 *   moon  'limb'  : −(0.5667° + semidiameter), the same convention for the moon
 *   moon  0       : centre on the horizon — "elevation at moonset is 0°"
 * Both are settable so the page can state which rule produced a time. */
var DEFAULTS = { sunHorizon: -0.8333, moonHorizon: 'limb' };

function moonLimit(snap, moonHorizon) {
    return moonHorizon === 'limb' || moonHorizon == null
        ? -(0.5667 + snap.moon.semidiameterTopo)
        : Number(moonHorizon);
}

/* Signed distance above the horizon rule, for root-finding. */
function moonMargin(site, moonHorizon) {
    return function (t) { var s = compute(t, site); return s.moon.alt - moonLimit(s, moonHorizon); };
}
function sunMargin(site, depth) {
    return function (t) { return compute(t, site).sun.alt - depth; };
}

/* Scan [jdStart, jdEnd] for crossings of a threshold; bisect each to ~1 s. */
function findCrossings(jdStart, jdEnd, site, valueFn, stepMinutes) {
    var step = (stepMinutes || 4) / 1440, out = [];
    var prevT = jdStart, prevV = valueFn(jdStart);
    for (var t = jdStart + step; t <= jdEnd + 1e-9; t += step) {
        var v = valueFn(t);
        if ((prevV < 0 && v >= 0) || (prevV > 0 && v <= 0)) {
            var a = prevT, b = t, fa = prevV;
            for (var i = 0; i < 22; i++) {
                var m = (a + b) / 2, fm = valueFn(m);
                if ((fa < 0) === (fm < 0)) { a = m; fa = fm; } else { b = m; }
            }
            out.push({ jd: (a + b) / 2, rising: prevV < 0 });
        }
        prevT = t; prevV = v;
    }
    return out;
}

/* Highest altitude in the window (upper transit). */
function findTransit(jdStart, jdEnd, altFn) {
    var step = 4 / 1440, best = null;
    for (var t = jdStart; t <= jdEnd; t += step) {
        var v = altFn(t);
        if (!best || v > best.alt) best = { jd: t, alt: v };
    }
    if (!best) return null;
    var lo = best.jd - step, hi = best.jd + step, gr = (Math.sqrt(5) - 1) / 2;
    var c = hi - gr * (hi - lo), d = lo + gr * (hi - lo), fc = altFn(c), fd = altFn(d);
    for (var i = 0; i < 40 && (hi - lo) > 1e-6; i++) {
        if (fc > fd) { hi = d; d = c; fd = fc; c = hi - gr * (hi - lo); fc = altFn(c); }
        else         { lo = c; c = d; fc = fd; d = lo + gr * (hi - lo); fd = altFn(d); }
    }
    var jd = (lo + hi) / 2;
    return { jd: jd, alt: altFn(jd) };
}

/* All rise/set/transit/twilight events inside [jdStart, jdEnd]. */
function dayEvents(jdStart, jdEnd, site, opts) {
    opts = opts || {};
    var sunH  = opts.sunHorizon  != null ? opts.sunHorizon  : DEFAULTS.sunHorizon;
    var moonH = opts.moonHorizon != null ? opts.moonHorizon : DEFAULTS.moonHorizon;

    var moonX = findCrossings(jdStart, jdEnd, site, moonMargin(site, moonH), 4);
    var sunX  = findCrossings(jdStart, jdEnd, site, sunMargin(site, sunH), 4);

    function pick(list, rising) {
        for (var i = 0; i < list.length; i++) if (list[i].rising === rising) return list[i].jd;
        return null;
    }
    function twilight(depth) {
        var x = findCrossings(jdStart, jdEnd, site, sunMargin(site, -depth), 4);
        return { start: pick(x, true), end: pick(x, false) };
    }

    return {
        moon: {
            rise: pick(moonX, true), set: pick(moonX, false),
            transit: findTransit(jdStart, jdEnd, function (t) { return compute(t, site).moon.alt; })
        },
        sun: {
            rise: pick(sunX, true), set: pick(sunX, false),
            transit: findTransit(jdStart, jdEnd, function (t) { return compute(t, site).sun.alt; })
        },
        twilight: { civil: twilight(6), nautical: twilight(12), astronomical: twilight(18) },
        horizon: { sun: sunH, moon: moonH }
    };
}

/* First crossing of a margin function strictly after jdFrom, searching up to
   `spanDays` ahead. Coarse 8-minute scan, then bisection to ~1 second. */
function nextCrossing(jdFrom, marginFn, rising, spanDays) {
    var step = 8 / 1440, end = jdFrom + (spanDays || 1.6);
    var prevT = jdFrom, prevV = marginFn(jdFrom);
    for (var t = jdFrom + step; t <= end; t += step) {
        var v = marginFn(t);
        if ((rising && prevV < 0 && v >= 0) || (!rising && prevV > 0 && v <= 0)) {
            var a = prevT, b = t, fa = prevV;
            for (var i = 0; i < 22; i++) {
                var m = (a + b) / 2, fm = marginFn(m);
                if ((fa < 0) === (fm < 0)) { a = m; fa = fm; } else { b = m; }
            }
            return (a + b) / 2;
        }
        prevT = t; prevV = v;
    }
    return null;
}

function nextSunset(jdFrom, site, opts) {
    opts = opts || {};
    var h = opts.sunHorizon != null ? opts.sunHorizon : DEFAULTS.sunHorizon;
    return nextCrossing(jdFrom, sunMargin(site, h), false, 1.6);
}

/* ── The sunset-anchored day ──────────────────────────────────────────── */

/* In the Towrah a day begins at sunset and runs to the next sunset, so every
 * figure a witness cares about is evaluated AT that sunset:
 *   elevation  moon's altitude when the sun sets
 *   age        time from the astronomical new moon (conjunction) to sunset
 *   viewable   sunset → moonset, the window in which a sliver could be seen
 * `jdSunset` is the sunset that opens the day. */
function sunsetObservation(jdSunset, site, opts) {
    if (jdSunset == null) return null;
    opts = opts || {};
    var moonH = opts.moonHorizon != null ? opts.moonHorizon : DEFAULTS.moonHorizon;

    var s = compute(jdSunset, site);
    var margin = moonMargin(site, moonH);

    /* If the moon is already down when the sun sets there is no window at
       all tonight — the next downward crossing belongs to tomorrow, so it
       must not be reported as this evening's moonset. */
    var upAtSunset = margin(jdSunset) > 0;
    var moonset = upAtSunset ? nextCrossing(jdSunset, margin, false, 1.2) : null;
    var moonrise = nextCrossing(jdSunset, margin, true, 1.2);
    var anm = lastNewMoon(jdSunset);
    var ageDays = jdSunset - anm.jd;

    return {
        sunset: jdSunset,
        moonset: moonset,
        moonrise: moonrise,
        moonUpAtSunset: upAtSunset,
        viewableMinutes: moonset != null ? (moonset - jdSunset) * 1440 : null,
        elevation: s.moon.alt,               /* moon centre, topocentric */
        azimuth: s.moon.az,
        illumination: s.illum.percent,
        elongation: s.illum.elongation,
        newMoon: anm,
        ageDays: ageDays,
        ageHours: ageDays * 24,
        crescent: crescentVisibility(jdSunset, moonset, site),
        snapshot: s
    };
}

/* Walk forward from the conjunction to find the sunset that opens month one
 * under each reckoning:
 *   astronomical — the first sunset after the moon begins waxing
 *   observational — the first sunset at which the sliver should be seen
 * `qThreshold` −0.014 is Yallop's boundary between "visible under perfect
 * conditions" (band B) and "may need optical aid" (band C). */
function monthStart(jdNear, site, opts) {
    opts = opts || {};
    var qMin = opts.qThreshold != null ? opts.qThreshold : -0.014;
    var anm = lastNewMoon(jdNear);

    var astronomical = nextSunset(anm.jd, site, opts);
    var observational = null, evenings = [], t = astronomical, obs;
    for (var i = 0; i < 4 && t != null; i++) {
        obs = sunsetObservation(t, site, opts);
        evenings.push(obs);
        if (observational == null && obs.crescent && obs.crescent.q > qMin) observational = t;
        t = nextSunset(t + 0.5, site, opts);
    }
    return {
        newMoon: anm,
        astronomical: astronomical,
        observational: observational,
        evenings: evenings
    };
}

/* Which day of the month a given sunset is, counting sunsets from the start. */
function dayOfMonth(jdSunset, jdMonthStart) {
    if (jdSunset == null || jdMonthStart == null) return null;
    return Math.round(jdSunset - jdMonthStart) + 1;
}

/* ── Crescent visibility (Yallop 1997) ────────────────────────────────── */

/* Evaluated at the "best time" = sunset + 4/9 × lag, the standard epoch for
   the q-test. Returns null when the moon sets before the sun. */
function crescentVisibility(jdSunset, jdMoonset, site) {
    if (jdSunset == null || jdMoonset == null) return null;
    var lag = jdMoonset - jdSunset;
    if (lag <= 0) return null;
    var best = jdSunset + (4 / 9) * lag;
    var s = compute(best, site);

    var arcl = s.illum.elongation;                       // geocentric elongation
    var arcv = s.moon.altGeocentric - s.sun.alt;         // arc of vision
    var daz  = norm180(s.moon.az - s.sun.az);
    var sd   = s.moon.semidiameterTopo * 60;             // arcminutes
    var w    = sd * (1 - cosd(arcl));                    // crescent width, arcmin
    var q    = (arcv - (11.8371 - 6.3226 * w + 0.7319 * w * w - 0.1018 * w * w * w)) / 10;

    var band, verdict;
    if (q > 0.216)       { band = 'A'; verdict = 'Easily visible to the unaided eye'; }
    else if (q > -0.014) { band = 'B'; verdict = 'Visible under perfect conditions'; }
    else if (q > -0.160) { band = 'C'; verdict = 'May need optical aid to find'; }
    else if (q > -0.232) { band = 'D'; verdict = 'Needs optical aid'; }
    else if (q > -0.293) { band = 'E'; verdict = 'Not visible, even with a telescope'; }
    else                 { band = 'F'; verdict = 'Below the Danjon limit'; }

    return {
        bestTime: best, lagMinutes: lag * 1440, arcl: arcl, arcv: arcv, daz: daz,
        width: w, q: q, band: band, verdict: verdict,
        moonAlt: s.moon.alt, sunAlt: s.sun.alt, illum: s.illum.percent
    };
}

/* ── Naked-eye visibility right now ───────────────────────────────────── */

/* A plain-language read on whether the moon can actually be seen at this
   instant from this site: below the horizon, drowned in daylight, or up. */
function visibilityNow(snap) {
    var alt = snap.moon.altApparent, sunAlt = snap.sun.altApparent;
    var pct = snap.illum.percent, elong = snap.illum.elongation;

    if (alt < -snap.moon.semidiameterTopo)
        return { code: 'below', score: 0, headline: 'Below the horizon',
                 detail: 'The moon is ' + Math.abs(alt).toFixed(1) + '° below the horizon here.' };

    var sky = sunAlt > -0.833 ? 'day' : (sunAlt > -6 ? 'civil' : (sunAlt > -12 ? 'nautical' : (sunAlt > -18 ? 'astronomical' : 'night')));
    var score;

    if (sky === 'day') {
        /* Daylight: contrast falls off with elongation and low altitude. */
        score = Math.max(0, Math.min(100,
            (elong - 20) * 1.4 + (pct - 10) * 0.5 + Math.min(alt, 40) * 0.6));
        if (elong < 15 || pct < 3)
            return { code: 'daylight-lost', score: Math.round(score), sky: sky,
                     headline: 'Lost in the sun’s glare',
                     detail: 'Only ' + elong.toFixed(0) + '° from the sun in full daylight.' };
        return { code: 'daylight', score: Math.round(score), sky: sky,
                 headline: score > 55 ? 'Visible in daylight' : 'Faint in daylight',
                 detail: pct.toFixed(0) + '% lit, ' + elong.toFixed(0) + '° from the sun, ' +
                         alt.toFixed(0) + '° up.' };
    }

    score = Math.max(0, Math.min(100, 55 + pct * 0.35 + Math.min(alt, 45) * 0.5));
    if (alt < 5) score *= 0.6;
    /* Within half a degree of the horizon the moon is rising or setting, which
       says more than the phase does — this is what a rise/set row lands on. */
    var head = alt < 0.5 ? 'Right on the horizon' :
               (pct < 2 ? 'A hair-thin crescent' :
               (alt < 5 ? 'Low on the horizon' : 'Clearly visible'));
    return { code: 'visible', score: Math.round(score), sky: sky,
             headline: head,
             detail: pct.toFixed(1) + '% lit, ' + alt.toFixed(0) + '° above the ' +
                     compassPoint(snap.moon.az) + ' horizon.' };
}

var COMPASS = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
function compassPoint(az) { return COMPASS[Math.round(norm360(az) / 22.5) % 16]; }

/* ── Exports ──────────────────────────────────────────────────────────── */

root.MoonEph = {
    compute: compute,
    dayEvents: dayEvents,
    nextCrossing: nextCrossing,
    nextSunset: nextSunset,
    sunsetObservation: sunsetObservation,
    monthStart: monthStart,
    dayOfMonth: dayOfMonth,
    DEFAULTS: DEFAULTS,
    lastNewMoon: lastNewMoon,
    phaseInstant: phaseInstant,
    phaseK: phaseK,
    lunationNumber: lunationNumber,
    nextApsis: nextApsis,
    crescentVisibility: crescentVisibility,
    visibilityNow: visibilityNow,
    compassPoint: compassPoint,
    jdFromDate: jdFromDate,
    dateFromJD: dateFromJD,
    deltaT: deltaT,
    refraction: refraction,
    norm360: norm360, norm180: norm180,
    SYNODIC: SYNODIC
};

})(typeof module !== 'undefined' && module.exports ? module.exports : window);
