"""
Migrate Yada Translations app from MySQL to PostgreSQL.
- Alters existing PG tables (yy_series, yy_volume, translation) to use _key naming
- Creates new PG tables (yah_scroll, yah_chapter, yah_verse, yy_chapter, yy_user, yy_user_preference, yy_translation)
- Creates revision tables and trigger functions
- Exports MySQL data and imports into PostgreSQL
"""

import pymysql
import psycopg2

MYSQL_CONFIG = dict(host='localhost', port=3307, user='yada', password='yada_pass', database='yada_translations')
PG_CONFIG = dict(host='localhost', port=5433, user='postgres', password='yada_password', database='yada')


def get_mysql():
    return pymysql.connect(**MYSQL_CONFIG, cursorclass=pymysql.cursors.DictCursor)


def get_pg():
    conn = psycopg2.connect(**PG_CONFIG)
    conn.autocommit = False
    return conn


def step_alter_existing_pg_tables(pg):
    """Rename _id columns to _key and add missing columns on existing PG tables."""
    cur = pg.cursor()

    print("Altering yy_series...")
    # Check if already renamed
    cur.execute("SELECT column_name FROM information_schema.columns WHERE table_name='yy_series' AND column_name='yy_series_id'")
    if cur.fetchone():
        cur.execute("ALTER TABLE yy_series RENAME COLUMN yy_series_id TO yy_series_key")
    cur.execute("SELECT column_name FROM information_schema.columns WHERE table_name='yy_series' AND column_name='yy_series_name'")
    if not cur.fetchone():
        cur.execute("ALTER TABLE yy_series ADD COLUMN yy_series_name VARCHAR(250)")

    print("Altering yy_volume...")
    # Drop FK from yy_volume -> yy_series first (references old column name)
    cur.execute("""
        SELECT constraint_name FROM information_schema.table_constraints
        WHERE table_name='yy_volume' AND constraint_type='FOREIGN KEY'
    """)
    for row in cur.fetchall():
        cur.execute(f"ALTER TABLE yy_volume DROP CONSTRAINT IF EXISTS {row[0]}")

    cur.execute("SELECT column_name FROM information_schema.columns WHERE table_name='yy_volume' AND column_name='yy_volume_id'")
    if cur.fetchone():
        cur.execute("ALTER TABLE yy_volume RENAME COLUMN yy_volume_id TO yy_volume_key")
    cur.execute("SELECT column_name FROM information_schema.columns WHERE table_name='yy_volume' AND column_name='yy_series_id'")
    if cur.fetchone():
        cur.execute("ALTER TABLE yy_volume RENAME COLUMN yy_series_id TO yy_series_key")

    for col, typ in [('yy_volume_name', 'VARCHAR(250)'), ('yy_volume_page_count', 'SMALLINT'),
                     ('yy_volume_paragraph_count', 'SMALLINT'), ('yy_volume_sort', 'SMALLINT DEFAULT 0')]:
        cur.execute(f"SELECT column_name FROM information_schema.columns WHERE table_name='yy_volume' AND column_name='{col}'")
        if not cur.fetchone():
            cur.execute(f"ALTER TABLE yy_volume ADD COLUMN {col} {typ}")

    # Re-add FK from yy_volume -> yy_series
    cur.execute("""
        ALTER TABLE yy_volume ADD CONSTRAINT yy_volume_yy_series_key_fkey
        FOREIGN KEY (yy_series_key) REFERENCES yy_series(yy_series_key)
    """)

    print("Altering translation...")
    # Drop FK from translation -> yy_volume
    cur.execute("""
        SELECT constraint_name FROM information_schema.table_constraints
        WHERE table_name='translation' AND constraint_type='FOREIGN KEY'
        AND constraint_name LIKE '%volume%'
    """)
    for row in cur.fetchall():
        cur.execute(f"ALTER TABLE translation DROP CONSTRAINT IF EXISTS {row[0]}")

    cur.execute("SELECT column_name FROM information_schema.columns WHERE table_name='translation' AND column_name='yy_volume_id'")
    if cur.fetchone():
        cur.execute("ALTER TABLE translation RENAME COLUMN yy_volume_id TO yy_volume_key")

    # Re-add FK
    cur.execute("""
        ALTER TABLE translation ADD CONSTRAINT translation_yy_volume_key_fkey
        FOREIGN KEY (yy_volume_key) REFERENCES yy_volume(yy_volume_key)
    """)

    pg.commit()
    print("  Existing tables altered successfully.")


