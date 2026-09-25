<?php
/**
 * Client-only EasyPC shop taxonomy.
 * Does not change products.category in the database (inventory stays intact).
 */

function ep_shop_taxonomy(): array
{
    return [
        'component' => [
            'label' => 'Component',
            'icon' => 'fa-microchip',
            'subs' => [
                'chassis-fan' => 'Chassis Fan',
                'cpu-cooling' => 'CPU Cooling',
                'graphics-card' => 'Graphics Card',
                'hard-disk' => 'Hard Disk',
                'memory' => 'Memory',
                'motherboard' => 'Motherboard',
                'pc-case' => 'PC Case',
                'power-supply' => 'Power Supply',
                'processor-amd' => 'Processor AMD',
                'processor-intel' => 'Processor INTEL',
                'processor-tray' => 'Processor Tray',
                'ssd' => 'Solid State Drive',
            ],
        ],
        'peripherals' => [
            'label' => 'Peripherals',
            'icon' => 'fa-keyboard',
            'subs' => [
                'cctv' => 'CCTV',
                'headset' => 'Headset',
                'keyboard' => 'Keyboard',
                'keyboard-mouse' => 'Keyboard and Mouse',
                'monitor' => 'Monitor',
                'mouse' => 'Mouse',
                'printer-scanner' => 'Printer & Scanner',
                'projector' => 'Projector',
                'recorder' => 'Recorder',
                'speaker' => 'Speaker',
                'ups-avr' => 'UPS & AVR',
                'webcam' => 'Web & Digital Camera',
            ],
        ],
        'accessories' => [
            'label' => 'Accessories',
            'icon' => 'fa-plug',
            'subs' => [
                'cables' => 'Cables',
                'earphones' => 'Earphones',
                'gaming-surface' => 'Gaming Surface',
                'power-bank' => 'Power Bank',
            ],
        ],
        'pc-furnitures' => [
            'label' => 'PC Furnitures',
            'icon' => 'fa-chair',
            'subs' => [
                'chairs' => 'Chairs',
                'tables' => 'Tables',
            ],
        ],
        'os-softwares' => [
            'label' => 'OS & Softwares',
            'icon' => 'fa-compact-disc',
            'subs' => [
                'antivirus' => 'Antivirus',
                'office' => 'Office Applications',
                'operating-system' => 'Operating System',
            ],
        ],
        'laptops-mobile' => [
            'label' => 'Laptops And Mobile Devices',
            'icon' => 'fa-laptop',
            'subs' => [
                'chromebook' => 'Chromebook',
                'laptops' => 'Laptops',
                'mobile-phone' => 'Mobile Phone',
                'tablet' => 'Tablet',
            ],
        ],
        'desktop' => [
            'label' => 'Desktop',
            'icon' => 'fa-desktop',
            'subs' => [],
        ],
        'others' => [
            'label' => 'Others',
            'icon' => 'fa-ellipsis-h',
            'subs' => [],
        ],
    ];
}

function ep_shop_special_sections(): array
{
    return [
        'desktop' => ['label' => 'Desktop', 'icon' => 'fa-desktop'],
        'laptop' => ['label' => 'Laptop', 'icon' => 'fa-laptop'],
        'power-stations' => ['label' => 'Power Stations', 'icon' => 'fa-battery-full'],
        'rakk' => ['label' => 'RAKK', 'icon' => 'fa-tag'],
        'brands' => ['label' => 'Brands', 'icon' => 'fa-copyright'],
    ];
}

