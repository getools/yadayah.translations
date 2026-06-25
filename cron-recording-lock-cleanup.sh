#!/bin/bash
# cron-recording-lock-cleanup.sh
#
# Sweeps for stale lock state that admin-recordings.php's in_progress signal
# would otherwise treat as still active. Runs every minute. Three classes:
#
#   1. Heartbeat files (/tmp/recording_active_<key>) older than 30s.
#      The popout heartbeats every 5s; mtime > 30s means the popout closed
#      ungracefully (browser crash, OS sleep, network drop) and never got
#      its release beacon out. admin-recordings.php cleans these inline as
#      it encounters them, but only for items it actually queries — this
#      sweep catches the rest.
#
#   2. Finalize worker state (/tmp/finalize_<key>.state) in terminal status
#      (complete/error/idle) older than 1 hour, plus any orphaned
#      .progress / .duration sidecars older than 1 hour. The worker writes
#      its terminal status and exits; nothing else cleans these up.
#
#   3. Transcript jobs (yy_feed_item_transcript_job) stuck in pending or
#      running for >2 hours with no PID alive. These would otherwise hold
#      an item's in_progress lock forever once the worker crashed.
#
# All operations are idempotent; safe to run as often as desired.

set -uo pipefail
LOG=/var/log/recording-lock-cleanup.log
NOW=$(date +%s)

ts() { date -Is; }

# --- 1. heartbeat files ---
removed_hb=0
for f in /tmp/recording_active_*; do
    [ -f "$f" ] || continue
    age=$((NOW - $(stat -c %Y "$f" 2>/dev/null || echo $NOW)))
    if [ "$age" -gt 30 ]; then
        rm -f "$f"
        removed_hb=$((removed_hb + 1))
    fi
done

# --- 2. finalize state + sidecars ---
removed_state=0
for f in /tmp/finalize_*.state; do
    [ -f "$f" ] || continue
    age=$((NOW - $(stat -c %Y "$f" 2>/dev/null || echo $NOW)))
    if [ "$age" -lt 3600 ]; then continue; fi
    # Read the JSON status — only remove if terminal.
    status=$(python3 -c "
import json, sys
try:
    s = json.load(open('$f'))
    print(s.get('status', ''))
except Exception:
    print('')" 2>/dev/null)
    if [ "$status" = "complete" ] || [ "$status" = "error" ] || [ "$status" = "idle" ] || [ -z "$status" ]; then
        rm -f "$f"
        removed_state=$((removed_state + 1))
    fi
done
for sidecar in /tmp/finalize_*.progress /tmp/finalize_*.duration; do
    [ -f "$sidecar" ] || continue
    age=$((NOW - $(stat -c %Y "$sidecar" 2>/dev/null || echo $NOW)))
    if [ "$age" -gt 3600 ]; then
        rm -f "$sidecar"
        removed_state=$((removed_state + 1))
    fi
done

# --- 3. orphaned transcript jobs ---
# Cancel pending/running rows older than 2h whose worker pid is dead.
PSQL="docker exec -i yada-postgres-prod psql -U postgres -d yada -t -A"
cancelled=0
while IFS='|' read -r jkey pid; do
    [ -z "$jkey" ] && continue
    # Worker PIDs are CONTAINER pids (transcript-worker.php runs inside
    # yada-www-web-1), so liveness must be checked in the container — a host
    # kill -0 always reads "dead" for them and would falsely cancel a
    # legitimately long-running transcription (a >2h wall-clock job whose
    # worker is alive but mostly blocked waiting on the GPU engine; the 40-min
    # worker cap is CPU time, not wall time, so long episodes can exceed 2h).
    # grep -ql "transcript-worker" guards against PID reuse by an unrelated
    # process that happened to be assigned the same PID after the worker exited.
    if [ -n "$pid" ] && [ "$pid" -gt 0 ]; then
        if docker exec yada-www-web-1 grep -ql "transcript-worker" /proc/$pid/cmdline </dev/null 2>/dev/null; then
            continue  # worker still alive in the container — leave it
        fi
    fi
    # </dev/null prevents docker exec -i (via $PSQL) from consuming the process
    # substitution's stdin, which would drain remaining SELECT rows and limit
    # this loop to cancelling only one job per run.
    $PSQL -c "UPDATE yy_feed_item_transcript_job
              SET job_status='cancelled',
                  job_message=COALESCE(job_message,'') || ' [auto-cancelled by lock-cleanup: stale]',
                  job_completed_dtime=NOW()
              WHERE feed_item_transcript_job_key=$jkey
                AND job_status IN ('pending','running')" </dev/null >/dev/null 2>&1
    cancelled=$((cancelled + 1))
done < <($PSQL -c "
    SELECT feed_item_transcript_job_key, COALESCE(job_worker_pid, 0)
    FROM yy_feed_item_transcript_job
    WHERE job_status IN ('pending','running')
      AND job_dtime < NOW() - INTERVAL '2 hours'
    ORDER BY job_dtime
" 2>/dev/null)

# Only log when we actually did something — avoids growing the log on idle
# minutes (which would be most of them).
if [ "$removed_hb" -gt 0 ] || [ "$removed_state" -gt 0 ] || [ "$cancelled" -gt 0 ]; then
    echo "[$(ts)] hb=$removed_hb state=$removed_state cancelled_jobs=$cancelled" >> "$LOG"
fi
exit 0
