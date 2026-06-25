/* ── community.js ── Core utilities, routing, init ── */
(function() {
'use strict';

window.Community = window.Community || {};

// ── State ──
Community.currentUser = null;
Community.categories = [];
Community.currentCategory = '';
Community.currentPage = 1;

// ── Utility: HTML escape ──
Community.esc = function(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
};

// ── Utility: initials from name ──
Community.initials = function(name) {
    if (!name) return '?';
    var p = name.trim().split(/\s+/);
    return p.length > 1
        ? (p[0][0] + p[p.length - 1][0]).toUpperCase()
        : p[0].substring(0, 2).toUpperCase();
};

// ── Utility: avatar HTML ──
Community.avatarHtml = function(url, name, cls, lastActive) {
    cls = cls || 'topic-avatar';
    var circleCls = cls + '-circle';
    var onlineClass = Community.isOnline(lastActive) ? ' online' : '';
    var wrapper = '<span class="avatar-wrap' + onlineClass + '">';
    var closeWrap = '</span>';
    if (url) {
        return wrapper + '<img class="' + cls + '" src="' + Community.esc(url) + '" alt="" onerror="this.outerHTML=\'<span class=&quot;' + circleCls + '&quot;>' + Community.esc(Community.initials(name)) + '</span>\'">' + closeWrap;
    }
    return wrapper + '<span class="' + circleCls + '">' + Community.esc(Community.initials(name)) + '</span>' + closeWrap;
};

// ── Utility: clickable avatar with profile card ──
Community.clickableAvatar = function(userKey, url, name, cls, lastActive) {
    cls = cls || 'topic-avatar';
    var circleCls = cls + '-circle';
    var onlineClass = Community.isOnline(lastActive) ? ' online' : '';
    var onclick = ' onclick="Community.showProfileCard(event,' + userKey + ')" style="cursor:pointer;"';
    var wrapper = '<span class="avatar-wrap' + onlineClass + '"' + onclick + '>';
    var closeWrap = '</span>';
    if (url) {
        return wrapper + '<img class="' + cls + '" src="' + Community.esc(url) + '" alt="" onerror="this.outerHTML=\'<span class=&quot;' + circleCls + '&quot;>' + Community.esc(Community.initials(name)) + '</span>\'">' + closeWrap;
    }
    return wrapper + '<span class="' + circleCls + '">' + Community.esc(Community.initials(name)) + '</span>' + closeWrap;
};

// ── Utility: time ago ──
Community.timeAgo = function(dt) {
    if (!dt) return '';
    var diff = (Date.now() - new Date(dt).getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
    return new Date(dt).toLocaleDateString();
};

// ── Utility: is user online (active within 5 min) ──
Community.isOnline = function(lastActive) {
    if (!lastActive) return false;
    var diff = (Date.now() - new Date(lastActive).getTime()) / 1000;
    return diff < 300;
};

// ── Utility: show error/success flash ──
Community.showMsg = function(el, msg, cls) {
    cls = cls || 'error';
    el.className = el.className.replace(/\bflash\b/, '').replace(/\bsuccess\b|\berror\b/g, '').trim();
    el.textContent = msg;
    el.style.display = '';
    void el.offsetWidth;
    el.className = (el.className + ' ' + cls + ' flash').trim();
};

// ── Utility: fetch JSON helper ──
Community.api = function(url, opts) {
    opts = opts || {};
    if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
        opts.headers = opts.headers || {};
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(opts.body);
    }
    return fetch(url, opts).then(function(r) { return r.text().then(t => { if (!t || !t.trim()) throw new Error('Empty response'); try { return JSON.parse(t); } catch(e) { throw new Error('Invalid JSON: ' + t.substring(0, 100)); } }); });
};

// ── Utility: format rich text body (links, embeds, images, bold, italic, code) ──
Community.formatBody = function(text) {
    if (!text) return '';
    var s = Community.esc(text);
    // Code blocks ```...```
    s = s.replace(/```([\s\S]*?)```/g, '<pre class="code-block">$1</pre>');
    // Inline code `...`
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
    // Bold **...**
    s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    // Italic *...*
    s = s.replace(/\*(.+?)\*/g, '<em>$1</em>');
    // Images ![alt](url)
    s = s.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, '<img src="$2" alt="$1" class="post-image">');
    // Links [text](url)
    s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    // Auto-link bare URLs
    s = s.replace(/(^|[\s>])(https?:\/\/[^\s<]+)/g, function(m, pre, url) {
        // YouTube embed
        var ytMatch = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]+)/);
        if (ytMatch) {
            return pre + '<div class="video-embed"><iframe src="https://www.youtube.com/embed/' + ytMatch[1] + '" frameborder="0" allowfullscreen></iframe></div>';
        }
        // Rumble embed
        var rumbleMatch = url.match(/rumble\.com\/embed\/([\w-]+)/);
        if (rumbleMatch) {
            return pre + '<div class="video-embed"><iframe src="https://rumble.com/embed/' + rumbleMatch[1] + '/" frameborder="0" allowfullscreen></iframe></div>';
        }
        var rumbleWatch = url.match(/rumble\.com\/([\w-]+)\.html/);
        if (rumbleWatch) {
            return pre + '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>';
        }
        return pre + '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>';
    });
    // Newlines
    s = s.replace(/\n/g, '<br>');
    return s;
};

