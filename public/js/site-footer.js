/* site-footer.js — renders the entire site footer from a single source */
(function() {
'use strict';


// Load error reporter
if (!document.getElementById('error-reporter-js')) {
    var s = document.createElement('script');
    s.id = 'error-reporter-js';
    s.src = '/js/error-reporter.js?v=12';
    document.head.appendChild(s);
}

// Google Analytics 4
if (!document.getElementById('ga-script')) {
    var ga = document.createElement('script');
    ga.id = 'ga-script';
    ga.async = true;
    ga.src = 'https://www.googletagmanager.com/gtag/js?id=G-VL844ZWNR9';
    document.head.appendChild(ga);
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-VL844ZWNR9');
}

// Inject footer CSS if not already present
if (!document.getElementById('site-footer-css')) {
    var style = document.createElement('style');
    style.id = 'site-footer-css';
    style.textContent = ''
        + 'html, body { overflow-x: hidden; max-width: 100%; }'
        + 'footer { background: url("/images/yy-bg-bluewave.jpg") center/cover no-repeat; position: relative; width: 100%; overflow: hidden; padding: 30px 20px 20px; color: #E5C86C; box-sizing: border-box; margin: auto -20px 0; padding-left: 20px; padding-right: 20px; width: calc(100% + 40px); }'
        + 'footer .footer-video { position: absolute; top: 50%; left: 50%; min-width: 100%; min-height: 100%; transform: translate(-50%, -50%); object-fit: cover; z-index: 0; }'
        + 'footer .footer-inner { position: relative; z-index: 1; max-width: 1000px; margin: 0 auto; display: flex; gap: 40px; justify-content: center; flex-wrap: wrap; align-items: flex-start; }'
        + 'footer .footer-col { min-width: auto; }'
        + 'footer .footer-col h4 { font-family: "Julius Sans One", sans-serif; font-size: 1rem; color: #E5C86C; border-bottom: 1px solid rgba(229,200,108,0.3); padding-bottom: 6px; margin: 0 0 10px; }'
        + 'footer .footer-col a { display: block; font-size: 0.9rem; color: rgba(255,255,255,0.8); text-decoration: none; padding: 3px 0; }'
        + 'footer .footer-col a:hover { color: #fff; }'
        + 'footer .footer-links { display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px 20px; }'
        + 'footer .footer-links-2col { grid-template-columns: 1fr 1fr; }'
        + 'footer .footer-links-1col { grid-template-columns: 1fr; }'
        + 'footer .footer-bottom { position: relative; z-index: 1; text-align: center; margin-top: 20px; font-size: 0.8rem; color: rgba(255,255,255,0.5); }';
    document.head.appendChild(style);
}

// Find the footer element
var footer = document.querySelector('footer');
if (!footer) {
    footer = document.createElement('footer');
    document.body.appendChild(footer);
}

// Build footer HTML
footer.innerHTML = '<video class="footer-video" autoplay muted loop playsinline></video>'
    + '<div class="footer-inner">'
    + '<div class="footer-col">'
    + '<h4 data-config="footer-text-books">Books</h4>'
    + '<div class="footer-links footer-links-2col">'
    + '<a href="books#An_Intro_to_God">An Intro to God</a>'
    + '<a href="books#Yada_Yahowah">Yada Yahowah</a>'
    + '<a href="books#Observations">Observations</a>'
    + '<a href="books#Coming_Home">Coming Home</a>'
    + '<a href="books#Babel">Babel</a>'
    + '<a href="books#Twistianity">Twistianity</a>'
    + '<a href="books#God_Damn_Religion">God Damn Religion</a>'
    + '<a href="books#In_the_Company">In the Company</a>'
    + '</div></div>'
    + '<div class="footer-col">'
    + '<h4 data-config="footer-text-links">Links</h4>'
    + '<div style="display:flex;gap:40px;align-items:flex-start;">'
    + '<div class="footer-links footer-links-1col" data-footer-col="1"></div>'
    + '<div class="footer-links footer-links-1col" data-footer-col="2"></div>'
    + '<div class="footer-links footer-links-1col" data-footer-col="3"></div>'
    + '</div></div></div>'
    + '<div class="footer-bottom">Copyright 2001 - 2026</div>';

// Load dynamic footer links
fetch('/api/page-footer.php')
    .then(function(r) { return r.json(); })
    .then(function(cols) {
        Array.prototype.forEach.call(footer.querySelectorAll('[data-footer-col]'), function(el) {
            var col = el.getAttribute('data-footer-col');
            var links = cols[col] || [];
            if (links.length) {
                el.innerHTML = links.map(function(l) {
                    return '<a href="' + l.url + '">' + l.title + '</a>';
                }).join('');
            }
        });
    });

// Apply data-config overrides from site-config (if loaded later by another script)
fetch('/api/site-config.php')
    .then(function(r) { return r.json(); })
    .then(function(cfg) {
        Array.prototype.forEach.call(footer.querySelectorAll('[data-config]'), function(el) {
            var k = el.getAttribute('data-config');
            if (cfg[k] != null) el.textContent = cfg[k];
        });
    });

// Background video — apply from sessionStorage since bg-video.js may have run before footer was created
(function() {
    var vid = footer.querySelector('.footer-video');
    if (!vid) return;
    var webm = sessionStorage.getItem('yy_bg_video');
    var mp4 = sessionStorage.getItem('yy_bg_mp4');
    var thumb = sessionStorage.getItem('yy_bg_thumb');
    if (thumb) vid.setAttribute('poster', thumb);
    if (webm) {
        var s1 = document.createElement('source');
        s1.setAttribute('src', webm);
        s1.setAttribute('type', 'video/webm');
        vid.appendChild(s1);
        if (mp4) {
            var s2 = document.createElement('source');
            s2.setAttribute('src', mp4);
            s2.setAttribute('type', 'video/mp4');
            vid.appendChild(s2);
        }
        vid.load();
    } else if (typeof window._bgVideoSrc !== 'undefined' && window._bgVideoSrc) {
        vid.src = window._bgVideoSrc;
    }
})();

// ── Scroll nav buttons ──
var scrollBtnStyle = document.createElement('style');
scrollBtnStyle.textContent = ''
    + '.scroll-btn { display:none; position:fixed; right:8px; width:40px; height:40px; border-radius:50%; background:rgba(49,52,90,0.85); color:#E5C86C; border:none; font-size:1.1rem; cursor:pointer; z-index:9999; box-shadow:0 2px 8px rgba(0,0,0,0.3); transition:opacity 0.2s,background 0.2s; opacity:0.7; align-items:center; justify-content:center; }'
    + '.scroll-btn:hover { opacity:1; background:rgba(49,52,90,1); }'
    + '.scroll-btn.visible { display:flex; }';
document.head.appendChild(scrollBtnStyle);

// Back to top — fixed bottom-right
var btt = document.createElement('button');
btt.id = 'back-to-top';
btt.className = 'scroll-btn';
btt.style.bottom = '8px';
btt.style.right = '8px';
btt.innerHTML = '&#9650;';
btt.title = 'Back to top';
btt.setAttribute('aria-label', 'Back to top');
btt.onclick = function() { window.scrollTo({ top: 0, behavior: 'smooth' }); };
document.body.appendChild(btt);

// Scroll to bottom — fixed top-right
var btb = document.createElement('button');
btb.id = 'scroll-to-bottom';
btb.className = 'scroll-btn';
btb.style.top = '8px';
btb.style.right = '8px';
btb.innerHTML = '&#9660;';
btb.title = 'Go to bottom';
btb.setAttribute('aria-label', 'Go to bottom');
btb.onclick = function() {
    btb.classList.remove('visible');
    var ft = document.querySelector('footer');
    if (ft) { var y = ft.getBoundingClientRect().top + window.scrollY - window.innerHeight; window.scrollTo({ top: y, behavior: 'smooth' }); }
    else { window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }); }
};
document.body.appendChild(btb);

