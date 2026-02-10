"""
Match unlinked yy_word_spelling entries to Strong's Hebrew numbers.
Reads the OpenScriptures Strong's Hebrew dictionary JSON and attempts to match
transliterated Hebrew words from the yy_word_spelling table.
"""
import json
import re
import unicodedata

# Load Strong's dictionary
with open(r"C:\Users\Joe\Work\dev\yada\translations\strongs-hebrew.json", "r", encoding="utf-8") as f:
    content = f.read()
    # Strip the JS variable assignment prefix
    json_start = content.index("{", content.index("="))
    json_end = content.rindex("}") + 1
    data = json.loads(content[json_start:json_end])

# Load unlinked words
with open(r"C:\Users\Joe\Work\dev\yada\translations\unlinked_words.txt", "r", encoding="utf-8") as f:
    unlinked = [line.strip() for line in f if line.strip()]

def normalize(text):
    """Normalize transliteration for matching."""
    # Remove diacritics
    text = unicodedata.normalize('NFD', text)
    text = ''.join(c for c in text if unicodedata.category(c) != 'Mn')
    # Lowercase
    text = text.lower()
    # Normalize apostrophes and special chars
    text = text.replace('\u2019', "'").replace('\u2018', "'").replace('\u02bc', "'")
    text = text.replace('\u02be', "'").replace('\u02bf', "'")
    # Remove hyphens
    text = text.replace('-', '')
    return text

# Build lookup from Strong's: normalized transliteration -> list of (strongs_num, entry)
strongs_lookup = {}
strongs_by_lemma = {}
for key, entry in data.items():
    num = key  # e.g. "H1"
    xlit = entry.get("xlit", "")
    lemma = entry.get("lemma", "")
    pron = entry.get("pron", "")

    for variant in [xlit, pron]:
        if variant:
            norm = normalize(variant)
            if norm not in strongs_lookup:
                strongs_lookup[norm] = []
            strongs_lookup[norm].append((key, entry))

    if lemma:
        if lemma not in strongs_by_lemma:
            strongs_by_lemma[lemma] = []
        strongs_by_lemma[lemma].append((key, entry))

# YY-specific transliteration mappings
# The YY books use a specific transliteration scheme that differs from Strong's xlit
# Common patterns: 'ow' for long-o, 'uw' for long-u, 'yah' suffix, etc.
yy_to_strongs_map = {
    'ow': 'o',
    'uw': 'u',
    'sh': 'sh',
    'ts': 'ts',
    'th': 'th',
    'ph': 'ph',
    'ch': 'ch',
}

def yy_normalize(text):
    """Additional normalization for YY transliteration style."""
    norm = normalize(text)
    # Remove trailing 'ah' that YY adds for feminine endings
    # Don't do this - too aggressive
    return norm

# Try matching each unlinked word
matches = []
unmatched = []

for word in unlinked:
    norm_word = normalize(word)

    # Direct match on normalized transliteration
    if norm_word in strongs_lookup:
        entries = strongs_lookup[norm_word]
        # Take the first match (prefer lower Strong's number = more common)
        best = min(entries, key=lambda x: int(x[0][1:]))
        matches.append((word, best[0], best[1]))
        continue

    # Try without leading apostrophe
    if norm_word.startswith("'"):
        test = norm_word[1:]
        if test in strongs_lookup:
            entries = strongs_lookup[test]
            best = min(entries, key=lambda x: int(x[0][1:]))
            matches.append((word, best[0], best[1]))
            continue

    # Try with leading apostrophe added
    test = "'" + norm_word
    if test in strongs_lookup:
        entries = strongs_lookup[test]
        best = min(entries, key=lambda x: int(x[0][1:]))
        matches.append((word, best[0], best[1]))
        continue

    # Try common YY transliteration variants
    found = False
    variants = [
        norm_word.replace('ow', 'o'),
        norm_word.replace('uw', 'u'),
        norm_word.replace('ow', 'o').replace('uw', 'u'),
        norm_word.replace("'", ''),
        norm_word.replace('y', 'i'),
        norm_word.replace('y', 'i').replace('ow', 'o'),
        norm_word.replace('y', 'i').replace('uw', 'u'),
        norm_word.replace('ow', 'o').replace("'", ''),
        norm_word.replace('uw', 'u').replace("'", ''),
        norm_word.replace('ow', 'o').replace('uw', 'u').replace("'", ''),
        norm_word.replace('ow', 'o').replace('y', 'i'),
        norm_word.replace('uw', 'oo'),
        norm_word.replace('ow', 'aw'),
    ]

    for v in variants:
        if v in strongs_lookup:
            entries = strongs_lookup[v]
            best = min(entries, key=lambda x: int(x[0][1:]))
            matches.append((word, best[0], best[1]))
            found = True
            break
        # Also try with/without apostrophe
        if ("'" + v) in strongs_lookup:
            entries = strongs_lookup["'" + v]
            best = min(entries, key=lambda x: int(x[0][1:]))
            matches.append((word, best[0], best[1]))
            found = True
            break
        if v.startswith("'") and v[1:] in strongs_lookup:
            entries = strongs_lookup[v[1:]]
            best = min(entries, key=lambda x: int(x[0][1:]))
            matches.append((word, best[0], best[1]))
            found = True
            break

    if not found:
        unmatched.append(word)

