// error-reporter.js
// Reports JS errors and unhandled promise rejections to the server.
// Filters out known third-party deprecation warnings we cannot fix.

(function () {
  'use strict';

  // Patterns for errors we should NOT report (third-party, unfixable deprecations, etc.)
  var IGNORE_PATTERNS = [
    // TinyMCE deprecation warnings we cannot fix
    /mozInputSource is deprecated/i,
    /tinymce/i,
    // Media autoplay policy (browser-enforced, not a code bug)
    /play method is not allowed by the user agent/i,
    /The play\(\) request was interrupted/i,
    // Cross-origin "Script error." with no source / line — browsers
    // censor errors thrown inside third-party iframes (YouTube, Rumble,
    // etc.) to a generic "Script error" reported at @0:0. Nothing we
    // can act on, and it spammed the monitor whenever a YouTube embed
    // went fullscreen on the search prototype.
    /^Script error\.?$/i,
    // Firefox AbortError when user navigates away — pending fetches are
    // cancelled by the browser; no stack trace, not actionable.
    /The operation was aborted\./i,
    // bg-video.js calls videos[i].load() during a <source> swap which
    // aborts the previous in-flight fetch — expected browser behavior.
    /The fetching process for the media resource was aborted/i,
    // Admin session expiry — api() helper in admin pages rethrows 401 as
    // unhandled rejection; expired session is expected, not a code bug.
    /Authentication required/i,
    /^401:/i
  ];

  // Files whose errors we should not report (third-party bundles)
  var IGNORE_FILES = [
    /tinymce/i
  ];

  function shouldIgnore(message, source) {
    var msg = message || '';
    var src = source || '';

    for (var i = 0; i < IGNORE_PATTERNS.length; i++) {
      if (IGNORE_PATTERNS[i].test(msg)) return true;
    }
    for (var j = 0; j < IGNORE_FILES.length; j++) {
      if (IGNORE_FILES[j].test(src)) return true;
    }
    return false;
  }

  // Server endpoint expects { errors: [{...}, ...] } at /api/client-error.php
  // (NOT /api/log-error — that path doesn't exist and was returning 500,
  // silently dropping every client-side error so yy_monitor_event never
  // logged them and the claude-fix runner never saw them).
  function sendError(payload) {
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '/api/client-error.php', true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(JSON.stringify({ errors: [payload] }));
    } catch (e) {
      // Never throw from error reporter
    }
  }

  // Global error handler
  window.addEventListener('error', function (event) {
    var message = (event.message || '') + '';
    var source  = (event.filename || '') + '';

    if (shouldIgnore(message, source)) return;

    sendError({
      type:    'js_error',
      message: message,
      source:  source,
      lineno:  event.lineno,
      colno:   event.colno,
      stack:   event.error && event.error.stack ? event.error.stack : null,
      page:    window.location.pathname + window.location.hash,
      ua:      navigator.userAgent
    });
  });

  // Unhandled promise rejection handler
  window.addEventListener('unhandledrejection', function (event) {
    var reason  = event.reason || {};
    var message = (reason.message || reason + '') + '';
    var source  = (reason.fileName || '') + '';

    if (shouldIgnore(message, source)) return;

    sendError({
      type:    'promise_rejection',
      message: message,
      source:  source,
      stack:   reason.stack || null,
      page:    window.location.pathname + window.location.hash,
      ua:      navigator.userAgent
    });
  });

}());
