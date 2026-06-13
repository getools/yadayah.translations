#!/usr/bin/env python3
"""
bundle_paragraphs.py — Phase 1 of the PDF-only parser pipeline.

Single source of truth: the Word-generated PDF in /opt/yada-www/public/pdf/.
Reads it with PyMuPDF (fitz), which handles word-boundary detection and
paragraph-block segmentation internally — so we don't reinvent either.

Output (one JSON object per line on stdout):
  {
    "page":             physical PDF page (1-indexed),
    "paragraph_number": 1-indexed within the volume,
    "chapter_number":   int or None,
    "chapter_name":     str or None,
    "text_plain":       str,
    "text_html":        str (with <i>/<b> wrappers from real font names),
    "warning":          str or None,
  }

The flipbook is rendered from the same PDF, so paragraph_page = the page
the flipbook navigates to. DOCX, PDF, flipbook, and DB all agree by
construction; there is no second extraction pipeline to drift against.

Usage:
  bundle_paragraphs.py <pdf_path> [<bundle_dir>]
    bundle_dir defaults to /opt/yada-www/public/<pdf_stem>/ and is only
    used to read toc.json for chapter assignment.
"""
import json, re, sys
from pathlib import Path

import fitz  # PyMuPDF

# ── Header/footer cutoffs in PDF points.
# YY books are 6"×9" = 432×648 pt. Running header (book title + chapter
# name) sits in the top inch, footer page number in the bottom half-inch.
# Use generous bands so jitter doesn't slip headers into content.
HEADER_MAX_Y = 55.0   # ~0.76"
FOOTER_MIN_Y = 595.0  # ~8.26" — leaves the body comfortably clear

# Bold / italic detection: PyMuPDF reports both a `flags` bitmask
# (bit 1=italic, bit 4=bold) and the embedded font name. On Word-
# generated PDFs the font names are deterministic suffixes, which is
# the more reliable signal — we use it as the source of truth and treat
# the bitmask as a fallback.
BOLD_NAME_RE   = re.compile(r"-Bold(?:MT|Italic|ItalicMT)?$|-Bold$|Bd$|,Bold$", re.I)
ITALIC_NAME_RE = re.compile(r"-Italic(?:MT)?$|-Oblique$|It$|,Italic$", re.I)

FLAG_ITALIC = 0b00010   # bit 1
FLAG_BOLD   = 0b10000   # bit 4

# PDF font names PyMuPDF reports often carry a 6-letter subset prefix
# (e.g. "ABCDEF+TimesNewRoman-Bold") and a variant suffix (-Bold, -Italic).
# Strip both so we land on the human-readable family name that matches
# the yy_tts_font.tts_font_name rows.
SUBSET_PREFIX_RE = re.compile(r"^[A-Z]{6}\+")
VARIANT_SUFFIX_RE = re.compile(
    r"(?:-(?:Bold|Italic|BoldItalic|ItalicMT|Italic-B|Oblique|BoldOblique)(?:MT)?|"
    r"MT$|,Bold|,Italic|Bd$|It$)",
    re.I,
)

def font_family(span):
    """Clean PyMuPDF font name → bare family (e.g. 'Times New Roman')."""
    name = (span.get("font") or "").strip()
    if not name: return ""
    name = SUBSET_PREFIX_RE.sub("", name)
    # Strip variant suffixes iteratively in case multiple are present.
    prev = None
    while prev != name:
        prev = name
        name = VARIANT_SUFFIX_RE.sub("", name).rstrip("-,")
    return name.replace("-", " ").strip()

# Hebrew Unicode block — wrapped as a synthetic "Hebrew Script" font so
# the same skip/pause rules in yy_tts_font apply, regardless of which
# real font PyMuPDF reported.
HEBREW_BLOCK_RE = re.compile(r"[֐-׿]+")
HEBREW_PSEUDO_FONT = "Hebrew Script"