// ── View management ──
Community.views = ['view-topics', 'view-topic', 'view-new', 'view-profile', 'view-messages', 'view-members', 'view-bookmarks', 'view-notifications', 'view-categories'];

Community.hideAllViews = function() {
    Community.views.forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
};

Community.showView = function(id) {
    Community.hideAllViews();
    var el = document.getElementById(id);
    if (el) el.style.display = '';
    // Update nav tabs + mod bar
    document.querySelectorAll('.community-nav a, .community-mod-bar a').forEach(function(a) {
        a.classList.remove('active');
    });
    var navMap = {
        'view-topics': 'nav-topics',
        'view-topic': 'nav-topics',
        'view-new': 'nav-topics',
        'view-messages': 'nav-messages',
        'view-members': 'nav-members',
        'view-notifications': 'nav-notifications',
        'view-categories': 'nav-categories'
    };
    var navId = navMap[id];
    // Override for comments section — highlight the correct tab
    if ((id === 'view-topics' || id === 'view-topic') && Community.currentSection === 'comments') {
        var h = window.location.hash;
        if (h === '#blog-comments') navId = 'nav-blog-comments';
        else if (h === '#vlog-comments') navId = 'nav-vlog-comments';
        else navId = 'nav-comments';
    }
    if (navId) {
        var navEl = document.getElementById(navId);
        if (navEl) navEl.classList.add('active');
    }
};

// ── Routing ──
Community.route = function() {
    var hash = window.location.hash || '#topics';

    // Check for special tokens
    if (hash.indexOf('#reset=') === 0) {
        window._resetToken = hash.substring(7);
        window.location.hash = '';
        setTimeout(function() { CommunityAuth.openLoginModal('reset'); }, 300);
        return;
    }
    if (hash.indexOf('#verify=') === 0) {
        var token = hash.substring(8);
        window.location.hash = '';
        CommunityAuth.verifyEmail(token);
        return;
    }

    if (hash === '#link-account') {
        CommunityAuth.showLinkPrompt();
        return;
    }

    if (Community._skipRoute) { Community._skipRoute = false; return; }

    if (hash === '#topics' || hash === '') {
        Community.setSection('topics');
        CommunityTopics.loadTopics();
    } else if (hash === '#comments') {
        Community.setSection('comments');
        CommunityTopics.loadTopics(1, null, { parentSlug: 'comments' });
    } else if (hash === '#blog-comments') {
        Community.setSection('comments');
        Community.currentCategory = 'comment-blog';
        Community.renderCategorySidebar();
        CommunityTopics.loadTopics(1, 'comment-blog', { parentSlug: 'comments' });
    } else if (hash === '#vlog-comments') {
        Community.setSection('comments');
        Community.currentCategory = 'comment-vlog';
        Community.renderCategorySidebar();
        CommunityTopics.loadTopics(1, 'comment-vlog', { parentSlug: 'comments' });
    } else if (hash === '#bookmarks') {
        CommunityTopics.loadBookmarks();
    } else if (hash.indexOf('#category/') === 0) {
        var catSlug = hash.split('/')[1];
        Community.setSection('topics');
        Community.currentCategory = catSlug;
        CommunityTopics.loadTopics(1, catSlug);
    } else if (hash.indexOf('#topic/') === 0) {
        var key = parseInt(hash.split('/')[1]);
        if (key) CommunityTopics.loadTopic(key);
    } else if (hash === '#new-topic') {
        CommunityTopics.showNewTopic();
    } else if (hash === '#messages') {
        CommunityDM.loadInbox();
    } else if (hash.indexOf('#message/') === 0) {
        var threadKey = parseInt(hash.split('/')[1]);
        if (threadKey) CommunityDM.loadThread(threadKey);
    } else if (hash === '#members') {
        CommunityMembers.load();
    } else if (hash === '#profile') {
        CommunityProfile.show();
    } else if (hash === '#bookmarks') {
        CommunityTopics.loadBookmarks();
    } else if (hash === '#notifications') {
        CommunityNotifications.showAll();
    } else if (hash === '#categories') {
        Community.showCategoryManager();
    } else if (hash === '#merge-confirm') {
        CommunityProfile.handleMergeReturn();
    } else if (hash.indexOf('#merge-error') === 0) {
        var reason = hash.indexOf('reason=same') >= 0 ? 'That is your current account.' : 'No account found for that login.';
        alert(reason);
        window.location.hash = '#profile';
    } else {
        CommunityTopics.loadTopics();
    }
};