def step_create_new_tables(pg):
    """Create tables that only existed in MySQL."""
    cur = pg.cursor()

    print("Creating new tables...")
    cur.execute("""
        CREATE TABLE IF NOT EXISTS yah_scroll (
            yah_scroll_key SERIAL PRIMARY KEY,
            yah_scroll_label_common VARCHAR(250) NOT NULL,
            yah_scroll_label_yy VARCHAR(250) NOT NULL,
            yah_scroll_sort SMALLINT DEFAULT 0
        )
    """)
    cur.execute("""
        CREATE TABLE IF NOT EXISTS yah_chapter (
            yah_chapter_key SERIAL PRIMARY KEY,
            yah_scroll_key INT NOT NULL REFERENCES yah_scroll(yah_scroll_key) ON DELETE RESTRICT ON UPDATE CASCADE,
            yah_chapter_number SMALLINT NOT NULL,
            yah_chapter_sort SMALLINT DEFAULT 0
        )
    """)
    cur.execute("CREATE INDEX IF NOT EXISTS idx_yah_chapter_scroll ON yah_chapter(yah_scroll_key)")

    cur.execute("""
        CREATE TABLE IF NOT EXISTS yah_verse (
            yah_verse_key SERIAL PRIMARY KEY,
            yah_chapter_key INT NOT NULL REFERENCES yah_chapter(yah_chapter_key) ON DELETE RESTRICT ON UPDATE CASCADE,
            yah_verse_number SMALLINT NOT NULL,
            yah_verse_sort SMALLINT DEFAULT 0
        )
    """)
    cur.execute("CREATE INDEX IF NOT EXISTS idx_yah_verse_chapter ON yah_verse(yah_chapter_key)")

    cur.execute("""
        CREATE TABLE IF NOT EXISTS yy_chapter (
            yy_chapter_key SERIAL PRIMARY KEY,
            yy_volume_key INT NOT NULL REFERENCES yy_volume(yy_volume_key) ON DELETE RESTRICT ON UPDATE CASCADE,
            yy_chapter_number SMALLINT NOT NULL,
            yy_chapter_page INT NULL,
            yy_chapter_name VARCHAR(250) NULL,
            yy_chapter_label VARCHAR(500) NULL,
            yy_chapter_sort SMALLINT DEFAULT 0
        )
    """)
    cur.execute("CREATE INDEX IF NOT EXISTS idx_yy_chapter_volume ON yy_chapter(yy_volume_key)")

    cur.execute("""
        CREATE TABLE IF NOT EXISTS yy_user (
            yy_user_key SERIAL PRIMARY KEY,
            yy_user_code VARCHAR(500) NOT NULL UNIQUE,
            yy_user_pass VARCHAR(255) NULL,
            yy_user_name_last VARCHAR(500) NULL,
            yy_user_name_first VARCHAR(500) NULL,
            yy_user_name_middle VARCHAR(500) NULL,
            yy_user_name_prefix VARCHAR(500) NULL,
            yy_user_name_suffix VARCHAR(500) NULL,
            yy_user_name_full VARCHAR(500) NULL,
            yy_user_email VARCHAR(500) NULL,
            yy_user_text VARCHAR(64) NULL
        )
    """)

    cur.execute("""
        CREATE TABLE IF NOT EXISTS yy_user_preference (
            yy_user_preference_key SERIAL PRIMARY KEY,
            yy_user_key INT NOT NULL REFERENCES yy_user(yy_user_key) ON DELETE CASCADE ON UPDATE CASCADE,
            yy_preference_name VARCHAR(100) NOT NULL,
            yy_preference_value TEXT NULL,
            UNIQUE (yy_user_key, yy_preference_name)
        )
    """)

    cur.execute("""
        CREATE TABLE IF NOT EXISTS yy_translation (
            yy_translation_key SERIAL PRIMARY KEY,
            yah_scroll_key INT NOT NULL REFERENCES yah_scroll(yah_scroll_key) ON DELETE RESTRICT ON UPDATE CASCADE,
            yah_chapter_key INT NOT NULL REFERENCES yah_chapter(yah_chapter_key) ON DELETE RESTRICT ON UPDATE CASCADE,
            yah_verse_key INT NOT NULL REFERENCES yah_verse(yah_verse_key) ON DELETE RESTRICT ON UPDATE CASCADE,
            yy_series_key INT NOT NULL REFERENCES yy_series(yy_series_key) ON DELETE RESTRICT ON UPDATE CASCADE,
            yy_volume_key INT NOT NULL REFERENCES yy_volume(yy_volume_key) ON DELETE RESTRICT ON UPDATE CASCADE,
            yy_chapter_key INT NOT NULL REFERENCES yy_chapter(yy_chapter_key) ON DELETE RESTRICT ON UPDATE CASCADE,
            yy_translation_page SMALLINT NULL,
            yy_translation_paragraph SMALLINT NULL,
            yy_translation_copy TEXT NULL,
            yy_translation_date DATE NULL,
            yy_translation_sort SMALLINT NOT NULL DEFAULT 0,
            yy_translation_dtime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    """)
    cur.execute("CREATE INDEX IF NOT EXISTS idx_yy_translation_verse ON yy_translation(yah_scroll_key, yah_chapter_key, yah_verse_key)")
    cur.execute("CREATE INDEX IF NOT EXISTS idx_yy_translation_scroll ON yy_translation(yah_scroll_key)")
    cur.execute("CREATE INDEX IF NOT EXISTS idx_yy_translation_chapter ON yy_translation(yah_chapter_key)")
    cur.execute("CREATE INDEX IF NOT EXISTS idx_yy_translation_yy_verse ON yy_translation(yah_verse_key)")
    cur.execute("CREATE INDEX IF NOT EXISTS idx_yy_translation_series ON yy_translation(yy_series_key)")
    cur.execute("CREATE INDEX IF NOT EXISTS idx_yy_translation_volume ON yy_translation(yy_volume_key)")
    cur.execute("CREATE INDEX IF NOT EXISTS idx_yy_translation_yy_chapter ON yy_translation(yy_chapter_key)")

    pg.commit()
    print("  New tables created.")


