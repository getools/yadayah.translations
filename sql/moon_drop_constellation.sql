-- The page no longer names constellations, so the cache no longer stores them.
-- The rev trigger lists its columns explicitly, so it has to be replaced in the
-- same script — otherwise the next INSERT would fail on a column that is gone.
BEGIN;

ALTER TABLE yy_moon_event     DROP COLUMN IF EXISTS moon_event_constellation;
ALTER TABLE yy_moon_event_rev DROP COLUMN IF EXISTS moon_event_constellation;

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

COMMIT;
