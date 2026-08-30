-- Temple Mount is the reference vantage point for the calendar, so it
-- replaces the generic Jerusalem seed and becomes the default selection.
UPDATE yy_moon_location
   SET moon_location_code      = 'temple-mount',
       moon_location_name      = 'Temple Mount, Jerusalem, Israel',
       moon_location_lat       = 31.778297,     -- 31°46'41.87"N
       moon_location_lon       = 35.235494,     -- 35°14'07.78"E
       moon_location_elevation_m = 740,
       moon_location_timezone  = 'Asia/Jerusalem',
       moon_location_default_flag = true,
       moon_location_sort      = 10,
       moon_location_note      = 'Reference vantage point for first-crescent visibility.'
 WHERE moon_location_code = 'jerusalem';

UPDATE yy_moon_location SET moon_location_default_flag = false
 WHERE moon_location_code <> 'temple-mount';

UPDATE yy_moon_location
   SET moon_location_name = 'Mount Karkom, Negev, Israel', moon_location_sort = 20
 WHERE moon_location_code = 'mount-sinai';

UPDATE yy_moon_location SET moon_location_sort = 60 WHERE moon_location_code = 'greenwich';

INSERT INTO yy_moon_location
    (moon_location_code, moon_location_name, moon_location_lat, moon_location_lon,
     moon_location_elevation_m, moon_location_timezone, moon_location_shared_flag,
     moon_location_default_flag, moon_location_sort)
VALUES
    ('mount-nebo',   'Mount Nebo, Jordan',            31.768000,  35.725000, 710, 'Asia/Amman',        true, false, 30),
    ('babylon',      'Babylon (Hillah), Iraq',        32.542000,  44.421000,  34, 'Asia/Baghdad',      true, false, 40),
    ('mount-ararat', 'Mount Ararat, Turkey',          39.702000,  44.298000, 5137,'Europe/Istanbul',   true, false, 50),
    ('new-york',     'New York, USA',                 40.712800, -74.006000,  10, 'America/New_York',  true, false, 70),
    ('chicago',      'Chicago, USA',                  41.878100, -87.629800, 181, 'America/Chicago',   true, false, 75),
    ('denver',       'Denver, USA',                   39.739200, -104.990300,1609,'America/Denver',    true, false, 80),
    ('los-angeles',  'Los Angeles, USA',              34.052200, -118.243700,  71,'America/Los_Angeles',true,false, 85),
    ('sydney',       'Sydney, Australia',            -33.868800,  151.209300,  19,'Australia/Sydney',  true, false, 90)
ON CONFLICT (moon_location_code) DO NOTHING;
