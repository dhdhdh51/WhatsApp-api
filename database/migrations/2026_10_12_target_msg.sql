-- Migration: morning WhatsApp target message (run once on existing installs)
SET NAMES utf8mb4;
ALTER TABLE targets ADD COLUMN IF NOT EXISTS notified_at DATETIME NULL COMMENT 'last WhatsApp target message queued' AFTER updated_at;
ALTER TABLE templates MODIFY type ENUM('form','reminder','final_reminder','welcome','target') NOT NULL;
ALTER TABLE message_queue MODIFY kind ENUM('form','reminder','final_reminder','manual_reminder','welcome','target','summary','escalation') NOT NULL;
INSERT IGNORE INTO templates (type, variant_no, body, is_active) VALUES
('target', 1, '🎯 Namaste {name} ji!\n\n*{month} ka aapka target* (ab tak / target):\n{target_lines}\n\nMahine ke {days_left} din bache hain. Shaam ko roz ki report bhejna na bhoolein 🙏', 1);
