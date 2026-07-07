/* Flipbook TTS playback
 *
 * Adds a Play/Pause button just above the bottom seek bar. When a TTS
 * MP3 is available for the chapter containing the current page, the
 * button is enabled and clicking it plays the chapter audio starting
 * from the first paragraph's offset on the current page.
 *
 *   • As playback crosses each paragraph marker, the flipbook auto-
 *     turns to that paragraph's page.
 *   • At the end of a chapter MP3, playback pauses for the admin-set
 *     "New Chapter" pause (Admin → TTS → Pauses) then loads and plays
 *     the next chapter's MP3 (if one exists & is ready).
 *   • Pages whose chapter has no audio still show the button — at
 *     opacity 0.25 and disabled — so the toolbar layout is stable.
 *
 * Public API (called from flipbook-viewer.js):
 *   FlipbookTTS.init({ bookCode, getCurrentPage, gotoPage, totalPages });
 *   FlipbookTTS.notifyPageChange(page1);   // viewer calls this whenever
 *                                          // the visible page changes
 */
(function () {
    'use strict';

    var BM = window.FlipbookTTS = window.FlipbookTTS || {};
    var cfg = null;
    var btn = null;
    var audio = null;
    var progressEl = null;       // wrapper around the seek track + fill
    var progressFill = null;     // inner bar that grows with currentTime
    var timeCurEl = null;        // "0:00" elapsed readout (left, under track)
    var timeDurEl = null;        // "4:56" total-length readout (right)
    var playerEl    = null;      // outer flex row: [volume] [progress] [play]
    var volSlider   = null;      // user-adjustable audio volume (0–1)
    var volBtnEl    = null;      // speaker button — mirrors play-button disabled state
    var current = null;     // last fetched chapter payload (see API shape)
    var fetchSeq = 0;       // monotonic — only latest fetch's response wins
    var loadedChapterKey = null;
    var isPlaying = false;
    var pendingSeekMs = null;      // seek requested before chapter metadata loaded
    var chapterPauseTimer = null;
    var suppressPageSync = false;  // set true when WE drive a goto from a marker
    var lastPage = 0;
    var currentParagraphNumber = -1;  // paragraph being narrated right now

    // ── DOM injection ───────────────────────────────────────────────────
    function injectStyles() {
        if (document.getElementById('fb-tts-style')) return;
        var st = document.createElement('style');
        st.id = 'fb-tts-style';
        st.textContent = [
            // Audio player widget: a fixed-position flex row spanning the
            // currently-visible page width (same horizontal extent as the
            // old standalone progress bar). Inside, left-to-right:
            // [volume slider] [progress scrubber] [play/pause button].
            '.fb-tts-player { position: fixed; z-index: 12; height: calc(var(--fb-tts-btn-size, 43) * 1px);',
            '                 display: flex; align-items: center; gap: 10px;',
            '                 pointer-events: none; /* children re-enable */ }',
            '.fb-tts-player > * { pointer-events: auto; }',
            // Volume control: a small speaker button on the far left that
            // pops up a vertical slider above itself when clicked. Closes
            // on outside click. Same circular footprint as the play
            // button so the player widget reads as a tidy three-control
            // strip when collapsed.
            '.fb-tts-vol-wrap { position: relative; flex: 0 0 calc(var(--fb-tts-btn-size, 43) * 1px);',
            '                   display: flex; align-items: center; justify-content: center; }',
            '.fb-tts-vol-btn { width: calc(var(--fb-tts-btn-size, 43) * 1px); height: calc(var(--fb-tts-btn-size, 43) * 1px); border-radius: 50%;',
            '                  background: var(--fb-tts-btn-bg, #1f3550); color: var(--fb-tts-icon-color, #cfe1ff); border: calc(var(--fb-tts-btn-border-width, 1) * 1px) solid var(--fb-tts-btn-border, #2a4d70);',
            '                  display: flex; align-items: center; justify-content: center;',
            '                  cursor: pointer; padding: 0;',
            '                  transition: background 0.15s, transform 0.1s; }',
            '.fb-tts-vol-btn:hover { background: var(--fb-tts-btn-bg-hover, #28456a); }',
            '.fb-tts-vol-btn:active { transform: scale(0.95); }',
            '.fb-tts-vol-btn svg { width: 22px; height: 22px; }',
            '.fb-tts-vol-popup { position: absolute; bottom: calc(100% + 8px);',
            '                    left: 50%; transform: translateX(-50%);',
            '                    background: var(--fb-tts-btn-bg, #1f3550); border: 1px solid var(--fb-tts-btn-border, #2a4d70); border-radius: 8px;',
            '                    padding: 14px 10px; box-shadow: 0 4px 14px rgba(0,0,0,0.45);',
            '                    display: none; z-index: 14; }',
            '.fb-tts-vol-popup.is-open { display: flex; align-items: center; justify-content: center; }',
            // The vertical orient is handled by writing-mode (modern) +
            // the -webkit-appearance:slider-vertical fallback (Safari).
            // direction:rtl flips so up = louder.
            // writing-mode + rtl puts the track flush with the input's
            // right edge in webkit, which leaves the thumb sitting visibly
            // left of the popup's optical center. A small left margin
            // nudges it back so the thumb-circle reads as centered.
            '.fb-tts-vol { writing-mode: vertical-rl; direction: rtl;',
            '              -webkit-appearance: slider-vertical;',
            '              width: 24px; height: 110px; padding: 0; margin: 0 0 0 6px;',
            '              cursor: pointer; background: transparent; outline: none; }',
            '.fb-tts-vol::-webkit-slider-runnable-track {',
            '              width: 6px; background: var(--fb-tts-track-bg, rgba(200,200,200,0.85));',
            '              border-radius: 3px; }',
            '.fb-tts-vol::-moz-range-track {',
            '              width: 6px; background: var(--fb-tts-track-bg, rgba(200,200,200,0.85));',
            '              border-radius: 3px; }',
            '.fb-tts-vol::-webkit-slider-thumb { -webkit-appearance: none; appearance: none;',
            '              width: 16px; height: 16px; border-radius: 50%;',
            '              background: var(--fb-tts-thumb-bg, #cfe1ff); border: 1px solid var(--fb-tts-btn-border, #2a4d70); cursor: pointer;',
            '              box-shadow: 0 1px 3px rgba(0,0,0,0.4); }',
            '.fb-tts-vol::-moz-range-thumb { width: 16px; height: 16px; border-radius: 50%;',
            '              background: var(--fb-tts-thumb-bg, #cfe1ff); border: 1px solid var(--fb-tts-btn-border, #2a4d70); cursor: pointer;',
            '              box-shadow: 0 1px 3px rgba(0,0,0,0.4); }',
            // Play/Pause button — flex item, fixed circle on the right.
            '.fb-tts-btn { flex: 0 0 calc(var(--fb-tts-btn-size, 43) * 1px); height: calc(var(--fb-tts-btn-size, 43) * 1px); border-radius: 50%;',
            '              background: var(--fb-tts-btn-bg, #1f3550); color: var(--fb-tts-icon-color, #cfe1ff); border: calc(var(--fb-tts-btn-border-width, 1) * 1px) solid var(--fb-tts-btn-border, #2a4d70);',
            '              display: flex; align-items: center; justify-content: center;',
            '              cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.35);',
            '              transition: opacity 0.2s, transform 0.1s, background 0.15s; }',
            '.fb-tts-btn:hover:not(.is-disabled) { background: var(--fb-tts-btn-bg-hover, #28456a); }',
            '.fb-tts-btn:active:not(.is-disabled) { transform: scale(0.95); }',
            '.fb-tts-btn.is-disabled { opacity: 0.25; cursor: not-allowed; }',
            // Speaker button mirrors the play button's disabled visual when
            // there's no TTS audio loaded — same opacity + not-allowed cursor
            // so the two read as a single "audio-controls offline" state.
            '.fb-tts-vol-btn.is-disabled { opacity: 0.25; cursor: not-allowed; }',
            '.fb-tts-btn svg { width: 20px; height: 20px; }',
            // Brief amber tint when waiting between chapters.
            '.fb-tts-btn.is-pausing { background: var(--fb-tts-pausing-bg, #4d3a1f); border-color: var(--fb-tts-pausing-border, #6d5a3a); color: var(--fb-tts-pausing-color, #f7d77a); }',
            // MP3 seek bar — middle flex item, takes remaining width.
            // Hidden (visually) while no chapter audio is available.
            // Column layout: scrub track on top, elapsed/total times below.
            '.fb-tts-progress { flex: 1; display: flex; flex-direction: column;',
            '                   justify-content: center; transition: opacity 0.2s; }',
            '.fb-tts-progress.is-hidden { opacity: 0; pointer-events: none; }',
            '.fb-tts-progress-track { position: relative; width: 100%; height: 6px;',
            '                         background: var(--fb-tts-track-bg, rgba(200,200,200,0.85));',
            '                         border-radius: 3px; cursor: pointer; overflow: hidden; }',
            '.fb-tts-progress-fill  { position: absolute; top: 0; left: 0; height: 100%;',
            '                         background: var(--fb-tts-fill-bg, #1f3550); width: 0%; }',
            // Time readout row under the scrubber: elapsed on the left,
            // total duration on the right.
            '.fb-tts-times { display: flex; justify-content: space-between;',
            '                margin-top: 3px; font-size: 11px; line-height: 1;',
            '                color: var(--fb-tts-time-color, #cfe1ff); font-variant-numeric: tabular-nums;',
            '                font-feature-settings: "tnum"; user-select: none; }',
            // Subtle read-along highlight — applied to text-layer spans
            // that belong to the paragraph currently being narrated. We
            // only mark spans on the visible page, so a paragraph
            // straddling a page break highlights its portion of each
            // page as the flipbook turns. !important wins against the
            // page-specific styles already injected on each span.
            // The narration tint is intentionally translucent (low alpha) so
            // the underlying text stays legible. The admin color picker yields
            // an opaque hex, so we re-derive the translucent fill from it with
            // color-mix — keeping the same 14% strength while letting the hue
            // be configured. Fallback #ffe696 == the old rgba(255,230,150).
            '.text-layer span.tts-current,',
            '.pg .text-layer span.tts-current,',
            '.cpg .text-layer span.tts-current {',
            '    background: color-mix(in srgb, var(--fb-tts-highlight, #ffe696) 14%, transparent) !important;',
            '    box-shadow: 0 0 0 2px color-mix(in srgb, var(--fb-tts-highlight, #ffe696) 14%, transparent);',
            '    border-radius: 2px;',
            '}',
            // URL-driven paragraph highlight uses continuous line-band
            // overlays instead of per-span backgrounds — the PDF text-
            // layer is one span per word-cluster, so any per-span fill
            // looks choppy/blocky. The band approach computes line
            // bounding rects from matched spans and overlays a single
            // rectangle per line of the paragraph, ignoring whatever
            // italicized translations / parens / nested quotes live
            // inside. The span class itself stays as a marker (so the
            // clear logic knows which lines belong to the highlight) but
            // carries no visual style.
            '.text-layer span.url-highlight,',
            '.pg .text-layer span.url-highlight,',
            '.cpg .text-layer span.url-highlight {',
            '    background: transparent !important;',
            '    box-shadow: none !important;',
            '    border-radius: 0 !important;',
            '}',
            // Search / deep-link paragraph band — same translucency trick as
            // the narration tint (40% here). Fallback #ffc83c == the old
            // rgba(255,200,60).
            '.text-layer .url-highlight-band {',
            '    position: absolute;',
            '    background: color-mix(in srgb, var(--fb-tts-url-highlight, #ffc83c) 40%, transparent);',
            '    pointer-events: none;',
            '    z-index: 0;',
            '}',
        ].join('\n');
        document.head.appendChild(st);
    }

    var ICON_PLAY  = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5v14l12-7L7 5z"/></svg>';
    var ICON_PAUSE = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14"/><rect x="14" y="5" width="4" height="14"/></svg>';
    // Speaker with sound-waves — collapses to a plain speaker when volume hits 0.
    var ICON_VOLUME = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05A4.5 4.5 0 0016.5 12zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>';
    var ICON_VOLUME_MUTE = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.5 12A4.5 4.5 0 0014 7.97v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51A8.96 8.96 0 0021 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.17v2.06a8.99 8.99 0 003.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>';

    function ensurePlayer() {
        if (playerEl) return;
        playerEl = document.createElement('div');
        playerEl.className = 'fb-tts-player';

        // Volume control on the far left: same circular button footprint
        // as the play button, but shows a speaker icon. Clicking opens a
        // vertical slider popup directly above the button; clicking
        // outside (or pressing Escape) closes it. Setting persists to
        // localStorage across sessions.
        var volWrap = document.createElement('div');
        volWrap.className = 'fb-tts-vol-wrap';

        var volBtn = document.createElement('button');
        volBtn.type = 'button';
        // Starts disabled, like the play button — both wait until a TTS-
        // backed chapter loads. renderButton() flips the state in sync.
        volBtn.className = 'fb-tts-vol-btn is-disabled';
        volBtn.title = 'Volume (no TTS audio for this chapter)';
        volBtn.setAttribute('aria-label', 'Volume');
        volBtn.innerHTML = ICON_VOLUME;
        volWrap.appendChild(volBtn);
        volBtnEl = volBtn;

        var volPopup = document.createElement('div');
        volPopup.className = 'fb-tts-vol-popup';

        volSlider = document.createElement('input');
        volSlider.type = 'range';
        volSlider.className = 'fb-tts-vol';
        volSlider.min = '0'; volSlider.max = '100'; volSlider.step = '1';
        volSlider.value = String(Math.round(initialVolume() * 100));
        volSlider.title = 'Volume';
        volSlider.setAttribute('aria-label', 'Volume');
        // Firefox honors orient="vertical" even though it's non-standard;
        // it's a no-op everywhere else, which is fine.
        volSlider.setAttribute('orient', 'vertical');
        volSlider.addEventListener('input', function () {
            var v = Math.max(0, Math.min(1, (parseInt(volSlider.value, 10) || 0) / 100));
            if (audio) audio.volume = v;
            try { localStorage.setItem('fb-tts-volume', String(v)); } catch (e) {}
            volBtn.innerHTML = v === 0 ? ICON_VOLUME_MUTE : ICON_VOLUME;
        });
        volPopup.appendChild(volSlider);
        volWrap.appendChild(volPopup);

        // Open/close behavior. The mousedown listener attaches AFTER the
        // open click finishes propagating (next microtask) so the same
        // click that opens doesn't immediately re-close.
        var docCloseHandler = null;
        function closeVol() {
            volPopup.classList.remove('is-open');
            if (docCloseHandler) {
                document.removeEventListener('mousedown', docCloseHandler);
                document.removeEventListener('touchstart', docCloseHandler);
                docCloseHandler = null;
            }
        }
        volBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            // Block interaction while the speaker is greyed out — mirrors
            // the play button which can't be clicked in this state.
            if (volBtn.classList.contains('is-disabled')) return;
            if (volPopup.classList.contains('is-open')) { closeVol(); return; }
            volPopup.classList.add('is-open');
            docCloseHandler = function (ev) {
                if (volWrap.contains(ev.target)) return;
                closeVol();
            };
            setTimeout(function () {
                document.addEventListener('mousedown', docCloseHandler);
                document.addEventListener('touchstart', docCloseHandler);
            }, 0);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeVol();
        });

        // Reflect the persisted initial volume on the icon in case it's 0.
        if (initialVolume() === 0) volBtn.innerHTML = ICON_VOLUME_MUTE;

        playerEl.appendChild(volWrap);

        // Audio scrubber (clickable track + fill).
        progressEl = document.createElement('div');
        progressEl.className = 'fb-tts-progress is-hidden';
        var track = document.createElement('div');
        track.className = 'fb-tts-progress-track';
        progressFill = document.createElement('div');
        progressFill.className = 'fb-tts-progress-fill';
        track.appendChild(progressFill);
        progressEl.appendChild(track);
        track.addEventListener('click', onProgressClick);

        // Elapsed / total time readout under the scrubber.
        var times = document.createElement('div');
        times.className = 'fb-tts-times';
        timeCurEl = document.createElement('span');
        timeCurEl.className = 'fb-tts-time-cur';
        timeCurEl.textContent = '0:00';
        timeDurEl = document.createElement('span');
        timeDurEl.className = 'fb-tts-time-dur';
        timeDurEl.textContent = '0:00';
        times.appendChild(timeCurEl);
        times.appendChild(timeDurEl);
        progressEl.appendChild(times);

        playerEl.appendChild(progressEl);

        // Play/Pause button on the far right.
        btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fb-tts-btn is-disabled';
        btn.innerHTML = ICON_PLAY;
        btn.title = 'Loading TTS…';
        btn.setAttribute('aria-label', 'Play chapter audio');
        btn.addEventListener('click', onClick);
        playerEl.appendChild(btn);

        document.body.appendChild(playerEl);
        // Track the visible page area so the widget aligns with whatever
        // pages are on screen (carousel: center slot; spread: full book).
        window.addEventListener('resize', syncPlayerGeometry);
        window.addEventListener('scroll', syncPlayerGeometry, true);
        syncPlayerGeometry();
    }

    function initialVolume() {
        var v = parseFloat(localStorage.getItem('fb-tts-volume'));
        return (isFinite(v) && v >= 0 && v <= 1) ? v : 0.75;
    }

    // Pin the player above the bottom nav and span the visible page area:
    //   • carousel (single-page) mode → .cpg.center
    //   • spread (two-page) mode      → #book covers both pages
    function syncPlayerGeometry() {
        if (!playerEl) return;
        var pageEl = document.body.classList.contains('mode-carousel')
            ? document.querySelector('#carousel .cpg.center')
            : document.getElementById('book');
        if (!pageEl) return;
        var r = pageEl.getBoundingClientRect();
        if (r.width <= 0) return;
        playerEl.style.left   = Math.round(r.left)  + 'px';
        playerEl.style.width  = Math.round(r.width) + 'px';
        // Sit just above the nav, nudged down ~18px from the old anchor so
        // the elapsed/total time readout clears the white page bottom and
        // reads against the black background below the page.
        playerEl.style.bottom = 'calc(var(--nav, 56px) - 20px)';
    }

    function ensureAudio() {
        if (audio) return;
        audio = document.createElement('audio');
        audio.preload = 'metadata';
        // Restore the user's persisted volume so the first play() honors it.
        audio.volume = initialVolume();
        audio.addEventListener('timeupdate',     function () { onTimeUpdate(); renderProgress(); });
        audio.addEventListener('loadedmetadata', function () {
            // A page-driven seek may have been requested before the MP3's
            // metadata was ready (e.g. the reader paged forward before ever
            // pressing play). Apply it now that we can position the playhead.
            if (pendingSeekMs != null) {
                try { audio.currentTime = pendingSeekMs / 1000; } catch (e) {}
                pendingSeekMs = null;
            }
            renderProgress();
        });
        audio.addEventListener('durationchange', renderProgress);
        audio.addEventListener('seeked',         renderProgress);
        audio.addEventListener('ended', onEnded);
        audio.addEventListener('playing', function () { isPlaying = true; renderButton(); renderProgress(); });
        audio.addEventListener('pause',   function () { isPlaying = false; renderButton(); clearHighlight(); });
        document.body.appendChild(audio);
    }

    // Click anywhere on the track to seek. The track lives at .fb-tts-progress-track;
    // the inner fill is pointer-events transparent (default) so click coords are
    // always against the track itself.
    function onProgressClick(e) {
        if (!current || !current.available || !audio || !audio.duration) return;
        var trackRect = e.currentTarget.getBoundingClientRect();
        var ratio = (e.clientX - trackRect.left) / trackRect.width;
        ratio = Math.max(0, Math.min(1, ratio));
        try { audio.currentTime = ratio * audio.duration; } catch (err) {}
    }

    // Seconds → "M:SS" (or "H:MM:SS" past an hour). Non-finite/NaN → "0:00".
    function formatTime(secs) {
        secs = Math.floor(secs);
        if (!isFinite(secs) || secs < 0) secs = 0;
        var h = Math.floor(secs / 3600);
        var m = Math.floor((secs % 3600) / 60);
        var s = secs % 60;
        var ss = (s < 10 ? '0' : '') + s;
        if (h > 0) return h + ':' + (m < 10 ? '0' : '') + m + ':' + ss;
        return m + ':' + ss;
    }

    function renderProgress() {
        if (!progressEl) return;
        var show = !!(current && current.available);
        progressEl.classList.toggle('is-hidden', !show);
        if (!show || !audio || !audio.duration || !isFinite(audio.duration)) {
            if (progressFill) progressFill.style.width = '0%';
            if (timeCurEl) timeCurEl.textContent = '0:00';
            if (timeDurEl) timeDurEl.textContent = '0:00';
            return;
        }
        if (progressFill) {
            var pct = (audio.currentTime / audio.duration) * 100;
            progressFill.style.width = pct.toFixed(2) + '%';
        }
        if (timeCurEl) timeCurEl.textContent = formatTime(audio.currentTime);
        if (timeDurEl) timeDurEl.textContent = formatTime(audio.duration);
        syncPlayerGeometry();
    }

    // ── State + render ─────────────────────────────────────────────────
    // Keep the volume button's enabled state in lockstep with the play
    // button. Both wait for a chapter that has TTS audio; before that,
    // the speaker icon is greyed out so it's clear there's nothing for
    // the volume slider to control.
    function setVolDisabled(disabled, reason) {
        if (!volBtnEl) return;
        if (disabled) {
            volBtnEl.classList.add('is-disabled');
            volBtnEl.title = reason || 'Volume (no TTS audio yet)';
        } else {
            volBtnEl.classList.remove('is-disabled');
            volBtnEl.title = 'Volume';
        }
    }
    function renderButton() {
        // Whenever button state changes, the progress bar's visibility
        // (and geometry, if it just became visible) follows along.
        renderProgress();
        if (!btn) return;
        if (chapterPauseTimer) {
            btn.classList.remove('is-disabled');
            btn.classList.add('is-pausing');
            btn.innerHTML = ICON_PAUSE;
            btn.title = 'Pausing between chapters — click to cancel';
            setVolDisabled(false);
            return;
        }
        btn.classList.remove('is-pausing');
        if (!current || !current.available) {
            btn.classList.add('is-disabled');
            btn.innerHTML = ICON_PLAY;
            btn.title = 'No TTS audio for this chapter';
            setVolDisabled(true, 'Volume (no TTS audio for this chapter)');
            return;
        }
        btn.classList.remove('is-disabled');
        setVolDisabled(false);
        if (isPlaying) {
            btn.innerHTML = ICON_PAUSE;
            btn.title = 'Pause (' + (current.chapter_number || '') + ' ' + (current.chapter_name || '') + ')';
        } else {
            btn.innerHTML = ICON_PLAY;
            btn.title = 'Play (' + (current.chapter_number || '') + ' ' + (current.chapter_name || '') + ')';
        }
    }

    // ── Lookup helpers ─────────────────────────────────────────────────
    function markerForPage(markers, page1) {
        // Return the FIRST marker whose paragraph_page is >= the requested
        // page (so playing from a given page lands on its first paragraph).
        // Falls back to nearest preceding marker if none reach the page.
        if (!markers || !markers.length) return null;
        for (var i = 0; i < markers.length; i++) {
            if (markers[i].paragraph_page >= page1) return markers[i];
        }
        return markers[markers.length - 1];
    }

    function pageAtMs(markers, ms) {
        // Largest paragraph_page among markers with offset_ms <= ms.
        // Returns 0 if before the first marker (treat as chapter start).
        if (!markers || !markers.length) return 0;
        var page = 0;
        for (var i = 0; i < markers.length; i++) {
            if (markers[i].offset_ms <= ms) page = markers[i].paragraph_page;
            else break;
        }
        return page;
    }

    function markerAtMs(markers, ms) {
        // Largest marker with offset_ms <= ms.
        if (!markers || !markers.length) return null;
        var best = null;
        for (var i = 0; i < markers.length; i++) {
            if (markers[i].offset_ms <= ms) best = markers[i];
            else break;
        }
        return best;
    }

    // ── Read-along highlight ───────────────────────────────────────────
    // Map paragraph_number → marker so we can fetch the paragraph text
    // to span-match on the current page. Each marker payload carries
    // `.text` (paragraph_text_plain) from the API.
    function paragraphMarkers(num) {
        if (!current || !current.markers) return [];
        var out = [];
        for (var i = 0; i < current.markers.length; i++) {
            if (current.markers[i].paragraph_number === num) out.push(current.markers[i]);
        }
        return out;
    }

    // Normalize text for fuzzy span matching. The PDF text-layer and
    // the DB-stored paragraph_text_plain disagree on a lot of details:
    // the layer inserts spaces around parens / before half-rings /
    // between bold and italic runs, splits "kanaʿ" into "kana ʿ", etc.
    // Rather than chase every variant, strip ALL non-alphanumeric chars
    // and lowercase — the remaining alpha+digit stream is identical
    // across both sources, so substring matching is reliable.
    function norm(s) {
        return String(s || '').toLowerCase().replace(/[^a-z0-9]/g, '');
    }

    // Find all .text-layer spans on the visible page whose concatenated
    // text falls inside the current paragraph's text. We compute a
    // normalized "page string" by concatenating all span texts, mark
    // each span with [startOffset, endOffset) within that string, then
    // locate the paragraph text (normalized) inside it. Any span whose
    // [start, end) range overlaps the paragraph's [hit, hit+plen) is
    // tagged with .tts-current.
    var highlightRetryTimer = null;

    // Run the match-and-mark logic against a single page's text-layer.
    // Returns true on a match (so the caller knows whether to retry).
    function markSpansOnLayer(layer, paraNorm) {
        var spans = layer.children;
        if (!spans.length) return false;

        // Build a single normalized string for the page + index of each
        // span's [start, end) into that string. Since norm() strips all
        // non-alphanumerics (including whitespace), we concatenate span
        // contributions directly with no separator. Spans whose normed
        // text is empty (punctuation-only, like "(" or "ʿ") get a
        // zero-width range at the current position so they can still
        // be marked when they sit inside the matched paragraph run.
        var pageStr = '';
        var ranges = []; // [start, end, spanEl]
        for (var i = 0; i < spans.length; i++) {
            var sEl = spans[i];
            var sText = norm(sEl.dataset.text || sEl.textContent || '');
            var start = pageStr.length;
            pageStr += sText;
            ranges.push([start, pageStr.length, sEl]);
        }

        // Locate the paragraph in the page string. Try a full match
        // first; if interior glyphs differ enough to fail it, anchor
        // with the longest prefix AND the longest suffix and mark
        // everything between them. For paragraphs that straddle a page
        // break (typical in spread mode), prefix-only matches on the
        // first page and suffix-only matches on the second.
        var paraLen = paraNorm.length;
        var hitStart = -1, hitEnd = -1;
        var full = pageStr.indexOf(paraNorm);
        if (full >= 0) { hitStart = full; hitEnd = full + paraLen; }
        else {
            var prefStart = -1, prefEnd = -1;
            for (var n = Math.min(paraLen, 300); n >= 20; n = Math.floor(n * 0.8)) {
                var p = pageStr.indexOf(paraNorm.substring(0, n));
                if (p >= 0) { prefStart = p; prefEnd = p + n; break; }
            }
            var suffStart = -1, suffEnd = -1;
            for (var n2 = Math.min(paraLen, 300); n2 >= 20; n2 = Math.floor(n2 * 0.8)) {
                var sp = pageStr.indexOf(paraNorm.substring(paraLen - n2));
                if (sp >= 0) { suffStart = sp; suffEnd = sp + n2; break; }
            }
            if (prefStart >= 0 && suffEnd > prefStart) { hitStart = prefStart; hitEnd = suffEnd; }
            else if (prefStart >= 0)                   { hitStart = prefStart; hitEnd = prefEnd; }
            else if (suffStart >= 0)                   { hitStart = suffStart; hitEnd = suffEnd; }
        }
        if (hitStart < 0) return false;

        var marked = 0;
        for (var r = 0; r < ranges.length; r++) {
            var rs = ranges[r][0], re = ranges[r][1], el = ranges[r][2];
            var inside = (rs < re)
                ? (rs < hitEnd && re > hitStart)
                : (rs > hitStart && rs < hitEnd);
            if (inside) { el.classList.add('tts-current'); marked++; }
        }
        return marked > 0;
    }

    function applyHighlight(paragraphNum, retriesLeft) {
        if (retriesLeft == null) retriesLeft = 12;   // ~12 × 100ms ≈ 1.2s
        if (highlightRetryTimer) { clearTimeout(highlightRetryTimer); highlightRetryTimer = null; }
        // Clear previous highlights (cheap — small DOM).
        document.querySelectorAll('.text-layer span.tts-current')
            .forEach(function (el) { el.classList.remove('tts-current'); });
        if (paragraphNum == null || paragraphNum < 0) { console.log('[tts hl] bail: paragraphNum', paragraphNum); return; }
        if (!cfg.getCurrentPage) { console.log('[tts hl] bail: no getCurrentPage'); return; }
        var page = cfg.getCurrentPage();
        if (!page) { console.log('[tts hl] bail: page=0'); return; }

        // Find the paragraph_text_plain to match against (carried on
        // every marker for this paragraph; pick the first non-empty).
        var markers = paragraphMarkers(paragraphNum);
        if (!markers.length) { console.log('[tts hl] bail: no markers for paragraph', paragraphNum); return; }
        var paragraphText = '';
        for (var k = 0; k < markers.length; k++) {
            if (markers[k].text) { paragraphText = markers[k].text; break; }
        }
        if (!paragraphText) { console.log('[tts hl] bail: no text on any marker for paragraph', paragraphNum); return; }
        var paraNorm = norm(paragraphText);
        if (paraNorm.length < 4) { console.log('[tts hl] bail: paraNorm too short', paraNorm.length); return; }

        // Pick the relevant pages from the paragraph's own markers — one
        // per paragraph_page the paragraph appears on. This is the
        // authoritative answer: a paragraph that straddles pages 7 and 8
        // has markers for both, regardless of what spread is on screen.
        // Filtering by markers (rather than "every layer in #book") is
        // essential after a page turn: PageFlip keeps the previous
        // spread's .pg elements in the DOM with their text-layer still
        // populated, so without this filter we'd happily match against
        // stale content from the spread we just left.
        var pages = [];
        var seen = {};
        for (var pi = 0; pi < markers.length; pi++) {
            var pg = markers[pi].paragraph_page;
            if (pg && !seen[pg]) { seen[pg] = true; pages.push(pg); }
        }
        if (!pages.length) pages = [page]; // fallback — match on the visible page

        var isCarousel = document.body.classList.contains('mode-carousel');
        var layers = [];
        var missingLayer = false;   // page-element exists but no text yet
        for (var pp = 0; pp < pages.length; pp++) {
            var pgNum = pages[pp];
            var pgEl = isCarousel
                ? (document.querySelector('#carousel .cpg[data-page="' + pgNum + '"]'))
                : (document.querySelector('#book .pg[data-page="' + pgNum + '"]'));
            if (!pgEl) continue;
            var L = pgEl.querySelector('.text-layer');
            if (!L || !L.children.length) { missingLayer = true; continue; }
            layers.push(L);
        }

        if (!layers.length || missingLayer) {
            // Either no page element rendered yet, or the page is on
            // screen but its text-layer hasn't been fetched yet. Retry —
            // text-layers are fetched async by the viewer right after a
            // page turn settles.
            console.log('[tts hl] layers not ready (have=', layers.length,
                        'missing=', missingLayer,
                        ' retriesLeft=', retriesLeft, ') carousel=', isCarousel);
            if (retriesLeft > 0) {
                highlightRetryTimer = setTimeout(function () { applyHighlight(paragraphNum, retriesLeft - 1); }, 100);
                // If we already have one layer ready, mark its spans now
                // so the user sees something while we wait for the other.
                if (layers.length) {
                    for (var li0 = 0; li0 < layers.length; li0++) {
                        markSpansOnLayer(layers[li0], paraNorm);
                    }
                }
                return;
            }
        }

        var anyMatch = false;
        for (var li = 0; li < layers.length; li++) {
            if (markSpansOnLayer(layers[li], paraNorm)) anyMatch = true;
        }
        if (!anyMatch) {
            console.log('[tts hl] NO MATCH across', layers.length, 'layers (pages=', pages.join(','), ')');
        }
    }

    function clearHighlight() {
        document.querySelectorAll('.text-layer span.tts-current')
            .forEach(function (el) { el.classList.remove('tts-current'); });
        currentParagraphNumber = -1;
    }

    // ── Fetching ───────────────────────────────────────────────────────
    // Init fires with cfg.getCurrentPage()==1 (carouselCenter default
    // before the deep-link nav lands), so we send a fetch for page=1
    // that comes back "no chapter" even though the user will end up on,
    // say, page 26 a moment later. notifyPageChange(26) fires a second
    // fetch — but whichever resolves LAST wins, so we sometimes saw
    // the page-1 "unavailable" overwrite the page-26 "available". A
    // monotonic seq stamp on each fetch fixes that: stale responses
    // are dropped on arrival.
    function fetchForPage(page1, opts) {
        opts = opts || {};
        if (!cfg.bookCode || !page1) return;
        // Skip redundant fetches when the same chapter still applies.
        if (current && current.chapter_key && !opts.force && current.markers && current.markers.length) {
            var minP = current.markers[0].paragraph_page;
            var maxP = current.markers[current.markers.length - 1].paragraph_page;
            if (page1 >= minP && page1 <= maxP) return;
        }
        var seq = ++fetchSeq;
        var url = '/api/tts-audio.php?book_code=' + encodeURIComponent(cfg.bookCode)
                + '&page=' + page1;
        console.log('[tts] fetchForPage', page1, 'seq=', seq);
        fetch(url).then(function (r) { return r.json(); })
            .then(function (data) {
                console.log('[tts] fetch resp seq=', seq, 'fetchSeq=', fetchSeq, 'available=', data && data.available);
                if (seq !== fetchSeq) return;   // a newer fetch superseded this
                current = data;
                renderButton();
                if (opts.afterLoad) opts.afterLoad();
            })
            .catch(function () {
                if (seq !== fetchSeq) return;
                current = { available: false };
                renderButton();
            });
    }

    function fetchByChapterKey(chapterKey, afterLoad) {
        if (!cfg.bookCode || !chapterKey) return;
        var seq = ++fetchSeq;
        fetch('/api/tts-audio.php?book_code=' + encodeURIComponent(cfg.bookCode)
            + '&chapter_key=' + chapterKey)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (seq !== fetchSeq) return;
                current = data;
                renderButton();
                if (afterLoad) afterLoad();
            })
            .catch(function () {});
    }

    // ── Playback control ───────────────────────────────────────────────
    function onClick() {
        if (chapterPauseTimer) {
            // Cancel the inter-chapter pause and stop playback.
            clearTimeout(chapterPauseTimer);
            chapterPauseTimer = null;
            renderButton();
            return;
        }
        if (!current || !current.available) return;
        if (isPlaying) {
            audio.pause();
            return;
        }
        startPlayback();
    }

    // Position the audio playhead at a given offset in the current chapter,
    // loading the chapter's MP3 first if it isn't already sourced. Works
    // whether or not we're playing — so paging through the book moves the
    // player (and the seek bar) to match the visible page even when paused.
    // If the metadata hasn't loaded yet the seek is deferred until it has
    // (see the loadedmetadata handler in ensureAudio).
    function seekChapterToMs(ms) {
        ensureAudio();
        if (!current || !current.available || !current.audio_url) return;
        if (loadedChapterKey !== current.chapter_key) {
            audio.src = current.audio_url;
            loadedChapterKey = current.chapter_key;
        }
        if (audio.readyState >= 1 && isFinite(audio.duration)) {
            pendingSeekMs = null;
            try { audio.currentTime = ms / 1000; } catch (e) {}
        } else {
            // Metadata not ready — record the target and nudge a load so the
            // loadedmetadata handler can apply it.
            pendingSeekMs = ms;
            try { audio.load(); } catch (e) {}
        }
        renderProgress();
    }

    function startPlayback() {
        ensureAudio();
        if (!current || !current.available) return;
        var page = cfg.getCurrentPage ? cfg.getCurrentPage() : 1;
        var marker = markerForPage(current.markers, page);
        var startMs = marker ? marker.offset_ms : 0;
        seekChapterToMs(startMs);
        var p = audio.play();
        if (p && p.catch) p.catch(function () {});
    }

    function onTimeUpdate() {
        if (!current || !current.markers || !cfg.gotoPage) return;
        // A timeupdate may fire between audio.pause() and the audio element's
        // paused-state propagation; if the user has navigated off the chapter,
        // notifyPageChange has paused us — don't fight back with cfg.gotoPage.
        if (audio && audio.paused) return;
        var ms = audio.currentTime * 1000;
        var nowPage = pageAtMs(current.markers, ms);
        if (!nowPage) return;
        var cur = cfg.getCurrentPage ? cfg.getCurrentPage() : nowPage;
        if (nowPage !== cur) {
            suppressPageSync = true;
            try { cfg.gotoPage(nowPage); } catch (e) {}
            // Page-change events from goto will re-trigger our refresh;
            // suppress the chapter-refetch path for one tick.
            setTimeout(function () { suppressPageSync = false; }, 0);
        }
        // Read-along: refresh the highlight when the paragraph_number
        // changes. Same-paragraph timeupdates skip the DOM work.
        var m = markerAtMs(current.markers, ms);
        if (m && m.paragraph_number !== currentParagraphNumber) {
            currentParagraphNumber = m.paragraph_number;
            applyHighlight(currentParagraphNumber);
        }
    }

    function onEnded() {
        // Chapter MP3 ran out — schedule the inter-chapter pause then
        // load+play the next chapter's audio if it's available.
        isPlaying = false;
        if (!current) { renderButton(); return; }
        var nextKey = current.next_chapter_key;
        var nextOk  = current.next_chapter_available;
        var pauseMs = current.chapter_pause_ms | 0;
        if (!nextKey || !nextOk) { renderButton(); return; }
        renderButton();
        chapterPauseTimer = setTimeout(function () {
            chapterPauseTimer = null;
            fetchByChapterKey(nextKey, function () {
                if (!current || !current.available) { renderButton(); return; }
                // Jump the flipbook to the new chapter's first page so the
                // user sees the right pages while listening.
                if (current.markers && current.markers.length && cfg.gotoPage) {
                    suppressPageSync = true;
                    try { cfg.gotoPage(current.markers[0].paragraph_page); } catch (e) {}
                    setTimeout(function () { suppressPageSync = false; }, 0);
                }
                startPlayback();
            });
        }, Math.max(0, pauseMs));
    }

    // ── Public API ─────────────────────────────────────────────────────
    // At init time cfg.getCurrentPage() returns 1 (carouselCenter default)
    // because the deep-link nav hasn't applied yet — so trusting it leads
    // to a wasted fetch for page=1 (which has no chapter, leaving the
    // button disabled until notifyPageChange fires). Read the URL hash
    // directly to find the intended landing page so the very first fetch
    // already targets the right chapter.
    function readInitialPageFromHash() {
        try {
            var hash = (location.hash || '').replace(/^#/, '');
            if (!hash) return 0;
            var p = new URLSearchParams(hash).get('page');
            var n = p ? parseInt(p, 10) : 0;
            return (n > 0) ? n : 0;
        } catch (e) { return 0; }
    }

    // ── Public: URL-driven paragraph highlight ────────────────────────
    //   FlipbookTTS.highlightParagraph(paragraphNum, paragraphText, opts)
    //     opts: { cssClass: 'url-highlight', pages: [N, ...] }
    // Spans on the visible page that match the supplied paragraph text are
    // tagged with the given class (default `url-highlight`). Retries while
    // the text-layer is still rendering — same behavior as the TTS path.
    // Independent from the TTS read-along band so it persists no-auto-clear.
    var urlRetryTimer = null;
    BM.highlightParagraph = function (paragraphNum, paragraphText, opts) {
        opts = opts || {};
        var cssClass = opts.cssClass || 'url-highlight';
        // Clear prior span markers + prior line bands (the bands are
        // added as siblings of the spans inside each .text-layer).
        document.querySelectorAll('.text-layer span.' + cssClass)
            .forEach(function (el) { el.classList.remove(cssClass); });
        document.querySelectorAll('.text-layer .' + cssClass + '-band')
            .forEach(function (el) { el.remove(); });
        if (urlRetryTimer) { clearTimeout(urlRetryTimer); urlRetryTimer = null; }
        if (!paragraphText) return;
        var paraNorm = norm(paragraphText);
        if (paraNorm.length < 4) return;

        var pages = Array.isArray(opts.pages) && opts.pages.length ? opts.pages : null;
        var attempt = function (retriesLeft) {
            var isCarousel = document.body.classList.contains('mode-carousel');
            var pgEls = [];
            if (pages) {
                for (var i = 0; i < pages.length; i++) {
                    var el = isCarousel
                        ? document.querySelector('#carousel .cpg[data-page="' + pages[i] + '"]')
                        : document.querySelector('#book .pg[data-page="' + pages[i] + '"]');
                    if (el) pgEls.push(el);
                }
            } else {
                var sel = isCarousel ? '#carousel .cpg' : '#book .pg';
                pgEls = Array.prototype.slice.call(document.querySelectorAll(sel));
            }
            var layers = [];
            var missingLayer = false;
            for (var p = 0; p < pgEls.length; p++) {
                var L = pgEls[p].querySelector('.text-layer');
                if (!L || !L.children.length) { missingLayer = true; continue; }
                layers.push(L);
            }
            if (!layers.length || missingLayer) {
                if (retriesLeft > 0) {
                    urlRetryTimer = setTimeout(function () { attempt(retriesLeft - 1); }, 100);
                    return;
                }
                if (!layers.length) return;
            }
            for (var li = 0; li < layers.length; li++) {
                markSpansOnLayerWithClass(layers[li], paraNorm, cssClass);
            }
        };
        // ~6s of retries (60 × 100ms). Carousel mode populates the text-
        // layer for the focused page only AFTER updateCarousel(p) runs,
        // which itself waits for pageFlip 'init'. On a deep-link load
        // (#p=N&h=M), that whole chain can take >2s when the json fetch
        // is cold; 6s covers slow networks too.
        attempt(60);
    };

    // Variant of markSpansOnLayer that applies an arbitrary class instead
    // of the hardcoded 'tts-current'. Mirrors the same matching logic.
    function markSpansOnLayerWithClass(layer, paraNorm, cssClass) {
        var spans = layer.children;
        if (!spans.length) return false;
        var pageStr = '';
        var ranges = [];
        for (var i = 0; i < spans.length; i++) {
            var sEl = spans[i];
            var sText = norm(sEl.dataset.text || sEl.textContent || '');
            var start = pageStr.length;
            pageStr += sText;
            ranges.push([start, pageStr.length, sEl]);
        }
        var paraLen = paraNorm.length;
        var hitStart = -1, hitEnd = -1;
        var full = pageStr.indexOf(paraNorm);
        if (full >= 0) { hitStart = full; hitEnd = full + paraLen; }
        else {
            var prefStart = -1, prefEnd = -1;
            for (var n = Math.min(paraLen, 300); n >= 20; n = Math.floor(n * 0.8)) {
                var pp = pageStr.indexOf(paraNorm.substring(0, n));
                if (pp >= 0) { prefStart = pp; prefEnd = pp + n; break; }
            }
            var suffStart = -1, suffEnd = -1;
            for (var n2 = Math.min(paraLen, 300); n2 >= 20; n2 = Math.floor(n2 * 0.8)) {
                var sp = pageStr.indexOf(paraNorm.substring(paraLen - n2));
                if (sp >= 0) { suffStart = sp; suffEnd = sp + n2; break; }
            }
            if (prefStart >= 0 && suffEnd > prefStart) { hitStart = prefStart; hitEnd = suffEnd; }
            else if (prefStart >= 0)                   { hitStart = prefStart; hitEnd = prefEnd; }
            else if (suffStart >= 0)                   { hitStart = suffStart; hitEnd = suffEnd; }
        }
        if (hitStart < 0) return false;
        var marked = 0;
        for (var r = 0; r < ranges.length; r++) {
            var rs = ranges[r][0], re = ranges[r][1], el = ranges[r][2];
            var inside = (rs < re) ? (rs < hitEnd && re > hitStart) : (rs > hitStart && rs < hitEnd);
            if (inside) { el.classList.add(cssClass); marked++; }
        }
        if (marked > 0) overlayLineBands(layer, cssClass);
        return marked > 0;
    }

    // Compute one bounding rect per "line" of the matched paragraph and
    // overlay a continuous colored band on each. Lines are detected by
    // grouping marked spans whose vertical midpoints are within half a
    // span-height of each other. Bands are inserted at the START of the
    // text-layer so the (selectable, often transparent) spans paint on
    // top. Coordinates are stored in percent of the layer's size so they
    // scale with zoom — same convention the spans already use.
    function overlayLineBands(layer, cssClass) {
        // Drop any prior bands on this layer before re-emitting.
        var prior = layer.querySelectorAll('.' + cssClass + '-band');
        for (var pi = 0; pi < prior.length; pi++) prior[pi].remove();
        var marked = layer.querySelectorAll('span.' + cssClass);
        if (!marked.length) return;
        var lrect = layer.getBoundingClientRect();
        if (!lrect.width || !lrect.height) return;
        // Collect span rects in px, then bin into lines.
        var rects = [];
        for (var i = 0; i < marked.length; i++) {
            var r = marked[i].getBoundingClientRect();
            if (!r.width || !r.height) continue;
            rects.push({ l: r.left - lrect.left, t: r.top - lrect.top, r: r.right - lrect.left, b: r.bottom - lrect.top });
        }
        if (!rects.length) return;
        rects.sort(function (a, b) { return a.t - b.t || a.l - b.l; });
        // Threshold: spans on the same line have midpoints within 60% of
        // their own height. Mixed font sizes (italics/parens) still fall
        // into the same line bucket because the mid-points are close.
        var lines = [];
        rects.forEach(function (r) {
            var mid = (r.t + r.b) / 2;
            var h   = (r.b - r.t);
            var match = null;
            for (var li = 0; li < lines.length; li++) {
                var L = lines[li];
                var Lmid = (L.t + L.b) / 2;
                if (Math.abs(Lmid - mid) < Math.max(L.b - L.t, h) * 0.6) { match = L; break; }
            }
            if (match) {
                match.l = Math.min(match.l, r.l);
                match.r = Math.max(match.r, r.r);
                match.t = Math.min(match.t, r.t);
                match.b = Math.max(match.b, r.b);
            } else {
                lines.push({ l: r.l, t: r.t, r: r.r, b: r.b });
            }
        });
        // Insert one band per line, positioned in % of the layer so the
        // band scales with the rest of the page on zoom.
        var W = lrect.width, H = lrect.height;
        var frag = document.createDocumentFragment();
        lines.forEach(function (L) {
            var d = document.createElement('div');
            d.className = cssClass + '-band';
            d.style.left   = (L.l / W * 100) + '%';
            d.style.top    = (L.t / H * 100) + '%';
            d.style.width  = ((L.r - L.l) / W * 100) + '%';
            d.style.height = ((L.b - L.t) / H * 100) + '%';
            frag.appendChild(d);
        });
        layer.insertBefore(frag, layer.firstChild);
    }

    BM.init = function (c) {
        cfg = c || {};
        injectStyles();
        ensurePlayer();
        ensureAudio();
        var fromHash = readInitialPageFromHash();
        var fromCfg  = cfg.getCurrentPage ? cfg.getCurrentPage() : 1;
        var p = fromHash || fromCfg;
        lastPage = p;
        console.log('[tts] init page=', p, 'hash=', fromHash, 'cfg=', fromCfg);
        fetchForPage(p);
        observeModeChange();
    };

    // Watch <body> for the mode-carousel ↔ (no class = spread) toggle so
    // we can re-apply the highlight against the newly-active tree. The
    // viewer toggles the class on the user's view-mode button; we don't
    // need to plumb a hook into it. Body class is the source of truth
    // applyHighlight already reads, so just nudging it after the switch
    // is enough.
    var modeObserver = null;
    function observeModeChange() {
        if (modeObserver) return;
        var lastMode = document.body.classList.contains('mode-carousel') ? 'carousel' : 'spread';
        modeObserver = new MutationObserver(function () {
            var nowMode = document.body.classList.contains('mode-carousel') ? 'carousel' : 'spread';
            if (nowMode === lastMode) return;
            lastMode = nowMode;
            syncPlayerGeometry();
            if (currentParagraphNumber < 0) return;
            // applyHighlight clears existing .tts-current marks across
            // both trees at its top, so we don't call clearHighlight
            // separately (that would zero currentParagraphNumber too).
            // Text-layers on the newly-active tree are populated async;
            // the retry budget inside applyHighlight handles the wait.
            setTimeout(function () { applyHighlight(currentParagraphNumber); }, 80);
        });
        modeObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }

    // Called from flipbook-viewer.js whenever the visible page changes.
    BM.notifyPageChange = function (page1) {
        if (!cfg) return;
        // Active page rect just moved (mode switch, zoom, navigation) —
        // realign the seek bar's width + horizontal position.
        syncPlayerGeometry();
        console.log('[tts] notifyPageChange', page1, 'lastPage=', lastPage, 'suppress=', suppressPageSync);
        // When OUR auto-turn drives the page change, the text-layer on
        // the new page rebuilds asynchronously; re-apply the highlight
        // for the still-current paragraph once that settles.
        if (suppressPageSync) {
            setTimeout(function () {
                if (currentParagraphNumber >= 0) applyHighlight(currentParagraphNumber);
            }, 80);
            return;
        }
        if (page1 === lastPage) return;
        lastPage = page1;
        // If the new page is outside the current chapter's range, refetch.
        if (current && current.markers && current.markers.length) {
            var minP = current.markers[0].paragraph_page;
            var maxP = current.markers[current.markers.length - 1].paragraph_page;
            if (page1 >= minP && page1 <= maxP) {
                // Same chapter — no fetch needed. Move the audio playhead to
                // where this page begins so the player tracks the book. This
                // runs whether we're playing OR paused: previously the seek
                // was gated on isPlaying, so turning the page while paused
                // (or before ever pressing play) left the playhead — and the
                // seek bar — parked at the old offset, making the audio seem
                // to "restart from the beginning" when play was pressed after
                // paging forward.
                var m = markerForPage(current.markers, page1);
                if (m) seekChapterToMs(m.offset_ms);
                // Re-apply the highlight against whatever's now visible.
                // If the paragraph being narrated spans the page we just
                // left and the one we just landed on, paragraph_number
                // doesn't change so onTimeUpdate won't trigger a re-match
                // on its own — and the user would see no highlight on
                // the new page until the *next* paragraph begins.
                if (currentParagraphNumber >= 0) {
                    setTimeout(function () { applyHighlight(currentParagraphNumber); }, 80);
                }
                return;
            }
        }
        // Different chapter. Remember whether we were playing: if so, the
        // user expects the narration to carry on into the new chapter rather
        // than stop. We still pause the OLD chapter's element immediately
        // (its audio_url is about to change) and clear `current` synchronously
        // so any racing timeupdate (between pause() and the element actually
        // stopping) doesn't snap the page back via cfg.gotoPage. Cancel any
        // pending inter-chapter pause timer too.
        var wasPlaying = isPlaying || (audio && !audio.paused);
        if (audio && !audio.paused) try { audio.pause(); } catch (e) {}
        if (chapterPauseTimer) { clearTimeout(chapterPauseTimer); chapterPauseTimer = null; }
        current = null;
        currentParagraphNumber = -1;
        clearHighlight();
        renderButton();
        // force:true because we nulled `current`; afterLoad resumes playback
        // from the landed page's offset in the NEW chapter when we were
        // already playing — so crossing a chapter boundary keeps the audio
        // going (loading the new MP3 + seeking to this page) instead of
        // halting at the seam.
        fetchForPage(page1, { force: true, afterLoad: function () {
            if (wasPlaying && current && current.available) startPlayback();
        }});
    };

    // Explicit chapter selection — call this when the reader picks a chapter
    // from the TOC (or a #c=… deep-link) rather than paging through the book.
    //
    // The page a chapter's TOC entry points at is its *heading* page, which
    // in paragraph terms is usually still owned by the PREVIOUS chapter: the
    // prior chapter's last paragraph spills onto the new chapter's heading
    // page (e.g. chapter 1 runs to paragraph-page 207 while chapter 2's TOC
    // page is 206 and its first paragraph isn't until 208). Resolving the
    // audio by that heading page — which is what notifyPageChange() does, and
    // what /api/tts-audio.php's `paragraph_page <= page` lookup does — binds
    // the player to the wrong (previous) chapter. While PLAYING this was
    // masked: the seek landed near the old chapter's end, `ended` fired, and
    // onEnded auto-advanced into the right chapter. While PAUSED there's no
    // `ended`, so the controls stayed stuck on the previous chapter.
    //
    // Here the caller passes the chapter's TOC slot [headingPage, nextHeading)
    // so we can resolve the audio using a page guaranteed to sit inside THIS
    // chapter's body, then position the playhead at the chapter's first
    // paragraph (its heading page precedes the first marker, so markerForPage
    // returns marker[0]).
    BM.selectChapterByTocSlot = function (headingPage, nextHeadingPage) {
        if (!cfg) return;
        headingPage    = parseInt(headingPage, 10) || 0;
        nextHeadingPage = parseInt(nextHeadingPage, 10) || 0;
        if (!headingPage) return;
        syncPlayerGeometry();
        // A page strictly inside the chapter body: the page just before the
        // next chapter's heading. For the final chapter (no next heading, or
        // an adjacent one) the heading page is already inside its own body.
        var resolvePage = (nextHeadingPage && nextHeadingPage > headingPage + 1)
            ? (nextHeadingPage - 1)
            : headingPage;
        lastPage = headingPage;
        // Stop the old chapter's element immediately (its audio_url is about
        // to change) and clear `current` synchronously so any racing
        // timeupdate doesn't snap the page back. Mirror notifyPageChange's
        // different-chapter teardown.
        var wasPlaying = isPlaying || (audio && !audio.paused);
        if (audio && !audio.paused) try { audio.pause(); } catch (e) {}
        if (chapterPauseTimer) { clearTimeout(chapterPauseTimer); chapterPauseTimer = null; }
        current = null;
        currentParagraphNumber = -1;
        clearHighlight();
        renderButton();
        fetchForPage(resolvePage, { force: true, afterLoad: function () {
            if (!current || !current.available) return;
            // Park the playhead (and seek bar) at the chapter's opening
            // paragraph so a paused reader sees 0:00 of the new chapter, and
            // resume playback there if we were playing when they jumped.
            var marker = markerForPage(current.markers, headingPage);
            seekChapterToMs(marker ? marker.offset_ms : 0);
            if (wasPlaying) startPlayback();
        }});
    };
})();
