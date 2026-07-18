<?php
/**
 * Shared helpers for CLIENT storefront pages.
 */

/** Load cart preview rows for header dropdown. */
function ep_get_cart_preview(mysqli $db): array
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
        while ($row = $res->fetch_assoc()) {
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
function ep_add_product_to_cart(mysqli $db, int $productId, int $qty = 1): bool
{
    if ($productId <= 0 || $qty < 1) {
        return false;
    }

    $chk = $db->prepare(
        'SELECT p.* FROM products p WHERE p.id = ? AND ' . ias_client_product_list_sql_condition('p') . ' LIMIT 1'
    );
    $chk->bind_param('i', $productId);
    $chk->execute();
    $productRow = $chk->get_result()->fetch_assoc();
    $chk->close();

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
        $chk->bind_param('ii', $uid, $productId);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $stmt = $db->prepare('UPDATE cart SET quantity = quantity + ? WHERE user_id = ? AND product_id = ?');
            $stmt->bind_param('iii', $qty, $uid, $productId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $db->prepare('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)');
            $stmt->bind_param('iii', $uid, $productId, $qty);
            $stmt->execute();
            $stmt->close();
        }
        $chk->close();
    }

    return true;
}
