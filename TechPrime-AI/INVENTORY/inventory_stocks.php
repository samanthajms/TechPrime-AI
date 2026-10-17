<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/product_categories.php';
require_once __DIR__ . '/../includes/client_shop_taxonomy.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';
require_once __DIR__ . '/../includes/pos_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$uid = (int)$_SESSION['user_id'];
$allowed_categories = ep_shop_inventory_allowed_category_values();
$categoryGroups = ep_shop_inventory_category_groups();

/* Stock alert thresholds (qty-based; no per-product config in the schema). */
const CRITICAL_STOCK_MAX = 5;   // 0 < stock <= 5
const LOW_STOCK_MAX = 15;       // 0 < stock <= 15 (includes critical)

function inventory_product_category(array $allowed): string
{
    $category = $_POST['category'] ?? 'Others';
    return in_array($category, $allowed, true) ? $category : 'Others';
}

/** Feature-detect optional columns so this page works with or without migration_inventory_sku.sql applied. */
function inventory_products_has_column(PDO $db, string $column): bool
{
    static $cache = [];
    if (isset($cache[$column])) {
        return $cache[$column];
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'products' AND column_name = ?"
    );
    $stmt->execute([$column]);
    $has = (bool)$stmt->fetchColumn();
    $cache[$column] = $has;
    return $has;
}

function inventory_stock_status(int $stock): string
{
    if ($stock <= 0) return 'out';
    if ($stock <= CRITICAL_STOCK_MAX) return 'critical';
    if ($stock <= LOW_STOCK_MAX) return 'low';
    return 'ok';
}

$hasSku = inventory_products_has_column($db, 'sku');
$hasBarcode = inventory_products_has_column($db, 'barcode');

/**
 * Validate the optional UPC/EAN field. Returns the normalized code, null for "none",
 * or redirects with an error when it is invalid or already used by another product.
 */
function inventory_barcode_from_post(PDO $db, bool $hasBarcode, int $productId): ?string
{
    $raw = trim((string)($_POST['barcode'] ?? ''));
    if (!$hasBarcode || $raw === '') {
        return null;
    }
    $code = pos_normalize_barcode($raw);
    if ($code === null) {
        header('Location: inventory_stocks.php?error=barcode');
        exit;
    }
    $dup = $db->prepare('SELECT id FROM products WHERE barcode = ? AND id <> ? LIMIT 1');
    $dup->execute([$code, $productId]);
    if ($dup->fetchColumn()) {
        header('Location: inventory_stocks.php?error=barcode_taken');
        exit;
    }
    return $code;
}

/* ---------------------------------------------------------------------
 * Add product
 * ------------------------------------------------------------------- */
if (isset($_POST['add_product'])) {
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $category = inventory_product_category($allowed_categories);
    $sku = trim($_POST['sku'] ?? '');
    $barcode = inventory_barcode_from_post($db, $hasBarcode, 0);
    $imageFile = ias_handle_product_upload($uid);

    if ($name !== '' && $price > 0 && $stock >= 0 && $imageFile !== null) {
        $emptyUrl = '';
        if ($hasSku) {
            $stmt = $db->prepare(
                'INSERT INTO products (seller_id, name, price, stock, description, image, image_url, category, sku)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $skuVal = $sku !== '' ? $sku : null;
            $stmt->execute([$uid, $name, $price, $stock, $desc, $imageFile, $emptyUrl, $category, $skuVal]);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO products (seller_id, name, price, stock, description, image, image_url, category)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$uid, $name, $price, $stock, $desc, $imageFile, $emptyUrl, $category]);
        }
        $newId = (int)$db->lastInsertId();
        if ($hasBarcode && $barcode !== null && $newId > 0) {
            $db->prepare('UPDATE products SET barcode = ? WHERE id = ?')->execute([$barcode, $newId]);
        }
        logActivity($db, $uid, 'add_product', 'Added product: ' . $name . ' [' . $category . '] (stock: ' . $stock . ')');
        if ($newId > 0 && $stock <= 15) {
            inv_notify_stock_change($db, $name, $newId, 999, $stock);
        }
        header('Location: inventory_stocks.php?alert=added');
        exit;
    }

    header('Location: inventory_stocks.php?alert=error');
    exit;
}

/* ---------------------------------------------------------------------
 * Edit product
 * ------------------------------------------------------------------- */
