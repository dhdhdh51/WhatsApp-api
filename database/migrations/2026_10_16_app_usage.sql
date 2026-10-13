-- Migration: app adoption / version tracking for the admin panel
ALTER TABLE bcs
  ADD COLUMN IF NOT EXISTS app_first_seen DATETIME NULL COMMENT 'first successful mobile app login' AFTER app_last_seen,
  ADD COLUMN IF NOT EXISTS app_version VARCHAR(30) NULL COMMENT 'last reported BC Report app version' AFTER app_first_seen;

-- Backfill existing app users from already-issued tokens. Version appears after their next app/API request.
UPDATE bcs b
JOIN (
  SELECT bc_id, MIN(created_at) first_seen, MAX(COALESCE(last_used_at, created_at)) last_seen
  FROM app_tokens GROUP BY bc_id
) t ON t.bc_id = b.id
SET b.app_first_seen = COALESCE(b.app_first_seen, t.first_seen),
    b.app_last_seen = GREATEST(COALESCE(b.app_last_seen, '1000-01-01'), t.last_seen);
