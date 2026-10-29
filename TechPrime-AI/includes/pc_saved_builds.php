<?php
/**
 * Saved builds (Build a PC) as price quotes. Each part keeps the price it had when the build
 * was saved, and components_json._priced_at holds that time (UTC). When any part's current
 * price differs, or a part is no longer sold, the quote has expired: the customer updates the
 * build to today's prices before adding it to the cart (saved_builds_api.php action=refresh_prices).
 * Used by saved_builds_api.php and saved_builds.php.
 */

/**
 * Current price and stock of the products in a build, by product id.
 *
 * @param array<string,mixed> $comps components_json (non-part entries such as _purpose are skipped)
 * @return array<int,array{price:float,stock:int}>
 */
function pc_saved_build_products(PDO $db, array $comps): array
{
    $ids = [];
    foreach ($comps as $item) {
        $id = is_array($item) ? (int)($item['id'] ?? 0) : 0;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, price, stock FROM products WHERE id IN ($in)");
    $stmt->execute(array_values($ids));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['id']] = ['price' => round((float)$row['price'], 2), 'stock' => (int)$row['stock']];
    }
    return $out;
}

/**
 * Compares the saved prices with the current ones.
 *
 * @param array<int,array{price:float,stock:int}> $current from pc_saved_build_products()
 * @return array{expired:bool,changes:array<int,array{slot:string,name:string,old:float,new:float}>,missing:array<int,string>,sold_out:array<int,string>,current_total:float}
 */
function pc_saved_build_price_check(array $comps, array $current): array
{
    $changes = [];
    $missing = [];
    $soldOut = [];
    $total = 0.0;
    foreach ($comps as $slot => $item) {
        $id = is_array($item) ? (int)($item['id'] ?? 0) : 0;
        /* A part the customer already owns ("I already have this") is not bought, so its price and stock don't matter. */
        if ($id <= 0 || !empty($item['owned'])) {
            continue;
        }
        $name = (string)($item['name'] ?? 'Product');
        $now = $current[$id] ?? null;
        if ($now === null) {
            $missing[] = $name;
            continue;
        }
        $old = round((float)($item['price'] ?? 0), 2);
        if (abs($old - $now['price']) >= 0.01) {
            $changes[] = ['slot' => (string)$slot, 'name' => $name, 'old' => $old, 'new' => $now['price']];
        }
        if ($now['stock'] <= 0) {
            $soldOut[] = $name;
        }
        $total += $now['price'];
    }
    return [
        'expired' => $changes || $missing,
        'changes' => $changes,
        'missing' => $missing,
        'sold_out' => $soldOut,
        'current_total' => round($total, 2),
    ];
}

/**
 * The build at today's prices: each part's price is replaced with the current one, parts that
 * are no longer sold are dropped, and _priced_at is set to now.
 *
 * @return array{components:array<string,mixed>,total:float,count:int}
 */
function pc_saved_build_reprice(array $comps, array $current): array
{
    $total = 0.0;
    $count = 0;
    foreach ($comps as $slot => $item) {
        if (!is_array($item)) {
            continue;
        }
        if (!empty($item['owned'])) {
            $count++;
            continue;
        }
        $now = $current[(int)($item['id'] ?? 0)] ?? null;
        if ($now === null) {
            unset($comps[$slot]);
            continue;
        }
        $comps[$slot]['price'] = $now['price'];
        $total += $now['price'];
        $count++;
    }
    $comps['_priced_at'] = gmdate('Y-m-d H:i:s');
    return ['components' => $comps, 'total' => round($total, 2), 'count' => $count];
}

/** When the saved prices were taken (UTC); builds saved before _priced_at existed use created_at. */
function pc_saved_build_priced_at(array $comps, string $createdAt): string
{
    $at = $comps['_priced_at'] ?? '';
    return is_string($at) && $at !== '' ? $at : $createdAt;
}

/** A UTC timestamp shown in Manila time, e.g. "Oct 29, 2026 · 3:15 PM" ('' when unreadable). */
function pc_saved_build_time_label(string $utc): string
{
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format('M j, Y · g:i A');
    } catch (Throwable $e) {
        return '';
    }
}
