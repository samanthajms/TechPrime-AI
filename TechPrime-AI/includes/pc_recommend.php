<?php
/**
 * Build a PC product recommendation.
 * Compatibility stays in pc_compatibility.php. This file only ranks parts
 * that already pass those rules, using a Random Forest trained on expert-rule
 * examples from the catalog plus real customer picks (saved builds, orders, POS sales).
 * Part specs (performance tier, wattage, RAM kit, cooler size, ...) come from pc_specs.php;
 * the customer's purpose (School, Office, Gaming, ...) and budget steer what counts as a good fit.
 */

require_once __DIR__ . '/client_helpers.php';
require_once __DIR__ . '/client_shop_taxonomy.php';
require_once __DIR__ . '/pc_compatibility.php';
require_once __DIR__ . '/pc_specs.php';
require_once __DIR__ . '/pc_purpose.php';
/* "Build it for me" upgrades by the Build Score; pc_rating.php requires this file back, which require_once allows. */
require_once __DIR__ . '/pc_rating.php';

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

/**
 * Add-ons sold with a build: not part of the PC itself, so no compatibility rules, no Build Score and
 * no model. A first PC still needs them (a screen, input devices, Windows, power protection), so the
 * builder lists them like parts.
 *
 * @return array<string,array{label:string,subs:array<int,string>}>
 */
function pc_recommend_addon_map(): array
{
    return [
        'monitor' => ['label' => 'Monitor', 'subs' => ['monitor']],
        'keyboard' => ['label' => 'Keyboard', 'subs' => ['keyboard', 'keyboard-mouse']],
        'mouse' => ['label' => 'Mouse', 'subs' => ['mouse']],
        'os' => ['label' => 'Operating System', 'subs' => ['operating-system']],
        'ups' => ['label' => 'UPS / AVR', 'subs' => ['ups-avr']],
    ];
}

/** Must match FEATURES in ml/pc_recommend/train_model.py. */
function pc_recommend_feature_names(): array
{
    return [
        'purpose_school', 'purpose_office', 'purpose_gaming', 'purpose_creation', 'slot_code', 'tier_gap', 'build_level', 'gpu_level', 'perf_tier', 'balance', 'cooling_margin',
        'dual_channel', 'psu_headroom', 'psu_rating', 'psu_atx3', 'case_bundle', 'form_slack', 'brand_match',
        'cand_igpu', 'cpu_igpu', 'cpu_cooler', 'vram', 'budget_slack',
    ];
}

/**
 * Where a price sits among one slot's products: 0 = cheapest, 1 = most expensive.
 *
 * @param array<int,float> $prices
 */
function pc_recommend_price_tier(array $prices, float $price): float
{
    $n = count($prices);
    if ($n === 0 || $price <= 0) {
        return 0.5;
    }
    $below = 0;
    $same = 0;
    foreach ($prices as $p) {
        if ($p < $price) {
            $below++;
        } elseif ($p == $price) {
            $same++;
        }
    }
    return round(($below + 0.5 * $same) / $n, 4);
}

/**
 * Every slot's shop products, loaded once per request, each with its price tier.
 * Called without $db it returns what was loaded already (empty lists before the first load).
 *
 * @return array<string,array<int,array<string,mixed>>>
 */
function pc_recommend_catalog(?PDO $db = null): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }
    if ($db === null) {
        return array_fill_keys(array_keys(pc_recommend_slot_map()), []);
    }
    $catalog = pc_recommend_classify_rows(pc_recommend_product_rows($db, false));
    foreach ($catalog as $slot => $products) {
        $prices = array_column($products, 'price');
        foreach ($products as $i => $product) {
            $catalog[$slot][$i]['tier'] = pc_recommend_price_tier($prices, (float)$product['price']);
        }
    }
    return $catalog;
}

/**
 * Shop products as rows for pc_recommend_classify_rows(), loaded once per request: the in-stock
 * listing (shop rule: stock > 0), or the sold-out products that would otherwise be listed.
 *
 * @return array<int,array<string,mixed>>
 */
function pc_recommend_product_rows(PDO $db, bool $soldOut): array
{
    static $rows = [];
    if (isset($rows[(int)$soldOut])) {
        return $rows[(int)$soldOut];
    }
    $where = $soldOut
        ? "COALESCE(p.stock, 0) <= 0
           AND p.seller_id IS NOT NULL AND p.seller_id > 0
           AND p.image IS NOT NULL AND TRIM(p.image) <> ''"
        : ias_client_product_list_sql_condition('p');
    $stmt = $db->prepare(
        "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url, p.description,
                u.name AS seller_name
         FROM products p
         INNER JOIN users u ON u.id = p.seller_id
         WHERE {$where}
         ORDER BY " . ($soldOut ? 'p.name ASC' : 'p.stock DESC, p.name ASC')
    );
    $rows[(int)$soldOut] = $stmt && $stmt->execute() ? ias_client_filter_products_for_display($stmt->fetchAll(PDO::FETCH_ASSOC)) : [];
    return $rows[(int)$soldOut];
}

/**
 * Sorts product rows into build slots by the shop taxonomy (one row can fit several slots).
 *
 * @param array<int,array<string,mixed>> $rows products joined with u.name AS seller_name
 * @param array<string,array<string,mixed>>|null $map slot map (default: the PC parts)
 * @return array<string,array<int,array<string,mixed>>>
 */
function pc_recommend_classify_rows(array $rows, ?array $map = null): array
{
    $map ??= pc_recommend_slot_map();
    $catalog = array_fill_keys(array_keys($map), []);
    $seen = [];
    foreach ($rows as $p) {
        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = (string)($p['name'] ?? '');
        $category = (string)($p['category'] ?? '');
        $description = (string)($p['description'] ?? '');
        $tax = ep_shop_classify_product($p);
        if ($tax['is_power_station']) {
            continue;
        }
        foreach ($map as $slot => $meta) {
            if (isset($seen[$slot][$id]) || !in_array($tax['sub'], $meta['subs'], true)) {
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
            $seen[$slot][$id] = true;
            $catalog[$slot][] = [
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
                'warranty' => ep_product_warranty($description),
            ];
        }
    }
    return $catalog;
}

/**
 * Out-of-stock shop products for one slot. The catalog above (shop listing rule: stock > 0)
 * stays the only source for the model, budgets and Build it for me; these are only shown in
 * the picker, still scored, so a good part is recommended even when it is sold out.
 * Price tier is measured against the in-stock prices, the same scale the model was trained on.
 *
 * @return array<int,array<string,mixed>>
 */
function pc_recommend_sold_out(PDO $db, string $slot): array
{
    static $soldOut = null;
    if ($soldOut === null) {
        $soldOut = pc_recommend_classify_rows(pc_recommend_product_rows($db, true));
    }
    $prices = array_column(pc_recommend_catalog($db)[$slot] ?? [], 'price');
    $out = [];
    foreach ($soldOut[$slot] ?? [] as $product) {
        $product['tier'] = pc_recommend_price_tier($prices, (float)$product['price']);
        $out[] = $product;
    }
    return $out;
}

/**
 * Picker list for an add-on slot (pc_recommend_addon_map()): in-stock products first, then sold-out ones,
 * each with a short note on what it is for. No model scores; the picker's Recommended sort keeps this order.
 *
 * @return array<int,array<string,mixed>>
 */
function pc_recommend_addon_products(PDO $db, string $slot): array
{
    $map = pc_recommend_addon_map();
    if (!isset($map[$slot])) {
        return [];
    }
    $out = array_merge(
        pc_recommend_classify_rows(pc_recommend_product_rows($db, false), $map)[$slot] ?? [],
        pc_recommend_classify_rows(pc_recommend_product_rows($db, true), $map)[$slot] ?? []
    );
    foreach ($out as $i => $p) {
        $out[$i]['addon'] = true;
        $out[$i]['recommend_note'] = pc_recommend_addon_note($slot, (string)$p['name']);
    }
    return $out;
}

/** One line on what an add-on is for, read from its name (monitor size and refresh rate, UPS vs AVR, ...). */
function pc_recommend_addon_note(string $slot, string $name): string
{
    switch ($slot) {
        case 'monitor':
            $size = preg_match('/(\d{2}(?:\.\d)?)\s*(?:"|”|-?\s*inch|in\b)/i', $name, $m) ? $m[1] . '-inch screen' : '';
            $hz = preg_match('/(\d{2,3})\s*hz/i', $name, $h) ? (int)$h[1] : 0;
            $use = $hz >= 120 ? 'smooth for fast games' : ($hz > 0 ? 'fine for work, school and videos' : '');
            return ucfirst(implode(', ', array_filter([$size, $hz > 0 ? $hz . ' Hz' : '', $use])));
        case 'keyboard':
            if (preg_match('/\bmouse\b|combo/i', $name)) {
                return 'Keyboard and mouse set: covers both.';
            }
            return preg_match('/mechanical/i', $name) ? 'Mechanical keyboard: durable and good for gaming.' : '';
        case 'mouse':
            return preg_match('/wireless/i', $name) ? 'Wireless mouse.' : '';
        case 'os':
            return 'Genuine Windows license: a new PC needs one to install Windows.';
        case 'ups':
            return preg_match('/\bups\b/i', $name)
                ? 'Battery backup: keeps the PC on for a few minutes during a brownout so you can save and shut down.'
                : 'Voltage regulator: protects against power spikes and drops (no battery backup).';
    }
    return '';
}

/**
 * The in-stock part to suggest instead of a sold-out one: a part the model recommends first,
 * then a good fit, then any compatible part; within that, the price closest to the sold-out part.
 *
 * @param array<int,array<string,mixed>> $scored in-stock products after pc_recommend_apply()
 * @return array{id:int,name:string,price:float}|null
 */
function pc_recommend_pick_alternative(array $scored, float $price, int $skipId = 0): ?array
{
    $best = null;
    $bestKey = null;
    foreach ($scored as $p) {
        if ((int)$p['id'] === $skipId || (int)($p['stock'] ?? 0) <= 0 || !isset($p['recommend_score'])) {
            continue;
        }
        $gap = $price > 0 ? -abs(log(max(1.0, (float)$p['price']) / $price)) : 0.0;
        $key = [!empty($p['recommended']) ? 2 : (!empty($p['recommend_reason']) ? 1 : 0), $gap, (float)$p['recommend_score']];
        if ($bestKey === null || $key > $bestKey) {
            $best = $p;
            $bestKey = $key;
        }
    }
    return $best === null ? null : ['id' => (int)$best['id'], 'name' => (string)$best['name'], 'price' => (float)$best['price']];
}

/**
 * @return array<int,array<string,mixed>>
 */
function pc_recommend_products_for_slot(PDO $db, string $slot): array
{
    return pc_recommend_catalog($db)[$slot] ?? [];
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

    $catalog = pc_recommend_catalog($db);
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
            /* Current stock (0 when the product no longer exists). */
            'stock' => $row ? (int)$row['stock'] : 0,
            'tier' => pc_recommend_price_tier(array_column($catalog[$slot] ?? [], 'price'), $price),
            'brand' => (string)($tax['brand'] ?? ''),
            'category' => $category,
            'compat' => ep_pc_compat_tags($name, $description, $category),
            'warranty' => ep_product_warranty($description),
        ];
    }
    return $map;
}

