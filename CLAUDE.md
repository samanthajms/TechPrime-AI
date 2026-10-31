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
python scripts/build_client_dark_css.py      # regenerate CLIENT/ep_dark_auto.css — re-run after editing CLIENT/styles.css, primo.css, tech_match.css or saved_builds.php styles
python ml/pc_recommend/retrain.py            # Build a PC Random Forest: rebuild dataset from the DB (read-only), retrain, swap in forest.json only if checks pass; log in ml/pc_recommend/logs/retrain.log. Runs nightly via Windows task "TechPrime-AI PC Recommender Retrain" (daily 02:00, this PC only)
```

Primo chatbot intent service (Python, needed only for the client chat):
```bat
cd ml\primo && python -m venv .venv && .venv\Scripts\activate && pip install -r requirements.txt
python train_model.py      # train SVM intent model
python predict_api.py      # serves http://127.0.0.1:5055 (or start_api.bat)
python test_intents.py
```

## Database — read before touching data

- **PostgreSQL on Supabase via PDO `pgsql`**, not MySQL. `database/schema.sql`, `techprime_ai.sql` and most `migration_*.sql` are stale MySQL/phpMyAdmin dumps; `database/migration_cashier_pos.sql`, `migration_messages_features.sql` and `migration_messages_is_read.sql` are Postgres and reflect the real current schema for POS and staff-chat tables. Inspect the live schema via `information_schema`/`pg_catalog` when in doubt.
- Connection: `getDbConnection()` in `backend/config/database.php` (reads `TechPrime-AI/.env` via phpdotenv — never read, edit or commit `.env`). `includes/db.php` is a legacy `getDb()` with hardcoded credentials; don't use it.
- **There is only one database: the live, shared Supabase instance.** Migrations are run by hand. Ask before any schema change or data write. Test DB logic inside a transaction that is rolled back (the POS helpers support being called inside an open transaction — they use a SAVEPOINT).
- Connection goes through the Supabase pooler on port 6543 (transaction pooling): keep work inside one transaction; never use session-level `SET` (it can leak to other clients).
- Schema quirks: `users.role` is `TEXT` with CHECK constraint `users_role_check` (adding a role = drop/re-add the constraint). Timestamps are `timestamp without time zone` holding UTC; PHP displays Asia/Manila. `products.sku` = EasyPC internal MSKU item code; `products.barcode` = normalized UPC/EAN (EAN-8 as 8 digits, UPC-A/UPC-E/EAN-13 as 13-digit GTIN). `products.stock` has `CHECK (stock >= 0)`. Several pages feature-detect optional columns via `information_schema` before using them.
- All SQL uses PDO prepared statements.

## Architecture

**Role-per-folder modules.** `login.php` → `redirectByRole()` sends each role to its folder: `ADMIN/` (admin), `RETAIL/` (retail_officer — reports/forecasting), `INVENTORY/` (inventory_custodian — stock master data, stock-in/receiving, orders, activity), `CASHIER/` (cashier — POS checkout for walk-ins, read-only stock alerts, profile), `CLIENT/` (client/default — storefront). `SELLER/` and `courier/` are referenced in code but do not exist; the technician role was removed.

**Auth/guards** (`includes/security.php`): PHP sessions; every page calls `checkSessionTimeout()` (15 min idle, `IAS_SESSION_IDLE_TIMEOUT`; must stay below `session.gc_maxlifetime` 1440 s — **currently switched off** via `IAS_SESSION_TIMEOUT_ENABLED = false` at the top of `security.php`, which also skips `session_timeout.js` and raises `gc_maxlifetime` to 8 h) and `checkRole('<role>')` (redirects to `ias_login_url()` — never hardcode `/login.php`, the app is not at the web root). An expired session redirects to `login.php?expired=1` (JSON/fetch requests get 401 `session_expired`); `includes/session_timeout.js` (loaded by `staff_page_end()` and `CLIENT/ep_header.php`) shows the idle warning / session-expired dialog via `backend/api/session.php`. JSON endpoints check `$_SESSION['role']` themselves and return `{ok:false,error}` with 403. CSRF via `generateCsrfToken()`/`verifyCsrfToken()`. `h()` for escaping. `logActivity($db, $uid, $action, $details)` writes the audit trail to the `logs` table (shown in Admin → Activity Logs; the custodian Activity page only shows a whitelist of actions and parses `stock X → Y` in details).

**Staff UI shell** (`includes/staff_layout.php` + `includes/staff_shared.css`): pages call `staff_page_start([...role, title, active, heading, subtitle, extra_head])` … `staff_page_end($scripts)`. Nav per role in `staff_nav_for_role()`. Relative asset paths depend on a folder regex `(ADMIN|RETAIL|INVENTORY|CASHIER|courier)` repeated in several helpers — a new role folder must be added there. Every staff role (cashier, admin, retail officer, inventory custodian) is handed off at the top of `staff_page_start/end` to the new app shell (roles listed in `staff_app_shell_roles()`; see **Staff design system** below). The legacy shell code in `staff_layout.php` (250px green sidebar, custodian `inv-page-banner`, legacy notifications dropdown) is no longer reached by any existing role; the orphan pages `RETAIL/retail_orders.php`, `retail_reports.php`, `retail_reviews.php`, `retail_settings.php` (not in the nav) still carry legacy inline CSS. Modal alerts (`includes/ui_alerts.js`, shared by staff and client pages): `IAS_UI.alert(msg, type, ms, opts)` and `IAS_UI.confirm(msg, opts)` → `Promise<boolean>` (`IAS_UI` is a top-level `const`, so test it with `typeof IAS_UI`, never `window.IAS_UI`), or `<form data-confirm="…" data-confirm-ok="…" data-confirm-type="danger">` — don't use the browser's `confirm()`/`alert()` (remaining `window.confirm` calls are only fallbacks when `IAS_UI` is missing). Redirect flash messages use `?alert=`/`?error=` keys mapped in `ias_alert_message_from_request()`. Every staff role's My Profile page is a thin wrapper around `staff_profile_page($db, $role)` in `includes/staff_profile.php` (guards first, then the call; avatars saved to `assets/profiles/u{id}.{ext}`).

**Staff chat** (`includes/staff_chat_*.php`, `staff_messages_ui.php`): admin, retail_officer and inventory_custodian only — **not cashier** (`staff_chat_allowed_roles()`). `staff_chat_messages.php` is the AJAX endpoint (GET get_staff/get_history/poll/file/search/media; POST send/react/unsend/forward/pin/unpin with CSRF; every action re-checks the user is in that conversation). `staff_page_end()` adds the floating widget, which embeds the role's Messages page with `?embed=1` (skipped on the Messages pages themselves or with `STAFF_CHAT_SKIP_WIDGET`). Attachments live in `uploads/chat/` (gitignored except its `.htaccess` guard and `index.html`).

**Page pattern:** POST handlers at the top of the page, then redirect with `?alert=...`; GET renders server-side and often embeds data as JSON for client-side filtering/paging (e.g. `INVENTORY/inventory_stocks.php`). Many pages build their `<script>` inside a PHP heredoc — `$` and `\n` are interpolated there, so use a nowdoc (`<<<'X'`) or escape.

**Stock alerts/notifications** (`includes/inventory_alerts.php`): thresholds are hardcoded (critical ≤ 5, low ≤ 15, no per-product reorder level); `inv_notify_stock_change()` notifies custodians when stock crosses a threshold.

**Cashier POS** (`includes/pos_helpers.php`, `backend/api/{barcode_lookup,stock_out,stock_in}.php`):
- Stock-in was moved from the cashier to the custodian (`INVENTORY/inventory_stock_in.php`); `stock_in.php` is custodian-only, `barcode_lookup.php` is shared, `stock_out.php`/`stock_alerts.php` are cashier-only. Supplier is required and must be one of `pos_supplier_catalog()` (no suppliers table: a supplier = product brand, the first word of the name matched against `shop_brands`). Log action stays `pos_stock_in`.
- Cashier stock alerts (dashboard panel + `CASHIER/cashier_stock_alerts.php`) poll `backend/api/stock_alerts.php` every 30 s; that endpoint deliberately does not call `checkSessionTimeout()` so polling doesn't keep an idle session alive.
- `pos_complete_sale()` locks product rows `FOR UPDATE ORDER BY id`, re-validates stock, uses DB prices only, writes `pos_sales`/`pos_sale_items`/`stock_movements` + `logs` in one transaction; `client_ref` UUID makes submits idempotent. Money is integer centavos; prices are VAT-inclusive (12%).
- API convention: business rejections return HTTP 200 `{ok:false,error,message}`; 4xx only for auth/method/CSRF; CSRF token travels in the JSON body.
- End of Day (`CASHIER/cashier_eod.php`, helpers `pos_day_*` / `pos_eod_*` in `pos_helpers.php`): per-day transaction history, totals per payment method, cash count with short/over, CSV + real `.xlsx` export (`includes/xlsx_writer.php`), same-tab print. Closings are saved in `pos_eod_reports` (`database/migration_pos_eod.sql`, Postgres, one row per cashier per Manila day; expected totals are recomputed server-side, every save/update is logged as `pos_eod_close`/`pos_eod_update`). The page feature-detects the table (`pos_eod_available()`): without it, it still shows/exports/prints but cannot save. Managers see every cashier's closings read-only in **Cashier Closings** (`ADMIN/admin_closings.php`, `RETAIL/retail_closings.php` → `staff_eod_review_page()` in `includes/eod_review.php`, data from `pos_eod_review_rows()`): filters (period, cashier, result), short/over totals, days with sales but no closing, a detail view per closing, CSV/.xlsx export. Shared styles (`.eod-pill`, `.eodr-*`) are in `staff_app.css` §12.
- `includes/barcode_scanner.js` handles USB keyboard-wedge scanners (fast keystrokes + Enter, check-digit validation, dedupe) and must stay in sync with `pos_barcode_analyze()` in PHP. `includes/barcode_label.js` renders printable EAN-13/UPC-A/EAN-8 SVGs (custodian Stocks page → Print Barcode / Generate; generated in-store codes are `21` + 10-digit product id + check digit).

**Client storefront** (`CLIENT/`, `includes/client_helpers.php`): session cart synced to DB on login (`ep_sync_cart_on_login`), COD orders via `ep_place_cod_order()` (atomic stock deduction), PayMongo in `backend/api/create_payment.php`, Primo chat UI → `backend/api/primo_chat.php` → Python service on 127.0.0.1:5055.
- Every customer page includes `CLIENT/ep_header.php` / `ep_footer.php`. Shop grouping is a client-only taxonomy (`includes/client_shop_taxonomy.php`; it never changes `products.category`). `products.php?id=` renders the full `product_detail.php` page (no popup). Build a PC = `build_a_pc.php` + `tech_match.js/.css` + `tech_match_api.php`, with compatibility derived from product name/description text only (`includes/pc_compatibility.php`) and builds saved via `saved_builds_api.php`. Notifications: `includes/client_notifications.php` (header dropdown + `notifications.php`). Back links use `<a href="fallback.php" data-ep-back>` (`ep_back.js`).
- **Storefront design (the homepage is the reference):** every customer page gets body class `sf-page` and loads `CLIENT/storefront.css` (tokens `--sf-*`, buttons, product card, rails, page header, pager, empty state, footer) + `storefront-pages.css` (older page markup such as `ep-shop-*`, `ep-pd-*`, `epc-*`, `pf-*`, `sb-*`, `tm-*` restyled onto the tokens; selectors start with `.ep-body.sf-page` so they beat `styles.css` and the generated dark rules) from `ep_header.php`, after the dark files. Markup helpers in `includes/storefront_components.php`: `sf_page_hero()` (light page header on the page background — breadcrumb/back link, eyebrow, h1, chips, actions, hairline above the content; `size => sm`, `width => narrow`; the deep-green hero band is homepage-only), `sf_product_card()` (`wish`, `rating`, `cat` options), `sf_product_rail()`, `sf_pagination()` (keeps `.ep-pagination a.ep-page-link` for shop.php's live results), `sf_empty_state()`. Rail arrows are wired by `CLIENT/storefront.js`. Dark mode for these files is token-only (no generator run needed); `.ep-body.sf-page` also re-points the legacy `--ep-*` variables (`--ep-green` = the darker action green). New client UI uses these components, not new `ep-*` styles.
- **Dark mode:** a header toggle sets `html[data-theme="dark"]` (saved in `localStorage` `ep_theme`). `ep_dark_auto.css` is **generated** — never edit it by hand. Put manual dark fixes in `ep_dark.css` (loads after it), and re-run `scripts/build_client_dark_css.py` after changing client styles, otherwise the dark theme drifts out of sync. Bump the `?v=` on the header's stylesheet links when they change.

**Catalog data:** `Oasis-Itemlist.csv` is the source list (261 products); images in `assets/products/{Category}/`, seller uploads in `uploads/products/`.

## Staff design system — the Cashier module is the reference

The Cashier redesign (built with the `ui-ux-pro-max` skill) is the approved look for **all staff modules**. Admin, Retail Officer and Inventory Custodian have been migrated. New staff UI work follows it; do not extend the legacy look.

**Reference implementation (read before migrating anything):**
- `includes/staff_app_layout.php` — role-aware app shell: `staff_app_shell_start($opts)` / `staff_app_shell_end($scripts)`, reached from the early return at the top of `staff_page_start/end` for the roles in `staff_app_shell_roles()` (`$GLOBALS['IAS_APP_SHELL']` tells `staff_page_end` which shell is open). Nav comes from `staff_nav_for_role()` with extra keys `ph` (Phosphor icon), `short` (tab label), `tab` (one of the 4 phone tabs when a role has > 5 items; the rest go in the "More" sheet), `section` (sidebar group label) and `children` (sub-pages, shown under the item). Account menu shows Settings when the nav has a `settings` item; the staff chat widget is added by `staff_chat_widget_maybe()` (shared with the legacy shell). Extra opts: `actions` (header buttons HTML), `hide_head` (no page header; an sr-only `h1` is still printed). The role stylesheet comes from `staff_app_role_css()`. `includes/cashier_layout.php` is only a compatibility shim now.
- `includes/staff_app.css` — tokens (§1), shell (§2), components (§3), dialog (§4), page building blocks (KPI panel, CTA tile, count tiles, toolbar, pager §5), shared pieces (§6), nav groups + More sheet (§9) and the legacy shared components as they render inside the shell (forecast cards, `.filter-modal`, `.ias-table`, badges, `staff_pagination()`, toggles, chat widget §10). §11 holds pieces shared by several roles: the custodian notifications bell (`.cx-notif`, rendered by `staff_app_notif_bell()`), segmented control `.cx-seg`, wrapping category chips `.cat-chips`, card-head tools/`.cx-count`, three-KPI card `.cx-kpi-card .kpis-3`, chart frame `.cx-chart`, the barcode scan bar `.pos-scan-bar` + `.pos-status` feedback (Cashier POS + custodian receiving) and dialog layering above the chat widget. Role page styles: `CASHIER/cashier.css`, `ADMIN/admin.css`, `RETAIL/retail.css`, `INVENTORY/inventory.css`. The phone tab bar sizes its grid from `--cx-tabs` (set by the shell from the tab count). Everything is scoped under `body.cx-app`; the token block also re-points the legacy `--ep-*`/`--card-*`/`--font-*` variables so shared pieces (My Profile, `.card`, `.btn`, `.form-control`, `.alert`, `IAS_UI` dialogs) follow the new look without markup changes.
- Page examples: `CASHIER/cashier_dashboard.php` (KPI panel, CTA tile, table + live list), `cashier_stock_alerts.php` (count tiles as filters, toolbar, table → cards on phones, pagination), `cashier_pos.php` (workspace + sticky side panel, dialog), `cashier_receipt.php` + `receipt_print.js` (same-tab printing).

**Look (keep the EasyPC colour scheme):**
- Colours: brand green `--cx-green-500 #62b236` for fills/icons only; **actions and green text use `--cx-green-700 #3d7422`** (white on `#62b236` fails contrast). Yellow `#f3c400` for low-stock accents and the sidebar's active marker; text on yellow tints uses `--cx-yellow-700`. Status: red (out), rose (critical), yellow (low). Neutrals: page `#f2f5f1`, surfaces white, borders `#e0e6dd`, text `#16201a` / `#45514a`, muted `#5c6862` (all ≥ 4.5:1). Dark "register display" `#13230d` + `#c9f5a8` for headline money.
- Type: Fira Sans (UI) + Fira Code (money, codes, invoice numbers, receipts); tabular numbers via `.cx-num`. Page `h1` 26px/700, card titles 16px/600, body 15px.
- Icons: **Phosphor** (`<i class="ph ph-…">`, `ph-fill` for the active nav item and status icons). Font Awesome stays loaded only because shared components still use it; migrated markup uses Phosphor only. Verify icon names exist in `@phosphor-icons/web@2.1.1` before use.
- Shape: radius 8 (controls) / 12 (inner blocks) / 16 (cards) / 20 (dialogs); flat white cards with a 1px border and `--cx-shadow-sm`; no gradient banners (the only gradients are the sidebar, the CTA tile and the register display).
- Google Fonts and the jsDelivr Phosphor CSS are already approved for staff pages; any other new CDN still needs the user's OK.