/** @return array{parent:string,sub:string,parent_label:string,sub_label:string,is_power_station:bool,brand:string} */
function ep_shop_classify_product(array $p): array
{
    $name = trim((string)($p['name'] ?? ''));
    $dbCat = trim((string)($p['category'] ?? ''));
    // Classify from the product name (and stored category). Descriptions often mention
    // "desktop/laptop" compatibility and must not steal the real type.
    $hay = mb_strtolower($name);
    $tax = ep_shop_taxonomy();

    $isPowerStation = (bool)preg_match('/\b(power station|portable power station|bluetti)\b/i', $hay)
        && !preg_match('/\b(solar panel|foldable solar)\b/i', $hay);

    $brand = function_exists('ias_client_product_brand')
        ? ias_client_product_brand($p)
        : '';

    $pick = static function (string $parent, string $sub = '') use ($tax): array {
        $parentLabel = $tax[$parent]['label'] ?? $parent;
        $subLabel = $sub !== '' ? (string)($tax[$parent]['subs'][$sub] ?? $sub) : '';
        return [$parent, $sub, $parentLabel, $subLabel];
    };

    // Power Stations are a shop section, not PSU / UPS.
    if ($isPowerStation) {
        [$parent, $sub, $pl, $sl] = $pick('others');
        return [
            'parent' => $parent,
            'sub' => $sub,
            'parent_label' => $pl,
            'sub_label' => $sl,
            'is_power_station' => true,
            'brand' => $brand,
        ];
    }

    $isBagOnly = preg_match('/\b(laptop bag|backpack)\b/i', $hay)
        && !preg_match('/\b(vivobook|win11|notebook|cyborg|modern 14|ideapad)\b/i', $hay);
    $isLaptopComputer = !$isBagOnly && (
        preg_match('/\b(vivobook|ideapad|thinkbook|chromebook|notebook|cyborg|modern 14)\b/i', $hay)
        || preg_match('/\blaptop\b/i', $hay)
        || strcasecmp($dbCat, 'Laptops') === 0
    );

    $result = null;

    if (preg_match('/\bchromebook\b/i', $hay)) {
        $result = $pick('laptops-mobile', 'chromebook');
    } elseif (preg_match('/\b(tablet|ipad)\b/i', $hay) && !preg_match('/\blaptop\b/i', $hay)) {
        $result = $pick('laptops-mobile', 'tablet');
    } elseif (preg_match('/\b(mobile phone|smartphone|cellphone)\b/i', $hay)) {
        $result = $pick('laptops-mobile', 'mobile-phone');
    } elseif ($isLaptopComputer) {
        $result = $pick('laptops-mobile', 'laptops');
    } elseif (ep_shop_is_ssd_only_product($name)) {
        $result = $pick('component', 'ssd');
    } elseif (preg_match('/\b(computer table|pc table|gaming desk)\b/i', $hay)
        || (preg_match('/\btable\b/i', $hay) && preg_match('/\b(wood|computer)\b/i', $hay))) {
        $result = $pick('pc-furnitures', 'tables');
    } elseif (preg_match('/\b(gaming chair|office chair)\b/i', $hay)) {
        $result = $pick('pc-furnitures', 'chairs');
    } elseif (preg_match('/\b(mini pc|all[\s-]?in[\s-]?one pc|desktop computer|barebone pc)\b/i', $hay)
        && !preg_match('/\b(usb hub|eco bag|power switch|table|fan|motherboard|solid state drive)\b/i', $hay)) {
        $result = $pick('desktop');
    } elseif (preg_match('/\b(windows\s*1[01]\s*pro|operating system|dsp oei|fqc-\d+)\b/i', $hay)
        && !preg_match('/\b(laptop|mini pc|solid state|ssd|nvme)\b/i', $hay)) {
        $result = $pick('os-softwares', 'operating-system');
    } elseif (preg_match('/\b(antivirus|norton|kaspersky|bitdefender|avast)\b/i', $hay)) {
        $result = $pick('os-softwares', 'antivirus');
    } elseif (preg_match('/\b(office 365|microsoft 365|office application|ms office)\b/i', $hay)
        && !preg_match('/\blaptop\b/i', $hay)) {
        $result = $pick('os-softwares', 'office');
    } elseif (preg_match('/\bmotherboard\b/i', $hay)) {
        $result = $pick('component', 'motherboard');
    } elseif (preg_match('/\b(processor tray|\bttp\b)\b/i', $hay) && preg_match('/\b(ryzen|intel|processor)\b/i', $hay)) {
        $result = $pick('component', 'processor-tray');
    } elseif (preg_match('/\b(intel core|core ultra)\b/i', $hay) && preg_match('/\bprocessor\b/i', $hay)) {
        $result = $pick('component', 'processor-intel');
    } elseif (preg_match('/\bryzen\b/i', $hay) && preg_match('/\bprocessor\b/i', $hay)) {
        $result = $pick('component', 'processor-amd');
    } elseif (preg_match('/\b(videocard|graphics card|geforce|radeon rx)\b/i', $hay)
        && !preg_match('/\b(processor|laptop)\b/i', $hay)) {
        $result = $pick('component', 'graphics-card');
    } elseif (preg_match('/\b(pc case|atx pc case|micro atx|mid tower|dual chamber|matx)\b/i', $hay)
        && preg_match('/\b(case|tempered glass|tower)\b/i', $hay)) {
        $result = $pick('component', 'pc-case');
    } elseif (preg_match('/\b(chassis fan|case fan|sickleflow|pwm single chassis|argb chassis fan|fan freebie)\b/i', $hay)
        && !preg_match('/\b(cpu air cooler|liquid cool|heatsink fan)\b/i', $hay)) {
        $result = $pick('component', 'chassis-fan');
    } elseif (preg_match('/\b(cpu cooler|cpu air cooler|liquid cool|liquid cooler|heatsink fan|cpu cooling)\b/i', $hay)) {
        $result = $pick('component', 'cpu-cooling');
    } elseif (preg_match('/\b(hard ?disk|harddisk|\bhdd\b|st\d{4}dm)\b/i', $hay)
        && !preg_match('/\b(ssd|nvme|solid state)\b/i', $hay)) {
        $result = $pick('component', 'hard-disk');
    } elseif (preg_match('/\b(solid state drive|nvme solid|\bssd\b)\b/i', $hay)
        && !preg_match('/\b(heatsink|laptop|mini pc|all[\s-]?in[\s-]?one|desktop computer|barebone pc)\b/i', $hay)) {
        $result = $pick('component', 'ssd');
    } elseif (preg_match('/\b(memory|sodimm)\b/i', $hay)
        || (preg_match('/\bddr[345]\b/i', $hay) && preg_match('/\b(\d+\s*gb|heatsink memory)\b/i', $hay)
            && !preg_match('/\b(motherboard|processor|videocard|laptop)\b/i', $hay))) {
        $result = $pick('component', 'memory');
    } elseif (preg_match('/\b(power supply|\bpsu\b)\b/i', $hay)
        && !preg_match('/\b(power station|portable power|solar panel|pc case|tempered glass|ups|avr)\b/i', $hay)) {
        $result = $pick('component', 'power-supply');
    } elseif (preg_match('/\b(\d{3,4}\s*w)\b/i', $hay) && preg_match('/\b(80\+|80plus|modular|atx 3)\b/i', $hay)
        && !preg_match('/\b(power station|solar)\b/i', $hay)) {
        $result = $pick('component', 'power-supply');
    } elseif (preg_match('/\b(cable|hdmi|vga|displayport|dp to|converter)\b/i', $hay)
        && preg_match('/\b(cable|adapter|converter|hdmi|vga|dp)\b/i', $hay)
        && !preg_match('/\b(motherboard|pc case|power supply|laptop)\b/i', $hay)) {
        $result = $pick('accessories', 'cables');
    } elseif (preg_match('/\b(power bank|powerbank)\b/i', $hay)) {
        $result = $pick('accessories', 'power-bank');
    } elseif (preg_match('/\b(earphone|earbuds|in-ear)\b/i', $hay) && !preg_match('/\bheadset\b/i', $hay)) {
        $result = $pick('accessories', 'earphones');
    } elseif (preg_match('/\b(mouse\s*pad|gaming surface)\b/i', $hay)) {
        $result = $pick('accessories', 'gaming-surface');
    } elseif (preg_match('/\b(webcam|web camera|digital camera)\b/i', $hay)
        && !preg_match('/\b(cctv|nvr)\b/i', $hay)) {
        $result = $pick('peripherals', 'webcam');
    } elseif (preg_match('/\b(ups|avr|back-?ups)\b/i', $hay)
        && !preg_match('/\b(power station|power supply|harddisk|hard disk)\b/i', $hay)) {
        $result = $pick('peripherals', 'ups-avr');
    } elseif (preg_match('/\b(nvr|network video recorder)\b/i', $hay)) {
        $result = $pick('peripherals', 'recorder');
    } elseif (preg_match('/\bprojector\b/i', $hay)) {
        $result = $pick('peripherals', 'projector');
    } elseif (preg_match('/\b(printer|scanner|pixma|ink tank|inkjet)\b/i', $hay)) {
        $result = $pick('peripherals', 'printer-scanner');
    } elseif (preg_match('/\b(cctv|ir dome|hiwatch|hikvision)\b/i', $hay)) {
        $result = $pick('peripherals', 'cctv');
    } elseif (preg_match('/\b(speakers?|subwoofer|studio monitors?|monitor speakers?)\b/i', $hay)
        && !preg_match('/\bheadset\b/i', $hay)) {
        $result = $pick('peripherals', 'speaker');
    } elseif (preg_match('/\bmonitor\b/i', $hay)
        && !preg_match('/\b(studio monitor|monitor speaker|all[\s-]?in[\s-]?one)\b/i', $hay)) {
        $result = $pick('peripherals', 'monitor');
    } elseif (preg_match('/\bheadset\b/i', $hay)) {
        $result = $pick('peripherals', 'headset');
    } elseif (preg_match('/\b(keyboard and mouse|keyboard\s*&\s*mouse|keyboard \/ mouse)\b/i', $hay)) {
        $result = $pick('peripherals', 'keyboard-mouse');
    } elseif (preg_match('/\bkeyboard\b/i', $hay) && !preg_match('/\bmouse\b/i', $hay)) {
        $result = $pick('peripherals', 'keyboard');
    } elseif (preg_match('/sinag\d+/i', $hay) && preg_match('/\bcase\b/i', $hay)) {
        $result = $pick('peripherals', 'keyboard');
    } elseif (preg_match('/\bmouse\b/i', $hay) && !preg_match('/\b(mousepad|mouse pad)\b/i', $hay)) {
        $result = $pick('peripherals', 'mouse');
    } elseif (strcasecmp($dbCat, 'Display') === 0) {
        $result = $pick('peripherals', 'monitor');
    } elseif (strcasecmp($dbCat, 'Speaker') === 0) {
        $result = $pick('peripherals', 'speaker');
    } elseif (strcasecmp($dbCat, 'Audio') === 0 && preg_match('/\bheadset\b/i', $hay)) {
        $result = $pick('peripherals', 'headset');
    } elseif (strcasecmp($dbCat, 'Audio') === 0 && preg_match('/\bspeaker\b/i', $hay)) {
        $result = $pick('peripherals', 'speaker');
    } elseif (strcasecmp($dbCat, 'Cooling') === 0 && preg_match('/\b(chassis fan|case fan)\b/i', $hay)) {
        $result = $pick('component', 'chassis-fan');
    } elseif (strcasecmp($dbCat, 'Cooling') === 0) {
        $result = $pick('component', 'cpu-cooling');
    } else {
        $result = $pick('others');
    }

    return [
        'parent' => $result[0],
        'sub' => $result[1],
        'parent_label' => $result[2],
        'sub_label' => $result[3],
        'is_power_station' => false,
        'brand' => $brand,
    ];
}

