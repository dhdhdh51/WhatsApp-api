-- CBC app login: lets CBC users log into the SAME mobile app (phone + their
-- panel password) and get an alarm when any of their BCs has not reported.
-- Purely additive: a new table, no change to existing tables/data.

CREATE TABLE IF NOT EXISTS cbc_app_tokens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL COMMENT 'users.id with role = cbc',
  token_hash    CHAR(64) NOT NULL,
  device        VARCHAR(120) NULL,
  fcm_token     VARCHAR(255) NULL COMMENT 'Firebase push token of this device',
  created_at    DATETIME NOT NULL,
  last_used_at  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cbc_app_token (token_hash),
  KEY idx_cbc_app_token_user (user_id),
  CONSTRAINT fk_cbc_app_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
