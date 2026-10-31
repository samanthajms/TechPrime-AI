<?php
/**
 * Admin — Build Checker: staff pick what the customer will use the PC for and the parts they
 * chose, and each part gets Good fit / Not ideal / Doesn't fit with one plain sentence. The answer
 * comes from the Build a PC expert system (knowledge base includes/pc_rules.php, run by the
 * inference engine includes/pc_inference.php), the same rules and wording the website uses.
 * Below it, the rules themselves in plain English. Read-only: nothing is saved.
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/pc_recommend.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$slotMap = pc_recommend_slot_map();
$purposes = pc_purposes();
$catalog = pc_recommend_catalog($db);
pc_recommend_budget_guide($db);

$ctx = pc_recommend_context(['purpose' => $_GET['purpose'] ?? 'balanced', 'budget' => $_GET['budget'] ?? 0]);
$picked = is_array($_GET['build'] ?? null) ? $_GET['build'] : [];

/* The customer's parts, taken from the in-stock catalog. */
$build = [];
foreach ($slotMap as $slot => $meta) {
    $id = (int)($picked[$slot] ?? 0);
    foreach ($id > 0 ? ($catalog[$slot] ?? []) : [] as $part) {
        if ((int)$part['id'] === $id) {
            $build[$slot] = $part;
            break;
        }
    }
}

/* One verdict per picked part, checked against the other parts. The budget is checked once for the
   whole build below (like the website's build check), not per part. */
$results = [];
$counts = ['good' => 0, 'warn' => 0, 'bad' => 0];
$partCtx = ['purpose' => $ctx['purpose'], 'budget' => 0.0];
$total = 0.0;
foreach ($build as $slot => $part) {
    $total += (float)$part['price'];
    $others = $build;
    unset($others[$slot]);
    $reason = ep_pc_compat_reason_for_candidate($slot, $part['compat'], $others);
    if ($reason !== null) {
        $status = 'bad';
        $text = $reason;
    } else {
        [$why, $note] = pc_recommend_explain($slot, $part, $others, pc_recommend_features($slot, $part, $others, $partCtx), $partCtx);
        $status = $why !== '' ? 'good' : 'warn';
        $text = $why !== '' ? $why : ($note !== '' ? $note : 'The product listing does not say enough to check this part.');
    }
    $counts[$status]++;
    $results[] = ['slot' => $slot, 'part' => $part, 'status' => $status, 'text' => $text];
}

/* Required parts that are still missing. */
$missing = [];
foreach ([['processor'], ['motherboard'], ['memory'], ['ssd', 'ssd_sata', 'hdd'], ['psu'], ['case']] as $slots) {
    if (!array_intersect_key($build, array_flip($slots))) {
        $missing[] = $slots[0] === 'ssd' ? 'Storage (SSD or hard disk)' : $slotMap[$slots[0]]['label'];
    }
}
if (empty($build['gpu']) && pc_recommend_gpu_needed($build, $ctx['purpose'])) {
    $missing[] = $slotMap['gpu']['label'] . (in_array($ctx['purpose'], ['gaming', 'creation'], true) ? '' : ' (the processor has no built-in graphics)');
}
if (empty($build['cooler']) && !empty($build['processor']) && !pc_recommend_spec('processor', $build['processor'])['cooler']) {
    $missing[] = $slotMap['cooler']['label'] . ' (the processor comes without one)';
}

$overBudget = $ctx['budget'] > 0 && $total > $ctx['budget'];

if (!$build) {
    $verdict = null;
} elseif ($counts['bad'] > 0) {
    $verdict = ['bad', 'ph-x-circle', 'Some parts do not fit together'];
} elseif ($missing) {
    $verdict = ['warn', 'ph-warning-circle', 'Not complete yet'];
} elseif ($overBudget) {
    $verdict = ['warn', 'ph-warning-circle', 'Over the budget'];
} elseif ($counts['warn'] > 0) {
    $verdict = ['warn', 'ph-warning-circle', 'Works, but some parts could be better'];
} else {
    $verdict = ['good', 'ph-check-circle', 'Looks good for ' . strtolower($purposes[$ctx['purpose']]['label'])];
}

/*
 * The rules in plain English, per part: what must fit, when a part is a good choice (and for
 * which purposes), and what rules it out. Built from the knowledge base, so it never drifts.
 */