function ep_shop_is_complete_computer_product(string $name): bool
{
    $hay = mb_strtolower(trim($name));
    if ($hay === '') {
        return false;
    }
    return (bool)preg_match('/\b(mini pc|all[\s-]?in[\s-]?one|laptop|notebook|vivobook|chromebook|desktop computer|barebone pc)\b/i', $hay);
}

function ep_shop_is_ssd_only_product(string $name): bool
{
    $hay = mb_strtolower(trim($name));
    if ($hay === '') {
        return false;
    }
    if (ep_shop_is_complete_computer_product($name)) {
        return false;
    }
    if (preg_match('/\bsolid state drive\b/i', $hay)) {
        return true;
    }
    return (bool)preg_match('/\bssd\b/i', $hay)
        && preg_match('/\b(m\.2|nvme|sata|2\.5)\b/i', $hay)
        && !preg_match('/\bheatsink\b/i', $hay);
}

function ep_shop_is_display_product(array $p): bool
{
    $dbCat = trim((string)($p['category'] ?? ''));
    if (strcasecmp($dbCat, 'Display') === 0) {
        return true;
    }
    if (($p['tax_sub'] ?? '') === 'monitor') {
        return true;
    }
    $name = trim((string)($p['name'] ?? ''));
    return $name !== ''
        && (bool)preg_match('/\bmonitor\b/i', $name)
        && !preg_match('/\b(studio monitor|monitor speaker|all[\s-]?in[\s-]?one)\b/i', $name);
}

