/* ── community-dm.js ── Direct messages: inbox, thread, compose, popover ── */
(function() {
'use strict';

window.CommunityDM = {};

var _activeThreadKey = null;
var _audioCtx = null;
// message_key → raw plain-text body, populated during render. The edit UI
// reads from this instead of round-tripping through a `data-body` HTML
// attribute, which silently truncates / re-encodes long or unusual content.
var _msgBodies = {};

// ── Pitch-shifted notification chime ──
// pitch 0-7 maps to different frequency pairs, giving each thread a unique sound
var CHIME_PITCHES = [
    { f1: 830,  f2: 1100 },  // 0: default (original)
    { f1: 740,  f2: 988  },  // 1: slightly lower
    { f1: 988,  f2: 1318 },  // 2: higher
    { f1: 660,  f2: 880  },  // 3: warm low
    { f1: 1047, f2: 1397 },  // 4: bright high
    { f1: 784,  f2: 1047 },  // 5: mid-warm
    { f1: 880,  f2: 1175 },  // 6: mid-bright
    { f1: 698,  f2: 932  },  // 7: soft low
];

// Map thread_key → chime_pitch (populated from API)
var _threadChimePitches = {};

window.playChime = playChime;
function playChime(pitch) {
    try {
        if (!_audioCtx) _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        var ctx = _audioCtx;
        var now = ctx.currentTime;
        var p = CHIME_PITCHES[pitch] || CHIME_PITCHES[0];

        var gain = ctx.createGain();
        gain.connect(ctx.destination);
        gain.gain.setValueAtTime(0.15, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.51);

        // First tone
        var osc1 = ctx.createOscillator();
        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(p.f1, now);
        osc1.connect(gain);
        osc1.start(now);
        osc1.stop(now + 0.127);

        // Second tone (higher)
        var osc2 = ctx.createOscillator();
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(p.f2, now + 0.127);
        osc2.connect(gain);
        osc2.start(now + 0.127);
        osc2.stop(now + 0.34);
    } catch(e) {}
}

// ── Media upload helper (shared across all compose areas) ──
function uploadMedia(file, callback) {
    if (!file) return;
    var isImage = file.type.startsWith('image/');
    var isVideo = file.type.startsWith('video/');
    if (!isImage && !isVideo) { alert('Only images and videos are supported'); return; }
    if (file.size > 20 * 1024 * 1024) { alert('File too large (max 20MB)'); return; }

    if (isImage) {
        var fd = new FormData();
        fd.append('image', file);
        fetch('/api/community-image-upload.php', { method: 'POST', credentials: 'include', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.url) callback('![image](' + data.url + ')');
                else alert(data.error || 'Upload failed');
            });
    } else {
        // Video: upload as image endpoint (backend will need to handle, or use same path)
        var fd = new FormData();
        fd.append('video', file);
        fetch('/api/community-image-upload.php', { method: 'POST', credentials: 'include', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.url) callback('[video](' + data.url + ')');
                else alert(data.error || 'Upload failed');
            });
    }
}

// Wire up a compose area with media buttons, paste, and drag-drop
function wireCompose(textareaId, sendFn) {
    var ta = document.getElementById(textareaId);
    if (!ta) return;

    // Enter to send (Shift+Enter for newline)
    ta.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendFn();
        }
    });

    // Paste images/videos
    ta.addEventListener('paste', function(e) {
        var items = (e.clipboardData || {}).items || [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') === 0) {
                e.preventDefault();
                uploadMedia(items[i].getAsFile(), function(md) { insertAtCursor(ta, md); });
                return;
            }
        }
    });

    // Drag and drop
    ta.addEventListener('dragover', function(e) { e.preventDefault(); ta.style.borderColor = '#31345A'; });
    ta.addEventListener('dragleave', function() { ta.style.borderColor = ''; });
    ta.addEventListener('drop', function(e) {
        e.preventDefault();
        ta.style.borderColor = '';
        var files = e.dataTransfer.files;
        for (var i = 0; i < files.length; i++) {
            uploadMedia(files[i], function(md) { insertAtCursor(ta, md); });
        }
    });

    // Media button click
    var btn = document.getElementById(textareaId + '-media-btn');
    var fileInput = document.getElementById(textareaId + '-media-file');
    if (btn && fileInput) {
        btn.addEventListener('click', function() { fileInput.click(); });
        fileInput.addEventListener('change', function() {
            var files = this.files;
            for (var i = 0; i < files.length; i++) {
                uploadMedia(files[i], function(md) { insertAtCursor(ta, md); });
            }
            this.value = '';
        });
    }

    ta.focus();
}

function insertAtCursor(ta, text) {
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    var before = ta.value.substring(0, start);
    var after = ta.value.substring(end);
    // Add newline before if needed
    if (before && !before.endsWith('\n')) before += '\n';
    ta.value = before + text + '\n' + after;
    ta.selectionStart = ta.selectionEnd = before.length + text.length + 1;
    ta.focus();
}

// Build compose HTML with TinyMCE editor
function composeHtml(textareaId, placeholder, rows, sendLabel, sendOnclick) {
    return '<div class="dm-compose">'
        + '<div class="dm-compose-row" id="' + textareaId + '-container">'
        + '<textarea id="' + textareaId + '" rows="' + (rows || 2) + '" style="width:100%;box-sizing:border-box;"></textarea>'
        + '</div>'
        + '<div class="dm-compose-actions">'
        + '<button class="btn btn-primary" onclick="' + sendOnclick + '">' + sendLabel + '</button>'
        + '</div></div>';
}

function initDmEditor(textareaId, threadKey) {
    if (typeof tinymce === 'undefined') {
        if (window.ensureTinyMCE) {
            ensureTinyMCE(function() { _initDmTinyMCE(textareaId, threadKey); });
        }
        return;
    }
    _initDmTinyMCE(textareaId, threadKey);
}

function _dmUpload(fd, threadKey) {
    if (threadKey) fd.append('thread_key', threadKey);
    return fetch('/api/dm-upload.php', {
        method: 'POST', credentials: 'include', body: fd
    }).then(function(r) { return r.json(); });
}

