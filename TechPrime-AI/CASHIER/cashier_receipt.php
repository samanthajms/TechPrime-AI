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

staff_page_start([
    'role' => 'cashier',
    'title' => $sale ? 'Receipt ' . $sale['invoice_no'] : 'Receipt',
    'active' => 'dashboard',
    'heading' => 'Receipt',
    'subtitle' => $sale ? 'Invoice ' . $sale['invoice_no'] : 'Receipt not found',
    'extra_head' => '<link rel="stylesheet" href="cashier.css?v=3">',
]);
?>
        <div class="receipt-toolbar">
            <?php if ($sale): ?>
            <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Receipt</button>
            <?php endif; ?>
            <a href="cashier_pos.php" class="btn btn-outline"><i class="fas fa-cash-register"></i> New Sale</a>
            <a href="cashier_dashboard.php" class="btn btn-outline"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        </div>

        <?php if (!$sale): ?>
            <div class="alert alert-error" style="max-width:520px;margin:0 auto;">
                <i class="fas fa-times-circle"></i>
                <div>This receipt does not exist or belongs to another cashier.</div>
            </div>
        <?php else:
            $units = 0;
            foreach ($sale['items'] as $it) {
                $units += (int)$it['quantity'];
            }
            ?>
        <article class="receipt" aria-label="Receipt <?php echo h($sale['invoice_no']); ?>">
            <div class="r-center">
                <div class="r-store">EasyPC</div>
                <div>One Oasis Branch</div>
                <div>Rosario, Pasig City</div>
                <div class="r-title">SALES INVOICE</div>
            </div>
            <hr>
            <div class="r-row"><span>Invoice No.</span><span><?php echo h($sale['invoice_no']); ?></span></div>
            <div class="r-row"><span>Date</span><span><?php echo h(pos_format_datetime($sale['created_at'], 'M j, Y g:i:s A')); ?></span></div>
            <div class="r-row"><span>Cashier</span><span><?php echo h($sale['cashier_name']); ?></span></div>
            <?php if ($sale['status'] === 'voided'): ?>
                <hr><div class="r-void">*** VOIDED ***</div>
            <?php endif; ?>
            <hr>
            <?php foreach ($sale['items'] as $it): ?>
                <div class="r-item-name"><?php echo h($it['product_name']); ?></div>
                <div class="r-row">
                    <span><?php echo (int)$it['quantity']; ?> x <?php echo h(pos_peso($it['unit_price'])); ?></span>
                    <span><?php echo h(pos_peso($it['line_total'])); ?></span>
                </div>
            <?php endforeach; ?>
            <hr>
            <div class="r-row"><span><?php echo $units; ?> item<?php echo $units === 1 ? '' : 's'; ?></span><span></span></div>
            <div class="r-row"><span>Subtotal</span><span><?php echo h(pos_peso($sale['subtotal'])); ?></span></div>
            <div class="r-row"><span>VATable Sales</span><span><?php echo h(pos_peso($sale['vatable_sales'])); ?></span></div>
            <div class="r-row"><span>VAT (12%)</span><span><?php echo h(pos_peso($sale['vat_amount'])); ?></span></div>
            <div class="r-row r-total"><span>TOTAL</span><span><?php echo h(pos_peso($sale['total'])); ?></span></div>
            <hr>
            <div class="r-row"><span>Payment</span><span><?php echo h(pos_payment_label((string)$sale['payment_method'])); ?></span></div>
            <?php if ($sale['payment_method'] === 'cash'): ?>
                <div class="r-row"><span>Cash Tendered</span><span><?php echo h(pos_peso($sale['amount_tendered'])); ?></span></div>
                <div class="r-row r-total"><span>CHANGE</span><span><?php echo h(pos_peso($sale['change_due'])); ?></span></div>
            <?php else: ?>
                <div class="r-row"><span>Reference No.</span><span><?php echo h((string)$sale['payment_ref']); ?></span></div>
            <?php endif; ?>
            <hr>
            <div class="r-center r-note">
                Prices are VAT-inclusive.<br>
                Thank you for shopping at EasyPC!<br>
                THIS IS NOT AN OFFICIAL RECEIPT.
            </div>
        </article>
        <?php endif; ?>
<?php
staff_page_end($autoPrint
    ? '<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 250); });</script>'
    : '');