def step_create_revision_tables(pg):
    """Create revision tracking tables."""
    cur = pg.cursor()
    print("Creating revision tables...")

    revision_tables = {
        'rev_yah_scroll': [
            'yah_scroll_key INT NULL',
            'yah_scroll_label_common VARCHAR(250) NULL',
            'yah_scroll_label_yy VARCHAR(250) NULL',
            'yah_scroll_sort SMALLINT NULL',
        ],
        'rev_yah_chapter': [
            'yah_chapter_key INT NULL',
            'yah_scroll_key INT NULL',
            'yah_chapter_number SMALLINT NULL',
            'yah_chapter_sort SMALLINT NULL',
        ],
        'rev_yah_verse': [
            'yah_verse_key INT NULL',
            'yah_chapter_key INT NULL',
            'yah_verse_number SMALLINT NULL',
            'yah_verse_sort SMALLINT NULL',
        ],
        'rev_yy_series': [
            'yy_series_key INT NULL',
            'yy_series_name VARCHAR(250) NULL',
            'yy_series_label VARCHAR(250) NULL',
            'yy_series_sort SMALLINT NULL',
        ],
        'rev_yy_volume': [
            'yy_volume_key INT NULL',
            'yy_series_key INT NULL',
            'yy_volume_number SMALLINT NULL',
            'yy_volume_name VARCHAR(250) NULL',
            'yy_volume_label VARCHAR(250) NULL',
            'yy_volume_page_count SMALLINT NULL',
            'yy_volume_paragraph_count SMALLINT NULL',
            'yy_volume_sort SMALLINT NULL',
        ],
        'rev_yy_chapter': [
            'yy_chapter_key INT NULL',
            'yy_volume_key INT NULL',
            'yy_chapter_number SMALLINT NULL',
            'yy_chapter_page INT NULL',
            'yy_chapter_name VARCHAR(250) NULL',
            'yy_chapter_label VARCHAR(500) NULL',
            'yy_chapter_sort SMALLINT NULL',
        ],
        'rev_yy_user': [
            'yy_user_key INT NULL',
            'yy_user_code VARCHAR(500) NULL',
            'yy_user_pass VARCHAR(255) NULL',
            'yy_user_name_last VARCHAR(500) NULL',
            'yy_user_name_first VARCHAR(500) NULL',
            'yy_user_name_middle VARCHAR(500) NULL',
            'yy_user_name_prefix VARCHAR(500) NULL',
            'yy_user_name_suffix VARCHAR(500) NULL',
            'yy_user_name_full VARCHAR(500) NULL',
            'yy_user_email VARCHAR(500) NULL',
            'yy_user_text VARCHAR(64) NULL',
        ],
        'rev_yy_translation': [
            'yy_translation_key INT NULL',
            'yah_scroll_key INT NULL',
            'yah_chapter_key INT NULL',
            'yah_verse_key INT NULL',
            'yy_series_key INT NULL',
            'yy_volume_key INT NULL',
            'yy_chapter_key INT NULL',
            'yy_translation_page SMALLINT NULL',
            'yy_translation_paragraph SMALLINT NULL',
            'yy_translation_copy TEXT NULL',
            'yy_translation_date DATE NULL',
            'yy_translation_sort SMALLINT NULL',
            'yy_translation_dtime TIMESTAMP NULL',
        ],
    }

    for table_name, columns in revision_tables.items():
        cols_sql = ',\n            '.join(columns)
        pk_col = list(revision_tables[table_name])[0].split()[0]  # first column name = entity key
        cur.execute(f"""
            CREATE TABLE IF NOT EXISTS {table_name} (
                _key BIGSERIAL PRIMARY KEY,
                {cols_sql},
                _remove_dtime TIMESTAMP NULL,
                _revision_count INT NOT NULL DEFAULT 0,
                _revision_user_key INT NOT NULL DEFAULT 0,
                _revision_dtime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        """)
        cur.execute(f"CREATE INDEX IF NOT EXISTS idx_{table_name}_{pk_col} ON {table_name}({pk_col})")

    pg.commit()
    print("  Revision tables created.")