// ── Profile Card Popup ──
Community.showProfileCard = function(event, userKey) {
    event.stopPropagation();
    // Remove any existing card
    var existing = document.querySelector('.profile-card-popup');
    if (existing) existing.remove();

    Community.api('/api/community-members.php?user_key=' + userKey).then(function(data) {
        if (!data || data.error) return;
        var u = data.member || data;
        var card = document.createElement('div');
        card.className = 'profile-card-popup';
        var onlineClass = Community.isOnline(u.user_last_active_dtime) ? ' online' : '';
        card.innerHTML = '<div class="profile-card-inner">'
            + '<button class="profile-card-close" onclick="this.parentElement.parentElement.remove()">&times;</button>'
            + '<div class="profile-card-header">'
            + '<span class="avatar-wrap' + onlineClass + '">'
            + (u.user_avatar
                ? '<img class="profile-card-avatar" src="' + Community.esc(u.user_avatar) + '" onerror="this.outerHTML=\'<span class=&quot;profile-card-avatar-circle&quot;>' + Community.esc(Community.initials(u.user_name_display)) + '</span>\'">'
                : '<span class="profile-card-avatar-circle">' + Community.esc(Community.initials(u.user_name_display)) + '</span>')
            + '</span>'
            + '<div><div class="profile-card-name">' + Community.esc(u.user_name_display) + '</div>'
            + (u.user_handle ? '<div class="profile-card-handle">@' + Community.esc(u.user_handle) + '</div>' : '')
            + '</div></div>'
            + (u.user_bio ? '<div class="profile-card-bio">' + Community.esc(u.user_bio) + '</div>' : '')
            + '<div class="profile-card-stats">'
            + '<span>Topics: <strong>' + (u.topic_count || 0) + '</strong></span>'
            + '<span>Replies: <strong>' + (u.reply_count || 0) + '</strong></span>'
            + '<span>Rep: <strong>' + (u.user_reputation || 0) + '</strong></span>'
            + '</div>'
            + '<div class="profile-card-meta">'
            + 'Joined ' + (u.user_created_dtime ? new Date(u.user_created_dtime).toLocaleDateString() : 'N/A')
            + (u.user_last_active_dtime ? ' &middot; Active ' + Community.timeAgo(u.user_last_active_dtime) : '')
            + '</div>'
            + (Community.currentUser && Community.currentUser.roles && (Community.currentUser.roles.indexOf('admin') >= 0 || Community.currentUser.roles.indexOf('moderator') >= 0) && u.user_email
                ? '<div class="profile-card-meta" style="color:#999;">' + Community.esc(u.user_email) + '</div>' : '')
            + (Community.currentUser && Community.currentUser.user_key !== userKey
                ? '<div class="profile-card-dm">'
                + '<textarea id="profile-dm-body" placeholder="Send a message..." rows="2" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:0.85rem;resize:vertical;margin-bottom:8px;"></textarea>'
                + '<button class="btn btn-primary btn-sm" style="width:100%;" onclick="Community.sendProfileDM(' + userKey + ')">Send Message</button>'
                + '</div>'
                : '')
            + '</div>';

        document.body.appendChild(card);

        // Position near the click
        var rect = event.target.getBoundingClientRect();
        card.style.top = (rect.bottom + window.scrollY + 8) + 'px';
        card.style.left = Math.max(10, Math.min(rect.left, window.innerWidth - 300)) + 'px';

        // Close on outside click
        setTimeout(function() {
            document.addEventListener('click', function handler(e) {
                if (!card.contains(e.target)) {
                    card.remove();
                    document.removeEventListener('click', handler);
                }
            });
        }, 10);
    });
};

