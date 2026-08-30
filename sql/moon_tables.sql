-- ════════════════════════════════════════════════════════════════════════
--  Moon Visibility  —  schema
--
--  yy_moon_location  saved observing sites (GPS + timezone)
--  yy_moon_sighting  logged crescent observations (what was actually seen)
--  yy_moon_event     cached lunation events (phases, perigee, apogee)
--
--  Column prefix = table name minus the yy_ prefix, matching
--  yy_feed_item_link / yy_menu_item. Every table carries the standard
--  revision trio and a *_rev history table fed by a BEFORE trigger.
-- ════════════════════════════════════════════════════════════════════════

-- ── Locations ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS yy_moon_location (
    moon_location_key               serial PRIMARY KEY,
    moon_location_code              varchar(120) NOT NULL UNIQUE,
    moon_location_name              varchar(250) NOT NULL,
    moon_location_lat               numeric(9,6) NOT NULL,
    moon_location_lon               numeric(9,6) NOT NULL,
    moon_location_elevation_m       numeric(8,2) DEFAULT 0,
    moon_location_timezone          varchar(80),
    moon_location_user_key          integer DEFAULT 0,
    moon_location_shared_flag       boolean DEFAULT false,
    moon_location_default_flag      boolean DEFAULT false,
    moon_location_sort              smallint NOT NULL DEFAULT 0,
    moon_location_note              text,
    moon_location_active_flag       boolean DEFAULT true,
    moon_location_revision_dtime    timestamptz DEFAULT now(),
    moon_location_revision_user_key integer DEFAULT 0,
    moon_location_revision_num      integer DEFAULT 1
);

CREATE TABLE IF NOT EXISTS yy_moon_location_rev (
    moon_location_revision_id           bigserial PRIMARY KEY,
    moon_location_revision_delete_dtime timestamptz,
    moon_location_key                   integer,
    moon_location_code                  varchar(120),
    moon_location_name                  varchar(250),
    moon_location_lat                   numeric(9,6),
    moon_location_lon                   numeric(9,6),
    moon_location_elevation_m           numeric(8,2),
    moon_location_timezone              varchar(80),
    moon_location_user_key              integer,
    moon_location_shared_flag           boolean,
    moon_location_default_flag          boolean,
    moon_location_sort                  smallint,
    moon_location_note                  text,
    moon_location_active_flag           boolean,
    moon_location_revision_dtime        timestamptz,
    moon_location_revision_user_key     integer,
    moon_location_revision_num          integer
);

CREATE INDEX IF NOT EXISTS idx_yy_moon_location_user
    ON yy_moon_location (moon_location_user_key)
    WHERE moon_location_active_flag;
CREATE INDEX IF NOT EXISTS idx_yy_moon_location_rev_key
    ON yy_moon_location_rev (moon_location_key);

-- ── Sightings ───────────────────────────────────────────────────────────
-- One row per logged look at the sky. Everything from *_illumination down
-- is the computed prediction captured at log time, so a later engine change
-- can be scored against what was actually observed.
CREATE TABLE IF NOT EXISTS yy_moon_sighting (
    moon_sighting_key               serial PRIMARY KEY,
    moon_location_key               integer REFERENCES yy_moon_location(moon_location_key) ON DELETE SET NULL,
    moon_sighting_dtime             timestamptz NOT NULL,
    moon_sighting_lat               numeric(9,6),
    moon_sighting_lon               numeric(9,6),
    moon_sighting_elevation_m       numeric(8,2),
    moon_sighting_timezone          varchar(80),
    moon_sighting_seen_flag         boolean,
    moon_sighting_method            varchar(40),
    moon_sighting_lunation          integer,
    moon_sighting_age_hours         numeric(8,3),
    moon_sighting_illumination      numeric(8,5),
    moon_sighting_altitude          numeric(7,3),
    moon_sighting_azimuth           numeric(8,3),
    moon_sighting_sun_altitude      numeric(7,3),
    moon_sighting_elongation        numeric(7,3),
    moon_sighting_arcv              numeric(7,3),
    moon_sighting_daz               numeric(7,3),
    moon_sighting_q_value           numeric(8,4),
    moon_sighting_criterion         varchar(40),
    moon_sighting_note              text,
    moon_sighting_user_key          integer DEFAULT 0,
    moon_sighting_active_flag       boolean DEFAULT true,
    moon_sighting_revision_dtime    timestamptz DEFAULT now(),
    moon_sighting_revision_user_key integer DEFAULT 0,
    moon_sighting_revision_num      integer DEFAULT 1
);

