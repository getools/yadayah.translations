#!/usr/bin/env python3
"""
strip_obfuscated_fonts.py — Remove embedded obfuscated TrueType fonts
(.odttf files) from a DOCX so Microsoft Graph's media-transform service
can render it. Some YY docs embed 8-10 obfuscated fonts in
word/fonts/font*.odttf which Graph's renderer chokes on (returning 500
from centralus1-mediap.svc.ms). Stripping them lets Microsoft fall back
to system Calibri/Times-equivalent fonts for rendering.

Text content and pagination are preserved — only font glyph appearance
may shift slightly. For our use case (PyMuPDF text extraction, search
indexing) that's irrelevant; visual rendering in flipbooks may differ
by a hairline kerning on body type.

Steps:
  1. Unzip the .docx (it's a ZIP container).
  2. Remove every word/fonts/*.odttf entry.
  3. Clean references in word/fontTable.xml: drop <w:embedRegular> /
     <w:embedBold> / <w:embedItalic> / <w:embedBoldItalic> elements
     that point to the now-deleted font files.
  4. Clean references in word/_rels/fontTable.xml.rels: drop
     <Relationship> entries whose Target was a fonts/ path.
  5. Clean references in [Content_Types].xml: remove the
     application/vnd.openxmlformats-officedocument.obfuscatedFont entry
     (so Word doesn't expect the type to be declared).
  6. Re-zip into output path.

Usage:
    strip_obfuscated_fonts.py <input.docx> <output.docx>
"""

import re
import shutil
import sys
import zipfile
from pathlib import Path


def strip(input_path: Path, output_path: Path) -> dict:
    """Return a dict summary of what was stripped."""
    summary = {
        "input": str(input_path),
        "output": str(output_path),
        "fonts_removed": [],
        "embed_refs_removed": 0,
        "relationships_removed": 0,
        "content_types_modified": False,
        "input_size": input_path.stat().st_size,
        "output_size": 0,
    }

    if not zipfile.is_zipfile(input_path):
        raise ValueError(f"{input_path} is not a ZIP / DOCX")

    with zipfile.ZipFile(input_path, "r") as src:
        names = src.namelist()
        odttf_paths = [n for n in names if n.startswith("word/fonts/") and n.endswith(".odttf")]
        odttf_basenames = {n.split("/")[-1] for n in odttf_paths}
        summary["fonts_removed"] = sorted(odttf_paths)

        # Read text files we may modify.
        font_table_xml = None
        font_table_rels_xml = None
        content_types_xml = None
        if "word/fontTable.xml" in names:
            font_table_xml = src.read("word/fontTable.xml").decode("utf-8")
        if "word/_rels/fontTable.xml.rels" in names:
            font_table_rels_xml = src.read("word/_rels/fontTable.xml.rels").decode("utf-8")
        if "[Content_Types].xml" in names:
            content_types_xml = src.read("[Content_Types].xml").decode("utf-8")

        # Build set of relationship IDs that point to font files —
        # these need to be removed from fontTable.xml's <w:embed*> tags.
        font_rids = set()
        if font_table_rels_xml:
            # Match <Relationship Id="rIdX" ... Target="fonts/fontN.odttf" />
            for m in re.finditer(
                r'<Relationship\b[^/>]*\bId="([^"]+)"[^/>]*\bTarget="(fonts/[^"]+\.odttf)"',
                font_table_rels_xml,
            ):
                rid, target = m.group(1), m.group(2)
                if target.split("/")[-1] in odttf_basenames:
                    font_rids.add(rid)

        # 3. Clean fontTable.xml — drop embed* elements that reference
        #    those rids.
        if font_table_xml and font_rids:
            for rid in font_rids:
                # <w:embedRegular r:id="rIdX"/> (self-closing) or
                # <w:embedRegular r:id="rIdX"></w:embedRegular>
                pat = re.compile(
                    r'<w:embed(?:Regular|Bold|Italic|BoldItalic)\b[^/>]*\br:id="'
                    + re.escape(rid)
                    + r'"[^/>]*/>',
                )
                new_xml, n = pat.subn("", font_table_xml)
                font_table_xml = new_xml
                summary["embed_refs_removed"] += n

        # 4. Clean fontTable.xml.rels — drop the <Relationship> lines.
        if font_table_rels_xml:
            before = len(font_table_rels_xml)
            font_table_rels_xml = re.sub(
                r'<Relationship\b[^/>]*\bTarget="fonts/[^"]+\.odttf"[^/>]*/>',
                "",
                font_table_rels_xml,
            )
            after = len(font_table_rels_xml)
            if before != after:
                summary["relationships_removed"] = before - after

        # 5. Clean [Content_Types].xml — remove the obfuscatedFont
        #    Default declaration so Office doesn't expect that type.
        if content_types_xml:
            new_ct = re.sub(
                r'<Default\b[^/>]*\bExtension="odttf"[^/>]*/>',
                "",
                content_types_xml,
            )
            if new_ct != content_types_xml:
                content_types_xml = new_ct
                summary["content_types_modified"] = True

        # Write the stripped DOCX.
        output_path.parent.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(output_path, "w", zipfile.ZIP_DEFLATED) as dst:
            for name in names:
                # Skip the obfuscated font files.
                if name in odttf_paths:
                    continue
                # Substitute modified XML where applicable.
                if name == "word/fontTable.xml" and font_table_xml is not None:
                    dst.writestr(name, font_table_xml)
                elif (
                    name == "word/_rels/fontTable.xml.rels"
                    and font_table_rels_xml is not None
                ):
                    dst.writestr(name, font_table_rels_xml)
                elif (
                    name == "[Content_Types].xml" and content_types_xml is not None
                ):
                    dst.writestr(name, content_types_xml)
                else:
                    dst.writestr(name, src.read(name))

    summary["output_size"] = output_path.stat().st_size
    return summary


def main(argv=None):
    argv = argv or sys.argv[1:]
    if len(argv) != 2:
        sys.stderr.write("usage: strip_obfuscated_fonts.py <input.docx> <output.docx>\n")
        return 1
    inp = Path(argv[0])
    out = Path(argv[1])
    s = strip(inp, out)
    print(f"input:           {s['input']} ({s['input_size']:,} bytes)")
    print(f"output:          {s['output']} ({s['output_size']:,} bytes)")
    print(f"fonts removed:   {len(s['fonts_removed'])}")
    for f in s["fonts_removed"]:
        print(f"  - {f}")
    print(f"embed refs removed:    {s['embed_refs_removed']}")
    print(f"relationships removed: {s['relationships_removed']} chars")
    print(f"content-types modified: {s['content_types_modified']}")
    print(f"size reduction:  {s['input_size'] - s['output_size']:,} bytes "
          f"({100 * (1 - s['output_size'] / s['input_size']):.1f}%)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
