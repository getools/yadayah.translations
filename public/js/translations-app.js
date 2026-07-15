/* Translations module — reusable port of the /translations page logic
   (public/translations.html). Initializes against a root container so it can
   be embedded anywhere (e.g. a test/page.php custom section) without relying
   on document-global element IDs. Behaviour, API calls, and URL building are
   an exact duplicate of the live page:

     - /api/cite-books.php                     → Book dropdown
     - /api/display-chapters.php?cite_book_key → Chapter dropdown (cascading)
     - /api/display-verses.php?chapter_key     → Verse dropdown (cascading)
     - /api/display-translations.php           → results
     - /api/word-lookup.php (POST, |-joined)   → italic-word popups

   Auto-inits every element matching [data-translations-app] once the DOM is
   present. Idempotent per root (guarded by data-ta-init).

   Expected markup inside the root:
     .ta-cite (select), .ta-chapter (select), .ta-verse (select),
     .ta-result-count, .ta-initial-prompt, .ta-word-link-status,
     .ta-results (container). See the section markup for the canonical layout. */
(function () {
    'use strict';

    function initTranslationsApp(root) {
        if (!root || root.getAttribute('data-ta-init') === '1') return;
        root.setAttribute('data-ta-init', '1');

        // ── Per-instance state ──
        var wordInfoStore = {};
        var wordInfoCounter = 0;
        var initialLoad = true;

        var citeSel   = root.querySelector('.ta-cite');
        var chapterSel = root.querySelector('.ta-chapter');
        var verseSel  = root.querySelector('.ta-verse');
        var resultsDiv = root.querySelector('.ta-results');
        var countDiv  = root.querySelector('.ta-result-count');
        var promptEl  = root.querySelector('.ta-initial-prompt');
        var statusEl  = root.querySelector('.ta-word-link-status');
        if (!citeSel || !chapterSel || !verseSel || !resultsDiv) {
            console.error('[TranslationsApp] required elements missing in root', root);
            return;
        }

        function normalizeWord(text) {
            return text.replace(/[‘’′]/g, "'").replace(/[–—]/g, '-').trim();
        }

        function escHtml(str) {
            if (str == null) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // ── Load cite books ──
        async function loadCiteBooks() {
            try {
                var response = await fetch('/api/cite-books.php');
                var books = await response.json();
                books.forEach(function (book) {
                    var option = document.createElement('option');
                    option.value = book.cite_book_key;
                    option.textContent = book.cite_book_hebrew + ' / ' + book.cite_book_common;
                    citeSel.appendChild(option);
                });
                if (citeSel.options.length > 0) {
                    citeSel.selectedIndex = 0;
                    citeSel.dispatchEvent(new Event('change'));
                }
            } catch (err) {
                if (err.name !== 'AbortError') console.error('[loadCiteBooks] error:', err);
            }
        }

        // ── Cascading dropdowns: Book → Chapter → Verse ──
        citeSel.addEventListener('change', function () {
            chapterSel.innerHTML = '<option value="">All</option>';
            verseSel.innerHTML = '<option value="">All</option>';
            verseSel.disabled = true;

            var citeBookKey = this.value;
            if (!citeBookKey) {
                chapterSel.disabled = true;
                if (!initialLoad) fetchResults();
                return;
            }

            fetch('/api/display-chapters.php?cite_book_key=' + citeBookKey)
                .then(function (r) { return r.json(); })
                .then(function (chapters) {
                    chapters.forEach(function (ch) {
                        var opt = document.createElement('option');
                        opt.value = ch.cite_chapter_number;
                        opt.setAttribute('data-key', ch.cite_chapter_key);
                        opt.textContent = ch.cite_chapter_number;
                        chapterSel.appendChild(opt);
                    });
                    chapterSel.disabled = false;
                    if (initialLoad && chapterSel.options.length > 1) {
                        chapterSel.selectedIndex = 1;
                        chapterSel.dispatchEvent(new Event('change'));
                    } else if (!initialLoad) {
                        fetchResults();
                    }
                }).catch(function (err) { if (err.name !== 'AbortError') console.error('[chapters fetch] error:', err); });
        });

        chapterSel.addEventListener('change', function () {
            verseSel.innerHTML = '<option value="">All</option>';

            var selected = this.options[this.selectedIndex];
            var chapterKey = selected ? selected.getAttribute('data-key') : null;
            if (!chapterKey) {
                verseSel.disabled = true;
                return;
            }

            fetch('/api/display-verses.php?chapter_key=' + chapterKey)
                .then(function (r) { return r.json(); })
                .then(function (verses) {
                    verses.forEach(function (v) {
                        var opt = document.createElement('option');
                        opt.value = v.cite_verse_number;
                        opt.textContent = v.cite_verse_number;
                        verseSel.appendChild(opt);
                    });
                    verseSel.disabled = false;
                    if (initialLoad) {
                        initialLoad = false;
                        fetchResults();
                    } else {
                        fetchResults();
                    }
                }).catch(function (err) { if (err.name !== 'AbortError') console.error('[verses fetch] error:', err); });
        });

        verseSel.addEventListener('change', function () {
            if (!initialLoad) fetchResults();
        });

        // ── Fetch and display results ──
        async function fetchResults() {
            var citeId = citeSel.value;
            var chapter = chapterSel.value;
            var verse = verseSel.value;

            var params = new URLSearchParams();
            if (citeId) params.set('cite_book_key', citeId);
            if (chapter) params.set('chapter', chapter);
            if (verse) params.set('verse', verse);

            try {
                var response = await fetch('/api/display-translations.php?' + params);
                var records = await response.json();
                await displayResults(records);
            } catch (err) {
                console.error('[TranslationDisplay] fetchResults error:', err);
            }
        }

        async function displayResults(records) {
            resultsDiv.innerHTML = '';
            wordInfoStore = {};
            wordInfoCounter = 0;

            if (promptEl) promptEl.style.display = 'none';

            if (countDiv) countDiv.textContent = records.length + ' translation' + (records.length !== 1 ? 's' : '') + ' found';

            if (records.length === 0) {
                resultsDiv.textContent = 'No translations found.';
                if (statusEl) statusEl.textContent = '';
                return;
            }

            // Step 1: Collect all italic words from all records
            var wordsMap = {};
            records.forEach(function (record) {
                var html = record.translation_text_word || '';
                var re = /<i[^>]*>([^<]+)<\/i>/gi;
                var m;
                while ((m = re.exec(html)) !== null) {
                    var raw = m[1].trim();
                    if (!raw) continue;
                    var parts = normalizeWord(raw).split(/\s+/);
                    for (var j = 0; j < parts.length; j++) {
                        var w = parts[j].replace(/^[^a-zA-Z']+|[^a-zA-Z']+$/g, '');
                        if (w.length > 1) wordsMap[w.toLowerCase()] = true;
                    }
                }
            });

            var wordList = Object.keys(wordsMap);

            // Step 2: render translations IMMEDIATELY (no word lookup yet).
            // word-lookup.php takes 6+s; painting records first keeps the page
            // from sitting empty while the lookup runs.
            renderRecords(records, resultsDiv, null);

            if (wordList.length === 0) { if (statusEl) statusEl.textContent = ''; return; }
            if (statusEl) statusEl.textContent = 'Loading word definitions…';

            // Step 3: fetch word lookup, then decorate.
            try {
                var lookupResp = await fetch('/api/word-lookup.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'text/plain' },
                    body: wordList.join('|')
                });
                var lookupText = await lookupResp.text();
                var wordLookup = JSON.parse(lookupText);
                decorateWordLinks(resultsDiv, wordLookup, statusEl);
            } catch (err) {
                console.error('[TranslationDisplay] word-lookup failed:', err);
                if (statusEl) statusEl.textContent = '';
            }
        }

        // Walks each already-rendered <i> inside .record, tokenizes its text,
        // and for tokens that match a wordLookup key replaces them with a new
        // <i class="word-link" data-wid="…"> element with a click handler.
        function decorateWordLinks(resultsDiv, wordLookup, statusEl) {
            if (!wordLookup || typeof wordLookup !== 'object' || Array.isArray(wordLookup)) return;
            var italics = resultsDiv.querySelectorAll('.record i');
            var linkCount = 0;
            italics.forEach(function (oldI) {
                var text = oldI.textContent || '';
                if (!text.trim()) return;
                var tokens = text.split(/(\s+)/);
                var anyMatch = false;
                var newNodes = [];
                tokens.forEach(function (token) {
                    if (/^\s+$/.test(token) || !token) {
                        if (token) newNodes.push(document.createTextNode(token));
                        return;
                    }
                    var normalized = normalizeWord(token).toLowerCase();
                    var w = normalized.replace(/^[^a-zA-Z']+|[^a-zA-Z']+$/g, '');
                    if (w && wordLookup[w]) {
                        var wid = 'w' + (++wordInfoCounter);
                        wordInfoStore[wid] = wordLookup[w];
                        var span = document.createElement('i');
                        for (var a = 0; a < oldI.attributes.length; a++) {
                            span.setAttribute(oldI.attributes[a].name, oldI.attributes[a].value);
                        }
                        span.classList.add('word-link');
                        span.setAttribute('data-wid', wid);
                        span.textContent = token;
                        (function (capWid) {
                            span.addEventListener('click', function (e) {
                                e.preventDefault(); e.stopPropagation();
                                var info = wordInfoStore[capWid];
                                if (info) showWordPopup(info, this);
                            });
                        })(wid);
                        newNodes.push(span);
                        linkCount++;
                        anyMatch = true;
                    } else {
                        newNodes.push(document.createTextNode(token));
                    }
                });
                if (!anyMatch) return;
                var frag = document.createDocumentFragment();
                newNodes.forEach(function (n) { frag.appendChild(n); });
                oldI.parentNode.replaceChild(frag, oldI);
            });
            if (statusEl) {
                statusEl.textContent = linkCount > 0
                    ? linkCount + ' clickable word links found (click underlined italic words for details)'
                    : '';
            }
        }

        function renderRecords(records, resultsDiv, wordLookup) {
            var lastCiteBookId = null, lastChapter = null, lastVerse = null;

            records.forEach(function (record) {
                // Section header when cite_book_id, chapter, or verse changes
                if (lastCiteBookId !== record.translation_cite_book_key ||
                    lastChapter !== record.translation_cite_chapter ||
                    lastVerse !== record.translation_cite_verse) {
                    var breakDiv = document.createElement('div');
                    breakDiv.className = 'section-break';
                    var citeName = record.cite_book_hebrew
                        ? record.cite_book_hebrew + ' / ' + record.cite_book_common
                        : (record.translation_cite || 'Unknown');
                    var ref = '';
                    if (record.translation_cite_chapter != null) {
                        ref += ' ' + record.translation_cite_chapter;
                        if (record.translation_cite_verse != null) {
                            ref += ':' + record.translation_cite_verse;
                            if (record.translation_cite_verse_end != null) {
                                ref += '-' + record.translation_cite_verse_end;
                            }
                        }
                    }
                    breakDiv.textContent = citeName + ref;
                    resultsDiv.appendChild(breakDiv);
                    lastCiteBookId = record.translation_cite_book_key;
                    lastChapter = record.translation_cite_chapter;
                    lastVerse = record.translation_cite_verse;
                }

                var recordDiv = document.createElement('div');
                recordDiv.className = 'record';
                var bookName = record.translation_book.replace(/^YY-/i, '');
                var page = record.translation_page;
                var bookPage;
                var chapterName = record.chapter_name || '';
                var pageLabel = page ? ' - Page ' + page : '';
                if (record.translation_book) {
                    // Flipbook dirs strip apostrophes and turn spaces into
                    // hyphens — slugify to match. `p` = physical PDF page
                    // (translation_page already IS the physical page, no
                    // offset), `h` = paragraph_number for URL-driven highlight.
                    var slug = record.translation_book.replace(/['‘’]/g, '').replace(/\s+/g, '-');
                    var hashParts = [];
                    if (page) hashParts.push('p=' + page);
                    if (record.translation_paragraph) hashParts.push('h=' + record.translation_paragraph);
                    var url = '/' + encodeURIComponent(slug) + '/' + (hashParts.length ? '#' + hashParts.join('&') : '');
                    bookPage = '<a href="' + url + '" target="_blank"><strong>' + bookName + '</strong>' + (chapterName ? ' - ' + chapterName : '') + pageLabel + '</a>';
                } else {
                    bookPage = '<strong>' + bookName + '</strong>' + (chapterName ? ' - ' + chapterName : '') + pageLabel;
                }

                // Pre-embed word links into HTML before DOM insertion
                var html = record.translation_text_word || '';
                if (wordLookup && typeof wordLookup === 'object' && !Array.isArray(wordLookup)) {
                    html = html.replace(/<i([^>]*)>([^<]+)<\/i>/gi, function (full, attrs, text) {
                        var raw = text.trim();
                        if (!raw) return full;
                        var tokens = text.split(/(\s+)/);
                        var anyMatch = false;
                        var result = tokens.map(function (token) {
                            if (/^\s+$/.test(token)) return token;
                            var normalized = normalizeWord(token).toLowerCase();
                            var w = normalized.replace(/^[^a-zA-Z']+|[^a-zA-Z']+$/g, '');
                            if (w && wordLookup[w]) {
                                var wid = 'w' + (++wordInfoCounter);
                                wordInfoStore[wid] = wordLookup[w];
                                anyMatch = true;
                                return '<i' + attrs + ' class="word-link" data-wid="' + wid + '">' + token + '</i>';
                            }
                            return '<i' + attrs + '>' + token + '</i>';
                        }).join('');
                        if (anyMatch) return result;
                        return full;
                    });
                }

                // Convert <br> to <p> paragraph tags
                var paragraphs = html.split(/<br\s*\/?>/gi);
                if (paragraphs.length > 1) {
                    html = paragraphs.map(function (p) {
                        var trimmed = p.trim();
                        return trimmed ? '<p>' + trimmed + '</p>' : '';
                    }).join('');
                } else {
                    html = '<p>' + html + '</p>';
                }

                recordDiv.innerHTML = bookPage + ':' + html + '<span style="font-size:2pt;color:white"> ' + record.translation_id + '</span>';
                resultsDiv.appendChild(recordDiv);
            });
        }

        // ── Show word popup ──
        function showWordPopup(info, anchorEl) {
            closeWordPopup();
            var rect = anchorEl.getBoundingClientRect();

            var html = '';
            var hasLeft = info.word_spellings && info.word_spellings.length > 0;
            var hasRight = info.word_yt || info.word_hebrew;
            if (hasLeft || hasRight) {
                html += '<div class="wp-top">';
                html += '<div class="wp-top-left">';
                if (hasLeft) {
                    html += '<div class="wp-spellings">' + info.word_spellings.map(function (s) { return '<div>' + escHtml(s) + '</div>'; }).join('') + '</div>';
                }
                html += '</div>';
                html += '<div class="wp-top-right">';
                if (info.word_yt) {
                    html += '<div class="wp-yt">' + escHtml(info.word_yt.split('').reverse().join('')) + '</div>';
                }
                if (info.word_hebrew) {
                    html += '<div class="wp-hebrew">' + escHtml(info.word_hebrew) + '</div>';
                }
                html += '</div>';
                html += '</div>';
            }
            if (info.word_strongs) {
                html += '<div class="wp-strongs"><a href="http://lexiconcordance.com/hebrew/' + encodeURIComponent(info.word_strongs.trim()) + '.html" target="_blank">' + escHtml(info.word_strongs.trim()) + '</a></div>';
            }
            if (info.definitions && info.definitions.length > 0) {
                html += '<hr>';
                for (var di = 0; di < info.definitions.length; di++) {
                    var d = info.definitions[di];
                    if (d.pos_label) {
                        var grammarNote = d.pos_label;
                        if (d.gender_label) grammarNote += ' (' + d.gender_label + ')';
                        if (d.plural_flag) grammarNote += ' (plural)';
                        html += '<div class="wp-pos-label">' + escHtml(grammarNote) + '</div>';
                    }
                    if (d.text) {
                        html += '<div class="wp-definition">' + escHtml(d.text) + '</div>';
                    }
                }
            }

            var overlay = document.createElement('div');
            overlay.className = 'word-popup-overlay';
            overlay.id = 'word-popup-overlay';
            overlay.addEventListener('click', closeWordPopup);
            document.body.appendChild(overlay);

            var popup = document.createElement('div');
            popup.className = 'word-popup';
            popup.id = 'word-popup';
            popup.innerHTML = html;
            document.body.appendChild(popup);

            var popupH = popup.offsetHeight;
            var popupW = popup.offsetWidth;
            var top = rect.bottom + 6;
            var left = rect.left;
            if (top + popupH > window.innerHeight) top = rect.top - popupH - 6;
            if (left + popupW > window.innerWidth) left = window.innerWidth - popupW - 10;
            if (left < 10) left = 10;
            popup.style.top = top + 'px';
            popup.style.left = left + 'px';
        }

        function closeWordPopup() {
            var popup = document.getElementById('word-popup');
            var overlay = document.getElementById('word-popup-overlay');
            if (popup) popup.remove();
            if (overlay) overlay.remove();
        }

        // ── Init ──
        loadCiteBooks();
    }

    function initAll() {
        var roots = document.querySelectorAll('[data-translations-app]');
        for (var i = 0; i < roots.length; i++) initTranslationsApp(roots[i]);
    }

    // Expose for manual init if a host wants to call it directly.
    window.initTranslationsApp = initTranslationsApp;

    // The custom-section loader re-executes this <script> after the markup is
    // already in the DOM, so the roots exist by the time we run. Guard for the
    // (unlikely) case the script somehow runs before DOM parse.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