// ── Send DM from profile card ──
Community.sendProfileDM = function(recipientKey) {
    var body = document.getElementById('profile-dm-body');
    if (!body || !body.value.trim()) { alert('Please enter a message'); return; }
    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'new_thread', recipient_key: recipientKey, body: body.value.trim() }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        body.value = '';
        body.placeholder = 'Message sent!';
        body.disabled = true;
        var btn = body.nextElementSibling;
        if (btn) { btn.textContent = 'Sent!'; btn.disabled = true; }
        setTimeout(function() {
            var popup = document.querySelector('.profile-card-popup');
            if (popup) popup.remove();
        }, 1500);
    });
};

// ── Load categories for sidebar ──
Community.currentSection = 'topics'; // 'topics' or 'comments'

Community.setSection = function(section) {
    Community.currentSection = section;
    Community.currentCategory = '';
    Community.loadCategories(section === 'comments' ? 'comments' : 'topics');
};

Community.loadCategories = function(parent) {
    parent = parent || Community.currentSection || 'topics';
    var url = '/api/community-categories.php?parent=' + encodeURIComponent(parent);
    Community.api(url).then(function(data) {
        Community.categories = data.categories || [];
        Community.renderCategorySidebar();
    }).catch(function(err) {
        console.error('loadCategories error:', err);
    });
};

Community.renderCategorySidebar = function() {
    var el = document.getElementById('category-sidebar');
    if (!el) return;
    var cats = Community.categories;
    var isMod = Community.currentUser && (Community.currentUser.roles.indexOf('admin') >= 0 || Community.currentUser.roles.indexOf('moderator') >= 0);
    var html = '<div class="category-bar">';
    if (isMod) {
        html += '<a class="category-bar-label" href="#categories" style="cursor:pointer;text-decoration:none;">Categories</a>';
    } else {
        html += '<span class="category-bar-label">Categories</span>';
    }
    html += '<div class="category-item' + (!Community.currentCategory ? ' active' : '') + '" onclick="Community.filterCategory(\'\')">All</div>';
    for (var i = 0; i < cats.length; i++) {
        var c = cats[i];
        var isActive = Community.currentCategory === c.category_slug;
        html += '<div class="category-item' + (isActive ? ' active' : '') + '" onclick="Community.filterCategory(\'' + Community.esc(c.category_slug) + '\')">'
            + '<span class="category-badge" style="background:' + Community.esc(c.category_color || '#31345A') + '"></span>'
            + Community.esc(c.category_name)
            + '</div>';
    }
    html += '</div>';
    el.innerHTML = html;
};

// ── Category Manager (moderators/admins) ──
Community.showCategoryManager = function() {
    Community.showView('view-categories');
    var el = document.getElementById('view-categories');
    if (!el) return;
    el.innerHTML = '<div class="empty-state">Loading...</div>';

    Community.api('/api/community-categories.php?all=1').then(function(data) {
        var cats = data.categories || [];
        var html = '<a href="#topics" class="back-link" onclick="window.location.hash=\'#topics\';return false;">&larr; Back to topics</a>';
        html += '<div class="section-header"><h2 class="section-title">Manage Categories</h2>'
            + '<button class="btn btn-primary btn-sm" onclick="Community.showCategoryForm(null)">+ Add Category</button></div>';
        html += '<div class="topic-list">';
        for (var i = 0; i < cats.length; i++) {
            var c = cats[i];
            var inactive = c.category_active_flag === false || c.category_active_flag === 'f';
            html += '<div class="topic-item" style="' + (inactive ? 'opacity:0.5;' : '') + 'align-items:center;">'
                + '<div style="display:flex;flex-direction:column;gap:2px;flex-shrink:0;margin-right:4px;">'
                + (i > 0 ? '<button class="sort-arrow" onclick="event.stopPropagation();Community.moveCategoryUp(' + c.category_key + ',' + i + ')" title="Move up">&#9650;</button>' : '<span class="sort-arrow" style="visibility:hidden;">&#9650;</span>')
                + (i < cats.length - 1 ? '<button class="sort-arrow" onclick="event.stopPropagation();Community.moveCategoryDown(' + c.category_key + ',' + i + ')" title="Move down">&#9660;</button>' : '<span class="sort-arrow" style="visibility:hidden;">&#9660;</span>')
                + '</div>'
                + '<span class="category-badge" style="background:' + Community.esc(c.category_color || '#31345A') + ';width:12px;height:12px;border-radius:50%;display:inline-block;flex-shrink:0;"></span>'
                + '<div class="topic-info">'
                + '<div class="topic-title">' + Community.esc(c.category_name) + (inactive ? ' <em style="color:#999;font-weight:400">(inactive)</em>' : '') + '</div>'
                + '<div class="topic-meta">' + Community.esc(c.category_description || '') + ' &middot; ' + (c.topic_count || 0) + ' topics</div>'
                + '</div>'
                + '<div style="display:flex;gap:10px;align-items:center;">'
                + '<label style="display:flex;align-items:center;gap:4px;font-size:0.8rem;color:#555;cursor:pointer;white-space:nowrap;">'
                + '<input type="checkbox"' + (inactive ? '' : ' checked') + ' onchange="event.stopPropagation();Community.toggleCategory(' + c.category_key + ',this.checked)"> Active</label>'
                + '<button class="btn btn-sm btn-outline" onclick="event.stopPropagation();Community.showCategoryForm(' + c.category_key + ')">Edit</button>'
                + '</div></div>';
        }
        html += '</div>';
        el.innerHTML = html;
    });
};

