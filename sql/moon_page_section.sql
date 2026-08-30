-- The /moon app as a page-system page: one custom section holding the body,
-- with its stylesheet and scripts loaded from /css and /js.
INSERT INTO yy_page (page_code, page_title, page_label, page_heading,
                     page_meta_description, page_toolbar, page_header_sort,
                     page_footer_col, page_footer_sort, page_active_flag)
VALUES ('moon', 'Moon Visibility', 'Moon', 'Moon Visibility',
        'Where the moon is, how much of it is lit, and whether the renewed sliver can be seen — from the Temple Mount or any location on earth.',
        1, 0, 0, 0, true)
ON CONFLICT (page_code) DO NOTHING;

INSERT INTO yy_section (page_key, section_type, section_sort, section_label,
                        section_config, section_active_flag)
SELECT p.page_key, 'custom', 0, 'Moon Visibility app',
       pg_read_file('/tmp/moon-section.json')::jsonb, true
  FROM yy_page p
 WHERE p.page_code = 'moon'
   AND NOT EXISTS (SELECT 1 FROM yy_section s
                    WHERE s.page_key = p.page_key AND s.section_type = 'custom');
