#!/bin/bash
# claude-fix.sh — invokes Claude Code on production to investigate & fix a single error.
#
# Usage:
#   claude-fix.sh <event_key>
#
# Reads error details from yy_monitor_event, builds a focused prompt,
# invokes `claude -p` (non-interactive), and writes the agent's resolution
# back to event_resolve_notes / event_action_taken.

set -e

EVENT_KEY="${1:?event_key required}"
LOG_DIR="/tmp/claude-fix-logs"
mkdir -p "$LOG_DIR" 2>/dev/null || true
chmod 1777 "$LOG_DIR" 2>/dev/null || true
LOG_FILE="$LOG_DIR/event_${EVENT_KEY}.log"

# claude-fix.sh must NOT run as root. The scheduler (claude-fix-runner.sh)
# drops to user 'claudefix' via systemd-run --uid before invoking us. If
# we somehow get here as root, refuse loudly — never try to "fix" it by
# chown'ing $HOME. On 2026-05-02 a previous version of this script ran
# `chown -R www-data:www-data "$HOME"` as root, with HOME defaulted to
# /root (systemd doesn't auto-set HOME for User=root services). That
# trashed every file in /root, broke key-based SSH for hours, and required
# console recovery. NEVER do that. If this branch is hit, exit and let the
# operator investigate why HOME/uid propagation broke.
if [ "$(id -u)" = "0" ]; then
    echo "claude-fix.sh refuses to run as root. Invoke via claude-fix-runner.sh, which drops to claudefix via systemd-run --uid." >&2
    exit 2
fi

