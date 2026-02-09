-- Revision triggers for all main tables
-- Uses @current_user_key session variable set by PHP before DML operations
-- Runs after seed data so initial inserts are not tracked
-- Uses DECLARE variable to avoid MySQL error 1093 (can't INSERT+SELECT same table)

DELIMITER //

-- ── yah_scroll ──
CREATE TRIGGER trg_yah_scroll_ai AFTER INSERT ON yah_scroll FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yah_scroll WHERE yah_scroll_key = NEW.yah_scroll_key), 0) + 1;
    INSERT INTO rev_yah_scroll (yah_scroll_key, yah_scroll_label_common, yah_scroll_label_yy, yah_scroll_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yah_scroll_key, NEW.yah_scroll_label_common, NEW.yah_scroll_label_yy, NEW.yah_scroll_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yah_scroll_au AFTER UPDATE ON yah_scroll FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yah_scroll WHERE yah_scroll_key = NEW.yah_scroll_key), 0) + 1;
    INSERT INTO rev_yah_scroll (yah_scroll_key, yah_scroll_label_common, yah_scroll_label_yy, yah_scroll_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yah_scroll_key, NEW.yah_scroll_label_common, NEW.yah_scroll_label_yy, NEW.yah_scroll_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yah_scroll_bd BEFORE DELETE ON yah_scroll FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yah_scroll WHERE yah_scroll_key = OLD.yah_scroll_key), 0) + 1;
    INSERT INTO rev_yah_scroll (yah_scroll_key, yah_scroll_label_common, yah_scroll_label_yy, yah_scroll_sort,
        _remove_dtime, _revision_count, _revision_user_key, _revision_dtime)
    VALUES (OLD.yah_scroll_key, OLD.yah_scroll_label_common, OLD.yah_scroll_label_yy, OLD.yah_scroll_sort,
        NOW(), rev_count, COALESCE(@current_user_key, 0), NOW());
END //

-- ── yah_chapter ──
CREATE TRIGGER trg_yah_chapter_ai AFTER INSERT ON yah_chapter FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yah_chapter WHERE yah_chapter_key = NEW.yah_chapter_key), 0) + 1;
    INSERT INTO rev_yah_chapter (yah_chapter_key, yah_scroll_key, yah_chapter_number, yah_chapter_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yah_chapter_key, NEW.yah_scroll_key, NEW.yah_chapter_number, NEW.yah_chapter_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yah_chapter_au AFTER UPDATE ON yah_chapter FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yah_chapter WHERE yah_chapter_key = NEW.yah_chapter_key), 0) + 1;
    INSERT INTO rev_yah_chapter (yah_chapter_key, yah_scroll_key, yah_chapter_number, yah_chapter_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yah_chapter_key, NEW.yah_scroll_key, NEW.yah_chapter_number, NEW.yah_chapter_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yah_chapter_bd BEFORE DELETE ON yah_chapter FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yah_chapter WHERE yah_chapter_key = OLD.yah_chapter_key), 0) + 1;
    INSERT INTO rev_yah_chapter (yah_chapter_key, yah_scroll_key, yah_chapter_number, yah_chapter_sort,
        _remove_dtime, _revision_count, _revision_user_key, _revision_dtime)
    VALUES (OLD.yah_chapter_key, OLD.yah_scroll_key, OLD.yah_chapter_number, OLD.yah_chapter_sort,
        NOW(), rev_count, COALESCE(@current_user_key, 0), NOW());
END //

