<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('client');

$user_id = (int)$_SESSION['user_id'];
// FIX: $name already escaped with htmlspecialchars — keep as is
$name = htmlspecialchars($_SESSION['name']);

$search_query = trim($_GET['q'] ?? '');
$current_filter = ias_normalize_order_status_filter($_GET['status'] ?? 'All');
$allowed_filters = ['All', 'to_pay', 'to_ship', 'to_receive', 'to_review', 'Order Details'];
if (!in_array($current_filter, $allowed_filters, true)) {
    $current_filter = 'All';
}

$sql = "SELECT o.*, s.shipment_status, s.carrier
        FROM orders o
        LEFT JOIN shipments s ON s.order_id = o.id
        WHERE o.user_id = ?";
$params = [$user_id];
$types = 'i';
if ($current_filter !== 'All' && $current_filter !== 'Order Details') {
    $sql .= ' AND o.status = ?';
    $params[] = $current_filter;
    $types .= 's';
}
$sql .= ' ORDER BY o.id DESC';
$stOrders = $db->prepare($sql);
$stOrders->bind_param($types, ...$params);
$stOrders->execute();
$orders = $stOrders->get_result();

// 3. FETCH RECENT PRODUCTS
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - IAS</title>
    <link rel="stylesheet" href="../styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body>

    <header class="top-header full-width">
        <a href="index.php" class="logo">IAS</a>
        <div class="search-wrap">
            <form action="user_dashboard.php" method="GET" class="search-form">
                <?php if ($current_filter !== 'All'): ?>
                    <input type="hidden" name="status" value="<?php echo h($current_filter); ?>">
                <?php endif; ?>
                <input type="text" name="q" placeholder="Search your past orders..." value="<?php echo h($search_query); ?>">
                <button type="submit" class="search-icon">⌕</button>
            </form>
        </div>
        <div class="header-icons">
            <button class="icon-btn" onclick="location.href='index.php'">🏠</button>
            <button class="icon-btn" onclick="location.href='cart.php'">🛒</button>
        </div>
    </header>

    <div class="dashboard-wrapper">
        <section class="profile-banner">
            <div class="user-meta">
                <!-- FIX: $name is already htmlspecialchars'd above, safe to use -->
                <div class="avatar-circle"><?php echo substr($name, 0, 1); ?></div>
                <div>
                    <h2 class="dashboard-name"><?php echo $name; ?></h2>
                    <p class="user-status">Verified Member</p>
                </div>
            </div>
            <a href="../logout.php" class="logout-btn">Log Out Account</a>
        </section>

        <div class="dash-grid">
            <div class="panel">
                <div class="panel-header">
                    <h3>📦 <?php
                        $titles = ['All'=>'Transaction History','to_pay'=>'To Pay','to_ship'=>'To Ship','to_receive'=>'To Receive','to_review'=>'To Review'];
                        echo ($current_filter === 'Order Details' || $current_filter === 'All')
                            ? 'Transaction History' : ($titles[$current_filter] ?? 'Orders');
                    ?></h3>
                </div>

                <div class="table-responsive">
                    <table class="order-list">
                        <thead>
                            <tr><th>ID</th><th>Date</th><th>Total</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php while($o = $orders->fetch_assoc()): ?>
                        <tr>
                            <td><strong>#ORD-<?php echo $o['id']; ?></strong></td>
                            <td><span style="color:#888;"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></span></td>
                            <td><b style="color: #1a1a1a;">₱<?php echo number_format($o['total'], 2); ?></b></td>
                            <td><span class="status-tag"><?php echo h(ias_order_display_status($o['status'] ?? '', $o['shipment_status'] ?? null)); ?></span></td>
                        </tr>
                        <?php endwhile; ?>

                        <?php if($orders->num_rows == 0): ?>
                        <tr><td colspan="4" class="empty-state">No transactions found in this category.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header"><h3>🔍 Recently Added</h3></div>
                <div class="recent-grid">
                    <?php foreach ($recentProducts as $rp): ?>
                    <div class="mini-card">
                        <img src="<?php echo h(ias_client_product_image_url($rp)); ?>" alt="">
                        <div class="mini-card-body">
                            <span class="mini-card-title"><?php echo htmlspecialchars($rp['name']); ?></span>
                            <span class="p-price">₱<?php echo number_format($rp['price'], 2); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="status-bar">
            <a href="user_dashboard.php?status=All" class="tab-item <?php echo $current_filter === 'All' ? 'active' : ''; ?>">📋 All History</a>
            <a href="user_dashboard.php?status=to_pay" class="tab-item <?php echo $current_filter === 'to_pay' ? 'active' : ''; ?>">💳 To Pay</a>
            <a href="user_dashboard.php?status=to_ship" class="tab-item <?php echo $current_filter === 'to_ship' ? 'active' : ''; ?>">🚚 To Ship</a>
            <a href="user_dashboard.php?status=to_receive" class="tab-item <?php echo $current_filter === 'to_receive' ? 'active' : ''; ?>">📬 To Receive</a>
            <a href="user_dashboard.php?status=to_review" class="tab-item <?php echo $current_filter === 'to_review' ? 'active' : ''; ?>">⭐ To Review</a>
        </div>
    </div>

    <footer class="site-footer">© 2026 IAS Marketplace Client Portal</footer>

<?php ias_alert_footer(); ?>
</body>
</html>
