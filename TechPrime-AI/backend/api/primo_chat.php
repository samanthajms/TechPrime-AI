<?php
/**
 * Primo chat bridge: PHP → SVM intent API → real TechPrime data handlers.
 * Browser never talks to Python or the DB directly for this flow.
 */
session_start();
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../includes/product_categories.php';

$primoHttp = PHP_SAPI !== 'cli' || realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__);
if ($primoHttp) {
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if (!verifyCsrfToken((string)($payload['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf', 'reply' => 'Your session expired. Please refresh the page and try again.']);
    exit;
}

$message = isset($payload['message']) ? trim((string) $payload['message']) : '';
if ($message === '' || mb_strlen($message) > 400) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_message', 'reply' => 'Please enter a short product-related question.']);
    exit;
}

// Block obvious script injection noise (message is never executed)
if (preg_match('/<\s*script|javascript:|onerror\s*=/i', $message)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_message', 'reply' => 'I can only help with TechPrime product questions.']);
    exit;
}

if (primo_is_explanation_request($message)) {
    $db = getDbConnection();
    $messageForLookup = primo_apply_context($message, 'brand_advice');
    $result = primo_brand_advice($db, $messageForLookup);
    primo_store_context('brand_advice', $messageForLookup, $result['products'] ?? []);
    echo json_encode([
        'ok' => true,
        'intent' => 'brand_advice',
        'confidence' => 1,
        'raw_intent' => 'brand_advice',
        'reply' => $result['reply'],
        'products' => $result['products'] ?? [],
        'show_tech_match' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$svm = primo_call_svm($message);
if ($svm === null) {
    echo json_encode([
        'ok' => false,
        'error' => 'svm_unavailable',
        'reply' => "Primo is temporarily unavailable. Please try again.",
    ]);
    exit;
}

$intent = (string) ($svm['intent'] ?? 'fallback');
$confidence = (float) ($svm['confidence'] ?? 0);
$rawIntent = (string) ($svm['raw_intent'] ?? $intent);
$db = getDbConnection();

/* Short follow-ups like "how much?" reuse the last product topic from this session. */
$messageForLookup = primo_apply_context($message, $intent);
if ($intent === 'brand_advice' || primo_is_explanation_request($message)) {
    $result = primo_brand_advice($db, $messageForLookup);
    $intent = 'brand_advice';
} else {
    $result = primo_handle_intent($db, $intent, $messageForLookup, $confidence, $message);
}
primo_store_context($intent, $messageForLookup, $result['products'] ?? []);

echo json_encode([
    'ok' => true,
    'intent' => $intent,
    'confidence' => round($confidence, 4),
    'raw_intent' => $rawIntent,
    'reply' => $result['reply'],
    'products' => $result['products'] ?? [],
    'show_tech_match' => !empty($result['show_tech_match']),
], JSON_UNESCAPED_UNICODE);
}

/**
 * Call local Flask SVM service.
 * @return array<string,mixed>|null
 */
function primo_call_svm(string $message): ?array
{
    // PRIMO_API_URL = base URL of the hosted service (e.g. https://primo-xxxx.onrender.com).
    // Checked in $_ENV (phpdotenv), $_SERVER (Apache SetEnv), then getenv (process env).
    $base = $_ENV['PRIMO_API_URL'] ?? $_SERVER['PRIMO_API_URL'] ?? getenv('PRIMO_API_URL');
    $base = is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : 'http://127.0.0.1:5055';
    $url = $base . '/predict';
    $isLocal = str_starts_with($base, 'http://127.0.0.1') || str_starts_with($base, 'http://localhost');
    // A sleeping Render free instance takes up to ~1 min to wake up.
    $connectTimeout = $isLocal ? 2 : 15;
    $timeout = $isLocal ? 6 : 60;
    $body = json_encode(['message' => $message]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $data = json_decode($resp, true);
        return is_array($data) && !empty($data['ok']) ? $data : null;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return null;
    }
    $data = json_decode($resp, true);
    return is_array($data) && !empty($data['ok']) ? $data : null;
}

/**
 * If the message is a vague follow-up, append last topic keywords from session.
 */
function primo_apply_context(string $message, string $intent): string
{
    $ctx = $_SESSION['primo_ctx'] ?? null;
    if (!is_array($ctx) || empty($ctx['topic'])) {
        return $message;
    }

    $vague = (bool) preg_match(
        '/^(how\s+much(\s+is\s+this)?|what.?s\s+the\s+price|price\s+please|is\s+(this|it)\s+(available|in\s+stock)|do\s+you\s+have\s+(this|it)|any\s+left|specs?\s*(please)?|tell\s+me\s+more|more\s+info|why(\s+that)?|explain(\s+more)?|more\s+details|details(\s+please)?|and\s+why)$/i',
        trim($message)
    );
    $productIntents = ['product_price', 'stock_inquiry', 'product_search', 'product_category', 'product_recommendation', 'brand_advice'];
    if ($vague || (in_array($intent, $productIntents, true) && mb_strlen(trim($message)) < 18)) {
        $topic = trim((string) $ctx['topic']);
        if ($topic !== '' && stripos($message, $topic) === false) {
            return trim($message . ' ' . $topic);
        }
    }
    return $message;
}

/**
 * Remember last product topic for short follow-up questions.
 * @param list<array>|array $products
 */
function primo_store_context(string $intent, string $message, array $products): void
{
    $keep = [
        'product_search', 'product_category', 'product_price', 'stock_inquiry',
        'product_recommendation', 'compatibility_question', 'saved_build', 'brand_advice',
    ];
    if (!in_array($intent, $keep, true)) {
        return;
    }

    $topic = '';
    if (!empty($products[0]['name'])) {
        $topic = (string) $products[0]['name'];
    } else {
        $lower = mb_strtolower($message);
        if (preg_match('/\b(ryzen\s*\d+|rtx\s*\d+|gtx\s*\d+|i[3579]-\d+\w*|1tb|500gb|ssd|ram|gpu|motherboard|processor|cpu)\b/i', $message, $m)) {
            $topic = $m[0];
        } elseif (preg_match('/\b(ssd|ram|gpu|monitor|laptop|desktop|headset|keyboard|mouse)\b/i', $lower, $m)) {
            $topic = $m[0];
        }
    }

    if ($topic === '') {
        return;
    }

    $_SESSION['primo_ctx'] = [
        'intent' => $intent,
        'topic' => $topic,
        'at' => time(),
    ];
}

function primo_is_explanation_request(string $message): bool
{
    $trim = trim($message);
    if (preg_match('/^(why|explain|details|tell me more|and why)[.!?\s]*$/i', $trim)) {
        return true;
    }
    return (bool) preg_match(
        '/\b(best\s+brands?|which\s+brands?|what\s+brands?|brand\s+and\s+why|explain|explanation|give\s+me\s+details|more\s+details|tell\s+me\s+why|why\s+that|why\s+is\s+that|compare\s+brands?|which\s+is\s+better|what\s+makes)\b/i',
        $message
    );
}

function primo_match_category(string $message): ?string
{
    $categoryMap = [
        'laptop' => 'Laptops',
        'laptops' => 'Laptops',
        'desktop' => 'Desktop',
        'desktops' => 'Desktop',
        'audio' => 'Audio',
        'headset' => 'Audio',
        'headsets' => 'Audio',
        'speaker' => 'Speaker',
        'cooling' => 'Cooling',
        'accessories' => 'Accessories',
        'accessory' => 'Accessories',
        'keyboard' => 'Keyboard',
        'mouse' => 'Mouse',
        'printer' => 'Printers and Scanners',
        'scanner' => 'Printers and Scanners',
        'gpu' => 'GPU',
        'graphics' => 'GPU',
        'geforce' => 'GPU',
        'radeon' => 'GPU',
        'rtx' => 'GPU',
        'gtx' => 'GPU',
        'ram' => 'RAM',
        'memory' => 'RAM',
        'motherboard' => 'Motherboard',
        'processor' => 'Processor',
        'cpu' => 'Processor',
        'ryzen' => 'Processor',
        'ssd' => 'Storage',
        'hdd' => 'Storage',
        'storage' => 'Storage',
        'psu' => 'PSU',
        'power supply' => 'PSU',
        'cooler' => 'Cooling',
        'monitor' => 'Monitor',
        'case' => 'Case',
    ];
    $matched = null;
    $bestLen = 0;
    foreach ($categoryMap as $needle => $cat) {
        if (!preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $message)) {
            continue;
        }
        $len = mb_strlen($needle);
        if ($len > $bestLen) {
            $bestLen = $len;
            $matched = $cat;
        }
    }
    return $matched;
}

/**
 * Explain a brand choice from the live catalog. "Best" means the brand with the most items in stock.
 *
 * @return array{reply:string,products:array}
 */
function primo_brand_advice(PDO $db, string $message): array
{
    $category = primo_match_category($message);
    $focus = trim((string) preg_replace(
        '/\b(give|me|details|detail|explain|explanation|best|brand|brands|why|because|the|a|an|or|to|and|please|about|you|your|our|shop|store|what|which|is|are|for|of|that|this|tell|more)\b/i',
        ' ',
        $message
    ));
    $focus = trim((string) preg_replace('/\s+/', ' ', $focus));
    $categoryWords = $category !== null;
    if ($categoryWords) {
        $focus = trim((string) preg_replace('/\b(laptop|laptops|desktop|desktops|gpu|graphics|ram|memory|motherboard|processor|cpu|ryzen|ssd|hdd|storage|psu|power supply|cooler|cooling|monitor|keyboard|mouse|headset|headsets|case)\b/i', ' ', $focus));
        $focus = trim((string) preg_replace('/\s+/', ' ', $focus));
    }

    $wantsBrand = (bool) preg_match('/\bbrands?\b/i', $message);
    $products = [];
    if (!$wantsBrand && $focus !== '' && mb_strlen($focus) >= 3) {
        $products = primo_find_products($db, $focus, 'product_search', true);
    }

    if (empty($products) && !$wantsBrand && $focus !== '') {
        $family = '';
        if (preg_match('/\b(rtx|gtx|ryzen|radeon)\b/i', $focus, $familyMatch)) {
            $family = $familyMatch[1];
            $products = primo_products_named_like($db, $family);
        }
        if ($products !== []) {
            usort($products, static function ($a, $b) {
                $score = static function ($row) {
                    $name = mb_strtolower((string) ($row['name'] ?? ''));
                    return str_contains($name, 'videocard') || str_contains($name, 'video card') ? 1 : 0;
                };
                return $score($b) <=> $score($a);
            });
            $lines = [];
            foreach (array_slice($products, 0, 3) as $p) {
                $stock = (int) $p['stock'];
                $avail = $stock > 0 ? "in stock ({$stock})" : 'out of stock';
                $lines[] = '• ' . $p['name'] . ' — ₱' . number_format((float) $p['price'], 2) . ' — ' . $avail;
            }
            $reply = "I checked the EasyPC catalog and there is no exact match for \"{$focus}\". "
                . "The closest {$family} items we actually sell are:\n"
                . implode("\n", $lines)
                . "\nAsk me for the price, the stock, or which of these fits a budget and I will narrow it down.";
            return ['reply' => $reply, 'products' => array_slice($products, 0, 3)];
        }
        $where = $category !== null ? " for {$category}" : '';
        return [
            'reply' => "I checked the EasyPC catalog and could not find \"{$focus}\"{$where}. Tell me another model, or ask for the best brand in a category such as GPU, laptop, or RAM.",
            'products' => [],
        ];
    }

    if (!empty($products)) {
        $top = $products[0];
        $brand = primo_brand_from_name((string) $top['name']);
        $stock = (int) $top['stock'];
        $avail = $stock > 0 ? "in stock ({$stock} available)" : 'currently out of stock';
        $price = number_format((float) $top['price'], 2);
        $cat = $category ?? primo_match_category((string) $top['name']) ?? ($top['category'] !== '' ? $top['category'] : 'this category');
        $stats = primo_brand_stats($db, $cat !== 'this category' ? $cat : null);
        $why = '';
        foreach ($stats as $row) {
            if (strcasecmp($row['brand'], $brand) === 0) {
                $why = " {$brand} is one of the brands we actually sell in {$cat}: {$row['stocked']} in stock, priced from ₱" . number_format($row['min'], 2) . " to ₱" . number_format($row['max'], 2) . ".";
                break;
            }
        }
        $reply = "{$top['name']} is a {$cat} from {$brand}. It is ₱{$price} and {$avail}.{$why} I use our catalog for this, not a guess from outside the store. Ask me to compare another brand, or name a budget and I will narrow it down.";
        return ['reply' => $reply, 'products' => array_slice($products, 0, 3)];
    }

    $scope = $category ?? null;
    $stats = primo_brand_stats($db, $scope);
    if ($stats === []) {
        return [
            'reply' => "I can explain brands only from what EasyPC has in the catalog, and I could not find products for that yet. Name a category such as GPU, laptop, or RAM and I will compare the brands we sell.",
            'products' => [],
        ];
    }

    $lead = $stats[0];
    $where = $scope !== null ? "in {$scope}" : 'across the EasyPC catalog';
    $lines = [];
    foreach (array_slice($stats, 0, 4) as $row) {
        $lines[] = '• ' . $row['brand'] . ' — ' . $row['stocked'] . ' in stock (' . $row['count'] . ' listed), ₱' . number_format($row['min'], 2) . '–₱' . number_format($row['max'], 2);
    }
    $reply = "You asked for the best brand and why. In this store I do not invent a winner. I pick the brand you can actually buy today: the one with the most items in stock {$where}. That is {$lead['brand']}, with {$lead['stocked']} in stock"
        . ($lead['count'] !== $lead['stocked'] ? " out of {$lead['count']} listed" : '')
        . ', from ₱' . number_format($lead['min'], 2) . ' to ₱' . number_format($lead['max'], 2) . ".\n"
        . implode("\n", $lines)
        . "\nTell me a category, a budget, or a part name and I will explain that choice the same way.";

    return ['reply' => $reply, 'products' => []];
}

function primo_category_name_pattern(string $category): ?string
{
    $patterns = [
        'GPU' => 'videocard|video card|radeon rx|geforce',
        'Processor' => 'ryzen|core i[3579]|processor',
        'RAM' => 'ddr[345]|\\yram\\y|memory',
        'Laptops' => 'laptop|vivobook|ideapad|notebook|chromebook',
        'Monitor' => 'monitor',
        'Storage' => 'ssd|nvme|hdd|hard disk',
        'Motherboard' => 'motherboard',
        'PSU' => 'power supply|\\ypsu\\y',
        'Cooling' => 'cooler|cooling fan|chassis fan',
        'Keyboard' => 'keyboard',
        'Mouse' => '\\ymouse\\y',
        'Audio' => 'headset|earphone|headphone',
        'Case' => 'pc case|chassis',
        'Desktop' => 'desktop computer|mini pc',
        'Speaker' => 'speaker',
    ];
    return $patterns[$category] ?? null;
}

/**
 * In-stock catalog rows whose name matches a word, without dropping a row for a missing image file.
 * @return list<array{id:int,name:string,price:float,stock:int,category:string,image:string}>
 */
function primo_products_named_like(PDO $db, string $word): array
{
    $word = trim($word);
    if ($word === '') {
        return [];
    }
    $condition = ias_client_product_list_sql_condition('p');
    $sql = "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url
            FROM products p
            WHERE {$condition} AND p.name ~* ?
            ORDER BY p.id DESC
            LIMIT 12";
    $stmt = $db->prepare($sql);
    $stmt->execute(['\\y' . preg_quote($word, '/') . '\\y']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return primo_map_product_rows($rows);
}

function primo_brand_from_name(string $name): string
{
    if (preg_match('/^([A-Za-z0-9][A-Za-z0-9&+.\-]*)/u', trim($name), $m)) {
        return $m[1];
    }
    return 'That brand';
}

/**
 * @return list<array{brand:string,count:int,stocked:int,min:float,max:float}>
 */
function primo_brand_stats(PDO $db, ?string $category): array
{
    $condition = ias_client_product_list_sql_condition('p');
    $sql = "SELECT p.name, p.price, p.stock, p.category FROM products p WHERE {$condition}";
    $params = [];
    if ($category !== null && $category !== '') {
        $sql .= ' AND (' . ias_category_in_sql('p.category', $category, $params);
        $pattern = primo_category_name_pattern($category);
        if ($pattern !== null) {
            $sql .= ' OR p.name ~* ?';
            $params[] = $pattern;
        }
        $sql .= ')';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stats = [];
    foreach ($rows as $row) {
        $brand = primo_brand_from_name((string) ($row['name'] ?? ''));
        if ($brand === 'That brand') {
            continue;
        }
        $key = mb_strtolower($brand);
        if (!isset($stats[$key])) {
            $stats[$key] = ['brand' => $brand, 'count' => 0, 'stocked' => 0, 'min' => (float) $row['price'], 'max' => (float) $row['price']];
        }
        $price = (float) $row['price'];
        $stats[$key]['count']++;
        if ((int) ($row['stock'] ?? 0) > 0) {
            $stats[$key]['stocked']++;
        }
        $stats[$key]['min'] = min($stats[$key]['min'], $price);
        $stats[$key]['max'] = max($stats[$key]['max'], $price);
    }

    $list = array_values($stats);
    usort($list, function ($a, $b) {
        if ($a['stocked'] !== $b['stocked']) {
            return $b['stocked'] <=> $a['stocked'];
        }
        if ($a['count'] !== $b['count']) {
            return $b['count'] <=> $a['count'];
        }
        return strcasecmp($a['brand'], $b['brand']);
    });
    return $list;
}

/**
 * @return array{reply:string,products?:array,show_tech_match?:bool}
 */
function primo_handle_intent(PDO $db, string $intent, string $message, float $confidence, ?string $originalMessage = null): array
{
    $displayMsg = $originalMessage !== null ? $originalMessage : $message;

    switch ($intent) {
        case 'greeting':
            $greetings = [
                "Hey! 👋 What can I help you find today?",
                "Hi! 👋 I'm Primo, your EasyPC assistant. Looking for a product or want to build a PC?",
                "Hello! 💚 Welcome to EasyPC. What are you shopping for?",
                "Hey there! 👋 Ready to find parts or check a product for you.",
            ];
            return [
                'reply' => $greetings[array_rand($greetings)],
            ];

        case 'goodbye':
            $byes = [
                "See you next time! 👋",
                "Bye! Come back anytime you need help with EasyPC products. 👋",
                "Take care! Happy building. 💚",
            ];
            return ['reply' => $byes[array_rand($byes)]];

        case 'gratitude':
            $thanks = [
                "You're welcome! 😊 Let me know if you need anything else.",
                "Happy to help! 💚 Anything else I can check for you?",
                "Anytime! Feel free to ask about products, prices, or builds.",
            ];
            return ['reply' => $thanks[array_rand($thanks)]];

        case 'help':
            return [
                'reply' => "I can help with EasyPC products — search, prices, stock, compatibility, orders, and PC builds. Open Build a PC in the top navigation to customize a setup, or ask me about a product.",
            ];

        case 'store_information':
            return [
                'reply' => "EasyPC One Oasis Branch is our TechPrime storefront. You can shop online here, open Shop Now for products, or use Messages / EasyFix Support for help. Walk-in hours and phone details aren't listed in the system yet—please contact support through Messages.",
            ];

        case 'return_refund':
            return [
                'reply' => "EasyPC offers a 30-Day Money Back Guarantee on eligible orders. To start a return or refund, open My Orders from your profile or contact EasyFix Support via Messages with your order number. I can't process returns directly in chat.",
            ];

        case 'compatibility_question':
            return [
                'reply' => "Good question! 💻 For parts to work together, match CPU socket (e.g. AM4/AM5), RAM type (DDR4/DDR5), and PSU wattage to your GPU. Open Build a PC in the top navigation — it blocks incompatible parts when product details allow it. I can also look up specific products if you name them.",
            ];

        case 'saved_build':
            if (empty($_SESSION['user_id'])) {
                return [
                    'reply' => "To view your Saved Builds, please log in to your client account, then open Build a PC → View Saved Build.",
                ];
            }
            return [
                'reply' => "Open Build a PC in the top navigation, then use View Saved Build (bottom right) to see every build you've saved. You can also edit a build back into Build a PC from there.",
            ];

        case 'order_status':
            return primo_order_status($db, $message);

        case 'product_search':
        case 'product_category':
        case 'product_price':
        case 'stock_inquiry':
        case 'product_recommendation':
            return primo_product_intent($db, $intent, $message, $displayMsg);

        case 'fallback':
        default:
            $clarify = [
                "I'm not completely sure what you're looking for. 😊 Are you asking about a product, price, stock, compatibility, or building a PC?",
                "I might need a bit more detail. Are you looking for a product, checking a price/stock, compatibility, or help building a PC?",
                "I'm mainly here for EasyPC products and PC builds. 😊 Want help with a product, price, stock, compatibility, or Build a PC?",
            ];
            return ['reply' => $clarify[array_rand($clarify)]];
    }
}

/**
 * @return array{reply:string,products?:array}
 */
function primo_order_status(PDO $db, string $message): array
{
    if (empty($_SESSION['user_id'])) {
        return [
            'reply' => "To check order status, please log in to your client account first, then ask me again or open My Orders from My Profile.",
        ];
    }

    $uid = (int) $_SESSION['user_id'];
    $orderId = null;
    if (preg_match('/(?:order\s*#?\s*|ord-|#)\s*(\d+)/i', $message, $m)) {
        $orderId = (int) $m[1];
    } elseif (preg_match('/\b(\d{3,})\b/', $message, $m)) {
        $orderId = (int) $m[1];
    }

    if ($orderId) {
        $stmt = $db->prepare(
            "SELECT o.id, o.total, o.status, o.created_at,
                    (SELECT s.shipment_status FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) AS shipment_status,
                    (SELECT s.tracking_number FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) AS tracking_number,
                    (SELECT s.carrier FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) AS carrier
             FROM orders o
             WHERE o.id = ? AND o.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$orderId, $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['reply' => "I couldn't find order #{$orderId} on your account. Double-check the number or open My Orders."];
        }
        return ['reply' => primo_format_order_line($row)];
    }

    $stmt = $db->prepare(
        "SELECT o.id, o.total, o.status, o.created_at,
                (SELECT s.shipment_status FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) AS shipment_status,
                (SELECT s.tracking_number FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) AS tracking_number,
                (SELECT s.carrier FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) AS carrier
         FROM orders o
         WHERE o.user_id = ?
         ORDER BY o.created_at DESC
         LIMIT 3"
    );
    $stmt->execute([$uid]);
    $res = $stmt;
    $rows = $res ? $res->fetchAll(PDO::FETCH_ASSOC) : [];
    if (empty($rows)) {
        return ['reply' => "You don't have any orders on your account yet. Browse Shop Now when you're ready to buy."];
    }

    $lines = ["Here are your most recent orders:"];
    foreach ($rows as $row) {
        $lines[] = '• ' . primo_format_order_line($row);
    }
    $lines[] = "Ask with a specific order number (e.g. \"Where is order #123\") for details.";
    return ['reply' => implode("\n", $lines)];
}

function primo_format_order_line(array $row): string
{
    $id = (int) $row['id'];
    $total = number_format((float) $row['total'], 2);
    $display = function_exists('ias_order_display_status')
        ? ias_order_display_status($row['status'] ?? '', $row['shipment_status'] ?? null)
        : ($row['shipment_status'] ?: $row['status']);
    $date = !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : '';
    $extra = '';
    if (!empty($row['tracking_number'])) {
        $carrier = $row['carrier'] ?: 'Courier';
        $extra = " Tracking: {$carrier} {$row['tracking_number']}.";
    }
    return "Order #ORD-{$id} ({$date}) — ₱{$total} — Status: {$display}.{$extra}";
}

/**
 * @return array{reply:string,products:array,show_tech_match?:bool}
 */
function primo_product_intent(PDO $db, string $intent, string $message, ?string $displayMessage = null): array
{
    $products = primo_find_products($db, $message, $intent);
    $wantsBuild = (bool) preg_match('/\b(build|components?|tech\s*&?\s*match|customize|parts?\s+to\s+choose)\b/i', $message . ' ' . (string) $displayMessage);

    if (empty($products)) {
        if ($intent === 'product_recommendation' || $wantsBuild) {
            return [
                'reply' => "Sure! 💻 I can help you choose components. Open Build a PC in the top navigation to customize a full setup, or tell me a specific part (like Ryzen 5, RTX 4060, or 1TB SSD).",
                'products' => [],
            ];
        }
        if ($intent === 'product_price') {
            return [
                'reply' => "I can check the product price for you. Which product are you referring to? (name or category works)",
                'products' => [],
            ];
        }
        if ($intent === 'stock_inquiry') {
            return [
                'reply' => "I can check availability — which product or category should I look up?",
                'products' => [],
            ];
        }
        return [
            'reply' => "I couldn't find matching products in the EasyPC catalog for that. Try another name, brand, or category — or open Build a PC to browse by component.",
            'products' => [],
        ];
    }

    $lines = [];
    foreach ($products as $p) {
        $stock = (int) ($p['stock'] ?? 0);
        $avail = $stock > 0 ? "In stock ({$stock})" : 'Out of stock';
        $price = number_format((float) $p['price'], 2);
        $cat = $p['category'] ?: 'Uncategorized';
        $lines[] = "• {$p['name']} — ₱{$price} — {$cat} — {$avail}";
    }

    switch ($intent) {
        case 'product_price':
            $intro = 'Here are the current prices from our catalog:';
            break;
        case 'stock_inquiry':
            $intro = 'Here is current availability:';
            break;
        case 'product_category':
            $intro = 'Here are products in that category:';
            break;
        case 'product_recommendation':
            $intro = $wantsBuild
                ? "Great — here are real EasyPC options you can use while building. Open Build a PC in the top navigation to put a full setup together:"
                : 'Based on what you asked, here are real EasyPC products to consider:';
            break;
        default:
            $intro = 'Here are matching products from EasyPC:';
    }

    $outro = "\nOpen Shop Now to buy, or ask me about price, stock, or compatibility.";
    return [
        'reply' => $intro . "\n" . implode("\n", $lines) . $outro,
        'products' => $products,
    ];
}

/**
 * Search real products using existing client visibility rules.
 * @return list<array{id:int,name:string,price:float,stock:int,category:string}>
 */
function primo_find_products(PDO $db, string $message, string $intent, bool $strict = false): array
{
    $condition = ias_client_product_list_sql_condition('p');
    $lower = mb_strtolower($message);

    $matchedCategory = primo_match_category($message);

    $tokens = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\-+#.]/u', ' ', $lower) ?? '') ?: [];
    $stop = [
        'a','an','the','is','are','do','you','have','this','that','for','my','me','i','to','of','in','on',
        'what','which','how','much','price','cost','stock','available','availability','recommend','recommendation',
        'explain','explanation','details','detail','brand','brands','why','because','reason','reasons','better','compare','give',
        'looking','need','show','find','want','buy','good','best','should','would','please','can','about',
        'product','products','pc','computer','item','items','with','and','or','your','from','any','play',
        'get','suggest','advice','advise','help','choose','still','right','now','tell',
    ];
    $keywords = [];
    foreach ($tokens as $t) {
        $t = trim((string) $t);
        if ($t === '' || in_array($t, $stop, true) || mb_strlen($t) < 2) {
            continue;
        }
        // Skip pure game titles for SQL LIKE — used only as recommendation context
        if (preg_match('/^(valorant|fortnite|minecraft|cod|gaming)$/i', $t)) {
            continue;
        }
        $keywords[] = $t;
        if (count($keywords) >= 6) {
            break;
        }
    }

    $gaming = (bool) preg_match('/valorant|gaming|fortnite|fps|streamer|game\b/i', $message);
    $preferCats = null;
    if ($gaming && in_array($intent, ['product_recommendation', 'product_search'], true) && $matchedCategory === null) {
        $preferCats = ['Desktop', 'Laptops', 'Audio', 'GPU', 'Accessories'];
    }

    $attempts = [];
    if (count($keywords) >= 2) {
        $attempts[] = ['category' => $matchedCategory, 'cats' => $preferCats, 'keywords' => $keywords, 'match_all' => true];
    }
    $attempts = array_merge($attempts, [
        ['category' => $matchedCategory, 'cats' => $preferCats, 'keywords' => $keywords, 'match_all' => false],
        ['category' => $matchedCategory, 'cats' => $preferCats, 'keywords' => [], 'match_all' => false],
        ['category' => $matchedCategory, 'cats' => null, 'keywords' => $keywords, 'match_all' => false],
        ['category' => null, 'cats' => $preferCats, 'keywords' => [], 'match_all' => false],
        ['category' => null, 'cats' => null, 'keywords' => $keywords, 'match_all' => false],
    ]);

    // Recommendations with no useful tokens: show newest catalog items.
    // A strict lookup must not invent a product when the name does not match.
    if (!$strict && in_array($intent, ['product_recommendation', 'product_search', 'product_category'], true)) {
        $attempts[] = ['category' => null, 'cats' => null, 'keywords' => []];
    }
    if ($strict) {
        $attempts = array_values(array_filter($attempts, static function ($attempt) {
            return !empty($attempt['keywords']);
        }));
    }

    $seen = [];
    foreach ($attempts as $attempt) {
        $key = json_encode($attempt);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $rows = primo_query_products(
            $db,
            $condition,
            $attempt['category'],
            $attempt['cats'],
            $attempt['keywords'],
            !empty($attempt['match_all'])
        );
        if (!empty($attempt['keywords']) && !empty($rows)) {
            $rows = primo_rank_products($rows, $attempt['keywords']);
        }
        $filtered = ias_client_filter_products_for_display($rows, 5);
        if (!empty($filtered)) {
            return primo_map_product_rows($filtered);
        }
    }

    return [];
}

/**
 * Prefer products whose name/description match more keywords.
 * @param list<array> $rows
 * @param list<string> $keywords
 * @return list<array>
 */
function primo_rank_products(array $rows, array $keywords): array
{
    usort($rows, function ($a, $b) use ($keywords) {
        $score = function ($row) use ($keywords) {
            $s = 0;
            $name = mb_strtolower((string) ($row['name'] ?? ''));
            $desc = mb_strtolower((string) ($row['description'] ?? ''));
            $cat = mb_strtolower((string) ($row['category'] ?? ''));
            foreach ($keywords as $kw) {
                $kw = mb_strtolower($kw);
                if ($kw !== '' && str_contains($name, $kw)) {
                    $s += 5;
                } elseif ($kw !== '' && str_contains($cat, $kw)) {
                    $s += 2;
                } elseif ($kw !== '' && str_contains($desc, $kw)) {
                    $s += 1;
                }
            }
            return $s;
        };
        $diff = $score($b) <=> $score($a);
        if ($diff !== 0) {
            return $diff;
        }
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });
    return $rows;
}

/**
 * @param list<string>|null $cats
 * @param list<string> $keywords
 * @return list<array>
 */
function primo_query_products(PDO $db, string $condition, ?string $category, ?array $cats, array $keywords, bool $matchAll = false): array
{
    $sql = "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url
            FROM products p
            WHERE {$condition}";
    $types = '';
    $params = [];

    if ($category !== null) {
        // matches the old buckets AND the aligned Client/Custodian labels (includes/product_categories.php)
        $before = count($params);
        $sql .= ' AND ' . ias_category_in_sql('p.category', $category, $params);
        $types .= str_repeat('s', count($params) - $before);
    } elseif (!empty($cats)) {
        $before = count($params);
        $sql .= ' AND ' . ias_category_in_sql('p.category', $cats, $params);
        $types .= str_repeat('s', count($params) - $before);
    }

    if (!empty($keywords)) {
        $ors = [];
        foreach ($keywords as $kw) {
            $ors[] = '(p.name LIKE ? OR p.description LIKE ? OR p.category LIKE ?)';
            $like = '%' . $kw . '%';
            $types .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' AND (' . implode($matchAll ? ' AND ' : ' OR ', $ors) . ')';
    }

    $sql .= ' ORDER BY p.id DESC LIMIT 40';

    if ($types !== '') {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    }

    $res = $db->query($sql);
    return $res ? $res->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * @param list<array> $filtered
 * @return list<array{id:int,name:string,price:float,stock:int,category:string,image:string}>
 */
function primo_map_product_rows(array $filtered): array
{
    $out = [];
    foreach ($filtered as $p) {
        $out[] = [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'price' => (float) $p['price'],
            'stock' => (int) ($p['stock'] ?? 0),
            'category' => (string) ($p['category'] ?? ''),
            // relative to CLIENT/ pages, where the Primo widget renders product cards
            'image' => ias_client_product_image_url($p),
        ];
    }
    return $out;
}
