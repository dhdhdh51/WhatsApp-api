-- Migration: mobile app (login, notifications, chat). Run once on existing installs; safe to re-run.
SET NAMES utf8mb4;
ALTER TABLE bcs
  ADD COLUMN IF NOT EXISTS app_password_hash VARCHAR(255) NULL AFTER welcome_sent_at,
  ADD COLUMN IF NOT EXISTS app_must_change TINYINT(1) NOT NULL DEFAULT 1 AFTER app_password_hash,
  ADD COLUMN IF NOT EXISTS app_last_seen DATETIME NULL AFTER app_must_change;
ALTER TABLE reports MODIFY source ENUM('whatsapp','admin','app') NOT NULL DEFAULT 'whatsapp';
ALTER TABLE report_versions MODIFY source ENUM('whatsapp','admin','app') NOT NULL;

-- ------------------------------------------------------------------ mobile app
CREATE TABLE IF NOT EXISTS app_tokens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bc_id         INT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  device        VARCHAR(120) NULL,
  created_at    DATETIME NOT NULL,
  last_used_at  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_token (token_hash),
  KEY idx_app_token_bc (bc_id),
  CONSTRAINT fk_app_token_bc FOREIGN KEY (bc_id) REFERENCES bcs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bc_id       INT UNSIGNED NOT NULL,
  kind        VARCHAR(30) NOT NULL,
  title       VARCHAR(150) NOT NULL,
  body        TEXT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_app_notif_bc (bc_id, id),
  CONSTRAINT fk_app_notif_bc FOREIGN KEY (bc_id) REFERENCES bcs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- one chat thread per BC, shared by its CBC, Supervisors and Admins
CREATE TABLE IF NOT EXISTS chat_messages (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bc_id       INT UNSIGNED NOT NULL,
  sender      ENUM('bc','staff') NOT NULL,
  user_id     INT UNSIGNED NULL,
  body        TEXT NOT NULL,
  created_at  DATETIME NOT NULL,
  read_at     DATETIME NULL COMMENT 'read by the other side',
  PRIMARY KEY (id),
  KEY idx_chat_bc (bc_id, id),
  KEY idx_chat_unread (sender, read_at),
  CONSTRAINT fk_chat_bc FOREIGN KEY (bc_id) REFERENCES bcs(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