-- ── yah_verse ──
CREATE TRIGGER trg_yah_verse_ai AFTER INSERT ON yah_verse FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yah_verse WHERE yah_verse_key = NEW.yah_verse_key), 0) + 1;
    INSERT INTO rev_yah_verse (yah_verse_key, yah_chapter_key, yah_verse_number, yah_verse_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yah_verse_key, NEW.yah_chapter_key, NEW.yah_verse_number, NEW.yah_verse_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yah_verse_au AFTER UPDATE ON yah_verse FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yah_verse WHERE yah_verse_key = NEW.yah_verse_key), 0) + 1;
    INSERT INTO rev_yah_verse (yah_verse_key, yah_chapter_key, yah_verse_number, yah_verse_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yah_verse_key, NEW.yah_chapter_key, NEW.yah_verse_number, NEW.yah_verse_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yah_verse_bd BEFORE DELETE ON yah_verse FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yah_verse WHERE yah_verse_key = OLD.yah_verse_key), 0) + 1;
    INSERT INTO rev_yah_verse (yah_verse_key, yah_chapter_key, yah_verse_number, yah_verse_sort,
        _remove_dtime, _revision_count, _revision_user_key, _revision_dtime)
    VALUES (OLD.yah_verse_key, OLD.yah_chapter_key, OLD.yah_verse_number, OLD.yah_verse_sort,
        NOW(), rev_count, COALESCE(@current_user_key, 0), NOW());
END //

-- ── yy_series ──
CREATE TRIGGER trg_yy_series_ai AFTER INSERT ON yy_series FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_series WHERE yy_series_key = NEW.yy_series_key), 0) + 1;
    INSERT INTO rev_yy_series (yy_series_key, yy_series_name, yy_series_label, yy_series_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yy_series_key, NEW.yy_series_name, NEW.yy_series_label, NEW.yy_series_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yy_series_au AFTER UPDATE ON yy_series FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_series WHERE yy_series_key = NEW.yy_series_key), 0) + 1;
    INSERT INTO rev_yy_series (yy_series_key, yy_series_name, yy_series_label, yy_series_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yy_series_key, NEW.yy_series_name, NEW.yy_series_label, NEW.yy_series_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yy_series_bd BEFORE DELETE ON yy_series FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_series WHERE yy_series_key = OLD.yy_series_key), 0) + 1;
    INSERT INTO rev_yy_series (yy_series_key, yy_series_name, yy_series_label, yy_series_sort,
        _remove_dtime, _revision_count, _revision_user_key, _revision_dtime)
    VALUES (OLD.yy_series_key, OLD.yy_series_name, OLD.yy_series_label, OLD.yy_series_sort,
        NOW(), rev_count, COALESCE(@current_user_key, 0), NOW());
END //

-- ── yy_volume ──
CREATE TRIGGER trg_yy_volume_ai AFTER INSERT ON yy_volume FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_volume WHERE yy_volume_key = NEW.yy_volume_key), 0) + 1;
    INSERT INTO rev_yy_volume (yy_volume_key, yy_series_key, yy_volume_number, yy_volume_name, yy_volume_label,
        yy_volume_page_count, yy_volume_paragraph_count, yy_volume_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yy_volume_key, NEW.yy_series_key, NEW.yy_volume_number, NEW.yy_volume_name, NEW.yy_volume_label,
        NEW.yy_volume_page_count, NEW.yy_volume_paragraph_count, NEW.yy_volume_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yy_volume_au AFTER UPDATE ON yy_volume FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_volume WHERE yy_volume_key = NEW.yy_volume_key), 0) + 1;
    INSERT INTO rev_yy_volume (yy_volume_key, yy_series_key, yy_volume_number, yy_volume_name, yy_volume_label,
        yy_volume_page_count, yy_volume_paragraph_count, yy_volume_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yy_volume_key, NEW.yy_series_key, NEW.yy_volume_number, NEW.yy_volume_name, NEW.yy_volume_label,
        NEW.yy_volume_page_count, NEW.yy_volume_paragraph_count, NEW.yy_volume_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yy_volume_bd BEFORE DELETE ON yy_volume FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_volume WHERE yy_volume_key = OLD.yy_volume_key), 0) + 1;
    INSERT INTO rev_yy_volume (yy_volume_key, yy_series_key, yy_volume_number, yy_volume_name, yy_volume_label,
        yy_volume_page_count, yy_volume_paragraph_count, yy_volume_sort,
        _remove_dtime, _revision_count, _revision_user_key, _revision_dtime)
    VALUES (OLD.yy_volume_key, OLD.yy_series_key, OLD.yy_volume_number, OLD.yy_volume_name, OLD.yy_volume_label,
        OLD.yy_volume_page_count, OLD.yy_volume_paragraph_count, OLD.yy_volume_sort,
        NOW(), rev_count, COALESCE(@current_user_key, 0), NOW());