if (isset($_POST['edit_product'])) {
    $id = (int)($_POST['product_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $category = inventory_product_category($allowed_categories);
    $sku = trim($_POST['sku'] ?? '');

    if ($id > 0 && $name !== '' && $price > 0 && $stock >= 0) {
        $barcode = inventory_barcode_from_post($db, $hasBarcode, $id);
        $newImage = ias_handle_product_upload($uid);
        $triedImageUpload = !empty($_FILES['product_image']['name']);

        if ($triedImageUpload && $newImage === null) {
            header('Location: inventory_stocks.php?alert=error');
            exit;
        }

        $oldSt = $db->prepare('SELECT name, stock, image, category FROM products WHERE id = ?');
        $oldSt->execute([$id]);
        $oldRow = $oldSt->fetch(PDO::FETCH_ASSOC) ?: [];
        $oldStock = (int)($oldRow['stock'] ?? 0);
        $oldName = trim((string)($oldRow['name'] ?? $name));
        $shownCat = ep_shop_category_store_value(ep_shop_classify_product([
            'name' => $oldName,
            'category' => (string)($oldRow['category'] ?? ''),
        ]));
        if ($category === $shownCat) {
            $category = (string)($oldRow['category'] ?? $category);
        }

        if ($newImage !== null) {
            $emptyUrl = '';
            if ($hasSku) {
                $stmt = $db->prepare(
                    'UPDATE products
                     SET name = ?, price = ?, stock = ?, description = ?, category = ?, image = ?, image_url = ?, sku = ?
                     WHERE id = ?'
                );
                $skuVal = $sku !== '' ? $sku : null;
                $stmt->execute([$name, $price, $stock, $desc, $category, $newImage, $emptyUrl, $skuVal, $id]);
            } else {
                $stmt = $db->prepare(
                    'UPDATE products
                     SET name = ?, price = ?, stock = ?, description = ?, category = ?, image = ?, image_url = ?
                     WHERE id = ?'
                );
                $stmt->execute([$name, $price, $stock, $desc, $category, $newImage, $emptyUrl, $id]);
            }
            if (!empty($oldRow['image'])) {
                $oldPath = dirname(__DIR__) . '/uploads/products/' . basename($oldRow['image']);
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
        } else {
            if ($hasSku) {
                $stmt = $db->prepare(
                    'UPDATE products
                     SET name = ?, price = ?, stock = ?, description = ?, category = ?, sku = ?
                     WHERE id = ?'
                );
                $skuVal = $sku !== '' ? $sku : null;
                $stmt->execute([$name, $price, $stock, $desc, $category, $skuVal, $id]);
            } else {
                $stmt = $db->prepare(
                    'UPDATE products
                     SET name = ?, price = ?, stock = ?, description = ?, category = ?
                     WHERE id = ?'
                );
                $stmt->execute([$name, $price, $stock, $desc, $category, $id]);
            }
        }

        if ($hasBarcode) {
            $db->prepare('UPDATE products SET barcode = ? WHERE id = ?')->execute([$barcode, $id]);
        }

        $logName = $name !== '' ? $name : $oldName;
        $logDetails = 'Updated product #' . $id . ' "' . $logName . '": stock ' . $oldStock . ' → ' . $stock;
        $oldCat = (string)($oldRow['category'] ?? '');
        if ($category !== $oldCat) {
            $logDetails .= '; category ' . $oldCat . ' → ' . $category;
        }
        logActivity($db, $uid, 'edit_product', $logDetails);
        inv_notify_stock_change($db, $logName, $id, $oldStock, $stock);
        header('Location: inventory_stocks.php?alert=updated');
        exit;
    }

    header('Location: inventory_stocks.php?alert=error');
    exit;
}

/** Delete a single product row (id already validated as int > 0) plus its image + cart refs. */
function inventory_delete_product(PDO $db, int $pid): void
{
    $imgSt = $db->prepare('SELECT image FROM products WHERE id = ?');
    $imgSt->execute([$pid]);
    $row = $imgSt->fetch(PDO::FETCH_ASSOC);
    $del = $db->prepare('DELETE FROM products WHERE id = ?');
    $del->execute([$pid]);
    $cart = $db->prepare('DELETE FROM cart WHERE product_id = ?');
    $cart->execute([$pid]);
    if (!empty($row['image'])) {
        $path = dirname(__DIR__) . '/uploads/products/' . basename($row['image']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/* ---------------------------------------------------------------------
 * Delete (single)
 * ------------------------------------------------------------------- */
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $pid = (int)($_POST['id'] ?? 0);
    if ($pid > 0) {
        $nmSt = $db->prepare('SELECT name FROM products WHERE id = ?');
        $nmSt->execute([$pid]);
        $nmRow = $nmSt->fetch(PDO::FETCH_ASSOC);
        $pname = trim((string)($nmRow['name'] ?? ''));
        inventory_delete_product($db, $pid);
        logActivity(
            $db,
            $uid,
            'delete_product',
            $pname !== '' ? ('Deleted product: ' . $pname . ' (#' . $pid . ')') : ("Deleted product #$pid")
        );
        header('Location: inventory_stocks.php?alert=deleted');
        exit;
    }
}

/* ---------------------------------------------------------------------
 * Delete (bulk — from the selection checkboxes)
 * ------------------------------------------------------------------- */
if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {
    $ids = array_filter(array_map('intval', $_POST['ids'] ?? []), fn($v) => $v > 0);
    foreach ($ids as $pid) {
        inventory_delete_product($db, $pid);
    }
    if (!empty($ids)) {
        logActivity($db, $uid, 'delete_product', 'Bulk deleted ' . count($ids) . ' product(s): ' . implode(', ', $ids));
    }
    header('Location: inventory_stocks.php?alert=' . (!empty($ids) ? 'deleted' : 'error'));
    exit;
}

/* ---------------------------------------------------------------------
 * Generate in-store barcodes for products that have none.
 * EAN-13 "21" + 10-digit product id + check digit: unique per product and
 * inside the GS1 in-store range, so it never clashes with retail barcodes.
 * ------------------------------------------------------------------- */
if (isset($_POST['action']) && $_POST['action'] === 'generate_barcode') {
    if (!$hasBarcode || !verifyCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
        header('Location: inventory_stocks.php?alert=error');
        exit;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0)));
    $made = [];
    $taken = $db->prepare('SELECT id FROM products WHERE barcode = ?');
    $up = $db->prepare('UPDATE products SET barcode = ? WHERE id = ? AND barcode IS NULL RETURNING name');
    foreach ($ids as $pid) {
        $body = '21' . str_pad((string)$pid, 10, '0', STR_PAD_LEFT);
        $code = $body . pos_gtin_check_digit($body);
        $taken->execute([$code]);
        if ($taken->fetchColumn()) {
            continue;
        }
        $up->execute([$code, $pid]);
        $name = $up->fetchColumn();
        if ($name !== false) {
            $made[] = 'product #' . $pid . ' "' . $name . '" = ' . $code;
        }
    }
    if ($made) {
        logActivity($db, $uid, 'generate_barcode', 'Generated in-store barcode for ' . count($made) . ' product(s): ' . implode('; ', $made));
    }
    $query = ['alert' => $made ? 'barcode_generated' : 'error'];
    if (count($ids) === 1 && $made) {
        $query['label'] = $ids[0];
    }
    header('Location: inventory_stocks.php?' . http_build_query($query));
    exit;
}

/* ---------------------------------------------------------------------
 * Data for the page: full product set (filtering/sorting/paging is done
 * client-side in JS so the dashboard updates instantly with no reloads),
 * plus the counts and category list the sidebar needs.
 * ------------------------------------------------------------------- */
$skuSelect = $hasSku ? 'sku' : 'NULL AS sku';
$barcodeSelect = $hasBarcode ? 'barcode' : 'NULL AS barcode';
$products = $db->query("SELECT id, name, description, price, stock, image, image_url, category, created_at, $skuSelect, $barcodeSelect FROM products ORDER BY name ASC");

$totalCount = 0;
$inStockCount = 0;
$outOfStockCount = 0;
$lowStockCount = 0;
$criticalStockCount = 0;
$withBarcodeCount = 0;
$rows = [];

if ($products) {
    while ($p = $products->fetch(PDO::FETCH_ASSOC)) {
        $stock = (int)$p['stock'];
        $status = inventory_stock_status($stock);
        $totalCount++;
        if ($status === 'out') {
            $outOfStockCount++;
        } else {
            $inStockCount++;
        }
        if ($status === 'low' || $status === 'critical') {
            $lowStockCount++;
        }
        if ($status === 'critical') {
            $criticalStockCount++;
        }
        if (trim((string)($p['barcode'] ?? '')) !== '') {
            $withBarcodeCount++;
        }
        $tax = ep_shop_classify_product($p);
        $p['tax_parent'] = $tax['parent'];
        $p['tax_parent_label'] = $tax['parent_label'];
        $p['tax_sub_label'] = $tax['sub_label'];
        $p['tax_display'] = ep_shop_category_display($tax);
        $p['tax_store'] = ep_shop_category_store_value($tax);
        $rows[] = $p;
    }
}

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Inventory',
    'active' => 'stocks',
    'active_child' => 'inventory',
    'heading' => 'Inventory',
    'subtitle' => 'Every product in the catalog: stock, price and barcode',
    'actions' => '<a class="cx-btn cx-btn-outline" href="inventory_stock_in.php"><i class="ph ph-barcode" aria-hidden="true"></i> Receive stock</a>'
        . '<button type="button" id="addProductBtn" class="cx-btn cx-btn-primary" onclick="openModal(\'addModal\')"><i class="ph ph-plus" aria-hidden="true"></i> Add Product</button>',
]);
?>

        <section class="cx-card stocks-card" aria-labelledby="stocksTitle">
            <div class="cx-card-head">
                <div>
                    <h2 id="stocksTitle"><i class="ph ph-package" aria-hidden="true"></i> Product catalog</h2>
                    <p>
                        <span class="cx-num"><?php echo number_format($totalCount); ?></span> products
                        · <span class="cx-num"><?php echo number_format($outOfStockCount); ?></span> out of stock
                        · <span class="cx-num"><?php echo number_format($lowStockCount); ?></span> running low
                    </p>
                </div>
            </div>

            <div class="alerts-toolbar stocks-toolbar">
                <div class="search-wrap">
                    <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                    <label class="cx-sr" for="searchInput">Search products</label>
                    <input type="search" id="searchInput" placeholder="Search by name, category, SKU or barcode" autocomplete="off">
                    <button type="button" id="scanBtn" class="cx-btn cx-btn-outline cx-btn-sm" title="Focus this field, then use a barcode scanner (acts as keyboard input ending in Enter)">
                        <i class="ph ph-barcode" aria-hidden="true"></i> Scan
                    </button>
                </div>
                <div class="alerts-toolbar-side">
                    <div class="cx-seg" role="group" aria-label="Layout">
                        <button type="button" id="listViewBtn" class="active" aria-pressed="true"><i class="ph ph-list" aria-hidden="true"></i> List</button>
                        <button type="button" id="gridViewBtn" aria-pressed="false"><i class="ph ph-squares-four" aria-hidden="true"></i> Pictures</button>
                    </div>
                    <div class="stocks-filter-wrap">
                        <button type="button" id="filterToggleBtn" class="cx-btn cx-btn-outline stocks-filter-btn" aria-expanded="false" aria-controls="stocksFilterPanel">
                            <i class="ph ph-funnel" aria-hidden="true"></i> Filter <span class="filter-dot" aria-hidden="true"></span><span class="cx-sr" id="filterActiveText"></span>
                            <i class="ph ph-caret-down stocks-filter-caret" aria-hidden="true"></i>
                        </button>
                        <div id="stocksFilterPanel" class="stocks-filter-panel" role="region" aria-labelledby="stocksFilterTitle">
                            <h3 class="stocks-filter-title" id="stocksFilterTitle">Filter stock</h3>
                            <div>
                                <span class="filter-title" id="statusTitle">Product status</span>
                                <div class="status-toggle" id="statusToggle" role="group" aria-labelledby="statusTitle">
                                    <button type="button" class="status-btn active" data-status="all" aria-pressed="true">All <span class="cnt cx-num"><?php echo (int)$totalCount; ?></span></button>
                                    <button type="button" class="status-btn" data-status="instock" aria-pressed="false">In stock <span class="cnt cx-num"><?php echo (int)$inStockCount; ?></span></button>
                                    <button type="button" class="status-btn" data-status="outofstock" aria-pressed="false">Out of stock <span class="cnt cx-num"><?php echo (int)$outOfStockCount; ?></span></button>
                                </div>
                            </div>
                            <div>
                                <label class="filter-title" for="sortBy">Sort by</label>
                                <select id="sortBy" class="form-control">
                                    <option value="name_asc" selected>Alphabetical (A-Z)</option>
                                    <option value="name_desc">Alphabetical (Z-A)</option>
                                    <option value="price_asc">Price (Low to High)</option>
                                    <option value="price_desc">Price (High to Low)</option>
                                    <option value="stock_asc">Stock Quantity (Low to High)</option>
                                    <option value="stock_desc">Stock Quantity (High to Low)</option>
                                </select>
                            </div>
                            <div>
                                <label class="filter-title" for="stockAlert">Stock alert</label>
                                <select id="stockAlert" class="form-control">
                                    <option value="all" selected>All Stock</option>
                                    <option value="low">Low Stock (&le; <?php echo LOW_STOCK_MAX; ?>)</option>
                                    <option value="critical">Critical Stock (&le; <?php echo CRITICAL_STOCK_MAX; ?>)</option>
                                </select>
                            </div>
                            <?php if ($hasBarcode): ?>
                            <div>
                                <label class="filter-title" for="barcodeFilter">Barcode</label>
                                <select id="barcodeFilter" class="form-control">
                                    <option value="all" selected>All Products</option>
                                    <option value="has">Has UPC/EAN barcode (<?php echo (int)$withBarcodeCount; ?>)</option>
                                    <option value="missing">Missing barcode (<?php echo (int)($totalCount - $withBarcodeCount); ?>)</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div>
                                <span class="filter-title" id="priceTitle">Price range</span>
                                <div class="price-range" role="group" aria-labelledby="priceTitle">
                                    <input type="number" min="0" step="0.01" id="priceMin" class="form-control" placeholder="Min" aria-label="Minimum price">
                                    <span aria-hidden="true">&ndash;</span>
                                    <input type="number" min="0" step="0.01" id="priceMax" class="form-control" placeholder="Max" aria-label="Maximum price">
                                </div>
                            </div>
                            <button type="button" id="resetFilters" class="cx-btn cx-btn-outline btn-reset"><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i> Reset filters</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="select-row">
                <label class="select-chip" id="selectedChip">
                    <input type="checkbox" id="selectedIndicator" checked disabled>
                    <span><span id="selectedCount">0</span> selected</span>
                </label>
                <label class="select-chip" for="selectAll">
                    <input type="checkbox" id="selectAll">
                    <span>Select all on this page (<span id="pageCount">0</span>)</span>
                </label>
                <div class="selection-bar" id="selectionBar">
                    <?php if ($hasBarcode): ?>
                    <button type="button" class="cx-btn cx-btn-outline cx-btn-sm" id="bulkLabelBtn"><i class="ph ph-printer" aria-hidden="true"></i> Print labels</button>
                    <button type="button" class="cx-btn cx-btn-outline cx-btn-sm" id="bulkGenerateBtn"><i class="ph ph-barcode" aria-hidden="true"></i> Generate barcodes</button>
                    <?php endif; ?>
                    <button type="button" class="cx-btn cx-btn-ghost-danger cx-btn-sm" id="bulkDeleteBtn"><i class="ph ph-trash" aria-hidden="true"></i> Delete</button>
                </div>
            </div>

            <div id="productList" class="cx-table-wrap stocks-table-wrap"><!-- rows injected by JS --></div>
            <div id="emptyState" class="cx-empty" hidden>
                <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-magnifying-glass"></i></span>
                <strong>No products match your filters</strong>
                <span>Try another search, or reset the filters.</span>
            </div>

            <div class="pagination-bar">
                <div class="pg-info" id="pgInfo" aria-live="polite"></div>
                <div class="pg-controls" id="pgControls"></div>
            </div>
        </section>

        <!-- ============================== ADD PRODUCT MODAL ============================== -->
        <div id="addModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="addModalTitle">
            <div class="modal-content">
                <button type="button" class="close" onclick="closeModal('addModal')" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
                <h3 id="addModalTitle"><i class="ph ph-plus-circle" aria-hidden="true"></i> Add new product</h3>
                <form method="post" enctype="multipart/form-data" class="inv-form">
                    <div class="form-group">
                        <label class="form-label" for="add_sku">SKU / Item Code (optional)</label>
                        <input type="text" name="sku" id="add_sku" class="form-control" placeholder="e.g. 11510">
                        <?php if (!$hasSku): ?>
                        <p class="form-hint">SKU is optional; apply SKU migration to persist item codes.</p>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasBarcode): ?>
                    <div class="form-group">
                        <label class="form-label" for="add_barcode">UPC / EAN Barcode (optional)</label>
                        <div class="barcode-input-row">
                            <input type="text" name="barcode" id="add_barcode" class="form-control cx-mono" maxlength="14" inputmode="numeric" placeholder="Scan or type barcode" onkeydown="if (event.key === 'Enter') event.preventDefault();" aria-describedby="addBarcodeHint">
                            <button type="button" class="cx-btn cx-btn-outline" onclick="focusBarcodeField('add_barcode')"><i class="ph ph-barcode" aria-hidden="true"></i> Scan</button>
                        </div>
                        <p class="form-hint" id="addBarcodeHint">UPC-A, UPC-E, EAN-13 or EAN-8 printed on the box. Used by the Cashier scanner.</p>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label" for="add_name">Product Name</label>
                        <input type="text" name="name" id="add_name" class="form-control" placeholder="e.g. Wireless Keyboard" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_category">Category</label>
                        <select name="category" id="add_category" class="form-control" required>
                            <option value="" disabled selected>Select a category...</option>
                            <?php foreach ($categoryGroups as $groupLabel => $groupValues): ?>
                            <optgroup label="<?php echo h($groupLabel); ?>">
                                <?php foreach ($groupValues as $category): ?>
                                <option value="<?php echo h($category); ?>"><?php echo h($category); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label" for="add_price">Price (₱)</label>
                            <input type="number" step="0.01" min="0.01" name="price" id="add_price" class="form-control cx-num" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="add_stock">Stock</label>
                            <input type="number" min="0" name="stock" id="add_stock" class="form-control cx-num" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_desc">Description</label>
                        <textarea name="description" id="add_desc" class="form-control" rows="3" placeholder="Describe your product..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_image">Product Image (JPG, JPEG, PNG)</label>
                        <input type="file" name="product_image" id="add_image" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="cx-btn cx-btn-ghost" onclick="closeModal('addModal')">Cancel</button>
                        <button type="submit" name="add_product" class="cx-btn cx-btn-primary"><i class="ph ph-plus" aria-hidden="true"></i> Add Product</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================== EDIT PRODUCT MODAL ============================== -->
        <div id="editModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
            <div class="modal-content">
                <button type="button" class="close" onclick="closeEditModal()" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
                <h3 id="editModalTitle"><i class="ph ph-pencil-simple" aria-hidden="true"></i> Edit product</h3>
                <form method="post" enctype="multipart/form-data" class="inv-form">
                    <input type="hidden" name="product_id" id="edit_id">
                    <?php if ($hasSku): ?>
                    <div class="form-group">
                        <label class="form-label" for="edit_sku">SKU / Item Code (optional)</label>
                        <input type="text" name="sku" id="edit_sku" class="form-control">
                    </div>
                    <?php endif; ?>
                    <?php if ($hasBarcode): ?>
                    <div class="form-group">
                        <label class="form-label" for="edit_barcode">UPC / EAN Barcode (optional)</label>
                        <div class="barcode-input-row">
                            <input type="text" name="barcode" id="edit_barcode" class="form-control cx-mono" maxlength="14" inputmode="numeric" placeholder="Scan or type barcode" onkeydown="if (event.key === 'Enter') event.preventDefault();">
                            <button type="button" class="cx-btn cx-btn-outline" onclick="focusBarcodeField('edit_barcode')"><i class="ph ph-barcode" aria-hidden="true"></i> Scan</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label" for="edit_name">Product Name</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_category">Category</label>
                        <select name="category" id="edit_category" class="form-control" required>
                            <?php foreach ($categoryGroups as $groupLabel => $groupValues): ?>
                            <optgroup label="<?php echo h($groupLabel); ?>">
                                <?php foreach ($groupValues as $category): ?>
                                <option value="<?php echo h($category); ?>"><?php echo h($category); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label" for="edit_price">Price (₱)</label>
                            <input type="number" step="0.01" min="0.01" name="price" id="edit_price" class="form-control cx-num" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="edit_stock">Stock</label>
                            <input type="number" min="0" name="stock" id="edit_stock" class="form-control cx-num" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_desc">Description</label>
                        <textarea name="description" id="edit_desc" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_image">Replace Image (optional)</label>
                        <input type="file" name="product_image" id="edit_image" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="cx-btn cx-btn-ghost" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" name="edit_product" class="cx-btn cx-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Update Product</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================== VIEW DETAILS MODAL ============================== -->
        <div id="detailsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="detailsModalTitle">
            <div class="modal-content wide">
                <button type="button" class="close" onclick="closeModal('detailsModal')" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
                <h3 id="detailsModalTitle"><i class="ph ph-info" aria-hidden="true"></i> Product details</h3>
                <img id="d_img" class="details-img" src="" alt="" style="display:none;">
                <dl class="details-grid">
                    <dt>Name</dt><dd id="d_name"></dd>
                    <dt>Category</dt><dd id="d_category"></dd>
                    <dt>Stock</dt><dd id="d_stock" class="cx-num"></dd>
                    <dt>Status</dt><dd id="d_status"></dd>
                    <dt>Price</dt><dd id="d_price" class="cx-num"></dd>
                    <?php if ($hasSku): ?><dt>SKU / Item Code</dt><dd id="d_sku" class="cx-mono"></dd><?php endif; ?>
                    <?php if ($hasBarcode): ?><dt>UPC / EAN</dt><dd id="d_barcode" class="cx-mono"></dd><?php endif; ?>
                    <dt>Date Added</dt><dd id="d_created" class="cx-num"></dd>
                    <dt>Description</dt><dd id="d_desc"></dd>
                </dl>
            </div>
        </div>

        <?php if ($hasBarcode): ?>
        <!-- ============================== BARCODE LABEL MODAL ============================== -->
        <div id="labelModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="labelModalTitle">
            <div class="modal-content wide">
                <button type="button" class="close" onclick="closeLabelModal()" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
                <h3 id="labelModalTitle"><i class="ph ph-barcode" aria-hidden="true"></i> Barcode label</h3>
                <div class="label-preview" id="labelPreview"></div>
                <div class="label-options">
                    <div class="form-group">
                        <label class="form-label" for="labelCopies">Copies</label>
                        <input type="number" id="labelCopies" class="form-control cx-num" min="1" max="60" value="1">
                    </div>
                    <label class="label-check">
                        <input type="checkbox" id="labelShowPrice" checked> Show price
                    </label>
                </div>
                <p class="form-hint label-hint">
                    Print at <strong>100% scale</strong> (turn off "Fit to page") so the bars keep their true size.
                    3 labels per row on A4/Letter; cut along the dashed lines.
                </p>
                <button type="button" class="cx-btn cx-btn-primary cx-btn-lg label-print-btn" id="labelPrintBtn">
                    <i class="ph ph-printer" aria-hidden="true"></i> Print labels
                </button>
            </div>
        </div>

        <!-- Hidden form used for barcode generation -->
        <form method="post" id="generateBarcodeForm" style="display:none;">
            <input type="hidden" name="action" value="generate_barcode">
            <input type="hidden" name="csrf_token" value="<?php echo h(generateCsrfToken()); ?>">
            <div id="generateBarcodeIds"></div>
        </form>
        <?php endif; ?>

        <!-- Hidden form used for bulk delete submissions -->
        <form method="post" id="bulkDeleteForm" style="display:none;">
            <input type="hidden" name="action" value="bulk_delete">
            <div id="bulkDeleteIds"></div>
        </form>

