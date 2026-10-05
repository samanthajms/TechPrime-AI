<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';
require_once __DIR__ . '/../includes/address_helpers.php';

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

/* ---- Profile settings ---- */
$hasPhone = ep_users_has_phone($db);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $name = trim((string)($_POST['name'] ?? ''));
    $surname = trim((string)($_POST['surname'] ?? ''));
    $age = (int)($_POST['age'] ?? 0);
    $addressInput = ep_address_from_input($_POST);
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone = trim((string)($_POST['phone'] ?? ''));

    if ($name === '' || $surname === '') {
        $profileFlashErr = 'First name and surname are required.';
    } elseif (mb_strlen($name) > 80 || mb_strlen($surname) > 80) {
        $profileFlashErr = 'Name fields must be 80 characters or fewer.';
    } elseif ($age < 13 || $age > 120) {
        $profileFlashErr = 'Age must be between 13 and 120.';
    } elseif ($addressInput['error'] !== '') {
        $profileFlashErr = $addressInput['error'];
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $profileFlashErr = 'Please enter a valid email address.';
    } elseif (mb_strlen($email) > 190) {
        $profileFlashErr = 'Email is too long.';
    } else {
        if ($hasPhone) {
            if ($phone === '') {
                $profileFlashErr = 'Phone number is required.';
            } else {
                $phoneDigits = preg_replace('/\D+/', '', $phone);
                if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
                    $profileFlashErr = 'Please enter a valid phone number.';
                } elseif (strlen($phone) > 30) {
                    $profileFlashErr = 'Phone number is too long.';
                }
            }
        }
        if ($profileFlashErr === '') {
            $dup = $db->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1');
            $dup->execute([$email, $user_id]);
            if ($dup->fetch(PDO::FETCH_ASSOC)) {
                $profileFlashErr = 'That email is already in use.';
            }
        }
        if ($profileFlashErr === '') {
            // users.address gets the formatted one-line address; address_* columns and
            // phone are included when migration_users_address_phone.sql has been applied.
            $set = ['name' => $name, 'surname' => $surname, 'age' => $age]
                + ep_address_columns($db, $addressInput['fields']);
            if ($hasPhone) {
                $set['phone'] = $phone;
            }
            $set['email'] = $email;
            $up = $db->prepare(
                'UPDATE users SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?'
            );
            $up->execute([...array_values($set), $user_id]);
            $_SESSION['name'] = $name;
            $_SESSION['surname'] = $surname;
            $_SESSION['email'] = $email;
            logActivity($db, $user_id, 'update_profile', 'Client updated profile settings');
            header('Location: user_dashboard.php?settings=1&alert=profile_saved');
            exit;
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

$profileCols = 'name, surname, age, address, email, profile_image, created_at, is_verified';
if (!isset($hasPhone)) {
    $hasPhone = ep_users_has_phone($db);
}
if ($hasPhone) {
    $profileCols .= ', phone';
}
$uStmt = $db->prepare('SELECT ' . $profileCols . ' FROM users WHERE id = ? LIMIT 1');
$uStmt->execute([$user_id]);
$uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$profileImageUrl = ep_user_profile_image_url($uRow['profile_image'] ?? null);

$profileForm = [
    'name' => (string)($uRow['name'] ?? ''),
    'surname' => (string)($uRow['surname'] ?? ''),
    'age' => (string)($uRow['age'] ?? ''),
    'email' => (string)($uRow['email'] ?? ''),
    'phone' => (string)($uRow['phone'] ?? ''),
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile' && $profileFlashErr !== '') {
    $profileForm['name'] = trim((string)($_POST['name'] ?? $profileForm['name']));
    $profileForm['surname'] = trim((string)($_POST['surname'] ?? $profileForm['surname']));
    $profileForm['age'] = (string)($_POST['age'] ?? $profileForm['age']);
    $profileForm['email'] = trim((string)($_POST['email'] ?? $profileForm['email']));
    $profileForm['phone'] = trim((string)($_POST['phone'] ?? $profileForm['phone']));
}

// Saved delivery address (structured). Legacy accounts may only have free-text users.address.
$savedAddress = ep_user_address($db, $user_id);
$addressComplete = ep_address_is_complete($savedAddress);
$savedAddressLine = $addressComplete ? ep_address_format($savedAddress) : $savedAddress['full'];
$profileAddr = $savedAddress;
if (isset($addressInput) && $profileFlashErr !== '') {
    $profileAddr = $addressInput['fields'] + $profileAddr;
}

$rawName = trim((string)($_SESSION['name'] ?? ($profileForm['name'] !== '' ? $profileForm['name'] : 'Customer')));
$safeName = h($rawName !== '' ? $rawName : 'Customer');
$initial = strtoupper(substr($rawName !== '' ? $rawName : 'C', 0, 1));
$fullName = trim((string)($uRow['name'] ?? '') . ' ' . (string)($uRow['surname'] ?? ''));
$displayName = $fullName !== '' ? $fullName : ($rawName !== '' ? $rawName : 'Customer');
$memberSince = !empty($uRow['created_at']) ? date('F Y', strtotime($uRow['created_at'] . ' UTC')) : '';
$isVerified = !empty($uRow['is_verified']);
if ($profileImageUrl !== '') {
    // The file name is reused on re-upload; the version makes a new photo show right away.
    $profileImageUrl .= '?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/' . $uRow['profile_image']);
}

