/* ── video-comments.js ── Video comment slide-out panel ── */
(function() {
'use strict';

window.VideoComments = {};

var _currentVideoId = null;
var _currentPageCode = null;
var _currentVideoSource = null;
var _currentVideoTitle = null;
var _currentThumbnail = null;
var _emptyText = 'No comments yet. Be the first!';
var _saveText = 'Comment';
var _configLoaded = false;
var _configPromise = null;

var _sessionUser = null;
var _sessionChecked = false;

// Current user (prefer the community singleton, fall back to our own fetch).
function _vcUser() {
    try { if (typeof Community !== 'undefined' && Community.currentUser) return Community.currentUser; } catch (e) {}
    return _sessionUser;
}
// A banned user stays logged in but gets NO interface to leave comments. Silent.
function _vcBanned() {
    var u = _vcUser();
    return !!(u && u.banned);
}

function _loadConfig() {
    // Fetch config and session in parallel
    var p1 = fetch('/api/site-config.php').then(function(r) { return r.json(); }).then(function(cfg) {
        if (cfg['comments-empty-text']) _emptyText = cfg['comments-empty-text'];
        if (cfg['comments-save-text']) _saveText = cfg['comments-save-text'];
    }).catch(function() {});
    var p2 = fetch('/api/community-session.php', { credentials: 'include' }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.user) _sessionUser = d.user;
        _sessionChecked = true;
    }).catch(function() { _sessionChecked = true; });
    return Promise.all([p1, p2]).then(function() { _configLoaded = true; });
}

// ── Inject CSS ──
var style = document.createElement('style');
style.textContent = ''
    + '.vc-overlay { display:none; position:fixed; top:0; right:0; bottom:0; width:420px; max-width:100vw; background:#fff; z-index:9000; box-shadow:-4px 0 24px rgba(0,0,0,0.2); flex-direction:column; }'
    + '.vc-overlay.open { display:flex; }'
    + '.vc-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; background:#31345A; color:#fff; font-weight:700; font-size:0.95rem; flex-shrink:0; }'
    + '.vc-header button { background:none; border:none; color:#fff; cursor:pointer; font-size:1.3rem; padding:0 4px; opacity:0.7; }'
    + '.vc-header button:hover { opacity:1; }'
    + '.vc-body { flex:1; overflow-y:auto; padding:14px 18px; }'
    + '.vc-empty { text-align:center; padding:10px 16px; color:#999; font-size:0.85rem; }'
    + '.vc-comment { margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid #f0f0f0; }'
    + '.vc-comment:last-child { border-bottom:none; }'
    + '.vc-comment-author { display:flex; align-items:center; gap:8px; font-size:0.82rem; color:#666; margin-bottom:6px; }'
    + '.vc-comment-author img { width:24px; height:24px; border-radius:50%; }'
    + '.vc-comment-author .vc-initials { width:24px; height:24px; border-radius:50%; background:#31345A; color:#fff; display:flex; align-items:center; justify-content:center; font-size:0.6rem; font-weight:700; }'
    + '.vc-comment-body { font-size:0.9rem; line-height:1.5; word-wrap:break-word; color:#222; }'
    + '.vc-comment-meta { display:flex; align-items:center; gap:12px; margin-top:6px; font-size:0.75rem; color:#999; }'
    + '.vc-comment-meta button { background:none; border:none; cursor:pointer; font-size:0.78rem; color:#999; padding:0; }'
    + '.vc-comment-meta button:hover { color:#31345A; }'
    + '.vc-comment-meta button.liked { color:#e74c3c; }'
    + '.vc-comment.removed { opacity:0.4; background:#fef2f2; padding:10px; border-radius:6px; border-left:3px solid #c0392b; }'
    + '.vc-compose { padding:10px 0 0; display:flex; flex-direction:column; gap:6px; flex-shrink:0; }'
    + '.vc-compose textarea { width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:6px; font-family:inherit; font-size:0.85rem; resize:vertical; min-height:70px; box-sizing:border-box; }'
    + '.vc-compose textarea:focus { outline:none; border-color:#31345A; }'
    + '.vc-compose .vc-submit-btn { align-self:center; }'
    + '.vc-compose button.vc-submit-btn { padding:6px 18px; background:#31345A; color:#fff; border:none; border-radius:6px; font-size:0.82rem; font-weight:600; cursor:pointer; }'
    + '.vc-compose button.vc-submit-btn:hover { background:#4a4e7a; }'
    + '.vc-compose button.vc-submit-btn:disabled { background:#999; cursor:not-allowed; }'
    + '.vc-signin { text-align:center; padding:12px 18px; border-top:1px solid #eee; font-size:0.85rem; color:#666; }'
    + '.vc-signin a { color:#31345A; font-weight:600; cursor:pointer; text-decoration:underline; }'
    + '.vc-badge { display:inline-flex; align-items:center; justify-content:center; background:#31345A; color:#fff; font-size:0.65rem; padding:1px 5px; border-radius:8px; margin-left:4px; min-width:14px; }'
    + '.vc-link { cursor:pointer; color:#31345A; font-size:0.78rem; text-decoration:none; display:inline-flex; align-items:center; gap:3px; opacity:0.7; transition:opacity 0.15s; }'
    + '.vc-link:hover { opacity:1; text-decoration:underline; }'
    + '@media (max-width:480px) { .vc-overlay { width:100vw; } }'
    ;
