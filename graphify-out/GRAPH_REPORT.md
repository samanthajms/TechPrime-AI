# Graph Report - TechPrime-AI  (2026-10-16)

## Corpus Check
- 50 files · ~1,910,839 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1383 nodes · 2491 edges · 181 communities (72 shown, 109 thin omitted)
- Extraction: 93% EXTRACTED · 7% INFERRED · 0% AMBIGUOUS · INFERRED: 179 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Build-a-PC Tech Match UI
- Primo Chat Widget JS
- Client Orders & Cart Helpers
- Oasis Catalog Importer
- Retail Reports & Forecast Data
- Primo Chat Backend
- Inventory Stocks Page
- Cashier POS Helpers
- Client Dark CSS Generator
- Registration & Activation Email
- PDF Report Generator
- Primo Intent API (Flask)
- Client Shop Taxonomy
- Cashier POS Checkout Page
- Auth & Security Guards
- Legacy Storefront script.js
- DB Connection & Categories
- Forecast Cards Script
- Session Timeout Dialog
- Staff Chat Library
- Shop Page Filters
- Order Reviews
- Client Header & Search
- PC Recommendation Model
- Staff Sidebar Layout
- Address Helpers
- Barcode Scanner (USB wedge)
- Project Docs: DB & Rules
- Legacy MySQL Dump
- Staff Shell & Profile
- Forecast Cards & Order Cards
- Staff Chat Endpoint
- Login Hero Animation
- XLSX Export Writer
- Project Docs: Auth & UI
- Primo Model Training
- Client Notifications
- POS Migration (Postgres)
- Login Circuit Animation
- Client Cart Page
- Legacy MySQL Schema
- Oasis Item List Catalog
- PH Address Picker
- Saved Builds Page
- PHPMailer Overview
- PHPMailer Security CVEs
- Cashier Stock Alerts JS
- Primo Chat History API
- Staff Chat Floating Widget
- Retail Dashboard Page
- Primo Service Docs
- PC Recommend Training
- Order Reviews Migration
- PHPMailer SMTPUTF8 Docs
- Custodian Stock-In Page
- Messages Features Migration
- Primo Text Preprocessing
- Cashier Stock Alerts Page
- Staff Messages UI
- Inventory Dashboard Charts
- Retail Reports Page
- Project Docs: POS & Barcodes
- Project Docs: Storefront & Sales
- Primo Intent Tests
- Courier Migration (unused)
- Legacy auth.js
- Order Review Modal JS
- Barcode Label Renderer
- Inventory Reviews Page
- Legacy Barcode Lookup JS
- Admin Settings Page
- Forecast Card Toggles
- PC Compatibility Rules
- Primo Python Requirements
- Legacy category.js
- Saved Builds API
- Primo Chat Migration
- Catalog DB Helper Script
- Composer Dependencies
- Saved Builds Migration
- Site Settings Migration
- IAS_UI Modal Alerts
- Serena Project Config
- DateTime (table ref)
- public.cart (table ref)
- public.locked_accounts (table ref)
- public.logs (table ref)
- public.message_hidden (table ref)
- public.message_pins (table ref)
- public.message_reactions (table ref)
- public.messages (table ref)
- public.notifications (table ref)
- public.order_items (table ref)
- public.primo_conversations (table ref)
- public.primo_messages (table ref)
- public.products (table ref)
- public.reviews (table ref)
- public.saved_builds (table ref)
- public.shipments (table ref)
- public.shop_brands (table ref)
- public.site_settings (table ref)
- public.wishlist (table ref)
- public.orders (table ref)
- public.users (table ref)
- public.messages (table ref)
- users (table ref)
- Path (table ref)
- ping.txt

## God Nodes (most connected - your core abstractions)
1. `IasPdfReport` - 27 edges
2. `bind()` - 23 edges
3. `h()` - 22 edges
4. `escapeHtml()` - 19 edges
5. `staff_profile_page()` - 15 edges
6. `pos_complete_sale()` - 15 edges
7. `confirmPay()` - 15 edges
8. `peso()` - 15 edges
9. `renderRight()` - 15 edges
10. `refresh()` - 14 edges

