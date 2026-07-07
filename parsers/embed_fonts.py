#!/usr/bin/env python3
"""
embed_fonts.py — Embed Yada custom TrueType fonts into a DOCX so that
Microsoft's DOCX->PDF renderers (Graph media-transform + Word for the
Web) reproduce the correct glyphs instead of substituting a system font.

WHY THIS EXISTS
---------------
Microsoft renders DOCX->PDF on *their* servers, which do NOT have the
Yada custom fonts installed. A glyph only survives the conversion if the
font is physically embedded inside the .docx (the "Embed fonts in the
file" Word option). Most YY source docs reference the fonts but embed
nothing, so the paleo-Hebrew Tetragrammaton (HWHY, set in "Yada Towrah")
and related glyphs render as a fallback face in the PDF/flipbook.

This is the exact inverse of strip_obfuscated_fonts.py. It injects the
ECMA-376 obfuscated font streams (word/fonts/fontN.odttf), the
fontTable.xml <w:embed*> references, the fontTable.xml.rels
relationships, and the [Content_Types].xml odttf Default — i.e. it
reproduces what Word writes when "Embed fonts in the file" is checked.

Idempotent: a font that already carries <w:embedRegular> is left
untouched, so re-running (or running on an already-embedded doc) is a
safe no-op for that font.

Obfuscation algorithm (validated against a Word-embedded YY doc):
  key  = bytes.fromhex(fontKeyGuid without dashes)[::-1]
  for i in 0..31: data[i] ^= key[i % 16]
(XOR is symmetric, so obfuscate == deobfuscate.)

Usage:
    embed_fonts.py <input.docx> <output.docx> [--fonts-dir DIR]
"""

import re
import sys
import uuid
import zipfile
from pathlib import Path

# Custom fonts we manage. Microsoft's renderers lack these; everything
# else (Times, Calibri, Aptos, ...) they already have, so we never embed
# those (keeps the docx small and avoids Graph's many-font 500s).
MANAGED_FONTS = {
    "Yada Towrah":  "YadaTowrah-Times.ttf",
    "Jupiter-Yada": "JupiterYada-Regular.ttf",
    "Semitic Early": "SemiticEarly.ttf",
}
DEFAULT_FONTS_DIR = "/usr/local/share/fonts/yada"

REL_NS = "http://schemas.openxmlformats.org/package/2006/relationships"
FONT_REL_TYPE = "http://schemas.openxmlformats.org/officeDocument/2006/relationships/font"
OBFUSCATED_CT = "application/vnd.openxmlformats-officedocument.obfuscatedFont"

EMPTY_RELS = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\r\n'
    f'<Relationships xmlns="{REL_NS}"></Relationships>'
)


def obfuscate(ttf: bytes, guid: str) -> bytes:
    """Obfuscate a raw TTF into an .odttf stream using the fontKey GUID."""
    clean = guid.replace("{", "").replace("}", "").replace("-", "")
    key = bytes.fromhex(clean)[::-1]
    data = bytearray(ttf)
    for i in range(32):
        data[i] ^= key[i % 16]
    return bytes(data)


def new_guid() -> str:
    return "{" + str(uuid.uuid4()).upper() + "}"


def max_rid(rels_xml: str) -> int:
    """Highest numeric rIdN already present in a .rels document."""
    return max((int(m) for m in re.findall(r'Id="rId(\d+)"', rels_xml)), default=0)


