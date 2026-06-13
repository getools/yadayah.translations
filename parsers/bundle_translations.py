#!/usr/bin/env python3
"""
bundle_translations.py — Phase 2 of the PDF-only parser pipeline.

Walks the paragraph stream emitted by bundle_paragraphs.parse_pdf and
extracts translation rows using the same two patterns the docx parser
used, applied now to the PyMuPDF-derived runs (which have reliable
bold/italic flags from real font names):

  Pattern A (curly-quote bold):
      A bold left-curly-quote (U+201C) opens a translation. Capture
      continues across runs and even across paragraphs until a bold
      right-curly-quote (U+201D). Text between the quotes = text_word.
      Text after the closing quote, up to and including the first
      "(...)", is parsed as the cite.

  Pattern B (bold-cite paragraph):
      A paragraph that's predominantly bold and ends with "(...)" parsed
      as a Bible citation (Book chapter:verse[-end]) becomes a translation
      whose text_word is the bold body and whose cite is the trailing
      "(...)".

Output (one JSON object per line on stdout, or via extract_translations()):
  {
    "page":            physical PDF page (1-indexed),
    "paragraph_index": 1-indexed paragraph this translation came from,
    "text_word":       str (with <i>/<b> wrappers preserved),
    "cite":            str (raw cite text inside the parens),
    "cite_name":       str or None,
    "cite_chapter":    int or None,
    "cite_verse":      int or None,
    "cite_verse_end":  int or None,
    "cite_note":       str or None,
    "cite_hebrew":     str or None,
    "cite_common":     str or None,
    "pattern":         "curly-quote" | "bold-cite",
  }

Usage:
  bundle_translations.py <pdf_path> [<bundle_dir>]
"""
import json, re, sys
from pathlib import Path

# Import paragraph parser as a sibling module.
sys.path.insert(0, str(Path(__file__).resolve().parent))
import bundle_paragraphs as bp

LEFT_QUOTE  = "“"
RIGHT_QUOTE = "”"

# Citation-shape detection. We pull out the FIRST parenthesized chunk and
# test it for "Book ch:vs" or bare "ch:vs". Note: parens can contain other
# parens, but Bible cites in YY books don't, so a non-greedy [^)]+ is fine.
PAREN_RE = re.compile(r"\(([^)]+)\)")

# A bible-style citation looks like "Name … 12:34" or "Name … 12:34-36".
# The note tail (free text after the verse range) is allowed.
CITE_FULL_RE  = re.compile(r"^(.+?)\s+(\d+):(\d+)(?:-(\d+))?\s*(.*)$")
CITE_BARE_RE  = re.compile(r"^(\d+):(\d+)(?:-(\d+))?\s*(.*)$")


def escape_html(s):
    return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def runs_to_html(runs):
    """Render a list of run dicts back to <b>/<i> HTML."""
    out = []
    for r in runs:
        h = escape_html(r["text"])
        if r["italic"]: h = f"<i>{h}</i>"
        if r["bold"]:   h = f"<b>{h}</b>"
        out.append(h)
    return "".join(out).strip()


def parse_cite(cite_text):
    """
    Split a citation into (name, chapter, verse, verse_end, note).
    Mirrors parse_word_translations.parse_cite so existing DB-shape code
    can consume the output unchanged.
    """
    if not cite_text:
        return (None, None, None, None, None)
    cite_text = cite_text.strip()

    m = CITE_FULL_RE.match(cite_text)
    if m:
        name      = m.group(1).strip()
        chapter   = int(m.group(2))
        verse     = int(m.group(3))
        verse_end = int(m.group(4)) if m.group(4) else None
        note_raw  = (m.group(5) or "").strip()
        note      = re.sub(r"^[^a-zA-Z]+|[^a-zA-Z]+$", "", note_raw) or None
        return (name, chapter, verse, verse_end, note)

    m = CITE_BARE_RE.match(cite_text)
    if m:
        chapter   = int(m.group(1))
        verse     = int(m.group(2))
        verse_end = int(m.group(3)) if m.group(3) else None
        note_raw  = (m.group(4) or "").strip()
        note      = re.sub(r"^[^a-zA-Z]+|[^a-zA-Z]+$", "", note_raw) or None
        return (None, chapter, verse, verse_end, note)

    return (cite_text, None, None, None, None)


