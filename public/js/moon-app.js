/* ═══════════════════════════════════════════════════════════════════════
 * moon-app.js — the /moon page. Depends on /js/moon-ephemeris.js.
 *
 * All the astronomy lives in the engine; this file is presentation:
 * location + time controls, the rendered disc, and its figures. Times are
 * always shown 24-hour, in the selected location's own zone (italic while
 * daylight saving is in effect) or in GMT.
 * ═══════════════════════════════════════════════════════════════════════ */
(function () {
'use strict';

var E = window.MoonEph;
var API = '/api/moon.php';
var D2R = Math.PI / 180;

var state = {
    locations: [], locKey: null, userKey: 0,
    lat: 31.778297, lon: 35.235494, elev: 740, tz: 'Asia/Jerusalem',
    jd: E.jdFromDate(new Date()),
    live: true, tzMode: 'local', moonHorizon: 'limb',
    events: null, obs: null, evening: null,
    addrCandidates: [], elevSource: null, momentRows: [], selectedMoment: null,
    lastCanvasJd: null
};

var el = {};
['locSelect','latInput','lonInput','elevInput','gpsBtn','dateInput','timeInput',
 'nowBtn','prevDay','nextDay','tzSelect','horizonSelect','ctlHint','consoleTitle',
 'moonCanvas','readout','jumpSelect','stepInput','stepMinus','stepPlus',
 'addrInput','addrFind','addrResults','momentsRail','deltaT']
    .forEach(function (id) { el[id] = document.getElementById(id); });

/* ── Time zone plumbing ───────────────────────────────────────────────── */

var offsetCache = {};
function tzOffsetMinutes(tz, date) {
    var key = tz + '|' + Math.floor(date.getTime() / 3600000);
    if (offsetCache[key] != null) return offsetCache[key];
    var off;
    try {
        var s = new Intl.DateTimeFormat('en-US', { timeZone: tz, timeZoneName: 'longOffset' })
                    .formatToParts(date).find(function (p) { return p.type === 'timeZoneName'; }).value;
        var m = /GMT([+-])(\d{1,2})(?::(\d{2}))?/.exec(s);
        off = m ? (m[1] === '-' ? -1 : 1) * (parseInt(m[2], 10) * 60 + parseInt(m[3] || '0', 10)) : 0;
    } catch (err) { off = 0; }
    offsetCache[key] = off;
    return off;
}

/* Standard time = the smallest offset the zone uses across the year; any
   larger offset means daylight saving is in effect. Works either hemisphere. */
var stdCache = {};
function standardOffset(tz, date) {
    var y = date.getUTCFullYear(), key = tz + '|' + y;
    if (stdCache[key] != null) return stdCache[key];
    var min = Infinity;
    for (var mo = 0; mo < 12; mo++) min = Math.min(min, tzOffsetMinutes(tz, new Date(Date.UTC(y, mo, 15))));
    stdCache[key] = min;
    return min;
}
function isDst(tz, date) { return tzOffsetMinutes(tz, date) > standardOffset(tz, date); }

function displayTz() { return state.tzMode === 'gmt' ? 'UTC' : state.tz; }

/* A browser has no timezone map, so a geocoded address gets the zone its
   longitude falls in: mean solar time to the nearest hour, no daylight saving.
   Named sites carry their real zone, so this only applies to searches. */
function zoneFromLongitude(lon) {
    var n = Math.round(lon / 15);
    if (!n) return 'UTC';
    /* Etc/GMT signs run backwards: Etc/GMT-3 is three hours ahead of UTC. */
    return 'Etc/GMT' + (n > 0 ? '-' : '+') + Math.abs(n);
}

function tzLabel() {
    if (state.tzMode === 'gmt') return 'GMT';
    var m = /^Etc\/GMT([+-])(\d+)$/.exec(state.tz);
    if (m) return 'UTC' + (m[1] === '-' ? '+' : '−') + m[2] + ', estimated from longitude';
    return state.tz;
}

/* Calendar/clock fields of an instant, in a given zone. */
function partsIn(date, tz) {
    var f = new Intl.DateTimeFormat('en-GB', {
        timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
    }).formatToParts(date).reduce(function (a, p) { a[p.type] = p.value; return a; }, {});
    return { y: +f.year, m: +f.month, d: +f.day,
             hh: +(f.hour === '24' ? '0' : f.hour), mm: +f.minute, ss: +f.second };
}

/* Wall-clock fields in a zone → the UTC instant they name. */
function zonedToUtc(y, m, d, hh, mm, tz) {
    var guess = Date.UTC(y, m - 1, d, hh, mm);
    var t = guess - tzOffsetMinutes(tz, new Date(guess)) * 60000;
    return guess - tzOffsetMinutes(tz, new Date(t)) * 60000;
}

var MONTHS = ['January','February','March','April','May','June','July',
              'August','September','October','November','December'];

function pad(n, w) { n = String(Math.abs(Math.trunc(n))); while (n.length < (w || 2)) n = '0' + n; return n; }

/* 24-hour clock, italicised while the zone is on daylight saving. */
function fmtTime(jd, withSeconds) {
    if (jd == null || !isFinite(jd)) return '<span class="v">&mdash;</span>';
    var date = E.dateFromJD(jd), tz = displayTz(), p = partsIn(date, tz);
    var t = pad(p.hh) + ':' + pad(p.mm) + (withSeconds ? ':' + pad(p.ss) : '');
    var dst = state.tzMode !== 'gmt' && isDst(tz, date);
    return '<span class="v' + (dst ? ' dst' : '') + '">' + t + '</span>';
}
function fmtDateLong(jd) {
    var p = partsIn(E.dateFromJD(jd), displayTz());
    return p.d + ' ' + MONTHS[p.m - 1] + ' ' + p.y;
}
/* Bare HH:MM, for places that cannot take markup (option labels). */
function clockText(jd, withSeconds) {
    if (jd == null || !isFinite(jd)) return '—';
    var p = partsIn(E.dateFromJD(jd), displayTz());
    return pad(p.hh) + ':' + pad(p.mm) + (withSeconds ? ':' + pad(p.ss) : '');
}
/* ── Angle formatting ─────────────────────────────────────────────────── */

function dms(x, degWidth) {
    if (x == null || !isFinite(x)) return '—';
    var sign = x < 0 ? '-' : '', a = Math.abs(x);
    var d = Math.floor(a), mF = (a - d) * 60, m = Math.floor(mF), s = Math.round((mF - m) * 60);
    if (s === 60) { s = 0; m++; }
    if (m === 60) { m = 0; d++; }
    return sign + pad(d, degWidth || 2) + '° ' + pad(m) + "' " + pad(s) + '"';
}
function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
}
/* ── Lunar albedo texture ─────────────────────────────────────────────── */
/* An equirectangular map built from the real selenographic positions of the
   maria and the brightest ray craters. It is a likeness, not imagery — but
   it is anchored to true coordinates, so libration swings the right features
   around the right limbs. */

var TEX_W = 720, TEX_H = 360, albedo = null;
var ALBEDO_SCALE = 200;   /* stored value = albedo x 200, so 1.275 is the ceiling */

var FEATURES = [
    /* lon, lat, radius-lon, radius-lat, albedo, edge softness */
    [-57,  18, 32, 34, 0.56, 0.55], [-16,  33, 17, 15, 0.50, 0.30],
    [ 17,  28, 12, 10, 0.50, 0.28], [ 30,   8, 14, 12, 0.52, 0.30],
    [ 52,  -8, 10, 12, 0.55, 0.30], [ 59,  17,  9,  7, 0.48, 0.18],
    [ 34, -15,  8,  7, 0.55, 0.28], [-39, -24,  8,  8, 0.55, 0.26],
    [-17, -21, 13,  8, 0.60, 0.40], [-23, -10,  7,  5, 0.60, 0.40],
    [  4,  13,  6,  5, 0.60, 0.40], [ -5,  56, 42,  4, 0.62, 0.50],
    [-32,  45,  8,  5, 0.62, 0.35], [-31,   8,  9,  7, 0.62, 0.45],
    [ 87,   2, 11, 11, 0.56, 0.35], [ 86,  13,  8,  8, 0.60, 0.35],
    [ 93, -50, 13, 13, 0.64, 0.45], [-95, -20, 11, 11, 0.60, 0.40],
    [-68,  -6,  4,  4, 0.44, 0.25], [ -9,  51,  3,  3, 0.50, 0.25],
    /* ray systems, then the bright crater floors inside them */
    [-11, -43, 30, 30, 1.06, 0.95], [-20,  10, 14, 14, 1.05, 0.95],
    [-47,  24,  7,  7, 1.08, 0.90], [-38,   8,  8,  8, 1.05, 0.92],
    [-11, -43,  2.6, 2.6, 1.32, 0.35], [-20, 10, 2.6, 2.6, 1.26, 0.35],
    [-47,  24,  1.6, 1.6, 1.40, 0.30], [-38,  8, 1.6, 1.6, 1.22, 0.30],
    [ 47,  16,  1.4, 1.4, 1.22, 0.30], [ 61,  -9,  2.2, 2.2, 1.14, 0.35]
];

function smoothstep(t) { return t <= 0 ? 0 : (t >= 1 ? 1 : t * t * (3 - 2 * t)); }

/* Cheap deterministic value noise, so the highlands are not a flat disc. */
function hash2(i, j) {
    var n = Math.sin(i * 127.1 + j * 311.7) * 43758.5453;
    return n - Math.floor(n);
}
function noise(x, y) {
    var xi = Math.floor(x), yi = Math.floor(y), xf = x - xi, yf = y - yi;
    var u = xf * xf * (3 - 2 * xf), v = yf * yf * (3 - 2 * yf);
    return (hash2(xi, yi) * (1 - u) + hash2(xi + 1, yi) * u) * (1 - v)
         + (hash2(xi, yi + 1) * (1 - u) + hash2(xi + 1, yi + 1) * u) * v;
}

function buildAlbedo() {
    albedo = new Uint8Array(TEX_W * TEX_H);
    for (var j = 0; j < TEX_H; j++) {
        var lat = 90 - (j + 0.5) * (180 / TEX_H);
        var cosLat = Math.cos(lat * D2R);
        for (var i = 0; i < TEX_W; i++) {
            var lon = -180 + (i + 0.5) * (360 / TEX_W);
            var a = 0.92
                  + 0.10 * (noise(lon / 7, lat / 7) - 0.5)
                  + 0.05 * (noise(lon / 2.2, lat / 2.2) - 0.5);
            for (var f = 0; f < FEATURES.length; f++) {
                var F = FEATURES[f];
                var dLon = ((lon - F[0] + 540) % 360 - 180) * cosLat;
                var dLat = lat - F[1];
                var d = Math.sqrt((dLon / F[2]) * (dLon / F[2]) + (dLat / F[3]) * (dLat / F[3]));
                if (d < 1) {
                    var w = smoothstep((1 - d) / Math.max(F[5], 1e-3));
                    /* Rays and highlands brighten; maria darken. */
                    a = F[4] < 1 ? a * (1 - w) + F[4] * w * (0.92 + 0.16 * noise(lon / 3, lat / 3))
                                 : a * (1 - w) + Math.min(1.45, a * F[4]) * w;
                }
            }
            albedo[j * TEX_W + i] = Math.min(255, a * ALBEDO_SCALE);
        }
    }
}

function sampleAlbedo(lon, lat) {
    var x = (lon + 180) / 360 * TEX_W - 0.5, y = (90 - lat) / 180 * TEX_H - 0.5;
    var x0 = Math.floor(x), y0 = Math.floor(y), fx = x - x0, fy = y - y0;
    var x1 = (x0 + 1) % TEX_W, y1 = Math.min(TEX_H - 1, y0 + 1);
    x0 = ((x0 % TEX_W) + TEX_W) % TEX_W;
    y0 = Math.max(0, Math.min(TEX_H - 1, y0));
    return ((albedo[y0 * TEX_W + x0] * (1 - fx) + albedo[y0 * TEX_W + x1] * fx) * (1 - fy)
          + (albedo[y1 * TEX_W + x0] * (1 - fx) + albedo[y1 * TEX_W + x1] * fx) * fy) / ALBEDO_SCALE;
}

/* The real thing: NASA's global albedo map, from the Lunar Reconnaissance
 * Orbiter's wide-angle camera (Goddard SVS "CGI Moon Kit", public domain),
 * reduced to 2048x1024. It replaces the drawn stand-in as soon as it arrives,
 * and if it never does the drawn one simply stays. Same equirectangular
 * layout, so the shading, libration and terminator are untouched.
 */
function loadAlbedoImage() {
    var img = new Image();
    img.onload = function () {
        try {
            var w = img.naturalWidth, h = img.naturalHeight;
            if (!w || !h) return;
            var c = document.createElement('canvas');
            c.width = w; c.height = h;
            var cx = c.getContext('2d');
            cx.drawImage(img, 0, 0);
            var px = cx.getImageData(0, 0, w, h).data;
            var arr = new Uint8Array(w * h), sum = 0, i, p;
            for (i = 0, p = 0; i < arr.length; i++, p += 4) {
                var lum = (px[p] * 0.299 + px[p + 1] * 0.587 + px[p + 2] * 0.114) / 255;
                arr[i] = lum * 255;
                sum += lum;
            }
            /* Match the average brightness the shading was tuned against, so
               swapping the map does not change how lit the disc looks. */
            var mean = sum / arr.length;
            var k = mean > 0.01 ? (0.80 / mean) * (ALBEDO_SCALE / 255) : 1;
            for (i = 0; i < arr.length; i++) arr[i] = Math.min(255, arr[i] * k);

            albedo = arr; TEX_W = w; TEX_H = h;
            state.lastCanvasJd = null;
            renderMoon(el.moonCanvas, E.compute(state.jd, site()));
        } catch (err) { /* keep the drawn map */ }
    };
    img.src = '/images/moon-albedo.jpg?v=1';
}

/* ── The disc ─────────────────────────────────────────────────────────── */

/* Drawn as it stands in the sky: celestial north up, celestial east to the
   left. The moon's pole is tilted by the position angle of its axis, the
   sub-earth point is displaced by the libration, and the terminator is the
   true one — the set of points where the sub-solar direction grazes the
   surface — so the phase, the tilt of the horns and the wobble all follow
   from the ephemeris rather than being drawn as a 2-D crescent. */
function renderMoon(canvas, snap) {
    if (!albedo) buildAlbedo();
    var size = canvas.width, ctx = canvas.getContext('2d');
    var img = ctx.createImageData(size, size), data = img.data;
    var R = size / 2 - 4, cx = size / 2, cy = size / 2;

    var lib = snap.librationTopo;
    var P = lib.pa * D2R, cosP = Math.cos(P), sinP = Math.sin(P);
    var l = lib.l * D2R, b = lib.b * D2R;

    /* Sub-earth direction and the east/north basis at that point. */
    var ex = Math.cos(b) * Math.cos(l), ey = Math.cos(b) * Math.sin(l), ez = Math.sin(b);
    var Ex = -Math.sin(l), Ey = Math.cos(l), Ez = 0;
    var Nx = -Math.sin(b) * Math.cos(l), Ny = -Math.sin(b) * Math.sin(l), Nz = Math.cos(b);

    /* Sub-solar direction, from the selenographic longitude/latitude of the sun. */
    var l0 = snap.selenographic.sunLon * D2R, b0 = snap.selenographic.sunLat * D2R;
    var sx = Math.cos(b0) * Math.cos(l0), sy = Math.cos(b0) * Math.sin(l0), sz = Math.sin(b0);

    var ashen = 0.030 * (1 - snap.illum.fraction);   /* earthshine on the night side */

    for (var py = 0; py < size; py++) {
        var yScreen = (cy - py - 0.5) / R;
        for (var px = 0; px < size; px++) {
            var xScreen = (px + 0.5 - cx) / R;
            var r2 = xScreen * xScreen + yScreen * yScreen;
            var o = (py * size + px) * 4;
            if (r2 >= 1.0004) { data[o + 3] = 0; continue; }

            var u = xScreen * cosP + yScreen * sinP;
            var v = -xScreen * sinP + yScreen * cosP;
            var w = Math.sqrt(Math.max(0, 1 - r2));

            var X = u * Ex + v * Nx + w * ex;
            var Y = u * Ey + v * Ny + w * ey;
            var Z = u * Ez + v * Nz + w * ez;

            var lat = Math.asin(Math.max(-1, Math.min(1, Z))) / D2R;
            var lon = Math.atan2(Y, X) / D2R;
            var alb = sampleAlbedo(lon, lat);

            var mu0 = X * sx + Y * sy + Z * sz;      /* cos of incidence */
            var val = ashen * alb;
            if (mu0 > 0) {
                /* Lommel-Seeliger: the reason a full moon looks flat rather
                   than like a lit ball. Softened across one pixel of terminator. */
                var edge = Math.min(1, mu0 / 0.015);
                val += 1.35 * alb * (mu0 / (mu0 + w + 1e-6)) * edge;
            }
            val = Math.max(0, Math.min(1, val));
            var g = Math.pow(val, 0.85) * 255;

            data[o]     = Math.min(255, g * 1.00);
            data[o + 1] = Math.min(255, g * 0.975);
            data[o + 2] = Math.min(255, g * 0.925);
            data[o + 3] = 255 * Math.max(0, Math.min(1, (1 - Math.sqrt(r2)) * R * 1.2 + 0.5));
        }
    }
    ctx.clearRect(0, 0, size, size);
    ctx.putImageData(img, 0, 0);
}

/* ── Panels ───────────────────────────────────────────────────────────── */

function site() { return { lat: state.lat, lon: state.lon, elev: state.elev }; }
function opts() { return { moonHorizon: state.moonHorizon }; }

var MOMENTS_HELP =
    '<p><b>How this panel works.</b> Pick a moment on the left and the disc, and every' +
    ' figure beside it, is drawn for that instant. The chosen moment takes the same' +
    ' dark ground as its figures, so the two read as one. <b>Selected time</b> at the' +
    ' head of the list is whatever the Time field at the top of the page is set to, and' +
    ' it moves as you type in that field, step it, or press Now.</p>' +
    '<p>Choosing a moment also moves the clock to it, so the rest of the page agrees.' +
    ' The moments run in the order they happen:</p>' +
    '<ul>' +
    '<li><b>Dawn</b> and <b>Dusk (astronomical)</b> — the sun 18° below the horizon,' +
    ' once on its way up and once on its way down. Dusk is the moment true darkness' +
    ' falls; dawn is when the sky first begins to lighten.</li>' +
    '<li><b>Sunrise</b> and <b>Sunset</b> — the upper limb on an ideal horizon. Sunset' +
    ' opens the next Towrah day.</li>' +
    '<li><b>Moonrise</b> and <b>Moonset</b> — the moon crossing the horizon, by' +
    ' whichever rule is set at the foot of the panel.</li>' +
    '<li><b>Moon transit</b> — when the moon crosses your meridian, due south or north' +
    ' of you and at its highest for the day.</li>' +
    '<li><b>Best Viewing</b> — Yallop’s epoch for judging a first sliver: four ninths of' +
    ' the way from sunset to moonset, when the sky has darkened but the moon has not yet' +
    ' sunk into the haze. The band letter beside the lit percentage is scored here.</li>' +
    '</ul>' +
    '<p>The moon rises about fifty minutes later each day, so a date can easily have a' +
    ' rise and no set, or neither; a moment that does not happen is greyed out.</p>' +
    '<p>Every figure in the middle column explains itself — hover its label. These are' +
    ' ideal-horizon figures: hills or buildings on your skyline will delay a rise and' +
    ' bring a set forward, and no atmospheric conditions are modelled.</p>';

var RULE_HELP =
    '<p><b>What counts as "on the horizon".</b> The moon has a visible width and' +
    ' the air bends its light, so the instant it rises or sets depends on which' +
    ' edge you measure and whether you allow for the atmosphere. This setting' +
    ' picks the rule, and it drives every moonrise and moonset on the page,' +
    ' including Best Viewing, which is measured from sunset to moonset.</p>' +
    '<p><b>Upper limb, 34′ refraction</b> — the almanac convention. The moment the' +
    ' top edge of the disc touches an ideal horizon, allowing 34 arcminutes for the' +
    ' atmosphere bending light around the curve of the earth. Because it is the top' +
    ' edge that is level, the centre of the disc is about 49′ below the horizon, which' +
    ' is why the altitude column reads slightly negative on those rows. This is what' +
    ' almanacs and the reference charts publish.</p>' +
    '<p><b>Centre of the disc at 0°</b> — the moment the middle of the moon reaches' +
    ' the horizon, with no allowance for refraction. This is the reading behind the' +
    ' statement that "the elevation at moonset is by definition 0°". It falls about' +
    ' four minutes earlier at moonset than the upper-limb rule.</p>' +
    '<p>Sunrise and sunset always use the upper-limb rule and are not affected by' +
    ' this choice. Neither rule allows for hills or buildings on your skyline, nor' +
    ' for the horizon dropping away when you stand on high ground.</p>';

/* Hover/tap explainer badge. tabindex makes it reachable by keyboard and
   openable by tap on a touch screen, where there is no hover. */
function helpBadge(html) {
    return '<span class="help" tabindex="0" role="button" aria-label="What this panel means">?' +
           '<span class="help-pop">' + html + '</span></span>';
}


/* The figures beside the disc. Each carries its own explainer, and the labels
   are built once so a popover cannot be torn out from under the pointer by the
   one-second tick — only the values are rewritten. */
var READOUT_FIELDS = [
    { key: 'visible', label: 'Visible',
      value: function (s) { return s.illum.percent.toFixed(4) + '%'; },
      help: '<p><b>Visible</b> is how much of the disc facing earth is in sunlight:' +
            ' 100% at full moon, 0% at the conjunction, and a fraction of one per cent' +
            ' for the sliver either side of it.</p>' +
            '<p>It is a fact about the moon and the sun, not about you — it is quoted' +
            ' whether the moon is overhead, below the horizon, or lost in daylight. The' +
            ' <b>Visibility</b> line below says whether it can be seen from here.</p>' },
    { key: 'distance', label: 'Distance',
      value: function (s) { return Math.round(s.moon.distKm).toLocaleString('en-US') + ' km'; },
      help: '<p><b>Distance</b> from the centre of the earth to the centre of the moon.</p>' +
            '<p>The orbit is an ellipse, so this swings between roughly 356,500 km at its' +
            ' nearest and 406,700 km at its farthest — a difference of about 14%, which is' +
            ' why the disc measurably changes size through the month and why a full moon at' +
            ' its nearest is noticeably brighter.</p>' },
    { key: 'altitude', label: 'Altitude',
      value: function (s) { return dms(s.moon.alt); },
      help: '<p><b>Altitude</b> is the angle of the centre of the disc above the horizon:' +
            ' 0° on the horizon, 90° straight overhead, negative when the moon is below the' +
            ' horizon and out of sight.</p>' +
            '<p>It is topocentric — measured from where you stand on the surface, not from' +
            ' the centre of the earth. On the moonrise and moonset rows it reads slightly' +
            ' negative, because those rules put the <i>top edge</i> of the disc on the' +
            ' horizon while the centre is still below it.</p>' },
    { key: 'azimuth', label: 'Azimuth',
      value: function (s) { return dms(s.moon.az, 3) + ' ' + E.compassPoint(s.moon.az); },
      help: '<p><b>Azimuth</b> is the compass bearing to the moon, measured round the' +
            ' horizon from north through east: 0° is due north, 90° east, 180° south,' +
            ' 270° west. The compass point beside it says the same thing in words.</p>' +
            '<p>This is the direction to face. Together with the altitude it tells you' +
            ' exactly where to look.</p>' },
    { key: 'arcv', label: 'Arc of Vision',
      value: function (s) { return dms(s.moon.altGeocentric - s.sun.alt); },
      help: '<p><b>Arc of Vision</b> is how much higher the moon rides than the sun —' +
            ' their separation measured straight up the sky, ignoring how far apart they' +
            ' are along the horizon. Negative means the moon is the lower of the two.</p>' +
            '<p>It is the single best guide to whether a young crescent can be caught,' +
            ' because it decides how dark the sky is where the moon actually sits: the' +
            ' further the moon rides above the sun, the deeper the twilight behind it.' +
            ' Yallop’s criterion — the band letter beside the lit percentage — weighs this' +
            ' against the width of the crescent. Below roughly 10° at sunset, a first' +
            ' sliver is very hard indeed.</p>' +
            '<p>By that criterion’s convention it is reckoned from the centre of the' +
            ' earth, so it is not quite the difference between the topocentric altitude' +
            ' above and the sun’s.</p>' },

    { key: 'declination', label: 'Declination',
      value: function (s) { return dms(s.moon.decTopo); },
      help: '<p><b>Declination</b> is latitude on the sky: how far the moon lies north (+)' +
            ' or south (−) of the celestial equator, the line the earth’s equator traces' +
            ' among the stars.</p>' +
            '<p>Unlike altitude and azimuth it does not depend on the time of day — it is' +
            ' the moon’s place among the stars, and it sets how high the moon can climb' +
            ' from your latitude. The moon’s declination swings between about ±18° and' +
            ' ±28° over an 18.6-year cycle.</p>' },
    { key: 'diameter', label: 'Diameter',
      value: function (s) { return dms(s.moon.diameter); },
      help: '<p><b>Diameter</b> is how wide the disc appears in the sky — about half a' +
            ' degree, near enough the same as the sun, which is why eclipses can be total.</p>' +
            '<p>For scale, your little fingernail held at arm’s length covers roughly a' +
            ' degree: the moon is half of that. The figure grows and shrinks by about 12%' +
            ' through the month as the distance above it changes.</p>' },
    { key: 'parallax', label: 'Parallax',
      value: function (s) { return dms(s.moon.parallax); },
      help: '<p><b>Parallax</b> is how far the moon appears to shift because you observe it' +
            ' from the surface of the earth rather than from its centre — up to about one' +
            ' degree, which is two full moon-widths.</p>' +
            '<p>The shift is nil when the moon is overhead and greatest when it sits on the' +
            ' horizon, always pushing the moon down. It is the reason the figures here are' +
            ' topocentric, and why they differ from the geocentric ones an almanac prints.</p>' },

    { key: 'lib-ns', label: 'Libration N-S',
      value: function (s) { return dms(s.librationTopo.b); },
      help: '<p>The moon keeps one face toward us, but not a perfectly steady one.' +
            ' <b>Libration N-S</b> is how far it is nodding north or south at this' +
            ' moment — up to about 7°.</p>' +
            '<p>Positive tips the moon’s north pole toward us and lets us peer a little' +
            ' way over the northern limb; negative does the same at the south. It happens' +
            ' because the moon’s axis is tilted to its orbit, just as the earth’s is.</p>' },

    { key: 'lib-ew', label: 'Libration E-W',
      value: function (s) { return dms(s.librationTopo.l); },
      help: '<p><b>Libration E-W</b> is the side-to-side rocking, up to about 8°.</p>' +
            '<p>The moon spins at a steady rate but travels round its elliptical orbit at' +
            ' a varying one, so its rotation runs alternately a little ahead of and behind' +
            ' its position — and we see slightly round one limb, then the other. Positive' +
            ' swings the eastern limb into view. Between them, the two librations let us' +
            ' see about 59% of the surface over time instead of half.</p>' },

    { key: 'lib-pa', label: 'Libration P.A.',
      value: function (s) { return dms(s.librationTopo.totalPA, 3); },
      help: '<p><b>Libration P.A.</b> gives the direction of the nod and rock combined,' +
            ' measured from the moon’s north pole round through east.</p>' +
            '<p>It answers "which edge has swung into view just now": 0° means the far side' +
            ' has tipped up over the north limb, 90° over the east, 180° the south, 270°' +
            ' the west. Useful if you are hunting a feature that only shows at favourable' +
            ' libration.</p>' },

    { key: 'axis-pa', label: 'Pos.Ang. axis',
      value: function (s) { return dms(s.librationTopo.pa, 3); },
      help: '<p><b>Position angle of the axis</b> is the tilt of the moon’s north pole as' +
            ' it appears in the sky, measured from celestial north round through east.</p>' +
            '<p>It tells you which way is "up" on the moon relative to the sky, and the' +
            ' disc drawn here is turned by exactly this angle — so the maria sit where you' +
            ' would actually see them.</p>' },

    { key: 'colongitude', label: 'CoLongitude',
      value: function (s) { return dms(s.selenographic.colongitude, 3); },
      help: '<p><b>Selenographic colongitude</b> fixes where the sunrise line — the' +
            ' terminator — is standing on the moon, and so which craters are catching the' +
            ' dawn light.</p>' +
            '<p>It runs 0° at first quarter, 90° at full moon, 180° at last quarter and' +
            ' 270° at the conjunction, advancing about 12° a day. Observers use it to time' +
            ' a visit to a feature: everything looks its most dramatic when the sun is' +
            ' rising or setting over it and the shadows are long.</p>' },
];

/* The Yallop bands, shown against the lit percentage. */
var BAND_HELP =
    '<p><b>The letter is the crescent band</b> for this evening — how likely the' +
    ' renewed sliver is to be seen at all, judged at Best Viewing: four ninths of the' +
    ' way from sunset to moonset. It follows Yallop’s criterion (H.M. Nautical' +
    ' Almanac Office, Technical Note 69), which weighs how high the moon stands above' +
    ' the sun against how wide the crescent is.</p>' +
    '<ul>' +
    '<li><span class="band">A</span> — easily visible to the unaided eye</li>' +
    '<li><span class="band">B</span> — visible under perfect conditions</li>' +
    '<li><span class="band">C</span> — may need optical aid to find</li>' +
    '<li><span class="band">D</span> — needs optical aid</li>' +
    '<li><span class="band">E</span> — not visible, even through a telescope</li>' +
    '<li><span class="band">F</span> — below the Danjon limit: too close to the sun for' +
    ' a crescent to form at all</li>' +
    '</ul>' +
    '<p>It appears only on the evenings either side of the conjunction, when there is a' +
    ' first or last sliver to hunt. Atmospheric conditions are not modelled.</p>';

/* The band belongs to the evening, not to the instant, and only means anything
   when the moon is young or old enough for a sliver to be in question. */
function eveningBand() {
    var ev = state.evening;
    if (!ev || !ev.crescent) return null;
    return (ev.ageDays < 4 || ev.ageDays > E.SYNODIC - 2.5) ? ev.crescent : null;
}

var DELTAT_HELP =
    '<p><b>ΔT</b> is the gap between two kinds of time. <b>Terrestrial Time</b> is' +
    ' uniform, and the orbital theories that place the moon and sun are written in it,' +
    ' because gravity takes no notice of how fast the earth is spinning. <b>Universal' +
    ' Time</b> is clock time, tied to that spin.</p>' +
    '<p>The two drift apart, because the earth’s rotation is slightly irregular and is' +
    ' slowly braking against the tides. ΔT measures how far. The engine adds it when' +
    ' working out where the moon is, and leaves it out when working out which way the' +
    ' earth is facing — so the moon lands where it really is for the clock on your wall.' +
    ' Without it everything would sit over a minute out of place.</p>' +
    '<p>This figure is extrapolated from the standard Espenak–Meeus polynomial, which' +
    ' runs a few seconds high for the 2020s; the measured value is nearer 70 s. That' +
    ' difference moves the moon about 3 arcseconds and the rise and set times by well' +
    ' under a second, so nothing shown here is affected.</p>';

/* Built once — only the number is rewritten, so the explainer stays open while
   the clock ticks underneath it. */
function renderDeltaT() {
    if (!el.deltaT) return;
    if (!el.deltaT.getAttribute('data-built')) {
        el.deltaT.innerHTML = '<span class="dt-trigger" tabindex="0" role="button"' +
            ' aria-label="What delta T means">ΔT = <span data-dt></span>' +
            '<span class="help-pop">' + DELTAT_HELP + '</span></span>';
        el.deltaT.setAttribute('data-built', '1');
    }
    var cell = el.deltaT.querySelector('[data-dt]');
    if (cell) cell.textContent = E.deltaT(state.jd).toFixed(1) + 's';
}

function buildReadout() {
    /* The label itself is the explainer's trigger — no badge to clutter the
       readout. tabindex keeps it reachable by keyboard and openable by tap. */
    el.readout.innerHTML = READOUT_FIELDS.map(function (f) {
        return '<div class="row">' +
               '<span class="k has-help" tabindex="0" role="button"' +
               ' aria-label="What ' + esc(f.label) + ' means">' + esc(f.label) + ':' +
               '<span class="help-pop">' + f.help + '</span></span>' +
               '<span class="v"><span data-v="' + f.key + '"></span>' +
               (f.key === 'visible' ? '<span data-band-slot></span>' : '') +
               '</span></div>';
    }).join('');
    el.readout.setAttribute('data-built', '1');
}

function renderConsole(snap) {
    var t = el.consoleTitle;
    t.querySelector('.ct-date').textContent = fmtDateLong(state.jd);
    t.querySelector('.ct-phase').textContent = ' — ' + snap.illum.name;

    renderDeltaT();
    if (!el.readout.getAttribute('data-built')) buildReadout();
    READOUT_FIELDS.forEach(function (f) {
        var cell = el.readout.querySelector('[data-v="' + f.key + '"]');
        if (cell) cell.textContent = f.value(snap);
    });

    /* Rewritten only when the letter itself changes, so hovering it is not
       interrupted by the one-second tick. */
    var slot = el.readout.querySelector('[data-band-slot]');
    if (slot) {
        var band = eveningBand(), letter = band ? band.band : '';
        if (slot.getAttribute('data-letter') !== letter) {
            slot.setAttribute('data-letter', letter);
            slot.innerHTML = letter
                ? ' <span class="band-tag" tabindex="0" role="button"' +
                  ' aria-label="Crescent visibility band ' + letter + '">(' + letter + ')' +
                  '<span class="help-pop">' + BAND_HELP + '</span></span>'
                : '';
        }
    }
}

/* The moments worth looking at on the selected date, in the order they happen,
   with the selected time itself at the head of the list. */
function momentRows(snap) {
    var ev = state.events, rows = [
        { key: 'selected', label: 'Selected time', jd: state.jd, snap: snap }
    ];
    var events = [];
    if (ev) {
        events.push({ key: 'moonrise', label: 'Moonrise', jd: ev.moon.rise });
        events.push({ key: 'transit', label: 'Moon transit', jd: ev.moon.transit ? ev.moon.transit.jd : null });
        events.push({ key: 'sunrise', label: 'Sunrise', jd: ev.sun.rise });
        events.push({ key: 'sunset', label: 'Sunset', jd: ev.sun.set });
        /* From TODAY's sunset, not the sunset that opened the current Towrah
           day — otherwise, before sunset, this would carry yesterday evening's
           time and sort ahead of this morning's moonrise. */
        var evening = state.evening;
        if (evening && evening.crescent) {
            events.push({ key: 'best', label: 'Best Viewing', jd: evening.crescent.bestTime,
                          band: evening.crescent });
        }
        events.push({ key: 'moonset', label: 'Moonset', jd: ev.moon.set });

        /* Astronomical twilight has two crossings: the sun on its way up before
           sunrise, and on its way down after sunset — the second being the
           moment true darkness falls. */
        events.push({ key: 'astronomical-dawn', label: 'Dawn (astronomical)',
                      jd: ev.twilight.astronomical.start });
        events.push({ key: 'astronomical-dusk', label: 'Dusk (astronomical)',
                      jd: ev.twilight.astronomical.end });
    }
    /* Chronological; anything that does not happen today sinks to the bottom. */
    events.sort(function (a, b) {
        if (a.jd == null) return b.jd == null ? 0 : 1;
        if (b.jd == null) return -1;
        return a.jd - b.jd;
    });
    return rows.concat(events);
}

function renderMomentsRail(snap) {
    var rows = momentRows(snap);
    state.momentRows = rows;
    var chosen = state.selectedMoment || 'selected';

    el.momentsRail.innerHTML = rows.map(function (r, i) {
        if (r.jd == null) {
            return '<div class="moment-item is-missing">' +
                   '<span class="mi-label">' + esc(r.label) + '</span>' +
                   '<span class="mi-time">—</span></div>';
        }
        var on = r.key === chosen;
        return '<button type="button" class="moment-item' + (on ? ' on' : '') + '"' +
               ' role="tab" aria-selected="' + (on ? 'true' : 'false') + '" data-i="' + i + '">' +
               '<span class="mi-label">' + esc(r.label) + '</span>' +
               '<span class="mi-time">' + clockText(r.jd, true) + '</span></button>';
    }).join('');
}

/* ── Recompute ────────────────────────────────────────────────────────── */

function localDayWindow() {
    var p = partsIn(E.dateFromJD(state.jd), displayTz());
    var startMs = zonedToUtc(p.y, p.m, p.d, 0, 0, displayTz());
    return [startMs / 86400000 + 2440587.5, (startMs + 86400000) / 86400000 + 2440587.5];
}

var heavyToken = 0;
function recomputeHeavy() {
    var win = localDayWindow();
    state.events = E.dayEvents(win[0], win[1], site(), opts());

    /* The day that opens at the last sunset on or before the selected time,
       so an evening after sunset belongs to the day it began. */
    var sunset = state.events.sun.set;
    if (sunset == null || sunset > state.jd) {
        var prior = E.nextSunset(win[0] - 1.05, site(), opts());
        if (prior != null && prior <= state.jd) sunset = prior;
    }
    if (sunset == null) sunset = state.events.sun.set;

    state.obs = sunset != null ? E.sunsetObservation(sunset, site(), opts()) : null;
    /* The evening of the calendar day on screen, which the Key Moments table
       tabulates. Usually the same observation as state.obs; only different
       when the selected time is before today's sunset. */
    state.evening = (state.events.sun.set == null) ? null
        : (state.obs && state.obs.sunset === state.events.sun.set
              ? state.obs
              : E.sunsetObservation(state.events.sun.set, site(), opts()));
}

function renderAll(full) {
    var snap = E.compute(state.jd, site());

    renderConsole(snap);
    renderMomentsRail(snap);
    if (full) {
        fillJumpMenu();
    }

    /* The disc changes far too slowly to redraw every tick. */
    if (state.lastCanvasJd == null || Math.abs(state.jd - state.lastCanvasJd) > 30 / 86400) {
        renderMoon(el.moonCanvas, snap);
        state.lastCanvasJd = state.jd;
    }
}

function refresh(heavy, full) {
    if (heavy) recomputeHeavy();
    renderAll(heavy || full !== false);
}

/* ── Controls ─────────────────────────────────────────────────────────── */

/* Never write over a field the user is in. In live mode syncInputs runs every
   second, and rewriting the date box mid-edit resets it — so typing a year one
   digit at a time only ever changed the digit last pressed. */
function setField(node, value) {
    if (!node || node === document.activeElement || node.value === value) return;
    node.value = value;
}

function syncInputs() {
    setField(el.latInput, state.lat.toFixed(6));
    setField(el.lonInput, state.lon.toFixed(6));
    setField(el.elevInput, String(Math.round(state.elev)));
    var p = partsIn(E.dateFromJD(state.jd), displayTz());
    setField(el.dateInput, p.y + '-' + pad(p.m) + '-' + pad(p.d));
    setField(el.timeInput, pad(p.hh) + ':' + pad(p.mm));
    el.nowBtn.classList.toggle('active', state.live);
    el.nowBtn.title = state.live
        ? 'Following the clock — click to freeze at this time'
        : 'Jump to the present time and follow the clock';
    el.nowBtn.setAttribute('aria-pressed', state.live ? 'true' : 'false');
    var elevNote = state.elevSource ? ' Elevation from <b>' + esc(state.elevSource) + '</b>.' : '';
    el.ctlHint.innerHTML = 'Times in <b>' + esc(tzLabel()) + '</b>' +
        (state.tzMode !== 'gmt' && isDst(state.tz, E.dateFromJD(state.jd))
            ? ' — <i>daylight saving in effect</i>' : '') +
        '.' + elevNote;
}

/* Accepts 21:30, 2130, 9:5 — anything that names a 24-hour time. */
function parseClock(s) {
    var m = /^\s*(\d{1,2})\s*[:.]?\s*(\d{1,2})?/.exec(String(s || ''));
    if (!m) return null;
    var hh = +m[1], mm = m[2] == null ? 0 : +m[2];
    if (m[2] == null && m[1].length > 2) { hh = +m[1].slice(0, 2); mm = +m[1].slice(2); }
    if (hh > 23 || mm > 59) return null;
    return { hh: hh, mm: mm };
}

/* The four turning points of the selected day, with their times on the option
   so the menu doubles as a summary of when they happen. */
function fillJumpMenu() {
    var ev = state.events;
    var items = [
        ['Sunrise',  ev && ev.sun.rise],
        ['Sunset',   ev && ev.sun.set],
        ['Moonrise', ev && ev.moon.rise],
        ['Moonset',  ev && ev.moon.set]
    ];
    el.jumpSelect.innerHTML = '<option value="">Select an event…</option>' + items.map(function (it) {
        var jd = it[1];
        return '<option value="' + (jd == null ? '' : jd) + '"' + (jd == null ? ' disabled' : '') +
               '>' + esc(it[0] + ' — ' + (jd == null ? 'none today' : clockText(jd))) + '</option>';
    }).join('');
    el.jumpSelect.value = '';
}

/* ── Address lookup ───────────────────────────────────────────────────── */

/* Any hand-entered position is a "Custom…" one — it no longer belongs to the
   saved site that was chosen from the list. */
function markCustom() {
    el.locSelect.value = 'custom';
    state.locKey = null;
}

function hideAddrResults() { el.addrResults.hidden = true; el.addrResults.innerHTML = ''; }
function showAddrResults(html) { el.addrResults.innerHTML = html; el.addrResults.hidden = false; }

var addrTimer = null, addrLastQuery = '';

function searchAddress() {
    var q = el.addrInput.value.trim();
    if (q.length < 3) { hideAddrResults(); return; }
    addrLastQuery = q;
    showAddrResults('<div class="msg">Searching…</div>');
    fetch(API + '?action=geocode&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (el.addrInput.value.trim() !== addrLastQuery) return;   /* stale reply */
            var list = d.results || [];
            state.addrCandidates = list;
            if (!list.length) {
                showAddrResults('<div class="msg">No match anywhere in the worldwide' +
                                ' address index. Try adding a town or country.</div>');
                return;
            }
            var approx = false;
            showAddrResults(list.map(function (r, i) {
                if (r.approx) approx = true;
                return '<button type="button" data-i="' + i + '">' + esc(r.address) +
                       (r.approx ? ' <em>(nearest match)</em>' : '') + '</button>';
            }).join('') +
            '<div class="msg">' +
            (approx ? 'No exact match — showing the nearest street or town. ' : '') +
            'Worldwide search — OpenStreetMap contributors and the US Census Bureau' +
            '</div>');
        })
        .catch(function () {
            showAddrResults('<div class="msg">Address lookup is unavailable just now — ' +
                            'you can still type coordinates directly.</div>');
        });
}

