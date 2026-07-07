<?php
/**
 * Shared flipbook shell. Every per-book index.php is a 4-line wrapper
 * that sets $FB and requires this file:
 *
 *   $FB = [
 *       'total'         => 385,
 *       'title'         => 'Beyth — In the Family',
 *       'bookCode'      => 'YY-s02v03-Yada-Yahowah-Beyth-In-the-Family',
 *       'bundleVersion' => '202605131618', // optional
 *   ];
 *   require __DIR__ . '/../_shared/flipbook-frame.php';
 *
 * Bumping a script's ?v= or tweaking shared markup only requires editing
 * THIS file. Every book picks up the change on its next pageview.
 */
if (!isset($FB) || !is_array($FB)) {
    http_response_code(404);
    exit('Not found');
}

// ── Admin-configurable audio-player colors ───────────────────────────
// The TTS player's circle buttons, icons, progress/volume bars, etc. use
// CSS custom properties with baked-in fallbacks (see /js/flipbook-tts.js).
// Any 'player-*' override set on the admin Flipbook page (yy_setting,
// scope=page group=flipbook) is emitted here as :root vars so it applies
// for every visitor — logged in or anonymous. config.php flips the
// Content-Type to JSON for API responses; this file is HTML, so re-assert
// it (same pattern as public/test/page.php).
require_once __DIR__ . '/../../api/config.php';
if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');

$PLAYER_VAR_MAP = [
    'player-btn-bg'         => '--fb-tts-btn-bg',
    'player-btn-bg-hover'   => '--fb-tts-btn-bg-hover',
    'player-btn-border'     => '--fb-tts-btn-border',
    'player-icon-color'     => '--fb-tts-icon-color',
    'player-track-bg'       => '--fb-tts-track-bg',
    'player-fill-bg'        => '--fb-tts-fill-bg',
    'player-thumb-bg'       => '--fb-tts-thumb-bg',
    'player-time-color'     => '--fb-tts-time-color',
    'player-pausing-bg'     => '--fb-tts-pausing-bg',
    'player-pausing-border' => '--fb-tts-pausing-border',
    'player-pausing-color'  => '--fb-tts-pausing-color',
    'player-highlight'      => '--fb-tts-highlight',
    'player-url-highlight'  => '--fb-tts-url-highlight',
];
$playerVars = '';
try {
    $ps = getDb()->prepare(
        "SELECT setting_code, setting_value FROM yy_setting
         WHERE setting_scope_code = 'page' AND setting_group_code = 'flipbook'
           AND setting_code LIKE 'player-%'"
    );
    $ps->execute();
    $decls = [];
    foreach ($ps->fetchAll() as $r) {
        $var = $PLAYER_VAR_MAP[$r['setting_code']] ?? null;
        $val = trim((string)($r['setting_value'] ?? ''));
        if (!$var || $val === '') continue;
        // Only allow plain color literals — never arbitrary CSS (injection guard).
        if (!preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,\s]+\))$/', $val)) continue;
        $decls[] = $var . ':' . $val;
    }
    if ($decls) $playerVars = ':root{' . implode(';', $decls) . '}';
} catch (Throwable $e) {
    $playerVars = ''; // fall back to the JS defaults on any DB error
}

// This shell references versioned asset URLs (?v=N). With no explicit
// cache header the browser heuristically caches the HTML, so a JS bump
// (e.g. tts 33 → 34) isn't picked up on a normal refresh — the stale HTML
// keeps pointing at the old ?v=. Force revalidation so every load re-reads
// the current asset versions. Cloudflare already treats this PHP response
// as DYNAMIC (uncached at the edge); this only governs the browser cache.
if (!headers_sent()) {
    header('Cache-Control: no-cache, must-revalidate');
}

$total    = (int)($FB['total'] ?? 1);
$title    = (string)($FB['title'] ?? 'Flipbook');
$bookCode = (string)($FB['bookCode'] ?? '');
$bv       = isset($FB['bundleVersion']) ? (string)$FB['bundleVersion'] : '';
$pdfPath  = '/pdf/' . $bookCode . '.pdf';
// Optional override: trim/letter books need ratio ≠ 9/6.
$pageRatio = isset($FB['pageRatio']) ? (float)$FB['pageRatio'] : null;

// ── Script versions in ONE place ─────────────────────────────────────
// Bump a number, push this file, every book gets the new JS on the
// next pageview. Cloudflare doesn't cache PHP responses by default, so
// users never see a stale script URL.
$JS_V  = [
    'viewer'    => 31,
    'bookmarks' => 21,
    'tts'       => 40,
];
$CSS_V = 6;

$h = function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

