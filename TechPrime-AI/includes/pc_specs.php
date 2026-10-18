<?php
/**
 * Build a PC part specs, read from product names (the catalog has no spec columns).
 * Used by pc_recommend.php to judge how well a compatible part matches a build.
 *
 * CPU / GPU performance tiers are a hand-made 1-10 gaming scale (10 = fastest),
 * approximated from public benchmark rankings (TechPowerUp relative GPU performance,
 * PassMark / review CPU gaming results). Power figures are manufacturer TDP / board
 * power. Review this table when the store adds a CPU or GPU model; models that are
 * not listed get an estimate from the name pattern (pc_spec_cpu_guess / pc_spec_gpu_guess).
 */

/** @return array<string,array{0:string,1:int,2:int,3:bool}> regex => [label, tier, watts, built-in graphics] */
function pc_spec_cpu_table(): array
{
    return [
        '/ryzen\s*3\s*3200g/' => ['Ryzen 3 3200G', 2, 65, true],
        '/ryzen\s*5\s*5500gt/' => ['Ryzen 5 5500GT', 4, 65, true],
        '/ryzen\s*5\s*5500\b/' => ['Ryzen 5 5500', 4, 65, false],
        '/ryzen\s*5\s*5600x\b/' => ['Ryzen 5 5600X', 5, 65, false],
        '/ryzen\s*5\s*5600\b/' => ['Ryzen 5 5600', 5, 65, false],
        '/ryzen\s*5\s*8400f/' => ['Ryzen 5 8400F', 5, 65, false],
        '/ryzen\s*7\s*5700g/' => ['Ryzen 7 5700G', 5, 65, true],
        '/ryzen\s*7\s*5700x\b/' => ['Ryzen 7 5700X', 6, 65, false],
        '/ryzen\s*7\s*7700\b/' => ['Ryzen 7 7700', 7, 65, true],
        '/ryzen\s*7\s*7800x3d/' => ['Ryzen 7 7800X3D', 9, 120, true],
        '/ryzen\s*7\s*9800x3d/' => ['Ryzen 7 9800X3D', 10, 120, true],
        '/ryzen\s*9\s*9900x\b/' => ['Ryzen 9 9900X', 8, 120, true],
        '/i5[\s\-]*14400\b/' => ['Core i5-14400', 6, 148, true],
        '/i9[\s\-]*14900k/' => ['Core i9-14900K', 9, 253, true],
        '/ultra\s*7\D*270k/' => ['Core Ultra 7 270K Plus', 8, 250, true],
    ];
}

/** @return array<string,array{0:string,1:int,2:int}> regex => [label, tier, board power watts] */
function pc_spec_gpu_table(): array
{
    return [
        '/rtx\s*3050/' => ['RTX 3050', 3, 70],
        '/rx\s*7600\b/' => ['RX 7600', 5, 165],
        '/rtx\s*5060\s*ti/' => ['RTX 5060 Ti', 6, 180],
        '/rtx\s*5060/' => ['RTX 5060', 6, 145],
        '/rx\s*9060\s*xt/' => ['RX 9060 XT', 7, 160],
        '/rtx\s*5070\s*ti/' => ['RTX 5070 Ti', 9, 300],
        '/rtx\s*5070/' => ['RTX 5070', 8, 250],
        '/rx\s*9070\s*xt/' => ['RX 9070 XT', 9, 304],
        '/rx\s*9070\b/' => ['RX 9070', 8, 220],
    ];
}

/** @return array{label:string,tier:int,watts:int,igpu:bool,cooler:bool,listed:bool} */
function pc_spec_cpu(string $name): array
{
    $n = strtolower($name);
    $cooler = (bool)preg_match('/\bwith\b.*\b(cooler|wraith)\b/', $n);
    foreach (pc_spec_cpu_table() as $re => [$label, $tier, $watts, $igpu]) {
        if (preg_match($re, $n)) {
            return ['label' => $label, 'tier' => $tier, 'watts' => $watts, 'igpu' => $igpu, 'cooler' => $cooler, 'listed' => true];
        }
    }
    return pc_spec_cpu_guess($n) + ['cooler' => $cooler, 'listed' => false];
}

