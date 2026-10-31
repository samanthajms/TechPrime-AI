<?php
/**
 * Build a PC — knowledge base of the expert system: a PC technician's rules, written as data.
 * The inference engine (pc_inference.php) runs them; nothing here runs on its own.
 * Rule format: see pc_inference.php. Priorities: 100 = rules that rule a part out,
 * 50-60 = rules that derive a fact other rules need, 10 = rules that accept a part, 5 = final verdict.
 *
 * 'compat' — hard compatibility, decided from the socket / RAM type / form factor / wattage
 * read from product text (facts from ep_pc_compat_facts() in pc_compatibility.php).
 * Goal fact: incompatible (the reason shown to the customer).
 *
 * 'fit' — is the part a good pick for the customer's purpose? Facts are the model features
 * (pc_recommend_features()) plus purpose and plain units (pc_recommend_fit_facts()).
 * Goal facts: fit (the part suits the purpose and the build) and good_pick (fit and it also
 * leaves enough budget for the parts still missing). These rules label the Random Forest's
 * training rows (scripts/build_pc_recommend_dataset.php) and explain its picks.
 * Purposes: school = School & Everyday, office = Office & Work, creation = Content Creation,
 * balanced = Not sure yet.
 */

/** @return array<string,array<int,array<string,mixed>>> rule set => rules */
function pc_knowledge_base(): array
{
    static $kb = null;
    return $kb ??= ['compat' => pc_rules_compat(), 'fit' => pc_rules_fit()];
}

