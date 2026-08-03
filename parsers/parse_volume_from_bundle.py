#!/usr/bin/env python3
"""
parse_volume_from_bundle.py — Phase 3 of the PDF-only parser pipeline.

Drop-in replacement for parse_volume.py. Same CLI surface
(`--volume-key N`), same DB shape, same status-flag contract — only the
content extraction changes:

  Old:  open .docx via python-docx → walk runs → emit paragraphs with
        page numbers from a `pgNumType` heuristic that disagreed with
        the actual PDF rendering.
  New:  open the Word-generated PDF via PyMuPDF → emit paragraphs whose
        page numbers ARE the physical PDF pages (= flipbook pages),
        because the flipbook is rendered from the same PDF.

Single source of truth: /opt/yada-www/public/pdf/<volume_pdf>. The DOCX
is no longer read.

Usage (from book-pipeline-worker.sh):
  parse_volume_from_bundle.py --volume-key 12

Optional flags:
  --dry-run   Don't touch the DB; print counts and a sample to stderr.
  --verbose   DEBUG-level logging.
"""
import argparse
import json
import logging
import os
import re
import subprocess
import sys
import traceback
from html import unescape as _html_unescape
from pathlib import Path

import psycopg2
from psycopg2.extras import execute_values
from dotenv import load_dotenv

PARSER_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(PARSER_DIR))
import bundle_paragraphs as bp
import bundle_translations as bt

PDF_DIR = Path(os.getenv("YY_PDF_DIR", "/opt/yada-www/public/pdf"))


_TAG_RE = re.compile(r"<[^>]+>")
_WS_RE  = re.compile(r"\s+")


def html_to_plain(html):
    if not html:
        return ""
    text = _TAG_RE.sub(" ", html)
    text = _html_unescape(text)
    return _WS_RE.sub(" ", text).strip()


def normalize_quotes(s):
    if not s:
        return s
    return s.replace("‘", "'").replace("’", "'").replace("“", '"').replace("”", '"')


def _norm_chapter_name(s):
    """Case/whitespace-insensitive key for matching a TOC section banner to
    its yy_chapter row. The toc.json carries back-matter titles in all-caps
    ('TOPICAL APPENDIX') while yy_chapter stores curated title case
    ('Topical Appendix'); casefold + whitespace-collapse makes them equal.
    Mirrors _norm_chapter_name() in parse_paragraphs.py."""
    return _WS_RE.sub(" ", (s or "")).strip().casefold()


# ── DB connection (mirrors parse_volume.db_connect) ────────────────────────

def db_connect():
    load_dotenv("/opt/yada-www/.env")
    host = os.getenv("POSTGRES_HOST", "localhost")
    port = os.getenv("POSTGRES_PORT", "5433")
    user = os.getenv("POSTGRES_USER", "postgres")
    password = os.getenv("POSTGRES_PASSWORD")
    dbname = os.getenv("POSTGRES_DB", "yada")
    try:
        return psycopg2.connect(host=host, port=port, user=user, password=password,
                                dbname=dbname, connect_timeout=3)
    except psycopg2.OperationalError as e:
        try:
            ip = subprocess.check_output(
                ["docker", "inspect", "-f",
                 "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}",
                 "yada-postgres-prod"], text=True).strip()
        except Exception:
            raise e
        if not ip:
            raise e
        return psycopg2.connect(host=ip, port="5432", user=user, password=password,
                                dbname=dbname, connect_timeout=3)


def update_status(conn, vol_key, status, message):
    cur = conn.cursor()
    cur.execute("""
        UPDATE yy_volume
           SET volume_parse_status = %s,
               volume_parse_message = %s,
               volume_parse_dtime = CASE WHEN %s IN ('success','error')
                                         THEN NOW()
                                         ELSE volume_parse_dtime END,
               volume_revision_dtime = NOW()
         WHERE volume_key = %s
    """, (status, message, status, vol_key))
    conn.commit()
    cur.close()


def log_monitor_event(conn, severity, message, detail):
    try:
        cur = conn.cursor()
        cur.execute("""
            INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_file)
            VALUES ('book_parse', %s, %s, %s, 'parse_volume_from_bundle.py')
        """, (severity, message, (detail[:8000] if detail else None)))
        conn.commit()
        cur.close()
    except Exception:
        pass


# ── Main ───────────────────────────────────────────────────────────────────

