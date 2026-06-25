/* ── community-topics.js ── Topic list, detail, create, reply, likes, reactions, watch, bookmark, polls ── */
(function() {
'use strict';

window.CommunityTopics = {};

// ── Moderator preference: show/hide removed posts ──
var _showRemoved = localStorage.getItem('mod_show_removed') !== 'false'; // default true

// ── Emoji reaction codes ──
var REACTIONS = [
    { code: 'thumbsup', emoji: '\uD83D\uDC4D' },
    { code: 'heart', emoji: '\u2764\uFE0F' },
    { code: 'pray', emoji: '\uD83D\uDE4F' },
    { code: 'fire', emoji: '\uD83D\uDD25' },
    { code: 'laugh', emoji: '\uD83D\uDE02' },
    { code: 'sad', emoji: '\uD83D\uDE22' }
];

// ── Rich text editor (TinyMCE) ──
CommunityTopics._editors = {};

CommunityTopics.createEditor = function(containerId, textareaId) {
    var container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = '<textarea id="' + textareaId + '" style="width:100%;min-height:150px;padding:10px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:0.95rem;resize:vertical;"></textarea>';

    // Lazy-load TinyMCE
    if (typeof tinymce === 'undefined') {
        if (window.ensureTinyMCE) {
            ensureTinyMCE(function() { CommunityTopics._initTinyMCE(containerId, textareaId); });
        }
        return;
    }

    CommunityTopics._initTinyMCE(containerId, textareaId);
};

CommunityTopics._initTinyMCE = function(containerId, textareaId) {
    try {
    // Remove existing instance if any
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
        // placeholder handled manually via setup below
        min_height: 220,
        max_height: 600,
        autoresize_bottom_margin: 10,
        skin: 'oxide',
        content_css: 'default',
        body_class: 'community-editor-body',
        content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 0.95rem; line-height: 1.6; color: #333; padding: 8px; } img { max-width: 100%; height: auto; border-radius: 6px; } a { color: #31345A; } blockquote { border-left: 3px solid #E5C86C; margin: 8px 0; padding: 8px 16px; background: #fafafa; } pre { background: #f4f4f4; padding: 12px; border-radius: 6px; overflow-x: auto; } table { border-collapse: collapse; width: 100%; } td, th { border: 1px solid #ddd; padding: 8px; }',

        // Image upload
        images_upload_handler: function(blobInfo) {
            return new Promise(function(resolve, reject) {
                var fd = new FormData();
                fd.append('image', blobInfo.blob(), blobInfo.filename());
                fetch('/api/community-image-upload.php', {
                    method: 'POST',
                    credentials: 'include',
                    body: fd
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.url) resolve(data.url);
                    else reject(data.error || 'Upload failed');
                }).catch(function() { reject('Upload failed'); });
            });
        },
        automatic_uploads: true,
        file_picker_types: 'image media file',
        images_reuse_filename: true,

        // File picker — handles image, video, and document uploads
        file_picker_callback: function(callback, value, meta) {
            var input = document.createElement('input');
            input.type = 'file';
            if (meta.filetype === 'image') {
                input.accept = 'image/jpeg,image/png,image/gif,image/webp';
            } else if (meta.filetype === 'media') {
                // TinyMCE's "media" picker covers both video and audio — accept
                // common audio formats too so posts can include voice notes or
                // a quick mp3/wav alongside video. TinyMCE picks <video> vs
                // <audio> based on the returned URL's extension.
                input.accept = 'video/mp4,video/webm,video/quicktime,video/ogg,'
                             + 'audio/mpeg,audio/wav,audio/x-wav,audio/wave,audio/mp3,'
                             + '.mp4,.webm,.mov,.ogg,.avi,.mkv,.mp3,.wav';
            } else {
                input.accept = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.rtf,.csv,.zip';
            }
            input.onchange = function() {
                var file = input.files[0];
                if (!file) return;
                var fd = new FormData();
                if (meta.filetype === 'image') {
                    fd.append('image', file, file.name);
                    fetch('/api/community-image-upload.php', {
                        method: 'POST', credentials: 'include', body: fd
                    }).then(function(r) { return r.json(); }).then(function(data) {
                        if (data.url) callback(data.url, { alt: file.name });
                        else alert(data.error || 'Upload failed');
                    }).catch(function() { alert('Upload failed'); });
                } else if (meta.filetype === 'media') {
                    fd.append('media', file, file.name);
                    fetch('/api/community-media-upload.php', {
                        method: 'POST', credentials: 'include', body: fd
                    }).then(function(r) { return r.json(); }).then(function(data) {
                        if (data.url) callback(data.url, { source2: '', poster: '' });
                        else alert(data.error || 'Upload failed');
                    }).catch(function() { alert('Upload failed'); });
                } else {
                    fd.append('file', file, file.name);
                    fetch('/api/community-doc-upload.php', {
                        method: 'POST', credentials: 'include', body: fd
                    }).then(function(r) { return r.json(); }).then(function(data) {
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

        // Media embed — allow YouTube, Rumble, etc.
        media_live_embeds: true,
        media_alt_source: false,
        media_poster: false,

        // Link settings
        link_default_target: '_blank',
        link_assume_external_targets: true,

        // Paste cleanup
        paste_as_text: false,
        paste_block_drop: false,

        // Block formats
        block_formats: 'Paragraph=p; Heading 3=h3; Heading 4=h4; Blockquote=blockquote; Code=pre',

        // Override image button to open file picker directly
        image_title: false,
        image_description: false,
        setup: function(editor) {
            CommunityTopics._editors[textareaId] = editor;

            // Custom image upload button
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
                        fetch('/api/community-image-upload.php', {
                            method: 'POST', credentials: 'include', body: fd
                        }).then(function(r) { return r.json(); }).then(function(data) {
                            if (data.url) {
                                editor.insertContent('<img src="' + data.url + '" alt="' + file.name.replace(/"/g, '') + '" style="max-width:100%;">');
                            } else { alert(data.error || 'Upload failed'); }
                        }).catch(function() { alert('Upload failed'); });
                    };
                    input.click();
                }
            });

            // Manual placeholder overlay — hides on focus, shows on blur if empty
            editor.on('init', function() {
                var iframeWrap = editor.getContainer().querySelector('.tox-editor-container .tox-sidebar-wrap');
                if (!iframeWrap) iframeWrap = editor.getContainer();
                var ph = document.createElement('div');
                ph.className = 'mce-placeholder-overlay';
                ph.textContent = 'Write your message...';
                ph.style.cssText = 'position:absolute;top:1px;left:12px;color:#aaa;font-size:0.95rem;pointer-events:none;z-index:1;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;';
                iframeWrap.style.position = 'relative';
                iframeWrap.appendChild(ph);

                function isEmpty() {
                    var content = editor.getContent({ format: 'text' }).trim();
                    var html = editor.getContent();
                    return !content && html.indexOf('<img') < 0 && html.indexOf('<video') < 0;
                }

                editor.on('focus', function() {
                    ph.style.display = 'none';
                });
                editor.on('blur', function() {
                    if (isEmpty()) ph.style.display = '';
                });
                if (isEmpty()) ph.style.display = '';
                else ph.style.display = 'none';
            });
        }
    });
    } catch(e) { console.warn('TinyMCE init failed, using plain textarea:', e); }
};

// Get editor HTML content
CommunityTopics.getEditorHtml = function(textareaId) {
    var editor = (typeof tinymce !== 'undefined') ? tinymce.get(textareaId) : null;
    if (editor) return editor.getContent();
    var ta = document.getElementById(textareaId);
    return ta ? ta.value : '';
};

// Get plain text from editor
CommunityTopics.getEditorText = function(textareaId) {
    var editor = (typeof tinymce !== 'undefined') ? tinymce.get(textareaId) : null;
    if (editor) return editor.getContent({ format: 'text' });
    var ta = document.getElementById(textareaId);
    return ta ? ta.value : '';
};

// ── Load topic list ──
CommunityTopics.loadTopics = function(page, category, opts) {
    opts = opts || {};
    page = page || Community.currentPage || 1;
    category = category !== undefined ? category : Community.currentCategory;
    Community.currentPage = page;

    Community.showView('view-topics');
    var el = document.getElementById('topic-list-content');
    if (!el) return;

    // Claim the topic-list render slot. Both this and CommunitySearch.search()
    // write here; the last one initiated must win regardless of which network
    // response lands first (fixes the initial page-load list clobbering search).
    Community._listSeq = (Community._listSeq || 0) + 1;
    var _seq = Community._listSeq;

    var url = '/api/community-topics.php?page=' + page;
    if (typeof CommunityAuth !== 'undefined' && CommunityAuth._showDeletions) url += '&show_deleted=1';
    if (category) url += '&category=' + encodeURIComponent(category);
    var parentSlug = opts.parentSlug || Community.currentSection || 'topics';
    if (parentSlug && parentSlug !== 'topics') url += '&parent_slug=' + encodeURIComponent(parentSlug);

    Community.api(url).then(function(data) {
        var topics = data.topics || [];
        var html = '';

        if (!topics.length) {
            html += '<div class="empty-state">No discussions yet. Be the first to start one!</div>';
        } else {
            html += '<div class="topic-list">';
            for (var i = 0; i < topics.length; i++) {
                var t = topics[i];
                var pinned = t.topic_pinned === true || t.topic_pinned === 't';
                var hidden = t.topic_active_flag === false || t.topic_active_flag === 'f';
                var isDeleted = !!t.topic_delete_dtime;
                var avatarHtml = t.topic_thumbnail
                    ? '<img class="topic-avatar" src="' + Community.esc(t.topic_thumbnail) + '" alt="" style="object-fit:cover;border-radius:6px;">'
                    : Community.clickableAvatar(t.user_key, t.user_avatar, t.user_name_display, 'topic-avatar', t.user_last_active_dtime);
                html += '<div class="topic-item' + (pinned ? ' pinned' : '') + (hidden ? ' topic-hidden' : '') + (isDeleted ? ' topic-deleted' : '') + '" onclick="window.location.hash=\'#topic/' + t.topic_key + '\'">'
                    + avatarHtml
                    + '<div class="topic-info">'
                    + '<div class="topic-title">' + (hidden ? '<span class="hidden-badge">Hidden</span> ' : '') + (pinned ? '&#128204; ' : '') + Community.esc(t.topic_title) + '</div>'
                    + '<div class="topic-meta">'
                    + Community.categoryBadge(t.category_slug)
                    + '<span onclick="event.stopPropagation();Community.showProfileCard(event,' + t.user_key + ')" style="cursor:pointer;">' + Community.esc(t.user_name_display || 'Anonymous') + '</span>'
                    + ' &middot; ' + Community.timeAgo(t.topic_dtime)
                    + (t.topic_last_reply_dtime ? ' &middot; last reply ' + Community.timeAgo(t.topic_last_reply_dtime) : '')
                    + '</div></div>'
                    + '<div class="topic-stats">'
                    + '<div class="stat-item"><span class="stat-icon" style="' + (t.user_replied ? 'color:#1a4f8a;' : 'opacity:0.35;') + '">' + (t.user_replied ? '&#9998;' : '&#128394;') + '</span><span class="stat-num">' + (t.topic_reply_count || 0) + '</span></div>'
                    + '<div class="stat-item"><span class="stat-icon" style="' + (t.user_viewed ? 'color:#0a3d1a;' : 'opacity:0.25;') + '">' + (t.user_viewed ? '&#128065;&#8205;&#128488;' : '&#128065;') + '</span><span class="stat-num">' + (t.topic_view_count || 0) + '</span></div>'
                    + '<div class="stat-item"><span class="stat-icon" style="' + (t.user_liked ? 'color:#e74c3c;' : 'opacity:0.35;') + '">' + (t.user_liked ? '&#10084;' : '&#9825;') + '</span><span class="stat-num">' + (t.total_like_count != null ? t.total_like_count : (t.topic_like_count || 0)) + '</span></div>'
                    + (isDeleted ? '<div class="stat-item"><button class="btn btn-sm btn-outline" onclick="event.stopPropagation();CommunityTopics.restoreTopic(' + t.topic_key + ')" style="font-size:0.72rem;">Restore</button></div>' : '')
                    + '</div></div>';
            }
            html += '</div>';
            if (data.pages > 1) {
                html += '<div class="pagination">';
                for (var p = 1; p <= data.pages; p++) {
                    html += '<button class="' + (p === data.page ? 'active' : '') + '" onclick="CommunityTopics.loadTopics(' + p + ')">' + p + '</button>';
                }
                html += '</div>';
            }
        }
        if (_seq !== Community._listSeq) return;  // a newer list/search render superseded us
        el.innerHTML = html;
    });
};

// ── Load single topic ──
CommunityTopics.loadTopic = function(key) {
    Community.showView('view-topic');
    var el = document.getElementById('view-topic');
    el.innerHTML = '<div class="empty-state">Loading...</div>';

    var topicUrl = '/api/community-topics.php?topic=' + key;
    if (typeof CommunityAuth !== 'undefined' && CommunityAuth._showDeletions) topicUrl += '&show_deleted=1';
    Community.api(topicUrl).then(function(data) {
        if (data.error || !data.topic) {
            el.innerHTML = '<div class="empty-state">' + (data.error || 'Topic not found') + '</div>'
                + '<a href="#topics" class="back-link" style="margin-top:12px;display:inline-block;">&larr; Back to topics</a>';
            return;
        }
        var t = data.topic;
        var replies = data.replies || [];
        var user = Community.currentUser;
        // Wire user status from top-level response onto topic + replies
        t.user_status = data.user_status || null;
        t.user_liked = !!(data.user_status && data.user_status.liked);
        var likedReplies = (data.user_status && data.user_status.liked_replies) || [];
        for (var li = 0; li < replies.length; li++) {
            replies[li].user_liked = likedReplies.indexOf(replies[li].reply_key) >= 0
                || likedReplies.indexOf(String(replies[li].reply_key)) >= 0;
        }
        var isMod = user && user.roles && (user.roles.indexOf('admin') >= 0 || user.roles.indexOf('moderator') >= 0);
        var isAuthorOrMod = user && (isMod || user.user_key === t.user_key);

        // Detect section from topic's category slug
        var isComment = (t.category_slug && t.category_slug.indexOf('comment-') === 0) || Community.currentSection === 'comments';
        var backHash = isComment ? '#comments' : '#topics';
        var backLabel = isComment ? 'Back to comments' : 'Back to topics';

        var html = '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">'
            + '<a href="' + backHash + '" class="back-link" style="margin-bottom:0;">&larr; ' + backLabel + '</a>';
        if (isMod) {
            html += '<label style="font-size:0.78rem;color:#888;display:flex;align-items:center;gap:5px;cursor:pointer;user-select:none;">'
                + '<input type="checkbox" id="toggle-removed" ' + (_showRemoved ? 'checked' : '') + ' onchange="CommunityTopics.toggleShowRemoved(this.checked,' + key + ')"> Show removed'
                + '</label>';
        }
        html += '</div>';

        // Topic detail
        var topicHidden = t.topic_active_flag === false || t.topic_active_flag === 'f';
        var topicIsRemoved = !!t.topic_delete_dtime;
        html += '<div class="topic-detail' + (topicHidden ? ' item-hidden' : '') + (topicIsRemoved && _showRemoved ? ' removed' : '') + '">';
        html += '<div class="topic-detail-header">';
        var videoUrl = '';
        if (t.video_id) {
            if (t.video_source === 'youtube') videoUrl = 'https://www.youtube.com/watch?v=' + t.video_id;
            else if (t.video_source === 'rumble') videoUrl = 'https://rumble.com/' + (t.video_id.charAt(0) === 'v' ? '' : 'v') + t.video_id;
            else if (t.video_source === 'facebook' && t.video_id.indexOf('_') > 0) videoUrl = 'https://www.facebook.com/' + t.video_id;
        }
        if (videoUrl) {
            html += '<h2><a href="' + Community.esc(videoUrl) + '" target="_blank" rel="noopener" style="color:inherit;text-decoration:none;" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">' + Community.esc(t.topic_title) + '</a></h2>';
        } else {
            html += '<h2>' + Community.esc(t.topic_title) + '</h2>';
        }
        // Watch and bookmark buttons
        if (user) {
            var watched = t.user_status && t.user_status.watching;
            var bookmarked = t.user_status && t.user_status.bookmarked;
            html += '<div class="topic-actions-top">'
                + '<button class="btn-icon' + (bookmarked ? ' active' : '') + '" onclick="CommunityTopics.toggleBookmark(' + key + ')" title="' + (bookmarked ? 'Remove bookmark' : 'Bookmark') + '">&#128278;' + (bookmarked ? ' Saved' : '') + '</button>'
                + '</div>';
        }
        html += '</div>';

        html += '<div class="topic-author">'
            + Community.clickableAvatar(t.user_key, t.user_avatar, t.user_name_display, 'detail-avatar', t.user_last_active_dtime)
            + '<span onclick="Community.showProfileCard(event,' + t.user_key + ')" style="cursor:pointer;">' + Community.esc(t.user_name_display || 'Anonymous') + '</span>'
            + ' &middot; ' + Community.timeAgo(t.topic_dtime)
            + (t.topic_last_reply_dtime ? ' &middot; last reply ' + Community.timeAgo(t.topic_last_reply_dtime) : '')
            + Community.categoryBadge(t.category_slug);
        if (isMod && user.user_key !== t.user_key) {
            html += '<button class="btn btn-sm btn-outline reply-ban-btn" onclick="CommunityTopics.openBanModal(' + t.user_key + ',\'' + Community.esc(t.user_name_display || '').replace(/'/g, "\\'") + '\')">Ban User</button>';
        }
        html += '</div>';

        if (t.topic_body_html || t.topic_body) {
            var bodyContent = t.topic_body_html || Community.formatBody(t.topic_body);
            if (t.video_id && t.topic_thumbnail) {
                var embedUrl = '';
                if (t.video_source === 'youtube') embedUrl = 'https://www.youtube.com/embed/' + t.video_id + '?autoplay=1';
                if (embedUrl) {
                    // YouTube: clickable thumbnail that swaps to inline player
                    bodyContent = '<div class="topic-video-thumb" onclick="this.innerHTML=\'<iframe width=560 height=315 src=&quot;' + Community.esc(embedUrl) + '&quot; frameborder=0 allowfullscreen allow=&quot;autoplay; encrypted-media&quot;></iframe>\';this.style.cursor=\'default\';this.onclick=null;" style="cursor:pointer;position:relative;display:inline-block;">'
                        + '<img src="' + Community.esc(t.topic_thumbnail) + '" alt="' + Community.esc(t.topic_title) + '" style="max-height:300px;width:auto;border-radius:6px;">'
                        + '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:60px;height:60px;background:rgba(0,0,0,0.6);border-radius:50%;display:flex;align-items:center;justify-content:center;"><svg viewBox="0 0 24 24" width="30" height="30" fill="#fff"><polygon points="8,5 19,12 8,19"/></svg></div>'
                        + '</div>';
                } else {
                    // Non-YouTube: thumbnail links to source page if valid
                    var sourceUrl = videoUrl;
                    if (sourceUrl) {
                        bodyContent = '<a href="' + Community.esc(sourceUrl) + '" target="_blank" rel="noopener" style="display:inline-block;">'
                            + '<img src="' + Community.esc(t.topic_thumbnail) + '" alt="' + Community.esc(t.topic_title) + '" style="max-height:300px;width:auto;border-radius:6px;">'
                            + '</a>';
                    } else {
                        bodyContent = '<img src="' + Community.esc(t.topic_thumbnail) + '" alt="' + Community.esc(t.topic_title) + '" style="max-height:300px;width:auto;border-radius:6px;">';
                    }
                }
            }
            html += '<div class="topic-body" id="topic-body-content">' + bodyContent + '</div>';
        }
        // Edit button for topic author (banned users can't edit)
        var isAuthor = user && user.user_key === t.user_key;
        if (isAuthor && !topicIsRemoved && Community.canPost()) {
            html += '<div style="margin-top:6px;"><button class="btn btn-sm btn-outline" style="font-size:0.75rem;" onclick="CommunityTopics.editTopic(' + key + ')">Edit</button>';
            if (t.topic_edit_dtime) html += ' <span style="font-size:0.72rem;color:#999;">(edited ' + Community.timeAgo(t.topic_edit_dtime) + ')</span>';
            html += '</div>';
        } else if (t.topic_edit_dtime) {
            html += '<div style="margin-top:4px;font-size:0.72rem;color:#999;">(edited ' + Community.timeAgo(t.topic_edit_dtime) + ')</div>';
        }

        // Poll
        if (data.poll) {
            html += CommunityTopics.renderPoll(data.poll, key);
        }

        // Like + reactions
        html += CommunityTopics.renderLikeAndReactions('topic', key, t);

        // Report button
        if (user && !isAuthorOrMod) {
            html += '<button class="btn-icon report-btn" onclick="CommunityTopics.openReport(\'topic\',' + key + ')" title="Report">&#9873; Report</button>';
        }

        // Topic remove/restore actions
        var topicRemoved = !!t.topic_delete_dtime;
        if (topicRemoved && isMod && _showRemoved) {
            html += '<div class="removed-notice"><span>Topic removed ' + Community.timeAgo(t.topic_delete_dtime)
                + (t.topic_delete_note ? ': ' + Community.esc(t.topic_delete_note) : '')
                + '</span><button class="btn btn-sm btn-outline restore-btn" onclick="CommunityTopics.restoreTopic(' + key + ')">Restore</button></div>';
        }
        if (!topicRemoved && isAuthorOrMod) {
            html += '<div class="mod-actions">'
                + '<button class="btn btn-sm btn-outline" style="color:#c0392b;border-color:#c0392b;" onclick="CommunityTopics.removeTopic(' + key + ')">Remove</button>'
                + '</div>';
        }

        html += '</div>';

        // Replies
        if (replies.length) {
            html += '<div class="reply-list">';
            for (var i = 0; i < replies.length; i++) {
                var r = replies[i];
                var canRemove = user && (isMod || user.user_key === r.user_key);
                var isRemoved = !!r.reply_delete_dtime;
                if (isRemoved && isMod && !_showRemoved) continue;

                html += '<div class="reply-item' + (isRemoved ? ' removed' : '') + '" id="reply-' + r.reply_key + '">';

                if (isRemoved && isMod) {
                    html += '<div class="removed-notice"><span>Removed ' + Community.timeAgo(r.reply_delete_dtime)
                        + (r.reply_delete_note ? ': ' + Community.esc(r.reply_delete_note) : '')
                        + '</span><button class="btn btn-sm btn-outline restore-btn" onclick="CommunityTopics.restoreReply(' + r.reply_key + ',' + key + ')">Restore</button></div>';
                }

                html += '<div class="reply-author">'
                    + Community.clickableAvatar(r.user_key, r.user_avatar, r.user_name_display, 'reply-avatar', r.user_last_active_dtime)
                    + '<span onclick="Community.showProfileCard(event,' + r.user_key + ')" style="cursor:pointer;">' + Community.esc(r.user_name_display || 'Anonymous') + '</span>'
                    + ' &middot; ' + Community.timeAgo(r.reply_dtime);
                if (isMod && !isRemoved && user.user_key !== r.user_key) {
                    html += '<button class="btn btn-sm btn-outline reply-ban-btn" onclick="CommunityTopics.openBanModal(' + r.user_key + ',\'' + Community.esc(r.user_name_display || '').replace(/'/g, "\\'") + '\')">Ban</button>';
                }
                html += '</div>'
                    + '<div class="reply-body">' + (r.reply_body_html || Community.formatBody(r.reply_body)) + '</div>';

                if (!isRemoved) {
                    // Reply like + reactions
                    html += CommunityTopics.renderLikeAndReactions('reply', r.reply_key, r);

                    // Report for reply (non-mods, non-authors)
                    if (user && !isMod && user.user_key !== r.user_key) {
                        html += '<button class="btn-icon report-btn" onclick="CommunityTopics.openReport(\'reply\',' + r.reply_key + ')" title="Report">&#9873;</button>';
                    }

                    // Edit + Remove buttons
                    var replyIsAuthor = user && user.user_key === r.user_key;
                    if (replyIsAuthor || canRemove) {
                        html += '<div class="reply-actions" style="display:flex;gap:8px;justify-content:flex-end;align-items:center;">';
                        if (replyIsAuthor && Community.canPost()) {
                            html += '<button class="btn btn-sm btn-outline" style="font-size:0.75rem;" onclick="CommunityTopics.editReply(' + r.reply_key + ',' + key + ')">Edit</button>';
                        }
                        if (r.reply_edit_dtime) {
                            html += '<span style="font-size:0.72rem;color:#999;">(edited ' + Community.timeAgo(r.reply_edit_dtime) + ')</span>';
                        }
                        if (canRemove) {
                            html += '<button class="btn btn-sm btn-outline" style="color:#c0392b;border-color:#c0392b;" onclick="CommunityTopics.removeReply(' + r.reply_key + ',' + key + ')">Remove</button>';
                        }
                        html += '</div>';
                    } else if (r.reply_edit_dtime) {
                        html += '<div style="text-align:right;font-size:0.72rem;color:#999;">(edited ' + Community.timeAgo(r.reply_edit_dtime) + ')</div>';
                    }
                }
                html += '</div>';
            }
            html += '</div>';
        }

        // Reply form
        var locked = t.topic_locked === true || t.topic_locked === 't';
        if (locked) {
            html += '<div class="empty-state" style="padding:20px;">This topic is locked.</div>';
        } else if (user && Community.canPost()) {
            html += '<div class="compose-form">'
                + '<div id="reply-editor"></div>'
                + '<button class="btn btn-primary" onclick="CommunityTopics.submitReply(' + key + ')">Reply</button>'
                + '</div>';
        } else if (!user) {
            html += '<div class="empty-state" style="padding:20px;"><a href="#" onclick="CommunityAuth.openLoginModal(\'login\');return false;">Sign in</a> to reply.</div>';
        }
        // Banned (logged in, !canPost): no compose, no notice — silent.

        // Clean up any existing TinyMCE instances before replacing HTML
        if (typeof tinymce !== 'undefined') {
            var existing = tinymce.get('reply-body');
            if (existing) { try { existing.remove(); } catch(e) {} }
        }

        el.innerHTML = html;

        // Init reply editor
        if (user && !locked && Community.canPost()) {
            CommunityTopics.createEditor('reply-editor', 'reply-body');
        }

        // Load poll if present
        if (!data.poll) {
            Community.api('/api/community-polls.php?topic=' + key).then(function(pollData) {
                if (pollData && pollData.poll) {
                    var pollContainer = document.createElement('div');
                    pollContainer.innerHTML = CommunityTopics.renderPoll(pollData.poll, key);
                    var topicDetail = el.querySelector('.topic-detail');
                    if (topicDetail) {
                        var body = topicDetail.querySelector('.topic-body');
                        if (body) body.insertAdjacentElement('afterend', pollContainer);
                    }
                }
            }).catch(function() {});
        }
    }).catch(function() {
        el.innerHTML = '<div class="empty-state">Unable to load topic. Please try refreshing.</div>'
            + '<a href="#topics" class="back-link" style="margin-top:12px;display:inline-block;">&larr; Back to topics</a>';
    });
};

// ── Like HTML ──
CommunityTopics.renderLikeAndReactions = function(type, key, item) {
    var likeCount = item[type + '_like_count'] || item.like_count || 0;
    var userLiked = item.user_liked;
    return '<div class="like-reaction-bar">'
        + '<button id="like-btn-' + type + '-' + key + '" class="btn-icon like-btn' + (userLiked ? ' liked' : '') + '" onclick="event.stopPropagation();CommunityTopics.toggleLike(event,\'' + type + '\',' + key + ');return false;">'
        + '<span class="heart-icon">' + (userLiked ? '&#9829;' : '&#9825;') + '</span>'
        + ' <span class="like-count">' + likeCount + '</span></button>'
        + '</div>';
};

CommunityTopics.toggleReactionPicker = function(btn) {
    var picker = btn.nextElementSibling;
    var isOpen = picker.style.display !== 'none';
    // Close all pickers first
    document.querySelectorAll('.reaction-picker').forEach(function(p) { p.style.display = 'none'; });
    picker.style.display = isOpen ? 'none' : 'flex';
};

// ── Toggle like ──
CommunityTopics.toggleLike = function(ev, type, key) {
    if (ev && ev.preventDefault) { ev.preventDefault(); ev.stopPropagation(); }
    if (!Community.currentUser) { CommunityAuth.openLoginModal('login'); return false; }
    // Resolve button from the event first, fall back to id lookup.
    var btn = (ev && (ev.currentTarget || ev.target)) || null;
    if (btn && btn.closest) btn = btn.closest('.like-btn') || btn;
    if (!btn || !btn.classList || !btn.classList.contains('like-btn')) {
        btn = document.getElementById('like-btn-' + type + '-' + key);
    }
    Community.api('/api/community-likes.php', {
        method: 'POST',
        body: { target_type: type, target_key: key }
    }).then(function(data) {
        if (!data || data.error || !btn) return;
        if (data.liked) btn.classList.add('liked');
        else btn.classList.remove('liked');
        var heart = btn.querySelector('.heart-icon');
        if (heart) heart.innerHTML = data.liked ? '&#9829;' : '&#9825;';
        var countEl = btn.querySelector('.like-count');
        if (countEl && typeof data.count === 'number') countEl.textContent = data.count;
    }).catch(function() {});
    return false;
};

// ── Toggle reaction ──
CommunityTopics.toggleReaction = function(type, key, code) {
    if (!Community.currentUser) { CommunityAuth.openLoginModal('login'); return; }
    Community.api('/api/community-reactions.php', {
        method: 'POST',
        body: { target_type: type, target_key: key, reaction_code: code }
    }).then(function() {
        var hash = window.location.hash;
        if (hash.indexOf('#topic/') === 0) {
            CommunityTopics.loadTopic(parseInt(hash.split('/')[1]));
        }
    }).catch(function() {});
};

// ── Watch ──
CommunityTopics.toggleWatch = function(topicKey) {
    if (!Community.currentUser) return;
    Community.api('/api/community-watch.php', {
        method: 'POST',
        body: { topic_key: topicKey }
    }).then(function() {
        CommunityTopics.loadTopic(topicKey);
    }).catch(function() {});
};

// ── Bookmark ──
CommunityTopics.restoreTopic = function(topicKey) {
    Community.api('/api/community-topics.php?topic=' + topicKey + '&type=topic', {
        method: 'DELETE',
        body: { action: 'restore' }
    }).then(function(d) {
        if (d.error) { alert(d.error); return; }
        CommunityTopics.loadTopics();
    });
};

CommunityTopics.toggleBookmark = function(topicKey) {
    if (!Community.currentUser) return;
    Community.api('/api/community-bookmarks.php', {
        method: 'POST',
        body: { topic_key: topicKey }
    }).then(function() {
        CommunityTopics.loadTopic(topicKey);
    }).catch(function() {});
};

// ── Bookmarks view ──
CommunityTopics.loadBookmarks = function() {
    Community.showView('view-bookmarks');
    var el = document.getElementById('view-bookmarks');
    if (!Community.currentUser) {
        el.innerHTML = '<div class="empty-state">Sign in to see your bookmarks.</div>';
        return;
    }
    el.innerHTML = '<div class="empty-state">Loading...</div>';
    Community.api('/api/community-bookmarks.php').then(function(data) {
        var topics = data.bookmarks || [];
        var html = '<h2 class="section-title">&#128278; Bookmarked Topics</h2>';
        if (!topics.length) {
            html += '<div class="empty-state">No bookmarks yet. Bookmark topics to save them here.</div>';
        } else {
            html += '<div class="topic-list">';
            for (var i = 0; i < topics.length; i++) {
                var t = topics[i];
                html += '<div class="topic-item" onclick="window.location.hash=\'#topic/' + t.topic_key + '\'">'
                    + Community.clickableAvatar(t.user_key, t.user_avatar, t.user_name_display, 'topic-avatar', t.user_last_active_dtime)
                    + '<div class="topic-info"><div class="topic-title">' + Community.esc(t.topic_title) + '</div>'
                    + '<div class="topic-meta">' + Community.esc(t.user_name_display || 'Anonymous') + ' &middot; ' + Community.timeAgo(t.topic_dtime) + (t.topic_last_reply_dtime ? ' &middot; last reply ' + Community.timeAgo(t.topic_last_reply_dtime) : '') + '</div></div>'
                    + '<div class="topic-stats">'
                    + '<div class="stat-item"><span class="stat-icon" style="' + (t.user_replied ? 'color:#31345A;' : '') + '">' + (t.user_replied ? '&#128172;' : '&#128173;') + '</span><span class="stat-num">' + (t.topic_reply_count || 0) + '</span></div>'
                    + '</div></div>';
            }
            html += '</div>';
        }
        el.innerHTML = html;
    });
};

// ── New topic form ──
CommunityTopics.showNewTopic = function() {
    if (!Community.currentUser) { CommunityAuth.openLoginModal('login'); return; }
    if (!Community.canPost()) { window.location.hash = '#topics'; return; } // banned: silent
    // If categories haven't arrived yet (community-categories.php currently
    // ~2s on prod, so an early click lands here with an empty cache), show a
    // loading state and fetch on demand instead of rendering an empty
    // <select>. The _catLoadAttempted guard prevents an infinite loop when
    // the API legitimately returns no categories.
    if ((!Community.categories || !Community.categories.length) && !CommunityTopics._catLoadAttempted) {
        CommunityTopics._catLoadAttempted = true;
        var parent = Community.currentSection === 'comments' ? 'comments' : 'topics';
        Community.api('/api/community-categories.php?parent=' + encodeURIComponent(parent))
            .then(function(data) {
                Community.categories = (data && data.categories) || [];
                CommunityTopics.showNewTopic();
            })
            .catch(function(){ CommunityTopics.showNewTopic(); });
        Community.showView('view-new');
        var elLoading = document.getElementById('view-new');
        if (elLoading) elLoading.innerHTML = '<div class="loading" style="padding:20px;color:#666;">Loading categories…</div>';
        return;
    }
    Community.showView('view-new');
    var el = document.getElementById('view-new');
    var cats = Community.categories || [];
    // Default the dropdown to the category the user is filtered into when they
    // click New Topic (so they don't have to re-pick it). When the filter is
    // "All", Community.currentCategory is falsy — leave the placeholder
    // "Select category" selected instead of guessing.
    var current = Community.currentCategory || '';
    var catOptions = '<option value="">Select category</option>';
    for (var i = 0; i < cats.length; i++) {
        var slug = cats[i].category_slug;
        var sel  = (current && slug === current) ? ' selected' : '';
        catOptions += '<option value="' + Community.esc(slug) + '"' + sel + '>' + Community.esc(cats[i].category_name) + '</option>';
    }

    var backHash = Community.currentSection === 'comments' ? '#comments' : '#topics';
    var backLabel = Community.currentSection === 'comments' ? 'Back to comments' : 'Back to topics';
    el.innerHTML = '<a href="' + backHash + '" class="back-link">&larr; ' + backLabel + '</a>'
        + '<div class="compose-form">'
        + '<h3 class="compose-title">New Topic</h3>'
        + '<input id="new-title" placeholder="Topic title">'
        + '<select id="new-category" class="form-select">' + catOptions + '</select>'
        + '<div id="new-body-editor"></div>'
        + '<div id="poll-section">'
        + '<label class="poll-toggle"><input type="checkbox" id="add-poll" onchange="CommunityTopics.togglePollForm()"> Add a poll</label>'
        + '<div id="poll-form" style="display:none">'
        + '<input id="poll-question" placeholder="Poll question">'
        + '<div id="poll-options"><input class="poll-option-input" placeholder="Option 1"><input class="poll-option-input" placeholder="Option 2"></div>'
        + '<button class="btn btn-outline btn-sm" onclick="CommunityTopics.addPollOption()">+ Add option</button>'
        + '</div></div>'
        + '<button class="btn btn-primary" onclick="CommunityTopics.submitTopic()">Create Topic</button>'
        + '</div>';

    CommunityTopics.createEditor('new-body-editor', 'new-body');
};

CommunityTopics.togglePollForm = function() {
    var checked = document.getElementById('add-poll').checked;
    document.getElementById('poll-form').style.display = checked ? '' : 'none';
};

CommunityTopics.addPollOption = function() {
    var container = document.getElementById('poll-options');
    var count = container.querySelectorAll('input').length + 1;
    var input = document.createElement('input');
    input.className = 'poll-option-input';
    input.placeholder = 'Option ' + count;
    container.appendChild(input);
};

CommunityTopics.submitTopic = function() {
    if (!Community.canPost()) return; // banned: silent
    var title = document.getElementById('new-title').value.trim();
    var bodyHtml = CommunityTopics.getEditorHtml('new-body');
    var body = CommunityTopics.getEditorText('new-body');
    var category = document.getElementById('new-category').value;
    if (!title) { alert('Title is required'); return; }

    var payload = { action: 'create_topic', title: title, body: body, topic_body_html: bodyHtml, category: category };

    // Poll data
    if (document.getElementById('add-poll') && document.getElementById('add-poll').checked) {
        var question = document.getElementById('poll-question').value.trim();
        var optInputs = document.querySelectorAll('.poll-option-input');
        var options = [];
        optInputs.forEach(function(inp) {
            var v = inp.value.trim();
            if (v) options.push(v);
        });
        if (question && options.length >= 2) {
            payload.poll = { question: question, options: options };
        }
    }

    Community.api('/api/community-topics.php', {
        method: 'POST',
        body: payload
    }).then(function(data) {
        if (data.topic_key) {
            // Create poll separately if needed
            if (payload.poll) {
                Community.api('/api/community-polls.php', {
                    method: 'POST',
                    body: { action: 'create', topic_key: data.topic_key, question: payload.poll.question, options: payload.poll.options }
                }).then(function() {
                    window.location.hash = '#topic/' + data.topic_key;
                }).catch(function() {
                    window.location.hash = '#topic/' + data.topic_key;
                });
            } else {
                window.location.hash = '#topic/' + data.topic_key;
            }
        } else {
            alert(data.error || 'Failed');
        }
    }).catch(function(err) {
        alert('Error posting topic: ' + (err && err.message ? err.message : 'Please try again'));
    });
};

// ── Submit reply ──
CommunityTopics.submitReply = function(topicKey) {
    if (!Community.canPost()) return; // banned: silent
    var bodyHtml = CommunityTopics.getEditorHtml('reply-body');
    var body = CommunityTopics.getEditorText('reply-body');

    // Check both plain text and HTML — media-only posts have no text
    var htmlEmpty = !bodyHtml || bodyHtml.replace(/<[^>]*>/g, '').trim() === '' && bodyHtml.indexOf('<img') === -1 && bodyHtml.indexOf('<video') === -1 && bodyHtml.indexOf('<iframe') === -1;
    if (!body.trim() && htmlEmpty) {
        alert('Please enter a reply');
        return;
    }

    // Disable button to prevent double-submit
    var btns = document.querySelectorAll('.compose-form .btn-primary');
    btns.forEach(function(b) { b.disabled = true; b.textContent = 'Sending...'; });

    Community.api('/api/community-topics.php', {
        method: 'POST',
        body: { action: 'reply', topic_key: topicKey, body: body || '(media)', reply_body_html: bodyHtml }
    }).then(function(data) {
        if (data.saved) {
            CommunityTopics.loadTopic(topicKey);
            setTimeout(function() {
                var replies = document.querySelectorAll('.reply-item');
                if (replies.length) replies[replies.length - 1].scrollIntoView({ behavior: 'smooth' });
            }, 600);
        } else {
            alert(data.error || 'Failed to post reply');
            btns.forEach(function(b) { b.disabled = false; b.textContent = 'Reply'; });
        }
    }).catch(function(err) {
        alert('Error posting reply: ' + (err.message || err));
        btns.forEach(function(b) { b.disabled = false; b.textContent = 'Reply'; });
    });
};

// ── Remove / Restore ──
CommunityTopics.toggleShowRemoved = function(checked, topicKey) {
    _showRemoved = checked;
    localStorage.setItem('mod_show_removed', checked ? 'true' : 'false');
    CommunityTopics.loadTopic(topicKey);
};

// ── Edit topic ──
CommunityTopics.editTopic = function(topicKey) {
    if (!Community.canPost()) return; // banned: silent
    // Fetch current topic data
    Community.api('/api/community-topics.php?topic=' + topicKey).then(function(data) {
        var t = data.topic;
        var el = document.getElementById('view-topic');
        var html = '<a href="#topic/' + topicKey + '" class="back-link" onclick="CommunityTopics.loadTopic(' + topicKey + ');return false;">&larr; Cancel</a>'
            + '<div class="compose-form">'
            + '<h3 class="compose-title">Edit Topic</h3>'
            + '<input id="edit-title" value="' + Community.esc(t.topic_title || '') + '" placeholder="Topic title">'
            + '<div id="edit-body-editor"></div>'
            + '<button class="btn btn-primary" onclick="CommunityTopics.saveEditTopic(' + topicKey + ')">Save Changes</button>'
            + '</div>';
        el.innerHTML = html;
        CommunityTopics.createEditor('edit-body-editor', 'edit-body');
        // Wait for TinyMCE to init, then set content
        var interval = setInterval(function() {
            var editor = (typeof tinymce !== 'undefined') ? tinymce.get('edit-body') : null;
            if (editor) {
                clearInterval(interval);
                editor.setContent(t.topic_body_html || Community.formatBody(t.topic_body || ''));
            }
        }, 100);
    });
};

CommunityTopics.saveEditTopic = function(topicKey) {
    if (!Community.canPost()) return; // banned: silent
    var title = document.getElementById('edit-title').value.trim();
    var bodyHtml = CommunityTopics.getEditorHtml('edit-body');
    var body = CommunityTopics.getEditorText('edit-body');
    if (!title) { alert('Title is required'); return; }
    Community.api('/api/community-topics.php', {
        method: 'POST',
        body: { action: 'edit_topic', topic_key: topicKey, title: title, body: body, topic_body_html: bodyHtml }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        CommunityTopics.loadTopic(topicKey);
    });
};

// ── Edit reply ──
CommunityTopics.editReply = function(replyKey, topicKey) {
    if (!Community.canPost()) return; // banned: silent
    Community.api('/api/community-topics.php?topic=' + topicKey).then(function(data) {
        var replies = data.replies || [];
        var r = null;
        for (var i = 0; i < replies.length; i++) {
            if (replies[i].reply_key === replyKey) { r = replies[i]; break; }
        }
        if (!r) { alert('Reply not found'); return; }

        var replyEl = document.getElementById('reply-' + replyKey);
        if (!replyEl) return;
        replyEl.innerHTML = '<div class="compose-form" style="margin:0;">'
            + '<div id="edit-reply-editor-' + replyKey + '"></div>'
            + '<div style="display:flex;gap:8px;justify-content:flex-end;">'
            + '<button class="btn btn-sm btn-outline" onclick="CommunityTopics.loadTopic(' + topicKey + ')">Cancel</button>'
            + '<button class="btn btn-sm btn-primary" onclick="CommunityTopics.saveEditReply(' + replyKey + ',' + topicKey + ')">Save</button>'
            + '</div></div>';
        CommunityTopics.createEditor('edit-reply-editor-' + replyKey, 'edit-reply-' + replyKey);
        var interval = setInterval(function() {
            var editor = (typeof tinymce !== 'undefined') ? tinymce.get('edit-reply-' + replyKey) : null;
            if (editor) {
                clearInterval(interval);
                editor.setContent(r.reply_body_html || Community.formatBody(r.reply_body || ''));
            }
        }, 100);
    });
};

CommunityTopics.saveEditReply = function(replyKey, topicKey) {
    if (!Community.canPost()) return; // banned: silent
    var bodyHtml = CommunityTopics.getEditorHtml('edit-reply-' + replyKey);
    var body = CommunityTopics.getEditorText('edit-reply-' + replyKey);
    if (!body.trim() && (!bodyHtml || bodyHtml.replace(/<[^>]*>/g, '').trim() === '')) { alert('Reply cannot be empty'); return; }
    Community.api('/api/community-topics.php', {
        method: 'POST',
        body: { action: 'edit_reply', reply_key: replyKey, body: body, reply_body_html: bodyHtml }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        CommunityTopics.loadTopic(topicKey);
    });
};

CommunityTopics.removeReply = function(replyKey, topicKey) {
    var note = prompt('Reason for removal (optional):');
    if (note === null) return; // cancelled
    fetch('/api/community-topics.php?type=reply&reply=' + replyKey, {
        method: 'DELETE', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ note: note })
    }).then(function() { CommunityTopics.loadTopic(topicKey); });
};

CommunityTopics.removeTopic = function(topicKey) {
    var note = prompt('Reason for removal (optional):');
    if (note === null) return;
    fetch('/api/community-topics.php?topic=' + topicKey + '&type=topic', {
        method: 'DELETE', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ note: note })
    }).then(function() { CommunityTopics.loadTopic(topicKey); });
};

