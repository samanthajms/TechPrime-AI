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

logActivity($db, $uid, 'view_dashboard', 'Inventory Custodian viewed dashboard');

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Inventory Dashboard',
    'active' => 'dashboard',
    'heading' => 'Inventory Dashboard',
    'subtitle' => 'Welcome, ' . ($_SESSION['name'] ?? 'Inventory'),
    'extra_head' => <<<'EXTRA'
<style>
.thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; background: var(--ep-green-light); }
.category-pill {
    background: var(--ep-green-light); color: var(--ep-green-dark);
    padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700;
}
.price-tag { color: var(--ep-green-dark); font-weight: 800; }
</style>
EXTRA
]);
?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Deliveries</div>
                <div class="stat-num"><?php echo $total; ?></div>
                <div class="stat-icon"><i class="fas fa-truck"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Shipments</div>
                <div class="stat-num"><?php echo $pending; ?></div>
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Completed Deliveries</div>
                <div class="stat-num"><?php echo $done; ?></div>
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-boxes"></i></span> Current Inventory</h3>
                    <div class="card-subtitle">Products currently in stock</div>
                </div>
                <a href="inventory_stocks.php" class="btn btn-outline btn-sm">Manage Stocks</a>
            </div>
            <div class="card-body" style="padding-top:0;">
                <div class="table-wrap">
                    <table class="ias-table">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($products && $products->num_rows > 0): ?>
                                <?php while ($p = $products->fetch_assoc()):
                                    $imgSrc = ias_product_image_url($p);
                                ?>
                                <tr>
                                    <td><?php if ($imgSrc !== ''): ?><img src="<?php echo h($imgSrc); ?>" alt="" class="thumb"><?php endif; ?></td>
                                    <td><strong><?php echo h($p['name']); ?></strong></td>
                                    <td><span class="category-pill"><?php echo h($p['category'] ?? 'Accessories'); ?></span></td>
                                    <td class="price-tag">₱<?php echo number_format((float)$p['price'], 2); ?></td>
                                    <td><?php echo (int)$p['stock']; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="empty-state">No products yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php staff_page_end(); ?>