function bc_rule_purposes(array $rule, array $purposes): array
{
    $all = array_keys($purposes);
    foreach ($rule['if'] ?? [] as $c) {
        if ($c[0] === 'purpose') {
            return match ($c[1]) {
                '=' => [$c[2]],
                'in' => $c[2],
                'not_in' => array_values(array_diff($all, $c[2])),
                default => $all,
            };
        }
        if ($c[0] === 'light') {
            return ['school', 'office'];
        }
    }
    return $all;
}

$allPurposes = array_keys($purposes);
$rulesBySlot = ['_all' => ['must' => [], 'good' => [], 'not' => []]];
foreach ($slotMap as $slot => $meta) {
    $rulesBySlot[$slot] = ['must' => [], 'good' => [], 'not' => []];
}
foreach (pc_knowledge_base() as $set => $rules) {
    foreach ($rules as $rule) {
        $then = $rule['then'];
        if (isset($then['incompatible'])) {
            $kind = 'must';
        } elseif (($then['fit'] ?? null) === true) {
            $kind = 'good';
        } elseif (($then['fit'] ?? null) === false || ($then['good_pick'] ?? null) === false) {
            $kind = 'not';
        } else {
            continue; /* facts other rules use, and the final verdict */
        }
        $slots = $rule['slots'] ?? null;
        if ($slots === null) {
            if (in_array('slot', array_column($rule['if'], 0), true)) {
                continue; /* parts outside the Build a PC slots */
            }
            $slots = ['_all'];
        }
        $for = bc_rule_purposes($rule, $purposes);
        $label = count(array_intersect($allPurposes, $for)) === count($allPurposes)
            ? ''
            : implode(', ', array_map(static fn(string $p): string => $purposes[$p]['label'], array_values(array_intersect($allPurposes, $for))));
        foreach ($slots as $s) {
            $rulesBySlot[$s][$kind][] = ['for' => $label, 'text' => $rule['because']];
        }
    }
}
$ruleTotal = 0;
foreach ($rulesBySlot as $groups) {
    $ruleTotal += count($groups['must']) + count($groups['good']) + count($groups['not']);
}

$statusInfo = [
    'good' => ['Good fit', 'ph-check-circle'],
    'warn' => ['Not ideal', 'ph-warning-circle'],
    'bad' => ['Doesn\'t fit', 'ph-x-circle'],
];

