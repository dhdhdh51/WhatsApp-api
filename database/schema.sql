-- BC Daily Report – database schema (MySQL 8 / MariaDB 10.6+)
-- Import:  mysql -u bc_report -p bc_report < database/schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------ org structure
CREATE TABLE IF NOT EXISTS branches (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  area        VARCHAR(120) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branch_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(120) NOT NULL,
  username         VARCHAR(60)  NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  role             ENUM('admin','manager') NOT NULL DEFAULT 'manager',
  phone            CHAR(10) NULL COMMENT '10-digit mobile for WhatsApp summaries',
  receive_summary  TINYINT(1) NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at    DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_branches (
  user_id    INT UNSIGNED NOT NULL,
  branch_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, branch_id),
  KEY idx_ub_branch (branch_id),
  CONSTRAINT fk_ub_user   FOREIGN KEY (user_id)   REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_ub_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bcs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bc_code       VARCHAR(40)  NOT NULL COMMENT 'BC ID',
  name          VARCHAR(120) NOT NULL,
  phone         CHAR(10)     NOT NULL COMMENT '10-digit Indian mobile, sent as 91XXXXXXXXXX@c.us',
  wa_lid        VARCHAR(64)  NULL COMMENT 'hidden WhatsApp id (xxx@lid), learnt automatically',
  branch_id     INT UNSIGNED NULL,
  block         VARCHAR(120) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  opted_out     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'sent STOP – no reminders',
  opted_out_at  DATETIME NULL,
  welcome_sent_at DATETIME NULL COMMENT 'welcome / onboarding message delivered',
  notes         VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bc_code (bc_code),
  UNIQUE KEY uq_bc_phone (phone),
  UNIQUE KEY uq_bc_lid (wa_lid),
  KEY idx_bc_branch (branch_id),
  KEY idx_bc_active (is_active),
  CONSTRAINT fk_bc_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ calendar
CREATE TABLE IF NOT EXISTS holidays (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  holiday_date  DATE NOT NULL,
  title         VARCHAR(120) NOT NULL,
  branch_id     INT UNSIGNED NULL COMMENT 'NULL = all branches',
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_holiday_date (holiday_date),
  CONSTRAINT fk_holiday_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bc_leaves (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bc_id       INT UNSIGNED NOT NULL,
  from_date   DATE NOT NULL,
  to_date     DATE NOT NULL,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_leave_bc (bc_id, from_date, to_date),
  CONSTRAINT fk_leave_bc FOREIGN KEY (bc_id) REFERENCES bcs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ reports
CREATE TABLE IF NOT EXISTS reports (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bc_id          INT UNSIGNED NOT NULL,
  report_date    DATE NOT NULL,
  apy            INT UNSIGNED NOT NULL DEFAULT 0,
  pmjjby         INT UNSIGNED NOT NULL DEFAULT 0,
  pmsby          INT UNSIGNED NOT NULL DEFAULT 0,
  pmjdy          INT UNSIGNED NOT NULL DEFAULT 0,
  blank_fields   VARCHAR(60) NULL COMMENT 'fields left blank (counted as 0)',
  source         ENUM('whatsapp','admin') NOT NULL DEFAULT 'whatsapp',
  version        INT UNSIGNED NOT NULL DEFAULT 1,
  wa_message_id  VARCHAR(128) NULL,
  submitted_at   DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL,
  updated_by     INT UNSIGNED NULL COMMENT 'admin user for manual edits',
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_bc_date (bc_id, report_date),
  KEY idx_report_date (report_date),
  CONSTRAINT fk_report_bc FOREIGN KEY (bc_id) REFERENCES bcs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- every submission / edit is kept here (audit trail of report values)
CREATE TABLE IF NOT EXISTS report_versions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id      INT UNSIGNED NULL,
  bc_id          INT UNSIGNED NOT NULL,
  report_date    DATE NOT NULL,
  version        INT UNSIGNED NOT NULL,
  apy            INT UNSIGNED NOT NULL DEFAULT 0,
  pmjjby         INT UNSIGNED NOT NULL DEFAULT 0,
  pmsby          INT UNSIGNED NOT NULL DEFAULT 0,
  pmjdy          INT UNSIGNED NOT NULL DEFAULT 0,
  source         ENUM('whatsapp','admin') NOT NULL,
  changed_by     INT UNSIGNED NULL,
  reason         VARCHAR(255) NULL,
  raw_text       TEXT NULL,
  wa_message_id  VARCHAR(128) NULL,
  created_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_rv_report (report_id),
  KEY idx_rv_bc_date (bc_id, report_date),
  CONSTRAINT fk_rv_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE SET NULL,
  CONSTRAINT fk_rv_bc FOREIGN KEY (bc_id) REFERENCES bcs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- unusually high values wait here until the BC replies HAAN
CREATE TABLE IF NOT EXISTS pending_confirmations (
  bc_id          INT UNSIGNED NOT NULL,
  report_date    DATE NOT NULL,
  apy            INT UNSIGNED NOT NULL DEFAULT 0,
  pmjjby         INT UNSIGNED NOT NULL DEFAULT 0,
  pmsby          INT UNSIGNED NOT NULL DEFAULT 0,
  pmjdy          INT UNSIGNED NOT NULL DEFAULT 0,
  blank_fields   VARCHAR(60) NULL,
  raw_text       TEXT NULL,
  wa_message_id  VARCHAR(128) NULL,
  note           VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL,
  expires_at     DATETIME NOT NULL,
  PRIMARY KEY (bc_id),
  CONSTRAINT fk_pc_bc FOREIGN KEY (bc_id) REFERENCES bcs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS targets (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bc_id          INT UNSIGNED NOT NULL,
  month          DATE NOT NULL COMMENT 'first day of month',
  apy_target     INT UNSIGNED NOT NULL DEFAULT 0,
  pmjjby_target  INT UNSIGNED NOT NULL DEFAULT 0,
  pmsby_target   INT UNSIGNED NOT NULL DEFAULT 0,
  pmjdy_target   INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by     INT UNSIGNED NULL,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_target_bc_month (bc_id, month),
  CONSTRAINT fk_target_bc FOREIGN KEY (bc_id) REFERENCES bcs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ messaging
CREATE TABLE IF NOT EXISTS templates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        ENUM('form','reminder','final_reminder','welcome') NOT NULL,
  variant_no  TINYINT UNSIGNED NOT NULL,
  body        TEXT NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_template_variant (type, variant_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_queue (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bc_id          INT UNSIGNED NULL,
  chat_id        VARCHAR(64) NOT NULL,
  body           TEXT NOT NULL,
  kind           ENUM('form','reminder','final_reminder','manual_reminder','welcome','summary','escalation') NOT NULL,
  priority       TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'lower = sent first',
  report_date    DATE NULL,
  status         ENUM('pending','sending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  scheduled_at   DATETIME NOT NULL,
  locked_at      DATETIME NULL,
  sent_at        DATETIME NULL,
  wa_message_id  VARCHAR(128) NULL,
  last_error     VARCHAR(500) NULL,
  dedup_key      VARCHAR(120) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_dedup (dedup_key),
  KEY idx_queue_next (status, priority, scheduled_at),
  KEY idx_queue_bc_date (bc_id, report_date),
  KEY idx_queue_sent (sent_at),
  CONSTRAINT fk_queue_bc FOREIGN KEY (bc_id) REFERENCES bcs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- log of every incoming and outgoing message
CREATE TABLE IF NOT EXISTS messages (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bc_id          INT UNSIGNED NULL,
  direction      ENUM('in','out') NOT NULL,
  chat_id        VARCHAR(64) NOT NULL,
  body           TEXT NULL,
  kind           VARCHAR(30) NOT NULL,
  status         ENUM('received','sent','failed','ignored') NOT NULL,
  wa_message_id  VARCHAR(128) NULL,
  wa_short_id    VARCHAR(80) NULL,
  reply_to_id    VARCHAR(128) NULL,
  queue_id       BIGINT UNSIGNED NULL,
  error          VARCHAR(500) NULL,
  report_date    DATE NULL,
  created_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_msg_dir_waid (direction, wa_message_id),
  KEY idx_msg_short (wa_short_id),
  KEY idx_msg_bc (bc_id, created_at),
  KEY idx_msg_created (created_at),
  CONSTRAINT fk_msg_bc FOREIGN KEY (bc_id) REFERENCES bcs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- messages from numbers that are not (or no longer) in the BC team – Admin inbox
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

-- ------------------------------------------------------------------ system
CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(64) NOT NULL,
  `value`     TEXT NULL,
  updated_at  DATETIME NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduler_runs (
  run_date    DATE NOT NULL,
  job         VARCHAR(40) NOT NULL,
  status      VARCHAR(20) NOT NULL,
  info        VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (run_date, job)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip            VARCHAR(45) NOT NULL,
  username      VARCHAR(60) NOT NULL,
  success       TINYINT(1) NOT NULL,
  attempted_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_la_ip (ip, attempted_at),
  KEY idx_la_user (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(60) NOT NULL,
  entity      VARCHAR(40) NOT NULL,
  entity_id   BIGINT NULL,
  details     TEXT NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity, entity_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------------ required defaults
INSERT IGNORE INTO settings (`key`, `value`, updated_at) VALUES
  ('form_time', '18:00', NOW()),
  ('reminder_times', '20:00', NOW()),
  ('cutoff_time', '23:59', NOW()),
  ('summary_time', '21:00', NOW()),
  ('escalation_time', '10:00', NOW()),
  ('escalation_days', '2', NOW()),
  ('send_summary', '1', NOW()),
  ('send_escalation', '1', NOW()),
  ('admin_phones', '', NOW()),
  ('weekly_off_days', '', NOW()),
  ('daily_limit', '300', NOW()),
  ('warmup_enabled', '1', NOW()),
  ('warmup_start_date', '', NOW()),
  ('warmup_days', '7', NOW()),
  ('warmup_daily_limit', '40', NOW()),
  ('min_delay_sec', '8', NOW()),
  ('max_delay_sec', '20', NOW()),
  ('typing_min_sec', '1', NOW()),
  ('typing_max_sec', '3', NOW()),
  ('max_retries', '2', NOW()),
  ('max_value', '500', NOW()),
  ('high_value_threshold', '50', NOW()),
  ('unknown_reply_cooldown_min', '10', NOW()),
  ('queue_paused', '0', NOW());

-- Message templates – {form} is replaced by the blank form, never write scheme names outside {form}.
INSERT IGNORE INTO templates (type, variant_no, body, is_active) VALUES
('form', 1, '{form}\n\n👆 Is message ko copy karke numbers bharein aur bhej dein. Kuch nahi kiya to 0 likhein.', 1),
('form', 2, 'Namaste {name} ji 🙏\n\n{form}\n\n👆 Is message ko copy karke numbers bharein aur bhej dein. Kuch nahi kiya to 0 likhein.', 1),
('form', 3, '{form}\n\n👆 Har line ke aage aaj ka number likhein aur bhej dein. Kuch nahi kiya to 0 likhein.', 1),
('form', 4, '🌇 Aaj ki report ka samay ho gaya.\n\n{form}\n\n👆 Is message ko copy karke numbers bharein aur bhej dein. Kuch nahi kiya to 0 likhein.', 1),
('reminder', 1, '⏰ {name} ji, aaj ki report abhi baaki hai.\n\n{form}\n\n👆 Is message ko copy karke numbers bharein aur bhej dein. Kuch nahi kiya to 0 likhein.', 1),
('reminder', 2, '🔔 Yaad dilana tha – {date} ki report nahi mili.\n\n{form}\n\n👆 Copy karke numbers bharein aur bhej dein. Kuch nahi kiya to 0 likhein.', 1),
('reminder', 3, '{name} ji, report pending hai 🙏\n\n{form}\n\n👆 Is message ko copy karke numbers bharein aur bhej dein. Kuch nahi kiya to 0 likhein.', 1),
('final_reminder', 1, '⚠️ {name} ji, aakhri reminder! Report {cutoff} tak bhej dein.\n\n{form}\n\n👆 Is message ko copy karke numbers bharein aur bhej dein. Kuch nahi kiya to 0 likhein.', 1),
('final_reminder', 2, '⏳ Aaj ki report abhi tak nahi aayi. Kripya {cutoff} se pehle bhejein.\n\n{form}\n\n👆 Copy karke numbers bharein aur bhej dein. Kuch nahi kiya to 0 likhein.', 1),
('final_reminder', 3, '🚨 Final reminder – {date}\n\n{form}\n\n👆 Is message ko copy karke numbers bharein aur bhej dein. Kuch nahi kiya to 0 likhein.', 1);

-- Welcome message sent to a BC when they are added (scheme names allowed here, it is not a form).
INSERT IGNORE INTO templates (type, variant_no, body, is_active) VALUES
('welcome', 1, '🙏 Namaste {name} ji!\n\nAapko *BC Daily Report* system mein jod diya gaya hai ✅\nBC ID: {bc_code}\n\n📌 *Kaise kaam karega*\n1️⃣ Roz {form_time} baje isi number se aapko ek form aayega.\n2️⃣ Form copy karein, har line ke aage aaj ka number likhein aur bhej dein:\nAPY: 2\nPMJJBY: 1\nPMSBY: 3\nPMJDY: 5\n3️⃣ Kuch nahi kiya to 0 likhein.\n4️⃣ Chhota tareeka: sirf 4 number bhejein – 2 1 3 5 (kram: APY, PMJJBY, PMSBY, PMJDY)\n5️⃣ Galti ho jaye to {cutoff} tak form dobara bhej dein – report update ho jayegi.\n\n🔤 *Commands*\nFORM – aaj ka form mangwayein\nSTATUS – aaj ki report dekhein\nHELP – madad\n\n👉 Kripya is number ko apne phone mein save kar lein. Dhanyavad 🙏', 1);
