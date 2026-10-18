<?php
/**
 * Read-only: write the Build a PC recommendation training table.
 * A row is one compatible candidate next to a partial build, for one purpose and budget. Two sources:
 *  - rule: random partial builds from the catalog, for every purpose (pc_purpose.php) and a
 *    sample budget, labelled by pc_recommend_is_positive() (1 = good fit for that purpose, 0 = not).
 *  - real: customer builds (saved builds, online orders, POS sales with 2+ build parts).
 *    One part is hidden; the part the customer chose is 1, the other compatible parts are 0.
 *    Saved builds carry the purpose the customer picked ("_purpose"); others count as "Not sure yet".
 * Real picks weigh more, so the model follows customers wherever they disagree with the rule.
 *
 * Usage: php scripts/build_pc_recommend_dataset.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/pc_recommend.php';

/* Partial builds per slot and purpose: at least 60, more for slots with few products (about 1200 rows each). */
const RULE_SAMPLES_MIN = 60;
const RULE_ROWS_PER_SLOT = 1200;
/* Sample budgets in pesos for rule rows; 0 = no budget set. */
const RULE_BUDGETS = [0, 0, 30000, 45000, 70000, 110000, 200000];
const REAL_POSITIVE_WEIGHT = 5;
const REAL_NEGATIVE_WEIGHT = 1;

$out = __DIR__ . '/../ml/pc_recommend/data/train.csv';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0777, true);
}

$db = getDbConnection();
$bySlot = pc_recommend_catalog($db);
$slotOfId = [];
foreach ($bySlot as $slot => $products) {
    foreach ($products as $product) {
        $slotOfId[$product['id']] ??= [$slot, $product];
    }
}

$names = pc_recommend_feature_names();
$fh = fopen($out, 'wb');
fputcsv($fh, array_merge(['group', 'query', 'source', 'slot'], $names, ['label', 'weight', 'rule']));
$count = ['rule' => 0, 'rule_pos' => 0, 'real' => 0, 'real_pos' => 0, 'real_builds' => 0, 'real_queries' => 0];

function pcr_write_row($fh, array $names, string $group, string $query, string $source, string $slot, array $features, int $label, int $weight): void
{
    $line = [$group, $query, $source, $slot];
    foreach ($names as $name) {
        $line[] = $features[$name];
    }
    $line[] = $label;
    $line[] = $weight;
    $line[] = pc_recommend_is_positive($slot, $features) ? 1 : 0;
    fputcsv($fh, $line);
}

/* Rule rows */
mt_srand(42);
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
    $samples = max(RULE_SAMPLES_MIN, (int)ceil(RULE_ROWS_PER_SLOT / count($bySlot[$target])));
    foreach (array_keys(pc_purposes()) as $purpose) {
    for ($sample = 0; $sample < $samples; $sample++) {
        $build = [];
        $pool = $others;
        shuffle($pool);
        /* Customers usually pick the processor first, and many rules depend on it: start most builds with one. */
        if ($target !== 'processor' && $sample % 3 !== 0) {
            $pool = array_values(array_unique(array_merge(['processor'], $pool)));
        }
        /* Some builds are empty: the first part of a build (and "Build it for me") is scored too. */
        $want = $sample % 6;
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
        $ctx = ['purpose' => $purpose, 'budget' => (float)RULE_BUDGETS[mt_rand(0, count(RULE_BUDGETS) - 1)]];
        $group = "rule:$target:$purpose:$sample";
        foreach ($bySlot[$target] as $candidate) {
            if (ep_pc_compat_reason_for_candidate($target, $candidate['compat'], $build) !== null) {
                continue;
            }
            $features = pc_recommend_features($target, $candidate, $build, $ctx);
            $label = pc_recommend_is_positive($target, $features) ? 1 : 0;
            pcr_write_row($fh, $names, $group, $group, 'rule', $target, $features, $label, 1);
            $count['rule']++;
            $count['rule_pos'] += $label;
        }
    }
    }
}

