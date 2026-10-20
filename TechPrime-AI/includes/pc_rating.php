<?php
/**
 * Build a PC — Build Score and Performance Balance.
 * Rates the picked parts against what the chosen purpose (pc_purpose.php) actually needs,
 * using the specs read from product names (pc_specs.php): CPU / GPU performance tier,
 * RAM size and channels, storage size and type, PSU wattage against the estimated draw.
 * A part far above what the purpose needs is not a better fit, a CPU / GPU pair that holds
 * each other back costs points, and a part that is not picked scores 0 on its axis.
 * Thresholds follow the expert rules in pc_recommend_slot_fit().
 */

require_once __DIR__ . '/pc_recommend.php';

/**
 * Per purpose: [good, ideal] for each axis — CPU / GPU tier on the 1-10 scale of pc_specs.php,
 * RAM and storage in GB (gpu null = built-in graphics is enough) — axis weights (sum 100),
 * and the CPU / GPU tier above which a part is more than the purpose needs (0 = never).
 *
 * @return array<string,array<string,mixed>>
 */
function pc_rating_profiles(): array
{
    return [
        'balanced' => [
            'cpu' => [4, 6], 'gpu' => [3, 5], 'ram' => [16, 32], 'storage' => [500, 1000],
            'weights' => ['cpu' => 25, 'gpu' => 25, 'ram' => 20, 'storage' => 15, 'psu' => 15],
            'cpu_max' => 0, 'gpu_max' => 0,
        ],
        'school' => [
            'cpu' => [2, 3], 'gpu' => null, 'ram' => [8, 16], 'storage' => [256, 500],
            'weights' => ['cpu' => 30, 'gpu' => 10, 'ram' => 25, 'storage' => 20, 'psu' => 15],
            'cpu_max' => 6, 'gpu_max' => 4,
        ],
        'office' => [
            'cpu' => [4, 5], 'gpu' => null, 'ram' => [16, 16], 'storage' => [500, 500],
            'weights' => ['cpu' => 30, 'gpu' => 10, 'ram' => 25, 'storage' => 20, 'psu' => 15],
            'cpu_max' => 8, 'gpu_max' => 4,
        ],
        'gaming' => [
            'cpu' => [5, 7], 'gpu' => [5, 7], 'ram' => [16, 32], 'storage' => [500, 1000],
            'weights' => ['cpu' => 22, 'gpu' => 38, 'ram' => 15, 'storage' => 10, 'psu' => 15],
            'cpu_max' => 0, 'gpu_max' => 0,
        ],
        'creation' => [
            'cpu' => [6, 8], 'gpu' => [5, 7], 'ram' => [16, 32], 'storage' => [1000, 2000],
            'weights' => ['cpu' => 32, 'gpu' => 23, 'ram' => 20, 'storage' => 12, 'psu' => 13],
            'cpu_max' => 0, 'gpu_max' => 0,
        ],
    ];
}

/**
 * How well a value meets a [good, ideal] need, 0-1: 1 at ideal or above, 0.85 at good,
 * in between linearly. Below good it drops with (value / good) ^ $curve: 1 for sizes
 * (half the RAM = 0.43), 1.5 for CPU / GPU tiers, where each tier short costs more.
 */
function pc_rating_fit(float $value, array $need, float $curve = 1.0): float
{
    [$good, $ideal] = $need;
    if ($value >= $ideal) {
        return 1.0;
    }
    if ($value >= $good) {
        return $ideal > $good ? 0.85 + 0.15 * ($value - $good) / ($ideal - $good) : 1.0;
    }
    return $good > 0 ? max(0.0, 0.85 * (max(0.0, $value) / $good) ** $curve) : 0.0;
}

/*
 * Absolute strength scale for the Performance Balance radar, 0-1 (1 = top of the range we sell):
 * CPU / GPU tier / 10; RAM on a log scale up to 64 GB (8 GB = 0.5, 16 GB = 0.67, 32 GB = 0.83);
 * storage on a log scale from 128 GB to 4 TB (256 GB = 0.2, 1 TB = 0.6, 2 TB = 0.8);
 * PSU watts / 1000. Built-in graphics: 0.1 (display only), 0.15 for Ryzen G / GT APUs.
 */
