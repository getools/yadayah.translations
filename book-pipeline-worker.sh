#!/bin/bash
# ── book-pipeline-worker.sh ───────────────────────────────────────────────
# Host-side worker that processes book pipeline jobs queued by admin-books.php.
#
# For each job in /opt/yada-www/jobs/book-pipeline/:
#   1. Convert .docx → .pdf via Microsoft Graph (Word's own renderer).
#      Hard requirement: PDFs are bit-for-bit Word output. OnlyOffice and
#      LibreOffice paths kept below as legacy code but no longer reachable.
#      Fail-loud if Graph is down — never silently fall back to a renderer
#      that has produced kerning-collapse bugs in the past.
#   2. Move PDF to /opt/yada-www/public/pdf/
#   3. (FlipHTML5 phase preserved as legacy; the self-hosted flipbook
#      system under /<volume_code>/ replaces it and is rebuilt elsewhere.)
#   4. Update yy_volume.volume_pdf, .volume_flip_code, and .volume_pipeline_*
#   5. Run parse_volume_from_bundle.py to refresh paragraphs + translations.
#
# Crontab (every 15 minutes):
#   */15 * * * * /opt/yada-www/book-pipeline-worker.sh >> /var/log/book-pipeline.log 2>&1
#
# Requirements on host:
#   - libreoffice (apt install libreoffice --no-install-recommends)
#   - docker (already available)
#   - rsshub container with puppeteer-real-browser (already deployed for Rumble scraping)

set -uo pipefail

# ── Resource gates ────────────────────────────────────────────────────
# Host has only ~3.9 GB RAM total; a single LibreOffice headless can claim
# 2.6 GB. Without limits we OOM-kill ourselves and starve the website. Two
# guards:
#   1. Pre-flight: if free RAM < MIN_FREE_MB or load avg > MAX_LOAD, skip.
#   2. Per-process: every heavy external (LibreOffice, Puppeteer/Node, the
#      Python parser) runs inside a transient systemd scope with hard
#      MemoryMax + CPUQuota. Excess gets cgroup-killed cleanly (logged as
#      exit 137) instead of starving the box.
MIN_FREE_MB=1000
MAX_LOAD=10.0
# Originally 1500 / 6.0, but on a 7.8G host with 4G swap the gate was
# tripping on transient transcript-worker spikes ~20×/day, deferring
# legitimate book-pipeline work for hours. The systemd-run scopes already
# cgroup-cap each heavy step, so the pre-flight just needs to keep the
# host from going completely flat — 1G headroom + 10.0 load is enough.
SOFFICE_MEM_MAX=5500M
SOFFICE_CPU_QUOTA=40%
PARSER_MEM_MAX=800M
PARSER_CPU_QUOTA=40%

# Bind-mounted by the web container (host: /opt/yada-www/public/jobs/...,
# container: /var/www/html/jobs/...). admin-books.php drops job files here
# whenever a docx is uploaded.
JOBS_DIR=/opt/yada-www/public/jobs/book-pipeline
DOCX_DIR=/opt/yada-www/public/u/books-word
PDF_DIR=/opt/yada-www/public/pdf
FLIP_DIR=/opt/yada-www/public/flipbook
LOCK=/var/lock/book-pipeline-worker.lock
PG_CONTAINER=yada-postgres-prod
WEB_CONTAINER=yada-www-web-1
RSSHUB_CONTAINER=yada-www-rsshub-1
ONLYOFFICE_CONTAINER=yada-www-onlyoffice-1

mkdir -p "$JOBS_DIR" "$DOCX_DIR" "$PDF_DIR" "$FLIP_DIR" "$JOBS_DIR/done" "$JOBS_DIR/failed"

# Single-instance lock — exits immediately if another worker is running.
exec 9>"$LOCK"
flock -n 9 || exit 0

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] $*"; }

# Pre-flight: bail if the host doesn't have headroom. We check BEFORE any
# heavy work so a heavy tick is naturally re-tried by cron 2 min later when
# the previous load has decayed.
free_mb=$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
load_now=$(awk '{print $1}' /proc/loadavg)
if [ "${free_mb:-0}" -lt "$MIN_FREE_MB" ]; then
    log "Skip tick: only ${free_mb}MB free (< ${MIN_FREE_MB}MB threshold)"
    exit 0
fi
if awk -v l="$load_now" -v m="$MAX_LOAD" 'BEGIN{exit !(l>m)}'; then
    log "Skip tick: load avg ${load_now} (> ${MAX_LOAD} threshold)"
    exit 0
fi
log "Pre-flight OK: ${free_mb}MB free, load ${load_now}"

# Catch-all error trap. Only fires on conditions that genuinely indicate a
# broken worker, NOT on routine non-zero returns:
#   - 127: command not found (missing binary, $PATH issue)
#   - 137: SIGKILL (OOM-killed, cgroup limit, or `kill -9`)
#   - 139: SIGSEGV (segfault — actually broken)
#   - 134: SIGABRT (abort)
# Routine non-zero exits from `mv`, `docker exec psql`, etc. were creating
# yy_monitor_event noise that buried real failures.
set -E
trap 'rc=$?; case "$rc" in 127|134|137|139) log_monitor_event "book_pipeline" "error" "Worker shell trap fired (exit $rc — fatal signal/missing binary)" "line $LINENO: ${BASH_COMMAND:-unknown command}";; esac' ERR

