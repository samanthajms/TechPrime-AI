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
$db = getDbConnection();
$result = primo_handle_intent($db, $intent, $message, $confidence);

echo json_encode([
    'ok' => true,
    'intent' => $intent,
    'confidence' => round($confidence, 4),
    'reply' => $result['reply'],
    'products' => $result['products'] ?? [],
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
 * @return array{reply:string,products?:array}
 */
function primo_handle_intent(mysqli $db, string $intent, string $message, float $confidence): array
{
    switch ($intent) {
        case 'greeting':
            return [
                'reply' => "Hi! I'm Primo. How can I help you find a product today?",
            ];

        case 'goodbye':
            return [
                'reply' => "Goodbye! Feel free to come back if you need help finding a product.",
            ];

        case 'gratitude':
            return [
                'reply' => "You're welcome! I'm happy to help.",
            ];

        case 'help':
            return [
                'reply' => "I can help you find products, check prices and availability, compare product options, answer compatibility questions, and help with order-related questions.",
            ];

        case 'store_information':
            return [
                'reply' => "EasyPC One Oasis Branch is our TechPrime storefront brand. You can shop online on this site, open Shop Now for products, or use Messages / EasyFix Support for help. Specific walk-in hours and phone details aren't listed in the system yet—please contact support through the Messages page.",
            ];

        case 'return_refund':
            return [
                'reply' => "EasyPC offers a 30-Day Money Back Guarantee on eligible orders (see the homepage feature strip). To start a return or refund, open My Orders from your profile or contact EasyFix Support via Messages with your order number. I can't process returns directly in chat.",
            ];

        case 'compatibility_question':
            // No Random Forest module exists yet — guide to existing Tech & Match UI.
            return [
                'reply' => "I can tell you're asking about hardware compatibility. TechPrime doesn't run an automated compatibility predictor in chat yet. Use the Tech and Match section on the home dashboard to browse related categories, or ask about a specific product name and I'll share what's in our catalog. Tip: match CPU socket (e.g. AM4/AM5), RAM type (DDR4/DDR5), and PSU wattage to your GPU.",
            ];

        case 'order_status':
            return primo_order_status($db, $message);

        case 'product_search':
        case 'product_category':
        case 'product_price':
        case 'stock_inquiry':
        case 'product_recommendation':
            return primo_product_intent($db, $intent, $message);

        case 'fallback':
        default:
            return [
                'reply' => "I'm mainly here to help with TechPrime products, prices, availability, compatibility, and orders. What product can I help you with?",
            ];
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
 * @return array{reply:string,products:array}
 */
function primo_product_intent(mysqli $db, string $intent, string $message): array
{
    $products = primo_find_products($db, $message, $intent);
    if (empty($products)) {
        return [
            'reply' => "I couldn't find matching products in the TechPrime catalog for that request. Try another name, brand, or category (Desktop, Laptops, Audio, Accessories, etc.).",
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
            $intro = 'Here are real prices from our catalog:';
            break;
        case 'stock_inquiry':
            $intro = 'Here is current availability from inventory:';
            break;
        case 'product_category':
            $intro = 'Products in related categories:';
            break;
        case 'product_recommendation':
            $intro = 'Based on your request, here are real TechPrime products you can consider:';
            break;
        default:
            $intro = 'Here are matching products from TechPrime:';
    }

    $outro = "\nOpen Shop Now to filter further or buy.";
    return [
        'reply' => $intro . "\n" . implode("\n", $lines) . $outro,
        'products' => $products,
    ];
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
        'motherboard' => 'Motherboard',
        'processor' => 'Processor',
        'cpu' => 'Processor',
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
