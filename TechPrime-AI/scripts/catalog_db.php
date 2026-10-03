<?php
/**
 * Catalog DB helper for Oasis import.
 * Usage:
 *   php scripts/catalog_db.php ensure_sku
 *   php scripts/catalog_db.php upsert <json-file>
 *   php scripts/catalog_db.php set_image <json-file>
 *   php scripts/catalog_db.php validate <csv-path>
 *   php scripts/catalog_db.php count
 */
require_once dirname(__DIR__) . '/backend/config/database.php';

$db = getDbConnection();
$cmd = $argv[1] ?? '';

function has_column(PDO $db, string $column): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'products' AND column_name = ?"
    );
    $stmt->execute([$column]);
    return (bool)$stmt->fetchColumn();
}

function seller_id(PDO $db): int
{
    $id = (int)$db->query(
        "SELECT id FROM users WHERE role = 'inventory_custodian' ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($id > 0) {
        return $id;
    }
    $id = (int)$db->query(
        "SELECT id FROM users WHERE role IN ('admin','retail_officer') ORDER BY id LIMIT 1"
    )->fetchColumn();
    return $id > 0 ? $id : 57;
}

if ($cmd === 'ensure_sku') {
    if (!has_column($db, 'sku')) {
        $db->exec('ALTER TABLE products ADD COLUMN sku VARCHAR(64) NULL');
        try {
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_products_sku_unique ON products (sku) WHERE sku IS NOT NULL');
        } catch (Throwable $e) {
            // non-fatal
        }
        echo "SKU_ADDED\n";
    } else {
        echo "SKU_EXISTS\n";
    }
    exit(0);
}

if ($cmd === 'count') {
    echo (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() . "\n";
    exit(0);
}

if ($cmd === 'upsert') {
    $path = $argv[2] ?? '';
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        fwrite(STDERR, "BAD_JSON\n");
        exit(1);
    }
    if (!has_column($db, 'sku')) {
        fwrite(STDERR, "NO_SKU_COLUMN\n");
        exit(1);
    }
    $sku = trim((string)($data['sku'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $desc = (string)($data['description'] ?? '');
    $price = (float)($data['price'] ?? 0);
    $stock = (int)($data['stock'] ?? 0);
    $category = trim((string)($data['category'] ?? 'Others'));
    $image = array_key_exists('image', $data) ? (string)$data['image'] : null;
    $imageUrl = array_key_exists('image_url', $data) ? (string)$data['image_url'] : null;
    $sid = seller_id($db);

    $find = $db->prepare('SELECT id, image, image_url FROM products WHERE sku = ? LIMIT 1');
    $find->execute([$sku]);
    $row = $find->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        if ($image !== null) {
            $stmt = $db->prepare(
                'UPDATE products SET name=?, description=?, price=?, stock=?, category=?, seller_id=?, image=?, image_url=? WHERE id=?'
            );
            $stmt->execute([
                $name, $desc, $price, $stock, $category, $sid,
                $image, $imageUrl ?? '', (int)$row['id'],
            ]);
        } else {
            $stmt = $db->prepare(
                'UPDATE products SET name=?, description=?, price=?, stock=?, category=?, seller_id=? WHERE id=?'
            );
            $stmt->execute([$name, $desc, $price, $stock, $category, $sid, (int)$row['id']]);
        }
        echo json_encode(['action' => 'updated', 'id' => (int)$row['id'], 'sku' => $sku]) . "\n";
    } else {
        $img = $image ?? '';
        $iu = $imageUrl ?? '';
        $stmt = $db->prepare(
            'INSERT INTO products (seller_id, name, price, stock, description, image, image_url, category, sku)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sid, $name, $price, $stock, $desc, $img, $iu, $category, $sku]);
        echo json_encode(['action' => 'inserted', 'id' => (int)$db->lastInsertId(), 'sku' => $sku]) . "\n";
    }
    exit(0);
}

if ($cmd === 'set_image') {
    $path = $argv[2] ?? '';
    $data = json_decode(file_get_contents($path), true);
    $sku = trim((string)($data['sku'] ?? ''));
    $image = (string)($data['image'] ?? '');
    $imageUrl = (string)($data['image_url'] ?? '');
    $stmt = $db->prepare('UPDATE products SET image=?, image_url=? WHERE sku=?');
    $stmt->execute([$image, $imageUrl, $sku]);
    echo json_encode(['updated' => $stmt->rowCount(), 'sku' => $sku]) . "\n";
    exit(0);
}

if ($cmd === 'validate') {
    $csvPath = $argv[2] ?? '';
    if (!is_file($csvPath)) {
        fwrite(STDERR, "NO_CSV\n");
        exit(1);
    }
    $fh = fopen($csvPath, 'r');
    $header = fgetcsv($fh);
    // strip BOM
    if ($header && isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }
    $idx = array_flip($header);
    $mismatches = [];
    $missing = [];
    $ok = 0;
    $total = 0;
    $stmt = $db->prepare('SELECT id, name, stock, price, category, sku FROM products WHERE sku = ?');
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 7) {
            continue;
        }
        $total++;
        $msku = trim((string)$row[$idx['MSKU']]);
        $qoh = (int)trim((string)$row[$idx['Quantity On Hand']]);
        $price = (float)str_replace([',', ' '], '', (string)$row[$idx['Product/Sales Price']]);
        $name = trim(preg_replace('/\s+/u', ' ', (string)$row[$idx['Name']]));
        $stmt->execute([$msku]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            $missing[] = $msku;
            continue;
        }
        if ((int)$p['stock'] !== $qoh) {
            $mismatches[] = ['sku' => $msku, 'csv' => $qoh, 'db' => (int)$p['stock']];
            // auto-correct
            $fix = $db->prepare('UPDATE products SET stock = ? WHERE sku = ?');
            $fix->execute([$qoh, $msku]);
        } else {
            $ok++;
        }
    }
    fclose($fh);
    echo json_encode([
        'total_csv' => $total,
        'stock_ok' => $ok,
        'missing' => $missing,
        'mismatches_corrected' => $mismatches,
        'db_count' => (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

fwrite(STDERR, "Unknown command\n");
exit(1);