# Insert a row into yy_monitor_event so failures surface in the central
# events monitor (/admin-monitor.html) alongside Apache, sync, and DB issues.
# Severities the UI already recognizes: error, warning, info.
log_monitor_event() {
    local source="$1" severity="$2" message="$3" detail="$4"
    local esc_msg esc_detail
    esc_msg=$(echo "$message" | sed "s/'/''/g")
    esc_detail=$(echo "$detail" | sed "s/'/''/g")
    docker exec "$PG_CONTAINER" psql -U postgres -d yada -c \
        "INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_file) \
         VALUES ('$source', '$severity', '$esc_msg', '$esc_detail', 'book-pipeline-worker.sh');" \
        > /dev/null 2>&1
}

# Update yy_volume status (escapes single quotes). Error/warning states also
# log a monitor event so admins notice them on /admin-monitor.html without
# having to be on the Books page when the failure happens.
update_status() {
    local volume_key="$1" status="$2" message="$3" detail="${4:-}"
    local esc_msg
    esc_msg=$(echo "$message" | sed "s/'/''/g")
    docker exec "$PG_CONTAINER" psql -U postgres -d yada -c \
        "UPDATE yy_volume SET volume_pipeline_status='$status', volume_pipeline_message='$esc_msg', volume_revision_dtime=NOW() WHERE volume_key=$volume_key;" \
        > /dev/null 2>&1
    if [ "$status" = "error" ] || [ "$status" = "warning" ]; then
        local d="${detail:-volume_key=$volume_key status=$status}"
        log_monitor_event "book_pipeline" "$status" \
            "Volume $volume_key: $message" "$d"
    fi
}

# Update yy_volume PDF + flip_code.
update_outputs() {
    local volume_key="$1" pdf_name="$2" flip_code="$3"
    local esc_pdf esc_flip
    esc_pdf=$(echo "$pdf_name" | sed "s/'/''/g")
    esc_flip=$(echo "$flip_code" | sed "s/'/''/g")
    local sets="volume_pdf='$esc_pdf'"
    if [ -n "$esc_flip" ]; then
        sets="$sets, volume_flip_code='$esc_flip'"
    fi
    docker exec "$PG_CONTAINER" psql -U postgres -d yada -c \
        "UPDATE yy_volume SET $sets, volume_revision_dtime=NOW() WHERE volume_key=$volume_key;" \
        > /dev/null 2>&1
}

# Try ONLYOFFICE Document Server for DOCX→PDF first. ~12-20s per volume,
# ~800MB peak memory (vs LibreOffice's 4-7GB on heavy docs). Returns 0 on
# success with PDF written to $2; non-zero on any failure (caller falls
# back to the LibreOffice 2-step bridge below).
#
# Side effect: stages a strip-vanish-paragraphs copy of the docx in
# $DOCX_DIR briefly (~30s) so ONLYOFFICE can fetch it via internal
# http://web/... URL, then removes it.
convert_via_onlyoffice() {
    local docx_path="$1" pdf_dest="$2"

    # Container running?
    if ! docker ps --format '{{.Names}}' | grep -q "^${ONLYOFFICE_CONTAINER}$"; then
        return 1
    fi
    # JWT secret readable?
    if [ ! -s /opt/yada-www/.oojwt ]; then
        return 2
    fi

    local jwt; jwt=$(cat /opt/yada-www/.oojwt)

    # Strip vanished TC paragraphs to a staging file under the web docroot —
    # ONLYOFFICE doesn't honor w:vanish on paragraph marks the way Word does
    # and renders them as phantom blank lines without this preprocessing.
    local stage_name="_oo-stage-$$-$(basename "$docx_path")"
    local stage_path="$DOCX_DIR/$stage_name"
    if ! python3 /opt/yada-www/docx-strip-vanish.py "$docx_path" "$stage_path" >> /var/log/book-pipeline.log 2>&1; then
        rm -f "$stage_path"
        return 3
    fi
    chmod 644 "$stage_path"

    # Make sure the in-container helper script is current (idempotent on
    # subsequent runs — survives container recreates this way).
    docker cp /opt/yada-www/oo-convert.py "$ONLYOFFICE_CONTAINER":/tmp/oo-convert.py >/dev/null 2>&1

    local oo_rc=0 oo_output
    oo_output=$(docker exec -e JWT_SECRET="$jwt" "$ONLYOFFICE_CONTAINER" \
        python3 /tmp/oo-convert.py "$stage_name" 2>&1) || oo_rc=$?
    oo_rc=${oo_rc:-0}
    echo "$oo_output" >> /var/log/book-pipeline.log

    rm -f "$stage_path"

    if [ "$oo_rc" -ne 0 ]; then
        log "ONLYOFFICE convert failed (rc=$oo_rc) — falling back to LibreOffice"
        return 4
    fi

    # Pull the PDF out of the container into the destination.
    if ! docker exec "$ONLYOFFICE_CONTAINER" cat /tmp/oo-out.pdf > "$pdf_dest" 2>/dev/null; then
        log "ONLYOFFICE: docker cp of result failed — falling back to LibreOffice"
        rm -f "$pdf_dest"
        return 5
    fi
    if [ ! -s "$pdf_dest" ]; then
        log "ONLYOFFICE produced empty PDF — falling back to LibreOffice"
        rm -f "$pdf_dest"
        return 6
    fi
    chmod 644 "$pdf_dest" 2>/dev/null
    return 0
}

