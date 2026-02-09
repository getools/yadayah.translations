-- Yada Yah Translations Seed Data
-- MySQL 8.0

-- ============================================================
-- 1. SCROLLS (26 books)
-- ============================================================
INSERT INTO yah_scroll (yah_scroll_key, yah_scroll_label_common, yah_scroll_label_yy, yah_scroll_sort) VALUES
(1, 'Genesis', 'Bereshit', 10),
(2, 'Exodus', 'Shemot', 20),
(3, 'Leviticus', 'Vayikra', 30),
(4, 'Numbers', 'Bamidbar', 40),
(5, 'Deuteronomy', 'Devarim', 50),
(6, 'Joshua', 'Yehoshua', 60),
(7, 'Judges', 'Shoftim', 70),
(8, 'Samuel I', 'Shmuel Alef', 80),
(9, 'Samuel II', 'Shmuel Bet', 90),
(10, 'Kings I', 'Melachim Alef', 100),
(11, 'Kings II', 'Melachim Bet', 110),
(12, 'Isaiah', 'Yeshayahu', 120),
(13, 'Jeremiah', 'Yirmiyahu', 130),
(14, 'Ezekiel', 'Yechezkel', 140),
(15, 'Hosea', 'Hoshea', 150),
(16, 'Joel', 'Yow''el', 160),
(17, 'Amos', 'Amos', 170),
(18, 'Obadiah', 'Ovadyah', 180),
(19, 'Jonah', 'Yonah', 190),
(20, 'Micah', 'Michah', 200),
(21, 'Nahum', 'Nachum', 210),
(22, 'Habakkuk', 'Chavakuk', 220),
(23, 'Zephaniah', 'Tzefanyah', 230),
(24, 'Haggai', 'Chaggai', 240),
(25, 'Zechariah', 'Zechariah', 250),
(26, 'Malachi', 'Malachi', 260);

-- ============================================================
-- 2. CHAPTERS (~561 chapters across 26 scrolls)
-- Chapter counts per scroll:
--   Genesis=50, Exodus=40, Leviticus=27, Numbers=36, Deuteronomy=34,
--   Joshua=24, Judges=21, 1Samuel=31, 2Samuel=24, 1Kings=22, 2Kings=25,
--   Isaiah=66, Jeremiah=52, Ezekiel=48, Hosea=14, Joel=3, Amos=9,
--   Obadiah=1, Jonah=4, Micah=7, Nahum=3, Habakkuk=3, Zephaniah=3,
--   Haggai=2, Zechariah=14, Malachi=4
-- ============================================================
DELIMITER //
CREATE PROCEDURE seed_chapters()
BEGIN
    DECLARE v_scroll_key INT;
    DECLARE v_chapter_count INT;
    DECLARE v_ch INT;

    -- Temporary table with chapter counts per scroll
    CREATE TEMPORARY TABLE tmp_scroll_chapters (
        scroll_key INT,
        chapter_count INT
    );

    INSERT INTO tmp_scroll_chapters VALUES
        (1, 50), (2, 40), (3, 27), (4, 36), (5, 34),
        (6, 24), (7, 21), (8, 31), (9, 24), (10, 22),
        (11, 25), (12, 66), (13, 52), (14, 48), (15, 14),
        (16, 3), (17, 9), (18, 1), (19, 4), (20, 7),
        (21, 3), (22, 3), (23, 3), (24, 2), (25, 14),
        (26, 4);

    BEGIN
        DECLARE done INT DEFAULT FALSE;
        DECLARE cur CURSOR FOR SELECT scroll_key, chapter_count FROM tmp_scroll_chapters ORDER BY scroll_key;
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

        OPEN cur;
        read_loop: LOOP
            FETCH cur INTO v_scroll_key, v_chapter_count;
            IF done THEN
                LEAVE read_loop;
            END IF;

            SET v_ch = 1;
            WHILE v_ch <= v_chapter_count DO
                INSERT INTO yah_chapter (yah_scroll_key, yah_chapter_number, yah_chapter_sort)
                VALUES (v_scroll_key, v_ch, v_ch * 10);
                SET v_ch = v_ch + 1;
            END WHILE;
        END LOOP;
        CLOSE cur;
    END;

    DROP TEMPORARY TABLE tmp_scroll_chapters;
END //
DELIMITER ;

