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
import json, re, struct, sys
from pathlib import Path

import fitz  # PyMuPDF

# ── Header/footer cutoffs in PDF points.
# YY books are 6"×9" = 432×648 pt. Running header (book title + chapter
# name) sits in the top inch, footer page number in the bottom half-inch.
# Use generous bands so jitter doesn't slip headers into content.
#
# ⚠ 55.0 was too tight. The running header is set in the book's display font,
# and where its text carries an embedded-subset glyph (the ʿ/ʾ half-rings in
# "Mowʿed ~ Appointments" / "ʾAdam ~ The Story of Man") that glyph's oversized
# box drags the block's bbox bottom to y1≈61.4 — clearing a 55pt cutoff, so
# the header survived as a body paragraph and got NARRATED once every other
# page in s02v02 and s02v06. Body text starts at y0>=64.4 in every YY PDF
# (all 432x648), so 63.0 clears the descender and still leaves the body clear.
HEADER_MAX_Y = 63.0   # ~0.87"
FOOTER_MIN_Y = 595.0  # ~8.26" — leaves the body comfortably clear

# Top zone a running header can occupy at all. Used by the repetition pass
# below, which is the real guarantee: geometry alone is one font change away
# from leaking again.
HEADER_ZONE_Y = 80.0
# A line that appears as the page's TOPMOST block on at least this share of
# pages is a running header, not prose. Real body text never repeats verbatim
# at the top of a quarter of the book.
HEADER_REPEAT_RATIO = 0.25

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

# ── Canonical font names ─────────────────────────────────────────────
# The name a run lands on MUST equal a yy_tts_font.tts_font_name exactly:
# preprocessFontFilter() looks the rule up with $fonts[$font], a plain
# array key. A near-miss is a silent no-op, not an error.
#
# ⚠ Word EMBEDS its non-standard fonts under obfuscated names — the PDF
# reports "___WRD_EMBED_SUB_1408", never "Yada Towrah". No rule can ever
# match that, so the skip flag on every special font was dead: the divine
# name (hwhy, set in Yada Towrah) was being read aloud as gibberish, as
# were the ʾ/ʿ half-rings inside transliterated words. The real name does
# survive inside the embedded font program's `name` table (PostScript
# name), so we recover it from there — see embedded_font_aliases().
#
# The SUB_#### numbers are NOT stable across books (1436 is IsaiahScroll
# in one PDF and PictoHeb in another), so the alias map is rebuilt per PDF.
FONT_CANON = {
    "yadatowrahtimes":    "Yada Towrah",
    "yadatowrah":         "Yada Towrah",
    "jupiteryadaregular": "Jupiter-Yada",
    "jupiteryada":        "Jupiter-Yada",
    "isaiahscroll":       "Isaiah Scroll",
    "moabitestone":       "Moabite Stone",
    "pictoheb":           "PictoHeb",
    "hebrewscript":       "Hebrew Script",
    "semiticearly":       "Semitic Early",
}


def canon_font(name):
    """Map a raw family/PostScript name onto its yy_tts_font rule name."""
    key = re.sub(r"[^a-z0-9]", "", (name or "").lower())
    return FONT_CANON.get(key, name)


def _ttf_ps_name(buf):
    """PostScript / family name out of an embedded TrueType `name` table."""
    try:
        num = struct.unpack(">H", buf[4:6])[0]
        off = None
        for i in range(num):
            rec = buf[12 + 16 * i: 12 + 16 * i + 16]
            if rec[0:4] == b"name":
                off = struct.unpack(">I", rec[8:12])[0]
                break
        if off is None:
            return None
        _fmt, count, str_off = struct.unpack(">HHH", buf[off:off + 6])
        best = {}
        for i in range(count):
            p = off + 6 + 12 * i
            pid, _eid, _lid, nid, ln, so = struct.unpack(">HHHHHH", buf[p:p + 12])
            raw = buf[off + str_off + so: off + str_off + so + ln]
            try:
                txt = raw.decode("utf-16-be") if pid == 3 else raw.decode("latin-1")
            except Exception:
                continue
            if nid in (1, 6) and txt.strip():
                best.setdefault(nid, txt.strip())
        family, ps = best.get(1, ""), best.get(6, "")
        # Word obfuscates the family name but leaves PostScript intact.
        return ps if (not family or "___WRD_EMBED" in family) else family
    except Exception:
        return None


