<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

date_default_timezone_set('Asia/Manila');

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];

$cnt = [];
$cnt['clients'] = $db->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetch_row()[0];
$cnt['retail_officer'] = $db->query("SELECT COUNT(*) FROM users WHERE role='retail_officer'")->fetch_row()[0];
$cnt['technician'] = $db->query("SELECT COUNT(*) FROM users WHERE role='technician'")->fetch_row()[0];
$cnt['inventory_custodian'] = $db->query("SELECT COUNT(*) FROM users WHERE role='inventory_custodian'")->fetch_row()[0];
$cnt['orders'] = $db->query("SELECT COUNT(*) FROM orders")->fetch_row()[0];

$salesData = [];
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $months[] = date('M', strtotime("-$i months"));
    $query = "SELECT SUM(total) as monthly_total FROM orders o
              JOIN order_items oi ON o.id = oi.order_id
              JOIN products p ON oi.product_id = p.id
              WHERE p.seller_id = ? AND o.created_at LIKE '$month%'";
    $st = $db->prepare($query);
    $st->bind_param('i', $admin_id);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $salesData[] = $res['monthly_total'] ?? 0;
}

// Delivery cards (same metrics as former associate dashboard, scoped to this seller's orders)
$totalQ = $db->prepare(
    "SELECT COUNT(DISTINCT s.id) FROM shipments s
     INNER JOIN orders o ON s.order_id = o.id
     WHERE EXISTS (
        SELECT 1 FROM order_items oi INNER JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = o.id AND p.seller_id = ?
     )"
);
$totalQ->bind_param('i', $admin_id);
$totalQ->execute();
$total = (int)($totalQ->get_result()->fetch_row()[0] ?? 0);
$totalQ->close();

$pendingQ = $db->prepare(
    "SELECT COUNT(DISTINCT s.id) FROM shipments s
     INNER JOIN orders o ON s.order_id = o.id
     WHERE s.shipment_status != 'delivered'
     AND EXISTS (
        SELECT 1 FROM order_items oi INNER JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = o.id AND p.seller_id = ?
     )"
);
$pendingQ->bind_param('i', $admin_id);
$pendingQ->execute();
$pending = (int)($pendingQ->get_result()->fetch_row()[0] ?? 0);
$pendingQ->close();

$doneQ = $db->prepare(
    "SELECT COUNT(DISTINCT s.id) FROM shipments s
     INNER JOIN orders o ON s.order_id = o.id
     WHERE s.shipment_status = 'delivered'
     AND EXISTS (
        SELECT 1 FROM order_items oi INNER JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = o.id AND p.seller_id = ?
     )"
);
$doneQ->bind_param('i', $admin_id);
$doneQ->execute();
$done = (int)($doneQ->get_result()->fetch_row()[0] ?? 0);
$doneQ->close();

logActivity($db, $admin_id, 'view_dashboard', "Admin viewed dashboard");

staff_page_start([
    'role' => 'admin',
    'title' => 'Admin Dashboard',
    'active' => 'dashboard',
    'heading' => 'System Administration',
    'subtitle' => 'Dashboard overview',
]);
?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Orders</div>
                <div class="stat-num"><?php echo (int)$cnt['orders']; ?></div>
                <div class="stat-icon"><i class="fas fa-shopping-bag"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Clients</div>
                <div class="stat-num"><?php echo (int)$cnt['clients']; ?></div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Retail Officers</div>
                <div class="stat-num"><?php echo (int)$cnt['retail_officer']; ?></div>
                <div class="stat-icon"><i class="fas fa-store"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Inventory Custodian</div>
                <div class="stat-num"><?php echo (int)$cnt['inventory_custodian']; ?></div>
                <div class="stat-icon"><i class="fas fa-warehouse"></i></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-chart-line"></i></span> Monthly Sales Report</h3>
                    <div class="card-subtitle">Revenue for the last 12 months</div>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-wrap">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
        </div>
<?php
$monthsJson = json_encode($months);
$salesJson = json_encode($salesData);
staff_page_end(<<<SCRIPTS
<script>
const ctx = document.getElementById('salesChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: {$monthsJson},
        datasets: [{
            label: 'Total Revenue (PHP)',
            data: {$salesJson},
            borderColor: '#61b337',
            backgroundColor: 'rgba(97, 179, 55, 0.12)',
            fill: true,
            tension: 0.4,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: '#61b337',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            borderWidth: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#61b337',
                titleFont: { size: 13, weight: 'bold', family: 'Poppins' },
                bodyFont: { family: 'Poppins' },
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
                    font: { family: 'Poppins' },
                    callback: value => '₱' + value.toLocaleString()
                }
            },
            x: {
                grid: { display: false },
                ticks: { font: { family: 'Poppins' } }
            }
        }
    }
});
</script>
SCRIPTS);
?>
<?php staff_page_end(); ?>
