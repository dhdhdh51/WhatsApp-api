-- Migration: welcome message for newly added BCs (run once on existing installs)
--   mysql -u bc_report -p bc_report < database/migrations/2026_10_11_welcome.sql
SET NAMES utf8mb4;
ALTER TABLE bcs ADD COLUMN IF NOT EXISTS welcome_sent_at DATETIME NULL COMMENT 'welcome / onboarding message delivered' AFTER opted_out_at;
ALTER TABLE templates MODIFY type ENUM('form','reminder','final_reminder','welcome') NOT NULL;
ALTER TABLE message_queue MODIFY kind ENUM('form','reminder','final_reminder','manual_reminder','welcome','summary','escalation') NOT NULL;
INSERT IGNORE INTO templates (type, variant_no, body, is_active) VALUES
('welcome', 1, '🙏 Namaste {name} ji!\n\nAapko *BC Daily Report* system mein jod diya gaya hai ✅\nBC ID: {bc_code}\n\n📌 *Kaise kaam karega*\n1️⃣ Roz {form_time} baje isi number se aapko ek form aayega.\n2️⃣ Form copy karein, har line ke aage aaj ka number likhein aur bhej dein:\nAPY: 2\nPMJJBY: 1\nPMSBY: 3\nPMJDY: 5\n3️⃣ Kuch nahi kiya to 0 likhein.\n4️⃣ Chhota tareeka: sirf 4 number bhejein – 2 1 3 5 (kram: APY, PMJJBY, PMSBY, PMJDY)\n5️⃣ Galti ho jaye to {cutoff} tak form dobara bhej dein – report update ho jayegi.\n\n🔤 *Commands*\nFORM – aaj ka form mangwayein\nSTATUS – aaj ki report dekhein\nHELP – madad\n\n👉 Kripya is number ko apne phone mein save kar lein. Dhanyavad 🙏', 1);