CREATE TABLE IF NOT EXISTS yy_moon_sighting_rev (
    moon_sighting_revision_id           bigserial PRIMARY KEY,
    moon_sighting_revision_delete_dtime timestamptz,
    moon_sighting_key                   integer,
    moon_location_key                   integer,
    moon_sighting_dtime                 timestamptz,
    moon_sighting_lat                   numeric(9,6),
    moon_sighting_lon                   numeric(9,6),
    moon_sighting_elevation_m           numeric(8,2),
    moon_sighting_timezone              varchar(80),
    moon_sighting_seen_flag             boolean,
    moon_sighting_method                varchar(40),
    moon_sighting_lunation              integer,
    moon_sighting_age_hours             numeric(8,3),
    moon_sighting_illumination          numeric(8,5),
    moon_sighting_altitude              numeric(7,3),
    moon_sighting_azimuth               numeric(8,3),
    moon_sighting_sun_altitude          numeric(7,3),
    moon_sighting_elongation            numeric(7,3),
    moon_sighting_arcv                  numeric(7,3),
    moon_sighting_daz                   numeric(7,3),
    moon_sighting_q_value               numeric(8,4),
    moon_sighting_criterion             varchar(40),
    moon_sighting_note                  text,
    moon_sighting_user_key              integer,
    moon_sighting_active_flag           boolean,
    moon_sighting_revision_dtime        timestamptz,
    moon_sighting_revision_user_key     integer,
    moon_sighting_revision_num          integer
);

CREATE INDEX IF NOT EXISTS idx_yy_moon_sighting_dtime
    ON yy_moon_sighting (moon_sighting_dtime DESC)
    WHERE moon_sighting_active_flag;
CREATE INDEX IF NOT EXISTS idx_yy_moon_sighting_lunation
    ON yy_moon_sighting (moon_sighting_lunation);
CREATE INDEX IF NOT EXISTS idx_yy_moon_sighting_rev_key
    ON yy_moon_sighting_rev (moon_sighting_key);

-- ── Lunation events (phase + apsis cache) ───────────────────────────────
-- moon_event_cycle is the dedupe key: Brown lunation number for the four
-- phases, anomalistic-month index for perigee/apogee. One row per event.
CREATE TABLE IF NOT EXISTS yy_moon_event (
    moon_event_key                  serial PRIMARY KEY,
    moon_event_type                 varchar(20) NOT NULL,
    moon_event_cycle                integer NOT NULL,
    moon_event_dtime                timestamptz NOT NULL,
    moon_event_lunation             integer,
    moon_event_distance_km          numeric(12,3),
    moon_event_illumination         numeric(8,5),
    moon_event_diameter_arcmin      numeric(8,4),
    moon_event_ecliptic_lon         numeric(9,5),
    moon_event_source               varchar(40) DEFAULT 'computed',
    moon_event_active_flag          boolean DEFAULT true,
    moon_event_revision_dtime       timestamptz DEFAULT now(),
    moon_event_revision_user_key    integer DEFAULT 0,
    moon_event_revision_num         integer DEFAULT 1,
    CONSTRAINT yy_moon_event_type_cycle_key UNIQUE (moon_event_type, moon_event_cycle)
);

CREATE TABLE IF NOT EXISTS yy_moon_event_rev (
    moon_event_revision_id           bigserial PRIMARY KEY,
    moon_event_revision_delete_dtime timestamptz,
    moon_event_key                   integer,
    moon_event_type                  varchar(20),
    moon_event_cycle                 integer,
    moon_event_dtime                 timestamptz,
    moon_event_lunation              integer,
    moon_event_distance_km           numeric(12,3),
    moon_event_illumination          numeric(8,5),
    moon_event_diameter_arcmin       numeric(8,4),
    moon_event_ecliptic_lon          numeric(9,5),
    moon_event_source                varchar(40),
    moon_event_active_flag           boolean,
    moon_event_revision_dtime        timestamptz,
    moon_event_revision_user_key     integer,
    moon_event_revision_num          integer
);

