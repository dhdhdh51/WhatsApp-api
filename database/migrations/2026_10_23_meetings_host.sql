-- CBC-hosted meetings + voice mode + 1:1 calls. Additive.
ALTER TABLE meetings MODIFY audience VARCHAR(16) NOT NULL DEFAULT 'all';
ALTER TABLE meetings
  ADD COLUMN mode         VARCHAR(8)   NOT NULL DEFAULT 'video' AFTER audience,
  ADD COLUMN host_cbc_id  INT UNSIGNED NULL AFTER created_by,
  ADD COLUMN target_bc_id INT UNSIGNED NULL AFTER host_cbc_id;
-- audience values now also include: 'cbc_bcs' (all BCs of host_cbc_id), 'one_bc' (just target_bc_id)