/** Estimate for a CPU missing from the table, from the series in its name. */
function pc_spec_cpu_guess(string $n): array
{
    if (preg_match('/ryzen\s*([3579])\s*(\d)(\d{3})(x3d|gt|g|xt|x|f)?/', $n, $m)) {
        $tier = ['3' => 2, '5' => 4, '7' => 6, '9' => 8][$m[1]] + ((int)$m[2] >= 7 ? 1 : 0) + (($m[4] ?? '') === 'x3d' ? 2 : 0);
        $suffix = $m[4] ?? '';
        return [
            'label' => 'Ryzen ' . $m[1] . ' ' . $m[2] . $m[3] . strtoupper($suffix),
            'tier' => min(10, $tier),
            'watts' => ($m[1] === '9' || $suffix === 'x3d') ? 120 : 65,
            'igpu' => in_array($suffix, ['g', 'gt'], true) || (int)$m[2] >= 7 && $suffix !== 'f',
        ];
    }
    if (preg_match('/\bi([3579])[\s\-]*(\d{1,2})(\d{3})(kf|k|f)?/', $n, $m)) {
        $tier = ['3' => 3, '5' => 5, '7' => 7, '9' => 8][$m[1]] + ((int)$m[2] >= 13 ? 1 : 0);
        $suffix = $m[4] ?? '';
        return [
            'label' => 'Core i' . $m[1] . '-' . $m[2] . $m[3] . strtoupper($suffix),
            'tier' => min(10, $tier),
            'watts' => str_contains($suffix, 'k') ? 181 : 65,
            'igpu' => !str_contains($suffix, 'f'),
        ];
    }
    if (preg_match('/ultra\s*([579])/', $n, $m)) {
        return ['label' => 'Core Ultra ' . $m[1], 'tier' => ['5' => 6, '7' => 8, '9' => 9][$m[1]], 'watts' => 125, 'igpu' => true];
    }
    return ['label' => 'this processor', 'tier' => 5, 'watts' => 65, 'igpu' => false];
}

/** @return array{label:string,tier:int,watts:int,vram:int,res:string,listed:bool} */
function pc_spec_gpu(string $name): array
{
    $n = strtolower($name);
    $vram = preg_match('/\b(\d{1,2})\s*gb\b/', $n, $vm) ? (int)$vm[1] : 0;
    $spec = null;
    foreach (pc_spec_gpu_table() as $re => [$label, $tier, $watts]) {
        if (preg_match($re, $n)) {
            $spec = ['label' => $label, 'tier' => $tier, 'watts' => $watts, 'listed' => true];
            break;
        }
    }
    if ($spec === null) {
        $spec = pc_spec_gpu_guess($n) + ['listed' => false];
    }
    $tier = $spec['tier'];
    $res = $tier <= 3 ? '1080p (medium settings)' : ($tier <= 6 ? '1080p' : ($tier <= 8 ? '1440p' : '1440p and 4K'));
    return $spec + ['vram' => $vram, 'res' => $res];
}

/** Estimate for a GPU missing from the table, from its model class (xx50 / xx60 / x600 ...). */
function pc_spec_gpu_guess(string $n): array
{
    if (preg_match('/\b(rtx|gtx)\s*(\d{1,2})(\d)0\b/', $n, $m)) {
        $tier = [5 => 3, 6 => 5, 7 => 7, 8 => 9, 9 => 10][(int)$m[3]] ?? 4;
        $tier += ((int)$m[2] >= 40 && $tier < 9) ? 1 : 0;
        return ['label' => strtoupper($m[1]) . ' ' . $m[2] . $m[3] . '0', 'tier' => min(10, $tier), 'watts' => 60 + 25 * $tier];
    }
    if (preg_match('/\brx\s*(\d)(\d)(\d)0\b/', $n, $m)) {
        $tier = [5 => 3, 6 => 5, 7 => 7, 8 => 8, 9 => 9][(int)$m[2]] ?? 4;
        $tier += ((int)$m[1] >= 7 && $tier < 9) ? 1 : 0;
        return ['label' => 'RX ' . $m[1] . $m[2] . $m[3] . '0', 'tier' => min(10, $tier), 'watts' => 60 + 25 * $tier];
    }
    return ['label' => 'this graphics card', 'tier' => 4, 'watts' => 150];
}

/** Chipset class: 1 = entry (A520/H610), 2 = mid (B550/B650/B760), 3 = high-end (X870/Z790). */
function pc_spec_board(string $name): array
{
    $n = strtolower($name);
    $chipset = preg_match('/\b([abhxz])(\d{3})(?!\d)/', $n, $m) ? strtoupper($m[1] . $m[2]) : '';
    $level = 2;
    if ($chipset !== '') {
        $level = in_array($m[1], ['a', 'h'], true) ? 1 : (in_array($m[1], ['x', 'z'], true) ? 3 : 2);
    }
    return ['chipset' => $chipset, 'level' => $level, 'label' => ['', 'entry-level', 'mid-range', 'high-end'][$level]];
}