CREATE INDEX IF NOT EXISTS idx_yy_moon_event_dtime
    ON yy_moon_event (moon_event_dtime);
CREATE INDEX IF NOT EXISTS idx_yy_moon_event_rev_key
    ON yy_moon_event_rev (moon_event_key);

-- ════════════════════════════════════════════════════════════════════════
--  Revision triggers. Explicit column lists (never NEW.*) so a later
--  ALTER TABLE on one side cannot silently shift values into the wrong
--  column. Insert is wrapped so a rev failure warns instead of killing
--  the write.
-- ════════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION trg_yy_moon_location_rev() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    OLD.moon_location_revision_num   := COALESCE(OLD.moon_location_revision_num, 0) + 1;
    OLD.moon_location_revision_dtime := NOW();
    BEGIN
      INSERT INTO yy_moon_location_rev (
        moon_location_revision_delete_dtime, moon_location_key, moon_location_code,
        moon_location_name, moon_location_lat, moon_location_lon, moon_location_elevation_m,
        moon_location_timezone, moon_location_user_key, moon_location_shared_flag,
        moon_location_default_flag, moon_location_sort, moon_location_note,
        moon_location_active_flag, moon_location_revision_dtime,
        moon_location_revision_user_key, moon_location_revision_num)
      VALUES (
        NOW(), OLD.moon_location_key, OLD.moon_location_code,
        OLD.moon_location_name, OLD.moon_location_lat, OLD.moon_location_lon, OLD.moon_location_elevation_m,
        OLD.moon_location_timezone, OLD.moon_location_user_key, OLD.moon_location_shared_flag,
        OLD.moon_location_default_flag, OLD.moon_location_sort, OLD.moon_location_note,
        OLD.moon_location_active_flag, OLD.moon_location_revision_dtime,
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
      moon_location_name, moon_location_lat, moon_location_lon, moon_location_elevation_m,
      moon_location_timezone, moon_location_user_key, moon_location_shared_flag,
      moon_location_default_flag, moon_location_sort, moon_location_note,
      moon_location_active_flag, moon_location_revision_dtime,
      moon_location_revision_user_key, moon_location_revision_num)
    VALUES (
      NULL, NEW.moon_location_key, NEW.moon_location_code,
      NEW.moon_location_name, NEW.moon_location_lat, NEW.moon_location_lon, NEW.moon_location_elevation_m,
      NEW.moon_location_timezone, NEW.moon_location_user_key, NEW.moon_location_shared_flag,
      NEW.moon_location_default_flag, NEW.moon_location_sort, NEW.moon_location_note,
      NEW.moon_location_active_flag, NEW.moon_location_revision_dtime,
      NEW.moon_location_revision_user_key, NEW.moon_location_revision_num);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_yy_moon_location_rev ON yy_moon_location;
CREATE TRIGGER trg_yy_moon_location_rev
    BEFORE INSERT OR UPDATE OR DELETE ON yy_moon_location
    FOR EACH ROW EXECUTE FUNCTION trg_yy_moon_location_rev();