# Source-material style detection by PyMuPDF span color. Each YY book
# uses distinct colors per translation / source-text. Spans with one of
# these colors get a data-style="kjv" (etc.) attribute on output so the
# TTS worker can route it through the appropriate voice category (Bible
# voice, Islam voice, etc.).
#
# Three categories, 15 styles total. Mapping is RGB-integer keyed for
# fast lookup; PyMuPDF reports the source color integer verbatim so no
# anti-alias tolerance is needed.
#
#   Bible:
#     esv  #981E5A   English Standard Version
#     jps  #005A9E   JPS
#     kjv  #58267E   King James Version
#     lv   #005696   Latin Vulgate
#     na   #825014   Nestle-Aland
#     nas  #9A0000   New American Standard Bible
#     niv  #A95007   New International Version
#     nlt  #00602B   New-Living Translation
#     nt   #35657A   New Testament
#     paul #807566   Paul
#
#   Islam:
#     bukhari #005024  Bukhari
#     ishaq   #004A82  Ishaq
#     muslim  #984806  Muslim
#     quran   #8A042A  Quran
#     tabari  #57257D  Tabari
#
#   Other:
#     kampf #6B8E23  Mein Kampf
STYLE_BY_COLOR = {
    # Bible
    0x981E5A: "esv",
    0x005A9E: "jps",
    0x58267E: "kjv",
    0x005696: "lv",
    0x825014: "na",
    0x9A0000: "nas",
    0xA95007: "niv",
    0x00602B: "nlt",
    0x35657A: "nt",
    0x807566: "paul",
    # Islam
    0x005024: "bukhari",
    0x004A82: "ishaq",
    0x984806: "muslim",
    0x8A042A: "quran",
    0x57257D: "tabari",
    # Other
    0x6B8E23: "kampf",
}

# Style code → high-level category. The TTS worker reads this to decide
# which voice profile to use for each span. New styles added to
# STYLE_BY_COLOR above MUST also be added here so downstream routing
# knows where they belong.
CATEGORY_BY_STYLE = {
    # Bible
    "esv": "bible", "jps": "bible", "kjv": "bible", "lv":  "bible",
    "na":  "bible", "nas": "bible", "niv": "bible", "nlt": "bible",
    "nt":  "bible", "paul": "bible",
    # Islam
    "bukhari": "islam", "ishaq": "islam", "muslim": "islam",
    "quran": "islam",   "tabari": "islam",
    # Other
    "kampf": "other",
}

def style_for(span):
    """Style classification for a span based on its RGB color:
      - One of the 15 mapped style codes (see STYLE_BY_COLOR above) when
        the color matches the known source-material palette.
      - 'other' when the color is non-black and isn't in the map —
        keeps every coloured-text style detectable downstream (e.g.
        olive, brown, plain green) without us needing to enumerate
        every Word colour the source might use.
      - None for default body text (color == 0, pure black) so it
        passes through without a data-style attribute."""
    color = span.get("color", 0) or 0
    mapped = STYLE_BY_COLOR.get(color)
    if mapped is not None:
        return mapped
    if color == 0:
        return None
    return 'other'

def category_for(span):
    """High-level source-category for a span: 'bible' / 'islam' /
    'other' / None. None means default body text. 'other' covers both
    explicitly-categorised styles (kampf) and any unmapped non-black
    colour that fell through to style_for's 'other' bucket."""
    style = style_for(span)
    if style is None:
        return None
    return CATEGORY_BY_STYLE.get(style, 'other')

# Back-compat aliases for any older callers. Same return shape.
BIBLE_STYLE_BY_COLOR = STYLE_BY_COLOR
bible_style_for = style_for


def is_bold(span):
    name = span.get("font", "") or ""
    if BOLD_NAME_RE.search(name): return True
    return bool(span.get("flags", 0) & FLAG_BOLD)


def is_italic(span):
    name = span.get("font", "") or ""
    if ITALIC_NAME_RE.search(name): return True
    return bool(span.get("flags", 0) & FLAG_ITALIC)


