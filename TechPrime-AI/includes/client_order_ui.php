<?php
/**
 * Client Order Summary / Order History card renderer.
 * Expects helpers already loaded: h(), ias_client_product_image_url(), ias_order_display_status(), generateCsrfToken().
 */

/**
 * @param array $o Order row (with optional shipment_status, carrier)
 * @param array $items Line items for this order
 * @param array $ctx {
 *   list: 'summary'|'history',
 *   current_filter: string,
 *   orderItemsShown: int,
 *   cancelReasons: list<string>,
 *   orderStatusTone: callable,
 *   orderStatusNote: callable,
 *   paymentLabel: callable,
 *   fulfillmentLabels: array
 * }
 */
function ep_render_client_order_card(array $o, array $items, array $ctx): void
{
    $list = (string)($ctx['list'] ?? 'summary');
    $currentFilter = (string)($ctx['current_filter'] ?? 'All');
    $orderItemsShown = (int)($ctx['orderItemsShown'] ?? 3);
    $cancelReasons = $ctx['cancelReasons'] ?? [];
    $orderStatusTone = $ctx['orderStatusTone'];
    $orderStatusNote = $ctx['orderStatusNote'];
    $paymentLabel = $ctx['paymentLabel'];
    $fulfillmentLabels = $ctx['fulfillmentLabels'] ?? [];

    $oid = (int)$o['id'];
    $ost = (string)($o['status'] ?? '');
    $ostNorm = strtolower(trim($ost));
    $payStatus = strtolower(trim((string)($o['payment_status'] ?? 'unpaid')));
    $isPaid = ($payStatus === 'paid');
    $cancelRequested = !empty($o['cancel_requested']);
    $isHistory = ($list === 'history');
    $idPrefix = $isHistory ? 'h' : 's';

    $canPay = !$isHistory && ($ost === 'to_pay' && !$isPaid && !$cancelRequested);
    $canCancel = !$isHistory && ($ost === 'to_pay' && !$cancelRequested && empty($o['stock_deducted']));
    $canReceive = !$isHistory
        && ($currentFilter === 'to_receive' || $currentFilter === 'All' || $currentFilter === 'to_ship')
        && in_array($ost, ['to_ship', 'to_receive'], true)
        && empty($o['stock_deducted'])
        && !$cancelRequested
        && $currentFilter === 'to_receive';
    // Remove: summary (to_pay / cancelled) or any history row — not active ship/receive.
    $canRemove = $isHistory
        || in_array($ostNorm, ['to_pay', 'to pay', 'cancelled', 'canceled'], true);

    if ($cancelRequested && $ostNorm !== 'cancelled' && $ostNorm !== 'canceled') {
        $statusLabel = 'Cancellation Requested';
    } elseif (!$isHistory && $currentFilter === 'to_receive' && in_array($ost, ['to_ship', 'to_receive'], true)) {
        $statusLabel = 'To Receive';
    } elseif (!$isHistory && $currentFilter === 'to_ship' && in_array($ost, ['to_ship', 'to_receive'], true)) {
        $statusLabel = 'To Ship';
    } elseif ($ost === 'to_pay' && $isPaid) {
        $statusLabel = 'Paid — awaiting accept';
    } elseif ($isHistory && in_array($ostNorm, ['to_review', 'to review'], true)) {
        $statusLabel = 'Completed';
    } else {
        $statusLabel = ias_order_display_status($o['status'] ?? '', $o['shipment_status'] ?? null);
    }

    $fulfilKey = strtolower(trim((string)($o['fulfillment_type'] ?? '')));
    $fulfil = $fulfillmentLabels[$fulfilKey] ?? '';
    if ($fulfilKey === 'delivery') {
        $fulfil = 'Delivery (Lalamove)';
    }
    [$noteIcon, $noteText] = $orderStatusNote($statusLabel, trim((string)($o['carrier'] ?? '')), $fulfilKey === 'pickup');
    if ($cancelRequested && $ostNorm !== 'cancelled' && $ostNorm !== 'canceled') {
        [$noteIcon, $noteText] = ['fa-hourglass-half', 'Cancellation pending store approval'];
    } elseif ($ost === 'to_pay' && $isPaid) {
        [$noteIcon, $noteText] = ['fa-store', 'Payment received — waiting for store acceptance'];
    } elseif ($isHistory && $fulfilKey === 'delivery' && in_array($ostNorm, ['to_review', 'to review'], true)) {
        [$noteIcon, $noteText] = ['fa-check-circle', 'Delivered by Lalamove'];
    }

    $itemQty = array_sum(array_map(static fn($it) => (int)$it['quantity'], $items));
    $hiddenCount = max(0, count($items) - $orderItemsShown);
    $payLabel = $paymentLabel($o);
    $shipTo = trim((string)($o['shipping_address'] ?? ''));
    $phone = trim((string)($o['customer_phone'] ?? ''));
    $cancelReasonText = trim((string)($o['cancel_reason'] ?? ''));
    $hasDetails = $shipTo !== '' || $phone !== '' || $payLabel !== '' || $fulfil !== '' || ($cancelRequested && $cancelReasonText !== '');
    $hasActions = $hasDetails || $canCancel || $canPay || $canReceive || $cancelRequested;
    ?>
    <li class="pf-order <?php echo $orderStatusTone($statusLabel); ?>">
        <div class="pf-order-head">
            <div class="pf-order-ref">
                <strong>Order #ORD-<?php echo $oid; ?></strong>
                <span>Placed <?php echo date('M d, Y', strtotime((string)$o['created_at'])); ?></span>
            </div>
            <div class="pf-order-head-right">
                <span class="pf-status <?php echo $orderStatusTone($statusLabel); ?>"><?php echo h($statusLabel); ?></span>
                <?php if ($canRemove): ?>
                    <form method="post" class="pf-order-remove-form"
                          data-confirm="Remove this order from your <?php echo $isHistory ? 'Order History' : 'Order Summary'; ?>? You will no longer see it here."
                          data-confirm-title="Remove order?"
                          data-confirm-ok="Remove"
                          data-confirm-cancel="Keep"
                          data-confirm-type="danger">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="hide_order">
                        <input type="hidden" name="order_id" value="<?php echo $oid; ?>">
                        <input type="hidden" name="from" value="<?php echo h($list); ?>">
                        <button type="submit" class="pf-order-remove" title="Remove" aria-label="Remove order #ORD-<?php echo $oid; ?>">
                            <i class="fas fa-trash-alt" aria-hidden="true"></i>
                            <span>Remove</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($items): ?>
            <ul class="pf-order-items" id="pfOrderItems<?php echo h($idPrefix . $oid); ?>">
                <?php foreach ($items as $i => $it):
                    $itName = trim((string)($it['name'] ?? ''));
                    $itImg = $itName !== '' ? ias_client_product_image_url($it) : '';
                    $itQty = (int)$it['quantity'];
                    $itPrice = (float)$it['price'];
                    $itLink = $itName !== '' ? 'products.php?id=' . (int)$it['product_id'] : '';
                    ?>
                    <li class="pf-item"<?php echo $i >= $orderItemsShown ? ' data-extra hidden' : ''; ?>>
                        <span class="pf-item-thumb">
                            <?php if ($itImg !== ''): ?>
                                <img src="<?php echo h($itImg); ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <i class="fas fa-box" aria-hidden="true"></i>
                            <?php endif; ?>
                        </span>
                        <span class="pf-item-info">
                            <?php if ($itLink !== ''): ?>
                                <a class="pf-item-name" href="<?php echo h($itLink); ?>"><?php echo h($itName); ?></a>
                            <?php else: ?>
                                <span class="pf-item-name is-gone">Product no longer available</span>
                            <?php endif; ?>
                            <span class="pf-item-qty">Qty <?php echo $itQty; ?> &times; &#8369;<?php echo number_format($itPrice, 2); ?></span>
                        </span>
                        <strong class="pf-item-sub">&#8369;<?php echo number_format($itPrice * $itQty, 2); ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($hiddenCount > 0): ?>
                <button type="button" class="pf-more-items" aria-expanded="false"
                        aria-controls="pfOrderItems<?php echo h($idPrefix . $oid); ?>" data-more-items
                        data-label-closed="Show <?php echo $hiddenCount; ?> more <?php echo $hiddenCount === 1 ? 'item' : 'items'; ?>"
                        data-label-open="Show fewer items">
                    <span>Show <?php echo $hiddenCount; ?> more <?php echo $hiddenCount === 1 ? 'item' : 'items'; ?></span>
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                </button>
            <?php endif; ?>
        <?php endif; ?>

        <div class="pf-order-foot">
            <p class="pf-order-note"><i class="fas <?php echo h($noteIcon); ?>" aria-hidden="true"></i> <?php echo h($noteText); ?></p>
            <p class="pf-order-total">
                <span>Order total<?php echo $itemQty > 0 ? ' (' . $itemQty . ' ' . ($itemQty === 1 ? 'item' : 'items') . ')' : ''; ?></span>
                <strong>&#8369;<?php echo number_format((float)$o['total'], 2); ?></strong>
            </p>
        </div>

        <?php if ($hasActions): ?>
            <div class="pf-order-actions">
                <?php if ($hasDetails): ?>
                    <button type="button" class="pf-order-btn" aria-expanded="false"
                            aria-controls="pfOrderDetails<?php echo h($idPrefix . $oid); ?>" data-order-details>
                        Order details <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </button>
                <?php endif; ?>
                <?php if ($canPay): ?>
                    <a href="payment.php?order_id=<?php echo $oid; ?>" class="pf-order-btn is-pay">Pay</a>
                <?php endif; ?>
                <?php if ($canReceive): ?>
                    <form method="post" class="order-cancel-form"
                          data-confirm="Confirm Item Received? Stock will be deducted once and this order will move to Order History."
                          data-confirm-title="Item received?"
                          data-confirm-ok="Item Received"
                          data-confirm-cancel="Not yet"
                          data-confirm-type="success">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="receive_order">
                        <input type="hidden" name="order_id" value="<?php echo $oid; ?>">
                        <button type="submit" class="pf-order-btn is-pay">Item Received</button>
                    </form>
                <?php endif; ?>
                <?php if ($canCancel): ?>
                    <form method="post" class="order-cancel-form pf-cancel-form"
                          data-confirm="Submit a cancellation request? The store must approve it. Stock will not be deducted."
                          data-confirm-title="Cancel this order?"
                          data-confirm-ok="Request cancel"
                          data-confirm-cancel="Keep order"
                          data-confirm-type="danger">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="cancel_order">
                        <input type="hidden" name="order_id" value="<?php echo $oid; ?>">
                        <label class="sr-only" for="cancelReason<?php echo h($idPrefix . $oid); ?>">Cancellation reason</label>
                        <select name="cancel_reason" id="cancelReason<?php echo h($idPrefix . $oid); ?>" class="pf-cancel-reason" required>
                            <option value="">Reason…</option>
                            <?php foreach ($cancelReasons as $cr): ?>
                                <option value="<?php echo h($cr); ?>"><?php echo h($cr); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="pf-order-btn is-danger">Cancel order</button>
                    </form>
                <?php elseif ($cancelRequested && $ostNorm !== 'cancelled' && $ostNorm !== 'canceled'): ?>
                    <span class="pf-order-btn" style="cursor:default;opacity:.85;">Cancel pending</span>
                <?php endif; ?>
            </div>
            <?php if ($hasDetails): ?>
                <dl class="pf-order-details" id="pfOrderDetails<?php echo h($idPrefix . $oid); ?>" hidden>
                    <?php if ($fulfil !== ''): ?>
                        <div><dt>Fulfillment</dt><dd><?php echo h($fulfil); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($shipTo !== ''): ?>
                        <div><dt><?php echo $fulfilKey === 'pickup' ? 'Pickup location' : 'Ship to'; ?></dt><dd><?php echo h($shipTo); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($phone !== ''): ?>
                        <div><dt>Contact number</dt><dd><?php echo h($phone); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($payLabel !== ''): ?>
                        <div><dt>Payment</dt><dd><?php echo h($payLabel); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($cancelRequested && $cancelReasonText !== ''): ?>
                        <div><dt>Cancel reason</dt><dd><?php echo h($cancelReasonText); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($fulfilKey === 'delivery'): ?>
                        <div><dt>Courier</dt><dd>Lalamove</dd></div>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
        <?php endif; ?>
    </li>
    <?php
}