def split_cite_name(cite_name):
    """('Yirmaʾyah / Yah Uplifts / Jeremiah') → ('Yirmaʾyah', 'Jeremiah')."""
    if not cite_name:
        return (None, None)
    if "/" not in cite_name:
        return (cite_name.strip(), None)
    parts = [p.strip() for p in cite_name.split("/")]
    return (parts[0], parts[-1])


def emit_translation(page, paragraph_index, captured_runs, cite_text, pattern):
    """Build a translation dict from a captured run list + raw cite text."""
    text_word = runs_to_html(captured_runs).strip()
    if not text_word:
        return None
    name, ch, vs, vs_end, note = parse_cite(cite_text)
    hebrew, common = split_cite_name(name)
    return {
        "page": page,
        "paragraph_index": paragraph_index,
        "text_word": text_word,
        "cite": cite_text,
        "cite_name": name,
        "cite_chapter": ch,
        "cite_verse": vs,
        "cite_verse_end": vs_end,
        "cite_note": note,
        "cite_hebrew": hebrew,
        "cite_common": common,
        "pattern": pattern,
    }


def is_bold_paragraph(runs, threshold=0.7):
    """
    True if at least `threshold` fraction of non-whitespace characters
    in the paragraph are bold. Used by the bold-cite pattern.
    """
    bold = 0
    total = 0
    for r in runs:
        n = sum(1 for c in r["text"] if not c.isspace())
        total += n
        if r["bold"]:
            bold += n
    if total == 0:
        return False
    return (bold / total) >= threshold


def extract_translations(paragraphs):
    """
    Walk the paragraph stream and yield translation dicts.

    State machine for Pattern A is implemented inline. Pattern B is a
    pure per-paragraph check on bold-density + trailing-cite shape.
    """
    out = []

    # ── Pattern A state ────────────────────────────────────────────────
    inside       = False     # we're between LEFT_QUOTE and RIGHT_QUOTE
    captured     = []        # runs captured between quotes
    cite_buffer  = ""        # text accumulated AFTER right quote, awaiting "(...)"
    cite_paragraph_index = None
    start_page   = None
    start_idx    = None

    def reset_pattern_a():
        nonlocal inside, captured, cite_buffer, cite_paragraph_index, start_page, start_idx
        inside = False
        captured = []
        cite_buffer = ""
        cite_paragraph_index = None
        start_page = None
        start_idx = None

    for p in paragraphs:
        runs = p.get("_runs") or []
        page = p["page"]
        pidx = p["paragraph_number"]

        # ── Pattern B: a paragraph that's mostly bold AND ends with a
        # parenthesized cite. This fires INDEPENDENTLY of pattern A —
        # there's no overlap because pattern A always involves curly
        # quotes inside the bold span, while pattern B is a flat bold
        # statement followed by its source.
        if not inside and is_bold_paragraph(runs):
            text_plain = p["text_plain"]
            m = re.search(r"\(([^)]+)\)\s*$", text_plain)
            if m:
                cite_text = m.group(1).strip()
                # Only emit if the parenthetical parses as a Bible cite
                # (chapter:verse pattern present), otherwise it's just
                # a bold paragraph that happens to end with parens.
                if CITE_FULL_RE.match(cite_text) or CITE_BARE_RE.match(cite_text):
                    # Strip the trailing "(...)" from the captured runs
                    # for text_word — we want the statement, not the cite.
                    body_runs = strip_trailing_cite_from_runs(runs)
                    t = emit_translation(page, pidx, body_runs, cite_text, "bold-cite")
                    if t:
                        out.append(t)
                    continue  # don't also feed this paragraph into pattern A

        # ── Pattern A: walk runs looking for bold curly quotes.
        for r in runs:
            text = r["text"]
            bold = r["bold"]

            if not inside:
                if LEFT_QUOTE in text and bold:
                    inside = True
                    start_page = page
                    start_idx = pidx
                    captured = []
                    cite_buffer = ""
                    # Capture from after the left quote onward.
                    after = text.split(LEFT_QUOTE, 1)[1]
                    # The remaining run keeps its formatting flags.
                    leading_run = {"text": after, "bold": bold, "italic": r["italic"]}
                    # If the right quote is in the same run, close out now.
                    if RIGHT_QUOTE in after and bold:
                        before_close, after_close = after.split(RIGHT_QUOTE, 1)
                        captured.append({"text": before_close, "bold": bold, "italic": r["italic"]})
                        cite_buffer = after_close
                        # Try to finalize from this paragraph's remaining runs +
                        # buffer; if no "(...)" is found here, keep building
                        # cite_buffer across subsequent runs / paragraphs.
                        inside = "awaiting_cite"
                        continue
                    captured.append(leading_run)
                # else: stay searching
            elif inside is True:
                # Capturing the body. Look for the closing quote.
                if RIGHT_QUOTE in text and bold:
                    before_close, after_close = text.split(RIGHT_QUOTE, 1)
                    captured.append({"text": before_close, "bold": bold, "italic": r["italic"]})
                    cite_buffer = after_close
                    inside = "awaiting_cite"
                else:
                    captured.append(r)
            elif inside == "awaiting_cite":
                cite_buffer += text

            if inside == "awaiting_cite":
                m = PAREN_RE.search(cite_buffer)
                if m:
                    cite_text = m.group(1).strip()
                    t = emit_translation(start_page, start_idx, captured, cite_text, "curly-quote")
                    if t:
                        out.append(t)
                    reset_pattern_a()

        # End-of-paragraph: if we're awaiting a cite but haven't found one
        # yet, keep state — translations can have the cite on the next
        # paragraph. If we're mid-capture (inside=True), same: paragraph
        # wraps are common.
        # However, if too much text accumulates without a "(...)" the
        # state machine has probably been stranded by a missing close
        # quote in the source; bail rather than drag state forever.
        if inside == "awaiting_cite" and len(cite_buffer) > 1500:
            reset_pattern_a()
        if inside is True and sum(len(rr["text"]) for rr in captured) > 5000:
            reset_pattern_a()

    return out