document.head.appendChild(style);

// Preload config and session immediately so lightbox opens fast
_configPromise = _loadConfig();

// ── Create overlay ──
var overlay = document.createElement('div');
overlay.className = 'vc-overlay';
overlay.id = 'vc-overlay';
overlay.innerHTML = '<div class="vc-header"><span id="vc-title">Comments</span><button onclick="VideoComments.close()" title="Close">&times;</button></div>'
    + '<div class="vc-body" id="vc-body"></div>'
    + '<div id="vc-compose-area"></div>';
document.body.appendChild(overlay);

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && overlay.classList.contains('open')) VideoComments.close();
});

var _inlineTarget = null; // if set, render inline instead of overlay

// ── Open (overlay mode) ──
VideoComments.open = function(videoId, pageCode, videoSource, videoTitle) {
    _currentVideoId = videoId;
    _currentPageCode = pageCode || 'unknown';
    _currentVideoSource = videoSource || 'youtube';
    _currentVideoTitle = videoTitle || '';
    _inlineTarget = null;
    overlay.classList.add('open');
    VideoComments.load();
};

// ── Open inline into a specific element ──
VideoComments.openInline = function(videoId, pageCode, videoSource, targetId, videoTitle, thumbnail) {
    _currentVideoId = videoId;
    _currentPageCode = pageCode || 'unknown';
    _currentVideoSource = videoSource || 'youtube';
    _currentVideoTitle = videoTitle || '';
    _currentThumbnail = thumbnail || '';
    _inlineTarget = document.getElementById(targetId);
    if (_inlineTarget) VideoComments.load();
};

VideoComments.close = function() {
    overlay.classList.remove('open');
    if (_inlineTarget) _inlineTarget.innerHTML = '';
    _inlineTarget = null;
    _currentVideoId = null;
};

// ── Load ──
VideoComments.load = function() {
    var body = _inlineTarget || document.getElementById('vc-body');
    body.innerHTML = '<div class="vc-empty">Loading...</div>';

    // Ensure config is loaded (fetches once, then cached in memory)
    var ready = _configLoaded ? Promise.resolve() : (_configPromise || (_configPromise = _loadConfig()));
    ready.then(function() { VideoComments._doLoad(); });
};

