"""
Scrape lexiconcordance.com for Strong's Hebrew numbers 0001-8674.
Extract: Strong's number, Hebrew text, spelling(s), pronunciation(s),
         derivation/definition, TWOT number, part of speech flags.
Output JSON file with all entries for database import.
"""
import re
import json
import time
import html
import urllib.request
import urllib.error
import sys

OUTPUT_FILE = r"C:\Users\Joe\Work\dev\yada\translations\strongs_scraped.json"
START = 1
END = 8674
BATCH_SIZE = 100  # Save progress every N entries
DELAY = 0.1  # Seconds between requests (be polite)

def decode_html_entities(text):
    """Decode HTML entities including hex Unicode."""
    text = html.unescape(text)
    return text

def parse_part_of_speech(pos_str):
    """Parse part of speech flags from the TWOT line."""
    flags = {
        'noun': False, 'verb': False, 'adjective': False, 'adverb': False,
        'preposition': False, 'conjunction': False, 'subst': False,
        'gender_m': False, 'gender_f': False, 'plural': False,
    }
    pos = pos_str.lower().strip()
    if 'n m' in pos or pos.endswith(' m') or 'n com' in pos:
        flags['noun'] = True
        flags['gender_m'] = True
    if 'n f' in pos or pos.endswith(' f'):
        flags['noun'] = True
        flags['gender_f'] = True
    if 'n pr' in pos:
        flags['noun'] = True  # proper noun
    if pos.startswith('n ') or pos == 'n':
        flags['noun'] = True
    if pos.startswith('v') or pos == 'v':
        flags['verb'] = True
    if 'adj' in pos:
        flags['adjective'] = True
    if 'adv' in pos:
        flags['adverb'] = True
    if 'prep' in pos:
        flags['preposition'] = True
    if 'conj' in pos:
        flags['conjunction'] = True
    if 'subst' in pos:
        flags['subst'] = True
    if 'pl' in pos:
        flags['plural'] = True
    return flags

def scrape_entry(num):
    """Scrape a single Strong's number page."""
    padded = str(num).zfill(4)
    url = f"http://lexiconcordance.com/hebrew/{padded}.html"

    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=15) as resp:
            raw = resp.read().decode('utf-8', errors='replace')
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as e:
        return None

    # Extract div.x content
    match = re.search(r'<DIV CLASS="x"><PRE>(.*?)</PRE></DIV>', raw, re.DOTALL)
    if not match:
        return None

    block = match.group(1)
    block = decode_html_entities(block)

    # Check if this is a grammar entry (no Hebrew/spelling)
    if 'Stem' in block and 'Mood' in block:
        return None

    # Extract Hebrew characters (inside <A CLASS="u">...</A>)
    hebrew_matches = re.findall(r'<A CLASS="u">([^<]+)</A>', block)
    hebrew_chars = [decode_html_entities(h).strip() for h in hebrew_matches]

    # Remove HTML tags for text parsing
    clean = re.sub(r'<[^>]+>', '', block).strip()

    # Parse spellings and pronunciations
    # Pattern: 'spelling {pronunciation}
    # Can have multiple: "or 'spelling2 {pronunciation2}"
    spell_pron = re.findall(r"(['\"]?\w['\w@\-]*)\s*\{([^}]+)\}", clean)

    spellings = []
    pronunciations = []
    for sp, pr in spell_pron:
        spellings.append(sp.strip())
        pronunciations.append(pr.strip())

    # Extract TWOT number
    twot_match = re.search(r'TWOT\s*-\s*([\w/,\s]+?)\s*;', clean)
    twot = twot_match.group(1).strip() if twot_match else ''

    # Extract part of speech (after last semicolon on TWOT line)
    pos_match = re.search(r'TWOT\s*-\s*[\w/,\s]+;\s*(.+?)$', clean, re.MULTILINE)
    pos_str = pos_match.group(1).strip() if pos_match else ''

    # Extract derivation/definition (text before TWOT line)
    deriv_match = re.search(r'\}\s*\n\s*(.*?);\s*TWOT', clean, re.DOTALL)
    if not deriv_match:
        deriv_match = re.search(r'\}\s*(.*?);\s*TWOT', clean, re.DOTALL)
    derivation = ''
    if deriv_match:
        derivation = ' '.join(deriv_match.group(1).split()).strip()
        # Clean up "or ... {pron}" artifacts
        derivation = re.sub(r"or\s+['\"]?\w['\w@\-]*\s*\{[^}]+\}", '', derivation).strip()

    if not spellings and not hebrew_chars:
        return None

    flags = parse_part_of_speech(pos_str)

    return {
        'strongs': padded,
        'hebrew': hebrew_chars,
        'spellings': spellings,
        'pronunciations': pronunciations,
        'derivation': derivation,
        'twot': twot,
        'pos': pos_str,
        'flags': flags,
    }

def main():
    results = []
    errors = 0
    skipped = 0

    # Try to resume from existing file
    start_from = START
    try:
        with open(OUTPUT_FILE, 'r', encoding='utf-8') as f:
            results = json.load(f)
        if results:
            last_num = max(int(r['strongs']) for r in results)
            start_from = last_num + 1
            print(f"Resuming from {start_from} ({len(results)} entries loaded)")
    except (FileNotFoundError, json.JSONDecodeError):
        pass

    for num in range(start_from, END + 1):
        padded = str(num).zfill(4)

        if num % 100 == 0:
            print(f"Processing {padded}... ({len(results)} entries so far)", flush=True)

        entry = None
        for attempt in range(3):
            try:
                entry = scrape_entry(num)
                break
            except Exception as e:
                if attempt < 2:
                    time.sleep(1)
                else:
                    print(f"  ERROR on {padded}: {e}", flush=True)
                    errors += 1

        if entry:
            results.append(entry)
        else:
            skipped += 1

        # Save progress periodically
        if num % BATCH_SIZE == 0:
            with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
                json.dump(results, f, ensure_ascii=False, indent=1)

        time.sleep(DELAY)

    # Final save
    with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
        json.dump(results, f, ensure_ascii=False, indent=1)

    print(f"\nDone! {len(results)} entries scraped, {skipped} skipped, {errors} errors")
    print(f"Saved to {OUTPUT_FILE}")

if __name__ == '__main__':
    main()
