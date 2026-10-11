<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/client_order_ui.php';
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

/* ---- Cancel order request (To Pay → Custodian Accept Cancel) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $oid = (int)($_POST['order_id'] ?? 0);
    $reason = trim((string)($_POST['cancel_reason'] ?? ''));
    $result = ep_request_order_cancel($db, $user_id, $oid, $reason);
    $redir = 'user_dashboard.php?status=to_pay&';
    if (!empty($result['ok'])) {
        $redir .= 'alert=cancel_requested';
    } elseif (($result['error'] ?? '') === 'already_cancelled') {
        $redir .= 'alert=already_cancelled';
    } elseif (($result['error'] ?? '') === 'not_cancellable') {
        $redir .= 'alert=not_cancellable';
    } elseif (($result['error'] ?? '') === 'reason') {
        $redir .= 'alert=cancel_reason';
    } else {
        $redir .= 'alert=error';
    }
    header('Location: ' . $redir);
    exit;
}

/* ---- Item Received (To Receive) → moves to Order History ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_order') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $oid = (int)($_POST['order_id'] ?? 0);
    $result = ep_confirm_order_received($db, $user_id, $oid);
    $redir = 'user_dashboard.php?';
    if (!empty($result['ok'])) {
        $redir .= 'alert=received#order-history';
    } elseif (($result['error'] ?? '') === 'stock') {
        $redir .= 'status=to_receive&alert=receive_stock';
    } elseif (($result['error'] ?? '') === 'not_receivable') {
        $redir .= 'status=to_receive&alert=not_receivable';
    } else {
        $redir .= 'status=to_receive&alert=error';
    }
    header('Location: ' . $redir);
    exit;
}

/* ---- Remove order from client Order Summary / History lists ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hide_order') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $oid = (int)($_POST['order_id'] ?? 0);
    $from = strtolower(trim((string)($_POST['from'] ?? 'summary')));
    $result = ep_hide_client_order($db, $user_id, $oid);
    $redir = 'user_dashboard.php?';
    if ($from === 'history') {
        $redir .= 'alert=' . (!empty($result['ok']) ? 'order_removed' : 'error') . '#order-history';
    } elseif (!empty($result['ok'])) {
        $redir .= 'alert=order_removed';
    } elseif (($result['error'] ?? '') === 'not_removable') {
        $redir .= 'status=to_receive&alert=not_removable';
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

/** One-line progress note shown at the bottom of an order card: [icon, text]. Delivery is always Lalamove. */
$orderStatusNote = static function (string $label, string $carrier, bool $isPickup): array {
    switch (strtolower($label)) {
        case 'cancelled':
            return ['fa-times-circle', 'This order was cancelled'];
        case 'to pay':
            return ['fa-wallet', 'Waiting for payment'];
        case 'delivered':
        case 'completed':
        case 'to review':
            if ($isPickup) {
                return ['fa-check-circle', 'Picked up at the store'];
            }
            return ['fa-check-circle', 'Delivered by Lalamove'];
        case 'with courier':
        case 'shipped':
        case 'out for delivery':
        case 'to receive':
            if ($isPickup) {
                return ['fa-store', 'Ready for pickup at the store'];
            }
            return ['fa-truck', 'On the way with Lalamove'];
        case 'to ship':
            if ($isPickup) {
                return ['fa-box', 'EasyPC is preparing your order for pickup'];
            }
            return ['fa-truck', 'Preparing for Lalamove delivery'];
        default:
            return ['fa-box', $isPickup ? 'EasyPC is preparing your order for pickup' : 'EasyPC is preparing your Lalamove delivery'];
    }
};

/** Compact page range for order table pagination. */
$epOrderPageRange = static function (int $current, int $total): array {
    if ($total <= 7) {
        return range(1, max(1, $total));
    }
    $pages = [1];
    $start = max(2, $current - 1);
    $end = min($total - 1, $current + 1);
    if ($start > 2) {
        $pages[] = '...';
    }
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($end < $total - 1) {
        $pages[] = '...';
    }
    $pages[] = $total;
    return $pages;
};

