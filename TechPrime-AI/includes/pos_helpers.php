<?php
/**
 * Cashier POS: UPC/EAN validation, product lookup by barcode, checkout (stock-out)
 * and receiving (stock-in, done by the Inventory Custodian). Tables come from
 * database/migration_cashier_pos.sql.
 * Money is handled in integer centavos; prices are VAT-inclusive.
 */

require_once __DIR__ . '/inventory_alerts.php';

const POS_VAT_RATE = 0.12;
const POS_MAX_LINE_QTY = 999;
const POS_MAX_STOCK_IN_QTY = 10000;
const POS_PAYMENT_METHODS = [
    'cash' => 'Cash',
    'gcash' => 'GCash',
    'maya' => 'Maya',
    'card' => 'Card',
];
const POS_TIMEZONE = 'Asia/Manila';

/* ---------------------------------------------------------------------
 * Barcodes (UPC-A 12, UPC-E 8, EAN-13 13, EAN-8 8)
 * ------------------------------------------------------------------- */

/** GTIN check digit for the digits before the check digit (weights 3,1,… from the right). */
function pos_gtin_check_digit(string $body): int
{
    $sum = 0;
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $sum += (int)$body[$len - 1 - $i] * ($i % 2 === 0 ? 3 : 1);
    }
    return (10 - $sum % 10) % 10;
}

function pos_gtin_valid(string $code): bool
{
    return strlen($code) >= 8
        && ctype_digit($code)
        && pos_gtin_check_digit(substr($code, 0, -1)) === (int)substr($code, -1);
}

/** Expand an 8-digit UPC-E code to its 12-digit UPC-A form, or null if it cannot be one. */
function pos_upce_to_upca(string $code): ?string
{
    if (strlen($code) !== 8 || !ctype_digit($code) || ($code[0] !== '0' && $code[0] !== '1')) {
        return null;
    }
    $m = substr($code, 1, 6);
    $last = $m[5];
    if ($last <= '2') {
        $body = $m[0] . $m[1] . $last . '0000' . $m[2] . $m[3] . $m[4];
    } elseif ($last === '3') {
        $body = $m[0] . $m[1] . $m[2] . '00000' . $m[3] . $m[4];
    } elseif ($last === '4') {
        $body = $m[0] . $m[1] . $m[2] . $m[3] . '00000' . $m[4];
    } else {
        $body = $m[0] . $m[1] . $m[2] . $m[3] . $m[4] . '0000' . $last;
    }
    return $code[0] . $body . $code[7];
}

/**
 * Validate a scanned/typed code and return its normalized lookup forms.
 * @return array{ok:bool, error?:string, message?:string, type?:string, candidates?:list<string>}
 */
function pos_barcode_analyze(string $raw): array
{
    $code = preg_replace('/\s+/', '', $raw);
    if ($code === '' || !ctype_digit($code)) {
        return ['ok' => false, 'error' => 'invalid_barcode', 'message' => 'Barcode must contain digits only.'];
    }
    $len = strlen($code);
    if (!in_array($len, [8, 12, 13], true)) {
        return ['ok' => false, 'error' => 'invalid_barcode',
            'message' => 'Unsupported barcode length (' . $len . ' digits). Use UPC-A, UPC-E, EAN-13 or EAN-8.'];
    }

    $candidates = [];
    $type = '';
    if ($len === 13 && pos_gtin_valid($code)) {
        $candidates[] = $code;
        $type = 'EAN-13';
    } elseif ($len === 12 && pos_gtin_valid($code)) {
        $candidates[] = '0' . $code;
        $type = 'UPC-A';
    } elseif ($len === 8) {
        if (pos_gtin_valid($code)) {
            $candidates[] = $code;
            $type = 'EAN-8';
        }
        $upca = pos_upce_to_upca($code);
        if ($upca !== null && pos_gtin_valid($upca)) {
            $candidates[] = '0' . $upca;
            $type = $type === '' ? 'UPC-E' : $type . '/UPC-E';
        }
    }

    if (!$candidates) {
        return ['ok' => false, 'error' => 'invalid_check_digit',
            'message' => 'Invalid barcode check digit (' . $code . '). Please scan again.'];
    }
    return ['ok' => true, 'type' => $type, 'candidates' => $candidates];
}

/** Canonical form stored in products.barcode, or null if invalid. */
function pos_normalize_barcode(string $raw): ?string
{
    $a = pos_barcode_analyze($raw);
    return $a['ok'] ? $a['candidates'][0] : null;
}

/**
 * @return array{ok:bool, error?:string, message?:string, product?:array}
 */
function pos_find_product_by_barcode(PDO $db, string $raw): array
{
    $a = pos_barcode_analyze($raw);
    if (!$a['ok']) {
        return $a;
    }
    $ph = implode(',', array_fill(0, count($a['candidates']), '?'));
    $st = $db->prepare("SELECT id, name, category, price, stock, barcode FROM products WHERE barcode IN ($ph) ORDER BY id LIMIT 1");
    $st->execute($a['candidates']);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'not_found',
            'message' => 'No product is registered with barcode ' . preg_replace('/\s+/', '', $raw) . '.'];
    }
    return ['ok' => true, 'product' => pos_product_payload($row)];
}

