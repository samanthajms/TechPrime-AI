<?php
/**
 * Shared helpers for CLIENT storefront pages.
 */

require_once __DIR__ . '/inventory_alerts.php';

/** Ensure users.profile_image exists (additive, non-destructive). */
function ep_profile_image_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        @$db->query('ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_image VARCHAR(255) NULL DEFAULT NULL');
    } catch (Throwable $e) {
        // Column may already exist or DB may lack IF NOT EXISTS — ignore.
    }
}

/** Public URL for a user's profile image, or empty string. */
function ep_user_profile_image_url(?string $profileImage): string
{
    $profileImage = trim((string)$profileImage);
    if ($profileImage === '') {
        return '';
    }
    // Stored as relative path under assets/profiles/
    if (preg_match('/^profiles\/[a-zA-Z0-9._-]+$/', $profileImage)) {
        $abs = dirname(__DIR__) . '/assets/' . $profileImage;
        if (is_file($abs)) {
            return '../assets/' . $profileImage;
        }
    }
    return '';
}

/**
 * Restore DB cart into session after login.
 * Merges any guest session cart into the user's DB cart first (no duplicate rows).
 */
function ep_sync_cart_on_login(PDO $db, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $guest = (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? $_SESSION['cart'] : [];
    foreach ($guest as $pid => $qty) {
        $pid = (int)$pid;
        $qty = (int)$qty;
        if ($pid <= 0 || $qty < 1) {
            continue;
        }
        $chk = $db->prepare('SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?');
        $chk->execute([$userId, $pid]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Keep the higher quantity to avoid stacking duplicates on re-login
            $newQty = max((int)$row['quantity'], $qty);
            $up = $db->prepare('UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?');
            $up->execute([$newQty, $userId, $pid]);
        } else {
            $ins = $db->prepare('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)');
            $ins->execute([$userId, $pid, $qty]);
        }
    }

    $_SESSION['cart'] = [];
    $st = $db->prepare('SELECT product_id, quantity FROM cart WHERE user_id = ?');
    $st->execute([$userId]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['product_id'];
        $qty = (int)$row['quantity'];
        if ($pid > 0 && $qty > 0) {
            $_SESSION['cart'][$pid] = $qty;
        }
    }
}

/** If logged-in session cart is empty, reload from DB (logout/login persistence). */
function ep_ensure_session_cart(PDO $db): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
        return;
    }
    $uid = (int)$_SESSION['user_id'];
    $_SESSION['cart'] = [];
    $st = $db->prepare('SELECT product_id, quantity FROM cart WHERE user_id = ?');
    $st->execute([$uid]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['product_id'];
        $qty = (int)$row['quantity'];
        if ($pid > 0 && $qty > 0) {
            $_SESSION['cart'][$pid] = $qty;
        }
    }
}

/**
 * Place unpaid order (Cart → Place Order → To Pay). No payment, no stock deduction.
 * @param list<array{id:int,qty:int,price:float|int,name?:string}> $items
 * @return array{ok:bool,order_id?:int,error?:string}
 */