function _initDmTinyMCE(textareaId, threadKey) {
    try {
    var existing = tinymce.get(textareaId);
    if (existing) existing.remove();

    tinymce.init({
        selector: '#' + textareaId,
        base_url: '/js/tinymce',
        plugins: 'autolink link image media lists advlist codesample emoticons fullscreen table autoresize charmap',
        toolbar: 'bold italic underline strikethrough | blocks | bullist numlist | blockquote codesample | link uploadimage media emoticons charmap | table | fullscreen',
        menubar: false,
        statusbar: true,
        branding: false,
        promotion: false,
        min_height: 220,
        max_height: 600,
        autoresize_bottom_margin: 10,
        skin: 'oxide',
        content_css: 'default',
        body_class: 'community-editor-body',
        content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 0.95rem; line-height: 1.6; color: #333; padding: 8px; } img { max-width: 100%; height: auto; border-radius: 6px; } a { color: #31345A; } blockquote { border-left: 3px solid #E5C86C; margin: 8px 0; padding: 8px 16px; background: #fafafa; } pre { background: #f4f4f4; padding: 12px; border-radius: 6px; overflow-x: auto; } table { border-collapse: collapse; width: 100%; } td, th { border: 1px solid #ddd; padding: 8px; }',

        images_upload_handler: function(blobInfo) {
            return new Promise(function(resolve, reject) {
                var fd = new FormData();
                fd.append('image', blobInfo.blob(), blobInfo.filename());
                _dmUpload(fd, threadKey).then(function(data) {
                    if (data.url) resolve(data.url);
                    else reject(data.error || 'Upload failed');
                }).catch(function() { reject('Upload failed'); });
            });
        },
        automatic_uploads: true,
        file_picker_types: 'image media file',
        images_reuse_filename: true,

        file_picker_callback: function(callback, value, meta) {
            var input = document.createElement('input');
            input.type = 'file';
            if (meta.filetype === 'image') {
                input.accept = 'image/jpeg,image/png,image/gif,image/webp';
            } else if (meta.filetype === 'media') {
                input.accept = 'video/mp4,video/webm,video/quicktime,video/ogg,.mp4,.webm,.mov,.ogg,.avi,.mkv';
            } else {
                input.accept = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.rtf,.csv,.zip';
            }
            input.onchange = function() {
                var file = input.files[0];
                if (!file) return;
                var fd = new FormData();
                if (meta.filetype === 'image') {
                    fd.append('image', file, file.name);
                    _dmUpload(fd, threadKey).then(function(data) {
                        if (data.url) callback(data.url, { alt: file.name });
                        else alert(data.error || 'Upload failed');
                    }).catch(function() { alert('Upload failed'); });
                } else if (meta.filetype === 'media') {
                    fd.append('video', file, file.name);
                    _dmUpload(fd, threadKey).then(function(data) {
                        if (data.url) callback(data.url, { source2: '', poster: '' });
                        else alert(data.error || 'Upload failed');
                    }).catch(function() { alert('Upload failed'); });
                } else {
                    fd.append('file', file, file.name);
                    _dmUpload(fd, threadKey).then(function(data) {
                        if (data.url) {
                            var absUrl = window.location.origin + data.url;
                            callback(absUrl, { text: data.name || file.name, title: file.name });
                        }
                        else alert(data.error || 'Upload failed');
                    }).catch(function() { alert('Upload failed'); });
                }
            };
            input.click();
        },

        relative_urls: false,
        remove_script_host: true,
        convert_urls: false,
        media_live_embeds: true,
        media_alt_source: false,
        media_poster: false,
        link_default_target: '_blank',
        link_assume_external_targets: true,
        paste_as_text: false,
        paste_block_drop: false,
        block_formats: 'Paragraph=p; Heading 3=h3; Heading 4=h4; Blockquote=blockquote; Code=pre',
        image_title: false,
        image_description: false,

        setup: function(editor) {
            CommunityDM._editor = editor;

            editor.ui.registry.addButton('uploadimage', {
                icon: 'image',
                tooltip: 'Upload Image',
                onAction: function() {
                    var input = document.createElement('input');
                    input.type = 'file';
                    input.accept = 'image/jpeg,image/png,image/gif,image/webp';
                    input.onchange = function() {
                        var file = input.files[0];
                        if (!file) return;
                        var fd = new FormData();
                        fd.append('image', file, file.name);
                        _dmUpload(fd, threadKey).then(function(data) {
                            if (data.url) editor.insertContent('<img src="' + data.url + '" alt="' + file.name.replace(/"/g, '') + '" style="max-width:100%;">');
                            else alert(data.error || 'Upload failed');
                        }).catch(function() { alert('Upload failed'); });
                    };
                    input.click();
                }
            });

            editor.on('init', function() {
                var iframeWrap = editor.getContainer().querySelector('.tox-editor-container .tox-sidebar-wrap');
                if (!iframeWrap) iframeWrap = editor.getContainer();
                var ph = document.createElement('div');
                ph.className = 'mce-placeholder-overlay';
                ph.textContent = 'Type a message...';
                ph.style.cssText = 'position:absolute;top:1px;left:12px;color:#aaa;font-size:0.95rem;pointer-events:none;z-index:1;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;';
                iframeWrap.style.position = 'relative';
                iframeWrap.appendChild(ph);

                function isEmpty() {
                    var content = editor.getContent({ format: 'text' }).trim();
                    var html = editor.getContent();
                    return !content && html.indexOf('<img') < 0 && html.indexOf('<video') < 0;
                }

                editor.on('focus', function() { ph.style.display = 'none'; });
                editor.on('blur', function() { if (isEmpty()) ph.style.display = ''; });
                if (isEmpty()) ph.style.display = '';
                else ph.style.display = 'none';
            });
        }
    });
    } catch(e) { console.warn('TinyMCE init failed, using plain textarea:', e); }
}

CommunityDM._editor = null;

// ── Called by SSE when a new DM arrives ──
CommunityDM.onNewMessage = function(msg) {
    if (!msg || !msg.thread_key) return;
    if (_activeThreadKey == msg.thread_key) {
        var container = document.getElementById('dm-messages');
        if (!container) return;
        var div = document.createElement('div');
        var isMine = Community.currentUser && msg.user_key === Community.currentUser.user_key;
        div.className = 'dm-message' + (isMine ? ' mine' : '');
        var senderHtml = '';
        if (msg.user_name_display && !isMine) {
            senderHtml = '<div class="dm-sender-name">' + Community.esc(msg.user_name_display) + '</div>';
        }
        div.innerHTML = senderHtml
            + '<div class="dm-message-bubble">' + (msg.message_body_html || Community.formatBody(msg.message_body)) + '</div>'
            + '<div class="dm-message-time">' + Community.timeAgo(msg.message_dtime) + '</div>';
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
        CommunityNotifications.clearDmCount();
        Community.api('/api/community-dm.php?thread=' + msg.thread_key);
    }
    var inboxEl = document.getElementById('view-messages');
    if (inboxEl && inboxEl.style.display !== 'none' && !_activeThreadKey) {
        CommunityDM.loadInbox();
    }
};

// ── Update DM badge in nav ──
CommunityDM.updateUnreadBadge = function(count) {
    var nav = document.getElementById('nav-messages');
    if (!nav) return;
    nav.innerHTML = nav.textContent.replace(/ \(\d+\)$/, '');
    if (count > 0) {
        nav.innerHTML = 'Messages <span class="dm-unread-badge">' + count + '</span>';
    } else {
        nav.textContent = 'Messages';
    }
};

// ── Load inbox ──
CommunityDM.loadInbox = function() {
    _activeThreadKey = null;
    Community.showView('view-messages');
    var el = document.getElementById('view-messages');
    if (!Community.currentUser) {
        el.innerHTML = '<div class="empty-state"><a href="#" onclick="CommunityAuth.openLoginModal(\'login\');return false;">Sign in</a> to see your messages.</div>';
        return;
    }
    el.innerHTML = '<div class="empty-state">Loading...</div>';

    Community.api('/api/community-dm.php?threads=1').then(function(data) {
        var threads = data.threads || [];
        // Store chime pitch mapping for each thread
        threads.forEach(function(t) { if (t.chime_pitch != null) _threadChimePitches[t.thread_key] = parseInt(t.chime_pitch); });
        var html = '<div class="section-header">'
            + '<h2 class="section-title">&#128172; Messages</h2>'
            + '<div style="display:flex;gap:8px;">'
            + '<button class="btn btn-primary btn-sm" onclick="CommunityDM.showCompose()">New Message</button>'
            + '<button class="btn btn-sm" style="background:#4a4e7a;color:#fff;" onclick="CommunityDM.showComposeGroup()">New Group</button>'
            + '</div></div>';

        if (!threads.length) {
            html += '<div class="empty-state">No messages yet</div>';
        } else {
            html += '<div class="dm-thread-list">';
            threads.forEach(function(t) {
                var unread = t.unread_count > 0;
                var otherUser = t.other_user || {};
                var avatarHtml = t.thread_is_group
                    ? '<div class="dm-group-icon">&#128101;</div>'
                    : Community.clickableAvatar(otherUser.user_key, otherUser.user_avatar, otherUser.user_name_display, 'dm-avatar', otherUser.user_last_active_dtime);
                var displayName = t.thread_is_group
                    ? (t.thread_name || otherUser.user_name_display || 'Group')
                    : (otherUser.user_name_display || 'Unknown');
                var chimePitch = t.chime_pitch != null ? parseInt(t.chime_pitch) : 0;
                html += '<div class="dm-thread-item' + (unread ? ' unread' : '') + '" onclick="window.location.hash=\'#message/' + t.thread_key + '\'">'
                    + avatarHtml
                    + '<div class="dm-thread-info">'
                    + '<div class="dm-thread-name">' + Community.esc(displayName) + (unread ? ' <span class="dm-unread-badge">' + t.unread_count + '</span>' : '')
                    + ' <span class="chime-btn" onclick="event.stopPropagation();CommunityDM.openChimeEditor(' + t.thread_key + ')" title="Set notification chime">&#9835;</span>'
                    + '</div>'
                    + '<div class="dm-thread-preview">' + Community.esc(t.last_message || '') + '</div>'
                    + '</div>'
                    + '<div class="dm-thread-time">' + Community.timeAgo(t.last_message_dtime) + '</div>'
                    + '</div>';
            });
            html += '</div>';
        }
        el.innerHTML = html;
    });
};

// ── Load thread ──
CommunityDM.loadThread = function(threadKey) {
    _activeThreadKey = threadKey;
    Community.showView('view-messages');
    var el = document.getElementById('view-messages');
    if (!Community.currentUser) {
        el.innerHTML = '<div class="empty-state">Sign in to view messages.</div>';
        return;
    }
    el.innerHTML = '<div class="empty-state">Loading...</div>';

    CommunityNotifications.clearDmCount();

    Community.api('/api/community-dm.php?thread=' + threadKey).then(function(data) {
        var messages = data.messages || [];
        var thread = data.thread || {};
        var otherUser = thread.other_user || {};
        var isGroup = thread.thread_is_group;
        var members = thread.members || [];

        var html = '<a href="#messages" class="back-link">&larr; Back to inbox</a>';

        if (isGroup) {
            var groupName = thread.thread_name || 'Group';
            var isCreator = thread.thread_creator_key === Community.currentUser.user_key;
            html += '<div class="dm-thread-header dm-group-header">'
                + '<div class="dm-group-icon" style="font-size:1.5rem;">&#128101;</div>'
                + '<div style="flex:1;">'
                + '<span class="dm-header-name">' + Community.esc(groupName) + '</span>'
                + '<div class="dm-group-member-count" style="font-size:0.8rem;color:#888;">' + members.length + ' members</div>'
                + '</div>'
                + '<button class="btn btn-sm" style="background:none;border:1px solid #ddd;font-size:1rem;padding:4px 10px;" onclick="CommunityDM.toggleGroupPanel(' + threadKey + ')" title="Group settings">&#9881;</button>'
                + '</div>';
            html += '<div id="dm-group-panel" class="dm-group-panel" style="display:none;">';
            html += '<div style="margin-bottom:10px;">'
                + '<label style="font-size:0.8rem;color:#888;">Group Name</label>'
                + '<div style="display:flex;gap:6px;">'
                + '<input id="dm-group-name-input" value="' + Community.esc(groupName) + '" style="flex:1;padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:0.85rem;">'
                + '<button class="btn btn-primary btn-sm" onclick="CommunityDM.renameGroup(' + threadKey + ')">Save</button>'
                + '</div></div>';
            html += '<div style="margin-bottom:8px;font-size:0.85rem;font-weight:600;color:#31345A;">Members</div>';
            html += '<div class="dm-group-members">';
            members.forEach(function(m) {
                var canRemove = m.user_key !== thread.thread_creator_key && m.user_key !== Community.currentUser.user_key;
                html += '<div class="dm-group-member-item">'
                    + Community.clickableAvatar(m.user_key, m.user_avatar, m.user_name_display, 'dm-avatar-sm', m.user_last_active_dtime)
                    + '<span style="flex:1;font-size:0.85rem;">' + Community.esc(m.user_name_display)
                    + (m.user_key === thread.thread_creator_key ? ' <span style="color:#888;font-size:0.75rem;">(creator)</span>' : '') + '</span>';
                if (canRemove) {
                    html += '<button class="btn btn-sm" style="background:none;color:#c0392b;border:1px solid #ddd;font-size:0.75rem;padding:2px 8px;" onclick="CommunityDM.removeMember(' + threadKey + ',' + m.user_key + ')">Remove</button>';
                }
                html += '</div>';
            });
            html += '</div>';
            html += '<div style="margin-top:10px;display:flex;gap:8px;">'
                + '<div class="dm-recipient-search" style="flex:1;">'
                + '<input id="dm-add-member-input" placeholder="Add a member..." autocomplete="off" style="width:100%;padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:0.85rem;box-sizing:border-box;">'
                + '<div id="dm-add-member-results" class="mention-dropdown" style="display:none"></div>'
                + '</div></div>';
            html += '<div style="margin-top:12px;border-top:1px solid #eee;padding-top:10px;">'
                + '<button class="btn btn-sm" style="background:none;color:#c0392b;border:1px solid #ddd;" onclick="CommunityDM.leaveGroup(' + threadKey + ')">Leave Group</button>'
                + '</div>';
            html += '</div>';
        } else {
            html += '<div class="dm-thread-header">'
                + Community.clickableAvatar(otherUser.user_key, otherUser.user_avatar, otherUser.user_name_display, 'dm-header-avatar', otherUser.user_last_active_dtime)
                + '<span class="dm-header-name">' + Community.esc(otherUser.user_name_display || 'Unknown') + '</span>'
                + '</div>';
        }

        html += '<div class="dm-messages" id="dm-messages">';
        messages.forEach(function(m) {
            var isMine = m.user_key === Community.currentUser.user_key;
            var isSystem = m.user_key === 0 || m.user_key === null;
            if (isSystem) {
                html += '<div class="dm-message dm-system-message">'
                    + '<div class="dm-system-text">' + Community.esc(m.message_body) + '</div>'
                    + '</div>';
            } else {
                if (isMine) _msgBodies[m.message_key] = m.message_body || '';
                var senderLabel = isGroup && !isMine ? '<div class="dm-sender-name">' + Community.esc(m.user_name_display || '') + '</div>' : '';
                var editedTag = ((m.message_revision_num || 1) > 1) ? ' <span class="dm-edited-tag" title="' + Community.esc(m.message_revision_dtime || '') + '">(edited)</span>' : '';
                var editBtn = isMine ? '<button type="button" class="dm-edit-btn" onclick="CommunityDM.beginEdit(' + threadKey + ',' + m.message_key + ')" title="Edit message">&#9998;</button>' : '';
                html += '<div class="dm-message' + (isMine ? ' mine' : '') + '" id="dm-msg-' + m.message_key + '">'
                    + senderLabel
                    + '<div class="dm-message-bubble">' + (m.message_body_html || Community.formatBody(m.message_body)) + '</div>'
                    + '<div class="dm-message-time">' + Community.timeAgo(m.message_dtime) + editedTag + editBtn + '</div>'
                    + '</div>';
            }
        });
        html += '</div>';

        html += composeHtml('dm-reply-body', 'Type a message...', 2, 'Send', 'CommunityDM.sendMessage(' + threadKey + ')');
        el.innerHTML = html;

        var msgContainer = document.getElementById('dm-messages');
        function scrollToThread() {
            // Scroll so the back link is at the top of the viewport
            var backLink = el.querySelector('.back-link');
            if (backLink) {
                var y = backLink.getBoundingClientRect().top + window.scrollY - 60;
                window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
            }
            // Scroll messages to bottom
            if (msgContainer) msgContainer.scrollTop = msgContainer.scrollHeight;
        }
        scrollToThread();
        // Re-scroll after images/media load
        var imgs = msgContainer ? msgContainer.querySelectorAll('img, video, iframe') : [];
        imgs.forEach(function(el) { el.addEventListener('load', function() { if (msgContainer) msgContainer.scrollTop = msgContainer.scrollHeight; }); });
        // Re-scroll after TinyMCE loads
        setTimeout(scrollToThread, 500);
        setTimeout(scrollToThread, 1200);

        wireCompose('dm-reply-body', function() { CommunityDM.sendMessage(threadKey); });
        initDmEditor('dm-reply-body', threadKey);
    });
};

// ── Send message ──
CommunityDM.sendMessage = function(threadKey) {
    var body = '';
    var bodyHtml = '';
    if (CommunityDM._editor) {
        var plainText = CommunityDM._editor.getContent({ format: 'text' }).trim();
        bodyHtml = CommunityDM._editor.getContent();
        body = plainText || '(media)';
        if (!plainText && bodyHtml.indexOf('<img') < 0) return;
        CommunityDM._editor.setContent('');
    } else {
        body = document.getElementById('dm-reply-body').value.trim();
        if (!body) return;
        document.getElementById('dm-reply-body').value = '';
    }
    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'send', thread_key: threadKey, body: body, message_body_html: bodyHtml }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        CommunityDM.loadThread(threadKey);
    });
};