<?php
$flashMsg = ias_alert_message_from_request();
$flashType = ((!empty($_GET['alert']) && $_GET['alert'] === 'error') || !empty($_GET['error'])) ? 'error' : 'success';
$flashScript = '';
if ($flashMsg) {
    $flashScript = '<script>document.addEventListener("DOMContentLoaded",function(){if(typeof IAS_UI!=="undefined")IAS_UI.alert('
        . json_encode($flashMsg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ','
        . json_encode($flashType) . ',0);});</script>';
}

$jsProducts = array_map(function ($p) {
    $stock = (int)$p['stock'];
    return [
        'id' => (int)$p['id'],
        'name' => $p['name'] ?? '',
        'category' => $p['tax_display'] ?? ($p['category'] ?? 'Others'),
        'category_value' => $p['tax_store'] ?? ($p['category'] ?? 'Others'),
        'tax_parent' => $p['tax_parent'] ?? 'others',
        'price' => (float)$p['price'],
        'stock' => $stock,
        'status' => inventory_stock_status($stock),
        'description' => $p['description'] ?? '',
        'sku' => $p['sku'] ?? '',
        'barcode' => $p['barcode'] ?? '',
        'created_at' => $p['created_at'] ?? '',
        'img' => ias_product_image_url($p),
    ];
}, $rows);

$productsJson = json_encode($jsProducts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$lowMax = LOW_STOCK_MAX;
$criticalMax = CRITICAL_STOCK_MAX;
$hasBarcodeJs = $hasBarcode ? 'true' : 'false';
$openLabelId = (int)($_GET['label'] ?? 0);

$mainScript = <<<SCRIPTS
<script>
var ALL_PRODUCTS = {$productsJson};
var LOW_STOCK_MAX = {$lowMax};
var CRITICAL_STOCK_MAX = {$criticalMax};
var HAS_BARCODE = {$hasBarcodeJs};

/* UPC-A is stored as a 13-digit GTIN with a leading 0; show the 12 digits printed on the box. */
function barcodeLabel(code) {
    code = String(code || '');
    return (code.length === 13 && code.charAt(0) === '0') ? code.slice(1) : code;
}
var OPEN_LABEL_ID = {$openLabelId};
function barcodeCellHtml(p) {
    return p.barcode
        ? '<span class="barcode-code">' + escapeHtml(barcodeLabel(p.barcode)) + '</span>' +
          '<button type="button" class="barcode-btn" data-label-id="' + p.id + '" title="Print barcode label" aria-label="Print barcode label for ' + escapeHtml(p.name) + '"><i class="ph ph-printer" aria-hidden="true"></i></button>'
        : '<span class="barcode-missing"><i class="ph ph-minus-circle" aria-hidden="true"></i> None</span>' +
          '<button type="button" class="barcode-btn" data-generate-id="' + p.id + '" title="Generate an in-store barcode"><i class="ph ph-plus" aria-hidden="true"></i> Generate</button>';
}
function barcodeMenuItem(p) {
    if (!HAS_BARCODE) return '';
    return p.barcode
        ? '<button type="button" role="menuitem" onclick="openLabelModal([' + p.id + '])"><i class="ph ph-printer" aria-hidden="true"></i> Print Barcode</button>'
        : '<button type="button" role="menuitem" onclick="generateBarcodes([' + p.id + '])"><i class="ph ph-barcode" aria-hidden="true"></i> Generate Barcode</button>';
}

/* ---- Barcode labels ---- */
var labelIds = [];
function labelHtml(p, showPrice) {
    return '<div class="ep-label">' +
        '<div class="ep-label-name">' + escapeHtml(p.name || ('Product #' + p.id)) + '</div>' +
        EP_BarcodeSVG.svg(p.barcode) +
        (showPrice ? '<div class="ep-label-price">' + peso(p.price) + '</div>' : '') +
    '</div>';
}
function openLabelModal(ids) {
    labelIds = ids.map(Number).filter(function (id) { var p = findProduct(id); return p && p.barcode; });
    if (!labelIds.length) return;
    document.getElementById('labelCopies').value = 1;
    renderLabelPreview();
    openModal('labelModal');
}
function closeLabelModal() { closeModal('labelModal'); }
function renderLabelPreview() {
    var showPrice = document.getElementById('labelShowPrice').checked;
    var first = findProduct(labelIds[0]);
    var more = labelIds.length > 1 ? '<p class="form-hint label-more">+ ' + (labelIds.length - 1) + ' more product(s)</p>' : '';
    document.getElementById('labelPreview').innerHTML = '<div>' + labelHtml(first, showPrice) + more + '</div>';
}
function printLabels() {
    var copies = Math.max(1, Math.min(60, parseInt(document.getElementById('labelCopies').value, 10) || 1));
    var showPrice = document.getElementById('labelShowPrice').checked;
    var area = document.getElementById('labelPrintArea');
    if (!area) {
        area = document.createElement('div');
        area.id = 'labelPrintArea';
        document.body.appendChild(area);
    }
    var html = '';
    labelIds.forEach(function (id) {
        var p = findProduct(id);
        for (var i = 0; i < copies; i++) html += labelHtml(p, showPrice);
    });
    area.innerHTML = html;
    document.body.classList.add('printing-labels');
    window.print();
}
window.addEventListener('afterprint', function () { document.body.classList.remove('printing-labels'); });
function generateBarcodes(ids) {
    ids = ids.map(Number).filter(function (id) { var p = findProduct(id); return p && !p.barcode; });
    if (!ids.length) {
        if (typeof IAS_UI !== 'undefined') IAS_UI.alert('The selected products already have barcodes.', 'info', 0);
        return;
    }
    IAS_UI.confirm('Only do this for items with no manufacturer barcode on the box.', {
        title: ids.length === 1 ? 'Generate an in-store barcode?' : 'Generate ' + ids.length + ' in-store barcodes?',
        detail: ids.length === 1 ? (findProduct(ids[0]).name || ('Product #' + ids[0])) : '',
        confirmLabel: ids.length === 1 ? 'Generate barcode' : 'Generate barcodes'
    }).then(function (ok) {
        if (!ok) return;
        document.getElementById('generateBarcodeIds').innerHTML = ids.map(function (id) {
            return '<input type="hidden" name="ids[]" value="' + id + '">';
        }).join('');
        document.getElementById('generateBarcodeForm').submit();
    });
}

function focusBarcodeField(id) {
    var input = document.getElementById(id);
    if (!input) return;
    input.focus();
    input.select();
    if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Ready to scan — point your barcode scanner at the field.', 'success', 0);
}

var state = {
    status: 'all',
    sort: 'name_asc',
    alert: 'all',
    barcode: 'all',
    priceMin: null,
    priceMax: null,
    search: '',
    view: 'list',
    page: 1,
    pageSize: 10,
    selected: {}
};

function peso(n) {
    return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function statusLabel(s) {
    if (s === 'out') return 'Out of Stock';
    if (s === 'critical') return 'Critical Stock';
    if (s === 'low') return 'Low Stock';
    return 'In Stock';
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function getFiltered() {
    var q = state.search.trim().toLowerCase();
    var list = ALL_PRODUCTS.filter(function (p) {
        if (state.status === 'instock' && p.status === 'out') return false;
        if (state.status === 'outofstock' && p.status !== 'out') return false;
        if (state.alert === 'low' && !(p.status === 'low' || p.status === 'critical')) return false;
        if (state.alert === 'critical' && p.status !== 'critical') return false;
        if (state.barcode === 'has' && !p.barcode) return false;
        if (state.barcode === 'missing' && p.barcode) return false;
        if (state.priceMin !== null && p.price < state.priceMin) return false;
        if (state.priceMax !== null && p.price > state.priceMax) return false;
        if (q) {
            var hay = (p.name + ' ' + p.category + ' ' + (p.tax_parent || '') + ' ' + (p.sku || '') + ' ' + (p.barcode || '') + ' ' + barcodeLabel(p.barcode)).toLowerCase();
            if (hay.indexOf(q) === -1) return false;
        }
        return true;
    });

    list.sort(function (a, b) {
        switch (state.sort) {
            case 'name_desc': return b.name.localeCompare(a.name);
            case 'price_asc': return a.price - b.price;
            case 'price_desc': return b.price - a.price;
            case 'stock_asc': return a.stock - b.stock;
            case 'stock_desc': return b.stock - a.stock;
            default: return a.name.localeCompare(b.name);
        }
    });

    return list;
}

function render() {
    var filtered = getFiltered();
    var totalPages = Math.max(1, Math.ceil(filtered.length / state.pageSize));
    if (state.page > totalPages) state.page = totalPages;
    var startIdx = (state.page - 1) * state.pageSize;
    var pageItems = filtered.slice(startIdx, startIdx + state.pageSize);

    var listEl = document.getElementById('productList');
    var emptyEl = document.getElementById('emptyState');

    if (pageItems.length === 0) {
        listEl.innerHTML = '';
        listEl.className = state.view === 'grid' ? 'product-list grid-view' : 'cx-table-wrap stocks-table-wrap';
        emptyEl.hidden = false;
    } else {
        emptyEl.hidden = true;
        if (state.view === 'grid') {
            listEl.className = 'product-list grid-view';
            listEl.innerHTML = pageItems.map(cardHtml).join('');
        } else {
            listEl.className = 'cx-table-wrap stocks-table-wrap';
            listEl.innerHTML =
                '<table class="cx-table stocks-table">' +
                    '<thead><tr>' +
                        '<th scope="col" class="col-chk"><span class="cx-sr">Select</span></th>' +
                        '<th scope="col">Product</th>' +
                        (HAS_BARCODE ? '<th scope="col" class="col-barcode">Barcode</th>' : '') +
                        '<th scope="col" class="col-stock num">Stock</th>' +
                        '<th scope="col" class="col-status">Status</th>' +
                        '<th scope="col" class="col-price num">Price</th>' +
                        '<th scope="col" class="col-actions"><span class="cx-sr">Actions</span></th>' +
                    '</tr></thead>' +
                    '<tbody>' + pageItems.map(rowHtml).join('') + '</tbody>' +
                '</table>';
        }
    }

    document.getElementById('pgInfo').textContent = filtered.length === 0
        ? 'No products'
        : ('Showing ' + (startIdx + 1) + '–' + Math.min(startIdx + pageItems.length, filtered.length) + ' of ' + filtered.length);

    renderPagination(totalPages);
    document.getElementById('pageCount').textContent = pageItems.length;
    updateSelectionUI();
    var selectAllBox = document.getElementById('selectAll');
    selectAllBox.checked = pageItems.length > 0 && pageItems.every(function (p) { return state.selected[p.id]; });
}

function statusPillHtml(status) {
    var label = statusLabel(status);
    var cls = status === 'out' ? 'out' : (status === 'critical' ? 'critical' : (status === 'low' ? 'low' : 'ok'));
    var icon = status === 'out' ? 'ph-x-circle' : (status === 'critical' ? 'ph-warning-circle' : (status === 'low' ? 'ph-warning' : 'ph-check-circle'));
    return '<span class="stock-status-pill ' + cls + '"><i class="ph-fill ' + icon + '" aria-hidden="true"></i> ' + label + '</span>';
}

function actionMenuHtml(p) {
    return '' +
        '<div class="ellipsis-wrap">' +
            '<button type="button" class="ellipsis-btn" id="menuBtn-' + p.id + '" onclick="toggleMenu(event, ' + p.id + ')" aria-haspopup="true" aria-expanded="false" aria-controls="menu-' + p.id + '" aria-label="Actions for ' + escapeHtml(p.name) + '"><i class="ph ph-dots-three" aria-hidden="true"></i></button>' +
            '<div class="ellipsis-menu" id="menu-' + p.id + '" role="menu">' +
                '<button type="button" role="menuitem" onclick="viewDetails(' + p.id + ')"><i class="ph ph-eye" aria-hidden="true"></i> View Details</button>' +
                '<button type="button" role="menuitem" onclick="openEditModal(' + p.id + ')"><i class="ph ph-pencil-simple" aria-hidden="true"></i> Edit</button>' +
                barcodeMenuItem(p) +
                '<button type="button" role="menuitem" class="danger" onclick="deleteOne(' + p.id + ')"><i class="ph ph-trash" aria-hidden="true"></i> Delete</button>' +
            '</div>' +
        '</div>';
}

function rowHtml(p) {
    var checked = state.selected[p.id] ? 'checked' : '';
    var qtyClass = p.status === 'out' ? ' out' : ((p.status === 'low' || p.status === 'critical') ? ' warn' : '');
    return '' +
    '<tr class="is-' + p.status + (checked ? ' selected' : '') + '" data-id="' + p.id + '">' +
        '<td class="col-chk"><input type="checkbox" class="chk row-chk" data-id="' + p.id + '" ' + checked + ' aria-label="Select ' + escapeHtml(p.name) + '"></td>' +
        '<td data-label="Product" class="col-product">' +
            '<div class="stocks-pname">' + escapeHtml(p.name) + '</div>' +
            '<span class="stocks-pcat">' + escapeHtml(p.category) + (p.sku ? ' · <span class="cx-mono">' + escapeHtml(p.sku) + '</span>' : '') + '</span>' +
        '</td>' +
        (HAS_BARCODE ? '<td data-label="Barcode" class="col-barcode">' + barcodeCellHtml(p) + '</td>' : '') +
        '<td data-label="Stock" class="num"><span class="stocks-qty' + qtyClass + '">' + p.stock + '</span></td>' +
        '<td data-label="Status">' + statusPillHtml(p.status) + '</td>' +
        '<td data-label="Price" class="num cx-num"><span class="price-tag">' + peso(p.price) + '</span></td>' +
        '<td class="col-actions">' + actionMenuHtml(p) + '</td>' +
    '</tr>';
}

function cardHtml(p) {
    var checked = state.selected[p.id] ? 'checked' : '';
    var qtyClass = p.status === 'out' ? ' out' : ((p.status === 'low' || p.status === 'critical') ? ' warn' : '');
    var img = p.img ? '<img src="' + escapeHtml(p.img) + '" class="thumb" alt="" loading="lazy">' : '<div class="thumb thumb-empty"><i class="ph ph-image" aria-hidden="true"></i></div>';
    return '' +
    '<div class="product-row is-' + p.status + (checked ? ' selected' : '') + '" data-id="' + p.id + '">' +
        '<input type="checkbox" class="chk row-chk" data-id="' + p.id + '" ' + checked + ' aria-label="Select ' + escapeHtml(p.name) + '">' +
        img +
        '<div class="product-main">' +
            '<div class="stocks-pname">' + escapeHtml(p.name) + '</div>' +
            '<span class="stocks-pcat">' + escapeHtml(p.category) + '</span>' +
            '<div class="product-meta">' + statusPillHtml(p.status) + '</div>' +
            (HAS_BARCODE ? '<div class="product-meta">' + barcodeCellHtml(p) + '</div>' : '') +
        '</div>' +
        '<div class="product-figures">' +
            '<div><span class="price-label">Stock</span><span class="stocks-qty' + qtyClass + '">' + p.stock + '</span></div>' +
            '<div><span class="price-label">Price</span><span class="price-tag cx-num">' + peso(p.price) + '</span></div>' +
        '</div>' +
        actionMenuHtml(p) +
    '</div>';
}

function renderPagination(totalPages) {
    var el = document.getElementById('pgControls');
    var WINDOW = 20;
    var windowIndex = Math.floor((state.page - 1) / WINDOW);
    var start = windowIndex * WINDOW + 1;
    var end = Math.min(start + WINDOW - 1, totalPages);
    var html = '';
    if (start > 1) {
        html += '<button type="button" aria-label="Previous page set" onclick="goToPage(' + (start - 1) + ')"><i class="ph ph-caret-double-left" aria-hidden="true"></i></button>';
    }
    html += '<button type="button" ' + (state.page <= 1 ? 'disabled' : '') + ' onclick="goToPage(' + (state.page - 1) + ')"><i class="ph ph-caret-left" aria-hidden="true"></i> Prev</button>';
    for (var i = start; i <= end; i++) {
        html += '<button type="button" class="' + (i === state.page ? 'active' : '') + '"' + (i === state.page ? ' aria-current="page"' : '') + ' onclick="goToPage(' + i + ')">' + i + '</button>';
    }
    html += '<button type="button" ' + (state.page >= totalPages ? 'disabled' : '') + ' onclick="goToPage(' + (state.page + 1) + ')">Next <i class="ph ph-caret-right" aria-hidden="true"></i></button>';
    if (end < totalPages) {
        html += '<button type="button" aria-label="Next page set" onclick="goToPage(' + (end + 1) + ')"><i class="ph ph-caret-double-right" aria-hidden="true"></i></button>';
    }
    el.innerHTML = html;
}

function goToPage(p) { state.page = p; render(); }

function closeMenus(except) {
    document.querySelectorAll('.ellipsis-menu.open').forEach(function (m) {
        if (m.id === except) return;
        m.classList.remove('open');
        var b = document.getElementById(m.id.replace('menu-', 'menuBtn-'));
        if (b) b.setAttribute('aria-expanded', 'false');
    });
}
function toggleMenu(evt, id) {
    evt.stopPropagation();
    closeMenus('menu-' + id);
    var menu = document.getElementById('menu-' + id);
    var open = menu.classList.toggle('open');
    var btn = document.getElementById('menuBtn-' + id);
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
        var first = menu.querySelector('button');
        if (first) first.focus({ preventScroll: true });
    }
}
document.addEventListener('click', function () { closeMenus(''); });
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('.ellipsis-menu.open');
    if (!open) return;
    var btn = document.getElementById(open.id.replace('menu-', 'menuBtn-'));
    closeMenus('');
    if (btn) btn.focus();
});

/* ---- Dialogs: open/close, Escape, focus returns to the control that opened them ---- */
var modalReturn = {};
function openModal(id) {
    var m = document.getElementById(id);
    if (!m) return;
    var from = document.activeElement;
    var fromMenu = from && from.closest ? from.closest('.ellipsis-menu') : null;
    /* An item of a row menu disappears with the menu: give focus back to that row's menu button */
    modalReturn[id] = fromMenu ? document.getElementById(fromMenu.id.replace('menu-', 'menuBtn-')) : from;
    m.classList.add('open');
    var first = m.querySelector('input:not([type=hidden]):not([disabled]), select, textarea, .modal-content button:not(.close)');
    setTimeout(function () { (first || m.querySelector('.close')).focus({ preventScroll: true }); }, 30);
}
function closeModal(id) {
    var m = document.getElementById(id);
    if (!m || !m.classList.contains('open')) return;
    m.classList.remove('open');
    var back = modalReturn[id];
    if (back && document.contains(back) && typeof back.focus === 'function') back.focus({ preventScroll: true });
}
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || document.querySelector('.ellipsis-menu.open')) return;
    var open = document.querySelector('.modal.open');
    if (open) closeModal(open.id);
});

