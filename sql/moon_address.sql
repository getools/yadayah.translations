-- Address lookup: a standardised postal address per saved site, and a cache of
-- geocoder answers so the same search is never asked upstream twice.
BEGIN;

ALTER TABLE yy_moon_location     ADD COLUMN IF NOT EXISTS moon_location_address text;
ALTER TABLE yy_moon_location_rev ADD COLUMN IF NOT EXISTS moon_location_address text;

-- The rev trigger names its columns explicitly, so it has to learn the new one.
CREATE OR REPLACE FUNCTION trg_yy_moon_location_rev() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    OLD.moon_location_revision_num   := COALESCE(OLD.moon_location_revision_num, 0) + 1;
    OLD.moon_location_revision_dtime := NOW();
    BEGIN
      INSERT INTO yy_moon_location_rev (
        moon_location_revision_delete_dtime, moon_location_key, moon_location_code,
        moon_location_name, moon_location_address, moon_location_lat, moon_location_lon,
        moon_location_elevation_m, moon_location_timezone, moon_location_user_key,
        moon_location_shared_flag, moon_location_default_flag, moon_location_sort,
        moon_location_note, moon_location_active_flag, moon_location_revision_dtime,
        moon_location_revision_user_key, moon_location_revision_num)
      VALUES (
        NOW(), OLD.moon_location_key, OLD.moon_location_code,
        OLD.moon_location_name, OLD.moon_location_address, OLD.moon_location_lat, OLD.moon_location_lon,
        OLD.moon_location_elevation_m, OLD.moon_location_timezone, OLD.moon_location_user_key,
        OLD.moon_location_shared_flag, OLD.moon_location_default_flag, OLD.moon_location_sort,
        OLD.moon_location_note, OLD.moon_location_active_flag, OLD.moon_location_revision_dtime,
        OLD.moon_location_revision_user_key, OLD.moon_location_revision_num);
    EXCEPTION WHEN OTHERS THEN
      RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
    END;
    RETURN OLD;
  END IF;

  IF TG_OP = 'UPDATE' THEN
    NEW.moon_location_revision_num   := COALESCE(OLD.moon_location_revision_num, 0) + 1;
    NEW.moon_location_revision_dtime := NOW();
  ELSIF TG_OP = 'INSERT' THEN
    IF NEW.moon_location_revision_num   IS NULL THEN NEW.moon_location_revision_num   := 1;     END IF;
    IF NEW.moon_location_revision_dtime IS NULL THEN NEW.moon_location_revision_dtime := NOW(); END IF;
  END IF;
  NEW.moon_location_revision_user_key := COALESCE(NULLIF(current_setting('app.user_key', true), '')::int, 0);

  BEGIN
    INSERT INTO yy_moon_location_rev (
      moon_location_revision_delete_dtime, moon_location_key, moon_location_code,
      moon_location_name, moon_location_address, moon_location_lat, moon_location_lon,
      moon_location_elevation_m, moon_location_timezone, moon_location_user_key,
      moon_location_shared_flag, moon_location_default_flag, moon_location_sort,
      moon_location_note, moon_location_active_flag, moon_location_revision_dtime,
      moon_location_revision_user_key, moon_location_revision_num)
    VALUES (
      NULL, NEW.moon_location_key, NEW.moon_location_code,
      NEW.moon_location_name, NEW.moon_location_address, NEW.moon_location_lat, NEW.moon_location_lon,
      NEW.moon_location_elevation_m, NEW.moon_location_timezone, NEW.moon_location_user_key,
      NEW.moon_location_shared_flag, NEW.moon_location_default_flag, NEW.moon_location_sort,
      NEW.moon_location_note, NEW.moon_location_active_flag, NEW.moon_location_revision_dtime,
      NEW.moon_location_revision_user_key, NEW.moon_location_revision_num);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$$;

UPDATE yy_moon_location SET moon_location_address = v.addr
  FROM (VALUES
    ('temple-mount', 'Temple Mount, Old City, Jerusalem, Israel'),
    ('mount-sinai',  'Mount Karkom, Negev Desert, Southern District, Israel'),
    ('mount-nebo',   'Mount Nebo, Madaba Governorate, Jordan'),
    ('babylon',      'Babylon, Hillah, Babil Governorate, Iraq'),
    ('mount-ararat', 'Mount Ararat, Doğubayazıt, Ağrı Province, Türkiye'),
    ('greenwich',    'Royal Observatory, Blackheath Avenue, Greenwich, London SE10 8XJ, United Kingdom'),
    ('new-york',     'New York, New York, United States'),
    ('chicago',      'Chicago, Cook County, Illinois, United States'),
    ('denver',       'Denver, Colorado, United States'),
    ('los-angeles',  'Los Angeles, California, United States'),
    ('sydney',       'Sydney, New South Wales, Australia')
  ) AS v(code, addr)
 WHERE yy_moon_location.moon_location_code = v.code;

