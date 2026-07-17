-- Optional migration for the redesigned Inventory Custodian "Stocks" tab.
-- Adds a nullable SKU/barcode column to products so items can be looked up
-- by barcode/SKU in the search bar. Safe to run on the live database:
-- the column is nullable, has no default constraint on existing rows,
-- and every INSERT/UPDATE in the codebase uses explicit column lists,
-- so RETAIL/CLIENT/ADMIN modules are unaffected whether or not this
-- migration has been applied (inventory_stocks.php feature-detects the
-- column and degrades gracefully if it's missing).

ALTER TABLE `products`
    ADD COLUMN `sku` VARCHAR(64) NULL DEFAULT NULL AFTER `id`;

ALTER TABLE `products`
    ADD INDEX `idx_products_sku` (`sku`);
