<?php
/**
 * Shared EasyPC staff layout (Admin / Retail / Associate / Courier).
 * Design tokens match CLIENT/index.php.
 */

if (!function_exists('staff_nav_for_role')) {
    function staff_nav_for_role(string $role): array
    {
        switch ($role) {
            case 'admin':
                return [
                    ['key' => 'dashboard', 'href' => 'admin_dashboard.php', 'label' => 'Dashboard', 'icon' => 'fa-tachometer-alt'],
                    ['key' => 'users', 'href' => 'manage_users.php', 'label' => 'Manage Users', 'icon' => 'fa-users'],
                    ['key' => 'logs', 'href' => 'view_logs.php', 'label' => 'Activity Logs', 'icon' => 'fa-clipboard-list'],
                    ['key' => 'profile', 'href' => 'admin_profile.php', 'label' => 'My Profile', 'icon' => 'fa-user'],
                    ['key' => 'settings', 'href' => 'admin_settings.php', 'label' => 'Settings', 'icon' => 'fa-cog'],
                ];
            case 'retail_officer':
                return [
                    ['key' => 'dashboard', 'href' => 'retail_dashboard.php', 'label' => 'Dashboard', 'icon' => 'fa-tachometer-alt'],
                    ['key' => 'products', 'href' => 'retail_products.php', 'label' => 'My Products', 'icon' => 'fa-box'],
                    ['key' => 'orders', 'href' => 'retail_orders.php', 'label' => 'Orders', 'icon' => 'fa-shopping-bag'],
                    ['key' => 'messages', 'href' => 'retail_messages.php', 'label' => 'Messages', 'icon' => 'fa-comments'],
                    ['key' => 'reviews', 'href' => 'retail_reviews.php', 'label' => 'Reviews', 'icon' => 'fa-star'],
                    ['key' => 'profile', 'href' => 'retail_profile.php', 'label' => 'My Profile', 'icon' => 'fa-user'],
                    ['key' => 'settings', 'href' => 'retail_settings.php', 'label' => 'Settings', 'icon' => 'fa-cog'],
                ];
            case 'technician':
            case 'inventory_custodian':
                return [
                    ['key' => 'dashboard', 'href' => 'associate_dashboard.php', 'label' => 'Dashboard', 'icon' => 'fa-tachometer-alt'],
                    ['key' => 'orders', 'href' => 'associate_orders.php', 'label' => 'Orders', 'icon' => 'fa-shopping-cart'],
                    ['key' => 'assign', 'href' => 'associate_assign.php', 'label' => 'Delivery Assignment', 'icon' => 'fa-truck'],
                    ['key' => 'history', 'href' => 'associate_history.php', 'label' => 'History', 'icon' => 'fa-history'],
                    ['key' => 'profile', 'href' => 'associate_profile.php', 'label' => 'My Profile', 'icon' => 'fa-user'],
                ];
            case 'courier':
                return [
                    ['key' => 'dashboard', 'href' => 'courier_dashboard.php', 'label' => 'Dashboard', 'icon' => 'fa-tachometer-alt'],
                    ['key' => 'orders', 'href' => 'courier_orders.php', 'label' => 'Orders', 'icon' => 'fa-shopping-cart'],
                    ['key' => 'assign', 'href' => 'courier_assign.php', 'label' => 'Assignments', 'icon' => 'fa-truck'],
                    ['key' => 'history', 'href' => 'courier_history.php', 'label' => 'History', 'icon' => 'fa-history'],
                ];
            default:
                return [];
        }
    }
}

if (!function_exists('staff_role_label')) {
    function staff_role_label(string $role): string
    {
        $map = [
            'admin' => 'Admin',
            'retail_officer' => 'Retail Officer',
            'technician' => 'Technician',
            'inventory_custodian' => 'Inventory Custodian',
            'courier' => 'Courier',
        ];
        return $map[$role] ?? 'Staff';
    }
}

if (!function_exists('staff_css_href')) {
    function staff_css_href(): string
    {
        // Resolve relative path from current script dir depth
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#/(ADMIN|RETAIL|ASSOCIATE|courier)$#', $scriptDir)) {
            return '../includes/staff_shared.css';
        }
        return 'includes/staff_shared.css';
    }
}

if (!function_exists('staff_logo_href')) {
    function staff_logo_href(): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#/(ADMIN|RETAIL|ASSOCIATE|courier)$#', $scriptDir)) {
            return '../assets/easypc-logo-transparent.png';
        }
        return 'assets/easypc-logo-transparent.png';
    }
}

if (!function_exists('staff_logout_href')) {
    function staff_logout_href(): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#/(ADMIN|RETAIL|ASSOCIATE|courier)$#', $scriptDir)) {
            return '../logout.php';
        }
        return 'logout.php';
    }
}

/**
 * Begin a staff page shell.
 *
 * @param array{
 *   title:string,
 *   active:string,
 *   heading:string,
 *   subtitle?:string,
 *   role?:string,
 *   extra_head?:string,
 *   nav?:array
 * } $opts
 */
if (!function_exists('staff_page_start')) {
    function staff_page_start(array $opts): void
    {
        $role = $opts['role'] ?? ($_SESSION['role'] ?? 'admin');
        $title = $opts['title'] ?? 'EasyPC';
        $active = $opts['active'] ?? '';
        $heading = $opts['heading'] ?? $title;
        $subtitle = $opts['subtitle'] ?? '';
        $extraHead = $opts['extra_head'] ?? '';
        $nav = $opts['nav'] ?? staff_nav_for_role($role);
        $userName = $_SESSION['name'] ?? 'User';
        $initials = strtoupper(substr($userName, 0, 1));
        $roleLabel = staff_role_label($role);
        $css = staff_css_href();
        $logo = staff_logo_href();
        $logout = staff_logout_href();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($title); ?> — EasyPC</title>
    <link rel="stylesheet" href="<?php echo h($css); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <?php echo $extraHead; ?>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-brand">
        <img src="<?php echo h($logo); ?>" alt="EasyPC" class="ep-logo-img brand-logo">
        <div>
            <div class="brand-text">EasyPC</div>
            <div class="brand-sub"><?php echo h($roleLabel); ?></div>
        </div>
    </div>
    <nav>
        <?php foreach ($nav as $item): ?>
            <a href="<?php echo h($item['href']); ?>" class="<?php echo ($item['key'] === $active) ? 'active' : ''; ?>">
                <i class="fas <?php echo h($item['icon']); ?>"></i>
                <span><?php echo h($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <a href="<?php echo h($logout); ?>"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h2><?php echo h($heading); ?></h2>
            <?php if ($subtitle !== ''): ?>
                <div class="breadcrumb"><?php echo h($subtitle); ?></div>
            <?php endif; ?>
        </div>
        <div class="topbar-right">
            <div class="admin-badge user-badge">
                <div class="avatar"><?php echo h($initials); ?></div>
                <?php echo h($userName); ?>
            </div>
        </div>
    </div>
    <div class="page-content">
        <?php
    }
}

if (!function_exists('staff_page_end')) {
    function staff_page_end(string $extraScripts = ''): void
    {
        ?>
    </div><!-- /.page-content -->
</div><!-- /.main -->
<script src="<?php
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        echo preg_match('#/(ADMIN|RETAIL|ASSOCIATE|courier)$#', $scriptDir)
            ? '../includes/ui_alerts.js'
            : 'includes/ui_alerts.js';
    ?>"></script>
<?php echo $extraScripts; ?>
</body>
</html>
        <?php
    }
}