function ep_place_unpaid_order(
    PDO $db,
    int $userId,
    array $items,
    float $total,
    string $address,
    string $phone,
    string $fulfillmentType = 'delivery'
): array {
    if ($userId <= 0 || empty($items) || $address === '' || $phone === '') {
        return ['ok' => false, 'error' => 'invalid'];
    }
    $fulfillmentType = strtolower(trim($fulfillmentType));
    if (!in_array($fulfillmentType, ['pickup', 'delivery'], true)) {
        $fulfillmentType = 'delivery';
    }

    $orderId = 0;
    try {
        $db->beginTransaction();

        foreach ($items as $item) {
            $pid = (int)($item['id'] ?? 0);
            $qty = (int)($item['qty'] ?? 0);
            if ($pid <= 0 || $qty < 1) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'invalid_item'];
            }
            $chk = $db->prepare('SELECT COALESCE(stock, 0) AS stock FROM products WHERE id = ? FOR UPDATE');
            $chk->execute([$pid]);
            $prod = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$prod || (int)$prod['stock'] < $qty) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'stock'];
            }
        }

        $ins = $db->prepare(
            "INSERT INTO orders (
                user_id, total, status, shipping_address, customer_phone,
                payment_method, payment_status, stock_deducted, fulfillment_type
             ) VALUES (?, ?, 'to_pay', ?, ?, NULL, 'unpaid', FALSE, ?)
             RETURNING id"
        );
        $ins->execute([$userId, $total, $address, $phone, $fulfillmentType]);
        $orderId = (int)$ins->fetchColumn();
        if ($orderId <= 0) {
            $orderId = (int)$db->lastInsertId('orders_id_seq');
        }
        if ($orderId <= 0) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'create_failed'];
        }

        $itemStmt = $db->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)'
        );
        $orderedIds = [];
        foreach ($items as $item) {
            $pid = (int)$item['id'];
            $qty = (int)$item['qty'];
            $price = (float)$item['price'];
            $itemStmt->execute([$orderId, $pid, $qty, $price]);
            $orderedIds[] = $pid;
        }

        if ($orderedIds) {
            $in = implode(',', array_map('intval', $orderedIds));
            $db->exec("DELETE FROM cart WHERE user_id = {$userId} AND product_id IN ({$in})");
            foreach ($orderedIds as $pid) {
                unset($_SESSION['cart'][$pid]);
            }
            $_SESSION['cart_selected'] = array_values(array_filter(
                array_map('intval', (array)($_SESSION['cart_selected'] ?? [])),
                static fn($id) => !in_array($id, $orderedIds, true)
            ));
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'exception'];
    }

    inv_notify_user(
        $db,
        $userId,
        "Order #ORD-{$orderId} placed. Please pay under Order Summary → To Pay.",
        'order_placed',
        'user_dashboard.php?status=to_pay'
    );

    return ['ok' => true, 'order_id' => $orderId];
}

/**
 * Legacy alias — places unpaid to_pay order (no stock). Prefer ep_place_unpaid_order.
 * @param list<array{id:int,qty:int,price:float|int,name?:string}> $items
 */
function ep_place_cod_order(PDO $db, int $userId, array $items, float $total, string $address, string $phone): array
{
    return ep_place_unpaid_order($db, $userId, $items, $total, $address, $phone, 'delivery');
}

/**
 * Mark an existing to_pay order as paid (placeholder payment). Does not create a new order.
 * @return array{ok:bool,error?:string}
 */
function ep_mark_order_paid(PDO $db, int $userId, int $orderId, string $paymentRef): array
{
    if ($userId <= 0 || $orderId <= 0 || trim($paymentRef) === '') {
        return ['ok' => false, 'error' => 'invalid'];
    }

    try {
        $db->beginTransaction();

        $byRef = $db->prepare('SELECT id, user_id, payment_status FROM orders WHERE payment_ref = ? LIMIT 1');
        $byRef->execute([$paymentRef]);
        $existing = $byRef->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $db->commit();
            if ((int)$existing['user_id'] === $userId && (int)$existing['id'] === $orderId) {
                return ['ok' => true];
            }
            return ['ok' => false, 'error' => 'ref_taken'];
        }

        $st = $db->prepare(
            'SELECT id, status, payment_status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE'
        );
        $st->execute([$orderId, $userId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ((string)$order['status'] !== 'to_pay') {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_payable'];
        }
        if (strtolower((string)($order['payment_status'] ?? '')) === 'paid') {
            $db->commit();
            return ['ok' => true];
        }

        $up = $db->prepare(
            "UPDATE orders
             SET payment_status = 'paid',
                 payment_method = 'online',
                 payment_ref = ?,
                 paid_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ? AND status = 'to_pay'
               AND COALESCE(payment_status, 'unpaid') <> 'paid'"
        );
        $up->execute([$paymentRef, $orderId, $userId]);
        if ($up->rowCount() < 1) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'already_paid'];
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'exception'];
    }

    inv_notify_user(
        $db,
        $userId,
        "Payment successful for order #ORD-{$orderId}. Waiting for store acceptance.",
        'order_paid',
        'user_dashboard.php?status=to_pay'
    );
    inv_notify_role(
        $db,
        'inventory_custodian',
        "Paid order #ORD-{$orderId} ready to Accept.",
        'new_order',
        'inventory_orders.php?status=to_pay'
    );

    return ['ok' => true];
}

