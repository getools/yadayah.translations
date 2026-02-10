import csv
import psycopg2

conn = psycopg2.connect(host='localhost', port=5433, dbname='yada', user='postgres', password='yada_password')
cur = conn.cursor()

csv_path = r'C:\Users\Joe\Downloads\Yada Yahowah-Hebrew Glossary Nouns and Verbs.csv'

with open(csv_path, 'r', encoding='utf-8-sig') as f:
    reader = csv.DictReader(f)
    count = 0
    for row in reader:
        # Strip trailing empty key from trailing comma
        row = {k.strip(): v.strip() if v else None for k, v in row.items() if k and k.strip()}

        if not row.get('word_id'):
            continue
        word_id = int(row['word_id'])
        word_strongs = row['word_strongs']
        word_hebrew = row['word_hebrew']
        word_yt = row['word_yt'] or None
        word_definition = row['word_definition'] or None

        def to_bool(val):
            if val and val.strip() == '1':
                return True
            return None

        cur.execute("""
            INSERT INTO yy_word (
                word_id, word_strongs, word_hebrew, word_yt,
                word_flag_gender_m, word_flag_gender_f, word_flag_plural,
                word_flag_noun, word_flag_verb, word_flag_adjective,
                word_flag_adverb, word_flag_preposition, word_flag_conjunction,
                word_flag_subst, word_definition
            ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
        """, (
            word_id, word_strongs, word_hebrew, word_yt,
            to_bool(row['word_flag_gender_m']),
            to_bool(row['word_flag_gender_f']),
            to_bool(row['word_flag_plural']),
            to_bool(row['word_flag_noun']),
            to_bool(row['word_flag_verb']),
            to_bool(row['word_flag_adjective']),
            to_bool(row['word_flag_adverb']),
            to_bool(row['word_flag_preposition']),
            to_bool(row['word_flag_conjunction']),
            to_bool(row['word_flag_subst']),
            word_definition,
        ))
        count += 1

conn.commit()

# Reset sequence to max word_id
cur.execute("SELECT setval('yy_word_word_id_seq', (SELECT MAX(word_id) FROM yy_word))")
conn.commit()

cur.close()
conn.close()
print(f'Imported {count} rows.')