Community._catData = {};
Community.showCategoryForm = function(catKey) {
    var existing = document.getElementById('cat-form-modal');
    if (existing) existing.remove();

    if (catKey) {
        // Find in current categories
        Community.api('/api/community-categories.php?all=1').then(function(data) {
            var cat = (data.categories || []).find(function(c) { return c.category_key == catKey; });
            Community._renderCatForm(cat || {});
        });
    } else {
        Community._renderCatForm({});
    }
};

Community._renderCatForm = function(cat) {
    var esc = Community.esc;
    var isEdit = !!cat.category_key;
    var modal = document.createElement('div');
    modal.id = 'cat-form-modal';
    modal.className = 'login-modal active';
    modal.onclick = function(e) { if (e.target === modal) modal.remove(); };
    modal.innerHTML = '<div class="login-box" style="max-width:420px;">'
        + '<button class="login-close" onclick="document.getElementById(\'cat-form-modal\').remove()">&times;</button>'
        + '<h2>' + (isEdit ? 'Edit Category' : 'New Category') + '</h2>'
        + '<div id="cat-form-msg"></div>'
        + '<input id="cat-f-name" placeholder="Category name" value="' + esc(cat.category_name || '') + '" style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:0.9rem;box-sizing:border-box;margin-bottom:12px;">'
        + '<input id="cat-f-desc" placeholder="Description (optional)" value="' + esc(cat.category_description || '') + '" style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:0.9rem;box-sizing:border-box;margin-bottom:12px;">'
        + '<div style="display:flex;gap:12px;margin-bottom:12px;">'
        + '<div style="flex:1;"><label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:4px;">Color</label>'
        + '<input id="cat-f-color" type="color" value="' + esc(cat.category_color || '#31345A') + '" style="width:100%;height:36px;border:1px solid #ddd;border-radius:4px;cursor:pointer;"></div>'
        + '<div style="flex:1;"><label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:4px;">Sort Order</label>'
        + '<input id="cat-f-sort" type="number" value="' + (cat.category_sort || 50) + '" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-family:inherit;box-sizing:border-box;"></div>'
        + '</div>'
        + '<button class="btn btn-primary" style="width:100%;" onclick="Community.saveCategoryForm(' + (cat.category_key || 0) + ')">' + (isEdit ? 'Save Changes' : 'Create Category') + '</button>'
        + '</div>';
    document.body.appendChild(modal);
};

Community.saveCategoryForm = function(catKey) {
    var name = document.getElementById('cat-f-name').value.trim();
    if (!name) { alert('Category name is required'); return; }
    var data = {
        category_name: name,
        category_description: document.getElementById('cat-f-desc').value.trim(),
        category_color: document.getElementById('cat-f-color').value,
        category_sort: parseInt(document.getElementById('cat-f-sort').value) || 50,
    };

    var url = '/api/community-categories.php' + (catKey ? '?key=' + catKey : '');
    Community.api(url, {
        method: catKey ? 'PUT' : 'POST',
        body: data
    }).then(function(res) {
        if (res.error) { alert(res.error); return; }
        var modal = document.getElementById('cat-form-modal');
        if (modal) modal.remove();
        Community.loadCategories();
        Community.showCategoryManager();
    });
};

