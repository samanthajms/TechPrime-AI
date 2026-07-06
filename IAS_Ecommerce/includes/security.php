<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Security Helper for IAS E-commerce
 */

// CSRF Protection
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}

// XSS Protection
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Activity Logging
function logActivity($db, $user_id, $action, $details) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $db->prepare("INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $user_id, $action, $details, $ip);
    $stmt->execute();
}

// Role Based Access Control
function checkRole($roles) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], (array)$roles)) {
        header("Location: /login.html");
        exit;
    }
}

// Session Timeout (15 minutes)
function checkSessionTimeout() {
    $timeout = 900; // 15 minutes
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        session_unset();
        session_destroy();
        header("Location: /login.html?timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// Password Complexity Check
// Reads rules from site_settings table if a DB connection is provided;
// falls back to safe hardcoded defaults if the table doesn't exist yet.
function isPasswordComplex($password, $db = null) {
    // Default rules (safe fallback)
    $minLen      = 8;
    $reqUpper    = true;
    $reqLower    = true;
    $reqNumber   = true;
    $reqSpecial  = true;

    if ($db !== null) {
        $res = @$db->query(
            "SELECT setting_key, setting_value FROM site_settings
             WHERE setting_key IN ('pw_min_length','pw_require_upper','pw_require_lower','pw_require_number','pw_require_special')"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                switch ($row['setting_key']) {
                    case 'pw_min_length':     $minLen     = max(6, (int)$row['setting_value']); break;
                    case 'pw_require_upper':  $reqUpper   = $row['setting_value'] === '1';       break;
                    case 'pw_require_lower':  $reqLower   = $row['setting_value'] === '1';       break;
                    case 'pw_require_number': $reqNumber  = $row['setting_value'] === '1';       break;
                    case 'pw_require_special':$reqSpecial = $row['setting_value'] === '1';       break;
                }
            }
        }
    }

    if (strlen($password) < $minLen)                       return false;
    if ($reqUpper   && !preg_match('/[A-Z]/', $password))  return false;
    if ($reqLower   && !preg_match('/[a-z]/', $password))  return false;
    if ($reqNumber  && !preg_match('/[0-9]/', $password))  return false;
    if ($reqSpecial && !preg_match('/[^A-Za-z0-9]/', $password)) return false;
    return true;
}

// Returns the active password rules as an array (for frontend hints)
function getPasswordRules($db = null) {
    $rules = [
        'min_length'      => 8,
        'require_upper'   => true,
        'require_lower'   => true,
        'require_number'  => true,
        'require_special' => true,
    ];
    if ($db !== null) {
        $res = @$db->query(
            "SELECT setting_key, setting_value FROM site_settings
             WHERE setting_key IN ('pw_min_length','pw_require_upper','pw_require_lower','pw_require_number','pw_require_special')"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                switch ($row['setting_key']) {
                    case 'pw_min_length':     $rules['min_length']      = max(6, (int)$row['setting_value']); break;
                    case 'pw_require_upper':  $rules['require_upper']   = $row['setting_value'] === '1';       break;
                    case 'pw_require_lower':  $rules['require_lower']   = $row['setting_value'] === '1';       break;
                    case 'pw_require_number': $rules['require_number']  = $row['setting_value'] === '1';       break;
                    case 'pw_require_special':$rules['require_special'] = $row['setting_value'] === '1';       break;
                }
            }
        }
    }
    return $rules;
}

/** Product image path for seller uploads or legacy URL */
function ias_product_image_url(array $p): string
{
    if (!empty($p['image'])) {
        return '../uploads/products/' . basename($p['image']);
    }
    if (!empty($p['image_url'])) {
        return $p['image_url'];
    }
    return '';
}

/** SQL fragment: in-stock seller products with an uploaded image filename (client listings) */
function ias_client_product_list_sql_condition(string $alias = 'p'): string
{
    $a = preg_match('/^[a-z_]+$/', $alias) ? $alias : 'p';
    return "COALESCE({$a}.stock, 0) > 0
        AND {$a}.seller_id IS NOT NULL AND {$a}.seller_id > 0
        AND {$a}.image IS NOT NULL AND TRIM({$a}.image) <> ''";
}

/** Client shop: only seller-uploaded files that exist on disk (no image_url / placeholders) */
function ias_client_product_image_url(array $p): string
{
    if (empty($p['image']) || !is_string($p['image'])) {
        return '';
    }
    $filename = basename($p['image']);
    if ($filename === '' || preg_match('/^(no[_-]?image|placeholder|default|demo|mock|fake)/i', $filename)) {
        return '';
    }
    $path = dirname(__DIR__) . '/uploads/products/' . $filename;
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return '';
    }
    return '../uploads/products/' . $filename;
}