function findProduct(id) {
    for (var i = 0; i < ALL_PRODUCTS.length; i++) if (ALL_PRODUCTS[i].id === id) return ALL_PRODUCTS[i];
    return null;
}

function viewDetails(id) {
    var p = findProduct(id);
    if (!p) return;
    var imgEl = document.getElementById('d_img');
    if (p.img) { imgEl.src = p.img; imgEl.style.display = 'block'; } else { imgEl.style.display = 'none'; }
    document.getElementById('d_name').textContent = p.name;
    document.getElementById('d_category').textContent = p.category;
    document.getElementById('d_stock').textContent = p.stock;
    document.getElementById('d_status').textContent = statusLabel(p.status);
    document.getElementById('d_price').textContent = peso(p.price);
    var skuEl = document.getElementById('d_sku');
    if (skuEl) skuEl.textContent = p.sku || '—';
    var barcodeEl = document.getElementById('d_barcode');
    if (barcodeEl) barcodeEl.textContent = p.barcode ? barcodeLabel(p.barcode) : '—';
    document.getElementById('d_created').textContent = p.created_at || '—';
    document.getElementById('d_desc').textContent = p.description || '—';
    openModal('detailsModal');
}

function openEditModal(id) {
    var p = findProduct(id);
    if (!p) return;
    document.getElementById('edit_id').value = p.id;
    document.getElementById('edit_name').value = p.name || '';
    document.getElementById('edit_category').value = p.category_value || p.category || 'Others';
    document.getElementById('edit_price').value = p.price;
    document.getElementById('edit_stock').value = p.stock;
    document.getElementById('edit_desc').value = p.description || '';
    var skuField = document.getElementById('edit_sku');
    if (skuField) skuField.value = p.sku || '';
    var barcodeField = document.getElementById('edit_barcode');
    if (barcodeField) barcodeField.value = p.barcode || '';
    openModal('editModal');
}
function closeEditModal() { closeModal('editModal'); }
document.getElementById('editModal').addEventListener('click', function (e) { if (e.target === this) closeEditModal(); });
document.getElementById('addModal').addEventListener('click', function (e) { if (e.target === this) closeModal('addModal'); });
document.getElementById('detailsModal').addEventListener('click', function (e) { if (e.target === this) closeModal('detailsModal'); });