function pos_product_payload(array $row): array
{
    $stock = (int)$row['stock'];
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'category' => (string)($row['category'] ?? ''),
        'price' => pos_cents_to_str(pos_db_money_cents($row['price'])),
        'stock' => $stock,
        'status' => inv_stock_status_label($stock),
        'barcode' => (string)($row['barcode'] ?? ''),
    ];
}

/* ---------------------------------------------------------------------
 * Money / time helpers
 * ------------------------------------------------------------------- */

function pos_db_money_cents($value): int
{
    return (int)round(((float)$value) * 100);
}

/** Parse user-entered money ("1500", "1500.5", "1,500.50"); null when invalid. */
function pos_parse_money_input($value): ?int
{
    $s = str_replace(',', '', trim((string)$value));
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $s)) {
        return null;
    }
    return (int)round(((float)$s) * 100);
}

function pos_cents_to_str(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

function pos_peso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

/** created_at columns are UTC wall-clock (DB timezone UTC); show them in Manila time. */
function pos_format_datetime(?string $utc, string $format = 'M j, Y g:i A'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone(POS_TIMEZONE))->format($format);
    } catch (Throwable $e) {
        return $utc;
    }
}

/** [start, end) of "today" in Manila, expressed as UTC timestamps for created_at filters. */
function pos_today_bounds_utc(): array
{
    $start = new DateTimeImmutable('today', new DateTimeZone(POS_TIMEZONE));
    $end = $start->modify('+1 day');
    $utc = new DateTimeZone('UTC');
    return [
        $start->setTimezone($utc)->format('Y-m-d H:i:s'),
        $end->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

function pos_payment_label(string $method): string
{
    return POS_PAYMENT_METHODS[$method] ?? ucfirst($method);
}

/* ---------------------------------------------------------------------
 * Transactions: own the transaction, or use a savepoint when the caller
 * already opened one (lets callers compose / roll back as a unit).
 * ------------------------------------------------------------------- */

function pos_tx_begin(PDO $db): ?string
{
    if ($db->inTransaction()) {
        $sp = 'pos_sp_' . bin2hex(random_bytes(4));
        $db->exec('SAVEPOINT ' . $sp);
        return $sp;
    }
    $db->beginTransaction();
    return null;
}

function pos_tx_commit(PDO $db, ?string $sp): void
{
    if ($sp === null) {
        $db->commit();
    } else {
        $db->exec('RELEASE SAVEPOINT ' . $sp);
    }
}

function pos_tx_rollback(PDO $db, ?string $sp): void
{
    try {
        if ($sp === null) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } else {
            $db->exec('ROLLBACK TO SAVEPOINT ' . $sp);
        }
    } catch (Throwable $e) {
        error_log('pos_tx_rollback: ' . $e->getMessage());
    }
}

function pos_err(string $code, string $message, array $extra = []): array
{
    return ['ok' => false, 'error' => $code, 'message' => $message] + $extra;
}

/* ---------------------------------------------------------------------
 * Checkout (stock-out)
 * ------------------------------------------------------------------- */

function pos_sale_summary_by_client_ref(PDO $db, string $clientRef): ?array
{
    $st = $db->prepare('SELECT id, invoice_no, total, change_due FROM pos_sales WHERE client_ref = ?');
    $st->execute([$clientRef]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Complete a POS sale atomically: lock rows, re-validate stock, create the sale +
 * line items, deduct stock, write stock_out movements and the audit log entry.
 *
 * @param list<array{product_id:int|string, qty:int|string}> $items
 * @param array{method?:string, tendered?:mixed, ref?:string} $payment
 * @return array{ok:bool, error?:string, message?:string, sale_id?:int, invoice_no?:string, total?:string, change?:string, duplicate?:bool}
 */
function pos_complete_sale(
    PDO $db,
    int $cashierId,
    string $cashierName,
    array $items,
    array $payment,
    string $clientRef,
    ?int $expectedTotalCents = null
): array {
    $lines = [];
    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        $qty = (int)($it['qty'] ?? 0);
        if ($pid <= 0 || $qty < 1 || $qty > POS_MAX_LINE_QTY) {
            return pos_err('invalid_item', 'Each cart line needs a product and a quantity between 1 and ' . POS_MAX_LINE_QTY . '.');
        }
        $lines[$pid] = ($lines[$pid] ?? 0) + $qty;
    }
    if (!$lines) {
        return pos_err('empty_cart', 'The cart is empty.');
    }
    if (count($lines) > 200) {
        return pos_err('invalid_item', 'Too many different items in one sale.');
    }

    $method = strtolower(trim((string)($payment['method'] ?? '')));
    if (!isset(POS_PAYMENT_METHODS[$method])) {
        return pos_err('invalid_payment', 'Choose a payment method.');
    }
    $ref = trim((string)($payment['ref'] ?? ''));
    $tenderedCents = null;
    if ($method === 'cash') {
        $ref = '';
        $tenderedCents = pos_parse_money_input($payment['tendered'] ?? '');
        if ($tenderedCents === null) {
            return pos_err('invalid_payment', 'Enter the cash amount tendered.');
        }
    } elseif ($ref === '' || strlen($ref) > 64 || !preg_match('/^[A-Za-z0-9 \-\/#]+$/', $ref)) {
        return pos_err('invalid_payment', 'Enter a valid reference number (letters, digits, spaces, - / #; max 64).');
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientRef)) {
        return pos_err('invalid_request', 'Missing transaction reference. Reload the page and try again.');
    }
    $cashierName = trim($cashierName) !== '' ? mb_substr(trim($cashierName), 0, 201) : ('User #' . $cashierId);

    $existing = pos_sale_summary_by_client_ref($db, $clientRef);
    if ($existing) {
        return pos_sale_ok_payload($existing, true);
    }

    $sp = null;
    $stockChanges = [];
    try {
        $sp = pos_tx_begin($db);

        ksort($lines);
        $ids = array_keys($lines);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // Lock in id order so concurrent sales/checkouts cannot deadlock.
        $lock = $db->prepare("SELECT id, name, price, stock, barcode FROM products WHERE id IN ($ph) ORDER BY id FOR UPDATE");
        $lock->execute($ids);
        $rows = [];
        foreach ($lock->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(int)$r['id']] = $r;
        }

        $totalCents = 0;
        foreach ($lines as $pid => $qty) {
            if (!isset($rows[$pid])) {
                pos_tx_rollback($db, $sp);
                return pos_err('not_found', 'A product in the cart no longer exists. Remove it and try again.', ['product_id' => $pid]);
            }
            $stock = (int)$rows[$pid]['stock'];
            if ($qty > $stock) {
                pos_tx_rollback($db, $sp);
                return pos_err(
                    'insufficient_stock',
                    'Not enough stock for "' . $rows[$pid]['name'] . '": ' . $qty . ' requested, ' . $stock . ' available.',
                    ['product_id' => $pid, 'available' => $stock]
                );
            }
            $totalCents += pos_db_money_cents($rows[$pid]['price']) * $qty;
        }

        if ($expectedTotalCents !== null && $expectedTotalCents !== $totalCents) {
            pos_tx_rollback($db, $sp);
            return pos_err('total_mismatch', 'Prices changed since the items were scanned. The cart was refreshed — please review the total.',
                ['total' => pos_cents_to_str($totalCents)]);
        }

        $changeCents = 0;
        if ($method === 'cash') {
            if ($tenderedCents < $totalCents) {
                pos_tx_rollback($db, $sp);
                return pos_err('insufficient_payment', 'Cash tendered (' . pos_peso($tenderedCents / 100) . ') is less than the total ('
                    . pos_peso($totalCents / 100) . ').');
            }
            $changeCents = $tenderedCents - $totalCents;
        }
        $vatableCents = (int)round($totalCents / (1 + POS_VAT_RATE));
        $vatCents = $totalCents - $vatableCents;

        $ins = $db->prepare(
            'INSERT INTO pos_sales (client_ref, cashier_id, cashier_name, subtotal, vatable_sales, vat_amount, total,
                                    payment_method, amount_tendered, change_due, payment_ref)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id, invoice_no, total, change_due'
        );
        $ins->execute([
            $clientRef,
            $cashierId,
            $cashierName,
            pos_cents_to_str($totalCents),
            pos_cents_to_str($vatableCents),
            pos_cents_to_str($vatCents),
            pos_cents_to_str($totalCents),
            $method,
            $tenderedCents !== null ? pos_cents_to_str($tenderedCents) : null,
            pos_cents_to_str($changeCents),
            $ref !== '' ? $ref : null,
        ]);
        $sale = $ins->fetch(PDO::FETCH_ASSOC);
        $saleId = (int)$sale['id'];

        $itemSt = $db->prepare(
            'INSERT INTO pos_sale_items (sale_id, product_id, product_name, barcode, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stockSt = $db->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ? RETURNING stock');
        $movSt = $db->prepare(
            "INSERT INTO stock_movements (product_id, product_name, movement_type, quantity, stock_before, stock_after, sale_id, user_id)
             VALUES (?, ?, 'stock_out', ?, ?, ?, ?, ?)"
        );

        $logParts = [];
        foreach ($lines as $pid => $qty) {
            $row = $rows[$pid];
            $name = (string)$row['name'];
            $priceCents = pos_db_money_cents($row['price']);
            $before = (int)$row['stock'];

            $itemSt->execute([$saleId, $pid, $name, $row['barcode'] ?: null, pos_cents_to_str($priceCents), $qty,
                pos_cents_to_str($priceCents * $qty)]);

            $stockSt->execute([$qty, $pid, $qty]);
            $after = $stockSt->fetchColumn();
            if ($after === false) {
                throw new RuntimeException('Stock guard rejected product #' . $pid);
            }
            $after = (int)$after;

            $movSt->execute([$pid, $name, $qty, $before, $after, $saleId, $cashierId]);
            $stockChanges[] = ['id' => $pid, 'name' => $name, 'before' => $before, 'after' => $after];
            $logParts[] = 'product #' . $pid . ' "' . $name . '" x' . $qty . ': stock ' . $before . ' → ' . $after;
        }

        logActivity(
            $db,
            $cashierId,
            'pos_sale',
            'Sale ' . $sale['invoice_no'] . ' (' . pos_payment_label($method) . ', ' . pos_peso($totalCents / 100) . '): '
                . implode('; ', $logParts)
        );

        pos_tx_commit($db, $sp);
    } catch (Throwable $e) {
        pos_tx_rollback($db, $sp);
        if ($e instanceof PDOException && $e->getCode() === '23505') {
            // Same client_ref submitted concurrently: report the sale that won.
            $existing = pos_sale_summary_by_client_ref($db, $clientRef);
            if ($existing) {
                return pos_sale_ok_payload($existing, true);
            }
        }
        error_log('pos_complete_sale: ' . $e->getMessage());
        return pos_err('exception', 'The sale could not be completed. No changes were saved.');
    }

    // Custodian low/out-of-stock alerts only after the sale is committed.
    foreach ($stockChanges as $c) {
        try {
            inv_notify_stock_change($db, $c['name'], $c['id'], $c['before'], $c['after']);
        } catch (Throwable $e) {
            error_log('pos notify: ' . $e->getMessage());
        }
    }

    return pos_sale_ok_payload($sale, false);
}

