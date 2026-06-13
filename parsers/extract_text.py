#!/usr/bin/env python3
"""Extract whole-page text for a flipbook bundle's search.json.

Reproduces the (lost) /tmp/extract_text.py helper that
migrate_flipbook.sh used to call. Reads a PDF via PyMuPDF and emits
one entry per page in the JSON shape the flipbook viewer expects:

  { "pages": [ {"p": 1, "t": "…page text…"}, ... ] }

The viewer treats `t` as a flat lowercase haystack: it does a
case-insensitive substring search and highlights matches with the
existing highlightSnippet helper. We collapse whitespace so a query
spanning what was originally a line break still matches.

Usage: extract_text.py <pdf> <out_json>
"""
import sys
import re
import json
import fitz  # PyMuPDF


_WS = re.compile(r"\s+", re.UNICODE)


def main(pdf_path: str, out_path: str) -> None:
    doc = fitz.open(pdf_path)
    pages = []
    for i, page in enumerate(doc, start=1):
        raw = page.get_text() or ""
        # Collapse newlines + runs of whitespace so multi-line phrases
        # match the same way a reader thinks of them. Strip the result
        # to avoid leading/trailing spaces in the JSON payload.
        text = _WS.sub(" ", raw).strip()
        if text:
            pages.append({"p": i, "t": text})
    doc.close()
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump({"pages": pages}, f, ensure_ascii=False)
    sys.stderr.write(f"wrote {len(pages)} pages to {out_path}\n")


if __name__ == "__main__":
    if len(sys.argv) < 3:
        sys.stderr.write("usage: extract_text.py <pdf> <out_json>\n")
        sys.exit(2)
    main(sys.argv[1], sys.argv[2])
