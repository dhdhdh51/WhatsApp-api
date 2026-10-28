-- Regions: a Region Manager (users.role = 'supervisor') owns one region; a region groups many branches.
-- Admin → Region → (many) Branches → BCs/CBCs. Additive, safe to re-run.
CREATE TABLE IF NOT EXISTS regions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_region_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE branches ADD COLUMN IF NOT EXISTS region_id INT UNSIGNED NULL AFTER name;
ALTER TABLE users    ADD COLUMN IF NOT EXISTS region_id INT UNSIGNED NULL AFTER role;
