-- Migration: CBC / Supervisor roles + APY/JJBY/SBY offline fields (run once on existing installs)
--   mysql -u bc_report -p bc_report < database/migrations/2026_10_12_cbc_offline.sql
SET NAMES utf8mb4;
ALTER TABLE users MODIFY role ENUM('admin','supervisor','manager','cbc') NOT NULL DEFAULT 'manager';
ALTER TABLE bcs ADD COLUMN IF NOT EXISTS cbc_id INT UNSIGNED NULL COMMENT 'CBC user who follows up this BC' AFTER branch_id;
ALTER TABLE bcs ADD INDEX IF NOT EXISTS idx_bc_cbc (cbc_id);
ALTER TABLE bcs ADD CONSTRAINT fk_bc_cbc FOREIGN KEY IF NOT EXISTS (cbc_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE reports
  ADD COLUMN IF NOT EXISTS apy_off INT UNSIGNED NOT NULL DEFAULT 0 AFTER pmjdy,
  ADD COLUMN IF NOT EXISTS pmjjby_off INT UNSIGNED NOT NULL DEFAULT 0 AFTER apy_off,
  ADD COLUMN IF NOT EXISTS pmsby_off INT UNSIGNED NOT NULL DEFAULT 0 AFTER pmjjby_off;
ALTER TABLE report_versions
  ADD COLUMN IF NOT EXISTS apy_off INT UNSIGNED NOT NULL DEFAULT 0 AFTER pmjdy,
  ADD COLUMN IF NOT EXISTS pmjjby_off INT UNSIGNED NOT NULL DEFAULT 0 AFTER apy_off,
  ADD COLUMN IF NOT EXISTS pmsby_off INT UNSIGNED NOT NULL DEFAULT 0 AFTER pmjjby_off;
ALTER TABLE pending_confirmations
  ADD COLUMN IF NOT EXISTS apy_off INT UNSIGNED NOT NULL DEFAULT 0 AFTER pmjdy,
  ADD COLUMN IF NOT EXISTS pmjjby_off INT UNSIGNED NOT NULL DEFAULT 0 AFTER apy_off,
  ADD COLUMN IF NOT EXISTS pmsby_off INT UNSIGNED NOT NULL DEFAULT 0 AFTER pmjjby_off;
ALTER TABLE targets
  ADD COLUMN IF NOT EXISTS apy_off_target INT UNSIGNED NOT NULL DEFAULT 0 AFTER pmjdy_target,
  ADD COLUMN IF NOT EXISTS pmjjby_off_target INT UNSIGNED NOT NULL DEFAULT 0 AFTER apy_off_target,
  ADD COLUMN IF NOT EXISTS pmsby_off_target INT UNSIGNED NOT NULL DEFAULT 0 AFTER pmjjby_off_target;
-- welcome message: show the offline lines in the example
UPDATE templates SET body = REPLACE(body, 'PMJDY: 5\n3', 'PMJDY: 5\nAPY OFFLINE: 0\nJJBY OFFLINE: 0\nSBY OFFLINE: 0\n3')
 WHERE type = 'welcome' AND body NOT LIKE '%OFFLINE%';
UPDATE templates SET body = REPLACE(body, 'sirf 4 number bhejein – 2 1 3 5 (kram: APY, PMJJBY, PMSBY, PMJDY)', 'sirf 4 number bhejein – 2 1 3 5 (kram: APY, PMJJBY, PMSBY, PMJDY). Offline bhi ho to 7 number: 2 1 3 5 0 1 0')
 WHERE type = 'welcome';
