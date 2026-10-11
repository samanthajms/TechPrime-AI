# Graph Report - TechPrime-AI  (2026-10-05)

## Corpus Check
- 178 files · ~2,299,271 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1055 nodes · 1866 edges · 138 communities (59 shown, 79 thin omitted)
- Extraction: 91% EXTRACTED · 9% INFERRED · 0% AMBIGUOUS · INFERRED: 160 edges (avg confidence: 0.85)
- Token cost: 91,360 input · 0 output

## Community Hubs (Navigation)
- Tech Match Builder
- Catalog Import Scripts
- Primo ML Service
- Forecasting And Retail History
- Primo Chat Widget
- Inventory Stocks And Barcodes
- Storefront Cart And Orders
- POS Checkout Helpers
- Client Dark Mode CSS Builder
- Retail Sales Reports
- Shop Taxonomy And Brand Logos
- Cashier POS Page
- Legacy Storefront Script
- Session Timeout Client
- Staff Layout And Alerts Panel
- Session Auth And CSRF Guards
- Shop Listing Page
- Staff Chat Library
- Primo Chat Backend
- Customer Header And Search
- Address Helpers
- Barcode Scanner Module
- Staff Chat Messages API
- Legacy MySQL Dump
- Login Hero Animation
- POS Schema Migration
- Login Circuit Animation
- Customer Cart Page
- Legacy Schema SQL
- User Profile And Audit Helpers
- Registration Form
- PH Address Picker
- Saved PC Builds Page
- Oasis Product Catalog
- Cashier Stock Alerts Widget
- Staff Chat Widget
- POS Conventions And Patterns
- Database Rules And Constraints
- Product Categories And Permissions
- Staff Messages UI
- Custodian Stock-In Page
- Architecture Overview
- Checkout Integrity Design
- Message Features Migration
- Cashier Stock Alerts Page
- Inventory Dashboard Charts
- Barcode Design Decisions
- Courier Shipments Migration
- Legacy Auth JS
- Primo History API
- Barcode Label Renderer
- Project Identity And Stack
- Stock Thresholds And SKU
- Legacy Barcode JS
- Admin Settings Page
- Database Connection
- PC Compatibility Rules
- Activation Mailer
- Legacy Category JS
- Saved Builds API
- Primo Chat Migration
- Catalog DB Helper
- Composer Dependencies
- Saved Builds Migration
- Site Settings Migration
- UI Alert Modal
- Public.Messages
- Users

## God Nodes (most connected - your core abstractions)
1. `bind()` - 22 edges
2. `escapeHtml()` - 16 edges
3. `confirmPay()` - 15 edges
4. `pos_complete_sale()` - 15 edges
5. `h()` - 15 edges
6. `staff_profile_page()` - 15 edges
7. `peso()` - 14 edges
8. `renderRight()` - 14 edges
9. `refresh()` - 14 edges
10. `staff_page_start()` - 14 edges

## Surprising Connections (you probably didn't know these)
- `Promotional Discount Lines (negative-price SDISC promos)` --conceptually_related_to--> `Checkout Integrity (FOR UPDATE ORDER BY id, guarded UPDATE)`  [AMBIGUOUS]
  graphify-out/converted/Oasis-Itemlist_e8aef282.md → HANDOFF.md
- `TechPrime AI Inventory/E-commerce System` --references--> `Oasis Item List Catalog (261 products)`  [INFERRED]
  CLAUDE.md → graphify-out/converted/Oasis-Itemlist_e8aef282.md
- `Cashier Stock Alerts 30s Polling` --references--> `Hardcoded Stock Alert Thresholds (critical<=5, low<=15)`  [INFERRED]
  HANDOFF.md → CLAUDE.md
- `ep_render_address_fields()` --calls--> `h()`  [INFERRED]
  TechPrime-AI/includes/address_helpers.php → TechPrime-AI/includes/security.php
