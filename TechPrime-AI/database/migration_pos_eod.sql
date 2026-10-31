-- ============================================================
-- Migration: Cashier End-of-Day closing (cash count / reconciliation)
-- Target: PostgreSQL (Supabase) — the database the app actually uses.
-- Safe to re-run (IF NOT EXISTS). Runs as one transaction.
--
-- One row per cashier per business day (Asia/Manila date). "expected_*" is a
-- snapshot of the system totals when the cashier saved the closing; "counted_*"
-- is what the cashier counted (cash drawer) or read off the card terminal /
-- GCash / Maya app. Difference = counted - expected (negative = short).
-- Expected cash already includes the opening fund (change fund in the drawer).
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS pos_eod_reports (
    id             SERIAL PRIMARY KEY,
    cashier_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    cashier_name   VARCHAR(201) NOT NULL,              -- snapshot for printed reports / audit
    business_date  DATE NOT NULL,                      -- Manila calendar day the sales belong to
    sales_count    INTEGER NOT NULL DEFAULT 0 CHECK (sales_count >= 0),
    voided_count   INTEGER NOT NULL DEFAULT 0 CHECK (voided_count >= 0),
    units_sold     INTEGER NOT NULL DEFAULT 0 CHECK (units_sold >= 0),
    gross_sales    NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (gross_sales >= 0),
    opening_fund   NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (opening_fund >= 0),
    expected_cash  NUMERIC(12,2) NOT NULL DEFAULT 0,
    counted_cash   NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (counted_cash >= 0),
    expected_gcash NUMERIC(12,2) NOT NULL DEFAULT 0,
    counted_gcash  NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (counted_gcash >= 0),
    expected_maya  NUMERIC(12,2) NOT NULL DEFAULT 0,
    counted_maya   NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (counted_maya >= 0),
    expected_card  NUMERIC(12,2) NOT NULL DEFAULT 0,
    counted_card   NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (counted_card >= 0),
    notes          VARCHAR(500),
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- UTC, like the other POS tables
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pos_eod_one_per_day UNIQUE (cashier_id, business_date)
);
CREATE INDEX IF NOT EXISTS idx_pos_eod_date ON pos_eod_reports (business_date DESC);

-- Same as the other POS tables: block Supabase REST access; the app connects as "postgres".
ALTER TABLE pos_eod_reports ENABLE ROW LEVEL SECURITY;

COMMIT;