VideoComments._doLoad = function() {
    var body = _inlineTarget || document.getElementById('vc-body');
    var url = '/api/video-comments.php?video_id=' + encodeURIComponent(_currentVideoId);
    if (_currentPageCode) url += '&page_code=' + encodeURIComponent(_currentPageCode);

    fetch(url, { credentials: 'include' }).then(function(r) { return r.json(); }).then(function(data) {
        var comments = data.comments || [];
        var userLikes = data.user_likes || [];
        var isMod = data.is_mod;
        var user = null;
        try { if (typeof Community !== 'undefined' && Community.currentUser) user = Community.currentUser; } catch(e) {}
        if (!user && _sessionUser) user = _sessionUser;

        if (!comments.length) {
            body.innerHTML = '<div class="vc-empty">' + esc(_emptyText) + '</div>';
        } else {
            var html = '';
            for (var i = 0; i < comments.length; i++) {
                var c = comments[i];
                var isRemoved = !!c.comment_delete_dtime;
                var isAuthor = user && (user.user_key == c.user_key);
                var liked = userLikes.indexOf(c.comment_key) >= 0 || userLikes.indexOf(String(c.comment_key)) >= 0;

                html += '<div class="vc-comment' + (isRemoved ? ' removed' : '') + '">';
                if (isRemoved && isMod) {
                    html += '<div style="font-size:0.72rem;color:#856404;margin-bottom:4px;">Removed' + (c.comment_delete_note ? ': ' + esc(c.comment_delete_note) : '') + ' <button style="font-size:0.7rem;color:#31345A;background:none;border:1px solid #ccc;border-radius:3px;padding:1px 6px;cursor:pointer;" onclick="VideoComments.restore(' + c.comment_key + ')">Restore</button></div>';
                }
                var av = c.user_avatar
                    ? '<img src="' + esc(c.user_avatar) + '" alt="" onerror="this.outerHTML=\'<span class=vc-initials>' + esc(initials(c.user_display_name)) + '</span>\'">'
                    : '<span class="vc-initials">' + esc(initials(c.user_display_name)) + '</span>';
                html += '<div class="vc-comment-author">' + av + '<strong>' + esc(c.user_display_name || 'Anonymous') + '</strong> &middot; ' + timeAgo(c.comment_dtime) + '</div>';
                html += '<div class="vc-comment-body" id="vc-body-' + c.comment_key + '">' + (c.comment_body_html || esc(c.comment_body)) + '</div>';
                if (c.comment_edit_dtime) html += '<span style="font-size:0.68rem;color:#999;font-style:italic;">(edited)</span>';
                if (!isRemoved) {
                    html += '<div class="vc-comment-meta">';
                    html += '<button id="vc-like-' + c.comment_key + '" class="' + (liked ? 'liked' : '') + '" onclick="VideoComments.like(' + c.comment_key + ')"><span class="vc-heart">' + (liked ? '&#9829;' : '&#9825;') + '</span> <span class="vc-like-count">' + (c.comment_like_count || 0) + '</span></button>';
                    if (isAuthor && !_vcBanned()) html += '<button onclick="VideoComments.edit(' + c.comment_key + ')">Edit</button>';
                    if (isAuthor || isMod) html += '<button onclick="VideoComments.remove(' + c.comment_key + ')">Remove</button>';
                    if (isMod && !isAuthor) html += '<button onclick="VideoComments.ban(' + c.user_key + ',\'' + esc(c.user_display_name || '') + '\')" style="color:#c0392b;">Ban</button>';
                    html += '</div>';
                }
                html += '</div>';
            }
            body.innerHTML = html;
        }

        var composeArea = _inlineTarget ? body : document.getElementById('vc-compose-area');
        var composeHtml = '';
        if (user && !user.banned) {
            // The compose box carries its own thread context in data-*
            // attributes and uses a CLASS (not a shared id) for the textarea.
            // The blog renders one inline thread per post, so a fixed
            // id="vc-input" produced duplicates — getElementById then grabbed
            // the first (often empty) one and submit() silently no-op'd after
            // the first post. Scoping every lookup to the clicked button's
            // own .vc-compose box fixes posting across multiple open threads.
            composeHtml = '<div class="vc-compose"'
                + ' data-video-id="'  + escAttr(_currentVideoId) + '"'
                + ' data-source="'    + escAttr(_currentVideoSource) + '"'
                + ' data-page-code="' + escAttr(_currentPageCode) + '"'
                + ' data-title="'     + escAttr(_currentVideoTitle) + '"'
                + ' data-thumbnail="' + escAttr(_currentThumbnail) + '"'
                + ' data-target="'    + (_inlineTarget ? escAttr(_inlineTarget.id) : '') + '">'
                + '<textarea class="vc-input" placeholder="Add a comment..." rows="2"></textarea>'
                + '<button class="vc-submit-btn" onclick="VideoComments.submit(this)">' + esc(_saveText) + '</button></div>';
        } else if (!user) {
            composeHtml = '<div class="vc-signin"><a href="javascript:void(0)" onclick="if(typeof CommunityAuth!==\'undefined\')CommunityAuth.openLoginModal(\'login\')">Sign in</a> to comment</div>';
        }
        // Banned (logged in): no compose box, no sign-in prompt — silent.
        if (_inlineTarget) {
            body.innerHTML += composeHtml;
        } else {
            composeArea.innerHTML = composeHtml;
        }
        // Bind Enter-to-submit on the compose box we just rendered, scoped to
        // its own thread (no cross-thread id collision).
        var justRendered = _inlineTarget ? body : composeArea;
        var input = justRendered ? justRendered.querySelector('.vc-input') : null;
        if (input) input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                var box = this.closest('.vc-compose');
                VideoComments.submit(box ? box.querySelector('.vc-submit-btn') : null);
            }
        });
    });
};

