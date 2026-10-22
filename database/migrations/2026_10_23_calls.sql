-- In-app 1:1 WebRTC calls (self-hosted; signaling over PHP/MySQL). Additive.
CREATE TABLE IF NOT EXISTS calls (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_type  VARCHAR(8) NOT NULL,          -- 'bc' | 'cbc'
  from_id    INT UNSIGNED NOT NULL,
  from_name  VARCHAR(120) NOT NULL,
  to_type    VARCHAR(8) NOT NULL,
  to_id      INT UNSIGNED NOT NULL,
  mode       VARCHAR(8) NOT NULL DEFAULT 'video',
  status     VARCHAR(10) NOT NULL DEFAULT 'ringing', -- ringing|accepted|rejected|ended|missed
  room       VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_calls_to (to_type, to_id, status),
  KEY idx_calls_from (from_type, from_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_signals (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  call_id    INT UNSIGNED NOT NULL,
  sender     VARCHAR(8) NOT NULL,          -- 'caller' | 'callee'
  data       MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sig_call (call_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
