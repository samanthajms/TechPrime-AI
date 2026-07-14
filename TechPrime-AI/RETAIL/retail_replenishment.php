<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'retail_officer') {
    header("Location: ../login.html");
    exit;
}

function sampleRecommendations() {
    return [
        ['item'=>'16GB DDR5 RAM','stock'=>3,'suggest'=>20],
        ['item'=>'RTX 4080 GPU','stock'=>12,'suggest'=>5],
        ['item'=>'1TB NVMe SSD','stock'=>25,'suggest'=>10],
    ];
}

$recs = sampleRecommendations();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Replenishment Planning | TechPrime AI</title>
    <link rel="stylesheet" href="../retail.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .layout-split { display: grid; grid-template-columns: 1.8fr 1fr; gap: 24px; }
        .forecast-placeholder { min-height: 340px; display: flex; align-items: center; justify-content: center; text-align: center; color: var(--ias-slate); background: var(--ias-surface); border: 1px solid var(--ias-border); border-radius: 18px; padding: 24px; }
        .rec-table { width: 100%; border-collapse: collapse; }
        .rec-table th,
        .rec-table td { padding: 16px 18px; border-bottom: 1px solid #edf2f5; }
        .rec-table th { background: #f7fafb; color: var(--ias-slate); text-transform: uppercase; font-size: 12px; }
        .btn-primary { min-width: 170px; }
        @media (max-width: 1120px) { .layout-split { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php $active = 'replenishment'; include __DIR__ . '/../includes/retail_shell.php'; ?>
    <main class="retail-main">
        <div class="page-header">
            <div>
                <h1 class="page-title">Replenishment Planning</h1>
                <p class="page-subtitle">Use demand signals and stock recommendations to keep shelves stocked the right way.</p>
            </div>
        </div>

        <div class="layout-split">
            <section class="card">
                <div class="section-title">Demand Forecast</div>
                <div class="forecast-placeholder">
                    <div>
                        <div style="font-size:18px;font-weight:800;color:var(--ias-teal);">Forecast graph placeholder</div>
                        <div style="margin-top:10px; color:var(--ias-slate);">Integrate trend charts and AI demand predictions here.</div>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="section-title">Suggested Reorders</div>
                <div class="section-body" style="padding-top:0;">
                    <table class="rec-table">
                        <thead>
                            <tr><th>Item</th><th>Current Stock</th><th>Suggested Qty</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($recs as $r): ?>
                                <tr>
                                    <td><?php echo h($r['item']); ?></td>
                                    <td><?php echo (int)$r['stock']; ?></td>
                                    <td><?php echo (int)$r['suggest']; ?></td>
                                    <td>
                                        <button class="btn btn-primary" type="button" onclick="alert('Proactive reorder created for <?php echo h($r['item']); ?>')">Reorder</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>

<footer class="ias-footer">© 2026 TechPrime AI Retail Center.</footer>
<?php ias_alert_footer(); ?>
</body>
</html>
