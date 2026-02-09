$(function () {
    // ── State ──
    var currentScrollKey = null;
    var currentChapterKey = null;
    var currentVerseKey = null;
    var editingTranslationKey = null;
    var editorReady = false;
    var allTranslations = [];
    var listPrefs = { sorts: [], filters: {} };
    var pendingEdit = null;

    // ════════════════════════════════════════════
    // AUTH
    // ════════════════════════════════════════════
    function checkAuth() {
        $.getJSON('/api/auth.php').done(function (data) {
            if (data.authenticated) {
                showApp(data);
            } else {
                showLogin();
            }
        }).fail(function () {
            showLogin();
        });
    }

    function showLogin() {
        $('#app').hide();
        $('#login-screen').show();
        $('#login-user').focus();
    }

    function showApp(user) {
        $('#login-screen').hide();
        $('#user-display').text(user.user_name || user.user_code);
        $('#app').show();
        initApp();
    }

    $('#btn-login').on('click', function () { doLogin(); });
    $('#login-pass').on('keypress', function (e) { if (e.which === 13) doLogin(); });
    $('#login-user').on('keypress', function (e) { if (e.which === 13) $('#login-pass').focus(); });

    function doLogin() {
        var login = $('#login-user').val().trim();
        var pass = $('#login-pass').val();
        if (!login || !pass) {
            $('#login-error').text('Please enter login and password.').show();
            return;
        }
        $.ajax({
            url: '/api/auth.php', method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ login: login, password: pass })
        }).done(function (data) {
            if (data.authenticated) {
                $('#login-error').hide();
                showApp(data);
            }
        }).fail(function (xhr) {
            var msg = 'Login failed.';
            try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
            $('#login-error').text(msg).show();
        });
    }

    $('#btn-logout').on('click', function () {
        $.ajax({ url: '/api/auth.php', method: 'DELETE' }).always(function () {
            showLogin();
        });
    });

    // ════════════════════════════════════════════
    // API HELPER
    // ════════════════════════════════════════════
    function apiCall(url, method, data) {
        var opts = {
            url: '/api/' + url,
            method: method || 'GET',
            dataType: 'json',
            contentType: 'application/json'
        };
        if (data && (method === 'POST' || method === 'PUT')) {
            opts.data = JSON.stringify(data);
        }
        return $.ajax(opts).fail(function (xhr) {
            if (xhr.status === 401) { showLogin(); return; }
            var msg = 'An error occurred.';
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.error) msg = resp.error;
                if (resp.errors) msg = resp.errors.join('\n');
            } catch (e) {}
            alert('Error: ' + msg);
        });
    }

    // ════════════════════════════════════════════
    // TABS
    // ════════════════════════════════════════════
    $('.tab-btn').on('click', function () {
        var tab = $(this).data('tab');
        switchTab(tab);
    });

    function switchTab(tab) {
        $('.tab-btn').removeClass('active');
        $('.tab-btn[data-tab="' + tab + '"]').addClass('active');
        $('.tab-content').removeClass('active');
        $('#tab-' + tab).addClass('active');
        if (tab === 'list') loadAllTranslations();
    }

    // ════════════════════════════════════════════
    // SELECT HELPERS
    // ════════════════════════════════════════════
    function populateSelect($sel, items, valueField, textFn) {
        $sel.empty().append('<option value="">-- Select --</option>');
        $.each(items, function (_, item) {
            $sel.append($('<option>').val(item[valueField]).text(textFn(item)));
        });
        $sel.prop('disabled', false);
    }

    function resetSelect($sel) {
        $sel.empty().append('<option value="">-- Select --</option>').prop('disabled', true);
    }

    // ════════════════════════════════════════════
    // TINYMCE INIT
    // ════════════════════════════════════════════
    var editorInitialized = false;
    function initEditor() {
        if (editorInitialized) return;
        editorInitialized = true;
        tinymce.init({
            selector: '#translation-text',
            base_url: '/js/tinymce',
            suffix: '.min',
            height: 350,
            menubar: false,
            plugins: 'lists link charmap searchreplace wordcount code fullscreen',
            toolbar: 'styles | bold italic underline | fontfamily fontsize | forecolor | alignleft aligncenter alignright | bullist numlist | charmap code fullscreen',
            style_formats: [
                { title: 'Main', inline: 'span', classes: 'main' },
                { title: 'Word', inline: 'span', classes: 'word' },
                { title: 'Definition', inline: 'span', classes: 'def' },
                { title: 'Note', inline: 'span', classes: 'note' }
            ],
            content_style: [
                "body { font-family: 'Times New Roman', Times, serif; font-size: 14px; }",
                "span.main { font-weight: bold; color: #1a5276; }",
                "span.word { font-style: italic; color: #7d3c98; }",
                "span.def { color: #333; }",
                "span.note { color: #e67e22; background: #fef9e7; padding: 0 2px; }"
            ].join('\n'),
            entity_encoding: 'raw',
            paste_as_text: false,
            paste_retain_style_properties: 'color,font-size,font-family,font-weight,font-style,text-decoration',
            paste_postprocess: function (editor, args) {
                processPastedContent(args.node);
            },
            charmap_append: [
                [0x2014, 'Em Dash'], [0x2013, 'En Dash'],
                [0x2018, 'Left Single Quote'], [0x2019, 'Right Single Quote'],
                [0x201C, 'Left Double Quote'], [0x201D, 'Right Double Quote'],
                [0x2026, 'Ellipsis']
            ],
            setup: function (editor) {
                editor.on('init', function () { editorReady = true; });
            }
        });
    }

    function processPastedContent(node) {
        // Handle inline-style bold (Word paste)
        $(node).find('[style]').each(function () {
            var style = ($(this).attr('style') || '').toLowerCase();
            if (/font-weight\s*:\s*(bold|[6-9]00)/.test(style)) {
                var s = document.createElement('span');
                s.className = 'main';
                s.innerHTML = this.innerHTML;
                this.parentNode.replaceChild(s, this);
            } else if (/font-style\s*:\s*italic/.test(style)) {
                var s2 = document.createElement('span');
                s2.className = 'word';
                s2.innerHTML = this.innerHTML;
                this.parentNode.replaceChild(s2, this);
            }
        });
        // Handle semantic tags
        $(node).find('strong, b').each(function () {
            var s = document.createElement('span');
            s.className = 'main';
            s.innerHTML = this.innerHTML;
            this.parentNode.replaceChild(s, this);
        });
        $(node).find('em, i').each(function () {
            var s = document.createElement('span');
            s.className = 'word';
            s.innerHTML = this.innerHTML;
            this.parentNode.replaceChild(s, this);
        });
        // Wrap remaining bare text nodes in span.def
        wrapTextInDef(node);
    }

    function wrapTextInDef(el) {
        var children = Array.prototype.slice.call(el.childNodes);
        for (var i = 0; i < children.length; i++) {
            var child = children[i];
            if (child.nodeType === 3 && child.textContent.trim()) {
                // Check if parent is already a classed span
                if (child.parentNode && child.parentNode.className &&
                    /\b(main|word|def|note)\b/.test(child.parentNode.className)) continue;
                var span = document.createElement('span');
                span.className = 'def';
                child.parentNode.insertBefore(span, child);
                span.appendChild(child);
            } else if (child.nodeType === 1) {
                var tag = child.tagName.toLowerCase();
                if (tag === 'p' || tag === 'div' || tag === 'li' || tag === 'td' || tag === 'th') {
                    wrapTextInDef(child);
                } else if (tag === 'span' && !child.className) {
                    child.className = 'def';
                }
            }
        }
    }

    // ════════════════════════════════════════════
    // INIT APP (called after auth)
    // ════════════════════════════════════════════
    var appInitialized = false;
    function initApp() {
        if (appInitialized) return;
        appInitialized = true;
        initEditor();
        // Load scrolls
        apiCall('scrolls.php').done(function (data) {
            populateSelect($('#scroll'), data, 'yah_scroll_key', function (s) {
                return s.yah_scroll_label_yy + ' / ' + s.yah_scroll_label_common;
            });
        });
        // Load series
        apiCall('series.php').done(function (data) {
            populateSelect($('#series'), data, 'yy_series_key', function (s) {
                return s.yy_series_name;
            });
        });
        // Load list preferences
        loadPreferences();
    }

    // ════════════════════════════════════════════
    // SCRIPTURE CASCADE: Scroll → Chapter → Verse
    // ════════════════════════════════════════════
    function loadChaptersForScroll(scrollKey, cb) {
        apiCall('chapters.php?scroll_key=' + scrollKey).done(function (data) {
            populateSelect($('#chapter'), data, 'yah_chapter_key', function (c) {
                return c.yah_chapter_number;
            });
            if (cb) cb();
        });
    }

    function loadVersesForChapter(chapterKey, cb) {
        apiCall('verses.php?chapter_key=' + chapterKey).done(function (data) {
            populateSelect($('#verse'), data, 'yah_verse_key', function (v) {
                return v.yah_verse_number;
            });
            if (cb) cb();
        });
    }

    $('#scroll').on('change', function () {
        var key = $(this).val();
        currentScrollKey = key || null;
        currentChapterKey = null;
        currentVerseKey = null;
        resetSelect($('#chapter'));
        resetSelect($('#verse'));
        hideTranslations();
        hideForm();
        if (key) loadChaptersForScroll(key);
    });

    $('#chapter').on('change', function () {
        var key = $(this).val();
        currentChapterKey = key || null;
        currentVerseKey = null;
        resetSelect($('#verse'));
        hideTranslations();
        hideForm();
        if (key) loadVersesForChapter(key);
    });

    $('#verse').on('change', function () {
        var key = $(this).val();
        currentVerseKey = key || null;
        hideForm();
        if (key) {
            loadTranslations();
        } else {
            hideTranslations();
        }
    });

    // ════════════════════════════════════════════
    // YY CASCADE: Series → Volume → YY Chapter
    // ════════════════════════════════════════════
    function loadVolumesForSeries(seriesKey, cb) {
        apiCall('volumes.php?series_key=' + seriesKey).done(function (data) {
            populateSelect($('#volume'), data, 'yy_volume_key', function (v) {
                return v.display_text;
            });
            if (cb) cb();
        });
    }

    function loadYYChaptersForVolume(volumeKey, cb) {
        apiCall('yy-chapters.php?volume_key=' + volumeKey).done(function (data) {
            populateSelect($('#yy-chapter'), data, 'yy_chapter_key', function (c) {
                var text = String(c.yy_chapter_number);
                if (c.yy_chapter_name) text += ' - ' + c.yy_chapter_name;
                return text;
            });
            if (cb) cb();
        });
    }

    $('#series').on('change', function () {
        var key = $(this).val();
        resetSelect($('#volume'));
        resetSelect($('#yy-chapter'));
        if (key) loadVolumesForSeries(key);
    });

    $('#volume').on('change', function () {
        var key = $(this).val();
        resetSelect($('#yy-chapter'));
        if (key) loadYYChaptersForVolume(key);
    });

    // ════════════════════════════════════════════
    // TRANSLATIONS LIST (verse view)
    // ════════════════════════════════════════════
    function loadTranslations() {
        if (!currentVerseKey) return;
        var scrollText = $('#scroll option:selected').text();
        var chapterText = $('#chapter option:selected').text();
        var verseText = $('#verse option:selected').text();
        $('#translations-title').text('Translations for ' + scrollText + ' ' + chapterText + ':' + verseText);

        apiCall('translations.php?verse_key=' + currentVerseKey).done(function (data) {
            renderTranslationCards(data);
            $('#translations-section').show();
            if (pendingEdit) {
                var key = pendingEdit;
                pendingEdit = null;
                var t = null;
                $.each(data, function (_, d) { if (d.yy_translation_key == key) { t = d; return false; } });
                if (t) showForm(t);
            }
        });
    }

    function renderTranslationCards(translations) {
        var $list = $('#translations-list').empty();
        if (!translations || translations.length === 0) {
            $list.html('<div class="no-translations">No translations for this verse yet.</div>');
            return;
        }
        $.each(translations, function (_, t) {
            var meta = '<span><strong>Series:</strong> ' + escHtml(t.yy_series_name) + '</span>' +
                '<span><strong>Volume:</strong> ' + escHtml(t.yy_volume_number + ' - ' + t.yy_volume_name) + '</span>' +
                '<span><strong>Ch:</strong> ' + escHtml(t.yy_ch_number + (t.yy_ch_name ? ' - ' + t.yy_ch_name : '')) + '</span>';
            if (t.yy_translation_page) meta += '<span><strong>Pg:</strong> ' + escHtml(t.yy_translation_page) + '</span>';
            if (t.yy_translation_paragraph) meta += '<span><strong>Par:</strong> ' + escHtml(t.yy_translation_paragraph) + '</span>';
            if (t.yy_translation_date) meta += '<span><strong>Date:</strong> ' + escHtml(t.yy_translation_date) + '</span>';
            var card = '<div class="translation-card" data-key="' + t.yy_translation_key + '">' +
                '<div class="translation-meta">' + meta + '</div>' +
                '<div class="translation-preview">' + (t.yy_translation_copy || '') + '</div>' +
                '<div class="translation-actions">' +
                '<button class="btn btn-primary btn-sm btn-edit">Edit</button>' +
                '<button class="btn btn-danger btn-sm btn-delete">Delete</button>' +
                '</div></div>';
            $list.append(card);
        });
    }

    function hideTranslations() {
        $('#translations-section').hide();
        $('#translations-list').empty();
    }

    // ════════════════════════════════════════════
    // TRANSLATION FORM
    // ════════════════════════════════════════════
    function showForm(translation) {
        editingTranslationKey = translation ? translation.yy_translation_key : null;
        $('#editing-key').val(editingTranslationKey || '');
        $('#form-title').text(editingTranslationKey ? 'Edit Translation' : 'New Translation');
        hideErrors();

        if (translation) {
            $('#series').val(translation.yy_series_key);
            loadVolumesForSeries(translation.yy_series_key, function () {
                $('#volume').val(translation.yy_volume_key);
                loadYYChaptersForVolume(translation.yy_volume_key, function () {
                    $('#yy-chapter').val(translation.yy_chapter_key);
                });
            });
            $('#page').val(translation.yy_translation_page || '');
            $('#paragraph').val(translation.yy_translation_paragraph || '');
            $('#translation-date').val(translation.yy_translation_date || '');
            $('#sort').val(translation.yy_translation_sort != null ? translation.yy_translation_sort : 0);
            setEditorContent(translation.yy_translation_copy || '');
        } else {
            $('#series').val('');
            resetSelect($('#volume'));
            resetSelect($('#yy-chapter'));
            $('#page').val('');
            $('#paragraph').val('');
            $('#translation-date').val('');
            $('#sort').val('0');
            setEditorContent('');
        }
        $('#form-section').show();
        $('html, body').animate({ scrollTop: $('#form-section').offset().top - 20 }, 300);
    }

    function hideForm() {
        $('#form-section').hide();
        editingTranslationKey = null;
        hideErrors();
    }

    function setEditorContent(html) {
        if (editorReady && tinymce.get('translation-text')) {
            tinymce.get('translation-text').setContent(html);
        }
    }
    function getEditorContent() {
        if (editorReady && tinymce.get('translation-text')) {
            return tinymce.get('translation-text').getContent();
        }
        return '';
    }

    // ── Validation ──
    function validateForm() {
        var errors = [];
        if (!currentScrollKey) errors.push('Scroll is required.');
        if (!currentChapterKey) errors.push('Chapter is required.');
        if (!currentVerseKey) errors.push('Verse is required.');
        if (!$('#series').val()) errors.push('Series is required.');
        if (!$('#volume').val()) errors.push('Volume is required.');
        if (!$('#yy-chapter').val()) errors.push('YY Chapter is required.');
        var text = getEditorContent();
        if (!$('<div>').html(text).text().trim()) errors.push('Translation text is required.');
        var page = $('#page').val();
        if (page !== '' && (isNaN(page) || parseInt(page) < 1)) errors.push('Page must be a positive integer.');
        var para = $('#paragraph').val();
        if (para !== '' && (isNaN(para) || parseInt(para) < 1)) errors.push('Paragraph must be a positive integer.');
        var dateVal = $('#translation-date').val();
        if (dateVal && !/^\d{4}-\d{2}-\d{2}$/.test(dateVal)) errors.push('Date must be in YYYY-MM-DD format.');
        var sortVal = $('#sort').val();
        if (sortVal !== '' && (isNaN(sortVal) || parseInt(sortVal) < 0)) errors.push('Sort must be a non-negative integer.');
        return errors;
    }

    function showErrors(errors) {
        var $el = $('#form-errors');
        $el.html('<ul>' + errors.map(function (e) { return '<li>' + escHtml(e) + '</li>'; }).join('') + '</ul>').show();
        $('html, body').animate({ scrollTop: $el.offset().top - 20 }, 300);
    }
    function hideErrors() { $('#form-errors').hide().empty(); }

    // ── Save ──
    function saveTranslation() {
        if (tinymce.get('translation-text')) tinymce.get('translation-text').save();
        var errors = validateForm();
        if (errors.length > 0) { showErrors(errors); return; }

        var data = {
            yah_scroll_key: parseInt(currentScrollKey),
            yah_chapter_key: parseInt(currentChapterKey),
            yah_verse_key: parseInt(currentVerseKey),
            yy_series_key: parseInt($('#series').val()),
            yy_volume_key: parseInt($('#volume').val()),
            yy_chapter_key: parseInt($('#yy-chapter').val()),
            yy_translation_page: $('#page').val() || null,
            yy_translation_paragraph: $('#paragraph').val() || null,
            yy_translation_copy: getEditorContent(),
            yy_translation_date: $('#translation-date').val() || null,
            yy_translation_sort: $('#sort').val() !== '' ? parseInt($('#sort').val()) : 0
        };

        if (editingTranslationKey) {
            apiCall('translations.php?key=' + editingTranslationKey, 'PUT', data).done(function () {
                hideForm();
                loadTranslations();
            });
        } else {
            apiCall('translations.php', 'POST', data).done(function () {
                hideForm();
                loadTranslations();
            });
        }
    }

    // ── Event handlers ──
    $('#btn-add').on('click', function () { showForm(null); });
    $('#btn-save').on('click', function () { saveTranslation(); });
    $('#btn-cancel').on('click', function () { hideForm(); });

    $('#translations-list').on('click', '.btn-edit', function () {
        var key = $(this).closest('.translation-card').data('key');
        apiCall('translations.php?verse_key=' + currentVerseKey).done(function (data) {
            var t = null;
            $.each(data, function (_, d) { if (d.yy_translation_key == key) { t = d; return false; } });
            if (t) showForm(t);
        });
    });

    $('#translations-list').on('click', '.btn-delete', function () {
        var key = $(this).closest('.translation-card').data('key');
        if (confirm('Are you sure you want to delete this translation?')) {
            apiCall('translations.php?key=' + key, 'DELETE').done(function () {
                loadTranslations();
                if (editingTranslationKey == key) hideForm();
            });
        }
    });

    // ════════════════════════════════════════════
    // TRANSLATIONS TABLE (list tab)
    // ════════════════════════════════════════════
    function loadAllTranslations() {
        apiCall('translations.php?list=all').done(function (data) {
            allTranslations = data || [];
            applyPrefsToUI();
            renderTable();
        });
    }

    function renderTable() {
        var filtered = filterData(allTranslations);
        var sorted = sortData(filtered);
        var $tbody = $('#translations-tbody').empty();
        if (sorted.length === 0) {
            $tbody.empty();
            $('#list-empty').show();
            return;
        }
        $('#list-empty').hide();
        $.each(sorted, function (_, t) {
            var row = '<tr data-key="' + t.yy_translation_key + '">' +
                '<td>' + escHtml(t.yah_scroll_label_yy) + '</td>' +
                '<td>' + escHtml(t.yah_scroll_label_common) + '</td>' +
                '<td>' + escHtml(t.yah_chapter_number) + '</td>' +
                '<td>' + escHtml(t.yah_verse_number) + '</td>' +
                '<td>' + escHtml(t.series_display) + '</td>' +
                '<td>' + escHtml(t.volume_display) + '</td>' +
                '<td>' + escHtml(t.yy_translation_page || '') + '</td>' +
                '<td>' + escHtml(t.yy_ch_number) + '</td>' +
                '<td><button class="btn btn-primary btn-sm btn-list-edit">Edit</button> ' +
                '<button class="btn btn-danger btn-sm btn-list-delete">Delete</button></td></tr>';
            $tbody.append(row);
        });
    }

    function filterData(data) {
        var filters = {};
        $('.col-filter').each(function () {
            var col = $(this).data('col');
            var val = $(this).val().trim().toLowerCase();
            if (val) filters[col] = val;
        });
        if ($.isEmptyObject(filters)) return data;
        return data.filter(function (row) {
            for (var col in filters) {
                var cellVal = String(row[col] == null ? '' : row[col]).toLowerCase();
                if (cellVal.indexOf(filters[col]) === -1) return false;
            }
            return true;
        });
    }

    function sortData(data) {
        if (!listPrefs.sorts || listPrefs.sorts.length === 0) return data;
        var sorts = listPrefs.sorts;
        return data.slice().sort(function (a, b) {
            for (var i = 0; i < sorts.length; i++) {
                var col = sorts[i].col;
                var dir = sorts[i].dir === 'desc' ? -1 : 1;
                var va = a[col], vb = b[col];
                if (va == null) va = '';
                if (vb == null) vb = '';
                var na = Number(va), nb = Number(vb);
                if (!isNaN(na) && !isNaN(nb) && va !== '' && vb !== '') {
                    if (na < nb) return -1 * dir;
                    if (na > nb) return 1 * dir;
                } else {
                    var sa = String(va).toLowerCase(), sb = String(vb).toLowerCase();
                    if (sa < sb) return -1 * dir;
                    if (sa > sb) return 1 * dir;
                }
            }
            return 0;
        });
    }

    // Column header sort
    $(document).on('click', '.sortable', function () {
        var col = $(this).data('sort-key') || $(this).data('col');
        var currentDir = null;
        if (listPrefs.sorts && listPrefs.sorts.length === 1 && listPrefs.sorts[0].col === col) {
            currentDir = listPrefs.sorts[0].dir;
        }
        var newDir = currentDir === 'asc' ? 'desc' : 'asc';
        listPrefs.sorts = [{ col: col, dir: newDir }];
        updateSortIndicators();
        renderTable();
        savePreferences();
    });

    function updateSortIndicators() {
        $('.sortable').removeClass('sort-asc sort-desc');
        if (listPrefs.sorts && listPrefs.sorts.length === 1) {
            var s = listPrefs.sorts[0];
            $('.sortable').each(function () {
                var sortKey = $(this).data('sort-key') || $(this).data('col');
                if (sortKey === s.col) {
                    $(this).addClass(s.dir === 'desc' ? 'sort-desc' : 'sort-asc');
                }
            });
        }
    }

    // Column filters
    var filterTimer;
    $(document).on('input', '.col-filter', function () {
        clearTimeout(filterTimer);
        filterTimer = setTimeout(function () {
            // Save filter state
            listPrefs.filters = {};
            $('.col-filter').each(function () {
                var val = $(this).val().trim();
                if (val) listPrefs.filters[$(this).data('col')] = val;
            });
            renderTable();
            savePreferences();
        }, 300);
    });

    // Edit from list
    $(document).on('click', '.btn-list-edit', function (e) {
        e.stopPropagation();
        var key = $(this).closest('tr').data('key');
        editFromList(key);
    });

    // Delete from list
    $(document).on('click', '.btn-list-delete', function (e) {
        e.stopPropagation();
        var key = $(this).closest('tr').data('key');
        if (confirm('Are you sure you want to delete this translation?')) {
            apiCall('translations.php?key=' + key, 'DELETE').done(function () {
                loadAllTranslations();
            });
        }
    });

    // Row click also edits
    $(document).on('click', '#translations-tbody tr', function (e) {
        if ($(e.target).is('button')) return;
        var key = $(this).data('key');
        editFromList(key);
    });

    function editFromList(translationKey) {
        apiCall('translations.php?translation_key=' + translationKey).done(function (t) {
            // Switch to entry tab
            switchTab('entry');
            // Set scripture cascade manually
            currentScrollKey = t.yah_scroll_key;
            currentChapterKey = t.yah_chapter_key;
            currentVerseKey = t.yah_verse_key;
            pendingEdit = t.yy_translation_key;

            $('#scroll').val(t.yah_scroll_key);
            loadChaptersForScroll(t.yah_scroll_key, function () {
                $('#chapter').val(t.yah_chapter_key);
                loadVersesForChapter(t.yah_chapter_key, function () {
                    $('#verse').val(t.yah_verse_key);
                    loadTranslations();
                });
            });
        });
    }

    // ════════════════════════════════════════════
    // USER PREFERENCES
    // ════════════════════════════════════════════
    function loadPreferences() {
        apiCall('preferences.php?name=translations_list').done(function (data) {
            if (data.value) {
                listPrefs = data.value;
                if (!listPrefs.sorts) listPrefs.sorts = [];
                if (!listPrefs.filters) listPrefs.filters = {};
            }
        });
    }

    function applyPrefsToUI() {
        // Apply saved filters to inputs
        if (listPrefs.filters) {
            $.each(listPrefs.filters, function (col, val) {
                $('.col-filter[data-col="' + col + '"]').val(val);
            });
        }
        updateSortIndicators();
    }

    function savePreferences() {
        apiCall('preferences.php', 'POST', {
            name: 'translations_list',
            value: listPrefs
        });
    }

    // ════════════════════════════════════════════
    // UTILITY
    // ════════════════════════════════════════════
    function escHtml(str) {
        if (str == null) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ════════════════════════════════════════════
    // START
    // ════════════════════════════════════════════
    checkAuth();
});
