# Graph Report - TechPrime-AI  (2026-09-26)

## Corpus Check
- Large corpus: 434 files · ~1,551,625 words. Semantic extraction will be expensive (many Claude tokens). Consider running on a subfolder.

## Summary
- 574 nodes · 854 edges · 94 communities (28 shown, 66 thin omitted)
- Extraction: 95% EXTRACTED · 5% INFERRED · 0% AMBIGUOUS · INFERRED: 39 edges (avg confidence: 0.84)
- Token cost: 44,746 input · 0 output

## Community Hubs (Navigation)
- Catalog Import Scripts
- Primo ML Service
- Primo Chat Widget
- Staff Dashboards
- Primo Chat Backend
- Tech Match Builder
- Client Cart And Orders
- Retail Sales Reports
- Inventory Stock Management
- Legacy Storefront Script
- DB Connection And Staff Layout
- Shop Taxonomy And Brands
- Main SQL Schema
- Client Header And Search
- Base Schema
- Shop Page Filters
- Roles And Categories
- Inventory Stock Charts
- Courier Shipments Migration
- Auth Client JS
- Primo Chat History
- Registration
- Barcode Scanner
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
- products
- users

## God Nodes (most connected - your core abstractions)
1. `staff_page_start()` - 11 edges
2. `readJson()` - 11 edges
3. `process_product()` - 11 edges
4. `refresh()` - 10 edges
5. `currency()` - 10 edges
6. `getProducts()` - 10 edges
7. `main()` - 10 edges
8. `bind()` - 9 edges
9. `preprocess_text()` - 9 edges
10. `writeJson()` - 9 edges

## Surprising Connections (you probably didn't know these)
- `admin_dashboard_preserve_hidden()` --calls--> `h()`  [INFERRED]
  TechPrime-AI/ADMIN/admin_dashboard.php → TechPrime-AI/includes/security.php
- `retail_dashboard_preserve_hidden()` --calls--> `h()`  [INFERRED]
  TechPrime-AI/RETAIL/retail_dashboard.php → TechPrime-AI/includes/security.php
- `ias_qs2()` --calls--> `h()`  [INFERRED]
  TechPrime-AI/RETAIL/retail_history.php → TechPrime-AI/includes/security.php
- `ias_qs()` --calls--> `h()`  [INFERRED]
  TechPrime-AI/RETAIL/retail_reports.php → TechPrime-AI/includes/security.php
- `staff_page_start()` --calls--> `inv_user_notifications()`  [INFERRED]
  TechPrime-AI/includes/staff_layout.php → TechPrime-AI/includes/inventory_alerts.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Primo chat request flow (UI -> PHP bridge -> Flask SVM API)** — techprime_ai_ml_primo_readme_primo_ui, techprime_ai_ml_primo_readme_primo_chat_php, techprime_ai_ml_primo_readme_predict_api_py [INFERRED 0.85]
- **Primo train / serve / test lifecycle** — techprime_ai_ml_primo_train_model, techprime_ai_ml_primo_readme_predict_api_py, techprime_ai_ml_primo_test_intents [EXTRACTED 1.00]

## Communities (94 total, 66 thin omitted)

### Community 0 - "Catalog Import Scripts"
Cohesion: 0.08
Nodes (46): collections, csv, io, pil, requests, subprocess, sys, db_category() (+38 more)

### Community 1 - "Primo ML Service"
Cohesion: 0.06
Nodes (42): DataFrame, flask, flask_cors, get, joblib, json, pandas, pathlib (+34 more)

### Community 2 - "Primo Chat Widget"
Cohesion: 0.14
Nodes (33): appendMessage(), appendTechMatchPromo(), clearPromoTimer(), closeChat(), csrf(), deleteStoredConversation(), escapeHtml(), formatBubbleText() (+25 more)

### Community 3 - "Staff Dashboards"
Cohesion: 0.06
Nodes (15): admin_dashboard_preserve_hidden(), fcData, histData, sfCtx, ias_dashboard_qs(), h(), fcData, histData (+7 more)

### Community 4 - "Primo Chat Backend"
Cohesion: 0.10
Nodes (16): PDO, primo_find_products(), primo_format_order_line(), primo_handle_intent(), primo_map_product_rows(), primo_order_status(), primo_product_intent(), primo_query_products() (+8 more)

### Community 5 - "Tech Match Builder"
Cohesion: 0.18
Nodes (29): applyLoadedBuild(), axisScore(), bind(), buildCompatMap(), buildScore(), candidateIncompatible(), clearCurrentBuild(), ensureCompat() (+21 more)

### Community 6 - "Client Cart And Orders"
Cohesion: 0.15
Nodes (23): ep_cancel_order(), ep_ensure_session_cart(), ep_ensure_session_wishlist(), ep_get_cart_preview(), ep_place_cod_order(), ep_profile_image_ensure_schema(), ep_sync_cart_on_login(), ep_toggle_wishlist() (+15 more)

### Community 7 - "Retail Sales Reports"
Cohesion: 0.16
Nodes (17): DateTime, ias_fetch_all_sales_rows(), ias_fetch_delivery_rows(), ias_fetch_sales_rows(), ias_group_sales_by_order(), ias_monthly_sales_report(), ias_previous_period(), ias_product_demand_forecast() (+9 more)