END //

-- ── yy_chapter ──
CREATE TRIGGER trg_yy_chapter_ai AFTER INSERT ON yy_chapter FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_chapter WHERE yy_chapter_key = NEW.yy_chapter_key), 0) + 1;
    INSERT INTO rev_yy_chapter (yy_chapter_key, yy_volume_key, yy_chapter_number, yy_chapter_page,
        yy_chapter_name, yy_chapter_label, yy_chapter_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yy_chapter_key, NEW.yy_volume_key, NEW.yy_chapter_number, NEW.yy_chapter_page,
        NEW.yy_chapter_name, NEW.yy_chapter_label, NEW.yy_chapter_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yy_chapter_au AFTER UPDATE ON yy_chapter FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_chapter WHERE yy_chapter_key = NEW.yy_chapter_key), 0) + 1;
    INSERT INTO rev_yy_chapter (yy_chapter_key, yy_volume_key, yy_chapter_number, yy_chapter_page,
        yy_chapter_name, yy_chapter_label, yy_chapter_sort,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yy_chapter_key, NEW.yy_volume_key, NEW.yy_chapter_number, NEW.yy_chapter_page,
        NEW.yy_chapter_name, NEW.yy_chapter_label, NEW.yy_chapter_sort,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yy_chapter_bd BEFORE DELETE ON yy_chapter FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_chapter WHERE yy_chapter_key = OLD.yy_chapter_key), 0) + 1;
    INSERT INTO rev_yy_chapter (yy_chapter_key, yy_volume_key, yy_chapter_number, yy_chapter_page,
        yy_chapter_name, yy_chapter_label, yy_chapter_sort,
        _remove_dtime, _revision_count, _revision_user_key, _revision_dtime)
    VALUES (OLD.yy_chapter_key, OLD.yy_volume_key, OLD.yy_chapter_number, OLD.yy_chapter_page,
        OLD.yy_chapter_name, OLD.yy_chapter_label, OLD.yy_chapter_sort,
        NOW(), rev_count, COALESCE(@current_user_key, 0), NOW());
END //

-- ── yy_user ──
CREATE TRIGGER trg_yy_user_ai AFTER INSERT ON yy_user FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_user WHERE yy_user_key = NEW.yy_user_key), 0) + 1;
    INSERT INTO rev_yy_user (yy_user_key, yy_user_code, yy_user_pass, yy_user_name_last, yy_user_name_first,
        yy_user_name_middle, yy_user_name_prefix, yy_user_name_suffix, yy_user_name_full,
        yy_user_email, yy_user_text,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yy_user_key, NEW.yy_user_code, NEW.yy_user_pass, NEW.yy_user_name_last, NEW.yy_user_name_first,
        NEW.yy_user_name_middle, NEW.yy_user_name_prefix, NEW.yy_user_name_suffix, NEW.yy_user_name_full,
        NEW.yy_user_email, NEW.yy_user_text,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yy_user_au AFTER UPDATE ON yy_user FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_user WHERE yy_user_key = NEW.yy_user_key), 0) + 1;
    INSERT INTO rev_yy_user (yy_user_key, yy_user_code, yy_user_pass, yy_user_name_last, yy_user_name_first,
        yy_user_name_middle, yy_user_name_prefix, yy_user_name_suffix, yy_user_name_full,
        yy_user_email, yy_user_text,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yy_user_key, NEW.yy_user_code, NEW.yy_user_pass, NEW.yy_user_name_last, NEW.yy_user_name_first,
        NEW.yy_user_name_middle, NEW.yy_user_name_prefix, NEW.yy_user_name_suffix, NEW.yy_user_name_full,
        NEW.yy_user_email, NEW.yy_user_text,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yy_user_bd BEFORE DELETE ON yy_user FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_user WHERE yy_user_key = OLD.yy_user_key), 0) + 1;
    INSERT INTO rev_yy_user (yy_user_key, yy_user_code, yy_user_pass, yy_user_name_last, yy_user_name_first,
        yy_user_name_middle, yy_user_name_prefix, yy_user_name_suffix, yy_user_name_full,
        yy_user_email, yy_user_text,
        _remove_dtime, _revision_count, _revision_user_key, _revision_dtime)
    VALUES (OLD.yy_user_key, OLD.yy_user_code, OLD.yy_user_pass, OLD.yy_user_name_last, OLD.yy_user_name_first,
        OLD.yy_user_name_middle, OLD.yy_user_name_prefix, OLD.yy_user_name_suffix, OLD.yy_user_name_full,
        OLD.yy_user_email, OLD.yy_user_text,
        NOW(), rev_count, COALESCE(@current_user_key, 0), NOW());