## Surprising Connections (you probably didn't know these)
- `vlucas/phpdotenv (.env loader, sole Composer dependency)` --conceptually_related_to--> `PHPMailer (email creation and transfer library)`  [AMBIGUOUS]
  CLAUDE.md → TechPrime-AI/backend/vendor/PHPMailer-master/README.md
- `Primo chat UI -> backend/api/primo_chat.php bridge` --semantically_similar_to--> `backend/api/primo_chat.php (PHP chat bridge)`  [INFERRED] [semantically similar]
  CLAUDE.md → TechPrime-AI/ml/primo/README.md
- `uploads/chat/ attachments (.htaccess guard + index.html)` --semantically_similar_to--> `uploads/reviews/index.html (empty directory-listing guard)`  [INFERRED] [semantically similar]
  CLAUDE.md → TechPrime-AI/uploads/reviews/index.html
- `reg_hint()` --calls--> `h()`  [INFERRED]
  TechPrime-AI/register.php → TechPrime-AI/includes/security.php
- `reg_old()` --calls--> `h()`  [INFERRED]
  TechPrime-AI/register.php → TechPrime-AI/includes/security.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Evolution of non-ASCII email support** — techprime_ai_backend_vendor_phpmailer_master_smtputf8_8bitmime, techprime_ai_backend_vendor_phpmailer_master_smtputf8_rfc2047, techprime_ai_backend_vendor_phpmailer_master_smtputf8_idn_punycode, techprime_ai_backend_vendor_phpmailer_master_smtputf8_smtputf8, techprime_ai_backend_vendor_phpmailer_master_smtputf8_eai_validator [EXTRACTED 1.00]
- **Primo chatbot request flow (storefront UI -> PHP bridge -> Python SVM API)** — claude_client_storefront, claude_primo_chat_bridge, techprime_ai_ml_primo_readme_primo_chat_php, techprime_ai_ml_primo_readme_primo_api_url, techprime_ai_ml_primo_readme_predict_api, techprime_ai_ml_primo_readme_train_model [EXTRACTED 1.00]
- **POS barcode handling (scanner JS, PHP analyzer, label generator, sale completion)** — claude_barcode_scanner_js, claude_pos_barcode_analyze, claude_barcode_label_js, claude_pos_complete_sale, claude_cashier_pos [INFERRED 0.85]

## Communities (181 total, 109 thin omitted)

### Community 0 - "Build-a-PC Tech Match UI"
Cohesion: 0.09
Nodes (80): alertUi(), applyComponents(), applyLoadedBuild(), axisScore(), bind(), buildScore(), buildSummaryText(), candidateIncompatible() (+72 more)

### Community 1 - "Primo Chat Widget JS"
Cohesion: 0.07
Nodes (67): appendChips(), appendMessage(), applyModality(), autosize(), botContentHtml(), closeChat(), csrf(), deleteStoredConversation() (+59 more)

### Community 2 - "Client Orders & Cart Helpers"
Cohesion: 0.07
Nodes (48): ep_accept_order(), ep_accept_order_cancel(), ep_add_product_to_cart(), ep_cancel_order(), ep_complete_order(), ep_confirm_order_received(), ep_ensure_session_cart(), ep_ensure_session_wishlist() (+40 more)

### Community 3 - "Oasis Catalog Importer"
Cohesion: 0.08
Nodes (46): collections, csv, io, pil, requests, subprocess, sys, db_category() (+38 more)

### Community 4 - "Retail Reports & Forecast Data"
Cohesion: 0.12
Nodes (28): ias_forecast_months(), ias_forecast_selection(), ias_dashboard_qs(), ias_delivered_units_by_date(), ias_delivered_units_by_group(), ias_fetch_all_sales_rows(), ias_fetch_delivery_rows(), ias_fetch_order_items_by_order() (+20 more)

### Community 5 - "Primo Chat Backend"
Cohesion: 0.12
Nodes (30): PDO, primo_brand_advice(), primo_brand_from_name(), primo_brand_stats(), primo_category_name_pattern(), primo_find_products(), primo_format_order_line(), primo_general_reply() (+22 more)

### Community 6 - "Inventory Stocks Page"
Cohesion: 0.12
Nodes (27): barcodeCellHtml(), barcodeLabel(), barcodeMenuItem(), cardHtml(), deleteOne(), escapeHtml(), findProduct(), generateBarcodes() (+19 more)