/** Colour group for an order status pill. */
$orderStatusTone = static function (string $label): string {
    $l = strtolower($label);
    if ($l === 'cancelled') {
        return 'is-cancelled';
    }
    if ($l === 'to pay') {
        return 'is-pay';
    }
    if ($l === 'delivered' || $l === 'completed') {
        return 'is-done';
    }
    return 'is-progress';
};

/** One-line progress note shown at the bottom of an order card: [icon, text]. */
$orderStatusNote = static function (string $label, string $carrier, bool $isPickup): array {
    $carriers = ['JNT' => 'J&T Express', 'NINJAVAN' => 'Ninja Van', 'LBC' => 'LBC', 'GRAB' => 'Grab', 'LALAMOVE' => 'Lalamove'];
    $carrierName = $carriers[strtoupper($carrier)] ?? $carrier;
    switch (strtolower($label)) {
        case 'cancelled':
            return ['fa-times-circle', 'This order was cancelled'];
        case 'to pay':
            return ['fa-wallet', 'Waiting for payment'];
        case 'delivered':
        case 'completed':
            if ($isPickup) {
                return ['fa-check-circle', 'Picked up at the store'];
            }
            return ['fa-check-circle', $carrierName !== '' ? 'Delivered by ' . $carrierName : 'Order completed'];
        case 'with courier':
        case 'shipped':
        case 'out for delivery':
            if ($isPickup) {
                return ['fa-store', 'Ready for pickup at the store'];
            }
            return ['fa-truck', $carrierName !== '' ? 'On the way with ' . $carrierName : 'On the way to you'];
        default:
            return ['fa-box', $isPickup ? 'EasyPC is preparing your order for pickup' : 'EasyPC is preparing your order'];
    }
};

/** Labels for the optional payment / fulfillment columns (empty when not recorded). */
$paymentLabel = static function (array $o): string {
    $methods = ['cod' => 'Cash on delivery', 'paymongo' => 'Online payment', 'gcash' => 'GCash', 'card' => 'Card'];
    $m = strtolower(trim((string)($o['payment_method'] ?? '')));
    $label = $m === '' ? '' : ($methods[$m] ?? ucfirst($m));
    $st = strtolower(trim((string)($o['payment_status'] ?? '')));
    if ($st !== '') {
        $label .= ($label !== '' ? ' (' . ucfirst($st) . ')' : ucfirst($st));
    }
    return $label;
};
$fulfillmentLabels = ['pickup' => 'Store pickup', 'delivery' => 'Delivery'];
$orderItemsShown = 3;   // more items collapse behind "Show N more"

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

// Items of the listed orders, in one query (product may since have been removed: LEFT JOIN).
$orderItems = [];
if ($orders) {
    $orderIds = array_map(static fn($o) => (int)$o['id'], $orders);
    $stItems = $db->prepare(
        'SELECT oi.order_id, oi.product_id, oi.quantity, oi.price, p.name, p.image
         FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')
         ORDER BY oi.order_id, oi.id'
    );
    $stItems->execute($orderIds);
    foreach ($stItems->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $orderItems[(int)$it['order_id']][] = $it;
    }
}

