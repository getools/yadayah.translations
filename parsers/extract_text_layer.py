#!/usr/bin/env python3
"""Extract per-page text-layer JSON for a flipbook bundle.

Reproduces the (lost) /tmp/extract_text_layer.py helper that
migrate_flipbook.sh used to call. Reads a PDF via PyMuPDF, emits one
JSON per page at <out_dir>/page-NNN.json in the format the flipbook
viewer's ensureCarouselText / ensureTextLayer expects.

Span shape: [x%, y%, x_end%, h%, text, flags, font_idx]

Coordinates are percentages of the page width/height so the viewer can
scale them to whatever pixel size the carousel slot ends up at.

Usage: extract_text_layer.py <pdf> <out_dir>
"""
import sys, os, json
import fitz   # PyMuPDF

def main(pdf_path, out_dir):
    os.makedirs(out_dir, exist_ok=True)
    doc = fitz.open(pdf_path)
    for i, page in enumerate(doc, start=1):
        rect = page.rect
        W, H = rect.width, rect.height
        if W <= 0 or H <= 0:
            continue
        fonts = {}
        spans = []
        td = page.get_text("dict")
        for block in td.get("blocks", []):
            for line in block.get("lines", []):
                for sp in line.get("spans", []):
                    text = sp.get("text", "")
                    if not text or not text.strip():
                        continue
                    bbox = sp.get("bbox") or (0, 0, 0, 0)
                    x0, y0, x1, y1 = bbox
                    if x1 <= x0 or y1 <= y0:
                        continue
                    font = sp.get("font", "")
                    flags = int(sp.get("flags", 0))
                    if font not in fonts:
                        fonts[font] = len(fonts)
                    # span[2] is WIDTH as a percentage of page width (the
                    # viewer multiplies it by pageW to get the target
                    # render width for transform: scaleX). x_end would be
                    # wrong — it produced grossly stretched select-
                    # rectangles for the 26 books I regenerated earlier.
                    spans.append([
                        round(x0 / W * 100, 3),
                        round(y0 / H * 100, 3),
                        round((x1 - x0) / W * 100, 3),
                        round((y1 - y0) / H * 100, 3),
                        text,
                        flags,
                        fonts[font],
                    ])
        payload = {
            "w": W,
            "h": H,
            "spans": spans,
            "fonts": {str(idx): name for name, idx in fonts.items()},
        }
        out_path = os.path.join(out_dir, "page-%03d.json" % i)
        with open(out_path, "w", encoding="utf-8") as f:
            json.dump(payload, f, ensure_ascii=False, separators=(",", ":"))
    return 0

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("usage: extract_text_layer.py <pdf> <out_dir>", file=sys.stderr)
        sys.exit(2)
    sys.exit(main(sys.argv[1], sys.argv[2]))
