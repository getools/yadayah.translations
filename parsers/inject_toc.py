#!/usr/bin/env python3
"""
Inject TOC bookmarks into a PDF that's missing them, using the
yy_chapter rows for that volume. Modifies the PDF in place.

Usage: inject_toc.py <pdf-path> <volume-key> [--offset N]

  chapter_page in yy_chapter is the footer page number — what the
  reader sees printed in the book. To address a PDF bookmark we need
  the physical PDF page number, which is `chapter_page + offset`.

  Every YY book uses the same front-matter template (cover + about
  author + TOC) totalling 6 pages before chapter 1's content, so the
  default offset is 6 — same constant the operator uses when reading
  page numbers in the picker. Pass --offset N to override for any book
  that differs.

Skips silently if the PDF already has bookmarks (idempotent).
"""
import sys
import os
import subprocess
import fitz  # PyMuPDF


# Postgres lives in the yada-postgres-prod container and has no host port
# mapping, so we shell out through `docker exec`. psycopg2 isn't installed
# on the host Python anyway.
def chapter_rows(volume_key):
    sql = (
        "SELECT chapter_number, chapter_name, chapter_page "
        "FROM yy_chapter "
        f"WHERE volume_key = {int(volume_key)} AND chapter_page IS NOT NULL "
        "ORDER BY chapter_sort, chapter_number"
    )
    out = subprocess.check_output([
        "docker", "exec", "yada-postgres-prod",
        "psql", "-U", "postgres", "-d", "yada",
        "-At", "-F", "\t", "-c", sql,
    ], text=True)
    rows = []
    for line in out.splitlines():
        line = line.rstrip("\n")
        if not line:
            continue
        parts = line.split("\t")
        if len(parts) < 3:
            continue
        rows.append((int(parts[0]), parts[1], int(parts[2])))
    return rows


DEFAULT_OFFSET = 6  # YY books' front-matter page count (cover + about + TOC).


def main():
    args = sys.argv[1:]
    offset = DEFAULT_OFFSET
    if "--offset" in args:
        i = args.index("--offset")
        offset = int(args[i + 1])
        del args[i:i + 2]
    if len(args) < 2:
        sys.stderr.write("usage: inject_toc.py <pdf-path> <volume-key> [--offset N]\n")
        sys.exit(2)
    pdf_path = args[0]
    vol_key = int(args[1])

    doc = fitz.open(pdf_path)
    existing = doc.get_toc()
    if existing:
        sys.stderr.write(f"PDF already has {len(existing)} TOC entries; skipping.\n")
        doc.close()
        return

    chapters = chapter_rows(vol_key)
    if not chapters:
        sys.stderr.write(f"No yy_chapter rows for volume_key={vol_key}; nothing to inject.\n")
        doc.close()
        return

    page_count = doc.page_count
    new_toc = []
    for num, name, footer_pg in chapters:
        physical = int(footer_pg) + offset
        # Clamp so a bogus chapter_page doesn't write a bookmark past the
        # last PDF page (PyMuPDF rejects out-of-range targets at save time).
        physical = max(1, min(physical, page_count))
        title = f"{num}  {name}" if name else str(num)
        new_toc.append([1, title, physical])
        sys.stderr.write(f"  ch{num}  footer p.{footer_pg}  → PDF p.{physical}  '{name}'\n")

    doc.set_toc(new_toc)
    tmp = pdf_path + ".toc-tmp"
    doc.save(tmp, deflate=True, garbage=3)
    doc.close()
    os.replace(tmp, pdf_path)
    sys.stderr.write(f"Injected {len(new_toc)} bookmarks into {pdf_path} (offset={offset})\n")


if __name__ == "__main__":
    main()
