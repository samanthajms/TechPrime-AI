-- ============================================================
-- Migration: Cashier role + POS (checkout / receiving by barcode)
-- Target: PostgreSQL (Supabase) — the database the app actually uses.
-- Safe to re-run (IF NOT EXISTS / guarded DO blocks). Runs as one transaction.
-- ============================================================

BEGIN;

-- 1) Cashier role (users.role is TEXT + CHECK constraint)
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role = ANY (ARRAY[
    'admin', 'seller', 'client', 'courier', 'retail_officer', 'technician', 'inventory_custodian', 'cashier'
]::text[]));

-- 2) UPC/EAN barcode, separate from products.sku (EasyPC MSKU item codes).
--    Stored normalized: EAN-8 as 8 digits; UPC-A / UPC-E / EAN-13 as 13-digit GTIN.
ALTER TABLE products ADD COLUMN IF NOT EXISTS barcode VARCHAR(13);
CREATE UNIQUE INDEX IF NOT EXISTS idx_products_barcode_unique ON products (barcode) WHERE barcode IS NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'products_barcode_format') THEN
        ALTER TABLE products ADD CONSTRAINT products_barcode_format
            CHECK (barcode IS NULL OR barcode ~ '^([0-9]{8}|[0-9]{13})$');
    END IF;
    -- Database-level guarantee that stock can never go negative (0 rows violated this when written).
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'products_stock_nonnegative') THEN
        ALTER TABLE products ADD CONSTRAINT products_stock_nonnegative CHECK (stock >= 0);
    END IF;
END $$;

-- 3) Sales / invoices (walk-in POS sales; online orders stay in orders/order_items)
CREATE SEQUENCE IF NOT EXISTS pos_invoice_seq;

CREATE TABLE IF NOT EXISTS pos_sales (
    id              SERIAL PRIMARY KEY,
    invoice_no      VARCHAR(32) NOT NULL UNIQUE DEFAULT (
                        'INV-' || to_char(now() AT TIME ZONE 'Asia/Manila', 'YYYYMMDD')
                        || '-' || lpad(nextval('pos_invoice_seq')::text, 6, '0')),
    client_ref      UUID NOT NULL UNIQUE,              -- idempotency key sent by the POS screen
    cashier_id      INTEGER REFERENCES users(id) ON DELETE SET NULL,
    cashier_name    VARCHAR(201) NOT NULL,             -- snapshot for receipts / audit
    subtotal        NUMERIC(12,2) NOT NULL CHECK (subtotal >= 0),
    vatable_sales   NUMERIC(12,2) NOT NULL,            -- prices are VAT-inclusive (12%)
    vat_amount      NUMERIC(12,2) NOT NULL,
    total           NUMERIC(12,2) NOT NULL CHECK (total >= 0),
    payment_method  VARCHAR(16) NOT NULL CHECK (payment_method IN ('cash', 'gcash', 'maya', 'card')),
    amount_tendered NUMERIC(12,2),
    change_due      NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (change_due >= 0),
    payment_ref     VARCHAR(64),
    status          VARCHAR(16) NOT NULL DEFAULT 'completed' CHECK (status IN ('completed', 'voided')),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pos_sales_cash_tendered CHECK (payment_method <> 'cash' OR amount_tendered >= total),
    CONSTRAINT pos_sales_noncash_ref   CHECK (payment_method = 'cash' OR COALESCE(payment_ref, '') <> '')
);
CREATE INDEX IF NOT EXISTS idx_pos_sales_cashier_created ON pos_sales (cashier_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pos_sales_created ON pos_sales (created_at DESC);

CREATE TABLE IF NOT EXISTS pos_sale_items (
    id           SERIAL PRIMARY KEY,
    sale_id      INTEGER NOT NULL REFERENCES pos_sales(id) ON DELETE CASCADE,
    product_id   INTEGER REFERENCES products(id) ON DELETE SET NULL,
    product_name VARCHAR(255) NOT NULL,
    barcode      VARCHAR(13),
    unit_price   NUMERIC(12,2) NOT NULL CHECK (unit_price >= 0),
    quantity     INTEGER NOT NULL CHECK (quantity > 0),
    line_total   NUMERIC(12,2) NOT NULL CHECK (line_total >= 0)
);
CREATE INDEX IF NOT EXISTS idx_pos_sale_items_sale ON pos_sale_items (sale_id);

-- 4) Stock movement ledger (stock_in = receiving, stock_out = POS sale)
CREATE TABLE IF NOT EXISTS stock_movements (
    id            SERIAL PRIMARY KEY,
    product_id    INTEGER REFERENCES products(id) ON DELETE SET NULL,
    product_name  VARCHAR(255) NOT NULL,
    movement_type VARCHAR(16) NOT NULL CHECK (movement_type IN ('stock_in', 'stock_out')),
    quantity      INTEGER NOT NULL CHECK (quantity > 0),
    stock_before  INTEGER NOT NULL CHECK (stock_before >= 0),
    stock_after   INTEGER NOT NULL CHECK (stock_after >= 0),
    sale_id       INTEGER REFERENCES pos_sales(id) ON DELETE SET NULL,
    supplier      VARCHAR(150),
    reference_no  VARCHAR(64),
    user_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT stock_movements_math CHECK (
        (movement_type = 'stock_in'  AND stock_after = stock_before + quantity) OR
        (movement_type = 'stock_out' AND stock_after = stock_before - quantity))
);
CREATE INDEX IF NOT EXISTS idx_stock_movements_product ON stock_movements (product_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_stock_movements_user ON stock_movements (user_id, created_at DESC);

-- Supabase exposes the public schema over its REST API; RLS with no policies blocks
-- anon/authenticated access. The app connects as "postgres" (BYPASSRLS) and is unaffected.
ALTER TABLE pos_sales       ENABLE ROW LEVEL SECURITY;
ALTER TABLE pos_sale_items  ENABLE ROW LEVEL SECURITY;
ALTER TABLE stock_movements ENABLE ROW LEVEL SECURITY;

COMMIT;
