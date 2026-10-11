-- Sample / demo data. Import AFTER schema.sql. Do NOT import on a production database
-- unless you want demo BCs. Change the demo passwords immediately (Account page or bin/create-admin.php).
--
--   admin    / Admin@12345     (Admin – sees everything)
--   manager1 / Manager@12345   (Manager – only "Sitapur Main" and "Sitapur Rural")
--   super1   / Super@12345     (BC Supervisor – all BCs, read + follow-up)
--   cbc1     / Cbc@12345       (CBC – only own BCs: BC001, BC002, BC003)
--
-- Phone numbers below are fake placeholders (98765000xx) – replace with real BC numbers.

SET NAMES utf8mb4;

INSERT INTO branches (id, name, area) VALUES
  (1, 'Sitapur Main', 'Sitapur'),
  (2, 'Sitapur Rural', 'Sitapur'),
  (3, 'Lakhimpur', 'Kheri');

INSERT INTO users (id, name, username, password_hash, role, phone, receive_summary, is_active) VALUES
  (1, 'System Admin', 'admin',    '$2y$12$yqs3YhllpVCD10A/FLfNReNof0LmVU4y2XBRjvwYVJGu.CEbwymZa', 'admin',   NULL, 0, 1),
  (2, 'Area Manager', 'manager1', '$2y$12$n.WlB6KzkmXdf6EfWZ.0m.bQbLRyAvNdzxBvluNuTlVX6h34XDygq', 'manager', NULL, 0, 1);

INSERT INTO users (id, name, username, password_hash, role, phone, receive_summary, is_active) VALUES
  (3, 'Suresh (CBC)', 'cbc1',   '$2y$12$XGcJu5.HCtlEQIYmhBUB3ON4jgxo2gCgwyV6xqXRRKyKp5kGOAC/K', 'cbc',        NULL, 0, 1),
  (4, 'BC Supervisor', 'super1', '$2y$12$82PyPegwHg9VmLD8ccvPZuque6SulMWhv2gEbOBhl.nEzIkWdWXPG', 'supervisor', NULL, 0, 1);

INSERT INTO user_branches (user_id, branch_id) VALUES (2, 1), (2, 2);

INSERT INTO bcs (id, bc_code, name, phone, branch_id, block, is_active, opted_out, created_at) VALUES
  (1, 'BC001', 'Ramesh Kumar',  '9876500001', 1, 'Biswan',    1, 0, CURDATE() - INTERVAL 30 DAY),
  (2, 'BC002', 'Sunita Devi',   '9876500002', 1, 'Biswan',    1, 0, CURDATE() - INTERVAL 30 DAY),
  (3, 'BC003', 'Mohd. Arif',    '9876500003', 1, 'Mahmudabad',1, 0, CURDATE() - INTERVAL 30 DAY),
  (4, 'BC004', 'Pooja Singh',   '9876500004', 2, 'Sidhauli',  1, 0, CURDATE() - INTERVAL 30 DAY),
  (5, 'BC005', 'Rajesh Yadav',  '9876500005', 2, 'Sidhauli',  1, 1, CURDATE() - INTERVAL 30 DAY),
  (6, 'BC006', 'Anita Verma',   '9876500006', 3, 'Gola',      1, 0, CURDATE() - INTERVAL 30 DAY),
  (7, 'BC007', 'Vikas Mishra',  '9876500007', 3, 'Gola',      1, 0, CURDATE() - INTERVAL 30 DAY),
  (8, 'BC008', 'Old BC',        '9876500008', 3, 'Mohammdi',  0, 0, CURDATE() - INTERVAL 30 DAY);

UPDATE bcs SET opted_out_at = NOW() WHERE id = 5;
UPDATE bcs SET cbc_id = 3 WHERE id IN (1, 2, 3);

-- reports for the last few days (BC006 and BC007 have missed the last days -> escalation)
INSERT INTO reports (bc_id, report_date, apy, pmjjby, pmsby, pmjdy, source, version, submitted_at, updated_at) VALUES
  (1, CURDATE() - INTERVAL 1 DAY, 2, 1, 3, 5, 'whatsapp', 1, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
  (2, CURDATE() - INTERVAL 1 DAY, 0, 2, 4, 1, 'whatsapp', 1, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
  (3, CURDATE() - INTERVAL 1 DAY, 1, 0, 2, 3, 'whatsapp', 2, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
  (4, CURDATE() - INTERVAL 1 DAY, 3, 1, 1, 6, 'whatsapp', 1, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
  (1, CURDATE() - INTERVAL 2 DAY, 1, 1, 2, 4, 'whatsapp', 1, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY),
  (2, CURDATE() - INTERVAL 2 DAY, 2, 0, 1, 2, 'whatsapp', 1, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY),
  (4, CURDATE() - INTERVAL 2 DAY, 0, 0, 3, 2, 'admin',    2, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY),
  (5, CURDATE() - INTERVAL 2 DAY, 1, 1, 1, 1, 'whatsapp', 1, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY),
  (1, CURDATE() - INTERVAL 3 DAY, 0, 1, 1, 3, 'whatsapp', 1, NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY),
  (3, CURDATE() - INTERVAL 3 DAY, 2, 2, 2, 2, 'whatsapp', 1, NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY),
  (6, CURDATE() - INTERVAL 4 DAY, 1, 0, 0, 2, 'whatsapp', 1, NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY),
  (7, CURDATE() - INTERVAL 4 DAY, 0, 1, 2, 1, 'whatsapp', 1, NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY),
  (1, CURDATE(),                  1, 0, 2, 3, 'whatsapp', 1, NOW(), NOW());

INSERT INTO report_versions (report_id, bc_id, report_date, version, apy, pmjjby, pmsby, pmjdy, source, changed_by, reason, raw_text, created_at)
SELECT id, bc_id, report_date, 1, apy, pmjjby, pmsby, pmjdy, 'whatsapp', NULL, NULL, 'APY: .. (sample)', submitted_at FROM reports;

INSERT INTO report_versions (report_id, bc_id, report_date, version, apy, pmjjby, pmsby, pmjdy, source, changed_by, reason, raw_text, created_at)
SELECT id, bc_id, report_date, 2, apy, pmjjby, pmsby, pmjdy, source, IF(source = 'admin', 1, NULL),
       IF(source = 'admin', 'BC called, corrected numbers', NULL), NULL, updated_at
FROM reports WHERE version = 2;

INSERT INTO holidays (holiday_date, title, branch_id, created_by) VALUES
  (CURDATE() + INTERVAL 10 DAY, 'Sample holiday (all branches)', NULL, 1);

INSERT INTO bc_leaves (bc_id, from_date, to_date, reason, created_by) VALUES
  (3, CURDATE(), CURDATE() + INTERVAL 1 DAY, 'Family function', 1);

INSERT INTO targets (bc_id, month, apy_target, pmjjby_target, pmsby_target, pmjdy_target, updated_by)
SELECT id, DATE_FORMAT(CURDATE(), '%Y-%m-01'), 20, 15, 30, 40, 1 FROM bcs WHERE is_active = 1;

UPDATE reports SET apy_off = 1, pmsby_off = 2 WHERE bc_id = 1 AND report_date = CURDATE() - INTERVAL 1 DAY;