# Hard guard: even as a non-root user, refuse to operate with HOME pointing
# at a system-critical path. Belt-and-suspenders against any future re-exec
# logic that might forget to set HOME explicitly.
case "${HOME:-}" in
    ''|'/'|/root|/root/*|/etc|/etc/*|/var|/var/*|/usr|/usr/*|/home)
        echo "claude-fix.sh refuses to operate with HOME='${HOME:-<unset>}'. Set HOME to /opt/yada-www/claude-home before invoking." >&2
        exit 2
        ;;
esac

# Claude credentials live on the host bind-mount at /opt/yada-www/claude-home/.claude/
# (mapped to /var/www/.claude-home/ in the container by docker-compose.prod.yml).
# Honor externally-set HOME — the host-side claude-fix-runner.sh sets HOME to
# /opt/yada-www/claude-home (the bind-mount source) and that's the canonical
# location of .claude/.credentials.json. Only fall back to the container path
# if HOME isn't set or doesn't have the .claude dir.
if [ -z "${HOME:-}" ] || [ ! -d "${HOME}/.claude" ]; then
    export HOME="/var/www/.claude-home"
fi
mkdir -p "$HOME/.claude"

# Resource & safety limits. The host runner (claude-fix-runner.sh) exports
# TIMEOUT_SECS from the admin Settings value; honor it when present, else the
# default below. Runner also wraps us in its own `timeout` as a backstop.
TIMEOUT_SECS="${TIMEOUT_SECS:-1500}"

# Fetch error details. Use direct psql if available (when running inside the
# web container); fall back to `docker exec` against the postgres container
# when running on the host (claudefix user has docker group access).
export PGPASSWORD="${PG_PASS:-yada_password}"
export PGHOST="${PG_HOST:-postgres}"
export PGUSER="${PG_USER:-postgres}"
export PGDATABASE="${PG_DB:-yada}"
psql_query() {
    if command -v psql >/dev/null 2>&1; then
        psql -t -A -F'|' -c "$1"
    else
        docker exec -e PGPASSWORD="$PGPASSWORD" yada-postgres-prod \
            psql -U "$PGUSER" -d "$PGDATABASE" -t -A -F'|' -c "$1"
    fi
}
ERR_JSON=$(psql_query \
    "SELECT event_source, event_severity, event_message, COALESCE(event_detail, ''), COALESCE(event_file, ''), COALESCE(event_referer, '') FROM yy_monitor_event WHERE event_key = $EVENT_KEY" 2>&1)

if [ -z "$ERR_JSON" ]; then
    echo "Event $EVENT_KEY not found" | tee -a "$LOG_FILE"
    exit 1
fi

IFS='|' read -r SOURCE SEVERITY MESSAGE DETAIL FILE REFERER <<< "$ERR_JSON"

# Detect environment: container (web) vs host (claude-fix-runner). The host
# has source under /opt/yada-www/{public,api}/; the container sees the same
# files as /var/www/html/{,api/} via bind mount. Pick the path that exists.
# Pick CODE_ROOT by checking for the API subdirectory specifically. Plain
# /var/www/html exists on the host too (default Apache page) but never has
# a populated /api/ subdir there — only the container does, via the
# /opt/yada-www/api → /var/www/html/api bind mount.
if [ -d /var/www/html/api ]; then
    CODE_ROOT=/var/www/html
    DB_QUERY_HINT="DB writes use \`psql\` with PGPASSWORD env var (psql is in PATH)."
else
    CODE_ROOT=/opt/yada-www
    DB_QUERY_HINT="DB writes use \`psql\` with PG_HOST/PG_USER/PG_PASS env vars set (psql is in PATH)."
fi

PROMPT="You are an autonomous code-fix agent for the Yada Yah website (PHP/PostgreSQL).

An error was logged in yy_monitor_event:

Event #$EVENT_KEY
Source: $SOURCE
Severity: $SEVERITY
Message: $MESSAGE
Detail: $DETAIL
File: $FILE
Referer: $REFERER

Your task:
1. Investigate the root cause (read the relevant source files in $CODE_ROOT, check Apache logs at /var/log/apache2/error.log, query the DB if needed).
2. Apply the fix in the code (write the corrected file).
3. Verify the fix works (curl the endpoint, query the DB).
4. Mark the error resolved with a description of what you did.

Constraints:
- ONLY modify files under $CODE_ROOT (or /opt/yada-www/api/ if you see that path exists).
- $DB_QUERY_HINT
- Mark resolved with: UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = 'description', event_resolve_notes = 'detailed notes' WHERE event_key = $EVENT_KEY;
- If you can't confidently fix it, leave it unresolved and write a note explaining what you investigated.

REGRESSION SAFETY — start from current production state:
- BEFORE editing ANY file, read its current contents from $CODE_ROOT with the Read tool. CWD IS the production tree; the Read tool always returns the live bytes. Do not work from memory, training-data assumptions, or notes you remember from a previous session — those will be stale and have caused us to silently overwrite recent features with old versions.
- Prefer the Edit tool (surgical old_string → new_string replacements) over Write whenever possible. Write replaces the entire file and is the most common way unrelated code gets lost. If you must use Write, re-read the file immediately before writing, include every line you intend to keep, and after writing, re-read to confirm only your intended diff is present.
- You ARE free to refactor, restructure, remove dead code, or improve adjacent areas while fixing an error — full creative latitude. The rule is only about the BASELINE you build from: it must be the live in-production state at the time of THIS run, not a remembered version.

Be concise. End with a one-line summary: 'RESOLVED: ...' or 'UNRESOLVED: ...'.
"

cd "$CODE_ROOT"

echo "[$(date -u +%FT%TZ)] claude-fix starting event $EVENT_KEY (CODE_ROOT=$CODE_ROOT, HOME=$HOME, uid=$(id -u))" >> "$LOG_FILE"

# Auto-fix authenticates via the OAuth/subscription session in $HOME/.claude.
# It must NEVER use the Anthropic API key (workspace billing is reserved for
# Ask Yada). Strip any inherited ANTHROPIC_API_KEY so a stray env var can't
# silently re-enable the API path.
unset ANTHROPIC_API_KEY CLAUDE_USE_API_KEY
# Two OAuth (subscription) mechanisms are accepted, in priority order:
#   1. A long-lived token from `claude setup-token`, stored at
#      $HOME/.claude/oauth_token and exported as CLAUDE_CODE_OAUTH_TOKEN.
#      This is OAuth, NOT the Anthropic API key, so it honors the rule above.
#      Preferred for unattended runs: it does not expire for ~1 year, sidestep-
#      ping the stored-session refresh that broke when refreshToken went empty.
#   2. The interactive session file $HOME/.claude/.credentials.json (from
#      `claude login`).
# Both are installed from the admin Settings → Auto-Fix → Authentication panel
# (api/admin-autofix-auth.php → host claude-auth-helper.sh). The env-var token
# takes precedence over the stored session when present.
TOKEN_FILE="$HOME/.claude/oauth_token"
if [ -s "$TOKEN_FILE" ]; then
    export CLAUDE_CODE_OAUTH_TOKEN="$(cat "$TOKEN_FILE")"
fi
if [ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ] && [ ! -s "$HOME/.claude/.credentials.json" ]; then
    echo "[$(date -u +%FT%TZ)] No OAuth auth: neither $TOKEN_FILE nor $HOME/.claude/.credentials.json present — refusing to run." >> "$LOG_FILE"
    echo "[$(date -u +%FT%TZ)] Fix from admin Settings → Auto-Fix → Authentication, or: sudo -u claudefix HOME=$HOME claude login" >> "$LOG_FILE"
    exit 2
fi

# Claude's Read/Edit tools default to CWD only. CWD is already CODE_ROOT
# (/opt/yada-www) so the production tree is implicit.
#
# Do NOT add /opt/yada-git as a readable dir. /opt/yada-git is a downstream
# mirror that lags /opt/yada-www by up to 30 minutes (rsync runs in
# git-push.sh on the cron). If Claude reads the mirror while reasoning about
# a file and then writes the rewrite back to the live tree, any changes
# made in the gap between rsyncs get silently reverted. This was the
# proximate cause of the recurring "old versions keep coming back" pattern
# the user has flagged. Always read from the live tree only.
#
# /var/log stays — read-only Apache + monitor logs, no overwrite risk.
CLAUDE_ADD_DIRS=()
if [ "$CODE_ROOT" = "/opt/yada-www" ]; then
    [ -d /var/log ]      && CLAUDE_ADD_DIRS+=(--add-dir /var/log)
fi

# Timeout-wrapped claude invocation. NO --bare flag — that would force the
# API-key auth path; we want OAuth/subscription auth only.
# --dangerously-skip-permissions ACTUALLY bypasses permission checks. The
# --allow- variant only makes bypass *available*, so in -p mode Edit/Bash were
# auto-denied ("sandbox-denied") and the agent could diagnose but never fix.
timeout "$TIMEOUT_SECS" claude \
    "${CLAUDE_ADD_DIRS[@]}" \
    --dangerously-skip-permissions \
    -p "$PROMPT" \
    >> "$LOG_FILE" 2>&1
RC=$?

echo "[$(date -u +%FT%TZ)] claude-fix exit=$RC" >> "$LOG_FILE"

# Tail of log for inline display
tail -50 "$LOG_FILE"

exit $RC