CALL seed_chapters();
DROP PROCEDURE IF EXISTS seed_chapters;

-- ============================================================
-- 3. VERSES (~23,145 verses)
-- Uses a recursive CTE to generate verse rows from a verse-count table
-- ============================================================

-- Temporary table: verse counts per chapter
-- Format: (scroll_key, chapter_number, verse_count)
CREATE TEMPORARY TABLE tmp_verse_counts (
    scroll_key INT,
    chapter_num INT,
    verse_count INT
);

-- Genesis (50 chapters)
INSERT INTO tmp_verse_counts VALUES
(1,1,31),(1,2,25),(1,3,24),(1,4,26),(1,5,32),(1,6,22),(1,7,24),(1,8,22),(1,9,29),(1,10,32),
(1,11,32),(1,12,20),(1,13,18),(1,14,24),(1,15,21),(1,16,16),(1,17,27),(1,18,33),(1,19,38),(1,20,18),
(1,21,34),(1,22,24),(1,23,20),(1,24,67),(1,25,34),(1,26,35),(1,27,46),(1,28,22),(1,29,35),(1,30,43),
(1,31,55),(1,32,32),(1,33,20),(1,34,31),(1,35,29),(1,36,43),(1,37,36),(1,38,30),(1,39,23),(1,40,23),
(1,41,57),(1,42,38),(1,43,34),(1,44,34),(1,45,28),(1,46,34),(1,47,31),(1,48,22),(1,49,33),(1,50,26);

-- Exodus (40 chapters)
INSERT INTO tmp_verse_counts VALUES
(2,1,22),(2,2,25),(2,3,22),(2,4,31),(2,5,23),(2,6,30),(2,7,25),(2,8,32),(2,9,35),(2,10,29),
(2,11,10),(2,12,51),(2,13,22),(2,14,31),(2,15,27),(2,16,36),(2,17,16),(2,18,27),(2,19,25),(2,20,26),
(2,21,36),(2,22,31),(2,23,33),(2,24,18),(2,25,40),(2,26,37),(2,27,21),(2,28,43),(2,29,46),(2,30,38),
(2,31,18),(2,32,35),(2,33,23),(2,34,35),(2,35,35),(2,36,38),(2,37,29),(2,38,31),(2,39,43),(2,40,38);

-- Leviticus (27 chapters)
INSERT INTO tmp_verse_counts VALUES
(3,1,17),(3,2,16),(3,3,17),(3,4,35),(3,5,19),(3,6,30),(3,7,38),(3,8,36),(3,9,24),(3,10,20),
(3,11,47),(3,12,8),(3,13,59),(3,14,57),(3,15,33),(3,16,34),(3,17,16),(3,18,30),(3,19,37),(3,20,27),
(3,21,24),(3,22,33),(3,23,44),(3,24,23),(3,25,55),(3,26,46),(3,27,34);

-- Numbers (36 chapters)
INSERT INTO tmp_verse_counts VALUES
(4,1,54),(4,2,34),(4,3,51),(4,4,49),(4,5,31),(4,6,27),(4,7,89),(4,8,26),(4,9,23),(4,10,36),
(4,11,35),(4,12,16),(4,13,33),(4,14,45),(4,15,41),(4,16,50),(4,17,13),(4,18,32),(4,19,22),(4,20,29),
(4,21,35),(4,22,41),(4,23,30),(4,24,25),(4,25,18),(4,26,65),(4,27,23),(4,28,31),(4,29,40),(4,30,16),
(4,31,54),(4,32,42),(4,33,56),(4,34,29),(4,35,34),(4,36,13);

-- Deuteronomy (34 chapters)
INSERT INTO tmp_verse_counts VALUES
(5,1,46),(5,2,37),(5,3,29),(5,4,49),(5,5,33),(5,6,25),(5,7,26),(5,8,20),(5,9,29),(5,10,22),
(5,11,32),(5,12,32),(5,13,18),(5,14,29),(5,15,23),(5,16,22),(5,17,20),(5,18,22),(5,19,21),(5,20,20),
(5,21,23),(5,22,30),(5,23,25),(5,24,22),(5,25,19),(5,26,19),(5,27,26),(5,28,68),(5,29,29),(5,30,20),
(5,31,30),(5,32,52),(5,33,29),(5,34,12);