**Shell anatomy:**
- Desktop (> 900px): fixed sidebar 248px (deep green gradient, logo + store line, nav, Collapse + Log out at the bottom) collapsing to a 76px icon rail; the preference is `localStorage ep_sidebar_collapsed` (shared with the legacy shell). Labels stay in the accessibility tree when collapsed. Slim white top bar (64px): breadcrumb `Role › Page`, live Asia/Manila clock, account menu (avatar, name, email, My Profile, Log out).
- ≤ 900px: sidebar hidden, brand shown in the top bar, **bottom tab bar** (max 5 items, icon + label, safe-area padding). Dialogs become bottom sheets.
- Page header inside `<main>`: `h1` + one-line subtitle + optional actions. Content max-width 1480px, 28px gutters (16px on phones).

**Components (classes in `cashier.css`):** `.cx-btn` + `-primary` / `-outline` / `-ghost` / `-ghost-danger`, sizes `-sm` 36px · default 44px · `-lg` 48px · `-xl` 58px; `.cx-card` + `.cx-card-head` (h2 with a green icon, `p` subtitle, optional `.cx-link` action); `.cx-table` in `.cx-table-wrap` (uppercase 11.5px headers, 12–14px row padding, `.num` right-aligned); `.stock-status-pill` (`ok` / `low` / `critical` / `out`), `.cx-badge`, `.pay-chip`; `.cx-empty` (icon circle + title + text + optional CTA); `.alerts-live` (live/stale data chip); `kbd` hints; `.cx-sr` for screen-reader-only text; `.cx-skip` skip link.

