/* admin-nav.js — single source for the admin navigation toolbar */
(function() {
'use strict';

// Load error reporter on admin pages too
if (!document.getElementById('error-reporter-js')) {
    var s = document.createElement('script');
    s.id = 'error-reporter-js';
    s.src = '/js/error-reporter.js?v=11';
    document.head.appendChild(s);
}

// Load shared full-message error/notice dialog (routes alert() through a modal
// with a scrollable, copyable textarea so long errors are never truncated).
if (!document.getElementById('admin-error-modal-js')) {
    var em = document.createElement('script');
    em.id = 'admin-error-modal-js';
    em.src = '/js/admin-error-modal.js?v=1';
    document.head.appendChild(em);
}

// Load dirty-tracking for Save buttons
if (!document.getElementById('admin-dirty-js')) {
    var d = document.createElement('script');
    d.id = 'admin-dirty-js';
    d.src = '/js/admin-dirty.js?v=3';
    document.head.appendChild(d);
}

var tabs = [
    ['admin-site.html', 'Site'],
    ['admin-fonts.html', 'Fonts', 'Central font registry — drives every text editor on the site.'],
    ['admin-home.html', 'Home'],
    ['admin-translation.html', 'Translation'],
    ['admin-series.html', 'Series'],
    ['admin-books.html', 'Books'],
    ['admin-flipbook.html', 'Flipbook'],
    ['admin-word.html', 'Word'],
    ['admin-glossary.html', 'Glossary'],
    ['admin-lookups.html', 'Lookups'],
    ['admin-ask.html', 'Ask'],
    ['admin-timeline.html', 'Timeline'],
    ['admin-memorial.html', 'Memorial'],
    ['admin-media.html', 'Media'],
    ['admin-ai.html', 'AI', 'AI image + video generation (image-to-video, etc.).'],
    ['admin-tts.html', 'TTS', 'Configure voices, pronunciations, pauses, and generate audio per book.'],
    ['admin-music.html', 'Music'],
    ['admin-chat.html', 'Chat'],
    ['admin-comments.html', 'Comments'],
    ['admin-invite.html', 'Invite'],
    ['admin-doyouyada.html', 'DoYou?'],
    ['admin-basics.html', 'Basics'],
    ['admin-vlog.html', 'Vlog'],
    ['admin-backgrounds.html', 'Backgrounds'],
    ['admin-feeds.html', 'Feeds'],
    ['admin-pages.html', 'Pages'],
    ['/test/admin-pages.html', 'Pages-New', 'Prototype multi-section page builder (yy_page_test) — the new test page editor.'],
    ['admin-redirects.html', 'Redirects'],
    ['admin-links.html', 'Links'],
    ['admin-resources.html', 'Resources'],
    ['admin-users.html', 'Users'],
    ['admin-test.html', 'Tests'],
    ['admin-monitoring.html', 'Monitor'],
    ['admin-perf.html', 'Reports', 'Performance + future reports (response times, host CPU steal, DB load).'],
    ['admin-settings.html', 'Settings', 'System settings — Auto-Fix cadence, resource caps, run timeout, and pause.'],
    ['admin-search.html', 'Search'],
    ['admin-misc.html', 'Misc']
];

var nav = document.querySelector('.app-header .nav-tabs');
if (!nav) return;

// Caddy redirects /admin-foo.html -> /admin-foo, so location.pathname is
// the no-extension form. Strip .html from both sides before comparing or
// no tab ever matches (and nothing gets the active highlight).
var currentPage = (window.location.pathname.split('/').pop() || '').replace(/\.html$/, '');

nav.innerHTML = tabs.map(function(t) {
    var tabPage = t[0].replace(/\.html$/, '');
    var active = (currentPage === tabPage) ? ' active' : '';
    // Optional third element of each tuple is a hover tooltip — emitted
    // as a title attribute only when present so most tabs stay clean.
    var title = t[2] ? (' title="' + String(t[2]).replace(/"/g, '&quot;') + '"') : '';
    return '<a href="' + t[0] + '" class="nav-tab' + active + '"' + title + '>' + t[1] + '</a>';
}).join('\n');

})();
