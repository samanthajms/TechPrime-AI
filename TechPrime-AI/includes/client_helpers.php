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
    inv_notify_role(
        $db,
        'inventory_custodian',
        "New order #ORD-{$orderId} placed — waiting for the customer's payment.",
        'order_update',
        'inventory_orders.php?status=to_pay'
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

/** True when the order is a store-pickup order (no shipping step). */
function ep_order_is_pickup(array $order): bool
{
    return strtolower(trim((string)($order['fulfillment_type'] ?? ''))) === 'pickup';
}

/** Make sure a pickup order has a shipment row in 'processing' (no carrier). Runs inside the caller's transaction. */
function ep_set_pickup_shipment(PDO $db, int $orderId): void
{
    $chk = $db->prepare('SELECT id FROM shipments WHERE order_id = ? LIMIT 1');
    $chk->execute([$orderId]);
    $ship = $chk->fetch(PDO::FETCH_ASSOC);
    if ($ship) {
        $db->prepare("UPDATE shipments SET shipment_status = 'processing' WHERE id = ?")->execute([(int)$ship['id']]);
    } else {
        $db->prepare("INSERT INTO shipments (order_id, shipment_status) VALUES (?, 'processing')")->execute([$orderId]);
    }
}

/**
 * Create/update the order's shipment row as 'processing'. Delivery orders get carrier LALAMOVE;
 * if the shipments.carrier column rejects that value (older enum) the carrier is left unset instead
 * of failing the whole accept. Runs inside the caller's transaction (SAVEPOINT).
 */
function ep_set_order_shipment(PDO $db, int $orderId, bool $isDelivery): void
{
    $chk = $db->prepare('SELECT id FROM shipments WHERE order_id = ? LIMIT 1');
    $chk->execute([$orderId]);
    $shipId = (int)$chk->fetchColumn();

    if ($isDelivery) {
        $db->exec('SAVEPOINT ep_ship_carrier');
        try {
            if ($shipId > 0) {
                $db->prepare("UPDATE shipments SET shipment_status = 'processing', carrier = 'LALAMOVE' WHERE id = ?")
                    ->execute([$shipId]);
            } else {
                $db->prepare("INSERT INTO shipments (order_id, shipment_status, carrier) VALUES (?, 'processing', 'LALAMOVE')")
                    ->execute([$orderId]);
            }
            $db->exec('RELEASE SAVEPOINT ep_ship_carrier');
            return;
        } catch (Throwable $e) {
            $db->exec('ROLLBACK TO SAVEPOINT ep_ship_carrier');
            error_log('ep_set_order_shipment #' . $orderId . ' (carrier rejected): ' . $e->getMessage());
        }
    }
    if ($shipId > 0) {
        $db->prepare("UPDATE shipments SET shipment_status = 'processing' WHERE id = ?")->execute([$shipId]);
    } else {
        $db->prepare("INSERT INTO shipments (order_id, shipment_status) VALUES (?, 'processing')")->execute([$orderId]);
    }
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
            'SELECT id, status, payment_status, fulfillment_type FROM orders WHERE id = ? AND user_id = ? FOR UPDATE'
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
        $isPickup = ep_order_is_pickup($order);
        if (strtolower((string)($order['payment_status'] ?? '')) === 'paid') {
            $db->commit();
            return ['ok' => true];
        }

        $up = $db->prepare(
            "UPDATE orders
             SET payment_status = 'paid',
                 payment_method = 'online',
                 payment_ref = ?,
                 paid_at = CURRENT_TIMESTAMP,
                 status = ?
             WHERE id = ? AND user_id = ? AND status = 'to_pay'
               AND COALESCE(payment_status, 'unpaid') <> 'paid'"
        );
        // Pickup orders skip To Ship: ready for pickup right after payment.
        $up->execute([$paymentRef, $isPickup ? 'to_receive' : 'to_pay', $orderId, $userId]);
        if ($up->rowCount() < 1) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'already_paid'];
        }
        if ($isPickup) {
            ep_set_pickup_shipment($db, $orderId);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'exception'];
    }

    if ($isPickup) {
        inv_notify_user(
            $db,
            $userId,
            "Payment successful for order #ORD-{$orderId}. It is now To Receive — pick it up at the store.",
            'order_paid',
            'user_dashboard.php?status=to_receive'
        );
        inv_notify_role(
            $db,
            'inventory_custodian',
            "Paid pickup order #ORD-{$orderId} — prepare it for store pickup.",
            'new_order',
            'inventory_orders.php?status=to_receive'
        );
        return ['ok' => true];
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
    $nextStatus = 'to_ship';
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

        $nextStatus = ep_order_is_pickup($order) ? 'to_receive' : 'to_ship';
        $up = $db->prepare(
            "UPDATE orders SET status = ?
             WHERE id = ? AND status = 'to_pay' AND COALESCE(cancel_requested, FALSE) = FALSE"
        );
        $up->execute([$nextStatus, $orderId]);
        if ($up->rowCount() < 1) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_acceptable'];
        }

        $isDelivery = strtolower(trim((string)($order['fulfillment_type'] ?? ''))) === 'delivery';
        ep_set_order_shipment($db, $orderId, $isDelivery);

        $clientId = (int)$order['user_id'];
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('ep_accept_order #' . $orderId . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'exception'];
    }

    if ($clientId > 0) {
        inv_notify_user(
            $db,
            $clientId,
            "Your order #ORD-{$orderId} was accepted.",
            'order_status',
            'user_dashboard.php?status=' . $nextStatus
        );
    }
    if ($staffUserId > 0 && function_exists('logActivity')) {
        logActivity($db, $staffUserId, 'accept_order', "Order #$orderId accepted → " . $nextStatus);
    }
    return ['ok' => true, 'status' => $nextStatus];
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
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }
    return ep_complete_order($db, $orderId, $userId, 0);
}

