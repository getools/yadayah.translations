#!/usr/bin/env python3
"""
Read embedded PDF bookmarks and write the flipbook's toc.json.

Usage: extract_toc.py <pdf-path> <volume-key-unused> <out-json-path>

The volume_key arg is unused but kept for backwards-compat with the
old migrate_flipbook.sh interface — the old script accepted it for a
DB fallback that never shipped.

Output schema (matches the format the flipbook viewer expects, which
the seven already-working flipbooks were built against):

  {
    "page_count": N,
    "toc": [
      { "title": str, "page": int, "level": 0, "children": [] },
      ...
    ]
  }

Hierarchy: PyMuPDF returns [level, title, page] tuples with 1-based
levels. We flatten level > 1 into children of the most recent parent
so the viewer's recursive walker renders nesting correctly. Empty
children arrays are still emitted for parity with the existing
production files.
"""
import sys
import json
import fitz  # PyMuPDF


def build_tree(flat_toc):
    """Convert [[level, title, page], ...] to nested dicts."""
    root = []
    # Stack of (level, list_to_append_children_to)
    stack = [(0, root)]
    for level, title, page in flat_toc:
        node = {
            "title": title,
            "page": int(page) if page else None,
            "level": level - 1,  # 0-indexed to match existing files
            "children": [],
        }
        # Walk back up until we find a parent at level - 1
        while stack and stack[-1][0] >= level:
            stack.pop()
        if not stack:
            stack.append((0, root))
        stack[-1][1].append(node)
        stack.append((level, node["children"]))
    return root


def main():
    if len(sys.argv) < 4:
        sys.stderr.write("usage: extract_toc.py <pdf> <volume-key> <out-json>\n")
        sys.exit(2)
    pdf_path, _vol_key, out_path = sys.argv[1], sys.argv[2], sys.argv[3]
    doc = fitz.open(pdf_path)
    toc = doc.get_toc()
    payload = {
        "page_count": doc.page_count,
        "toc": build_tree(toc),
    }
    doc.close()
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)
    sys.stderr.write(f"wrote {len(toc)} TOC entries to {out_path}\n")


if __name__ == "__main__":
    main()