def escape_html(s):
    return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def block_in_body(block):
    """True if a text block sits in the body band (not header or footer)."""
    if block.get("type", 1) != 0:
        return False
    x0, y0, x1, y1 = block["bbox"]
    # Reject if entirely in the header band or entirely in the footer band.
    # A block straddling a band boundary is rare and we err on the side of
    # keeping it (a body paragraph shouldn't span 600 pt vertically).
    if y1 <= HEADER_MAX_Y: return False
    if y0 >= FOOTER_MIN_Y: return False
    # Drop pure page-number footers that drift into the body band: a single
    # short numeric line near the bottom edge.
    if y0 >= FOOTER_MIN_Y - 20:
        text = "".join(s["text"] for ln in block["lines"] for s in ln["spans"]).strip()
        if text.isdigit() and len(text) <= 4:
            return False
    return True


def render_block(block):
    """
    Walk a block's lines/spans and produce:
      • text_plain — flat text, whitespace normalized
      • text_html  — same text wrapped in <i>/<b> per run
      • runs       — [{text, bold, italic}, ...] for downstream state-
                     machine consumers (translation/cite extractors)
    """
    plain_parts = []
    html_parts = []
    runs = []
    for li, line in enumerate(block["lines"]):
        if li > 0:
            # Lines inside a paragraph block are separated by a single space.
            # Only insert if previous fragment didn't already end with one.
            if plain_parts and not plain_parts[-1].endswith(" "):
                plain_parts.append(" ")
                html_parts.append(" ")
                runs.append({"text": " ", "bold": False, "italic": False})
        for span in line["spans"]:
            text = span.get("text", "")
            if not text:
                continue
            b = is_bold(span)
            i = is_italic(span)
            family = font_family(span)
            style = bible_style_for(span)
            plain_parts.append(text)
            # Build the per-span attribute string once. Bible-style
            # spans get a data-style="…" attr the TTS worker uses to
            # route them through the 'bible' voice category.
            def open_span(font_name):
                attrs = []
                if font_name: attrs.append(f'data-font="{font_name}"')
                if style:     attrs.append(f'data-style="{style}"')
                if not attrs: return ''
                return f'<span {" ".join(attrs)}>'
            # Split the span text into Hebrew-block runs and non-Hebrew
            # runs; wrap each appropriately so we never double-wrap.
            # Hebrew runs always get the synthetic "Hebrew Script" font,
            # overriding the real PDF font (which is what the synth
            # pipeline needs to skip them as one group).
            pieces = []
            last = 0
            for m in HEBREW_BLOCK_RE.finditer(text):
                if m.start() > last:
                    seg = text[last:m.start()]
                    seg_esc = escape_html(seg)
                    op = open_span(family)
                    pieces.append(f'{op}{seg_esc}</span>' if op else seg_esc)
                heb_esc = escape_html(m.group(0))
                # Hebrew script overrides the bible-style colour, but
                # keep the data-style attr too in the rare case both
                # apply.
                heb_attrs = [f'data-font="{HEBREW_PSEUDO_FONT}"']
                if style: heb_attrs.append(f'data-style="{style}"')
                pieces.append(f'<span {" ".join(heb_attrs)}>{heb_esc}</span>')
                last = m.end()
            if last < len(text):
                seg = text[last:]
                seg_esc = escape_html(seg)
                op = open_span(family)
                pieces.append(f'{op}{seg_esc}</span>' if op else seg_esc)
            html = "".join(pieces)
            if i: html = f"<i>{html}</i>"
            if b: html = f"<b>{html}</b>"
            html_parts.append(html)
            runs.append({"text": text, "bold": b, "italic": i, "font": family, "style": style})
    text_plain = re.sub(r"\s+", " ", "".join(plain_parts)).strip()
    text_html  = consolidate_font_spans("".join(html_parts)).strip()
    return text_plain, text_html, runs


_SAME_FONT_SPAN_RE = re.compile(
    r'<span data-font="([^"]+)">([^<]*)</span><span data-font="\1">',
)
def consolidate_font_spans(html: str) -> str:
    """Merge adjacent <span data-font="X">…</span><span data-font="X">…</span>
    pairs into a single span. PyMuPDF emits one span per word/whitespace
    run, which would otherwise produce massive HTML."""
    prev = None
    while prev != html:
        prev = html
        html = _SAME_FONT_SPAN_RE.sub(r'<span data-font="\1">\2', html)
    return html