CommunityTopics.restoreReply = function(replyKey, topicKey) {
    fetch('/api/community-topics.php?topic=' + topicKey, {
        method: 'PATCH', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'reply', reply_key: replyKey })
    }).then(function() { CommunityTopics.loadTopic(topicKey); });
};

CommunityTopics.restoreTopic = function(topicKey) {
    fetch('/api/community-topics.php?topic=' + topicKey, {
        method: 'PATCH', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'topic' })
    }).then(function() { CommunityTopics.loadTopic(topicKey); });
};

// ── Legacy delete (kept for compatibility) ──
CommunityTopics.deleteReply = function(replyKey, topicKey) {
    if (!confirm('Delete this reply?')) return;
    fetch('/api/community-topics.php?type=reply&reply=' + replyKey, { method: 'DELETE' })
        .then(function() { CommunityTopics.loadTopic(topicKey); });
};

// ── Report modal ──
CommunityTopics.openReport = function(type, key) {
    var reasons = ['Spam', 'Harassment', 'Inappropriate content', 'Off-topic', 'Other'];
    var html = '<div class="report-modal-inner">'
        + '<h3>Report ' + type + '</h3>'
        + '<select id="report-reason" class="form-select">';
    reasons.forEach(function(r) { html += '<option value="' + r + '">' + r + '</option>'; });
    html += '</select>'
        + '<textarea id="report-detail" placeholder="Additional details (optional)" rows="3"></textarea>'
        + '<div class="report-actions">'
        + '<button class="btn btn-primary" onclick="CommunityTopics.submitReport(\'' + type + '\',' + key + ')">Submit Report</button>'
        + '<button class="btn btn-outline" onclick="document.getElementById(\'report-modal\').classList.remove(\'active\')">Cancel</button>'
        + '</div></div>';

    var modal = document.getElementById('report-modal');
    modal.innerHTML = html;
    modal.classList.add('active');
};