// Order count per status tab (legacy labels like "To Pay" count toward their tab).
$statusCounts = array_fill_keys($allowed_filters, 0);
$stCounts = $db->prepare('SELECT status, COUNT(*) AS n FROM orders WHERE user_id = ? GROUP BY status');
$stCounts->execute([$user_id]);
foreach ($stCounts->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
    $statusCounts['All'] += (int)$cRow['n'];
    $cKey = ias_normalize_order_status_filter((string)$cRow['status']);
    if ($cKey !== 'All' && isset($statusCounts[$cKey])) {
        $statusCounts[$cKey] += (int)$cRow['n'];
    }
}

$savedBuildCount = 0;
try {
    $stBuilds = $db->prepare('SELECT COUNT(*) FROM saved_builds WHERE user_id = ?');
    $stBuilds->execute([$user_id]);
    $savedBuildCount = (int)$stBuilds->fetchColumn();
} catch (Throwable $e) {
    $savedBuildCount = 0;   // saved_builds is created on first use by saved_builds_api.php
}
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
$bodyClass = 'pf-body';
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
        'profile_saved' => 'Profile settings saved.',
    ];
    $alertMsg = $alertMap[$_GET['alert']] ?? '';
}

$showSettings = isset($_GET['settings'])
    || (isset($_POST['keep_settings']) && (string)$_POST['keep_settings'] === '1')
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile');
if ($showSettings) {
    $pageTitle = 'Profile Settings';
}
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="dashboard-wrapper pf-page">
        <?php if ($profileFlash !== ''): ?>
            <div class="ep-info-note" style="margin-bottom:16px;"><?php echo h($profileFlash); ?></div>
        <?php endif; ?>
        <?php if ($profileFlashErr !== ''): ?>
            <div class="ep-info-note" style="margin-bottom:16px;color:#c0392b;border-color:#c0392b;"><?php echo h($profileFlashErr); ?></div>
        <?php endif; ?>
        <?php if ($alertMsg !== ''): ?>
            <div class="ep-info-note" style="margin-bottom:16px;"><?php echo h($alertMsg); ?></div>
        <?php endif; ?>

        <section class="pf-hero">
            <div class="pf-avatar">
                <?php if ($profileImageUrl !== ''): ?>
                    <img class="pf-avatar-img" src="<?php echo h($profileImageUrl); ?>" alt="Profile picture">
                <?php else: ?>
                    <span class="pf-avatar-initial" aria-hidden="true"><?php echo h($initial); ?></span>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="pf-avatar-form">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="upload_avatar">
                    <?php if (!empty($showSettings)): ?>
                        <input type="hidden" name="keep_settings" value="1">
                    <?php endif; ?>
                    <label class="pf-avatar-btn" for="avatarInput" title="Change photo">
                        <i class="fas fa-camera" aria-hidden="true"></i>
                        <span class="sr-only">Change photo</span>
                    </label>
                    <input id="avatarInput" class="pf-avatar-input" type="file" name="avatar"
                           accept="image/jpeg,image/png,image/webp,image/gif"
                           onchange="this.form.submit()">
                </form>
            </div>

            <div class="pf-identity">
                <h1 class="pf-name">
                    <?php echo h($displayName); ?>
                    <?php if ($isVerified): ?>
                        <i class="fas fa-check-circle pf-verified" title="Verified account" aria-hidden="true"></i>
                        <span class="sr-only">(verified account)</span>
                    <?php endif; ?>
                </h1>
                <ul class="pf-meta">
                    <?php if ($profileForm['email'] !== ''): ?>
                        <li><i class="far fa-envelope" aria-hidden="true"></i> <span class="pf-meta-email"><?php echo h($profileForm['email']); ?></span></li>
                    <?php endif; ?>
                    <?php if ($memberSince !== ''): ?>
                        <li><i class="far fa-calendar" aria-hidden="true"></i> Member since <?php echo h($memberSince); ?></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="pf-hero-actions">
                <a href="user_dashboard.php?settings=1"
                   class="pf-btn pf-btn-primary<?php echo $showSettings ? ' is-active' : ''; ?>"
                   <?php echo $showSettings ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-user-cog" aria-hidden="true"></i> Profile settings
                </a>
                <a href="../logout.php" class="pf-btn pf-btn-quiet">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i> Log out
                </a>
            </div>
        </section>

        <?php if ($showSettings): ?>
        <section class="panel profile-settings-panel">
            <div class="panel-header">
                <h3><i class="fas fa-user-cog"></i> Profile Settings</h3>
            </div>
            <form method="post" class="profile-settings-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="update_profile">
                <div class="profile-settings-grid">
                    <div>
                        <label class="ep-form-label" for="profileName">First name</label>
                        <input class="ep-form-control" id="profileName" type="text" name="name" maxlength="80" required
                               value="<?php echo h($profileForm['name']); ?>">
                    </div>
                    <div>
                        <label class="ep-form-label" for="profileSurname">Surname</label>
                        <input class="ep-form-control" id="profileSurname" type="text" name="surname" maxlength="80" required
                               value="<?php echo h($profileForm['surname']); ?>">
                    </div>
                    <div>
                        <label class="ep-form-label" for="profileAge">Age</label>
                        <input class="ep-form-control" id="profileAge" type="number" name="age" min="13" max="120" required
                               value="<?php echo h($profileForm['age']); ?>">
                    </div>
                    <div>
                        <label class="ep-form-label" for="profileEmail">Email</label>
                        <input class="ep-form-control" id="profileEmail" type="email" name="email" maxlength="190" required
                               value="<?php echo h($profileForm['email']); ?>">
                    </div>
                    <?php if ($hasPhone): ?>
                    <div>
                        <label class="ep-form-label" for="profilePhone">Phone number</label>
                        <input class="ep-form-control" id="profilePhone" type="tel" name="phone" maxlength="30" required
                               value="<?php echo h($profileForm['phone']); ?>">
                    </div>
                    <?php endif; ?>
                    <div class="profile-settings-full" id="delivery">
                        <h4 class="ep-address-heading"><i class="fas fa-map-marker-alt"></i> Default delivery address</h4>
                        <p class="ep-address-hint">Used automatically at checkout. You can still send a single order somewhere else.</p>
                        <?php if (!$addressComplete && $savedAddress['full'] !== ''): ?>
                            <div class="ep-address-legacy">
                                <i class="fas fa-exclamation-circle"></i>
                                <span>Your current address <strong>"<?php echo h($savedAddress['full']); ?>"</strong> is missing details. Please fill in the fields below.</span>
                            </div>
                        <?php endif; ?>
                        <?php ep_render_address_fields($profileAddr, '../assets/data/psgc', 'profileAddr_'); ?>
                    </div>
                </div>
                <div class="profile-settings-actions">
                    <a href="user_dashboard.php" class="ep-btn profile-settings-back">Back</a>
                    <button type="submit" class="ep-btn ep-btn-primary">Save changes</button>
                </div>
            </form>
        </section>
        <?php else: ?>

        <div class="pf-layout">
            <section class="pf-card pf-orders" aria-labelledby="pfOrdersTitle">
                <div class="pf-card-head">
                    <h2 class="pf-card-title" id="pfOrdersTitle">My orders</h2>
                    <form action="user_dashboard.php" method="GET" class="pf-order-search" role="search">
                        <?php if ($current_filter !== 'All'): ?>
                            <input type="hidden" name="status" value="<?php echo h($current_filter); ?>">
                        <?php endif; ?>
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input type="text" name="q" placeholder="Search by order number or date"
                               aria-label="Search your orders" value="<?php echo h($search_query); ?>">
                    </form>
                </div>

                <?php
                $orderTabs = [
                    'All' => 'All',
                    'to_pay' => 'To pay',
                    'to_ship' => 'To ship',
                    'to_receive' => 'To receive',
                    'to_review' => 'To review',
                ];
                ?>
                <nav class="pf-tabs" aria-label="Filter orders by status">
                    <?php foreach ($orderTabs as $tabKey => $tabLabel): ?>
                        <a href="user_dashboard.php?status=<?php echo h($tabKey); ?>"
                           class="pf-tab<?php echo $current_filter === $tabKey ? ' is-active' : ''; ?>"
                           <?php echo $current_filter === $tabKey ? 'aria-current="page"' : ''; ?>>
                            <?php echo h($tabLabel); ?>
                            <?php if ($statusCounts[$tabKey] > 0): ?>
                                <span class="pf-tab-count"><?php echo (int)$statusCounts[$tabKey]; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <?php if (count($orders) > 0): ?>
                    <ul class="pf-order-list">
                        <?php foreach ($orders as $o):
                            $oid = (int)$o['id'];
                            $ost = (string)($o['status'] ?? '');
                            $canCancel = in_array($ost, $cancellableStatuses, true);
                            $statusLabel = ias_order_display_status($o['status'] ?? '', $o['shipment_status'] ?? null);
                            $fulfilKey = strtolower(trim((string)($o['fulfillment_type'] ?? '')));
                            $fulfil = $fulfillmentLabels[$fulfilKey] ?? '';
                            [$noteIcon, $noteText] = $orderStatusNote($statusLabel, trim((string)($o['carrier'] ?? '')), $fulfilKey === 'pickup');
                            $items = $orderItems[$oid] ?? [];
                            $itemQty = array_sum(array_map(static fn($it) => (int)$it['quantity'], $items));
                            $hiddenCount = max(0, count($items) - $orderItemsShown);
                            $payLabel = $paymentLabel($o);
                            $shipTo = trim((string)($o['shipping_address'] ?? ''));
                            $phone = trim((string)($o['customer_phone'] ?? ''));
                            $hasDetails = $shipTo !== '' || $phone !== '' || $payLabel !== '' || $fulfil !== '';
                            ?>
                            <li class="pf-order <?php echo $orderStatusTone($statusLabel); ?>">
                                <div class="pf-order-head">
                                    <div class="pf-order-ref">
                                        <strong>Order #ORD-<?php echo $oid; ?></strong>
                                        <span>Placed <?php echo date('M d, Y', strtotime($o['created_at'])); ?></span>
                                    </div>
                                    <span class="pf-status <?php echo $orderStatusTone($statusLabel); ?>"><?php echo h($statusLabel); ?></span>
                                </div>

                                <?php if ($items): ?>
                                    <ul class="pf-order-items" id="pfOrderItems<?php echo $oid; ?>">
                                        <?php foreach ($items as $i => $it):
                                            $itName = trim((string)($it['name'] ?? ''));
                                            $itImg = $itName !== '' ? ias_client_product_image_url($it) : '';
                                            $itQty = (int)$it['quantity'];
                                            $itPrice = (float)$it['price'];
                                            $itLink = $itName !== '' ? 'products.php?id=' . (int)$it['product_id'] : '';
                                            ?>
                                            <li class="pf-item"<?php echo $i >= $orderItemsShown ? ' data-extra hidden' : ''; ?>>
                                                <span class="pf-item-thumb">
                                                    <?php if ($itImg !== ''): ?>
                                                        <img src="<?php echo h($itImg); ?>" alt="" loading="lazy">
                                                    <?php else: ?>
                                                        <i class="fas fa-box" aria-hidden="true"></i>
                                                    <?php endif; ?>
                                                </span>
                                                <span class="pf-item-info">
                                                    <?php if ($itLink !== ''): ?>
                                                        <a class="pf-item-name" href="<?php echo h($itLink); ?>"><?php echo h($itName); ?></a>
                                                    <?php else: ?>
                                                        <span class="pf-item-name is-gone">Product no longer available</span>
                                                    <?php endif; ?>
                                                    <span class="pf-item-qty">Qty <?php echo $itQty; ?> &times; &#8369;<?php echo number_format($itPrice, 2); ?></span>
                                                </span>
                                                <strong class="pf-item-sub">&#8369;<?php echo number_format($itPrice * $itQty, 2); ?></strong>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if ($hiddenCount > 0): ?>
                                        <button type="button" class="pf-more-items" aria-expanded="false"
                                                aria-controls="pfOrderItems<?php echo $oid; ?>" data-more-items
                                                data-label-closed="Show <?php echo $hiddenCount; ?> more <?php echo $hiddenCount === 1 ? 'item' : 'items'; ?>"
                                                data-label-open="Show fewer items">
                                            <span>Show <?php echo $hiddenCount; ?> more <?php echo $hiddenCount === 1 ? 'item' : 'items'; ?></span>
                                            <i class="fas fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <div class="pf-order-foot">
                                    <p class="pf-order-note"><i class="fas <?php echo h($noteIcon); ?>" aria-hidden="true"></i> <?php echo h($noteText); ?></p>
                                    <p class="pf-order-total">
                                        <span>Order total<?php echo $itemQty > 0 ? ' (' . $itemQty . ' ' . ($itemQty === 1 ? 'item' : 'items') . ')' : ''; ?></span>
                                        <strong>&#8369;<?php echo number_format((float)$o['total'], 2); ?></strong>
                                    </p>
                                </div>

                                <?php if ($hasDetails || $canCancel): ?>
                                    <div class="pf-order-actions">
                                        <?php if ($hasDetails): ?>
                                            <button type="button" class="pf-order-btn" aria-expanded="false"
                                                    aria-controls="pfOrderDetails<?php echo $oid; ?>" data-order-details>
                                                Order details <i class="fas fa-chevron-down" aria-hidden="true"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($canCancel): ?>
                                            <form method="post" class="order-cancel-form" data-confirm="The items go back into stock and the order can't be restored." data-confirm-title="Cancel this order?" data-confirm-ok="Cancel order" data-confirm-cancel="Keep order" data-confirm-type="danger">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="cancel_order">
                                                <input type="hidden" name="order_id" value="<?php echo $oid; ?>">
                                                <button type="submit" class="pf-order-btn is-danger">Cancel order</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($hasDetails): ?>
                                        <dl class="pf-order-details" id="pfOrderDetails<?php echo $oid; ?>" hidden>
                                            <?php if ($fulfil !== ''): ?>
                                                <div><dt>Fulfillment</dt><dd><?php echo h($fulfil); ?></dd></div>
                                            <?php endif; ?>
                                            <?php if ($shipTo !== ''): ?>
                                                <div><dt><?php echo $fulfilKey === 'pickup' ? 'Pickup location' : 'Ship to'; ?></dt><dd><?php echo h($shipTo); ?></dd></div>
                                            <?php endif; ?>
                                            <?php if ($phone !== ''): ?>
                                                <div><dt>Contact number</dt><dd><?php echo h($phone); ?></dd></div>
                                            <?php endif; ?>
                                            <?php if ($payLabel !== ''): ?>
                                                <div><dt>Payment</dt><dd><?php echo h($payLabel); ?></dd></div>
                                            <?php endif; ?>
                                        </dl>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ($search_query !== '' || $current_filter !== 'All'): ?>
                    <div class="pf-empty">
                        <span class="pf-empty-icon"><i class="fas fa-search" aria-hidden="true"></i></span>
                        <strong>No orders match</strong>
                        <span>Try another status or search term.</span>
                        <a href="user_dashboard.php" class="pf-btn pf-btn-outline">Show all orders</a>
                    </div>
                <?php else: ?>
                    <div class="pf-empty">
                        <span class="pf-empty-icon"><i class="fas fa-box-open" aria-hidden="true"></i></span>
                        <strong>No orders yet</strong>
                        <span>Your orders and their delivery status will show up here.</span>
                        <a href="shop.php" class="pf-btn pf-btn-primary">Start shopping</a>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="pf-side">
                <section class="pf-card pf-address<?php echo $addressComplete ? '' : ' is-incomplete'; ?>" aria-labelledby="pfAddrTitle">
                    <div class="pf-card-head">
                        <h2 class="pf-card-title" id="pfAddrTitle">Delivery address</h2>
                        <a class="pf-link" href="user_dashboard.php?settings=1#delivery">
                            <?php echo $addressComplete ? 'Edit' : 'Complete'; ?>
                        </a>
                    </div>
                    <?php if ($addressComplete): ?>
                        <p class="pf-address-line"><?php echo h($savedAddressLine); ?></p>
                        <?php if ($profileForm['phone'] !== ''): ?>
                            <p class="pf-address-meta"><i class="fas fa-phone-alt" aria-hidden="true"></i> <?php echo h($profileForm['phone']); ?></p>
                        <?php endif; ?>
                        <p class="pf-address-meta"><i class="fas fa-check-circle" aria-hidden="true"></i> Used automatically at checkout</p>
                    <?php else: ?>
                        <p class="pf-address-line"><?php echo $savedAddressLine !== '' ? h($savedAddressLine) : 'No delivery address yet.'; ?></p>
                        <p class="pf-address-meta is-warning">
                            <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                            Add your street, barangay, city, province and ZIP so we can deliver your orders.
                        </p>
                    <?php endif; ?>
                </section>

                <nav class="pf-card pf-shortcuts" aria-label="My saved items">
                    <a class="pf-shortcut" href="wishlist.php">
                        <span class="pf-shortcut-icon"><i class="fas fa-heart" aria-hidden="true"></i></span>
                        <span class="pf-shortcut-label">Wishlist</span>
                        <span class="pf-shortcut-count"><?php echo (int)($epWishCount ?? 0); ?></span>
                        <i class="fas fa-chevron-right pf-shortcut-caret" aria-hidden="true"></i>
                    </a>
                    <a class="pf-shortcut" href="saved_builds.php">
                        <span class="pf-shortcut-icon"><i class="fas fa-desktop" aria-hidden="true"></i></span>
                        <span class="pf-shortcut-label">Saved builds</span>
                        <span class="pf-shortcut-count"><?php echo (int)$savedBuildCount; ?></span>
                        <i class="fas fa-chevron-right pf-shortcut-caret" aria-hidden="true"></i>
                    </a>
                    <a class="pf-shortcut" href="notifications.php">
                        <span class="pf-shortcut-icon"><i class="fas fa-bell" aria-hidden="true"></i></span>
                        <span class="pf-shortcut-label">Notifications</span>
                        <?php if (!empty($epNotifUnread)): ?>
                            <span class="pf-shortcut-count is-new"><?php echo (int)$epNotifUnread; ?> new</span>
                        <?php endif; ?>
                        <i class="fas fa-chevron-right pf-shortcut-caret" aria-hidden="true"></i>
                    </a>
                </nav>

                <?php if (!empty($recentProducts)): ?>
                    <section class="pf-card pf-new" aria-labelledby="pfNewTitle">
                        <div class="pf-card-head">
                            <h2 class="pf-card-title" id="pfNewTitle">New in store</h2>
                            <a class="pf-link" href="shop.php">Shop all</a>
                        </div>
                        <ul class="pf-new-list">
                            <?php foreach ($recentProducts as $rp): ?>
                                <li>
                                    <a class="pf-new-item" href="products.php?id=<?php echo (int)$rp['id']; ?>">
                                        <span class="pf-new-thumb"><img src="<?php echo h(ias_client_product_image_url($rp)); ?>" alt="" loading="lazy"></span>
                                        <span class="pf-new-info">
                                            <span class="pf-new-name"><?php echo h($rp['name']); ?></span>
                                            <span class="pf-new-price">&#8369;<?php echo number_format((float)$rp['price'], 2); ?></span>
                                        </span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php
if ($showSettings) {
    $extraScripts = '<script src="../assets/js/ph-address.js"></script>';
} else {
    // Order cards: "Order details" and "Show N more items" toggles.
    $extraScripts = <<<'JS'
<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-order-details], [data-more-items]');
    if (!btn) return;
    var open = btn.getAttribute('aria-expanded') !== 'true';
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    var target = document.getElementById(btn.getAttribute('aria-controls'));
    if (!target) return;
    if (btn.hasAttribute('data-order-details')) {
        target.hidden = !open;
    } else {
        target.querySelectorAll('[data-extra]').forEach(function (li) { li.hidden = !open; });
        btn.querySelector('span').textContent = btn.getAttribute(open ? 'data-label-open' : 'data-label-closed');
    }
});
</script>
JS;
}
include __DIR__ . '/ep_footer.php';
?>