- `ias_dashboard_qs()` --calls--> `h()`  [INFERRED]
  TechPrime-AI/includes/retail_reports.php → TechPrime-AI/includes/security.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Primo train / serve / test lifecycle** — techprime_ai_ml_primo_train_model, techprime_ai_ml_primo_readme_predict_api_py, techprime_ai_ml_primo_test_intents [EXTRACTED 1.00]
- **Primo chat request flow (UI -> PHP bridge -> Flask SVM API)** — techprime_ai_ml_primo_readme_primo_ui, techprime_ai_ml_primo_readme_primo_chat_php, techprime_ai_ml_primo_readme_predict_api_py [INFERRED 0.85]
- **Atomic POS Checkout Flow** — claude_cashier_pos, handoff_checkout_integrity, handoff_client_ref_idempotency, handoff_migration_cashier_pos, handoff_invoice_numbering, handoff_custodian_stock_notifications, claude_activity_logs_audit_trail [EXTRACTED 1.00]
- **Barcode Scan, Normalize and Label Subsystem** — claude_barcode_normalization, handoff_usb_keyboard_wedge_scanner, handoff_svg_barcode_label_renderer, handoff_in_store_barcode_generation [EXTRACTED 1.00]
- **Stock Level Alerting** — claude_stock_alert_thresholds, handoff_cashier_stock_alerts_polling, handoff_custodian_stock_notifications, handoff_inv_notify_critical_branch_bug [INFERRED 0.85]

## Communities (138 total, 79 thin omitted)

### Community 0 - "Tech Match Builder"
Cohesion: 0.09
Nodes (75): alertUi(), applyComponents(), applyLoadedBuild(), axisScore(), bind(), buildScore(), buildSummaryText(), candidateIncompatible() (+67 more)

### Community 1 - "Catalog Import Scripts"
Cohesion: 0.08
Nodes (46): collections, csv, io, pil, requests, subprocess, sys, db_category() (+38 more)

### Community 2 - "Primo ML Service"
Cohesion: 0.06
Nodes (43): DataFrame, flask, flask_cors, get, joblib, json, os, pandas (+35 more)

### Community 3 - "Forecasting And Retail History"
Cohesion: 0.05
Nodes (25): DateTimeImmutable, admin_forecast_preserve_hidden(), fcData, histData, sfCtx, ep_notif_day_label(), ep_notif_local(), ep_notif_message_html() (+17 more)

### Community 4 - "Primo Chat Widget"
Cohesion: 0.13
Nodes (34): appendMessage(), appendTechMatchPromo(), clearPromoTimer(), closeChat(), csrf(), deleteStoredConversation(), escapeHtml(), formatBubbleText() (+26 more)

### Community 5 - "Inventory Stocks And Barcodes"
Cohesion: 0.12
Nodes (27): barcodeCellHtml(), barcodeLabel(), barcodeMenuItem(), cardHtml(), deleteOne(), escapeHtml(), findProduct(), generateBarcodes() (+19 more)

### Community 6 - "Storefront Cart And Orders"
Cohesion: 0.13
Nodes (28): ep_add_product_to_cart(), ep_cancel_order(), ep_ensure_session_cart(), ep_ensure_session_wishlist(), ep_get_cart_preview(), ep_place_cod_order(), ep_profile_image_ensure_schema(), ep_set_cart_quantity() (+20 more)

### Community 7 - "POS Checkout Helpers"
Cohesion: 0.14
Nodes (32): PDO, pos_api_require_cashier(), pos_api_require_role(), pos_barcode_analyze(), pos_cents_to_str(), pos_complete_sale(), pos_db_money_cents(), pos_err() (+24 more)

### Community 8 - "Client Dark Mode CSS Builder"
Cohesion: 0.12
Nodes (29): colorsys, collect_vars(), convert(), convert_rule(), fmt(), from_hls(), info(), is_strong_bg() (+21 more)