function updateScrollButtons() {
    var docHeight = document.documentElement.scrollHeight;
    var winHeight = window.innerHeight;
    var scrollY = window.scrollY;
    var ft = document.querySelector('footer');
    var footerHeight = ft ? ft.offsetHeight : 0;
    var contentBottom = docHeight - footerHeight;
    var atBottom = scrollY + winHeight >= contentBottom - 50;
    var hasOverflow = contentBottom > winHeight + 150;
    var showUp = scrollY > 150;
    var showDown = hasOverflow && !atBottom;
    btt.classList.toggle('visible', showUp);
    btb.classList.toggle('visible', showDown);
    // Force display in case inline styles override the class
    btt.style.display = showUp ? 'flex' : 'none';
    btb.style.display = showDown ? 'flex' : 'none';
}
window.addEventListener('scroll', updateScrollButtons, { passive: true });
window.addEventListener('resize', updateScrollButtons, { passive: true });
// Run after page settles (images, dynamic content)
updateScrollButtons();
setTimeout(updateScrollButtons, 500);
setTimeout(updateScrollButtons, 2000);
setTimeout(updateScrollButtons, 5000);
// Re-check when DOM changes (dynamic content loading)
if (typeof MutationObserver !== 'undefined') {
    var _scrollBtnTimer = null;
    new MutationObserver(function() {
        clearTimeout(_scrollBtnTimer);
        _scrollBtnTimer = setTimeout(updateScrollButtons, 200);
    }).observe(document.body, { childList: true, subtree: true });
}

