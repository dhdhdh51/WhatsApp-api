-- Welcome message: add the app download link (only if not there yet)
SET NAMES utf8mb4;
UPDATE templates SET body = CONCAT(body, '\n\n📱 *Naya BC Report App* – report, lakshya aur chat ke liye:\n{app_link}')
 WHERE type = 'welcome' AND body NOT LIKE '%{app_link}%';