var _submitting = false;
// Restore the compose UI to a usable state after a submit settles, win or
// lose. Without this, on any path that didn't re-render the compose area
// (network error, server-returned error, or a load() that resolves with
// user=null and renders the sign-in bar instead) the button stays
// disabled "Posting..." and the user has to refresh the page to post
// again. Bug report: "I can only do one, then I have to refresh the page
// to post another."
// Restore a compose box to a usable state after a submit settles. `box` is
// the specific .vc-compose element (so the right thread's button/textarea
// are reset when several inline threads are open); falls back to the first
// one if not supplied.
function _resetComposeUI(box, clearInput) {
    _submitting = false;
    var scope = (box && box.querySelector) ? box : document;
    var btn = scope.querySelector('.vc-submit-btn') || document.querySelector('.vc-submit-btn');
    if (btn) { btn.disabled = false; btn.textContent = _saveText; }
    if (clearInput) {
        var input = scope.querySelector('.vc-input') || document.querySelector('.vc-input');
        if (input) input.value = '';
    }
}

// `btn` is the clicked Comment button (or the submit button paired with the
// Enter-key handler). Everything is resolved relative to its .vc-compose box
// so multiple open threads on the blog don't collide on a shared id.
VideoComments.submit = function(btn) {
    if (_submitting) return;
    if (_vcBanned()) return; // banned: silent
    var box = (btn && btn.closest) ? btn.closest('.vc-compose') : document.querySelector('.vc-compose');
    var input = box ? box.querySelector('.vc-input') : null;
    if (!input) return;
    var body = input.value.trim();
    if (!body) return;
    var ds = box.dataset || {};
    _submitting = true;
    var sbtn = box.querySelector('.vc-submit-btn');
    if (sbtn) { sbtn.disabled = true; sbtn.textContent = 'Posting...'; }
    fetch('/api/video-comments.php', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add', video_id: ds.videoId, video_source: ds.source, page_code: ds.pageCode, video_title: ds.title, thumbnail: ds.thumbnail, body: body })
    }).then(function(r) { return r.json(); }).then(function(data) {
        if (data.error) {
            alert(data.error);
            _resetComposeUI(box, false);  // keep typed text so user can retry
            return;
        }
        // Success: clear + re-enable THIS box, then reload THIS thread. Restore
        // the thread's context into the module globals first because load()
        // still reads them — this also fixes posting to the correct post when
        // another thread was opened more recently.
        _resetComposeUI(box, true);
        _currentVideoId     = ds.videoId;
        _currentVideoSource = ds.source;
        _currentPageCode    = ds.pageCode;
        _currentVideoTitle  = ds.title;
        _currentThumbnail   = ds.thumbnail;
        _inlineTarget       = ds.target ? document.getElementById(ds.target) : null;
        VideoComments.load();
    }).catch(function(err) {
        alert('Comment failed to post — please retry.');
        _resetComposeUI(box, false);
    });
};