function pos_sale_ok_payload(array $sale, bool $duplicate): array
{
    return [
        'ok' => true,
        'duplicate' => $duplicate,
        'sale_id' => (int)$sale['id'],
        'invoice_no' => (string)$sale['invoice_no'],
        'total' => pos_cents_to_str(pos_db_money_cents($sale['total'])),
        'change' => pos_cents_to_str(pos_db_money_cents($sale['change_due'])),
    ];
}

/* ---------------------------------------------------------------------
 * Receiving (stock-in)
 * ------------------------------------------------------------------- */

/**
 * Suppliers for the stock-in dropdown. There is no suppliers table, so a supplier is
 * the product's brand: the first word of the product name (ias_client_product_brand)
 * matched case-insensitively against shop_brands. Only brands that at least one
 * product maps to are offered.
 *
 * @return array{suppliers: string[], by_product: array<int,string>}
 */
function pos_supplier_catalog(PDO $db): array
{
    require_once __DIR__ . '/client_helpers.php';
    require_once __DIR__ . '/client_shop_taxonomy.php';
    ep_shop_brands_ensure_schema($db);

    $brands = [];
    foreach ($db->query('SELECT name FROM shop_brands ORDER BY name') as $row) {
        $name = trim((string)$row['name']);
        $key = mb_strtolower($name);
        if ($name !== '' && !isset($brands[$key])) {
            $brands[$key] = $name;
        }
    }

    $byProduct = [];
    $used = [];
    foreach ($db->query('SELECT id, name FROM products') as $row) {
        $key = mb_strtolower(ias_client_product_brand($row));
        if ($key !== '' && isset($brands[$key])) {
            $byProduct[(int)$row['id']] = $brands[$key];
            $used[$brands[$key]] = true;
        }
    }
    $suppliers = array_keys($used);
    natcasesort($suppliers);

    return ['suppliers' => array_values($suppliers), 'by_product' => $byProduct];
}

