<?php
/**
 * Primo chat bridge: PHP → SVM intent API → real TechPrime data handlers.
 * Browser never talks to Python or the DB directly for this flow.
 */
session_start();
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../config/database.php';

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
$result = primo_handle_intent($db, $intent, $messageForLookup, $confidence, $message);
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

/**
 * Call local Flask SVM service.
 * @return array<string,mixed>|null
 */
function primo_call_svm(string $message): ?array
{
    $url = 'http://127.0.0.1:5055/predict';
    $body = json_encode(['message' => $message]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 6,
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
            'timeout' => 6,
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
        '/^(how\s+much(\s+is\s+this)?|what.?s\s+the\s+price|price\s+please|is\s+(this|it)\s+(available|in\s+stock)|do\s+you\s+have\s+(this|it)|any\s+left|specs?\s*(please)?|tell\s+me\s+more|more\s+info)$/i',
        trim($message)
    );
    $productIntents = ['product_price', 'stock_inquiry', 'product_search', 'product_category', 'product_recommendation'];
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
        'product_recommendation', 'compatibility_question', 'saved_build',
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

/**
 * @return array{reply:string,products?:array,show_tech_match?:bool}
 */
function primo_handle_intent(mysqli $db, string $intent, string $message, float $confidence, ?string $originalMessage = null): array
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
                'show_tech_match' => true,
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
                'reply' => "I can help with EasyPC products — search, prices, stock, compatibility, orders, Tech & Match PC builds, and your Saved Builds. What would you like to do?",
                'show_tech_match' => true,
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
                'reply' => "Good question! 💻 For parts to work together, match CPU socket (e.g. AM4/AM5), RAM type (DDR4/DDR5), and PSU wattage to your GPU. Tech & Match is the easiest way to pick compatible components — I can also look up specific products if you name them.",
                'show_tech_match' => true,
            ];

        case 'saved_build':
            if (empty($_SESSION['user_id'])) {
                return [
                    'reply' => "To view your Saved Builds, please log in to your client account, then open Saved Build in the top navigation.",
                ];
            }
            return [
                'reply' => "You can open your saved PC builds anytime from Saved Build in the top navigation. That page lists every build you've saved, and you can load one back into Tech & Match.",
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
                "I'm mainly here for EasyPC products and PC builds. 😊 Want help with a product, price, stock, compatibility, or Tech & Match?",
            ];
            return ['reply' => $clarify[array_rand($clarify)]];
    }
}

/**
 * @return array{reply:string,products?:array}
 */
