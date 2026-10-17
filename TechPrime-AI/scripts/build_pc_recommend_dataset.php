<?php
/**
 * Read-only: write the Build a PC recommendation training table.
 * A row is one compatible candidate next to a partial build.
 * Label 1 = price, wattage, and case size fit that build. Label 0 = compatible but a poor fit.
 *
 * Usage: php scripts/build_pc_recommend_dataset.php
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/pc_recommend.php';

$out = __DIR__ . '/../ml/pc_recommend/data/train.csv';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0777, true);
}

$db = getDbConnection();
$bySlot = [];
foreach (array_keys(pc_recommend_slot_map()) as $slot) {
    $bySlot[$slot] = pc_recommend_products_for_slot($db, $slot);
}

mt_srand(42);
$names = pc_recommend_feature_names();
$fh = fopen($out, 'wb');
fputcsv($fh, array_merge(['slot'], $names, ['label']));

$rows = 0;
$positives = 0;
foreach (array_keys($bySlot) as $target) {
    if (!$bySlot[$target]) {
        continue;
    }
    $others = [];
    foreach ($bySlot as $slot => $products) {
        if ($slot !== $target && $products) {
            $others[] = $slot;
        }
    }
    for ($sample = 0; $sample < 48; $sample++) {
        $build = [];
        $pool = $others;
        shuffle($pool);
        $want = 1 + ($sample % 3);
        foreach (array_slice($pool, 0, $want) as $slot) {
            $choices = $bySlot[$slot];
            shuffle($choices);
            foreach (array_slice($choices, 0, 12) as $part) {
                if (ep_pc_compat_reason_for_candidate($slot, $part['compat'], $build) === null) {
                    $build[$slot] = $part;
                    break;
                }
            }
        }
        if (!$build) {
            continue;
        }
        foreach ($bySlot[$target] as $candidate) {
            if (ep_pc_compat_reason_for_candidate($target, $candidate['compat'], $build) !== null) {
                continue;
            }
            $features = pc_recommend_features($target, $candidate, $build);
            $label = pc_recommend_is_positive($target, $features) ? 1 : 0;
            $line = [$target];
            foreach ($names as $name) {
                $line[] = $features[$name];
            }
            $line[] = $label;
            fputcsv($fh, $line);
            $rows++;
            $positives += $label;
        }
    }
}
fclose($fh);
fwrite(STDERR, "rows=$rows positives=$positives file=$out\n");