// ── Edit own message: swap bubble for inline textarea ──
// `isPop` indicates the popover view (different element id prefix and a
// non-reload save path so we don't blow away the popover state).
CommunityDM.beginEdit = function(threadKey, messageKey, isPop) {
    var idPrefix = isPop ? 'dm-pop-msg-' : 'dm-msg-';
    var wrap = document.getElementById(idPrefix + messageKey);
    if (!wrap) return;
    if (wrap.classList.contains('editing')) return;
    var original = _msgBodies[messageKey] || '';
    var bubble = wrap.querySelector('.dm-message-bubble');
    var time = wrap.querySelector('.dm-message-time');
    if (!bubble || !time) return;
    wrap.classList.add('editing');
    // Stash original markup so Cancel restores it without a server round-trip.
    wrap._dmOrigBubble = bubble.innerHTML;
    wrap._dmOrigTime = time.innerHTML;
    bubble.innerHTML = '<textarea class="dm-edit-textarea" rows="' + Math.max(2, Math.min(10, (original.match(/\n/g) || []).length + 2)) + '"></textarea>';
    var ta = bubble.querySelector('.dm-edit-textarea');
    ta.value = original;
    ta.focus();
    var endPos = ta.value.length;
    ta.setSelectionRange(endPos, endPos);
    time.innerHTML = '<button type="button" class="dm-edit-save" onclick="CommunityDM.saveEdit(' + threadKey + ',' + messageKey + ',' + (isPop ? 'true' : 'false') + ')">Save</button>'
        + ' <button type="button" class="dm-edit-cancel" onclick="CommunityDM.cancelEdit(' + messageKey + ',' + (isPop ? 'true' : 'false') + ')">Cancel</button>';
    // Enter saves, Shift+Enter inserts newline, Escape cancels.
    ta.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            CommunityDM.saveEdit(threadKey, messageKey, !!isPop);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            CommunityDM.cancelEdit(messageKey, !!isPop);
        }
    });
};

