<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('client');

$user_id = (int)$_SESSION['user_id'];
$rawName = trim((string)($_SESSION['name'] ?? 'Customer'));
$safeName = h($rawName !== '' ? $rawName : 'Customer');
$initial = strtoupper(substr($rawName !== '' ? $rawName : 'C', 0, 1));

ep_profile_image_ensure_schema($db);
ep_ensure_session_cart($db);

$profileFlash = '';
$profileFlashErr = '';

/* ---- Profile picture upload ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_avatar') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    if (empty($_FILES['avatar']) || !is_uploaded_file($_FILES['avatar']['tmp_name'])) {
        $profileFlashErr = 'Please choose an image file.';
    } else {
        $file = $_FILES['avatar'];
        $maxBytes = 2 * 1024 * 1024; // 2 MB
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $profileFlashErr = 'Upload failed. Please try again.';
        } elseif ((int)$file['size'] > $maxBytes) {
            $profileFlashErr = 'Image must be 2 MB or smaller.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']) ?: '';
            if (!isset($allowed[$mime])) {
                $profileFlashErr = 'Only JPG, PNG, WEBP, or GIF images are allowed.';
            } else {
                $dir = dirname(__DIR__) . '/assets/profiles';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $ext = $allowed[$mime];
                $rel = 'profiles/u' . $user_id . '.' . $ext;
                $dest = dirname(__DIR__) . '/assets/' . $rel;

                // Remove previous extensions for this user
                foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $oldExt) {
                    $old = dirname(__DIR__) . '/assets/profiles/u' . $user_id . '.' . $oldExt;
                    if (is_file($old) && $old !== $dest) {
                        @unlink($old);
                    }
                }

                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    $profileFlashErr = 'Could not save the image.';
                } else {
                    $up = $db->prepare('UPDATE users SET profile_image = ? WHERE id = ?');
                    $up->execute([$rel, $user_id]);
                    $profileFlash = 'Profile picture updated.';
                }
            }
        }
    }
}

/* ---- Cancel order ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $oid = (int)($_POST['order_id'] ?? 0);
    $result = ep_cancel_order($db, $user_id, $oid);
    $redir = 'user_dashboard.php?';
    if (!empty($result['ok'])) {
        $redir .= 'alert=cancelled';
    } elseif (($result['error'] ?? '') === 'already_cancelled') {
        $redir .= 'alert=already_cancelled';
    } elseif (($result['error'] ?? '') === 'not_cancellable') {
        $redir .= 'alert=not_cancellable';
    } else {
        $redir .= 'alert=error';
    }
    header('Location: ' . $redir);
    exit;
}

$uStmt = $db->prepare('SELECT profile_image FROM users WHERE id = ? LIMIT 1');
$uStmt->execute([$user_id]);
$uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$profileImageUrl = ep_user_profile_image_url($uRow['profile_image'] ?? null);

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

if ($current_filter !== 'All') {
    $sql .= ' AND (o.status = ? OR o.status = ?)';
    $params[] = $current_filter;
    $params[] = $legacyStatus[$current_filter] ?? $current_filter;
}

if ($search_query !== '') {
    $like = '%' . $search_query . '%';
    $sql .= " AND (
        CAST(o.id AS TEXT) LIKE ?
        OR CAST(o.total AS TEXT) LIKE ?
        OR o.status LIKE ?
        OR o.shipping_address LIKE ?
        OR o.customer_phone LIKE ?
        OR to_char(o.created_at, 'Month DD, YYYY') LIKE ?
    )";
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$sql .= ' ORDER BY o.id DESC';
$stOrders = $db->prepare($sql);
$stOrders->execute($params);
$orders = $stOrders->fetchAll(PDO::FETCH_ASSOC);
$recentResult = $db->query(
    "SELECT p.*, u.name AS seller_name FROM products p
     INNER JOIN users u ON p.seller_id = u.id
     WHERE " . ias_client_product_list_sql_condition('p') . "
     ORDER BY p.id DESC LIMIT 20"
);
$recentProducts = ias_client_filter_products_for_display(
    $recentResult ? $recentResult->fetchAll(PDO::FETCH_ASSOC) : [],
    4
);

$cancellableStatuses = ['to_pay', 'to_ship', 'To Pay', 'To Ship', 'Pending', 'pending'];

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

$alertMsg = '';
if (!empty($_GET['alert'])) {
    $alertMap = [
        'cancelled' => 'Order cancelled. Stock has been restored.',
        'already_cancelled' => 'This order was already cancelled.',
        'not_cancellable' => 'This order can no longer be cancelled.',
        'error' => 'Could not complete the action. Please try again.',
    ];
    $alertMsg = $alertMap[$_GET['alert']] ?? '';
}
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="dashboard-wrapper">
        <?php if ($profileFlash !== ''): ?>
            <div class="ep-info-note" style="margin-bottom:16px;"><?php echo h($profileFlash); ?></div>
        <?php endif; ?>
        <?php if ($profileFlashErr !== ''): ?>
            <div class="ep-info-note" style="margin-bottom:16px;color:#c0392b;border-color:#c0392b;"><?php echo h($profileFlashErr); ?></div>
        <?php endif; ?>
        <?php if ($alertMsg !== ''): ?>
            <div class="ep-info-note" style="margin-bottom:16px;"><?php echo h($alertMsg); ?></div>
        <?php endif; ?>

        <section class="profile-banner">
            <div class="user-meta">
                <div class="avatar-wrap">
                    <?php if ($profileImageUrl !== ''): ?>
                        <img class="avatar-circle avatar-img" src="<?php echo h($profileImageUrl); ?>" alt="Profile picture">
                    <?php else: ?>
                        <div class="avatar-circle"><?php echo h($initial); ?></div>
                    <?php endif; ?>
                    <form method="post" enctype="multipart/form-data" class="avatar-upload-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="upload_avatar">
                        <label class="avatar-upload-btn" for="avatarInput">
                            <i class="fas fa-camera" aria-hidden="true"></i>
                            <span>Change photo</span>
                        </label>
                        <input id="avatarInput" type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" hidden
                               onchange="this.form.submit()">
                    </form>
                </div>
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
                            <tr><th>ID</th><th>Date</th><th>Total</th><th>Status</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orders as $o):
                            $ost = (string)($o['status'] ?? '');
                            $canCancel = in_array($ost, $cancellableStatuses, true);
                            ?>
                            <tr>
                                <td><strong>#ORD-<?php echo (int)$o['id']; ?></strong></td>
                                <td><span class="meta-text"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></span></td>
                                <td><b class="dash-price">&#8369;<?php echo number_format((float)$o['total'], 2); ?></b></td>
                                <td><span class="status-tag"><?php echo h(ias_order_display_status($o['status'] ?? '', $o['shipment_status'] ?? null)); ?></span></td>
                                <td>
                                    <?php if ($canCancel): ?>
                                        <form method="post" class="order-cancel-form" onsubmit="return confirm('Cancel this order? Stock will be restored.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="cancel_order">
                                            <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                                            <button type="submit" class="order-cancel-btn">Cancel</button>
                                        </form>
                                    <?php elseif (strcasecmp($ost, 'cancelled') === 0 || strcasecmp($ost, 'canceled') === 0): ?>
                                        <span class="meta-text">Cancelled</span>
                                    <?php else: ?>
                                        <span class="meta-text">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (count($orders) === 0): ?>
                            <tr><td colspan="5" class="empty-state">No transactions found.</td></tr>
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
                            <div class="mini-card-media">
                                <span class="mini-card-badge">New</span>
                                <img src="<?php echo h(ias_client_product_image_url($rp)); ?>" alt="<?php echo h($rp['name']); ?>">
                            </div>
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
