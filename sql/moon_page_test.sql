-- The page renderer reads yy_page_test; yy_section carries both keys, so the
-- section written against yy_page also needs its page_test_key.
INSERT INTO yy_page_test (page_test_code, page_test_title, page_test_label,
                          page_test_heading, page_test_meta_description,
                          page_test_active_flag)
VALUES ('moon', 'Moon Visibility', 'Moon', 'Moon Visibility',
        'Where the moon is, how much of it is lit, and whether the renewed sliver can be seen — from the Temple Mount or any location on earth.',
        true)
ON CONFLICT DO NOTHING;

UPDATE yy_section s
   SET page_test_key = t.page_test_key
  FROM yy_page_test t, yy_page p
 WHERE t.page_test_code = 'moon'
   AND p.page_code = 'moon'
   AND s.page_key = p.page_key
   AND s.page_test_key IS NULL;
