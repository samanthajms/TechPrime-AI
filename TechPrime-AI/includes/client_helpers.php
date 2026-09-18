<?php
/**
 * Shared helpers for CLIENT storefront pages.
 */

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
