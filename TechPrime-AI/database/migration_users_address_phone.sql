-- PostgreSQL (Supabase). Structured Philippine delivery address + phone on users.
-- Additive and safe to run more than once.
--
-- users.address keeps the full formatted address ("Unit, Street, Barangay, City, Province ZIP")
-- so pages that only read users.address / orders.shipping_address keep working.
-- Province / city / barangay names come from PSGC (assets/data/psgc, built by
-- scripts/build_psgc_data.php).
-- Replaces database/migration_users_phone.sql, which was MySQL-only and never ran on Postgres.

BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS phone            VARCHAR(30),
    ADD COLUMN IF NOT EXISTS address_street   TEXT,         -- house/lot/block no. + street (required in app)
    ADD COLUMN IF NOT EXISTS address_unit     TEXT,         -- unit, floor, building, subdivision (optional)
    ADD COLUMN IF NOT EXISTS address_barangay TEXT,
    ADD COLUMN IF NOT EXISTS address_city     TEXT,         -- city or municipality
    ADD COLUMN IF NOT EXISTS address_province TEXT,         -- "Metro Manila" for NCR
    ADD COLUMN IF NOT EXISTS address_zip      VARCHAR(4);   -- PH ZIP codes are 4 digits

COMMIT;