# Process a single job file.
process_job() {
    local job="$1"
    local volume_key docx_name pdf_name
    volume_key=$(grep -oE '"volume_key"\s*:\s*[0-9]+' "$job" | grep -oE '[0-9]+')
    docx_name=$(grep -oE '"docx_name"\s*:\s*"[^"]+"' "$job" | sed -E 's/.*"([^"]+)"$/\1/')
    pdf_name=$(grep -oE '"pdf_name"\s*:\s*"[^"]+"' "$job" | sed -E 's/.*"([^"]+)"$/\1/')

    if [ -z "$volume_key" ] || [ -z "$docx_name" ] || [ -z "$pdf_name" ]; then
        log "Bad job file (missing fields): $job"
        log_monitor_event "book_pipeline" "error" \
            "Malformed pipeline job file: $(basename "$job")" \
            "$(head -c 500 "$job")"
        mv "$job" "$JOBS_DIR/failed/"
        return
    fi

    log "Job start volume_key=$volume_key docx=$docx_name"
    update_status "$volume_key" "running" "Converting DOCX → PDF"

    local docx_path="$DOCX_DIR/$docx_name"
    if [ ! -f "$docx_path" ]; then
        # Missing DOCX is a setup gap, not a worker error — repeated retries
        # can never recover (only an admin re-upload can). Use status
        # 'waiting-docx' so the volume shows clearly in the books admin UI
        # and the FlipHTML5 retry loop in process_job below leaves it alone.
        log "DOCX not found: $docx_path — waiting for admin upload, not retrying"
        update_status "$volume_key" "waiting-docx" "DOCX file not found: $docx_name — re-upload via Admin → Books"
        mv "$job" "$JOBS_DIR/failed/"
        return
    fi

    # ── Phase 1: DOCX → PDF via Microsoft Graph (Word's own renderer) ──
    # The Graph API exposes desktop Word's PDF exporter as REST. Output is
    # bit-for-bit identical to File → Save As PDF in Word. This replaces
    # the OnlyOffice + LibreOffice paths (both still defined above as
    # legacy code, but no longer reachable) which produced kerning-collapse
    # bugs that destroyed inter-word spaces in justified condensed-spacing
    # paragraphs (Snake/Satanic had 200+ each).
    #
    # No fallback path. If Graph is down, the job remains queued (cron will
    # pick it up next tick) and the admin sees the failure clearly. Silent
    # fallback to a renderer with known PDF-text-quality issues was the
    # original sin we are eliminating.
    #
    # On retry the PDF is already on disk — skip conversion so retries
    # don't waste cycles re-converting an unchanged docx.
    if [ -f "$PDF_DIR/$pdf_name" ] && [ -s "$PDF_DIR/$pdf_name" ] && [ ! "$docx_path" -nt "$PDF_DIR/$pdf_name" ]; then
        log "PDF already on disk — skipping conversion (retry path): $PDF_DIR/$pdf_name"
    else
        # 1a. Primary: Microsoft Graph (fast, ~30s, but 60s server-side cap)
        local graph_output graph_rc=0
        graph_output=$(/opt/yada-www/parsers/docx_to_pdf_via_graph.py \
            --input  "$docx_path" \
            --output "$PDF_DIR/$pdf_name" 2>&1) || graph_rc=$?
        graph_rc=${graph_rc:-0}
        echo "$graph_output" >> /var/log/book-pipeline.log
        if [ "$graph_rc" -eq 2 ]; then
            # Exit 2 = PDF was written but failed validation
            # (producer not Word, or run-on tokens > threshold).
            log "Graph conversion produced suspect PDF for $docx_name — refusing to publish (rc=2)"
            update_status "$volume_key" "error" \
                "Graph PDF validation failed (producer != Word, or kerning regression)" \
                "$graph_output"
            rm -f "$PDF_DIR/$pdf_name"
            mv "$job" "$JOBS_DIR/failed/"
            return
        fi
        if [ "$graph_rc" -ne 0 ]; then
            # 1b. Fallback: Word for the Web via headless Playwright.
            # Graph's Python script already retries up to 10 times with
            # exponential backoff before returning rc≠0. By the time rc
            # reaches the shell, the failure is not a transient network
            # blip — fall through to Word Online regardless of whether
            # the root cause was a WRS timeout or a persistent 500.
            local wol_reason
            if echo "$graph_output" | grep -qE "Timeout_TaskCanceled|wordcs\.officeapps\.live\.com"; then
                wol_reason="Graph timed out"
            else
                wol_reason="Graph returned persistent errors (rc=$graph_rc)"
            fi
            log "Graph failed for $docx_name ($wol_reason) — falling back to Word for the Web"
            update_status "$volume_key" "running" "$wol_reason — falling back to Word for the Web"
            local wol_output wol_rc=0
            wol_output=$(/opt/yada-www/parsers/docx_to_pdf_via_word_online.py \
                --input  "$docx_path" \
                --output "$PDF_DIR/$pdf_name" 2>&1) || wol_rc=$?
            wol_rc=${wol_rc:-0}
            echo "$wol_output" >> /var/log/book-pipeline.log
            if [ "$wol_rc" -eq 3 ]; then
                log "Word Online auth expired for $docx_name — manual re-bootstrap required"
                update_status "$volume_key" "error" \
                    "Word Online auth state expired — re-run refresh_word_online_auth.py on a Windows machine and SCP the result to /opt/yada-www/secrets/" \
                    "$wol_output"
                mv "$job" "$JOBS_DIR/failed/"
                return
            fi
            if [ "$wol_rc" -eq 2 ]; then
                log "Word Online produced suspect PDF for $docx_name — refusing to publish"
                update_status "$volume_key" "error" \
                    "Word Online PDF validation failed (producer != Word, or kerning regression)" \
                    "$wol_output"
                rm -f "$PDF_DIR/$pdf_name"
                mv "$job" "$JOBS_DIR/failed/"
                return
            fi
            if [ "$wol_rc" -ne 0 ]; then
                log "Word Online fallback failed for $docx_name (rc=$wol_rc) — leaving job queued for next tick"
                update_status "$volume_key" "warning" \
                    "Both Graph and Word Online failed (Graph rc=$graph_rc, WOL rc=$wol_rc) — retry next tick" \
                    "$wol_output"
                return
            fi
            log "Word Online fallback succeeded for $docx_name"
        fi
        if [ ! -s "$PDF_DIR/$pdf_name" ] || [ $(stat -c%s "$PDF_DIR/$pdf_name") -lt 10000 ]; then
            log "PDF output missing/tiny for $docx_name — failing job"
            update_status "$volume_key" "error" \
                "PDF output missing or implausibly small after Graph + Word-Online" ""
            rm -f "$PDF_DIR/$pdf_name"
            mv "$job" "$JOBS_DIR/failed/"
            return
        fi
        chmod 644 "$PDF_DIR/$pdf_name" 2>/dev/null
        log "PDF generated for $docx_name ($(stat -c%s "$PDF_DIR/$pdf_name") bytes)"
    fi

    update_outputs "$volume_key" "$pdf_name" ""
    update_status "$volume_key" "running" "PDF generated; uploading to FlipHTML5"

    # ── Phase 2: FlipHTML5 upload (best-effort) ──
    # Runs the Node-based puppeteer worker inside the rsshub container, which has
    # puppeteer-real-browser installed. The worker does the upload and outputs the
    # new flip_code on stdout; on error or container missing, we keep the PDF and
    # report partial success — the admin can paste the flip_code manually.
    local flip_code=""
    # Per-phase pre-flight: don't launch Chromium if the host is already busy.
    # The book-pipeline-worker's outer pre-flight uses MIN_FREE_MB=1500; for
    # the puppeteer step we want a stricter floor since Chromium adds ~500MB.
    local fhx_free fhx_load
    fhx_free=$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
    fhx_load=$(awk '{print $1}' /proc/loadavg)
    local fhx_skip=0
    if [ "${fhx_free:-0}" -lt 1800 ]; then fhx_skip=1; fi
    if awk -v l="$fhx_load" 'BEGIN{exit !(l>4.0)}'; then fhx_skip=1; fi

    # Pull existing flip_code from DB so re-upload mode kicks in (if no code,
    # the upload script exits 2 → first-time uploads need manual handling).
    local existing_flip
    existing_flip=$(docker exec "$PG_CONTAINER" psql -U postgres -d yada -t -A -c \
        "SELECT COALESCE(volume_flip_code, '') FROM yy_volume WHERE volume_key=$volume_key" 2>/dev/null \
        | tr -d '[:space:]')

    if [ "$fhx_skip" = "1" ]; then
        log "FlipHTML5 phase deferred (free=${fhx_free}MB load=${fhx_load}); PDF kept, will retry on next upload"
        update_status "$volume_key" "warning" "PDF generated; FlipHTML5 deferred to next tick (host load)" \
            "free=${fhx_free}MB load=${fhx_load}"
    elif docker ps --format '{{.Names}}' | grep -q "^${RSSHUB_CONTAINER}$" \
       && [ -f /opt/yada-www/rumble-scraper/yy/fliphtml5-upload.cjs ] \
       && [ -f /opt/yada-www/.env ]; then
        # Pull credentials from the host .env (POSTGRES_* lives there too).
        local fhx_email fhx_pass
        fhx_email=$(grep '^FLIPHTML5_EMAIL=' /opt/yada-www/.env 2>/dev/null | cut -d= -f2- | tr -d '"')
        fhx_pass=$(grep '^FLIPHTML5_PASS=' /opt/yada-www/.env 2>/dev/null | cut -d= -f2- | tr -d '"')
        if [ -z "$fhx_email" ] || [ -z "$fhx_pass" ]; then
            log "FlipHTML5 credentials missing in /opt/yada-www/.env — skipping"
            update_status "$volume_key" "warning" "PDF generated; FlipHTML5 credentials not configured" ""
        else
            log "Triggering FlipHTML5 re-upload via $RSSHUB_CONTAINER (existing flip=${existing_flip:-NONE})"
            local up_output up_rc
            # Detect Chrome binary path inside the container so puppeteer-real-browser
            # can find it via CHROME_PATH (it doesn't fall back to its own cache).
            local chrome_bin
            chrome_bin=$(docker exec "$RSSHUB_CONTAINER" find /app/node_modules/.cache/puppeteer -name "chrome" -type f 2>/dev/null | head -1)
            local chrome_env=()
            [ -n "$chrome_bin" ] && chrome_env=(-e "CHROME_PATH=$chrome_bin")
            # systemd-run cgroup ceiling: 700M is enough for headless Chromium with
            # images/extensions disabled; CPUQuota lets it use one core but yield
            # under load. Inner --js-flags=--max-old-space-size=400 inside the JS
            # is the soft ceiling; this 700M is the hard ceiling.
            # Script lives at /scraper/yy/fliphtml5-upload.cjs inside the
            # container (bind-mounted from /opt/yada-www/rumble-scraper/yy/
            # on the host). docker exec ignores -v flags, so we can't add
            # mounts at exec time — the script HTTP-fetches the PDF instead.
            # Hard timeout on the docker exec — without one, a hung
            # puppeteer-real-browser session inside the container will
            # hold the global pipeline lock forever (observed: 1h 49m
            # before manual kill, blocking every other queued volume).
            # 600s is generous: a healthy upload runs in 30-90s.
            # `timeout --signal=KILL` because puppeteer ignores SIGTERM
            # when stuck mid-protocol; SIGKILL is the only thing that
            # frees the lock. timeout exits 124 on timeout, 137 on KILL.
            up_output=$(timeout --signal=KILL 600 systemd-run --scope --quiet --wait \
                --property=MemoryMax=700M \
                --property=MemorySwapMax=0 \
                --property=CPUQuota=50% \
                docker exec \
                    -e PDF_NAME="$pdf_name" \
                    -e VOLUME_KEY="$volume_key" \
                    -e FLIP_CODE="$existing_flip" \
                    -e FLIP_EMAIL="$fhx_email" \
                    -e FLIP_PASS="$fhx_pass" \
                    "${chrome_env[@]}" \
                    "$RSSHUB_CONTAINER" /scraper/yy/fliphtml5-upload-wrapper.sh 2>&1) || up_rc=$?
            up_rc=${up_rc:-0}
            if [ "$up_rc" -eq 124 ] || [ "$up_rc" -eq 137 ]; then
                log "FlipHTML5 upload timed out after 600s (rc=$up_rc) — killed to release lock"
                log_monitor_event "book_pipeline" "warning" \
                    "FlipHTML5 upload timed out for $pdf_name (volume_key=$volume_key)" \
                    "Killed by timeout(1) after 600s. Puppeteer in $RSSHUB_CONTAINER was likely stuck mid-protocol."
                # Leave any stray docker exec processes for the next tick to find.
                # The puppeteer Chrome inside the container may still be alive;
                # `pkill chrome` inside the container would be a separate fix.
            fi
            echo "$up_output" >> /var/log/book-pipeline.log
            flip_code=$(echo "$up_output" | tail -1 | tr -d '[:space:]')
            if ! echo "$flip_code" | grep -qE '^[A-Za-z0-9_-]+$'; then
                flip_code=""
            fi
            # (exit 2 used to mean "first-time upload requires manual step"
            #  — that's now automated, so any non-zero exit is a real error.)
        fi
        if [ "$up_rc" -eq 0 ] && [ -n "$flip_code" ]; then
            log "FlipHTML5 upload returned flip_code=$flip_code"
            update_outputs "$volume_key" "$pdf_name" "$flip_code"
            update_status "$volume_key" "running" "FlipHTML5 uploaded ($flip_code); downloading offline package"

            # ── Phase 3: download .zip and self-host ──
            local zip_dir="$FLIP_DIR/$flip_code"
            mkdir -p "$zip_dir"
            local dl_output dl_rc
            # Same 600s timeout as the upload step — puppeteer-real-browser
            # can hang here too on the FlipHTML5 download flow (different
            # script, same root cause). 124/137 = killed by timeout.
            dl_output=$(timeout --signal=KILL 600 docker exec -e FLIP_CODE="$flip_code" -e OUT_DIR="/tmp/flip_$flip_code" \
                "$RSSHUB_CONTAINER" node /scraper/yy/fliphtml5-download.cjs 2>&1) || dl_rc=$?
            dl_rc=${dl_rc:-0}
            if [ "$dl_rc" -eq 124 ] || [ "$dl_rc" -eq 137 ]; then
                log "Flipbook download timed out after 600s (rc=$dl_rc) — killed to release lock"
                log_monitor_event "book_pipeline" "warning" \
                    "Flipbook download timed out for flip_code=$flip_code (volume_key=$volume_key)" \
                    "Killed by timeout(1) after 600s. Puppeteer download flow was stuck."
            fi
            echo "$dl_output" >> /var/log/book-pipeline.log
            if [ "$dl_rc" -eq 0 ]; then
                log "Flipbook downloaded + extracted to $zip_dir"
                update_status "$volume_key" "success" "PDF + FlipHTML5 + offline package complete"
            else
                log "Flipbook download failed (exit $dl_rc; kept PDF + FlipHTML5)"
                update_status "$volume_key" "warning" "PDF + FlipHTML5 done; offline package download failed (exit $dl_rc)" "$dl_output"
            fi
        else
            # Auto-requeue on transient FlipHTML5 failure (Puppeteer flake,
            # site CAPTCHA, selector timing). Cap at 3 so a permanently broken
            # upload doesn't loop forever.
            local retry_max=3
            local retry_now
            retry_now=$(docker exec "$PG_CONTAINER" psql -U postgres -d yada -t -A -c \
                "UPDATE yy_volume SET volume_pipeline_retry_count = COALESCE(volume_pipeline_retry_count, 0) + 1
                 WHERE volume_key = $volume_key
                 RETURNING volume_pipeline_retry_count" 2>/dev/null | tr -d '[:space:]')
            retry_now=${retry_now:-99}
            if [ "$retry_now" -lt "$retry_max" ]; then
                log "FlipHTML5 upload failed (exit $up_rc) — auto-requeueing (attempt $retry_now/$retry_max)"
                update_status "$volume_key" "queued" \
                    "FlipHTML5 upload failed (exit $up_rc) — auto-retry $retry_now/$retry_max queued" \
                    "$up_output"
                # Leave the job file in JOBS_DIR for the next cron tick.
                return 0
            else
                log "FlipHTML5 upload failed (exit $up_rc) — retry cap ($retry_max) reached, marking error"
                update_status "$volume_key" "error" \
                    "FlipHTML5 upload failed (exit $up_rc) after $retry_max retries — manual intervention required" \
                    "$up_output"
            fi
        fi
    else
        log "Skipping FlipHTML5 (rsshub container or fliphtml5-upload.cjs missing)"
        local skip_detail="rsshub_running=$(docker ps --format '{{.Names}}' | grep -c \"^${RSSHUB_CONTAINER}\$\"); upload_script_present=$( [ -f /opt/yada-www/fliphtml5-upload.cjs ] && echo yes || echo no )"
        update_status "$volume_key" "success" "PDF generated; FlipHTML5 disabled (manual upload only)" "$skip_detail"
    fi

    # ── Phase 4: paragraph + translation extraction ──────────────────
    # Runs the dedicated per-volume parser which deletes ONLY this volume's
    # rows in yy_paragraph + yy_translation and re-inserts from the new docx.
    # Honors yy_volume.volume_parse_flag (if FALSE, parser exits early with
    # status='skipped'). Failures here log a monitor event but do NOT mark
    # the docx→pdf→flip pipeline as failed — the artifacts are still good.
    if [ -x /opt/yada-www/parsers/parse_volume_from_bundle.py ]; then
        log "Running paragraph + translation extraction (capped at $PARSER_MEM_MAX)"
        local pv_output pv_rc
        pv_output=$(systemd-run --scope --quiet \
            --property=MemoryMax=$PARSER_MEM_MAX \
            --property=MemorySwapMax=0 \
            --property=CPUQuota=$PARSER_CPU_QUOTA \
            python3 /opt/yada-www/parsers/parse_volume_from_bundle.py --volume-key "$volume_key" 2>&1) || pv_rc=$?
        pv_rc=${pv_rc:-0}
        echo "$pv_output" >> /var/log/book-pipeline.log
        if [ "$pv_rc" -ne 0 ]; then
            log "Parser failed for volume $volume_key (exit $pv_rc) — pipeline artifacts kept"
            log_monitor_event "book_parse" "error" \
                "Volume $volume_key parser failed (exit $pv_rc)" "$pv_output"
        else
            log "Parser succeeded for volume $volume_key"
        fi
    else
        log "Skipping parser (parse_volume.py not deployed/executable)"
    fi

    mv "$job" "$JOBS_DIR/done/"
    log "Job done volume_key=$volume_key"
}

# Process at most ONE job per invocation. Each job runs LibreOffice
# (DOCX→PDF) then Puppeteer/Chrome (FlipHTML5 upload + download). Both
# are RAM-heavy on a 7.8G host — running two simultaneously would race
# the systemd-run cgroups and the FlipHTML5 login session. Cron ticks
# every 15 min, so the next queued volume picks up on the next tick.
shopt -s nullglob
for j in "$JOBS_DIR"/*.json; do
    process_job "$j"
    log "Processed one job — yielding to next cron tick (single-volume-per-run policy)"
    break
done

# ── Phase 4.5: stale-artifact sweep ──────────────────────────────
# Auto-heal volumes whose pipeline ended in a non-final state (warning,
# error with retries left) or whose DOCX is now newer than the PDF. Without
# this, a docx that ran into a transient FlipHTML5 / load-gate failure sits
# in 'warning' forever. We just write a job file and let the regular
# process_job loop pick it up on the NEXT tick. Cap at 5 per tick.
sweep_count=0
sweep_rows=$(docker exec "$PG_CONTAINER" psql -U postgres -d yada -At -F'|' -c "
    SELECT v.volume_key,
           v.volume_docx,
           v.volume_pdf,
           COALESCE(v.volume_flip_code, ''),
           COALESCE(v.volume_pipeline_status, ''),
           COALESCE(v.volume_pipeline_retry_count, 0)
      FROM yy_volume v
     WHERE v.volume_docx IS NOT NULL
       AND v.volume_docx <> ''
       AND COALESCE(v.volume_pipeline_status, '') NOT IN ('running','waiting-docx')
     ORDER BY v.volume_key
" 2>/dev/null)
while IFS='|' read -r vk docx pdf flip status retries; do
    [ -z "$vk" ] && continue
    [ "$sweep_count" -ge 5 ] && break

    # Skip if a job file is already queued for this volume.
    # Using `find` not `ls` because nullglob is enabled and an unmatched
    # glob would leave `ls` with no args, listing CWD instead of returning
    # empty — which made every volume falsely look like it had a queued job.
    existing=$(find "$JOBS_DIR" -maxdepth 1 -name "$(printf '%010d' "$vk")_*.json" -print -quit 2>/dev/null)
    [ -n "$existing" ] && continue

    docx_path="$DOCX_DIR/$docx"
    pdf_path="$PDF_DIR/$pdf"
    needs_requeue=0
    reason=""

    case "$status" in
        warning|error|queued|"")
            # Stuck in a non-final state — but only retry while under the cap
            # (retry_count is reset to 0 by admin re-queue / docx re-upload).
            if [ "${retries:-0}" -lt 3 ]; then
                needs_requeue=1
                reason="status=${status:-unset}, retry $retries/3"
            fi
            ;;
        success)
            # Re-queue when the on-disk artifacts disagree with 'success':
            #   - PDF missing entirely (manual cleanup, accidental delete,
            #     or a prior render that produced nothing)
            #   - DOCX is newer than the PDF (admin uploaded a new revision
            #     of the docx since the last successful render)
            # Both end with the same fix: run LibreOffice/ONLYOFFICE again.
            if [ -f "$docx_path" ]; then
                if [ ! -f "$pdf_path" ]; then
                    needs_requeue=1
                    reason="PDF missing on disk"
                elif [ "$docx_path" -nt "$pdf_path" ]; then
                    needs_requeue=1
                    reason="DOCX newer than PDF"
                fi
            fi
            ;;
    esac

    [ "$needs_requeue" = "1" ] || continue

    ts_now=$(date -u +%s)
    job_file="$JOBS_DIR/$(printf '%010d' "$vk")_stalesweep_${ts_now}.json"
    flip_field="null"
    [ -n "$flip" ] && flip_field="\"$flip\""
    # Escape backslash + double-quote for JSON safety; apostrophes are fine
    # inside double-quoted JSON strings.
    docx_esc=$(printf '%s' "$docx" | sed 's/\\/\\\\/g; s/"/\\"/g')
    pdf_esc=$(printf '%s' "$pdf" | sed 's/\\/\\\\/g; s/"/\\"/g')
    printf '{"volume_key":%s,"docx_name":"%s","pdf_name":"%s","flip_code":%s,"queued_at":"%s","auto_retry":true,"source":"stale-sweep"}\n' \
        "$vk" "$docx_esc" "$pdf_esc" "$flip_field" "$(date -u +%FT%TZ)" > "$job_file"

    docker exec "$PG_CONTAINER" psql -U postgres -d yada -c \
        "UPDATE yy_volume SET volume_pipeline_status='queued', volume_pipeline_message='Re-queued by stale-sweep: $reason' WHERE volume_key=$vk" >/dev/null 2>&1

    log "Stale-sweep: queued volume $vk ($reason)"
    sweep_count=$((sweep_count + 1))
done <<< "$sweep_rows"

# ── Phase 6: flipbook regen sweep ─────────────────────────────────
# When a PDF is newer than its self-hosted flipbook (or the flipbook is
# missing), regenerate via migrate_flipbook.sh. Caps at 1 volume per tick —
# pdftoppm at 200dpi is heavy, and process_job above already may have run
# DOCX→PDF + parser this tick. The sweep walks volumes in volume_key order
# and picks the first stale slug it finds, so when many books are stale we
# work through them deterministically over successive ticks.
#
# Slug convention matches migrate_flipbook.sh: spaces→hyphens, apostrophes
# stripped. PDF stem keeps apostrophes (so file lookup matches what
# pdftoppm/yy_volume reference). The slug is the LIVE URL path under
# /opt/yada-www/public/<slug>/.
if [ -x /opt/yada-www/migrate_flipbook.sh ]; then
    flipbook_rows=$(docker exec "$PG_CONTAINER" psql -U postgres -d yada -At -F'|' -c "
        SELECT replace(replace(volume_file, ' ', '-'), '''', '') AS slug,
               volume_pdf                                          AS pdf_name
          FROM yy_volume
         WHERE volume_file IS NOT NULL
           AND volume_file <> ''
         ORDER BY volume_key
    " 2>/dev/null)

    fb_target_slug=""
    fb_target_reason=""
    # Use volume_pdf directly as the on-disk PDF filename. Deriving the
    # stem from `replace(volume_file, ' ', '-')` previously kept apostrophes
    # that the actual files don't have (e.g. Mow'ed-Appointments vs
    # Mowed-Appointments.pdf), making the [-f "$fb_pdf"] check fail for
    # apostrophe-containing labels and silently skipping those volumes
    # every sweep tick.
    while IFS='|' read -r fb_slug fb_pdf_name; do
        [ -z "$fb_slug" ] && continue
        [ -z "$fb_pdf_name" ] && continue
        fb_pdf="$PDF_DIR/$fb_pdf_name"
        [ ! -f "$fb_pdf" ] && continue
        # Per-book wrapper now lives at index.php (shared-shell shim);
        # legacy index.html still exists in a handful of un-migrated
        # dirs and also counts as "present". Migrate when PDF is newer
        # than whichever wrapper is in place.
        fb_index_php="/opt/yada-www/public/$fb_slug/index.php"
        fb_index_html="/opt/yada-www/public/$fb_slug/index.html"
        if   [ -f "$fb_index_php" ];  then fb_index="$fb_index_php"
        elif [ -f "$fb_index_html" ]; then fb_index="$fb_index_html"
        else
            fb_target_slug="$fb_slug"
            fb_target_reason="flipbook missing"
            break
        fi
        if [ "$fb_pdf" -nt "$fb_index" ]; then
            fb_target_slug="$fb_slug"
            fb_target_reason="PDF newer than flipbook"
            break
        fi
    done <<< "$flipbook_rows"

    if [ -n "$fb_target_slug" ]; then
        log "Flipbook-sweep: regenerating $fb_target_slug ($fb_target_reason)"
        fb_rc=0
        fb_output=$(systemd-run --scope --quiet \
            --property=MemoryMax=1500M \
            --property=CPUQuota=50% \
            /opt/yada-www/migrate_flipbook.sh "$fb_target_slug" 2>&1) || fb_rc=$?
        echo "$fb_output" >> /var/log/book-pipeline.log
        if [ "$fb_rc" -ne 0 ]; then
            log "Flipbook-sweep: $fb_target_slug failed (exit $fb_rc)"
            log_monitor_event "flipbook_regen" "error" \
                "Flipbook regen failed for slug $fb_target_slug (exit $fb_rc)" "$fb_output"
        else
            log "Flipbook-sweep: $fb_target_slug regenerated successfully"
        fi
    fi
fi

# ── Phase 5: parse-only sweep ─────────────────────────────────────
# Pick up volumes that already have a docx but no parse output (or status
# 'queued'/'error'/'stale'). These appear as 'stale' in the admin UI because
# their parse work was never triggered (e.g. docx was uploaded before the
# parser pipeline existed, or a previous parse errored). Process at most 1
# per tick to avoid hogging the host. The next 2-min tick picks up the next.
if [ -x /opt/yada-www/parsers/parse_volume_from_bundle.py ]; then
    parse_target=$(docker exec "$PG_CONTAINER" psql -U postgres -d yada -At -c "SELECT volume_key FROM yy_volume WHERE volume_docx IS NOT NULL AND (volume_parse_status IS NULL OR volume_parse_status IN ('queued','stale')) ORDER BY CASE WHEN volume_parse_status='queued' THEN 0 ELSE 1 END, volume_key LIMIT 1")
    if [ -n "$parse_target" ]; then
        log "Parse-sweep: picking up volume $parse_target"
        ps_rc=0
        ps_output=$(systemd-run --scope --quiet \
            --property=MemoryMax=$PARSER_MEM_MAX \
            --property=MemorySwapMax=0 \
            --property=CPUQuota=$PARSER_CPU_QUOTA \
            python3 /opt/yada-www/parsers/parse_volume_from_bundle.py --volume-key "$parse_target" 2>&1) || ps_rc=$?
        echo "$ps_output" >> /var/log/book-pipeline.log
        if [ "$ps_rc" -ne 0 ]; then
            log "Parse-sweep: volume $parse_target failed (exit $ps_rc)"
            log_monitor_event "book_parse" "error" "Volume $parse_target parse-sweep failed (exit $ps_rc)" "$ps_output"
        else
            log "Parse-sweep: volume $parse_target succeeded"
        fi
    fi
fi
