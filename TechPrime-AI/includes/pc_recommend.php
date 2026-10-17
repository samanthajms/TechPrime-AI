<?php
/**
 * Build a PC product recommendation.
 * Compatibility stays in pc_compatibility.php. This file only ranks parts
 * that already pass those rules, using a Random Forest trained on catalog fits.
 */

require_once __DIR__ . '/client_helpers.php';
require_once __DIR__ . '/client_shop_taxonomy.php';
require_once __DIR__ . '/pc_compatibility.php';

/** @return array<string,array{label:string,subs:array<int,string>,exclude?:string,storage?:string}> */
function pc_recommend_slot_map(): array
{
    return [
        'processor' => ['label' => 'Processor', 'subs' => ['processor-amd', 'processor-intel', 'processor-tray']],
        'motherboard' => ['label' => 'Motherboard', 'subs' => ['motherboard']],
        'memory' => ['label' => 'Memory', 'subs' => ['memory'], 'exclude' => '/\bso-?dimm\b/i'],
        'ssd' => ['label' => 'SSD (NVMe / M.2)', 'subs' => ['ssd'], 'storage' => 'nvme'],
        'ssd_sata' => ['label' => 'SSD (SATA)', 'subs' => ['ssd'], 'storage' => 'sata'],
        'hdd' => ['label' => 'Hard Disk', 'subs' => ['hard-disk']],
        'gpu' => ['label' => 'Graphics Card', 'subs' => ['graphics-card']],
        'psu' => ['label' => 'Power Supply', 'subs' => ['power-supply']],
        'case' => ['label' => 'PC Case', 'subs' => ['pc-case']],
        'cooler' => ['label' => 'CPU Cooler', 'subs' => ['cpu-cooling']],
        'case_fan' => ['label' => 'Case Fan', 'subs' => ['chassis-fan']],
    ];
}

function pc_recommend_feature_names(): array
{
    return ['price_ratio', 'socket_ok', 'ddr_ok', 'watt_headroom', 'brand_match', 'in_stock', 'form_slack'];
}

/**
 * @return array<int,array<string,mixed>>
 */