/**
 * @param callable $urlFn fn(array $overrides): string
 * @param string $pageKey 'spage'|'hpage'
 */
function ep_render_orders_pagination(
    int $page,
    int $totalPages,
    int $totalRows,
    int $onPage,
    string $pageKey,
    callable $urlFn,
    callable $rangeFn,
    string $label
): void {
    if ($totalRows < 1) {
        return;
    }
    ?>
    <nav class="ep-pagination pf-orders-pagination" aria-label="<?php echo h($label); ?>">
        <?php if ($totalPages > 1): ?>
            <a class="ep-page-link ep-page-nav<?php echo $page <= 1 ? ' disabled' : ''; ?>"
               href="<?php echo h($urlFn([$pageKey => max(1, $page - 1)])); ?>"
               <?php echo $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
                <i class="fas fa-arrow-left" aria-hidden="true"></i> Previous
            </a>
            <?php foreach ($rangeFn($page, $totalPages) as $item): ?>
                <?php if ($item === '...'): ?>
                    <span class="ep-page-ellipsis">…</span>
                <?php else: ?>
                    <a class="ep-page-link<?php echo (int)$item === $page ? ' active' : ''; ?>"
                       href="<?php echo h($urlFn([$pageKey => (int)$item])); ?>"><?php echo (int)$item; ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a class="ep-page-link ep-page-nav<?php echo $page >= $totalPages ? ' disabled' : ''; ?>"
               href="<?php echo h($urlFn([$pageKey => min($totalPages, $page + 1)])); ?>"
               <?php echo $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
                Next <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
        <span class="pf-orders-page-meta">
            Showing <?php echo (int)$onPage; ?> of <?php echo (int)$totalRows; ?> · max 5 per page
        </span>
    </nav>
    <?php
}