def reparse_volume(vol_key, dry_run=False, verbose=False):
    logging.basicConfig(
        level=logging.DEBUG if verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
    )
    conn = db_connect()
    cur = conn.cursor()

    cur.execute("""
        SELECT v.volume_key, v.series_key, v.volume_pdf, v.volume_code,
               v.volume_label, v.volume_parse_flag
          FROM yy_volume v WHERE v.volume_key = %s
    """, (vol_key,))
    row = cur.fetchone()
    if not row:
        raise SystemExit(f"No yy_volume row for volume_key={vol_key}")
    _, series_key, pdf_name, vol_code, label, parse_flag = row

    if not parse_flag:
        logging.info(f"volume_parse_flag is FALSE for {label} (key {vol_key}); skipping")
        if not dry_run:
            update_status(conn, vol_key, "skipped", "volume_parse_flag is disabled")
        return

    if not pdf_name:
        msg = "No volume_pdf — generate the PDF (via the YY PDF Generator App) first"
        if not dry_run:
            update_status(conn, vol_key, "error", msg)
        raise SystemExit(msg)

    pdf_path = PDF_DIR / pdf_name
    if not pdf_path.is_file():
        msg = f".pdf file missing on disk: {pdf_name}"
        if not dry_run:
            update_status(conn, vol_key, "error", msg)
        raise SystemExit(msg)

    bundle_dir = Path("/opt/yada-www/public") / pdf_path.stem
    if not bundle_dir.is_dir():
        # Bundle is needed for chapter (toc.json) lookup. Without it we
        # still parse paragraphs but chapter assignment goes NULL — log
        # rather than fail, since the flipbook builder eventually creates
        # the bundle and the next reparse fills chapters in.
        logging.warning(f"flipbook bundle missing at {bundle_dir} — chapter info will be NULL")
        bundle_dir = None

    if not dry_run:
        update_status(conn, vol_key, "running",
                      f"Extracting paragraphs + translations from {pdf_name}")
    logging.info(f"Reparsing volume {vol_key} ({label}) from {pdf_path}")

    # ── Extract paragraphs + translations from the PDF ─────────────────
    paragraphs = bp.parse_pdf(str(pdf_path), str(bundle_dir) if bundle_dir else None)
    translations = bt.extract_translations(paragraphs)
    logging.info(f"  Extracted {len(paragraphs)} paragraphs, {len(translations)} translations")

    # The PyMuPDF parse above runs for minutes on a large book; by the time
    # it returns, the DB connection opened at the top has usually been reaped
    # as idle by the server (psycopg2 "server closed the connection
    # unexpectedly" on the next query). Re-establish it before the DB work.
    try:
        cur.execute("SELECT 1")
        cur.fetchone()
    except Exception:
        logging.info("  DB connection went idle during parse — reconnecting")
        try:
            conn.close()
        except Exception:
            pass
        conn = db_connect()
        cur = conn.cursor()

    # ── yy_chapter mapping (chapter_number → chapter_key) ──────────────
    cur.execute("SELECT chapter_key, chapter_number, chapter_name FROM yy_chapter WHERE volume_key = %s",
                (vol_key,))
    _chapter_rows = cur.fetchall()
    yy_chapter_map = {cn: ck for ck, cn, _nm in _chapter_rows}
    # Named (unnumbered) back-matter sections carry chapter_number None in the
    # TOC, so they resolve by name instead — see the paragraph loop below.
    yy_chapter_by_name = {_norm_chapter_name(nm): ck
                          for ck, _cn, nm in _chapter_rows if nm}

    # ── Cite resolution lookups ────────────────────────────────────────
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
            if dry_run:
                # Pretend it exists so we don't crash; real run inserts it.
                chapter_map[key] = -1
            else:
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
            if dry_run:
                verse_map[key] = -1
            else:
                cur.execute(
                    "INSERT INTO yy_cite_verse (cite_chapter_key, cite_verse_number, cite_verse_sort) "
                    "VALUES (%s, %s, %s) RETURNING cite_verse_key",
                    (ch_key, verse_num, verse_num * 10))
                verse_map[key] = cur.fetchone()[0]
                conn.commit()
        return verse_map[key]

    # ── Build paragraph rows ───────────────────────────────────────────
    para_rows = []
    for p in paragraphs:
        ch_num = p.get("chapter_number")
        # Numbered chapters resolve by number; named back-matter sections
        # (Afterword / Topical Appendix / Bibliography / Resources), which
        # carry chapter_number None, resolve by normalized name. An
        # unmatched name (e.g. a front-matter banner with no yy_chapter row)
        # leaves chapter_key NULL so the paragraphs orphan (un-narrated)
        # instead of being swallowed into the previous chapter.
        if ch_num is not None:
            chapter_key = yy_chapter_map.get(ch_num)
        else:
            ch_name = p.get("chapter_name")
            chapter_key = yy_chapter_by_name.get(_norm_chapter_name(ch_name)) if ch_name else None
        text_html = p.get("text_html", "")
        text_plain = p.get("text_plain") or html_to_plain(text_html)
        # text_raw: a compact JSON of run breakdown so a future re-render
        # can reconstruct formatting if we ever need to. Mirrors the role
        # of the docx parser's text_raw field.
        text_raw = json.dumps(p.get("_runs", []), ensure_ascii=False)
        para_rows.append((
            series_key, vol_key, chapter_key,
            p["page"], p["paragraph_number"],
            text_raw, text_html, text_plain,
            bool(p.get("is_table", False)),
            bool(p.get("is_continuation", False)),
        ))

    # ── Build translation rows ─────────────────────────────────────────
    trans_rows = []
    skipped = 0
    for t in translations:
        cite_hebrew = t.get("cite_hebrew")
        scroll_key = None
        if cite_hebrew:
            norm = normalize_quotes(cite_hebrew)
            scroll_key = cite_book_map.get(norm) or cite_book_map.get(cite_hebrew)
        if scroll_key is None and t.get("cite"):
            scroll_key = cite_book_map.get(normalize_quotes(t["cite"]))
        if scroll_key is None or t.get("cite_chapter") is None or t.get("cite_verse") is None:
            skipped += 1
            continue
        ch_key = ensure_chapter(scroll_key, t["cite_chapter"])
        verse_key = ensure_verse(ch_key, t["cite_verse"])
        # Find the chapter_key from the originating paragraph's chapter.
        # bundle_translations.emit_translation doesn't track chapter_number
        # currently — look it up from the page via toc-derived info.
        para_idx = t.get("paragraph_index")
        paragraph = next((p for p in paragraphs if p["paragraph_number"] == para_idx), None)
        yy_ch_key = yy_chapter_map.get(paragraph["chapter_number"]) if paragraph and paragraph.get("chapter_number") else None
        trans_rows.append((
            scroll_key, ch_key, verse_key,
            series_key, vol_key, yy_ch_key,
            t.get("page"), None,
            t.get("text_word", ""), 0,
        ))

    if dry_run:
        logging.info("DRY RUN — not writing DB")
        logging.info(f"  Would insert {len(para_rows)} paragraphs")
        logging.info(f"  Would insert {len(trans_rows)} translations (skipped {skipped} unresolvable)")
        logging.info("  Sample paragraph rows:")
        for r in para_rows[:3]:
            preview = (r[7] or "")[:100]
            logging.info(f"    page={r[3]} para={r[4]} ch_key={r[2]}: {preview!r}")
        logging.info("  Sample translation rows:")
        for r in trans_rows[:3]:
            logging.info(f"    page={r[6]} scroll={r[0]} ch={r[1]} v={r[2]}: {(r[8] or '')[:80]!r}")
        return

    # ── Live: delete + insert ──────────────────────────────────────────
    cur.execute("DELETE FROM yy_paragraph   WHERE volume_key = %s", (vol_key,))
    cur.execute("DELETE FROM yy_translation WHERE volume_key = %s", (vol_key,))
    conn.commit()

    if para_rows:
        execute_values(cur, """
            INSERT INTO yy_paragraph (
                series_key, volume_key, chapter_key,
                paragraph_page, paragraph_number,
                paragraph_text_raw, paragraph_text_html, paragraph_text_plain,
                paragraph_is_table,
                paragraph_is_continuation
            ) VALUES %s
        """, para_rows, page_size=500)
        conn.commit()

    # Junk-row deactivation. EXCLUDES paragraphs flagged is_continuation —
    # those are legitimate tails of a logical paragraph (e.g. "62:2)" — no
    # letters but it's the closing fragment of the previous citation) and
    # must stay active so the TTS worker can coalesce them into the head.
    cur.execute("""
        UPDATE yy_paragraph
           SET paragraph_active_flag = false
         WHERE volume_key = %s
           AND paragraph_active_flag = true
           AND paragraph_is_continuation = false
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
    deactivated = cur.rowcount
    conn.commit()

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

    cur.execute("UPDATE yy_volume SET volume_paragraph_count = %s WHERE volume_key = %s",
                (len(para_rows), vol_key))
    conn.commit()

    update_status(conn, vol_key, "success",
        f"{len(para_rows)} paragraphs ({deactivated} junk deactivated), "
        f"{len(trans_rows)} translations ({skipped} cite-unresolvable)")
    logging.info("done")
    cur.close()
    conn.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--volume-key", type=int, required=True)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--verbose", action="store_true")
    args = ap.parse_args()
    try:
        reparse_volume(args.volume_key, dry_run=args.dry_run, verbose=args.verbose)
    except SystemExit:
        raise
    except Exception as e:
        tb = traceback.format_exc()
        try:
            conn = db_connect()
            update_status(conn, args.volume_key, "error", str(e)[:1000])
            log_monitor_event(conn, "error", f"Volume {args.volume_key} parse failed: {e}", tb)
            conn.close()
        except Exception:
            pass
        print(tb, file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