function deleteOne(id) {
    var p = findProduct(id);
    IAS_UI.confirm('This removes the product from the catalog.', {
        title: 'Delete this product?', detail: p ? p.name : '', confirmLabel: 'Delete', type: 'danger'
    }).then(function (ok) {
        if (!ok) return;
        var f = document.createElement('form');
        f.method = 'post';
        f.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(f);
        f.submit();
    });
}

function updateSelectionUI() {
    var ids = Object.keys(state.selected).filter(function (id) { return state.selected[id]; });
    var bar = document.getElementById('selectionBar');
    document.getElementById('selectedCount').textContent = ids.length;
    bar.classList.toggle('show', ids.length > 0);
    document.getElementById('selectedChip').classList.toggle('active', ids.length > 0);
}

document.getElementById('productList').addEventListener('change', function (e) {
    if (e.target.classList.contains('row-chk')) {
        state.selected[e.target.dataset.id] = e.target.checked;
        var row = e.target.closest('tr') || e.target.closest('.product-row');
        if (row) row.classList.toggle('selected', e.target.checked);
        updateSelectionUI();
        var selectAllBox = document.getElementById('selectAll');
        var visible = document.querySelectorAll('.row-chk');
        selectAllBox.checked = visible.length > 0 && Array.prototype.every.call(visible, function (c) { return c.checked; });
    }
});