-- Joshua (24 chapters)
INSERT INTO tmp_verse_counts VALUES
(6,1,18),(6,2,24),(6,3,17),(6,4,24),(6,5,15),(6,6,27),(6,7,26),(6,8,35),(6,9,27),(6,10,43),
(6,11,23),(6,12,24),(6,13,33),(6,14,15),(6,15,63),(6,16,10),(6,17,18),(6,18,28),(6,19,51),(6,20,9),
(6,21,45),(6,22,34),(6,23,16),(6,24,33);

-- Judges (21 chapters)
INSERT INTO tmp_verse_counts VALUES
(7,1,36),(7,2,23),(7,3,31),(7,4,24),(7,5,31),(7,6,40),(7,7,25),(7,8,35),(7,9,57),(7,10,18),
(7,11,40),(7,12,15),(7,13,25),(7,14,20),(7,15,20),(7,16,31),(7,17,13),(7,18,31),(7,19,30),(7,20,48),
(7,21,25);

-- 1 Samuel (31 chapters)
INSERT INTO tmp_verse_counts VALUES
(8,1,28),(8,2,36),(8,3,21),(8,4,22),(8,5,12),(8,6,21),(8,7,17),(8,8,22),(8,9,27),(8,10,27),
(8,11,15),(8,12,25),(8,13,23),(8,14,52),(8,15,35),(8,16,23),(8,17,58),(8,18,30),(8,19,24),(8,20,42),
(8,21,15),(8,22,23),(8,23,29),(8,24,22),(8,25,44),(8,26,25),(8,27,12),(8,28,25),(8,29,11),(8,30,31),
(8,31,13);

-- 2 Samuel (24 chapters)
INSERT INTO tmp_verse_counts VALUES
(9,1,27),(9,2,32),(9,3,39),(9,4,12),(9,5,25),(9,6,23),(9,7,29),(9,8,18),(9,9,13),(9,10,19),
(9,11,27),(9,12,31),(9,13,39),(9,14,33),(9,15,37),(9,16,23),(9,17,29),(9,18,33),(9,19,43),(9,20,26),
(9,21,22),(9,22,51),(9,23,39),(9,24,25);

-- 1 Kings (22 chapters)
INSERT INTO tmp_verse_counts VALUES
(10,1,53),(10,2,46),(10,3,28),(10,4,34),(10,5,18),(10,6,38),(10,7,51),(10,8,66),(10,9,28),(10,10,29),
(10,11,43),(10,12,33),(10,13,34),(10,14,31),(10,15,34),(10,16,34),(10,17,24),(10,18,46),(10,19,21),
(10,20,43),(10,21,29),(10,22,53);

-- 2 Kings (25 chapters)
INSERT INTO tmp_verse_counts VALUES
(11,1,18),(11,2,25),(11,3,27),(11,4,44),(11,5,27),(11,6,33),(11,7,20),(11,8,29),(11,9,37),(11,10,36),
(11,11,21),(11,12,21),(11,13,25),(11,14,29),(11,15,38),(11,16,20),(11,17,41),(11,18,37),(11,19,37),
(11,20,21),(11,21,26),(11,22,20),(11,23,37),(11,24,20),(11,25,30);

-- Isaiah (66 chapters)
INSERT INTO tmp_verse_counts VALUES
(12,1,31),(12,2,22),(12,3,26),(12,4,6),(12,5,30),(12,6,13),(12,7,25),(12,8,22),(12,9,21),(12,10,34),
(12,11,16),(12,12,6),(12,13,22),(12,14,32),(12,15,9),(12,16,14),(12,17,14),(12,18,7),(12,19,25),
(12,20,6),(12,21,17),(12,22,25),(12,23,18),(12,24,23),(12,25,12),(12,26,21),(12,27,13),(12,28,29),
(12,29,24),(12,30,33),(12,31,9),(12,32,20),(12,33,24),(12,34,17),(12,35,10),(12,36,22),(12,37,38),
(12,38,22),(12,39,8),(12,40,31),(12,41,29),(12,42,25),(12,43,28),(12,44,28),(12,45,25),(12,46,13),
(12,47,15),(12,48,22),(12,49,26),(12,50,11),(12,51,23),(12,52,15),(12,53,12),(12,54,17),(12,55,13),
(12,56,12),(12,57,21),(12,58,14),(12,59,21),(12,60,22),(12,61,11),(12,62,12),(12,63,19),(12,64,12),
(12,65,25),(12,66,24);

