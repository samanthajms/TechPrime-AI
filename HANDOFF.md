# HANDOFF — Cashier role + barcode POS

_Last updated: 2026-09-26 · branch `khenzou` · committed locally (not pushed)_

## Goal

Add a **Cashier** role to TechPrime AI (EasyPC One Oasis Branch). The cashier uses a USB handheld 1D barcode scanner (keyboard wedge: types digits + Enter) for **checkout (stock-out)** and **receiving (stock-in)**, with a UI that matches the Inventory Custodian pages. Manuscript DFD: the scanner is only an input device; the cashier sends UPC/EAN scans and stock-in payloads and receives scan confirmations and stock-in status. Panel asks: invoicing and low/critical-stock monitoring.

Follow-ups done in the same session: a Barcode column + filter on the custodian Stocks table, and printable barcode labels / in-store barcode generation so items can be scanned for testing.

## Done

All paths relative to `TechPrime-AI/`.

**Database** — `database/migration_cashier_pos.sql` (PostgreSQL, idempotent) **has been applied to the live Supabase DB**:
- `cashier` added to `users_role_check`
- `products.barcode VARCHAR(13)` + unique partial index + format CHECK; `CHECK (stock >= 0)` on products
- new tables `pos_sales` (invoice `INV-YYYYMMDD-NNNNNN` from `pos_invoice_seq`, `client_ref` UUID unique), `pos_sale_items`, `stock_movements` (before/after + math CHECK); RLS enabled on the three new tables

**New files**
| File | Why |
|---|---|
| `includes/pos_helpers.php` | Barcode validate/normalize, lookup, `pos_complete_sale()` (atomic checkout), `pos_stock_in()`, dashboard read models, JSON API guard |
| `includes/barcode_scanner.js` | Shared scanner module: always-focused scan field, scanner-vs-typing detection, check digits, 300 ms dedupe, stray-scan rerouting, inline feedback + beep |
| `includes/barcode_label.js` | Dependency-free SVG renderer for EAN-13/UPC-A/EAN-8 at true print size (0.33 mm module) |
| `backend/api/barcode_lookup.php`, `stock_out.php`, `stock_in.php` | Cashier-only JSON endpoints (the planned `/api/stock-in`, `/api/stock-out`) |
| `CASHIER/cashier_dashboard.php` | Today's sales count/total, own recent transactions + reprint, read-only stock alerts |
| `CASHIER/cashier_pos.php` | Scan → cart, qty edit/remove, totals with VAT split, payment modal (cash/GCash/Maya/Card), sale completion |
| `CASHIER/cashier_stock_in.php` | Scan → qty/supplier/reference → confirm; recent stock-ins |
| `CASHIER/cashier_receipt.php` | Printable sales invoice (80 mm-friendly print CSS); own sales only |
| `CASHIER/cashier.css` | Custodian page styles (stat cards, pills, tables, modal) copied with identical values + POS/receipt/print styles |
| `database/migration_cashier_pos.sql` | See above |
| `../CLAUDE.md`, `../HANDOFF.md` | Agent docs |

**Modified files**
| File | Change |
|---|---|
| `login.php` | `cashier` → `CASHIER/cashier_dashboard.php` |
| `includes/staff_layout.php` | Cashier nav + label, `CASHIER` added to the 4 folder regexes, cashier pages get the green page banner |
| `ADMIN/manage_users.php` | Cashier in staff-role dropdown, Cashiers tab + count |
| `includes/security.php` | Flash messages `barcode`, `barcode_taken`, `barcode_generated` |
| `INVENTORY/inventory_stocks.php` | New UPC/EAN field on Add/Edit (old "Barcode" field actually saved `sku` → relabelled "SKU / Item Code"); UPC/EAN in details; Barcode column + Has/Missing filter; search matches barcodes; Print Barcode label modal (copies, price toggle, print sheet); Generate / bulk Generate Barcodes; Print Labels for selected |

**Verification done** (evidence in the session; scripts lived in a temp scratchpad and are likely gone):
- `php -l` clean on every changed PHP file.
- 40/40 DB integration checks inside a rolled-back transaction (lookup, sale, idempotent resubmit, over-stock block, forced mid-transaction failure → full rollback, payment validation, stock-in, CHECK constraints, read models); DB identical to baseline afterwards. Row-lock contention test passed.
- HTTP access control: cashier → 200 on own pages, 302 on all ADMIN/INVENTORY/RETAIL pages, 403 on custodian API; other roles → blocked from all cashier pages (302) and APIs (403); unauthenticated APIs → 403.
- Headless browser E2E (simulated scanner keystrokes): 30 checks incl. real sale + stock-in; 0 console errors, 0 failed requests on all cashier pages; visual comparison with custodian Stocks page.
- Rendered barcodes decoded by an independent decoder (ZXing) for all formats, including a label taken from the live page.