**Rules that keep it consistent:**
- Targets ≥ 44px (36px only for secondary in-table buttons); visible focus = 2px `--cx-green-700` outline (yellow inside the sidebar); never convey status by colour alone (icon + text, plus a coloured left edge on rows).
- Tables become stacked cards ≤ 720px (`td[data-label]`, see `.alerts-table` / `.tx-table`); widgets that sit in variable-width columns use container queries (see `.pos-cart`, `.kpis`) instead of viewport breakpoints.
- Motion: 150–220ms with `--cx-ease`; transform/opacity only; every animation has a `prefers-reduced-motion` fallback.
- Prints: hide `.cx-sidebar`, `.cx-topbar`, `.cx-tabbar`, `.cx-pagehead`; print in the same tab (hidden iframe pattern from `receipt_print.js`), never `target="_blank"` just to print.
- Report printing (Print button = `window.print()`): pass `'report' => ['title', 'lines', 'sign']` to `staff_page_start()`. The shell then loads `includes/staff_print.css` (formal A4 layout in the EasyPC colours with `print-color-adjust: exact` — green headings and table header rows, light tints, coloured status text and charts — that still reads when printed in black and white; no icons; page numbers; the letterhead uses `assets/logo-color.png`), adds body class `cx-report`, a letterhead (`staff_report_head()`) and a signature block (`staff_report_sign()`). Print must include everything: rows on other screen pages are rendered as `tr.cx-print-row` (hidden on screen), print-only text/tables use `.cx-print-only`, and charts get their figures in a print-only table.
- Restyling is presentation only: keep PHP logic, form field names/types, POST handlers, endpoints and request payloads unchanged unless the user asks. Keep the IDs and classes page JS depends on, or update that JS in the same change.