CommunityTopics.submitReport = function(type, key) {
    var reason = document.getElementById('report-reason').value;
    var detail = document.getElementById('report-detail').value.trim();
    Community.api('/api/community-reports.php', {
        method: 'POST',
        body: { target_type: type, target_key: key, report_reason: reason, report_detail: detail }
    }).then(function(data) {
        document.getElementById('report-modal').classList.remove('active');
        if (data.error) alert(data.error);
        else alert('Report submitted. Thank you.');
    });
};

// ── Hide/Delete modal (moderators) ──
CommunityTopics.openHideModal = function(type, key, topicKey) {
    var html = '<div class="report-modal-inner">'
        + '<h3>Hide ' + type + '</h3>'
        + '<p style="font-size:0.85rem;color:#666;margin:0 0 12px;">This will remove the ' + type + ' from public view.</p>'
        + '<textarea id="hide-reason" placeholder="Reason (optional)" rows="3" style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.9rem;box-sizing:border-box;resize:vertical;"></textarea>'
        + '<div class="report-actions" style="margin-top:12px;">'
        + '<button class="btn btn-primary" onclick="CommunityTopics.submitHide(\'' + type + '\',' + key + ',' + (topicKey || key) + ')">Hide ' + type + '</button>'
        + '<button class="btn btn-outline" onclick="document.getElementById(\'report-modal\').classList.remove(\'active\')">Cancel</button>'
        + '</div></div>';

    var modal = document.getElementById('report-modal');
    modal.innerHTML = html;
    modal.classList.add('active');
};