### Community 9 - "Retail Sales Reports"
Cohesion: 0.15
Nodes (19): DateTime, ias_dashboard_qs(), ias_fetch_all_sales_rows(), ias_fetch_delivery_rows(), ias_fetch_sales_rows(), ias_group_sales_by_order(), ias_monthly_sales_report(), ias_previous_period() (+11 more)

### Community 10 - "Shop Taxonomy And Brand Logos"
Cohesion: 0.15
Nodes (17): ias_client_product_brand(), ep_shop_attach_taxonomy(), ep_shop_brand_logo_url(), ep_shop_brand_logos(), ep_shop_brands_ensure_schema(), ep_shop_category_tiles(), ep_shop_classify_product(), ep_shop_inventory_allowed_category_values() (+9 more)

### Community 11 - "Cashier POS Page"
Cohesion: 0.25
Nodes (21): closePay(), confirmPay(), findLine(), lookup(), onScan(), openPay(), parseMoney(), payFail() (+13 more)

### Community 12 - "Legacy Storefront Script"
Cohesion: 0.26
Nodes (22): addRecent(), addToCart(), CATEGORY_META, currency(), defaultProducts(), ensureStore(), getProductById(), getProducts() (+14 more)

### Community 13 - "Session Timeout Client"
Cohesion: 0.22
Nodes (20): apply(), broadcast(), check(), closeDialog(), day(), end(), fmt(), hideWarning() (+12 more)

### Community 14 - "Staff Layout And Alerts Panel"
Cohesion: 0.15
Nodes (11): inv_relative_time(), apply(), isDesktop(), onBreakpoint(), readCollapsed(), setDrawer(), staff_logo_href(), staff_logout_href() (+3 more)

### Community 15 - "Session Auth And CSRF Guards"
Cohesion: 0.18
Nodes (16): checkRole(), checkSessionTimeout(), generateCsrfToken(), ias_alert_footer(), ias_alert_message_from_request(), ias_app_base_url(), ias_expire_session(), ias_login_url() (+8 more)

### Community 16 - "Shop Listing Page"
Cohesion: 0.18
Nodes (9): buildParams(), clearScope(), formatPeso(), load(), resetFilters(), scheduleLoad(), syncActiveOptions(), syncFromRanges() (+1 more)

### Community 17 - "Staff Chat Library"
Cohesion: 0.18
Nodes (14): PDO, staff_chat_allowed_roles(), staff_chat_attachment_types(), staff_chat_clean_filename(), staff_chat_emoji_allowed(), staff_chat_emoji_groups(), staff_chat_has_is_read(), staff_chat_inspect_file() (+6 more)

### Community 18 - "Primo Chat Backend"
Cohesion: 0.24
Nodes (11): PDO, primo_find_products(), primo_format_order_line(), primo_handle_intent(), primo_map_product_rows(), primo_order_status(), primo_product_intent(), primo_query_products() (+3 more)

### Community 19 - "Customer Header And Search"
Cohesion: 0.27
Nodes (11): escapeHtml(), fetchSuggest(), hideSuggest(), highlightMatch(), isSafeSearchTerm(), loadRecents(), renderSuggest(), runSearch() (+3 more)

### Community 20 - "Address Helpers"
Cohesion: 0.30
Nodes (12): ep_address_columns(), ep_address_empty(), ep_address_format(), ep_address_from_input(), ep_address_is_complete(), ep_checkout_resolve_address(), ep_psgc_barangays(), ep_psgc_provinces() (+4 more)

### Community 21 - "Barcode Scanner Module"
Cohesion: 0.22
Nodes (11): analyze(), attach(), emit(), refocus(), beep(), escapeHtml(), gtinCheckDigit(), gtinValid() (+3 more)

### Community 22 - "Staff Chat Messages API"
Cohesion: 0.29
Nodes (11): staff_chat_is_image_mime(), PDO, staff_chat_fetch(), staff_chat_format(), staff_chat_mark_read(), staff_chat_own_message(), staff_chat_pins(), staff_chat_reactions() (+3 more)