/** Custodian accepts a paid to_pay order → to_ship (client sees To Ship + To Receive). */
function ep_accept_order(PDO $db, int $orderId, int $staffUserId = 0): array
{
    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }

    $clientId = 0;
    $isDelivery = false;
    try {
        $db->beginTransaction();
        $st = $db->prepare(
            'SELECT id, user_id, status, payment_status, cancel_requested, fulfillment_type
             FROM orders WHERE id = ? FOR UPDATE'
        );
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ((string)$order['status'] !== 'to_pay') {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_acceptable'];
        }
        if (!empty($order['cancel_requested'])) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'cancel_pending'];
        }
        if (strtolower((string)($order['payment_status'] ?? '')) !== 'paid') {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_paid'];
        }

        $up = $db->prepare(
            "UPDATE orders SET status = 'to_ship'
             WHERE id = ? AND status = 'to_pay' AND COALESCE(cancel_requested, FALSE) = FALSE"
        );
        $up->execute([$orderId]);
        if ($up->rowCount() < 1) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_acceptable'];
        }

        $isDelivery = strtolower(trim((string)($order['fulfillment_type'] ?? ''))) === 'delivery';
        $chk = $db->prepare('SELECT id FROM shipments WHERE order_id = ? LIMIT 1');
        $chk->execute([$orderId]);
        $ship = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$ship) {
            if ($isDelivery) {
                $db->prepare(
                    "INSERT INTO shipments (order_id, shipment_status, carrier) VALUES (?, 'processing', 'LALAMOVE')"
                )->execute([$orderId]);
            } else {
                $db->prepare("INSERT INTO shipments (order_id, shipment_status) VALUES (?, 'processing')")
                    ->execute([$orderId]);
            }
        } else {
            if ($isDelivery) {
                $db->prepare(
                    "UPDATE shipments SET shipment_status = 'processing', carrier = 'LALAMOVE' WHERE id = ?"
                )->execute([(int)$ship['id']]);
            } else {
                $db->prepare("UPDATE shipments SET shipment_status = 'processing' WHERE id = ?")
                    ->execute([(int)$ship['id']]);
            }
        }

        $clientId = (int)$order['user_id'];
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'exception'];
    }

    if ($clientId > 0) {
        inv_notify_user(
            $db,
            $clientId,
            "Your order #ORD-{$orderId} was accepted.",
            'order_status',
            'user_dashboard.php?status=to_ship'
        );
    }
    if ($staffUserId > 0 && function_exists('logActivity')) {
        logActivity($db, $staffUserId, 'accept_order', "Order #$orderId accepted → to_ship");
    }
    return ['ok' => true];
}

/**
 * Soft-remove an order from the client's Order Summary / History lists.
 * Does not change order status or stock — only hides it for this client.
 * @return array{ok:bool,error?:string}
 */
