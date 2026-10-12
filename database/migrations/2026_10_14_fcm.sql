-- Migration: Firebase push token per app login (safe to re-run)
ALTER TABLE app_tokens ADD COLUMN IF NOT EXISTS fcm_token VARCHAR(255) NULL AFTER device;