-- Jeremiah (52 chapters)
INSERT INTO tmp_verse_counts VALUES
(13,1,19),(13,2,37),(13,3,25),(13,4,31),(13,5,31),(13,6,30),(13,7,34),(13,8,22),(13,9,26),(13,10,25),
(13,11,23),(13,12,17),(13,13,27),(13,14,22),(13,15,21),(13,16,21),(13,17,27),(13,18,23),(13,19,15),
(13,20,18),(13,21,14),(13,22,30),(13,23,40),(13,24,10),(13,25,38),(13,26,24),(13,27,22),(13,28,17),
(13,29,32),(13,30,24),(13,31,40),(13,32,44),(13,33,26),(13,34,22),(13,35,19),(13,36,32),(13,37,21),
(13,38,28),(13,39,18),(13,40,16),(13,41,18),(13,42,22),(13,43,13),(13,44,30),(13,45,5),(13,46,28),
(13,47,7),(13,48,47),(13,49,39),(13,50,46),(13,51,64),(13,52,34);

-- Ezekiel (48 chapters)
INSERT INTO tmp_verse_counts VALUES
(14,1,28),(14,2,10),(14,3,27),(14,4,17),(14,5,17),(14,6,14),(14,7,27),(14,8,18),(14,9,11),(14,10,22),
(14,11,25),(14,12,28),(14,13,23),(14,14,23),(14,15,8),(14,16,63),(14,17,24),(14,18,32),(14,19,14),
(14,20,49),(14,21,32),(14,22,31),(14,23,49),(14,24,27),(14,25,17),(14,26,21),(14,27,36),(14,28,26),
(14,29,21),(14,30,26),(14,31,18),(14,32,32),(14,33,33),(14,34,31),(14,35,15),(14,36,38),(14,37,28),
(14,38,23),(14,39,29),(14,40,49),(14,41,26),(14,42,20),(14,43,27),(14,44,31),(14,45,25),(14,46,24),
(14,47,23),(14,48,35);

-- Hosea (14 chapters)
INSERT INTO tmp_verse_counts VALUES
(15,1,11),(15,2,23),(15,3,5),(15,4,19),(15,5,15),(15,6,11),(15,7,16),(15,8,14),(15,9,17),(15,10,15),
(15,11,12),(15,12,14),(15,13,16),(15,14,9);

-- Joel (3 chapters)
INSERT INTO tmp_verse_counts VALUES
(16,1,20),(16,2,32),(16,3,21);

-- Amos (9 chapters)
INSERT INTO tmp_verse_counts VALUES
(17,1,15),(17,2,16),(17,3,15),(17,4,13),(17,5,27),(17,6,14),(17,7,17),(17,8,14),(17,9,15);

-- Obadiah (1 chapter)
INSERT INTO tmp_verse_counts VALUES
(18,1,21);

-- Jonah (4 chapters)
INSERT INTO tmp_verse_counts VALUES
(19,1,17),(19,2,10),(19,3,10),(19,4,11);

-- Micah (7 chapters)
INSERT INTO tmp_verse_counts VALUES
(20,1,16),(20,2,13),(20,3,12),(20,4,13),(20,5,15),(20,6,16),(20,7,20);

-- Nahum (3 chapters)
INSERT INTO tmp_verse_counts VALUES
(21,1,15),(21,2,13),(21,3,19);

-- Habakkuk (3 chapters)
INSERT INTO tmp_verse_counts VALUES
(22,1,17),(22,2,20),(22,3,19);

-- Zephaniah (3 chapters)
INSERT INTO tmp_verse_counts VALUES
(23,1,18),(23,2,15),(23,3,20);

-- Haggai (2 chapters)
INSERT INTO tmp_verse_counts VALUES
(24,1,15),(24,2,23);

-- Zechariah (14 chapters)
INSERT INTO tmp_verse_counts VALUES
(25,1,21),(25,2,13),(25,3,10),(25,4,14),(25,5,11),(25,6,15),(25,7,14),(25,8,23),(25,9,17),(25,10,12),
(25,11,17),(25,12,14),(25,13,9),(25,14,21);

-- Malachi (4 chapters)
INSERT INTO tmp_verse_counts VALUES
(26,1,14),(26,2,17),(26,3,18),(26,4,6);

