# Graph Report - TechPrime-AI  (2026-09-26)

## Corpus Check
- 18 files · ~1,569,276 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 733 nodes · 1162 edges · 97 communities (33 shown, 64 thin omitted)
- Extraction: 93% EXTRACTED · 7% INFERRED · 0% AMBIGUOUS · INFERRED: 80 edges (avg confidence: 0.85)
- Token cost: 73,167 input · 0 output

## Community Hubs (Navigation)
- Catalog Import Scripts
- Primo ML Service
- Project Stack And Constraints
- POS Checkout Integrity
- Inventory Stocks And Barcodes
- Storefront And Stock Alerts
- Primo Chat Widget
- Session Auth And Primo Backend
- Staff Dashboards
- Tech Match Builder
- Retail Sales Reports
- Roles And Staff UI Shell
- Cashier POS Page
- Legacy Storefront Script
- Shop Taxonomy And Brands
- Main SQL Schema
- POS Database Migration
- Client Header And Search
- Base Schema
- Shop Page Filters
- Cashier Stock-In
- Roles And Categories
- Inventory Stock Charts
- Courier Shipments Migration
- Auth Client JS
- Primo Chat History
- Registration
- Unused Barcode Stub
- Admin Settings
- Activation Mailer
- Category Page JS
- Saved Builds API
- Primo Chat Migration
- PC Compatibility Rules
- Catalog DB Helpers
- Composer Dependencies
- Saved Builds Migration
- Settings Migration
- Admin Chat Widget
- UI Alerts
- Retail Messages
- users

## God Nodes (most connected - your core abstractions)
1. `pos_complete_sale()` - 23 edges
2. `confirmPay()` - 15 edges
3. `process_product()` - 11 edges
4. `readJson()` - 11 edges
5. `pos_stock_in()` - 11 edges
6. `main()` - 10 edges
7. `refresh()` - 10 edges
8. `inv_notify_stock_change()` - 10 edges
9. `currency()` - 10 edges
10. `getProducts()` - 10 edges

## Surprising Connections (you probably didn't know these)
- `pos_barcode_analyze()` --implements--> `Barcode Normalization (EAN-8 / 13-digit GTIN)`  [INFERRED]
  TechPrime-AI/includes/pos_helpers.php → CLAUDE.md
- `getDbConnection()` --references--> `.env Credentials (never read/edit/commit)`  [EXTRACTED]
  TechPrime-AI/backend/config/database.php → CLAUDE.md
- `Hardcoded Stock Thresholds (critical<=5, low<=15)` --references--> `inv_notify_stock_change()`  [EXTRACTED]
  CLAUDE.md → TechPrime-AI/includes/inventory_alerts.php
- `Unreachable Critical Branch in inv_notify_stock_change` --references--> `inv_notify_stock_change()`  [EXTRACTED]
  HANDOFF.md → TechPrime-AI/includes/inventory_alerts.php
- `Cashier POS Checkout` --references--> `pos_complete_sale()`  [EXTRACTED]
  CLAUDE.md → TechPrime-AI/includes/pos_helpers.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Primo train / serve / test lifecycle** — techprime_ai_ml_primo_train_model, techprime_ai_ml_primo_readme_predict_api_py, techprime_ai_ml_primo_test_intents [EXTRACTED 1.00]
- **Primo chat request flow (UI -> PHP bridge -> Flask SVM API)** — techprime_ai_ml_primo_readme_primo_ui, techprime_ai_ml_primo_readme_primo_chat_php, techprime_ai_ml_primo_readme_predict_api_py [INFERRED 0.85]
- **Cashier Scan-to-Checkout Flow** — techprime_ai_includes_barcode_scanner, techprime_ai_backend_api_barcode_lookup, techprime_ai_cashier_cashier_pos, techprime_ai_backend_api_stock_out, techprime_ai_includes_pos_helpers_pos_complete_sale, handoff_pos_sales_table, handoff_stock_movements_table, techprime_ai_includes_inventory_alerts_inv_notify_stock_change [INFERRED 0.85]
- **Barcode Validation/Normalization/Rendering** — techprime_ai_includes_barcode_scanner, techprime_ai_includes_pos_helpers_pos_barcode_analyze, techprime_ai_includes_barcode_label, claude_barcode_normalization, handoff_generated_instore_barcodes [INFERRED 0.85]
- **Staff Page Guard Pattern** — techprime_ai_includes_security_checksessiontimeout, techprime_ai_includes_security_checkrole, techprime_ai_includes_staff_layout_staff_page_start, claude_auth_guards [EXTRACTED 1.00]

## Communities (97 total, 64 thin omitted)

### Community 0 - "Catalog Import Scripts"
Cohesion: 0.08
Nodes (46): collections, csv, io, pil, requests, subprocess, sys, db_category() (+38 more)

### Community 1 - "Primo ML Service"
Cohesion: 0.06
Nodes (42): DataFrame, flask, flask_cors, get, joblib, json, pandas, pathlib (+34 more)

