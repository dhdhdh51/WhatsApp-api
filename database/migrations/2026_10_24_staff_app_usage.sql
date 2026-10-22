-- Migration: app adoption / version tracking for staff (CBC, supervisor, admin),
-- mirroring what bcs already has, so the panel can show who is logged in,
-- who logged out / removed the app, and which app version each one runs.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS app_last_seen  DATETIME NULL COMMENT 'last mobile app request',
  ADD COLUMN IF NOT EXISTS app_first_seen DATETIME NULL COMMENT 'first successful mobile app login' AFTER app_last_seen,
  ADD COLUMN IF NOT EXISTS app_version    VARCHAR(30) NULL COMMENT 'last reported BC Report app version' AFTER app_first_seen;

-- Backfill from tokens already issued. Version shows up after their next app request.
UPDATE users u
JOIN (
  SELECT user_id, MIN(created_at) first_seen, MAX(COALESCE(last_used_at, created_at)) last_seen
  FROM cbc_app_tokens GROUP BY user_id
) t ON t.user_id = u.id
SET u.app_first_seen = COALESCE(u.app_first_seen, t.first_seen),
    u.app_last_seen = GREATEST(COALESCE(u.app_last_seen, '1000-01-01'), t.last_seen);

-- The APK appends "BCReportApp/<version>" to the END of the user agent, but we stored only the
-- first 100-120 chars, so the version token was always cut off and app_version stayed NULL.
-- Widen the columns so the whole UA (and therefore the version) survives.
ALTER TABLE app_tokens     MODIFY COLUMN device VARCHAR(255) NULL;
ALTER TABLE cbc_app_tokens MODIFY COLUMN device VARCHAR(255) NULL;
