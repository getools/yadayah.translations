-- Yada Yah Translations Database Schema
-- MySQL 8.0 with utf8mb4 charset

-- ============================================================
-- MAIN TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS yah_scroll (
    yah_scroll_key INT NOT NULL AUTO_INCREMENT,
    yah_scroll_label_common VARCHAR(250) NOT NULL,
    yah_scroll_label_yy VARCHAR(250) NOT NULL,
    yah_scroll_sort SMALLINT DEFAULT 0,
    PRIMARY KEY (yah_scroll_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yah_chapter (
    yah_chapter_key INT NOT NULL AUTO_INCREMENT,
    yah_scroll_key INT NOT NULL,
    yah_chapter_number SMALLINT NOT NULL,
    yah_chapter_sort SMALLINT DEFAULT 0,
    PRIMARY KEY (yah_chapter_key),
    INDEX idx_yah_chapter_scroll (yah_scroll_key),
    FOREIGN KEY (yah_scroll_key) REFERENCES yah_scroll(yah_scroll_key)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yah_verse (
    yah_verse_key INT NOT NULL AUTO_INCREMENT,
    yah_chapter_key INT NOT NULL,
    yah_verse_number SMALLINT NOT NULL,
    yah_verse_sort SMALLINT DEFAULT 0,
    PRIMARY KEY (yah_verse_key),
    INDEX idx_yah_verse_chapter (yah_chapter_key),
    FOREIGN KEY (yah_chapter_key) REFERENCES yah_chapter(yah_chapter_key)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yy_series (
    yy_series_key INT NOT NULL AUTO_INCREMENT,
    yy_series_name VARCHAR(250) NOT NULL,
    yy_series_label VARCHAR(250) NULL,
    yy_series_sort SMALLINT DEFAULT 0,
    PRIMARY KEY (yy_series_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yy_volume (
    yy_volume_key INT NOT NULL AUTO_INCREMENT,
    yy_series_key INT NOT NULL,
    yy_volume_number SMALLINT NOT NULL,
    yy_volume_name VARCHAR(250) NOT NULL,
    yy_volume_label VARCHAR(250) NULL,
    yy_volume_page_count SMALLINT NULL,
    yy_volume_paragraph_count SMALLINT NULL,
    yy_volume_sort SMALLINT DEFAULT 0,
    PRIMARY KEY (yy_volume_key),
    INDEX idx_yy_volume_series (yy_series_key),
    FOREIGN KEY (yy_series_key) REFERENCES yy_series(yy_series_key)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yy_chapter (
    yy_chapter_key INT NOT NULL AUTO_INCREMENT,
    yy_volume_key INT NOT NULL,
    yy_chapter_number SMALLINT NOT NULL,
    yy_chapter_page INT NULL,
    yy_chapter_name VARCHAR(250) NULL,
    yy_chapter_label VARCHAR(500) NULL,
    yy_chapter_sort SMALLINT DEFAULT 0,
    PRIMARY KEY (yy_chapter_key),
    INDEX idx_yy_chapter_volume (yy_volume_key),
    FOREIGN KEY (yy_volume_key) REFERENCES yy_volume(yy_volume_key)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yy_user (
    yy_user_key INT NOT NULL AUTO_INCREMENT,
    yy_user_code VARCHAR(500) NOT NULL,
    yy_user_pass VARCHAR(255) NULL,
    yy_user_name_last VARCHAR(500) NULL,
    yy_user_name_first VARCHAR(500) NULL,
    yy_user_name_middle VARCHAR(500) NULL,
    yy_user_name_prefix VARCHAR(500) NULL,
    yy_user_name_suffix VARCHAR(500) NULL,
    yy_user_name_full VARCHAR(500) NULL,
    yy_user_email VARCHAR(500) NULL,
    yy_user_text VARCHAR(64) NULL,
    PRIMARY KEY (yy_user_key),
    UNIQUE KEY uk_yy_user_code (yy_user_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yy_user_preference (
    yy_user_preference_key INT NOT NULL AUTO_INCREMENT,
    yy_user_key INT NOT NULL,
    yy_preference_name VARCHAR(100) NOT NULL,
    yy_preference_value TEXT NULL,
    PRIMARY KEY (yy_user_preference_key),
    UNIQUE KEY uk_user_pref (yy_user_key, yy_preference_name),
    FOREIGN KEY (yy_user_key) REFERENCES yy_user(yy_user_key)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yy_translation (
    yy_translation_key INT NOT NULL AUTO_INCREMENT,
    yah_scroll_key INT NOT NULL,
    yah_chapter_key INT NOT NULL,
    yah_verse_key INT NOT NULL,
    yy_series_key INT NOT NULL,
    yy_volume_key INT NOT NULL,
    yy_chapter_key INT NOT NULL,
    yy_translation_page SMALLINT NULL,
    yy_translation_paragraph SMALLINT NULL,
    yy_translation_copy LONGTEXT NULL,
    yy_translation_date DATE NULL,
    yy_translation_sort SMALLINT NOT NULL DEFAULT 0,
    yy_translation_dtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (yy_translation_key),
    INDEX idx_translation_verse (yah_scroll_key, yah_chapter_key, yah_verse_key),
    INDEX idx_translation_scroll (yah_scroll_key),
    INDEX idx_translation_chapter (yah_chapter_key),
    INDEX idx_translation_yy_verse (yah_verse_key),
    INDEX idx_translation_series (yy_series_key),
    INDEX idx_translation_volume (yy_volume_key),
    INDEX idx_translation_yy_chapter (yy_chapter_key),
    FOREIGN KEY (yah_scroll_key) REFERENCES yah_scroll(yah_scroll_key)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (yah_chapter_key) REFERENCES yah_chapter(yah_chapter_key)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (yah_verse_key) REFERENCES yah_verse(yah_verse_key)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (yy_series_key) REFERENCES yy_series(yy_series_key)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (yy_volume_key) REFERENCES yy_volume(yy_volume_key)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (yy_chapter_key) REFERENCES yy_chapter(yy_chapter_key)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- REVISION TABLES
-- Mirror main table columns (no defaults, FKs, or auto-increment)
-- Plus revision tracking columns
-- ============================================================

CREATE TABLE IF NOT EXISTS rev_yah_scroll (
    _key BIGINT NOT NULL AUTO_INCREMENT,
    yah_scroll_key INT NULL,
    yah_scroll_label_common VARCHAR(250) NULL,
    yah_scroll_label_yy VARCHAR(250) NULL,
    yah_scroll_sort SMALLINT NULL,
    _remove_dtime DATETIME NULL,
    _revision_count INT NOT NULL DEFAULT 0,
    _revision_user_key INT NOT NULL DEFAULT 0,
    _revision_dtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (_key),
    INDEX idx_rev_yah_scroll_key (yah_scroll_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rev_yah_chapter (
    _key BIGINT NOT NULL AUTO_INCREMENT,
    yah_chapter_key INT NULL,
    yah_scroll_key INT NULL,
    yah_chapter_number SMALLINT NULL,
    yah_chapter_sort SMALLINT NULL,
    _remove_dtime DATETIME NULL,
    _revision_count INT NOT NULL DEFAULT 0,
    _revision_user_key INT NOT NULL DEFAULT 0,
    _revision_dtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (_key),
    INDEX idx_rev_yah_chapter_key (yah_chapter_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rev_yah_verse (
    _key BIGINT NOT NULL AUTO_INCREMENT,
    yah_verse_key INT NULL,
    yah_chapter_key INT NULL,
    yah_verse_number SMALLINT NULL,
    yah_verse_sort SMALLINT NULL,
    _remove_dtime DATETIME NULL,
    _revision_count INT NOT NULL DEFAULT 0,
    _revision_user_key INT NOT NULL DEFAULT 0,
    _revision_dtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (_key),
    INDEX idx_rev_yah_verse_key (yah_verse_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rev_yy_series (
    _key BIGINT NOT NULL AUTO_INCREMENT,
    yy_series_key INT NULL,
    yy_series_name VARCHAR(250) NULL,
    yy_series_label VARCHAR(250) NULL,
    yy_series_sort SMALLINT NULL,
    _remove_dtime DATETIME NULL,
    _revision_count INT NOT NULL DEFAULT 0,
    _revision_user_key INT NOT NULL DEFAULT 0,
    _revision_dtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (_key),
    INDEX idx_rev_yy_series_key (yy_series_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rev_yy_volume (
    _key BIGINT NOT NULL AUTO_INCREMENT,
    yy_volume_key INT NULL,
    yy_series_key INT NULL,
    yy_volume_number SMALLINT NULL,
    yy_volume_name VARCHAR(250) NULL,
    yy_volume_label VARCHAR(250) NULL,
    yy_volume_page_count SMALLINT NULL,
    yy_volume_paragraph_count SMALLINT NULL,
    yy_volume_sort SMALLINT NULL,
    _remove_dtime DATETIME NULL,
    _revision_count INT NOT NULL DEFAULT 0,
    _revision_user_key INT NOT NULL DEFAULT 0,
    _revision_dtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (_key),
    INDEX idx_rev_yy_volume_key (yy_volume_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rev_yy_chapter (
    _key BIGINT NOT NULL AUTO_INCREMENT,
    yy_chapter_key INT NULL,
    yy_volume_key INT NULL,
    yy_chapter_number SMALLINT NULL,
    yy_chapter_page INT NULL,
    yy_chapter_name VARCHAR(250) NULL,
    yy_chapter_label VARCHAR(500) NULL,
    yy_chapter_sort SMALLINT NULL,
    _remove_dtime DATETIME NULL,
    _revision_count INT NOT NULL DEFAULT 0,
    _revision_user_key INT NOT NULL DEFAULT 0,
    _revision_dtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (_key),
    INDEX idx_rev_yy_chapter_key (yy_chapter_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rev_yy_user (
    _key BIGINT NOT NULL AUTO_INCREMENT,
    yy_user_key INT NULL,
    yy_user_code VARCHAR(500) NULL,
    yy_user_pass VARCHAR(255) NULL,
    yy_user_name_last VARCHAR(500) NULL,
    yy_user_name_first VARCHAR(500) NULL,
    yy_user_name_middle VARCHAR(500) NULL,
    yy_user_name_prefix VARCHAR(500) NULL,
    yy_user_name_suffix VARCHAR(500) NULL,
    yy_user_name_full VARCHAR(500) NULL,
    yy_user_email VARCHAR(500) NULL,
    yy_user_text VARCHAR(64) NULL,
    _remove_dtime DATETIME NULL,
    _revision_count INT NOT NULL DEFAULT 0,
    _revision_user_key INT NOT NULL DEFAULT 0,
    _revision_dtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (_key),
    INDEX idx_rev_yy_user_key (yy_user_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rev_yy_translation (
    _key BIGINT NOT NULL AUTO_INCREMENT,
    yy_translation_key INT NULL,
    yah_scroll_key INT NULL,
    yah_chapter_key INT NULL,
    yah_verse_key INT NULL,
    yy_series_key INT NULL,
    yy_volume_key INT NULL,
    yy_chapter_key INT NULL,
    yy_translation_page SMALLINT NULL,
    yy_translation_paragraph SMALLINT NULL,
    yy_translation_copy LONGTEXT NULL,
    yy_translation_date DATE NULL,
    yy_translation_sort SMALLINT NULL,
    yy_translation_dtime DATETIME NULL,
    _remove_dtime DATETIME NULL,
    _revision_count INT NOT NULL DEFAULT 0,
    _revision_user_key INT NOT NULL DEFAULT 0,
    _revision_dtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (_key),
    INDEX idx_rev_yy_translation_key (yy_translation_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
