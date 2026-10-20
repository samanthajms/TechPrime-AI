<?php
/**
 * Read-only: check how the Build a PC recommender behaves.
 *  1. Coverage: for every purpose, builds are filled in the picker's order with random compatible
 *     parts; before every pick it counts how many parts of that slot get the "Recommended" badge.
 *  2. Expert scenarios: cases a PC technician would judge the same way, run against the trained model.
 *
 * Usage: php scripts/eval_pc_recommend.php [builds per purpose=150]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/pc_recommend.php';

$builds = max(1, (int)($argv[1] ?? 150));
$order = ['processor', 'motherboard', 'memory', 'gpu', 'ssd', 'ssd_sata', 'hdd', 'psu', 'case', 'cooler', 'case_fan'];
$purposes = pc_purposes();

$db = getDbConnection();
$bySlot = pc_recommend_catalog($db);

/* 1. Coverage */
mt_srand(11);
$stats = [];
foreach (array_keys($purposes) as $purpose) {
    $ctx = ['purpose' => $purpose, 'budget' => 0.0];
    foreach ($order as $slot) {
        $stats[$purpose][$slot] = ['asked' => 0, 'covered' => 0, 'recommended' => 0];
    }
    for ($b = 0; $b < $builds; $b++) {
        $build = [];
        foreach ($order as $slot) {
            $compatible = [];
            foreach ($bySlot[$slot] as $candidate) {
                if (ep_pc_compat_reason_for_candidate($slot, $candidate['compat'], $build) === null) {
                    $compatible[] = $candidate;
                }
            }
            if (!$compatible) {
                continue;
            }
            $rec = 0;
            foreach (pc_recommend_apply($slot, $compatible, $build, $ctx) as $candidate) {
                $rec += empty($candidate['recommended']) ? 0 : 1;
            }
            $stats[$purpose][$slot]['asked']++;
            $stats[$purpose][$slot]['recommended'] += $rec;
            $stats[$purpose][$slot]['covered'] += $rec > 0 ? 1 : 0;
            $build[$slot] = $compatible[mt_rand(0, count($compatible) - 1)];
        }
    }
}

echo "coverage: % of picker openings with at least one Recommended part (average badges)\n";
printf("%-12s", 'slot');
foreach ($purposes as $p) {
    printf(" %18s", $p['label']);
}
echo "\n";
foreach ($order as $slot) {
    printf("%-12s", $slot);
    foreach (array_keys($purposes) as $purpose) {
        $s = $stats[$purpose][$slot];
        $cell = $s['asked'] ? sprintf('%3.0f%% (%.1f)', 100 * $s['covered'] / $s['asked'], $s['recommended'] / $s['asked']) : '-';
        printf(" %18s", $cell);
    }
    echo "\n";
}
echo "(0% for GPU, cooler and case fan under School / Office is intended: those parts are optional there.)\n";

/*
 * 2. Expert scenarios: [purpose, budget, build (slot => name fragment), slot to pick, name fragment, should it be Recommended?]
 */