### Community 2 - "Project Stack And Constraints"
Cohesion: 0.06
Nodes (37): EasyPC One Oasis Branch, .env Credentials (never read/edit/commit), USB Keyboard-wedge Barcode Scanner, Plain PHP 8.2 / XAMPP Stack (no framework), Project Rules (ask before delete/deps/DB writes), Stale MySQL Schema Dumps, Supabase Pooler Port 6543 (transaction pooling), Supabase PostgreSQL (live shared DB) (+29 more)

### Community 3 - "POS Checkout Integrity"
Cohesion: 0.10
Nodes (38): Audit Trail (logs table), client_ref UUID Idempotent Submits, Rolled-back Transaction Testing, Manual Verification Workflow, Checkout Integrity Design, FK ON DELETE SET NULL + Name Snapshots, Invoice Numbering (pos_invoice_seq), Receipt: THIS IS NOT AN OFFICIAL RECEIPT (+30 more)

### Community 4 - "Inventory Stocks And Barcodes"
Cohesion: 0.10
Nodes (31): Barcode Normalization (EAN-8 / 13-digit GTIN), Oasis-Itemlist.csv Catalog (261 products), POST-Redirect-GET Page Pattern, PHP Heredoc JS Interpolation Gotcha, Separate products.barcode Column (not sku), barcodeCellHtml(), barcodeLabel(), barcodeMenuItem() (+23 more)

### Community 5 - "Storefront And Stock Alerts"
Cohesion: 0.10
Nodes (30): Client Storefront, PayMongo Payments, Primo Chatbot Intent Service (SVM, 127.0.0.1:5055), Client Role (CLIENT/ storefront), Hardcoded Stock Thresholds (critical<=5, low<=15), Unreachable Critical Branch in inv_notify_stock_change, ep_add_product_to_cart(), ep_cancel_order() (+22 more)

### Community 6 - "Primo Chat Widget"
Cohesion: 0.14
Nodes (33): appendMessage(), appendTechMatchPromo(), clearPromoTimer(), closeChat(), csrf(), deleteStoredConversation(), escapeHtml(), formatBubbleText() (+25 more)

### Community 7 - "Session Auth And Primo Backend"
Cohesion: 0.09
Nodes (23): Session Auth & Role Guards, Absolute /login.php Redirect 404 Bug, API Session Expiry 401 session_expired, PDO, primo_find_products(), primo_format_order_line(), primo_handle_intent(), primo_map_product_rows() (+15 more)

### Community 8 - "Staff Dashboards"
Cohesion: 0.06
Nodes (15): admin_dashboard_preserve_hidden(), fcData, histData, sfCtx, ias_dashboard_qs(), h(), fcData, histData (+7 more)

### Community 9 - "Tech Match Builder"
Cohesion: 0.18
Nodes (29): applyLoadedBuild(), axisScore(), bind(), buildCompatMap(), buildScore(), candidateIncompatible(), clearCurrentBuild(), ensureCompat() (+21 more)

### Community 10 - "Retail Sales Reports"
Cohesion: 0.16
Nodes (17): DateTime, ias_fetch_all_sales_rows(), ias_fetch_delivery_rows(), ias_fetch_sales_rows(), ias_group_sales_by_order(), ias_monthly_sales_report(), ias_previous_period(), ias_product_demand_forecast() (+9 more)

### Community 11 - "Roles And Staff UI Shell"
Cohesion: 0.10
Nodes (17): API Response Convention (200 {ok:false} for business rejections), Cashier POS Checkout, Integer Centavos, VAT-inclusive 12% Pricing, Admin Role (ADMIN/), Cashier Role (CASHIER/), Inventory Custodian Role (INVENTORY/), Role-per-folder Modules, Retail Officer Role (RETAIL/) (+9 more)

### Community 12 - "Cashier POS Page"
Cohesion: 0.23
Nodes (22): Payment Methods (Cash/GCash/Maya/Card), closePay(), confirmPay(), findLine(), lookup(), onScan(), openPay(), parseMoney() (+14 more)

### Community 13 - "Legacy Storefront Script"
Cohesion: 0.26
Nodes (22): addRecent(), addToCart(), CATEGORY_META, currency(), defaultProducts(), ensureStore(), getProductById(), getProducts() (+14 more)

### Community 14 - "Shop Taxonomy And Brands"
Cohesion: 0.18
Nodes (12): ias_client_product_brand(), ep_shop_attach_taxonomy(), ep_shop_brand_logo_url(), ep_shop_brand_logos(), ep_shop_brands_ensure_schema(), ep_shop_classify_product(), ep_shop_is_complete_computer_product(), ep_shop_is_ssd_only_product() (+4 more)

### Community 15 - "Main SQL Schema"
Cohesion: 0.23
Nodes (12): `cart`, `locked_accounts`, `logs`, `messages`, `notifications`, `order_items`, `orders`, `products` (+4 more)