function ep_hide_client_order(PDO $db, int $userId, int $orderId): array
{
    if ($userId <= 0 || $orderId <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }

    try {
        $st = $db->prepare(
            'SELECT id, status FROM orders WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $st->execute([$orderId, $userId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $status = strtolower(trim((string)($order['status'] ?? '')));
        // Active fulfillment orders stay visible so Item Received remains available.
        if (in_array($status, ['to_ship', 'to_receive', 'to ship', 'to receive'], true)) {
            return ['ok' => false, 'error' => 'not_removable'];
        }

        $up = $db->prepare(
            'UPDATE orders SET client_history_hidden = TRUE
             WHERE id = ? AND user_id = ? AND COALESCE(client_history_hidden, FALSE) = FALSE'
        );
        $up->execute([$orderId, $userId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'exception'];
    }
}

/** Client Item Received — mark successful and deduct stock once. */
function ep_confirm_order_received(PDO $db, int $userId, int $orderId): array
{
    if ($userId <= 0 || $orderId <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }

    try {
        $db->beginTransaction();
        $st = $db->prepare(
            'SELECT id, status, payment_status, stock_deducted
             FROM orders WHERE id = ? AND user_id = ? FOR UPDATE'
        );
        $st->execute([$orderId, $userId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_found'];
        }

        $status = (string)($order['status'] ?? '');
        $alreadyDeducted = !empty($order['stock_deducted']);

        if ($status === 'to_review' && $alreadyDeducted) {
            $db->commit();
            return ['ok' => true];
        }

        if (!in_array($status, ['to_ship', 'to_receive'], true)) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_receivable'];
        }

        if (!$alreadyDeducted) {
            $items = $db->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
            $items->execute([$orderId]);
            $stockStmt = $db->prepare(
                'UPDATE products SET stock = stock - ? WHERE id = ? AND COALESCE(stock, 0) >= ?'
            );
            while ($row = $items->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int)$row['product_id'];
                $qty = (int)$row['quantity'];
                if ($pid <= 0 || $qty < 1) {
                    continue;
                }
                $before = $db->prepare('SELECT name, COALESCE(stock, 0) AS stock FROM products WHERE id = ?');
                $before->execute([$pid]);
                $prod = $before->fetch(PDO::FETCH_ASSOC);
                $stockStmt->execute([$qty, $pid, $qty]);
                if ($stockStmt->rowCount() < 1) {
                    $db->rollBack();
                    return ['ok' => false, 'error' => 'stock'];
                }
                if ($prod) {
                    inv_notify_stock_change(
                        $db,
                        (string)$prod['name'],
                        $pid,
                        (int)$prod['stock'],
                        (int)$prod['stock'] - $qty
                    );
                }
            }
        }

        $up = $db->prepare(
            "UPDATE orders
             SET status = 'to_review', stock_deducted = TRUE
             WHERE id = ? AND user_id = ? AND status IN ('to_ship', 'to_receive')"
        );
        $up->execute([$orderId, $userId]);
        if ($up->rowCount() < 1 && !$alreadyDeducted) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'already_received'];
        }

        $ship = $db->prepare('SELECT id FROM shipments WHERE order_id = ? LIMIT 1');
        $ship->execute([$orderId]);
        $shipRow = $ship->fetch(PDO::FETCH_ASSOC);
        if ($shipRow) {
            $db->prepare("UPDATE shipments SET shipment_status = 'delivered' WHERE id = ?")
                ->execute([(int)$shipRow['id']]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'exception'];
    }

    inv_notify_user(
        $db,
        $userId,
        "Order #ORD-{$orderId} completed. Thank you!",
        'order_received',
        'user_dashboard.php#order-history'
    );
    inv_notify_role(
        $db,
        'inventory_custodian',
        "Order #ORD-{$orderId} Item Received — stock deducted once.",
        'order_received',
        'inventory_orders.php'
    );

    return ['ok' => true];
}

/** Allowed client cancellation reasons. */
function ep_order_cancel_reasons(): array
{
    return [
        'Changed my mind',
        'Ordered by mistake',
        'Found another product',
        'Wrong product/order',
        'Other',
    ];
}

/**
 * Client requests cancellation (To Pay). Needs Inventory Custodian Accept Cancel.
 * Does not deduct or restore stock (stock is never deducted until Item Received).
 * @return array{ok:bool,error?:string}
 */
