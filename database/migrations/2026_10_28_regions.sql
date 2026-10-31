-- Regions group many branches. A BC Supervisor (users.role = supervisor) owns one region.
-- Hierarchy is Admin then Region then many Branches then BCs and CBCs. Additive, safe to re-run.
CREATE TABLE IF NOT EXISTS regions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_region_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE branches ADD COLUMN IF NOT EXISTS region_id INT UNSIGNED NULL AFTER name;
ALTER TABLE users    ADD COLUMN IF NOT EXISTS region_id INT UNSIGNED NULL AFTER role;