### Community 7 - "Cashier POS Helpers"
Cohesion: 0.14
Nodes (32): PDO, pos_api_require_cashier(), pos_api_require_role(), pos_barcode_analyze(), pos_cents_to_str(), pos_complete_sale(), pos_db_money_cents(), pos_err() (+24 more)

### Community 8 - "Client Dark CSS Generator"
Cohesion: 0.12
Nodes (29): colorsys, collect_vars(), convert(), convert_rule(), fmt(), from_hls(), info(), is_strong_bg() (+21 more)

### Community 9 - "Registration & Activation Email"
Cohesion: 0.11
Nodes (27): PHPMailer\PHPMailer\Exception, PHPMailer\PHPMailer\PHPMailer, ias_activation_email_hours(), ias_activation_email_html(), ias_activation_email_text(), sendActivationEmail(), check(), checkMatch() (+19 more)

### Community 11 - "Primo Intent API (Flask)"
Cohesion: 0.12
Nodes (25): BaseException, flask, flask_cors, get, os, post, chat(), chat_reply() (+17 more)

### Community 12 - "Client Shop Taxonomy"
Cohesion: 0.15
Nodes (17): ias_client_product_brand(), ep_shop_attach_taxonomy(), ep_shop_brand_logo_url(), ep_shop_brand_logos(), ep_shop_brands_ensure_schema(), ep_shop_category_tiles(), ep_shop_classify_product(), ep_shop_inventory_allowed_category_values() (+9 more)

### Community 13 - "Cashier POS Checkout Page"
Cohesion: 0.25
Nodes (21): closePay(), confirmPay(), findLine(), lookup(), onScan(), openPay(), parseMoney(), payFail() (+13 more)

### Community 14 - "Auth & Security Guards"
Cohesion: 0.14
Nodes (16): checkRole(), checkSessionTimeout(), getPasswordRules(), ias_alert_footer(), ias_alert_message_from_request(), ias_app_base_url(), ias_expire_session(), ias_login_url() (+8 more)

### Community 15 - "Legacy Storefront script.js"
Cohesion: 0.26
Nodes (22): addRecent(), addToCart(), CATEGORY_META, currency(), defaultProducts(), ensureStore(), getProductById(), getProducts() (+14 more)

### Community 16 - "DB Connection & Categories"
Cohesion: 0.14
Nodes (17): getDbConnection(), PDO, getDb(), ias_admin_roles(), ias_can_access_admin_panel(), ias_category_expand(), ias_category_group_members(), ias_category_group_of() (+9 more)

### Community 17 - "Forecast Cards Script"
Cohesion: 0.21
Nodes (16): badge(), cell(), el(), emptyRow(), ias_forecast_view_toggle(), kpis(), momCell(), num() (+8 more)

### Community 18 - "Session Timeout Dialog"
Cohesion: 0.22
Nodes (20): apply(), broadcast(), check(), closeDialog(), day(), end(), fmt(), hideWarning() (+12 more)

### Community 19 - "Staff Chat Library"
Cohesion: 0.16
Nodes (14): PDO, staff_chat_allowed_roles(), staff_chat_attachment_types(), staff_chat_clean_filename(), staff_chat_emoji_allowed(), staff_chat_emoji_groups(), staff_chat_has_is_read(), staff_chat_inspect_file() (+6 more)

### Community 20 - "Shop Page Filters"
Cohesion: 0.18
Nodes (9): buildParams(), clearScope(), formatPeso(), load(), resetFilters(), scheduleLoad(), syncActiveOptions(), syncFromRanges() (+1 more)

### Community 21 - "Order Reviews"
Cohesion: 0.24
Nodes (12): ep_review_attach_media(), ep_review_decode_tags(), ep_review_ensure_schema(), ep_review_list(), ep_review_map_for_user(), ep_review_media_dir(), ep_review_remove_videos(), ep_review_reply() (+4 more)

### Community 22 - "Client Header & Search"
Cohesion: 0.25
Nodes (13): closeAll(), escapeHtml(), fetchSuggest(), hideSuggest(), highlightMatch(), isSafeSearchTerm(), loadRecents(), renderSuggest() (+5 more)

