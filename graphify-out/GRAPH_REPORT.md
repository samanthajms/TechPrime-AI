# Graph Report - TechPrime-AI  (2026-10-13)

## Corpus Check
- 68 files · ~2,385,137 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1284 nodes · 2276 edges · 159 communities (57 shown, 102 thin omitted)
- Extraction: 91% EXTRACTED · 8% INFERRED · 0% AMBIGUOUS · INFERRED: 193 edges (avg confidence: 0.85)
- Token cost: 95,876 input · 0 output

## Community Hubs (Navigation)
- Tech Match Builder
- Primo Chat Widget
- Session Auth And Staff Layout
- Client Helpers And Inventory Alerts
- Retail Sales Reports
- Notifications And Forecast Cards
- Catalog Import Scripts
- Architecture Overview
- Inventory Stocks And Barcodes
- POS Checkout Helpers
- Primo Chat Backend
- Client Dark Mode CSS Builder
- Registration And Activation Mailer
- Staff Chat Library
- PHPMailer Library Docs
- Shop Taxonomy And Brand Logos
- Cashier POS Page
- Legacy Storefront Script
- Session Timeout Client
- Shop Listing Page
- Order Reviews
- Customer Header And Search
- Primo Predict API
- Primo Model Training
- Address Helpers
- Barcode Scanner Module
- Primo LLM Phrasing
- Legacy MySQL Dump
- Login Hero Animation
- POS Schema Migration
- Login Circuit Animation
- Customer Cart Page
- Legacy Schema SQL
- Oasis Product Catalog
- PH Address Picker
- Saved PC Builds Page
- Cashier Stock Alerts Widget
- Primo History API
- Staff Chat Widget
- Order Reviews Migration
- Custodian Stock-In Page
- Message Features Migration
- Primo Text Preprocessing
- Cashier Stock Alerts Page
- Inventory Dashboard Charts
- Primo Intent Tests
- Courier Shipments Migration
- Legacy Auth JS
- Order Review Modal
- Barcode Label Renderer
- Inventory Reviews Page
- Legacy Barcode JS
- Admin Settings Page
- PC Compatibility Rules
- Primo Python Requirements
- Legacy Category JS
- Saved Builds API
- Primo Chat Migration
- Catalog DB Helper
- Composer Dependencies
- Saved Builds Migration
- Site Settings Migration
- UI Alert Modal
- Serena Project Config
- Table Cart
- Table Locked Accounts
- Table Logs
- Table Message Hidden
- Table Message Pins
- Table Message Reactions
- Table Messages
- Table Notifications
- Table Order Items
- Table Primo Conversations
- Table Primo Messages
- Table Products
- Table Reviews
- Table Saved Builds
- Table Shipments
- Table Shop Brands
- Table Site Settings
- Table Wishlist
- Table Orders
- Table Users
- Table Messages
- Users
- Python Path Import
- Ping Health Check

## God Nodes (most connected - your core abstractions)
1. `bind()` - 22 edges
2. `h()` - 22 edges
3. `escapeHtml()` - 17 edges
4. `confirmPay()` - 15 edges
5. `staff_profile_page()` - 15 edges
6. `pos_complete_sale()` - 15 edges
7. `peso()` - 15 edges
8. `renderRight()` - 15 edges
9. `refresh()` - 14 edges
10. `sendChat()` - 13 edges

## Surprising Connections (you probably didn't know these)
- `vlucas/phpdotenv (.env loader, sole Composer dependency)` --conceptually_related_to--> `PHPMailer (email creation and transfer library)`  [AMBIGUOUS]
  CLAUDE.md → TechPrime-AI/backend/vendor/PHPMailer-master/README.md
- `uploads/chat/ attachments (.htaccess guard + index.html)` --semantically_similar_to--> `uploads/reviews/index.html (empty directory-listing guard)`  [INFERRED] [semantically similar]
  CLAUDE.md → TechPrime-AI/uploads/reviews/index.html
- `Primo chat UI -> backend/api/primo_chat.php bridge` --semantically_similar_to--> `backend/api/primo_chat.php (PHP chat bridge)`  [INFERRED] [semantically similar]
  CLAUDE.md → TechPrime-AI/ml/primo/README.md
- `Empty uploads/chat index.html (directory listing guard)` --conceptually_related_to--> `Project rules (ask before deletes, deps, DB writes, role changes; never touch .env)`  [INFERRED]
  TechPrime-AI/uploads/chat/index.html → CLAUDE.md
