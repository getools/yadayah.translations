#!/bin/bash
# Migrate one volume's URL from FlipHTML5 → custom self-hosted flipbook.
#
# Usage:  migrate_flipbook.sh <url-slug>
#         e.g.  migrate_flipbook.sh YY-s02v01-Yada-Yahowah-Baresyth-Beginning
#
# url-slug is the disk-stem WITHOUT apostrophes — i.e., what the existing
# FlipHTML5 install dir at /opt/yada-www/public/<url-slug>/ is named, and
# what URLs link to (per translations.html slugify rule).
#
# Steps:
#   1. Resolve canonical PDF stem (with apostrophes) and volume_key from
#      yy_volume by inverting the slugify rule.
#   2. Render assets via pdftoppm + the /tmp/extract_*.py helpers into a
#      temp dir, then a /opt/yada-www/public/_staging_flipbook/<slug>/.
#   3. Render index.html from the template substituting {{TITLE}},
#      {{TOTAL}}, {{BOOK_CODE}}, {{PDF_PATH}}.
#   4. Atomic-ish swap: existing /public/<slug>/ → /opt/yada-www/_archive_fliphtml5/<slug>/,
#      then staging → /public/<slug>/.
#
# Idempotent: re-running on an already-migrated slug rebuilds + re-swaps,
# moving the previously-live (now custom) install to _archive again — but
# that's almost never what you want; check migration_progress.log first.
#
# Exit non-zero on any failure; never half-swaps.
set -euo pipefail

URL_SLUG="${1:?url-slug required, e.g. YY-s02v03-Yada-Yahowah-Beyth-In-the-Family}"
PUBLIC=/opt/yada-www/public
ARCHIVE=/opt/yada-www/_archive_fliphtml5
TEMPLATE=/opt/yada-www/templates/flipbook-index.php.tpl
STAGING="$PUBLIC/_staging_flipbook/$URL_SLUG"
LIVE="$PUBLIC/$URL_SLUG"

if [ ! -f "$TEMPLATE" ]; then
    echo "missing template: $TEMPLATE" >&2; exit 1
fi

log() { echo "[$(date +%H:%M:%S)] [$URL_SLUG] $*"; }

