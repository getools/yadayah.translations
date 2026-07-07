#!/usr/bin/env python3
"""
Per-volume parser worker — option B from the admin-books pipeline plan.

DO NOT INVOKE THIS DIRECTLY UNDER LOAD. It is meant to be called by
book-pipeline-worker.sh which holds an flock and runs at nice -n 19 +
ionice -c 3. Running it ad-hoc on the host while LibreOffice/Puppeteer
are also active can briefly starve Apache/PHP workers and make the
public site appear hung. If you must reparse a single volume by hand,
let the worker pick it up via the next cron tick (every 2 min) instead.


Given a single yy_volume.volume_key, this script:
  1. Marks the row's volume_parse_status = 'running'
  2. Deletes only that volume's existing yy_paragraph + yy_translation rows
     (NOT a global TRUNCATE — that's the destructive footgun in the original
     parse_paragraphs.py / parse_word_translations.py top-level mains)
  3. Re-extracts paragraphs + translations from the volume's stored .docx
  4. Inserts the extracted rows back into yy_paragraph + yy_translation
  5. Updates volume_paragraph_count + volume_parse_status='success'/'error'

Reuses the heavy lifters from the original scripts:
  - parse_paragraphs.extract_paragraphs_from_doc
  - parse_paragraphs.build_paragraph_raw / build_paragraph_html
  - parse_paragraphs.get_chapter_for_paragraph / new_css_collector
  - parse_word_translations.extract_translations_from_doc
  - parse_word_translations.build_footer_page_map
  - parse_word_translations.normalize_quotes (via populate_word_translations
    if available; otherwise inlined)

Usage (from book-pipeline-worker.sh):
  /opt/yada-www/parsers/parse_volume.py --volume-key 12

Environment:
  Reads /opt/yada-www/.env for POSTGRES_* (host, port, user, password, db).
"""
import os
import sys
import json
import argparse
import logging
import re
import unicodedata
from html import unescape as _html_unescape
from pathlib import Path
from datetime import datetime

# Make the deployed parser dir importable so we can pull in helpers from
# parse_paragraphs.py and parse_word_translations.py without duplicating them.
PARSER_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(PARSER_DIR))

import psycopg2
from psycopg2.extras import execute_values
from dotenv import load_dotenv

from parse_paragraphs import (
    extract_paragraphs_from_doc,
    get_chapter_for_paragraph,
    new_css_collector,
)
from parse_word_translations import (
    extract_translations_from_doc,
    build_footer_page_map,
    get_com_page_map_iter,  # safely returns {} on Linux now
)

DOCX_DIR = Path(os.getenv('YY_DOCX_DIR', '/opt/yada-www/public/u/books-word'))


_TAG_RE = re.compile(r'<[^>]+>')
_WS_RE = re.compile(r'\s+')

def html_to_plain(html):
    """Strip HTML tags and decode entities for full-text indexing.
    Mirrors what the original parse_paragraphs pipeline implicitly relied on
    (search.php queries paragraph_text_plain, but the upstream extractor only
    emits text_html). Keep collapsing whitespace so tsvector tokens are clean."""
    if not html:
        return ''
    text = _TAG_RE.sub(' ', html)
    text = _html_unescape(text)
    return _WS_RE.sub(' ', text).strip()


def normalize_quotes(s):
    """Convert curly quotes / apostrophes to ASCII for stable cite_book lookups.
    Mirrors populate_word_translations.normalize_quotes which we don't ship to
    the server (lighter footprint to inline this single function)."""
    if not s:
        return s
    return s.replace('‘', "'").replace('’', "'") \
            .replace('“', '"').replace('”', '"')