/* Ground height for the current position, looked up after the fact so the page
   does not wait on it. A late reply for somewhere we have already moved away
   from is dropped. */
function fetchElevation(lat, lon) {
    fetch(API + '?action=elevation&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lon),
          { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
            if (!d || d.elevation == null) return;
            if (Math.abs(state.lat - lat) > 1e-6 || Math.abs(state.lon - lon) > 1e-6) return;
            state.elev = d.elevation;
            state.elevSource = d.source;
            syncInputs();
            refresh(true);
        })
        .catch(function () { /* leave the elevation as it stands */ });
}

/* A picked address sets the position but says nothing about height, and the
   geocoder returns no timezone, so both are reset rather than carried over
   from whatever site was selected before. Height is then fetched separately. */
function applyAddress(r) {
    state.lat = r.lat;
    state.lon = r.lon;
    state.elev = 0;
    state.elevSource = null;
    /* A US match carries its real zone, daylight saving and all; anywhere else
       falls back to the hour of longitude. */
    state.tz = r.timezone || zoneFromLongitude(r.lon);
    markCustom();
    el.addrInput.value = r.address;
    addrLastQuery = r.address;
    hideAddrResults();
    state.lastCanvasJd = null;
    syncInputs();
    refresh(true);
    fetchElevation(r.lat, r.lon);
}