**Live test data left in the DB (intentional, approved):**
- Test cashier `cashier.test@easypc.local` (user #62). Password was given to the user in chat; it is **not** stored in the repo.
- Test barcodes (GS1 in-store `20…`/`4…` prefixes): #102 `20000011`, #162 `0400000000015` (prints as `400000000015`), #218 `2000000000015`.
- Generated barcode #243 `2100000002436` (real use of the Generate action).
- One sale `INV-20260926-000004` (1 × #162, cash) + one stock-in (+1 #162) → net stock unchanged; matching `stock_movements` and `logs` rows.

## Left to do (in order)

1. **Physical test with the real scanner and a printer** (never done — scanner was simulated). Print labels at 100% scale ("Fit to page" off); confirm the scanner sends Enter as suffix (Tab or no suffix will not work without reconfiguring it).
2. **Push** branch `khenzou` when the user asks (reviewed and committed locally; `reference/scanner.jpg` was left untracked on purpose).
3. **Assign real barcodes**: custodian Stocks → filter "Missing barcode" → Edit → scan the box barcode. Use Generate only for items with no manufacturer barcode (freebies/bundles).
4. **Decide on test data cleanup** (test cashier #62, test barcodes on #102/#162/#218, sale INV-…-000004 and its rows). Deleting needs the user's explicit OK.
5. **Create real cashier accounts** via Admin → Manage Users; block/delete the test account before go-live.
6. **Rotate the Supabase DB password** — it is hardcoded in `includes/db.php`, which is committed and on GitHub. Deleting that (apparently unused) file needs the user's OK.
7. Close the verification gaps: submit Admin's Create Staff form through the UI (no admin password available to the agent); render the Admin/Inventory/Retail dashboards (they write a `logs` row on every GET, so they were skipped); save a barcode through the custodian Edit form; API session-expiry (401) path; custodian notifications triggered by cashier sales.
8. Not started / out of scope so far: voiding a sale with Store Manager approval (no Store Manager role exists; `pos_sales.status` already allows `voided`); POS sales in RETAIL sales reports (they only read online `orders`); cashier actions on the custodian Activity page (user declined); per-product reorder levels.

## Key decisions (and why)

- **Postgres, not MySQL** — the running code and live DB are Supabase Postgres; the `/database` MySQL dumps are stale.
- **Separate `products.barcode`**, not `sku` — all 261 `sku` values are internal MSKU codes; no UPC/EAN existed in the catalog.
- **Normalized storage**: EAN-8 as 8 digits; UPC-A/UPC-E/EAN-13 as 13-digit GTIN, so the same item matches whether the scanner sends 12 or 13 digits. 8-digit codes are tried as both EAN-8 and UPC-E.
- **Defaults accepted by the user**: prices VAT-inclusive 12% (receipt shows VATable/VAT); payments Cash/GCash/Maya/Card, non-cash requires a reference no., no split payments; stock-in endpoint is cashier-only; cashier actions not shown on custodian Activity page; DB-level `CHECK (stock >= 0)`; receipt works on 80 mm and A4 and says "THIS IS NOT AN OFFICIAL RECEIPT" (no BIR POS accreditation).
- **Checkout integrity**: lock rows `FOR UPDATE ORDER BY id` (no deadlocks), re-check stock, prices from DB only (client total must match), guarded `UPDATE … WHERE stock >= ?`, `client_ref` idempotency, custodian notifications only after commit. Helpers use a SAVEPOINT when called inside an open transaction (enables rollback-only testing).
- **FKs `ON DELETE SET NULL` + name snapshots** on sales/movements so Admin can still delete users and the custodian can still delete products.
- **API status codes**: business rejections return 200 `{ok:false}` (repo convention, and 4xx fetches show up as browser console errors); 4xx only for auth/method/CSRF.
- **No new dependencies**: own scanner module and SVG barcode renderer instead of a library (user rule: ask before adding dependencies).
- **Generated in-store barcodes** = `21` + 10-digit product id + check digit (unique, GS1 restricted range, distinct from the `20…` test codes).

## Known bugs / gotchas

- Pre-existing: `checkRole()`/`checkSessionTimeout()` redirect to absolute `/login.php`, which 404s under `/TechPrime-AI/TechPrime-AI/`. Left as-is (existing behavior).
- Pre-existing: `INVENTORY/barcode.js` is a broken, unused stub (mixes a React `import` into a plain script). Not used by anything.
- Pre-existing: `includes/db.php` hardcoded credentials (see Left to do #6).
- Stock thresholds are hardcoded (critical ≤ 5, low ≤ 15): 188 of 261 products show as critical, so alert lists are long.
- One product has a blank name (first row in Stocks); labels/confirmations fall back to "Product #id".
- Invoice numbers 1–3 were consumed by rolled-back tests (sequences are not transactional); real numbering started at 4.
- `staff_shared.css` gives `.alert` `display:flex`, which overrides the `hidden` attribute — `CASHIER/cashier.css` adds `[hidden]{display:none!important}`.
- JS written inside PHP heredocs: `$` and `\n` are interpolated — use nowdoc or escape (a `\n` in a `confirm()` string broke once).
- `.brand-sub` is uppercased by CSS; use `textContent` not `innerText` in assertions.
- Testing tools: the browser-automation driver (patchright) runs `page.evaluate` in an isolated world — inject page scripts with `addScriptTag` and read results from the DOM. A CLI role-simulation harness once `chdir`'d into the page folder and wrote HTML dumps into the web root (moved out immediately) — write test output outside `TechPrime-AI/`.
- Git Bash `sed -i` converts CRLF → LF; harmless because `.gitattributes` is `text=auto`.
- RETAIL pages call `session_start()` directly (notice only when simulated in CLI); dashboards write a `logs` row on every GET.