CommunityDM.cancelEdit = function(messageKey, isPop) {
    var idPrefix = isPop ? 'dm-pop-msg-' : 'dm-msg-';
    var wrap = document.getElementById(idPrefix + messageKey);
    if (!wrap) return;
    var bubble = wrap.querySelector('.dm-message-bubble');
    var time = wrap.querySelector('.dm-message-time');
    if (bubble && wrap._dmOrigBubble != null) bubble.innerHTML = wrap._dmOrigBubble;
    if (time   && wrap._dmOrigTime   != null) time.innerHTML   = wrap._dmOrigTime;
    wrap.classList.remove('editing');
};

CommunityDM.saveEdit = function(threadKey, messageKey, isPop) {
    var idPrefix = isPop ? 'dm-pop-msg-' : 'dm-msg-';
    var wrap = document.getElementById(idPrefix + messageKey);
    if (!wrap) return;
    var ta = wrap.querySelector('.dm-edit-textarea');
    if (!ta) return;
    var body = ta.value.trim();
    if (!body) { alert('Message cannot be empty'); return; }
    var saveBtn = wrap.querySelector('.dm-edit-save');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving...'; }
    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'edit_message', message_key: messageKey, body: body }
    }).then(function(data) {
        if (data.error) {
            alert(data.error);
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
            return;
        }
        // Full-page view: simplest correct path is to reload the thread so
        // sender labels / scroll position / image embeds are re-rendered
        // through the same code path as the initial load.
        if (!isPop) {
            CommunityDM.loadThread(threadKey);
            return;
        }
        // Popover: patch in place to preserve scroll + popover state.
        _msgBodies[messageKey] = data.message_body || body;
        var bubble = wrap.querySelector('.dm-message-bubble');
        var time = wrap.querySelector('.dm-message-time');
        if (bubble) bubble.innerHTML = data.message_body_html || Community.formatBody(data.message_body || body);
        if (time) {
            time.innerHTML = Community.timeAgo(data.message_revision_dtime || new Date().toISOString())
                + ' <span class="dm-edited-tag" title="' + Community.esc(data.message_revision_dtime || '') + '">(edited)</span>'
                + ' <button type="button" class="dm-edit-btn" onclick="CommunityDM.beginEdit(' + threadKey + ',' + messageKey + ',true)" title="Edit message">&#9998;</button>';
        }
        wrap.classList.remove('editing');
    });
};

