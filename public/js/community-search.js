/* ── community-search.js ── Search bar and results ── */
(function() {
'use strict';

window.CommunitySearch = {};

var _timeout = null;

// ── Init search bar (event delegation — works even if input added/removed dynamically) ──
CommunitySearch.init = function() {
    document.addEventListener('input', function(e) {
        if (!e.target || e.target.id !== 'community-search') return;
        clearTimeout(_timeout);
        var q = e.target.value.trim();
        if (q.length < 2) {
            CommunitySearch.hideResults();
            return;
        }
        _timeout = setTimeout(function() { CommunitySearch.search(q); }, 300);
    });

    document.addEventListener('keydown', function(e) {
        if (!e.target || e.target.id !== 'community-search') return;
        if (e.key === 'Escape') {
            e.target.value = '';
            CommunitySearch.hideResults();
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            var q = e.target.value.trim();
            if (q.length >= 2) CommunitySearch.search(q);
        }
    });

    // Close results on outside click
    document.addEventListener('click', function(e) {
        var container = document.getElementById('search-results');
        var input = document.getElementById('community-search');
        if (container && !container.contains(e.target) && e.target !== input) {
            CommunitySearch.hideResults();
        }
    });
};

// ── Perform search ──
CommunitySearch.search = function(query) {
    // #hashtag = filter by category name
    if (query.charAt(0) === '#' && query.length > 1) {
        var catName = query.substring(1).toLowerCase();
        var cats = Community.categories || [];
        for (var i = 0; i < cats.length; i++) {
            if (cats[i].category_name.toLowerCase() === catName || cats[i].category_slug.toLowerCase() === catName) {
                Community.filterCategory(cats[i].category_slug);
                document.getElementById('community-search').value = '';
                CommunitySearch.hideResults();
                return;
            }
        }
        // No exact match — show as no results
        var container = document.getElementById('search-results');
        if (container) {
            container.innerHTML = '<div class="search-empty">No category "' + Community.esc(query) + '"</div>';
            container.style.display = '';
        }
        return;
    }

    var url = '/api/community-search.php?q=' + encodeURIComponent(query);
    if (Community.currentCategory) {
        url += '&category=' + encodeURIComponent(Community.currentCategory);
    }

    // Claim the topic-list render slot so a late-arriving loadTopics() fetch
    // (e.g. the initial page-load list) can't clobber these results.
    Community._listSeq = (Community._listSeq || 0) + 1;
    var _seq = Community._listSeq;

    // Show an immediate "Searching…" indicator so the action has visible feedback.
    var dd0 = document.getElementById('search-results');
    if (dd0) { dd0.innerHTML = ''; dd0.style.display = 'none'; }
    if (typeof Community !== 'undefined' && Community.showView) Community.showView('view-topics');
    var topicEl0 = document.getElementById('topic-list-content');
    if (topicEl0) {
        CommunitySearch._injectSpinnerCss();
        topicEl0.innerHTML = '<div class="search-results-header">'
            + '<button class="btn btn-sm btn-outline" onclick="CommunitySearch.clear()">&larr; Back to topics</button>'
            + '<span style="margin-left:12px;font-size:0.9rem;color:#666;">Searching for "<strong>' + Community.esc(query) + '</strong>"&hellip;</span>'
            + '</div>'
            + '<div class="search-empty" style="display:flex;align-items:center;justify-content:center;gap:10px;padding:40px 14px;color:#888;"><span class="cs-spinner"></span> Searching&hellip;</div>';
    }

    Community.api(url).then(function(data) {
        if (_seq !== Community._listSeq) return;  // a newer list/search render superseded us
        // API returns separate topics and replies arrays — merge them
        var results = [];
        (data.topics || []).forEach(function(r) { r.match_type = 'topic'; results.push(r); });
        (data.replies || []).forEach(function(r) { r.match_type = 'reply'; results.push(r); });
        if (!results.length && data.results) results = data.results;

        // Render into the main topic list area (full results page, not dropdown)
        var topicEl = document.getElementById('topic-list-content');
        var dropdown = document.getElementById('search-results');
        if (dropdown) { dropdown.innerHTML = ''; dropdown.style.display = 'none'; }
        if (!topicEl) return;

        // Show view-topics so results are visible
        if (typeof Community !== 'undefined' && Community.showView) Community.showView('view-topics');

        if (!results.length) {
            topicEl.innerHTML = '<div class="search-results-header">'
                + '<button class="btn btn-sm btn-outline" onclick="CommunitySearch.clear()">&larr; Back to topics</button>'
                + '<span style="margin-left:12px;font-size:0.9rem;color:#666;">No results for "<strong>' + Community.esc(query) + '</strong>"</span>'
                + '</div>';
            return;
        }

        var html = '<div class="search-results-header">'
            + '<button class="btn btn-sm btn-outline" onclick="CommunitySearch.clear()">&larr; Back to topics</button>'
            + '<span style="margin-left:12px;font-size:0.9rem;color:#666;"><strong>' + results.length + '</strong> result' + (results.length === 1 ? '' : 's') + ' for "<strong>' + Community.esc(query) + '</strong>"</span>'
            + '</div>';

        html += '<div class="search-results-list">';
        results.forEach(function(r) {
            var url = '#topic/' + r.topic_key;
            html += '<div class="topic-item" style="cursor:pointer;" onclick="window.location.hash=\'' + url + '\'">'
                + '<div class="topic-info">'
                + '<div class="topic-title">' + Community.esc(r.topic_title || r.title || '')
                + (r.match_type === 'reply' ? ' <span style="font-size:0.72rem;color:#888;font-weight:400;">(match in reply)</span>' : '')
                + '</div>'
                + '<div class="topic-meta">'
                + Community.esc(r.user_name_display || 'Anonymous') + ' &middot; ' + Community.timeAgo(r.topic_dtime || r.reply_dtime || r.dtime)
                + '</div>'
                + (r.snippet ? '<div class="search-snippet" style="font-size:0.88rem;color:#555;margin-top:6px;line-height:1.5;">' + r.snippet + '</div>' : '')
                + '</div>'
                + '</div>';
        });
        html += '</div>';

        topicEl.innerHTML = html;
    }).catch(function() {
        if (_seq !== Community._listSeq) return;
        var topicEl = document.getElementById('topic-list-content');
        if (!topicEl) return;
        topicEl.innerHTML = '<div class="search-results-header">'
            + '<button class="btn btn-sm btn-outline" onclick="CommunitySearch.clear()">&larr; Back to topics</button></div>'
            + '<div class="search-empty">Search failed. Please try again.</div>';
    });
};

// ── Inject the search spinner CSS once ──
CommunitySearch._injectSpinnerCss = function() {
    if (document.getElementById('cs-spinner-style')) return;
    var s = document.createElement('style');
    s.id = 'cs-spinner-style';
    s.textContent = '.cs-spinner{display:inline-block;width:18px;height:18px;border:2px solid #d4d7e8;border-top-color:#31345A;border-radius:50%;animation:csspin 0.7s linear infinite;vertical-align:middle;}@keyframes csspin{to{transform:rotate(360deg)}}';
    document.head.appendChild(s);
};

CommunitySearch.clear = function() {
    var input = document.getElementById('community-search');
    if (input) input.value = '';
    // Reload current topic list
    if (typeof CommunityTopics !== 'undefined' && CommunityTopics.loadTopics) {
        var opts = {};
        if (Community.currentSection === 'comments') opts.parentSlug = 'comments';
        CommunityTopics.loadTopics(1, Community.currentCategory, opts);
    } else {
        window.location.hash = '#topics';
    }
};

// ── Trigger search from button click or external code ──
CommunitySearch.runFromInput = function() {
    var input = document.getElementById('community-search');
    if (!input) return;
    var q = input.value.trim();
    if (q.length < 2) return;
    CommunitySearch.search(q);
};

// ── Hide results ──
CommunitySearch.hideResults = function() {
    var container = document.getElementById('search-results');
    if (container) container.style.display = 'none';
};

// Init: handle both cases — DOM already ready or still loading
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { CommunitySearch.init(); });
} else {
    CommunitySearch.init();
}

})();
