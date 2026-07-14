<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'retail_officer') {
    header("Location: ../login.html"); 
    exit;
}

$db = getDbConnection();
$retailId = (int)$_SESSION['user_id'];

// --- REAL DATA: SALES CHART (12 Months) ---
$salesData = []; $months = [];
for ($i = 11; $i >= 0; $i--) { 
    $month = date('Y-m', strtotime("-$i months"));
    $months[] = date('M', strtotime("-$i months"));
    $query = "SELECT SUM(total) as monthly_total FROM orders o 
              JOIN order_items oi ON o.id = oi.order_id 
              JOIN products p ON oi.product_id = p.id 
              WHERE p.seller_id = ? AND o.created_at LIKE '$month%'";
    $st = $db->prepare($query); $st->bind_param("i", $retailId);
    $st->execute(); $res = $st->get_result()->fetch_assoc();
    $salesData[] = $res['monthly_total'] ?? 0;
}

// --- REAL DATA: RECENT ACTIVITIES ---
$activityQuery = "SELECT 'Message' as type, u.name as title, m.message as subtitle, m.created_at 
                  FROM messages m JOIN users u ON m.sender_id = u.id 
                  WHERE m.receiver_id = ? ORDER BY m.created_at DESC LIMIT 5";
$stmtAct = $db->prepare($activityQuery);
$stmtAct->bind_param("i", $retailId);
$stmtAct->execute();
$activities = $stmtAct->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retail Officer Dashboard | TechPrime AI</title>
    <link rel="stylesheet" href="../retail.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-grid { max-width: 1400px; }
        .dashboard-grid canvas { width: 100% !important; height: 100% !important; }
    </style>
</head>
<body>

<?php $active = 'dashboard'; include __DIR__ . '/../includes/retail_shell.php'; ?>
    <main class="retail-main">
        <div class="page-header">
            <div>
                <h1 class="page-title">Retail Officer Dashboard</h1>
                <p class="page-subtitle">Monitor sales performance, customer alerts, and AI-driven insights in one unified view.</p>
            </div>
        </div>

        <div class="dashboard-grid">
            <section class="card">
                <div class="section-title">Monthly Sales Report</div>
                <div class="section-body" style="min-height: 420px; padding: 0;">
                    <canvas id="salesChart"></canvas>
                </div>
            </section>

            <section class="card">
                <div class="section-title">Recent Notifications</div>
                <div class="section-body">
                    <?php while($row = $activities->fetch_assoc()): ?>
                        <div class="act-row">
                            <div class="act-title">📩 New from <?php echo h($row['title']); ?></div>
                            <div class="act-sub">"<?php echo h(substr($row['subtitle'], 0, 80)); ?>..."</div>
                            <div class="act-time"><?php echo date('M d, Y • g:i a', strtotime($row['created_at'])); ?></div>
                        </div>
                    <?php endwhile; ?>

                    <?php if($activities->num_rows == 0): ?>
                        <div style="text-align:center; padding: 40px 0; color: var(--ias-slate);">
                            <span style="font-size: 42px;">🔔</span>
                            <p style="margin-top: 14px;">No new notifications yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>

<footer class="ias-footer">© 2026 TechPrime AI Retail Center.</footer>

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