def step_migrate_data(my, pg):
    """Export from MySQL and import into PostgreSQL."""
    my_cur = my.cursor()
    pg_cur = pg.cursor()

    # -- yah_scroll --
    print("Migrating yah_scroll...")
    my_cur.execute("SELECT yah_scroll_key, yah_scroll_label_common, yah_scroll_label_yy, yah_scroll_sort FROM yah_scroll ORDER BY yah_scroll_key")
    rows = my_cur.fetchall()
    for r in rows:
        pg_cur.execute(
            "INSERT INTO yah_scroll (yah_scroll_key, yah_scroll_label_common, yah_scroll_label_yy, yah_scroll_sort) VALUES (%s, %s, %s, %s) ON CONFLICT (yah_scroll_key) DO NOTHING",
            (r['yah_scroll_key'], r['yah_scroll_label_common'], r['yah_scroll_label_yy'], r['yah_scroll_sort'])
        )
    print(f"  {len(rows)} scrolls.")

    # -- yah_chapter --
    print("Migrating yah_chapter...")
    my_cur.execute("SELECT yah_chapter_key, yah_scroll_key, yah_chapter_number, yah_chapter_sort FROM yah_chapter ORDER BY yah_chapter_key")
    rows = my_cur.fetchall()
    for r in rows:
        pg_cur.execute(
            "INSERT INTO yah_chapter (yah_chapter_key, yah_scroll_key, yah_chapter_number, yah_chapter_sort) VALUES (%s, %s, %s, %s) ON CONFLICT (yah_chapter_key) DO NOTHING",
            (r['yah_chapter_key'], r['yah_scroll_key'], r['yah_chapter_number'], r['yah_chapter_sort'])
        )
    print(f"  {len(rows)} chapters.")

    # -- yah_verse --
    print("Migrating yah_verse...")
    my_cur.execute("SELECT yah_verse_key, yah_chapter_key, yah_verse_number, yah_verse_sort FROM yah_verse ORDER BY yah_verse_key")
    rows = my_cur.fetchall()
    for r in rows:
        pg_cur.execute(
            "INSERT INTO yah_verse (yah_verse_key, yah_chapter_key, yah_verse_number, yah_verse_sort) VALUES (%s, %s, %s, %s) ON CONFLICT (yah_verse_key) DO NOTHING",
            (r['yah_verse_key'], r['yah_chapter_key'], r['yah_verse_number'], r['yah_verse_sort'])
        )
    print(f"  {len(rows)} verses.")

    # -- UPDATE yy_series with yy_series_name from MySQL --
    print("Updating yy_series with names from MySQL...")
    my_cur.execute("SELECT yy_series_key, yy_series_name FROM yy_series ORDER BY yy_series_key")
    rows = my_cur.fetchall()
    for r in rows:
        pg_cur.execute("UPDATE yy_series SET yy_series_name = %s WHERE yy_series_key = %s", (r['yy_series_name'], r['yy_series_key']))
    # For any PG-only series rows without a name, use label as fallback
    pg_cur.execute("UPDATE yy_series SET yy_series_name = yy_series_label WHERE yy_series_name IS NULL")
    print(f"  {len(rows)} series updated.")

    # -- UPDATE yy_volume with extra columns from MySQL --
    print("Updating yy_volume with data from MySQL...")
    my_cur.execute("SELECT yy_volume_key, yy_volume_name, yy_volume_page_count, yy_volume_paragraph_count, yy_volume_sort FROM yy_volume ORDER BY yy_volume_key")
    rows = my_cur.fetchall()
    for r in rows:
        pg_cur.execute(
            "UPDATE yy_volume SET yy_volume_name = %s, yy_volume_page_count = %s, yy_volume_paragraph_count = %s, yy_volume_sort = %s WHERE yy_volume_key = %s",
            (r['yy_volume_name'], r['yy_volume_page_count'], r['yy_volume_paragraph_count'], r['yy_volume_sort'], r['yy_volume_key'])
        )
    # For any PG-only volume rows without a name, use label as fallback
    pg_cur.execute("UPDATE yy_volume SET yy_volume_name = yy_volume_label WHERE yy_volume_name IS NULL")
    pg_cur.execute("UPDATE yy_volume SET yy_volume_sort = yy_volume_number * 10 WHERE yy_volume_sort IS NULL OR yy_volume_sort = 0")
    print(f"  {len(rows)} volumes updated.")

    # -- yy_chapter --
    print("Migrating yy_chapter...")
    my_cur.execute("SELECT yy_chapter_key, yy_volume_key, yy_chapter_number, yy_chapter_page, yy_chapter_name, yy_chapter_label, yy_chapter_sort FROM yy_chapter ORDER BY yy_chapter_key")
    rows = my_cur.fetchall()
    for r in rows:
        pg_cur.execute(
            "INSERT INTO yy_chapter (yy_chapter_key, yy_volume_key, yy_chapter_number, yy_chapter_page, yy_chapter_name, yy_chapter_label, yy_chapter_sort) VALUES (%s, %s, %s, %s, %s, %s, %s) ON CONFLICT (yy_chapter_key) DO NOTHING",
            (r['yy_chapter_key'], r['yy_volume_key'], r['yy_chapter_number'], r['yy_chapter_page'], r['yy_chapter_name'], r['yy_chapter_label'], r['yy_chapter_sort'])
        )
    print(f"  {len(rows)} yy_chapters.")

    # -- yy_user --
    print("Migrating yy_user...")
    my_cur.execute("SELECT yy_user_key, yy_user_code, yy_user_pass, yy_user_name_last, yy_user_name_first, yy_user_name_middle, yy_user_name_prefix, yy_user_name_suffix, yy_user_name_full, yy_user_email, yy_user_text FROM yy_user ORDER BY yy_user_key")
    rows = my_cur.fetchall()
    for r in rows:
        pg_cur.execute(
            """INSERT INTO yy_user (yy_user_key, yy_user_code, yy_user_pass, yy_user_name_last, yy_user_name_first, yy_user_name_middle, yy_user_name_prefix, yy_user_name_suffix, yy_user_name_full, yy_user_email, yy_user_text)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s) ON CONFLICT (yy_user_key) DO NOTHING""",
            (r['yy_user_key'], r['yy_user_code'], r['yy_user_pass'], r['yy_user_name_last'], r['yy_user_name_first'],
             r['yy_user_name_middle'], r['yy_user_name_prefix'], r['yy_user_name_suffix'], r['yy_user_name_full'],
             r['yy_user_email'], r['yy_user_text'])
        )
    print(f"  {len(rows)} users.")

    # -- yy_user_preference --
    print("Migrating yy_user_preference...")
    my_cur.execute("SELECT yy_user_preference_key, yy_user_key, yy_preference_name, yy_preference_value FROM yy_user_preference ORDER BY yy_user_preference_key")
    rows = my_cur.fetchall()
    for r in rows:
        pg_cur.execute(
            """INSERT INTO yy_user_preference (yy_user_preference_key, yy_user_key, yy_preference_name, yy_preference_value)
            VALUES (%s, %s, %s, %s) ON CONFLICT (yy_user_preference_key) DO NOTHING""",
            (r['yy_user_preference_key'], r['yy_user_key'], r['yy_preference_name'], r['yy_preference_value'])
        )
    print(f"  {len(rows)} preferences.")

    # -- yy_translation --
    print("Migrating yy_translation...")
    my_cur.execute("""SELECT yy_translation_key, yah_scroll_key, yah_chapter_key, yah_verse_key, yy_series_key, yy_volume_key, yy_chapter_key,
        yy_translation_page, yy_translation_paragraph, yy_translation_copy, yy_translation_date, yy_translation_sort, yy_translation_dtime
        FROM yy_translation ORDER BY yy_translation_key""")
    rows = my_cur.fetchall()
    for r in rows:
        pg_cur.execute(
            """INSERT INTO yy_translation (yy_translation_key, yah_scroll_key, yah_chapter_key, yah_verse_key, yy_series_key, yy_volume_key, yy_chapter_key,
                yy_translation_page, yy_translation_paragraph, yy_translation_copy, yy_translation_date, yy_translation_sort, yy_translation_dtime)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s) ON CONFLICT (yy_translation_key) DO NOTHING""",
            (r['yy_translation_key'], r['yah_scroll_key'], r['yah_chapter_key'], r['yah_verse_key'],
             r['yy_series_key'], r['yy_volume_key'], r['yy_chapter_key'],
             r['yy_translation_page'], r['yy_translation_paragraph'], r['yy_translation_copy'],
             r['yy_translation_date'], r['yy_translation_sort'], r['yy_translation_dtime'])
        )
    print(f"  {len(rows)} translations.")

    pg.commit()
    print("  Data migration complete.")