// ── Show compose new message ──
CommunityDM.showCompose = function(recipientKey) {
    _activeThreadKey = null;
    Community.showView('view-messages');
    var el = document.getElementById('view-messages');

    var html = '<a href="#messages" class="back-link">&larr; Back to inbox</a>';
    html += '<div class="compose-form">'
        + '<h3 class="compose-title">New Message</h3>'
        + '<div class="dm-recipient-search">'
        + '<input id="dm-recipient" placeholder="Search for a user..." autocomplete="off"' + (recipientKey ? ' data-key="' + recipientKey + '"' : '') + '>'
        + '<div id="dm-recipient-results" class="mention-dropdown" style="display:none"></div>'
        + '</div>'
        + composeHtml('dm-new-body', 'Your message...', 4, 'Send Message', 'CommunityDM.submitNew()')
        + '</div>';
    el.innerHTML = html;

    wireCompose('dm-new-body', CommunityDM.submitNew);
    initDmEditor('dm-new-body');

    // Recipient search
    var input = document.getElementById('dm-recipient');
    var timeout;
    input.addEventListener('input', function() {
        clearTimeout(timeout);
        var q = this.value.trim();
        if (q.length < 2) {
            document.getElementById('dm-recipient-results').style.display = 'none';
            return;
        }
        timeout = setTimeout(function() {
            Community.api('/api/community-members.php?q=' + encodeURIComponent(q) + '&limit=5').then(function(data) {
                var members = data.members || [];
                var dd = document.getElementById('dm-recipient-results');
                if (!members.length) { dd.style.display = 'none'; return; }
                var h = '';
                members.forEach(function(m) {
                    h += '<div class="mention-item" data-key="' + m.user_key + '" data-name="' + Community.esc(m.user_name_display) + '">'
                        + Community.clickableAvatar(m.user_key, m.user_avatar, m.user_name_display, 'mention-avatar', m.user_last_active_dtime)
                        + '<span>' + Community.esc(m.user_name_display) + '</span></div>';
                });
                dd.innerHTML = h;
                dd.style.display = '';
                dd.querySelectorAll('.mention-item').forEach(function(item) {
                    item.addEventListener('click', function() {
                        input.value = this.getAttribute('data-name');
                        input.setAttribute('data-key', this.getAttribute('data-key'));
                        dd.style.display = 'none';
                    });
                });
            });
        }, 200);
    });

    if (recipientKey) {
        Community.api('/api/community-members.php?user_key=' + recipientKey).then(function(data) {
            var m = data.member || data;
            if (m && m.user_name_display) {
                input.value = m.user_name_display;
                input.setAttribute('data-key', recipientKey);
            }
        });
    }
};

CommunityDM.startNew = function(userKey) {
    CommunityDM.showCompose(userKey);
};

// ── Submit new thread ──
CommunityDM.submitNew = function() {
    var recipientKey = document.getElementById('dm-recipient').getAttribute('data-key');
    var body = '';
    var bodyHtml = '';
    if (CommunityDM._editor) {
        var plainText = CommunityDM._editor.getContent({ format: 'text' }).trim();
        bodyHtml = CommunityDM._editor.getContent();
        body = plainText || '(media)';
        if (!plainText && bodyHtml.indexOf('<img') < 0) { body = ''; bodyHtml = ''; }
    } else {
        body = document.getElementById('dm-new-body').value.trim();
    }
    if (!recipientKey) { alert('Please select a recipient'); return; }
    if (!body) { alert('Message cannot be empty'); return; }

    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'new_thread', recipient_key: parseInt(recipientKey), body: body, message_body_html: bodyHtml }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        if (data.thread_key) {
            window.location.hash = '#message/' + data.thread_key;
        } else {
            window.location.hash = '#messages';
        }
    });
};

// ── Show compose group ──
var _groupMembers = [];

CommunityDM.showComposeGroup = function() {
    _activeThreadKey = null;
    _groupMembers = [];
    Community.showView('view-messages');
    var el = document.getElementById('view-messages');

    var html = '<a href="#messages" class="back-link">&larr; Back to inbox</a>';
    html += '<div class="compose-form">'
        + '<h3 class="compose-title">New Group</h3>'
        + '<div style="margin-bottom:12px;">'
        + '<input id="dm-group-name" placeholder="Group name (optional)" style="width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:0.9rem;box-sizing:border-box;">'
        + '</div>'
        + '<div style="margin-bottom:8px;font-size:0.85rem;font-weight:600;color:#31345A;">Add Members</div>'
        + '<div class="dm-recipient-search" style="margin-bottom:8px;">'
        + '<input id="dm-group-member-search" placeholder="Search for members..." autocomplete="off" style="width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:0.9rem;box-sizing:border-box;">'
        + '<div id="dm-group-search-results" class="mention-dropdown" style="display:none"></div>'
        + '</div>'
        + '<div id="dm-group-selected" class="dm-group-selected" style="margin-bottom:12px;display:flex;flex-wrap:wrap;gap:6px;"></div>'
        + composeHtml('dm-group-body', 'First message (optional)...', 4, 'Send Message to Group', 'CommunityDM.submitGroup()')
        + '</div>';
    el.innerHTML = html;

    wireCompose('dm-group-body', CommunityDM.submitGroup);
    initDmEditor('dm-group-body');

    // Member search
    var input = document.getElementById('dm-group-member-search');
    var timeout;
    input.addEventListener('input', function() {
        clearTimeout(timeout);
        var q = this.value.trim();
        if (q.length < 2) {
            document.getElementById('dm-group-search-results').style.display = 'none';
            return;
        }
        timeout = setTimeout(function() {
            Community.api('/api/community-members.php?q=' + encodeURIComponent(q) + '&limit=8').then(function(data) {
                var members = data.members || [];
                var dd = document.getElementById('dm-group-search-results');
                if (!members.length) { dd.style.display = 'none'; return; }
                var h = '';
                members.forEach(function(m) {
                    if (Community.currentUser && m.user_key === Community.currentUser.user_key) return;
                    if (_groupMembers.some(function(gm) { return gm.key === m.user_key; })) return;
                    h += '<div class="mention-item" data-key="' + m.user_key + '" data-name="' + Community.esc(m.user_name_display) + '">'
                        + Community.clickableAvatar(m.user_key, m.user_avatar, m.user_name_display, 'mention-avatar', m.user_last_active_dtime)
                        + '<span>' + Community.esc(m.user_name_display) + '</span></div>';
                });
                dd.innerHTML = h || '<div style="padding:8px;color:#999;font-size:0.85rem;">No results</div>';
                dd.style.display = '';
                dd.querySelectorAll('.mention-item').forEach(function(item) {
                    item.addEventListener('click', function() {
                        var key = parseInt(this.getAttribute('data-key'));
                        var name = this.getAttribute('data-name');
                        _groupMembers.push({ key: key, name: name });
                        _renderGroupSelected();
                        input.value = '';
                        dd.style.display = 'none';
                    });
                });
            });
        }, 200);
    });
};