### Community 23 - "Legacy MySQL Dump"
Cohesion: 0.23
Nodes (12): `cart`, `locked_accounts`, `logs`, `messages`, `notifications`, `order_items`, `orders`, `products` (+4 more)

### Community 24 - "Login Hero Animation"
Cohesion: 0.27
Nodes (8): glow(), layout(), pcScene(), newStreak(), rand(), storeScene(), makePuff(), newMote()

### Community 25 - "POS Schema Migration"
Cohesion: 0.35
Nodes (10): products, idx_pos_sale_items_sale, idx_pos_sales_cashier_created, idx_pos_sales_created, idx_stock_movements_product, idx_stock_movements_user, pos_sale_items, pos_sales (+2 more)

### Community 26 - "Login Circuit Animation"
Cohesion: 0.42
Nodes (10): build(), drawBoard(), frame(), free(), key(), pointAt(), rand(), route() (+2 more)

### Community 27 - "Customer Cart Page"
Cohesion: 0.33
Nodes (8): checkedRows(), money(), postForm(), refreshTotals(), rows(), saveQty(), saveSelection(), setText()

### Community 28 - "Legacy Schema SQL"
Cohesion: 0.25
Nodes (10): cart, locked_accounts, logs, messages, notifications, order_items, orders, products (+2 more)

### Community 29 - "User Profile And Audit Helpers"
Cohesion: 0.24
Nodes (10): ep_user_profile_image_url(), getPasswordRules(), isPasswordComplex(), logActivity(), staff_chat_avatar_url(), staff_user_initials(), PDO, staff_profile_format_ts() (+2 more)

### Community 30 - "Registration Form"
Cohesion: 0.31
Nodes (10): checkMatch(), checkPasswordComplexity(), fieldValid(), mark(), onEdit(), PDO, PW_RULES, register_users_has_phone() (+2 more)

### Community 31 - "PH Address Picker"
Cohesion: 0.56
Nodes (9): fill(), fire(), init(), onCity(), onProvince(), provinceByName(), initAll(), load() (+1 more)

### Community 32 - "Saved PC Builds Page"
Cohesion: 0.44
Nodes (9): addToCart(), confirmDelete(), deleteBuild(), handleAction(), loadIntoBuilder(), openDialog(), openViewModal(), postJson() (+1 more)

### Community 33 - "Oasis Product Catalog"
Cohesion: 0.22
Nodes (9): Catalog Data Quality Issues (blank description, misplaced SKU text, placeholder prices), Laptop Tiers (GA2, GA3, PR2, PR3) and Mini PC, Oasis Item List Catalog (261 products), PC Component Categories (Cooling, PC Case, Processor, Motherboard, Graphic Card, Memory, PSU, SSD), Peripheral Categories (Display, Audio, Speaker, Mouse, Keyboard, Combo, Network, Printer), Catalog Brands (MSI, ASUS, AMD, RAKK, DarkFlash, CoolerMaster, Gigabyte), Warranty Types (1 YR, EasyFix, No WTY, 1 MON, 1 WK), Stock-in Moved from Cashier to Inventory Custodian (+1 more)

### Community 35 - "Staff Chat Widget"
Cohesion: 0.44
Nodes (8): close(), createFrame(), esc(), initials(), open(), refreshBadge(), setSummary(), tellFrame()

### Community 36 - "POS Conventions And Patterns"
Cohesion: 0.29
Nodes (8): Activity Logs Audit Trail (logs table), Cashier POS Checkout, PHP Heredoc Interpolation Gotcha in Inline JS, POS JSON API Convention (200 ok:false for business rejections), POST-then-Redirect Page Pattern with ?alert= Flash, Cashier Role + Barcode POS Handoff, Printable Sales Invoice (NOT AN OFFICIAL RECEIPT), VAT-inclusive 12% Pricing in Centavos