function pc_recommend_products_for_slot(PDO $db, string $slot): array
{
    $map = pc_recommend_slot_map();
    if (!isset($map[$slot])) {
        return [];
    }
    $meta = $map[$slot];
    $vis = ias_client_product_list_sql_condition('p');
    $stmt = $db->prepare(
        "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url, p.description,
                u.name AS seller_name
         FROM products p
         INNER JOIN users u ON u.id = p.seller_id
         WHERE {$vis}
         ORDER BY p.stock DESC, p.name ASC"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->execute();
    $rows = ias_client_filter_products_for_display($stmt->fetchAll(PDO::FETCH_ASSOC));
    $products = [];
    $seen = [];
    foreach ($rows as $p) {
        $id = (int)($p['id'] ?? 0);
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $name = (string)($p['name'] ?? '');
        $category = (string)($p['category'] ?? '');
        $description = (string)($p['description'] ?? '');
        $tax = ep_shop_classify_product($p);
        if ($tax['is_power_station'] || !in_array($tax['sub'], $meta['subs'], true)) {
            continue;
        }
        if (!empty($meta['exclude']) && preg_match($meta['exclude'], $name)) {
            continue;
        }
        if (!empty($meta['storage'])) {
            $isSata = (bool)preg_match('/\bsata\b/i', $name) && !preg_match('/\b(nvme|m\.2|pcie)\b/i', $name);
            if (($meta['storage'] === 'sata') !== $isSata) {
                continue;
            }
        }
        $seen[$id] = true;
        $products[] = [
            'id' => $id,
            'name' => $name,
            'price' => (float)($p['price'] ?? 0),
            'stock' => (int)($p['stock'] ?? 0),
            'category' => $category,
            'type' => (string)$tax['sub_label'],
            'brand' => (string)$tax['brand'],
            'description' => $description,
            'image' => ias_client_product_image_url($p),
            'seller' => (string)($p['seller_name'] ?? ''),
            'compat' => ep_pc_compat_tags($name, $description, $category),
        ];
    }
    return $products;
}

/**
 * @param array<string,mixed> $raw slot => {id,name,price,category,description,brand}
 * @return array<string,array<string,mixed>>
 */
function pc_recommend_build_map(PDO $db, array $raw): array
{
    $ids = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $byId = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT id, name, price, stock, category, description FROM products WHERE id IN ($in)"
        );
        if ($stmt) {
            $stmt->execute(array_values($ids));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byId[(int)$row['id']] = $row;
            }
        }
    }

    $map = [];
    foreach ($raw as $slot => $item) {
        $slot = (string)$slot;
        if (!isset(pc_recommend_slot_map()[$slot]) || !is_array($item)) {
            continue;
        }
        $id = (int)($item['id'] ?? 0);
        $row = $byId[$id] ?? null;
        $name = $row ? (string)$row['name'] : (string)($item['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $category = $row ? (string)$row['category'] : (string)($item['category'] ?? '');
        $description = $row ? (string)$row['description'] : (string)($item['description'] ?? '');
        $price = $row ? (float)$row['price'] : (float)($item['price'] ?? 0);
        $tax = ep_shop_classify_product([
            'name' => $name,
            'category' => $category,
            'description' => $description,
        ]);
        $map[$slot] = [
            'id' => $id,
            'name' => $name,
            'price' => $price,
            'brand' => (string)($tax['brand'] ?? ''),
            'category' => $category,
            'compat' => ep_pc_compat_tags($name, $description, $category),
        ];
    }
    return $map;
}

function pc_recommend_anchor_price(array $buildMap): float
{
    if (!empty($buildMap['processor']['price']) && (float)$buildMap['processor']['price'] > 0) {
        return (float)$buildMap['processor']['price'];
    }
    $sum = 0.0;
    $n = 0;
    foreach ($buildMap as $part) {
        $price = (float)($part['price'] ?? 0);
        if ($price > 0) {
            $sum += $price;
            $n++;
        }
    }
    return $n > 0 ? $sum / $n : 0.0;
}

function pc_recommend_power_estimate(array $buildMap): int
{
    $estimate = 0;
    if (!empty($buildMap['processor'])) {
        $estimate += 65;
    }
    if (!empty($buildMap['motherboard'])) {
        $estimate += 40;
    }
    if (!empty($buildMap['gpu'])) {
        $estimate += 180;
    }
    if (!empty($buildMap['memory'])) {
        $estimate += 10;
    }
    if (!empty($buildMap['ssd']) || !empty($buildMap['ssd_sata']) || !empty($buildMap['hdd'])) {
        $estimate += 15;
    }
    return $estimate;
}

/**
 * @param array<string,mixed> $candidate
 * @return array<string,float>
 */
function pc_recommend_features(string $slot, array $candidate, array $buildMap): array
{
    $tags = $candidate['compat'] ?? ep_pc_compat_tags(
        (string)($candidate['name'] ?? ''),
        (string)($candidate['description'] ?? ''),
        (string)($candidate['category'] ?? '')
    );
    $anchor = pc_recommend_anchor_price($buildMap);
    $price = (float)($candidate['price'] ?? 0);
    $ratio = ($anchor > 0 && $price > 0) ? $price / $anchor : 1.0;

    $socketOk = 1.0;
    $ddrOk = 1.0;
    $cpu = $buildMap['processor']['compat'] ?? null;
    $mobo = $buildMap['motherboard']['compat'] ?? null;
    $ram = $buildMap['memory']['compat'] ?? null;
    if ($slot === 'processor' && $mobo && !empty($mobo['socket']) && !empty($tags['socket']) && $mobo['socket'] !== $tags['socket']) {
        $socketOk = 0.0;
    }
    if ($slot === 'motherboard' && $cpu && !empty($cpu['socket']) && !empty($tags['socket']) && $cpu['socket'] !== $tags['socket']) {
        $socketOk = 0.0;
    }
    if ($slot === 'cooler') {
        $ref = $cpu['socket'] ?? ($mobo['socket'] ?? null);
        if ($ref && !empty($tags['socket']) && $tags['socket'] !== $ref) {
            $socketOk = 0.0;
        }
    }
    if ($slot === 'memory' && !empty($tags['ddr'])) {
        $want = $mobo['ddr'] ?? null;
        if ($want === null && $cpu) {
            $want = ep_pc_cpu_ddr($cpu);
        }
        if ($want !== null && $want !== $tags['ddr']) {
            $ddrOk = 0.0;
        }
    }
    if ($slot === 'motherboard' && $ram && !empty($ram['ddr']) && !empty($tags['ddr']) && $ram['ddr'] !== $tags['ddr']) {
        $ddrOk = 0.0;
    }

    $headroom = 0.0;
    if ($slot === 'psu' && !empty($tags['watts'])) {
        $estimate = max(1, pc_recommend_power_estimate($buildMap));
        $headroom = ((int)$tags['watts']) / $estimate;
    }

    $brand = strtolower(trim((string)($candidate['brand'] ?? '')));
    $brandMatch = 0.0;
    if ($brand !== '') {
        foreach ($buildMap as $part) {
            if (strtolower(trim((string)($part['brand'] ?? ''))) === $brand) {
                $brandMatch = 1.0;
                break;
            }
        }
    }

    $formSlack = 0.0;
    $rank = ['ITX' => 1, 'MATX' => 2, 'ATX' => 3, 'EATX' => 4];
    if ($slot === 'case' && $mobo && !empty($mobo['form']) && !empty($tags['form'])) {
        $caseRank = $rank[$tags['form']] ?? null;
        $moboRank = $rank[$mobo['form']] ?? null;
        if ($caseRank !== null && $moboRank !== null) {
            $formSlack = (float)($caseRank - $moboRank);
        }
    }

    return [
        'price_ratio' => round($ratio, 4),
        'socket_ok' => $socketOk,
        'ddr_ok' => $ddrOk,
        'watt_headroom' => round($headroom, 4),
        'brand_match' => $brandMatch,
        'in_stock' => ((int)($candidate['stock'] ?? 0) > 0) ? 1.0 : 0.0,
        'form_slack' => $formSlack,
    ];
}

/** A compatible part is a good next pick when its price, wattage, and case size fit the build. */
function pc_recommend_is_positive(string $slot, array $features): bool
{
    $ratio = (float)$features['price_ratio'];
    if ($ratio < 0.5 || $ratio > 1.75) {
        return false;
    }
    if ($slot === 'psu') {
        $headroom = (float)$features['watt_headroom'];
        if ($headroom > 0 && ($headroom < 1.2 || $headroom > 2.4)) {
            return false;
        }
    }
    if ($slot === 'case' && (float)$features['form_slack'] > 1) {
        return false;
    }
    return true;
}

function pc_recommend_reason(string $slot, array $features): string
{
    if ($slot === 'psu' && (float)$features['watt_headroom'] >= 1.2 && (float)$features['watt_headroom'] <= 2.4) {
        return 'Wattage fits the parts you already picked.';
    }
    if ((float)$features['brand_match'] >= 1) {
        return 'Same brand as a part already in this build.';
    }
    if ((float)$features['price_ratio'] >= 0.5 && (float)$features['price_ratio'] <= 1.75) {
        return 'Price is in line with the parts you already picked.';
    }
    if ($slot === 'case' && (float)$features['form_slack'] >= 0 && (float)$features['form_slack'] <= 1) {
        return 'Case size fits the motherboard in this build.';
    }
    return 'Fits the parts you already picked.';
}

function pc_recommend_model_path(): string
{
    return dirname(__DIR__) . '/ml/pc_recommend/models/forest.json';
}

/** @return array<string,mixed>|null */
function pc_recommend_model(): ?array
{
    static $model = false;
    if ($model !== false) {
        return $model;
    }
    $path = pc_recommend_model_path();
    if (!is_file($path)) {
        $model = null;
        return null;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    $model = is_array($decoded) && !empty($decoded['trees']) ? $decoded : null;
    return $model;
}

/** @param array<string,float> $features */
function pc_recommend_proba(array $features): ?float
{
    $model = pc_recommend_model();
    if ($model === null) {
        return null;
    }
    $order = $model['features'] ?? pc_recommend_feature_names();
    $x = [];
    foreach ($order as $name) {
        $x[] = (float)($features[$name] ?? 0);
    }
    $trees = $model['trees'];
    if (!$trees) {
        return null;
    }
    $sum = 0.0;
    foreach ($trees as $nodes) {
        $i = 0;
        $guard = 0;
        while ($guard < 64 && isset($nodes[$i]) && (int)($nodes[$i]['f'] ?? -2) >= 0) {
            $feat = (int)$nodes[$i]['f'];
            $i = ($x[$feat] <= (float)$nodes[$i]['t']) ? (int)$nodes[$i]['l'] : (int)$nodes[$i]['r'];
            $guard++;
        }
        $sum += (float)($nodes[$i]['p'] ?? 0);
    }
    return $sum / count($trees);
}

/**
 * @param array<string,mixed> $candidate
 * @return array{score:float,recommended:bool,reason:string}|null
 */
function pc_recommend_score(string $slot, array $candidate, array $buildMap): ?array
{
    if (!$buildMap || pc_recommend_model() === null) {
        return null;
    }
    if (ep_pc_compat_reason_for_candidate($slot, $candidate['compat'] ?? [], $buildMap) !== null) {
        return null;
    }
    $features = pc_recommend_features($slot, $candidate, $buildMap);
    $proba = pc_recommend_proba($features);
    if ($proba === null) {
        return null;
    }
    $threshold = (float)(pc_recommend_model()['threshold'] ?? 0.5);
    $recommended = $proba >= $threshold;
    return [
        'score' => round($proba, 4),
        'recommended' => $recommended,
        'reason' => $recommended ? pc_recommend_reason($slot, $features) : '',
    ];
}