def step_reset_sequences(pg):
    """Reset all SERIAL sequences after inserting data with explicit keys."""
    cur = pg.cursor()
    print("Resetting sequences...")

    sequences = [
        ('yah_scroll', 'yah_scroll_key'),
        ('yah_chapter', 'yah_chapter_key'),
        ('yah_verse', 'yah_verse_key'),
        ('yy_chapter', 'yy_chapter_key'),
        ('yy_user', 'yy_user_key'),
        ('yy_user_preference', 'yy_user_preference_key'),
        ('yy_translation', 'yy_translation_key'),
    ]

    for table, col in sequences:
        cur.execute(f"SELECT COALESCE(MAX({col}), 0) FROM {table}")
        max_val = cur.fetchone()[0]
        seq_name = f"{table}_{col}_seq"
        cur.execute(f"SELECT setval('{seq_name}', %s)", (max(max_val, 1),))
        print(f"  {seq_name} -> {max_val}")

    # Also reset the renamed sequences for yy_series and yy_volume
    cur.execute("SELECT COALESCE(MAX(yy_series_key), 0) FROM yy_series")
    max_val = cur.fetchone()[0]
    # The sequence might still have old name
    cur.execute("SELECT c.relname FROM pg_class c WHERE c.relname LIKE 'yy_series_%_seq'")
    seq_row = cur.fetchone()
    if seq_row:
        cur.execute(f"SELECT setval('{seq_row[0]}', %s)", (max(max_val, 1),))
        print(f"  {seq_row[0]} -> {max_val}")

    cur.execute("SELECT COALESCE(MAX(yy_volume_key), 0) FROM yy_volume")
    max_val = cur.fetchone()[0]
    cur.execute("SELECT c.relname FROM pg_class c WHERE c.relname LIKE 'yy_volume_%_seq'")
    seq_row = cur.fetchone()
    if seq_row:
        cur.execute(f"SELECT setval('{seq_row[0]}', %s)", (max(max_val, 1),))
        print(f"  {seq_row[0]} -> {max_val}")

    pg.commit()
    print("  Sequences reset.")


