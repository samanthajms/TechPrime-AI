<?php
/**
 * PC build compatibility helpers.
 * Derives socket / RAM / form-factor / wattage signals ONLY from existing
 * product name + description text (no invented DB columns).
 * The compatibility rules themselves are in the knowledge base (pc_rules.php).
 */

require_once __DIR__ . '/pc_inference.php';

/**
 * Extract hardware tags from existing product text fields.
 *
 * @return array{socket:?string,ddr:?string,form:?string,storage:?string,watts:?int,raw:string}
 */
function ep_pc_compat_tags(string $name, string $description = '', string $category = ''): array
{
    $raw = strtolower(trim($name . ' ' . $description . ' ' . $category));
    $tags = [
        'socket' => null,
        'ddr' => null,
        'form' => null,
        'storage' => null,
        'watts' => null,
        'raw' => $raw,
    ];

    if (preg_match('/\bam5\b/', $raw)) {
        $tags['socket'] = 'AM5';
    } elseif (preg_match('/\bam4\b/', $raw)) {
        $tags['socket'] = 'AM4';
    } elseif (preg_match('/\blga\s*1851\b/', $raw)) {
        $tags['socket'] = 'LGA1851';
    } elseif (preg_match('/\blga\s*1700\b/', $raw)) {
        $tags['socket'] = 'LGA1700';
    } elseif (preg_match('/\blga\s*1200\b/', $raw)) {
        $tags['socket'] = 'LGA1200';
    } elseif (preg_match('/\blga\s*1151\b/', $raw)) {
        $tags['socket'] = 'LGA1151';
    } elseif (preg_match('/\blga\s*1150\b/', $raw)) {
        $tags['socket'] = 'LGA1150';
    }

    if (preg_match('/\bddr5\b/', $raw)) {
        $tags['ddr'] = 'DDR5';
    } elseif (preg_match('/\bddr4\b/', $raw)) {
        $tags['ddr'] = 'DDR4';
    } elseif (preg_match('/\bddr3\b/', $raw)) {
        $tags['ddr'] = 'DDR3';
    }

    if (preg_match('/\bmini[\s\-]?itx\b|\bitx\b/', $raw)) {
        $tags['form'] = 'ITX';
    } elseif (preg_match('/\bmicro[\s\-]?atx\b|\bmatx\b|\bm\-atx\b/', $raw)) {
        $tags['form'] = 'MATX';
    } elseif (preg_match('/\beatx\b/', $raw)) {
        $tags['form'] = 'EATX';
    } elseif (preg_match('/\batx\b/', $raw)) {
        $tags['form'] = 'ATX';
    }

    /* Motherboard names carry the chipset (B550M, A520M-K, X870-P): an "M" suffix means micro-ATX. */
    $lname = strtolower($name);
    if (preg_match('/\bmotherboard\b/', $lname) && preg_match('/\b[abhxz]\d{3}(m?)/', $lname, $cm)) {
        if ($tags['form'] === null) {
            $tags['form'] = $cm[1] === 'm' ? 'MATX' : 'ATX';
        }
        if ($tags['socket'] === null) {
            if (preg_match('/\b(a320|b350|x370|b450|x470|a520|b550|x570)/', $lname)) {
                $tags['socket'] = 'AM4';
            } elseif (preg_match('/\b(a620|b650|x670|b840|b850|x870)/', $lname)) {
                $tags['socket'] = 'AM5';
            } elseif (preg_match('/\b(h610|b660|h670|z690|b760|z790)/', $lname)) {
                $tags['socket'] = 'LGA1700';
            } elseif (preg_match('/\b(h810|b860|z890)/', $lname)) {
                $tags['socket'] = 'LGA1851';
            }
        }
    }

    if (preg_match('/\bnvme\b|\bm\.?2\b/', $raw)) {
        $tags['storage'] = 'NVME';
    } elseif (preg_match('/\bsata\b/', $raw)) {
        $tags['storage'] = 'SATA';
    }

    if (preg_match('/\b(\d{3,4})\s*w\b/', $raw, $m)) {
        $w = (int)$m[1];
        if ($w >= 200 && $w <= 2000) {
            $tags['watts'] = $w;
        }
    }

    return $tags;
}

