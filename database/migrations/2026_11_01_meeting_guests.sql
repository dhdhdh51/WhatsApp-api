-- Guest links: an Admin / BC Supervisor invites an outside officer into one live meeting without a login.
-- A link works only while its meeting is live (meetings.is_active = 1) and until the host closes it.
-- Only the SHA-256 of the link key is stored. Additive, safe to re-run.
CREATE TABLE IF NOT EXISTS meeting_guest_links (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id  INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL,
  revoked_at  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_guest_link_hash (token_hash),
  KEY idx_guest_link_meeting (meeting_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per person who opened a link and typed a name. Their meeting identity is "guest-<id>".
CREATE TABLE IF NOT EXISTS meeting_guests (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  link_id     INT UNSIGNED NOT NULL,
  meeting_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(60) NOT NULL,
  ip          VARCHAR(45) NOT NULL,
  joined_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_guest_link (link_id),
  KEY idx_guest_meeting (meeting_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