function ep_shop_matches_desktop_section(array $p): bool
{
    if (($p['tax_sub'] ?? '') === 'ssd') {
        return false;
    }
    return !empty($p['is_display']);
}

function ep_shop_attach_taxonomy(array $products): array
{
    foreach ($products as &$p) {
        $info = ep_shop_classify_product($p);
        $p['tax_parent'] = $info['parent'];
        $p['tax_sub'] = $info['sub'];
        $p['tax_parent_label'] = $info['parent_label'];
        $p['tax_sub_label'] = $info['sub_label'];
        $p['is_power_station'] = $info['is_power_station'];
        $p['is_display'] = $info['sub'] === 'monitor' || strcasecmp((string)($p['category'] ?? ''), 'Display') === 0;
        $p['brand'] = $info['brand'] !== '' ? $info['brand'] : ias_client_product_brand($p);
    }
    unset($p);
    return $products;
}

function ep_shop_url(array $overrides = [], ?array $base = null): string
{
    $params = array_merge($base ?? $_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '' || $v === 0 || $v === '0') {
            unset($params[$k]);
        }
    }
    if (!array_key_exists('page', $overrides)) {
        unset($params['page']);
    }
    return 'shop.php' . ($params ? ('?' . http_build_query($params)) : '');
}

function ep_shop_parent_valid(?string $slug): bool
{
    return $slug !== null && $slug !== '' && isset(ep_shop_taxonomy()[$slug]);
}

