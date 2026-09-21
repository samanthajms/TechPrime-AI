<?php
/**
 * PC build compatibility helpers.
 * Derives socket / RAM / form-factor / wattage signals ONLY from existing
 * product name + description text (no invented DB columns).
 */

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
 * Compare a candidate product for $slot against the current build selections.
 * Returns null if compatible / unknown, or a short reason string if incompatible.
 *
 * @param array<string,array> $buildMap slot => ['name'=>,'category'=>,'compat'=>tags]
 */
function ep_pc_compat_reason_for_candidate(string $slot, array $candidateTags, array $buildMap): ?string
{
    $cpu = $buildMap['processor']['compat'] ?? null;
    $mobo = $buildMap['motherboard']['compat'] ?? null;
    $ram = $buildMap['memory']['compat'] ?? null;
    $case = $buildMap['case']['compat'] ?? null;
    $psu = $buildMap['psu']['compat'] ?? null;
    $gpu = $buildMap['gpu']['compat'] ?? null;

    if ($slot === 'processor' && $mobo && !empty($mobo['socket']) && !empty($candidateTags['socket'])) {
        if ($mobo['socket'] !== $candidateTags['socket']) {
            return 'Not compatible with the selected motherboard — CPU socket mismatch.';
        }
    }
    if ($slot === 'motherboard' && $cpu && !empty($cpu['socket']) && !empty($candidateTags['socket'])) {
        if ($cpu['socket'] !== $candidateTags['socket']) {
            return 'Not compatible with the selected processor — CPU socket mismatch.';
        }
    }
    if ($slot === 'motherboard' && $ram && !empty($ram['ddr']) && !empty($candidateTags['ddr'])) {
        if ($ram['ddr'] !== $candidateTags['ddr']) {
            return 'Not compatible with the selected memory — RAM type mismatch.';
        }
    }
    if ($slot === 'memory' && $mobo && !empty($mobo['ddr']) && !empty($candidateTags['ddr'])) {
        if ($mobo['ddr'] !== $candidateTags['ddr']) {
            return 'Not compatible with the selected motherboard — RAM type mismatch.';
        }
    }
    if ($slot === 'cooler') {
        $refSocket = $cpu['socket'] ?? ($mobo['socket'] ?? null);
        if ($refSocket && !empty($candidateTags['socket']) && $candidateTags['socket'] !== $refSocket) {
            return 'Not compatible with the selected CPU/motherboard — cooler socket mismatch.';
        }
    }
    if ($slot === 'case' && $mobo && !empty($mobo['form']) && !empty($candidateTags['form'])) {
        $rank = ['ITX' => 1, 'MATX' => 2, 'ATX' => 3, 'EATX' => 4];
        $caseRank = $rank[$candidateTags['form']] ?? null;
        $moboRank = $rank[$mobo['form']] ?? null;
        if ($caseRank !== null && $moboRank !== null && $caseRank < $moboRank) {
            return 'Not compatible with the selected motherboard — case form factor too small.';
        }
    }
    if ($slot === 'motherboard' && $case && !empty($case['form']) && !empty($candidateTags['form'])) {
        $rank = ['ITX' => 1, 'MATX' => 2, 'ATX' => 3, 'EATX' => 4];
        $caseRank = $rank[$case['form']] ?? null;
        $moboRank = $rank[$candidateTags['form']] ?? null;
        if ($caseRank !== null && $moboRank !== null && $caseRank < $moboRank) {
            return 'Not compatible with the selected case — motherboard form factor too large.';
        }
    }
    if ($slot === 'ssd' && !empty($candidateTags['storage']) && $candidateTags['storage'] === 'SATA') {
        /* Prefer NVMe for the NVMe SSD slot when text clearly says SATA-only. */
        if (strpos($candidateTags['raw'], 'nvme') === false && strpos($candidateTags['raw'], 'm.2') === false) {
            return 'Not compatible with this SSD slot — SATA drive should use SSD (SATA).';
        }
    }
    if ($slot === 'psu') {
        $estimate = 0;
        if (!empty($buildMap['processor'])) $estimate += 65;
        if (!empty($buildMap['motherboard'])) $estimate += 40;
        if (!empty($buildMap['gpu'])) $estimate += 180;
        if (!empty($buildMap['memory'])) $estimate += 10;
        if (!empty($buildMap['ssd']) || !empty($buildMap['ssd_sata']) || !empty($buildMap['hdd'])) $estimate += 15;
        if (!empty($candidateTags['watts']) && $candidateTags['watts'] < $estimate) {
            return 'Not compatible with the current build — PSU wattage too low for estimated power draw.';
        }
    }
    if ($slot === 'gpu' && $psu && !empty($psu['watts'])) {
        $estimate = 180 + (!empty($cpu) ? 65 : 0) + (!empty($mobo) ? 40 : 0) + 25;
        if ($psu['watts'] < $estimate) {
            return 'Not compatible with the selected power supply — estimated GPU build draw exceeds PSU wattage.';
        }
    }

    return null;
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
