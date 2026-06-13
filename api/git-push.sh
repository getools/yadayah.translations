#!/bin/bash
# Sync changed code files from the deployment directory to the git repo and push.
# Called after auto-fix applies changes, or via cron.
#
# Usage: /opt/yada-www/api/git-push.sh [commit message]

DEPLOY_DIR="/opt/yada-www"
GIT_DIR="/opt/yada-git"

# Use summary file from auto-fix if available, otherwise use argument or default
SUMMARY_FILE="/tmp/auto-fix-summary.txt"
if [ -f "$SUMMARY_FILE" ]; then
    MSG="$(cat "$SUMMARY_FILE")"
    rm -f "$SUMMARY_FILE"
elif [ -n "$1" ]; then
    MSG="$1"
else
    MSG="Auto-fix applied changes"
fi

cd "$GIT_DIR" || exit 1

# Abort any in-progress rebase and sync local master to remote before rsync.
# This ensures our rsync'd changes are applied on top of origin rather than
# stale local commits that would conflict on push.
git rebase --abort 2>/dev/null || true
git fetch origin master 2>&1
git checkout -B master origin/master 2>&1

# Sync API PHP files and supporting config/scripts without extensions
rsync -a --delete "$DEPLOY_DIR/api/" "$GIT_DIR/api/" \
    --include='*.php' --include='*.sh' --include='*.conf' \
    --include='yt-dlp-plugin' \
    --include='*/' --exclude='*'

# Sync only specific public subdirectories that contain code
rsync -a "$DEPLOY_DIR/public/css/" "$GIT_DIR/public/css/" \
    --include='*.css' --include='*/' --exclude='*'

rsync -a "$DEPLOY_DIR/public/js/" "$GIT_DIR/public/js/" \
    --include='*.js' --include='*/' --exclude='*'

# Sync public root HTML files only (no subdirectories)
rsync -a "$DEPLOY_DIR/public/" "$GIT_DIR/public/" \
    --include='*.html' --exclude='*/' --exclude='*'

# Sync parsers (Python scripts for document processing pipelines)
mkdir -p "$GIT_DIR/parsers"
rsync -a --delete "$DEPLOY_DIR/parsers/" "$GIT_DIR/parsers/" \
    --include='*.py' --exclude='__pycache__/' --include='*/' --exclude='*'

# Sync flipbook templates (per-book slim shell + helper assets)
mkdir -p "$GIT_DIR/templates"
rsync -a --delete "$DEPLOY_DIR/templates/" "$GIT_DIR/templates/"

# Sync rumble-scraper scripts (shell and Node.js)
mkdir -p "$GIT_DIR/rumble-scraper/yy"
rsync -a --delete "$DEPLOY_DIR/rumble-scraper/yy/" "$GIT_DIR/rumble-scraper/yy/" \
    --include='*.sh' --include='*.cjs' --include='*.js' --include='*/' --exclude='*'

# Sync root config files
for f in .htaccess CLAUDE.md Dockerfile docker-compose.yml docker-compose.prod.yml docker-entrypoint.sh php.ini cron-monitor.sh cron-backup.sh book-pipeline-worker.sh migrate_flipbook.sh migrate_all_flipbooks.sh Caddyfile claude-fix-runner.sh cron-claude-fix-gated.sh cron-flipbook-retry.sh cron-recording-lock-cleanup.sh cron-fliphtml5-match.sh cron-fliphtml5-match-trigger.sh cron-fliphtml5-match.py flipbook-download-host.sh fliphtml5-download.cjs fliphtml5-upload.cjs process-email-cron.sh sync-blog-cron.sh sync-invite-cron.sh; do
    [ -f "$DEPLOY_DIR/$f" ] && cp -f "$DEPLOY_DIR/$f" "$GIT_DIR/$f" 2>/dev/null
done

# Check if there are any changes
if git diff --quiet && git diff --cached --quiet && [ -z "$(git ls-files --others --exclude-standard)" ]; then
    echo "[git-push] No changes to push"
    exit 0
fi

# Stage, commit, push
git add -A
git commit -m "$MSG

Co-Authored-By: Yada Auto-Fix <autofix@yadayah.com>"
git push origin master

echo "[git-push] Pushed: $MSG"

# ── Post-push smoke verification ────────────────────────────────────────
# Any change that lands in production should be sanity-checked immediately.
# Runs yy_test #18 ("Flipbook assets serve") which HEADs one of each bundle
# asset type per book — pages JPG, thumbs JPG, text JSON, toc.json,
# search.json. Catches perm regressions (mode 700 dirs → 403), broken
# rewrites, deleted assets, and Apache routing changes within seconds.
# Failures log loudly here AND file a row in yy_test_log + yy_monitor_event
# so auto-fix can act on them next tick instead of waiting for the daily run.
echo "[git-push] Smoke-testing bundle assets…"
SMOKE_OUT=$(docker exec yada-www-web-1 \
    curl -sk "http://localhost/api/cron-test.php?key=yada2026test&test_key=18" 2>&1)
SMOKE_STATUS=$(echo "$SMOKE_OUT" | grep -oE '"status":"[^"]+"' | head -1 | sed 's/.*:"//;s/"//')
if [ "$SMOKE_STATUS" = "pass" ]; then
    echo "[git-push] Smoke test: PASS"
else
    echo "[git-push] !!! SMOKE TEST FAILED — see yy_test_log + yy_monitor_event"
    echo "$SMOKE_OUT" | head -40
fi