function _renderGroupSelected() {
    var el = document.getElementById('dm-group-selected');
    if (!el) return;
    var html = '';
    _groupMembers.forEach(function(m, i) {
        html += '<span class="dm-group-chip">'
            + Community.esc(m.name)
            + ' <span class="dm-group-chip-remove" onclick="_removeGroupMember(' + i + ')">&times;</span>'
            + '</span>';
    });
    el.innerHTML = html;
}

function _removeGroupMember(index) {
    _groupMembers.splice(index, 1);
    _renderGroupSelected();
}

// Make accessible globally for onclick
window._removeGroupMember = _removeGroupMember;

CommunityDM.submitGroup = function() {
    if (_groupMembers.length < 1) { alert('Add at least one member'); return; }
    var groupName = (document.getElementById('dm-group-name') || {}).value || '';
    var body = '';
    var bodyHtml = '';
    if (CommunityDM._editor) {
        var plainText = CommunityDM._editor.getContent({ format: 'text' }).trim();
        bodyHtml = CommunityDM._editor.getContent();
        body = plainText || '';
    } else {
        body = (document.getElementById('dm-group-body') || {}).value || '';
    }

    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: {
            action: 'create_group',
            group_name: groupName.trim(),
            member_keys: _groupMembers.map(function(m) { return m.key; }),
            body: body,
            message_body_html: bodyHtml
        }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        if (data.thread_key) {
            window.location.hash = '#message/' + data.thread_key;
        }
    });
};

// ── Group management functions ──
CommunityDM.toggleGroupPanel = function(threadKey) {
    var panel = document.getElementById('dm-group-panel');
    if (!panel) return;
    var isHidden = panel.style.display === 'none';
    panel.style.display = isHidden ? '' : 'none';

    // Wire up add member search when panel opens
    if (isHidden) {
        var input = document.getElementById('dm-add-member-input');
        if (!input) return;
        var timeout;
        input.addEventListener('input', function() {
            clearTimeout(timeout);
            var q = this.value.trim();
            var dd = document.getElementById('dm-add-member-results');
            if (q.length < 2) { dd.style.display = 'none'; return; }
            timeout = setTimeout(function() {
                Community.api('/api/community-members.php?q=' + encodeURIComponent(q) + '&limit=5').then(function(data) {
                    var members = data.members || [];
                    if (!members.length) { dd.style.display = 'none'; return; }
                    var h = '';
                    members.forEach(function(m) {
                        if (Community.currentUser && m.user_key === Community.currentUser.user_key) return;
                        h += '<div class="mention-item" data-key="' + m.user_key + '" data-name="' + Community.esc(m.user_name_display) + '">'
                            + Community.clickableAvatar(m.user_key, m.user_avatar, m.user_name_display, 'mention-avatar', m.user_last_active_dtime)
                            + '<span>' + Community.esc(m.user_name_display) + '</span></div>';
                    });
                    dd.innerHTML = h;
                    dd.style.display = '';
                    dd.querySelectorAll('.mention-item').forEach(function(item) {
                        item.addEventListener('click', function() {
                            var mk = parseInt(this.getAttribute('data-key'));
                            CommunityDM.addMember(threadKey, mk);
                            input.value = '';
                            dd.style.display = 'none';
                        });
                    });
                });
            }, 200);
        });
    }
};

CommunityDM.renameGroup = function(threadKey) {
    var input = document.getElementById('dm-group-name-input');
    var name = input ? input.value.trim() : '';
    if (!name) { alert('Enter a group name'); return; }
    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'rename_group', thread_key: threadKey, group_name: name }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        CommunityDM.loadThread(threadKey);
    });
};

CommunityDM.addMember = function(threadKey, memberKey) {
    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'add_member', thread_key: threadKey, user_key: memberKey }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        CommunityDM.loadThread(threadKey);
    });
};

CommunityDM.removeMember = function(threadKey, memberKey) {
    if (!confirm('Remove this member from the group?')) return;
    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'remove_member', thread_key: threadKey, user_key: memberKey }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        CommunityDM.loadThread(threadKey);
    });
};

CommunityDM.leaveGroup = function(threadKey) {
    if (!confirm('Are you sure you want to leave this group?')) return;
    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'leave_group', thread_key: threadKey }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        window.location.hash = '#messages';
    });
};

// ══════════════════════════════════════════════
// ── Chat Popover (Messenger-style floating widget) ──
// ══════════════════════════════════════════════

var _popThread = null;

CommunityDM.initPopover = function() {
    var fab = document.getElementById('chat-fab');
    if (fab && Community.currentUser) fab.style.display = '';
};

CommunityDM.updateFabBadge = function(count) {
    var badge = document.getElementById('chat-fab-badge');
    if (!badge) return;
    if (count > 0) { badge.textContent = count; badge.style.display = ''; }
    else { badge.style.display = 'none'; }
};

CommunityDM.togglePopover = function() {
    var pop = document.getElementById('chat-popover');
    if (pop.classList.contains('open')) {
        pop.classList.remove('open');
        _popThread = null;
    } else {
        pop.classList.add('open');
        CommunityDM.popInbox();
        // Scroll page to bottom so the popover is fully visible
        var ft = document.querySelector('footer');
        if (ft) {
            var y = ft.getBoundingClientRect().top + window.scrollY - window.innerHeight;
            window.scrollTo({ top: y, behavior: 'smooth' });
        } else {
            window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
        }
    }
};

CommunityDM.popInbox = function() {
    _popThread = null;
    document.getElementById('chat-pop-title').textContent = 'Messages';
    document.getElementById('chat-pop-back').style.display = 'none';
    var newBtn = document.getElementById('chat-pop-new');
    if (newBtn) newBtn.style.display = '';
    var body = document.getElementById('chat-pop-body');
    body.innerHTML = '<div class="chat-pop-empty">Loading...</div>';

    Community.api('/api/community-dm.php?threads=1').then(function(data) {
        var threads = data.threads || [];
        threads.forEach(function(t) { if (t.chime_pitch != null) _threadChimePitches[t.thread_key] = parseInt(t.chime_pitch); });
        if (!threads.length) {
            body.innerHTML = '<div class="chat-pop-empty">No messages yet</div>';
            return;
        }
        var html = '';
        threads.forEach(function(t) {
            var u = t.other_user || {};
            var avatarHtml = t.thread_is_group
                ? '<div class="dm-group-icon" style="width:36px;height:36px;font-size:1rem;">&#128101;</div>'
                : Community.clickableAvatar(u.user_key, u.user_avatar, u.user_name_display, 'dm-avatar', u.user_last_active_dtime);
            var displayName = t.thread_is_group
                ? (t.thread_name || u.user_name_display || 'Group')
                : (u.user_name_display || 'Unknown');
            html += '<div class="chat-pop-thread' + (t.unread_count > 0 ? ' unread' : '') + '" onclick="CommunityDM.popThread(' + t.thread_key + ')">'
                + avatarHtml
                + '<div style="flex:1;min-width:0;">'
                + '<div class="cp-name">' + Community.esc(displayName)
                + (t.unread_count > 0 ? ' <span class="dm-unread-badge">' + t.unread_count + '</span>' : '') + '</div>'
                + '<div class="cp-preview">' + Community.esc(t.last_message || '') + '</div>'
                + '</div>'
                + '<span class="cp-time">' + Community.timeAgo(t.last_message_dtime) + '</span>'
                + '</div>';
        });
        body.innerHTML = html;
    });
};

