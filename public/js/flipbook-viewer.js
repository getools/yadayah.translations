/* Self-hosted flipbook viewer — shared across every book. Per-book
 * config (page count, title, code, PDF path) comes from a tiny inline
 * script in each volume's index.html that sets window.FLIPBOOK_CONFIG.
 *
 * Boot order on each page:
  *   1. <script>window.FLIPBOOK_CONFIG = { ... };</script>  (per-book)
  *   2. <link rel="stylesheet" href="/css/flipbook-viewer.css">
  *   3. <script src=".../page-flip.browser.js">
  *   4. <script src="/js/flipbook-viewer.js">  (this file)
 *
 * Future bug fixes & feature additions to the viewer JS happen here only;
 * each volume's index.html stays as a thin shell. Bump the `?v=N` on the
 * <script src=> in the per-book HTML (and the shared CSS) to bust client
 * caches.
 */

// Top-level error reporting — caught by the showErr surface inside the
// load handler. Keeps the in-page error banner working without each
// volume's index.html needing its own inline reporter.
(function () {
  function pipe(prefix, msg, stack) {
    var el = document.getElementById('err');
    if (!el) return;
    el.style.display = 'block';
    el.textContent += prefix + ': ' + msg + '\n' + (stack || '') + '\n';
  }
  window.addEventListener('error', function (e) {
    pipe('onerror', e.message || String(e), e.error && e.error.stack);
  });
  window.addEventListener('unhandledrejection', function (e) {
    var r = e.reason; pipe('unhandled rejection', (r && r.message) || String(r), r && r.stack);
  });
})();

  window.addEventListener('load', async () => {
    try {
      if (!window.St || !window.St.PageFlip) { showErr('Library not loaded'); return; }

      // Per-book configuration is supplied by a tiny inline script in
      // each volume's index.html (window.FLIPBOOK_CONFIG). Bail clearly
      // if it's missing — that's a deploy bug, not a runtime condition.
      const cfg = window.FLIPBOOK_CONFIG || {};
      if (!cfg.total || !cfg.bookCode) {
        showErr('FLIPBOOK_CONFIG missing or incomplete on this page.');
        return;
      }
      const TOTAL      = cfg.total | 0;
      const PAD        = n => String(n).padStart(3, '0');
      const PAGE_RATIO = cfg.pageRatio || (9 / 6);   // 6"×9" paperback default
      const BOOK_CODE  = cfg.bookCode;
      const PDF_PATH   = cfg.pdfPath || ('/pdf/' + BOOK_CODE + '.pdf');
      const STORE_KEY  = 'flipbook:' + BOOK_CODE;
      // Bundle assets (page JPGs, per-page text JSON, search.json, toc.json)
      // are immutable once uploaded but get *replaced* whenever a new PDF
      // is processed. Without a version query, browsers serve the cached
      // copy of the old PDF's images for hours after a rebuild — making
      // search highlights land on stale image positions. rebuild_prototype.sh
      // bumps cfg.bundleVersion; we append it to every asset URL.
      const BV = cfg.bundleVersion ? ('?v=' + encodeURIComponent(cfg.bundleVersion)) : '';
      const bundleUrl = (path) => path + BV;

      // CAPTURE bookmark NOW — before pageFlip's 'init' event handler can
      // call syncUrlAndStorage(1) and overwrite it. The init event fires
      // during the first `await` later in this function (e.g. the toc.json
      // fetch), so any later read would already be clobbered to page 1.
      const _bookmarkAtLoad = parseInt(localStorage.getItem(STORE_KEY + ':page') || '0', 10);

      // CAPTURE original URL hash NOW for the same reason — the init handler's
      // syncUrlAndStorage(1) replaces location.hash with '#page=1' (or just
      // a chapter that maps to page 1), so by the time we get to the initial-
      // nav block below, the user's intended #chapter=… is already gone.
      const _hashAtLoad = location.hash;

      const bookEl     = document.getElementById('book');
      const carouselEl = document.getElementById('carousel');
      const bookFrame  = document.getElementById('book-frame');
      const main       = document.querySelector('main');
      let zoom = 1, toc = [];
      // Default to single-page (carousel) view; only stay in spread if the
      // user has explicitly chosen it during a prior session.
      let mode = localStorage.getItem(STORE_KEY + ':mode') === 'spread' ? 'spread' : 'carousel';

      // ── Build page DIVs (HTML mode) ──────────────────────────────────
      for (let i = 1; i <= TOTAL; i++) {
        const pg = document.createElement('div');
        pg.className = 'pg';
        pg.dataset.page = i;
        pg.innerHTML =
          `<img src="${bundleUrl('pages/page-' + PAD(i) + '.jpg')}" alt="Page ${i}" loading="lazy" decoding="async">` +
          `<div class="text-layer"></div>`;
        bookEl.appendChild(pg);
      }

      // ── Compute base book size that fits available space (zoom not
      // applied here — zoom is a CSS transform on top of the rendered book).
      function fitBook() {
        const availW = Math.max(100, main.clientWidth  - 32);
        const availH = Math.max(100, main.clientHeight - 32);
        const portrait = availW < 700;
        const ratio = portrait ? PAGE_RATIO : (PAGE_RATIO / 2);
        let bookW = availW, bookH = bookW * ratio;
        if (bookH > availH) { bookH = availH; bookW = bookH / ratio; }
        const pageW = portrait ? bookW : bookW / 2;
        return { pageW: Math.max(50, Math.floor(pageW)),
                 pageH: Math.max(50, Math.floor(pageW * PAGE_RATIO)) };
      }

      const initial = fitBook();
      const pageFlip = new St.PageFlip(bookEl, {
        width: initial.pageW, height: initial.pageH,
        size: 'fixed', maxShadowOpacity: 0.5, showCover: false,
        // usePortrait:false — once StPageFlip latches to portrait at init
        // (because bookEl was 0-wide before layout settled), update() does
        // not reliably re-evaluate. Spread mode is gated to desktop anyway
        // (mobile users default to carousel and the mode toggle is hidden
        // below 700px), so forcing landscape here removes the latch bug.
        flippingTime: 700, mobileScrollSupport: true, usePortrait: false, drawShadow: true,
        // Disable mouse-drag flipping so transparent text overlay can
        // capture mousedown for text selection. Touch swipe still flips
        // on mobile. Desktop navigation: arrow keys / buttons / TOC.
        useMouseEvents: false,
      });
      pageFlip.loadFromHTML(document.querySelectorAll('#book .pg'));
      document.getElementById('status').remove();

      // ── Apply zoom: CSS transform + grow book-frame so main can scroll.
      // In carousel mode the visible box is the carousel container, not bookEl.
      function applyZoom() {
        document.documentElement.style.setProperty('--zoom', zoom);
        requestAnimationFrame(() => {
          const src = (mode === 'carousel') ? carouselEl : bookEl;
          const w = src.offsetWidth  * zoom;
          const h = src.offsetHeight * zoom;
          bookFrame.style.width  = Math.ceil(w) + 'px';
          bookFrame.style.height = Math.ceil(h) + 'px';
          var zp = document.getElementById('zoom-pct');
          if (zp && document.activeElement !== zp) zp.value = Math.round(zoom * 100) + '%';
          positionFlipZones();
        });
      }

      // ── Single-page carousel: 5 fanned slots, center is the focused page.
      // The slot content (img + text-layer) is swapped on every navigation;
      // the slot transform is driven by --ox/--ry/--sc/--op CSS variables, so
      // smooth slide animations between positions come "for free" from the
      // CSS transition declared on .cpg.
      const CAROUSEL_RADIUS = 2;          // -2..+2 visible
      // With tilted previews the fan is foreshortened. Container width =
      // 2 × (oxBase[edge] + visibleHalf) + margin. With oxBase=1.75 and
      // ry=50° (visibleHalf ≈ 0.32 × pageW), wide ≈ 2 × (1.75 + 0.32) + 0.2
      // ≈ 4.4. Medium drops the n2 slots: 2 × (0.95 + 0.44) + 0.2 ≈ 3.0.
      const CAROUSEL_WIDTH_FACTOR  = 4.4;
      const CAROUSEL_MEDIUM_FACTOR = 3.0;
      const carouselSlots = [];
      const carouselText  = new Map();    // page → cached parsed text-layer dataset
      let carouselCenter  = 1;
      let carouselPageW = 0, carouselPageH = 0;

      function buildCarouselSlots() {
        carouselEl.innerHTML = '';
        carouselSlots.length = 0;
        for (let i = -CAROUSEL_RADIUS; i <= CAROUSEL_RADIUS; i++) {
          const slot = document.createElement('div');
          slot.className = 'cpg';
          slot.dataset.slot = i;
          slot.innerHTML = '<img alt="" decoding="async"><div class="text-layer"></div>';
          slot.addEventListener('click', () => {
            const p = parseInt(slot.dataset.page || '0', 10);
            if (p && p !== carouselCenter) goto(p);
          });
          carouselEl.appendChild(slot);
          carouselSlots.push(slot);
        }
      }

      function carouselTransform(offset) {
        // FlipHTML5-style fan: stronger rotateY on previews so they take less
        // horizontal space (foreshortened by perspective), letting all 5 fan
        // slots fit in a narrower viewport. Offsets are smaller to match —
        // previews tuck closer to the center page rather than splaying flat.
        const sign = Math.sign(offset);
        const abs  = Math.abs(offset);
        const oxBase = [0, 0.95, 1.75][abs] || (1.75 + (abs - 2) * 0.85);
        const ryBase = [0, 28,   50][abs]   || (50   + (abs - 2) * 8);
        const scBase = [1, 0.97, 0.92][abs] || Math.max(0.5, 0.92 - (abs - 2) * 0.08);
        const opBase = [1, 1,    0.85][abs] || Math.max(0, 0.85 - (abs - 2) * 0.4);
        return {
          ox: sign * oxBase * carouselPageW,
          ry: -sign * ryBase,            // right pages tilt their right edge away
          sc: scBase,
          op: opBase,
          shadeL: sign > 0 ? 0.10 : 0,   // darken outward edge of each neighbor
          shadeR: sign < 0 ? 0.10 : 0,
        };
      }

      function sizeCarousel() {
        const fit = fitOnePage();
        carouselPageW = fit.pageW;
        carouselPageH = fit.pageH;
        // Tier classes — CSS uses these to hide the appropriate preview slots.
        document.body.classList.toggle('carousel-narrow', fit.tier === 'narrow');
        document.body.classList.toggle('carousel-medium', fit.tier === 'medium');
        const factor = fit.tier === 'narrow' ? 1.0
                     : fit.tier === 'medium' ? CAROUSEL_MEDIUM_FACTOR
                                             : CAROUSEL_WIDTH_FACTOR;
        const w = Math.ceil(carouselPageW * factor);
        const h = carouselPageH;
        carouselEl.style.width  = w + 'px';
        carouselEl.style.height = h + 'px';
        for (const slot of carouselSlots) {
          slot.style.width  = carouselPageW + 'px';
          slot.style.height = carouselPageH + 'px';
        }
      }

      // Hysteresis on the tier transitions — without it, width sits right on
      // a boundary and oscillates as the layout rebuilds with/without a
      // scrollbar each pass. Once we've entered a tier, we stay until the
      // OPPOSITE threshold has a small buffer crossed.
      let _carouselTier = 'wide';   // 'wide' | 'medium' | 'narrow'
      function fitOnePage() {
        // One-page sizing — pick the largest pageW that fits inside main.
        // While the available width can hold the full fan, height is the
        // binding constraint and pageW = pageH / PAGE_RATIO. When width
        // becomes the constraint, drop preview-page tiers (n2 first, then
        // n1) instead of shrinking the center page.
        const availW = Math.max(120, main.clientWidth  - 32);
        const availH = Math.max(120, main.clientHeight - 32);
        const heightFitW = availH / PAGE_RATIO;
        const factorAvail = availW / heightFitW;  // how many pageW's fit
        // Boundary thresholds with a tiny (1%) buffer — just enough to avoid
        // single-pixel rounding flicker. Larger buffers strand users in a
        // lower tier after they expand back to their original width because
        // they never cross the higher up-threshold. scrollbar-gutter:stable
        // already prevents the layout-oscillation that originally motivated
        // a larger buffer.
        const WIDE_DOWN   = CAROUSEL_WIDTH_FACTOR;
        const WIDE_UP     = CAROUSEL_WIDTH_FACTOR * 1.01;
        const MEDIUM_DOWN = CAROUSEL_MEDIUM_FACTOR;
        const MEDIUM_UP   = CAROUSEL_MEDIUM_FACTOR * 1.01;
        let tier = _carouselTier;
        if (tier === 'wide') {
          if (factorAvail < WIDE_DOWN) tier = 'medium';
        } else if (tier === 'medium') {
          if (factorAvail >= WIDE_UP)        tier = 'wide';
          else if (factorAvail < MEDIUM_DOWN) tier = 'narrow';
        } else { // narrow
          if (factorAvail >= MEDIUM_UP) tier = 'medium';
        }
        _carouselTier = tier;
        let pageW, pageH;
        if (tier === 'narrow') {
          pageW = Math.min(heightFitW, availW);
          pageH = pageW * PAGE_RATIO;
        } else {
          pageH = availH;
          pageW = heightFitW;
        }
        return { pageW: Math.max(60, Math.floor(pageW)),
                 pageH: Math.max(60, Math.floor(pageH)),
                 tier: tier };
      }

      async function ensureCarouselText(page1, slot) {
        const layer = slot.querySelector('.text-layer');
        layer.innerHTML = '';
        if (page1 < 1 || page1 > TOTAL) return;
        let data = carouselText.get(page1);
        if (!data) {
          try { data = await fetch(bundleUrl(`text/page-${PAD(page1)}.json`)).then(r => r.json()); }
          catch { return; }
          carouselText.set(page1, data);
        }
        // Slot may have been re-assigned by the time fetch resolves.
        if (parseInt(slot.dataset.page || '0', 10) !== page1) return;
        const pageH = slot.clientHeight, pageW = slot.clientWidth;
        if (pageH < 10 || pageW < 10) return;
        const frag = document.createDocumentFragment();
        const fontTable = data.fonts || null;
        for (const sp of data.spans) {
          const x = sp[0], y = sp[1], h = sp[3], text = sp[4], flags = sp[5] | 0;
          const fontKey = sp.length >= 7 ? sp[6] : -1;
          const s = document.createElement('span');
          buildStyledSpanContent(s, text, flags);
          if (fontTable && fontKey >= 0 && fontTable[String(fontKey)]) {
            s.dataset.font = fontTable[String(fontKey)];
          }
          s.style.left = x + '%';
          s.style.top  = y + '%';
          s.style.fontSize = (h * pageH / 100) + 'px';
          frag.appendChild(s);
        }
        layer.appendChild(frag);
        const targets = data.spans.map(s => s[2] * pageW / 100);
        const kids = layer.children;
        for (let i = 0; i < kids.length; i++) {
          const intrinsic = kids[i].getBoundingClientRect().width;
          if (intrinsic > 1 && targets[i] > 1) kids[i].style.transform = 'scaleX(' + (targets[i]/intrinsic) + ')';
        }
        // Apply any active search highlight to this freshly-built carousel layer.
        if (typeof applySearchHighlight === 'function') { try { if (_currentSearchQuery) applySearchHighlight(); } catch (e) {} }
      }

      function updateCarousel(page1, { animate = true } = {}) {
        // Guard against being called before buildCarouselSlots has run —
        // happens when a ResizeObserver tick fires during initial layout.
        if (carouselSlots.length === 0) return;
        carouselCenter = Math.max(1, Math.min(TOTAL, page1));
        for (let i = -CAROUSEL_RADIUS; i <= CAROUSEL_RADIUS; i++) {
          const slot = carouselSlots[i + CAROUSEL_RADIUS];
          if (!slot) continue;  // belt-and-suspenders: skip any missing slot
          const page = carouselCenter + i;
          const inRange = (page >= 1 && page <= TOTAL);
          slot.classList.toggle('center', i === 0);
          slot.classList.toggle('n1',     Math.abs(i) === 1);
          slot.classList.toggle('n2',     Math.abs(i) >= 2);
          slot.classList.toggle('gone',  !inRange);
          const t = carouselTransform(i);
          slot.style.setProperty('--ox', t.ox + 'px');
          slot.style.setProperty('--ry', t.ry + 'deg');
          slot.style.setProperty('--sc', t.sc);
          slot.style.setProperty('--op', t.op);
          slot.style.setProperty('--shade-l', t.shadeL);
          slot.style.setProperty('--shade-r', t.shadeR);
          const img = slot.querySelector('img');
          const layer = slot.querySelector('.text-layer');
          if (inRange) {
            slot.dataset.page = page;
            const newSrc = bundleUrl(`pages/page-${PAD(page)}.jpg`);
            if (!img.src.endsWith(newSrc)) img.src = newSrc;
            slot.title = `Page ${page}`;
            // Only the focused page gets a selectable text layer — neighbors
            // are previews. Cheaper to render and avoids stray selections.
            if (i === 0) ensureCarouselText(page, slot);
            else         layer.innerHTML = '';
          } else {
            slot.dataset.page = '0';
            img.removeAttribute('src');
            layer.innerHTML = '';
          }
        }
        if (!animate) {
          // Skip the slide on initial render / mode switch by zeroing the
          // transition for one frame, then restoring it.
          for (const s of carouselSlots) s.style.transition = 'none';
          requestAnimationFrame(() => {
            for (const s of carouselSlots) s.style.transition = '';
          });
        }
      }

      function setMode(newMode, { silent = false, targetPage = null } = {}) {
        if (newMode !== 'spread' && newMode !== 'carousel') return;
        if (newMode === mode) return;
        // The caller can pass an explicit target so the carousel doesn't
        // get reset to currentPage() at a moment when currentPage() is
        // unreliable. Specifically: during init, when the user lands with
        // #page=356, goto(356) has already set carouselCenter=356 — but
        // here mode was just flipped to 'spread' as a workaround, so
        // currentPage() falls back to pageFlip.getCurrentPageIndex()+1
        // which is still 1. updateCarousel(1) would clobber 356.
        const wasPage = (targetPage != null) ? targetPage : currentPage();
        mode = newMode;
        document.body.classList.toggle('mode-carousel', mode === 'carousel');
        const btn = document.getElementById('mode');
        // SVG icons reflect the TARGET mode (what you'd switch to by clicking):
        //   In spread → show single-rect (clicking goes to single).
        //   In carousel → show two-rect (clicking goes to spread).
        const SPREAD_ICON = '<svg viewBox="0 0 24 18" width="22" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><rect x="2" y="2" width="9" height="14" rx="1"/><rect x="13" y="2" width="9" height="14" rx="1"/></svg>';
        const SINGLE_ICON = '<svg viewBox="0 0 24 18" width="22" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><rect x="7" y="2" width="10" height="14" rx="1"/></svg>';
        btn.innerHTML = (mode === 'carousel') ? SPREAD_ICON : SINGLE_ICON;
        btn.setAttribute('aria-pressed', mode === 'carousel' ? 'true' : 'false');
        btn.title = (mode === 'carousel')
          ? 'Switch to two-page spread'
          : 'Switch to single-page view';
        localStorage.setItem(STORE_KEY + ':mode', mode);
        if (mode === 'carousel') {
          sizeCarousel();
          updateCarousel(wasPage, { animate: false });
        } else {
          // Returning to spread — StPageFlip may have collapsed to portrait/
          // single-page while #book was hidden in carousel mode (its layout
          // was last computed against a 0-width container). Force a full
          // re-layout once #book is visible again so the spread renders at
          // the proper width centered in the main area, then jump back to
          // the page the user was on.
          requestAnimationFrame(() => {
            try { pageFlip.update(); } catch (e) {}
            try { pageFlip.turnToPage(Math.max(0, wasPage - 1)); } catch (e) {}
            applyZoom();
            positionFlipZones();
          });
        }
        applyZoom();
        if (!silent) update();
      }

      // ── Flip zones overlay the visible (transformed) book.
      // Re-positioned after every layout-affecting change so they always
      // cover the actual rendered book box, regardless of zoom or which
      // pages are currently being animated.
      const flipZones = document.getElementById('flip-zones');
      const leftZone  = flipZones.querySelector('.zone.l');
      const rightZone = flipZones.querySelector('.zone.r');
      function positionFlipZones() {
        // Zones cover the dark gap on each side of the visible page PLUS an
        // overlap onto the page's outer margin (PAGE_OVERLAP px) so the
        // click-target visibly laps onto the printed margin — clicking the
        // outermost slice of the page still flips. Anything farther in stays
        // free for text selection.
        // In carousel mode we anchor on the .cpg.center slot (StPageFlip's
        // bookEl is display:none then), so the flip zones still hug the
        // currently-displayed page.
        let b;
        if (mode === 'carousel') {
          const center = document.querySelector('#carousel .cpg.center');
          if (!center) return;
          b = center.getBoundingClientRect();
        } else {
          b = bookEl.getBoundingClientRect();
        }
        const f = bookFrame.getBoundingClientRect();
        const leftGap  = Math.max(0, b.left - f.left);
        const rightGap = Math.max(0, f.right - b.right);
        // On narrow viewports the page text runs near the outer edge, so a
        // 60px overlap eats into readable copy. Use a tighter overlap there
        // — about halfway between the original 60/40 and the very tight 22/18
        // we tried (which felt too thin to hit reliably).
        const isMobile = window.innerWidth <= 700;
        const PAGE_OVERLAP = isMobile ? 41 : 60;
        const MIN          = isMobile ? 29 : 40;
        leftZone.style.left  = '0';
        leftZone.style.width = (leftGap >= MIN
          ? leftGap + PAGE_OVERLAP
          : MIN + PAGE_OVERLAP) + 'px';
        if (leftGap < MIN) leftZone.style.left = Math.max(0, b.left - f.left - PAGE_OVERLAP/2) + 'px';

        rightZone.style.right = '0';
        rightZone.style.width = (rightGap >= MIN
          ? rightGap + PAGE_OVERLAP
          : MIN + PAGE_OVERLAP) + 'px';
        if (rightGap < MIN) rightZone.style.right = Math.max(0, f.right - b.right - PAGE_OVERLAP/2) + 'px';
      }
      flipZones.addEventListener('click', e => {
        const z = e.target.closest('.zone');
        if (!z) return;
        if (z.dataset.dir === 'prev') flipPrev();
        else                          flipNext();
      });

      function flipPrev() {
        if (mode === 'carousel') goto(carouselCenter - 1);
        else                     pageFlip.flipPrev();
      }
      function flipNext() {
        if (mode === 'carousel') goto(carouselCenter + 1);
        else                     pageFlip.flipNext();
      }

      // ── Resize: have StPageFlip rebuild internal layout via update().
      // pageFlip.update() with no args re-fits to the current setup; if
      // that no-ops, we tear down and rebuild as a fallback. (v2.0.7
      // doesn't expose a width/height setter.)
      let resizeT;
      function refit() {
        clearTimeout(resizeT);
        resizeT = setTimeout(() => {
          // For now, refit just re-applies the zoom-frame sizing so scroll
          // area tracks the natural book size. The natural fit (zoom = 1)
          // doesn't change because the page elements stay the configured
          // pageW × pageH; viewport-fitting is via the CSS scale only when
          // zoom != 1.
          if (typeof autoShowSidebar === 'function') autoShowSidebar();
          invalidateTextLayers();
          if (mode === 'carousel') {
            sizeCarousel();
            // Text spans were sized to the old pageH — clear cache so they
            // re-render at the new size on the next updateCarousel().
            for (const s of carouselSlots) s.querySelector('.text-layer').innerHTML = '';
            updateCarousel(carouselCenter, { animate: false });
          } else {
            // Spread mode: kick StPageFlip to re-evaluate portrait/landscape
            // orientation against the new container width. Without this it
            // stays locked to whatever orientation it picked at init time
            // (e.g. portrait when bookEl was 0-wide during script bootstrap).
            try { pageFlip.update(); } catch (e) {}
          }
          applyZoom();
          repopulateNeighbors(currentPage());
        }, 80);
      }
      if (window.ResizeObserver) new ResizeObserver(refit).observe(main);
      window.addEventListener('resize', refit);
      if (window.visualViewport) window.visualViewport.addEventListener('resize', refit);

      // ── Page tracking
      const pinput = document.getElementById('pinput');
      const ptotal = document.getElementById('ptotal');
      if (ptotal) ptotal.textContent = String(TOTAL);
      // Typeable current-page input. Enter (or blur) commits; Escape reverts.
      if (pinput) {
        pinput.addEventListener('focus', () => pinput.select());
        pinput.addEventListener('keydown', e => {
          if (e.key === 'Enter') { e.preventDefault(); pinput.blur(); }
          if (e.key === 'Escape') { pinput.value = String(currentPage()); pinput.blur(); }
        });
        pinput.addEventListener('blur', () => {
          const n = parseInt((pinput.value || '').replace(/\D/g, ''), 10);
          if (n && n >= 1 && n <= TOTAL && n !== currentPage()) goto(n);
          else pinput.value = String(currentPage());
        });
      }
      const seek  = document.getElementById('seek');
      const currentPage = () => (mode === 'carousel')
        ? carouselCenter
        : Math.max(1, Math.min(TOTAL, pageFlip.getCurrentPageIndex() + 1));

      function chapterForPage(p) {
        let cur = null;
        for (const e of toc) {
          if (e.page && e.page <= p) cur = e;
          else if (e.page && e.page > p) break;
        }
        return cur;
      }
      function chapterBySlug(slug) { return toc.find(e => e.slug === slug); }
      // Smallest TOC heading page strictly after `headingPage` (i.e. where
      // the next chapter/section begins). Falls back to TOTAL+1 for the last
      // entry so the slot covers the rest of the book.
      function nextTocPageAfter(headingPage) {
        let next = 0;
        for (const e of toc) {
          if (e.page && e.page > headingPage && (next === 0 || e.page < next)) next = e.page;
        }
        return next || (TOTAL + 1);
      }
      // Tell the TTS player the reader explicitly jumped to the chapter whose
      // TOC heading is `headingPage`. Passing the chapter's whole TOC slot
      // lets it bind to THIS chapter rather than the previous one whose last
      // paragraph bleeds onto this chapter's heading page (see
      // FlipbookTTS.selectChapterByTocSlot for the full rationale).
      function ttsSelectChapter(headingPage) {
        try {
          if (window.FlipbookTTS && FlipbookTTS.selectChapterByTocSlot && headingPage) {
            FlipbookTTS.selectChapterByTocSlot(headingPage, nextTocPageAfter(headingPage));
          }
        } catch (e) {}
      }
      // Resolve a chapter reference from a URL param. Accepts either:
      //   - a full slug ("2-hayah-existence")
      //   - just the chapter number as a string ("2")
      // For pure-digit refs, matches toc entries whose slug starts with
      // "<num>-" (the parsers always prefix the slug with the chapter
      // number); falls back to exact slug match if no prefix hit.
      function chapterByRef(ref) {
        if (!ref) return null;
        var s = String(ref).trim();
        if (!s) return null;
        if (/^\d+$/.test(s)) {
          var prefix = s + '-';
          return toc.find(e => e.slug && (e.slug === s || e.slug.indexOf(prefix) === 0));
        }
        return chapterBySlug(s);
      }

      let lastSavedPage = 0;
      let urlWrittenOnce = false;
      function syncUrlAndStorage(page1) {
        if (page1 !== lastSavedPage) {
          // Don't overwrite a saved bookmark with page 1 during the very
          // first sync of a load — pageFlip's init event fires update() which
          // calls us with page=1 before the user has actually navigated. If
          // we wrote that, every subsequent refresh would lose the bookmark.
          // Allow writing 1 only after some other page has already been
          // synced (lastSavedPage non-zero), which means the user navigated.
          const isFirstSyncOfLoad = lastSavedPage === 0;
          if (page1 !== 1 || !isFirstSyncOfLoad) {
            localStorage.setItem(STORE_KEY + ':page', String(page1));
          }
          lastSavedPage = page1;
        }
        // Same guard for the URL hash. pageFlip emits 'init' which fires
        // update() → syncUrlAndStorage(1) BEFORE the queued initial-nav
        // gets to call goto(<the URL's intended page>). Without this
        // guard, that init firing replaces the user's deep-link hash
        // (e.g. #chapter=8&page=313&q=test) with #page=1, and a hard
        // refresh then loses the search query. Skip the write when
        // we've never written the URL yet AND we're being called with
        // page=1 — that's the init-artifact case. Once any navigation
        // has touched the URL, all subsequent syncs write normally.
        if (!urlWrittenOnce && page1 === 1) {
          return;
        }
        urlWrittenOnce = true;
        try { if (window.FlipbookBookmarks) { FlipbookBookmarks.refresh(); FlipbookBookmarks.recordCurrentPage(page1); } } catch (e) {}
        try { if (window.FlipbookTTS) FlipbookTTS.notifyPageChange(page1); } catch (e) {}
        const ch = chapterForPage(page1);
        const parts = [];
        if (ch && ch.slug) parts.push('chapter=' + encodeURIComponent(ch.slug));
        parts.push('page=' + page1);
        // Preserve the active search query in the URL so a copied/shared
        // link keeps the in-page highlight active for the next reader.
        const _si = document.getElementById('search-input');
        const activeQ = (_si && _si.value || '').trim();
        if (activeQ) parts.push('q=' + encodeURIComponent(activeQ));
        const newHash = '#' + parts.join('&');
        if (location.hash !== newHash) history.replaceState(null, '', newHash);
      }
      function goto(page1, { silent = false } = {}) {
        const p = Math.max(1, Math.min(TOTAL, page1));
        if (mode === 'carousel') {
          if (p === carouselCenter) { if (!silent) syncUrlAndStorage(p); return; }
          updateCarousel(p);
          playFlip();
          update();
          if (!silent) syncUrlAndStorage(p);
        } else {
          pageFlip.turnToPage(p - 1);
          // Synchronously reflect the target page in the slider + input so the
          // visible UI doesn't lag behind the deep-link nav (see comment).
          seek.value = p;
          if (pinput && document.activeElement !== pinput) pinput.value = String(p);
          if (!silent) syncUrlAndStorage(p);
        }
      }
      const update = () => {
        const human = currentPage();
        if (pinput && document.activeElement !== pinput) pinput.value = String(human);
        seek.value = human;
        document.querySelectorAll('.thumbs button.active').forEach(b => b.classList.remove('active'));
        const active = document.querySelector(`.thumbs button[data-pg="${human}"]`);
        if (active) { active.classList.add('active'); active.scrollIntoView({block:'nearest', behavior:'smooth'}); }
        syncUrlAndStorage(human);
        if (typeof scrollSearchResultsToCurrentPage === 'function') scrollSearchResultsToCurrentPage();
        if (mode === 'spread') {
          playFlip();
          repopulateNeighbors(human);
          // Flip animations briefly change bookEl's bounding box (page-curl
          // shadow) — re-snap zones after the animation settles.
          setTimeout(positionFlipZones, 750);
        }
        // After every navigation, give the URL-driven paragraph
        // highlight another chance — the destination page's text-layer
        // is typically built right after this fires.
        try { tryReapplyUrlHighlight(); } catch (e) {}
        // And one more pass shortly after, once carousel/spread text-
        // layers have actually populated (their fetches are async).
        setTimeout(() => { try { tryReapplyUrlHighlight(); } catch (e) {} }, 500);
        setTimeout(() => { try { tryReapplyUrlHighlight(); } catch (e) {} }, 1500);
      };
      pageFlip.on('flip', update);
      pageFlip.on('init', update);

      // ── Lazy text layer
      const textCache = new Map();
      async function ensureTextLayer(page1) {
        if (page1 < 1 || page1 > TOTAL) return;
        const pgEl = bookEl.querySelector(`.pg[data-page="${page1}"]`);
        if (!pgEl || pgEl.dataset.populated === '1') return;
        let data = textCache.get(page1);
        if (!data) {
          try { data = await fetch(bundleUrl(`text/page-${PAD(page1)}.json`)).then(r => r.json()); }
          catch { return; }
          textCache.set(page1, data);
        }
        const layer = pgEl.querySelector('.text-layer');
        const pageH = pgEl.clientHeight, pageW = pgEl.clientWidth;
        if (pageH < 10 || pageW < 10) return;
        const frag = document.createDocumentFragment();
        const fontTable = data.fonts || null;
        for (const sp of data.spans) {
          const x = sp[0], y = sp[1], h = sp[3], text = sp[4], flags = sp[5] | 0;
          const fontKey = sp.length >= 7 ? sp[6] : -1;
          const s = document.createElement('span');
          buildStyledSpanContent(s, text, flags);
          if (fontTable && fontKey >= 0 && fontTable[String(fontKey)]) {
            s.dataset.font = fontTable[String(fontKey)];
          }
          s.style.left = x + '%';
          s.style.top  = y + '%';
          s.style.fontSize = (h * pageH / 100) + 'px';
          frag.appendChild(s);
        }
        layer.innerHTML = '';
        layer.appendChild(frag);
        const targets = data.spans.map(s => s[2] * pageW / 100);
        const kids = layer.children;
        for (let i = 0; i < kids.length; i++) {
          const intrinsic = kids[i].getBoundingClientRect().width;
          if (intrinsic > 1 && targets[i] > 1) kids[i].style.transform = 'scaleX(' + (targets[i]/intrinsic) + ')';
        }
        pgEl.dataset.populated = '1';
        // Newly-rendered text-layer should pick up any active search highlight.
        if (typeof applySearchHighlight === 'function') { try { if (_currentSearchQuery) applySearchHighlight(); } catch (e) {} }
      }
      function repopulateNeighbors(page1) {
        const portrait = main.clientWidth < 732;
        const left = page1 % 2 === 0 ? page1 - 1 : page1;
        const targets = portrait
          ? [page1 - 1, page1, page1 + 1, page1 + 2]
          : [left - 2, left - 1, left, left + 1, left + 2, left + 3];
        for (const p of targets) ensureTextLayer(p);
      }
      function invalidateTextLayers() {
        bookEl.querySelectorAll('.pg[data-populated="1"]').forEach(pg => {
          pg.dataset.populated = '';
          const tl = pg.querySelector('.text-layer'); if (tl) tl.innerHTML = '';
        });
      }

      // ── Page-flip sound: bandpass-swept noise (soft swoosh)
      let audioCtx = null, soundOn = localStorage.getItem(STORE_KEY + ':sound') === '1';
      const soundBtn = document.getElementById('sound');
      function setSound(on) {
        soundOn = on; soundBtn.textContent = on ? '🔊' : '🔇';
        soundBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        localStorage.setItem(STORE_KEY + ':sound', on ? '1' : '0');
      }
      // Page-turn sound — plays a recorded MP3 (page-turn.mp3 in this
      // bundle). A small pool of <audio> elements lets fast successive
      // turns overlap rather than cutting each other off.
      const flipAudioPool = [];
      const FLIP_AUDIO_SRC = 'page-turn.mp3';
      const FLIP_POOL_SIZE = 4;
      let flipPoolNext = 0;
      function playFlip() {
        if (!soundOn) return;
        try {
          if (flipAudioPool.length === 0) {
            for (let i = 0; i < FLIP_POOL_SIZE; i++) {
              const a = new Audio(FLIP_AUDIO_SRC);
              a.preload = 'auto';
              a.volume = 0.1;
              a.playbackRate = 1.5;
              flipAudioPool.push(a);
            }
          }
          const a = flipAudioPool[flipPoolNext];
          flipPoolNext = (flipPoolNext + 1) % FLIP_POOL_SIZE;
          a.currentTime = 0;
          const p = a.play();
          if (p && p.catch) p.catch(() => {});  // ignore autoplay-block exceptions
        } catch {}
      }
      setSound(soundOn);
      soundBtn.onclick = () => setSound(!soundOn);

      const toast = document.getElementById('toast');
      let toastT;
      const showToast = m => { toast.textContent = m; toast.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(() => toast.classList.remove('show'), 1800); };

      // Zoom toast — on narrow viewports the inline % input is hidden, so
      // tapping zoom +/- gives no feedback unless we surface the new value
      // somehow. Show a big translucent badge in the middle of the page that
      // fades out after ~1.4s. Desktop already has the inline % input.
      const zoomToast = document.getElementById('zoom-toast');
      let zoomToastT;
      function showZoomToast() {
        if (window.innerWidth > 700) return;
        zoomToast.textContent = Math.round(zoom * 100) + '%';
        zoomToast.classList.add('show');
        clearTimeout(zoomToastT);
        zoomToastT = setTimeout(() => zoomToast.classList.remove('show'), 1400);
      }

      // ── Toolbar
      document.getElementById('zoom-in').onclick  = () => { zoom = Math.min(3,   zoom + 0.15); applyZoom(); showZoomToast(); };
      document.getElementById('zoom-out').onclick = () => { zoom = Math.max(0.25, zoom - 0.15); applyZoom(); showZoomToast(); };
      // Editable zoom percentage. Accepts a number (with or without %) and
      // commits on Enter or blur. Clamped to 25–300% to match what the
      // ± buttons can reach. Selecting all on focus makes overwrite easy.
      const zoomPctEl = document.getElementById('zoom-pct');
      const commitZoom = () => {
        const raw = (zoomPctEl.value || '').replace(/[^\d.]/g, '');
        const pct = parseFloat(raw);
        if (!isNaN(pct) && pct > 0) {
          zoom = Math.max(0.25, Math.min(3, pct / 100));
        }
        applyZoom();
      };
      zoomPctEl.addEventListener('focus', () => { zoomPctEl.select(); });
      zoomPctEl.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); zoomPctEl.blur(); }
        if (e.key === 'Escape') { zoomPctEl.value = Math.round(zoom * 100) + '%'; zoomPctEl.blur(); }
      });
      zoomPctEl.addEventListener('blur', commitZoom);
      document.getElementById('fullscreen').onclick = () => {
        if (document.fullscreenElement) document.exitFullscreen();
        else document.documentElement.requestFullscreen();
      };
      document.getElementById('download').onclick = () => { window.location.href = PDF_PATH; };

      // MP3 download — sibling button next to PDF. Always inserted; we
      // HEAD-probe /u/tts-audio/<bookCode>.mp3.zip and disable the button
      // if no bundled zip has been built yet. The zip is rebuilt by
      // admin-tts-build-worker.php at the end of each chapter synth.
      // The download URL carries ?v=<mtime> so Cloudflare's edge cache
      // can't serve a stale zip after a rebuild.
      (() => {
        const dlBtn = document.getElementById('download');
        if (!dlBtn || !BOOK_CODE) return;
        const MP3_PATH = '/u/tts-audio/' + BOOK_CODE + '.mp3.zip';
        const btn = document.createElement('button');
        btn.id = 'download-mp3';
        btn.innerHTML = '↓ <span class="lbl">MP3</span>';
        btn.className = dlBtn.className;
        btn.disabled = true;
        btn.title = 'No bundled MP3 yet — build a chapter first';
        dlBtn.parentNode.insertBefore(btn, dlBtn.nextSibling);
        // Probe with a random query so Cloudflare can't serve a cached
        // HEAD that points us at a stale Last-Modified. The download
        // URL itself uses ?v=<mtime>, which is stable per build so the
        // edge can still cache the actual zip once.
        fetch(MP3_PATH + '?probe=' + Date.now(), { method: 'HEAD', cache: 'no-store' }).then(r => {
          if (!r.ok) return;
          const lm = r.headers.get('last-modified');
          const ts = lm ? Math.floor(new Date(lm).getTime() / 1000) : Date.now();
          btn.disabled = false;
          btn.title = 'Download all chapter MP3s (zip)';
          btn.onclick = () => { window.location.href = MP3_PATH + '?v=' + ts; };
        }).catch(() => {});
      })();

      // Print: load PDF in a hidden iframe and trigger its print dialog so
      // the output is the high-quality vector PDF, not the rasterized DOM.
      let printArmed = false;
      const printFrame = document.getElementById('print-frame');
      printFrame.addEventListener('load', () => {
        if (!printArmed) return;
        printArmed = false;
        try {
          const w = printFrame.contentWindow;
          if (w) setTimeout(() => { try { w.focus(); w.print(); } catch { window.print(); } }, 250);
          else window.print();
        } catch { window.print(); }
      });
      document.getElementById('print').onclick = () => {
        showToast('Preparing print…');
        printArmed = true;
        printFrame.src = PDF_PATH + '?print=' + Date.now();
      };

      document.getElementById('share').onclick = async () => {
        const url = location.href;
        try {
          if (navigator.share) await navigator.share({ url, title: document.title });
          else { await navigator.clipboard.writeText(url); showToast('Link copied to clipboard'); }
        } catch { showToast('Share cancelled'); }
      };

      function openSidebar(tab) {
        document.body.classList.add('show-side');
        if (tab) {
          document.querySelectorAll('aside .tab').forEach(x => {
            x.classList.toggle('active', x.dataset.tab === tab);
            const pane = document.getElementById(x.dataset.tab + '-pane');
            if (pane) pane.style.display = (x.dataset.tab === tab) ? '' : 'none';
          });
        }
        setTimeout(refit, 280);
      }
      // Auto-show sidebar on load (and on resize) whenever the window has
      // enough horizontal room for the helper pane WITHOUT crowding the
      // book. SIDEBAR_WIDTH (280) + a min main width that comfortably fits a
      // 2-page spread (~700px) → threshold ≈ 1024px. Once the user manually
      // toggles the sidebar we stop auto-adjusting on resize and respect
      // their explicit choice for the rest of the session.
      const SIDEBAR_WIDTH        = 280;
      const MIN_MAIN_FOR_SIDEBAR = 700;
      const SIDEBAR_AUTO_THRESH  = SIDEBAR_WIDTH + MIN_MAIN_FOR_SIDEBAR + 32;
      let sidebarUserToggled = false;
      function autoShowSidebar() {
        if (sidebarUserToggled) return;
        document.body.classList.toggle('show-side', window.innerWidth >= SIDEBAR_AUTO_THRESH);
      }
      autoShowSidebar();
      // On narrow viewports the sidebar overlays / crowds the book, so close
      // it after a TOC, page, or search-result click so the user actually
      // sees what they navigated to. Keeps the sidebar open on wide screens
      // where it lives in its own column.
      function maybeCloseSidebarAfterNav() {
        if (window.innerWidth < SIDEBAR_AUTO_THRESH) {
          document.body.classList.remove('show-side');
        }
      }
      document.getElementById('toggle-side').onclick = () => {
        sidebarUserToggled = true;
        document.body.classList.toggle('show-side');
        setTimeout(refit, 280);
      };
      document.querySelectorAll('aside .tab').forEach(t => { t.onclick = () => openSidebar(t.dataset.tab); });

      // ── Nav
      document.getElementById('first').onclick = () => goto(1);
      document.getElementById('prev').onclick  = () => flipPrev();
      document.getElementById('next').onclick  = () => flipNext();
      document.getElementById('last').onclick  = () => goto(TOTAL);
      document.getElementById('mode').onclick  = () => setMode(mode === 'spread' ? 'carousel' : 'spread');
      seek.oninput = e => goto(parseInt(e.target.value, 10));
      // Touch swipe — left to advance, right to go back. StPageFlip handles
      // swipe in spread mode itself, so this only fires in carousel mode to
      // avoid double-flips. Threshold and direction filter keep accidental
      // taps and vertical scrolls from triggering navigation.
      let _touchSX = 0, _touchSY = 0, _touchStartT = 0;
      main.addEventListener('touchstart', e => {
        if (e.touches.length !== 1) return;
        _touchSX = e.touches[0].clientX;
        _touchSY = e.touches[0].clientY;
        _touchStartT = Date.now();
      }, { passive: true });
      main.addEventListener('touchend', e => {
        if (mode !== 'carousel') return;
        if (e.changedTouches.length !== 1) return;
        const t = e.changedTouches[0];
        const dx = t.clientX - _touchSX;
        const dy = t.clientY - _touchSY;
        const dt = Date.now() - _touchStartT;
        if (Math.abs(dx) < 50) return;                  // too short → tap, not swipe
        if (Math.abs(dy) > Math.abs(dx) * 0.7) return;  // mostly vertical → let scroll happen
        if (dt > 800) return;                           // too slow → probably a drag, not swipe
        if (dx < 0) flipNext();
        else        flipPrev();
      }, { passive: true });

      document.addEventListener('keydown', e => {
        if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) return;
        if (e.key === 'ArrowLeft')  flipPrev();
        if (e.key === 'ArrowRight') flipNext();
        if (e.key === 'Home')       goto(1);
        if (e.key === 'End')        goto(TOTAL);
        if (e.key === 'f' || e.key === 'F') document.getElementById('fullscreen').click();
        if (e.key === 'v' || e.key === 'V') setMode(mode === 'spread' ? 'carousel' : 'spread');
        if (e.key === '/')          { e.preventDefault(); openSidebar('search'); document.getElementById('search-input').focus(); }
      });

      // ── TOC
      const tocPane = document.getElementById('toc-pane');
      try {
        const tocJson = await fetch(bundleUrl('toc.json')).then(r => r.json());
        const flat = [];
        const walk = (items, depth = 0) => {
          for (const it of items) {
            flat.push(it);
            const btn = document.createElement('button');
            btn.className = 'toc-item';
            btn.style.paddingLeft = (10 + depth * 14) + 'px';
            btn.innerHTML = `<span class="pg">${it.page ?? ''}</span>${it.title}`;
            if (it.page) btn.onclick = () => { goto(it.page); ttsSelectChapter(it.page); maybeCloseSidebarAfterNav(); };
            tocPane.appendChild(btn);
            if (it.children && it.children.length) walk(it.children, depth + 1);
          }
        };
        if (tocJson.toc && tocJson.toc.length) walk(tocJson.toc);
        else tocPane.innerHTML = '<div style="opacity:0.5; padding:12px; font-size:12px;">No bookmarks in source PDF.</div>';
        toc = flat;
      } catch { tocPane.innerHTML = '<div style="opacity:0.5; padding:12px;">TOC unavailable</div>'; }

      // ── Thumbnails
      const thumbsPane = document.getElementById('thumbs-pane');
      for (let i = 1; i <= TOTAL; i++) {
        const wrap = document.createElement('div');
        const btn = document.createElement('button');
        btn.dataset.pg = i; btn.title = `Page ${i}`;
        btn.innerHTML = `<img src="thumbs/thumb-${PAD(i)}.jpg" alt="" loading="lazy">`;
        btn.onclick = () => { goto(i); maybeCloseSidebarAfterNav(); };
        const lbl = document.createElement('div'); lbl.className = 'lbl'; lbl.textContent = i;
        wrap.appendChild(btn); wrap.appendChild(lbl);
        thumbsPane.appendChild(wrap);
      }

      // ── Search
      let searchIndex = null;
      const searchInput = document.getElementById('search-input');
      const searchResults = document.getElementById('search-results');
      const searchSummary = document.getElementById('search-summary');
      const escHtml = s => s.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
      function highlightSnippet(text, q, ctx = 60) {
        const i = text.toLowerCase().indexOf(q.toLowerCase());
        if (i < 0) return escHtml(text.slice(0, 120));
        const start = Math.max(0, i - ctx), end = Math.min(text.length, i + q.length + ctx);
        return (start > 0 ? '… ' : '') + escHtml(text.slice(start, i)) +
               '<mark>' + escHtml(text.slice(i, i + q.length)) + '</mark>' +
               escHtml(text.slice(i + q.length, end)) + (end < text.length ? ' …' : '');
      }
      let searchT;
      async function runSearch() {
        const q = searchInput.value.trim();
        clearTimeout(searchT);
        if (q.length < 2) { searchResults.innerHTML = ''; searchSummary.textContent = 'Type at least 2 characters.'; return; }
        searchT = setTimeout(async () => {
          if (!searchIndex) {
            searchSummary.textContent = 'Loading search index…';
            searchIndex = await fetch(bundleUrl('search.json')).then(r => r.json());
          }
          const ql = q.toLowerCase();
          const hits = [];
          for (const p of searchIndex.pages) {
            const idx = p.t.toLowerCase().indexOf(ql);
            if (idx >= 0) hits.push({ page: p.p, snippet: highlightSnippet(p.t, q) });
            if (hits.length >= 200) break;
          }
          searchSummary.textContent = `${hits.length}${hits.length === 200 ? '+' : ''} match${hits.length === 1 ? '' : 'es'}`;
          searchResults.innerHTML = '';
          for (const h of hits) {
            const btn = document.createElement('button');
            btn.className = 'hit';
            btn.innerHTML = `<span class="pg">${h.page}</span>${h.snippet}`;
            btn.onclick = () => { goto(h.page); maybeCloseSidebarAfterNav(); };
            searchResults.appendChild(btn);
          }
          // Once the result list is built, scroll the entry matching the
          // current page into view (e.g. when this runs from a deep-link
          // load with #q=…&page=N).
          scrollSearchResultsToCurrentPage();
        }, 180);
      }
      searchInput.addEventListener('input', () => {
        runSearch();
        applySearchHighlight();
      });

      // Build a text-layer span's content so copy/paste preserves italic/bold.
      // flags bitmask from extractor: 1 = italic, 2 = bold. We wrap the text
      // in real <i>/<b> elements (rather than CSS) so clipboard targets like
      // Word/Docs receive the formatting via HTML semantics.
      // Also stashes (text, flags) on data-* attributes so the search-highlight
      // pass can re-render the span on every keystroke without needing to
      // remember which fragment of which page it came from.
      function buildStyledSpanContent(el, text, flags) {
        el.dataset.text = text;
        if (flags) el.dataset.flags = String(flags);
        else delete el.dataset.flags;
        el.textContent = '';
        if (!flags) { el.appendChild(document.createTextNode(text)); return; }
        let inner = document.createTextNode(text);
        if (flags & 1) { const i = document.createElement('i'); i.appendChild(inner); inner = i; }
        if (flags & 2) { const b = document.createElement('b'); b.appendChild(inner); inner = b; }
        el.appendChild(inner);
      }

      // Re-render a span's content with each occurrence of `query` (case-
      // insensitive) wrapped in <mark class="search-hit">, preserving any
      // italic/bold formatting from `flags`.
      function buildHighlightedSpanContent(el, text, flags, query) {
        el.textContent = '';
        const q = query.toLowerCase();
        const tl = text.toLowerCase();
        const frag = document.createDocumentFragment();
        let pos = 0;
        while (pos < text.length) {
          const idx = tl.indexOf(q, pos);
          if (idx < 0) { frag.appendChild(document.createTextNode(text.slice(pos))); break; }
          if (idx > pos) frag.appendChild(document.createTextNode(text.slice(pos, idx)));
          const mark = document.createElement('mark');
          mark.className = 'search-hit';
          mark.textContent = text.slice(idx, idx + query.length);
          frag.appendChild(mark);
          pos = idx + query.length;
        }
        let inner = frag;
        if (flags & 1) { const i = document.createElement('i'); i.appendChild(inner); inner = i; }
        if (flags & 2) { const b = document.createElement('b'); b.appendChild(inner); inner = b; }
        el.appendChild(inner);
      }

      // Highlight in-book occurrences of the current search query. For each
      // text-layer span, if its stored text contains the query we rebuild the
      // span content with <mark class="search-hit"> wrapping just the matching
      // substring(s); otherwise we restore the plain styled content. Reading
      // text from data-text (rather than textContent) keeps this O(n) and
      // robust to repeated highlight passes that change DOM structure.
      let _currentSearchQuery = '';
      // For each text-layer span on every visible page, wrap the matching
      // substring in <mark class="search-hit"> when the query is present, or
      // restore the plain styled content when it clears. Spans store their
      // source text in data-text, so this can be re-run idempotently as new
      // pages render or the user types another character.
      function applySearchHighlight() {
        const q = (searchInput.value || '').trim();
        _currentSearchQuery = q;
        const qLower = q.toLowerCase();
        const layers = document.querySelectorAll('.text-layer');
        layers.forEach(layer => {
          const spans = layer.children;
          for (let i = 0; i < spans.length; i++) {
            const s = spans[i];
            const text = s.dataset.text;
            if (text == null) continue;
            const flags = parseInt(s.dataset.flags || '0', 10);
            const hit = q && text.toLowerCase().includes(qLower);
            const hasMark = !!s.querySelector('mark.search-hit');
            if (hit) {
              buildHighlightedSpanContent(s, text, flags, q);
            } else if (hasMark) {
              buildStyledSpanContent(s, text, flags);
            }
          }
        });
        // After re-rendering, scroll the search-results list to the entry
        // that matches the current flipbook page so the user can see the
        // matching context without hunting.
        scrollSearchResultsToCurrentPage();
      }

      // If the search-results panel has hits, scroll the entry whose .pg
      // matches the current flipbook page (or the closest preceding page)
      // into view. Looks the element up via getElementById each call because
      // pageFlip's 'init' event fires update() before the closure-bound
      // `searchResults` const has been initialized (temporal dead zone).
      function scrollSearchResultsToCurrentPage() {
        const list = document.getElementById('search-results');
        if (!list || !list.children.length) return;
        let cur = 0;
        try { cur = (typeof currentPage === 'function') ? currentPage() : 0; } catch (e) { return; }
        if (!cur) return;
        let best = null, bestPg = -1;
        for (const btn of list.children) {
          const pgEl = btn.querySelector('.pg');
          const pg = pgEl ? parseInt(pgEl.textContent, 10) : NaN;
          if (!isFinite(pg)) continue;
          if (pg <= cur && pg > bestPg) { best = btn; bestPg = pg; }
          if (pg === cur) break;
        }
        if (best) best.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }

      // Bookmarks module — populates icon overlay on visible pages, the
      // sidebar Bookmarks tab, and the slider marker lane. refresh() is also
      // called from syncUrlAndStorage on every navigation.
      try {
        if (window.FlipbookBookmarks) {
          FlipbookBookmarks.init({
            bookCode: cfg.bookCode,
            totalPages: TOTAL,
            getCurrentPage: function () { try { return currentPage(); } catch (e) { return 1; } },
            gotoPage: function (n) { try { goto(n); } catch (e) {} },
          });
        }
      } catch (e) {}
      // TTS playback — Play/Pause button above the seek bar that syncs
      // page turns to the audio's per-paragraph markers. Always renders
      // (disabled+dimmed when no audio is available for the current
      // chapter) so the toolbar layout stays stable.
      try {
        if (window.FlipbookTTS) {
          FlipbookTTS.init({
            bookCode: cfg.bookCode,
            totalPages: TOTAL,
            getCurrentPage: function () { try { return currentPage(); } catch (e) { return 1; } },
            gotoPage: function (n) { try { goto(n); } catch (e) {} },
          });
        }
      } catch (e) {}
      // ── Initial nav: hash > localStorage resume > page 1
      // Use the hash captured at the very top of init — see _hashAtLoad above.
      // URL convention: `p` is preferred for page (short form); `page` is
      // accepted for backwards compatibility with older bookmarked links.
      // `h` carries a paragraph_number for the URL-driven highlight.
      const params = new URLSearchParams(_hashAtLoad.replace(/^#/, ''));
      // URL convention: `c` is preferred (short form), `chapter` accepted
      // for backwards compatibility. Both can be a full slug
      // ("2-hayah-existence") or just the chapter number ("2").
      const hashChapter = params.get('c') || params.get('chapter');
      const hashPage    = parseInt(params.get('p') || params.get('page') || '0', 10);
      const hashHighlight = parseInt(params.get('h') || '0', 10);
      const hashQuery   = params.get('q') || '';

      // Apply (or clear) the URL-driven paragraph highlight. Fetches
      // paragraph text via /api/paragraph-text.php and hands it to
      // FlipbookTTS.highlightParagraph which handles fuzzy span match +
      // retry-until-text-layer-ready. Safe to call repeatedly.
      // Pending paragraph_number for URL-driven highlight. Stays set until
      // update() (which fires after every page navigation) sees that bands
      // are actually drawn — then it stops re-attempting. Re-attempts are
      // necessary because on a deep-link load (#p=N&h=M), the initial fire
      // happens BEFORE the carousel has navigated to N and built its text-
      // layer, so the first highlight attempt finds nothing to match.
      let _urlHighlightPending = 0;
      let _urlHighlightText    = null;  // cached so re-fires skip the fetch
      function applyUrlHighlight(paragraphNum) {
        // Always clear any prior URL-driven highlight on a new request.
        document.querySelectorAll('.text-layer span.url-highlight')
          .forEach(el => el.classList.remove('url-highlight'));
        document.querySelectorAll('.text-layer .url-highlight-band')
          .forEach(el => el.remove());
        _urlHighlightPending = 0;
        _urlHighlightText    = null;
        if (!window.FlipbookTTS || !FlipbookTTS.highlightParagraph) return;
        if (!paragraphNum) return;
        _urlHighlightPending = paragraphNum;
        const url = '/api/paragraph-text.php?v=' + encodeURIComponent(cfg.bookCode) + '&h=' + paragraphNum;
        fetch(url, { credentials: 'omit' })
          .then(r => r.ok ? r.json() : null)
          .then(d => {
            if (!d || !d.text) return;
            _urlHighlightText = d.text;
            FlipbookTTS.highlightParagraph(paragraphNum, d.text);
          })
          .catch(() => {});
      }
      // Called from update() after every page navigation. If the URL
      // hash still wants a highlight and we haven't yet seen the band
      // appear, fire highlightParagraph again — by now the carousel may
      // have built the text-layer for the target page.
      function tryReapplyUrlHighlight() {
        if (!_urlHighlightPending || !_urlHighlightText) return;
        if (document.querySelectorAll('.text-layer .url-highlight-band').length > 0) {
          // Bands are already on the page — nothing more to do until the
          // hash changes (which will reset _urlHighlightPending).
          return;
        }
        if (window.FlipbookTTS && FlipbookTTS.highlightParagraph) {
          FlipbookTTS.highlightParagraph(_urlHighlightPending, _urlHighlightText);
        }
      }
      // If the URL hash carries a search query (deep-link from the site-
      // wide search results), seed the in-flipbook search box and run it.
      // The existing `input` listener wires through runSearch() and
      // applySearchHighlight(); just dispatch the event so the same code
      // path fires. Highlights re-apply as new pages render via the per-
      // page text-layer builders (which invoke applySearchHighlight).
      // Helper: open the Search side-tab. Defensively retries on the next
      // animation frame because some later init code in this function may
      // reset to the default Contents tab if it ran before our call.
      function focusSearchPane() {
        try { openSidebar('search'); } catch (e) {}
        try {
          const tab = document.querySelector('aside .tab[data-tab="search"]');
          if (tab) tab.click();
        } catch (e) {}
      }
      if (hashQuery && searchInput) {
        searchInput.value = hashQuery;
        searchInput.dispatchEvent(new Event('input'));
        focusSearchPane();
        requestAnimationFrame(focusSearchPane);
        setTimeout(focusSearchPane, 300);
      }
      window.addEventListener('hashchange', () => {
        const p = new URLSearchParams(location.hash.replace(/^#/, ''));
        const ch = p.get('c') || p.get('chapter');
        const pg = parseInt(p.get('p') || p.get('page') || '0', 10);
        const hh = parseInt(p.get('h') || '0', 10);
        const qq = p.get('q') || '';
        if (pg) goto(pg, { silent: true });
        else if (ch) { const c = chapterByRef(ch); if (c && c.page) { goto(c.page, { silent: true }); ttsSelectChapter(c.page); } }
        if (searchInput && qq !== searchInput.value) {
          searchInput.value = qq;
          searchInput.dispatchEvent(new Event('input'));
          if (qq) focusSearchPane();
        }
        // URL-driven paragraph highlight (h=N). The helper handles the
        // "wait until text-layer is rendered" retry loop internally.
        applyUrlHighlight(hh);
      });

      let initialPage = null;
      let initialChapterPage = 0;   // set when the deep-link is a chapter ref
      if (hashPage) initialPage = hashPage;
      else if (hashChapter) { const c = chapterByRef(hashChapter); if (c && c.page) { initialPage = c.page; initialChapterPage = c.page; } }

      // Build carousel DOM up-front so mode toggling is instant. We start in
      // spread mode by default; if storage said carousel, flip it on after
      // initial page placement so StPageFlip's internal state is also at the
      // right page (so toggling back to spread shows the right spread).
      buildCarouselSlots();

      // Use the bookmark captured at the very top of init, BEFORE pageFlip's
      // 'init' event handler had a chance to overwrite it with page 1 during
      // an await yield earlier in this function.
      const bookmarkedPage = _bookmarkAtLoad;
      const goingToPage = initialPage || 1;

      // Direct URL navigation needs to wait until pageFlip is fully initialized
      // — otherwise turnToPage() during the load phase can no-op silently and
      // the user lands on page 1 despite the URL specifying another page.
      // Track readiness with a flag and a queue.
      let pageFlipReady = false;
      let pendingInitialNav = null;
      pageFlip.on('init', () => {
        pageFlipReady = true;
        if (pendingInitialNav) {
          const p = pendingInitialNav; pendingInitialNav = null;
          goto(p);
        }
      });
      if (initialPage) {
        // Sync the slider + page input up-front so the visible UI matches the
        // URL even if pageFlip's 'init' event fires before our listener is
        // wired (or doesn't fire at all on cached/instant loads). update() at
        // 'flip'/'init' will overwrite these once the library catches up, but
        // by then pageFlip is at the right page so the values agree.
        try {
            seek.value = String(initialPage);
            if (pinput && document.activeElement !== pinput) pinput.value = String(initialPage);
        } catch (e) {}
        if (pageFlipReady) goto(initialPage);
        else pendingInitialNav = initialPage;
        // A #c=… deep-link lands on the chapter's heading page; bind the TTS
        // player to that chapter explicitly (init's page-based fetch resolves
        // the heading page to the previous chapter — same overlap as the TOC).
        if (initialChapterPage) ttsSelectChapter(initialChapterPage);
      } else {
        update();
      }
      // Apply the URL-driven paragraph highlight on initial load. The helper
      // fetches paragraph text and retries until the text-layer is rendered,
      // so it's safe to fire immediately — no need to await any page-turn.
      if (hashHighlight) applyUrlHighlight(hashHighlight);

      // Resume-bar bookmark: shows whenever the saved page differs from where
      // we're landing AND the user hasn't dismissed it for that exact page.
      const dismissed = localStorage.getItem(STORE_KEY + ':resume-dismissed') === String(bookmarkedPage);
      const bookmarkDecision = (bookmarkedPage && bookmarkedPage > 1 && bookmarkedPage <= TOTAL && bookmarkedPage !== goingToPage && !dismissed) ? 'SHOW' : 'HIDE';
      console.log('[bookmark]', {
        bookmarkedPage, goingToPage, dismissed,
        decision: bookmarkDecision,
        reasons: {
          hasBookmark: !!bookmarkedPage,
          beyondFirst: bookmarkedPage > 1,
          inRange: bookmarkedPage <= TOTAL,
          differsFromLanding: bookmarkedPage !== goingToPage,
          notDismissed: !dismissed,
        },
      });
      if (bookmarkedPage && bookmarkedPage > 1 && bookmarkedPage <= TOTAL && bookmarkedPage !== goingToPage && !dismissed) {
        document.getElementById('resume-link').textContent = String(bookmarkedPage);
        document.getElementById('resume-link-compact').textContent = String(bookmarkedPage);
        document.getElementById('resume-toolbar-btn').onclick = (e) => {
          // The × inside the button is a separate click target — let its own
          // handler win (it stops propagation), so anywhere else triggers nav.
          if (e.target.closest('#resume-dismiss')) return;
          // The button stays visible after navigating — only the × dismisses
          // it. Useful when the user wants to come back again.
          goto(bookmarkedPage);
        };
        document.getElementById('resume-dismiss').onclick = (e) => {
          e.stopPropagation();
          // Remember the dismissal so the same bookmark doesn't keep showing
          // up on every reload — but a NEW bookmark (different page) will.
          localStorage.setItem(STORE_KEY + ':resume-dismissed', String(bookmarkedPage));
          document.body.classList.remove('show-resume');
        };
        document.body.classList.add('show-resume');
      }
      applyZoom();   // initialize zoom + frame size

      // Apply persisted mode (default 'spread' was set during state init).
      if (mode === 'carousel') {
        const wasMode = mode;
        mode = 'spread';                 // setMode no-ops if already in target
        // Tell setMode the page we're actually landing on. Otherwise it
        // calls currentPage() while mode is briefly 'spread' and gets
        // pageFlip's index (which never moved in carousel land — still 1),
        // overwriting the carouselCenter that goto() just set.
        setMode(wasMode, { silent: true, targetPage: initialPage || 1 });
      } else {
        // First-paint: in spread, the button shows the single-page icon
        // (because that's what clicking would switch to).
        const btn = document.getElementById('mode');
        btn.innerHTML = '<svg viewBox="0 0 24 18" width="22" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><rect x="7" y="2" width="10" height="14" rx="1"/></svg>';
        btn.title = 'Switch to single-page view';
        // StPageFlip evaluates portrait vs landscape from bookEl.offsetWidth
        // at init time. If bookEl was 0-wide because the page-flip init runs
        // before layout settles, the library latches to portrait and stays
        // there even after bookEl grows. Re-fire update() once the layout
        // is definitely complete (load event covers async images/fonts) so
        // the spread renders both pages on a wide viewport.
        function forceSpreadRelayout() {
          try { pageFlip.update(); } catch (e) {}
          try { pageFlip.turnToPage(Math.max(0, (initialPage || 1) - 1)); } catch (e) {}
          applyZoom();
          positionFlipZones();
        }
        requestAnimationFrame(forceSpreadRelayout);
        if (document.readyState !== 'complete') {
          window.addEventListener('load', forceSpreadRelayout, { once: true });
        }
        // Belt-and-suspenders: a 250ms tick covers cases where layout is
        // still settling after `load` (sidebar autoShow animations, etc.).
        setTimeout(forceSpreadRelayout, 250);
      }

    } catch (e) { showErr('init threw: ' + (e && e.stack || e)); }
  });