CommunityTopics.submitHide = function(type, key, topicKey) {
    var reason = document.getElementById('hide-reason').value.trim();
    var url = '/api/community-topics.php?type=' + type;
    if (type === 'topic') url += '&topic=' + key;
    else url += '&reply=' + key;

    Community.api(url, {
        method: 'DELETE',
        body: { reason: reason }
    }).then(function(data) {
        document.getElementById('report-modal').classList.remove('active');
        if (data.error) { alert(data.error); return; }
        if (type === 'topic') {
            window.location.hash = '#topics';
        } else {
            CommunityTopics.loadTopic(topicKey);
        }
    });
};

// ── Ban modal (moderators) ──
CommunityTopics.openBanModal = function(targetUserKey, displayName) {
    // Don't allow banning yourself
    if (Community.currentUser && Community.currentUser.user_key === targetUserKey) {
        alert('You cannot ban yourself.');
        return;
    }

    var html = '<div class="report-modal-inner">'
        + '<h3>Ban User</h3>'
        + '<p style="font-size:0.85rem;color:#666;margin:0 0 12px;">Ban <strong>' + Community.esc(displayName) + '</strong> from the community.</p>'
        + '<div style="margin-bottom:12px;">'
        + '<label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:4px;">Duration</label>'
        + '<select id="ban-duration" style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.9rem;">'
        + '<option value="">Permanent</option>'
        + '<option value="1">1 hour</option>'
        + '<option value="24">24 hours</option>'
        + '<option value="168">7 days</option>'
        + '<option value="720">30 days</option>'
        + '</select></div>'
        + '<textarea id="ban-reason" placeholder="Reason (optional)" rows="3" style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.9rem;box-sizing:border-box;resize:vertical;"></textarea>'
        + '<div class="report-actions" style="margin-top:12px;">'
        + '<button class="btn btn-primary" style="background:#c0392b;" onclick="CommunityTopics.submitBan(' + targetUserKey + ')">Ban User</button>'
        + '<button class="btn btn-outline" onclick="document.getElementById(\'report-modal\').classList.remove(\'active\')">Cancel</button>'
        + '</div></div>';

    var modal = document.getElementById('report-modal');
    modal.innerHTML = html;
    modal.classList.add('active');
};