Community.moveCategoryUp = function(catKey, index) {
    Community._swapCategorySort(index, index - 1);
};

Community.moveCategoryDown = function(catKey, index) {
    Community._swapCategorySort(index, index + 1);
};

Community._swapCategorySort = function(indexA, indexB) {
    Community.api('/api/community-categories.php?all=1').then(function(data) {
        var cats = data.categories || [];
        if (indexA < 0 || indexB < 0 || indexA >= cats.length || indexB >= cats.length) return;
        var a = cats[indexA], b = cats[indexB];
        var sortA = a.category_sort, sortB = b.category_sort;
        // If same sort value, offset them
        if (sortA === sortB) { sortB = parseInt(sortA) + 1; }
        // Swap
        Promise.all([
            Community.api('/api/community-categories.php?key=' + a.category_key, { method: 'PUT', body: { category_sort: parseInt(sortB) } }),
            Community.api('/api/community-categories.php?key=' + b.category_key, { method: 'PUT', body: { category_sort: parseInt(sortA) } })
        ]).then(function() {
            Community.loadCategories();
            Community.showCategoryManager();
        });
    });
};

Community.toggleCategory = function(catKey, activate) {
    Community.api('/api/community-categories.php?key=' + catKey, {
        method: 'PUT',
        body: { category_active_flag: activate }
    }).then(function(res) {
        if (res.error) { alert(res.error); return; }
        Community.loadCategories();
    });
};

Community._skipRoute = false;
Community.filterCategory = function(slug) {
    if (slug && slug === Community.currentCategory) return;
    Community.currentCategory = slug;
    Community.currentPage = 1;
    Community.renderCategorySidebar();
    var opts = {};
    if (Community.currentSection === 'comments') {
        opts.parentSlug = 'comments';
    }
    CommunityTopics.loadTopics(1, slug, opts);
    var newHash;
    if (Community.currentSection === 'comments') {
        newHash = '#comments';
    } else {
        newHash = slug ? '#category/' + slug : '#topics';
    }
    // Only set _skipRoute if the hash will actually change (otherwise hashchange won't fire and the flag will linger)
    if (window.location.hash !== newHash) {
        Community._skipRoute = true;
        window.location.hash = newHash;
    }
};

// ── Category badge HTML ──
Community.categoryBadge = function(slug) {
    if (!slug) return '';
    for (var i = 0; i < Community.categories.length; i++) {
        var c = Community.categories[i];
        if (c.category_slug === slug) {
            return '<a href="#category/' + Community.esc(slug) + '" onclick="event.stopPropagation();" class="topic-category-badge" style="background:' + Community.esc(c.category_color || '#31345A') + ';text-decoration:none;color:#fff;cursor:pointer;">' + Community.esc(c.category_name) + '</a>';
        }
    }
    return '';
};

// ── Posting privilege ──
// A banned user stays fully logged in and tracked, but cannot create or edit
// any messages (topics, replies, DMs). All compose/edit UI and actions gate on
// this. Banning is silent — no UI tells the user they are banned.
Community.canPost = function() {
    return !!(Community.currentUser && !Community.currentUser.banned);
};

// ── Init ──
Community.init = function() {
    // Load session
    Community.api('/api/community-session.php').then(function(data) {
        Community.currentUser = data.user;
        CommunityAuth.renderAuth();
        CommunityAuth.renderVerifyBanner();
        if (data.user) {
            CommunityNotifications.startPolling();
            CommunityDM.initPopover();
            // Apply saved font size preference
            if (data.user.user_font_size && window.applyUserFontSize) {
                applyUserFontSize(data.user.user_font_size);
            }
            // Show moderator toolbar for admin/moderator users
            var roles = data.user.roles || [];
            if (roles.indexOf('admin') >= 0 || roles.indexOf('moderator') >= 0) {
                var modBar = document.getElementById('community-mod-bar');
                if (modBar) modBar.style.display = '';
            }
        }
        Community.route();
    }).catch(function() {
        Community.route();
    });

    // Listen for hash changes
    window.addEventListener('hashchange', Community.route);

    // Close profile cards on escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var card = document.querySelector('.profile-card-popup');
            if (card) card.remove();
            CommunityAuth.closeLoginModal();
        }
    });
};

// Boot
document.addEventListener('DOMContentLoaded', Community.init);

})();