**Migrating another role — how to do it:**
1. Done for the shell (`staff_app_layout.php` + `staff_app.css`). To switch a role on: give its `staff_nav_for_role()` items `ph`/`short` (and `tab`/`section` if it has > 5 items), add it to `staff_app_shell_roles()`, add its stylesheet to `staff_app_role_css()`.
2. Already in the app shell: nav `children`, the Settings link, the chat widget, the "More" sheet, the custodian notifications bell (`staff_app_notif_bell()` + `inventory_notif_api.php`; its script is only printed for that role).
3. Navigation over 5 items (Admin has 7, Custodian 8): the phone tab bar shows the 4 most used destinations + a "More" item that opens the full list in a sheet/drawer; don't squeeze more than 5 tabs.
4. Then restyle that role's pages onto the components above. Page CSS that is still inline in `extra_head` moves into the role's own stylesheet.

**Known gotchas (learned on the cashier work):** `IAS_UI` is a top-level `const` (use `typeof IAS_UI`, not `window.IAS_UI`). `barcode_scanner.js` re-focuses the scan field after any click, so any menu or popover on a page with a scanner needs a `canRefocus` exception (see `cashier_pos.php`). `.alert` from `staff_shared.css` overrides `[hidden]` — `cashier.css` sets `[hidden]{display:none!important}`. `staff_profile_page()` is shared by every role and hardcodes Font Awesome icons; restyle it through tokens, or change its markup for all roles at once. Bump the `?v=` on shell stylesheets/scripts whenever they change.

