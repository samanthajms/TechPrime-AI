<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/pos_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('cashier');

$uid = (int)$_SESSION['user_id'];
$saleId = (int)($_GET['id'] ?? 0);
$sale = $saleId > 0 ? pos_get_sale_for_cashier($db, $saleId, $uid) : null;
$autoPrint = $sale !== null && isset($_GET['print']);
/* ?frame=1: bare receipt for the hidden print frame (receipt_print.js), so
   Print / Reprint print from the current tab instead of opening a new one */
$frameMode = isset($_GET['frame']);

$receiptHtml = '';
if ($sale) {
    $units = 0;
    foreach ($sale['items'] as $it) {
        $units += (int)$it['quantity'];
    }
    ob_start();
    ?>
        <article class="receipt" aria-label="Warranty receipt <?php echo h($sale['invoice_no']); ?>">
            <header class="r-head">
                <img class="r-logo" src="../assets/logo-color.png" alt="EasyPC" width="168" height="36">
                <div class="r-tag">TECH IT EASY</div>
                <div class="r-co">TECH IT EASY INC</div>
                <div>One Oasis Branch</div>
                <div>Rosario, Pasig City</div>
                <div>www.easypc.com.ph</div>
            </header>
            <hr>
            <div class="r-meta">
                <div class="r-title">Warranty Receipt</div>
                <div><?php echo h(pos_format_datetime($sale['created_at'], 'Y-m-d h:i A')); ?></div>
                <div>Sale ID # <?php echo h($sale['invoice_no']); ?></div>
                <div>Employee: <?php echo h($sale['cashier_name']); ?></div>
                <?php if ($sale['status'] === 'voided'): ?>
                    <div class="r-void">*** VOIDED ***</div>
                <?php endif; ?>
            </div>
            <hr>
            <div class="r-party">
                <div><span>Customer</span><b>:</b> Walk-in</div>
                <div><span>Address</span><b>:</b></div>
            </div>
            <hr>
            <table class="r-lines">
                <thead>
                    <tr>
                        <th class="r-sku">Phone Number</th>
                        <th></th>
                        <th class="num">Price</th>
                        <th class="qty">Qty</th>
                        <th class="num">Total</th>
                    </tr>
                    <tr>
                        <th class="r-sku">SKU</th>
                        <th class="r-desc">Product Description</th>
                        <th></th>
                        <th></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sale['items'] as $it): ?>
                    <tr>
                        <td class="r-sku"><?php echo h((string)($it['sku'] ?? '')); ?></td>
                        <td class="r-desc">
                            <strong><?php echo h($it['product_name']); ?></strong>
                            <?php if (!empty($it['barcode'])): ?>
                                <div>UPC#: <?php echo h((string)$it['barcode']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?php echo h(number_format((float)$it['unit_price'], 2)); ?></td>
                        <td class="qty"><?php echo (int)$it['quantity']; ?></td>
                        <td class="num"><?php echo h(number_format((float)$it['line_total'], 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="r-foot">
                <div class="r-remarks">Remarks:</div>
                <table class="r-totals">
                    <tr><th>Sub Total:</th><td><?php echo h(number_format((float)$sale['subtotal'], 2)); ?></td></tr>
                    <tr><th>Discount:</th><td>0.00</td></tr>
                    <tr class="r-grand"><th>Total:</th><td><?php echo h(number_format((float)$sale['total'], 2)); ?></td></tr>
                    <tr><th>Numbers of items sold:</th><td><?php echo (int)$units; ?></td></tr>
                    <tr>
                        <th>Payment Method:</th>
                        <td><?php
                            echo h(pos_payment_label((string)$sale['payment_method']));
                            if ($sale['payment_method'] !== 'cash' && (string)$sale['payment_ref'] !== '') {
                                echo ' · ' . h((string)$sale['payment_ref']);
                            }
                        ?></td>
                    </tr>
                    <tr><th>Change Due:</th><td><?php echo h(number_format((float)$sale['change_due'], 2)); ?></td></tr>
                </table>
            </div>
        </article>
    <?php
    $receiptHtml = ob_get_clean();
}

if ($frameMode) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo h($sale ? 'Receipt ' . $sale['invoice_no'] : 'Receipt not found'); ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@500;600;700&amp;family=Fira+Sans:wght@700;800&amp;display=swap">
    <link rel="stylesheet" href="cashier.css?v=15">
</head>
<body class="cx-app cx-receipt-frame">
<?php if ($sale): ?>
<?php echo $receiptHtml; ?>
<?php else: ?>
<p data-receipt-missing>This receipt does not exist or belongs to another cashier.</p>
<?php endif; ?>
</body>
</html>
    <?php
    exit;
}

$receiptActions = '<div class="receipt-toolbar">'
    . ($sale ? '<button type="button" class="cx-btn cx-btn-primary" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print receipt</button>' : '')
    . '<a href="cashier_pos.php" class="cx-btn cx-btn-outline"><i class="ph ph-cash-register" aria-hidden="true"></i> New sale</a>'
    . '<a href="cashier_dashboard.php" class="cx-btn cx-btn-outline"><i class="ph ph-squares-four" aria-hidden="true"></i> Dashboard</a>'
    . '</div>';

staff_page_start([
    'role' => 'cashier',
    'title' => $sale ? 'Receipt ' . $sale['invoice_no'] : 'Receipt',
    'active' => 'dashboard',
    'heading' => 'Receipt',
    'subtitle' => $sale ? 'Invoice ' . $sale['invoice_no'] . ' · ' . pos_format_datetime($sale['created_at']) : 'Receipt not found',
    'actions' => $receiptActions,
]);
?>
        <?php if (!$sale): ?>
            <div class="cx-empty receipt-missing">
                <span class="cx-empty-icon is-bad" aria-hidden="true"><i class="ph ph-receipt"></i></span>
                <strong>Receipt not found</strong>
                <span>This receipt does not exist or belongs to another cashier.</span>
            </div>
        <?php else: ?>
<?php echo $receiptHtml; ?>
        <?php endif; ?>
<?php
staff_page_end($autoPrint
    ? '<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 250); });</script>'
    : '');