# ── 1. Resolve volume from DB by inverting the slugify rule ─────────
# DB volume_file uses spaces and (sometimes) apostrophes; the URL slug
# has spaces→hyphens and apostrophes stripped. The PDF on disk now also
# follows the apostrophe-stripped convention (matches URL_SLUG exactly),
# so we don't need a separate PDF_STEM — URL_SLUG is the single source.
ROW=$(docker exec yada-postgres-prod psql -U postgres -d yada -At -F '|' -c "
  SELECT volume_key, volume_label
    FROM yy_volume
   WHERE replace(replace(volume_file, ' ', '-'), '''', '') = '$URL_SLUG'
   LIMIT 1
")
if [ -z "$ROW" ]; then
    log "no yy_volume matches URL slug — skipping"
    exit 1
fi
VOL_KEY=$(echo "$ROW" | cut -d'|' -f1)
VOL_LABEL=$(echo "$ROW" | cut -d'|' -f2)
PDF_STEM="$URL_SLUG"
PDF="$PUBLIC/pdf/$PDF_STEM.pdf"

if [ ! -f "$PDF" ]; then
    log "no PDF at $PDF — skipping"
    exit 1
fi

# Title: replace the FIRST hyphen in volume_label with " — " (em-dash).
# Single-word labels (no hyphen) pass through untouched.
TITLE=$(echo "$VOL_LABEL" | sed -E 's/-/ \xe2\x80\x94 /')

log "starting build (vol_key=$VOL_KEY, pdf_stem=$PDF_STEM, title=\"$TITLE\")"

# ── 2. Render assets ────────────────────────────────────────────────
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/pages" "$TMP/thumbs" "$TMP/text"

log "rendering pages (200dpi)…"
pdftoppm -jpeg -r 200 -jpegopt 'quality=85,progressive=y' "$PDF" "$TMP/pages/page" >/dev/null 2>&1

log "rendering thumbs (33dpi)…"
pdftoppm -jpeg -r 33 -jpegopt 'quality=80' "$PDF" "$TMP/thumbs/thumb" >/dev/null 2>&1

# pdftoppm names files page-1.jpg, page-2.jpg, etc. Re-pad to 3 digits.
# Force base-10 so already-padded names like 008 don't trip printf's octal
# parser (which rejects 8 / 9 as invalid octal digits).
pad_dir() {
    local d="$1" prefix="$2" f b n padded
    for f in "$d"/${prefix}-*.jpg; do
        b=$(basename "$f")
        n=$(echo "$b" | sed -E "s/^${prefix}-([0-9]+)\.jpg/\1/")
        padded=$(printf '%03d' "$((10#$n))")
        if [ "$n" != "$padded" ]; then
            mv "$f" "$d/${prefix}-${padded}.jpg"
        fi
    done
}
pad_dir "$TMP/pages" page
pad_dir "$TMP/thumbs" thumb

TOTAL=$(ls "$TMP/pages" | wc -l)
if [ "$TOTAL" -lt 1 ]; then
    log "ERROR: rendered 0 pages from $PDF — aborting"
    exit 1
fi
log "rendered $TOTAL pages"

log "extract_text → search.json…"
# Same `/tmp/extract_text.py-vanished` regression as extract_toc — the
# durable copy now lives in /parsers/. Without it every migrate has been
# falling through to an empty search index for months.
python3 /opt/yada-www/parsers/extract_text.py "$PDF" "$TMP/search.json" 2>>"$TMP/extract_text.err" || {
    log "WARN: extract_text failed ($(tail -1 "$TMP/extract_text.err" 2>/dev/null)); writing empty search.json"
    echo '{"pages":[]}' > "$TMP/search.json"
}

log "extract_text_layer → text/…"
python3 /opt/yada-www/parsers/extract_text_layer.py "$PDF" "$TMP/text" 2>>"$TMP/extract_text_layer.err" || {
    log "WARN: extract_text_layer failed ($(tail -1 "$TMP/extract_text_layer.err" 2>/dev/null)); text overlays will be empty"
}

log "extract_toc → toc.json…"
# Reads doc.get_toc() from the PDF and writes the flipbook viewer's
# expected JSON shape. The previous /tmp/extract_toc_v3.py was wiped
# silently and migrations had been falling through to {"toc":[]}
# placeholders for months — the durable copy now lives in /parsers/.
python3 /opt/yada-www/parsers/extract_toc.py "$PDF" "$VOL_KEY" "$TMP/toc.json" 2>>"$TMP/extract_toc.err" || {
    log "WARN: extract_toc failed ($(tail -1 "$TMP/extract_toc.err" 2>/dev/null)); writing empty TOC"
    echo '{"toc":[]}' > "$TMP/toc.json"
}

# ── 3. Render index.php from template ───────────────────────────────
# All HTML/CSS/JS now lives in /public/_shared/flipbook-frame.php — the
# per-book file is just a 7-line wrapper setting $FB['total','title',
# 'bookCode'] and require'ing the shared shell. Bug fixes + cache bumps
# happen in one place; books pick them up on the next request.

# Escape the title for embedding in a PHP single-quoted literal: only `\`
# and `'` need attention. `&` and `|` are also escaped here because they
# pass through sed below.
esc_sed_repl() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/&/\\&/g' -e 's/|/\\|/g'
}
esc_php_squote() {
    # PHP single-quoted strings only need backslash + apostrophe escaped.
    printf '%s' "$1" | sed -e "s/\\\\/\\\\\\\\/g" -e "s/'/\\\\'/g"
}
TITLE_PHP=$(esc_sed_repl "$(esc_php_squote "$TITLE")")
URL_SLUG_E=$(esc_sed_repl "$URL_SLUG")

# Build stamp → flipbook-frame.php emits it as FLIPBOOK_CONFIG.bundleVersion,
# and flipbook-viewer.js appends it as ?v= to every page/thumb/text/toc/search
# URL. Those paths are byte-identical across rebuilds, so without a fresh
# stamp a browser keeps serving the previous build's cached images.
BUNDLE_VERSION=$(date -u +%Y%m%d%H%M%S)

sed \
    -e "s|{{TITLE_PHP}}|$TITLE_PHP|g" \
    -e "s|{{TOTAL}}|$TOTAL|g" \
    -e "s|{{BOOK_CODE}}|$URL_SLUG_E|g" \
    -e "s|{{BUNDLE_VERSION}}|$BUNDLE_VERSION|g" \
    "$TEMPLATE" > "$TMP/index.php"

# Sanity: ensure no placeholder slipped through.
if grep -q '{{[A-Z_]*}}' "$TMP/index.php"; then
    log "ERROR: unresolved placeholders in rendered index.php — aborting"
    grep -n '{{[A-Z_]*}}' "$TMP/index.php" | head -5 >&2
    exit 1
fi

# ── 4. Stage + atomic swap ──────────────────────────────────────────
# Build a fresh staging dir from scratch each time so a previous half-
# baked staging from an aborted run doesn't bleed in.
rm -rf "$STAGING"
mkdir -p "$STAGING/pages" "$STAGING/thumbs" "$STAGING/text"
rsync -a --delete "$TMP/pages/"  "$STAGING/pages/"
rsync -a --delete "$TMP/thumbs/" "$STAGING/thumbs/"
rsync -a --delete "$TMP/text/"   "$STAGING/text/"
cp "$TMP/search.json" "$STAGING/search.json"
cp "$TMP/toc.json"    "$STAGING/toc.json"
cp "$TMP/index.php"   "$STAGING/index.php"

mkdir -p "$ARCHIVE"
if [ -d "$LIVE" ]; then
    # If we re-migrate the same slug, the prior archive would block us.
    # Suffix with a timestamp so the original FlipHTML5 archive is preserved
    # and any subsequent re-migrations land in their own slot.
    if [ -d "$ARCHIVE/$URL_SLUG" ]; then
        TS=$(date +%Y%m%d-%H%M%S)
        log "archive slot already exists; nesting under $URL_SLUG.$TS"
        mv "$LIVE" "$ARCHIVE/$URL_SLUG.$TS"
    else
        mv "$LIVE" "$ARCHIVE/$URL_SLUG"
    fi
fi
mv "$STAGING" "$LIVE"

# -- Retention: each rebuild leaves the previously-live install behind in
# _archive_fliphtml5 as a full (~200MB) copy. Keep the base slot ($URL_SLUG,
# the original FlipHTML5 import) plus the most recent timestamped snapshot;
# delete older timestamped snapshots for THIS slug so the archive cannot grow
# unbounded. Matches only "$URL_SLUG.<8digits>-<6digits>" so sibling slugs
# that share a prefix are never touched.
KEEP_SNAPSHOTS=1
mapfile -t _OLD_SNAPS < <(ls -1dt "$ARCHIVE/$URL_SLUG".[0-9]*-[0-9]* 2>/dev/null | tail -n +$((KEEP_SNAPSHOTS+1)))
if [ "${#_OLD_SNAPS[@]}" -gt 0 ]; then
    log "retention: pruning ${#_OLD_SNAPS[@]} old flipbook snapshot(s) for $URL_SLUG"
    rm -rf "${_OLD_SNAPS[@]}"
fi

log "✓ swapped: $TOTAL pages live at /$URL_SLUG/"

# ── Refresh yy_volume tracking columns so the admin Books page reflects
# the freshly-rendered artifacts. Without this, volume_page_count stays
# stuck on whatever the original docx-upload pipeline last wrote — even
# when the on-disk PDF / flipbook are brand new.
log "updating yy_volume.volume_page_count = $TOTAL, status='success'"
docker exec -i yada-postgres-prod psql -U postgres -d yada -At -c "
  UPDATE yy_volume
     SET volume_page_count = $TOTAL,
         volume_pipeline_status = 'success',
         volume_pipeline_message = 'Word render + flipbook rebuild ' || to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI'),
         volume_pipeline_retry_count = 0
   WHERE volume_key = $VOL_KEY
" >/dev/null