- `ep_render_address_fields()` --calls--> `h()`  [INFERRED]
  TechPrime-AI/includes/address_helpers.php → TechPrime-AI/includes/security.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Primo chatbot request flow (storefront UI -> PHP bridge -> Python SVM API)** — claude_client_storefront, claude_primo_chat_bridge, techprime_ai_ml_primo_readme_primo_chat_php, techprime_ai_ml_primo_readme_primo_api_url, techprime_ai_ml_primo_readme_predict_api, techprime_ai_ml_primo_readme_train_model [EXTRACTED 1.00]
- **POS barcode handling (scanner JS, PHP analyzer, label generator, sale completion)** — claude_barcode_scanner_js, claude_pos_barcode_analyze, claude_barcode_label_js, claude_pos_complete_sale, claude_cashier_pos [INFERRED 0.85]
- **Evolution of non-ASCII email support** — techprime_ai_backend_vendor_phpmailer_master_smtputf8_8bitmime, techprime_ai_backend_vendor_phpmailer_master_smtputf8_rfc2047, techprime_ai_backend_vendor_phpmailer_master_smtputf8_idn_punycode, techprime_ai_backend_vendor_phpmailer_master_smtputf8_smtputf8, techprime_ai_backend_vendor_phpmailer_master_smtputf8_eai_validator [EXTRACTED 1.00]

## Communities (159 total, 102 thin omitted)

### Community 0 - "Tech Match Builder"
Cohesion: 0.09
Nodes (77): alertUi(), applyComponents(), applyLoadedBuild(), axisScore(), bind(), buildScore(), buildSummaryText(), candidateIncompatible() (+69 more)

### Community 1 - "Primo Chat Widget"
Cohesion: 0.08
Nodes (63): appendChips(), appendMessage(), applyModality(), autosize(), botContentHtml(), closeChat(), csrf(), deleteStoredConversation() (+55 more)

### Community 2 - "Session Auth And Staff Layout"
Cohesion: 0.06
Nodes (43): ep_user_profile_image_url(), inv_relative_time(), checkRole(), checkSessionTimeout(), generateCsrfToken(), getPasswordRules(), ias_alert_footer(), ias_alert_message_from_request() (+35 more)

### Community 3 - "Client Helpers And Inventory Alerts"
Cohesion: 0.08
Nodes (46): ep_accept_order(), ep_accept_order_cancel(), ep_add_product_to_cart(), ep_cancel_order(), ep_complete_order(), ep_confirm_order_received(), ep_ensure_session_cart(), ep_ensure_session_wishlist() (+38 more)

### Community 4 - "Retail Sales Reports"
Cohesion: 0.07
Nodes (42): DateTime, getDbConnection(), PDO, getDb(), ias_forecast_months(), ias_forecast_selection(), ias_admin_roles(), ias_can_access_admin_panel() (+34 more)

### Community 5 - "Notifications And Forecast Cards"
Cohesion: 0.06
Nodes (33): DateTimeImmutable, ep_notif_day_label(), ep_notif_local(), ep_notif_message_html(), ep_notif_meta(), ep_notif_relative_time(), ep_notif_render_item(), ep_notif_safe_link() (+25 more)

### Community 6 - "Catalog Import Scripts"
Cohesion: 0.08
Nodes (46): collections, csv, io, pil, requests, subprocess, sys, db_category() (+38 more)

### Community 7 - "Architecture Overview"
Cohesion: 0.05
Nodes (43): includes/barcode_label.js (printable EAN-13/UPC-A/EAN-8 SVG labels), includes/barcode_scanner.js (keyboard-wedge scanner handling), Build a PC (build_a_pc.php, tech_match, pc_compatibility.php), Cashier POS (includes/pos_helpers.php, barcode_lookup/stock_out/stock_in APIs), checkSessionTimeout(), Client dark mode (generated ep_dark_auto.css + manual ep_dark.css), Client storefront (CLIENT/, client_helpers.php, COD orders, PayMongo), ep_place_cod_order() (atomic stock deduction) (+35 more)

### Community 8 - "Inventory Stocks And Barcodes"
Cohesion: 0.12
Nodes (27): barcodeCellHtml(), barcodeLabel(), barcodeMenuItem(), cardHtml(), deleteOne(), escapeHtml(), findProduct(), generateBarcodes() (+19 more)