print(f"Total unlinked: {len(unlinked)}")
print(f"Matched: {len(matches)}")
print(f"Unmatched: {len(unmatched)}")
print()

# Generate SQL
# First, check which Strong's numbers already exist in yy_word
# We'll output SQL that:
# 1. Creates new yy_word entries for new Strong's numbers
# 2. Links yy_word_spelling entries to the appropriate yy_word

# Group matches by Strong's number
from collections import defaultdict
by_strongs = defaultdict(list)
for word, strongs_key, entry in matches:
    num = strongs_key[1:]  # Remove 'H' prefix
    by_strongs[num].append((word, entry))

print(f"Distinct Strong's numbers matched: {len(by_strongs)}")
print()
print("Sample matches:")
for word, strongs_key, entry in matches[:20]:
    try:
        print(f"  {word:30s} -> {strongs_key:6s} xlit={entry.get('xlit',''):20s}")
    except UnicodeEncodeError:
        print(f"  {word:30s} -> {strongs_key:6s}")

# Write SQL output
with open(r"C:\Users\Joe\Work\dev\yada\translations\strongs_matches.sql", "w", encoding="utf-8") as f:
    f.write("-- Auto-generated: Link yy_word_spelling to yy_word via Strong's numbers\n")
    f.write("BEGIN;\n\n")

    for strongs_num, word_entries in sorted(by_strongs.items(), key=lambda x: int(x[0])):
        entry = word_entries[0][1]  # Use first entry for metadata
        strongs_4digit = strongs_num.zfill(4)
        lemma = entry.get('lemma', '').replace("'", "''")
        xlit = entry.get('xlit', '').replace("'", "''")
        definition = entry.get('strongs_def', '').replace("'", "''")

        spellings = [w for w, _ in word_entries]
        spellings_sql = ", ".join(f"'{s.replace(chr(39), chr(39)+chr(39))}'" for s in spellings)

        f.write(f"-- Strong's H{strongs_num}: {xlit} = {lemma}\n")
        f.write(f"DO $$ DECLARE wid INTEGER; BEGIN\n")
        f.write(f"  SELECT word_id INTO wid FROM yy_word WHERE word_strongs = '{strongs_4digit}' LIMIT 1;\n")
        f.write(f"  IF wid IS NULL THEN\n")
        f.write(f"    INSERT INTO yy_word (word_strongs, word_hebrew, word_definition)\n")
        f.write(f"    VALUES ('{strongs_4digit}', '{lemma}', '{definition}')\n")
        f.write(f"    RETURNING word_id INTO wid;\n")
        f.write(f"  END IF;\n")
        f.write(f"  UPDATE yy_word_spelling SET word_id = wid\n")
        f.write(f"  WHERE word_spelling_text IN ({spellings_sql}) AND word_id IS NULL;\n")
        f.write(f"END $$;\n\n")

    f.write("COMMIT;\n")

print(f"\nSQL written to strongs_matches.sql")
print(f"\nSample unmatched words:")
for w in unmatched[:30]:
    try:
        print(f"  {w}")
    except UnicodeEncodeError:
        print(f"  (encoding error)")