CommunityDM.popThread = function(threadKey) {
    _popThread = threadKey;
    document.getElementById('chat-pop-back').style.display = '';
    var newBtn = document.getElementById('chat-pop-new');
    if (newBtn) newBtn.style.display = 'none';
    var body = document.getElementById('chat-pop-body');
    body.innerHTML = '<div class="chat-pop-empty">Loading...</div>';

    CommunityNotifications.clearDmCount();

    Community.api('/api/community-dm.php?thread=' + threadKey).then(function(data) {
        var messages = data.messages || [];
        var thread = data.thread || {};
        var otherUser = thread.other_user || {};

        var popTitle = thread.thread_is_group
            ? (thread.thread_name || otherUser.user_name_display || 'Group')
            : (otherUser.user_name_display || 'Messages');
        document.getElementById('chat-pop-title').textContent = popTitle;

        var html = '<div class="chat-pop-msgs" id="chat-pop-msgs">';
        messages.forEach(function(m) {
            var isMine = m.user_key === Community.currentUser.user_key;
            if (isMine) _msgBodies[m.message_key] = m.message_body || '';
            var editedTag = ((m.message_revision_num || 1) > 1) ? ' <span class="dm-edited-tag" title="' + Community.esc(m.message_revision_dtime || '') + '">(edited)</span>' : '';
            var editBtn = isMine ? '<button type="button" class="dm-edit-btn" onclick="CommunityDM.beginEdit(' + threadKey + ',' + m.message_key + ',true)" title="Edit message">&#9998;</button>' : '';
            html += '<div class="dm-message' + (isMine ? ' mine' : '') + '" id="dm-pop-msg-' + m.message_key + '">'
                + '<div class="dm-message-bubble">' + (m.message_body_html || Community.formatBody(m.message_body)) + '</div>'
                + '<div class="dm-message-time">' + Community.timeAgo(m.message_dtime) + editedTag + editBtn + '</div>'
                + '</div>';
        });
        html += '</div>';
        html += '<div class="chat-pop-compose">'
            + '<textarea id="chat-pop-input" placeholder="Type a message..." rows="1"></textarea>'
            + '<button type="button" class="dm-media-btn" id="chat-pop-input-media-btn" title="Attach image or video">&#128247;</button>'
            + '<input type="file" id="chat-pop-input-media-file" accept="image/*,video/*" multiple style="display:none">'
            + '<button class="chat-pop-send" onclick="CommunityDM.popSend(' + threadKey + ')">&#10148;</button>'
            + '</div>';
        body.innerHTML = html;

        var msgs = document.getElementById('chat-pop-msgs');
        function scrollPopToBottom() {
            if (msgs) msgs.scrollTop = msgs.scrollHeight;
        }
        scrollPopToBottom();
        // Re-scroll after images/media load
        var popImgs = msgs ? msgs.querySelectorAll('img, video, iframe') : [];
        popImgs.forEach(function(el) { el.addEventListener('load', scrollPopToBottom); });
        setTimeout(scrollPopToBottom, 300);
        setTimeout(scrollPopToBottom, 800);

        // Wire up media button
        var mediaBtn = document.getElementById('chat-pop-input-media-btn');
        var mediaFile = document.getElementById('chat-pop-input-media-file');
        var popTa = document.getElementById('chat-pop-input');
        if (mediaBtn && mediaFile) {
            mediaBtn.addEventListener('click', function() { mediaFile.click(); });
            mediaFile.addEventListener('change', function() {
                for (var i = 0; i < this.files.length; i++) {
                    uploadMedia(this.files[i], function(md) { insertAtCursor(popTa, md); });
                }
                this.value = '';
            });
        }

        // Paste support
        if (popTa) {
            popTa.focus();
            popTa.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    CommunityDM.popSend(threadKey);
                }
            });
            popTa.addEventListener('paste', function(e) {
                var items = (e.clipboardData || {}).items || [];
                for (var i = 0; i < items.length; i++) {
                    if (items[i].type.indexOf('image') === 0) {
                        e.preventDefault();
                        uploadMedia(items[i].getAsFile(), function(md) { insertAtCursor(popTa, md); });
                        return;
                    }
                }
            });
        }
    });
};

CommunityDM.popSend = function(threadKey) {
    var input = document.getElementById('chat-pop-input');
    if (!input) return;
    var body = input.value.trim();
    if (!body) return;
    input.value = '';

    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'send', thread_key: threadKey, body: body }
    }).then(function(data) {
        if (data.error) return;
        var msgs = document.getElementById('chat-pop-msgs');
        if (msgs) {
            var div = document.createElement('div');
            div.className = 'dm-message mine';
            div.innerHTML = '<div class="dm-message-bubble">' + Community.formatBody(body) + '</div>'
                + '<div class="dm-message-time">just now</div>';
            msgs.appendChild(div);
            msgs.scrollTop = msgs.scrollHeight;
        }
        // Scroll page so popover compose area is visible
        var popInput = document.getElementById('chat-pop-input');
        if (popInput) popInput.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
};

// ── Popover compose: search for a member to message ──
CommunityDM.popCompose = function() {
    _popThread = null;
    document.getElementById('chat-pop-title').textContent = 'New Message';
    document.getElementById('chat-pop-back').style.display = '';
    document.getElementById('chat-pop-new').style.display = 'none';
    var body = document.getElementById('chat-pop-body');
    body.innerHTML = '<div class="chat-pop-search">'
        + '<input id="chat-pop-member-search" placeholder="Search for a member..." autocomplete="off">'
        + '</div>'
        + '<div class="chat-pop-results" id="chat-pop-results"></div>';

    var input = document.getElementById('chat-pop-member-search');
    var searchTimeout;
    input.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        var q = this.value.trim();
        var results = document.getElementById('chat-pop-results');
        if (q.length < 2) { results.innerHTML = ''; return; }
        searchTimeout = setTimeout(function() {
            Community.api('/api/community-members.php?q=' + encodeURIComponent(q) + '&limit=8').then(function(data) {
                var members = data.members || [];
                if (!members.length) {
                    results.innerHTML = '<div class="chat-pop-empty" style="padding:20px">No members found</div>';
                    return;
                }
                var html = '';
                members.forEach(function(m) {
                    if (Community.currentUser && m.user_key === Community.currentUser.user_key) return;
                    html += '<div class="chat-pop-thread" onclick="CommunityDM.popStartThread(' + m.user_key + ')">'
                        + Community.clickableAvatar(m.user_key, m.user_avatar, m.user_name_display, 'dm-avatar', m.user_last_active_dtime)
                        + '<div style="flex:1;min-width:0;">'
                        + '<div class="cp-name">' + Community.esc(m.user_name_display) + '</div>'
                        + (m.user_handle ? '<div class="cp-preview">@' + Community.esc(m.user_handle) + '</div>' : '')
                        + '</div></div>';
                });
                results.innerHTML = html || '<div class="chat-pop-empty" style="padding:20px">No members found</div>';
            });
        }, 250);
    });
    input.focus();
};