### Community 23 - "PC Recommendation Model"
Cohesion: 0.23
Nodes (14): pc_recommend_anchor_price(), pc_recommend_build_map(), pc_recommend_feature_names(), pc_recommend_features(), pc_recommend_model(), pc_recommend_model_path(), pc_recommend_power_estimate(), pc_recommend_proba() (+6 more)

### Community 24 - "Staff Sidebar Layout"
Cohesion: 0.19
Nodes (6): apply(), isDesktop(), onBreakpoint(), readCollapsed(), setDrawer(), staff_logout_href()

### Community 25 - "Address Helpers"
Cohesion: 0.30
Nodes (12): ep_address_columns(), ep_address_empty(), ep_address_format(), ep_address_from_input(), ep_address_is_complete(), ep_checkout_resolve_address(), ep_psgc_barangays(), ep_psgc_provinces() (+4 more)

### Community 26 - "Barcode Scanner (USB wedge)"
Cohesion: 0.22
Nodes (11): analyze(), attach(), emit(), refocus(), beep(), escapeHtml(), gtinCheckDigit(), gtinValid() (+3 more)

### Community 27 - "Project Docs: DB & Rules"
Cohesion: 0.15
Nodes (13): getDbConnection() (backend/config/database.php), HANDOFF.md (Cashier/POS status and gotchas), Legacy getDb() in includes/db.php (do not use), Oasis-Itemlist.csv catalog (261 products), vlucas/phpdotenv (.env loader, sole Composer dependency), Project rules (ask before deletes, deps, DB writes, role changes; never touch .env), redirectByRole() in login.php, Role-per-folder modules (ADMIN, RETAIL, INVENTORY, CASHIER, CLIENT) (+5 more)

### Community 28 - "Legacy MySQL Dump"
Cohesion: 0.23
Nodes (12): `cart`, `locked_accounts`, `logs`, `messages`, `notifications`, `order_items`, `orders`, `products` (+4 more)

### Community 29 - "Staff Shell & Profile"
Cohesion: 0.22
Nodes (12): ep_user_profile_image_url(), isPasswordComplex(), staff_chat_avatar_url(), staff_logo_href(), staff_nav_for_role(), staff_page_start(), staff_role_label(), staff_user_initials() (+4 more)

### Community 30 - "Forecast Cards & Order Cards"
Cohesion: 0.21
Nodes (10): ep_render_client_order_card(), ep_render_orders_pagination(), ias_forecast_card_css(), ias_forecast_cards_render(), ias_forecast_months_field(), ias_forecast_preserve_hidden(), ias_forecast_range_fields(), generateCsrfToken() (+2 more)

### Community 31 - "Staff Chat Endpoint"
Cohesion: 0.31
Nodes (10): PDO, staff_chat_fetch(), staff_chat_format(), staff_chat_mark_read(), staff_chat_own_message(), staff_chat_pins(), staff_chat_reactions(), staff_chat_snippet() (+2 more)

### Community 32 - "Login Hero Animation"
Cohesion: 0.27
Nodes (8): glow(), layout(), pcScene(), newStreak(), rand(), storeScene(), makePuff(), newMote()

### Community 34 - "Project Docs: Auth & UI"
Cohesion: 0.18
Nodes (10): checkSessionTimeout(), IAS_UI modal alerts (includes/ui_alerts.js), Page pattern (POST handlers on top, redirect with ?alert=, server render + embedded JSON), Auth/guards (includes/security.php: checkSessionTimeout, checkRole, CSRF, h()), includes/session_timeout.js (idle warning dialog), Staff chat (staff_chat_messages.php AJAX endpoint + floating widget), staff_profile_page() (includes/staff_profile.php), Staff UI shell (staff_page_start/staff_page_end, staff_shared.css) (+2 more)

### Community 35 - "Primo Model Training"
Cohesion: 0.22
Nodes (10): DataFrame, Path, Pipeline, sklearn_feature_extraction_text, sklearn_pipeline, sklearn_svm, build_pipeline(), load_dataset() (+2 more)

### Community 36 - "Client Notifications"
Cohesion: 0.33
Nodes (9): DateTimeImmutable, ep_notif_day_label(), ep_notif_local(), ep_notif_message_html(), ep_notif_meta(), ep_notif_relative_time(), ep_notif_render_item(), ep_notif_safe_link() (+1 more)