$configJson = json_encode(
    array_filter([
        'total'         => $total,
        'title'         => $title,
        'bookCode'      => $bookCode,
        'pdfPath'       => $pdfPath,
        'bundleVersion' => $bv !== '' ? $bv : null,
        'pageRatio'     => $pageRatio,
    ], function ($v) { return $v !== null; }),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= $h($title) ?> · Flipbook</title>
  <script>
    // Per-book config — read by /js/flipbook-viewer.js once it loads.
    window.FLIPBOOK_CONFIG = <?= $configJson ?>;
  </script>
  <link rel="stylesheet" href="/css/flipbook-viewer.css?v=<?= $CSS_V ?>">
<?php if ($playerVars !== ''): ?>
  <style id="fb-player-vars"><?= $playerVars ?></style>
<?php endif; ?>
</head>
<body>
  <header>
    <a class="home-link" href="/" title="Yada Yahowah home"><img src="/images/yy-logo-80x90.png" alt="Yada Yahowah"></a>
    <button class="icon" id="toggle-side" title="Contents / pages / search">☰</button>
    <div class="titlewrap">
      <h1><?= $h($title) ?></h1>
      <div class="meta">Self-hosted flipbook · <?= $total ?> pages · drag any text to select · click far edges or top corners to turn</div>
    </div>
    <div class="tools">
      <button id="resume-toolbar-btn" type="button" title="Return to your last bookmarked page">
        <span class="resume-full">↩ Return to Page <span id="resume-link"></span></span>
        <span class="resume-compact">↩ <span id="resume-link-compact"></span></span>
        <span class="x" id="resume-dismiss" title="Dismiss">✕</span>
      </button>
      <button class="icon" id="zoom-out" title="Zoom out">−</button>
      <input class="zoom-pct" id="zoom-pct" type="text" value="100%" title="Zoom — type a number 50-300 and press Enter">
      <button class="icon" id="zoom-in"  title="Zoom in">+</button>
      <button class="icon" id="mode"     title="Switch to single-page view" aria-pressed="false"><svg viewBox="0 0 24 18" width="22" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><rect x="7" y="2" width="10" height="14" rx="1"/></svg></button>
      <button class="icon" id="sound"    title="Page-flip sound" aria-pressed="false">🔇</button>
      <button class="icon" id="share"    title="Copy link"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg></button>
      <button class="icon" id="print"    title="Print"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg></button>
      <button class="icon" id="fullscreen" title="Fullscreen (F)"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 9 4 4 9 4"/><polyline points="20 9 20 4 15 4"/><polyline points="4 15 4 20 9 20"/><polyline points="20 15 20 20 15 20"/></svg></button>
      <button id="download" title="Download original PDF">↓ <span class="lbl">PDF</span></button>
    </div>
  </header>

  <aside>
    <div class="tabs">
      <div class="tab active" data-tab="toc">TOC</div>
      <div class="tab"        data-tab="thumbs">Pages</div>
      <div class="tab"        data-tab="search">Search</div>
      <div class="tab"        data-tab="bookmarks">Bookmarks</div>
    </div>
    <div class="body">
      <div id="toc-pane"    class="toc"></div>
      <div id="thumbs-pane" class="thumbs" style="display: none;"></div>
      <div id="search-pane" class="search" style="display: none;">
        <div class="input"><input id="search-input" type="search" placeholder="Search the book…" autocomplete="off"></div>
        <div class="summary" id="search-summary">Type at least 2 characters.</div>
        <div class="results" id="search-results"></div>
      </div>
      <div id="bookmarks-pane" style="display:none;"></div>
    </div>
  </aside>

  <main>
    <div id="status">Loading…</div>
    <div id="err"></div>
    <div id="book-frame">
      <div id="book"></div>
      <div id="carousel"></div>
      <div id="flip-zones">
        <div class="zone l" data-dir="prev" title="Previous page"><span class="arrow"></span></div>
        <div class="zone r" data-dir="next" title="Next page"><span class="arrow"></span></div>
      </div>
    </div>
  </main>

  <div id="toast"></div>
  <div id="zoom-toast"></div>
  <iframe id="print-frame" aria-hidden="true"></iframe>

  <nav>
    <button id="first" title="First page">«</button>
    <button id="prev"  title="Prev (←)"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 5 8 12 15 19"/></svg></button>
    <input id="seek" type="range" min="1" max="<?= $total ?>" value="1">
    <span class="pageinfo">
      <input type="text" id="pinput" value="1" title="Type a page number and press Enter">
      / <span id="ptotal"><?= $total ?></span>
    </span>
    <button id="next"  title="Next (→)"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 5 16 12 9 19"/></svg></button>
    <button id="last"  title="Last">»</button>
  </nav>

  <script src="https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.js"
          onerror="document.getElementById('err').style.display='block'; document.getElementById('err').textContent='Failed to load page-flip.browser.js';"></script>
  <script src="/js/flipbook-viewer.js?v=<?= $JS_V['viewer'] ?>"></script>
  <script src="/js/flipbook-bookmarks.js?v=<?= $JS_V['bookmarks'] ?>"></script>
  <script src="/js/flipbook-tts.js?v=<?= $JS_V['tts'] ?>"></script>
</body>
</html>