staff_page_start([
    'role' => 'admin',
    'title' => 'Build Checker',
    'active' => 'checker',
    'heading' => 'Build Checker',
    'subtitle' => 'Check whether a customer\'s parts fit together and suit what they need',
]);
?>

        <div class="bc-page">
        <section class="cx-card" aria-labelledby="bcFormTitle">
            <div class="cx-card-head">
                <div>
                    <h2 id="bcFormTitle"><i class="ph ph-list-checks" aria-hidden="true"></i> Check a build</h2>
                    <p>Uses the same rules as the Build a PC page on the website.</p>
                </div>
            </div>
            <ol class="bc-steps">
                <li><span class="bc-step-n" aria-hidden="true">1</span> Choose what the customer will use the PC for.</li>
                <li><span class="bc-step-n" aria-hidden="true">2</span> Pick the parts they chose. Leave the rest on “None”.</li>
                <li><span class="bc-step-n" aria-hidden="true">3</span> Press <strong>Check build</strong>.</li>
            </ol>
            <form class="bc-form" method="get" action="admin_build_checker.php">
                <div class="bc-row bc-row-top">
                    <label class="bc-field">
                        <span class="form-label">Used for</span>
                        <select class="form-control" name="purpose">
                            <?php foreach ($purposes as $key => $p): ?>
                            <option value="<?php echo h($key); ?>" <?php echo $ctx['purpose'] === $key ? 'selected' : ''; ?>><?php echo h($p['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="bc-field">
                        <span class="form-label">Budget in ₱ (optional)</span>
                        <input class="form-control" type="number" name="budget" min="0" step="1000" inputmode="numeric" placeholder="No budget" value="<?php echo $ctx['budget'] > 0 ? (int)$ctx['budget'] : ''; ?>">
                    </label>
                </div>
                <div class="bc-row">
                    <?php foreach ($slotMap as $slot => $meta): ?>
                    <label class="bc-field">
                        <span class="form-label"><?php echo h($meta['label']); ?></span>
                        <select class="form-control" name="build[<?php echo h($slot); ?>]">
                            <option value="">None</option>
                            <?php foreach ($catalog[$slot] ?? [] as $part): ?>
                            <option value="<?php echo (int)$part['id']; ?>" <?php echo isset($build[$slot]) && (int)$build[$slot]['id'] === (int)$part['id'] ? 'selected' : ''; ?>><?php echo h($part['name'] . ' · ₱' . number_format((float)$part['price'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="bc-actions">
                    <button type="submit" class="cx-btn cx-btn-primary"><i class="ph ph-check" aria-hidden="true"></i> Check build</button>
                    <?php if ($build): ?>
                    <a class="cx-btn cx-btn-ghost" href="admin_build_checker.php"><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i> Start over</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <?php if ($verdict !== null): ?>
        <section class="cx-card" aria-labelledby="bcResultTitle">
            <div class="bc-verdict is-<?php echo $verdict[0]; ?>" role="status">
                <i class="ph-fill <?php echo $verdict[1]; ?>" aria-hidden="true"></i>
                <div>
                    <h2 id="bcResultTitle"><?php echo h($verdict[2]); ?></h2>
                    <p><?php echo h($purposes[$ctx['purpose']]['label']); ?> · <?php echo count($results); ?> part<?php echo count($results) === 1 ? '' : 's'; ?> checked · total <span class="cx-num">₱<?php echo number_format($total); ?></span><?php
                        if ($ctx['budget'] > 0) {
                            $gap = abs($ctx['budget'] - $total);
                            echo ' · ' . ($overBudget ? '<strong class="bc-over">₱' . number_format($gap) . ' over</strong> the' : '₱' . number_format($gap) . ' left of the') . ' ₱' . number_format($ctx['budget']) . ' budget';
                        } ?></p>
                </div>
            </div>
            <ul class="bc-results">
                <?php foreach ($results as $r): [$label, $icon] = $statusInfo[$r['status']]; ?>
                <li class="is-<?php echo $r['status']; ?>">
                    <div class="bc-result-part">
                        <span class="bc-result-slot"><?php echo h($slotMap[$r['slot']]['label']); ?></span>
                        <strong><?php echo h($r['part']['name']); ?></strong>
                    </div>
                    <span class="bc-pill is-<?php echo $r['status']; ?>"><i class="ph-fill <?php echo $icon; ?>" aria-hidden="true"></i> <?php echo h($label); ?></span>
                    <p class="bc-result-text"><?php echo h($r['text']); ?></p>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($missing): ?>
            <div class="bc-missing">
                <i class="ph ph-info" aria-hidden="true"></i>
                <p><strong>Still needed:</strong> <?php echo h(implode(', ', $missing)); ?>.</p>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="cx-card" aria-labelledby="bcRulesTitle">
            <div class="cx-card-head">
                <div>
                    <h2 id="bcRulesTitle"><i class="ph ph-book-open" aria-hidden="true"></i> The rules it uses</h2>
                    <p><?php echo $ruleTotal; ?> rules a PC technician would follow. Open a part to see its rules.</p>
                </div>
            </div>
            <div class="bc-rules">
                <?php foreach ($rulesBySlot as $slot => $groups):
                    $n = count($groups['must']) + count($groups['good']) + count($groups['not']);
                    if ($n === 0) {
                        continue;
                    } ?>
                <details class="bc-rules-part">
                    <summary>
                        <i class="ph ph-caret-right bc-caret" aria-hidden="true"></i>
                        <span><?php echo h($slot === '_all' ? 'Every part' : $slotMap[$slot]['label']); ?></span>
                        <span class="bc-rules-n"><?php echo $n; ?> rule<?php echo $n === 1 ? '' : 's'; ?></span>
                    </summary>
                    <?php foreach (['must' => 'Must fit the other parts', 'good' => 'A good choice when', 'not' => 'Not a good choice when'] as $kind => $title):
                        if (!$groups[$kind]) {
                            continue;
                        } ?>
                    <h3 class="bc-rules-kind is-<?php echo $kind; ?>"><?php echo h($title); ?></h3>
                    <ul class="bc-rules-list">
                        <?php foreach ($groups[$kind] as $rule): ?>
                        <li><?php if ($rule['for'] !== ''): ?><span class="bc-for"><?php echo h($rule['for']); ?></span><?php endif; ?><span><?php echo h($rule['text']); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endforeach; ?>
                </details>
                <?php endforeach; ?>
            </div>
        </section>
        </div>

<?php staff_page_end(); ?>