function setFromInputs() {
    var y = el.dateInput.value.split('-'), t = parseClock(el.timeInput.value);
    if (y.length === 3 && t) {
        var ms = zonedToUtc(+y[0], +y[1], +y[2], t.hh, t.mm, displayTz());
        state.jd = ms / 86400000 + 2440587.5;
    }
}

function bind() {
    el.locSelect.addEventListener('change', function () {
        var loc = state.locations.filter(function (l) {
            return String(l.moon_location_key) === el.locSelect.value;
        })[0];
        if (!loc) return;               /* "Custom location" */
        state.locKey = loc.moon_location_key;
        state.lat = loc.moon_location_lat;
        state.lon = loc.moon_location_lon;
        state.elev = loc.moon_location_elevation_m || 0;
        state.elevSource = null;
        state.tz = loc.moon_location_timezone || state.tz;
        el.addrInput.value = loc.moon_location_address || loc.moon_location_name || '';
        addrLastQuery = el.addrInput.value;
        hideAddrResults();
        try { localStorage.setItem('yy-moon-loc', String(state.locKey)); } catch (e) {}
        state.lastCanvasJd = null;
        syncInputs(); refresh(true);
    });

    /* Typing an address means the position is no longer the chosen site. */
    el.addrInput.addEventListener('input', function () {
        markCustom();
        hideAddrResults();
        if (addrTimer) clearTimeout(addrTimer);
        addrTimer = setTimeout(searchAddress, 600);
    });
    el.addrInput.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (addrTimer) clearTimeout(addrTimer);
        searchAddress();
    });
    el.addrFind.addEventListener('click', function () {
        if (addrTimer) clearTimeout(addrTimer);
        searchAddress();
    });
    el.addrResults.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('button[data-i]') : null;
        if (!b) return;
        var r = state.addrCandidates[+b.getAttribute('data-i')];
        if (r) applyAddress(r);
    });

    ['latInput', 'lonInput', 'elevInput'].forEach(function (id) {
        el[id].addEventListener('change', function () {
            var lat = parseFloat(el.latInput.value), lon = parseFloat(el.lonInput.value);
            var elev = parseFloat(el.elevInput.value);
            if (isFinite(lat)) state.lat = Math.max(-90, Math.min(90, lat));
            if (isFinite(lon)) state.lon = Math.max(-180, Math.min(180, lon));
            if (isFinite(elev) && elev !== state.elev) { state.elev = elev; state.elevSource = null; }
            markCustom();
            el.addrInput.value = '';
            hideAddrResults();
            state.lastCanvasJd = null;
            syncInputs(); refresh(true);
        });
    });

    el.gpsBtn.addEventListener('click', function () {
        if (!navigator.geolocation) { el.ctlHint.innerHTML = '<span class="err">This browser will not share a location.</span>'; return; }
        el.ctlHint.textContent = 'Asking for your location…';
        navigator.geolocation.getCurrentPosition(function (pos) {
            state.lat = pos.coords.latitude;
            state.lon = pos.coords.longitude;
            var haveAltitude = pos.coords.altitude != null;
            if (haveAltitude) { state.elev = pos.coords.altitude; state.elevSource = 'this device'; }
            else { state.elevSource = null; }
            try { state.tz = Intl.DateTimeFormat().resolvedOptions().timeZone || state.tz; } catch (e) {}
            markCustom();
            el.addrInput.value = '';
            hideAddrResults();
            state.lastCanvasJd = null;
            syncInputs(); refresh(true);
            /* Most browsers report no altitude, so ask the elevation service. */
            if (!haveAltitude) fetchElevation(state.lat, state.lon);
        }, function (err) {
            el.ctlHint.innerHTML = '<span class="err">Could not read your location (' + esc(err.message) + ').</span>';
        }, { enableHighAccuracy: true, timeout: 10000 });
    });

    el.dateInput.addEventListener('change', function () { state.live = false; state.selectedMoment = null; setFromInputs(); syncInputs(); refresh(true); });
    el.timeInput.addEventListener('change', function () { state.live = false; state.selectedMoment = null; setFromInputs(); syncInputs(); refresh(true); });
    el.timeInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') el.timeInput.dispatchEvent(new Event('change')); });
    /* Follow the field as it is typed, but only once it names a whole time —
       reacting to a half-typed "2" would yank the page to 02:00. syncInputs is
       left to the change event so the caret is not moved mid-keystroke. */
    el.timeInput.addEventListener('input', function () {
        if (!/^\s*\d{1,2}[:.]\d{2}\s*$|^\s*\d{4}\s*$/.test(el.timeInput.value)) return;
        state.live = false;
        state.selectedMoment = null;
        el.nowBtn.classList.remove('active');
        setFromInputs();
        refresh(true);
    });

    el.prevDay.addEventListener('click', function () { state.live = false; state.selectedMoment = null; state.jd -= 1; syncInputs(); refresh(true); });
    el.nextDay.addEventListener('click', function () { state.live = false; state.selectedMoment = null; state.jd += 1; syncInputs(); refresh(true); });

    el.momentsRail.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('button.moment-item') : null;
        if (!b) return;
        var r = state.momentRows[+b.getAttribute('data-i')];
        if (!r || r.jd == null) return;
        state.live = false;
        state.selectedMoment = r.key === 'selected' ? null : r.key;
        state.jd = r.jd;
        syncInputs();
        refresh(true);
    });

    el.jumpSelect.addEventListener('change', function () {
        var jd = parseFloat(el.jumpSelect.value);
        if (!isFinite(jd)) return;
        state.live = false;
        state.selectedMoment = null;
        state.jd = jd;
        syncInputs(); refresh(true);
    });

    /* Nudge the clock by the number of minutes in the step box. */
    function stepBy(sign) {
        var m = parseInt(el.stepInput.value, 10);
        if (!isFinite(m) || m < 1) { m = 10; el.stepInput.value = '10'; }
        state.live = false;
        state.selectedMoment = null;
        state.jd += sign * m / 1440;
        syncInputs(); refresh(true);
    }
    el.stepMinus.addEventListener('click', function () { stepBy(-1); });
    el.stepPlus.addEventListener('click', function () { stepBy(1); });

    el.nowBtn.addEventListener('click', function () {
        state.live = !state.live;
        state.selectedMoment = null;
        if (state.live) { state.jd = E.jdFromDate(new Date()); syncInputs(); refresh(true); }
        else syncInputs();
    });

    el.tzSelect.addEventListener('change', function () {
        state.tzMode = el.tzSelect.value;
        syncInputs(); refresh(true);
    });

    if (el.horizonSelect) el.horizonSelect.addEventListener('change', function () {
        state.moonHorizon = el.horizonSelect.value === 'centre' ? 0 : 'limb';
        refresh(true);
    });

    setInterval(function () {
        if (!state.live) return;
        state.selectedMoment = null;
        var before = partsIn(E.dateFromJD(state.jd), displayTz()).d;
        state.jd = E.jdFromDate(new Date());
        var after = partsIn(E.dateFromJD(state.jd), displayTz()).d;
        syncInputs();
        refresh(before !== after, false);
    }, 1000);
}