### Community 37 - "Database Rules And Constraints"
Cohesion: 0.25
Nodes (8): Project Rules (stop and ask before deletes, deps, DB writes), Stale MySQL Schema Dumps, Supabase Pooler Transaction Pooling (port 6543), Live Shared Supabase PostgreSQL Database, Hardcoded DB Credentials in includes/db.php, Handoff Open Items (scanner test, push, real barcodes, cleanup), Live Test Data (test cashier #62, test barcodes, INV-...-000004), Empty uploads/chat index.html (directory listing guard)

### Community 38 - "Product Categories And Permissions"
Cohesion: 0.36
Nodes (4): ias_admin_roles(), ias_can_access_admin_panel(), ias_staff_role_label(), ias_staff_roles()

### Community 39 - "Staff Messages UI"
Cohesion: 0.36
Nodes (7): staff_chat_endpoint_href(), staff_css_href(), SM, staff_messages_embed_page(), staff_messages_extra_head(), staff_messages_is_embed(), staff_messages_render()

### Community 40 - "Custodian Stock-In Page"
Cohesion: 0.43
Nodes (6): fillSuppliers(), onScan(), readJson(), setFormEnabled(), setScanState(), showProduct()

### Community 41 - "Architecture Overview"
Cohesion: 0.29
Nodes (7): Client Storefront (session cart, COD, PayMongo), CSRF Token Protection, Primo Chatbot SVM Intent Service (127.0.0.1:5055), Role-per-Folder Modules (ADMIN/RETAIL/INVENTORY/CASHIER/CLIENT), Session Idle Timeout and Role Guards, Staff UI Shell (staff_layout + staff_shared.css), Cashier Stock Alerts 30s Polling

### Community 42 - "Checkout Integrity Design"
Cohesion: 0.29
Nodes (7): Rolled-back Transaction Verification Approach, Promotional Discount Lines (negative-price SDISC promos), Checkout Integrity (FOR UPDATE ORDER BY id, guarded UPDATE), client_ref UUID Idempotent Submit, FK ON DELETE SET NULL + Name Snapshots, Invoice Numbering INV-YYYYMMDD-NNNNNN (pos_invoice_seq), migration_cashier_pos.sql (pos_sales, pos_sale_items, stock_movements)

### Community 43 - "Message Features Migration"
Cohesion: 0.52
Nodes (6): public.users, messages_pair_idx, public.message_hidden, public.message_pins, public.message_reactions, public.messages

### Community 44 - "Cashier Stock Alerts Page"
Cohesion: 0.48
Nodes (5): getFiltered(), render(), renderPagination(), rowHtml(), setData()

### Community 45 - "Inventory Dashboard Charts"
Cohesion: 0.43
Nodes (4): buildSeries(), monthIndexInRange(), renderStockinChart(), updateBadge()

### Community 46 - "Barcode Design Decisions"
Cohesion: 0.33
Nodes (6): Barcode Normalization (EAN-8 / 13-digit GTIN), Value Plus Freebies (zero-price bundle items), In-store Barcode Generation (21 + product id + check digit), No New Dependencies Decision, Dependency-free SVG Barcode Label Renderer, USB Keyboard-Wedge Barcode Scanner Module

### Community 47 - "Courier Shipments Migration"
Cohesion: 0.47
Nodes (5): orders, idx_shipments_courier, idx_shipments_order, shipments, users

### Community 48 - "Legacy Auth JS"
Cohesion: 0.53
Nodes (4): authRequest(), initLoginPage(), initRegisterPage(), redirectByRole()

### Community 49 - "Primo History API"
Cohesion: 0.40
Nodes (3): ph_cleanup(), ph_ensure_tables(), PDO

### Community 50 - "Barcode Label Renderer"
Cohesion: 0.60
Nodes (5): checkDigit(), modules(), printed(), svg(), valid()

### Community 51 - "Project Identity And Stack"
Cohesion: 0.40
Nodes (5): Serena Project Config (PHP language server), EasyPC One Oasis Branch (Rosario, Pasig), Plain PHP 8.2 on XAMPP (no framework), TechPrime AI Inventory/E-commerce System, ping.txt Health Check (ok)

### Community 52 - "Stock Thresholds And SKU"
Cohesion: 0.40
Nodes (5): products.sku = EasyPC MSKU Item Code, Hardcoded Stock Alert Thresholds (critical<=5, low<=15), Catalog Columns (Category, MSKU, Description, Warranty, Price, Qty On Hand), Custodian Stock-change Notifications (12h dedupe), Unreachable Critically-low Branch in inv_notify_stock_change

### Community 53 - "Legacy Barcode JS"
Cohesion: 0.60
Nodes (4): ref_react, BarcodeScanner(), displayProductDetails(), searchProductByBarcode()

### Community 54 - "Admin Settings Page"
Cohesion: 0.60
Nodes (3): adjustVal(), setPrev(), updatePreview()

### Community 55 - "Database Connection"
Cohesion: 0.40
Nodes (3): getDbConnection(), PDO, getDb()

### Community 56 - "PC Compatibility Rules"
Cohesion: 0.70
Nodes (4): ep_pc_compat_reason_for_candidate(), ep_pc_compat_tags(), ep_pc_compat_validate_build(), ep_pc_cpu_ddr()

### Community 59 - "Legacy Category JS"
Cohesion: 0.83
Nodes (3): currency(), makeProducts(), renderCategoryPage()

### Community 64 - "Primo Chat Migration"
Cohesion: 0.67
Nodes (3): primo_conversations, primo_messages, users

### Community 65 - "Catalog DB Helper"
Cohesion: 0.67
Nodes (3): has_column(), PDO, seller_id()

## Ambiguous Edges - Review These
- `Checkout Integrity (FOR UPDATE ORDER BY id, guarded UPDATE)` → `Promotional Discount Lines (negative-price SDISC promos)`  [AMBIGUOUS]
  graphify-out/converted/Oasis-Itemlist_e8aef282.md · relation: conceptually_related_to

## Knowledge Gaps
- **44 isolated node(s):** `Primo UI`, `pandas>=2.0 / numpy>=1.24`, `CATEGORY_META`, `STORE_KEYS`, ``cart`` (+39 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 301 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **79 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `Checkout Integrity (FOR UPDATE ORDER BY id, guarded UPDATE)` and `Promotional Discount Lines (negative-price SDISC promos)`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **Why does `h()` connect `Forecasting And Retail History` to `Staff Messages UI`, `Retail Sales Reports`, `Staff Layout And Alerts Panel`, `Session Auth And CSRF Guards`, `Address Helpers`, `User Profile And Audit Helpers`?**
  _High betweenness centrality (0.048) - this node is a cross-community bridge._
- **Why does `logActivity()` connect `User Profile And Audit Helpers` to `Session Auth And CSRF Guards`, `POS Checkout Helpers`?**
  _High betweenness centrality (0.020) - this node is a cross-community bridge._
- **Why does `pos_normalize_barcode()` connect `POS Checkout Helpers` to `Inventory Stocks And Barcodes`?**
  _High betweenness centrality (0.018) - this node is a cross-community bridge._
- **Are the 7 inferred relationships involving `bind()` (e.g. with `clearCurrentBuild()` and `editBudget()`) actually correct?**
  _`bind()` has 7 INFERRED edges - model-reasoned connections that need verification._
- **Are the 2 inferred relationships involving `confirmPay()` (e.g. with `cashier_pos.php` and `readJson()`) actually correct?**
  _`confirmPay()` has 2 INFERRED edges - model-reasoned connections that need verification._
- **Are the 2 inferred relationships involving `pos_complete_sale()` (e.g. with `inv_notify_stock_change()` and `logActivity()`) actually correct?**
  _`pos_complete_sale()` has 2 INFERRED edges - model-reasoned connections that need verification._