def step_create_triggers(pg):
    """Create PG trigger functions and attach triggers for revision tracking."""
    cur = pg.cursor()
    print("Creating trigger functions and triggers...")

    # Define trigger specs: (main_table, rev_table, entity_key_col, data_columns)
    trigger_specs = [
        ('yah_scroll', 'rev_yah_scroll', 'yah_scroll_key',
         ['yah_scroll_key', 'yah_scroll_label_common', 'yah_scroll_label_yy', 'yah_scroll_sort']),
        ('yah_chapter', 'rev_yah_chapter', 'yah_chapter_key',
         ['yah_chapter_key', 'yah_scroll_key', 'yah_chapter_number', 'yah_chapter_sort']),
        ('yah_verse', 'rev_yah_verse', 'yah_verse_key',
         ['yah_verse_key', 'yah_chapter_key', 'yah_verse_number', 'yah_verse_sort']),
        ('yy_series', 'rev_yy_series', 'yy_series_key',
         ['yy_series_key', 'yy_series_name', 'yy_series_label', 'yy_series_sort']),
        ('yy_volume', 'rev_yy_volume', 'yy_volume_key',
         ['yy_volume_key', 'yy_series_key', 'yy_volume_number', 'yy_volume_name', 'yy_volume_label',
          'yy_volume_page_count', 'yy_volume_paragraph_count', 'yy_volume_sort']),
        ('yy_chapter', 'rev_yy_chapter', 'yy_chapter_key',
         ['yy_chapter_key', 'yy_volume_key', 'yy_chapter_number', 'yy_chapter_page',
          'yy_chapter_name', 'yy_chapter_label', 'yy_chapter_sort']),
        ('yy_user', 'rev_yy_user', 'yy_user_key',
         ['yy_user_key', 'yy_user_code', 'yy_user_pass', 'yy_user_name_last', 'yy_user_name_first',
          'yy_user_name_middle', 'yy_user_name_prefix', 'yy_user_name_suffix', 'yy_user_name_full',
          'yy_user_email', 'yy_user_text']),
        ('yy_translation', 'rev_yy_translation', 'yy_translation_key',
         ['yy_translation_key', 'yah_scroll_key', 'yah_chapter_key', 'yah_verse_key',
          'yy_series_key', 'yy_volume_key', 'yy_chapter_key',
          'yy_translation_page', 'yy_translation_paragraph', 'yy_translation_copy',
          'yy_translation_date', 'yy_translation_sort', 'yy_translation_dtime']),
    ]

    for main_table, rev_table, entity_key, columns in trigger_specs:
        fn_name = f"trg_{main_table}_revision"
        col_list = ', '.join(columns)
        new_vals = ', '.join([f'NEW.{c}' for c in columns])
        old_vals = ', '.join([f'OLD.{c}' for c in columns])

        func_sql = f"""
CREATE OR REPLACE FUNCTION {fn_name}() RETURNS TRIGGER AS $$
DECLARE
    rev_count INT;
    user_key INT;
    entity_key_val INT;
BEGIN
    user_key := COALESCE(NULLIF(current_setting('app.current_user_key', true), ''), '0')::INT;

    IF TG_OP = 'DELETE' THEN
        entity_key_val := OLD.{entity_key};
    ELSE
        entity_key_val := NEW.{entity_key};
    END IF;

    SELECT COALESCE(MAX(_revision_count), 0) + 1 INTO rev_count
    FROM {rev_table} WHERE {entity_key} = entity_key_val;

    IF TG_OP = 'DELETE' THEN
        INSERT INTO {rev_table} ({col_list}, _remove_dtime, _revision_count, _revision_user_key, _revision_dtime)
        VALUES ({old_vals}, NOW(), rev_count, user_key, NOW());
        RETURN OLD;
    ELSE
        INSERT INTO {rev_table} ({col_list}, _revision_count, _revision_user_key, _revision_dtime)
        VALUES ({new_vals}, rev_count, user_key, NOW());
        RETURN NEW;
    END IF;
END;
$$ LANGUAGE plpgsql;
"""
        cur.execute(func_sql)

        # Drop existing triggers if any, then create
        for suffix, timing, event in [('ai', 'AFTER', 'INSERT'), ('au', 'AFTER', 'UPDATE'), ('bd', 'BEFORE', 'DELETE')]:
            trig_name = f"trg_{main_table}_{suffix}"
            cur.execute(f"DROP TRIGGER IF EXISTS {trig_name} ON {main_table}")
            cur.execute(f"""
                CREATE TRIGGER {trig_name} {timing} {event} ON {main_table}
                FOR EACH ROW EXECUTE FUNCTION {fn_name}()
            """)

    pg.commit()
    print("  Triggers created.")