function ep_request_order_cancel(PDO $db, int $userId, int $orderId, string $reason): array
{
    if ($userId <= 0 || $orderId <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }
    $reason = trim($reason);
    $allowed = ep_order_cancel_reasons();
    if ($reason === '' || !in_array($reason, $allowed, true)) {
        return ['ok' => false, 'error' => 'reason'];
    }

    try {
        $db->beginTransaction();
        $st = $db->prepare(
            'SELECT id, status, cancel_requested, stock_deducted
             FROM orders WHERE id = ? AND user_id = ? FOR UPDATE'
        );
        $st->execute([$orderId, $userId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_found'];
        }
        $status = (string)($order['status'] ?? '');
        if (strcasecmp($status, 'cancelled') === 0 || strcasecmp($status, 'Canceled') === 0) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'already_cancelled'];
        }
        if (!empty($order['cancel_requested'])) {
            $db->commit();
            return ['ok' => true];
        }
        if ($status !== 'to_pay') {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_cancellable'];
        }
        if (!empty($order['stock_deducted'])) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_cancellable'];
        }

        $up = $db->prepare(
            "UPDATE orders
             SET cancel_requested = TRUE,
                 cancel_reason = ?,
                 cancel_requested_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ? AND status = 'to_pay'
               AND COALESCE(cancel_requested, FALSE) = FALSE"
        );
        $up->execute([$reason, $orderId, $userId]);
        if ($up->rowCount() < 1) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_cancellable'];
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'exception'];
    }

    inv_notify_user(
        $db,
        $userId,
        "Cancellation requested for order #ORD-{$orderId}. Waiting for store approval.",
        'cancel_requested',
        'user_dashboard.php?status=to_pay'
    );
    inv_notify_role(
        $db,
        'inventory_custodian',
        "Cancel request for order #ORD-{$orderId}. Reason: {$reason}",
        'cancel_requested',
        'inventory_orders.php?status=cancel_requested'
    );

    return ['ok' => true];
}

/**
 * Custodian Accept Cancel Order — mark cancelled. No stock change.
 * @return array{ok:bool,error?:string}
 */
function ep_accept_order_cancel(PDO $db, int $orderId, int $staffUserId = 0): array
{
    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }

    $clientId = 0;
    $reason = '';
    try {
        $db->beginTransaction();
        $st = $db->prepare(
            'SELECT id, user_id, status, cancel_requested, cancel_reason, stock_deducted
             FROM orders WHERE id = ? FOR UPDATE'
        );
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (strcasecmp((string)$order['status'], 'cancelled') === 0) {
            $db->commit();
            return ['ok' => true];
        }
        if (empty($order['cancel_requested'])) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'no_request'];
        }
        if (!empty($order['stock_deducted'])) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'stock_deducted'];
        }

        $reason = trim((string)($order['cancel_reason'] ?? ''));
        $up = $db->prepare(
            "UPDATE orders
             SET status = 'cancelled',
                 cancel_requested = FALSE
             WHERE id = ? AND COALESCE(cancel_requested, FALSE) = TRUE"
        );
        $up->execute([$orderId]);
        if ($up->rowCount() < 1) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_found'];
        }
        $clientId = (int)$order['user_id'];
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'exception'];
    }

    if ($clientId > 0) {
        $msg = "Your order #ORD-{$orderId} was cancelled.";
        if ($reason !== '') {
            $msg .= " Reason: {$reason}";
        }
        inv_notify_user($db, $clientId, $msg, 'order_cancelled', 'user_dashboard.php');
    }
    if ($staffUserId > 0 && function_exists('logActivity')) {
        logActivity(
            $db,
            $staffUserId,
            'accept_order_cancel',
            "Order #$orderId cancelled" . ($reason !== '' ? ". Reason: {$reason}" : '')
        );
    }
    return ['ok' => true];
}

/**
 * Legacy alias — request cancel without a reason is not allowed; use ep_request_order_cancel.
 * @return array{ok:bool,error?:string}
 */
function ep_cancel_order(PDO $db, int $userId, int $orderId, string $reason = ''): array
{
    if ($reason === '') {
        $reason = 'Changed my mind';
    }
    return ep_request_order_cancel($db, $userId, $orderId, $reason);
}

