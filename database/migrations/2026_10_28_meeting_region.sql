-- Region-scoped meetings: a Region Manager (supervisor) broadcasts / video-meets only their own
-- region's BCs and staff. region_id NULL = org-wide (Admin). Additive, safe to re-run.
ALTER TABLE meetings ADD COLUMN IF NOT EXISTS region_id INT UNSIGNED NULL AFTER branch_id;