document.getElementById('selectAll').addEventListener('change', function () {
    var checked = this.checked;
    document.querySelectorAll('.row-chk').forEach(function (c) {
        var row = c.closest('tr') || c.closest('.product-row');
        if (row) row.classList.toggle('selected', checked);
        c.checked = checked;
        state.selected[c.dataset.id] = checked;
    });
    updateSelectionUI();
});

document.getElementById('bulkDeleteBtn').addEventListener('click', function () {
    var ids = Object.keys(state.selected).filter(function (id) { return state.selected[id]; });
    if (ids.length === 0) return;
    IAS_UI.confirm('They will be removed from the catalog.', {
        title: 'Delete ' + ids.length + ' selected product(s)?', confirmLabel: 'Delete', type: 'danger'
    }).then(function (ok) {
        if (!ok) return;
        var holder = document.getElementById('bulkDeleteIds');
        holder.innerHTML = ids.map(function (id) { return '<input type="hidden" name="ids[]" value="' + id + '">'; }).join('');
        document.getElementById('bulkDeleteForm').submit();
    });
});

if (HAS_BARCODE) {
    var selectedIds = function () {
        return Object.keys(state.selected).filter(function (id) { return state.selected[id]; }).map(Number);
    };
    document.getElementById('bulkLabelBtn').addEventListener('click', function () {
        var ids = selectedIds();
        var withCode = ids.filter(function (id) { var p = findProduct(id); return p && p.barcode; });
        if (!withCode.length) {
            if (typeof IAS_UI !== 'undefined') IAS_UI.alert('None of the selected products has a barcode yet. Use Generate Barcodes first.', 'info', 0);
            return;
        }
        openLabelModal(withCode);
    });
    document.getElementById('bulkGenerateBtn').addEventListener('click', function () { generateBarcodes(selectedIds()); });
    document.getElementById('productList').addEventListener('click', function (e) {
        var lbl = e.target.closest('[data-label-id]');
        if (lbl) { e.stopPropagation(); openLabelModal([Number(lbl.getAttribute('data-label-id'))]); return; }
        var gen = e.target.closest('[data-generate-id]');
        if (gen) { e.stopPropagation(); generateBarcodes([Number(gen.getAttribute('data-generate-id'))]); }
    });
    document.getElementById('labelShowPrice').addEventListener('change', renderLabelPreview);
    document.getElementById('labelPrintBtn').addEventListener('click', printLabels);
    document.getElementById('labelModal').addEventListener('click', function (e) { if (e.target === this) closeLabelModal(); });
    if (OPEN_LABEL_ID) {
        document.addEventListener('DOMContentLoaded', function () { openLabelModal([OPEN_LABEL_ID]); });
    }
}