/** @return array<int,array<string,mixed>> */
function pc_rules_compat(): array
{
    return [
        /* Derived facts: the RAM generation a socket supports when it supports only one
           (LGA1700 boards exist for both DDR4 and DDR5). */
        ['id' => 'CPU-AM4-NEEDS-DDR4', 'slots' => ['memory'], 'priority' => 50,
            'if' => [['cpu.socket', '=', 'AM4']], 'then' => ['cpu.ddr_needed' => 'DDR4'],
            'because' => 'AM4 processors only work with DDR4 memory.'],
        ['id' => 'CPU-AM5-NEEDS-DDR5', 'slots' => ['memory'], 'priority' => 50,
            'if' => [['cpu.socket', '=', 'AM5']], 'then' => ['cpu.ddr_needed' => 'DDR5'],
            'because' => 'AM5 processors only work with DDR5 memory.'],
        ['id' => 'CPU-LGA1851-NEEDS-DDR5', 'slots' => ['memory'], 'priority' => 50,
            'if' => [['cpu.socket', '=', 'LGA1851']], 'then' => ['cpu.ddr_needed' => 'DDR5'],
            'because' => 'LGA1851 processors only work with DDR5 memory.'],
        ['id' => 'CAND-AM4-NEEDS-DDR4', 'slots' => ['processor'], 'priority' => 50,
            'if' => [['cand.socket', '=', 'AM4']], 'then' => ['cand.ddr_needed' => 'DDR4'],
            'because' => 'AM4 processors only work with DDR4 memory.'],
        ['id' => 'CAND-AM5-NEEDS-DDR5', 'slots' => ['processor'], 'priority' => 50,
            'if' => [['cand.socket', '=', 'AM5']], 'then' => ['cand.ddr_needed' => 'DDR5'],
            'because' => 'AM5 processors only work with DDR5 memory.'],
        ['id' => 'CAND-LGA1851-NEEDS-DDR5', 'slots' => ['processor'], 'priority' => 50,
            'if' => [['cand.socket', '=', 'LGA1851']], 'then' => ['cand.ddr_needed' => 'DDR5'],
            'because' => 'LGA1851 processors only work with DDR5 memory.'],

        /* Derived fact: the socket a cooler must fit — the processor's, else the motherboard's. */
        ['id' => 'COOLER-SOCKET-FROM-CPU', 'slots' => ['cooler'], 'priority' => 60,
            'if' => [['cpu.socket', 'known']], 'then' => ['ref_socket' => '@cpu.socket'],
            'because' => 'The cooler must fit the processor socket ({ref_socket}).'],
        ['id' => 'COOLER-SOCKET-FROM-BOARD', 'slots' => ['cooler'], 'priority' => 50,
            'if' => [['board.socket', 'known']], 'then' => ['ref_socket' => '@board.socket'],
            'because' => 'No processor yet: the cooler must fit the motherboard socket ({ref_socket}).'],

        /* Derived fact: estimated power draw of the build, part by part (watts). */
        ['id' => 'DRAW-CPU', 'slots' => ['psu'], 'priority' => 50,
            'if' => [['has.processor', '=', true]], 'then' => ['+build.draw' => 65],
            'because' => 'A processor draws about 65 W.'],
        ['id' => 'DRAW-BOARD', 'slots' => ['psu'], 'priority' => 50,
            'if' => [['has.motherboard', '=', true]], 'then' => ['+build.draw' => 40],
            'because' => 'A motherboard draws about 40 W.'],
        ['id' => 'DRAW-GPU', 'slots' => ['psu'], 'priority' => 50,
            'if' => [['has.gpu', '=', true]], 'then' => ['+build.draw' => 180],
            'because' => 'A graphics card draws about 180 W.'],
        ['id' => 'DRAW-RAM', 'slots' => ['psu'], 'priority' => 50,
            'if' => [['has.memory', '=', true]], 'then' => ['+build.draw' => 10],
            'because' => 'Memory draws about 10 W.'],
        ['id' => 'DRAW-STORAGE', 'slots' => ['psu'], 'priority' => 50,
            'if' => [['has.storage', '=', true]], 'then' => ['+build.draw' => 15],
            'because' => 'Storage draws about 15 W.'],
        ['id' => 'GPU-DRAW-CARD', 'slots' => ['gpu'], 'priority' => 50,
            'if' => [], 'then' => ['+gpu_build.draw' => 180],
            'because' => 'A graphics card draws about 180 W.'],
        ['id' => 'GPU-DRAW-OTHER', 'slots' => ['gpu'], 'priority' => 50,
            'if' => [], 'then' => ['+gpu_build.draw' => 25],
            'because' => 'Memory, drives and fans draw about 25 W.'],
        ['id' => 'GPU-DRAW-CPU', 'slots' => ['gpu'], 'priority' => 50,
            'if' => [['has.processor', '=', true]], 'then' => ['+gpu_build.draw' => 65],
            'because' => 'The processor draws about 65 W.'],
        ['id' => 'GPU-DRAW-BOARD', 'slots' => ['gpu'], 'priority' => 50,
            'if' => [['has.motherboard', '=', true]], 'then' => ['+gpu_build.draw' => 40],
            'because' => 'The motherboard draws about 40 W.'],

        /* Compatibility checks, in the order the customer sees them (the first one that fails is shown). */
        ['id' => 'CPU-SOCKET-VS-BOARD', 'slots' => ['processor'], 'priority' => 10,
            'if' => [['board.socket', 'known'], ['cand.socket', 'known'], ['cand.socket', '!=', '@board.socket']],
            'then' => ['incompatible' => 'Not compatible with the selected motherboard — CPU socket mismatch.'],
            'because' => 'The processor socket must match the motherboard socket.'],
        ['id' => 'BOARD-SOCKET-VS-CPU', 'slots' => ['motherboard'], 'priority' => 10,
            'if' => [['cpu.socket', 'known'], ['cand.socket', 'known'], ['cand.socket', '!=', '@cpu.socket']],
            'then' => ['incompatible' => 'Not compatible with the selected processor — CPU socket mismatch.'],
            'because' => 'The motherboard socket must match the processor socket.'],
        ['id' => 'BOARD-DDR-VS-RAM', 'slots' => ['motherboard'], 'priority' => 10,
            'if' => [['ram.ddr', 'known'], ['cand.ddr', 'known'], ['cand.ddr', '!=', '@ram.ddr']],
            'then' => ['incompatible' => 'Not compatible with the selected memory — RAM type mismatch.'],
            'because' => 'The motherboard must take the same memory type (DDR4 / DDR5) as the memory picked.'],
        ['id' => 'RAM-DDR-VS-BOARD', 'slots' => ['memory'], 'priority' => 10,
            'if' => [['board.ddr', 'known'], ['cand.ddr', 'known'], ['cand.ddr', '!=', '@board.ddr']],
            'then' => ['incompatible' => 'Not compatible with the selected motherboard — RAM type mismatch.'],
            'because' => 'The memory type (DDR4 / DDR5) must match the motherboard.'],
        ['id' => 'RAM-DDR-VS-CPU', 'slots' => ['memory'], 'priority' => 10,
            'if' => [['cpu.ddr_needed', 'known'], ['cand.ddr', 'known'], ['cand.ddr', '!=', '@cpu.ddr_needed']],
            'then' => ['incompatible' => 'Not compatible with the selected processor — it needs {cpu.ddr_needed} memory.'],
            'because' => 'The memory type must be one the processor supports (AM4 = DDR4; AM5 and LGA1851 = DDR5).'],
        ['id' => 'CPU-DDR-VS-RAM', 'slots' => ['processor'], 'priority' => 10,
            'if' => [['ram.ddr', 'known'], ['cand.ddr_needed', 'known'], ['cand.ddr_needed', '!=', '@ram.ddr']],
            'then' => ['incompatible' => 'Not compatible with the selected memory — this processor needs {cand.ddr_needed}.'],
            'because' => 'The processor must support the memory type already picked (AM4 = DDR4; AM5 and LGA1851 = DDR5).'],
        ['id' => 'COOLER-SOCKET', 'slots' => ['cooler'], 'priority' => 10,
            'if' => [['ref_socket', 'known'], ['cand.socket', 'known'], ['cand.socket', '!=', '@ref_socket']],
            'then' => ['incompatible' => 'Not compatible with the selected CPU/motherboard — cooler socket mismatch.'],
            'because' => 'The cooler must fit the processor socket (or the motherboard socket when no processor is picked).'],
        ['id' => 'CASE-TOO-SMALL', 'slots' => ['case'], 'priority' => 10,
            'if' => [['board.form_rank', 'known'], ['cand.form_rank', 'known'], ['cand.form_rank', '<', '@board.form_rank']],
            'then' => ['incompatible' => 'Not compatible with the selected motherboard — case form factor too small.'],
            'because' => 'The case must be big enough for the motherboard (Mini-ITX < Micro-ATX < ATX < E-ATX).'],
        ['id' => 'BOARD-TOO-BIG', 'slots' => ['motherboard'], 'priority' => 10,
            'if' => [['case.form_rank', 'known'], ['cand.form_rank', 'known'], ['case.form_rank', '<', '@cand.form_rank']],
            'then' => ['incompatible' => 'Not compatible with the selected case — motherboard form factor too large.'],
            'because' => 'The motherboard must fit inside the case picked.'],
        ['id' => 'SSD-IS-SATA', 'slots' => ['ssd'], 'priority' => 10,
            'if' => [['cand.storage', '=', 'SATA'], ['cand.says_nvme', '=', false]],
            'then' => ['incompatible' => 'Not compatible with this SSD slot — SATA drive should use SSD (SATA).'],
            'because' => 'A SATA-only drive goes in the SSD (SATA) slot, not the NVMe slot.'],
        ['id' => 'PSU-WATTAGE-LOW', 'slots' => ['psu'], 'priority' => 10,
            'if' => [['cand.watts', 'known'], ['cand.watts', '<', '@build.draw']],
            'then' => ['incompatible' => 'Not compatible with the current build — PSU wattage too low for estimated power draw.'],
            'because' => 'The power supply must cover the estimated power draw of the parts picked.'],
        ['id' => 'GPU-PSU-TOO-SMALL', 'slots' => ['gpu'], 'priority' => 10,
            'if' => [['psu.watts', 'known'], ['psu.watts', '<', '@gpu_build.draw']],
            'then' => ['incompatible' => 'Not compatible with the selected power supply — estimated GPU build draw exceeds PSU wattage.'],
            'because' => 'The power supply picked must be strong enough to add a graphics card.'],
    ];
}