$scenarios = [
    ['gaming', 0, ['processor' => 'Ryzen 3 3200g'], 'gpu', 'RTX 5070', false],
    ['gaming', 0, ['processor' => 'Ryzen 3 3200g'], 'gpu', 'RTX 3050', true],
    ['gaming', 0, ['processor' => 'Ryzen 5 5600 Socket'], 'gpu', 'RTX 5060', true],
    ['gaming', 0, ['processor' => 'i9-14900K'], 'gpu', 'RTX 3050', false],
    ['gaming', 0, ['gpu' => 'RTX 5070'], 'processor', 'Ryzen 3 3200g', false],
    ['gaming', 0, ['gpu' => 'RTX 5070'], 'processor', 'Ryzen 7 7700 ', true],
    ['gaming', 0, ['processor' => 'i9-14900K'], 'cooler', 'Deepcool TW-003', false],
    ['gaming', 0, ['processor' => 'i9-14900K'], 'cooler', 'Coreliquid A13 360 CPU Liquid Cooler Black', true],
    ['gaming', 0, ['processor' => 'Ryzen 5 5500 Socket'], 'cooler', 'Coreliquid A13 360 CPU Liquid Cooler Black', false],
    ['gaming', 0, ['processor' => 'Ryzen 5 5500 Socket'], 'cooler', 'COREFROZR AA13', true],
    ['gaming', 0, ['processor' => 'Ryzen 7 5700X'], 'motherboard', 'A520m-A Pro', false],
    ['gaming', 0, ['processor' => 'Ryzen 7 5700X'], 'motherboard', 'B550M-A PRO', true],
    ['gaming', 0, ['processor' => 'Ryzen 5 5600 Socket', 'gpu' => 'RTX 5060'], 'memory', 'Ramsta 4gb', false],
    ['gaming', 0, ['processor' => 'Ryzen 5 5600 Socket', 'gpu' => 'RTX 5060'], 'memory', 'TForce Delta 16gb 2x8 3600mhz Ddr4 RGB Memory Black', true],
    ['gaming', 0, ['processor' => 'Ryzen 7 7700 ', 'gpu' => 'RTX 5070'], 'ssd', 'Hiksemi Wave P 256GB', false],
    ['gaming', 0, ['processor' => 'Ryzen 7 7700 ', 'gpu' => 'RTX 5070'], 'ssd', 'NM620 1TB', true],
    ['gaming', 0, ['processor' => 'Ryzen 7 7700 ', 'gpu' => 'RTX 5070'], 'psu', 'Intelligent 700', false],
    ['gaming', 0, ['processor' => 'Ryzen 7 7700 ', 'gpu' => 'RTX 5070'], 'psu', 'A850GL', true],
    ['gaming', 0, ['processor' => 'Ryzen 7 7700 ', 'gpu' => 'RTX 5070'], 'psu', 'Acer AC-500', false],
    ['gaming', 0, ['processor' => 'Ryzen 7 7700 ', 'gpu' => 'RTX 5070'], 'case', 'RAKK TANAN', false],
    ['gaming', 0, ['processor' => 'Ryzen 5 5500 Socket', 'gpu' => 'RTX 3050'], 'psu', 'Acer AC-550', true],
    ['school', 0, [], 'processor', 'Ryzen 5 5500GT', true],
    ['school', 0, [], 'processor', 'i9-14900K', false],
    ['school', 0, ['processor' => 'Ryzen 5 5500GT'], 'gpu', 'RTX 3050', false],
    ['school', 0, ['processor' => 'Ryzen 5 5600 Socket'], 'gpu', 'RTX 3050', true],
    ['school', 0, ['processor' => 'Ryzen 5 5600 Socket'], 'gpu', 'RTX 5070', false],
    ['school', 0, ['processor' => 'Ryzen 5 5500 Socket'], 'cooler', 'COREFROZR AA13', false],
    ['school', 0, ['processor' => 'Ryzen 5 5500GT'], 'memory', 'HKC PC 8gb', true],
    ['office', 0, [], 'processor', 'Ryzen 7 5700G', true],
    ['office', 0, [], 'processor', 'Ryzen 3 3200g', false],
    ['office', 0, ['processor' => 'Ryzen 7 5700G'], 'memory', 'Biostar Storming V 8GB', false],
    ['office', 0, ['processor' => 'Ryzen 7 5700G'], 'memory', 'KINGBANK 16GB', true],
    ['office', 0, ['processor' => 'Ryzen 7 5700G'], 'gpu', 'RX 9070', false],
    ['office', 0, ['processor' => 'Ryzen 7 5700G'], 'case_fan', 'NZXT F120', false],
    ['creation', 0, [], 'processor', 'Ryzen 3 3200g', false],
    ['creation', 0, [], 'processor', 'Ryzen 7 5700X', true],
    ['creation', 0, ['processor' => 'Ryzen 7 5700X'], 'gpu', 'RX 9060 XT', true],
    ['creation', 0, ['processor' => 'Ryzen 7 5700X'], 'gpu', 'RTX 3050', false],
    ['creation', 0, ['processor' => 'Ryzen 7 5700X'], 'ssd', 'MP33 512GB', false],
    ['creation', 0, ['processor' => 'Ryzen 7 5700X'], 'ssd', 'NM620 1TB', true],
    ['gaming', 40000, ['processor' => 'Ryzen 5 5600 Socket'], 'gpu', 'RTX 5070', false],
    ['gaming', 40000, ['processor' => 'Ryzen 5 5600 Socket'], 'gpu', 'RTX 3050', true],
];
$find = static function (string $slot, string $fragment) use ($bySlot): ?array {
    foreach ($bySlot[$slot] ?? [] as $product) {
        if (stripos($product['name'], $fragment) !== false) {
            return $product;
        }
    }
    return null;
};
echo "\nexpert scenarios:\n";
$passed = 0;
$ran = 0;
foreach ($scenarios as [$purpose, $budget, $parts, $slot, $fragment, $expected]) {
    $build = [];
    $label = [];
    foreach ($parts as $partSlot => $partName) {
        $part = $find($partSlot, $partName);
        if ($part) {
            $build[$partSlot] = $part;
            $label[] = trim($partName);
        }
    }
    $candidate = $find($slot, $fragment);
    if (!$candidate || count($build) !== count($parts)) {
        printf("  SKIP  %s (product not in catalog)\n", $fragment);
        continue;
    }
    $scored = pc_recommend_score($slot, $candidate, $build, ['purpose' => $purpose, 'budget' => (float)$budget]);
    $got = $scored !== null && $scored['recommended'];
    $ran++;
    $passed += $got === $expected ? 1 : 0;
    printf(
        "  %s  [%s%s] %s -> %s %s %s (score %.2f) %s\n",
        $got === $expected ? 'PASS' : 'FAIL',
        $purposes[$purpose]['label'],
        $budget > 0 ? ', budget ' . number_format($budget) : '',
        $label ? implode(' + ', $label) . ' +' : '(empty build)',
        $slot,
        trim($fragment),
        $expected ? 'recommended' : 'not recommended',
        $scored['score'] ?? 0,
        $scored ? ($scored['reason'] !== '' ? $scored['reason'] : $scored['note']) : '(incompatible)'
    );
}
printf("expert scenarios passed: %d / %d\n", $passed, $ran);

