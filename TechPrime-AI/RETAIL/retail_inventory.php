<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'retail_officer') {
    header("Location: ../login.html");
    exit;
}

$retailId = (int)$_SESSION['user_id'];

function sampleInventory() {
    return [
        ['sku' => 'TP-1001', 'name' => 'RTX 4080 GPU', 'category' => 'Graphics', 'stock' => 12, 'safety' => 'OK'],
        ['sku' => 'TP-2002', 'name' => '16GB DDR5 RAM', 'category' => 'Memory', 'stock' => 3, 'safety' => 'LOW'],
        ['sku' => 'TP-3003', 'name' => '1TB NVMe SSD', 'category' => 'Storage', 'stock' => 25, 'safety' => 'OK'],
    ];
}

$items = sampleInventory();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory & Barcodes | TechPrime AI</title>
    <link rel="stylesheet" href="../retail.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .inventory-table { width: 100%; border-collapse: collapse; }
        .inventory-table th,
        .inventory-table td { padding: 16px 18px; border-bottom: 1px solid #edf2f5; }
        .inventory-table th { background: #f7fafb; color: var(--ias-slate); text-transform: uppercase; font-size: 12px; }
        .badge-ok { background: rgba(10, 166, 120, 0.12); color: var(--ias-success); }
        .badge-low { background: rgba(240, 140, 0, 0.14); color: var(--ias-warning); }
        .modal { position: fixed; inset: 0; background: rgba(12, 27, 33, 0.35); display: none; align-items: center; justify-content: center; z-index: 100; }
        .modal.open { display: flex; }
        .modal-card { width: min(520px, 100%); background: white; border-radius: 20px; padding: 26px; box-shadow: 0 24px 60px rgba(5, 22, 31, 0.16); }
        .modal-card input { width: 100%; padding: 14px 16px; border-radius: 12px; border: 1px solid var(--ias-border); }
    </style>
</head>
<body>

<?php $active = 'inventory'; include __DIR__ . '/../includes/retail_shell.php'; ?>
    <main class="retail-main">
        <div class="page-header">
            <div>
                <h1 class="page-title">Inventory & Barcodes</h1>
                <p class="page-subtitle">Track inventory, scan barcodes, and monitor stock health from a modern AI retail workspace.</p>
            </div>
            <div class="page-actions">
                <button class="btn btn-primary" id="scanBtn">🔍 Scan Barcode</button>
            </div>
        </div>

        <section class="card">
            <div class="section-title">Product Inventory</div>
            <div class="section-body">
                <div style="overflow-x:auto;">
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Stock Level</th>
                                <th>Safety Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($items as $it): ?>
                                <tr>
                                    <td><?php echo h($it['sku']); ?></td>
                                    <td><?php echo h($it['name']); ?></td>
                                    <td><?php echo h($it['category']); ?></td>
                                    <td><?php echo (int)$it['stock']; ?></td>
                                    <td><span class="badge <?php echo $it['safety'] === 'LOW' ? 'badge-low' : 'badge-ok'; ?>"><?php echo $it['safety'] === 'LOW' ? 'Low Stock' : 'OK'; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<div class="modal" id="scanModal">
    <div class="modal-card">
        <h3>Scan / Enter Barcode</h3>
        <div class="form-group">
            <input id="barcodeInput" type="text" placeholder="Enter UPC/EAN or scan with device" />
        </div>
        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button class="btn btn-primary" id="scanSubmit">Add / Lookup</button>
            <button class="btn btn-secondary" id="scanClose">Close</button>
        </div>
    </div>
</div>

<script>
const scanModal = document.getElementById('scanModal');
const scanInput = document.getElementById('barcodeInput');
document.getElementById('scanBtn').addEventListener('click', () => {
    scanModal.classList.add('open');
    scanInput.focus();
});
document.getElementById('scanClose').addEventListener('click', () => scanModal.classList.remove('open'));
document.getElementById('scanSubmit').addEventListener('click', () => {
    const value = scanInput.value.trim();
    if (!value) {
        return alert('Enter a barcode string.');
    }
    alert('Simulated scan received: ' + value + '\n(Integrate hardware API here)');
    scanModal.classList.remove('open');
});
</script>
<?php ias_alert_footer(); ?>
</body>
</html>
