-- Pick exactly which BCs are invited to a meeting (audience = 'selected'). Additive.
CREATE TABLE IF NOT EXISTS meeting_invites (
  meeting_id INT UNSIGNED NOT NULL,
  bc_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (meeting_id, bc_id),
  KEY idx_mi_bc (bc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
