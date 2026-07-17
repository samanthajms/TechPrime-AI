<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/product_categories.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$uid = (int)$_SESSION['user_id'];
$allowed_categories = ['Laptops', 'Desktop', 'Mobile', 'Cameras', 'Accessories'];

/* Stock alert thresholds (qty-based; no per-product config in the schema). */
const CRITICAL_STOCK_MAX = 5;   // 0 < stock <= 5
const LOW_STOCK_MAX = 15;       // 0 < stock <= 15 (includes critical)

function inventory_product_category(array $allowed): string
{
    $category = $_POST['category'] ?? 'Accessories';
    return in_array($category, $allowed, true) ? $category : 'Accessories';
}

/** Feature-detect optional columns so this page works with or without migration_inventory_sku.sql applied. */
function inventory_products_has_column(mysqli $db, string $column): bool
{
    static $cache = [];
    if (isset($cache[$column])) {
        return $cache[$column];
    }
    $col = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM products LIKE '$col'");
    $has = $res && $res->num_rows > 0;
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
    $imageFile = ias_handle_product_upload($uid);

    if ($name !== '' && $price > 0 && $stock >= 0 && $imageFile !== null) {
        $emptyUrl = '';
        if ($hasSku) {
            $stmt = $db->prepare(
                'INSERT INTO products (seller_id, name, price, stock, description, image, image_url, category, sku)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $skuVal = $sku !== '' ? $sku : null;
            $stmt->bind_param('isdisssss', $uid, $name, $price, $stock, $desc, $imageFile, $emptyUrl, $category, $skuVal);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO products (seller_id, name, price, stock, description, image, image_url, category)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('isdissss', $uid, $name, $price, $stock, $desc, $imageFile, $emptyUrl, $category);
        }
        $stmt->execute();
        $stmt->close();

        logActivity($db, $uid, 'add_product', "Added product: $name");
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
        $newImage = ias_handle_product_upload($uid);
        $triedImageUpload = !empty($_FILES['product_image']['name']);

        if ($triedImageUpload && $newImage === null) {
            header('Location: inventory_stocks.php?alert=error');
            exit;
        }

        if ($newImage !== null) {
            $oldSt = $db->prepare('SELECT image FROM products WHERE id = ?');
            $oldSt->bind_param('i', $id);
            $oldSt->execute();
            $oldRow = $oldSt->get_result()->fetch_assoc();
            $oldSt->close();

            $emptyUrl = '';
            if ($hasSku) {
                $stmt = $db->prepare(
                    'UPDATE products
                     SET name = ?, price = ?, stock = ?, description = ?, category = ?, image = ?, image_url = ?, sku = ?
                     WHERE id = ?'
                );
                $skuVal = $sku !== '' ? $sku : null;
                $stmt->bind_param('sdisssssi', $name, $price, $stock, $desc, $category, $newImage, $emptyUrl, $skuVal, $id);
            } else {
                $stmt = $db->prepare(
                    'UPDATE products
                     SET name = ?, price = ?, stock = ?, description = ?, category = ?, image = ?, image_url = ?
                     WHERE id = ?'
                );
                $stmt->bind_param('sdissssi', $name, $price, $stock, $desc, $category, $newImage, $emptyUrl, $id);
            }
            $stmt->execute();
            $stmt->close();

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
                $stmt->bind_param('sdisssi', $name, $price, $stock, $desc, $category, $skuVal, $id);
            } else {
                $stmt = $db->prepare(
                    'UPDATE products
                     SET name = ?, price = ?, stock = ?, description = ?, category = ?
                     WHERE id = ?'
                );
                $stmt->bind_param('sdissi', $name, $price, $stock, $desc, $category, $id);
            }
            $stmt->execute();
            $stmt->close();
        }

        logActivity($db, $uid, 'edit_product', "Updated product #$id");
        header('Location: inventory_stocks.php?alert=updated');
        exit;
    }

    header('Location: inventory_stocks.php?alert=error');
    exit;
}

