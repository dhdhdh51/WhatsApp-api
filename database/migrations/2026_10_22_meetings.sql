-- Video meetings (Jitsi rooms) for BCs and CBC supervisors. Additive only.
CREATE TABLE IF NOT EXISTS meetings (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title       VARCHAR(160) NOT NULL,
  room        VARCHAR(80)  NOT NULL,
  audience    ENUM('all','bc','cbc','branch') NOT NULL DEFAULT 'all',
  branch_id   INT UNSIGNED NULL,
  created_by  INT UNSIGNED NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at    DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_meet_active (is_active),
  KEY idx_meet_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