/* Real customer builds: slot => catalog product, keyed by source */
$realBuilds = [];
$customerOf = [];
$purposeOf = [];
foreach ($db->query('SELECT id, user_id, components_json FROM saved_builds ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $components = json_decode((string)$row['components_json'], true);
    if (!is_array($components)) {
        continue;
    }
    $parts = [];
    foreach ($components as $slot => $item) {
        $id = is_array($item) ? (int)($item['id'] ?? 0) : 0;
        if ($id > 0 && isset($slotOfId[$id]) && $slotOfId[$id][0] === $slot) {
            $parts[$slot] = $slotOfId[$id][1];
        }
    }
    $realBuilds['saved:' . $row['id']] = $parts;
    $purposeOf['saved:' . $row['id']] = pc_purpose_key($components['_purpose'] ?? '');
    $customerOf['saved:' . $row['id']] = 'user:' . (int)$row['user_id'];
}
$baskets = [
    'order' => "SELECT oi.order_id AS basket, oi.product_id, 'user:' || o.user_id AS customer FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE COALESCE(o.status, '') NOT ILIKE 'cancel%' ORDER BY oi.order_id, oi.id",
    'pos' => "SELECT si.sale_id AS basket, si.product_id, 'sale:' || si.sale_id AS customer FROM pos_sale_items si
              INNER JOIN pos_sales s ON s.id = si.sale_id
              WHERE s.status = 'completed' ORDER BY si.sale_id, si.id",
];
foreach ($baskets as $source => $sql) {
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['product_id'];
        if (!isset($slotOfId[$id])) {
            continue;
        }
        [$slot, $product] = $slotOfId[$id];
        $realBuilds[$source . ':' . $row['basket']][$slot] ??= $product;
        $customerOf[$source . ':' . $row['basket']] = (string)$row['customer'];
    }
}

/* The same customer saving the same parts twice is one example, not two. */
$seenBuilds = [];
foreach ($realBuilds as $source => $parts) {
    if (count($parts) < 2) {
        continue;
    }
    $signature = [];
    foreach ($parts as $slot => $part) {
        $signature[] = $slot . '=' . $part['id'];
    }
    sort($signature);
    $purpose = $purposeOf[$source] ?? 'balanced';
    $signature = $customerOf[$source] . '|' . $purpose . '|' . implode(',', $signature);
    if (isset($seenBuilds[$signature])) {
        continue;
    }
    $seenBuilds[$signature] = true;
    $count['real_builds']++;
    /* Grouped by customer, so cross-validation always tests on customers the model has not seen. */
    $group = 'real:' . $customerOf[$source];
    foreach ($parts as $target => $chosen) {
        $build = $parts;
        unset($build[$target]);
        if (ep_pc_compat_reason_for_candidate($target, $chosen['compat'], $build) !== null) {
            continue;
        }
        $candidates = [];
        foreach ($bySlot[$target] as $candidate) {
            if (ep_pc_compat_reason_for_candidate($target, $candidate['compat'], $build) === null) {
                $candidates[] = $candidate;
            }
        }
        if (count($candidates) < 2) {
            continue;
        }
        $count['real_queries']++;
        foreach ($candidates as $candidate) {
            $label = $candidate['id'] === $chosen['id'] ? 1 : 0;
            $features = pc_recommend_features($target, $candidate, $build, ['purpose' => $purpose, 'budget' => 0.0]);
            $weight = $label ? REAL_POSITIVE_WEIGHT : REAL_NEGATIVE_WEIGHT;
            pcr_write_row($fh, $names, $group, "real:$source:$target", 'real', $target, $features, $label, $weight);
            $count['real']++;
            $count['real_pos'] += $label;
        }
    }
}
fclose($fh);
fwrite(STDERR, sprintf(
    "rule rows=%d (positives=%d)  real builds=%d queries=%d rows=%d (positives=%d)  file=%s\n",
    $count['rule'],
    $count['rule_pos'],
    $count['real_builds'],
    $count['real_queries'],
    $count['real'],
    $count['real_pos'],
    realpath($out)
));
