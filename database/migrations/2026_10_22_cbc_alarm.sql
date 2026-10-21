-- CBC alarm test ("ring now") support: a poll sequence + a pending test flag
-- per CBC device. Additive columns only.

ALTER TABLE cbc_app_tokens
  ADD COLUMN poll_seq  INT UNSIGNED NOT NULL DEFAULT 1 AFTER fcm_token,
  ADD COLUMN test_mode VARCHAR(8) NULL AFTER poll_seq;