/** Load cart preview rows for header dropdown. */
function ep_get_cart_preview(PDO $db): array
{
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        return ['items' => [], 'total' => 0.0, 'count' => 0];
    }

    $ids = array_filter(array_map('intval', array_keys($_SESSION['cart'])), fn($id) => $id > 0);
    if (empty($ids)) {
        return ['items' => [], 'total' => 0.0, 'count' => 0];
    }

    $idsStr = implode(',', $ids);
    $res = $db->query("SELECT id, name, price, image FROM products WHERE id IN ($idsStr)");
    $items = [];
    $total = 0.0;
    $count = 0;

    if ($res) {
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int)$row['id'];
            $qty = (int)($_SESSION['cart'][$pid] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $subtotal = (float)$row['price'] * $qty;
            $items[] = [
                'id' => $pid,
                'name' => $row['name'],
                'price' => (float)$row['price'],
                'qty' => $qty,
                'subtotal' => $subtotal,
                // Thumbnail for the header cart preview ('' = show a placeholder icon)
                'image' => function_exists('ias_client_product_image_url') ? ias_client_product_image_url($row) : '',
            ];
            $total += $subtotal;
            $count += $qty;
        }
    }

    return ['items' => $items, 'total' => $total, 'count' => $count];
}

/**
 * Stage a Buy Now product for checkout without adding it to the cart.
 * @return bool false if product invalid / unavailable
 */
function ep_set_buy_now(PDO $db, int $productId, int $qty = 1): bool
{
    if ($productId <= 0 || $qty < 1) {
        return false;
    }
    $chk = $db->prepare(
        'SELECT p.* FROM products p WHERE p.id = ? AND ' . ias_client_product_list_sql_condition('p') . ' LIMIT 1'
    );
    $chk->execute([$productId]);
    $productRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$productRow || ias_client_product_image_url($productRow) === '') {
        return false;
    }
    $stock = (int)($productRow['stock'] ?? 0);
    if ($stock < 1) {
        return false;
    }
    $qty = min($qty, $stock, 99);
    $_SESSION['buy_now'] = [$productId => $qty];
    return true;
}

function ep_clear_buy_now(): void
{
    unset($_SESSION['buy_now']);
}

/** @return array<int,int> product_id => qty */
function ep_buy_now_map(): array
{
    if (empty($_SESSION['buy_now']) || !is_array($_SESSION['buy_now'])) {
        return [];
    }
    $out = [];
    foreach ($_SESSION['buy_now'] as $pid => $qty) {
        $pid = (int)$pid;
        $qty = (int)$qty;
        if ($pid > 0 && $qty > 0) {
            $out[$pid] = $qty;
        }
    }
    return $out;
}

/** Add a product to session (and DB cart when logged in). Returns false if invalid. */
function ep_add_product_to_cart(PDO $db, int $productId, int $qty = 1): bool
{
    if ($productId <= 0 || $qty < 1) {
        return false;
    }

    $chk = $db->prepare(
        'SELECT p.* FROM products p WHERE p.id = ? AND ' . ias_client_product_list_sql_condition('p') . ' LIMIT 1'
    );
    $chk->execute([$productId]);
    $productRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$productRow || ias_client_product_image_url($productRow) === '') {
        return false;
    }

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    if (isset($_SESSION['cart'][$productId])) {
        $_SESSION['cart'][$productId] += $qty;
    } else {
        $_SESSION['cart'][$productId] = $qty;
    }

    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $chk = $db->prepare('SELECT id FROM cart WHERE user_id = ? AND product_id = ?');
        $chk->execute([$uid, $productId]);
        if ($chk->fetch(PDO::FETCH_ASSOC)) {
            $stmt = $db->prepare('UPDATE cart SET quantity = quantity + ? WHERE user_id = ? AND product_id = ?');
            $stmt->execute([$qty, $uid, $productId]);
        } else {
            $stmt = $db->prepare('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)');
            $stmt->execute([$uid, $productId, $qty]);
        }
    }

    return true;
}