### Community 9 - "POS Checkout Helpers"
Cohesion: 0.14
Nodes (32): PDO, pos_api_require_cashier(), pos_api_require_role(), pos_barcode_analyze(), pos_cents_to_str(), pos_complete_sale(), pos_db_money_cents(), pos_err() (+24 more)

### Community 10 - "Primo Chat Backend"
Cohesion: 0.12
Nodes (27): PDO, primo_brand_advice(), primo_brand_from_name(), primo_brand_stats(), primo_category_name_pattern(), primo_find_products(), primo_format_order_line(), primo_handle_intent() (+19 more)

### Community 11 - "Client Dark Mode CSS Builder"
Cohesion: 0.12
Nodes (29): colorsys, collect_vars(), convert(), convert_rule(), fmt(), from_hls(), info(), is_strong_bg() (+21 more)

### Community 12 - "Registration And Activation Mailer"
Cohesion: 0.11
Nodes (27): PHPMailer\PHPMailer\Exception, PHPMailer\PHPMailer\PHPMailer, ias_activation_email_hours(), ias_activation_email_html(), ias_activation_email_text(), sendActivationEmail(), check(), checkMatch() (+19 more)

### Community 13 - "Staff Chat Library"
Cohesion: 0.11
Nodes (24): PDO, staff_chat_allowed_roles(), staff_chat_attachment_types(), staff_chat_clean_filename(), staff_chat_emoji_allowed(), staff_chat_emoji_groups(), staff_chat_has_is_read(), staff_chat_is_image_mime() (+16 more)

### Community 14 - "PHPMailer Library Docs"
Cohesion: 0.09
Nodes (26): DKIM and S/MIME signing, Header injection protection and automatic address validation, LGPL 2.1 license with GPL Cooperation Commitment, PHPMailer localization (setLanguage, language folder), Manual loading without Composer (Exception.php, PHPMailer.php, SMTP.php), PHP mail() function (discouraged), PHPMailer (email creation and transfer library), SMTP auth mechanisms (LOGIN, PLAIN, CRAM-MD5, XOAUTH2) (+18 more)

### Community 15 - "Shop Taxonomy And Brand Logos"
Cohesion: 0.15
Nodes (17): ias_client_product_brand(), ep_shop_attach_taxonomy(), ep_shop_brand_logo_url(), ep_shop_brand_logos(), ep_shop_brands_ensure_schema(), ep_shop_category_tiles(), ep_shop_classify_product(), ep_shop_inventory_allowed_category_values() (+9 more)

### Community 16 - "Cashier POS Page"
Cohesion: 0.25
Nodes (21): closePay(), confirmPay(), findLine(), lookup(), onScan(), openPay(), parseMoney(), payFail() (+13 more)

### Community 17 - "Legacy Storefront Script"
Cohesion: 0.26
Nodes (22): addRecent(), addToCart(), CATEGORY_META, currency(), defaultProducts(), ensureStore(), getProductById(), getProducts() (+14 more)

### Community 18 - "Session Timeout Client"
Cohesion: 0.22
Nodes (20): apply(), broadcast(), check(), closeDialog(), day(), end(), fmt(), hideWarning() (+12 more)

### Community 19 - "Shop Listing Page"
Cohesion: 0.18
Nodes (9): buildParams(), clearScope(), formatPeso(), load(), resetFilters(), scheduleLoad(), syncActiveOptions(), syncFromRanges() (+1 more)

### Community 20 - "Order Reviews"
Cohesion: 0.24
Nodes (12): ep_review_attach_media(), ep_review_decode_tags(), ep_review_ensure_schema(), ep_review_list(), ep_review_map_for_user(), ep_review_media_dir(), ep_review_remove_videos(), ep_review_reply() (+4 more)

### Community 21 - "Customer Header And Search"
Cohesion: 0.25
Nodes (13): closeAll(), escapeHtml(), fetchSuggest(), hideSuggest(), highlightMatch(), isSafeSearchTerm(), loadRecents(), renderSuggest() (+5 more)

### Community 22 - "Primo Predict API"
Cohesion: 0.16
Nodes (12): BaseException, flask, flask_cors, get, os, get_threshold(), health(), _llm_error_text() (+4 more)

### Community 23 - "Primo Model Training"
Cohesion: 0.16
Nodes (13): DataFrame, pandas, Path, Pipeline, sklearn_feature_extraction_text, sklearn_metrics, sklearn_model_selection, sklearn_pipeline (+5 more)