/** Keep only rows with a valid client-listable image; optional max count */
function ias_client_filter_products_for_display(array $rows, int $limit = 0): array
{
    $out = [];
    foreach ($rows as $p) {
        if (ias_client_product_image_url($p) === '') {
            continue;
        }
        $out[] = $p;
        if ($limit > 0 && count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/** Validate and save product image; returns filename or null */
function ias_handle_product_upload(int $sellerId): ?string
{
    if (empty($_FILES['product_image']['name']) || ($_FILES['product_image']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return null;
    }
    $allowed = ['image/jpeg', 'image/jpg', 'image/png'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['product_image']['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed, true)) {
        return null;
    }
    $ext = $mime === 'image/png' ? 'png' : 'jpg';
    $dir = dirname(__DIR__) . '/uploads/products';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = 'seller_' . $sellerId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (move_uploaded_file($_FILES['product_image']['tmp_name'], $dir . '/' . $filename)) {
        return $filename;
    }
    return null;
}

/** Human-readable order/shipment status for all roles */
function ias_order_display_status(?string $orderStatus, ?string $shipmentStatus = null): string
{
    if ($shipmentStatus !== null && $shipmentStatus !== '') {
        $ship = [
            'pending' => 'Pending',
            'processing' => 'Processing',
            'shipped' => 'Shipped',
            'out_for_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered',
        ];
        return $ship[$shipmentStatus] ?? ucfirst(str_replace('_', ' ', $shipmentStatus));
    }
    $order = [
        'to_pay' => 'To Pay',
        'to_ship' => 'To Ship',
        'to_receive' => 'With Courier',
        'to_review' => 'Completed',
    ];
    $s = $orderStatus ?? '';
    return $order[$s] ?? ($s !== '' ? ucfirst($s) : 'New Order');
}

/** Map legacy dashboard tab labels to DB enum values */
function ias_normalize_order_status_filter(string $raw): string
{
    $legacy = [
        'To Pay' => 'to_pay',
        'To Ship' => 'to_ship',
        'To Receive' => 'to_receive',
        'To Review' => 'to_review',
    ];
    return $legacy[$raw] ?? $raw;
}

/** Map URL params to alert messages for IAS_UI.alert() */
function ias_alert_message_from_request(): ?string
{
    $map = [
        'added'       => 'Product added successfully.',
        'updated'     => 'Shipment status updated successfully.',
        'deleted'     => 'Product deleted successfully.',
        'passed'      => 'Order passed to courier successfully.',
        'assigned'    => 'Shipment assigned successfully.',
        'updated_ship'=> 'Shipment status updated successfully.',
        'placed'      => 'Order placed successfully.',
        'cart_added'  => 'Added to cart successfully.',
        'registered'  => 'Registration successful. Please check your email to activate your account.',
        'logout'      => 'You have been logged out successfully.',
        'login'       => 'Login successful.',
        'error'       => 'Could not complete the action. Please check your input and try again.',
        'stock'       => 'Some items are out of stock. Your cart was updated.',
    ];
    if (isset($_GET['logged_out'])) {
        return $map['logout'];
    }
    if (isset($_GET['added'])) {
        return $map['added'];
    }
    if (!empty($_GET['error']) && isset($map[$_GET['error']])) {
        return $map[$_GET['error']];
    }
    if (!empty($_GET['alert']) && isset($map[$_GET['alert']])) {
        return $map[$_GET['alert']];
    }
    if (!empty($_GET['success']) && isset($map[$_GET['success']])) {
        return $map[$_GET['success']];
    }
    if (isset($_GET['registered'])) {
        return $map['registered'];
    }
    if (isset($_GET['updated'])) {
        return 'Cart updated successfully.';
    }
    if (isset($_GET['removed'])) {
        return 'Item removed from cart.';
    }
    return null;
}

/** Output ui_alerts.js + auto-show flash from query string */
function ias_alert_footer(): void
{
    $msg = ias_alert_message_from_request();
    $root = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/SELLER/') !== false
        || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/courier/') !== false
        || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/CLIENT/') !== false)
        ? '../includes/ui_alerts.js' : 'includes/ui_alerts.js';
    echo '<script src="' . h($root) . '"></script>';
    if ($msg) {
        $type = ((!empty($_GET['alert']) && $_GET['alert'] === 'error') || !empty($_GET['error'])) ? 'error' : 'success';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){if(typeof IAS_UI!=="undefined")IAS_UI.alert('
            . json_encode($msg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ','
            . json_encode($type) . ',0);});</script>';
    }
}
?>