function updateFilterBtnState() {
    var btn = document.getElementById('filterToggleBtn');
    var active = state.status !== 'all' || state.alert !== 'all' || state.barcode !== 'all' || state.sort !== 'name_asc'
        || state.priceMin !== null || state.priceMax !== null;
    btn.classList.toggle('has-active', active);
    document.getElementById('filterActiveText').textContent = active ? ' (filters on)' : '';
    document.querySelectorAll('.status-btn').forEach(function (b) {
        b.setAttribute('aria-pressed', b.classList.contains('active') ? 'true' : 'false');
    });
}

var filterBtn = document.getElementById('filterToggleBtn');
var filterPanel = document.getElementById('stocksFilterPanel');
filterBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = filterPanel.classList.toggle('open');
    filterBtn.classList.toggle('open', open);
    filterBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
});
filterPanel.addEventListener('click', function (e) { e.stopPropagation(); });
document.addEventListener('click', function () {
    filterPanel.classList.remove('open');
    filterBtn.classList.remove('open');
    filterBtn.setAttribute('aria-expanded', 'false');
});
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || !filterPanel.classList.contains('open')) return;
    filterPanel.classList.remove('open');
    filterBtn.classList.remove('open');
    filterBtn.setAttribute('aria-expanded', 'false');
    filterBtn.focus();
});

