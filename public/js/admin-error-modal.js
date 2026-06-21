/* admin-error-modal.js — shared full-message error/notice dialog for admin pages.
 *
 * Problem it solves: errors were surfaced via native alert() and tiny status
 * spans, both of which truncate or clip long messages (stack traces, engine
 * 500 bodies, SQL errors), so the operator could never read or copy the whole
 * thing. This renders the FULL message inside a read-only, selectable,
 * scrollable <textarea> in a modal, with a Copy button.
 *
 * Public API:
 *   window.adminShowError(message, opts)
 *     message : string | Error | anything (coerced)
 *     opts    : { title?: string }   // optional dialog heading
 *
 * Side effect: window.alert is routed through this dialog so every existing
 * alert() across the admin pages gets the full-message treatment with no
 * per-page edits. The native implementation is kept as window.__nativeAlert.
 */
(function () {
'use strict';

if (window.adminShowError) return; // already loaded

// ---- one-time CSS -----------------------------------------------------------
if (!document.getElementById('admin-error-modal-css')) {
    var css = document.createElement('style');
    css.id = 'admin-error-modal-css';
    css.textContent = [
        '#admin-error-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);',
            'z-index:100000;justify-content:center;align-items:center;padding:20px;box-sizing:border-box;}',
        '#admin-error-overlay .aem-dialog{background:#fff;border-radius:10px;padding:20px;',
            'max-width:820px;width:100%;max-height:85vh;display:flex;flex-direction:column;',
            'box-shadow:0 12px 40px rgba(0,0,0,0.35);font-family:inherit;}',
        '#admin-error-overlay .aem-head{display:flex;justify-content:space-between;align-items:center;',
            'margin-bottom:12px;gap:12px;}',
        '#admin-error-overlay .aem-title{margin:0;font-size:1rem;color:#31345A;font-weight:600;}',
        '#admin-error-overlay .aem-x{background:none;border:none;font-size:1.4rem;line-height:1;',
            'cursor:pointer;color:#888;padding:0 4px;}',
        '#admin-error-overlay .aem-x:hover{color:#333;}',
        '#admin-error-overlay textarea.aem-text{width:100%;box-sizing:border-box;resize:vertical;',
            'min-height:60px;max-height:60vh;font-family:ui-monospace,Menlo,Consolas,monospace;',
            'font-size:0.82rem;line-height:1.5;color:#222;background:#f8f8f8;border:1px solid #ddd;',
            'border-radius:6px;padding:12px;white-space:pre;overflow:auto;}',
        '#admin-error-overlay .aem-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:14px;}',
        '#admin-error-overlay .aem-btn{padding:6px 16px;font-size:0.85rem;border-radius:5px;',
            'border:1px solid #ccc;background:#fff;cursor:pointer;}',
        '#admin-error-overlay .aem-btn:hover{background:#f0f0f0;}',
        '#admin-error-overlay .aem-btn.primary{background:#31345A;border-color:#31345A;color:#fff;}',
        '#admin-error-overlay .aem-btn.primary:hover{background:#3f4374;}'
    ].join('');
    (document.head || document.documentElement).appendChild(css);
}

var overlay, titleEl, textEl, copyBtn, okBtn, lastFocus;

function build() {
    overlay = document.createElement('div');
    overlay.id = 'admin-error-overlay';
    overlay.innerHTML =
        '<div class="aem-dialog" role="dialog" aria-modal="true" aria-labelledby="aem-title">' +
            '<div class="aem-head">' +
                '<h3 class="aem-title" id="aem-title">Notice</h3>' +
                '<button class="aem-x" type="button" aria-label="Close">&times;</button>' +
            '</div>' +
            '<textarea class="aem-text" readonly spellcheck="false" wrap="off"></textarea>' +
            '<div class="aem-foot">' +
                '<button class="aem-btn aem-copy" type="button">Copy</button>' +
                '<button class="aem-btn primary aem-ok" type="button">OK</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    titleEl = overlay.querySelector('.aem-title');
    textEl  = overlay.querySelector('.aem-text');
    copyBtn = overlay.querySelector('.aem-copy');
    okBtn   = overlay.querySelector('.aem-ok');

    overlay.querySelector('.aem-x').addEventListener('click', close);
    okBtn.addEventListener('click', close);
    copyBtn.addEventListener('click', copy);
    // Click on the backdrop (not the dialog) closes.
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });
    // Esc closes while open.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.style.display === 'flex') close();
    });
}

function coerce(message) {
    if (message == null) return '';
    if (message instanceof Error) {
        return message.stack || (message.name + ': ' + message.message);
    }
    if (typeof message === 'object') {
        try { return JSON.stringify(message, null, 2); }
        catch (e) { return String(message); }
    }
    return String(message);
}

function close() {
    if (!overlay) return;
    overlay.style.display = 'none';
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
}

function copy() {
    var text = textEl.value;
    var done = function () {
        copyBtn.textContent = 'Copied!';
        setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, fallback);
    } else {
        fallback();
    }
    function fallback() {
        textEl.removeAttribute('readonly');
        textEl.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        textEl.setAttribute('readonly', '');
    }
}

function show(message, opts) {
    opts = opts || {};
    if (!overlay) build();
    var text = coerce(message);
    var title = opts.title;
    if (!title) {
        title = /error|fail|exception|denied|unable|cannot|invalid|✗|❌|⚠/i.test(text)
            ? 'Error' : 'Notice';
    }
    titleEl.textContent = title;
    textEl.value = text;
    // Size to content: ~1 row per line, clamped; CSS max-height handles overflow.
    var lines = text.split('\n').length;
    textEl.rows = Math.max(3, Math.min(lines + 1, 24));
    lastFocus = document.activeElement;
    overlay.style.display = 'flex';
    okBtn.focus();
}

window.adminShowError = show;

// Route native alert() through the dialog so every existing call site benefits.
// Native alert is blocking; this modal is not, but the admin pages never rely
// on alert() blocking before a navigation/reload (audited — no such call
// sites), so behaviour is preserved while messages are no longer truncated.
window.__nativeAlert = window.alert;
window.alert = function (message) { show(message); };

}());