/* 3. "Build it for me": a full build per purpose at each suggested budget; every one must be compatible and within budget. */
echo "\nbuild it for me (suggested budgets):\n";
$guide = pc_recommend_budget_guide($db);
$autoOk = 0;
$autoRan = 0;
foreach ($guide['purposes'] as $purpose => $tiers) {
    foreach ($tiers as $tier => $budget) {
        $result = pc_recommend_auto_build($db, ['purpose' => $purpose, 'budget' => (float)$budget]);
        $total = array_sum(array_map(static fn(array $p): float => (float)$p['price'], $result['added']));
        $compat = ep_pc_compat_validate_build($result['added']);
        $within = $total <= $budget;
        $complete = !$result['missing'];
        $autoRan++;
        $autoOk += ($compat['ok'] && $within && $complete) ? 1 : 0;
        $short = [];
        foreach (['processor', 'gpu', 'memory', 'ssd', 'psu'] as $slot) {
            if (!empty($result['added'][$slot])) {
                $short[] = implode(' ', array_slice(explode(' ', $result['added'][$slot]['name']), 0, 4));
            }
        }
        printf(
            "  %s  %-18s %-11s budget %7s  total %7s  parts %d%s%s | %s\n",
            ($compat['ok'] && $within && $complete) ? 'PASS' : 'FAIL',
            $purposes[$purpose]['label'],
            $tier,
            number_format($budget),
            number_format($total),
            count($result['added']),
            $result['fallback'] ? '  fallback: ' . implode(',', $result['fallback']) : '',
            $result['missing'] ? '  missing: ' . implode(',', $result['missing']) : '',
            implode(' / ', $short)
        );
    }
}
printf("build it for me passed: %d / %d (compatible, complete, within budget)\n", $autoOk, $autoRan);