### Community 37 - "POS Migration (Postgres)"
Cohesion: 0.35
Nodes (10): products, idx_pos_sale_items_sale, idx_pos_sales_cashier_created, idx_pos_sales_created, idx_stock_movements_product, idx_stock_movements_user, pos_sale_items, pos_sales (+2 more)

### Community 38 - "Login Circuit Animation"
Cohesion: 0.42
Nodes (10): build(), drawBoard(), frame(), free(), key(), pointAt(), rand(), route() (+2 more)

### Community 39 - "Client Cart Page"
Cohesion: 0.33
Nodes (8): checkedRows(), money(), postForm(), refreshTotals(), rows(), saveQty(), saveSelection(), setText()

### Community 40 - "Legacy MySQL Schema"
Cohesion: 0.25
Nodes (10): cart, locked_accounts, logs, messages, notifications, order_items, orders, products (+2 more)

### Community 41 - "Oasis Item List Catalog"
Cohesion: 0.20
Nodes (10): Catalog Columns (Category, MSKU, Description, Warranty, Price, Qty On Hand), Catalog Data Quality Issues (blank description, misplaced SKU text, placeholder prices), Laptop Tiers (GA2, GA3, PR2, PR3) and Mini PC, Oasis Item List Catalog (261 products), PC Component Categories (Cooling, PC Case, Processor, Motherboard, Graphic Card, Memory, PSU, SSD), Peripheral Categories (Display, Audio, Speaker, Mouse, Keyboard, Combo, Network, Printer), Promotional Discount Lines (negative-price SDISC promos), Catalog Brands (MSI, ASUS, AMD, RAKK, DarkFlash, CoolerMaster, Gigabyte) (+2 more)

### Community 42 - "PH Address Picker"
Cohesion: 0.56
Nodes (9): fill(), fire(), init(), onCity(), onProvince(), provinceByName(), initAll(), load() (+1 more)

### Community 43 - "Saved Builds Page"
Cohesion: 0.44
Nodes (9): addToCart(), confirmDelete(), deleteBuild(), handleAction(), loadIntoBuilder(), openDialog(), openViewModal(), postJson() (+1 more)

### Community 44 - "PHPMailer Overview"
Cohesion: 0.22
Nodes (9): DKIM and S/MIME signing, Header injection protection and automatic address validation, LGPL 2.1 license with GPL Cooperation Commitment, Manual loading without Composer (Exception.php, PHPMailer.php, SMTP.php), PHP mail() function (discouraged), PHPMailer (email creation and transfer library), SMTP auth mechanisms (LOGIN, PLAIN, CRAM-MD5, XOAUTH2), PHPMailer integrated SMTP client (SMTP.php) (+1 more)