function ep_shop_sub_valid(string $parent, ?string $sub): bool
{
    if ($sub === null || $sub === '') {
        return false;
    }
    $tax = ep_shop_taxonomy();
    return isset($tax[$parent]['subs'][$sub]);
}

function ep_shop_brands_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db->query(
            'CREATE TABLE IF NOT EXISTS shop_brands (
                name TEXT PRIMARY KEY,
                logo_path TEXT NOT NULL DEFAULT \'\',
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )'
        );
    } catch (Throwable $e) {
        // Table may already exist.
    }
}

/** @return array<string,string> brand name => public logo URL */
function ep_shop_brand_logos(PDO $db): array
{
    ep_shop_brands_ensure_schema($db);
    $out = [];
    try {
        $st = $db->query('SELECT name, logo_path FROM shop_brands WHERE logo_path <> \'\'');
        if (!$st) {
            return $out;
        }
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = trim((string)($row['name'] ?? ''));
            $url = ep_shop_brand_logo_url((string)($row['logo_path'] ?? ''));
            if ($name === '' || $url === '') {
                continue;
            }
            $out[$name] = $url;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function ep_shop_brand_logo_url(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_contains($path, '..')) {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (str_starts_with($path, 'assets/brands/')) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
            return '';
        }
        return '../' . $path;
    }
    return '';
}

function ep_shop_brand_logo_lookup(array $logos, string $brandName): string
{
    if ($brandName === '') {
        return '';
    }
    if (isset($logos[$brandName]) && $logos[$brandName] !== '') {
        return $logos[$brandName];
    }
    foreach ($logos as $name => $url) {
        if (strcasecmp((string)$name, $brandName) === 0) {
            return (string)$url;
        }
    }
    return '';
}