def db_connect():
    """Connect to Postgres. On the production host the postgres container only
    listens on the docker bridge network (no host port mapping) so the .env's
    POSTGRES_HOST=localhost won't work. We fall back to dynamically resolving
    the container's bridge IP via `docker inspect`."""
    load_dotenv('/opt/yada-www/.env')
    host = os.getenv('POSTGRES_HOST', 'localhost')
    port = os.getenv('POSTGRES_PORT', '5433')
    user = os.getenv('POSTGRES_USER', 'postgres')
    password = os.getenv('POSTGRES_PASSWORD')
    dbname = os.getenv('POSTGRES_DB', 'yada')
    try:
        return psycopg2.connect(host=host, port=port, user=user, password=password, dbname=dbname,
                                connect_timeout=3)
    except psycopg2.OperationalError as e:
        import subprocess
        try:
            ip = subprocess.check_output([
                'docker', 'inspect', '-f',
                '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}',
                'yada-postgres-prod'
            ], text=True).strip()
        except Exception:
            raise e
        if not ip:
            raise e
        return psycopg2.connect(host=ip, port='5432', user=user, password=password, dbname=dbname,
                                connect_timeout=3)


def update_status(conn, vol_key, status, message):
    cur = conn.cursor()
    cur.execute("""
        UPDATE yy_volume
           SET volume_parse_status = %s,
               volume_parse_message = %s,
               volume_parse_dtime = CASE WHEN %s IN ('success','error') THEN NOW() ELSE volume_parse_dtime END,
               volume_revision_dtime = NOW()
         WHERE volume_key = %s
    """, (status, message, status, vol_key))
    conn.commit()
    cur.close()


def log_monitor_event(conn, severity, message, detail):
    """Mirror book-pipeline-worker.sh's monitor logging so parser failures
    surface in /admin-monitor.html alongside docx→pdf→flip failures."""
    try:
        cur = conn.cursor()
        cur.execute("""
            INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_file)
            VALUES ('book_parse', %s, %s, %s, 'parse_volume.py')
        """, (severity, message, detail[:8000] if detail else None))
        conn.commit()
        cur.close()
    except Exception:
        pass  # never let monitor logging break the parse


def _norm_chapter_name(s):
    """Normalize a chapter name for case/whitespace-insensitive matching."""
    return re.sub(r'\s+', ' ', (s or '')).strip().lower()


def ensure_chapters_for_boundaries(conn, cur, vol_key, boundaries, page_map):
    """Make sure a yy_chapter row exists for every detected heading.

    Returns [(para_idx, chapter_key), …] in document order for paragraph
    assignment. Existing chapters are matched by number (numbered headings)
    or by normalized name (unnumbered sections) and reused as-is — curated
    numbers, names and sorts are never overwritten. Only genuinely missing
    chapters are created, which is what lets unnumbered front/back-matter
    sections (and any not-yet-seeded numbered chapter) get parsed
    automatically on upload/reparse.
    """
    cur.execute(
        "SELECT chapter_key, chapter_number, chapter_name, chapter_sort "
        "FROM yy_chapter WHERE volume_key = %s", (vol_key,))
    by_number, by_name = {}, {}
    for ck, cn, nm, st in cur.fetchall():
        if cn is not None and cn > 0:
            by_number[cn] = (ck, st)
        if nm:
            by_name[_norm_chapter_name(nm)] = (ck, st)

    # Walk headings in document order, tracking the sort of the previous one so
    # a newly-created section lands immediately AFTER its predecessor (using the
    # predecessor's real sort, whether that row is curated or freshly made).
    # Numbered chapters anchor to number*10 (the historical convention).
    boundary_keys = []
    created = 0
    prev_sort = 0
    for idx, number, name in boundaries:
        hit = None
        if number is not None and number > 0:
            hit = by_number.get(number)
        if hit is None and name:
            hit = by_name.get(_norm_chapter_name(name))
        if hit is not None:
            ck, st = hit
            sort = st if st is not None else prev_sort + 1
        else:
            sort = number * 10 if (number is not None and number > 0) else prev_sort + 1
            page = page_map.get(idx) if page_map else None
            cur.execute(
                "INSERT INTO yy_chapter "
                "(volume_key, chapter_number, chapter_name, chapter_sort, chapter_page) "
                "VALUES (%s, %s, %s, %s, %s) RETURNING chapter_key",
                (vol_key, number if number else 0, name or None, sort, page))
            ck = cur.fetchone()[0]
            if number is not None and number > 0:
                by_number[number] = (ck, sort)
            if name:
                by_name[_norm_chapter_name(name)] = (ck, sort)
            created += 1
            logging.info(f"  + created chapter {(str(number) if number else '(section)'):>9} "
                         f"sort={sort:>4} {name!r}")
        prev_sort = sort
        boundary_keys.append((idx, ck))
    if created:
        conn.commit()
    logging.info(f"  Chapter rows ensured: {len(boundary_keys)} headings, {created} created")
    return boundary_keys