// ── Live Feed indicator ──
(function() {
    var _liveStyleInjected = false;
    var _isLive = false;
    var _liveStarted = 0; // timestamp when live was first detected
    var MAX_LIVE_POLL = 3 * 60 * 60 * 1000; // 3 hours

    var _liveData = null;

    function injectLiveStyle() {
        if (_liveStyleInjected) return;
        _liveStyleInjected = true;
        var style = document.createElement('style');
        style.textContent = ''
            + '#live-indicator { position:fixed; top:8px; left:8px; z-index:9999; cursor:pointer; width:200px; box-shadow:0 4px 16px rgba(0,0,0,0.5); border-radius:8px; overflow:hidden; }'
            + '#live-indicator .live-badge { position:relative; display:flex; align-items:center; justify-content:center; gap:6px; background:rgba(200,0,0,0.9); color:#fff; padding:6px 28px 6px 10px; font-size:0.85rem; font-weight:700; transition:background 0.15s; width:100%; box-sizing:border-box; }'
            + '#live-indicator:hover .live-badge { background:rgba(220,0,0,1); }'
            + '#live-indicator .live-dot { width:10px; height:10px; background:#fff; border-radius:50%; animation:live-pulse 1.5s ease-in-out infinite; }'
            + '@keyframes live-pulse { 0%,100% { opacity:1; } 50% { opacity:0.3; } }'
            + '.live-preview { width:100%; background:#000; position:relative; box-sizing:border-box; }'
            + '.live-preview img { width:100%; aspect-ratio:16/9; object-fit:cover; display:block; }'
            + '.live-preview .live-preview-title { padding:6px 8px; font-size:0.7rem; font-weight:400; color:#ccc; line-height:1.3; white-space:normal; }'
            + '#live-indicator .live-close-x { position:absolute; top:50%; right:6px; transform:translateY(-50%); width:20px; height:20px; border-radius:50%; background:rgba(0,0,0,0.35); color:#fff; font-size:13px; line-height:1; text-align:center; cursor:pointer; border:none; padding:0; z-index:3; transition:background 0.15s; display:flex; align-items:center; justify-content:center; }'
            + '#live-indicator .live-close-x:hover { background:rgba(0,0,0,0.7); color:#E5C86C; }'
            + '#live-overlay { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.85); z-index:10001; justify-content:center; align-items:center; }'
            + '#live-overlay.active { display:flex; }'
            + '#live-container { display:flex; width:95vw; max-width:1400px; height:85vh; border-radius:10px; overflow:hidden; box-shadow:0 4px 30px rgba(0,0,0,0.6); position:relative; }'
            + '#live-container .live-video { flex:1; min-width:0; background:#000; }'
            + '#live-container .live-video iframe { width:100%; height:100%; border:none; }'
            + '#live-container .live-chat { width:360px; flex-shrink:0; background:#fff; }'
            + '#live-container .live-chat iframe { width:100%; height:100%; border:none; }'
            + '#live-close { position:absolute; top:-36px; right:0; color:#fff; font-size:1.8rem; cursor:pointer; background:none; border:none; padding:4px 10px; z-index:2; }'
            + '#live-close:hover { color:#E5C86C; }'
            + '#live-title-bar { position:absolute; top:-36px; left:0; color:#E5C86C; font-size:0.85rem; font-weight:600; padding:4px 10px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:calc(100% - 60px); }'
            + '@media (max-width:800px) { #live-container { flex-direction:column; width:98vw; height:92vh; } #live-container .live-chat { width:100%; height:280px; } }';
        document.head.appendChild(style);
    }

    function openLivePopover() {
        if (!_liveData) return;
        var existing = document.getElementById('live-overlay');
        if (existing) existing.remove();

        var videoId = _liveData.video_id || '';
        var embedUrl = _liveData.embed_url || ('https://www.youtube.com/embed/' + videoId + '?autoplay=1');
        var chatUrl = 'https://www.youtube.com/live_chat?v=' + videoId + '&embed_domain=' + location.hostname;
        var title = _liveData.title || 'Live Now';

        var overlay = document.createElement('div');
        overlay.id = 'live-overlay';
        overlay.innerHTML = '<div id="live-container">'
            + '<div id="live-title-bar">' + title.replace(/</g, '&lt;') + '</div>'
            + '<button id="live-close" onclick="document.getElementById(\'live-overlay\').remove()" title="Close">&times;</button>'
            + '<div class="live-video"><iframe src="' + embedUrl + '" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>'
            + '<div class="live-chat"><iframe src="' + chatUrl + '"></iframe></div>'
            + '</div>';
        overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);
        overlay.classList.add('active');
    }

    function applyState(d) {
        // Remove ALL existing live indicators (defensive — handles edge cases
        // where multiple may have been inserted, e.g. cached/stale versions)
        Array.prototype.forEach.call(document.querySelectorAll('#live-indicator'), function(el) { el.remove(); });

        if (d && d.live) {
            // Skip rendering if user dismissed THIS specific stream this session
            var dismissedId = sessionStorage.getItem('yy_live_dismissed');
            if (dismissedId && dismissedId === (d.video_id || '')) {
                _liveData = d; // still track it for popover, but don't show indicator
                _isLive = true;
                return;
            }
            // If a new stream started, clear any previous dismissal
            if (dismissedId && dismissedId !== (d.video_id || '')) {
                sessionStorage.removeItem('yy_live_dismissed');
            }

            if (!_isLive) _liveStarted = Date.now();
            _isLive = true;
            _liveData = d;
            injectLiveStyle();
            var btn = document.createElement('div');
            btn.id = 'live-indicator';
            var isUpcoming = d.upcoming;
            var badgeLabel = isUpcoming ? 'UPCOMING' : 'LIVE';
            var titleText = d.title || (isUpcoming ? 'Upcoming' : 'Live Now');
            btn.title = titleText;
            var previewHtml = '';
            var thumbUrl = d.thumbnail || (d.video_id ? 'https://i.ytimg.com/vi/' + d.video_id + '/mqdefault.jpg' : '');
            if (thumbUrl) {
                var scheduleInfo = '';
                if (isUpcoming && d.scheduled_start) {
                    var startDate = new Date(d.scheduled_start);
                    scheduleInfo = '<div style="font-size:0.7rem;color:#E5C86C;margin-top:2px;padding:0 8px 6px;">' + startDate.toLocaleString(undefined, {weekday:'short', month:'short', day:'numeric', hour:'numeric', minute:'2-digit'}) + '</div>';
                }
                previewHtml = '<div class="live-preview">'
                    + '<img src="' + thumbUrl + '" alt="">'
                    + '<div class="live-preview-title">' + titleText.replace(/</g, '&lt;') + '</div>'
                    + scheduleInfo
                    + '</div>';
            }
            var badgeColor = isUpcoming ? 'background:#E5C86C;color:#333;' : '';
            btn.innerHTML = '<div class="live-badge" style="' + badgeColor + '">'
                + '<span class="live-dot" style="' + (isUpcoming ? 'background:#333;' : '') + '"></span> ' + badgeLabel
                + '<button class="live-close-x" title="Hide live preview" aria-label="Close">&times;</button>'
                + '</div>' + previewHtml;
            btn.addEventListener('click', openLivePopover);
            document.body.appendChild(btn);

            // Wire close button — must stop propagation so it doesn't open the popover
            var closeBtn = btn.querySelector('.live-close-x');
            if (closeBtn) {
                closeBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    e.preventDefault();
                    sessionStorage.setItem('yy_live_dismissed', d.video_id || '');
                    btn.remove();
                });
            }
        } else {
            _isLive = false;
            _liveStarted = 0;
            _liveData = null;
        }
    }

    // ── Polling for live state ──
    // Note: SSE was attempted but Apache mpm_prefork only has 150 workers,
    // and each long-lived SSE connection holds one — so SSE saturates the server.
    // Lightweight polling against the cached push state file scales much better.
    var _pollTimer = null;

    // Live broadcasts only happen 10am-10pm ET. Skip outside that window.
    function isWithinBroadcastWindow() {
        try {
            var fmt = new Intl.DateTimeFormat('en-US', {
                timeZone: 'America/New_York',
                hour: 'numeric',
                hour12: false
            });
            var hour = parseInt(fmt.format(new Date()), 10);
            return hour >= 10 && hour < 22;
        } catch(e) {
            return true;
        }
    }

    function pollLive() {
        if (!isWithinBroadcastWindow()) {
            applyState({live: false});
            scheduleNextPoll(5 * 60 * 1000); // check window again in 5 min
            return;
        }

        fetch('/api/live-check.php')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                applyState(d);
                // Faster poll while live (to detect end), slower otherwise
                scheduleNextPoll(d && d.live ? 30000 : 300000);
            })
            .catch(function() {
                scheduleNextPoll(300000);
            });
    }

    function scheduleNextPoll(delay) {
        clearTimeout(_pollTimer);
        // Stop polling if live indicator has been showing >3 hours (stale stream)
        if (_isLive && Date.now() - _liveStarted > MAX_LIVE_POLL) {
            _isLive = false;
            var el = document.getElementById('live-indicator');
            if (el) el.remove();
            return;
        }
        _pollTimer = setTimeout(pollLive, delay);
    }

    // Initial check on page load
    pollLive();

    // Recheck when tab becomes visible again
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') pollLive();
    });
})();

})();
