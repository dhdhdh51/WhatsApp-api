-- Track who created a user, so a BC Supervisor can manage the CBC logins they created
-- (in addition to CBCs who follow up a BC in the supervisor's region). Additive, safe to re-run.
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_by INT UNSIGNED NULL AFTER role;