/**
 * RAM generation a processor requires, when its socket supports only one
 * (AM4 = DDR4, AM5 / LGA1851 = DDR5; LGA1700 boards exist for both).
 * Asks the same knowledge-base rules the compatibility check uses.
 */
function ep_pc_cpu_ddr(array $cpuTags): ?string
{
    $facts = ['slot' => 'memory', 'cpu.socket' => $cpuTags['socket'] ?? null];
    return pc_infer(pc_infer_rules('compat', 'memory'), $facts, 'cpu.ddr_needed')['facts']['cpu.ddr_needed'] ?? null;
}

/**
 * Facts for the 'compat' rules (pc_rules.php): the candidate's tags (cand.*), the tags of the
 * parts already picked (cpu.*, board.*, ram.*, case.*, psu.*) and which parts are in the build.
 *
 * @param array<string,array> $buildMap slot => ['name'=>,'category'=>,'compat'=>tags]
 * @return array<string,mixed>
 */
function ep_pc_compat_facts(string $slot, array $candidateTags, array $buildMap): array
{
    $rank = ['ITX' => 1, 'MATX' => 2, 'ATX' => 3, 'EATX' => 4];
    $parts = [
        'cand' => $candidateTags,
        'cpu' => $buildMap['processor']['compat'] ?? null,
        'board' => $buildMap['motherboard']['compat'] ?? null,
        'ram' => $buildMap['memory']['compat'] ?? null,
        'case' => $buildMap['case']['compat'] ?? null,
        'psu' => $buildMap['psu']['compat'] ?? null,
    ];
    $facts = ['slot' => $slot];
    foreach ($parts as $prefix => $tags) {
        foreach (['socket', 'ddr', 'form', 'storage', 'watts'] as $key) {
            $facts[$prefix . '.' . $key] = $tags[$key] ?? null;
        }
        $facts[$prefix . '.form_rank'] = $rank[$tags['form'] ?? ''] ?? null;
    }
    $raw = (string)($candidateTags['raw'] ?? '');
    $facts['cand.says_nvme'] = str_contains($raw, 'nvme') || str_contains($raw, 'm.2');
    foreach (['processor', 'motherboard', 'gpu', 'memory'] as $s) {
        $facts['has.' . $s] = !empty($buildMap[$s]);
    }
    $facts['has.storage'] = !empty($buildMap['ssd']) || !empty($buildMap['ssd_sata']) || !empty($buildMap['hdd']);
    return $facts;
}

/**
 * Compare a candidate product for $slot against the current build selections.
 * Returns null if compatible / unknown, or a short reason string if incompatible.
 * The rules are the 'compat' set in pc_rules.php, run by the inference engine (pc_inference.php).
 *
 * @param array<string,array> $buildMap slot => ['name'=>,'category'=>,'compat'=>tags]
 */
function ep_pc_compat_reason_for_candidate(string $slot, array $candidateTags, array $buildMap): ?string
{
    $result = pc_infer(pc_infer_rules('compat', $slot), ep_pc_compat_facts($slot, $candidateTags, $buildMap), 'incompatible');
    return $result['facts']['incompatible'] ?? null;
}

/**
 * Validate a full saved-build style components map.
 *
 * @param array<string,array> $components
 * @return array{ok:bool,issues:string[]}
 */
function ep_pc_compat_validate_build(array $components): array
{
    $buildMap = [];
    foreach ($components as $slot => $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = (string)($item['name'] ?? '');
        $cat = (string)($item['category'] ?? '');
        $desc = (string)($item['description'] ?? '');
        $buildMap[(string)$slot] = [
            'name' => $name,
            'category' => $cat,
            'compat' => ep_pc_compat_tags($name, $desc, $cat),
        ];
    }

    $issues = [];
    foreach ($buildMap as $slot => $entry) {
        $others = $buildMap;
        unset($others[$slot]);
        $reason = ep_pc_compat_reason_for_candidate($slot, $entry['compat'], $others);
        if ($reason) {
            $label = $entry['name'] !== '' ? $entry['name'] : $slot;
            $issues[] = $label . ': ' . $reason;
        }
    }

    return ['ok' => empty($issues), 'issues' => array_values(array_unique($issues))];
}