/** Labels for the optional payment / fulfillment columns (empty when not recorded). */
$paymentLabel = static function (array $o): string {
    $methods = [
        'cod' => 'Cash on delivery',
        'online' => 'Online payment',
        'gcash' => 'GCash',
        'card' => 'Card',
    ];
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
// Order Summary tabs only — completed (to_review) and cancelled live in Order History.
$allowed_filters = ['All', 'to_pay', 'to_ship', 'to_receive'];
if (!in_array($current_filter, $allowed_filters, true)) {
    $current_filter = 'All';
}

$legacyStatus = [
    'to_pay' => 'To Pay',
    'to_ship' => 'To Ship',
    'to_receive' => 'To Receive',
    'to_review' => 'To Review',
];

$perPage = 5;
$summaryPage = max(1, (int)($_GET['spage'] ?? 1));
$historyPage = max(1, (int)($_GET['hpage'] ?? 1));

$orderSelect = "SELECT o.*,
               (SELECT s.shipment_status FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) AS shipment_status,
               (SELECT s.carrier FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) AS carrier
        FROM orders o
        WHERE o.user_id = ?
          AND COALESCE(o.client_history_hidden, FALSE) = FALSE";

$searchSql = '';
$searchParams = [];
if ($search_query !== '') {
    $like = '%' . $search_query . '%';
    $searchSql = " AND (
        CAST(o.id AS TEXT) LIKE ?
        OR CAST(o.total AS TEXT) LIKE ?
        OR o.status LIKE ?
        OR o.shipping_address LIKE ?
        OR o.customer_phone LIKE ?
        OR to_char(o.created_at, 'Month DD, YYYY') LIKE ?
    )";
    $searchParams = [$like, $like, $like, $like, $like, $like];
}

// ---- Order Summary (active only) ----
$summaryWhere = " AND LOWER(TRIM(o.status)) IN ('to_pay', 'to_ship', 'to_receive', 'to pay', 'to ship', 'to receive')";
$summaryParams = [$user_id];
if ($current_filter === 'to_ship' || $current_filter === 'to_receive') {
    $summaryWhere .= " AND LOWER(TRIM(o.status)) IN ('to_ship', 'to_receive', 'to ship', 'to receive')";
} elseif ($current_filter === 'to_pay') {
    $summaryWhere .= ' AND (o.status = ? OR o.status = ?)';
    $summaryParams[] = 'to_pay';
    $summaryParams[] = 'To Pay';
}
$summaryWhere .= $searchSql;
$summaryParams = array_merge($summaryParams, $searchParams);

$stSumCount = $db->prepare('SELECT COUNT(*) FROM orders o WHERE o.user_id = ? AND COALESCE(o.client_history_hidden, FALSE) = FALSE' . $summaryWhere);
$stSumCount->execute($summaryParams);
$summaryTotal = (int)$stSumCount->fetchColumn();
$summaryPages = max(1, (int)ceil($summaryTotal / $perPage));
if ($summaryPage > $summaryPages) {
    $summaryPage = $summaryPages;
}
$summaryOffset = ($summaryPage - 1) * $perPage;

$stOrders = $db->prepare($orderSelect . $summaryWhere . ' ORDER BY o.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$summaryOffset);
$stOrders->execute($summaryParams);
$orders = $stOrders->fetchAll(PDO::FETCH_ASSOC);

// ---- Order History (received / cancelled) ----
$historyWhere = " AND (
    LOWER(TRIM(o.status)) IN ('to_review', 'to review', 'cancelled', 'canceled')
)";
$historyWhere .= $searchSql;
$historyParams = array_merge([$user_id], $searchParams);

$stHistCount = $db->prepare('SELECT COUNT(*) FROM orders o WHERE o.user_id = ? AND COALESCE(o.client_history_hidden, FALSE) = FALSE' . $historyWhere);
$stHistCount->execute($historyParams);
$historyTotal = (int)$stHistCount->fetchColumn();
$historyPages = max(1, (int)ceil($historyTotal / $perPage));
if ($historyPage > $historyPages) {
    $historyPage = $historyPages;
}
$historyOffset = ($historyPage - 1) * $perPage;

