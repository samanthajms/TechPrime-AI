<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/staff_layout.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'retail_officer') {
    header('Location: ../login.php');
    exit;
}

$db = getDbConnection();
$retailId = (int)$_SESSION['user_id'];

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
    $st->bind_param('i', $retailId);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $salesData[] = $res['monthly_total'] ?? 0;
}

$activityQuery = "SELECT 'Message' as type, u.name as title, m.message as subtitle, m.created_at
                  FROM messages m JOIN users u ON m.sender_id = u.id
                  WHERE m.receiver_id = ? ORDER BY m.created_at DESC LIMIT 5";
$stmtAct = $db->prepare($activityQuery);
$stmtAct->bind_param('i', $retailId);
$stmtAct->execute();
$activities = $stmtAct->get_result();

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'Retail Dashboard',
    'active' => 'dashboard',
    'heading' => 'Retail Dashboard',
    'subtitle' => 'Sales performance and recent activity',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>',
]);
?>

        <div class="dash-grid">
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

            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-bell"></i></span> Recent Notifications</h3>
                        <div class="card-subtitle">Latest customer messages</div>
                    </div>
                </div>
                <div class="card-body">
                    <?php
                    $hasRows = false;
                    while ($row = $activities->fetch_assoc()):
                        $hasRows = true;
                    ?>
                        <div class="act-row">
                            <div class="act-title">New from <?php echo h($row['title']); ?></div>
                            <div class="act-sub">"<?php echo h(substr($row['subtitle'], 0, 80)); ?>…"</div>
                            <div class="act-time"><?php echo date('M d, Y • g:i a', strtotime($row['created_at'])); ?></div>
                        </div>
                    <?php endwhile; ?>
                    <?php if (!$hasRows): ?>
                        <div class="empty-state">
                            <i class="fas fa-bell" style="font-size:28px;opacity:.35;margin-bottom:8px;"></i>
                            <p>No new notifications yet.</p>
                        </div>
                    <?php endif; ?>
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
