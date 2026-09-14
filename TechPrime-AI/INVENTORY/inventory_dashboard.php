<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$uid = (int)$_SESSION['user_id'];

$total = (int)($db->query('SELECT COUNT(*) FROM shipments')->fetch_row()[0] ?? 0);
$pending = (int)($db->query("SELECT COUNT(*) FROM shipments WHERE shipment_status != 'delivered'")->fetch_row()[0] ?? 0);
$done = (int)($db->query("SELECT COUNT(*) FROM shipments WHERE shipment_status = 'delivered'")->fetch_row()[0] ?? 0);

$products = $db->query('SELECT * FROM products ORDER BY id DESC');
$productRows = ($products && $products->num_rows > 0) ? $products->fetch_all(MYSQLI_ASSOC) : [];

$productCount = count($productRows);
$totalStock = 0;
$lowStockCount = 0;
$outOfStockCount = 0;
foreach ($productRows as $row) {
    $stock = (int)($row['stock'] ?? 0);
    $totalStock += $stock;
    if ($stock <= 0) {
        $outOfStockCount++;
    } elseif ($stock <= 5) {
        $lowStockCount++;
    }
}
$completionRate = $total > 0 ? (int)round(($done / $total) * 100) : 0;

$perPage = 10;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($productCount / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$pagedProducts = array_slice($productRows, ($currentPage - 1) * $perPage, $perPage);

logActivity($db, $uid, 'view_dashboard', 'Inventory Custodian viewed dashboard');

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Inventory Dashboard',
    'active' => 'dashboard',
    'heading' => 'Inventory Dashboard',
    'subtitle' => 'Welcome, ' . ($_SESSION['name'] ?? 'Inventory'),
    'extra_head' => <<<'EXTRA'
<style>
.inv-dash { display: flex; flex-direction: column; gap: 24px; }

.inv-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    padding: 26px 30px;
    background: linear-gradient(135deg, var(--ep-green) 0%, var(--ep-green-dark) 100%);
    border-radius: 16px;
    color: #fff;
    box-shadow: 0 10px 32px rgba(75, 139, 42, 0.3);
    position: relative;
    overflow: hidden;
}
.inv-hero::after {
    content: '';
    position: absolute;
    right: -40px;
    top: -40px;
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.08);
    pointer-events: none;
}
.inv-hero-text { position: relative; z-index: 1; max-width: 560px; }
.inv-hero-kicker {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.4px;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.82);
    margin-bottom: 6px;
}
.inv-hero h1 {
    margin: 0 0 8px;
    font-size: 24px;
    font-weight: 800;
    line-height: 1.2;
}
.inv-hero p {
    margin: 0;
    font-size: 13.5px;
    line-height: 1.55;
    color: rgba(255, 255, 255, 0.9);
    font-weight: 500;
}

.inv-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
}
.inv-stat-card {
    background: var(--card-bg);
    border-radius: 14px;
    border: 1px solid var(--border);
    padding: 20px 22px;
    box-shadow: var(--card-shadow);
    position: relative;
    overflow: hidden;
    transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}
.inv-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 14px 32px rgba(0, 0, 0, 0.1);
    border-color: rgba(75, 139, 42, 0.25);
}
.inv-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--stat-accent, var(--ep-green));
}
.inv-stat-card.stat-deliveries { --stat-accent: var(--ep-green); }
.inv-stat-card.stat-pending { --stat-accent: var(--ep-yellow); }
.inv-stat-card.stat-completed { --stat-accent: #16a34a; }
.inv-stat-card.stat-products { --stat-accent: var(--ep-green-dark); }
.inv-stat-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}
.inv-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    background: var(--ep-green-light);
    color: var(--ep-green-dark);
    border: 1px solid var(--teal-light);
    flex-shrink: 0;
}
.inv-stat-card.stat-pending .inv-stat-icon {
    background: var(--yellow-pale);
    color: var(--ep-yellow-dark);
    border-color: #fde68a;
}
.inv-stat-card.stat-completed .inv-stat-icon {
    background: #f0fdf4;
    color: #16a34a;
    border-color: #bbf7d0;
}
.inv-stat-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.85px;
    color: var(--text-muted);
    margin-bottom: 4px;
}
.inv-stat-num {
    font-size: 32px;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1;
}
.inv-stat-foot {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 500;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--border);
}

