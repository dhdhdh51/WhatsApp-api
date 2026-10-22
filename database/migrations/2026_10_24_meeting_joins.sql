-- Track who actually joined a meeting so absentees can be re-notified. Additive.
CREATE TABLE IF NOT EXISTS meeting_joins (
  meeting_id INT UNSIGNED NOT NULL,
  actor      VARCHAR(24) NOT NULL,       -- 'bc-12' / 'user-3'
  joined_at  DATETIME NOT NULL,
  PRIMARY KEY (meeting_id, actor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE meetings
  ADD COLUMN last_nudge_at DATETIME NULL,
  ADD COLUMN nudges INT UNSIGNED NOT NULL DEFAULT 0;
