"""
Import scraped Strong's Hebrew data into yy_word and yy_word_spelling tables.
- Updates existing yy_word entries with new data (hebrew, definition, flags)
- Creates new yy_word entries for Strong's numbers not yet in the table
- Adds new yy_word_spelling entries for spellings not yet in the table
- Links existing unlinked yy_word_spelling entries to the correct yy_word
"""
import json
import psycopg2
import unicodedata

SCRAPED_FILE = r"C:\Users\Joe\Work\dev\yada\translations\strongs_scraped.json"

DB_CONFIG = {
    'host': 'localhost',
    'port': 5433,
    'dbname': 'yada',
    'user': 'postgres',
    'password': 'yada_password',
}

def normalize_for_match(text):
    """Normalize spelling for matching against yy_word_spelling."""
    text = unicodedata.normalize('NFD', text)
    text = ''.join(c for c in text if unicodedata.category(c) != 'Mn')
    text = text.lower()
    text = text.replace('\u2019', "'").replace('\u2018', "'").replace('\u02bc', "'")
    text = text.replace('\u02be', "'").replace('\u02bf', "'")
    text = text.replace('@', '')  # lexiconcordance uses @ for sheva
    return text

def main():
    with open(SCRAPED_FILE, 'r', encoding='utf-8') as f:
        entries = json.load(f)

    conn = psycopg2.connect(**DB_CONFIG)
    cur = conn.cursor()

    # Get existing yy_word entries by strongs number
    cur.execute("SELECT word_id, word_strongs FROM yy_word")
    existing_words = {}
    for row in cur.fetchall():
        existing_words[row[1].strip()] = row[0]

    # Get existing yy_word_spelling entries
    cur.execute("SELECT word_spelling_id, word_id, word_spelling_text FROM yy_word_spelling")
    existing_spellings = {}
    existing_spellings_lower = {}  # for matching
    for row in cur.fetchall():
        existing_spellings[row[2]] = {'id': row[0], 'word_id': row[1]}
        existing_spellings_lower[row[2].lower()] = {'id': row[0], 'word_id': row[1], 'text': row[2]}

    words_created = 0
    words_updated = 0
    spellings_added = 0
    spellings_linked = 0

    for entry in entries:
        strongs = entry['strongs']
        hebrew_list = entry['hebrew']
        spellings = entry['spellings']
        derivation = entry['derivation']
        flags = entry['flags']
        pos = entry['pos']

        hebrew = hebrew_list[0] if hebrew_list else ''
        definition = derivation

        word_id = existing_words.get(strongs)

        if word_id:
            # Update existing entry with any missing data
            cur.execute("""
                UPDATE yy_word SET
                    word_hebrew = CASE WHEN word_hebrew = '' OR word_hebrew IS NULL THEN %s ELSE word_hebrew END,
                    word_definition = CASE WHEN word_definition = '' OR word_definition IS NULL THEN %s ELSE word_definition END,
                    word_flag_noun = COALESCE(word_flag_noun, %s),
                    word_flag_verb = COALESCE(word_flag_verb, %s),
                    word_flag_adjective = COALESCE(word_flag_adjective, %s),
                    word_flag_adverb = COALESCE(word_flag_adverb, %s),
                    word_flag_preposition = COALESCE(word_flag_preposition, %s),
                    word_flag_conjunction = COALESCE(word_flag_conjunction, %s),
                    word_flag_subst = COALESCE(word_flag_subst, %s),
                    word_flag_gender_m = COALESCE(word_flag_gender_m, %s),
                    word_flag_gender_f = COALESCE(word_flag_gender_f, %s),
                    word_flag_plural = COALESCE(word_flag_plural, %s)
                WHERE word_id = %s
            """, (
                hebrew, definition,
                flags.get('noun'), flags.get('verb'), flags.get('adjective'),
                flags.get('adverb'), flags.get('preposition'), flags.get('conjunction'),
                flags.get('subst'), flags.get('gender_m'), flags.get('gender_f'),
                flags.get('plural'), word_id
            ))
            words_updated += 1
        else:
            # Create new yy_word entry
            cur.execute("""
                INSERT INTO yy_word (word_strongs, word_hebrew, word_definition,
                    word_flag_noun, word_flag_verb, word_flag_adjective,
                    word_flag_adverb, word_flag_preposition, word_flag_conjunction,
                    word_flag_subst, word_flag_gender_m, word_flag_gender_f, word_flag_plural)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                RETURNING word_id
            """, (
                strongs, hebrew, definition,
                flags.get('noun'), flags.get('verb'), flags.get('adjective'),
                flags.get('adverb'), flags.get('preposition'), flags.get('conjunction'),
                flags.get('subst'), flags.get('gender_m'), flags.get('gender_f'),
                flags.get('plural')
            ))
            word_id = cur.fetchone()[0]
            existing_words[strongs] = word_id
            words_created += 1

        # Process spellings from scraped data
        for spelling in spellings:
            # Clean up the spelling (remove @ used for sheva)
            clean_spelling = spelling.replace('@', "'")

            # Check if this spelling already exists
            if clean_spelling in existing_spellings:
                # Link if unlinked
                sp = existing_spellings[clean_spelling]
                if sp['word_id'] is None:
                    cur.execute("UPDATE yy_word_spelling SET word_id = %s WHERE word_spelling_id = %s",
                                (word_id, sp['id']))
                    spellings_linked += 1
            elif clean_spelling.lower() in existing_spellings_lower:
                # Case-insensitive match exists
                sp = existing_spellings_lower[clean_spelling.lower()]
                if sp['word_id'] is None:
                    cur.execute("UPDATE yy_word_spelling SET word_id = %s WHERE word_spelling_id = %s",
                                (word_id, sp['id']))
                    spellings_linked += 1
            else:
                # Add new spelling
                cur.execute("""
                    INSERT INTO yy_word_spelling (word_id, word_spelling_text)
                    VALUES (%s, %s)
                """, (word_id, clean_spelling))
                spellings_added += 1
                existing_spellings[clean_spelling] = {'id': None, 'word_id': word_id}
                existing_spellings_lower[clean_spelling.lower()] = {'id': None, 'word_id': word_id, 'text': clean_spelling}

        # Also try to link existing unlinked spellings by matching against scraped spellings
        # Use normalized comparison
        norm_spellings = set()
        for sp in spellings:
            norm_spellings.add(normalize_for_match(sp))
            norm_spellings.add(normalize_for_match(sp.replace('@', "'")))

    # Second pass: try to link remaining unlinked spellings
    cur.execute("SELECT word_spelling_id, word_spelling_text FROM yy_word_spelling WHERE word_id IS NULL")
    unlinked = cur.fetchall()

    # Build normalized lookup from all scraped data
    norm_to_strongs = {}
    for entry in entries:
        strongs = entry['strongs']
        for sp in entry['spellings']:
            norm = normalize_for_match(sp)
            if norm not in norm_to_strongs:
                norm_to_strongs[norm] = strongs
            # Also without @
            norm2 = normalize_for_match(sp.replace('@', "'"))
            if norm2 not in norm_to_strongs:
                norm_to_strongs[norm2] = strongs

    for sp_id, sp_text in unlinked:
        norm_text = normalize_for_match(sp_text)

        matched_strongs = norm_to_strongs.get(norm_text)
        if not matched_strongs:
            # Try without leading apostrophe
            if norm_text.startswith("'"):
                matched_strongs = norm_to_strongs.get(norm_text[1:])
            if not matched_strongs:
                matched_strongs = norm_to_strongs.get("'" + norm_text)

        if matched_strongs:
            wid = existing_words.get(matched_strongs)
            if wid:
                cur.execute("UPDATE yy_word_spelling SET word_id = %s WHERE word_spelling_id = %s AND word_id IS NULL",
                            (wid, sp_id))
                if cur.rowcount > 0:
                    spellings_linked += 1

    conn.commit()

    # Final stats
    cur.execute("SELECT COUNT(*) FROM yy_word")
    total_words = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM yy_word_spelling")
    total_spellings = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM yy_word_spelling WHERE word_id IS NOT NULL")
    linked = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM yy_word_spelling WHERE word_id IS NULL")
    still_null = cur.fetchone()[0]

    cur.close()
    conn.close()

    print(f"Words created:    {words_created}")
    print(f"Words updated:    {words_updated}")
    print(f"Spellings added:  {spellings_added}")
    print(f"Spellings linked: {spellings_linked}")
    print(f"")
    print(f"Total yy_word:         {total_words}")
    print(f"Total yy_word_spelling: {total_spellings}")
    print(f"  Linked:              {linked}")
    print(f"  Still unlinked:      {still_null}")

if __name__ == '__main__':
    main()