/**
 * @return array{ok:bool, error?:string, message?:string, product?:array, stock_before?:int, stock_after?:int, quantity?:int}
 */
function pos_stock_in(PDO $db, int $userId, int $productId, int $qty, string $supplier, string $reference = ''): array
{
    $supplier = trim($supplier);
    $reference = trim($reference);
    if ($productId <= 0) {
        return pos_err('invalid_item', 'Scan a product first.');
    }
    if ($qty < 1 || $qty > POS_MAX_STOCK_IN_QTY) {
        return pos_err('invalid_quantity', 'Quantity must be between 1 and ' . POS_MAX_STOCK_IN_QTY . '.');
    }
    if ($supplier === '') {
        return pos_err('supplier_required', 'Choose the supplier of this delivery.');
    }
    $known = null;
    foreach (pos_supplier_catalog($db)['suppliers'] as $name) {
        if (strcasecmp($name, $supplier) === 0) {
            $known = $name;
            break;
        }
    }
    if ($known === null) {
        return pos_err('invalid_supplier', 'Choose a supplier from the list.');
    }
    $supplier = $known;
    if (mb_strlen($reference) > 64) {
        return pos_err('invalid_reference', 'Reference number is too long (max 64 characters).');
    }

    $sp = null;
    try {
        $sp = pos_tx_begin($db);

        $lock = $db->prepare('SELECT id, name, category, price, stock, barcode FROM products WHERE id = ? FOR UPDATE');
        $lock->execute([$productId]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            pos_tx_rollback($db, $sp);
            return pos_err('not_found', 'That product no longer exists.');
        }
        $before = (int)$row['stock'];

        $up = $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ? RETURNING stock');
        $up->execute([$qty, $productId]);
        $after = (int)$up->fetchColumn();

        $mov = $db->prepare(
            "INSERT INTO stock_movements (product_id, product_name, movement_type, quantity, stock_before, stock_after,
                                         supplier, reference_no, user_id)
             VALUES (?, ?, 'stock_in', ?, ?, ?, ?, ?, ?)"
        );
        $mov->execute([$productId, (string)$row['name'], $qty, $before, $after,
            $supplier !== '' ? $supplier : null, $reference !== '' ? $reference : null, $userId]);

        $details = 'Stock-in product #' . $productId . ' "' . $row['name'] . '": stock ' . $before . ' → ' . $after . ' (+' . $qty . ')';
        if ($supplier !== '') {
            $details .= ', supplier: ' . $supplier;
        }
        if ($reference !== '') {
            $details .= ', ref: ' . $reference;
        }
        logActivity($db, $userId, 'pos_stock_in', $details);

        pos_tx_commit($db, $sp);
    } catch (Throwable $e) {
        pos_tx_rollback($db, $sp);
        error_log('pos_stock_in: ' . $e->getMessage());
        return pos_err('exception', 'Stock-in failed. No changes were saved.');
    }

    try {
        inv_notify_stock_change($db, (string)$row['name'], $productId, $before, $after);
    } catch (Throwable $e) {
        error_log('pos notify: ' . $e->getMessage());
    }

    $row['stock'] = $after;
    return [
        'ok' => true,
        'product' => pos_product_payload($row),
        'stock_before' => $before,
        'stock_after' => $after,
        'quantity' => $qty,
    ];
}