def embedded_font_aliases(doc):
    """{obfuscated basefont → canonical family} for one PDF."""
    aliases = {}
    for pno in range(doc.page_count):
        for f in doc.get_page_fonts(pno, full=True):
            xref, basefont = f[0], SUBSET_PREFIX_RE.sub("", f[3])
            if "___WRD_EMBED" not in basefont or basefont in aliases:
                continue
            try:
                real = _ttf_ps_name(doc.extract_font(xref)[3])
            except Exception:
                real = None
            if real:
                aliases[basefont] = canon_font(real)
    return aliases


def font_family(span, aliases=None):
    """Clean PyMuPDF font name → the family name the TTS rules key on."""
    name = (span.get("font") or "").strip()
    if not name: return ""
    name = SUBSET_PREFIX_RE.sub("", name)
    if aliases and name in aliases:
        return aliases[name]          # Word-embedded: recovered real family
    # Strip variant suffixes iteratively in case multiple are present.
    prev = None
    while prev != name:
        prev = name
        name = VARIANT_SUFFIX_RE.sub("", name).rstrip("-,")
    return canon_font(name.replace("-", " ").strip())

# ── Glyph fonts vs the half-rings that live inside them ──────────────
# Yada Towrah (and the other Hebrew/paleo faces) carry TWO different
# things, and they must not share a fate:
#
#   1. Glyph text — "hwhy", "zy": ASCII that RENDERS as Hebrew. Nonsense
#      when read aloud, so the font is marked skip in yy_tts_font.
#   2. The ʾ/ʿ half-rings inside transliterated words — "huwʾ", "Yisraʾel",
#      "ʾatah". Word sets these in the glyph font too (it owns the glyph),
#      but they are part of the ENGLISH transliteration.
#
# Skipping the font wholesale would strip the half-rings out of ~155k runs
# — and 510 pronunciation tunes are keyed on prints that CONTAIN them
# ("huwʾ" → huːʔ, "Yisraʾel" → jɪsɹˈɑʕɛl). Those tunes would silently stop
# matching corpus-wide: a far worse regression than the bug being fixed.
# So a run is only handed to the skipped font when it is glyph text and
# nothing else; anything holding a half-ring stays plain text.
GLYPH_FONTS = {"Yada Towrah", "PictoHeb", "Isaiah Scroll", "Moabite Stone", "Semitic Early"}
HALF_RING_RE = re.compile(r"[ʾʿʾʿ]")
GLYPH_TEXT_RE = re.compile(r"[A-Za-z0-9]")


def glyph_font_for(family, text):
    """The data-font a run should carry — '' means 'plain text, never skipped'."""
    if family not in GLYPH_FONTS:
        return family
    if HALF_RING_RE.search(text):
        return ""                      # transliteration diacritic — must be spoken
    if GLYPH_TEXT_RE.search(text):
        return family                  # real glyph text — skip it
    return ""                          # whitespace / punctuation — keep spacing intact


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


def render_block(block, aliases=None):
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
            family = font_family(span, aliases)
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
                    op = open_span(glyph_font_for(family, seg))
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
                op = open_span(glyph_font_for(family, seg))
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


def _norm_line(s):
    return re.sub(r"\s+", " ", s or "").strip()