function pc_rating_ram_scale(float $gb): float
{
    return $gb > 0 ? max(0.0, min(1.0, log($gb, 2) / 6)) : 0.0;
}

function pc_rating_storage_scale(float $gb): float
{
    return $gb > 0 ? max(0.05, min(1.0, log($gb / 128, 2) / 5)) : 0.0;
}

/**
 * What the purpose needs on the same scale as the strength values (the "good" level of each
 * profile; the PSU target is the wattage recommended for the picked parts, at least 450 W).
 *
 * @return array<string,float> cpu, ram, gpu, storage, psu
 */
function pc_rating_targets(string $purpose, array $buildMap = []): array
{
    $prof = pc_rating_profiles()[pc_purpose_key($purpose)];
    return [
        'cpu' => $prof['cpu'][0] / 10,
        'ram' => round(pc_rating_ram_scale((float)$prof['ram'][0]), 3),
        'gpu' => $prof['gpu'] === null ? 0.1 : $prof['gpu'][0] / 10,
        'storage' => round(pc_rating_storage_scale((float)$prof['storage'][0]), 3),
        'psu' => min(1.0, pc_recommend_psu_need($buildMap) / 1000),
    ];
}

/** @return array<string,array<string,float>> purpose => targets for an empty build (embedded in build_a_pc.php) */
function pc_rating_all_targets(): array
{
    $out = [];
    foreach (array_keys(pc_purposes()) as $key) {
        $out[$key] = pc_rating_targets($key);
    }
    return $out;
}

/**
 * Absolute strength of the picked parts (0 = not picked), whatever the purpose.
 *
 * @return array<string,float> cpu, ram, gpu, storage, psu
 */
function pc_rating_strength(array $buildMap): array
{
    $cpu = !empty($buildMap['processor']) ? pc_recommend_spec('processor', $buildMap['processor']) : null;
    $out = ['cpu' => $cpu ? $cpu['tier'] / 10 : 0.0, 'ram' => 0.0, 'gpu' => 0.0, 'storage' => 0.0, 'psu' => 0.0];
    if (!empty($buildMap['gpu'])) {
        $out['gpu'] = pc_recommend_spec('gpu', $buildMap['gpu'])['tier'] / 10;
    } elseif ($cpu && $cpu['igpu']) {
        $out['gpu'] = preg_match('/\d{4}gt?$/i', $cpu['label']) ? 0.15 : 0.1;
    }
    if (!empty($buildMap['memory'])) {
        $gb = pc_recommend_spec('memory', $buildMap['memory'])['gb'];
        $out['ram'] = $gb > 0 ? pc_rating_ram_scale((float)$gb) : 0.5;
    }
    $drives = array_filter(['ssd', 'ssd_sata', 'hdd'], static fn(string $s): bool => !empty($buildMap[$s]));
    if ($drives) {
        $gb = 0;
        foreach ($drives as $s) {
            $gb += pc_recommend_spec($s, $buildMap[$s])['gb'];
        }
        $speed = in_array('ssd', $drives, true) ? 1.0 : (in_array('ssd_sata', $drives, true) ? 0.92 : 0.6);
        $out['storage'] = ($gb > 0 ? pc_rating_storage_scale((float)$gb) : 0.3) * $speed;
    }
    if (!empty($buildMap['psu'])) {
        $w = pc_recommend_spec('psu', $buildMap['psu'])['watts'];
        $out['psu'] = $w > 0 ? min(1.0, $w / 1000) : 0.4;
    } elseif (!empty($buildMap['case']) && pc_recommend_spec('case', $buildMap['case'])['bundle']) {
        $out['psu'] = 0.35;
    }
    return array_map(static fn(float $v): float => round($v, 3), $out);
}

/**
 * @param array<string,array<string,mixed>> $buildMap from pc_recommend_build_map()
 * @return array{score:int,verdict:string,complete:bool,notes:array<int,string>,axes:array<int,array{key:string,label:string,value:float,power:float,target:float,text:string}>}
 */