def strip_trailing_cite_from_runs(runs):
    """
    Remove the trailing "(...)" plus any preceding whitespace from a run
    list, returning a new run list. Only the visible "(...)" gets
    stripped — earlier parens elsewhere in the paragraph survive.
    """
    # Reconstruct flat text to find where the trailing cite begins.
    flat = "".join(r["text"] for r in runs)
    m = re.search(r"\([^)]+\)\s*$", flat)
    if not m:
        return runs
    cut = m.start()
    # Trim trailing whitespace before the open paren.
    while cut > 0 and flat[cut - 1].isspace():
        cut -= 1
    # Walk runs and truncate at the cut point.
    new_runs = []
    consumed = 0
    for r in runs:
        end = consumed + len(r["text"])
        if end <= cut:
            new_runs.append(r)
        elif consumed >= cut:
            break
        else:
            keep = cut - consumed
            new_runs.append({"text": r["text"][:keep],
                             "bold": r["bold"], "italic": r["italic"]})
            break
        consumed = end
    return new_runs


def main():
    if len(sys.argv) < 2:
        print(__doc__, file=sys.stderr); sys.exit(2)
    pdf_path = sys.argv[1]
    bundle_dir = sys.argv[2] if len(sys.argv) >= 3 else None
    if bundle_dir is None:
        stem = Path(pdf_path).stem
        guess = Path("/opt/yada-www/public") / stem
        if guess.is_dir():
            bundle_dir = str(guess)
    paragraphs = bp.parse_pdf(pdf_path, bundle_dir)
    translations = extract_translations(paragraphs)
    print(f"# {len(paragraphs)} paragraphs → {len(translations)} translations from {pdf_path}",
          file=sys.stderr)
    for t in translations:
        print(json.dumps(t, ensure_ascii=False))


if __name__ == "__main__":
    main()
