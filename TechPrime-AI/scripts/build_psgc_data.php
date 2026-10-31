<?php
/**
 * Builds the Philippine address dropdown data (Province -> City/Municipality -> Barangay)
 * used by assets/js/ph-address.js and includes/address_helpers.php.
 *
 * Source: PSGC data from https://psgc.gitlab.io/api/ (provinces.json,
 * cities-municipalities.json, barangays.json). Download those three files into
 * one folder, then run:
 *   php scripts/build_psgc_data.php <folder-with-psgc-json>
 *
 * Output (overwritten):
 *   assets/data/psgc/locations.json            provinces with their cities
 *   assets/data/psgc/barangays/<province>.json  { cityCode: [barangay names] }
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$src = rtrim($argv[1] ?? '', '/\\');
if ($src === '' || !is_file("$src/provinces.json")) {
    fwrite(STDERR, "Usage: php scripts/build_psgc_data.php <folder with provinces.json, cities-municipalities.json, barangays.json>\n");
    exit(1);
}

$load = function (string $file) use ($src): array {
    $data = json_decode((string)file_get_contents("$src/$file"), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Could not parse $file\n");
        exit(1);
    }
    return $data;
};
$provinces = $load('provinces.json');
$cities = $load('cities-municipalities.json');
$barangays = $load('barangays.json');

const METRO_MANILA = '130000000';   // NCR region code, used as a pseudo-province
// Independent cities without a province in PSGC, placed in their geographic province.
$provinceOverride = [
    'City of Isabela'  => 'Basilan',
    'City of Cotabato' => 'Maguindanao',
];

$provByName = [];
$out = [METRO_MANILA => ['n' => 'Metro Manila', 'c' => METRO_MANILA, 'cities' => []]];
foreach ($provinces as $p) {
    $out[$p['code']] = ['n' => $p['name'], 'c' => $p['code'], 'cities' => []];
    $provByName[$p['name']] = $p['code'];
}

$cityProvince = [];
foreach ($cities as $c) {
    $pc = $c['provinceCode'] ?: null;
    if (!$pc && $c['regionCode'] === METRO_MANILA) {
        $pc = METRO_MANILA;
    }
    if (!$pc && isset($provinceOverride[$c['name']], $provByName[$provinceOverride[$c['name']]])) {
        $pc = $provByName[$provinceOverride[$c['name']]];
    }
    if (!$pc || !isset($out[$pc])) {
        fwrite(STDERR, "Skipping city without province: {$c['name']}\n");
        continue;
    }
    $out[$pc]['cities'][] = [$c['name'], $c['code']];
    $cityProvince[$c['code']] = $pc;
}

// Sort cities ignoring the "City of " prefix so "City of Pasig" sits under P.
$cityKey = fn(string $n): string => mb_strtolower(preg_replace('/^City of\s+/i', '', $n));
foreach ($out as &$p) {
    usort($p['cities'], fn($a, $b) => strcmp($cityKey($a[0]), $cityKey($b[0])));
}
unset($p);

// Metro Manila first (store's home region), then alphabetical.
$list = array_values($out);
usort($list, function ($a, $b) {
    if ($a['c'] === METRO_MANILA) return -1;
    if ($b['c'] === METRO_MANILA) return 1;
    return strcmp($a['n'], $b['n']);
});

$byProvince = [];
foreach ($barangays as $b) {
    $cc = $b['cityCode'] ?: $b['municipalityCode'];
    if (!$cc || !isset($cityProvince[$cc])) {
        continue;
    }
    $byProvince[$cityProvince[$cc]][$cc][] = $b['name'];
}

$dest = dirname(__DIR__) . '/assets/data/psgc';
if (!is_dir("$dest/barangays") && !mkdir("$dest/barangays", 0775, true)) {
    fwrite(STDERR, "Could not create $dest/barangays\n");
    exit(1);
}
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
file_put_contents("$dest/locations.json", json_encode(['provinces' => $list], $flags));

$brgyCount = 0;
foreach ($byProvince as $pc => $citiesMap) {
    foreach ($citiesMap as &$names) {
        $names = array_values(array_unique($names));
        natcasesort($names);
        $names = array_values($names);
        $brgyCount += count($names);
    }
    unset($names);
    file_put_contents("$dest/barangays/$pc.json", json_encode($citiesMap, $flags));
}

printf("Wrote %d provinces, %d cities, %d barangays to %s\n",
    count($list), count($cityProvince), $brgyCount, $dest);
