-- Migration: daily morning performance + target message (run once on existing installs)
SET NAMES utf8mb4;
ALTER TABLE targets
  ADD COLUMN IF NOT EXISTS d_apy INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS d_pmjjby INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS d_pmsby INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS d_pmjdy INT UNSIGNED NULL;
ALTER TABLE templates MODIFY type ENUM('form','reminder','final_reminder','welcome','target','morning') NOT NULL;
ALTER TABLE message_queue MODIFY kind ENUM('form','reminder','final_reminder','manual_reminder','welcome','target','morning','summary','escalation') NOT NULL;
INSERT IGNORE INTO templates (type, variant_no, body, is_active) VALUES
('morning', 1, '*प्रिय BCA {name} जी,*\n\n📊 *कल दिनांक {yesterday} को आपके द्वारा किया गया प्रदर्शन:*\n\n- *APY: {y_apy}*\n- *PMJJBY: {y_pmjjby}*\n- *PMSBY: {y_pmsby}*\n- *PMJDY: {y_pmjdy}*\n- APY Offline: {y_apy_off}\n- JJBY Offline: {y_pmjjby_off}\n- SBY Offline: {y_pmsby_off}\n\n🎯 *आज का निर्धारित लक्ष्य:*\n\n- *APY: {t_apy}*\n- *PMJJBY: {t_pmjjby}*\n- *PMSBY: {t_pmsby}*\n- *PMJDY: {t_pmjdy}*\n\nआपसे अपेक्षा है कि आज अपने प्रदर्शन में सुधार करते हुए *निर्धारित लक्ष्य को अनिवार्य रूप से पूरा करें।*\n\n*महत्वपूर्ण निर्देश:* उपरोक्त सभी योजनाओं में नामांकन *ऑनलाइन या ऑफलाइन, किसी भी माध्यम से* किया जा सकता है। अतः किसी भी प्रकार की तकनीकी समस्या या अन्य कारण से नामांकन कार्य बाधित न होने दें और सभी योजनाओं में निर्धारित लक्ष्य पूरा करने का हरसंभव प्रयास करें।\n\nकृपया इस निर्देश को *गंभीरता से लें* तथा आज बेहतर प्रदर्शन सुनिश्चित करें।\n\nसादर,\n*BC Supervisor*\n*Agra Region*', 1);
