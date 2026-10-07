<?php
// Idle session timeout switch. TEMPORARILY DISABLED for local development — set back to true
// before deployment. When false: no idle sign-out, no idle warning / signed-out dialog
// (includes/session_timeout.js is not loaded), and PHP keeps idle sessions for 8 hours
// instead of php.ini's 24 minutes so they are not silently garbage-collected.
const IAS_SESSION_TIMEOUT_ENABLED = false;

if (session_status() === PHP_SESSION_NONE) {
    if (!IAS_SESSION_TIMEOUT_ENABLED) {
        ini_set('session.gc_maxlifetime', '28800');
    }
    session_start();
}

/**
 * Security Helper for Easy PC E-commerce
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
    $stmt->execute([$user_id, $action, $details, $ip]);
}

// Role Based Access Control
function checkRole($roles) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], (array)$roles)) {
        header('Location: ' . ias_login_url());
        exit;
    }
}

/** URL path of the app root (e.g. /TechPrime-AI/TechPrime-AI), so redirects work from any folder. */
function ias_app_base_url(): string {
    $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $file = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($file !== '' && stripos($file, $root . '/') === 0) {
        $rel = substr($file, strlen($root)); // e.g. /ADMIN/admin_dashboard.php
        if (strlen($script) >= strlen($rel) && strcasecmp(substr($script, -strlen($rel)), $rel) === 0) {
            return substr($script, 0, -strlen($rel));
        }
    }
    $docRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    if ($docRoot !== '' && stripos($root, $docRoot) === 0) {
        return substr($root, strlen($docRoot));
    }
    return '';
}

function ias_login_url(array $query = []): string {
    return ias_app_base_url() . '/login.php' . ($query ? '?' . http_build_query($query) : '');
}

// Idle session timeout. Keep it below session.gc_maxlifetime (1440 s in XAMPP's php.ini):
// past that PHP may garbage-collect the session file first and the user loses the notice.
const IAS_SESSION_IDLE_TIMEOUT = 900; // 15 minutes
const IAS_SESSION_WARN_BEFORE = 60;   // includes/session_timeout.js warns this many seconds before

/**
 * Signs the user out after IAS_SESSION_IDLE_TIMEOUT without activity, otherwise stamps activity.
 * Pages go to login.php?expired=1 (session details shown there); fetch/JSON requests get a
 * 401 {ok:false,error:'session_expired'}.
 */
function checkSessionTimeout() {
    if (ias_session_idle_expired()) {
        ias_expire_session();
        if (ias_request_wants_json()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'session_expired',
                'message' => 'Your session expired due to inactivity. Please log in again.',
            ]);
            exit;
        }
        header('Location: ' . ias_login_url(['expired' => 1]));
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function ias_session_idle_expired(): bool {
    return IAS_SESSION_TIMEOUT_ENABLED && !empty($_SESSION['user_id']) && isset($_SESSION['last_activity'])
        && time() - (int)$_SESSION['last_activity'] >= IAS_SESSION_IDLE_TIMEOUT;
}

/** Seconds left before the idle timeout (0 when signed out or expired). */
function ias_session_remaining(): int {
    if (empty($_SESSION['user_id'])) {
        return 0;
    }
    $last = (int)($_SESSION['last_activity'] ?? time());
    return max(0, IAS_SESSION_IDLE_TIMEOUT - (time() - $last));
}

/** Who/when of the current session, for the session-expired dialog. */
function ias_session_snapshot(): array {
    $role = (string)($_SESSION['role'] ?? '');
    $labels = [
        'admin' => 'Admin',
        'retail_officer' => 'Retail Officer',
        'inventory_custodian' => 'Inventory Custodian',
        'cashier' => 'Cashier',
        'client' => 'Customer',
        'customer' => 'Customer',
        '' => 'Customer',
    ];
    return [
        'name' => trim(($_SESSION['name'] ?? '') . ' ' . ($_SESSION['surname'] ?? '')),
        'email' => (string)($_SESSION['email'] ?? ''),
        'role' => $role,
        'role_label' => $labels[$role] ?? ucwords(str_replace('_', ' ', $role)),
        'login_at' => isset($_SESSION['login_at']) ? (int)$_SESSION['login_at'] : null,
        'last_activity' => isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : null,
    ];
}

/**
 * End an idle session: the old session is discarded and the fresh one only carries
 * $_SESSION['session_expired'] (snapshot) for the notice. Call before any output.
 */
function ias_expire_session(): void {
    $info = ias_session_snapshot();
    $last = $info['last_activity'] ?? time();
    $info['expired_at'] = min(time(), $last + IAS_SESSION_IDLE_TIMEOUT);
    $info['timeout_minutes'] = intdiv(IAS_SESSION_IDLE_TIMEOUT, 60);
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['session_expired'] = $info;
}

/** True for fetch/XHR/JSON requests, which must not be redirected to an HTML page. */
function ias_request_wants_json(): bool {
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type: application/json') === 0) {
            return true;
        }
    }
    return stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0
        || ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'empty';
}

/** Idle warning + session-expired dialog for signed-in pages (staff layout, client header). */
function ias_session_timeout_assets(): string {
    if (!IAS_SESSION_TIMEOUT_ENABLED || empty($_SESSION['user_id'])) {
        return '';
    }
    $base = ias_app_base_url();
    return ias_session_dialog_tags([
        'timeout' => IAS_SESSION_IDLE_TIMEOUT,
        'warn' => IAS_SESSION_WARN_BEFORE,
        'remaining' => ias_session_remaining(),
        'csrf' => generateCsrfToken(),
        'api' => $base . '/backend/api/session.php',
        'login' => $base . '/login.php',
        'logout' => $base . '/logout.php',
        'session' => ias_session_snapshot(),
    ]);
}