-- Generate verses using recursive CTE
INSERT INTO yah_verse (yah_chapter_key, yah_verse_number, yah_verse_sort)
WITH RECURSIVE verse_gen AS (
    SELECT
        c.yah_chapter_key,
        1 AS verse_num,
        vc.verse_count
    FROM tmp_verse_counts vc
    JOIN yah_chapter c ON c.yah_scroll_key = vc.scroll_key AND c.yah_chapter_number = vc.chapter_num
    UNION ALL
    SELECT
        yah_chapter_key,
        verse_num + 1,
        verse_count
    FROM verse_gen
    WHERE verse_num < verse_count
)
SELECT yah_chapter_key, verse_num, verse_num * 10
FROM verse_gen
ORDER BY yah_chapter_key, verse_num;

DROP TEMPORARY TABLE tmp_verse_counts;

-- ============================================================
-- 4. SERIES (7 series)
-- ============================================================
INSERT INTO yy_series (yy_series_key, yy_series_name, yy_series_label, yy_series_sort) VALUES
(1, 'An Intro to God', NULL, 10),
(2, 'Yada Yahowah', NULL, 20),
(3, 'Observations', NULL, 30),
(4, 'Coming Home', NULL, 40),
(5, 'Babel', NULL, 50),
(6, 'Twistianity', NULL, 60),
(7, 'God Damn Religion', NULL, 70);