.inv-overview {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}
.inv-overview-chip {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--card-shadow);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}
.inv-overview-chip:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
    border-color: rgba(75, 139, 42, 0.22);
}
.inv-overview-chip i {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    background: var(--ep-green-light);
    color: var(--ep-green-dark);
}
.inv-overview-chip.chip-warn i { background: var(--yellow-pale); color: #b45309; }
.inv-overview-chip.chip-danger i { background: #fef2f2; color: #dc2626; }
.inv-overview-chip strong {
    display: block;
    font-size: 18px;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1.1;
}
.inv-overview-chip span {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 500;
}

.inv-panel {
    background: var(--card-bg);
    border-radius: 16px;
    border: 1px solid var(--border);
    box-shadow: var(--card-shadow);
    overflow: hidden;
}
.inv-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    padding: 22px 26px;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(to bottom, #fff 0%, var(--slate-50) 100%);
}
.inv-panel-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 10px;
}
.inv-panel-header .card-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
}
.inv-panel-meta {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
    font-weight: 500;
}
.inv-panel-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.inv-panel-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 600;
    background: var(--ep-green-light);
    color: var(--ep-green-dark);
    border: 1px solid var(--teal-light);
}
.inv-panel-body { padding: 0; }

.inv-table-wrap { overflow-x: auto; }
.inv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}
.inv-table thead tr {
    background: var(--ep-green-light);
    border-bottom: 2px solid var(--teal-light);
}
.inv-table th {
    padding: 13px 20px;
    text-align: left;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--teal-deeper);
    white-space: nowrap;
}
.inv-table td {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.inv-table tbody tr:last-child td { border-bottom: none; }
.inv-table tbody tr {
    transition: background 0.2s ease, transform 0.2s ease;
}
.inv-table tbody tr:hover { background: rgba(238, 248, 230, 0.75); }

.inv-product-cell {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 200px;
}
.inv-thumb {
    width: 52px;
    height: 52px;
    object-fit: cover;
    border-radius: 10px;
    background: var(--ep-green-light);
    border: 1px solid var(--border);
    flex-shrink: 0;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.inv-table tbody tr:hover .inv-thumb {
    transform: scale(1.04);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
.inv-thumb-placeholder {
    width: 52px;
    height: 52px;
    border-radius: 10px;
    background: var(--ep-green-light);
    border: 1px dashed var(--teal-light);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ep-green-dark);
    font-size: 18px;
    flex-shrink: 0;
    opacity: 0.7;
}
.inv-product-name {
    font-weight: 700;
    color: var(--text-main);
    font-size: 14px;
    line-height: 1.3;
}
.inv-product-id {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 500;
    margin-top: 2px;
}
.category-pill {
    display: inline-flex;
    align-items: center;
    background: var(--ep-green-light);
    color: var(--ep-green-dark);
    padding: 5px 12px;
    border-radius: 100px;
    font-size: 11.5px;
    font-weight: 700;
    border: 1px solid var(--teal-light);
    white-space: nowrap;
}
.price-tag {
    color: var(--ep-green-dark);
    font-weight: 800;
    font-size: 14px;
    white-space: nowrap;
}
.stock-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 11px;
    border-radius: 100px;
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.inv-table tbody tr:hover .stock-badge {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}
.stock-badge.in-stock { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.stock-badge.low-stock { background: var(--yellow-pale); color: #b45309; border: 1px solid #fde68a; }
.stock-badge.out-stock { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

.inv-empty {
    text-align: center;
    padding: 56px 24px;
    color: var(--text-muted);
}
.inv-empty i {
    font-size: 40px;
    color: var(--teal-light);
    margin-bottom: 14px;
    display: block;
}
.inv-empty p {
    margin: 0 0 16px;
    font-size: 14px;
    font-weight: 500;
}

.inv-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    flex-wrap: wrap;
    padding: 20px 26px;
    border-top: 1px solid var(--border);
    background: var(--slate-50);
}
.inv-page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 12px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: #fff;
    color: var(--text-main);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
}
.inv-page-link:hover:not(.disabled):not(.active) {
    border-color: var(--ep-green);
    color: var(--ep-green-dark);
    background: var(--ep-green-light);
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(75, 139, 42, 0.15);
}
.inv-page-link.active {
    background: var(--ep-green);
    border-color: var(--ep-green);
    color: #fff;
    box-shadow: 0 4px 12px rgba(75, 139, 42, 0.28);
}
.inv-page-link.disabled {
    opacity: 0.45;
    pointer-events: none;
    cursor: default;
}
.inv-page-nav { font-size: 12.5px; gap: 6px; }
.inv-page-summary {
    width: 100%;
    text-align: center;
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 500;
    margin-top: 4px;
}

@media (max-width: 1100px) {
    .inv-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .inv-overview { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .inv-stats-grid { grid-template-columns: 1fr; }
    .inv-hero { padding: 20px; }
    .inv-hero h1 { font-size: 20px; }
    .inv-panel-header { padding: 18px; }
    .inv-table th, .inv-table td { padding: 12px 14px; }
}
</style>
EXTRA
]);
?>

        <div class="inv-dash">

            <section class="inv-hero">
                <div class="inv-hero-text">
                    <div class="inv-hero-kicker">Inventory Control Center</div>
                    <h1>Operations Overview</h1>
                    <p>Monitor deliveries, track stock levels, and manage your warehouse inventory from one centralized dashboard.</p>
                </div>
            </section>

            <div class="inv-stats-grid">
                <div class="inv-stat-card stat-deliveries">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Total Deliveries</div>
                            <div class="inv-stat-num"><?php echo $total; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-truck"></i></div>
                    </div>
                    <div class="inv-stat-foot">All shipment records</div>
                </div>
                <div class="inv-stat-card stat-pending">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Pending Shipments</div>
                            <div class="inv-stat-num"><?php echo $pending; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-clock"></i></div>
                    </div>
                    <div class="inv-stat-foot">Awaiting delivery</div>
                </div>
                <div class="inv-stat-card stat-completed">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Completed Deliveries</div>
                            <div class="inv-stat-num"><?php echo $done; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-check-circle"></i></div>
                    </div>
                    <div class="inv-stat-foot"><?php echo $completionRate; ?>% completion rate</div>
                </div>
                <div class="inv-stat-card stat-products">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Products in Catalog</div>
                            <div class="inv-stat-num"><?php echo $productCount; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-cubes"></i></div>
                    </div>
                    <div class="inv-stat-foot"><?php echo number_format($totalStock); ?> total units in stock</div>
                </div>
            </div>

            <div class="inv-overview">
                <div class="inv-overview-chip">
                    <i class="fas fa-warehouse"></i>
                    <div>
                        <strong><?php echo number_format($totalStock); ?></strong>
                        <span>Total stock units</span>
                    </div>
                </div>
                <div class="inv-overview-chip chip-warn">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong><?php echo $lowStockCount; ?></strong>
                        <span>Low stock items (≤ 5)</span>
                    </div>
                </div>
                <div class="inv-overview-chip chip-danger">
                    <i class="fas fa-times-circle"></i>
                    <div>
                        <strong><?php echo $outOfStockCount; ?></strong>
                        <span>Out of stock items</span>
                    </div>
                </div>
            </div>

            <section class="inv-panel">
                <div class="inv-panel-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-boxes"></i></span> Current Inventory</h3>
                        <div class="inv-panel-meta">Products currently in stock — sorted by most recent</div>
                    </div>
                    <div class="inv-panel-actions">
                        <span class="inv-panel-badge"><i class="fas fa-layer-group"></i> <?php echo $productCount; ?> product<?php echo $productCount === 1 ? '' : 's'; ?></span>
                    </div>
                </div>
                <div class="inv-panel-body">
                    <?php if (!empty($productRows)): ?>
                    <div class="inv-table-wrap">
                        <table class="inv-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Stock Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagedProducts as $p):
                                    $imgSrc = ias_product_image_url($p);
                                    $stock = (int)($p['stock'] ?? 0);
                                    if ($stock <= 0) {
                                        $stockClass = 'out-stock';
                                        $stockLabel = 'Out of Stock';
                                        $stockIcon = 'fa-times-circle';
                                    } elseif ($stock <= 5) {
                                        $stockClass = 'low-stock';
                                        $stockLabel = 'Low Stock';
                                        $stockIcon = 'fa-exclamation-triangle';
                                    } else {
                                        $stockClass = 'in-stock';
                                        $stockLabel = 'In Stock';
                                        $stockIcon = 'fa-check-circle';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="inv-product-cell">
                                            <?php if ($imgSrc !== ''): ?>
                                                <img src="<?php echo h($imgSrc); ?>" alt="" class="inv-thumb">
                                            <?php else: ?>
                                                <div class="inv-thumb-placeholder" aria-hidden="true"><i class="fas fa-image"></i></div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="inv-product-name"><?php echo h($p['name']); ?></div>
                                                <div class="inv-product-id">ID #<?php echo (int)$p['id']; ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="category-pill"><?php echo h($p['category'] ?? 'Accessories'); ?></span></td>
                                    <td class="price-tag">₱<?php echo number_format((float)$p['price'], 2); ?></td>
                                    <td>
                                        <span class="stock-badge <?php echo $stockClass; ?>">
                                            <i class="fas <?php echo $stockIcon; ?>"></i>
                                            <?php echo $stockLabel; ?> (<?php echo $stock; ?>)
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1): ?>
                    <nav class="inv-pagination" aria-label="Product pagination">
                        <?php
                        $pageBase = 'inventory_dashboard.php';
                        $prevPage = max(1, $currentPage - 1);
                        $nextPage = min($totalPages, $currentPage + 1);
                        ?>
                        <a href="<?php echo h($pageBase . '?page=' . $prevPage); ?>"
                           class="inv-page-link inv-page-nav<?php echo $currentPage <= 1 ? ' disabled' : ''; ?>"
                           aria-disabled="<?php echo $currentPage <= 1 ? 'true' : 'false'; ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <a href="<?php echo h($pageBase . '?page=' . $p); ?>"
                               class="inv-page-link<?php echo $p === $currentPage ? ' active' : ''; ?>"
                               <?php echo $p === $currentPage ? 'aria-current="page"' : ''; ?>><?php echo $p; ?></a>
                        <?php endfor; ?>
                        <a href="<?php echo h($pageBase . '?page=' . $nextPage); ?>"
                           class="inv-page-link inv-page-nav<?php echo $currentPage >= $totalPages ? ' disabled' : ''; ?>"
                           aria-disabled="<?php echo $currentPage >= $totalPages ? 'true' : 'false'; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                        <div class="inv-page-summary">
                            Showing <?php echo $productCount === 0 ? 0 : (($currentPage - 1) * $perPage + 1); ?>–<?php echo min($currentPage * $perPage, $productCount); ?> of <?php echo $productCount; ?> products
                        </div>
                    </nav>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="inv-empty">
                        <i class="fas fa-box-open"></i>
                        <p>No products in inventory yet.</p>
                        <a href="inventory_stocks.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add Products</a>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

        </div>

<?php staff_page_end(); ?>