### Community 24 - "Address Helpers"
Cohesion: 0.30
Nodes (12): ep_address_columns(), ep_address_empty(), ep_address_format(), ep_address_from_input(), ep_address_is_complete(), ep_checkout_resolve_address(), ep_psgc_barangays(), ep_psgc_provinces() (+4 more)

### Community 25 - "Barcode Scanner Module"
Cohesion: 0.22
Nodes (11): analyze(), attach(), emit(), refocus(), beep(), escapeHtml(), gtinCheckDigit(), gtinValid() (+3 more)

### Community 26 - "Primo LLM Phrasing"
Cohesion: 0.22
Nodes (13): post, chat(), chat_reply(), _groq_post(), _llm_public_status(), _llm_settings(), _message_text(), phrase() (+5 more)

### Community 27 - "Legacy MySQL Dump"
Cohesion: 0.23
Nodes (12): `cart`, `locked_accounts`, `logs`, `messages`, `notifications`, `order_items`, `orders`, `products` (+4 more)

### Community 28 - "Login Hero Animation"
Cohesion: 0.27
Nodes (8): glow(), layout(), pcScene(), newStreak(), rand(), storeScene(), makePuff(), newMote()

### Community 29 - "POS Schema Migration"
Cohesion: 0.35
Nodes (10): products, idx_pos_sale_items_sale, idx_pos_sales_cashier_created, idx_pos_sales_created, idx_stock_movements_product, idx_stock_movements_user, pos_sale_items, pos_sales (+2 more)

### Community 30 - "Login Circuit Animation"
Cohesion: 0.42
Nodes (10): build(), drawBoard(), frame(), free(), key(), pointAt(), rand(), route() (+2 more)

### Community 31 - "Customer Cart Page"
Cohesion: 0.33
Nodes (8): checkedRows(), money(), postForm(), refreshTotals(), rows(), saveQty(), saveSelection(), setText()

### Community 32 - "Legacy Schema SQL"
Cohesion: 0.25
Nodes (10): cart, locked_accounts, logs, messages, notifications, order_items, orders, products (+2 more)

### Community 33 - "Oasis Product Catalog"
Cohesion: 0.20
Nodes (10): Catalog Columns (Category, MSKU, Description, Warranty, Price, Qty On Hand), Catalog Data Quality Issues (blank description, misplaced SKU text, placeholder prices), Laptop Tiers (GA2, GA3, PR2, PR3) and Mini PC, Oasis Item List Catalog (261 products), PC Component Categories (Cooling, PC Case, Processor, Motherboard, Graphic Card, Memory, PSU, SSD), Peripheral Categories (Display, Audio, Speaker, Mouse, Keyboard, Combo, Network, Printer), Promotional Discount Lines (negative-price SDISC promos), Catalog Brands (MSI, ASUS, AMD, RAKK, DarkFlash, CoolerMaster, Gigabyte) (+2 more)

### Community 34 - "PH Address Picker"
Cohesion: 0.56
Nodes (9): fill(), fire(), init(), onCity(), onProvince(), provinceByName(), initAll(), load() (+1 more)

### Community 35 - "Saved PC Builds Page"
Cohesion: 0.44
Nodes (9): addToCart(), confirmDelete(), deleteBuild(), handleAction(), loadIntoBuilder(), openDialog(), openViewModal(), postJson() (+1 more)

### Community 37 - "Primo History API"
Cohesion: 0.31
Nodes (5): ph_attach_products(), ph_bullet_product_names(), ph_cleanup(), ph_ensure_tables(), PDO

### Community 38 - "Staff Chat Widget"
Cohesion: 0.44
Nodes (8): close(), createFrame(), esc(), initials(), open(), refreshBadge(), setSummary(), tellFrame()

### Community 39 - "Order Reviews Migration"
Cohesion: 0.36
Nodes (7): public, order_review_media_review_idx, order_reviews_user_idx, public.order_review_media, public.order_reviews, public.orders, public.users

### Community 40 - "Custodian Stock-In Page"
Cohesion: 0.43
Nodes (6): fillSuppliers(), onScan(), readJson(), setFormEnabled(), setScanState(), showProduct()

### Community 41 - "Message Features Migration"
Cohesion: 0.52
Nodes (6): public.users, messages_pair_idx, public.message_hidden, public.message_pins, public.message_reactions, public.messages

