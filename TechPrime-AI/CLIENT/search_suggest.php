<?php
/**
 * Lightweight search suggestions for the client header search bar.
 * Returns JSON: { suggestions: [ { label, type } ] }
 * Types: category | brand | product — all from real catalog/taxonomy data.
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
if (is_file(__DIR__ . '/../includes/client_shop_taxonomy.php')) {
    require_once __DIR__ . '/../includes/client_shop_taxonomy.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 80) {
    echo json_encode(['suggestions' => []]);
    exit;
}

$qLower = mb_strtolower($q);
$limit = 8;

$seen = [];
$short = [];
$products = [];

$matches = static function (string $label, string $qLower, bool $allowContains): ?int {
    $label = trim($label);
    if ($label === '' || mb_strlen($label) < 2) {
        return null;
    }
    $hay = mb_strtolower($label);
    if (str_starts_with($hay, $qLower)) {
        return 0;
    }
    $stop = ['and', 'for', 'the', 'with', 'from', 'this', 'that', 'a', 'an', 'or', 'of', 'to'];
    if (preg_match_all('/(?:^|[\s\/&+\-])(' . preg_quote($qLower, '/') . '[a-z0-9&+.\-]*)/u', $hay, $wm)) {
        foreach ($wm[1] as $word) {
            if ($word !== '' && !in_array($word, $stop, true) && mb_strlen($word) >= 3) {
                return 0;
            }
        }
    }
    if (!$allowContains) {
        return null;
    }
    if (mb_strpos($hay, $qLower) === false) {
        return null;
    }
    return 1;
};

$usableBrand = static function (string $label, bool $fromNameToken): bool {
    $label = trim($label);
    if ($label === '') {
        return false;
    }
    // Model fragments: A13, A16, BVX650I
    if (preg_match('/^[A-Za-z]\d+[A-Za-z0-9\-]*$/', $label)) {
        return false;
    }
    if ($fromNameToken) {
        if (mb_strlen($label) < 4 || preg_match('/\d/', $label)) {
            return false;
        }
        if (!preg_match('/^[A-Za-z][A-Za-z&+.\-]*$/', $label)) {
            return false;
        }
        $skip = ['with', 'and', 'for', 'the', 'from', 'this', 'that', 'black', 'white', 'mini', 'dual', 'active'];
        return !in_array(mb_strtolower($label), $skip, true);
    }
    // First-token product brand: AMD, ASUS, Acer, HP, A4Tech
    if (mb_strlen($label) < 2) {
        return false;
    }
    return (bool)preg_match('/^[A-Za-z][A-Za-z0-9&+.\-]*$/', $label);
};

$addShort = static function (string $label, string $type) use (&$seen, &$short, $qLower, $matches): void {
    $label = trim($label);
    $rank = $matches($label, $qLower, false);
    if ($rank === null) {
        return;
    }
    $key = mb_strtolower($label);
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $short[] = ['label' => $label, 'type' => $type, 'rank' => $rank];
};

if (function_exists('ep_shop_taxonomy')) {
    foreach (ep_shop_taxonomy() as $node) {
        $addShort((string)($node['label'] ?? ''), 'category');
        foreach ((array)($node['subs'] ?? []) as $subLabel) {
            $addShort((string)$subLabel, 'category');
        }
    }
}

$db = getDbConnection();
$vis = ias_client_product_list_sql_condition('p');
$sql = "SELECT p.name, p.category
        FROM products p
        WHERE {$vis}
        ORDER BY p.name ASC
        LIMIT 400";
$stmt = $db->query($sql);
if ($stmt) {
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = trim((string)($row['name'] ?? ''));
        $cat = trim((string)($row['category'] ?? ''));
        $addShort($cat, 'category');
        $brand = ias_client_product_brand($row);
        if ($usableBrand($brand, false)) {
            $addShort($brand, 'brand');
        }
        if ($name !== '' && preg_match_all('/[A-Za-z][A-Za-z0-9&+.\-]{1,24}/', $name, $wm)) {
            foreach ($wm[0] as $word) {
                if ($usableBrand($word, true)) {
                    $addShort($word, 'brand');
                }
            }
        }
        $nameRank = $matches($name, $qLower, true);
        if ($nameRank !== null) {
            $pkey = 'p:' . mb_strtolower($name);
            if (!isset($seen[$pkey])) {
                $seen[$pkey] = true;
                $products[] = ['label' => $name, 'type' => 'product', 'rank' => $nameRank];
            }
        }
    }
}

usort($short, static function ($a, $b) {
    $typeOrder = ['category' => 0, 'brand' => 1];
    $ta = $typeOrder[$a['type']] ?? 2;
    $tb = $typeOrder[$b['type']] ?? 2;
    if ($ta !== $tb) {
        return $ta <=> $tb;
    }
    if ($a['rank'] !== $b['rank']) {
        return $a['rank'] <=> $b['rank'];
    }
    return strcasecmp($a['label'], $b['label']);
});
usort($products, static function ($a, $b) {
    if ($a['rank'] !== $b['rank']) {
        return $a['rank'] <=> $b['rank'];
    }
    return strcasecmp($a['label'], $b['label']);
});

$suggestions = [];
$cats = [];
$brands = [];
foreach ($short as $item) {
    if ($item['type'] === 'category') {
        $cats[] = $item;
    } else {
        $brands[] = $item;
    }
}
$catN = min(3, count($cats));
$brandN = min(3, count($brands));
while ($catN + $brandN < 6 && $catN < count($cats)) {
    $catN++;
}
while ($catN + $brandN < 6 && $brandN < count($brands)) {
    $brandN++;
}
foreach (array_slice($cats, 0, $catN) as $item) {
    $suggestions[] = ['label' => $item['label'], 'type' => 'category'];
}
foreach (array_slice($brands, 0, $brandN) as $item) {
    $suggestions[] = ['label' => $item['label'], 'type' => 'brand'];
}
foreach ($products as $item) {
    if (count($suggestions) >= $limit) {
        break;
    }
    $suggestions[] = ['label' => $item['label'], 'type' => 'product'];
}

echo json_encode(['suggestions' => $suggestions]);