/** Set an exact cart quantity (session + DB). Qty < 1 removes the item. */
function ep_set_cart_quantity(PDO $db, int $productId, int $qty): array
{
    if ($productId <= 0) {
        return ['ok' => false, 'qty' => 0, 'error' => 'invalid'];
    }

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    if ($qty < 1) {
        unset($_SESSION['cart'][$productId]);
        if (!empty($_SESSION['user_id'])) {
            $del = $db->prepare('DELETE FROM cart WHERE user_id = ? AND product_id = ?');
            $del->execute([(int)$_SESSION['user_id'], $productId]);
        }
        return ['ok' => true, 'qty' => 0];
    }

    $chk = $db->prepare(
        'SELECT p.id, p.stock FROM products p WHERE p.id = ? AND ' . ias_client_product_list_sql_condition('p') . ' LIMIT 1'
    );
    $chk->execute([$productId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'qty' => 0, 'error' => 'not_found'];
    }
    $stock = (int)($row['stock'] ?? 0);
    if ($stock < 1) {
        return ['ok' => false, 'qty' => 0, 'error' => 'stock'];
    }
    if ($qty > $stock) {
        $qty = $stock;
    }

    $_SESSION['cart'][$productId] = $qty;
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $exists = $db->prepare('SELECT id FROM cart WHERE user_id = ? AND product_id = ?');
        $exists->execute([$uid, $productId]);
        if ($exists->fetch(PDO::FETCH_ASSOC)) {
            $up = $db->prepare('UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?');
            $up->execute([$qty, $uid, $productId]);
        } else {
            $ins = $db->prepare('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)');
            $ins->execute([$uid, $productId, $qty]);
        }
    }

    return ['ok' => true, 'qty' => $qty];
}

function ep_users_has_phone(PDO $db): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'phone'"
    );
    $stmt->execute();
    $has = (bool)$stmt->fetchColumn();
    return $has;
}

/**
 * Derive a brand label from real product name text (first token).
 * No hardcoded brand list — values come from existing product names.
 */
function ias_client_product_brand(array $p): string
{
    $name = trim((string) ($p['name'] ?? ''));
    if ($name === '') {
        return '';
    }
    if (preg_match('/^([A-Za-z0-9][A-Za-z0-9&+.\-]*)/', $name, $m)) {
        return $m[1];
    }
    return '';
}

function ep_wishlist_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        @$db->query(
            'CREATE TABLE IF NOT EXISTS wishlist (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                UNIQUE (user_id, product_id)
            )'
        );
    } catch (Throwable $e) {
        // Table may already exist.
    }
}

function ep_ensure_session_wishlist(PDO $db): void
{
    if (!isset($_SESSION['wishlist']) || !is_array($_SESSION['wishlist'])) {
        $_SESSION['wishlist'] = [];
    }
    if (empty($_SESSION['user_id'])) {
        return;
    }
    ep_wishlist_ensure_schema($db);
    $st = $db->prepare('SELECT product_id FROM wishlist WHERE user_id = ?');
    $st->execute([(int)$_SESSION['user_id']]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['product_id'];
        if ($pid > 0) {
            $_SESSION['wishlist'][$pid] = 1;
        }
    }
}

function ep_wishlist_ids(): array
{
    if (empty($_SESSION['wishlist']) || !is_array($_SESSION['wishlist'])) {
        return [];
    }
    return array_values(array_filter(array_map('intval', array_keys($_SESSION['wishlist'])), fn($id) => $id > 0));
}

function ep_wishlist_has(int $productId): bool
{
    return $productId > 0 && !empty($_SESSION['wishlist'][$productId]);
}

function ep_toggle_wishlist(PDO $db, int $productId): bool
{
    if ($productId <= 0) {
        return false;
    }
    if (!isset($_SESSION['wishlist']) || !is_array($_SESSION['wishlist'])) {
        $_SESSION['wishlist'] = [];
    }
    $on = !empty($_SESSION['wishlist'][$productId]);
    if ($on) {
        unset($_SESSION['wishlist'][$productId]);
    } else {
        $_SESSION['wishlist'][$productId] = 1;
    }
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        ep_wishlist_ensure_schema($db);
        if ($on) {
            $db->prepare('DELETE FROM wishlist WHERE user_id = ? AND product_id = ?')->execute([$uid, $productId]);
        } else {
            $chk = $db->prepare('SELECT id FROM wishlist WHERE user_id = ? AND product_id = ?');
            $chk->execute([$uid, $productId]);
            if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                $db->prepare('INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)')->execute([$uid, $productId]);
            }
        }
    }
    return !$on;
}