CREATE OR REPLACE FUNCTION trg_yy_moon_sighting_rev() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    OLD.moon_sighting_revision_num   := COALESCE(OLD.moon_sighting_revision_num, 0) + 1;
    OLD.moon_sighting_revision_dtime := NOW();
    BEGIN
      INSERT INTO yy_moon_sighting_rev (
        moon_sighting_revision_delete_dtime, moon_sighting_key, moon_location_key,
        moon_sighting_dtime, moon_sighting_lat, moon_sighting_lon, moon_sighting_elevation_m,
        moon_sighting_timezone, moon_sighting_seen_flag, moon_sighting_method,
        moon_sighting_lunation, moon_sighting_age_hours, moon_sighting_illumination,
        moon_sighting_altitude, moon_sighting_azimuth, moon_sighting_sun_altitude,
        moon_sighting_elongation, moon_sighting_arcv, moon_sighting_daz,
        moon_sighting_q_value, moon_sighting_criterion, moon_sighting_note,
        moon_sighting_user_key, moon_sighting_active_flag, moon_sighting_revision_dtime,
        moon_sighting_revision_user_key, moon_sighting_revision_num)
      VALUES (
        NOW(), OLD.moon_sighting_key, OLD.moon_location_key,
        OLD.moon_sighting_dtime, OLD.moon_sighting_lat, OLD.moon_sighting_lon, OLD.moon_sighting_elevation_m,
        OLD.moon_sighting_timezone, OLD.moon_sighting_seen_flag, OLD.moon_sighting_method,
        OLD.moon_sighting_lunation, OLD.moon_sighting_age_hours, OLD.moon_sighting_illumination,
        OLD.moon_sighting_altitude, OLD.moon_sighting_azimuth, OLD.moon_sighting_sun_altitude,
        OLD.moon_sighting_elongation, OLD.moon_sighting_arcv, OLD.moon_sighting_daz,
        OLD.moon_sighting_q_value, OLD.moon_sighting_criterion, OLD.moon_sighting_note,
        OLD.moon_sighting_user_key, OLD.moon_sighting_active_flag, OLD.moon_sighting_revision_dtime,
        OLD.moon_sighting_revision_user_key, OLD.moon_sighting_revision_num);
    EXCEPTION WHEN OTHERS THEN
      RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
    END;
    RETURN OLD;
  END IF;

  IF TG_OP = 'UPDATE' THEN
    NEW.moon_sighting_revision_num   := COALESCE(OLD.moon_sighting_revision_num, 0) + 1;
    NEW.moon_sighting_revision_dtime := NOW();
  ELSIF TG_OP = 'INSERT' THEN
    IF NEW.moon_sighting_revision_num   IS NULL THEN NEW.moon_sighting_revision_num   := 1;     END IF;
    IF NEW.moon_sighting_revision_dtime IS NULL THEN NEW.moon_sighting_revision_dtime := NOW(); END IF;
  END IF;
  NEW.moon_sighting_revision_user_key := COALESCE(NULLIF(current_setting('app.user_key', true), '')::int, 0);

  BEGIN
    INSERT INTO yy_moon_sighting_rev (
      moon_sighting_revision_delete_dtime, moon_sighting_key, moon_location_key,
      moon_sighting_dtime, moon_sighting_lat, moon_sighting_lon, moon_sighting_elevation_m,
      moon_sighting_timezone, moon_sighting_seen_flag, moon_sighting_method,
      moon_sighting_lunation, moon_sighting_age_hours, moon_sighting_illumination,
      moon_sighting_altitude, moon_sighting_azimuth, moon_sighting_sun_altitude,
      moon_sighting_elongation, moon_sighting_arcv, moon_sighting_daz,
      moon_sighting_q_value, moon_sighting_criterion, moon_sighting_note,
      moon_sighting_user_key, moon_sighting_active_flag, moon_sighting_revision_dtime,
      moon_sighting_revision_user_key, moon_sighting_revision_num)
    VALUES (
      NULL, NEW.moon_sighting_key, NEW.moon_location_key,
      NEW.moon_sighting_dtime, NEW.moon_sighting_lat, NEW.moon_sighting_lon, NEW.moon_sighting_elevation_m,
      NEW.moon_sighting_timezone, NEW.moon_sighting_seen_flag, NEW.moon_sighting_method,
      NEW.moon_sighting_lunation, NEW.moon_sighting_age_hours, NEW.moon_sighting_illumination,
      NEW.moon_sighting_altitude, NEW.moon_sighting_azimuth, NEW.moon_sighting_sun_altitude,
      NEW.moon_sighting_elongation, NEW.moon_sighting_arcv, NEW.moon_sighting_daz,
      NEW.moon_sighting_q_value, NEW.moon_sighting_criterion, NEW.moon_sighting_note,
      NEW.moon_sighting_user_key, NEW.moon_sighting_active_flag, NEW.moon_sighting_revision_dtime,
      NEW.moon_sighting_revision_user_key, NEW.moon_sighting_revision_num);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_yy_moon_sighting_rev ON yy_moon_sighting;
CREATE TRIGGER trg_yy_moon_sighting_rev
    BEFORE INSERT OR UPDATE OR DELETE ON yy_moon_sighting
    FOR EACH ROW EXECUTE FUNCTION trg_yy_moon_sighting_rev();


