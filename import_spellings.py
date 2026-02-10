import csv
import psycopg2

conn = psycopg2.connect(host='localhost', port=5433, dbname='yada', user='postgres', password='yada_password')
cur = conn.cursor()

csv_path = r'C:\Users\Joe\Downloads\Yada Yahowah-Hebrew Glossary Nouns and Verbs-Spellings.csv'

with open(csv_path, 'r', encoding='utf-8-sig') as f:
    reader = csv.reader(f)
    header = next(reader)  # skip header row
    count = 0
    for row in reader:
        if not row or not row[0].strip():
            continue
        word_id = int(row[0].strip())
        spellings_raw = row[1] if len(row) > 1 else ''
        # Split on newlines to get individual spellings
        spellings = [s.strip() for s in spellings_raw.split('\n') if s.strip()]
        for sort_idx, spelling in enumerate(spellings):
            cur.execute("""
                INSERT INTO yy_word_spelling (word_id, word_spelling_text, word_spelling_sort)
                VALUES (%s, %s, %s)
            """, (word_id, spelling, sort_idx))
            count += 1

conn.commit()
cur.close()
conn.close()
print(f'Imported {count} spelling rows.')
