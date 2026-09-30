# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

TechPrime AI — capstone inventory/e-commerce system for EasyPC One Oasis Branch (Rosario, Pasig). All application code lives in `TechPrime-AI/` (paths below are relative to it). Planning docs mention Next.js/FastAPI/Supabase; **the code is the source of truth**: plain PHP 8.2 pages on XAMPP Apache, vanilla JS/CSS, no framework, no build step. Do not introduce a new framework or stack.

App URL (local): `http://localhost/TechPrime-AI/TechPrime-AI/login.php`

## Commands

There is no test suite, linter config, or build. PHP CLI is XAMPP's: `C:/xampp/php/php.exe`.

```bash
php -l path/to/file.php                     # syntax check (run on every changed PHP file)
composer install                            # only dependency is vlucas/phpdotenv (vendor/ is gitignored)
php scripts/ensure_product_indexes.php      # idempotent product indexes
php scripts/catalog_db.php count|ensure_sku|validate <csv>   # catalog import helper (used by scripts/import_oasis_catalog.py)
```

Primo chatbot intent service (Python, needed only for the client chat):
```bat
cd ml\primo && python -m venv .venv && .venv\Scripts\activate && pip install -r requirements.txt
python train_model.py      # train SVM intent model
python predict_api.py      # serves http://127.0.0.1:5055 (or start_api.bat)
python test_intents.py
```

## Database — read before touching data

- **PostgreSQL on Supabase via PDO `pgsql`**, not MySQL. `database/schema.sql`, `techprime_ai.sql` and most `migration_*.sql` are stale MySQL/phpMyAdmin dumps; `database/migration_cashier_pos.sql` is Postgres and reflects the real current schema for POS tables. Inspect the live schema via `information_schema`/`pg_catalog` when in doubt.
- Connection: `getDbConnection()` in `backend/config/database.php` (reads `TechPrime-AI/.env` via phpdotenv — never read, edit or commit `.env`). `includes/db.php` is a legacy `getDb()` with hardcoded credentials; don't use it.
- **There is only one database: the live, shared Supabase instance.** Migrations are run by hand. Ask before any schema change or data write. Test DB logic inside a transaction that is rolled back (the POS helpers support being called inside an open transaction — they use a SAVEPOINT).
- Connection goes through the Supabase pooler on port 6543 (transaction pooling): keep work inside one transaction; never use session-level `SET` (it can leak to other clients).
- Schema quirks: `users.role` is `TEXT` with CHECK constraint `users_role_check` (adding a role = drop/re-add the constraint). Timestamps are `timestamp without time zone` holding UTC; PHP displays Asia/Manila. `products.sku` = EasyPC internal MSKU item code; `products.barcode` = normalized UPC/EAN (EAN-8 as 8 digits, UPC-A/UPC-E/EAN-13 as 13-digit GTIN). `products.stock` has `CHECK (stock >= 0)`. Several pages feature-detect optional columns via `information_schema` before using them.
- All SQL uses PDO prepared statements.

## Architecture

**Role-per-folder modules.** `login.php` → `redirectByRole()` sends each role to its folder: `ADMIN/` (admin), `RETAIL/` (retail_officer — reports/forecasting), `INVENTORY/` (inventory_custodian — stock master data, stock-in/receiving, orders, activity), `CASHIER/` (cashier — POS checkout for walk-ins, read-only stock alerts, profile), `CLIENT/` (client/default — storefront). `SELLER/` and `courier/` are referenced in code but do not exist; the technician role was removed.

**Auth/guards** (`includes/security.php`): PHP sessions; every page calls `checkSessionTimeout()` (15 min idle, `IAS_SESSION_IDLE_TIMEOUT`; must stay below `session.gc_maxlifetime` 1440 s) and `checkRole('<role>')` (redirects to `ias_login_url()` — never hardcode `/login.php`, the app is not at the web root). An expired session redirects to `login.php?expired=1` (JSON/fetch requests get 401 `session_expired`); `includes/session_timeout.js` (loaded by `staff_page_end()` and `CLIENT/ep_header.php`) shows the idle warning / session-expired dialog via `backend/api/session.php`. JSON endpoints check `$_SESSION['role']` themselves and return `{ok:false,error}` with 403. CSRF via `generateCsrfToken()`/`verifyCsrfToken()`. `h()` for escaping. `logActivity($db, $uid, $action, $details)` writes the audit trail to the `logs` table (shown in Admin → Activity Logs; the custodian Activity page only shows a whitelist of actions and parses `stock X → Y` in details).

**Staff UI shell** (`includes/staff_layout.php` + `includes/staff_shared.css`): pages call `staff_page_start([...role, title, active, heading, subtitle, extra_head])` … `staff_page_end($scripts)`. Nav per role in `staff_nav_for_role()`. Relative asset paths depend on a folder regex `(ADMIN|RETAIL|INVENTORY|CASHIER|courier)` repeated in several helpers — a new role folder must be added there. Custodian and cashier pages get the green `inv-page-banner`. Page-specific CSS is inline in `extra_head` (INVENTORY) or `CASHIER/cashier.css`, built on the `staff_shared.css` tokens (`--ep-green`, etc.). Modal alerts: `IAS_UI.alert()` (`includes/ui_alerts.js`); redirect flash messages use `?alert=`/`?error=` keys mapped in `ias_alert_message_from_request()`.