/* ── Data from the server ─────────────────────────────────────────────── */

function loadLocations() {
    return fetch(API + '?action=locations', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            state.locations = d.locations || [];
            state.userKey = d.user_key || 0;
            var saved = null;
            try { saved = localStorage.getItem('yy-moon-loc'); } catch (e) {}
            var chosen = null;
            state.locations.forEach(function (l) {
                if (saved && String(l.moon_location_key) === saved) chosen = l;
                if (!chosen && !saved && l.moon_location_default_flag) chosen = l;
            });
            if (!chosen && state.locations.length) chosen = state.locations[0];
            el.locSelect.innerHTML = state.locations.map(function (l) {
                return '<option value="' + l.moon_location_key + '">' + esc(l.moon_location_name) + '</option>';
            }).join('') + '<option value="custom">Custom location…</option>';
            if (chosen) {
                el.locSelect.value = String(chosen.moon_location_key);
                state.locKey = chosen.moon_location_key;
                state.lat = chosen.moon_location_lat;
                state.lon = chosen.moon_location_lon;
                state.elev = chosen.moon_location_elevation_m || 0;
                state.tz = chosen.moon_location_timezone || state.tz;
                el.addrInput.value = chosen.moon_location_address || chosen.moon_location_name || '';
                addrLastQuery = el.addrInput.value;
            }
        })
        .catch(function () { /* seeded defaults already in state */ });
}

/* ── Boot ─────────────────────────────────────────────────────────────── */

loadLocations().then(function () {
    bind();
    /* Key Moments has no verdict banner to hang the badge off, so it goes in
       the panel heading — added once, since the heading is never rewritten. */
    var consoleTitle = document.querySelector('.console-title');
    if (consoleTitle) consoleTitle.insertAdjacentHTML('beforeend', helpBadge(MOMENTS_HELP));
    var ruleLabel = document.querySelector('.console-foot label');
    if (ruleLabel) ruleLabel.insertAdjacentHTML('beforeend', helpBadge(RULE_HELP));
    syncInputs();
    refresh(true);
    loadAlbedoImage();
});

})();