def embed(input_path: Path, output_path: Path, fonts_dir: Path) -> dict:
    summary = {"embedded": [], "skipped_present": [], "skipped_already": [],
               "odttf_added": 0, "input_size": input_path.stat().st_size,
               "output_size": 0}

    if not zipfile.is_zipfile(input_path):
        raise ValueError(f"{input_path} is not a ZIP / DOCX")

    with zipfile.ZipFile(input_path, "r") as src:
        names = src.namelist()
        if "word/fontTable.xml" not in names:
            raise ValueError("no word/fontTable.xml — cannot embed fonts")

        font_table = src.read("word/fontTable.xml").decode("utf-8")
        rels_name = "word/_rels/fontTable.xml.rels"
        rels_xml = (src.read(rels_name).decode("utf-8")
                    if rels_name in names else EMPTY_RELS)
        ct_name = "[Content_Types].xml"
        content_types = src.read(ct_name).decode("utf-8")

        new_odttf = {}          # zip path -> bytes
        rid_counter = max_rid(rels_xml)
        font_counter = max(
            (int(m) for m in re.findall(r'fonts/font(\d+)\.odttf', rels_xml)),
            default=0,
        )
        new_rel_entries = []

        for font_name, ttf_file in MANAGED_FONTS.items():
            # Locate the <w:font w:name="..."> ... </w:font> block.
            block_re = re.compile(
                r'(<w:font\b[^>]*\bw:name="' + re.escape(font_name) + r'"[^>]*>)(.*?)(</w:font>)',
                re.S,
            )
            m = block_re.search(font_table)
            if not m:
                continue  # doc doesn't reference this font
            if "<w:embed" in m.group(2):
                summary["skipped_already"].append(font_name)
                continue  # already embedded — idempotent no-op

            ttf_path = fonts_dir / ttf_file
            if not ttf_path.is_file():
                raise FileNotFoundError(f"managed font TTF missing: {ttf_path}")
            ttf_bytes = ttf_path.read_bytes()

            # Embed the same face for all four style slots (matches what
            # Word does for symbol/script fonts), each with its own GUID.
            embeds = []
            for tag in ("embedRegular", "embedBold", "embedItalic", "embedBoldItalic"):
                rid_counter += 1
                font_counter += 1
                rid = f"rId{rid_counter}"
                guid = new_guid()
                target = f"fonts/font{font_counter}.odttf"
                new_odttf[f"word/{target}"] = obfuscate(ttf_bytes, guid)
                new_rel_entries.append(
                    f'<Relationship Id="{rid}" Type="{FONT_REL_TYPE}" Target="{target}"/>'
                )
                embeds.append(f'<w:{tag} r:id="{rid}" w:fontKey="{guid}"/>')

            # Inject embed refs just before </w:font> (correct CT_Font order:
            # sig is the last existing child, embed* follow it).
            font_table = (
                font_table[:m.start()]
                + m.group(1) + m.group(2) + "".join(embeds) + m.group(3)
                + font_table[m.end():]
            )
            summary["embedded"].append(font_name)

        if not summary["embedded"]:
            # Nothing to do — still produce output (a faithful copy) so the
            # caller can use a single path unconditionally.
            with zipfile.ZipFile(output_path, "w", zipfile.ZIP_DEFLATED) as dst:
                for n in names:
                    dst.writestr(n, src.read(n))
            summary["output_size"] = output_path.stat().st_size
            return summary

        # Splice new relationships into the .rels document.
        rels_xml = rels_xml.replace(
            "</Relationships>", "".join(new_rel_entries) + "</Relationships>"
        )

        # Ensure the odttf Default content-type is declared exactly once.
        if 'Extension="odttf"' not in content_types:
            content_types = content_types.replace(
                "</Types>",
                f'<Default Extension="odttf" ContentType="{OBFUSCATED_CT}"/></Types>',
            )

        # Write the embedded DOCX.
        output_path.parent.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(output_path, "w", zipfile.ZIP_DEFLATED) as dst:
            handled = {"word/fontTable.xml", ct_name, rels_name}
            for n in names:
                if n == "word/fontTable.xml":
                    dst.writestr(n, font_table)
                elif n == ct_name:
                    dst.writestr(n, content_types)
                elif n == rels_name:
                    dst.writestr(n, rels_xml)
                else:
                    dst.writestr(n, src.read(n))
            if rels_name not in names:           # create rels if absent
                dst.writestr(rels_name, rels_xml)
            for path, data in new_odttf.items():  # add obfuscated streams
                dst.writestr(path, data)
                summary["odttf_added"] += 1

    summary["output_size"] = output_path.stat().st_size
    return summary


def main(argv=None):
    argv = argv or sys.argv[1:]
    fonts_dir = Path(DEFAULT_FONTS_DIR)
    args = []
    i = 0
    while i < len(argv):
        if argv[i] == "--fonts-dir":
            fonts_dir = Path(argv[i + 1]); i += 2
        else:
            args.append(argv[i]); i += 1
    if len(args) != 2:
        sys.stderr.write("usage: embed_fonts.py <input.docx> <output.docx> [--fonts-dir DIR]\n")
        return 1
    s = embed(Path(args[0]), Path(args[1]), fonts_dir)
    print(f"input:            {args[0]} ({s['input_size']:,} bytes)")
    print(f"output:           {args[1]} ({s['output_size']:,} bytes)")
    print(f"fonts embedded:   {s['embedded']}")
    print(f"already embedded: {s['skipped_already']}")
    print(f"odttf streams added: {s['odttf_added']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
