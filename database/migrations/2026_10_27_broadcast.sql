-- Live broadcast (town-hall): persistent in-meeting chat + promoted speakers. Additive, safe to re-run.
--
-- A broadcast is a meeting with audience = 'broadcast':
--   * it rings EVERYONE (all active BCs + all active staff),
--   * the audience joins view-only (LiveKit token canPublish = false),
--   * the host can promote a raised hand to speak (row in meeting_speakers).
-- 'broadcast' fits the existing meetings.audience VARCHAR(16), so no ALTER is needed there.

-- Durable group chat for a meeting/broadcast. Unlike the LiveKit data channel this
-- keeps history, reaches view-only viewers, and is readable by people who join late.
CREATE TABLE IF NOT EXISTS meeting_messages (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id INT UNSIGNED NOT NULL,
  actor      VARCHAR(24)  NOT NULL,            -- 'bc-12' / 'user-3'
  name       VARCHAR(80)  NOT NULL,
  is_staff   TINYINT(1)   NOT NULL DEFAULT 0,
  body       VARCHAR(2000) NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_mm_meeting (meeting_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who the host allowed to speak in a broadcast (so publish rights survive a reconnect).
CREATE TABLE IF NOT EXISTS meeting_speakers (
  meeting_id INT UNSIGNED NOT NULL,
  actor      VARCHAR(24)  NOT NULL,            -- 'bc-12' / 'user-3'
  granted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (meeting_id, actor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