/**
 * Mark an order Completed (to_review): deduct stock once, mark the shipment delivered and
 * notify the customer, inventory custodians and retail officers (sales are read live from
 * completed orders). Used by the client "Item Received" and the custodian status dropdown.
 *
 * @param int $ownerUserId Restrict to this customer's order (client flow); 0 = staff flow, any order
 * @param int $staffUserId Staff user doing the update (0 for the client flow)
 * @return array{ok:bool,error?:string,already?:bool}
 */
function ep_complete_order(PDO $db, int $orderId, int $ownerUserId = 0, int $staffUserId = 0): array
{
    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }

    $userId = $ownerUserId;
    $alreadyCompleted = false;
    try {
        $db->beginTransaction();
        if ($ownerUserId > 0) {
            $st = $db->prepare(
                'SELECT id, user_id, status, payment_status, stock_deducted, cancel_requested
                 FROM orders WHERE id = ? AND user_id = ? FOR UPDATE'
            );
            $st->execute([$orderId, $ownerUserId]);
        } else {
            $st = $db->prepare(
                'SELECT id, user_id, status, payment_status, stock_deducted, cancel_requested
                 FROM orders WHERE id = ? FOR UPDATE'
            );
            $st->execute([$orderId]);
        }
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_found'];
        }

        $userId = (int)$order['user_id'];
        $status = (string)($order['status'] ?? '');
        $alreadyDeducted = !empty($order['stock_deducted']);

        if ($status === 'to_review' && $alreadyDeducted) {
            $db->commit();
            return ['ok' => true, 'already' => true];
        }

        // The customer can only confirm receipt once the order is To Receive; staff may complete from To Ship too.
        $completableFrom = $ownerUserId > 0 ? ['to_receive'] : ['to_ship', 'to_receive'];
        if (!in_array($status, $completableFrom, true)) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_receivable'];
        }
        if (!empty($order['cancel_requested'])) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'cancel_pending'];
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
        'user_dashboard.php?status=history'
    );
    inv_notify_role(
        $db,
        'inventory_custodian',
        "Order #ORD-{$orderId} Item Received — stock deducted once.",
        'order_received',
        'inventory_orders.php'
    );
    inv_notify_role(
        $db,
        'retail_officer',
        "Order #ORD-{$orderId} completed — recorded as a sale in your reports.",
        'order_completed',
        'retail_dashboard.php'
    );
    if ($staffUserId > 0 && function_exists('logActivity')) {
        logActivity($db, $staffUserId, 'complete_order', "Order #$orderId completed — stock deducted, sale recorded");
    }

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
 * Client requests cancellation (To Pay and still unpaid only). Needs Inventory Custodian Accept Cancel.
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
            'SELECT id, status, payment_status, cancel_requested, stock_deducted
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
        // Once paid (or To Ship / To Receive / Completed) the customer can no longer cancel.
        if (strtolower((string)($order['payment_status'] ?? '')) === 'paid') {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_cancellable'];
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
               AND COALESCE(payment_status, 'unpaid') <> 'paid'
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
        if (!empty($order['stock_deducted']) || (string)$order['status'] !== 'to_pay') {
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
 * Warranty from the "Product/Warranty Type: ..." line of a product description (catalog import), in plain words:
 * "1 YR WTY." -> "1-year warranty", "6 MON WTY" -> "6-month warranty", "12 Months EasyFix Warranty" ->
 * "12-month EasyFix warranty", "EASYFIX WTY." -> "EasyFix warranty", "NO WTY." -> "No warranty",
 * "7 DAYS REPLACEMENT." -> "7-day replacement". '' when the description lists none.
 */
function ep_product_warranty(string $description): string
{
    if (!preg_match('/warranty[^:\n]*:[ \t]*([^\n]+)/i', $description, $m)) {
        return '';
    }
    $raw = strtoupper(trim(rtrim(trim($m[1]), '.')));
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^NO\b/', $raw)) {
        return 'No warranty';
    }
    $easyFix = str_contains($raw, 'EASYFIX');
    if (preg_match('/(\d+)\s*(YRS?|YEARS?|MONTHS?|MON|WKS?|WEEKS?|DAYS?)\b/', $raw, $n)) {
        $unit = ['Y' => 'year', 'M' => 'month', 'W' => 'week', 'D' => 'day'][$n[2][0]];
        $what = str_contains($raw, 'REPLACEMENT') ? 'replacement' : ($easyFix ? 'EasyFix warranty' : 'warranty');
        return $n[1] . '-' . $unit . ' ' . $what;
    }
    return $easyFix ? 'EasyFix warranty' : ucfirst(strtolower($raw));
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

/* ---- Recently viewed products ----
 * Kept in a browser cookie (ep_recent: product ids, newest first) so guests get it too
 * and it lasts across visits. No DB table: products are re-read on display, so prices,
 * stock and visibility are always current. Written by product_detail.php, shown on the
 * homepage and under the product page.
 */
const EP_RECENT_COOKIE = 'ep_recent';
const EP_RECENT_MAX = 12;

function ep_recent_ids(): array
{
    $raw = (string)($_COOKIE[EP_RECENT_COOKIE] ?? '');
    $ids = [];
    foreach (explode(',', $raw) as $part) {
        $id = (int)$part;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        if (count($ids) >= EP_RECENT_MAX) {
            break;
        }
    }
    return $ids;
}

/** Moves $productId to the front of the list. Must run before any page output. */
function ep_recent_record(int $productId): void
{
    if ($productId <= 0 || headers_sent()) {
        return;
    }
    $ids = array_values(array_diff(ep_recent_ids(), [$productId]));
    array_unshift($ids, $productId);
    $ids = array_slice($ids, 0, EP_RECENT_MAX);
    $value = implode(',', $ids);
    setcookie(EP_RECENT_COOKIE, $value, [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[EP_RECENT_COOKIE] = $value;
}

/** Recently viewed products that are still listed, newest first. */
function ep_recent_products(PDO $db, int $excludeId = 0, int $limit = 8): array
{
    $ids = array_values(array_diff(ep_recent_ids(), [$excludeId]));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $vis = ias_client_product_list_sql_condition('p');
    $stmt = $db->prepare(
        "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url
         FROM products p WHERE p.id IN ({$in}) AND {$vis}"
    );
    $stmt->execute($ids);
    $byId = [];
    foreach (ias_client_filter_products_for_display($stmt->fetchAll(PDO::FETCH_ASSOC)) as $p) {
        $byId[(int)$p['id']] = $p;
    }
    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $out[] = $byId[$id];
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}