/** Average price tier of the parts already in the build (0.5 when it is empty). */
function pc_recommend_build_tier(array $buildMap): float
{
    $sum = 0.0;
    $n = 0;
    foreach ($buildMap as $part) {
        if (isset($part['tier'])) {
            $sum += (float)$part['tier'];
            $n++;
        }
    }
    return $n > 0 ? $sum / $n : 0.5;
}

/** Specs read from a part's name (see pc_specs.php). */
function pc_recommend_spec(string $slot, array $part): array
{
    $name = (string)($part['name'] ?? '');
    return match ($slot) {
        'processor' => pc_spec_cpu($name),
        'gpu' => pc_spec_gpu($name),
        'motherboard' => pc_spec_board($name),
        'memory' => pc_spec_memory($name),
        'ssd', 'ssd_sata', 'hdd' => pc_spec_storage($name),
        'psu' => pc_spec_psu($name),
        'cooler' => pc_spec_cooler($name, (float)($part['price'] ?? 0)),
        'case' => pc_spec_case($name),
        'case_fan' => ['fans' => pc_spec_fan_count($name)],
        default => [],
    };
}

/**
 * Performance level of the build, 0-1: the CPU / GPU tiers when picked (leaving out $skip),
 * otherwise the average price tier of the parts picked so far. An empty build takes its
 * level from the budget (see pc_recommend_budget_level()).
 */
function pc_recommend_build_level(array $buildMap, string $skip = '', array $ctx = []): float
{
    $tiers = [];
    foreach (['processor', 'gpu'] as $slot) {
        if ($slot !== $skip && !empty($buildMap[$slot])) {
            $tiers[] = pc_recommend_spec($slot, $buildMap[$slot])['tier'] / 10;
        }
    }
    if ($tiers) {
        return array_sum($tiers) / count($tiers);
    }
    $rest = $buildMap;
    unset($rest[$skip]);
    if (!$rest && (float)($ctx['budget'] ?? 0) > 0) {
        return pc_recommend_budget_level(pc_purpose_key($ctx['purpose'] ?? ''), (float)$ctx['budget']);
    }
    return pc_recommend_build_tier($rest);
}

/**
 * How high a budget sits for its purpose, as a performance level: the Starter / Recommended /
 * High-end suggestions (pc_recommend_budget_guide()) map to 0.35 / 0.55 / 0.8, in between linearly.
 */
function pc_recommend_budget_level(string $purpose, float $budget): float
{
    $tiers = pc_recommend_budget_guide()['purposes'][$purpose] ?? null;
    if (!$tiers || $budget <= 0) {
        return 0.5;
    }
    $points = [[(float)$tiers['starter'], 0.35], [(float)$tiers['recommended'], 0.55], [(float)$tiers['high'], 0.8]];
    if ($budget <= $points[0][0]) {
        return max(0.2, 0.35 * $budget / max(1.0, $points[0][0]));
    }
    for ($i = 1; $i < count($points); $i++) {
        if ($budget <= $points[$i][0]) {
            [$b0, $l0] = $points[$i - 1];
            [$b1, $l1] = $points[$i];
            return $l0 + ($l1 - $l0) * ($budget - $b0) / max(1.0, $b1 - $b0);
        }
    }
    return min(0.95, 0.8 + 0.15 * ($budget - $points[2][0]) / max(1.0, $points[2][0]));
}

/** Watts the finished PC draws: CPU and GPU from the spec table, plus about 75 W for board, RAM, drives and fans. */
function pc_recommend_power_estimate(array $buildMap): int
{
    $cpu = !empty($buildMap['processor']) ? pc_recommend_spec('processor', $buildMap['processor'])['watts'] : 65;
    $gpu = !empty($buildMap['gpu']) ? pc_recommend_spec('gpu', $buildMap['gpu'])['watts'] : 0;
    return $cpu + $gpu + 75;
}

/** Smallest PSU worth suggesting: 30% over the estimated draw, at least 450 W. */
function pc_recommend_psu_need(array $buildMap): int
{
    return max(450, (int)ceil(pc_recommend_power_estimate($buildMap) * 1.3 / 50) * 50);
}

/**
 * What the customer asked for: purpose (pc_purpose.php) and budget in pesos (0 = no budget).
 *
 * @return array{purpose:string,budget:float}
 */
function pc_recommend_context(array $payload): array
{
    return [
        'purpose' => pc_purpose_key($payload['purpose'] ?? ''),
        'budget' => max(0.0, (float)($payload['budget'] ?? 0)),
    ];
}

/** A graphics card is required for gaming and content creation, or when the CPU has no built-in graphics. */
function pc_recommend_gpu_needed(array $buildMap, string $purpose): bool
{
    if (in_array($purpose, ['gaming', 'creation'], true)) {
        return true;
    }
    return !empty($buildMap['processor']) && !pc_recommend_spec('processor', $buildMap['processor'])['igpu'];
}

/**
 * Required parts that could no longer be added: missing from the build and with no compatible
 * in-stock option left (e.g. an Intel CPU when only AMD motherboards are in stock).
 *
 * @return array<int,string> slot keys
 */
function pc_recommend_dead_ends(array $buildMap, string $purpose): array
{
    $required = [['processor'], ['motherboard'], ['memory'], ['ssd', 'ssd_sata', 'hdd'], ['psu'], ['case']];
    if (pc_recommend_gpu_needed($buildMap, $purpose)) {
        $required[] = ['gpu'];
    }
    $catalog = pc_recommend_catalog();
    $dead = [];
    foreach ($required as $slots) {
        foreach ($slots as $s) {
            if (!empty($buildMap[$s])) {
                continue 2;
            }
            foreach ($catalog[$s] ?? [] as $p) {
                if (ep_pc_compat_reason_for_candidate($s, $p['compat'], $buildMap) === null) {
                    continue 3;
                }
            }
        }
        $dead[] = $slots[0];
    }
    return $dead;
}