/* ---- Filter/sort/search controls ---- */
document.getElementById('statusToggle').addEventListener('click', function (e) {
    var btn = e.target.closest('.status-btn');
    if (!btn) return;
    document.querySelectorAll('.status-btn').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    state.status = btn.dataset.status;
    state.page = 1;
    updateFilterBtnState();
    render();
});

document.getElementById('sortBy').addEventListener('change', function () {
    state.sort = this.value;
    updateFilterBtnState();
    render();
});

var barcodeFilterEl = document.getElementById('barcodeFilter');
if (barcodeFilterEl) {
    barcodeFilterEl.addEventListener('change', function () {
        state.barcode = this.value;
        state.page = 1;
        updateFilterBtnState();
        render();
    });
}

document.getElementById('stockAlert').addEventListener('change', function () {
    state.alert = this.value;
    state.page = 1;
    updateFilterBtnState();
    render();
});

document.getElementById('priceMin').addEventListener('input', function () {
    state.priceMin = this.value === '' ? null : parseFloat(this.value);
    state.page = 1;
    updateFilterBtnState();
    render();
});
document.getElementById('priceMax').addEventListener('input', function () {
    state.priceMax = this.value === '' ? null : parseFloat(this.value);
    state.page = 1;
    updateFilterBtnState();
    render();
});

document.getElementById('searchInput').addEventListener('input', function () {
    state.search = this.value;
    state.page = 1;
    render();
});
document.getElementById('scanBtn').addEventListener('click', function () {
    var input = document.getElementById('searchInput');
    input.focus();
    input.select();
    if (typeof IAS_UI !== 'undefined') {
        IAS_UI.alert('Ready to scan — point your barcode scanner at the product and scan now.', 'success', 0);
    }
});

document.getElementById('resetFilters').addEventListener('click', function () {
    state.status = 'all'; state.sort = 'name_asc'; state.alert = 'all'; state.barcode = 'all';
    if (barcodeFilterEl) barcodeFilterEl.value = 'all';
    state.priceMin = null; state.priceMax = null; state.page = 1;
    document.querySelectorAll('.status-btn').forEach(function (b) { b.classList.remove('active'); });
    document.querySelector('.status-btn[data-status="all"]').classList.add('active');
    document.getElementById('sortBy').value = 'name_asc';
    document.getElementById('stockAlert').value = 'all';
    document.getElementById('priceMin').value = '';
    document.getElementById('priceMax').value = '';
    updateFilterBtnState();
    render();
});

document.getElementById('listViewBtn').addEventListener('click', function () {
    state.view = 'list';
    this.classList.add('active');
    this.setAttribute('aria-pressed', 'true');
    document.getElementById('gridViewBtn').classList.remove('active');
    document.getElementById('gridViewBtn').setAttribute('aria-pressed', 'false');
    render();
});
document.getElementById('gridViewBtn').addEventListener('click', function () {
    state.view = 'grid';
    this.classList.add('active');
    this.setAttribute('aria-pressed', 'true');
    document.getElementById('listViewBtn').classList.remove('active');
    document.getElementById('listViewBtn').setAttribute('aria-pressed', 'false');
    render();
});

// Deep links from the dashboard: ?status=outofstock|instock, ?alert=low|critical
(function () {
    var params = new URLSearchParams(window.location.search);
    var urlStatus = params.get('status');
    var urlAlert = params.get('alert');
    if (urlStatus === 'instock' || urlStatus === 'outofstock') {
        state.status = urlStatus;
        document.querySelectorAll('.status-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.status === urlStatus);
        });
    }
    if (urlAlert === 'low' || urlAlert === 'critical') {
        state.alert = urlAlert;
        document.getElementById('stockAlert').value = urlAlert;
        state.sort = 'stock_asc';
        document.getElementById('sortBy').value = 'stock_asc';
    }
})();

updateFilterBtnState();
render();
</script>
SCRIPTS;

staff_page_end('<script src="../includes/barcode_label.js?v=1"></script>' . $mainScript . $flashScript);
?>
