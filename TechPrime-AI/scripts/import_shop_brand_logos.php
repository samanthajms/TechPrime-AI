<?php
/**
 * Download real brand logos for existing catalog brands and store paths in shop_brands.
 * Does not modify products or create brands that are not already used in the catalog.
 */
require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/includes/client_helpers.php';
require_once dirname(__DIR__) . '/includes/client_shop_taxonomy.php';

$db = getDbConnection();
ep_shop_brands_ensure_schema($db);

$vis = ias_client_product_list_sql_condition('p');
$stmt = $db->query(
    "SELECT p.id, p.name, p.category, p.image, p.image_url
     FROM products p
     INNER JOIN users u ON p.seller_id = u.id
     WHERE {$vis}"
);
$products = ep_shop_attach_taxonomy($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []);
$brands = [];
foreach ($products as $p) {
    $name = trim((string)($p['brand'] ?? ''));
    if ($name !== '') {
        $brands[$name] = true;
    }
}
$brands = array_keys($brands);
sort($brands, SORT_STRING | SORT_FLAG_CASE);

$easypc = 'https://cdn.shopify.com/s/files/1/0101/4864/2879/files/';

/** Known real logo files on EasyPC Brands (reference only). */
$easypcFile = [
    'A4Tech' => 'A4T.png?v=1700623403',
    'Acer' => 'ACER.png?v=1701137969',
    'Adata' => 'ADATA.png?v=1700623010',
    'AMD' => 'AMD.png?v=1700623052',
    'AOC' => 'AOC.png?v=1700623074',
    'Asrock' => 'ASROCK.png?v=1701137970',
    'Asus' => 'ASUS_ed526605-d39e-4f9f-8e89-70e59635b47c.png?v=1701928127',
    'ASUS' => 'ASUS_ed526605-d39e-4f9f-8e89-70e59635b47c.png?v=1701928127',
    'BeQuiet' => 'BEQUITE.png?v=1701137969',
    'Biostar' => 'BIOSTAR.png?v=1700623403',
    'Canon' => 'CANON.png?v=1700623403',
    'CoolerMaster' => 'COOLERMASTER.png?v=1700623403',
    'DarkFlash' => 'DARKFLASH.png?v=1701137970',
    'Deepcool' => 'DEEPCOOL.png?v=1700623403',
    'Edifier' => 'EDIFIER.png?v=1700623403',
    'Epson' => 'EPSON.png?v=1700623403',
    'Fantech' => 'FANTECH.png?v=1701137970',
    'Gamdias' => 'GAMDIAS.png?v=1701137970',
    'Gigabyte' => 'GIGABYTE.png?v=1700623403',
    'HKC' => 'HKC.png?v=1700623403',
    'InPlay' => 'INPLAY.png?v=1701137970',
    'Intel' => 'INTEL.png?v=1700623403',
    'Intelligent' => 'INTELLIGENT.png?v=1700623403',
    'Lenovo' => 'LENOVO.png?v=1701137970',
    'Lexar' => 'LEXAR.png?v=1701137969',
    'LG' => 'LG.png?v=1700623403',
    'Logitech' => 'LOGI.png?v=1700623403',
    'MSI' => 'MSI.png?v=1700623403',
    'Nvision' => 'NVISION.png?v=1701137970',
    'NZXT' => 'NZXT.png?v=1700623403',
    'Philips' => 'PHILIPS.png?v=1701137970',
    'RAKK' => 'RAKK.png?v=1700623403',
    'Ramsta' => 'RAMSTA.png?v=1701137970',
    'Redragon' => 'REDDRAGON.png?v=1700623403',
    'Secure' => 'SECURE.png?v=1701137969',
    'Team' => 'TEAMGROUP.png?v=1701137969',
    'Teamgroup' => 'TEAMGROUP.png?v=1701137969',
    'TP-Link' => 'TPLINK.png?v=1700623403',
    'ViewSonic' => 'VIEWSONIC.png?v=1701137969',
];

$guessFiles = [
    'APC' => ['APC.png', 'APC.PNG'],
    'Accutone' => ['ACCUTONE.png'],
    'Aula' => ['AULA.png'],
    'Bluetti' => ['BLUETTI.png'],
    'DITO' => ['DITO.png'],
    'EPOS' => ['EPOS.png'],
    'ESGaming' => ['ESGAMING.png', 'ES_GAMING.png'],
    'FASPEED' => ['FASPEED.png'],
    'Hiksemi' => ['HIKSEMI.png', 'HIKVISION.png'],
    'KINGBANK' => ['KINGBANK.png'],
    'ORTIZAN' => ['ORTIZAN.png'],
    'TBL' => ['TBL.png'],
    'Windows' => ['WINDOWS.png', 'MS.png'],
    'Y5Plus' => ['Y5PLUS.png', 'Y5.png'],
    'Y5Pro' => ['Y5PRO.png', 'Y5.png'],
    'YGT' => ['YGT.png'],
];