END //

-- ── yy_translation ──
CREATE TRIGGER trg_yy_translation_ai AFTER INSERT ON yy_translation FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_translation WHERE yy_translation_key = NEW.yy_translation_key), 0) + 1;
    INSERT INTO rev_yy_translation (yy_translation_key, yah_scroll_key, yah_chapter_key, yah_verse_key,
        yy_series_key, yy_volume_key, yy_chapter_key, yy_translation_page, yy_translation_paragraph,
        yy_translation_copy, yy_translation_date, yy_translation_sort, yy_translation_dtime,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yy_translation_key, NEW.yah_scroll_key, NEW.yah_chapter_key, NEW.yah_verse_key,
        NEW.yy_series_key, NEW.yy_volume_key, NEW.yy_chapter_key, NEW.yy_translation_page, NEW.yy_translation_paragraph,
        NEW.yy_translation_copy, NEW.yy_translation_date, NEW.yy_translation_sort, NEW.yy_translation_dtime,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yy_translation_au AFTER UPDATE ON yy_translation FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_translation WHERE yy_translation_key = NEW.yy_translation_key), 0) + 1;
    INSERT INTO rev_yy_translation (yy_translation_key, yah_scroll_key, yah_chapter_key, yah_verse_key,
        yy_series_key, yy_volume_key, yy_chapter_key, yy_translation_page, yy_translation_paragraph,
        yy_translation_copy, yy_translation_date, yy_translation_sort, yy_translation_dtime,
        _revision_count, _revision_user_key, _revision_dtime)
    VALUES (NEW.yy_translation_key, NEW.yah_scroll_key, NEW.yah_chapter_key, NEW.yah_verse_key,
        NEW.yy_series_key, NEW.yy_volume_key, NEW.yy_chapter_key, NEW.yy_translation_page, NEW.yy_translation_paragraph,
        NEW.yy_translation_copy, NEW.yy_translation_date, NEW.yy_translation_sort, NEW.yy_translation_dtime,
        rev_count, COALESCE(@current_user_key, 0), NOW());
END //

CREATE TRIGGER trg_yy_translation_bd BEFORE DELETE ON yy_translation FOR EACH ROW
BEGIN
    DECLARE rev_count INT;
    SET rev_count = COALESCE((SELECT MAX(_revision_count) FROM rev_yy_translation WHERE yy_translation_key = OLD.yy_translation_key), 0) + 1;
    INSERT INTO rev_yy_translation (yy_translation_key, yah_scroll_key, yah_chapter_key, yah_verse_key,
        yy_series_key, yy_volume_key, yy_chapter_key, yy_translation_page, yy_translation_paragraph,
        yy_translation_copy, yy_translation_date, yy_translation_sort, yy_translation_dtime,
        _remove_dtime, _revision_count, _revision_user_key, _revision_dtime)
    VALUES (OLD.yy_translation_key, OLD.yah_scroll_key, OLD.yah_chapter_key, OLD.yah_verse_key,
        OLD.yy_series_key, OLD.yy_volume_key, OLD.yy_chapter_key, OLD.yy_translation_page, OLD.yy_translation_paragraph,
        OLD.yy_translation_copy, OLD.yy_translation_date, OLD.yy_translation_sort, OLD.yy_translation_dtime,
        NOW(), rev_count, COALESCE(@current_user_key, 0), NOW());
END //

DELIMITER ;