def step_verify(pg):
    """Verify row counts after migration."""
    cur = pg.cursor()
    print("\nVerification:")
    checks = [
        ('yah_scroll', 26), ('yah_chapter', 567), ('yah_verse', 15147),
        ('yy_series', 8), ('yy_volume', 34), ('yy_chapter', 371),
        ('yy_user', 2), ('yy_translation', 4),
    ]
    all_ok = True
    for table, expected_min in checks:
        cur.execute(f"SELECT COUNT(*) FROM {table}")
        count = cur.fetchone()[0]
        status = 'OK' if count >= expected_min else 'MISMATCH'
        if status == 'MISMATCH':
            all_ok = False
        print(f"  {table}: {count} rows (expected >={expected_min}) [{status}]")

    # Verify trigger works
    cur.execute("SET app.current_user_key = '0'")
    cur.execute("UPDATE yah_scroll SET yah_scroll_sort = yah_scroll_sort WHERE yah_scroll_key = 1")
    cur.execute("SELECT COUNT(*) FROM rev_yah_scroll WHERE yah_scroll_key = 1")
    rev_count = cur.fetchone()[0]
    print(f"  Trigger test: rev_yah_scroll has {rev_count} row(s) for scroll_key=1 [{'OK' if rev_count >= 1 else 'FAIL'}]")
    pg.rollback()  # Roll back the test update

    return all_ok


def main():
    print("=== MySQL to PostgreSQL Migration ===\n")

    print("Connecting to MySQL...")
    my = get_mysql()
    print("Connecting to PostgreSQL...")
    pg = get_pg()

    step_alter_existing_pg_tables(pg)
    step_create_new_tables(pg)
    step_create_revision_tables(pg)
    step_migrate_data(my, pg)
    step_reset_sequences(pg)
    step_create_triggers(pg)

    ok = step_verify(pg)

    my.close()
    pg.close()

    if ok:
        print("\nMigration completed successfully!")
    else:
        print("\nMigration completed with warnings - check counts above.")


if __name__ == '__main__':
    main()