/** @return array<int,array<string,mixed>> */
function pc_rules_fit(): array
{
    $gamingLike = ['gaming', 'balanced'];
    $storage = ['ssd_sata', 'hdd'];
    return [
        /* Rule-outs: checked first, they decide on their own. */
        ['id' => 'BUDGET-SHORT', 'priority' => 100,
            'if' => [['budget_slack', '<', 0]], 'then' => ['good_pick' => false],
            'because' => 'It leaves too little budget for the required parts still missing.'],
        ['id' => 'CPU-COOLER-TOO-WEAK', 'slots' => ['processor'], 'priority' => 100,
            'if' => [['cooling_margin', '>', 0], ['cooling_margin', '<', 1.1]], 'then' => ['fit' => false],
            'because' => 'The cooler already picked is too weak for it.'],
        ['id' => 'PSU-LOW-RATING', 'slots' => ['psu'], 'priority' => 100,
            'if' => [['psu_rating', '<', 2]], 'then' => ['fit' => false],
            'because' => 'Below 80 Plus Bronze efficiency.'],
        ['id' => 'PSU-TOO-SMALL', 'slots' => ['psu'], 'priority' => 100,
            'if' => [['psu_headroom', '<', 1.0]], 'then' => ['fit' => false],
            'because' => 'Less wattage than the build should have (30% above its power draw, at least 450 W).'],
        ['id' => 'PSU-TOO-BIG', 'slots' => ['psu'], 'priority' => 100,
            'if' => [['psu_headroom', '>', 1.7]], 'then' => ['fit' => false],
            'because' => 'Far more wattage than the build needs (over 1.7x).'],
        ['id' => 'CASE-BUNDLED-PSU-LEVEL', 'slots' => ['case'], 'priority' => 100,
            'if' => [['case_bundle', '>', 0], ['build_level', '>=', 0.5]], 'then' => ['fit' => false],
            'because' => 'Comes with a bundled power supply, in a mid- or high-end build.'],
        ['id' => 'CASE-BUNDLED-PSU-PURPOSE', 'slots' => ['case'], 'priority' => 100,
            'if' => [['case_bundle', '>', 0], ['purpose', 'in', ['gaming', 'creation']]], 'then' => ['fit' => false],
            'because' => 'Comes with a bundled power supply.'],
        ['id' => 'CASE-TOO-BIG', 'slots' => ['case'], 'priority' => 100,
            'if' => [['form_slack', '>', 1]], 'then' => ['fit' => false],
            'because' => 'Much bigger than the motherboard needs.'],

        /* Derived facts. */
        ['id' => 'LIGHT-USE', 'priority' => 50,
            'if' => [['purpose', 'in', ['school', 'office']]], 'then' => ['light' => true],
            'because' => 'School and office PCs are light-use builds.'],
        ['id' => 'DISPLAY-FROM-CPU', 'slots' => ['processor'], 'priority' => 50,
            'if' => [['cand_igpu', '>', 0]], 'then' => ['has_display' => true],
            'because' => 'The processor has built-in graphics.'],
        ['id' => 'DISPLAY-FROM-GPU', 'slots' => ['processor'], 'priority' => 50,
            'if' => [['gpu_level', '>', 0]], 'then' => ['has_display' => true],
            'because' => 'The build already has a graphics card.'],
        ['id' => 'BUDGET-BUILD', 'slots' => ['memory', 'ssd'], 'priority' => 50,
            'if' => [['build_level', '<=', 0.4]], 'then' => ['budget_build' => true],
            'because' => 'This is a budget-level build.'],

        /* Processor (tiers 1-10). */
        ['id' => 'CPU-SCHOOL', 'slots' => ['processor'], 'priority' => 10,
            'if' => [['purpose', '=', 'school'], ['cpu_tier', '>=', 1.5], ['cpu_tier', '<=', 5.5], ['has_display', '=', true]],
            'then' => ['fit' => true], 'because' => 'Entry to mid-range processor (tier 2–5 of 10) that can show a display (built-in graphics or a graphics card).'],
        ['id' => 'CPU-OFFICE', 'slots' => ['processor'], 'priority' => 10,
            'if' => [['purpose', '=', 'office'], ['cpu_tier', '>=', 3.5], ['cpu_tier', '<=', 7.5], ['has_display', '=', true]],
            'then' => ['fit' => true], 'because' => 'Mid-range processor (tier 4–7 of 10) that can show a display (built-in graphics or a graphics card).'],
        ['id' => 'CPU-CREATION-NO-GPU', 'slots' => ['processor'], 'priority' => 10,
            'if' => [['purpose', '=', 'creation'], ['cpu_tier', '>=', 5.5], ['gpu_level', '<=', 0]],
            'then' => ['fit' => true], 'because' => 'Upper mid-range or better processor (tier 6+ of 10).'],
        ['id' => 'CPU-CREATION-WITH-GPU', 'slots' => ['processor'], 'priority' => 10,
            'if' => [['purpose', '=', 'creation'], ['cpu_tier', '>=', 5.5], ['balance', '>=', -0.3]],
            'then' => ['fit' => true], 'because' => 'Tier 6+ processor that is not far below the graphics card.'],
        ['id' => 'CPU-BALANCED', 'slots' => ['processor'], 'priority' => 10,
            'if' => [['purpose', 'in', $gamingLike], ['abs_balance', '<=', 0.2]],
            'then' => ['fit' => true], 'because' => 'Within 2 tiers of the graphics card (or the rest of the build), so neither holds the other back.'],

        /* Graphics card. */
        ['id' => 'GPU-LIGHT-NEEDED', 'slots' => ['gpu'], 'priority' => 10,
            'if' => [['light', '=', true], ['cpu_igpu', '=', 0], ['perf_tier', '<=', 0.35]],
            'then' => ['fit' => true], 'because' => 'Only an entry-level card (tier 3 or lower), and only when the processor has no built-in graphics.'],
        ['id' => 'GPU-CREATION-12GB', 'slots' => ['gpu'], 'priority' => 10,
            'if' => [['purpose', '=', 'creation'], ['vram_gb', '>=', 11.5]],
            'then' => ['fit' => true], 'because' => '12 GB of video memory or more.'],
        ['id' => 'GPU-CREATION-8GB', 'slots' => ['gpu'], 'priority' => 10,
            'if' => [['purpose', '=', 'creation'], ['vram_gb', '>=', 7.5], ['perf_tier', '>=', 0.55]],
            'then' => ['fit' => true], 'because' => '8 GB of video memory on a tier 6+ card.'],
        ['id' => 'GPU-BALANCED', 'slots' => ['gpu'], 'priority' => 10,
            'if' => [['purpose', 'in', $gamingLike], ['abs_balance', '<=', 0.2]],
            'then' => ['fit' => true], 'because' => 'Within 2 tiers of the processor (or the rest of the build), so neither holds the other back.'],

        /* Motherboard: entry chipset for CPUs up to tier 5, mid-range above; at most one class higher. */
        ['id' => 'BOARD-MATCH', 'slots' => ['motherboard'], 'priority' => 10,
            'if' => [['balance', '>', -0.01], ['balance', '<', 0.34]],
            'then' => ['fit' => true], 'because' => 'Entry chipset for processors up to tier 5, mid-range above; at most one class higher than needed.'],

        /* Memory. */
        ['id' => 'RAM-SCHOOL', 'slots' => ['memory'], 'priority' => 10,
            'if' => [['purpose', '=', 'school'], ['ram_gb', '>=', 7.5]],
            'then' => ['fit' => true], 'because' => '8 GB or more.'],
        ['id' => 'RAM-16GB', 'slots' => ['memory'], 'priority' => 10,
            'if' => [['purpose', 'in', ['office', 'creation', 'gaming', 'balanced']], ['ram_gb', '>=', 15.5]],
            'then' => ['fit' => true], 'because' => '16 GB or more.'],
        ['id' => 'RAM-BUDGET-8GB', 'slots' => ['memory'], 'priority' => 10,
            'if' => [['purpose', 'in', $gamingLike], ['ram_gb', '>=', 7.5], ['budget_build', '=', true]],
            'then' => ['fit' => true], 'because' => '8 GB is enough in a budget-level build.'],

        /* NVMe SSD. */
        ['id' => 'NVME-SCHOOL', 'slots' => ['ssd'], 'priority' => 10,
            'if' => [['purpose', '=', 'school'], ['disk_gb', '>=', 240]],
            'then' => ['fit' => true], 'because' => '256 GB or more.'],
        ['id' => 'NVME-OFFICE', 'slots' => ['ssd'], 'priority' => 10,
            'if' => [['purpose', '=', 'office'], ['disk_gb', '>=', 480]],
            'then' => ['fit' => true], 'because' => '500 GB or more.'],
        ['id' => 'NVME-CREATION', 'slots' => ['ssd'], 'priority' => 10,
            'if' => [['purpose', '=', 'creation'], ['disk_gb', '>=', 960]],
            'then' => ['fit' => true], 'because' => '1 TB or more.'],
        ['id' => 'NVME-500GB', 'slots' => ['ssd'], 'priority' => 10,
            'if' => [['purpose', 'in', $gamingLike], ['disk_gb', '>=', 480]],
            'then' => ['fit' => true], 'because' => '500 GB or more.'],
        ['id' => 'NVME-BUDGET', 'slots' => ['ssd'], 'priority' => 10,
            'if' => [['purpose', 'in', $gamingLike], ['disk_gb', '>=', 240], ['budget_build', '=', true]],
            'then' => ['fit' => true], 'because' => '256 GB is enough in a budget-level build.'],

        /* SATA SSD / hard disk. */
        ['id' => 'DRIVE-CREATION', 'slots' => $storage, 'priority' => 10,
            'if' => [['purpose', '=', 'creation'], ['disk_gb', '>=', 480]],
            'then' => ['fit' => true], 'because' => '500 GB or more.'],
        ['id' => 'DRIVE-256GB', 'slots' => $storage, 'priority' => 10,
            'if' => [['purpose', 'not_in', ['creation']], ['disk_gb', '>=', 240]],
            'then' => ['fit' => true], 'because' => '256 GB or more.'],

        /* Power supply (after the rule-outs above: 1x-1.7x the suggested wattage, Bronze or better). */
        ['id' => 'PSU-NO-HIGH-END-GPU', 'slots' => ['psu'], 'priority' => 10,
            'if' => [['gpu_level', '<', 0.8]],
            'then' => ['fit' => true], 'because' => 'Right wattage and Bronze or better, when there is no high-end graphics card.'],
        ['id' => 'PSU-GOLD', 'slots' => ['psu'], 'priority' => 10,
            'if' => [['psu_rating', '>=', 3]],
            'then' => ['fit' => true], 'because' => 'Gold-rated: safe even with a high-end (tier 8+) graphics card.'],
        ['id' => 'PSU-ATX3', 'slots' => ['psu'], 'priority' => 10,
            'if' => [['psu_atx3', '>', 0]],
            'then' => ['fit' => true], 'because' => 'ATX 3.1, with the power connector high-end graphics cards use.'],

        /* CPU cooler (cooling_margin = cooler capacity / CPU heat). */
        ['id' => 'COOLER-LIGHT', 'slots' => ['cooler'], 'priority' => 10,
            'if' => [['light', '=', true], ['cpu_cooler', '<=', 0], ['cooling_margin', '>=', 1.1], ['cooling_margin', '<=', 2.0]],
            'then' => ['fit' => true], 'because' => 'Only when the processor has no cooler of its own; handles 1.1x–2x the processor\'s heat.'],
        ['id' => 'COOLER-CREATION', 'slots' => ['cooler'], 'priority' => 10,
            'if' => [['purpose', '=', 'creation'], ['cooling_margin', '>=', 1.1], ['cooling_margin', '<=', 4.5]],
            'then' => ['fit' => true], 'because' => 'Handles 1.1x–4.5x the processor\'s heat.'],
        ['id' => 'COOLER-GAMING', 'slots' => ['cooler'], 'priority' => 10,
            'if' => [['purpose', 'in', $gamingLike], ['cooling_margin', '>=', 1.1], ['cooling_margin', '<=', 3.0]],
            'then' => ['fit' => true], 'because' => 'Handles 1.1x–3x the processor\'s heat.'],

        /* Case, case fans and any other part: a similar price range to the rest of the build. */
        ['id' => 'CASE-PRICE-RANGE', 'slots' => ['case'], 'priority' => 10,
            'if' => [['abs_tier_gap', '<=', 0.35]],
            'then' => ['fit' => true], 'because' => 'In a similar price range to the rest of the build.'],
        ['id' => 'FAN-PRICE-RANGE', 'slots' => ['case_fan'], 'priority' => 10,
            'if' => [['purpose', 'not_in', ['school', 'office']], ['abs_tier_gap', '<=', 0.2]],
            'then' => ['fit' => true], 'because' => 'In a similar price range to the rest of the build (extra fans are not needed for school or office).'],
        ['id' => 'OTHER-PRICE-RANGE', 'priority' => 10,
            'if' => [['slot', 'not_in', ['processor', 'gpu', 'motherboard', 'memory', 'ssd', 'ssd_sata', 'hdd', 'psu', 'cooler', 'case', 'case_fan']], ['abs_tier_gap', '<=', 0.2]],
            'then' => ['fit' => true], 'because' => 'In a similar price range to the rest of the build.'],

        /* Final verdict. */
        ['id' => 'GOOD-PICK', 'priority' => 5,
            'if' => [['fit', '=', true]], 'then' => ['good_pick' => true],
            'because' => 'It fits and leaves enough budget for the rest.'],
    ];
}
