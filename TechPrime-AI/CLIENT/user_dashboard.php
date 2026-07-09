<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('client');

$user_id = (int)$_SESSION['user_id'];
$rawName = trim((string)($_SESSION['name'] ?? 'Customer'));
$safeName = h($rawName !== '' ? $rawName : 'Customer');
$initial = strtoupper(substr($rawName !== '' ? $rawName : 'C', 0, 1));

$search_query = trim($_GET['q'] ?? '');
$current_filter = ias_normalize_order_status_filter($_GET['status'] ?? 'All');
$allowed_filters = ['All', 'to_pay', 'to_ship', 'to_receive', 'to_review'];
if (!in_array($current_filter, $allowed_filters, true)) {
    $current_filter = 'All';
}

$legacyStatus = [
    'to_pay' => 'To Pay',
    'to_ship' => 'To Ship',
    'to_receive' => 'To Receive',
    'to_review' => 'To Review',
];

$sql = "SELECT o.*,
               (SELECT s.shipment_status FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) AS shipment_status,
               (SELECT s.carrier FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) AS carrier
        FROM orders o
        WHERE o.user_id = ?";
$params = [$user_id];
$types = 'i';

if ($current_filter !== 'All') {
    $sql .= ' AND (o.status = ? OR o.status = ?)';
    $params[] = $current_filter;
    $params[] = $legacyStatus[$current_filter] ?? $current_filter;
    $types .= 'ss';
}

if ($search_query !== '') {
    $like = '%' . $search_query . '%';
    $sql .= " AND (
        CAST(o.id AS CHAR) LIKE ?
        OR CAST(o.total AS CHAR) LIKE ?
        OR o.status LIKE ?
        OR o.shipping_address LIKE ?
        OR o.customer_phone LIKE ?
        OR DATE_FORMAT(o.created_at, '%M %d, %Y') LIKE ?
    )";
    array_push($params, $like, $like, $like, $like, $like, $like);
    $types .= 'ssssss';
}

$sql .= ' ORDER BY o.id DESC';
$stOrders = $db->prepare($sql);
$stOrders->bind_param($types, ...$params);
$stOrders->execute();
$orders = $stOrders->get_result();

$recentResult = $db->query(
    "SELECT p.*, u.name AS seller_name FROM products p
     INNER JOIN users u ON p.seller_id = u.id
     WHERE " . ias_client_product_list_sql_condition('p') . "
     ORDER BY p.id DESC LIMIT 20"
);
$recentProducts = ias_client_filter_products_for_display(
    $recentResult ? $recentResult->fetch_all(MYSQLI_ASSOC) : [],
    4
);

$isLoggedIn = true;
$activePage = 'account';
$pageTitle = 'My Account';
$searchQuery = '';

$statusTitles = [
    'All' => 'Transaction History',
    'to_pay' => 'To Pay',
    'to_ship' => 'To Ship',
    'to_receive' => 'To Receive',
    'to_review' => 'To Review',
];
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="dashboard-wrapper">
        <section class="profile-banner">
            <div class="user-meta">
                <div class="avatar-circle"><?php echo h($initial); ?></div>
                <div>
                    <h2 class="dashboard-name"><?php echo $safeName; ?></h2>
                    <p class="user-status">Verified Member</p>
                </div>
            </div>
            <a href="../logout.php" class="logout-btn">Log Out Account</a>
        </section>

        <div class="dash-grid">
            <div class="panel">
                <div class="panel-header dashboard-panel-header">
                    <h3><i class="fas fa-box"></i> <?php echo h($statusTitles[$current_filter] ?? 'Orders'); ?></h3>
                    <form action="user_dashboard.php" method="GET" class="search-form dashboard-order-search">
                        <?php if ($current_filter !== 'All'): ?>
                            <input type="hidden" name="status" value="<?php echo h($current_filter); ?>">
                        <?php endif; ?>
                        <input type="text" name="q" placeholder="Search your past orders..." value="<?php echo h($search_query); ?>">
                        <button type="submit" class="search-icon" aria-label="Search orders"><i class="fas fa-search"></i></button>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="order-list">
                        <thead>
                            <tr><th>ID</th><th>Date</th><th>Total</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php while ($o = $orders->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#ORD-<?php echo (int)$o['id']; ?></strong></td>
                                <td><span class="meta-text"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></span></td>
                                <td><b class="dash-price">&#8369;<?php echo number_format((float)$o['total'], 2); ?></b></td>
                                <td><span class="status-tag"><?php echo h(ias_order_display_status($o['status'] ?? '', $o['shipment_status'] ?? null)); ?></span></td>
                            </tr>
                        <?php endwhile; ?>

                        <?php if ($orders->num_rows === 0): ?>
                            <tr><td colspan="4" class="empty-state">No transactions found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header"><h3><i class="fas fa-search"></i> Recently Added</h3></div>
                <div class="recent-grid">
                    <?php foreach ($recentProducts as $rp): ?>
                        <a class="mini-card" href="products.php?id=<?php echo (int)$rp['id']; ?>">
                            <img src="<?php echo h(ias_client_product_image_url($rp)); ?>" alt="<?php echo h($rp['name']); ?>">
                            <div class="mini-card-body">
                                <span class="mini-card-title"><?php echo h($rp['name']); ?></span>
                                <span class="p-price">&#8369;<?php echo number_format((float)$rp['price'], 2); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($recentProducts)): ?>
                        <div class="empty-state">No recent products yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="status-bar">
            <a href="user_dashboard.php?status=All" class="tab-item <?php echo $current_filter === 'All' ? 'active' : ''; ?>"><i class="fas fa-list"></i> All History</a>
            <a href="user_dashboard.php?status=to_pay" class="tab-item <?php echo $current_filter === 'to_pay' ? 'active' : ''; ?>"><i class="fas fa-credit-card"></i> To Pay</a>
            <a href="user_dashboard.php?status=to_ship" class="tab-item <?php echo $current_filter === 'to_ship' ? 'active' : ''; ?>"><i class="fas fa-truck"></i> To Ship</a>
            <a href="user_dashboard.php?status=to_receive" class="tab-item <?php echo $current_filter === 'to_receive' ? 'active' : ''; ?>"><i class="fas fa-inbox"></i> To Receive</a>
            <a href="user_dashboard.php?status=to_review" class="tab-item <?php echo $current_filter === 'to_review' ? 'active' : ''; ?>"><i class="fas fa-star"></i> To Review</a>
        </div>
    </div>
</main>

<?php include __DIR__ . '/ep_footer.php'; ?>