### Community 45 - "PHPMailer Security CVEs"
Cohesion: 0.25
Nodes (9): PHPMailer localization (setLanguage, language folder), CVE-2016-10033 / CVE-2016-10045 (remote code execution, sender injection), CVE-2017-5223 (msgHTML local file disclosure), CVE-2018-19296 (phar:// object injection via addAttachment), CVE-2020-13625 (attachment filename escaping bug), CVE-2020-36326 (regression of CVE-2018-19296 in 6.1.8-6.4.0), CVE-2021-34551 (setLanguage $lang_path UNC RCE), PHPMailer security notices (CVE history) (+1 more)

### Community 47 - "Primo Chat History API"
Cohesion: 0.31
Nodes (5): ph_attach_products(), ph_bullet_product_names(), ph_cleanup(), ph_ensure_tables(), PDO

### Community 48 - "Staff Chat Floating Widget"
Cohesion: 0.44
Nodes (8): close(), createFrame(), esc(), initials(), open(), refreshBadge(), setSummary(), tellFrame()

### Community 50 - "Primo Service Docs"
Cohesion: 0.36
Nodes (8): Primo chat UI -> backend/api/primo_chat.php bridge, GET /health (model_loaded, 200/503), predict_api.py (HTTP API on 127.0.0.1:5055), PRIMO_API_URL env var (defaults to http://127.0.0.1:5055), backend/api/primo_chat.php (PHP chat bridge), Primo SVM Intent Service (ml/primo), Render deployment (Python 3.12.5, build/start commands, health check), train_model.py (SVM intent training)

### Community 51 - "PC Recommend Training"
Cohesion: 0.29
Nodes (7): pandas, sklearn_ensemble, sklearn_metrics, sklearn_model_selection, export_tree(), main(), Train the Build a PC Random Forest recommender. Reads data/train.csv (written…

### Community 52 - "Order Reviews Migration"
Cohesion: 0.36
Nodes (7): public, order_review_media_review_idx, order_reviews_user_idx, public.order_review_media, public.order_reviews, public.orders, public.users

### Community 53 - "PHPMailer SMTPUTF8 Docs"
Cohesion: 0.25
Nodes (8): CVE-2021-3603 (validateAddress 'php' validator function hijack), 8BITMIME SMTP extension (RFC 1652 / RFC 6152), Content transfer encodings (quoted-printable, base64), EAI address validator (PHPMailer::$validator = 'eai'), IDN / punycode (RFC 3492), Postfix SMTPUTF8 advertisement gotcha, RFC 2047 header encoding, SMTPUTF8 extension (RFC 6531)

### Community 54 - "Custodian Stock-In Page"
Cohesion: 0.43
Nodes (6): fillSuppliers(), onScan(), readJson(), setFormEnabled(), setScanState(), showProduct()

### Community 55 - "Messages Features Migration"
Cohesion: 0.52
Nodes (6): public.users, messages_pair_idx, public.message_hidden, public.message_pins, public.message_reactions, public.messages

### Community 56 - "Primo Text Preprocessing"
Cohesion: 0.33
Nodes (6): re, preprocess_text(), Shared text preprocessing for Primo SVM intent classification., Lowercase, expand light contractions, clean punctuation, normalize spaces., Optional helper for handlers (not used by TF-IDF directly)., tokenize_hint()

### Community 57 - "Cashier Stock Alerts Page"
Cohesion: 0.48
Nodes (5): getFiltered(), render(), renderPagination(), rowHtml(), setData()

### Community 58 - "Staff Messages UI"
Cohesion: 0.43
Nodes (6): staff_css_href(), SM, staff_messages_embed_page(), staff_messages_extra_head(), staff_messages_is_embed(), staff_messages_render()

### Community 59 - "Inventory Dashboard Charts"
Cohesion: 0.43
Nodes (4): buildSeries(), monthIndexInRange(), renderStockinChart(), updateBadge()

### Community 60 - "Retail Reports Page"
Cohesion: 0.29
Nodes (3): catCtx, ias_qs(), trendCtx

### Community 61 - "Project Docs: POS & Barcodes"
Cohesion: 0.33
Nodes (6): includes/barcode_label.js (printable EAN-13/UPC-A/EAN-8 SVG labels), includes/barcode_scanner.js (keyboard-wedge scanner handling), Cashier POS (includes/pos_helpers.php, barcode_lookup/stock_out/stock_in APIs), Stock alerts/notifications (includes/inventory_alerts.php, inv_notify_stock_change), pos_barcode_analyze() (PHP barcode validation), backend/api/stock_alerts.php (30s polling, no session refresh)

### Community 62 - "Project Docs: Storefront & Sales"
Cohesion: 0.33
Nodes (6): Build a PC (build_a_pc.php, tech_match, pc_compatibility.php), Client dark mode (generated ep_dark_auto.css + manual ep_dark.css), Client storefront (CLIENT/, client_helpers.php, COD orders, PayMongo), ep_place_cod_order() (atomic stock deduction), logActivity() audit trail (logs table), pos_complete_sale() (FOR UPDATE locking, idempotent client_ref)

### Community 63 - "Primo Intent Tests"
Cohesion: 0.33
Nodes (4): joblib, json, pathlib, Quick intent tests against the trained SVM pipeline (no Flask required). Usage:…

### Community 64 - "Courier Migration (unused)"
Cohesion: 0.47
Nodes (5): orders, idx_shipments_courier, idx_shipments_order, shipments, users

### Community 65 - "Legacy auth.js"
Cohesion: 0.53
Nodes (4): authRequest(), initLoginPage(), initRegisterPage(), redirectByRole()

### Community 66 - "Order Review Modal JS"
Cohesion: 0.47
Nodes (3): openModal(), paintStars(), textWithTags()

### Community 67 - "Barcode Label Renderer"
Cohesion: 0.60
Nodes (5): checkDigit(), modules(), printed(), svg(), valid()

### Community 68 - "Inventory Reviews Page"
Cohesion: 0.47
Nodes (3): openReply(), showEdit(), showView()

### Community 69 - "Legacy Barcode Lookup JS"
Cohesion: 0.60
Nodes (4): ref_react, BarcodeScanner(), displayProductDetails(), searchProductByBarcode()

### Community 71 - "Admin Settings Page"
Cohesion: 0.60
Nodes (3): adjustVal(), setPrev(), updatePreview()

### Community 73 - "PC Compatibility Rules"
Cohesion: 0.70
Nodes (4): ep_pc_compat_reason_for_candidate(), ep_pc_compat_tags(), ep_pc_compat_validate_build(), ep_pc_cpu_ddr()

### Community 75 - "Primo Python Requirements"
Cohesion: 0.40
Nodes (5): flask>=3.0 / flask-cors>=4.0, joblib>=1.3, pandas>=2.0 / numpy>=1.24, Primo Python Requirements, scikit-learn>=1.3

### Community 78 - "Legacy category.js"
Cohesion: 0.83
Nodes (3): currency(), makeProducts(), renderCategoryPage()

### Community 83 - "Primo Chat Migration"
Cohesion: 0.67
Nodes (3): primo_conversations, primo_messages, users

### Community 84 - "Catalog DB Helper Script"
Cohesion: 0.67
Nodes (3): has_column(), PDO, seller_id()

## Ambiguous Edges - Review These
- `PHPMailer (email creation and transfer library)` → `vlucas/phpdotenv (.env loader, sole Composer dependency)`  [AMBIGUOUS]
  CLAUDE.md · relation: conceptually_related_to

## Knowledge Gaps
- **59 isolated node(s):** `Catalog Columns (Category, MSKU, Description, Warranty, Price, Qty On Hand)`, `Catalog Data Quality Issues (blank description, misplaced SKU text, placeholder prices)`, `flask>=3.0 / flask-cors>=4.0`, `pandas>=2.0 / numpy>=1.24`, `scikit-learn>=1.3` (+54 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 398 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **109 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `PHPMailer (email creation and transfer library)` and `vlucas/phpdotenv (.env loader, sole Composer dependency)`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **Why does `h()` connect `Forecast Cards & Order Cards` to `Client Notifications`, `Retail Reports & Forecast Data`, `Registration & Activation Email`, `Auth & Security Guards`, `Retail Dashboard Page`, `Address Helpers`, `Staff Messages UI`, `Retail Reports Page`, `Staff Shell & Profile`?**
  _High betweenness centrality (0.050) - this node is a cross-community bridge._
- **Why does `logActivity()` connect `Client Orders & Cart Helpers` to `Cashier POS Helpers`, `Registration & Activation Email`, `Auth & Security Guards`, `Order Reviews`, `Staff Shell & Profile`?**
  _High betweenness centrality (0.027) - this node is a cross-community bridge._
- **Why does `ias_client_product_image_url()` connect `Client Orders & Cart Helpers` to `Primo Chat Backend`, `Client Shop Taxonomy`, `Auth & Security Guards`, `Primo Chat History API`, `PC Recommendation Model`, `Forecast Cards & Order Cards`?**
  _High betweenness centrality (0.017) - this node is a cross-community bridge._
- **Are the 7 inferred relationships involving `bind()` (e.g. with `clearCurrentBuild()` and `editBudget()`) actually correct?**
  _`bind()` has 7 INFERRED edges - model-reasoned connections that need verification._
- **Are the 19 inferred relationships involving `h()` (e.g. with `ep_render_address_fields()` and `ep_notif_message_html()`) actually correct?**
  _`h()` has 19 INFERRED edges - model-reasoned connections that need verification._
- **What connects `Catalog Columns (Category, MSKU, Description, Warranty, Price, Qty On Hand)`, `Catalog Data Quality Issues (blank description, misplaced SKU text, placeholder prices)`, `flask>=3.0 / flask-cors>=4.0` to the rest of the system?**
  _59 weakly-connected nodes found - possible documentation gaps or missing edges._