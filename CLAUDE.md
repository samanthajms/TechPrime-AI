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

**Role-per-folder modules.** `login.php` → `redirectByRole()` sends each role to its folder: `ADMIN/` (admin), `RETAIL/` (retail_officer — reports/forecasting), `INVENTORY/` (inventory_custodian — stock master data, orders, activity), `CASHIER/` (cashier — POS checkout + receiving), `CLIENT/` (client/default — storefront). `SELLER/` and `courier/` are referenced in code but do not exist; the technician role was removed.

**Auth/guards** (`includes/security.php`): PHP sessions; every page calls `checkSessionTimeout()` (15 min) and `checkRole('<role>')` (redirects to `/login.php`). JSON endpoints check `$_SESSION['role']` themselves and return `{ok:false,error}` with 403. CSRF via `generateCsrfToken()`/`verifyCsrfToken()`. `h()` for escaping. `logActivity($db, $uid, $action, $details)` writes the audit trail to the `logs` table (shown in Admin → Activity Logs; the custodian Activity page only shows a whitelist of actions and parses `stock X → Y` in details).

**Staff UI shell** (`includes/staff_layout.php` + `includes/staff_shared.css`): pages call `staff_page_start([...role, title, active, heading, subtitle, extra_head])` … `staff_page_end($scripts)`. Nav per role in `staff_nav_for_role()`. Relative asset paths depend on a folder regex `(ADMIN|RETAIL|INVENTORY|CASHIER|courier)` repeated in several helpers — a new role folder must be added there. Custodian and cashier pages get the green `inv-page-banner`. Page-specific CSS is inline in `extra_head` (INVENTORY) or `CASHIER/cashier.css`, built on the `staff_shared.css` tokens (`--ep-green`, etc.). Modal alerts: `IAS_UI.alert()` (`includes/ui_alerts.js`); redirect flash messages use `?alert=`/`?error=` keys mapped in `ias_alert_message_from_request()`.

**Page pattern:** POST handlers at the top of the page, then redirect with `?alert=...`; GET renders server-side and often embeds data as JSON for client-side filtering/paging (e.g. `INVENTORY/inventory_stocks.php`). Many pages build their `<script>` inside a PHP heredoc — `$` and `\n` are interpolated there, so use a nowdoc (`<<<'X'`) or escape.

**Stock alerts/notifications** (`includes/inventory_alerts.php`): thresholds are hardcoded (critical ≤ 5, low ≤ 15, no per-product reorder level); `inv_notify_stock_change()` notifies custodians when stock crosses a threshold.

**Cashier POS** (`includes/pos_helpers.php`, `backend/api/{barcode_lookup,stock_out,stock_in}.php`):
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
- `.gitattributes` uses `text=auto` (repo stores LF); CRLF/LF differences in the working copy are harmless. Work happens on branch `Paul-UI`.

## Verifying changes

No automated tests exist; the working approach has been:
1. `php -l` on every changed PHP file.
2. DB logic: a CLI script that opens a transaction, calls the helpers (they use SAVEPOINTs when nested), asserts, then **rolls back** and confirms the DB matches the baseline. For a forced mid-transaction failure, create a trigger inside that same transaction. Sequences are not transactional: rolled-back sales still consume `pos_invoice_seq` numbers, so tests that complete sales first run `ALTER TABLE pos_sales ALTER COLUMN invoice_no SET DEFAULT ('TEST-' || substr(md5(random()::text), 1, 12))` inside the same transaction (rolled back with it; locks `pos_sales` briefly — only while nobody is selling). Include `SELECT last_value FROM pos_invoice_seq` in the baseline.
3. Access control: `curl` with a cookie jar after logging in through `login.php`; other roles can be simulated in CLI by starting a session, setting `$_SESSION` and `require`-ing the page (avoid pages that write on GET — the dashboards call `logActivity` on every view). Without a password, craft a session over HTTP: a CLI script calls `session_id('<id>')`, `session_start()`, fills `$_SESSION` (`user_id`, `role`, `name`, `surname`, `last_activity`, `csrf_token`) and `session_write_close()` — XAMPP's `session.save_path` is `C:\xampp\tmp`, shared with Apache — then send `-b PHPSESSID=<id>`. Set `last_activity` to `time() - 1000` to test the 15-min expiry. Delete the `sess_<id>` files afterwards.
4. UI: the `browser-automation` skill (headless; simulate the scanner with `page.keyboard.type(code, {delay: 8})` + Enter). Its `page.evaluate` runs in an isolated world — use `addScriptTag` to reach page globals. Check for console errors on every page.

Test account on the live DB: cashier `cashier.test@easypc.local` (password is not stored in the repo; ask the user). Test barcodes: `400000000015` (UPC-A), `20000011` (EAN-8), `2000000000015` (EAN-13).

See `HANDOFF.md` for the status of the Cashier/POS work, open items and known gotchas.

## Knowledge graph (graphify)

`graphify-out/` (repo root, outside the web root) holds a graphify knowledge graph of the app code plus `CLAUDE.md`/`HANDOFF.md` (`graph.html`, `GRAPH_REPORT.md`, `graph.json`); it is committed. Refresh it after code changes with `/graphify --update`. Its scope is the app code only: `--update` also reports vendor code (PHPMailer, Composer), the product/brand images under `assets/` and tool config (`.serena/`, `claude/settings.json`) as new — leave those out, as the original build did (there is no `.graphifyignore` yet). The `GEMINI_API_KEY`/`GOOGLE_API_KEY` in the environment was rejected as invalid on 2026-09-26, so doc extraction falls back to a Claude subagent.