### Community 42 - "Primo Text Preprocessing"
Cohesion: 0.33
Nodes (6): re, preprocess_text(), Shared text preprocessing for Primo SVM intent classification., Lowercase, expand light contractions, clean punctuation, normalize spaces., Optional helper for handlers (not used by TF-IDF directly)., tokenize_hint()

### Community 43 - "Cashier Stock Alerts Page"
Cohesion: 0.48
Nodes (5): getFiltered(), render(), renderPagination(), rowHtml(), setData()

### Community 44 - "Inventory Dashboard Charts"
Cohesion: 0.43
Nodes (4): buildSeries(), monthIndexInRange(), renderStockinChart(), updateBadge()

### Community 45 - "Primo Intent Tests"
Cohesion: 0.33
Nodes (4): joblib, json, pathlib, Quick intent tests against the trained SVM pipeline (no Flask required). Usage:…

### Community 46 - "Courier Shipments Migration"
Cohesion: 0.47
Nodes (5): orders, idx_shipments_courier, idx_shipments_order, shipments, users

### Community 47 - "Legacy Auth JS"
Cohesion: 0.53
Nodes (4): authRequest(), initLoginPage(), initRegisterPage(), redirectByRole()

### Community 48 - "Order Review Modal"
Cohesion: 0.47
Nodes (3): openModal(), paintStars(), textWithTags()

### Community 49 - "Barcode Label Renderer"
Cohesion: 0.60
Nodes (5): checkDigit(), modules(), printed(), svg(), valid()

### Community 50 - "Inventory Reviews Page"
Cohesion: 0.47
Nodes (3): openReply(), showEdit(), showView()

### Community 51 - "Legacy Barcode JS"
Cohesion: 0.60
Nodes (4): ref_react, BarcodeScanner(), displayProductDetails(), searchProductByBarcode()

### Community 53 - "Admin Settings Page"
Cohesion: 0.60
Nodes (3): adjustVal(), setPrev(), updatePreview()

### Community 54 - "PC Compatibility Rules"
Cohesion: 0.70
Nodes (4): ep_pc_compat_reason_for_candidate(), ep_pc_compat_tags(), ep_pc_compat_validate_build(), ep_pc_cpu_ddr()

### Community 56 - "Primo Python Requirements"
Cohesion: 0.40
Nodes (5): flask>=3.0 / flask-cors>=4.0, joblib>=1.3, pandas>=2.0 / numpy>=1.24, Primo Python Requirements, scikit-learn>=1.3

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
- `vlucas/phpdotenv (.env loader, sole Composer dependency)` → `PHPMailer (email creation and transfer library)`  [AMBIGUOUS]
  CLAUDE.md · relation: conceptually_related_to

## Knowledge Gaps
- **60 isolated node(s):** `flask>=3.0 / flask-cors>=4.0`, `pandas>=2.0 / numpy>=1.24`, `scikit-learn>=1.3`, `CATEGORY_META`, `STORE_KEYS` (+55 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 376 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **102 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `vlucas/phpdotenv (.env loader, sole Composer dependency)` and `PHPMailer (email creation and transfer library)`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **Why does `h()` connect `Notifications And Forecast Cards` to `Address Helpers`, `Session Auth And Staff Layout`, `Registration And Activation Mailer`?**
  _High betweenness centrality (0.041) - this node is a cross-community bridge._
- **Why does `staff_profile_page()` connect `Session Auth And Staff Layout` to `POS Checkout Helpers`, `Client Helpers And Inventory Alerts`, `Notifications And Forecast Cards`?**
  _High betweenness centrality (0.028) - this node is a cross-community bridge._
- **Why does `logActivity()` connect `Client Helpers And Inventory Alerts` to `POS Checkout Helpers`, `Session Auth And Staff Layout`, `Order Reviews`, `Registration And Activation Mailer`?**
  _High betweenness centrality (0.026) - this node is a cross-community bridge._
- **Are the 7 inferred relationships involving `bind()` (e.g. with `clearCurrentBuild()` and `editBudget()`) actually correct?**
  _`bind()` has 7 INFERRED edges - model-reasoned connections that need verification._
- **Are the 19 inferred relationships involving `h()` (e.g. with `ep_render_address_fields()` and `ep_notif_message_html()`) actually correct?**
  _`h()` has 19 INFERRED edges - model-reasoned connections that need verification._
- **Are the 2 inferred relationships involving `confirmPay()` (e.g. with `cashier_pos.php` and `readJson()`) actually correct?**
  _`confirmPay()` has 2 INFERRED edges - model-reasoned connections that need verification._