/** Delete a single product row (id already validated as int > 0) plus its image + cart refs. */
function inventory_delete_product(mysqli $db, int $pid): void
{
    $imgSt = $db->prepare('SELECT image FROM products WHERE id = ?');
    $imgSt->bind_param('i', $pid);
    $imgSt->execute();
    $row = $imgSt->get_result()->fetch_assoc();
    $imgSt->close();

    $del = $db->prepare('DELETE FROM products WHERE id = ?');
    $del->bind_param('i', $pid);
    $del->execute();
    $del->close();

    $cart = $db->prepare('DELETE FROM cart WHERE product_id = ?');
    $cart->bind_param('i', $pid);
    $cart->execute();
    $cart->close();

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
        inventory_delete_product($db, $pid);
        logActivity($db, $uid, 'delete_product', "Deleted product #$pid");
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
 * Data for the page: full product set (filtering/sorting/paging is done
 * client-side in JS so the dashboard updates instantly with no reloads),
 * plus the counts and category list the sidebar needs.
 * ------------------------------------------------------------------- */
$skuSelect = $hasSku ? 'sku' : 'NULL AS sku';
$products = $db->query("SELECT id, name, description, price, stock, image, image_url, category, created_at, $skuSelect FROM products ORDER BY name ASC");

$totalCount = 0;
$inStockCount = 0;
$outOfStockCount = 0;
$lowStockCount = 0;
$criticalStockCount = 0;
$categoriesInUse = [];
$rows = [];

if ($products) {
    while ($p = $products->fetch_assoc()) {
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
        $cat = $p['category'] ?? 'Accessories';
        if ($cat !== '' && !in_array($cat, $categoriesInUse, true)) {
            $categoriesInUse[] = $cat;
        }
        $rows[] = $p;
    }
}
sort($categoriesInUse);
$categoryOptions = array_values(array_unique(array_merge($categoriesInUse, $allowed_categories)));
sort($categoryOptions);

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Stocks',
    'active' => 'stocks',
    'heading' => 'Stocks',
    'subtitle' => 'Manage product inventory',
    'extra_head' => <<<'EXTRA'
<style>
.stocks-layout { display: grid; grid-template-columns: 260px 1fr; gap: 24px; align-items: start; }
@media (max-width: 980px) { .stocks-layout { grid-template-columns: 1fr; } }

/* ---- Filter sidebar ---- */
.filter-card { position: sticky; top: 16px; }
.filter-card .card-body { display: flex; flex-direction: column; gap: 18px; }
.filter-title { font-size: 13px; font-weight: 700; color: var(--ep-text); margin-bottom: 8px; display: block; }
.filter-count { font-size: 13px; color: var(--ep-muted); font-weight: 600; }
.status-toggle { display: flex; flex-direction: column; gap: 6px; }
.status-btn {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 12px; border-radius: 8px; border: 1px solid var(--ep-border);
    background: #fff; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--ep-text);
    transition: all .15s ease;
}
.status-btn:hover { border-color: var(--ep-green); }
.status-btn.active { background: var(--ep-green); border-color: var(--ep-green); color: #fff; }
.status-btn .cnt { font-weight: 700; opacity: .85; }
.price-range { display: flex; align-items: center; gap: 8px; }
.price-range input { width: 100%; }
.btn-reset { width: 100%; }

/* ---- Main content ---- */
.stocks-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.search-wrap {
    flex: 1 1 320px; display: flex; align-items: center; gap: 6px;
    background: var(--ep-gray-bg); border: 1px solid var(--ep-border); border-radius: 999px; padding: 4px 6px 4px 16px;
}
.search-wrap i.fa-search { color: var(--ep-muted); }
.search-wrap input { flex: 1; border: none; background: transparent; box-shadow: none; padding: 8px 4px; }
.search-wrap input:focus { outline: none; box-shadow: none; }
.btn-scan {
    white-space: nowrap; border-radius: 999px !important; background: var(--ep-green-light);
    border: 1px solid var(--ep-green) !important; color: var(--ep-green-dark) !important; font-weight: 700;
}
.toolbar-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.view-toggle { display: flex; border: 1px solid var(--ep-border); border-radius: 999px; overflow: hidden; }
.view-toggle button {
    border: none; background: #fff; padding: 8px 12px; cursor: pointer; color: var(--ep-muted);
}
.view-toggle button.active { background: var(--ep-green); color: #fff; }
#addProductBtn { border-radius: 999px; }

.select-row {
    display: flex; align-items: center; gap: 18px; margin-bottom: 12px; flex-wrap: wrap;
}
.select-chip {
    display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--ep-text);
    border: 1px solid var(--ep-border); border-radius: 999px; padding: 6px 14px; background: #fff; cursor: pointer;
}
.select-chip.active { border-color: var(--ep-green); color: var(--ep-green-dark); background: var(--ep-green-light); }
.select-chip input { width: 16px; height: 16px; }
.selection-bar {
    display: none; align-items: center; gap: 10px; margin-left: auto;
}
.selection-bar.show { display: flex; }

.thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 10px; background: var(--ep-green-light); flex-shrink: 0; }
.category-pill {
    background: var(--ep-green-light); color: var(--ep-green-dark);
    padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700;
}
.variant-pill {
    background: #eaf0ff; color: #2527a8; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700;
}
.stock-type { color: var(--ep-muted); display: inline-flex; align-items: center; gap: 4px; }
.stock-pill { padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.stock-pill.out { background: #fdecea; color: #c0392b; }
.stock-pill.critical { background: #fdecea; color: #c0392b; }
.stock-pill.low { background: #fff8db; color: var(--ep-yellow-dark); }
.stock-pill.ok { background: var(--ep-green-light); color: var(--ep-green-dark); }
.price-tag { color: var(--ep-green-dark); font-weight: 800; }
.price-label { font-size: 11px; color: var(--ep-muted); font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }

/* Product list rows */
.product-list { display: flex; flex-direction: column; gap: 10px; }
.product-row {
    display: flex; align-items: center; gap: 14px; padding: 14px; border: 1.5px solid var(--ep-border);
    border-radius: 14px; background: #fff; transition: border-color .15s ease, box-shadow .15s ease;
}
.product-row.selected { border-color: var(--ep-green); box-shadow: 0 0 0 1px var(--ep-green) inset; }
.product-row .chk { flex-shrink: 0; width: 18px; height: 18px; accent-color: var(--ep-green); }
.product-main { flex: 1; min-width: 0; }
.product-main .pname { font-weight: 700; color: var(--ep-text); }
.product-meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 4px; font-size: 12px; color: var(--ep-muted); }
.stock-qty { font-weight: 700; }
.stock-qty.warn { color: #c0392b; }
.low-flag { color: #c0392b; font-weight: 700; display: inline-flex; align-items: center; gap: 3px; }
.low-flag::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #c0392b; display: inline-block; }
.product-price { text-align: right; min-width: 100px; }
.ellipsis-wrap { position: relative; flex-shrink: 0; }
.ellipsis-btn {
    border: none; background: none; font-size: 18px; color: var(--ep-muted); cursor: pointer;
    width: 32px; height: 32px; border-radius: 999px;
}
.ellipsis-btn:hover { background: var(--ep-gray-bg); }
.ellipsis-menu {
    display: none; position: absolute; right: 0; top: 36px; background: #fff; border: 1px solid var(--ep-border);
    border-radius: 8px; box-shadow: var(--card-shadow); z-index: 50; min-width: 150px; overflow: hidden;
}
.ellipsis-menu.open { display: block; }
.ellipsis-menu button {
    display: block; width: 100%; text-align: left; padding: 10px 14px; border: none; background: none;
    cursor: pointer; font-size: 13px; color: var(--ep-text);
}
.ellipsis-menu button:hover { background: var(--ep-gray-bg); }
.ellipsis-menu button.danger { color: #c0392b; }

/* Grid view */
.product-list.grid-view { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
.product-list.grid-view .product-row { flex-direction: column; align-items: stretch; text-align: center; position: relative; }
.product-list.grid-view .thumb { width: 100%; height: 120px; margin: 0 auto; }
.product-list.grid-view .product-main { text-align: left; margin-top: 6px; }
.product-list.grid-view .product-price { text-align: left; margin-top: 6px; }
.product-list.grid-view .chk { position: absolute; top: 10px; left: 10px; }
.product-list.grid-view .ellipsis-wrap { position: absolute; top: 6px; right: 6px; }

.empty-state-row { text-align: center; padding: 40px 0; color: var(--ep-muted); }

/* Pagination */
.pagination-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 16px; flex-wrap: wrap; }
.pagination-bar .pg-info { font-size: 13px; color: var(--ep-muted); }
.pagination-bar .pg-controls { display: flex; gap: 6px; align-items: center; }
.pagination-bar button {
    border: 1px solid var(--ep-border); background: #fff; border-radius: 6px; padding: 6px 12px;
    cursor: pointer; font-size: 13px; font-weight: 600; color: var(--ep-text);
}
.pagination-bar button.active { background: var(--ep-green); border-color: var(--ep-green); color: #fff; }
.pagination-bar button:disabled { opacity: .4; cursor: not-allowed; }

/* Modals */
.modal {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
    z-index: 2000; align-items: center; justify-content: center;
}
.modal.open { display: flex; }
.modal-content {
    background: #fff; padding: 28px; border-radius: 12px; width: 460px;
    max-width: 92%; max-height: 90vh; overflow-y: auto; position: relative;
    border: 2px solid var(--ep-green);
}
.modal-content.wide { width: 560px; }
.modal .close {
    position: absolute; right: 16px; top: 12px; font-size: 24px;
    cursor: pointer; border: none; background: none; color: #888; line-height: 1;
}
.modal h3 { margin: 0 0 16px; color: var(--ep-green-dark); }
.details-grid { display: grid; grid-template-columns: 120px 1fr; gap: 10px 14px; font-size: 13px; }
.details-grid dt { color: var(--ep-muted); font-weight: 600; }
.details-grid dd { margin: 0; color: var(--ep-text); font-weight: 600; }
.details-img { width: 100%; max-height: 220px; object-fit: contain; background: var(--ep-green-light); border-radius: 10px; margin-bottom: 16px; }
</style>
EXTRA
]);
?>

        <div class="stocks-layout">
            <!-- ============================== FILTER SIDEBAR ============================== -->
            <div class="card filter-card">
                <div class="card-header">
                    <div>
                        <h3><i class="fas fa-sliders-h"></i> Product</h3>
                        <div class="card-subtitle"><span id="totalCountLabel"><?php echo (int)$totalCount; ?></span> Products</div>
                    </div>
                </div>
                <div class="card-body">
                    <div>
                        <span class="filter-title">Product Status</span>
                        <div class="status-toggle" id="statusToggle">
                            <button type="button" class="status-btn active" data-status="all">All <span class="cnt"><?php echo (int)$totalCount; ?></span></button>
                            <button type="button" class="status-btn" data-status="instock">In stock <span class="cnt"><?php echo (int)$inStockCount; ?></span></button>
                            <button type="button" class="status-btn" data-status="outofstock">Out of Stock <span class="cnt"><?php echo (int)$outOfStockCount; ?></span></button>
                        </div>
                    </div>

                    <div>
                        <span class="filter-title">Product Category</span>
                        <input type="text" id="categoryFilter" class="form-control" list="categoryOptions" placeholder="All Categories" autocomplete="off">
                        <datalist id="categoryOptions">
                            <?php foreach ($categoryOptions as $cat): ?>
                                <option value="<?php echo h($cat); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div>
                        <span class="filter-title">Sort By</span>
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
                        <span class="filter-title">Stock Alert</span>
                        <select id="stockAlert" class="form-control">
                            <option value="all" selected>All Stock</option>
                            <option value="low">Low Stock (&le; <?php echo LOW_STOCK_MAX; ?>)</option>
                            <option value="critical">Critical Stock (&le; <?php echo CRITICAL_STOCK_MAX; ?>)</option>
                        </select>
                    </div>

                    <div>
                        <span class="filter-title">Price Range</span>
                        <div class="price-range">
                            <input type="number" min="0" step="0.01" id="priceMin" class="form-control" placeholder="Min">
                            <span>&ndash;</span>
                            <input type="number" min="0" step="0.01" id="priceMax" class="form-control" placeholder="Max">
                        </div>
                    </div>

                    <button type="button" id="resetFilters" class="btn btn-outline btn-reset">Reset Filters</button>
                </div>
            </div>

            <!-- ============================== MAIN CONTENT ============================== -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-boxes"></i></span> Current Inventory</h3>
                        <div class="card-subtitle">All stocked products</div>
                    </div>
                </div>
                <div class="card-body" style="padding-top:0;">

                    <div class="stocks-toolbar">
                        <div class="search-wrap">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" class="form-control" placeholder="Search by name, category or SKU...">
                            <button type="button" id="scanBtn" class="btn btn-outline btn-scan" title="Focus this field, then use a barcode scanner (acts as keyboard input ending in Enter)">
                                <i class="fas fa-barcode"></i> Scan
                            </button>
                        </div>
                        <div class="toolbar-actions">
                            <div class="view-toggle">
                                <button type="button" id="listViewBtn" class="active" title="List view"><i class="fas fa-list"></i></button>
                                <button type="button" id="gridViewBtn" title="Grid view"><i class="fas fa-th-large"></i></button>
                            </div>
                            <button type="button" id="addProductBtn" class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('open')">
                                <i class="fas fa-plus"></i> Add Product
                            </button>
                        </div>
                    </div>

                    <div class="select-row">
                        <label class="select-chip" id="selectedChip">
                            <input type="checkbox" id="selectedIndicator" checked disabled>
                            Selected (<span id="selectedCount">0</span>)
                        </label>
                        <label class="select-chip" for="selectAll">
                            <input type="checkbox" id="selectAll">
                            Select All (<span id="pageCount">0</span>)
                        </label>
                        <div class="selection-bar" id="selectionBar">
                            <button type="button" class="btn btn-danger btn-sm" id="bulkDeleteBtn"><i class="fas fa-trash"></i> Delete</button>
                        </div>
                    </div>

                    <div id="productList" class="product-list"><!-- rows injected by JS --></div>
                    <div id="emptyState" class="empty-state-row" style="display:none;">No products match your filters.</div>

                    <div class="pagination-bar">
                        <div class="pg-info" id="pgInfo"></div>
                        <div class="pg-controls" id="pgControls"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================== ADD PRODUCT MODAL ============================== -->
        <div id="addModal" class="modal">
            <div class="modal-content">
                <button type="button" class="close" onclick="document.getElementById('addModal').classList.remove('open')">&times;</button>
                <h3><i class="fas fa-plus"></i> Add New Product</h3>
                <form method="post" enctype="multipart/form-data">
                    <?php if ($hasSku): ?>
                    <div class="form-group">
                        <label class="form-label">Barcode / SKU (optional)</label>
                        <input type="text" name="sku" class="form-control" placeholder="Scan or type a barcode/SKU">
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">Product Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Wireless Keyboard" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-control" required>
                            <option value="" disabled selected>Select a category...</option>
                            <?php foreach ($allowed_categories as $category): ?>
                                <option value="<?php echo h($category); ?>"><?php echo h($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Price</label>
                        <input type="number" step="0.01" min="0.01" name="price" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Stock</label>
                        <input type="number" min="0" name="stock" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Describe your product..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Product Image (JPG, JPEG, PNG)</label>
                        <input type="file" name="product_image" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
                    </div>
                    <button type="submit" name="add_product" class="btn btn-primary" style="width:100%;">Add Product</button>
                </form>
            </div>
        </div>

        <!-- ============================== EDIT PRODUCT MODAL ============================== -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <button type="button" class="close" onclick="closeEditModal()">&times;</button>
                <h3>Edit Product</h3>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="product_id" id="edit_id">
                    <?php if ($hasSku): ?>
                    <div class="form-group">
                        <label class="form-label">Barcode / SKU (optional)</label>
                        <input type="text" name="sku" id="edit_sku" class="form-control">
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">Product Name</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" id="edit_category" class="form-control" required>
                            <?php foreach ($allowed_categories as $category): ?>
                                <option value="<?php echo h($category); ?>"><?php echo h($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Price</label>
                        <input type="number" step="0.01" min="0.01" name="price" id="edit_price" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Stock</label>
                        <input type="number" min="0" name="stock" id="edit_stock" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_desc" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Replace Image (optional)</label>
                        <input type="file" name="product_image" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                    </div>
                    <button type="submit" name="edit_product" class="btn btn-primary" style="width:100%;">Update Product</button>
                </form>
            </div>
        </div>

        <!-- ============================== VIEW DETAILS MODAL ============================== -->
        <div id="detailsModal" class="modal">
            <div class="modal-content wide">
                <button type="button" class="close" onclick="document.getElementById('detailsModal').classList.remove('open')">&times;</button>
                <h3>Product Details</h3>
                <img id="d_img" class="details-img" src="" alt="" style="display:none;">
                <dl class="details-grid">
                    <dt>Name</dt><dd id="d_name"></dd>
                    <dt>Category</dt><dd id="d_category"></dd>
                    <dt>Stock</dt><dd id="d_stock"></dd>
                    <dt>Status</dt><dd id="d_status"></dd>
                    <dt>Price</dt><dd id="d_price"></dd>
                    <?php if ($hasSku): ?><dt>SKU / Barcode</dt><dd id="d_sku"></dd><?php endif; ?>
                    <dt>Date Added</dt><dd id="d_created"></dd>
                    <dt>Description</dt><dd id="d_desc"></dd>
                </dl>
            </div>
        </div>

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
        'category' => $p['category'] ?? 'Accessories',
        'price' => (float)$p['price'],
        'stock' => $stock,
        'status' => inventory_stock_status($stock),
        'description' => $p['description'] ?? '',
        'sku' => $p['sku'] ?? '',
        'created_at' => $p['created_at'] ?? '',
        'img' => ias_product_image_url($p),
    ];
}, $rows);

$productsJson = json_encode($jsProducts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$lowMax = LOW_STOCK_MAX;
$criticalMax = CRITICAL_STOCK_MAX;

$mainScript = <<<SCRIPTS
<script>
var ALL_PRODUCTS = {$productsJson};
var LOW_STOCK_MAX = {$lowMax};
var CRITICAL_STOCK_MAX = {$criticalMax};

var state = {
    status: 'all',
    category: '',
    sort: 'name_asc',
    alert: 'all',
    priceMin: null,
    priceMax: null,
    search: '',
    view: 'list',
    page: 1,
    pageSize: 10,
    selected: {}
};

function peso(n) { return '₱' + Number(n).toFixed(2); }

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
        if (state.category && p.category !== state.category) return false;
        if (state.alert === 'low' && !(p.status === 'low' || p.status === 'critical')) return false;
        if (state.alert === 'critical' && p.status !== 'critical') return false;
        if (state.priceMin !== null && p.price < state.priceMin) return false;
        if (state.priceMax !== null && p.price > state.priceMax) return false;
        if (q) {
            var hay = (p.name + ' ' + p.category + ' ' + (p.sku || '')).toLowerCase();
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
        emptyEl.style.display = 'block';
    } else {
        emptyEl.style.display = 'none';
        listEl.innerHTML = pageItems.map(rowHtml).join('');
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

function rowHtml(p) {
    var warnClass = (p.status === 'low' || p.status === 'critical') ? ' warn' : '';
    var img = p.img ? '<img src="' + escapeHtml(p.img) + '" class="thumb" alt="">' : '<div class="thumb"></div>';
    var checked = state.selected[p.id] ? 'checked' : '';

    var stockBit;
    if (p.status === 'out') {
        stockBit = '<span class="stock-pill out">Out of Stock</span>';
    } else {
        var flag = (p.status === 'critical')
            ? '<span class="low-flag">critical</span>'
            : (p.status === 'low' ? '<span class="low-flag">low</span>' : '');
        stockBit = '<span class="stock-qty' + warnClass + '">' + p.stock + ' in stock</span>' + (flag ? '&nbsp;' + flag : '');
    }

    return '' +
    '<div class="product-row' + (checked ? ' selected' : '') + '" data-id="' + p.id + '">' +
        '<input type="checkbox" class="chk row-chk" data-id="' + p.id + '" ' + checked + '>' +
        img +
        '<div class="product-main">' +
            '<div class="pname">' + escapeHtml(p.name) + '</div>' +
            '<div class="product-meta">' +
                '<span class="category-pill">' + escapeHtml(p.category) + '</span>' +
                '<span class="stock-type"><i class="fas fa-box"></i> Stocked Product:</span>' +
                stockBit +
            '</div>' +
        '</div>' +
        '<div class="product-price">' +
            '<div class="price-label">Price</div>' +
            '<div class="price-tag">' + peso(p.price) + '</div>' +
        '</div>' +
        '<div class="ellipsis-wrap">' +
            '<button type="button" class="ellipsis-btn" onclick="toggleMenu(event, ' + p.id + ')"><i class="fas fa-ellipsis-h"></i></button>' +
            '<div class="ellipsis-menu" id="menu-' + p.id + '">' +
                '<button type="button" onclick="viewDetails(' + p.id + ')"><i class="fas fa-eye"></i> View Details</button>' +
                '<button type="button" onclick="openEditModal(' + p.id + ')"><i class="fas fa-edit"></i> Edit</button>' +
                '<button type="button" class="danger" onclick="deleteOne(' + p.id + ')"><i class="fas fa-trash"></i> Delete</button>' +
            '</div>' +
        '</div>' +
    '</div>';
}

function renderPagination(totalPages) {
    var el = document.getElementById('pgControls');
    var html = '<button ' + (state.page <= 1 ? 'disabled' : '') + ' onclick="goToPage(' + (state.page - 1) + ')">Prev</button>';
    for (var i = 1; i <= totalPages; i++) {
        html += '<button class="' + (i === state.page ? 'active' : '') + '" onclick="goToPage(' + i + ')">' + i + '</button>';
    }
    html += '<button ' + (state.page >= totalPages ? 'disabled' : '') + ' onclick="goToPage(' + (state.page + 1) + ')">Next</button>';
    el.innerHTML = html;
}

function goToPage(p) { state.page = p; render(); }

function toggleMenu(evt, id) {
    evt.stopPropagation();
    document.querySelectorAll('.ellipsis-menu.open').forEach(function (m) {
        if (m.id !== 'menu-' + id) m.classList.remove('open');
    });
    document.getElementById('menu-' + id).classList.toggle('open');
}
document.addEventListener('click', function () {
    document.querySelectorAll('.ellipsis-menu.open').forEach(function (m) { m.classList.remove('open'); });
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
    document.getElementById('d_created').textContent = p.created_at || '—';
    document.getElementById('d_desc').textContent = p.description || '—';
    document.getElementById('detailsModal').classList.add('open');
}

function openEditModal(id) {
    var p = findProduct(id);
    if (!p) return;
    document.getElementById('edit_id').value = p.id;
    document.getElementById('edit_name').value = p.name || '';
    document.getElementById('edit_category').value = p.category || 'Accessories';
    document.getElementById('edit_price').value = p.price;
    document.getElementById('edit_stock').value = p.stock;
    document.getElementById('edit_desc').value = p.description || '';
    var skuField = document.getElementById('edit_sku');
    if (skuField) skuField.value = p.sku || '';
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() { document.getElementById('editModal').classList.remove('open'); }
document.getElementById('editModal').addEventListener('click', function (e) { if (e.target === this) closeEditModal(); });
document.getElementById('addModal').addEventListener('click', function (e) { if (e.target === this) this.classList.remove('open'); });
document.getElementById('detailsModal').addEventListener('click', function (e) { if (e.target === this) this.classList.remove('open'); });

function deleteOne(id) {
    if (!confirm('Delete this product?')) return;
    var f = document.createElement('form');
    f.method = 'post';
    f.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '">';
    document.body.appendChild(f);
    f.submit();
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
        e.target.closest('.product-row').classList.toggle('selected', e.target.checked);
        updateSelectionUI();
        var selectAllBox = document.getElementById('selectAll');
        var visible = document.querySelectorAll('.row-chk');
        selectAllBox.checked = visible.length > 0 && Array.prototype.every.call(visible, function (c) { return c.checked; });
    }
});

document.getElementById('selectAll').addEventListener('change', function () {
    var checked = this.checked;
    document.querySelectorAll('.row-chk').forEach(function (c) {
        c.closest('.product-row').classList.toggle('selected', checked);
        c.checked = checked;
        state.selected[c.dataset.id] = checked;
    });
    updateSelectionUI();
});

document.getElementById('bulkDeleteBtn').addEventListener('click', function () {
    var ids = Object.keys(state.selected).filter(function (id) { return state.selected[id]; });
    if (ids.length === 0) return;
    if (!confirm('Delete ' + ids.length + ' selected product(s)?')) return;
    var holder = document.getElementById('bulkDeleteIds');
    holder.innerHTML = ids.map(function (id) { return '<input type="hidden" name="ids[]" value="' + id + '">'; }).join('');
    document.getElementById('bulkDeleteForm').submit();
});

/* ---- Filter/sort/search controls ---- */
document.getElementById('statusToggle').addEventListener('click', function (e) {
    var btn = e.target.closest('.status-btn');
    if (!btn) return;
    document.querySelectorAll('.status-btn').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    state.status = btn.dataset.status;
    state.page = 1;
    render();
});

document.getElementById('categoryFilter').addEventListener('input', function () {
    state.category = this.value.trim();
    state.page = 1;
    render();
});

document.getElementById('sortBy').addEventListener('change', function () {
    state.sort = this.value;
    render();
});

document.getElementById('stockAlert').addEventListener('change', function () {
    state.alert = this.value;
    state.page = 1;
    render();
});

document.getElementById('priceMin').addEventListener('input', function () {
    state.priceMin = this.value === '' ? null : parseFloat(this.value);
    state.page = 1;
    render();
});
document.getElementById('priceMax').addEventListener('input', function () {
    state.priceMax = this.value === '' ? null : parseFloat(this.value);
    state.page = 1;
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
    state.status = 'all'; state.category = ''; state.sort = 'name_asc'; state.alert = 'all';
    state.priceMin = null; state.priceMax = null; state.search = ''; state.page = 1;
    document.querySelectorAll('.status-btn').forEach(function (b) { b.classList.remove('active'); });
    document.querySelector('.status-btn[data-status="all"]').classList.add('active');
    document.getElementById('categoryFilter').value = '';
    document.getElementById('sortBy').value = 'name_asc';
    document.getElementById('stockAlert').value = 'all';
    document.getElementById('priceMin').value = '';
    document.getElementById('priceMax').value = '';
    document.getElementById('searchInput').value = '';
    render();
});

document.getElementById('listViewBtn').addEventListener('click', function () {
    state.view = 'list';
    this.classList.add('active');
    document.getElementById('gridViewBtn').classList.remove('active');
    document.getElementById('productList').classList.remove('grid-view');
});
document.getElementById('gridViewBtn').addEventListener('click', function () {
    state.view = 'grid';
    this.classList.add('active');
    document.getElementById('listViewBtn').classList.remove('active');
    document.getElementById('productList').classList.add('grid-view');
});

render();
</script>
SCRIPTS;

staff_page_end($mainScript . $flashScript);
?>