VideoComments.like = function(ck) {
    fetch('/api/video-comments.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'like', comment_key: ck }) })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || data.error) return;
            var btn = document.getElementById('vc-like-' + ck);
            if (!btn) return;
            if (data.liked) btn.classList.add('liked'); else btn.classList.remove('liked');
            var heart = btn.querySelector('.vc-heart');
            if (heart) heart.innerHTML = data.liked ? '&#9829;' : '&#9825;';
            var countEl = btn.querySelector('.vc-like-count');
            if (countEl && typeof data.count === 'number') countEl.textContent = data.count;
        });
};

VideoComments.remove = function(ck) {
    var note = prompt('Reason for removal (optional):');
    if (note === null) return;
    fetch('/api/video-comments.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', comment_key: ck, note: note }) })
        .then(function() { VideoComments.load(); });
};

VideoComments.edit = function(ck) {
    if (_vcBanned()) return; // banned: silent
    var bodyEl = document.getElementById('vc-body-' + ck);
    if (!bodyEl) return;
    var current = bodyEl.textContent || bodyEl.innerText;
    bodyEl.innerHTML = '<textarea id="vc-edit-' + ck + '" style="width:100%;min-height:60px;padding:6px 8px;border:1px solid #31345A;border-radius:4px;font-family:inherit;font-size:0.85rem;resize:vertical;">' + esc(current) + '</textarea>'
        + '<div style="margin-top:6px;display:flex;gap:6px;">'
        + '<button style="padding:4px 12px;background:#31345A;color:#fff;border:none;border-radius:4px;font-size:0.78rem;cursor:pointer;" onclick="VideoComments.saveEdit(' + ck + ')">Save</button>'
        + '<button style="padding:4px 12px;background:#eee;border:1px solid #ccc;border-radius:4px;font-size:0.78rem;cursor:pointer;" onclick="VideoComments.load()">Cancel</button>'
        + '</div>';
    document.getElementById('vc-edit-' + ck).focus();
};

VideoComments.saveEdit = function(ck) {
    if (_vcBanned()) return; // banned: silent
    var input = document.getElementById('vc-edit-' + ck);
    if (!input) return;
    var body = input.value.trim();
    if (!body) return;
    fetch('/api/video-comments.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'edit', comment_key: ck, body: body }) })
        .then(function(r) { return r.json(); }).then(function(data) {
            if (data.error) { alert(data.error); return; }
            VideoComments.load();
        });
};

VideoComments.ban = function(uk, name) {
    var reason = prompt('Ban ' + name + '? Enter reason (optional):');
    if (reason === null) return;
    fetch('/api/video-comments.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'ban', user_key: uk, reason: reason }) })
        .then(function(r) { return r.json(); }).then(function(data) {
            if (data.error) { alert(data.error); return; }
            alert(name + ' has been banned.');
            VideoComments.load();
        });
};

VideoComments.restore = function(ck) {
    fetch('/api/video-comments.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'restore', comment_key: ck }) })
        .then(function() { VideoComments.load(); });
};

// ── Create clickable comment link for video cards ──
VideoComments.link = function(videoId, pageCode, videoSource) {
    return '<a class="vc-link" onclick="event.stopPropagation();VideoComments.open(\'' + esc(videoId) + '\',\'' + esc(pageCode) + '\',\'' + esc(videoSource || 'youtube') + '\')">&#128172; Comments</a>';
};

function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
// Attribute-safe escape: esc() handles & < >; also escape quotes so a value
// with a " (e.g. a video title) can't break out of a double-quoted attr.
function escAttr(s) { return esc(s == null ? '' : String(s)).replace(/"/g, '&quot;'); }
function initials(n) { if (!n) return '?'; var p = n.trim().split(/\s+/); return p.length > 1 ? (p[0][0]+p[p.length-1][0]).toUpperCase() : p[0].substring(0,2).toUpperCase(); }
function timeAgo(dt) {
    if (!dt) return '';
    var diff = (Date.now() - new Date(dt).getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    if (diff < 2592000) return Math.floor(diff/86400) + 'd ago';
    return new Date(dt).toLocaleDateString();
}

})();
