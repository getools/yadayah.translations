#!/bin/bash
# cron-claude-fix.sh - Invoke Claude Code on unresolved monitor events.
# Authenticated via Max subscription (claude setup-token).
# Designed to run from cron every 96 minutes as user 'claudefix'.

set -u
umask 0002

# HARD GUARANTEE: this fixer authenticates ONLY via the Max subscription
# (CLAUDE_CODE_OAUTH_TOKEN, below). Strip any Anthropic API-key env vars so it
# can NEVER spend pay-per-token API credits — even if a key ever leaks into
# this user's environment. Pay-per-token billing is reserved for Ask Yada.
unset ANTHROPIC_API_KEY CLAUDE_USE_API_KEY

LOG=/var/log/yada-claude-fix.log
LOCK=/tmp/yada-claude-fix.lock

if [ "$(id -u)" = "0" ]; then
  echo "$(date -Is) ERROR: refusing to run as root - run as claudefix" >&2
  exit 2
fi

TOKEN_FILE="$HOME/.claude-token"
if [ -r "$TOKEN_FILE" ]; then
  export CLAUDE_CODE_OAUTH_TOKEN=$(cat "$TOKEN_FILE")
elif [ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]; then
  echo "$(date -Is) ERROR: no token at $TOKEN_FILE and CLAUDE_CODE_OAUTH_TOKEN not set" >&2
  exit 3
fi
PROMPT_FILE=/opt/yada-www/YADA_ERROR_FIXER_PROMPT.md
WORKDIR=/opt/yada-www

exec >> "$LOG" 2>&1
echo "===== $(date -Is) start ====="

if [ -e "$LOCK" ]; then
  PID=$(cat "$LOCK" 2>/dev/null || echo 0)
  if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
    echo "still running (pid=$PID), skipping"
    exit 0
  fi
  rm -f "$LOCK"
fi
echo $$ > "$LOCK"
trap 'rm -f "$LOCK"' EXIT

PSQL="docker exec -i yada-postgres-prod psql -U postgres -d yada -t -A"

COUNT=$($PSQL -c "
  SELECT COUNT(*) FROM yy_monitor_event
  WHERE event_resolved_flag = FALSE
    AND COALESCE(event_severity,'') NOT IN ('info')
    AND COALESCE(event_source,'')   NOT IN ('honeypot')
")
COUNT=$(echo "$COUNT" | tr -d '[:space:]')

if [ -z "$COUNT" ] || [ "$COUNT" = "0" ]; then
  echo "no unresolved actionable events"
  exit 0
fi
echo "unresolved actionable events: $COUNT"

EVENTS=$($PSQL -c "
  SELECT 'event_key=' || event_key
      || ' | sev=' || COALESCE(event_severity,'')
      || ' | src=' || COALESCE(event_source,'')
      || ' | file=' || COALESCE(event_file,'')
      || ' | msg=' || regexp_replace(COALESCE(event_message,''), E'[\n\r]+', ' ', 'g')
  FROM yy_monitor_event
  WHERE event_resolved_flag = FALSE
    AND COALESCE(event_severity,'') NOT IN ('info')
    AND COALESCE(event_source,'')   NOT IN ('honeypot')
  ORDER BY event_dtime DESC
  LIMIT 20
")

if [ ! -f "$PROMPT_FILE" ]; then
  echo "missing prompt file: $PROMPT_FILE"
  exit 1
fi
SYSTEM_PROMPT=$(cat "$PROMPT_FILE")

USER_MSG=$(cat <<EOF
There are $COUNT unresolved actionable events in yy_monitor_event. The most recent 20:

$EVENTS

For each event in this list:
  1. Triage per the noise rules in your system prompt.
  2. If real, read the source, diagnose root cause, apply fix, verify with curl or psql.
  3. UPDATE yy_monitor_event SET event_resolved_flag=TRUE, event_action_taken='Fixed: ...' WHERE event_key=N.
  4. If you change files, commit via /opt/yada-www/api/git-push.sh with message 'Auto-fix: <description>'.
  5. If you cannot fix safely, set event_action_taken='Needs human review: <reason>' but leave event_resolved_flag=FALSE.

Stop after at most 30 minutes of work. Report a one-paragraph summary at the end.
EOF
)

cd "$WORKDIR"

timeout 1800 claude -p \
  --append-system-prompt "$SYSTEM_PROMPT" \
  --dangerously-skip-permissions \
  --permission-mode bypassPermissions \
  --no-session-persistence \
  --output-format text \
  "$USER_MSG"

RC=$?
echo "===== $(date -Is) end rc=$RC ====="