-- ── Geocoder cache ──────────────────────────────────────────────────────
-- Insert-only by design: no hit counter, because an UPDATE per lookup would
-- write a revision row per lookup.
CREATE TABLE IF NOT EXISTS yy_moon_geocode (
    moon_geocode_key                serial PRIMARY KEY,
    moon_geocode_query              varchar(300) NOT NULL UNIQUE,
    moon_geocode_result             jsonb,
    moon_geocode_source             varchar(40) DEFAULT 'nominatim',
    moon_geocode_active_flag        boolean DEFAULT true,
    moon_geocode_revision_dtime     timestamptz DEFAULT now(),
    moon_geocode_revision_user_key  integer DEFAULT 0,
    moon_geocode_revision_num       integer DEFAULT 1
);

CREATE TABLE IF NOT EXISTS yy_moon_geocode_rev (
    moon_geocode_revision_id           bigserial PRIMARY KEY,
    moon_geocode_revision_delete_dtime timestamptz,
    moon_geocode_key                   integer,
    moon_geocode_query                 varchar(300),
    moon_geocode_result                jsonb,
    moon_geocode_source                varchar(40),
    moon_geocode_active_flag           boolean,
    moon_geocode_revision_dtime        timestamptz,
    moon_geocode_revision_user_key     integer,
    moon_geocode_revision_num          integer
);

CREATE INDEX IF NOT EXISTS idx_yy_moon_geocode_rev_key
    ON yy_moon_geocode_rev (moon_geocode_key);

CREATE OR REPLACE FUNCTION trg_yy_moon_geocode_rev() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    OLD.moon_geocode_revision_num   := COALESCE(OLD.moon_geocode_revision_num, 0) + 1;
    OLD.moon_geocode_revision_dtime := NOW();
    BEGIN
      INSERT INTO yy_moon_geocode_rev (
        moon_geocode_revision_delete_dtime, moon_geocode_key, moon_geocode_query,
        moon_geocode_result, moon_geocode_source, moon_geocode_active_flag,
        moon_geocode_revision_dtime, moon_geocode_revision_user_key, moon_geocode_revision_num)
      VALUES (
        NOW(), OLD.moon_geocode_key, OLD.moon_geocode_query,
        OLD.moon_geocode_result, OLD.moon_geocode_source, OLD.moon_geocode_active_flag,
        OLD.moon_geocode_revision_dtime, OLD.moon_geocode_revision_user_key, OLD.moon_geocode_revision_num);
    EXCEPTION WHEN OTHERS THEN
      RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
    END;
    RETURN OLD;
  END IF;

  IF TG_OP = 'UPDATE' THEN
    NEW.moon_geocode_revision_num   := COALESCE(OLD.moon_geocode_revision_num, 0) + 1;
    NEW.moon_geocode_revision_dtime := NOW();
  ELSIF TG_OP = 'INSERT' THEN
    IF NEW.moon_geocode_revision_num   IS NULL THEN NEW.moon_geocode_revision_num   := 1;     END IF;
    IF NEW.moon_geocode_revision_dtime IS NULL THEN NEW.moon_geocode_revision_dtime := NOW(); END IF;
  END IF;
  NEW.moon_geocode_revision_user_key := COALESCE(NULLIF(current_setting('app.user_key', true), '')::int, 0);

  BEGIN
    INSERT INTO yy_moon_geocode_rev (
      moon_geocode_revision_delete_dtime, moon_geocode_key, moon_geocode_query,
      moon_geocode_result, moon_geocode_source, moon_geocode_active_flag,
      moon_geocode_revision_dtime, moon_geocode_revision_user_key, moon_geocode_revision_num)
    VALUES (
      NULL, NEW.moon_geocode_key, NEW.moon_geocode_query,
      NEW.moon_geocode_result, NEW.moon_geocode_source, NEW.moon_geocode_active_flag,
      NEW.moon_geocode_revision_dtime, NEW.moon_geocode_revision_user_key, NEW.moon_geocode_revision_num);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_yy_moon_geocode_rev ON yy_moon_geocode;
CREATE TRIGGER trg_yy_moon_geocode_rev
    BEFORE INSERT OR UPDATE OR DELETE ON yy_moon_geocode
    FOR EACH ROW EXECUTE FUNCTION trg_yy_moon_geocode_rev();

COMMIT;