// ── Start or open a thread with a selected member ──
CommunityDM.popStartThread = function(userKey) {
    // Check if thread already exists
    Community.api('/api/community-dm.php?threads=1').then(function(data) {
        var threads = data.threads || [];
        for (var i = 0; i < threads.length; i++) {
            if (threads[i].other_user && threads[i].other_user.user_key === userKey) {
                CommunityDM.popThread(threads[i].thread_key);
                return;
            }
        }
        // No existing thread — create one with an empty first message prompt
        _popThread = null;
        document.getElementById('chat-pop-back').style.display = '';
        document.getElementById('chat-pop-new').style.display = 'none';
        var body = document.getElementById('chat-pop-body');
        body.innerHTML = '<div class="chat-pop-msgs" id="chat-pop-msgs" style="min-height:200px">'
            + '<div class="chat-pop-empty" style="padding:40px 20px">Start the conversation</div>'
            + '</div>'
            + '<div class="chat-pop-compose">'
            + '<textarea id="chat-pop-input" placeholder="Type a message..." rows="1"></textarea>'
            + '<button class="chat-pop-send" onclick="CommunityDM.popSendNew(' + userKey + ')">&#10148;</button>'
            + '</div>';

        Community.api('/api/community-members.php?user_key=' + userKey).then(function(d) {
            var m = d.member || d;
            if (m && m.user_name_display) {
                document.getElementById('chat-pop-title').textContent = m.user_name_display;
            }
        });

        var ta = document.getElementById('chat-pop-input');
        if (ta) {
            ta.focus();
            ta.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    CommunityDM.popSendNew(userKey);
                }
            });
        }
    });
};

// ── Send first message to create new thread via popover ──
CommunityDM.popSendNew = function(recipientKey) {
    var input = document.getElementById('chat-pop-input');
    var body = input ? input.value.trim() : '';
    if (!body) return;
    input.value = '';

    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'new_thread', recipient_key: recipientKey, body: body }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        if (data.thread_key) {
            CommunityDM.popThread(data.thread_key);
        }
    });
};

// Hook into SSE for live popover updates
var _origOnNewMessage = CommunityDM.onNewMessage;
CommunityDM.onNewMessage = function(msg) {
    _origOnNewMessage(msg);
    if (!msg || !msg.thread_key) return;

    // Play pitch-shifted chime for incoming messages (not our own)
    if (!Community.currentUser || msg.user_key !== Community.currentUser.user_key) {
        var pitch = _threadChimePitches[msg.thread_key] || 0;
        playChime(pitch);
    }

    var pop = document.getElementById('chat-popover');
    if (pop && pop.classList.contains('open')) {
        if (_popThread == msg.thread_key) {
            var msgs = document.getElementById('chat-pop-msgs');
            if (msgs) {
                var div = document.createElement('div');
                div.className = 'dm-message';
                div.innerHTML = '<div class="dm-message-bubble">' + (msg.message_body_html || Community.formatBody(msg.message_body)) + '</div>'
                    + '<div class="dm-message-time">' + Community.timeAgo(msg.message_dtime) + '</div>';
                msgs.appendChild(div);
                msgs.scrollTop = msgs.scrollHeight;
            }
            CommunityNotifications.clearDmCount();
            Community.api('/api/community-dm.php?thread=' + msg.thread_key);
        } else {
            if (!_popThread) CommunityDM.popInbox();
        }
    }
    CommunityDM.updateFabBadge(CommunityNotifications.getUnreadDm ? CommunityNotifications.getUnreadDm() : 0);
};

// ── Chime editor ──
(function() {
    // Inject chime editor styles
    var style = document.createElement('style');
    style.textContent = ''
        + '.chime-btn { cursor:pointer; font-size:0.75rem; opacity:0.55; transition:opacity 0.15s; vertical-align:middle; }'
        + '.chime-btn:hover { opacity:1; }'
        + '.chime-editor { position:fixed; z-index:9999; background:#fff; border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,0.25); padding:16px; min-width:240px; }'
        + '.chime-editor h4 { margin:0 0 12px; font-size:0.9rem; color:#31345A; }'
        + '.chime-pitch-list { display:flex; flex-direction:column; gap:6px; }'
        + '.chime-pitch-item { display:flex; align-items:center; gap:10px; padding:6px 10px; border-radius:6px; cursor:pointer; transition:background 0.1s; }'
        + '.chime-pitch-item:hover { background:#f0f4ff; }'
        + '.chime-pitch-item.active { background:#e8ecff; font-weight:600; }'
        + '.chime-pitch-item .pitch-label { font-size:0.82rem; color:#333; flex:1; }'
        + '.chime-pitch-item .pitch-play { font-size:0.8rem; color:#31345A; cursor:pointer; opacity:0.6; }'
        + '.chime-pitch-item .pitch-play:hover { opacity:1; }';
    document.head.appendChild(style);
})();

var PITCH_NAMES = ['Default', 'Mellow', 'Bright', 'Warm', 'Crisp', 'Soft', 'Clear', 'Deep'];

CommunityDM.openChimeEditor = function(threadKey) {
    var currentPitch = _threadChimePitches[threadKey] || 0;
    // Remove existing editor
    var existing = document.getElementById('chime-editor');
    if (existing) existing.remove();

    var editor = document.createElement('div');
    editor.id = 'chime-editor';
    editor.className = 'chime-editor';

    var html = '<h4>Notification Chime</h4><div class="chime-pitch-list">';
    for (var i = 0; i < 8; i++) {
        html += '<div class="chime-pitch-item' + (i === currentPitch ? ' active' : '') + '" data-pitch="' + i + '" onclick="CommunityDM.selectChime(' + threadKey + ',' + i + ')">'
            + '<span class="pitch-play" onclick="event.stopPropagation();playChime(' + i + ')" title="Preview">&#9654;</span>'
            + '<span class="pitch-label">' + PITCH_NAMES[i] + '</span>'
            + (i === currentPitch ? '<span style="font-size:0.7rem;color:#27ae60;">&#10003;</span>' : '')
            + '</div>';
    }
    html += '</div>';
    editor.innerHTML = html;

    // Position near center of screen
    editor.style.top = '50%';
    editor.style.left = '50%';
    editor.style.transform = 'translate(-50%, -50%)';

    // Close on outside click
    var backdrop = document.createElement('div');
    backdrop.id = 'chime-backdrop';
    backdrop.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:9998;background:rgba(0,0,0,0.2);';
    backdrop.onclick = function() { backdrop.remove(); editor.remove(); };
    document.body.appendChild(backdrop);
    document.body.appendChild(editor);
};

CommunityDM.selectChime = function(threadKey, pitch) {
    // Save to server
    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'set_chime', thread_key: threadKey, chime_pitch: pitch }
    }).then(function() {
        _threadChimePitches[threadKey] = pitch;
        // Play the selected chime as confirmation
        playChime(pitch);
        // Close editor
        var backdrop = document.getElementById('chime-backdrop');
        var editor = document.getElementById('chime-editor');
        if (backdrop) backdrop.remove();
        if (editor) editor.remove();
        // Refresh the inbox to update the icon
        if (document.getElementById('view-messages') && document.getElementById('view-messages').style.display !== 'none') {
            CommunityDM.loadInbox();
        }
    });
};

})();