def chapter_key_for_paragraph(para_num, boundary_keys):
    """Return chapter_key for a paragraph given ordered (idx, key) boundaries."""
    ck = None
    for bidx, key in boundary_keys:
        if para_num >= bidx:
            ck = key
        else:
            break
    return ck


def reparse_volume(vol_key, verbose=False):
    logging.basicConfig(
        level=logging.DEBUG if verbose else logging.INFO,
        format='%(asctime)s %(levelname)s %(message)s',
    )
    conn = db_connect()
    cur = conn.cursor()

    # Resolve volume metadata
    cur.execute("""
        SELECT v.volume_key, v.series_key, v.volume_docx, v.volume_label, v.volume_parse_flag
          FROM yy_volume v WHERE v.volume_key = %s
    """, (vol_key,))
    row = cur.fetchone()
    if not row:
        raise SystemExit(f"No yy_volume row for volume_key={vol_key}")
    _, series_key, docx_name, label, parse_flag = row
    if not parse_flag:
        logging.info(f"volume_parse_flag is FALSE for {label} (key {vol_key}); skipping")
        update_status(conn, vol_key, 'skipped', 'volume_parse_flag is disabled')
        return
    if not docx_name:
        update_status(conn, vol_key, 'error', 'No volume_docx — upload a .docx first')
        raise SystemExit("No volume_docx")
    docx_path = DOCX_DIR / docx_name
    if not docx_path.is_file():
        update_status(conn, vol_key, 'error', f'.docx file missing on disk: {docx_name}')
        raise SystemExit(f"Missing: {docx_path}")

    update_status(conn, vol_key, 'running', f'Extracting paragraphs + translations from {docx_name}')
    logging.info(f"Reparsing volume {vol_key} ({label}) from {docx_path}")

    # ── Phase 1: paragraphs ─────────────────────────────────────────────
    try:
        _, content_start, _ = build_footer_page_map(docx_path)
    except Exception as e:
        logging.warning(f"build_footer_page_map failed, defaulting content_start=0: {e}")
        content_start = 0

    com_page_map = {}
    try:
        com_page_map = get_com_page_map_iter(str(docx_path.absolute()))
    except Exception as e:
        logging.warning(f"COM page-map failed (Linux fallback): {e}")

    # Use COM page numbers if available; else fall back to footer page map.
    page_map = dict(com_page_map)
    if not page_map:
        footer_map, _, _ = build_footer_page_map(docx_path)
        page_map = footer_map

    css = new_css_collector()
    paragraphs, chapter_boundaries = extract_paragraphs_from_doc(
        docx_path, css,
        content_start_idx=content_start,
        last_page=None,
        page_map=page_map,
    )
    logging.info(f"  Extracted {len(paragraphs)} content paragraphs, {len(chapter_boundaries)} chapter boundaries")

    # Ensure a yy_chapter row exists for every heading the extractor found —
    # numbered chapters AND unnumbered front/back-matter sections (Prelude,
    # Afterword, Bibliography…). Missing ones are created so they get parsed
    # automatically; existing curated rows are reused untouched. Returns the
    # ordered (para_idx, chapter_key) boundaries used for assignment below.
    boundary_keys = ensure_chapters_for_boundaries(conn, cur, vol_key, chapter_boundaries, page_map)

    # Also keep a chapter_number → chapter_key map for the translation phase,
    # which links rows by the numbered chapter they fall under.
    cur.execute("SELECT chapter_key, chapter_number FROM yy_chapter WHERE volume_key = %s",
                (vol_key,))
    yy_chapter_map = {cn: ck for ck, cn in cur.fetchall()}

    # ── Delete existing rows for this volume ───────────────────────────
    cur.execute("DELETE FROM yy_paragraph WHERE volume_key = %s", (vol_key,))
    cur.execute("DELETE FROM yy_translation WHERE volume_key = %s", (vol_key,))
    conn.commit()
    logging.info("  Deleted existing yy_paragraph + yy_translation rows for this volume")

    # ── Insert paragraphs ─────────────────────────────────────────────
    para_rows = []
    for p in paragraphs:
        para_num = p['paragraph_number']
        chapter_key = chapter_key_for_paragraph(para_num, boundary_keys)
        # extract_paragraphs_from_doc emits 'text_raw' (already JSON-encoded)
        # and 'text_html'. Earlier wrapper read 'raw'/'html'/'text_plain' which
        # this extractor never produces — that's what wiped 99k paragraphs to
        # '{}'/''/''. Synthesize text_plain locally from text_html since the
        # original pipeline never produced it but search.php requires it.
        text_raw = p.get('text_raw') or '{}'
        text_html = p.get('text_html', '')
        text_plain = html_to_plain(text_html)
        para_rows.append((
            series_key, vol_key, chapter_key,
            p.get('page'), para_num,
            text_raw,
            text_html,
            text_plain,
        ))
    if para_rows:
        execute_values(cur, """
            INSERT INTO yy_paragraph (
                series_key, volume_key, chapter_key,
                paragraph_page, paragraph_number,
                paragraph_text_raw, paragraph_text_html, paragraph_text_plain
            ) VALUES %s
        """, para_rows, page_size=500)
        conn.commit()
    logging.info(f"  Inserted {len(para_rows)} paragraphs")

    # Deactivate junk paragraph rows. A row is JUNK if it's NULL/empty/
    # whitespace-only, has no letters at all, or is a fragment with fewer
    # than 5 letters AND no terminal punctuation. The terminal-punct
    # exception keeps short real sentences ("Wow!", "Yes!", "ago.")
    # active — these are valid one-word paragraphs in the source docx.
    cur.execute("""
        UPDATE yy_paragraph
           SET paragraph_active_flag = false
         WHERE volume_key = %s
           AND paragraph_active_flag = true
           AND (
                paragraph_text_plain IS NULL
             OR trim(paragraph_text_plain) = ''
             OR paragraph_text_plain !~ '[a-zA-Z]'
             OR (
                  length(regexp_replace(paragraph_text_plain, '[^a-zA-Z]', '', 'g')) < 5
                  AND trim(paragraph_text_plain) !~ '[.!?]\\s*$'
                )
           )
    """, (vol_key,))
    deactivated_junk = cur.rowcount
    conn.commit()
    logging.info(f"  Deactivated {deactivated_junk} junk paragraphs (empty / non-letter / short-no-terminal-punct)")
    # ── Phase 2: translations ─────────────────────────────────────────
    # Load FK lookup tables for cite resolution
    cur.execute("""
        SELECT cbm.cite_book_map_hebrew, cbm.cite_book_key
          FROM yy_cite_book_map cbm
         WHERE cbm.cite_book_key IS NOT NULL
    """)
    cite_book_map = {}
    for cite_hebrew, scroll_key in cur.fetchall():
        cite_book_map[cite_hebrew] = scroll_key
        norm = normalize_quotes(cite_hebrew)
        if norm != cite_hebrew:
            cite_book_map[norm] = scroll_key

    cur.execute("SELECT cite_chapter_key, cite_book_key, cite_chapter_number FROM yy_cite_chapter")
    chapter_map = {(sk, cn): ck for ck, sk, cn in cur.fetchall()}
    cur.execute("SELECT cite_verse_key, cite_chapter_key, cite_verse_number FROM yy_cite_verse")
    verse_map = {(ck, vn): vk for vk, ck, vn in cur.fetchall()}

    def ensure_chapter(scroll_key, ch_num):
        key = (scroll_key, ch_num)
        if key not in chapter_map:
            cur.execute(
                "INSERT INTO yy_cite_chapter (cite_book_key, cite_chapter_number, cite_chapter_sort) "
                "VALUES (%s, %s, %s) RETURNING cite_chapter_key",
                (scroll_key, ch_num, ch_num * 10))
            chapter_map[key] = cur.fetchone()[0]
            conn.commit()
        return chapter_map[key]

    def ensure_verse(ch_key, verse_num):
        key = (ch_key, verse_num)
        if key not in verse_map:
            cur.execute(
                "INSERT INTO yy_cite_verse (cite_chapter_key, cite_verse_number, cite_verse_sort) "
                "VALUES (%s, %s, %s) RETURNING cite_verse_key",
                (ch_key, verse_num, verse_num * 10))
            verse_map[key] = cur.fetchone()[0]
            conn.commit()
        return verse_map[key]

    results = extract_translations_from_doc(docx_path, detect_chapters=True)
    chapter_meta = results[-1] if results and isinstance(results[-1], dict) and '_chapters' in results[-1] else None
    translations = results[:-1] if chapter_meta else results
    logging.info(f"  Extracted {len(translations)} translations")

    trans_rows = []
    skipped = 0
    for t in translations:
        cite_hebrew = t.get('cite_hebrew')
        scroll_key = None
        if cite_hebrew:
            norm = normalize_quotes(cite_hebrew)
            scroll_key = cite_book_map.get(norm) or cite_book_map.get(cite_hebrew)
        if scroll_key is None and t.get('cite'):
            scroll_key = cite_book_map.get(normalize_quotes(t['cite']))
        if scroll_key is None or t.get('cite_chapter') is None or t.get('cite_verse') is None:
            skipped += 1
            continue
        ch_key = ensure_chapter(scroll_key, t['cite_chapter'])
        verse_key = ensure_verse(ch_key, t['cite_verse'])
        yy_ch_key = yy_chapter_map.get(t.get('_chapter_num'))
        trans_rows.append((
            scroll_key, ch_key, verse_key,
            series_key, vol_key, yy_ch_key,
            t.get('page'), None,
            t.get('text_word', ''), 0,
        ))
    if trans_rows:
        execute_values(cur, """
            INSERT INTO yy_translation (
                cite_book_key, cite_chapter_key, cite_verse_key,
                series_key, volume_key, chapter_key,
                translation_page, translation_paragraph,
                translation_copy, translation_sort
            ) VALUES %s
        """, trans_rows, page_size=500)
        conn.commit()
    logging.info(f"  Inserted {len(trans_rows)} translations (skipped {skipped} unresolvable)")

    # ── Update count + status ─────────────────────────────────────────
    cur.execute("UPDATE yy_volume SET volume_paragraph_count = %s WHERE volume_key = %s",
                (len(para_rows), vol_key))
    conn.commit()
    update_status(conn, vol_key, 'success',
        f'{len(para_rows)} paragraphs, {len(trans_rows)} translations ({skipped} cite-unresolvable)')
    cur.close()
    conn.close()


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--volume-key', type=int, required=True)
    ap.add_argument('--verbose', action='store_true')
    args = ap.parse_args()
    try:
        reparse_volume(args.volume_key, verbose=args.verbose)
    except SystemExit:
        raise
    except Exception as e:
        import traceback
        tb = traceback.format_exc()
        try:
            conn = db_connect()
            update_status(conn, args.volume_key, 'error', str(e)[:1000])
            log_monitor_event(conn, 'error', f'Volume {args.volume_key} parse failed: {e}', tb)
            conn.close()
        except Exception:
            pass
        print(tb, file=sys.stderr)
        sys.exit(1)