/* ---------------------------------------------------------------------
 * Read models for the cashier pages
 * ------------------------------------------------------------------- */

function pos_today_stats(PDO $db, int $cashierId): array
{
    [$start, $end] = pos_today_bounds_utc();
    $st = $db->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total
         FROM pos_sales
         WHERE cashier_id = ? AND status = 'completed' AND created_at >= ? AND created_at < ?"
    );
    $st->execute([$cashierId, $start, $end]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'total' => 0];

    $items = $db->prepare(
        "SELECT COALESCE(SUM(i.quantity), 0)
         FROM pos_sale_items i JOIN pos_sales s ON s.id = i.sale_id
         WHERE s.cashier_id = ? AND s.status = 'completed' AND s.created_at >= ? AND s.created_at < ?"
    );
    $items->execute([$cashierId, $start, $end]);

    return [
        'count' => (int)$row['cnt'],
        'total' => (float)$row['total'],
        'units' => (int)$items->fetchColumn(),
    ];
}

function pos_recent_sales(PDO $db, int $cashierId, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $st = $db->prepare(
        'SELECT s.id, s.invoice_no, s.total, s.payment_method, s.status, s.created_at,
                (SELECT COALESCE(SUM(quantity), 0) FROM pos_sale_items i WHERE i.sale_id = s.id) AS units
         FROM pos_sales s
         WHERE s.cashier_id = ?
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT ' . $limit
    );
    $st->execute([$cashierId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** A sale with its items, only if it belongs to the given cashier. */
function pos_get_sale_for_cashier(PDO $db, int $saleId, int $cashierId): ?array
{
    $st = $db->prepare('SELECT * FROM pos_sales WHERE id = ? AND cashier_id = ?');
    $st->execute([$saleId, $cashierId]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        return null;
    }
    $it = $db->prepare(
        'SELECT i.product_name, i.barcode, i.unit_price, i.quantity, i.line_total, p.sku
         FROM pos_sale_items i
         LEFT JOIN products p ON p.id = i.product_id
         WHERE i.sale_id = ?
         ORDER BY i.id'
    );
    $it->execute([$saleId]);
    $sale['items'] = $it->fetchAll(PDO::FETCH_ASSOC);
    return $sale;
}

/* ---------------------------------------------------------------------
 * End of Day (cashier closing / reconciliation). The pos_eod_reports table
 * comes from database/migration_pos_eod.sql; pages feature-detect it so the
 * report still renders (read-only) before the migration has been run.
 * ------------------------------------------------------------------- */

/** Today's Manila calendar date as 'Y-m-d'. */
function pos_today_date(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(POS_TIMEZONE)))->format('Y-m-d');
}

/** A valid business day 'Y-m-d' (not in the future), or null. */
function pos_parse_business_date(string $raw): ?string
{
    $raw = trim($raw);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone(POS_TIMEZONE));
    if (!$d || $d->format('Y-m-d') !== $raw || $raw < '2020-01-01' || $raw > pos_today_date()) {
        return null;
    }
    return $raw;
}