-- ============================================================
-- 5. VOLUMES (~33 volumes)
-- ============================================================
INSERT INTO yy_volume (yy_volume_key, yy_series_key, yy_volume_number, yy_volume_name, yy_volume_label, yy_volume_sort) VALUES
-- An Intro to God (3 volumes)
(1, 1, 1, 'Dabarym-Words', NULL, 10),
(2, 1, 2, 'Mitswah-Instructions', NULL, 20),
(3, 1, 3, 'Towrah-Mizmowr', NULL, 30),
-- Yada Yahowah (9 volumes)
(4, 2, 1, 'Bare''syth-Beginning', NULL, 10),
(5, 2, 2, '''Adam-Story of Man', NULL, 20),
(6, 2, 3, 'Beyth-In the Family', NULL, 30),
(7, 2, 4, 'Miqra''ey-Invitations', NULL, 40),
(8, 2, 5, 'Qatsyr-Harvests', NULL, 50),
(9, 2, 6, 'Mow''ed-Appointments', NULL, 60),
(10, 2, 7, 'Shanah-Years', NULL, 70),
(11, 2, 8, '''Azab-Separation', NULL, 80),
(12, 2, 9, '''Eth Tsarh-Time of Trouble', NULL, 90),
-- Observations (5 volumes)
(13, 3, 1, 'Perspective', NULL, 10),
(14, 3, 2, 'Covenant', NULL, 20),
(15, 3, 3, 'Growing', NULL, 30),
(16, 3, 4, 'Teaching', NULL, 40),
(17, 3, 5, 'Understanding', NULL, 50),
-- Coming Home (3 volumes)
(18, 4, 1, 'Qowl-A Voice', NULL, 10),
(19, 4, 2, 'Mashyach-Messiah', NULL, 20),
(20, 4, 3, 'Dowd-Beloved', NULL, 30),
-- Babel (3 volumes)
(21, 5, 1, 'Chywah-Beast', NULL, 10),
(22, 5, 2, 'Tow''ebah-Abominable', NULL, 20),
(23, 5, 3, 'Chemah-Venemous', NULL, 30),
-- Twistianity (5 volumes)
(24, 6, 1, 'Appaling', NULL, 10),
(25, 6, 2, 'Towrahless', NULL, 20),
(26, 6, 3, 'Devil''s Advocate', NULL, 30),
(27, 6, 4, 'Incredible', NULL, 40),
(28, 6, 5, 'Foolology', NULL, 50),
-- God Damn Religion (5 volumes)
(29, 7, 1, 'Snake', NULL, 10),
(30, 7, 2, 'Satanic', NULL, 20),
(31, 7, 3, 'Submission', NULL, 30),
(32, 7, 4, 'Slaughter', NULL, 40),
(33, 7, 5, 'Sunnah & Suratun', NULL, 50);

-- ============================================================
-- 6. YY CHAPTERS (~380 chapters)
-- ============================================================
DELIMITER //
CREATE PROCEDURE seed_yy_chapters()
BEGIN
    DECLARE v_volume_key INT;
    DECLARE v_chapter_count INT;
    DECLARE v_ch INT;

    CREATE TEMPORARY TABLE tmp_vol_chapters (
        volume_key INT,
        chapter_count INT
    );

    INSERT INTO tmp_vol_chapters VALUES
        (1, 10), (2, 12), (3, 12),           -- An Intro to God
        (4, 8), (5, 9), (6, 9), (7, 9),      -- Yada Yahowah
        (8, 12), (9, 7), (10, 13),
        (11, 12), (12, 19),
        (13, 12), (14, 12), (15, 14),         -- Observations
        (16, 13), (17, 14),
        (18, 11), (19, 13), (20, 12),         -- Coming Home
        (21, 11), (22, 10), (23, 11),         -- Babel
        (24, 11), (25, 11), (26, 13),         -- Twistianity
        (27, 8), (28, 8),
        (29, 12), (30, 8), (31, 11),          -- God Damn Religion
        (32, 11), (33, 6);

    BEGIN
        DECLARE done INT DEFAULT FALSE;
        DECLARE cur CURSOR FOR SELECT volume_key, chapter_count FROM tmp_vol_chapters ORDER BY volume_key;
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

        OPEN cur;
        read_loop: LOOP
            FETCH cur INTO v_volume_key, v_chapter_count;
            IF done THEN
                LEAVE read_loop;
            END IF;

            SET v_ch = 1;
            WHILE v_ch <= v_chapter_count DO
                INSERT INTO yy_chapter (yy_volume_key, yy_chapter_number, yy_chapter_sort)
                VALUES (v_volume_key, v_ch, v_ch * 10);
                SET v_ch = v_ch + 1;
            END WHILE;
        END LOOP;
        CLOSE cur;
    END;

    DROP TEMPORARY TABLE tmp_vol_chapters;

    -- Special chapters for "Sunnah & Suratun" (volume 33)
    -- Chapter 7 = Afterword
    INSERT INTO yy_chapter (yy_volume_key, yy_chapter_number, yy_chapter_name, yy_chapter_sort)
    VALUES (33, 7, 'Afterword', 70);
    -- Chapter 8 = Total Appendix
    INSERT INTO yy_chapter (yy_volume_key, yy_chapter_number, yy_chapter_name, yy_chapter_sort)
    VALUES (33, 8, 'Total Appendix', 80);

    -- Bibliography chapters for all 5 "God Damn Religion" volumes
    -- Snake (vol 29): 12 regular + Bibliography = ch 13
    INSERT INTO yy_chapter (yy_volume_key, yy_chapter_number, yy_chapter_name, yy_chapter_sort)
    VALUES (29, 13, 'Bibliography', 130);
    -- Satanic (vol 30): 8 regular + Bibliography = ch 9
    INSERT INTO yy_chapter (yy_volume_key, yy_chapter_number, yy_chapter_name, yy_chapter_sort)
    VALUES (30, 9, 'Bibliography', 90);
    -- Submission (vol 31): 11 regular + Bibliography = ch 12
    INSERT INTO yy_chapter (yy_volume_key, yy_chapter_number, yy_chapter_name, yy_chapter_sort)
    VALUES (31, 12, 'Bibliography', 120);
    -- Slaughter (vol 32): 11 regular + Bibliography = ch 12
    INSERT INTO yy_chapter (yy_volume_key, yy_chapter_number, yy_chapter_name, yy_chapter_sort)
    VALUES (32, 12, 'Bibliography', 120);
    -- Sunnah & Suratun (vol 33): 8 (incl Afterword + Total Appendix) + Bibliography = ch 9
    INSERT INTO yy_chapter (yy_volume_key, yy_chapter_number, yy_chapter_name, yy_chapter_sort)
    VALUES (33, 9, 'Bibliography', 90);
END //
DELIMITER ;

CALL seed_yy_chapters();
DROP PROCEDURE IF EXISTS seed_yy_chapters;

-- ============================================================
-- 7. USERS (password hashes set by PHP entrypoint)
-- ============================================================
INSERT INTO yy_user (yy_user_key, yy_user_code, yy_user_pass, yy_user_name_full) VALUES
(1, 'admin', NULL, 'Administrator'),
(2, 'joep', NULL, 'Joe P');
