-- ─────────────────────────────────────────────────────────────────────────
-- Generic job-status push: a reusable NOTIFY trigger so admin job dashboards
-- can consume Postgres LISTEN/NOTIFY (via PDO pgsqlGetNotify + admin-job-sse.php)
-- instead of polling list_jobs on a setInterval. Created 2026-07-10 as part of
-- the poll → push migration.
--
--   yy_job_notify(channel, key_col, status_col, progress_col)
--     emits pg_notify(channel, {key, status, progress}) on the NEW row.
--
-- Attached to each job table via a pair of triggers: AFTER INSERT (always) and
-- AFTER UPDATE guarded by WHEN (status or progress actually changed) so an
-- unrelated column write never storms the channel. WHEN can't reference OLD on
-- an INSERT-capable trigger, hence the INSERT/UPDATE split.
-- ─────────────────────────────────────────────────────────────────────────

CREATE OR REPLACE FUNCTION yy_job_notify() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  j jsonb := to_jsonb(NEW);
BEGIN
  PERFORM pg_notify(TG_ARGV[0], json_build_object(
    'key',      j ->> TG_ARGV[1],
    'status',   j ->> TG_ARGV[2],
    'progress', j ->> TG_ARGV[3]
  )::text);
  RETURN NEW;
END;
$$;

-- ── yy_i2v_job (admin-ai video) → channel ai_i2v_job ──────────────────────
DROP TRIGGER IF EXISTS trg_yy_i2v_job_notify_ins ON yy_i2v_job;
CREATE TRIGGER trg_yy_i2v_job_notify_ins AFTER INSERT ON yy_i2v_job
  FOR EACH ROW
  EXECUTE FUNCTION yy_job_notify('ai_i2v_job', 'i2v_job_key', 'i2v_job_status', 'i2v_job_progress');

DROP TRIGGER IF EXISTS trg_yy_i2v_job_notify_upd ON yy_i2v_job;
CREATE TRIGGER trg_yy_i2v_job_notify_upd AFTER UPDATE ON yy_i2v_job
  FOR EACH ROW
  WHEN (OLD.i2v_job_status   IS DISTINCT FROM NEW.i2v_job_status
     OR OLD.i2v_job_progress IS DISTINCT FROM NEW.i2v_job_progress)
  EXECUTE FUNCTION yy_job_notify('ai_i2v_job', 'i2v_job_key', 'i2v_job_status', 'i2v_job_progress');

-- ── yy_t2i_job (admin-ai image) → channel ai_t2i_job ──────────────────────
DROP TRIGGER IF EXISTS trg_yy_t2i_job_notify_ins ON yy_t2i_job;
CREATE TRIGGER trg_yy_t2i_job_notify_ins AFTER INSERT ON yy_t2i_job
  FOR EACH ROW
  EXECUTE FUNCTION yy_job_notify('ai_t2i_job', 't2i_job_key', 't2i_job_status', 't2i_job_progress');

DROP TRIGGER IF EXISTS trg_yy_t2i_job_notify_upd ON yy_t2i_job;
CREATE TRIGGER trg_yy_t2i_job_notify_upd AFTER UPDATE ON yy_t2i_job
  FOR EACH ROW
  WHEN (OLD.t2i_job_status   IS DISTINCT FROM NEW.t2i_job_status
     OR OLD.t2i_job_progress IS DISTINCT FROM NEW.t2i_job_progress)
  EXECUTE FUNCTION yy_job_notify('ai_t2i_job', 't2i_job_key', 't2i_job_status', 't2i_job_progress');

-- ── yy_feed_item_transcript_job (admin-feeds gen) → channel feed_transcript_job
DROP TRIGGER IF EXISTS trg_yy_feed_transcript_job_notify_ins ON yy_feed_item_transcript_job;
CREATE TRIGGER trg_yy_feed_transcript_job_notify_ins AFTER INSERT ON yy_feed_item_transcript_job
  FOR EACH ROW
  EXECUTE FUNCTION yy_job_notify('feed_transcript_job', 'feed_item_transcript_job_key', 'job_status', 'job_progress');

DROP TRIGGER IF EXISTS trg_yy_feed_transcript_job_notify_upd ON yy_feed_item_transcript_job;
CREATE TRIGGER trg_yy_feed_transcript_job_notify_upd AFTER UPDATE ON yy_feed_item_transcript_job
  FOR EACH ROW
  WHEN (OLD.job_status   IS DISTINCT FROM NEW.job_status
     OR OLD.job_progress IS DISTINCT FROM NEW.job_progress)
  EXECUTE FUNCTION yy_job_notify('feed_transcript_job', 'feed_item_transcript_job_key', 'job_status', 'job_progress');
