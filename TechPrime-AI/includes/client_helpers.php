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
 * Place a COD order with stock checks, atomic deduction, and notifications.
 * @param list<array{id:int,qty:int,price:float|int,name?:string}> $items
 * @return array{ok:bool,order_id?:int,error?:string}
 */
function ep_place_cod_order(PDO $db, int $userId, array $items, float $total, string $address, string $phone): array
{
    if ($userId <= 0 || empty($items) || $address === '' || $phone === '') {
        return ['ok' => false, 'error' => 'invalid'];
    }

    try {
        $db->beginTransaction();

        $ins = $db->prepare(
            "INSERT INTO orders (user_id, total, status, shipping_address, customer_phone) VALUES (?, ?, 'to_ship', ?, ?)"
        );
        $ins->execute([$userId, $total, $address, $phone]);
        $orderId = (int)$db->lastInsertId();
        if ($orderId <= 0) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'create_failed'];
        }

        $itemStmt = $db->prepare('INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)');
        $stockStmt = $db->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND COALESCE(stock, 0) >= ?'
        );

        foreach ($items as $item) {
            $pid = (int)($item['id'] ?? 0);
            $qty = (int)($item['qty'] ?? 0);
            $price = (float)($item['price'] ?? 0);
            if ($pid <= 0 || $qty < 1) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'invalid_item'];
            }
            $itemStmt->execute([$orderId, $pid, $qty, $price]);
            $stockStmt->execute([$qty, $pid, $qty]);
            if ($stockStmt->rowCount() < 1) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'stock'];
            }
            $nameStmt = $db->prepare('SELECT name, stock FROM products WHERE id = ?');
            $nameStmt->execute([$pid]);
            $prod = $nameStmt->fetch(PDO::FETCH_ASSOC);
            if ($prod) {
                inv_notify_stock_change(
                    $db,
                    (string)$prod['name'],
                    $pid,
                    (int)$prod['stock'] + $qty,
                    (int)$prod['stock']
                );
            }
        }

        $db->prepare('DELETE FROM cart WHERE user_id = ?')->execute([$userId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'exception'];
    }

    unset($_SESSION['cart']);

    inv_notify_user(
        $db,
        $userId,
        "Your order #ORD-{$orderId} was placed successfully.",
        'order_placed',
        'user_dashboard.php'
    );
    inv_notify_role(
        $db,
        'inventory_custodian',
        "New order #ORD-{$orderId} placed (₱" . number_format($total, 2) . ").",
        'new_order',
        'inventory_orders.php'
    );

    return ['ok' => true, 'order_id' => $orderId];
}

/**
 * Cancel an order if still cancellable. Restores stock exactly once (idempotent).
 * @return array{ok:bool,error?:string}
 */
function ep_cancel_order(PDO $db, int $userId, int $orderId): array
{
    if ($userId <= 0 || $orderId <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }

    $cancellable = ['to_pay', 'to_ship', 'To Pay', 'To Ship', 'Pending', 'pending'];

    try {
        $db->beginTransaction();

        $st = $db->prepare('SELECT id, status, total FROM orders WHERE id = ? AND user_id = ? FOR UPDATE');
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
        if (!in_array($status, $cancellable, true)) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_cancellable'];
        }

        $up = $db->prepare(
            "UPDATE orders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = ?"
        );
        $up->execute([$orderId, $userId, $status]);
        if ($up->rowCount() < 1) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'already_cancelled'];
        }

        $items = $db->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
        $items->execute([$orderId]);
        $restore = $db->prepare('UPDATE products SET stock = COALESCE(stock, 0) + ? WHERE id = ?');
        while ($row = $items->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int)$row['product_id'];
            $qty = (int)$row['quantity'];
            if ($pid > 0 && $qty > 0) {
                $before = $db->prepare('SELECT name, stock FROM products WHERE id = ?');
                $before->execute([$pid]);
                $prod = $before->fetch(PDO::FETCH_ASSOC);
                $restore->execute([$qty, $pid]);
                if ($prod) {
                    inv_notify_stock_change(
                        $db,
                        (string)$prod['name'],
                        $pid,
                        (int)$prod['stock'],
                        (int)$prod['stock'] + $qty
                    );
                }
            }
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
        "Your order #ORD-{$orderId} was cancelled. Stock has been restored.",
        'order_cancelled',
        'user_dashboard.php'
    );
    inv_notify_role(
        $db,
        'inventory_custodian',
        "Order #ORD-{$orderId} was cancelled by the customer.",
        'order_cancelled',
        'inventory_orders.php'
    );

    return ['ok' => true];
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
    $res = $db->query("SELECT id, name, price FROM products WHERE id IN ($idsStr)");
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
            ];
            $total += $subtotal;
            $count += $qty;
        }
    }

    return ['items' => $items, 'total' => $total, 'count' => $count];
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
