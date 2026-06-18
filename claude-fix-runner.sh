#!/bin/bash
# claude-fix-runner.sh — Host-side worker that picks up Claude-fix requests
# enqueued by auto-fix-error.php (running inside the web container) and runs
# the Claude Code CLI on the host (where node + claude are installed).
#
# Why this exists:
#   The web container doesn't have node/claude. auto-fix-error.php inside the
#   container can't successfully invoke `claude` directly — every attempt
#   exits with rc=127. So PHP enqueues a request file in a bind-mounted dir,
#   this host-side script picks it up, runs claude-fix.sh on the host with
#   live stdout streamed to a log file, and writes a `done` marker with the
#   exit code when finished. PHP's polling loop reads the log file and
#   surfaces it as `claude_output` in the status JSON.
#
# Cron: every 30 seconds. Single-instance via flock.
#
# Layout (bind-mount: /opt/yada-www/public/jobs/claude-fix/ in the host =
# /var/www/html/jobs/claude-fix/ in the container):
#   req_{run_id}_{event_key}.json   — input from PHP
#   log_{run_id}_{event_key}.log    — live stdout from claude (this script writes)
#   done_{run_id}_{event_key}.json  — exit marker (this script writes)
#   processed/                      — req files moved here when done

set -uo pipefail

QUEUE=/opt/yada-www/public/jobs/claude-fix
LOCK=/var/lock/claude-fix-runner.lock
CLAUDE_FIX=/opt/yada-www/api/claude-fix.sh

mkdir -p "$QUEUE" "$QUEUE/processed" /opt/yada-www/public/jobs/autofix
# Shared ownership so the web container (www-data) can drop req files AND
# the host runner (root) can write logs. setgid bit keeps group sticky.
chown -R www-data:claudefix "$QUEUE" /opt/yada-www/public/jobs/autofix 2>/dev/null || true
chmod -R 2775 "$QUEUE" /opt/yada-www/public/jobs/autofix 2>/dev/null || true

exec 9>"$LOCK"
flock -n 9 || exit 0

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] $*"; }

# ── Pause flag: skip while a developer is editing prod from VS Code. ──
# Mirrors the check in auto-fix-error.php. A SessionStart hook on the dev
# machine touches this file; honored for a bounded window so a forgotten
# resume self-heals instead of permanently disabling auto-fix.
PAUSE_FLAG=/opt/yada-www/public/jobs/autofix/PAUSED
PAUSE_TTL=7200
if [ -f "$PAUSE_FLAG" ]; then
    flag_age=$(( $(date +%s) - $(stat -c %Y "$PAUSE_FLAG" 2>/dev/null || echo 0) ))
    if [ "$flag_age" -ge 0 ] && [ "$flag_age" -lt "$PAUSE_TTL" ]; then
        log "Skip: auto-fix paused by dev flag (age ${flag_age}s < ${PAUSE_TTL}s)"
        exit 0
    fi
fi

# Pre-flight: don't spawn Claude when host is busy. Same gate as the cron-
# claude-fix-gated wrapper.
free_mb=$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
load_now=$(awk '{print $1}' /proc/loadavg)
if [ "${free_mb:-0}" -lt 700 ]; then
    log "Skip: only ${free_mb}MB free"; exit 0
fi
if awk -v l="$load_now" 'BEGIN{exit !(l>4.0)}'; then
    log "Skip: load avg ${load_now} > 4.0"; exit 0
fi

shopt -s nullglob

# Process ALL pending requests in a single invocation. The systemd path
# watcher fires us once per "queue went non-empty" event; if we only handled
# one request, a burst of N queued events would need N path-watcher fires.
# Looping in-place is simpler and more responsive.
AUTOFIX_DIR=/opt/yada-www/public/jobs/autofix

# Update the autofix run's status file so the admin UI sees live progress
# without needing to poll a separate endpoint. Reads existing file, merges
# the patch, writes it back. python is used for safe JSON manipulation.
update_autofix_status() {
    local run_id="$1" key="$2" value="$3" extra_key="${4:-}" extra_value="${5:-}"
    local f="$AUTOFIX_DIR/run_${run_id}.json"
    [ -f "$f" ] || return
    python3 - "$f" "$key" "$value" "$extra_key" "$extra_value" <<'PY' 2>/dev/null || true
import json, sys, os
path, key, value, ek, ev = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5]
try:
    with open(path) as fh: d = json.load(fh)
except Exception:
    d = {}
d[key] = value
if ek:
    d[ek] = ev
d['updated'] = __import__('datetime').datetime.now().isoformat()
tmp = path + '.tmp'
with open(tmp, 'w') as fh: json.dump(d, fh, indent=2)
os.replace(tmp, path)
PY
}