**Page pattern:** POST handlers at the top of the page, then redirect with `?alert=...`; GET renders server-side and often embeds data as JSON for client-side filtering/paging (e.g. `INVENTORY/inventory_stocks.php`). Many pages build their `<script>` inside a PHP heredoc — `$` and `\n` are interpolated there, so use a nowdoc (`<<<'X'`) or escape.

**Stock alerts/notifications** (`includes/inventory_alerts.php`): thresholds are hardcoded (critical ≤ 5, low ≤ 15, no per-product reorder level); `inv_notify_stock_change()` notifies custodians when stock crosses a threshold.

**Cashier POS** (`includes/pos_helpers.php`, `backend/api/{barcode_lookup,stock_out,stock_in}.php`):
- Stock-in was moved from the cashier to the custodian (`INVENTORY/inventory_stock_in.php`); `stock_in.php` is custodian-only, `barcode_lookup.php` is shared, `stock_out.php`/`stock_alerts.php` are cashier-only. Supplier is required and must be one of `pos_supplier_catalog()` (no suppliers table: a supplier = product brand, the first word of the name matched against `shop_brands`). Log action stays `pos_stock_in`.
- Cashier stock alerts (dashboard panel + `CASHIER/cashier_stock_alerts.php`) poll `backend/api/stock_alerts.php` every 30 s; that endpoint deliberately does not call `checkSessionTimeout()` so polling doesn't keep an idle session alive.
- `pos_complete_sale()` locks product rows `FOR UPDATE ORDER BY id`, re-validates stock, uses DB prices only, writes `pos_sales`/`pos_sale_items`/`stock_movements` + `logs` in one transaction; `client_ref` UUID makes submits idempotent. Money is integer centavos; prices are VAT-inclusive (12%).
- API convention: business rejections return HTTP 200 `{ok:false,error,message}`; 4xx only for auth/method/CSRF; CSRF token travels in the JSON body.
- `includes/barcode_scanner.js` handles USB keyboard-wedge scanners (fast keystrokes + Enter, check-digit validation, dedupe) and must stay in sync with `pos_barcode_analyze()` in PHP. `includes/barcode_label.js` renders printable EAN-13/UPC-A/EAN-8 SVGs (custodian Stocks page → Print Barcode / Generate; generated in-store codes are `21` + 10-digit product id + check digit).

**Client storefront** (`CLIENT/`, `includes/client_helpers.php`): session cart synced to DB on login (`ep_sync_cart_on_login`), COD orders via `ep_place_cod_order()` (atomic stock deduction), PayMongo in `backend/api/create_payment.php`, Primo chat UI → `backend/api/primo_chat.php` → Python service on 127.0.0.1:5055.

**Catalog data:** `Oasis-Itemlist.csv` is the source list (261 products); images in `assets/products/{Category}/`, seller uploads in `uploads/products/`.

## Project rules

- **Stop and ask the user before:** deleting any file, adding any dependency (Composer, npm, CDN library), running a schema change or data write against the (live) database, or changing an existing role's behavior. Never read, edit or commit `.env` or other credentials.
- Only make changes the task requires — no unrelated refactors. Reuse existing helpers (`getDbConnection`, `logActivity`, `staff_page_start`, `inv_notify_stock_change`, `pos_*`) and the repo's response format instead of writing parallel ones.
- Match existing staff UI (layout shell, tokens, `.card`, `.btn-*`, `.alert-*`, tables, modals) rather than adding new design systems. Note `.alert` is `display:flex` and overrides `[hidden]` unless a page adds `[hidden]{display:none!important}`.
- Keep scratch/debug output out of the web root — everything under `TechPrime-AI/` is publicly served by Apache.
- `.gitattributes` uses `text=auto` (repo stores LF); CRLF/LF differences in the working copy are harmless. Work happens on branch `khenzou`.

## Verifying changes

No automated tests exist; the working approach has been:
1. `php -l` on every changed PHP file.
2. DB logic: a CLI script that opens a transaction, calls the helpers (they use SAVEPOINTs when nested), asserts, then **rolls back** and confirms the DB matches the baseline. For a forced mid-transaction failure, create a trigger inside that same transaction.
3. Access control: `curl` with a cookie jar after logging in through `login.php`; other roles can be simulated in CLI by starting a session, setting `$_SESSION` and `require`-ing the page (avoid pages that write on GET — the dashboards call `logActivity` on every view).
4. UI: the `browser-automation` skill (headless; simulate the scanner with `page.keyboard.type(code, {delay: 8})` + Enter). Its `page.evaluate` runs in an isolated world — use `addScriptTag` to reach page globals. Check for console errors on every page.

Test account on the live DB: cashier `cashier.test@easypc.local` (password is not stored in the repo; ask the user). Test barcodes: `400000000015` (UPC-A), `20000011` (EAN-8), `2000000000015` (EAN-13).

See `HANDOFF.md` for the status of the Cashier/POS work, open items and known gotchas.