/** [start, end) of a Manila calendar day, expressed as UTC timestamps for created_at filters. */
function pos_day_bounds_utc(string $ymd): array
{
    $start = new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone(POS_TIMEZONE));
    $end = $start->modify('+1 day');
    $utc = new DateTimeZone('UTC');
    return [
        $start->setTimezone($utc)->format('Y-m-d H:i:s'),
        $end->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

/** Every sale (completed and voided) a cashier rang up on one business day, oldest first. */
function pos_day_sales(PDO $db, int $cashierId, string $ymd): array
{
    [$start, $end] = pos_day_bounds_utc($ymd);
    $st = $db->prepare(
        'SELECT s.id, s.invoice_no, s.total, s.payment_method, s.payment_ref, s.amount_tendered, s.change_due,
                s.status, s.created_at,
                (SELECT COALESCE(SUM(quantity), 0) FROM pos_sale_items i WHERE i.sale_id = s.id) AS units
         FROM pos_sales s
         WHERE s.cashier_id = ? AND s.created_at >= ? AND s.created_at < ?
         ORDER BY s.created_at, s.id'
    );
    $st->execute([$cashierId, $start, $end]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Totals of one day's sales. Voided sales are counted apart and left out of the money.
 * Money is in centavos. methods: [method => ['count' => int, 'total' => cents]] for every POS method.
 */
function pos_day_summary(array $sales): array
{
    $sum = ['count' => 0, 'voided' => 0, 'units' => 0, 'gross' => 0, 'methods' => []];
    foreach (array_keys(POS_PAYMENT_METHODS) as $m) {
        $sum['methods'][$m] = ['count' => 0, 'total' => 0];
    }
    foreach ($sales as $s) {
        if ((string)$s['status'] !== 'completed') {
            $sum['voided']++;
            continue;
        }
        $cents = pos_db_money_cents($s['total']);
        $m = (string)$s['payment_method'];
        $sum['count']++;
        $sum['units'] += (int)$s['units'];
        $sum['gross'] += $cents;
        if (isset($sum['methods'][$m])) {
            $sum['methods'][$m]['count']++;
            $sum['methods'][$m]['total'] += $cents;
        }
    }
    return $sum;
}

/** Expected amount per payment method in centavos; cash includes the opening (change) fund. */
function pos_eod_expected(array $summary, int $openingCents): array
{
    $out = [];
    foreach ($summary['methods'] as $m => $row) {
        $out[$m] = $row['total'] + ($m === 'cash' ? $openingCents : 0);
    }
    return $out;
}

/** Short / over / balanced for a difference in centavos (counted - expected). */
function pos_eod_result(int $diffCents): array
{
    if ($diffCents === 0) {
        return ['key' => 'balanced', 'label' => 'Balanced', 'icon' => 'ph-check-circle'];
    }
    return $diffCents < 0
        ? ['key' => 'short', 'label' => 'Short ' . pos_peso(-$diffCents / 100), 'icon' => 'ph-arrow-down']
        : ['key' => 'over', 'label' => 'Over ' . pos_peso($diffCents / 100), 'icon' => 'ph-arrow-up'];
}

/** True once database/migration_pos_eod.sql has been run. */
function pos_eod_available(PDO $db): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $st = $db->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = current_schema() AND table_name = 'pos_eod_reports'"
            );
            $ok = (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** A saved closing with its money as centavos (expected[] / counted[] per method), or null. */
function pos_eod_get(PDO $db, int $cashierId, string $ymd): ?array
{
    if (!pos_eod_available($db)) {
        return null;
    }
    $st = $db->prepare('SELECT * FROM pos_eod_reports WHERE cashier_id = ? AND business_date = ?');
    $st->execute([$cashierId, $ymd]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? pos_eod_row($row) : null;
}

/** The cashier's latest closings, newest day first. */
function pos_eod_recent(PDO $db, int $cashierId, int $limit = 7): array
{
    if (!pos_eod_available($db)) {
        return [];
    }
    $limit = max(1, min(31, $limit));
    $st = $db->prepare(
        'SELECT * FROM pos_eod_reports WHERE cashier_id = ? ORDER BY business_date DESC LIMIT ' . $limit
    );
    $st->execute([$cashierId]);
    return array_map('pos_eod_row', $st->fetchAll(PDO::FETCH_ASSOC));
}

function pos_eod_row(array $row): array
{
    $row['business_date'] = substr((string)$row['business_date'], 0, 10);
    $row['expected'] = [];
    $row['counted'] = [];
    foreach (array_keys(POS_PAYMENT_METHODS) as $m) {
        $row['expected'][$m] = pos_db_money_cents($row['expected_' . $m] ?? 0);
        $row['counted'][$m] = pos_db_money_cents($row['counted_' . $m] ?? 0);
    }
    $row['opening_cents'] = pos_db_money_cents($row['opening_fund']);
    $row['gross_cents'] = pos_db_money_cents($row['gross_sales']);
    $row['difference'] = array_sum($row['counted']) - array_sum($row['expected']);
    $row['cash_difference'] = $row['counted']['cash'] - $row['expected']['cash'];
    return $row;
}

/** One saved closing by id (any cashier), for the manager review page. */
function pos_eod_get_by_id(PDO $db, int $id): ?array
{
    if ($id <= 0 || !pos_eod_available($db)) {
        return null;
    }
    $st = $db->prepare('SELECT * FROM pos_eod_reports WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? pos_eod_row($row) : null;
}

/**
 * Manager review (Admin, Retail Officer): every saved closing in [from, to] plus every cashier day
 * with POS sales that has no closing, newest day first. $cashierId 0 = all cashiers.
 * Each row: business_date, cashier_id, cashier_name, closing (pos_eod_row() or null), status
 * (open | balanced | short | over, from the all-payments difference), sales_count, gross_cents,
 * stale (sales changed after the closing was saved).
 */
function pos_eod_review_rows(PDO $db, string $from, string $to, int $cashierId = 0): array
{
    [$startUtc] = pos_day_bounds_utc($from);
    [, $endUtc] = pos_day_bounds_utc($to);
    $cashierSql = $cashierId > 0 ? ' AND s.cashier_id = ?' : '';
    $params = [$startUtc, $endUtc];
    if ($cashierId > 0) {
        $params[] = $cashierId;
    }
    $days = $db->prepare(
        "SELECT s.cashier_id, MAX(s.cashier_name) AS cashier_name,
                ((s.created_at AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Manila')::date AS d,
                COUNT(*) FILTER (WHERE s.status = 'completed') AS cnt,
                COUNT(*) FILTER (WHERE s.status <> 'completed') AS voided,
                COALESCE(SUM(s.total) FILTER (WHERE s.status = 'completed'), 0) AS gross
         FROM pos_sales s
         WHERE s.cashier_id IS NOT NULL AND s.created_at >= ? AND s.created_at < ?" . $cashierSql . '
         GROUP BY s.cashier_id, d'
    );
    $days->execute($params);
    $live = [];
    foreach ($days->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $live[(int)$d['cashier_id'] . '|' . substr((string)$d['d'], 0, 10)] = $d;
    }

    $closings = [];
    if (pos_eod_available($db)) {
        $st = $db->prepare(
            'SELECT * FROM pos_eod_reports WHERE business_date BETWEEN ? AND ?' . ($cashierId > 0 ? ' AND cashier_id = ?' : '')
        );
        $st->execute($cashierId > 0 ? [$from, $to, $cashierId] : [$from, $to]);
        $closings = array_map('pos_eod_row', $st->fetchAll(PDO::FETCH_ASSOC));
    }

    $rows = [];
    foreach ($closings as $c) {
        $key = (int)$c['cashier_id'] . '|' . $c['business_date'];
        $d = $live[$key] ?? null;
        unset($live[$key]);
        $stale = (int)($d['cnt'] ?? 0) !== (int)$c['sales_count'] || (int)($d['voided'] ?? 0) !== (int)$c['voided_count']
            || pos_db_money_cents($d['gross'] ?? 0) !== $c['gross_cents'];
        $rows[] = [
            'business_date' => $c['business_date'],
            'cashier_id' => (int)$c['cashier_id'],
            'cashier_name' => (string)$c['cashier_name'],
            'closing' => $c,
            'status' => pos_eod_result($c['difference'])['key'],
            'sales_count' => (int)$c['sales_count'],
            'gross_cents' => $c['gross_cents'],
            'stale' => $stale,
        ];
    }
    foreach ($live as $d) {
        if ((int)$d['cnt'] === 0 && (int)$d['voided'] === 0) {
            continue;
        }
        $rows[] = [
            'business_date' => substr((string)$d['d'], 0, 10),
            'cashier_id' => (int)$d['cashier_id'],
            'cashier_name' => (string)$d['cashier_name'],
            'closing' => null,
            'status' => 'open',
            'sales_count' => (int)$d['cnt'],
            'gross_cents' => pos_db_money_cents($d['gross']),
            'stale' => false,
        ];
    }
    usort($rows, fn($a, $b) => strcmp($b['business_date'], $a['business_date']) ?: strcasecmp($a['cashier_name'], $b['cashier_name']));
    return $rows;
}

/** Cashier accounts for the review filter: [id => display name]. */
function pos_cashier_options(PDO $db): array
{
    $out = [];
    foreach ($db->query("SELECT id, name, surname FROM users WHERE role = 'cashier' ORDER BY name, surname, id") as $u) {
        $out[(int)$u['id']] = trim($u['name'] . ' ' . $u['surname']) ?: ('User #' . (int)$u['id']);
    }
    return $out;
}

/**
 * Save (or re-save) a cashier's closing for one business day. The expected totals are always
 * recomputed from pos_sales here, never taken from the form. $input: opening_fund, counted[method], notes.
 * Every save is written to the activity log; a re-save records the previous counted cash.
 */
function pos_eod_save(PDO $db, int $cashierId, string $cashierName, string $ymd, array $input): array
{
    if (!pos_eod_available($db)) {
        return pos_err('eod_unavailable', 'End-of-day closings cannot be saved until the database migration has been run.');
    }
    $day = pos_parse_business_date($ymd);
    if ($day === null) {
        return pos_err('eod_invalid', 'Choose a valid business day (not in the future).');
    }
    $opening = pos_parse_money_input(($input['opening_fund'] ?? '') === '' ? '0' : $input['opening_fund']);
    if ($opening === null) {
        return pos_err('eod_invalid', 'Enter the opening fund as an amount, e.g. 1000 or 1000.50.');
    }
    $counted = [];
    foreach (POS_PAYMENT_METHODS as $m => $label) {
        $c = pos_parse_money_input($input['counted'][$m] ?? '');
        if ($c === null) {
            return pos_err('eod_invalid', 'Enter the counted ' . $label . ' total (0 if none).');
        }
        $counted[$m] = $c;
    }
    $notes = trim((string)($input['notes'] ?? ''));
    if (mb_strlen($notes) > 500) {
        return pos_err('eod_invalid', 'Notes can be at most 500 characters.');
    }
    $cashierName = trim($cashierName) !== '' ? mb_substr(trim($cashierName), 0, 201) : ('User #' . $cashierId);

    $sp = pos_tx_begin($db);
    try {
        $prev = $db->prepare('SELECT counted_cash FROM pos_eod_reports WHERE cashier_id = ? AND business_date = ? FOR UPDATE');
        $prev->execute([$cashierId, $day]);
        $prevCash = $prev->fetchColumn();

        $sum = pos_day_summary(pos_day_sales($db, $cashierId, $day));
        $expected = pos_eod_expected($sum, $opening);

        $cols = ['cashier_id', 'cashier_name', 'business_date', 'sales_count', 'voided_count', 'units_sold',
                 'gross_sales', 'opening_fund', 'notes'];
        $vals = [$cashierId, $cashierName, $day, $sum['count'], $sum['voided'], $sum['units'],
                 pos_cents_to_str($sum['gross']), pos_cents_to_str($opening), $notes !== '' ? $notes : null];
        foreach (array_keys(POS_PAYMENT_METHODS) as $m) {
            array_push($cols, 'expected_' . $m, 'counted_' . $m);
            array_push($vals, pos_cents_to_str($expected[$m]), pos_cents_to_str($counted[$m]));
        }
        $updates = [];
        foreach (array_slice($cols, 1) as $c) {
            if ($c !== 'business_date') {
                $updates[] = $c . ' = EXCLUDED.' . $c;
            }
        }
        $updates[] = 'updated_at = CURRENT_TIMESTAMP';
        $db->prepare(
            'INSERT INTO pos_eod_reports (' . implode(', ', $cols) . ')
             VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')
             ON CONFLICT (cashier_id, business_date) DO UPDATE SET ' . implode(', ', $updates)
        )->execute($vals);

        $diff = array_sum($counted) - array_sum($expected);
        $cashDiff = $counted['cash'] - $expected['cash'];
        $details = 'End of day ' . $day . ': ' . $sum['count'] . ' sale' . ($sum['count'] === 1 ? '' : 's')
            . ', ' . pos_peso($sum['gross'] / 100) . '; cash counted ' . pos_peso($counted['cash'] / 100)
            . ' vs expected ' . pos_peso($expected['cash'] / 100) . ' (' . pos_eod_result($cashDiff)['label'] . ')'
            . '; all methods ' . pos_eod_result($diff)['label'];
        if ($prevCash !== false) {
            $details .= '; updated, cash counted was ' . pos_peso((float)$prevCash);
        }
        logActivity($db, $cashierId, $prevCash !== false ? 'pos_eod_update' : 'pos_eod_close', $details);

        pos_tx_commit($db, $sp);
    } catch (Throwable $e) {
        pos_tx_rollback($db, $sp);
        error_log('pos_eod_save: ' . $e->getMessage());
        return pos_err('server_error', 'Could not save the closing. Please try again.');
    }

    return ['ok' => true, 'updated' => $prevCash !== false, 'difference' => $diff, 'cash_difference' => $cashDiff];
}

function pos_recent_stock_ins(PDO $db, int $userId, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $st = $db->prepare(
        "SELECT product_name, quantity, stock_before, stock_after, supplier, reference_no, created_at
         FROM stock_movements
         WHERE user_id = ? AND movement_type = 'stock_in'
         ORDER BY created_at DESC, id DESC
         LIMIT " . $limit
    );
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Read-only low/critical/out-of-stock alerts (same thresholds as the Inventory module),
 * lowest stock first. Every alerting product is returned so the list always matches
 * the counts; items and counts come from the same query.
 */
function pos_stock_alerts(PDO $db): array
{
    $rows = $db->query(
        'SELECT id, name, category, price, stock, barcode FROM products WHERE stock <= 15 ORDER BY stock ASC, name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $out = ['out' => 0, 'critical' => 0, 'low' => 0, 'items' => []];
    foreach ($rows as $row) {
        $item = pos_product_payload($row);
        $out[$item['status']]++;
        $out['items'][] = $item;
    }
    return $out;
}

/* ---------------------------------------------------------------------
 * JSON API guard shared by backend/api/barcode_lookup|stock_in|stock_out|stock_alerts.php
 * ------------------------------------------------------------------- */

function pos_json(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Cashier-only endpoint guard: JSON headers, session timeout, role, method, CSRF (POST). */
function pos_api_require_cashier(string $method): array
{
    return pos_api_require_role(['cashier'], $method, 'Cashier access only.');
}

/** Same guard for endpoints shared with (or owned by) other staff roles. */
function pos_api_require_role(array $roles, string $method, string $forbiddenMessage): array
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    checkSessionTimeout();

    if (!isset($_SESSION['user_id'], $_SESSION['role']) || !in_array($_SESSION['role'], $roles, true)) {
        pos_json(403, ['ok' => false, 'error' => 'forbidden', 'message' => $forbiddenMessage]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        pos_json(405, ['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Method not allowed.']);
    }

    $input = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $input = json_decode((string)$raw, true);
        if (!is_array($input)) {
            pos_json(400, ['ok' => false, 'error' => 'invalid_request', 'message' => 'Invalid request body.']);
        }
        if (!verifyCsrfToken((string)($input['csrf_token'] ?? ''))) {
            pos_json(400, ['ok' => false, 'error' => 'invalid_token', 'message' => 'Security token expired. Reload the page.']);
        }
    }
    return $input;
}