function pc_build_rating(array $buildMap, string $purpose): array
{
    $purpose = pc_purpose_key($purpose);
    $prof = pc_rating_profiles()[$purpose];
    $use = pc_purposes()[$purpose]['use'];
    $notes = [];
    $penalty = 0;
    $cap = 100;

    $cpu = !empty($buildMap['processor']) ? pc_recommend_spec('processor', $buildMap['processor']) : null;
    $gpu = !empty($buildMap['gpu']) ? pc_recommend_spec('gpu', $buildMap['gpu']) : null;

    /* CPU: tier against the need; a cooler that is too small or an entry board under a fast CPU holds it back. */
    $axCpu = ['v' => 0.0, 'text' => 'No processor picked yet'];
    if ($cpu) {
        $v = pc_rating_fit((float)$cpu['tier'], $prof['cpu'], 1.5);
        $text = $cpu['label'] . ($v >= 0.85 ? ' meets the need for ' : ' is below what is needed for ') . $use;
        if (!empty($buildMap['cooler'])) {
            $cooler = pc_recommend_spec('cooler', $buildMap['cooler']);
            if ($cooler['capacity'] < $cpu['watts'] * 1.1) {
                $v *= 0.85;
                $text = 'The ' . $cooler['type'] . ' is too small for the ' . $cpu['label'] . ', so it may slow down under load';
            }
        }
        if (!empty($buildMap['motherboard']) && $cpu['tier'] >= 7 && pc_recommend_spec('motherboard', $buildMap['motherboard'])['level'] === 1) {
            $v *= 0.93;
            $text = 'An entry-level board can hold back the ' . $cpu['label'];
        }
        $axCpu = ['v' => $v, 'text' => $text];
    }

    /* GPU: for school / office built-in graphics is enough; otherwise tier (and VRAM for creation). */
    $axGpu = ['v' => 0.0, 'text' => 'No graphics card picked yet'];
    if ($prof['gpu'] === null) {
        if ($gpu) {
            $axGpu = ['v' => 1.0, 'text' => $gpu['label'] . ' is more than enough for ' . $use];
        } elseif ($cpu && $cpu['igpu']) {
            $axGpu = ['v' => 1.0, 'text' => 'Built-in graphics of the ' . $cpu['label'] . ' is enough for ' . $use];
        } elseif ($cpu) {
            $axGpu = ['v' => 0.0, 'text' => 'The ' . $cpu['label'] . ' has no built-in graphics: add a graphics card'];
        } else {
            $axGpu = ['v' => 0.0, 'text' => 'Pick a processor with built-in graphics, or a graphics card'];
        }
    } elseif ($gpu) {
        $v = pc_rating_fit((float)$gpu['tier'], $prof['gpu'], 1.5);
        $text = $gpu['label'] . ' handles ' . $gpu['res'];
        if ($purpose === 'creation' && $gpu['vram'] > 0 && $gpu['vram'] < 12) {
            $v *= $gpu['vram'] >= 8 ? 0.92 : 0.8;
            $text = $gpu['label'] . ' has ' . $gpu['vram'] . ' GB of video memory; 12 GB or more suits editing better';
        } elseif ($purpose === 'gaming' && $gpu['vram'] > 0 && $gpu['vram'] < 8) {
            $v *= 0.9;
            $text = $gpu['label'] . ' has only ' . $gpu['vram'] . ' GB of video memory for newer games';
        }
        $axGpu = ['v' => $v, 'text' => $text];
    } elseif ($cpu && $cpu['igpu']) {
        /* Ryzen G / GT APUs have usable graphics; other built-in graphics only drive a display. */
        $apu = (bool)preg_match('/\d{4}gt?$/i', $cpu['label']);
        $axGpu = [
            'v' => pc_rating_fit($apu ? 1.5 : 0.5, $prof['gpu'], 1.5),
            'text' => 'Built-in graphics only: fine for light use, too weak for ' . $use,
        ];
    }

    /* RAM: size against the need; one stick runs single-channel (slower). */
    $axRam = ['v' => 0.0, 'text' => 'No memory picked yet'];
    if (!empty($buildMap['memory'])) {
        $mem = pc_recommend_spec('memory', $buildMap['memory']);
        if ($mem['gb'] <= 0) {
            $axRam = ['v' => 0.7, 'text' => 'Memory size could not be read from the product name'];
        } else {
            $v = pc_rating_fit((float)$mem['gb'], $prof['ram']);
            $text = $mem['gb'] . ' GB ' . ($v >= 0.85 ? 'is enough for ' : 'is tight for ') . $use;
            if ($mem['sticks'] < 2) {
                $v *= 0.93;
                $text .= '; one stick runs in slower single-channel mode';
            }
            $axRam = ['v' => $v, 'text' => $text];
        }
    }

    /* Storage: total size of all drives, scaled by the fastest drive type (NVMe > SATA SSD > hard disk). */
    $axSto = ['v' => 0.0, 'text' => 'No storage picked yet'];
    $drives = array_filter(['ssd', 'ssd_sata', 'hdd'], static fn(string $s): bool => !empty($buildMap[$s]));
    if ($drives) {
        $total = 0;
        foreach ($drives as $s) {
            $total += pc_recommend_spec($s, $buildMap[$s])['gb'];
        }
        $speed = in_array('ssd', $drives, true) ? 1.0 : (in_array('ssd_sata', $drives, true) ? 0.92 : 0.6);
        $v = $total > 0 ? pc_rating_fit((float)$total, $prof['storage']) : 0.7;
        $text = ($total > 0 ? pc_recommend_size_label($total) . ' total' : 'Drive size unknown')
            . ($speed < 1 ? ($speed < 0.9 ? ', on a hard disk only (slow to start up and load)' : ', on SATA (slower than NVMe)') : ' on fast NVMe');
        $axSto = ['v' => $v * $speed, 'text' => $text];
    }

    /* PSU: wattage against the estimated draw (30% headroom recommended) and efficiency rating. */
    $axPsu = ['v' => 0.0, 'text' => 'No power supply picked yet'];
    $draw = pc_recommend_power_estimate($buildMap);
    $need = pc_recommend_psu_need($buildMap);
    $psu = !empty($buildMap['psu']) ? pc_recommend_spec('psu', $buildMap['psu']) : null;
    if (!$psu && !empty($buildMap['case']) && pc_recommend_spec('case', $buildMap['case'])['bundle']) {
        $axPsu = ['v' => 0.5, 'text' => 'Uses the power supply bundled with the case (wattage and rating unknown)'];
    } elseif ($psu) {
        if ($psu['watts'] <= 0) {
            $v = 0.6;
            $text = 'Wattage could not be read from the product name';
        } elseif ($psu['watts'] < $draw) {
            $v = 0.15;
            $text = $psu['watts'] . ' W is below the estimated ' . $draw . ' W draw';
            $notes[] = 'The power supply is too weak for these parts (about ' . $draw . ' W needed, ' . $need . ' W recommended).';
        } elseif ($psu['watts'] < $need) {
            $v = 0.6 + 0.25 * ($psu['watts'] - $draw) / max(1, $need - $draw);
            $text = $psu['watts'] . ' W runs close to the limit; ' . $need . ' W is recommended';
        } else {
            $v = 1.0;
            $text = $psu['watts'] . ' W leaves enough headroom (about ' . $draw . ' W draw)';
        }
        $gpuHigh = $gpu && $gpu['tier'] >= 8;
        $rate = [0 => 0.8, 1 => 0.9, 2 => $gpuHigh ? 0.92 : 1.0, 3 => 1.0, 4 => 1.0][$psu['rating']];
        if ($gpuHigh && $psu['atx3']) {
            $rate = 1.0;
        }
        if ($rate < 1.0) {
            $text .= '; ' . ($psu['rating'] < 2 ? $psu['rating_label'] . ' (Bronze or better is safer)' : 'Gold or ATX 3.1 suits a high-end card');
        }
        $axPsu = ['v' => $v * $rate, 'text' => $text];
    }

    $axes = [
        'cpu' => ['label' => 'CPU'] + $axCpu,
        'ram' => ['label' => 'RAM'] + $axRam,
        'gpu' => ['label' => 'GPU'] + $axGpu,
        'storage' => ['label' => 'Storage'] + $axSto,
        'psu' => ['label' => 'PSU'] + $axPsu,
    ];
    $raw = 0.0;
    foreach ($prof['weights'] as $key => $w) {
        $raw += $w * $axes[$key]['v'];
    }

    /* CPU / GPU balance: within 2 tiers is fine, each tier beyond costs 6 points; editing leans on the CPU. */
    if ($cpu && $gpu && $prof['gpu'] !== null) {
        $gap = $cpu['tier'] - $gpu['tier'];
        if ($gap < -2) {
            $penalty += min(30, (-$gap - 2) * 6);
            $notes[] = 'Bottleneck: the ' . $cpu['label'] . ' holds back the ' . $gpu['label'] . '.';
        } elseif ($gap > 2 && $purpose !== 'creation') {
            $penalty += min(30, ($gap - 2) * 6);
            $notes[] = 'Bottleneck: the ' . $gpu['label'] . ' limits what the ' . $cpu['label'] . ' can do in games.';
        }
    }

    /* More than the purpose needs: still works, but the money is better spent elsewhere. */
    $over = 0;
    if ($cpu && $prof['cpu_max'] > 0 && $cpu['tier'] > $prof['cpu_max']) {
        $over += $cpu['tier'] - $prof['cpu_max'];
    }
    if ($gpu && $prof['gpu_max'] > 0 && $gpu['tier'] > $prof['gpu_max']) {
        $over += $gpu['tier'] - $prof['gpu_max'];
    }
    if ($over > 0) {
        $penalty += min(20, $over * 3);
        $notes[] = 'More performance than ' . $use . ' needs: a cheaper processor or graphics card would do the same job.';
    }

    if ($cpu && !$cpu['igpu'] && !$gpu) {
        $cap = min($cap, 40);
        $notes[] = 'No display output: the ' . $cpu['label'] . ' needs a graphics card.';
    }

    $issues = 0;
    foreach ($buildMap as $slot => $part) {
        $others = $buildMap;
        unset($others[$slot]);
        if (ep_pc_compat_reason_for_candidate($slot, $part['compat'] ?? [], $others) !== null) {
            $issues++;
        }
    }
    if ($issues > 0) {
        $cap = min($cap, 40);
        $notes[] = 'Some parts are not compatible with each other, so this build will not work as it is.';
    }

    $score = $buildMap ? (int)max(0, min($cap, round($raw - $penalty))) : 0;

    /* The weakest picked axis, when it falls short and nothing above already explains it. */
    $weak = null;
    foreach ($axes as $key => $a) {
        if ($a['v'] > 0 && $a['v'] < 0.8 && ($weak === null || $a['v'] < $axes[$weak]['v'])) {
            $weak = $key;
        }
    }
    if ($weak !== null && count($notes) < 3) {
        $notes[] = $axes[$weak]['text'] . '.';
    }

    /* Required parts still missing (a case with a bundled power supply covers the PSU). */
    $complete = !empty($buildMap['processor']) && !empty($buildMap['motherboard']) && !empty($buildMap['memory'])
        && $drives && !empty($buildMap['case']) && ($psu || $axPsu['v'] > 0)
        && ($gpu || !pc_recommend_gpu_needed($buildMap, $purpose));
    $verdict = '';
    if ($buildMap) {
        $verdict = ($score >= 85 ? 'Excellent' : ($score >= 70 ? 'Good' : ($score >= 50 ? 'Okay' : 'Not ready'))) . ' for ' . $use
            . ($complete ? '' : ' so far');
    }

    /* value = fit for the purpose (drives the score); power / target = radar fill / dashed outline. */
    $power = pc_rating_strength($buildMap);
    $target = pc_rating_targets($purpose, $buildMap);
    $out = [];
    foreach ($axes as $key => $a) {
        $out[] = [
            'key' => $key,
            'label' => $a['label'],
            'value' => round($a['v'], 3),
            'power' => $power[$key],
            'target' => $target[$key],
            'text' => $a['text'],
        ];
    }
    return ['score' => $score, 'verdict' => $verdict, 'complete' => $complete, 'notes' => array_slice($notes, 0, 3), 'axes' => $out];
}