$official = [
    'APC' => [
        'https://commons.wikimedia.org/wiki/Special:FilePath/LogoAPC.svg',
        'https://commons.wikimedia.org/wiki/Special:FilePath/APC-logo.svg',
    ],
    'Aula' => [
        'https://www.aulastar.com/uploads/allimg/20230921/1-2309212124532Z.png',
    ],
    'Bluetti' => [
        'https://cdn.shopify.com/s/files/1/0536/3390/8911/files/logo-text.svg?v=1763970499',
        'https://cdn.shopify.com/s/files/1/0536/3390/8911/files/logo-icon.svg?v=1763970499',
    ],
    'DITO' => [
        'https://dito.ph/hubfs/raw_assets/public/Dito_July2021/images/dito-logo.svg',
    ],
    'EPOS' => [
        'https://www.eposaudio.com/favicon.svg',
    ],
    'KINGBANK' => [
        'https://www.kingbank.com/uploads/20260427/c4014a22c7868b8f049a46beeec1048a.png',
    ],
    'ORTIZAN' => [
        'https://ortizan.com/wp-content/uploads/2022/06/logo2.png',
    ],
    'Windows' => [
        'https://commons.wikimedia.org/wiki/Special:FilePath/Windows_11_logo.svg',
    ],
    'YGT' => [
        'https://cdn.shopify.com/s/files/1/0101/4864/2879/files/YGT.png',
    ],
    'TBL' => [
        'https://cdn.shopify.com/s/files/1/0101/4864/2879/files/TBL.png',
    ],
    'ESGaming' => [
        'https://cdn.shopify.com/s/files/1/0101/4864/2879/files/ESGAMING.png',
    ],
    'FASPEED' => [
        'https://cdn.shopify.com/s/files/1/0101/4864/2879/files/FASPEED.png',
    ],
    'Y5Plus' => [
        'https://cdn.shopify.com/s/files/1/0101/4864/2879/files/Y5PLUS.png',
    ],
    'Y5Pro' => [
        'https://cdn.shopify.com/s/files/1/0101/4864/2879/files/Y5PRO.png',
    ],
];

$dir = dirname(__DIR__) . '/assets/brands';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}

function shop_brand_slug(string $name): string
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
    return trim($slug, '-') ?: 'brand';
}

function shop_brand_download(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TechPrime-AI/1.0; brand-logo import)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: image/png,image/svg+xml,image/jpeg,image/*,*/*;q=0.8'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300 || strlen($body) < 80) {
        return null;
    }
    $head = substr($body, 0, 16);
    $ext = '';
    if (str_starts_with($head, "\x89PNG")) {
        $ext = 'png';
    } elseif (str_starts_with($head, "\xFF\xD8\xFF")) {
        $ext = 'jpg';
    } elseif (str_starts_with($head, 'RIFF') && str_contains(substr($body, 0, 16), 'WEBP')) {
        $ext = 'webp';
    } elseif (str_starts_with($head, 'GIF8')) {
        $ext = 'gif';
    } elseif (stripos($body, '<svg') !== false) {
        $ext = 'svg';
    } elseif (str_contains($ctype, 'svg')) {
        $ext = 'svg';
    } elseif (str_contains($ctype, 'png')) {
        $ext = 'png';
    } elseif (str_contains($ctype, 'jpeg') || str_contains($ctype, 'jpg')) {
        $ext = 'jpg';
    } elseif (str_contains($ctype, 'webp')) {
        $ext = 'webp';
    } elseif (str_contains($ctype, 'gif')) {
        $ext = 'gif';
    }
    if ($ext === '' || str_contains($ctype, 'text/html')) {
        return null;
    }
    unset($err);
    return ['ext' => $ext, 'body' => $body];
}

function shop_brand_candidate_urls(string $brand, array $easypcFile, array $guessFiles, array $official, string $easypc): array
{
    $urls = [];
    foreach ($official[$brand] ?? [] as $url) {
        $urls[] = $url;
    }
    if (isset($easypcFile[$brand])) {
        $urls[] = $easypc . $easypcFile[$brand];
    }
    foreach ($guessFiles[$brand] ?? [] as $file) {
        $urls[] = $easypc . $file;
    }
    return array_values(array_unique($urls));
}

$upsert = $db->prepare(
    'INSERT INTO shop_brands (name, logo_path, updated_at)
     VALUES (?, ?, NOW())
     ON CONFLICT (name) DO UPDATE SET logo_path = EXCLUDED.logo_path, updated_at = NOW()'
);

$ok = [];
$fail = [];
foreach ($brands as $brand) {
    $slug = shop_brand_slug($brand);
    $saved = '';
    foreach (shop_brand_candidate_urls($brand, $easypcFile, $guessFiles, $official, $easypc) as $url) {
        $got = shop_brand_download($url);
        if ($got === null) {
            continue;
        }
        $rel = 'assets/brands/' . $slug . '.' . $got['ext'];
        $abs = dirname(__DIR__) . '/' . $rel;
        if (file_put_contents($abs, $got['body']) === false) {
            continue;
        }
        $saved = $rel;
        break;
    }
    if ($saved === '') {
        $fail[] = $brand;
        echo "MISS\t{$brand}\n";
        continue;
    }
    $upsert->execute([$brand, $saved]);
    $ok[] = $brand;
    echo "OK\t{$brand}\t{$saved}\n";
}

echo "\nImported " . count($ok) . " / " . count($brands) . " brand logos.\n";
if ($fail) {
    echo "Missing: " . implode(', ', $fail) . "\n";
    exit(2);
}