CREATE OR REPLACE FUNCTION trg_yy_moon_event_rev() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    OLD.moon_event_revision_num   := COALESCE(OLD.moon_event_revision_num, 0) + 1;
    OLD.moon_event_revision_dtime := NOW();
    BEGIN
      INSERT INTO yy_moon_event_rev (
        moon_event_revision_delete_dtime, moon_event_key, moon_event_type, moon_event_cycle,
        moon_event_dtime, moon_event_lunation, moon_event_distance_km, moon_event_illumination,
        moon_event_diameter_arcmin, moon_event_ecliptic_lon,
        moon_event_source, moon_event_active_flag, moon_event_revision_dtime,
        moon_event_revision_user_key, moon_event_revision_num)
      VALUES (
        NOW(), OLD.moon_event_key, OLD.moon_event_type, OLD.moon_event_cycle,
        OLD.moon_event_dtime, OLD.moon_event_lunation, OLD.moon_event_distance_km, OLD.moon_event_illumination,
        OLD.moon_event_diameter_arcmin, OLD.moon_event_ecliptic_lon,
        OLD.moon_event_source, OLD.moon_event_active_flag, OLD.moon_event_revision_dtime,
        OLD.moon_event_revision_user_key, OLD.moon_event_revision_num);
    EXCEPTION WHEN OTHERS THEN
      RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
    END;
    RETURN OLD;
  END IF;

  IF TG_OP = 'UPDATE' THEN
    NEW.moon_event_revision_num   := COALESCE(OLD.moon_event_revision_num, 0) + 1;
    NEW.moon_event_revision_dtime := NOW();
  ELSIF TG_OP = 'INSERT' THEN
    IF NEW.moon_event_revision_num   IS NULL THEN NEW.moon_event_revision_num   := 1;     END IF;
    IF NEW.moon_event_revision_dtime IS NULL THEN NEW.moon_event_revision_dtime := NOW(); END IF;
  END IF;
  NEW.moon_event_revision_user_key := COALESCE(NULLIF(current_setting('app.user_key', true), '')::int, 0);

  BEGIN
    INSERT INTO yy_moon_event_rev (
      moon_event_revision_delete_dtime, moon_event_key, moon_event_type, moon_event_cycle,
      moon_event_dtime, moon_event_lunation, moon_event_distance_km, moon_event_illumination,
      moon_event_diameter_arcmin, moon_event_ecliptic_lon,
      moon_event_source, moon_event_active_flag, moon_event_revision_dtime,
      moon_event_revision_user_key, moon_event_revision_num)
    VALUES (
      NULL, NEW.moon_event_key, NEW.moon_event_type, NEW.moon_event_cycle,
      NEW.moon_event_dtime, NEW.moon_event_lunation, NEW.moon_event_distance_km, NEW.moon_event_illumination,
      NEW.moon_event_diameter_arcmin, NEW.moon_event_ecliptic_lon,
      NEW.moon_event_source, NEW.moon_event_active_flag, NEW.moon_event_revision_dtime,
      NEW.moon_event_revision_user_key, NEW.moon_event_revision_num);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_yy_moon_event_rev ON yy_moon_event;
CREATE TRIGGER trg_yy_moon_event_rev
    BEFORE INSERT OR UPDATE OR DELETE ON yy_moon_event
    FOR EACH ROW EXECUTE FUNCTION trg_yy_moon_event_rev();

-- ── Seed: shared reference sites ────────────────────────────────────────
INSERT INTO yy_moon_location (
    moon_location_code, moon_location_name, moon_location_lat, moon_location_lon,
    moon_location_elevation_m, moon_location_timezone, moon_location_shared_flag,
    moon_location_default_flag, moon_location_sort, moon_location_note)
VALUES
    ('jerusalem', 'Jerusalem, Israel', 31.778000, 35.235000, 754, 'Asia/Jerusalem', true, true, 10,
     'Reference site for first-crescent visibility.'),
    ('mount-sinai', 'Mount Karkom, Negev', 30.320000, 34.750000, 847, 'Asia/Jerusalem', true, false, 20, NULL),
    ('greenwich', 'Greenwich, England', 51.477900, -0.001500, 46, 'Europe/London', true, false, 30, NULL)
ON CONFLICT (moon_location_code) DO NOTHING;
