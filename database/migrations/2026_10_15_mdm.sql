-- Migration: reports which BC devices are enrolled as Android Device Owner (MDM)
ALTER TABLE app_tokens ADD COLUMN IF NOT EXISTS mdm_owner TINYINT(1) NOT NULL DEFAULT 0 AFTER fcm_token;