/** Login page: show the session-expired dialog for a checkSessionTimeout() redirect. */
function ias_session_expired_notice(array $info): string {
    return ias_session_dialog_tags([
        'timeout' => IAS_SESSION_IDLE_TIMEOUT,
        'notice' => (object)$info,
    ]);
}

function ias_session_dialog_tags(array $cfg): string {
    $src = ias_app_base_url() . '/includes/session_timeout.js?v=1';
    return '<script>window.IAS_SESSION_CFG = '
        . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
        . '<script src="' . h($src) . '" defer></script>';
}

// Password Complexity Check
function isPasswordComplex($password, $db = null) {
    // Default rules (safe fallback)
    $minLen      = 8;
    $reqUpper    = true;
    $reqLower    = true;
    $reqNumber   = true;
    $reqSpecial  = true;

    if ($db !== null) {
        try {
            $res = $db->query(
                "SELECT setting_key, setting_value FROM site_settings
                 WHERE setting_key IN ('pw_min_length','pw_require_upper','pw_require_lower','pw_require_number','pw_require_special')"
            );
            if ($res) {
                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    switch ($row['setting_key']) {
                        case 'pw_min_length':     $minLen     = max(6, (int)$row['setting_value']); break;
                        case 'pw_require_upper':  $reqUpper   = $row['setting_value'] === '1';       break;
                        case 'pw_require_lower':  $reqLower   = $row['setting_value'] === '1';       break;
                        case 'pw_require_number': $reqNumber  = $row['setting_value'] === '1';       break;
                        case 'pw_require_special':$reqSpecial = $row['setting_value'] === '1';       break;
                    }
                }
            }
        } catch (Throwable $e) {
            // Fallback to defaults if table doesn't exist or query fails
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
        try {
            $res = $db->query(
                "SELECT setting_key, setting_value FROM site_settings
                 WHERE setting_key IN ('pw_min_length','pw_require_upper','pw_require_lower','pw_require_number','pw_require_special')"
            );
            if ($res) {
                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    switch ($row['setting_key']) {
                        case 'pw_min_length':     $rules['min_length']      = max(6, (int)$row['setting_value']); break;
                        case 'pw_require_upper':  $rules['require_upper']   = $row['setting_value'] === '1';       break;
                        case 'pw_require_lower':  $rules['require_lower']   = $row['setting_value'] === '1';       break;
                        case 'pw_require_number': $rules['require_number']  = $row['setting_value'] === '1';       break;
                        case 'pw_require_special':$rules['require_special'] = $row['setting_value'] === '1';       break;
                    }
                }
            }
        } catch (Throwable $e) {
            // Fallback to defaults
        }
    }
    return $rules;
}

/** Product image path for catalog assets, seller uploads, or legacy URL */
function ias_product_image_url(array $p): string
{
    if (!empty($p['image'])) {
        $raw = str_replace('\\', '/', trim((string) $p['image']));
        if (str_starts_with($raw, 'assets/products/')) {
            return '../' . $raw;
        }
        return '../uploads/products/' . basename($raw);
    }
    if (!empty($p['image_url'])) {
        $url = str_replace('\\', '/', trim((string) $p['image_url']));
        if (str_starts_with($url, 'assets/products/')) {
            return '../' . $url;
        }
        return $url;
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

/** Client shop: catalog assets/products paths or seller uploads that exist on disk */
function ias_client_product_image_url(array $p): string
{
    if (empty($p['image']) || !is_string($p['image'])) {
        return '';
    }
    $raw = str_replace('\\', '/', trim($p['image']));
    if ($raw === '' || preg_match('/(no[_-]?image|placeholder|default|demo|mock|fake)/i', basename($raw))) {
        return '';
    }

    // Git-synced catalog images: trust managed paths (avoid per-row filesystem stats on listings)
    if (str_starts_with($raw, 'assets/products/')) {
        $ext = strtolower(pathinfo($raw, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return '';
        }
        if (str_contains($raw, '..')) {
            return '';
        }
        return '../' . $raw;
    }

    $filename = basename($raw);
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
    $s = $orderStatus ?? '';
    if (strcasecmp($s, 'cancelled') === 0 || strcasecmp($s, 'canceled') === 0) {
        return 'Cancelled';
    }
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
        'cancelled' => 'Cancelled',
        'Canceled' => 'Cancelled',
        'Cancelled' => 'Cancelled',
    ];
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
        'accepted'    => 'Order accepted successfully.',
        'order_updated' => 'Order status updated successfully.',
        'cart_added'  => 'Added to cart successfully.',
        'cancel_accepted' => 'Cancellation accepted.',
        'cancel_requested' => 'Cancellation requested.',
        'registered'  => 'Registration successful. Please check your email to activate your account.',
        'logout'      => 'You have been logged out successfully.',
        'login'       => 'Login successful.',
        'error'       => 'Could not complete the action. Please check your input and try again.',
        'stock'       => 'Some items are out of stock. Your cart was updated.',
        'barcode'     => 'Invalid UPC/EAN barcode. Check the digits (UPC-A 12, UPC-E 8, EAN-13 13, EAN-8 8) and the check digit.',
        'barcode_taken' => 'That UPC/EAN barcode is already assigned to another product.',
        'barcode_generated' => 'Barcode generated. Print the label and stick it on the item.',
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
        || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/CLIENT/') !== false
        || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/RETAIL/') !== false
        || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/INVENTORY/') !== false)
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