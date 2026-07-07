<?php
/**
 * Unified courier layout shell (sidebar, topbar, base styles).
 * Use courier_page_start() / courier_page_end() on every courier page.
 */

function courier_page_start(
    string $pageTitle,
    string $activeNav,
    string $topbarTitle,
    string $topbarSubtitle = '',
    string $extraStyles = ''
): void {
    $navItems = [
        'dashboard' => ['href' => 'courier_dashboard.php', 'icon' => '📊', 'label' => 'Dashboard'],
        'orders'    => ['href' => 'courier_orders.php',    'icon' => '🛒', 'label' => 'Orders'],
        'assign'    => ['href' => 'courier_assign.php',    'icon' => '🚚', 'label' => 'Delivery Assignment'],
        'history'   => ['href' => 'courier_history.php',   'icon' => '📜', 'label' => 'History'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($pageTitle); ?> - IAS Courier</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            color: #333;
            min-height: 100vh;
        }

        .courier-sidebar {
            width: 300px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: #0998a8;
            color: #fff;
            display: flex;
            flex-direction: column;
            padding: 20px 0 0;
            z-index: 100;
            overflow-y: auto;
        }

        .courier-sidebar .brand {
            padding: 8px 24px 24px;
            text-align: center;
            color: #f5f500;
            font-weight: 800;
            font-size: 26px;
            letter-spacing: 2px;
            line-height: 1.2;
        }

        .courier-sidebar .nav-label {
            padding: 4px 24px 8px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.65);
            font-weight: 700;
        }

        .courier-sidebar .nav-list {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .courier-sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 24px;
            color: #fff;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.3;
            border-left: 4px solid transparent;
            transition: background 0.2s ease;
        }

        .courier-sidebar .nav-link .nav-icon {
            width: 22px;
            text-align: center;
            flex-shrink: 0;
            font-size: 16px;
        }

        .courier-sidebar .nav-link .nav-text {
            flex: 1;
            text-align: left;
        }

        .courier-sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.12);
        }

        .courier-sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            border-left-color: #f5f500;
        }

        .courier-sidebar .logout-link {
            margin-top: auto;
            border-top: 1px solid rgba(255, 255, 255, 0.25);
        }

        .courier-main {
            margin-left: 300px;
            min-height: 100vh;
            padding: 20px;
            width: calc(100% - 300px);
            max-width: 100%;
            overflow-x: hidden;
        }

        .courier-topbar {
            background: #fff;
            padding: 15px 25px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 8px;
            gap: 16px;
            flex-wrap: wrap;
        }

        .courier-topbar h2 {
            margin: 0;
            font-size: 20px;
            color: #2c3e50;
            font-weight: 700;
        }

        .courier-topbar .topbar-sub {
            font-weight: 600;
            color: #555;
            font-size: 14px;
            text-align: right;
        }

        .courier-content {
            width: 100%;
        }

        .card {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }

        .btn {
            padding: 8px 14px;
            border: none;
            border-radius: 6px;
            color: #fff;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            background: #0998a8;
            display: inline-block;
            transition: background 0.2s;
        }

        .btn:hover { background: #076f7d; }

        .btn-outline {
            background: transparent;
            border: 1px solid #0998a8;
            color: #0998a8;
        }

        .btn-outline:hover {
            background: #0998a8;
            color: #fff;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 14px 18px;
            border-radius: 8px;
            border: 1px solid #c3e6cb;
            margin-bottom: 16px;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .courier-main { padding: 16px; }
            .courier-topbar { padding: 12px 16px; }
        }

        <?php echo $extraStyles; ?>
    </style>
</head>
<body>

<aside class="courier-sidebar">
    <div class="brand">IAS</div>
    <span class="nav-label">Navigation</span>
    <nav class="nav-list" aria-label="Courier navigation">
        <?php foreach ($navItems as $key => $item): ?>
        <a href="<?php echo h($item['href']); ?>"
           class="nav-link<?php echo $activeNav === $key ? ' active' : ''; ?>">
            <span class="nav-icon"><?php echo $item['icon']; ?></span>
            <span class="nav-text"><?php echo h($item['label']); ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <a href="../logout.php" class="nav-link logout-link">
        <span class="nav-icon">🚪</span>
        <span class="nav-text">Logout</span>
    </a>
</aside>

<div class="courier-main">
    <header class="courier-topbar">
        <h2><?php echo h($topbarTitle); ?></h2>
        <?php if ($topbarSubtitle !== ''): ?>
        <div class="topbar-sub"><?php echo h($topbarSubtitle); ?></div>
        <?php endif; ?>
    </header>
    <div class="courier-content">
    <?php
}

function courier_page_end(): void
{
    ?>
    </div>
</div>

</body>
</html>
    <?php
}