$stHistory = $db->prepare($orderSelect . $historyWhere . ' ORDER BY o.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$historyOffset);
$stHistory->execute($historyParams);
$historyOrders = $stHistory->fetchAll(PDO::FETCH_ASSOC);

// Items for both lists in one query.
$orderItems = [];
$allListed = array_merge($orders, $historyOrders);
if ($allListed) {
    $orderIds = array_values(array_unique(array_map(static fn($o) => (int)$o['id'], $allListed)));
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

// Summary tab counts (active, not hidden).
$statusCounts = array_fill_keys($allowed_filters, 0);
$stCounts = $db->prepare(
    "SELECT status, COUNT(*) AS n FROM orders
     WHERE user_id = ? AND COALESCE(client_history_hidden, FALSE) = FALSE
       AND LOWER(TRIM(status)) IN ('to_pay', 'to_ship', 'to_receive', 'to pay', 'to ship', 'to receive')
     GROUP BY status"
);
$stCounts->execute([$user_id]);
foreach ($stCounts->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
    $statusCounts['All'] += (int)$cRow['n'];
    $cKey = ias_normalize_order_status_filter((string)$cRow['status']);
    if ($cKey === 'to_ship' || $cKey === 'to_receive') {
        $statusCounts['to_ship'] += (int)$cRow['n'];
        $statusCounts['to_receive'] += (int)$cRow['n'];
    } elseif ($cKey !== 'All' && isset($statusCounts[$cKey])) {
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

$isLoggedIn = true;
$activePage = 'account';
$bodyClass = 'pf-body';
$pageTitle = 'My Account';
$searchQuery = '';

$statusTitles = [
    'All' => 'Order Summary',
    'to_pay' => 'To Pay',
    'to_ship' => 'To Ship',
    'to_receive' => 'To Receive',
];

$paidOrderId = (int)($_GET['order_id'] ?? 0);
$showPaidSuccess = isset($_GET['alert']) && (string)$_GET['alert'] === 'paid';

$alertMsg = '';
if (!empty($_GET['alert']) && !$showPaidSuccess) {
    $alertMap = [
        'cancelled' => 'Order cancelled successfully.',
        'cancel_requested' => 'Cancellation requested.',
        'cancel_reason' => 'Please choose a cancellation reason.',
        'already_cancelled' => 'This order was already cancelled.',
        'not_cancellable' => 'This order can no longer be cancelled.',
        'already_paid' => 'This order was already paid.',
        'received' => 'Item received successfully.',
        'receive_stock' => 'Could not confirm receipt — stock is insufficient.',
        'not_receivable' => 'This order cannot be marked as received.',
        'order_removed' => 'Order removed successfully.',
        'not_removable' => 'This order cannot be removed yet.',
        'error' => 'Could not complete the action. Please try again.',
        'profile_saved' => 'Profile settings saved.',
    ];
    $alertMsg = $alertMap[$_GET['alert']] ?? '';
}
$cancelReasons = ep_order_cancel_reasons();

/** Build pagination query string preserving filter/search and the other table's page. */
$epOrdersUrl = static function (array $overrides = []) use ($current_filter, $search_query, $summaryPage, $historyPage): string {
    $q = [
        'status' => $current_filter,
        'q' => $search_query,
        'spage' => $summaryPage,
        'hpage' => $historyPage,
    ];
    foreach ($overrides as $k => $v) {
        $q[$k] = $v;
    }
    if (($q['status'] ?? 'All') === 'All') {
        unset($q['status']);
    }
    if (($q['q'] ?? '') === '') {
        unset($q['q']);
    }
    if ((int)($q['spage'] ?? 1) <= 1) {
        unset($q['spage']);
    }
    if ((int)($q['hpage'] ?? 1) <= 1) {
        unset($q['hpage']);
    }
    $qs = http_build_query($q);
    return 'user_dashboard.php' . ($qs !== '' ? '?' . $qs : '');
};

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

        <?php if ($showPaidSuccess): ?>
            <div class="ep-pay-success-backdrop" id="epPaySuccessBackdrop" aria-hidden="false"></div>
            <div class="ep-pay-success" id="epPaySuccess" role="alertdialog" aria-modal="true" aria-labelledby="epPaySuccessTitle">
                <button type="button" class="ep-pay-success-close" id="epPaySuccessClose" aria-label="Close">&times;</button>
                <div class="ep-pay-success-badge" aria-hidden="true"><i class="fas fa-wallet"></i></div>
                <h3 class="ep-pay-success-title" id="epPaySuccessTitle">Payment successful</h3>
                <p class="ep-pay-success-msg">
                    <?php if ($paidOrderId > 0): ?>
                        Payment for order <strong>#ORD-<?php echo (int)$paidOrderId; ?></strong> was successful.
                    <?php else: ?>
                        Your payment was successful.
                    <?php endif; ?>
                </p>
                <div class="ep-pay-success-actions">
                    <button type="button" class="ep-btn ep-btn-primary" id="epPaySuccessOk">Got it</button>
                </div>
            </div>
        <?php endif; ?>

        <div class="pf-layout">
            <div class="pf-orders-col">
            <section class="pf-card pf-orders" id="order-summary" aria-labelledby="pfOrdersTitle">
                <div class="pf-card-head">
                    <h2 class="pf-card-title" id="pfOrdersTitle">Order Summary</h2>
                    <form action="user_dashboard.php" method="GET" class="pf-order-search" role="search">
                        <?php if ($current_filter !== 'All'): ?>
                            <input type="hidden" name="status" value="<?php echo h($current_filter); ?>">
                        <?php endif; ?>
                        <?php if ($historyPage > 1): ?>
                            <input type="hidden" name="hpage" value="<?php echo (int)$historyPage; ?>">
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
                ];
                $cardCtx = [
                    'current_filter' => $current_filter,
                    'orderItemsShown' => $orderItemsShown,
                    'cancelReasons' => $cancelReasons,
                    'orderStatusTone' => $orderStatusTone,
                    'orderStatusNote' => $orderStatusNote,
                    'paymentLabel' => $paymentLabel,
                    'fulfillmentLabels' => $fulfillmentLabels,
                ];
                ?>
                <nav class="pf-tabs" aria-label="Filter Order Summary by status">
                    <?php foreach ($orderTabs as $tabKey => $tabLabel): ?>
                        <a href="<?php echo h($epOrdersUrl(['status' => $tabKey, 'spage' => 1])); ?>"
                           class="pf-tab<?php echo $current_filter === $tabKey ? ' is-active' : ''; ?>"
                           <?php echo $current_filter === $tabKey ? 'aria-current="page"' : ''; ?>>
                            <?php echo h($tabLabel); ?>
                            <?php if ($statusCounts[$tabKey] > 0): ?>
                                <span class="pf-tab-count"><?php echo (int)$statusCounts[$tabKey]; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <p class="pf-orders-sub">Active orders only. Received orders move to Order History below. Delivery is handled by Lalamove.</p>

                <?php if (count($orders) > 0): ?>
                    <ul class="pf-order-list">
                        <?php foreach ($orders as $o):
                            ep_render_client_order_card($o, $orderItems[(int)$o['id']] ?? [], $cardCtx + ['list' => 'summary']);
                        endforeach; ?>
                    </ul>
                    <?php
                    ep_render_orders_pagination(
                        $summaryPage,
                        $summaryPages,
                        $summaryTotal,
                        count($orders),
                        'spage',
                        $epOrdersUrl,
                        $epOrderPageRange,
                        'Order Summary pagination'
                    );
                    ?>
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
                        <strong>No active orders</strong>
                        <span>Placed and in-progress orders will show up here.</span>
                        <a href="shop.php" class="pf-btn pf-btn-primary">Start shopping</a>
                    </div>
                <?php endif; ?>
            </section>

            <section class="pf-card pf-orders pf-order-history" id="order-history" aria-labelledby="pfHistoryTitle">
                <div class="pf-card-head">
                    <h2 class="pf-card-title" id="pfHistoryTitle">Order History</h2>
                    <?php if ($historyTotal > 0): ?>
                        <span class="pf-history-count"><?php echo (int)$historyTotal; ?> completed / cancelled</span>
                    <?php endif; ?>
                </div>
                <p class="pf-orders-sub">Orders you marked as received (and cancelled orders) appear here.</p>

                <?php if (count($historyOrders) > 0): ?>
                    <ul class="pf-order-list">
                        <?php foreach ($historyOrders as $o):
                            ep_render_client_order_card($o, $orderItems[(int)$o['id']] ?? [], $cardCtx + ['list' => 'history']);
                        endforeach; ?>
                    </ul>
                    <?php
                    ep_render_orders_pagination(
                        $historyPage,
                        $historyPages,
                        $historyTotal,
                        count($historyOrders),
                        'hpage',
                        $epOrdersUrl,
                        $epOrderPageRange,
                        'Order History pagination'
                    );
                    ?>
                <?php else: ?>
                    <div class="pf-empty pf-empty-compact">
                        <span class="pf-empty-icon"><i class="fas fa-history" aria-hidden="true"></i></span>
                        <strong>No order history yet</strong>
                        <span>When you confirm Item Received, that order moves here.</span>
                    </div>
                <?php endif; ?>
            </section>
            </div>

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
    // Order cards + payment success dialog.
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
(function () {
    var box = document.getElementById('epPaySuccess');
    if (!box) return;
    var backdrop = document.getElementById('epPaySuccessBackdrop');
    function closePay() {
        box.hidden = true;
        if (backdrop) backdrop.hidden = true;
        try {
            var u = new URL(window.location.href);
            u.searchParams.delete('alert');
            u.searchParams.delete('order_id');
            window.history.replaceState({}, '', u.pathname + (u.search ? u.search : '') + u.hash);
        } catch (err) {}
    }
    var closeBtn = document.getElementById('epPaySuccessClose');
    var okBtn = document.getElementById('epPaySuccessOk');
    if (closeBtn) closeBtn.addEventListener('click', closePay);
    if (okBtn) okBtn.addEventListener('click', closePay);
    if (backdrop) backdrop.addEventListener('click', closePay);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !box.hidden) closePay();
    });
})();
</script>
JS;
}
include __DIR__ . '/ep_footer.php';
?>