/** @return array{gb:int,sticks:int,mhz:int} */
function pc_spec_memory(string $name): array
{
    $n = strtolower($name);
    $gb = preg_match('/\b(\d{1,3})\s*gb\b/', $n, $m) ? (int)$m[1] : 0;
    $sticks = 1;
    if (preg_match('/\b(\d)\s*x\s*(\d{1,2})\b/', $n, $m) || preg_match('/\b\d{1,2}\s*gb\s*x\s*(\d)\b/', $n, $m)) {
        $sticks = (int)$m[1];
    }
    $mhz = preg_match('/\b(\d{4})\s*mhz\b/', $n, $m) ? (int)$m[1] : 0;
    return ['gb' => $gb, 'sticks' => max(1, $sticks), 'mhz' => $mhz];
}

/** Capacity in GB ("256B" in one listing is a typo for 256GB). */
function pc_spec_storage(string $name): array
{
    $n = strtolower($name);
    $gb = 0;
    if (preg_match('/\b(\d{1,2})\s*tb\b/', $n, $m)) {
        $gb = 1000 * (int)$m[1];
    } elseif (preg_match('/\b(\d{2,4})\s*g?b\b/', $n, $m)) {
        $gb = (int)$m[1];
    }
    $gen = preg_match('/\bgen\s*([345])/', $n, $m) ? (int)$m[1] : 0;
    return ['gb' => $gb, 'gen' => $gen];
}

/** @return array{watts:int,rating:int,rating_label:string,atx3:bool} rating 0 = none, 1 = 80+, 2 = Bronze, 3 = Gold, 4 = Platinum+ */
function pc_spec_psu(string $name): array
{
    $n = strtolower($name);
    $watts = preg_match('/\b(\d{3,4})\s*w(atts?)?\b/', $n, $m) ? (int)$m[1] : 0;
    $rating = 0;
    if (preg_match('/\b80\s*(\+|plus)/', $n)) {
        $rating = 1;
        if (preg_match('/\b(platinum|titanium)\b/', $n)) {
            $rating = 4;
        } elseif (preg_match('/\bgold\b/', $n)) {
            $rating = 3;
        } elseif (preg_match('/\b(bronze|silver)\b/', $n)) {
            $rating = 2;
        }
    }
    return [
        'watts' => $watts,
        'rating' => $rating,
        'rating_label' => ['no 80 Plus rating', '80 Plus', '80 Plus Bronze', '80 Plus Gold', '80 Plus Platinum'][$rating],
        'atx3' => (bool)preg_match('/\batx\s*3|\bpcie\s*5|12v-?2x6|12vhpwr/', $n),
    ];
}

/** Rough heat a cooler can handle, in watts. Air coolers under 700 pesos are basic low-profile models. */
function pc_spec_cooler(string $name, float $price): array
{
    $n = strtolower($name);
    if (preg_match('/liquid|\baio\b/', $n)) {
        $rad = preg_match('/\b(120|240|280|360|420)(?!\d)/', $n, $m) ? (int)$m[1] : 240;
        $cap = [120 => 150, 240 => 220, 280 => 240, 360 => 280, 420 => 300][$rad];
        return ['type' => $rad . ' mm liquid cooler', 'capacity' => $cap];
    }
    if ($price > 0 && $price < 700) {
        return ['type' => 'basic air cooler', 'capacity' => 95];
    }
    return ['type' => 'tower air cooler', 'capacity' => 160];
}

function pc_spec_case(string $name): array
{
    $n = strtolower($name);
    return [
        'bundle' => (bool)preg_match('/\bwith\s+(psu|power\s+supply)\b/', $n),
        'airflow' => (bool)preg_match('/\bflow\b|\bmesh\b|\bairflow\b/', $n),
    ];
}

function pc_spec_fan_count(string $name): int
{
    $n = strtolower($name);
    if (preg_match('/\b(\d)\s*in\s*1\b|\b(\d)\s*-?\s*pack\b/', $n, $m)) {
        return (int)($m[1] !== '' ? $m[1] : $m[2]);
    }
    return preg_match('/\btriple\b/', $n) ? 3 : (preg_match('/\bduo\b|\bdual\b/', $n) ? 2 : 1);
}
