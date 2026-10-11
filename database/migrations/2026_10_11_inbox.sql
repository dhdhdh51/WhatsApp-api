-- Migration: Admin inbox for messages from non-team numbers (run once on existing installs)
--   mysql -u bc_report -p bc_report < database/migrations/2026_10_11_inbox.sql
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS inbox_threads (
  chat_id          VARCHAR(64) NOT NULL,
  phone            CHAR(10) NULL,
  name             VARCHAR(120) NULL COMMENT 'WhatsApp profile name',
  bc_id            INT UNSIGNED NULL COMMENT 'set when the sender is an inactive BC',
  message_count    INT UNSIGNED NOT NULL DEFAULT 0,
  unread           INT UNSIGNED NOT NULL DEFAULT 0,
  first_at         DATETIME NOT NULL,
  last_at          DATETIME NOT NULL,
  auto_replied_at  DATETIME NULL,
  archived         TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (chat_id),
  KEY idx_inbox_last (archived, last_at),
  CONSTRAINT fk_inbox_bc FOREIGN KEY (bc_id) REFERENCES bcs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