### Community 16 - "POS Database Migration"
Cohesion: 0.35
Nodes (10): products, idx_pos_sale_items_sale, idx_pos_sales_cashier_created, idx_pos_sales_created, idx_stock_movements_product, idx_stock_movements_user, pos_sale_items, pos_sales (+2 more)

### Community 17 - "Client Header And Search"
Cohesion: 0.29
Nodes (6): escapeHtml(), fetchSuggest(), hideSuggest(), highlightMatch(), renderSuggest(), runSearch()

### Community 18 - "Base Schema"
Cohesion: 0.25
Nodes (10): cart, locked_accounts, logs, messages, notifications, order_items, orders, products (+2 more)

### Community 19 - "Shop Page Filters"
Cohesion: 0.24
Nodes (3): formatPeso(), syncFromRanges(), updateUI()

### Community 20 - "Cashier Stock-In"
Cohesion: 0.39
Nodes (5): onScan(), readJson(), setFormEnabled(), setScanState(), showProduct()

### Community 21 - "Roles And Categories"
Cohesion: 0.36
Nodes (4): ias_admin_roles(), ias_can_access_admin_panel(), ias_staff_role_label(), ias_staff_roles()

### Community 22 - "Inventory Stock Charts"
Cohesion: 0.43
Nodes (4): buildSeries(), monthIndexInRange(), renderStockinChart(), updateBadge()

### Community 23 - "Courier Shipments Migration"
Cohesion: 0.47
Nodes (5): orders, idx_shipments_courier, idx_shipments_order, shipments, users

### Community 24 - "Auth Client JS"
Cohesion: 0.53
Nodes (4): authRequest(), initLoginPage(), initRegisterPage(), redirectByRole()

### Community 25 - "Primo Chat History"
Cohesion: 0.40
Nodes (3): ph_cleanup(), ph_ensure_tables(), PDO

### Community 26 - "Registration"
Cohesion: 0.40
Nodes (5): checkPasswordComplexity(), PDO, PW_RULES, register_users_has_phone(), setCheck()

### Community 27 - "Unused Barcode Stub"
Cohesion: 0.60
Nodes (4): ref_react, BarcodeScanner(), displayProductDetails(), searchProductByBarcode()

### Community 28 - "Admin Settings"
Cohesion: 0.60
Nodes (3): adjustVal(), setPrev(), updatePreview()

### Community 30 - "Category Page JS"
Cohesion: 0.83
Nodes (3): currency(), makeProducts(), renderCategoryPage()

### Community 33 - "Primo Chat Migration"
Cohesion: 0.67
Nodes (3): primo_conversations, primo_messages, users

### Community 34 - "PC Compatibility Rules"
Cohesion: 0.83
Nodes (3): ep_pc_compat_reason_for_candidate(), ep_pc_compat_tags(), ep_pc_compat_validate_build()

### Community 36 - "Catalog DB Helpers"
Cohesion: 0.67
Nodes (3): has_column(), PDO, seller_id()

## Ambiguous Edges - Review These
- `barcode_scanner.js` → `Broken unused INVENTORY/barcode.js Stub`  [AMBIGUOUS]
  HANDOFF.md · relation: semantically_similar_to

## Knowledge Gaps
- **48 isolated node(s):** ``cart``, ``messages``, ``notifications``, ``order_items``, ``site_settings`` (+43 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 259 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **64 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `barcode_scanner.js` and `Broken unused INVENTORY/barcode.js Stub`?**
  _Edge tagged AMBIGUOUS (relation: semantically_similar_to) - confidence is low._
- **Why does `pos_complete_sale()` connect `POS Checkout Integrity` to `Roles And Staff UI Shell`, `Storefront And Stock Alerts`, `Session Auth And Primo Backend`?**
  _High betweenness centrality (0.097) - this node is a cross-community bridge._
- **Why does `h()` connect `Staff Dashboards` to `Roles And Staff UI Shell`, `Session Auth And Primo Backend`?**
  _High betweenness centrality (0.070) - this node is a cross-community bridge._
- **Why does `logActivity()` connect `POS Checkout Integrity` to `Session Auth And Primo Backend`?**
  _High betweenness centrality (0.054) - this node is a cross-community bridge._
- **Are the 4 inferred relationships involving `pos_complete_sale()` (e.g. with `stock_out.php` and `ep_place_cod_order()`) actually correct?**
  _`pos_complete_sale()` has 4 INFERRED edges - model-reasoned connections that need verification._
- **Are the 2 inferred relationships involving `confirmPay()` (e.g. with `cashier_pos.php` and `readJson()`) actually correct?**
  _`confirmPay()` has 2 INFERRED edges - model-reasoned connections that need verification._
- **What connects ``cart``, ``messages``, ``notifications`` to the rest of the system?**
  _48 weakly-connected nodes found - possible documentation gaps or missing edges._