CommunityTopics.submitBan = function(targetUserKey) {
    var reason = document.getElementById('ban-reason').value.trim();
    var duration = document.getElementById('ban-duration').value;

    Community.api('/api/community-ban.php?user=' + targetUserKey, {
        method: 'POST',
        body: { action: 'ban', reason: reason, duration: duration || null }
    }).then(function(data) {
        document.getElementById('report-modal').classList.remove('active');
        if (data.error) { alert(data.error); return; }
        alert('User has been banned.');
    });
};

// ── Poll rendering ──
CommunityTopics.renderPoll = function(poll, topicKey) {
    if (!poll || !poll.question) return '';
    var options = poll.options || [];
    var totalVotes = 0;
    options.forEach(function(o) { totalVotes += (o.vote_count || 0); });
    var userVoted = poll.user_voted;

    var html = '<div class="poll-card">'
        + '<h4 class="poll-question">&#128202; ' + Community.esc(poll.question) + '</h4>';

    if (userVoted || !Community.currentUser) {
        // Show results
        options.forEach(function(o) {
            var pct = totalVotes > 0 ? Math.round((o.vote_count / totalVotes) * 100) : 0;
            html += '<div class="poll-result">'
                + '<div class="poll-result-bar" style="width:' + pct + '%"></div>'
                + '<span class="poll-result-label">' + Community.esc(o.option_text) + '</span>'
                + '<span class="poll-result-pct">' + pct + '% (' + (o.vote_count || 0) + ')</span>'
                + '</div>';
        });
    } else {
        // Show vote buttons
        options.forEach(function(o) {
            html += '<button class="poll-option-btn" onclick="CommunityTopics.votePoll(' + poll.poll_key + ',' + o.option_key + ',' + topicKey + ')">'
                + Community.esc(o.option_text) + '</button>';
        });
    }

    html += '<div class="poll-total">' + totalVotes + ' vote' + (totalVotes !== 1 ? 's' : '') + '</div>';
    html += '</div>';
    return html;
};

CommunityTopics.votePoll = function(pollKey, optionKey, topicKey) {
    if (!Community.currentUser) { CommunityAuth.openLoginModal('login'); return; }
    Community.api('/api/community-polls.php', {
        method: 'POST',
        body: { action: 'vote', poll_key: pollKey, option_key: optionKey }
    }).then(function() {
        CommunityTopics.loadTopic(topicKey);
    });
};

})();