def running_header_texts(doc, aliases=None):
    """Lines that recur as the page's TOPMOST block across the book.

    The geometric band above is necessary but not sufficient: it only takes a
    taller glyph in the header font to push the block past the cutoff, which is
    exactly how the book title leaked into the paragraphs (and the audiobook)
    for s02v02 / s02v06. Repetition is the property a running header actually
    has and prose never does — the same line sitting at the top of a quarter of
    the pages. Returns the set of such lines; the block loop drops them.
    """
    counts = {}
    pages = 0
    for page in doc:
        pages += 1
        blocks = [b for b in page.get_text("dict", sort=True)["blocks"]
                  if b.get("type", 1) == 0 and b.get("lines")]
        if not blocks:
            continue
        top = min(blocks, key=lambda b: b["bbox"][1])
        if top["bbox"][1] > HEADER_ZONE_Y:
            continue                      # nothing in the header zone on this page
        text = _norm_line(render_block(top, aliases)[0])
        if text:
            counts[text] = counts.get(text, 0) + 1
    if not pages:
        return set()
    threshold = pages * HEADER_REPEAT_RATIO
    return {t for t, c in counts.items() if c >= threshold}


def parse_pdf(pdf_path, bundle_dir=None):
    toc = load_toc(bundle_dir)
    out = []
    paragraph_idx = 0
    with fitz.open(pdf_path) as doc:
        aliases = embedded_font_aliases(doc)
        if aliases:
            print("embedded fonts resolved: %s" % sorted(set(aliases.values())), file=sys.stderr)
        header_texts = running_header_texts(doc, aliases)
        if header_texts:
            print("running header(s) dropped: %s" % sorted(header_texts), file=sys.stderr)
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
                text_plain, text_html, runs = render_block(block, aliases)
                if not text_plain:
                    continue
                # Running header that cleared the geometric band (a tall glyph
                # in the header font is all it takes) — drop it on repetition.
                if (header_texts and block["bbox"][1] <= HEADER_ZONE_Y
                        and _norm_line(text_plain) in header_texts):
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
                    "is_continuation": False,
                })
    mark_page_break_continuations(out)
    return out


# ── Page-break continuation detection ────────────────────────────────────
#
# PyMuPDF emits one block per page, so a single logical paragraph that
# wraps across a page break ends up as TWO yy_paragraph rows: the head on
# page N and a "tail" fragment on page N+1. The tail is usually:
#   • a mid-sentence wrap (starts with a lowercase letter), or
#   • a citation fragment like "(Yashaʿyah / Isaiah 57:10)" or "62:2)",
#   • an orphan close-paren.
#
# We flag the tail with is_continuation=True; the TTS worker coalesces
# flagged tails into their preceding head before synthesis, so a logical
# paragraph reads as one block. Display / search / translations see the
# rows individually — only TTS opts into the coalesce.
_CONT_CITATION_FRAG_RE = re.compile(
    r'^\([A-Za-zʿʾ][A-Za-zʿʾ\s/]+\s+\d+:\d+(?:-\d+)?\)|'   # (Book ch:v) or (Book ch:v-end)
    r'^\d+:\d+(?:-\d+)?\)|'                                                    # bare 'ch:v)' tail
    r'^\)'                                                                      # orphan close paren
)


def _is_page_continuation(prev, cur):
    """True iff `cur` looks like a page-wrap continuation of `prev`.
    Heuristics intentionally conservative — we'd rather miss a continuation
    than incorrectly merge two real paragraphs."""
    if cur["page"] - prev["page"] != 1:
        return False
    prev_text = (prev.get("text_plain") or "").rstrip()
    cur_text  = (cur.get("text_plain")  or "").lstrip()
    if not prev_text or not cur_text:
        return False
    # Lone-digit chapter-heading "5" paragraphs are NOT continuations.
    if cur_text.strip().isdigit():
        return False
    cur_first = cur_text[0]
    # Mid-sentence wrap (current paragraph starts mid-clause).
    if cur_first.islower():
        return True
    # Citation fragment patterns.
    if _CONT_CITATION_FRAG_RE.match(cur_text):
        return True
    # Orphan close paren as first character.
    if cur_first == ')':
        return True
    return False


def mark_page_break_continuations(out):
    """Walks `out` and sets is_continuation=True on detected page-wrap
    continuations. The head paragraph's flag stays False. Mutates in place."""
    for i in range(1, len(out)):
        if _is_page_continuation(out[i-1], out[i]):
            out[i]["is_continuation"] = True
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
