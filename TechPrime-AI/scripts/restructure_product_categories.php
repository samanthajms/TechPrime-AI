<?php
/**
 * Remap product categories to the 7 client categories and move images.
 * Does not change stock, price, name, description, or sku.
 */
require_once dirname(__DIR__) . '/backend/config/database.php';

$root = dirname(__DIR__);
$db = getDbConnection();

function final_category(string $raw): string
{
    $key = strtolower(trim(preg_replace('/\s+/', ' ', $raw)));
    $key = str_replace(['&', '/'], ['and', ' '], $key);
    $key = preg_replace('/\s+/', ' ', $key);

    $map = [
        'desktop' => 'Display',
        'display' => 'Display',
        'laptops' => 'Laptops',
        'laptop ga2' => 'Laptops',
        'laptop ga3' => 'Laptops',
        'laptop pr2' => 'Laptops',
        'laptop pr3' => 'Laptops',
        'audio' => 'Audio',
        'cooling' => 'Cooling',
        'speaker' => 'Speaker',
        'keyboard' => 'Accessories',
        'mouse' => 'Accessories',
        'pc case' => 'Accessories',
        'accessories' => 'Accessories',
        'combo' => 'Others',
        'customization' => 'Others',
        'cuztomization' => 'Others',
        'gaming surface' => 'Others',
        'gpu' => 'Others',
        'graphic card' => 'Others',
        'hard disk' => 'Others',
        'home and office furniture' => 'Others',
        'memory' => 'Others',
        'ram' => 'Others',
        'mini pc' => 'Others',
        'network device' => 'Others',
        'power station' => 'Others',
        'power supply' => 'Others',
        'processor' => 'Others',
        'software' => 'Others',
        'softwware' => 'Others',
        'solid state drive' => 'Others',
        'ups and avr' => 'Others',
        'ups & avr' => 'Others',
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    if (str_starts_with($key, 'laptop')) {
        return 'Laptops';
    }
    return 'Others';
}

$rows = $db->query('SELECT id, sku, name, category, stock, image, image_url FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$update = $db->prepare('UPDATE products SET category = ?, image = ?, image_url = ? WHERE id = ?');

$moved = 0;
$already = 0;
$counts = [];
$before = [];

foreach ($rows as $row) {
    $old = (string)($row['category'] ?? '');
    $before[$old] = ($before[$old] ?? 0) + 1;
    $final = final_category($old);
    $counts[$final] = ($counts[$final] ?? 0) + 1;

    $image = str_replace('\\', '/', trim((string)($row['image'] ?? '')));
    $imageUrl = str_replace('\\', '/', trim((string)($row['image_url'] ?? '')));
    $newImage = $image;
    $newUrl = $imageUrl;

    if ($image !== '' && str_starts_with($image, 'assets/products/')) {
        $src = $root . '/' . $image;
        $base = basename($image);
        $destRel = 'assets/products/' . $final . '/' . $base;
        $dest = $root . '/' . $destRel;
        if (is_file($src)) {
            if (strtolower(str_replace('\\', '/', $src)) !== strtolower(str_replace('\\', '/', $dest))) {
                $dir = dirname($dest);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                if (is_file($dest)) {
                    $baseName = pathinfo($base, PATHINFO_FILENAME);
                    $ext = pathinfo($base, PATHINFO_EXTENSION);
                    $sku = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$row['sku']);
                    $destRel = 'assets/products/' . $final . '/' . $baseName . ' - ' . $sku . '.' . $ext;
                    $dest = $root . '/' . $destRel;
                }
                if (!rename($src, $dest)) {
                    fwrite(STDERR, "MOVE_FAIL {$row['sku']} {$image}\n");
                } else {
                    $moved++;
                }
            } else {
                $already++;
            }
            $newImage = $destRel;
            if ($imageUrl === $image || str_starts_with($imageUrl, 'assets/products/')) {
                $newUrl = $destRel;
            }
        }
    }

    $update->execute([$final, $newImage, $newUrl, (int)$row['id']]);
}

// Remove empty leftover category folders
$productsDir = $root . '/assets/products';
if (is_dir($productsDir)) {
    foreach (scandir($productsDir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $productsDir . '/' . $entry;
        if (!is_dir($path)) {
            continue;
        }
        $files = array_diff(scandir($path), ['.', '..']);
        if ($files === []) {
            rmdir($path);
        }
    }
}

// Update manifest paths from current DB image paths
$manifest = $root . '/product_image_manifest.csv';
if (is_file($manifest)) {
    $bySku = [];
    $imgRows = $db->query("SELECT sku, image FROM products WHERE image IS NOT NULL AND BTRIM(image) <> ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($imgRows as $r) {
        $bySku[(string)$r['sku']] = str_replace('\\', '/', (string)$r['image']);
    }
    $fh = fopen($manifest, 'r');
    $header = fgetcsv($fh);
    $outRows = [];
    while (($line = fgetcsv($fh)) !== false) {
        $assoc = array_combine($header, array_pad($line, count($header), ''));
        $sku = $assoc['MSKU'] ?? '';
        if (isset($bySku[$sku]) && ($assoc['Image Status'] ?? '') !== '') {
            $assoc['Image Relative Path'] = $bySku[$sku];
            $assoc['Image Filename'] = basename($bySku[$sku]);
        }
        $outRows[] = $assoc;
    }
    fclose($fh);
    $out = fopen($manifest, 'w');
    fputcsv($out, $header);
    foreach ($outRows as $assoc) {
        $line = [];
        foreach ($header as $col) {
            $line[] = $assoc[$col] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
}

echo "PRODUCTS " . count($rows) . PHP_EOL;
echo "MOVED $moved ALREADY $already" . PHP_EOL;
echo "BEFORE " . json_encode($before) . PHP_EOL;
ksort($counts);
echo "AFTER " . json_encode($counts) . PHP_EOL;
echo "SUM " . array_sum($counts) . PHP_EOL;

$stock = (int)$db->query('SELECT COALESCE(SUM(stock),0) FROM products')->fetchColumn();
echo "STOCK_SUM $stock" . PHP_EOL;