/**
 * Budget check for one candidate: money left after the parts already picked, and what would
 * remain after buying the candidate while keeping enough for the cheapest catalog option of
 * every required part that is still missing (negative = it leaves too little).
 *
 * @return array{left:float,after:float}
 */
function pc_recommend_budget_room(string $slot, array $candidate, array $buildMap, array $ctx): array
{
    $spent = 0.0;
    foreach ($buildMap as $s => $part) {
        if ($s !== $slot) {
            $spent += (float)($part['price'] ?? 0);
        }
    }
    $left = (float)($ctx['budget'] ?? 0) - $spent;
    $with = $buildMap;
    $with[$slot] = $candidate;
    $required = [['processor'], ['motherboard'], ['memory'], ['ssd', 'ssd_sata', 'hdd'], ['psu'], ['case']];
    if (pc_recommend_gpu_needed($with, (string)($ctx['purpose'] ?? 'balanced'))) {
        $required[] = ['gpu'];
    }
    /* A CPU sold without a cooler (MPK / tray) needs one. */
    if (!empty($with['processor']) && !pc_recommend_spec('processor', $with['processor'])['cooler']) {
        $required[] = ['cooler'];
    }
    $catalog = pc_recommend_catalog();
    $reserve = 0.0;
    foreach ($required as $slots) {
        $cheapest = null;
        foreach ($slots as $s) {
            if (!empty($with[$s])) {
                continue 2;
            }
            /* Cheapest option that still fits the build (an AM5 CPU needs DDR5 memory, ...). */
            foreach ($catalog[$s] ?? [] as $p) {
                if ($p['price'] > 0 && ($cheapest === null || $p['price'] < $cheapest)
                    && ep_pc_compat_reason_for_candidate($s, $p['compat'], $with) === null) {
                    $cheapest = (float)$p['price'];
                }
            }
        }
        $reserve += $cheapest ?? 0.0;
    }
    return ['left' => $left, 'after' => $left - (float)($candidate['price'] ?? 0) - $reserve];
}

/**
 * @param array<string,mixed> $candidate
 * @param array{purpose?:string,budget?:float} $ctx
 * @return array<string,float>
 */