## Project rules

- **Stop and ask the user before:** deleting any file, adding any dependency (Composer, npm, CDN library), running a schema change or data write against the (live) database, or changing an existing role's behavior. Never read, edit or commit `.env` or other credentials.
- Only make changes the task requires — no unrelated refactors. Reuse existing helpers (`getDbConnection`, `logActivity`, `staff_page_start`, `inv_notify_stock_change`, `pos_*`) and the repo's response format instead of writing parallel ones.
- Staff UI follows the **Staff design system** above (Cashier module = reference); don't add another design system or extend the legacy look. On legacy-shell pages that are not being migrated, stay consistent with what is there. Note `.alert` is `display:flex` and overrides `[hidden]` unless a page adds `[hidden]{display:none!important}`.
- Keep scratch/debug output out of the web root — everything under `TechPrime-AI/` is publicly served by Apache.
- `.gitattributes` uses `text=auto` (repo stores LF); CRLF/LF differences in the working copy are harmless. Work happens on branch `khenzou`.
- `graphify-out/` (repo root, outside the web root) holds a knowledge graph of the codebase; `/graphify query "<question>"` answers from it, `/graphify update` refreshes it after changes.

## Verifying changes

No automated tests exist; the working approach has been:
1. `php -l` on every changed PHP file.
2. DB logic: a CLI script that opens a transaction, calls the helpers (they use SAVEPOINTs when nested), asserts, then **rolls back** and confirms the DB matches the baseline. For a forced mid-transaction failure, create a trigger inside that same transaction.
3. Access control: `curl` with a cookie jar after logging in through `login.php`; other roles can be simulated in CLI by starting a session, setting `$_SESSION` and `require`-ing the page (avoid pages that write on GET — the dashboards call `logActivity` on every view).
4. UI: the `browser-automation` skill (headless; simulate the scanner with `page.keyboard.type(code, {delay: 8})` + Enter). Its `page.evaluate` runs in an isolated world — use `addScriptTag` to reach page globals and pass results back through `document.body.dataset`. Check for console errors on every page.
5. Login-gated pages without credentials or DB access: render the page in CLI with a seeded `$_SESSION` and the DB helpers swapped for mock data (or a fake `PDO` subclass that never connects), inject `<base href="http://localhost/TechPrime-AI/TechPrime-AI/<FOLDER>/">`, save the HTML **outside the web root** and open it as `file://`. Same-origin fetches then fail with CORS — expected noise. For same-origin flows (iframes, fetch), serve the rendered HTML at its real URL with Playwright `page.route`.
6. Redesign checks: screenshot each page at 1440, 1024 and 390px (grow the viewport to the content height; full-page captures clip fixed sidebars) and assert no horizontal overflow.
7. Shared template edited for one role (`staff_layout.php`, shared CSS): render the committed version (`git show HEAD:…`) and the new one for every other role with the same stub session, whitespace-normalise and diff — the expected difference is zero lines; check line counts first so a truncated render can't pass.

Test account on the live DB: cashier `cashier.test@easypc.local` (password is not stored in the repo; ask the user). Test barcodes: `400000000015` (UPC-A), `20000011` (EAN-8), `2000000000015` (EAN-13).

See `HANDOFF.md` for the status of the Cashier/POS work, open items and known gotchas.