def load_toc(bundle_dir):
    """Return [(page, chapter_number, chapter_name)] sorted by page."""
    if not bundle_dir:
        return []
    p = Path(bundle_dir) / "toc.json"
    if not p.exists():
        return []
    with p.open(encoding="utf-8") as f:
        data = json.load(f)
    out = []
    for entry in data.get("toc", []):
        m = re.match(r"^\s*(\d+)\s+(.+?)\s*$", entry.get("title", ""))
        if m and entry.get("page"):
            out.append((int(entry["page"]), int(m.group(1)), m.group(2)))
    out.sort()
    return out


def chapter_for_page(page, toc):
    cur = (None, None)
    for p, num, name in toc:
        if p > page: break
        cur = (num, name)
    return cur


def parse_pdf(pdf_path, bundle_dir=None):
    toc = load_toc(bundle_dir)
    out = []
    paragraph_idx = 0
    with fitz.open(pdf_path) as doc:
        for page_num0, page in enumerate(doc):
            page_num = page_num0 + 1
            page_dict = page.get_text("dict", sort=True)
            chap_num, chap_name = chapter_for_page(page_num, toc)
            # Table auto-detect via PyMuPDF. Each detected table is a
            # rectangle in PDF coordinates; we flag any block whose bbox
            # intersects with one so the TTS worker can skip it. Tables
            # of dates / numbers / visibility percentages don't read
            # aloud well, and the user has many such tables across YY
            # books.
            #
            # find_tables() is best-effort — narrative columns can
            # occasionally false-positive. The admin's per-volume
            # volume_skip_pages list is the manual override.
            try:
                tbls = page.find_tables()
                table_rects = [fitz.Rect(t.bbox) for t in tbls.tables] if tbls else []
            except Exception:
                table_rects = []

            for block in page_dict.get("blocks", []):
                if not block_in_body(block):
                    continue
                text_plain, text_html, runs = render_block(block)
                if not text_plain:
                    continue
                # Drop pure-punctuation artifacts (horizontal rules rendered
                # as a stray "-", lone bullet glyphs, etc.). Anything that
                # contains a letter or digit gets through, even if short
                # like "1." or "Yes" — those can be legitimate.
                if not re.search(r"\w", text_plain, re.UNICODE):
                    continue
                paragraph_idx += 1
                warning = None
                if len(text_plain) <= 3 and not re.search(r"</?[bi]>", text_html):
                    warning = "very short paragraph (possible artifact)"
                # Block lives inside a detected table region if its bbox
                # intersects any table rect on this page.
                blk_rect = fitz.Rect(block["bbox"])
                is_table = any(blk_rect.intersects(tr) for tr in table_rects)
                out.append({
                    "page": page_num,
                    "paragraph_number": paragraph_idx,
                    "chapter_number": chap_num,
                    "chapter_name": chap_name,
                    "text_plain": text_plain,
                    "text_html": text_html,
                    "_runs": runs,
                    "warning": warning,
                    "is_table": is_table,
                })
    return out


def main():
    if len(sys.argv) < 2:
        print(__doc__, file=sys.stderr); sys.exit(2)
    pdf_path = sys.argv[1]
    bundle_dir = sys.argv[2] if len(sys.argv) >= 3 else None
    if bundle_dir is None:
        # Default convention: /opt/yada-www/public/<pdf_stem>/
        stem = Path(pdf_path).stem
        guess = Path("/opt/yada-www/public") / stem
        if guess.is_dir():
            bundle_dir = str(guess)
    paras = parse_pdf(pdf_path, bundle_dir)
    print(f"# {len(paras)} paragraphs from {pdf_path}", file=sys.stderr)
    # Strip private fields (_runs) before serializing for human consumption.
    for p in paras:
        clean = {k: v for k, v in p.items() if not k.startswith("_")}
        print(json.dumps(clean, ensure_ascii=False))


if __name__ == "__main__":
    main()