process_one() {
    local req="$1"
    local req_basename suffix event_key run_id log_file done_file rc
    req_basename=$(basename "$req" .json)        # req_{run_id}_{event_key}
    suffix=${req_basename#req_}
    event_key=$(grep -oE '"event_key"\s*:\s*[0-9]+' "$req" | grep -oE '[0-9]+' | head -1)
    run_id=$(grep -oE '"run_id"\s*:\s*"[^"]+"' "$req" | sed -E 's/.*"([^"]+)"$/\1/')
    if [ -z "$event_key" ]; then
        log "Bad request file (no event_key): $req"
        mv "$req" "$QUEUE/processed/" 2>/dev/null
        return
    fi
    # Some writers (auto-fix-error.php in cron context) enqueue requests
    # with an empty or missing run_id. Don't silently drop those — synthesize
    # a run_id so the event still gets processed. Without this, every such
    # request was moved to processed/ untouched and the underlying event
    # stayed unresolved forever (LibreOffice / FlipHTML5 failures piled up
    # for hours unnoticed before this guard was added).
    if [ -z "$run_id" ]; then
        run_id="auto$(date +%s)$$"
        log "Synthesized run_id=$run_id for $req (writer omitted run_id)"
        new_req="$QUEUE/req_${run_id}_${event_key}.json"
        mv "$req" "$new_req" 2>/dev/null && req="$new_req"
        suffix="${run_id}_${event_key}"
    fi
    log_file="$QUEUE/log_${suffix}.log"
    done_file="$QUEUE/done_${suffix}.json"
    log "Processing event $event_key (run $run_id)"

    : > "$log_file"
    # Allow web container's www-data to read the streaming log (PHP polling).
    chown claudefix:claudefix "$log_file" 2>/dev/null || true
    chmod 0664 "$log_file" 2>/dev/null || true

    # Mark the autofix run as actively processing this event before we kick
    # off Claude. Status state stays 'claude_queued' so the UI keeps polling.
    update_autofix_status "$run_id" "state" "claude_queued" "claude_event_key" "$event_key"

    # Run claude inside a transient SYSTEM-level systemd scope with hard
    # mem/CPU caps. We use root-side systemd-run with --uid/--gid to drop to
    # claudefix; the prior `runuser ... systemd-run --user` approach fails
    # because the claudefix user has no login session ("No medium found"
    # from sd_bus). HOME must be set so `claude` finds its credentials at
    # /opt/yada-www/claude-home/.claude/.credentials.json.
    #
    # On-host postgres connection: the docker container hostname "postgres"
    # only resolves inside the container network. From the host we need the
    # bridge IP, which we look up dynamically via docker inspect each run
    # (the IP can change between container restarts).
    local pg_ip
    pg_ip=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' yada-postgres-prod 2>/dev/null)
    [ -z "$pg_ip" ] && pg_ip=postgres
    # Auto-fix MUST authenticate via the OAuth/subscription session at
    # /opt/yada-www/claude-home/.claude/. It must NEVER fall back to the
    # Anthropic API key — that drains the workspace API budget reserved for
    # Ask Yada. If OAuth is expired, fail fast: refresh via `claude login`
    # as the claudefix user instead of patching this script.
    local creds=/opt/yada-www/claude-home/.claude/.credentials.json
    if [ ! -s "$creds" ]; then
        {
            echo "[$(date +%H:%M:%S)] OAuth credentials missing at $creds — refusing to run."
            echo "[$(date +%H:%M:%S)] Refresh by running 'sudo -u claudefix HOME=/opt/yada-www/claude-home claude login' on the host."
        } >> "$log_file" 2>&1
        rc=2
    else
        {
            echo "[$(date +%H:%M:%S)] Starting claude-fix.sh for event $event_key (pg=$pg_ip, auth=oauth)"
            systemd-run --scope --quiet \
                --uid=claudefix --gid=claudefix \
                --setenv=HOME=/opt/yada-www/claude-home \
                --setenv=PG_HOST="$pg_ip" --setenv=PG_PORT=5432 \
                --setenv=PG_DB=yada --setenv=PG_USER=postgres --setenv=PG_PASS=yada_password \
                --property=MemoryMax=1200M \
                --property=MemorySwapMax=0 \
                --property=CPUQuota=70% \
                timeout 1620 bash "$CLAUDE_FIX" "$event_key"
            rc=$?
            echo "[$(date +%H:%M:%S)] claude-fix.sh exited with rc=$rc"
        } >> "$log_file" 2>&1
        rc=${rc:-1}
    fi

    cat > "$done_file" <<EOF
{"run_id":"$run_id","event_key":$event_key,"rc":$rc,"finished":"$(date -Iseconds)"}
EOF
    mv "$req" "$QUEUE/processed/" 2>/dev/null

    # Stream the captured Claude output into the autofix status file so the
    # UI textarea fills in (last 16 KB to keep the file small).
    if [ -f "$log_file" ]; then
        local tail_buf
        tail_buf=$(tail -c 16384 "$log_file" 2>/dev/null)
        update_autofix_status "$run_id" "claude_event_key" "$event_key" "claude_output" "$tail_buf"
    fi

    # If no more pending requests for THIS run_id, mark the autofix as done.
    local remaining
    remaining=$(ls "$QUEUE"/req_${run_id}_*.json 2>/dev/null | wc -l)
    if [ "$remaining" = "0" ]; then
        update_autofix_status "$run_id" "state" "complete" "finished" "$(date -Iseconds)"
        log "Run $run_id has no more pending requests — marked complete"
    fi

    log "Done event $event_key rc=$rc"
}

while true; do
    # Find oldest pending request
    oldest=""
    for r in "$QUEUE"/req_*.json; do
        if [ -z "$oldest" ] || [ "$r" -ot "$oldest" ]; then
            oldest="$r"
        fi
    done
    [ -z "$oldest" ] && break

    # Re-check pre-flight gates between requests — host conditions can
    # change while we're processing. If load spikes, defer remaining
    # requests to the next path-watcher fire.
    free_mb=$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
    load_now=$(awk '{print $1}' /proc/loadavg)
    if [ "${free_mb:-0}" -lt 700 ]; then
        log "Defer remaining: only ${free_mb}MB free"; break
    fi
    if awk -v l="$load_now" 'BEGIN{exit !(l>4.0)}'; then
        log "Defer remaining: load ${load_now} > 4.0"; break
    fi

    process_one "$oldest"
done

chown -R www-data:claudefix "$QUEUE" 2>/dev/null || true
chmod -R 2775 "$QUEUE" 2>/dev/null || true
