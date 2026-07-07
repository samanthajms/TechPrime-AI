-- ============================================================
-- Migration: Replace MFA code with Google Authenticator TOTP
-- Run this on your existing ias_ecommerce database
-- ============================================================

USE ias_ecommerce;

-- Add TOTP columns
ALTER TABLE users
    ADD COLUMN totp_secret  VARCHAR(64) NULL    AFTER failed_attempts,
    ADD COLUMN totp_enabled TINYINT     DEFAULT 0 AFTER totp_secret;

-- Reset all users so they go through TOTP setup on next login
-- (existing users will be prompted to scan the QR on their next login)
UPDATE users SET totp_secret = NULL, totp_enabled = 0;