function primo_order_status(mysqli $db, string $message): array
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
        $stmt->bind_param('ii', $orderId, $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
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
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

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
function primo_product_intent(mysqli $db, string $intent, string $message, ?string $displayMessage = null): array
{
    $products = primo_find_products($db, $message, $intent);
    $wantsBuild = (bool) preg_match('/\b(build|components?|tech\s*&?\s*match|customize|parts?\s+to\s+choose)\b/i', $message . ' ' . (string) $displayMessage);

    if (empty($products)) {
        if ($intent === 'product_recommendation' || $wantsBuild) {
            return [
                'reply' => "Sure! 💻 I can help you choose components. Try Tech & Match to build a full PC, or tell me a specific part (like Ryzen 5, RTX 4060, or 1TB SSD).",
                'products' => [],
                'show_tech_match' => true,
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
            'reply' => "I couldn't find matching products in the EasyPC catalog for that. Try another name, brand, or category — or open Tech & Match to browse by component.",
            'products' => [],
            'show_tech_match' => true,
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
                ? "Great — here are real EasyPC options you can use while building. Tech & Match can help you put a full setup together:"
                : 'Based on what you asked, here are real EasyPC products to consider:';
            break;
        default:
            $intro = 'Here are matching products from EasyPC:';
    }

    $outro = "\nOpen Shop Now to buy, or ask me about price, stock, or compatibility.";
    $out = [
        'reply' => $intro . "\n" . implode("\n", $lines) . $outro,
        'products' => $products,
    ];
    if ($intent === 'product_recommendation' || $wantsBuild) {
        $out['show_tech_match'] = true;
    }
    return $out;
}

/**
 * Search real products using existing client visibility rules.
 * @return list<array{id:int,name:string,price:float,stock:int,category:string}>
 */
function primo_find_products(mysqli $db, string $message, string $intent): array
{
    $condition = ias_client_product_list_sql_condition('p');
    $lower = mb_strtolower($message);

    $categoryMap = [
        'laptop' => 'Laptops',
        'laptops' => 'Laptops',
        'desktop' => 'Desktop',
        'desktops' => 'Desktop',
        'audio' => 'Audio',
        'headset' => 'Audio',
        'headsets' => 'Audio',
        'speaker' => 'Audio',
        'cooling' => 'Cooling',
        'accessories' => 'Accessories',
        'accessory' => 'Accessories',
        'keyboard' => 'Accessories',
        'mouse' => 'Accessories',
        'printer' => 'Printers and Scanners',
        'scanner' => 'Printers and Scanners',
        'gpu' => 'GPU',
        'graphics' => 'GPU',
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

    $matchedCategory = null;
    foreach ($categoryMap as $needle => $cat) {
        if (preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $message)) {
            $matchedCategory = $cat;
            break;
        }
    }

    $tokens = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\-+#.]/u', ' ', $lower) ?? '') ?: [];
    $stop = [
        'a','an','the','is','are','do','you','have','this','that','for','my','me','i','to','of','in','on',
        'what','which','how','much','price','cost','stock','available','availability','recommend','recommendation',
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

    $attempts = [
        ['category' => $matchedCategory, 'cats' => $preferCats, 'keywords' => $keywords],
        ['category' => $matchedCategory, 'cats' => $preferCats, 'keywords' => []],
        ['category' => $matchedCategory, 'cats' => null, 'keywords' => $keywords],
        ['category' => null, 'cats' => $preferCats, 'keywords' => []],
        ['category' => null, 'cats' => null, 'keywords' => $keywords],
    ];

    // Recommendations with no useful tokens: show newest catalog items
    if (in_array($intent, ['product_recommendation', 'product_search', 'product_category'], true)) {
        $attempts[] = ['category' => null, 'cats' => null, 'keywords' => []];
    }

    $seen = [];
    foreach ($attempts as $attempt) {
        $key = json_encode($attempt);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $rows = primo_query_products($db, $condition, $attempt['category'], $attempt['cats'], $attempt['keywords']);
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
function primo_query_products(mysqli $db, string $condition, ?string $category, ?array $cats, array $keywords): array
{
    $sql = "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url
            FROM products p
            WHERE {$condition}";
    $types = '';
    $params = [];

    if ($category !== null) {
        if ($category === 'Printers and Scanners') {
            $sql .= " AND (p.category = ? OR p.category = ?)";
            $types .= 'ss';
            $params[] = 'Printers and Scanners';
            $params[] = 'Printer and Scanner';
        } else {
            $sql .= ' AND p.category = ?';
            $types .= 's';
            $params[] = $category;
        }
    } elseif (!empty($cats)) {
        $placeholders = implode(',', array_fill(0, count($cats), '?'));
        $sql .= " AND p.category IN ($placeholders)";
        $types .= str_repeat('s', count($cats));
        foreach ($cats as $c) {
            $params[] = $c;
        }
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
        $sql .= ' AND (' . implode(' OR ', $ors) . ')';
    }

    $sql .= ' ORDER BY p.id DESC LIMIT 40';

    if ($types !== '') {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows ?: [];
    }

    $res = $db->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * @param list<array> $filtered
 * @return list<array{id:int,name:string,price:float,stock:int,category:string}>
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
        ];
    }
    return $out;
}
