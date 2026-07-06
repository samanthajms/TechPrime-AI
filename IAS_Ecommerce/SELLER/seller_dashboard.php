<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    header("Location: ../login.html"); 
    exit;
}

$db = getDbConnection();
$sellerId = (int)$_SESSION['user_id'];

// --- REAL DATA: SALES CHART (12 Months) ---
$salesData = []; $months = [];
for ($i = 11; $i >= 0; $i--) { 
    $month = date('Y-m', strtotime("-$i months"));
    $months[] = date('M', strtotime("-$i months"));
    $query = "SELECT SUM(total) as monthly_total FROM orders o 
              JOIN order_items oi ON o.id = oi.order_id 
              JOIN products p ON oi.product_id = p.id 
              WHERE p.seller_id = ? AND o.created_at LIKE '$month%'";
    $st = $db->prepare($query); $st->bind_param("i", $sellerId);
    $st->execute(); $res = $st->get_result()->fetch_assoc();
    $salesData[] = $res['monthly_total'] ?? 0;
}

// --- REAL DATA: RECENT ACTIVITIES ---
$activityQuery = "SELECT 'Message' as type, u.name as title, m.message as subtitle, m.created_at 
                  FROM messages m JOIN users u ON m.sender_id = u.id 
                  WHERE m.receiver_id = ? ORDER BY m.created_at DESC LIMIT 5";
$stmtAct = $db->prepare($activityQuery);
$stmtAct->bind_param("i", $sellerId);
$stmtAct->execute();
$activities = $stmtAct->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Seller Dashboard | IAS</title>
    <link rel="stylesheet" href="../seller.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --ias-teal: #0998a8;
            --ias-gold: #f5f500;
            --sidebar-gray: #6a969a;
            --bg-gray: #f4f7f6;
        }

        html, body { height: 100%; margin: 0; }
        body { 
            display: flex; 
            flex-direction: column; 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg-gray); 
        }

        /* THEME HEADER */
        .seller-header {
            background: var(--ias-teal);
            padding: 15px 30px;
            border-bottom: 3px solid var(--ias-gold);
        }
        .logo-text { color: var(--ias-gold); font-size: 24px; font-weight: 900; letter-spacing: 1px; }

        .seller-layout { display: flex; flex: 1; overflow: hidden; }

        /* FIXED SIDEBAR */
        .seller-sidebar {
            background: var(--sidebar-gray);
            width: 260px;
            padding-top: 10px;
            display: flex;
            flex-direction: column;
        }

        .sidebar-item {
            background: transparent;
            color: white;
            border: none;
            padding: 15px 25px;
            width: 100%;
            text-align: left;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            transition: 0.2s;
        }

        .sidebar-item:hover, .sidebar-item.active {
            background: rgba(0,0,0,0.1);
            color: var(--ias-gold);
        }

        .logout-btn { background: #b22222 !important; margin-top: auto; border-bottom: none; }

        /* MAIN CONTENT AREA */
        .seller-main { padding: 30px; flex: 1; overflow-y: auto; }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1.8fr 1fr;
            gap: 25px;
            max-width: 1400px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px dashed var(--ias-teal);
        }

        .card h2 { font-size: 20px; margin-top: 0; margin-bottom: 20px; color: #111; font-weight: 800; }

        /* ACTIVITY LIST */
        .act-row { padding: 15px 0; border-bottom: 1px solid #eee; }
        .act-row:last-child { border-bottom: none; }
        .act-title { font-weight: 700; color: var(--ias-teal); font-size: 14px; }
        .act-sub { font-size: 13px; color: #555; margin: 4px 0; line-height: 1.4; }
        .act-time { font-size: 11px; color: #999; text-transform: uppercase; }

        /* FOOTER */
        .ias-footer {
            background: var(--ias-teal);
            color: white;
            padding: 15px 30px;
            font-size: 14px;
            font-weight: 500;
        }
    </style>
</head>
<body>

<header class="seller-header">
    <div class="logo-text">IAS SELLER CENTER</div>
</header>

<div class="seller-layout">
    <aside class="seller-sidebar">
        <button class="sidebar-item active">📊 Dashboard</button>
        <button class="sidebar-item" onclick="location.href='seller_products.php'">📦 My Products</button>
        <button class="sidebar-item" onclick="location.href='seller_orders.php'">📜 Orders</button>
        <button class="sidebar-item" onclick="location.href='seller_messages.php'">💬 Messages</button>
        <button class="sidebar-item" onclick="location.href='seller_settings.php'">⚙️ Settings</button>
        <button class="sidebar-item logout-btn" onclick="location.href='../logout.php'">🚪 Logout</button>
    </aside>

    <main class="seller-main">
        <div class="dashboard-grid">
            <section class="card">
                <h2>Monthly Sales Report</h2>
                <div style="height: 380px;">
                    <canvas id="salesChart"></canvas>
                </div>
            </section>

            <section class="card">
                <h2>Recent Notifications</h2>
                <div class="rows-list">
                    <?php while($row = $activities->fetch_assoc()): ?>
                        <div class="act-row">
                            <div class="act-title">📩 New from <?php echo h($row['title']); ?></div>
                            <div class="act-sub">"<?php echo h(substr($row['subtitle'], 0, 80)); ?>..."</div>
                            <div class="act-time"><?php echo date('M d, Y • g:i a', strtotime($row['created_at'])); ?></div>
                        </div>
                    <?php endwhile; ?>
                    
                    <?php if($activities->num_rows == 0): ?>
                        <div style="text-align:center; padding: 40px 0;">
                            <span style="font-size: 40px;">🔔</span>
                            <p style="color:#999; margin-top:10px;">No new notifications yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>

<footer class="ias-footer">
    © 2026 IAS E-Commerce Seller Center. All Rights Reserved.
</footer>

<script>
const ctx = document.getElementById('salesChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [{
            label: 'Total Revenue (PHP)',
            data: <?php echo json_encode($salesData); ?>,
            borderColor: '#0998a8',
            backgroundColor: 'rgba(9, 152, 168, 0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 6,
            pointHoverRadius: 8,
            pointBackgroundColor: '#0998a8',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            borderWidth: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { 
            legend: { display: false },
            tooltip: {
                backgroundColor: '#0998a8',
                titleFont: { size: 14, weight: 'bold' },
                padding: 12,
                displayColors: false,
                callbacks: {
                    label: function(context) {
                        return '₱ ' + context.parsed.y.toLocaleString();
                    }
                }
            }
        },
        scales: { 
            y: { 
                beginAtZero: true, 
                grid: { color: '#f0f0f0' },
                ticks: { 
                    callback: value => '₱' + value.toLocaleString()
                }
            },
            x: { grid: { display: false } }
        }
    }
});
</script>
<?php ias_alert_footer(); ?>
</body>
</html>