### Community 8 - "Inventory Stock Management"
Cohesion: 0.14
Nodes (17): cardHtml(), escapeHtml(), findProduct(), getFiltered(), goToPage(), inventory_delete_product(), inventory_products_has_column(), openEditModal() (+9 more)

### Community 9 - "Legacy Storefront Script"
Cohesion: 0.26
Nodes (22): addRecent(), addToCart(), CATEGORY_META, currency(), defaultProducts(), ensureStore(), getProductById(), getProducts() (+14 more)

### Community 10 - "DB Connection And Staff Layout"
Cohesion: 0.14
Nodes (10): getDbConnection(), PDO, inv_relative_time(), generateCsrfToken(), staff_css_href(), staff_logo_href(), staff_logout_href(), staff_nav_for_role() (+2 more)

### Community 11 - "Shop Taxonomy And Brands"
Cohesion: 0.18
Nodes (12): ias_client_product_brand(), ep_shop_attach_taxonomy(), ep_shop_brand_logo_url(), ep_shop_brand_logos(), ep_shop_brands_ensure_schema(), ep_shop_classify_product(), ep_shop_is_complete_computer_product(), ep_shop_is_ssd_only_product() (+4 more)

### Community 12 - "Main SQL Schema"
Cohesion: 0.23
Nodes (12): `cart`, `locked_accounts`, `logs`, `messages`, `notifications`, `order_items`, `orders`, `products` (+4 more)

### Community 13 - "Client Header And Search"
Cohesion: 0.29
Nodes (6): escapeHtml(), fetchSuggest(), hideSuggest(), highlightMatch(), renderSuggest(), runSearch()

### Community 14 - "Base Schema"
Cohesion: 0.25
Nodes (10): cart, locked_accounts, logs, messages, notifications, order_items, orders, products (+2 more)

### Community 15 - "Shop Page Filters"
Cohesion: 0.24
Nodes (3): formatPeso(), syncFromRanges(), updateUI()

### Community 16 - "Roles And Categories"
Cohesion: 0.36
Nodes (4): ias_admin_roles(), ias_can_access_admin_panel(), ias_staff_role_label(), ias_staff_roles()

### Community 17 - "Inventory Stock Charts"
Cohesion: 0.43
Nodes (4): buildSeries(), monthIndexInRange(), renderStockinChart(), updateBadge()

### Community 18 - "Courier Shipments Migration"
Cohesion: 0.47
Nodes (5): orders, idx_shipments_courier, idx_shipments_order, shipments, users

### Community 19 - "Auth Client JS"
Cohesion: 0.53
Nodes (4): authRequest(), initLoginPage(), initRegisterPage(), redirectByRole()

### Community 20 - "Primo Chat History"
Cohesion: 0.40
Nodes (3): ph_cleanup(), ph_ensure_tables(), PDO

### Community 21 - "Registration"
Cohesion: 0.40
Nodes (5): checkPasswordComplexity(), PDO, PW_RULES, register_users_has_phone(), setCheck()

### Community 22 - "Barcode Scanner"
Cohesion: 0.60
Nodes (4): ref_react, BarcodeScanner(), displayProductDetails(), searchProductByBarcode()

### Community 23 - "Admin Settings"
Cohesion: 0.60
Nodes (3): adjustVal(), setPrev(), updatePreview()

### Community 25 - "Category Page JS"
Cohesion: 0.83
Nodes (3): currency(), makeProducts(), renderCategoryPage()

### Community 28 - "Primo Chat Migration"
Cohesion: 0.67
Nodes (3): primo_conversations, primo_messages, users

### Community 29 - "PC Compatibility Rules"
Cohesion: 0.83
Nodes (3): ep_pc_compat_reason_for_candidate(), ep_pc_compat_tags(), ep_pc_compat_validate_build()

### Community 31 - "Catalog DB Helpers"
Cohesion: 0.67
Nodes (3): has_column(), PDO, seller_id()

## Knowledge Gaps
- **28 isolated node(s):** `sfCtx`, `histData`, `fcData`, `CW`, `sfCtx` (+23 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 232 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **66 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `h()` connect `Staff Dashboards` to `DB Connection And Staff Layout`, `Primo Chat Backend`?**
  _High betweenness centrality (0.043) - this node is a cross-community bridge._
- **Why does `staff_page_start()` connect `DB Connection And Staff Layout` to `Staff Dashboards`, `Client Cart And Orders`?**
  _High betweenness centrality (0.022) - this node is a cross-community bridge._
- **Why does `ias_dashboard_qs()` connect `Staff Dashboards` to `Retail Sales Reports`?**
  _High betweenness centrality (0.020) - this node is a cross-community bridge._
- **Are the 5 inferred relationships involving `staff_page_start()` (e.g. with `getDbConnection()` and `inv_relative_time()`) actually correct?**
  _`staff_page_start()` has 5 INFERRED edges - model-reasoned connections that need verification._
- **What connects `sfCtx`, `histData`, `fcData` to the rest of the system?**
  _28 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Catalog Import Scripts` be split into smaller, more focused modules?**
  _Cohesion score 0.07624113475177305 - nodes in this community are weakly interconnected._
- **Should `Primo ML Service` be split into smaller, more focused modules?**
  _Cohesion score 0.06475485661424607 - nodes in this community are weakly interconnected._