function pc_recommend_features(string $slot, array $candidate, array $buildMap, array $ctx = []): array
{
    $purpose = pc_purpose_key($ctx['purpose'] ?? '');
    $budget = (float)($ctx['budget'] ?? 0);
    $tags = $candidate['compat'] ?? ep_pc_compat_tags(
        (string)($candidate['name'] ?? ''),
        (string)($candidate['description'] ?? ''),
        (string)($candidate['category'] ?? '')
    );
    $spec = pc_recommend_spec($slot, $candidate);
    $tier = isset($candidate['tier']) ? (float)$candidate['tier'] : 0.5;
    $level = pc_recommend_build_level($buildMap, $slot, ['purpose' => $purpose, 'budget' => $budget]);
    $cpu = !empty($buildMap['processor']) ? pc_recommend_spec('processor', $buildMap['processor']) : null;
    $gpuLevel = !empty($buildMap['gpu']) ? pc_recommend_spec('gpu', $buildMap['gpu'])['tier'] / 10 : 0.0;
    $mobo = $buildMap['motherboard']['compat'] ?? null;

    $perf = 0.0;
    $balance = 0.0;
    $cooling = 0.0;
    $dual = 0.0;
    $headroom = 0.0;
    $rating = 0.0;
    $atx3 = 0.0;
    $bundle = 0.0;
    $vram = 0.0;
    $candIgpu = 0.0;
    switch ($slot) {
        case 'processor':
        case 'gpu':
            /* Compared with the other one of the pair (CPU vs GPU): positive = this part is stronger. */
            $perf = $spec['tier'] / 10;
            $balance = $perf - $level;
            if ($slot === 'processor') {
                $candIgpu = $spec['igpu'] ? 1.0 : 0.0;
                if (!empty($buildMap['cooler'])) {
                    $cooling = pc_recommend_spec('cooler', $buildMap['cooler'])['capacity'] / $spec['watts'];
                }
            } else {
                $vram = $spec['vram'] / 16;
            }
            break;
        case 'motherboard':
            $perf = $spec['level'] / 3;
            $needed = ($cpu ? $cpu['tier'] : $level * 10) <= 5 ? 1 : 2;
            $balance = ($spec['level'] - $needed) / 3;
            break;
        case 'memory':
            $perf = min(32, $spec['gb']) / 32;
            $dual = $spec['sticks'] >= 2 ? 1.0 : 0.0;
            break;
        case 'ssd':
        case 'ssd_sata':
        case 'hdd':
            $perf = min(2000, $spec['gb']) / 2000;
            break;
        case 'psu':
            $headroom = $spec['watts'] > 0 ? $spec['watts'] / pc_recommend_psu_need($buildMap) : 0.0;
            $rating = (float)$spec['rating'];
            $atx3 = $spec['atx3'] ? 1.0 : 0.0;
            break;
        case 'cooler':
            $perf = $spec['capacity'] / 300;
            $cooling = $spec['capacity'] / ($cpu ? $cpu['watts'] : 65);
            break;
        case 'case':
            $bundle = $spec['bundle'] ? 1.0 : 0.0;
            break;
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

    $budgetSlack = 1.0;
    if ($budget > 0) {
        $room = pc_recommend_budget_room($slot, $candidate, $buildMap, ['purpose' => $purpose, 'budget' => $budget]);
        $budgetSlack = max(-1.0, min(1.0, $room['after'] / $budget));
    }

    return pc_purpose_features($purpose) + [
        'slot_code' => (float)array_search($slot, array_keys(pc_recommend_slot_map()), true),
        'tier_gap' => round($tier - pc_recommend_build_tier($buildMap), 4),
        'build_level' => round($level, 4),
        'gpu_level' => round($gpuLevel, 4),
        'perf_tier' => round($perf, 4),
        'balance' => round($balance, 4),
        'cooling_margin' => round($cooling, 4),
        'dual_channel' => $dual,
        'psu_headroom' => round($headroom, 4),
        'psu_rating' => $rating,
        'psu_atx3' => $atx3,
        'case_bundle' => $bundle,
        'form_slack' => $formSlack,
        'brand_match' => $brandMatch,
        'cand_igpu' => $candIgpu,
        'cpu_igpu' => $cpu ? ($cpu['igpu'] ? 1.0 : 0.0) : -1.0,
        'cpu_cooler' => $cpu && $cpu['cooler'] ? 1.0 : 0.0,
        'vram' => round($vram, 4),
        'budget_slack' => round($budgetSlack, 4),
    ];
}

/**
 * Expert rules that label the catalog training rows; real customer picks are added on top
 * by scripts/build_pc_recommend_dataset.php. Any purpose: the part must leave enough budget
 * for the required parts still missing. Then, per part and purpose (school = School & Everyday,
 * office = Office & Work, creation = Content Creation, balanced = Not sure yet):
 *  - CPU: school tier 2-5, office tier 4-7, both with built-in graphics unless a GPU is picked;
 *    creation tier 6+; gaming / balanced within 2 tiers of the GPU. Never with a too-weak cooler.
 *  - GPU: school / office only an entry card (tier 3 or lower), and only when the CPU has no
 *    built-in graphics; creation 12 GB VRAM (or 8 GB at tier 6+); gaming / balanced within 2 tiers of the CPU.
 *  - Motherboard: entry chipset for CPUs up to tier 5, mid-range above; at most one class higher.
 *  - Memory: school 8 GB+, office / creation 16 GB+, gaming / balanced 16 GB+ (8 GB in budget builds).
 *  - NVMe: school 256 GB+, office 500 GB+, creation 1 TB+, gaming / balanced 500 GB+ (256 GB in budget builds).
 *    SATA / HDD: 256 GB+ (creation 500 GB+).
 *  - PSU: 1x-1.7x the suggested wattage, Bronze or better; Gold or ATX 3.1 for tier 8+ GPUs.
 *  - Cooler: school / office not needed when the CPU has a cooler, else 1.1x-2x the CPU's power;
 *    gaming / balanced 1.1x-3x; creation 1.1x-4.5x.
 *  - Case: fits the board; no bundled PSU in gaming / creation or mid/high-end builds; similar price range.
 *  - Case fan: not needed for school / office; otherwise similar price range.
 */
function pc_recommend_is_positive(string $slot, array $f): bool
{
    return $f['budget_slack'] >= 0 && pc_recommend_slot_fit($slot, $f);
}

function pc_recommend_slot_fit(string $slot, array $f): bool
{
    $p = pc_purpose_from_features($f);
    $light = $p === 'school' || $p === 'office';
    $level = (float)$f['build_level'];
    $balance = (float)$f['balance'];
    $perf = (float)$f['perf_tier'];
    switch ($slot) {
        case 'processor':
            if ($f['cooling_margin'] > 0 && $f['cooling_margin'] < 1.1) {
                return false;
            }
            $t = $perf * 10;
            $display = $f['cand_igpu'] > 0 || $f['gpu_level'] > 0;
            if ($p === 'school') {
                return $t >= 1.5 && $t <= 5.5 && $display;
            }
            if ($p === 'office') {
                return $t >= 3.5 && $t <= 7.5 && $display;
            }
            if ($p === 'creation') {
                return $t >= 5.5 && ($f['gpu_level'] <= 0 || $balance >= -0.3);
            }
            return abs($balance) <= 0.2;
        case 'gpu':
            if ($light) {
                return $f['cpu_igpu'] == 0 && $perf <= 0.35;
            }
            if ($p === 'creation') {
                $vram = $f['vram'] * 16;
                return $vram >= 11.5 || ($vram >= 7.5 && $perf >= 0.55);
            }
            return abs($balance) <= 0.2;
        case 'motherboard':
            return $balance > -0.01 && $balance < 0.34;
        case 'memory':
            $gb = $perf * 32;
            if ($p === 'school') {
                return $gb >= 7.5;
            }
            if ($p === 'office' || $p === 'creation') {
                return $gb >= 15.5;
            }
            return $gb >= 15.5 || ($gb >= 7.5 && $level <= 0.4);
        case 'ssd':
            $gb = $perf * 2000;
            if ($p === 'school') {
                return $gb >= 240;
            }
            if ($p === 'office') {
                return $gb >= 480;
            }
            if ($p === 'creation') {
                return $gb >= 960;
            }
            return $gb >= 480 || ($gb >= 240 && $level <= 0.4);
        case 'ssd_sata':
        case 'hdd':
            return $perf * 2000 >= ($p === 'creation' ? 480 : 240);
        case 'psu':
            if ($f['psu_headroom'] < 1.0 || $f['psu_headroom'] > 1.7 || $f['psu_rating'] < 2) {
                return false;
            }
            return $f['gpu_level'] < 0.8 || $f['psu_rating'] >= 3 || $f['psu_atx3'] > 0;
        case 'cooler':
            $m = (float)$f['cooling_margin'];
            if ($light) {
                return $f['cpu_cooler'] <= 0 && $m >= 1.1 && $m <= 2.0;
            }
            return $m >= 1.1 && $m <= ($p === 'creation' ? 4.5 : 3.0);
        case 'case':
            if ($f['form_slack'] > 1) {
                return false;
            }
            if ($f['case_bundle'] > 0 && ($level >= 0.5 || $p === 'gaming' || $p === 'creation')) {
                return false;
            }
            return abs((float)$f['tier_gap']) <= 0.35;
        case 'case_fan':
            return !$light && abs((float)$f['tier_gap']) <= 0.2;
    }
    return abs((float)$f['tier_gap']) <= 0.2;
}

function pc_recommend_size_label(int $gb): string
{
    return $gb >= 1000 ? rtrim(rtrim(number_format($gb / 1000, 1), '0'), '.') . ' TB' : $gb . ' GB';
}

/**
 * Customer-facing text for a candidate, worded for the purpose, from the same expert rules.
 *
 * @return array{0:string,1:string} [why it is a good pick, why it is not ideal] (one of them empty)
 */
function pc_recommend_explain(string $slot, array $candidate, array $buildMap, array $f, array $ctx = []): array
{
    $p = pc_purpose_from_features($f);
    $use = pc_purposes()[$p]['use'];
    $light = $p === 'school' || $p === 'office';
    $spec = pc_recommend_spec($slot, $candidate);
    $cpu = !empty($buildMap['processor']) ? pc_recommend_spec('processor', $buildMap['processor']) : null;
    $gpu = !empty($buildMap['gpu']) ? pc_recommend_spec('gpu', $buildMap['gpu']) : null;
    $balance = (float)$f['balance'];
    $level = (float)$f['build_level'];

    if ($f['budget_slack'] < 0) {
        $room = pc_recommend_budget_room($slot, $candidate, $buildMap, $ctx);
        if ((float)($candidate['price'] ?? 0) > $room['left']) {
            return ['', 'Over your remaining budget of ₱' . number_format(max(0, $room['left'])) . '.'];
        }
        return ['', 'Leaves too little of your budget for the parts still needed.'];
    }
    $good = pc_recommend_slot_fit($slot, $f);

    switch ($slot) {
        case 'processor':
            if ($good) {
                if ($p === 'school') {
                    $why = $spec['label'] . ($spec['igpu'] ? ' with built-in graphics' : '') . ': smooth for browsing, homework and videos.';
                } elseif ($p === 'office') {
                    $why = $spec['label'] . ': handles Office, video calls and many browser tabs.' . ($spec['igpu'] ? ' Built-in graphics, so no graphics card needed.' : '');
                } elseif ($p === 'creation') {
                    $why = $spec['label'] . ': strong multi-core performance for editing and rendering.';
                } elseif ($gpu) {
                    $why = 'Balanced with your ' . $gpu['label'] . ($p === 'gaming' ? ': no bottleneck in games.' : ', so neither part holds the other back.');
                } else {
                    $why = $buildMap ? 'Matches the performance level of this build.' : ($p === 'gaming' ? 'A solid gaming processor to build around.' : 'A balanced processor that suits most uses.');
                }
                return [$why . ($spec['cooler'] ? ' Comes with its own cooler.' : ''), ''];
            }
            if ($f['cooling_margin'] > 0 && $f['cooling_margin'] < 1.1) {
                $cooler = pc_recommend_spec('cooler', $buildMap['cooler']);
                return ['', 'Your ' . $cooler['type'] . ' is too weak for this CPU (~' . $spec['watts'] . ' W).'];
            }
            $t = $spec['tier'];
            if ($light) {
                if (!$spec['igpu'] && !$gpu) {
                    return ['', 'No built-in graphics: this build would also need a graphics card.'];
                }
                if ($p === 'office' && $t < 4) {
                    return ['', 'Entry-level; a Ryzen 5 or Core i5 handles office multitasking better.'];
                }
                return ['', 'More processor than ' . $use . ' needs.'];
            }
            if ($p === 'creation') {
                return ['', $t < 6 ? 'Fewer cores; editing and rendering will be slow. A Ryzen 7 / Core i7 or higher is better.' : 'This CPU will hold back your ' . $gpu['label'] . '.'];
            }
            if ($gpu) {
                return ['', $balance > 0 ? 'More CPU than your ' . $gpu['label'] . ' can use' . ($p === 'gaming' ? ' in games.' : '.') : 'This CPU will hold back your ' . $gpu['label'] . '.'];
            }
            if (!$buildMap) {
                return ['', pc_recommend_level_note($balance, $ctx)];
            }
            return ['', $balance > 0 ? 'Higher-end than the parts you picked so far.' : 'Lower-end than the parts you picked so far.'];
        case 'gpu':
            if ($good) {
                if ($light) {
                    return ['Entry-level card for displays, videos and video calls; your ' . $cpu['label'] . ' has no built-in graphics.', ''];
                }
                if ($p === 'creation') {
                    return [$spec['vram'] . ' GB of video memory speeds up video editing and effects.', ''];
                }
                if ($p === 'gaming') {
                    return [$cpu ? 'Balanced with your ' . $cpu['label'] . ': no bottleneck for ' . $spec['res'] . ' gaming.' : 'Good for ' . $spec['res'] . ' gaming at this build\'s level.', ''];
                }
                return [$cpu ? 'Balanced with your ' . $cpu['label'] . ', so neither part holds the other back.' : ($buildMap ? 'Matches the performance level of this build.' : 'A mid-range card that suits most uses.'), ''];
            }
            if ($light) {
                if ($f['cpu_igpu'] > 0) {
                    return ['', 'Optional: your ' . $cpu['label'] . ' has built-in graphics, enough for ' . $use . '.'];
                }
                if ($f['cpu_igpu'] < 0) {
                    return ['', 'Optional: a processor with built-in graphics is enough for ' . $use . '.'];
                }
                return ['', 'More graphics power than ' . $use . ' needs.'];
            }
            if ($p === 'creation') {
                return ['', 'Only ' . $spec['vram'] . ' GB of video memory; 12 GB or more is better for video editing.'];
            }
            if ($cpu) {
                return ['', $balance > 0 ? 'Your ' . $cpu['label'] . ' will hold back this ' . $spec['label'] . '.' : 'Weak for your ' . $cpu['label'] . '; it will limit performance.'];
            }
            if (!$buildMap) {
                return ['', pc_recommend_level_note($balance, $ctx)];
            }
            return ['', $balance > 0 ? 'Higher-end than the parts you picked so far.' : 'Lower-end than the parts you picked so far.'];
        case 'motherboard':
            $board = trim(ucfirst($spec['label']) . ' ' . $spec['chipset']) . ' board';
            $for = $cpu ? 'the ' . $cpu['label'] : 'this build';
            if ($good) {
                return [$board . ', a good match for ' . $for . '.', ''];
            }
            return ['', $balance < 0 ? $board . '; a mid-range board suits ' . $for . ' better.' : $board . '; more than ' . $for . ' needs.'];
        case 'memory':
            $gb = $spec['gb'];
            $kit = $spec['sticks'] >= 2 ? ' dual-channel kit (' . $spec['sticks'] . ' x ' . intdiv($gb, $spec['sticks']) . ' GB)' : '';
            if ($good) {
                $texts = [
                    'school' => $gb . ' GB: enough for browsing, homework and videos.',
                    'office' => $gb . ' GB' . $kit . ': smooth multitasking with Office, browsers and video calls.',
                    'gaming' => $gb < 16 ? '8 GB is enough for a budget build; 16 GB is better for gaming.' : $gb . ' GB' . $kit . ': recommended for gaming.',
                    'creation' => $gb . ' GB' . $kit . ': the minimum for editing; 32 GB is better for 4K video.',
                    'balanced' => $gb < 16 ? '8 GB is enough for a budget build.' : $gb . ' GB' . $kit . ': comfortable for most uses.',
                ];
                return [$texts[$p], ''];
            }
            if ($gb < 8) {
                return ['', $gb . ' GB is not enough for a modern PC.'];
            }
            $texts = [
                'office' => '8 GB fills up quickly with Office, browsers and video calls; 16 GB is recommended.',
                'gaming' => $gb . ' GB is low for gaming; 16 GB is recommended.',
                'creation' => 'Editing needs at least 16 GB.',
            ];
            return ['', $texts[$p] ?? $gb . ' GB is low for this build; 16 GB is recommended.'];
        case 'ssd':
        case 'ssd_sata':
        case 'hdd':
            $size = pc_recommend_size_label($spec['gb']);
            if ($slot === 'ssd') {
                if ($good) {
                    $texts = [
                        'school' => $size . ' NVMe: fast start-up and room for school files.',
                        'office' => $size . ' NVMe: fast and roomy for work files.',
                        'gaming' => $spec['gb'] >= 480 ? $size . ' NVMe: room for Windows and several games.' : $size . ' NVMe: fine for a budget build.',
                        'creation' => $size . ' NVMe: room for video projects and fast previews.',
                        'balanced' => $spec['gb'] >= 480 ? $size . ' NVMe: room for Windows, apps and files.' : $size . ' NVMe: fine for a budget build.',
                    ];
                    return [$texts[$p], ''];
                }
                $texts = [
                    'school' => $size . ' is too small for Windows and apps.',
                    'office' => $size . ' fills up with work files; 500 GB or more is recommended.',
                    'gaming' => $size . ' fills up fast with games; 500 GB or more is recommended.',
                    'creation' => 'Video projects fill drives quickly; 1 TB or more is recommended.',
                ];
                return ['', $texts[$p] ?? $size . ' fills up quickly; 500 GB or more is recommended.'];
            }
            if ($good) {
                $texts = [
                    'gaming' => $size . ': good extra storage for games and files.',
                    'creation' => $size . ': extra space for footage and project files.',
                ];
                return [$texts[$p] ?? $size . ': good extra storage for files and apps.', ''];
            }
            return ['', $p === 'creation' ? $size . ' is small for video files; 500 GB or more is better.' : $size . ' is too small for Windows and apps.'];
        case 'psu':
            if ($good) {
                $why = $spec['watts'] . ' W ' . $spec['rating_label'] . ' covers this build\'s ~' . pc_recommend_power_estimate($buildMap) . ' W with headroom.';
                if ($gpu && $f['gpu_level'] >= 0.8 && $f['psu_atx3'] > 0) {
                    $why .= ' Has the newer ATX 3.1 / PCIe 5 connector for your ' . $gpu['label'] . '.';
                }
                return [$why, ''];
            }
            if ($spec['watts'] <= 0) {
                return ['', ''];
            }
            if ($f['psu_rating'] < 2) {
                return ['', ($f['psu_rating'] < 1 ? 'No 80 Plus rating' : 'Only basic 80 Plus efficiency') . '; a Bronze-rated or better PSU is safer.'];
            }
            if ($f['psu_headroom'] < 1.0) {
                return ['', 'Below the ~' . pc_recommend_psu_need($buildMap) . ' W this build should have.'];
            }
            if ($f['psu_headroom'] > 1.7) {
                return ['', 'More wattage than this build needs.'];
            }
            return ['', 'For ' . ($gpu ? 'an ' . $gpu['label'] : 'this graphics card') . ', a Gold-rated or ATX 3.1 PSU is the safer pick.'];
        case 'cooler':
            $heat = $cpu ? $cpu['watts'] : 65;
            $for = $cpu ? 'the ' . $cpu['label'] : 'a typical processor';
            if ($good) {
                $tail = $p === 'creation' ? ' and stays cool during long renders.' : ' without overkill.';
                return ['This ' . $spec['type'] . ' handles ' . $for . ' (~' . $heat . ' W)' . $tail, ''];
            }
            if ($light && $f['cpu_cooler'] > 0) {
                return ['', 'Optional: the ' . $cpu['label'] . ' comes with its own cooler, enough for ' . $use . '.'];
            }
            return ['', $f['cooling_margin'] < 1.1 ? 'Too weak for ' . $for . ' (~' . $heat . ' W).' : 'More cooling than ' . $for . ' needs.'];
        case 'case':
            if ($good) {
                $fits = !empty($buildMap['motherboard']) ? 'Fits the motherboard in this build' : 'Fits this build';
                return [$fits . (pc_spec_case((string)$candidate['name'])['airflow'] ? ', with an airflow-focused design.' : '.'), ''];
            }
            if ($f['case_bundle'] > 0 && ($level >= 0.5 || $p === 'gaming' || $p === 'creation')) {
                return ['', 'Its bundled power supply is not suited to this build.'];
            }
            if ($f['form_slack'] > 1) {
                return ['', 'Much bigger than the motherboard needs.'];
            }
            return ['', pc_recommend_tier_note((float)$f['tier_gap'])];
        case 'case_fan':
            if ($good) {
                return [($spec['fans'] >= 3 ? $spec['fans'] . '-fan pack in' : 'In') . ' the same price range as your other parts.', ''];
            }
            return ['', $light ? 'Optional: extra fans are not needed for ' . $use . '.' : pc_recommend_tier_note((float)$f['tier_gap'])];
    }
    return [$good ? 'Fits the parts you already picked.' : '', ''];
}

/**
 * Note for the first CPU / GPU of an empty build that is above or below the build's level. With a
 * budget the level comes from the budget, so the note says that instead of calling the part weak.
 */
function pc_recommend_level_note(float $balance, array $ctx): string
{
    if ((float)($ctx['budget'] ?? 0) > 0) {
        return $balance > 0 ? 'Pricier than your budget comfortably allows.' : 'Your budget can get a stronger one.';
    }
    return $balance > 0 ? 'A high-end part; more than most builds like this need.' : 'An entry-level part; fine for light use only.';
}

/** Note for a part that only misses on price level: tier_gap > 0 means pricier than the rest of the build. */
function pc_recommend_tier_note(float $gap): string
{
    return $gap > 0 ? 'Pricier than the rest of this build needs.' : 'A lower price range than the rest of this build.';
}

function pc_recommend_model_path(): string
{
    return dirname(__DIR__) . '/ml/pc_recommend/models/forest.json';
}

/** @return array<string,mixed>|null null when the file is missing or was trained on other features */
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
    $model = is_array($decoded) && !empty($decoded['trees'])
        && ($decoded['features'] ?? null) === pc_recommend_feature_names() ? $decoded : null;
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
 * @param array{purpose?:string,budget?:float} $ctx
 * @return array{score:float,recommended:bool,reason:string,note:string,rule_fit:bool}|null note = why it is not ideal
 */
function pc_recommend_score(string $slot, array $candidate, array $buildMap, array $ctx = []): ?array
{
    if (pc_recommend_model() === null) {
        return null;
    }
    if (ep_pc_compat_reason_for_candidate($slot, $candidate['compat'] ?? [], $buildMap) !== null) {
        return null;
    }
    /* Never recommend a part that leaves a required part with no compatible option in stock. */
    $purpose = pc_purpose_key($ctx['purpose'] ?? '');
    $with = $buildMap;
    $with[$slot] = $candidate;
    $dead = pc_recommend_dead_ends($with, $purpose);
    if ($dead && !pc_recommend_dead_ends($buildMap, $purpose)) {
        $label = strtolower(pc_recommend_slot_map()[$dead[0]]['label'] ?? $dead[0]);
        return ['score' => 0.0, 'recommended' => false, 'reason' => '', 'note' => 'No compatible ' . $label . ' in stock right now for this part.', 'rule_fit' => false];
    }
    $features = pc_recommend_features($slot, $candidate, $buildMap, $ctx);
    $proba = pc_recommend_proba($features);
    if ($proba === null) {
        return null;
    }
    $threshold = (float)(pc_recommend_model()['threshold'] ?? 0.5);
    $recommended = $proba >= $threshold;
    [$why, $note] = pc_recommend_explain($slot, $candidate, $buildMap, $features, $ctx);
    return [
        'score' => round($proba, 4),
        'recommended' => $recommended,
        'reason' => $recommended ? ($why !== '' ? $why : 'Fits the parts you already picked.') : '',
        'note' => $recommended ? '' : $note,
        /* The rules call it a fit even though the forest ranks it lower. */
        'rule_fit' => !$recommended && $why !== '',
    ];
}

/** How many parts per picker list get the Recommended badge (the Hit@3 test in train_model.py uses the same 3). */
const PC_RECOMMEND_TOP = 3;

/**
 * Scores a picker list. Every part the forest calls a good fit gets a reason; the best
 * PC_RECOMMEND_TOP of them also get the Recommended badge. Parts that are not a good fit
 * get a note saying why, when the rules have one.
 *
 * @param array<int,array<string,mixed>> $products
 * @return array<int,array<string,mixed>>
 */
function pc_recommend_apply(string $slot, array $products, array $buildMap, array $ctx = []): array
{
    $good = [];
    foreach ($products as $i => $product) {
        $scored = pc_recommend_score($slot, $product, $buildMap, $ctx);
        if ($scored === null) {
            continue;
        }
        $products[$i]['recommend_score'] = $scored['score'];
        if ($scored['recommended']) {
            $good[$i] = $scored;
        } elseif ($scored['note'] !== '') {
            $products[$i]['recommend_note'] = $scored['note'];
        } elseif (!empty($scored['rule_fit'])) {
            $products[$i]['recommend_note'] = 'Works with this build, but the Recommended parts suit it better.';
        }
    }
    uasort($good, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    foreach ($good as $i => $scored) {
        $products[$i]['recommend_reason'] = $scored['reason'];
    }
    foreach (array_keys(array_slice($good, 0, PC_RECOMMEND_TOP, true)) as $i) {
        $products[$i]['recommended'] = true;
    }
    return $products;
}

/**
 * Checks the picked parts against the purpose: whether a graphics card is still needed,
 * build-wide warnings (no display output) and a note for each picked part that is not a good fit.
 *
 * @return array{gpu_needed:bool,warnings:array<int,string>,notes:array<int,array{slot:string,text:string}>}
 */
function pc_recommend_review(array $buildMap, array $ctx): array
{
    $purpose = pc_purpose_key($ctx['purpose'] ?? '');
    $warnings = [];
    if (!empty($buildMap['processor']) && empty($buildMap['gpu'])) {
        $cpu = pc_recommend_spec('processor', $buildMap['processor']);
        if (!$cpu['igpu']) {
            $warnings[] = 'No display output yet: the ' . $cpu['label'] . ' has no built-in graphics, so this build needs a graphics card.';
        }
    }
    $notes = [];
    /* Budget is left out here: the budget bar already shows when the build goes over. */
    $partCtx = ['purpose' => $purpose, 'budget' => 0.0];
    foreach (array_keys(pc_recommend_slot_map()) as $slot) {
        if (empty($buildMap[$slot])) {
            continue;
        }
        $others = $buildMap;
        unset($others[$slot]);
        /* The no-display warning already covers a CPU without built-in graphics. */
        if ($slot === 'processor' && $warnings) {
            continue;
        }
        /* The CPU and GPU notes describe the same mismatch; keep the GPU one. */
        if ($slot === 'processor' && !empty($buildMap['gpu'])) {
            $gpuCheck = pc_recommend_score('gpu', $buildMap['gpu'], array_diff_key($buildMap, ['gpu' => 1]), $partCtx);
            if ($gpuCheck !== null && !$gpuCheck['recommended'] && $gpuCheck['note'] !== '') {
                continue;
            }
        }
        $scored = pc_recommend_score($slot, $buildMap[$slot], $others, $partCtx);
        if ($scored !== null && !$scored['recommended'] && $scored['note'] !== '') {
            $notes[] = ['slot' => $slot, 'text' => $scored['note']];
        }
    }
    /* Current price and stock of every picked part, and an in-stock alternative for sold-out ones
       (scored with the budget, like the picker, so both suggest the same part). */
    $altCtx = ['purpose' => $purpose, 'budget' => (float)($ctx['budget'] ?? 0)];
    $parts = [];
    $soldOut = [];
    foreach ($buildMap as $slot => $part) {
        $parts[$slot] = ['id' => (int)$part['id'], 'price' => (float)$part['price'], 'stock' => (int)($part['stock'] ?? 0), 'warranty' => (string)($part['warranty'] ?? '')];
        if ((int)($part['stock'] ?? 0) > 0) {
            continue;
        }
        $others = $buildMap;
        unset($others[$slot]);
        $options = pc_recommend_apply($slot, pc_recommend_catalog()[$slot] ?? [], $others, $altCtx);
        $soldOut[] = [
            'slot' => $slot,
            'name' => (string)$part['name'],
            'alternative' => pc_recommend_pick_alternative($options, (float)$part['price'], (int)$part['id']),
        ];
    }
    return [
        'gpu_needed' => pc_recommend_gpu_needed($buildMap, $purpose),
        'warnings' => $warnings,
        'notes' => $notes,
        'parts' => $parts,
        'sold_out' => $soldOut,
    ];
}

/**
 * Suggested budgets per purpose from the live catalog, for the budget dialog.
 * Each tier adds up one price per required part, taken at a price percentile of that
 * part's in-stock products (higher purposes sit higher in the price range). Also returns
 * the cheapest price per slot, so the page can say what finishing a build costs at least,
 * and the least a complete PC costs per purpose ("Build it for me" refuses smaller budgets).
 *
 * @return array{purposes:array<string,array{starter:int,recommended:int,high:int}>,cheapest:array<string,float>,minimum:array<string,int>}
 */
function pc_recommend_budget_guide(?PDO $db = null): array
{
    static $guide = null;
    if ($guide !== null) {
        return $guide;
    }
    $catalog = pc_recommend_catalog($db);
    if (!array_filter($catalog)) {
        return ['purposes' => [], 'cheapest' => [], 'minimum' => []];
    }
    $prices = [];
    foreach ($catalog as $slot => $products) {
        $list = array_values(array_filter(array_map('floatval', array_column($products, 'price')), static fn(float $p): bool => $p > 0));
        sort($list);
        $prices[$slot] = $list;
    }
    $at = static function (array $list, float $q): float {
        if (!$list) {
            return 0.0;
        }
        $pos = $q * (count($list) - 1);
        $lo = (int)floor($pos);
        $hi = min(count($list) - 1, $lo + 1);
        return $list[$lo] + ($list[$hi] - $list[$lo]) * ($pos - $lo);
    };
    /* [starter, recommended, high-end] price percentiles per purpose. */
    $levels = [
        'balanced' => [0.25, 0.5, 0.8],
        'school' => [0.15, 0.35, 0.6],
        'office' => [0.25, 0.45, 0.7],
        'gaming' => [0.3, 0.55, 0.85],
        'creation' => [0.4, 0.65, 0.9],
    ];
    $storage = $prices['ssd'] ?: $prices['ssd_sata'];
    $out = [];
    foreach ($levels as $purpose => $qs) {
        $slots = ['processor', 'motherboard', 'memory', 'psu', 'case'];
        if (pc_recommend_gpu_needed([], $purpose)) {
            $slots[] = 'gpu';
        }
        $tiers = [];
        foreach ($qs as $q) {
            $sum = $at($storage, $q);
            foreach ($slots as $slot) {
                $sum += $at($prices[$slot], $q);
            }
            $tiers[] = (int)(ceil($sum / 1000) * 1000);
        }
        $out[$purpose] = ['starter' => $tiers[0], 'recommended' => $tiers[1], 'high' => $tiers[2]];
    }
    $cheapest = [];
    foreach ($prices as $slot => $list) {
        if ($list) {
            $cheapest[$slot] = $list[0];
        }
    }
    /* Least a complete, compatible PC costs per purpose: "Build it for me" with almost no budget
       falls back to the cheapest part that fits at every step. */
    $minimum = [];
    if ($db !== null) {
        foreach (array_keys($levels) as $purpose) {
            $cheap = pc_recommend_auto_build($db, ['purpose' => $purpose, 'budget' => 1.0]);
            if (!$cheap['missing']) {
                $sum = array_sum(array_map(static fn(array $p): float => (float)$p['price'], $cheap['added']));
                $minimum[$purpose] = (int)(ceil($sum / 1000) * 1000);
            }
        }
    }
    $guide = ['purposes' => $out, 'cheapest' => $cheapest, 'minimum' => $minimum];
    return $guide;
}

/**
 * Typical share of the budget per part for each purpose (a PC technician's rule of thumb,
 * e.g. about 38% of a gaming budget goes to the graphics card). Used by "Build it for me".
 *
 * @return array<string,float>
 */
function pc_recommend_budget_shares(string $purpose): array
{
    $shares = [
        'balanced' => ['processor' => 0.24, 'gpu' => 0.26, 'motherboard' => 0.14, 'memory' => 0.10, 'ssd' => 0.09, 'psu' => 0.08, 'case' => 0.06, 'cooler' => 0.02, 'case_fan' => 0.01],
        'school' => ['processor' => 0.28, 'gpu' => 0.15, 'motherboard' => 0.20, 'memory' => 0.14, 'ssd' => 0.14, 'psu' => 0.10, 'case' => 0.10, 'cooler' => 0.03, 'case_fan' => 0.02],
        'office' => ['processor' => 0.30, 'gpu' => 0.15, 'motherboard' => 0.20, 'memory' => 0.15, 'ssd' => 0.14, 'psu' => 0.10, 'case' => 0.09, 'cooler' => 0.03, 'case_fan' => 0.02],
        'gaming' => ['processor' => 0.20, 'gpu' => 0.38, 'motherboard' => 0.12, 'memory' => 0.08, 'ssd' => 0.07, 'psu' => 0.07, 'case' => 0.05, 'cooler' => 0.02, 'case_fan' => 0.01],
        'creation' => ['processor' => 0.27, 'gpu' => 0.30, 'motherboard' => 0.12, 'memory' => 0.10, 'ssd' => 0.09, 'psu' => 0.06, 'case' => 0.04, 'cooler' => 0.02, 'case_fan' => 0.01],
    ];
    return $shares[pc_purpose_key($purpose)];
}

/**
 * "Build it for me": fills a complete build one slot at a time, in the picker's order. For each
 * slot the forest scores every compatible in-stock part against what is already picked, the
 * purpose and the budget. Among the parts it recommends that fit the budget, the one priced
 * closest to that part's usual share of the budget is added. A required part with
 * no recommended option falls back to the best part that still fits the budget, else the
 * cheapest. Optional parts (a graphics card the purpose does not need, cooler, case fans) are
 * added only when the forest recommends one. Parts in $keep stay as they are.
 *
 * @param array<string,array<string,mixed>> $keep build map of parts the customer already picked
 * @return array{added:array<string,array<string,mixed>>,reasons:array<string,string>,fallback:array<int,string>,missing:array<int,string>}
 */
function pc_recommend_auto_build(PDO $db, array $ctx, array $keep = []): array
{
    $catalog = pc_recommend_catalog($db);
    $purpose = pc_purpose_key($ctx['purpose'] ?? '');
    $budget = (float)($ctx['budget'] ?? 0);
    $shares = pc_recommend_budget_shares($purpose);
    $build = $keep;
    $added = [];
    $reasons = [];
    $fallback = [];
    $missing = [];
    /* Storage is one step: the main drive can be an NVMe or a SATA SSD. */
    foreach (['processor', 'motherboard', 'memory', 'gpu', ['ssd', 'ssd_sata'], 'psu', 'case', 'cooler', 'case_fan'] as $step) {
        $slots = (array)$step;
        $slot = $slots[0];
        if (!empty($build[$slot]) || ($slot === 'ssd' && (!empty($build['ssd_sata']) || !empty($build['hdd'])))) {
            continue;
        }
        /* A CPU sold with its own cooler needs no other; one sold without (MPK / tray) cannot run without one. */
        $cpuCooler = !empty($build['processor']) && pc_recommend_spec('processor', $build['processor'])['cooler'];
        if ($slot === 'cooler' && $cpuCooler) {
            continue;
        }
        $required = in_array($slot, ['processor', 'motherboard', 'memory', 'ssd', 'psu', 'case'], true)
            || ($slot === 'gpu' && pc_recommend_gpu_needed($build, $purpose))
            || ($slot === 'cooler' && !empty($build['processor']));
        $best = null;
        $bestKey = null;
        $target = $budget * ($shares[$slot] ?? 0.05);
        $options = [];
        foreach ($slots as $s) {
            foreach ($catalog[$s] ?? [] as $candidate) {
                $options[] = [$s, $candidate];
            }
        }
        foreach ($options as [$s, $candidate]) {
            $scored = pc_recommend_score($s, $candidate, $build, $ctx);
            if ($scored === null) {
                continue;
            }
            $with = $build;
            $with[$s] = $candidate;
            if (pc_recommend_dead_ends($with, $purpose)) {
                continue;
            }
            $fits = $budget <= 0 || pc_recommend_budget_room($s, $candidate, $build, $ctx)['after'] >= 0;
            /* Recommended first, then within budget, then an NVMe main drive over SATA, then closest
               to the part's budget share (good fits only), then the forest's score, then the lower price. */
            $price = max(1.0, (float)$candidate['price']);
            $share = $scored['recommended'] && $fits && $target > 0 ? -abs(log($price / $target)) : 0.0;
            $key = [$scored['recommended'] ? 1 : 0, $fits ? 1 : 0, $s === 'ssd_sata' ? 0 : 1, $share, $fits ? $scored['score'] : 0.0, -$price];
            if ($bestKey === null || $key > $bestKey) {
                $best = [$s, $candidate, $scored, $fits];
                $bestKey = $key;
            }
        }
        if ($best === null) {
            if ($required) {
                $missing[] = $slot;
            }
            continue;
        }
        [$slot, $candidate, $scored, $fits] = $best;
        if (!$scored['recommended'] && !$required) {
            continue;
        }
        $build[$slot] = $candidate;
        $added[$slot] = $candidate;
        if ($scored['recommended'] && $slot === 'ssd_sata') {
            $reasons[$slot] = pc_recommend_size_label(pc_spec_storage((string)$candidate['name'])['gb']) . ' SATA SSD as the main drive keeps the build within budget.';
        } elseif ($scored['recommended']) {
            $reasons[$slot] = $scored['reason'];
        } else {
            $fallback[] = $slot;
            $reasons[$slot] = $fits
                ? 'Closest fit in stock for this build.'
                : 'Cheapest compatible option in stock; the budget is tight for this build.';
        }
    }
    if ($budget > 0 && !$missing) {
        $changes = pc_recommend_auto_upgrade($build, $added, $ctx);
        foreach ($changes as $from => [$slot, $candidate, $reason]) {
            unset($added[$from], $reasons[$from], $build[$from]);
            $fallback = array_values(array_diff($fallback, [$from]));
            $added[$slot] = $candidate;
            $build[$slot] = $candidate;
            $reasons[$slot] = $reason;
        }
        /* Reasons name the other parts ("a good match for the Ryzen 5 5600"), so reword them for the final build. */
        if ($changes) {
            foreach ($added as $slot => $candidate) {
                $rest = $build;
                unset($rest[$slot]);
                $scored = pc_recommend_score($slot, $candidate, $rest, $ctx);
                if ($scored !== null && $scored['recommended']) {
                    $reasons[$slot] = $slot === 'ssd_sata'
                        ? pc_recommend_size_label(pc_spec_storage((string)$candidate['name'])['gb']) . ' SATA SSD as the main drive keeps the build within budget.'
                        : $scored['reason'];
                }
            }
        }
    }
    return ['added' => $added, 'reasons' => $reasons, 'fallback' => $fallback, 'missing' => $missing];
}

/** The Build Score (pc_rating.php) before rounding, so small differences between two builds still count. */
function pc_recommend_build_points(array $buildMap, string $purpose): float
{
    $rating = pc_build_rating($buildMap, $purpose);
    $points = (float)$rating['base'];
    $cap = 100.0;
    foreach ($rating['adjustments'] as $a) {
        $points += (float)($a['points'] ?? 0);
        $cap = min($cap, (float)($a['cap'] ?? 100));
    }
    return min($cap, $points);
}

/** Whether $candidate does everything $current does in this build (same or better specs), so a cheaper one is a pure saving. */
function pc_recommend_not_worse(string $slot, array $candidate, string $currentSlot, array $current, array $rest): bool
{
    $new = pc_recommend_spec($slot, $candidate);
    $old = pc_recommend_spec($currentSlot, $current);
    switch ($slot) {
        case 'processor':
            /* Built-in graphics only matters while there is no graphics card. */
            return $new['tier'] >= $old['tier'] && ($new['igpu'] || !$old['igpu'] || !empty($rest['gpu']));
        case 'gpu':
            return $new['tier'] >= $old['tier'] && $new['vram'] >= $old['vram'];
        case 'memory':
            return $new['gb'] >= $old['gb'] && $new['sticks'] >= $old['sticks'];
        case 'ssd':
        case 'ssd_sata':
            /* NVMe is faster than SATA, so a SATA drive never replaces an NVMe one as "not worse". */
            return $new['gb'] >= $old['gb'] && ($slot === $currentSlot || $slot === 'ssd');
    }
    return false;
}

/**
 * Second pass of "Build it for me": puts the money the first pass left over to work, judged by the
 * Build Score so the build and its score agree. Each round makes one change to a part this pass or the
 * first one picked: first a part swapped for one at least ₱1,000 cheaper with the same or better
 * specs (e.g. no paying for built-in graphics next to a graphics card), otherwise the upgrade that
 * adds the most score per peso and still fits the budget (more or dual-channel memory, a bigger
 * drive, a stronger CPU / GPU, a better PSU, or a graphics card the purpose can use). Every new part
 * must also be one the forest recommends for that build.
 *
 * @param array<string,array<string,mixed>> $build the finished build
 * @param array<string,array<string,mixed>> $added the parts "Build it for me" picked (others are the customer's)
 * @return array<string,array{0:string,1:array<string,mixed>,2:string}> replaced slot => [new slot, part, reason]
 */
function pc_recommend_auto_upgrade(array $build, array $added, array $ctx): array
{
    $purpose = pc_purpose_key($ctx['purpose'] ?? '');
    $budget = (float)($ctx['budget'] ?? 0);
    $catalog = pc_recommend_catalog();
    $changes = [];
    for ($round = 0; $round < 8; $round++) {
        $left = $budget - array_sum(array_map(static fn(array $p): float => (float)($p['price'] ?? 0), $build));
        $base = pc_recommend_build_points($build, $purpose);
        $best = null;
        foreach (['processor', 'gpu', 'memory', 'ssd', 'psu'] as $step) {
            /* The main drive can move between NVMe and SATA. */
            $from = $step === 'ssd' && empty($build['ssd']) && !empty($build['ssd_sata']) ? 'ssd_sata' : $step;
            $current = $build[$from] ?? null;
            if (($current && !isset($added[$from])) || (!$current && $step !== 'gpu')) {
                continue;
            }
            $rest = $build;
            unset($rest[$from]);
            foreach ($step === 'ssd' ? ['ssd', 'ssd_sata'] : [$step] as $slot) {
                foreach ($catalog[$slot] ?? [] as $candidate) {
                    $extra = (float)$candidate['price'] - (float)($current['price'] ?? 0);
                    if ($extra > $left || ($current && (int)$candidate['id'] === (int)$current['id'])) {
                        continue;
                    }
                    $saving = $current && $extra <= -1000 && pc_recommend_not_worse($slot, $candidate, $from, $current, $rest);
                    if ($extra <= 0 && !$saving) {
                        continue;
                    }
                    /* Without a cooler in the build, the CPU has to come with its own. */
                    if ($slot === 'processor' && empty($rest['cooler']) && !pc_recommend_spec('processor', $candidate)['cooler']) {
                        continue;
                    }
                    $with = $rest;
                    $with[$slot] = $candidate;
                    $gain = pc_recommend_build_points($with, $purpose) - $base;
                    if ($saving ? $gain < 0 : $gain < 0.5) {
                        continue;
                    }
                    $rank = $saving ? [1, -$extra] : [0, $gain / $extra];
                    if ($best !== null && $rank <= $best[0]) {
                        continue;
                    }
                    $scored = pc_recommend_score($slot, $candidate, $rest, $ctx);
                    if ($scored !== null && $scored['recommended']) {
                        $best = [$rank, $from, $slot, $candidate, $scored['reason']];
                    }
                }
            }
        }
        if ($best === null) {
            break;
        }
        [, $from, $slot, $candidate, $reason] = $best;
        unset($build[$from], $added[$from]);
        $build[$slot] = $candidate;
        $added[$slot] = $candidate;
        /* Keyed by the slot the first pass filled, so a drive that moved from SATA to NVMe replaces the right one. */
        $origin = $from;
        foreach ($changes as $key => $change) {
            if ($change[0] === $from) {
                $origin = $key;
            }
        }
        $changes[$origin] = [$slot, $candidate, $reason];
    }
    return $changes;
}

/**
 * Ready-made starter builds for the Build a PC page: "Build it for me" at the suggested budget of a few
 * common purposes, built live from today's stock, each with its total, Build Score and what it can run.
 *
 * @return array<int,array<string,mixed>>
 */
function pc_recommend_starter_builds(PDO $db): array
{
    $guide = pc_recommend_budget_guide($db);
    $levels = ['starter' => 'Starter', 'recommended' => 'Recommended', 'high' => 'High-end'];
    $presets = [['school', 'recommended'], ['office', 'recommended'], ['balanced', 'recommended'], ['gaming', 'starter'], ['gaming', 'recommended'], ['creation', 'recommended']];
    $out = [];
    foreach ($presets as [$purpose, $level]) {
        $budget = (float)($guide['purposes'][$purpose][$level] ?? 0);
        if ($budget <= 0) {
            continue;
        }
        $result = pc_recommend_auto_build($db, ['purpose' => $purpose, 'budget' => $budget]);
        if ($result['missing'] || !$result['added']) {
            continue;
        }
        $build = $result['added'];
        $rating = pc_build_rating($build, $purpose);
        $cpu = pc_recommend_spec('processor', $build['processor']);
        $mem = !empty($build['memory']) ? pc_recommend_spec('memory', $build['memory']) : null;
        $drive = $build['ssd'] ?? $build['ssd_sata'] ?? $build['hdd'] ?? null;
        $out[] = [
            'purpose' => $purpose,
            'purpose_label' => pc_purposes()[$purpose]['label'],
            'level' => $level,
            'level_label' => $levels[$level],
            'budget' => (int)$budget,
            'total' => array_sum(array_map(static fn(array $p): float => (float)$p['price'], $build)),
            'score' => $rating['score'],
            'verdict' => $rating['verdict'],
            'specs' => array_values(array_filter([
                $cpu['label'],
                !empty($build['gpu']) ? pc_recommend_spec('gpu', $build['gpu'])['label'] : 'Built-in graphics',
                $mem ? $mem['gb'] . ' GB RAM' . ($mem['sticks'] >= 2 ? ' (2 sticks)' : '') : '',
                $drive ? pc_recommend_size_label(pc_spec_storage((string)$drive['name'])['gb']) . (isset($build['ssd']) ? ' NVMe SSD' : (isset($build['ssd_sata']) ? ' SATA SSD' : ' hard disk')) : '',
            ])),
            'can_run' => pc_rating_can_run($build),
            'parts' => $build,
        ];
    }
    return $out;
}
