<?php
/**
 * Primo chat bridge: PHP → SVM intent API → real TechPrime data handlers.
 * Browser never talks to Python or the DB directly for this flow.
 */
session_start();
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../includes/product_categories.php';
require_once __DIR__ . '/../../includes/pc_compatibility.php';
require_once __DIR__ . '/../../includes/client_helpers.php';        // ias_client_product_brand
require_once __DIR__ . '/../../includes/client_shop_taxonomy.php';  // Shop Now's categories, so lists and counts match it

// Unanswered-question log (primo_log_unanswered). These stay above the request handling: unlike
// functions, a const only exists once its line has run, and the handling below runs first.
const PRIMO_UNANSWERED_KEEP_DAYS = 90;
const PRIMO_UNANSWERED_PER_SESSION = 30;   // one visitor cannot fill the table

// Rate limit (primo_rate_limit_wait): messages per minute. A person typing stays far below both.
const PRIMO_RATE_WINDOW = 60;          // seconds
const PRIMO_RATE_PER_SESSION = 15;     // one browser
const PRIMO_RATE_PER_IP = 60;          // a script that opens a new session for every batch; a shared school/office network still fits

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

$wait = primo_rate_limit_wait();
if ($wait > 0) {
    http_response_code(429);
    header('Retry-After: ' . $wait);
    echo json_encode([
        'ok' => false,
        'error' => 'rate_limited',
        'retry_after' => $wait,
        'reply' => "You're sending messages a little fast. Please wait {$wait} seconds, then try again.",
    ]);
    exit;
}

/* A misspelled product type or brand ("hedset", "lenvo laptop", "may moniter ba kayo") is asked back as
 * "Did you mean …?" with the corrected question as suggestions, before any lookup answers with the
 * wrong products. Order and account questions are left alone. */
$db = ias_db_try();
if ($db !== null && !preg_match('/\b(order|refund|return|password|tracking)\b/i', $message)) {
    $misses = primo_spelling_check($db, $message);
    if ($misses !== []) {
        $result = primo_spelling_reply($message, $misses);
        echo json_encode([
            'ok' => true,
            'intent' => 'spelling_check',
            'confidence' => 1,
            'raw_intent' => 'spelling_check',
            'reply' => $result['reply'],
            'products' => [],
            'suggestions' => $result['suggestions'],
            'show_tech_match' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* "Will X work with Y?" is answered with the Build a PC compatibility rules. */
if (primo_is_compat_question($message)) {
    $db ??= getDbConnection();
    $result = primo_compat_answer($db, $message);
    if ($result !== null) {
        primo_store_context('compatibility_question', $message, $result['products']);
        echo json_encode([
            'ok' => true,
            'intent' => 'compatibility_question',
            'confidence' => 1,
            'raw_intent' => 'compatibility_question',
            'reply' => $result['reply'],
            'products' => $result['products'],
            'show_tech_match' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (primo_is_itemlist_question($message)) {
    try {
        $db ??= getDbConnection();
        $result = primo_itemlist_answer($db, $message);
    } catch (Throwable $e) {
        $result = ['reply' => 'I could not read the EasyPC catalog right now. Please try again in a moment.', 'products' => []];
    }
    primo_store_context('product_recommendation', $message, $result['products'] ?? []);
    echo json_encode([
        'ok' => true,
        'intent' => 'product_recommendation',
        'confidence' => 1,
        'raw_intent' => 'product_recommendation',
        'reply' => primo_phrase_catalog_reply((string) $result['reply']),
        'products' => $result['products'] ?? [],
        'show_tech_match' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (primo_is_explanation_request($message)) {
    $db ??= getDbConnection();
    $messageForLookup = primo_apply_context($message, 'brand_advice');
    $result = primo_brand_advice($db, $messageForLookup);
    primo_store_context('brand_advice', $messageForLookup, $result['products'] ?? []);
    echo json_encode([
        'ok' => true,
        'intent' => 'brand_advice',
        'confidence' => 1,
        'raw_intent' => 'brand_advice',
        'reply' => primo_phrase_catalog_reply((string) $result['reply']),
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

if ($intent === 'fallback') {
    primo_log_unanswered($message, $rawIntent, $confidence);
}

/* A message that names a product type is a catalog search, whatever the intent model made of it:
 * "asus gpu" is too short for the model, and Taglish such as "ano yung mga headphones niyo?" scores as
 * fallback or store information. Without this it reaches the general chat, which has no catalog and
 * invents products. It is still logged above, so retraining can learn it. A brand inside the type word
 * does not count ("cooler master case" is a case). */
if (in_array($intent, ['fallback', 'greeting', 'help', 'store_information'], true)) {
    $brandsAsked = $db !== null ? primo_brands_in($db, $message) : [];
    if (primo_match_category(primo_without_brands($message, $brandsAsked)) !== null) {
        $intent = 'product_search';
    }
}

/* Still unplaced: a word close to a product name ("vivobok") is asked back too, instead of going to the general chat. */
if ($intent === 'fallback' && $db !== null && !preg_match('/\b(order|refund|return|password|tracking)\b/i', $message)) {
    $misses = primo_spelling_check($db, $message, true);
    if ($misses !== []) {
        $result = primo_spelling_reply($message, $misses);
        echo json_encode([
            'ok' => true,
            'intent' => 'spelling_check',
            'confidence' => round($confidence, 4),
            'raw_intent' => $rawIntent,
            'reply' => $result['reply'],
            'products' => [],
            'suggestions' => $result['suggestions'],
            'show_tech_match' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (in_array($intent, ['greeting', 'goodbye', 'gratitude', 'help', 'fallback'], true)) {
    $general = primo_general_reply($message);
    if ($general !== null) {
        echo json_encode([
            'ok' => true,
            'intent' => $intent,
            'confidence' => round($confidence, 4),
            'raw_intent' => $rawIntent,
            'reply' => $general,
            'products' => [],
            'show_tech_match' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$db ??= getDbConnection();

/* Short follow-ups like "how much?" reuse the last product topic from this session. */
$messageForLookup = primo_apply_context($message, $intent);
if ($intent === 'brand_advice' || primo_is_explanation_request($message)) {
    $result = primo_brand_advice($db, $messageForLookup);
    $intent = 'brand_advice';
} else {
    $result = primo_handle_intent($db, $intent, $messageForLookup, $confidence, $message);
}
primo_store_context($intent, $messageForLookup, $result['products'] ?? []);

$reply = (string) ($result['reply'] ?? '');
if (in_array($intent, ['product_search', 'product_category', 'product_price', 'stock_inquiry', 'product_recommendation', 'brand_advice'], true)) {
    $reply = primo_phrase_catalog_reply($reply);
}

echo json_encode([
    'ok' => true,
    'intent' => $intent,
    'confidence' => round($confidence, 4),
    'raw_intent' => $rawIntent,
    'reply' => $reply,
    'products' => $result['products'] ?? [],
    'suggestions' => $result['suggestions'] ?? [],
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
 * Ask the existing Render service to rephrase one catalog sentence.
 * The PHP sentence stays when the service or Groq cannot keep the facts.
 */
function primo_phrase_catalog_reply(string $reply): string
{
    $reply = trim($reply);
    if ($reply === '' || mb_strlen($reply) > 900) {
        return $reply;
    }
    if (substr_count($reply, "\n") > 2) {
        return $reply;
    }
    // a reply with product cards keeps its "•" lines (primo_cards_reply), or a reopened chat loses the cards
    if (preg_match('/^\s*•\s/mu', $reply)) {
        return $reply;
    }
    if (!preg_match('/₱[\d,]+(?:\.\d+)?/u', $reply)) {
        return $reply;
    }

    $base = $_ENV['PRIMO_API_URL'] ?? $_SERVER['PRIMO_API_URL'] ?? getenv('PRIMO_API_URL');
    $base = is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : 'http://127.0.0.1:5055';
    $url = $base . '/phrase';
    $isLocal = str_starts_with($base, 'http://127.0.0.1') || str_starts_with($base, 'http://localhost');
    $body = json_encode(['facts' => $reply], JSON_UNESCAPED_UNICODE);
    $timeout = $isLocal ? 8 : 15;

    $resp = false;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $isLocal ? 2 : 8,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
    if ($resp === false || $code < 200 || $code >= 300) {
        return $reply;
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['ok']) || empty($data['phrased'])) {
        return $reply;
    }
    $phrased = trim((string) ($data['reply'] ?? ''));
    return primo_phrase_keeps_facts($reply, $phrased) ? $phrased : $reply;
}

function primo_phrase_keeps_facts(string $facts, string $phrased): bool
{
    if ($phrased === '' || mb_strlen($phrased) > 700) {
        return false;
    }
    preg_match_all('/₱[\d,]+(?:\.\d+)?/u', $facts, $needed);
    preg_match_all('/₱[\d,]+(?:\.\d+)?/u', $phrased, $got);
    $needed = $needed[0] ?? [];
    $got = $got[0] ?? [];
    foreach ($needed as $amount) {
        if (!in_array($amount, $got, true)) {
            return false;
        }
    }
    foreach ($got as $amount) {
        if (!in_array($amount, $needed, true)) {
            return false;
        }
    }
    if (preg_match_all('/([^.\n]{2,160}?) — ₱/u', $facts, $names) && !empty($names[1])) {
        $name = trim((string) end($names[1]));
        if ($name !== '' && mb_stripos($phrased, $name) === false) {
            return false;
        }
    }
    if (preg_match_all('/\d+ on hand/i', $facts, $qty)) {
        foreach ($qty[0] as $bit) {
            if (mb_stripos($phrased, $bit) === false) {
                return false;
            }
        }
    }
    return true;
}

/**
 * Shop talk and short greetings may go to Groq. Other topics get a fixed reply and are not sent.
 */
function primo_mentions_shop(string $message): bool
{
    return (bool) preg_match(
        '/\b(easypc|easy\s*pc|oasis|pasig|rosario|techprime|pc|pcs|computer|laptop|desktop|monitor|keyboard|mouse|gpu|cpu|ram|memory|ssd|hdd|nvme|motherboard|mobo|psu|cooler|headset|headphone|webcam|printer|router|wifi|gaming|office|parts?|component|upgrade|specs?|specification|price|priced|cost|magkano|presyo|stock|stocks|warranty|build|socket|ddr4|ddr5|intel|amd|nvidia|ryzen|rtx|gtx|msi|asus|acer|lenovo|gigabyte|corsair|kingston|samsung|logitech)\b/i',
        $message
    );
}

function primo_is_off_topic(string $message): bool
{
    return (bool) preg_match(
        '/\b(homework|assignment|essay|quiz|exam|algebra|calculus|photosynthesis|capital of|love advice|in love|crush|girlfriend|boyfriend|dating|relationship|joke|poem|movie|song|lyrics|weather|recipe|cook|cooking|president|celebrity|horoscope|translate)\b/i',
        $message
    );
}

function primo_is_shop_talk(string $message, array $turns): bool
{
    if (primo_is_off_topic($message)) {
        return false;
    }
    if (primo_mentions_shop($message)) {
        return true;
    }
    // Greetings and thanks, also with a name or polite word after them: "hello primo", "thanks po", "hi there! 👋"
    if (preg_match('/^(hi|hello|hey|yo|kamusta|good (morning|afternoon|evening)|thanks|thank you|salamat|bye|goodbye|paalam|see you|how are you|who are you|what can you (do|help(?: me)? with)|help)(\s*,?\s*(primo|po|there|again|so much|a lot|everyone))*[\s!.?,\p{So}]*$/iu', trim($message))) {
        return true;
    }
    if (mb_strlen(trim($message)) > 80) {
        return false;
    }
    foreach (array_slice($turns, -4) as $turn) {
        if (is_array($turn) && primo_mentions_shop((string) ($turn['content'] ?? ''))) {
            return true;
        }
    }
    return false;
}

/**
 * Shop conversation goes to Groq. Product lookups stay on the item list.
 * Returns null when Groq is unavailable so the existing reply is used.
 */
function primo_general_reply(string $message): ?string
{
    if (preg_match('/\b(order|refund|password|tracking)\b/i', $message)) {
        return null;
    }
    $turns = $_SESSION['primo_chat'] ?? [];
    if (!is_array($turns)) {
        $turns = [];
    }
    if (!primo_is_shop_talk($message, $turns)) {
        return "I'm only here for EasyPC and computer products. Ask me about a part, a PC, a price, or what's in stock.";
    }
    $messages = [];
    foreach (array_slice($turns, -6) as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $role = (string) ($turn['role'] ?? '');
        $content = trim((string) ($turn['content'] ?? ''));
        if (($role === 'user' || $role === 'assistant') && $content !== '') {
            $messages[] = ['role' => $role, 'content' => primo_redact(mb_substr($content, 0, 500))];
        }
    }
    // Groq is an outside provider: e-mail addresses and phone numbers never leave EasyPC (privacy policy)
    $messages[] = ['role' => 'user', 'content' => primo_redact($message)];

    $base = $_ENV['PRIMO_API_URL'] ?? $_SERVER['PRIMO_API_URL'] ?? getenv('PRIMO_API_URL');
    $base = is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : 'http://127.0.0.1:5055';
    $isLocal = str_starts_with($base, 'http://127.0.0.1') || str_starts_with($base, 'http://localhost');
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($base . '/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $isLocal ? 2 : 8,
        CURLOPT_TIMEOUT => $isLocal ? 12 : 25,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) {
        return null;
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['ok'])) {
        return null;
    }
    $reply = trim((string) ($data['reply'] ?? ''));
    if ($reply === '') {
        return null;
    }
    $turns[] = ['role' => 'user', 'content' => mb_substr($message, 0, 500)];
    $turns[] = ['role' => 'assistant', 'content' => mb_substr($reply, 0, 500)];
    $_SESSION['primo_chat'] = array_slice($turns, -8);
    return $reply;
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

/**
 * Questions the intent model could not place (final intent "fallback") are kept for review, so real
 * shopper wording can become training examples (scripts/primo_export_unanswered.php).
 * Nothing identifies the shopper: no user id, session, IP or page is stored, e-mail addresses and
 * phone/long numbers are masked first, and rows older than PRIMO_UNANSWERED_KEEP_DAYS are deleted.
 * Does nothing until database/migration_primo_unanswered.sql has been run, and never breaks the chat.
 */

function primo_redact(string $text): string
{
    $text = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/u', '[email]', $text) ?? $text;
    // Phone and account numbers (7+ digits, spaces or dashes allowed); model numbers like 4060 stay
    return preg_replace_callback('/\+?\d[\d\s\-().]{5,}\d/u', static function ($m) {
        return preg_match_all('/\d/', $m[0]) >= 7 ? '[number]' : $m[0];
    }, $text) ?? $text;
}

function primo_log_unanswered(string $message, string $rawIntent, float $confidence, ?PDO $db = null): void
{
    $logged = (int) ($_SESSION['primo_unanswered_logged'] ?? 0);
    if ($logged >= PRIMO_UNANSWERED_PER_SESSION) {
        return;
    }
    $db = $db ?? ias_db_try();   // no service-unavailable screen: a logging problem must not replace Primo's reply
    if ($db === null) {
        return;
    }
    try {
        if ($db->query("SELECT 1 WHERE to_regclass('public.primo_unanswered') IS NOT NULL")->fetchColumn() === false) {
            return;   // migration not run yet
        }
        $stmt = $db->prepare(
            'INSERT INTO primo_unanswered (message, raw_intent, confidence, reason) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            mb_substr(primo_redact(trim($message)), 0, 400),
            mb_substr($rawIntent, 0, 40),
            max(0.0, min(1.0, $confidence)),
            // the model itself said "not a shop question", or it had a guess but was not sure enough
            $rawIntent === 'fallback' ? 'off_topic' : 'low_confidence',
        ]);
        $_SESSION['primo_unanswered_logged'] = $logged + 1;
        if (random_int(1, 50) === 1) {
            $db->exec(
                "DELETE FROM primo_unanswered WHERE created_at < CURRENT_TIMESTAMP - INTERVAL '"
                . PRIMO_UNANSWERED_KEEP_DAYS . " days'"
            );
        }
    } catch (Throwable $e) {
        error_log('Primo unanswered log skipped: ' . $e->getMessage());
    }
}

/**
 * Seconds the visitor must wait before Primo answers again, or 0 to answer now.
 * Primo's replies use the Render service and Groq, both on free plans with limits, so one visitor or
 * script must not use them up and take Primo down for everyone. Counts the messages of the last
 * PRIMO_RATE_WINDOW seconds per session and per IP address. A blocked message is not counted, so
 * waiting always works. The IP address is not kept as text: its counter file is named by a hash of
 * it, lives in PHP's temp folder (outside the website), and is deleted once it is a minute old.
 */
function primo_rate_limit_wait(): int
{
    $now = time();
    $since = $now - PRIMO_RATE_WINDOW;
    $recent = array_values(array_filter(
        is_array($_SESSION['primo_rate'] ?? null) ? $_SESSION['primo_rate'] : [],
        static fn ($t) => is_int($t) && $t > $since
    ));
    if (count($recent) >= PRIMO_RATE_PER_SESSION) {
        return max(1, $recent[count($recent) - PRIMO_RATE_PER_SESSION] + PRIMO_RATE_WINDOW - $now);
    }

    // REMOTE_ADDR, not X-Forwarded-For: a client can put any address in that header
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip !== '') {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'techprime_primo_rate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $fh = @fopen($dir . DIRECTORY_SEPARATOR . hash('sha256', $ip) . '.json', 'c+');
        if ($fh !== false) {
            flock($fh, LOCK_EX);   // two requests at once from the same address
            $times = json_decode((string) stream_get_contents($fh), true);
            $times = array_values(array_filter(is_array($times) ? $times : [], static fn ($t) => is_int($t) && $t > $since));
            $blocked = count($times) >= PRIMO_RATE_PER_IP;
            if (!$blocked) {
                $times[] = $now;
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode($times));
            }
            flock($fh, LOCK_UN);
            fclose($fh);
            if ($blocked) {
                return max(1, $times[count($times) - PRIMO_RATE_PER_IP] + PRIMO_RATE_WINDOW - $now);
            }
        }
        if (random_int(1, 100) === 1) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $old) {
                if ((int) @filemtime($old) < $since) {
                    @unlink($old);
                }
            }
        }
    }

    $recent[] = $now;
    $_SESSION['primo_rate'] = $recent;
    return 0;
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

/** Product-type words => category. primo_match_category() also accepts their plurals. */
function primo_category_map(): array
{
    return [
        'laptop' => 'Laptops',
        'laptops' => 'Laptops',
        'desktop' => 'Desktop',
        'desktops' => 'Desktop',
        'audio' => 'Audio',
        'headset' => 'Audio',
        'headsets' => 'Audio',
        'headphone' => 'Audio',
        'earphone' => 'Audio',
        'earbud' => 'Audio',
        'speaker' => 'Speaker',
        'cooling' => 'Cooling',
        'accessories' => 'Accessories',
        'accessory' => 'Accessories',
        'keyboard' => 'Keyboard',
        'mouse' => 'Mouse',
        'mice' => 'Mouse',
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
        'power supplies' => 'PSU',
        'cooler' => 'Cooling',
        'monitor' => 'Monitor',
        'case' => 'Case',
    ];
}

function primo_match_category(string $message): ?string
{
    $matched = null;
    $bestLen = 0;
    foreach (primo_category_map() as $needle => $cat) {
        // plurals too: "monitors", "gpus", "cases"
        if (!preg_match('/\b' . preg_quote($needle, '/') . '(?:e?s)?\b/i', $message)) {
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
            return primo_cards_reply(
                "I checked the EasyPC catalog and there is no exact match for \"{$focus}\". The closest {$family} items we actually sell are:",
                array_slice($products, 0, 3),
                'Ask me for the price, the stock, or which of these fits a budget and I will narrow it down.'
            );
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
        $cat =$category ?? primo_match_category((string) $top['name']) ?? ($top['category'] !== '' ? $top['category'] : 'this category');
        $stats = primo_brand_stats($db, $cat !== 'this category' ? $cat : null);
        $why = '';
        foreach ($stats as $row) {
            if (strcasecmp($row['brand'], $brand) === 0) {
                $why = " {$brand} is one of the brands we actually sell in {$cat}: {$row['stocked']} in stock, priced from ₱" . number_format($row['min'], 2) . " to ₱" . number_format($row['max'], 2) . ".";
                break;
            }
        }
        // name, price and stock are on the cards
        return primo_cards_reply(
            'The closest match in our catalog is ' . (count($products) > 1 ? 'the first one below' : 'the one below') . ", a {$cat} from {$brand}.{$why} I use our catalog for this, not a guess from outside the store.",
            array_slice($products, 0, 3),
            'Ask me to compare another brand, or name a budget and I will narrow it down.'
        );
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
    $counts = [];
    $tops = [];   // each brand's top-end item in stock, shown as its card
    foreach (array_slice($stats, 0, 4) as $row) {
        $counts[] = "{$row['brand']} {$row['stocked']}";
        if ($row['top'] !== null) {
            $tops[] = $row['top'];
        }
    }
    $reply = "You asked for the best brand and why. In this store I do not invent a winner. I pick the brand you can actually buy today: the one with the most items in stock {$where}. That is {$lead['brand']}, with {$lead['stocked']} in stock"
        . ($lead['count'] !== $lead['stocked'] ? " out of {$lead['count']} listed" : '')
        . ', from ₱' . number_format($lead['min'], 2) . ' to ₱' . number_format($lead['max'], 2) . ".\n"
        . 'Items in stock per brand: ' . implode(', ', $counts) . '.'
        . ($tops !== [] ? " Below is each brand's top-end item in stock." : '');

    return primo_cards_reply($reply, primo_map_product_rows($tops), 'Tell me a category, a budget, or a part name and I will explain that choice the same way.');
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
    $sql = "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url FROM products p WHERE {$condition}";
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
            $stats[$key] = ['brand' => $brand, 'count' => 0, 'stocked' => 0, 'min' => (float) $row['price'], 'max' => (float) $row['price'], 'top' => null];
        }
        $price = (float) $row['price'];
        $stats[$key]['count']++;
        if ((int) ($row['stock'] ?? 0) > 0) {
            $stats[$key]['stocked']++;
            if ($stats[$key]['top'] === null || $price > (float) $stats[$key]['top']['price']) {
                $stats[$key]['top'] = $row;
            }
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
                'reply' => "EasyPC One Oasis Branch is at One Oasis, Rosario, Pasig City. It's open Monday to Sunday, 10:00 AM to 7:00 PM. Call or text 0917 625 5078, or message the EasyPC One Oasis Facebook page. You can also order here: open Shop Now, then choose Lalamove delivery or pick up at the store.",
            ];

        case 'return_refund':
            return [
                'reply' => "To ask about a return or refund, open My Orders from your profile or contact EasyFix Support via Messages with your order number. I can't process returns directly in chat.",
            ];

        case 'compatibility_question':
            $compat = primo_compat_answer($db, $displayMsg);
            if ($compat !== null) {
                return $compat;
            }
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
    $date = format_utc_datetime((string)($row['created_at'] ?? ''), 'M d, Y');
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
    $asked = primo_is_itemlist_question($message) ? $message : (string) $displayMessage;
    if ($asked !== '' && primo_is_itemlist_question($asked)) {
        return primo_itemlist_answer($db, $asked);
    }

    $own = (string) $displayMessage !== '' ? (string) $displayMessage : $message;
    // 5 cards keep the chat readable; "show all ..." gets the whole list up to 12, the rest is in Shop Now
    $limit = primo_wants_all($own) ? 12 : 5;
    $total = null;        // set when the list is a whole category or brand, so the reply can give the count
    $listLabel = '';
    $listCategory = null; // what that list is, for the Shop Now link that opens the same list
    $listBrands = [];

    // Brand first: the shopper's own words, then the product carried over from the last answer
    // ("how much" after a Lenovo answer). A brand typed now replaces the carried-over one, and a
    // message that names its own product type ("show all laptops") does not inherit one.
    $brands = primo_brands_in($db, $own);
    if ($brands === [] && primo_match_category($own) === null) {
        $brands = primo_brands_in($db, $message);
    }
    $brandIntro = null;
    if ($brands !== []) {
        // the shopper's words first: "asus gpu" after a laptop answer is about GPUs, not the carried-over laptop
        $category = primo_match_category(primo_without_brands($own, $brands))
            ?? primo_match_category(primo_without_brands($message, $brands));
        $products = primo_find_brand_products($db, $message, $brands, $category, $limit, $total);
        $listLabel = primo_brand_label($brands) . ' ' . primo_category_plural($category);
        $listCategory = $category;
        $listBrands = $brands;
        if ($products === []) {
            $missing = "We don't have any {$listLabel} in stock right now.";
            $products = $category !== null ? primo_find_brand_products($db, $message, [], $category, $limit, $total) : [];
            $listLabel = primo_category_plural($category);
            $listBrands = [];
            if ($products === []) {
                return [
                    'reply' => $missing . ' Try another brand, or open Shop Now to see everything in stock.',
                    'products' => [],
                ];
            }
            $brandIntro = $missing . " Here are other {$listLabel} you can check:";
        }
    } else {
        $category = primo_match_category($own);
        $products = [];
        if ($category !== null && primo_is_category_listing($own)) {
            // a whole category ("show all laptops"): the same list and count as Shop Now
            $products = primo_find_brand_products($db, $own, [], $category, $limit, $total);
            $listLabel = primo_category_plural($category);
            $listCategory = $category;
        }
        if ($products === []) {
            $total = null;
            $products = primo_find_products($db, $message, $intent);
        }
    }
    $wantsBuild = (bool) preg_match('/\b(build|components?|tech\s*&?\s*match|customize|parts?\s+to\s+choose)\b/i', $message . ' ' . (string) $displayMessage);

    if (empty($products)) {
        // nothing matched: a word close to a product name ("vivobok") is asked back, not guessed
        $misses = primo_spelling_check($db, $own, true);
        if ($misses !== []) {
            return primo_spelling_reply($own, $misses);
        }
        if ($intent === 'product_recommendation' || $wantsBuild) {
            return [
                'reply' => "Sure! 💻 I can help you choose components. Open Build a PC in the top navigation to customize a full setup, or tell me a specific part (like Ryzen 5, RTX 4060, or 1TB SSD).",
                'products' => [],
            ];
        }
        $named = primo_search_keywords($own);
        if ($named !== []) {
            return [
                'reply' => 'I couldn\'t find "' . implode(' ', $named) . '" in the EasyPC catalog. Try another name, brand, or category, or open Shop Now to see everything in stock.',
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
    if ($brandIntro !== null) {
        $intro = $brandIntro;
    }

    $outro = 'Open Shop Now to buy, or ask me about price, stock, or compatibility.';
    if ($total !== null && $total > count($products)) {
        // never let a short list look like everything the store has
        // primo.js turns [Shop Now](shop.php?...) into a link to that filtered list
        $outro = 'Showing ' . count($products) . " of the {$total} {$listLabel} in stock. Open [Shop Now]("
            . primo_shop_url($listCategory, $listBrands, $products) . ') to see them all.';
    } elseif ($total !== null && $total > 1 && $limit === 12 && $brandIntro === null) {
        $intro = "Here are all {$total} {$listLabel} in stock:";
    }
    return primo_cards_reply($intro, $products, $outro);
}

/**
 * Search real products using existing client visibility rules.
 * @return list<array{id:int,name:string,price:float,stock:int,category:string}>
 */
function primo_find_products(PDO $db, string $message, string $intent, bool $strict = false): array
{
    $condition = ias_client_product_list_sql_condition('p');
    $matchedCategory = primo_match_category($message);
    $keywords = primo_search_keywords($message);

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
    // A message that named something ("headfones", "xyz") must not be answered with the newest items
    // of the whole shop: no match is answered with "could not find" / "did you mean" instead.
    if ($keywords !== []) {
        $attempts = array_values(array_filter($attempts, static function ($attempt) {
            return $attempt['category'] !== null || !empty($attempt['cats']) || !empty($attempt['keywords']);
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
 * Search words in a message: lowercased, without filler words, at most 6.
 * @return list<string>
 */
function primo_search_keywords(string $message): array
{
    $lower = mb_strtolower($message);
    $tokens = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\-+#.]/u', ' ', $lower) ?? '') ?: [];
    $stop = primo_stop_words();
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

    return $keywords;
}

/** Filler words that never name a product, in English and Tagalog/Taglish ("ipakita mo sakin yung mga headphones"). */
function primo_stop_words(): array
{
    return [
        'a','an','the','is','are','do','you','have','this','that','for','my','me','i','to','of','in','on',
        'what','which','how','much','price','cost','stock','available','availability','recommend','recommendation',
        'explain','explanation','details','detail','brand','brands','why','because','reason','reasons','better','compare','give',
        'looking','need','show','find','want','buy','good','best','should','would','please','can','about',
        'product','products','pc','computer','item','items','with','and','or','your','from','any','play',
        'get','suggest','advice','advise','help','choose','still','right','now','tell',
        // "what's new?", "recommend something": no product named, so the newest items are a fair answer
        'new','newest','latest','something','anything',
        'ano','anong','yung','iyong','yong','mga','niyo','nyo','ninyo','inyo','ipakita','pakita','pakitingin',
        'patingin','tingin','mo','ko','sakin','akin','amin','namin','natin','sa','kayo','kayong','ka','ba','bang',
        'po','opo','meron','merong','mayroon','may','ng','nang','ang','na','naman','lang','lamang','pa','gusto',
        'hanap','hinahanap','naghahanap','pwede','puwede','paki','magkano','presyo','din','rin','nga','ito','eto',
        'iyan','yan','yun','iyon','dito','diyan','dyan','kailangan','bili','bibili','bumili','para','sana','kung',
        'saan','ilan','ilang','pang','at','o','kaya','wala','walang','bago','si','ni','sila','kami','tayo',
    ];
}

/** Everyday shop words that are not in product names; the spell check must not take them for typos. */
function primo_common_words(): array
{
    return [
        'hello','hi','hey','thanks','thank','salamat','good','morning','afternoon','evening','bye','okay',
        'want','need','like','looking','search','show','list','have','has','had','there','here','where','when',
        'what','which','who','whom','whose','will','would','could','should','shall','might','must','does','did',
        'done','make','made','take','took','come','came','going','gonna','know','think','sure','also','just',
        'only','very','really','more','most','less','least','many','much','some','every','each','other','another',
        'same','than','then','them','they','their','these','those','your','yours','ours','mine','into','onto',
        'over','under','below','above','around','between','within','without','about','after','before','again',
        'cheap','cheaper','cheapest','affordable','budget','expensive','price','prices','pricing','cost','costs',
        'total','amount','pesos','peso','money','cash','card','gcash','maya','paymongo','payment','pay','paid',
        'delivery','deliver','lalamove','pickup','pick','ship','shipping','store','shop','branch','open','close',
        'closed','hours','time','today','tomorrow','address','location','contact','number','phone','email',
        'order','orders','track','tracking','return','refund','warranty','repair','fix','broken','issue',
        'problem','account','login','password','profile','cart','checkout','wishlist','review','reviews',
        'build','builds','saved','part','parts','component','components','compatible','compatibility','upgrade',
        'gaming','game','games','gamer','office','school','student','students','work','working','business',
        'editing','video','videos','photo','photos','stream','streaming','music','movies','coding','programming',
        'fast','faster','fastest','slow','strong','powerful','light','lightweight','small','big','large','size',
        'color','colors','white','black','pink','blue','green','red','silver','gray','grey','wireless','wired',
        'inch','inches','model','models','type','types','kind','kinds','spec','specs','specification',
        'specifications','quality','brand','brands','available','availability','stock','stocks','left','sold',
        'apply','deal','deals','sale','promo','discount','offer','offers','free','included','include','includes',
        'please','pls','thank','sorry','yes','yeah','nope','maybe','compare','comparison','difference','versus',
        'recommend','recommended','suggestion','suggestions','option','options','choice','best','better','great',
        'nice','cool','awesome','latest','newest','older','used','brand-new','original','authentic','legit',
        'mura','murang','mahal','maganda','magandang','sulit','salamat','hello','kamusta','kumusta','paano',
        'pano','bakit','gaano','kelan','kailan','tapos','pero','kasi','talaga','sige','okay','ayos',
        'primo','easypc','techprime','oasis','pasig','rosario',
    ];
}

/**
 * What the spell check compares a word with. "strong": product types and brands, word => how to write it;
 * a near miss of one of these is asked back ("Did you mean headset?"). "names": every word of the product
 * names, offered only when a search found nothing. "known": words that are never a typo. Built once per request.
 *
 * @return array{strong:array<string,string>,names:array<string,string>,known:array<string,true>}
 */
function primo_spelling_words(PDO $db): array
{
    static $words = null;
    if ($words !== null) {
        return $words;
    }
    $strong = [];
    foreach (array_keys(primo_category_map()) as $type) {
        foreach (explode(' ', $type) as $part) {   // "power supply": each word on its own
            $strong[$part] = $part;
        }
    }
    foreach (['laptop', 'desktop', 'headset', 'headphone', 'earphone', 'earbud', 'speaker', 'keyboard', 'printer',
        'scanner', 'monitor', 'processor', 'motherboard', 'cooler', 'case', 'gpu', 'cpu', 'ssd'] as $countable) {
        $strong[$countable . 's'] = $countable . 's';
    }
    $known = [];
    foreach (array_keys(primo_brand_patterns($db)) as $label) {
        $key = mb_strtolower(str_replace([' ', '-'], '', $label));
        if (mb_strlen($key) >= 3) {
            $strong[$key] = $label;
        }
        foreach (preg_split('/[\s\-]+/', mb_strtolower($label)) ?: [] as $part) {
            $known[$part] = true;
        }
    }
    $names = [];
    try {
        foreach ($db->query('SELECT name FROM products') as $row) {
            preg_match_all('/\p{L}{3,}/u', mb_strtolower((string) $row['name']), $m);
            foreach ($m[0] as $word) {
                $names[$word] = $word;
            }
        }
    } catch (Throwable $e) {
        // no product words: types and brands are still checked
    }
    foreach (array_merge(primo_stop_words(), primo_common_words()) as $word) {
        $known[$word] = true;
    }
    $words = [
        'strong' => $strong,
        'names' => $names,
        'known' => $known + array_fill_keys(array_keys($strong), true) + array_fill_keys(array_keys($names), true),
    ];
    return $words;
}

/**
 * The closest targets to a word that is not known: same first letter, at most 1 letter off (2 for words
 * of 6+ letters). A 4-letter word only counts when a letter was left out ("mous"), so "cash" is not "case".
 *
 * @param array<string,string> $targets
 * @return list<string> how to write each match, best first, at most 2
 */
function primo_spelling_matches(string $word, array $targets): array
{
    $len = strlen($word);
    $max = $len >= 6 ? 2 : 1;
    $found = [];
    foreach ($targets as $target => $label) {
        $target = (string) $target;
        if ($target[0] !== $word[0] || abs(strlen($target) - $len) > $max) {
            continue;
        }
        if ($len < 5 && (strlen($target) !== $len + 1 || !preg_match('/^' . implode('.?', array_map(static fn ($c) => preg_quote($c, '/'), str_split($word))) . '.?$/', $target))) {
            continue;
        }
        $distance = levenshtein($word, $target);
        if ($distance <= $max) {
            $found[$label] = min($found[$label] ?? PHP_INT_MAX, $distance);
        }
    }
    if ($found === []) {
        return [];
    }
    $best = min($found);
    $closest = array_keys(array_filter($found, static fn ($d) => $d === $best));
    return array_slice(array_map('strval', $closest), 0, 2);
}

/**
 * Words in the message that look like misspelled product types or brands, or with $names also like
 * misspelled words of product names.
 *
 * @return list<array{word:string,options:list<string>}>
 */
function primo_spelling_check(PDO $db, string $message, bool $names = false): array
{
    $words = primo_spelling_words($db);
    $targets = $names ? $words['strong'] + $words['names'] : $words['strong'];
    preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($message), $m);
    $misses = [];
    foreach (array_unique($m[0]) as $token) {
        // model numbers ("rtx4060", "b450m") and short words are left alone
        if (strlen($token) < 4 || preg_match('/\d|[^a-z]/', $token) || isset($words['known'][$token])) {
            continue;
        }
        // a plural of a known word ("vivobooks", "boxes") is known too
        if (isset($words['known'][substr($token, 0, -1)]) || (str_ends_with($token, 'es') && isset($words['known'][substr($token, 0, -2)]))) {
            continue;
        }
        $options = primo_spelling_matches($token, $targets);
        if ($options !== []) {
            $misses[] = ['word' => $token, 'options' => $options];
        }
    }
    return $misses;
}

/**
 * "Did you mean …?" for the words primo_spelling_check() found, with the corrected question as
 * tap-to-send suggestions (shown by primo.js as chips).
 *
 * @param list<array{word:string,options:list<string>}> $misses
 * @return array{reply:string,products:array,suggestions:list<array{label:string,text:string}>}
 */
function primo_spelling_reply(string $message, array $misses): array
{
    $typed = implode(' or ', array_map(static fn ($miss) => '"' . $miss['word'] . '"', $misses));
    $meant = implode(' and ', array_map(static fn ($miss) => implode(' or ', array_map(static fn ($o) => '"' . $o . '"', $miss['options'])), $misses));
    $replace = static function (string $text, string $word, string $with): string {
        return preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/iu', $with, $text) ?? $text;
    };
    $suggestions = [];
    foreach ($misses[0]['options'] as $option) {
        $text = $replace($message, $misses[0]['word'], $option);
        foreach (array_slice($misses, 1) as $miss) {
            $text = $replace($text, $miss['word'], $miss['options'][0]);
        }
        $suggestions[] = ['label' => mb_strimwidth($text, 0, 48, '…'), 'text' => $text];
    }
    return [
        'reply' => "I couldn't find {$typed} in the EasyPC catalog. Did you mean {$meant}? Pick a suggestion below, or type the name again.",
        'products' => [],
        'suggestions' => $suggestions,
    ];
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
            // ILIKE: Postgres LIKE is case-sensitive, so "lenovo" would never match "Lenovo ..."
            $ors[] = '(p.name ILIKE ? OR p.description ILIKE ? OR p.category ILIKE ?)';
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
        $image = ias_client_product_image_url($p);
        $out[] = [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'price' => (float) $p['price'],
            'stock' => (int) ($p['stock'] ?? 0),
            'category' => (string) ($p['category'] ?? ''),
            // relative to CLIENT/ pages, where the Primo widget renders product cards
            'image' => $image,
            // the product page only opens for in-stock products with an image; other cards have no link
            'listed' => $image !== '' && (int) ($p['stock'] ?? 0) > 0,
        ];
    }
    return $out;
}

/**
 * Every answer that shows products has this one shape, so the chat draws the same product cards for
 * all of them (CLIENT/primo.js botContentHtml: text above, cards, note below) and a reopened chat gets
 * the cards back (CLIENT/primo_history_api.php reads the "•" lines): the text, one
 * "• {name} — ₱{price} — {category} — {availability}" line per product, then a short note.
 *
 * @param list<array{name:string,price:float,stock:int,category:string}> $products from primo_map_product_rows()
 * @return array{reply:string,products:list<array>}
 */
function primo_cards_reply(string $text, array $products, string $note = ''): array
{
    $reply = trim($text);
    foreach ($products as $p) {
        $stock = (int) ($p['stock'] ?? 0);
        $avail = $stock > 0 ? "In stock ({$stock})" : 'Out of stock';
        $cat = ($p['category'] ?? '') !== '' ? $p['category'] : 'Uncategorized';
        $reply .= "\n• {$p['name']} — ₱" . number_format((float) $p['price'], 2) . " — {$cat} — {$avail}";
    }
    $note = trim($note);
    return ['reply' => $note !== '' ? $reply . "\n" . $note : $reply, 'products' => $products];
}

/* ---- Brands ----------------------------------------------------------------
 * "Lenovo laptops" lists Lenovo only. When the shop has none, Primo says so before showing other
 * brands, instead of quietly answering with a different brand. Brand names come from the shop's own
 * list (shop_brands, the same list the custodian's stock-in uses) plus well-known PC brands EasyPC
 * may not carry, so "Kingston RAM" gets an honest "we don't have any" too.
 */

/**
 * @return array<string,string> brand label => regex that finds it in a message or a product name
 */
function primo_brand_patterns(PDO $db): array
{
    static $patterns = null;
    if ($patterns !== null) {
        return $patterns;
    }
    $names = [
        'Kingston', 'Samsung', 'Corsair', 'HP', 'Dell', 'Razer', 'HyperX', 'SteelSeries', 'Western Digital',
        'Crucial', 'Seagate', 'Toshiba', 'Transcend', 'Apacer', 'Zotac', 'Sapphire', 'PowerColor', 'XFX',
        'Palit', 'Galax', 'Inno3D', 'Colorful', 'Thermaltake', 'Lian Li', 'Antec', 'Cougar', 'Seasonic',
        'Hikvision', 'Huawei', 'Apple', 'Xiaomi', 'BenQ', 'JBL', 'Sony', 'Nvidia',
    ];
    try {
        foreach ($db->query('SELECT name FROM shop_brands ORDER BY name') as $row) {
            $names[] = (string) $row['name'];
        }
    } catch (Throwable $e) {
        // no shop_brands table: the built-in list still works
    }
    $skip = ['windows', 'secure', 'intelligent'];   // shop_brands entries that are everyday words in a question
    $merge = ['team' => 'teamgroup'];                // the same maker, named both ways in product names
    $aliases = [
        'coolermaster' => ['cooler master'],
        'bequiet' => ['be quiet'],
        'darkflash' => ['dark flash'],
        'teamgroup' => ['team group', 'team'],
        'western digital' => ['wd'],
    ];

    $brands = [];
    foreach ($names as $name) {
        $name = trim($name);
        $key = mb_strtolower($name);
        if ($name === '' || in_array($key, $skip, true)) {
            continue;
        }
        $key = $merge[$key] ?? $key;
        $brands[$key] ??= ['label' => null, 'variants' => [$key]];
        if ($brands[$key]['label'] === null && mb_strtolower($name) === $key) {
            $brands[$key]['label'] = $name;   // first spelling wins: "Asus" and "ASUS" are one brand
        }
    }
    $patterns = [];
    foreach ($brands as $key => $brand) {
        $alts = [];
        foreach (array_unique(array_merge($brand['variants'], $aliases[$key] ?? [])) as $variant) {
            // a space or hyphen may be written either way, or left out: "tp-link", "tp link", "tplink"
            $alts[] = str_replace(['\-', ' '], '[\s\-]?', preg_quote($variant, '/'));
        }
        usort($alts, static fn ($a, $b) => strlen($b) <=> strlen($a));
        // whole words only: "LG" must not match "LGA1700"
        $patterns[$brand['label'] ?? ucfirst($key)] = '/(?<![\p{L}\p{N}])(?:' . implode('|', $alts) . ')(?![\p{L}\p{N}])/iu';
    }
    return $patterns;
}

/**
 * Brands named in the text. AMD, Intel and Nvidia also appear in other makers' product names
 * ("Asus Prime AMD Radeon", "MSI ... Intel Core 7"), so they only count when no maker is named.
 *
 * @return array<string,string> brand label => regex
 */
function primo_brands_in(PDO $db, string $text): array
{
    $found = [];
    foreach (primo_brand_patterns($db) as $label => $re) {
        if (preg_match($re, $text)) {
            $found[$label] = $re;
        }
    }
    $makers = array_filter(
        $found,
        static fn ($label) => !in_array(mb_strtolower($label), ['amd', 'intel', 'nvidia'], true),
        ARRAY_FILTER_USE_KEY
    );
    return $makers !== [] ? $makers : $found;
}

/**
 * The text without brand names, for category matching: "cooler master case" is a case, not a cooler.
 * @param array<string,string> $brands
 */
function primo_without_brands(string $text, array $brands): string
{
    foreach ($brands as $re) {
        $text = preg_replace($re, ' ', $text) ?? $text;
    }
    return $text;
}

/** @param array<string,string> $brands */
function primo_name_has_brand(string $name, array $brands): bool
{
    foreach ($brands as $re) {
        if (preg_match($re, $name)) {
            return true;
        }
    }
    return false;
}

/** "Lenovo", "Asus or MSI" */
function primo_brand_label(array $brands): string
{
    $labels = array_keys($brands);
    $last = array_pop($labels);
    return $labels === [] ? (string) $last : implode(', ', $labels) . ' or ' . $last;
}

/** Plural shop words for a primo_match_category() result: "We don't have any Lenovo monitors". */
function primo_category_plural(?string $category): string
{
    $words = [
        'Laptops' => 'laptops', 'Desktop' => 'desktops', 'Audio' => 'audio products', 'Speaker' => 'speakers',
        'Cooling' => 'coolers', 'Accessories' => 'accessories', 'Keyboard' => 'keyboards', 'Mouse' => 'mice',
        'Printers and Scanners' => 'printers', 'GPU' => 'graphics cards', 'RAM' => 'RAM', 'Motherboard' => 'motherboards',
        'Processor' => 'processors', 'Storage' => 'storage drives', 'PSU' => 'power supplies', 'Monitor' => 'monitors',
        'Case' => 'PC cases',
    ];
    return $category === null ? 'products' : ($words[$category] ?? 'products');
}

/**
 * Shop Now filter (cat/sub/q in includes/client_shop_taxonomy.php) for each primo_match_category()
 * result. Desktop has none: Shop Now's "desktop" section lists displays, not mini PCs.
 *
 * @return array<string,array<string,string>>
 */
function primo_shop_filters(): array
{
    return [
        'Laptops' => ['cat' => 'laptops-mobile', 'sub' => 'laptops'],
        'Audio' => ['cat' => 'peripherals', 'sub' => 'headset'],
        'Speaker' => ['cat' => 'peripherals', 'sub' => 'speaker'],
        'Cooling' => ['cat' => 'component', 'sub' => 'cpu-cooling'],
        'Accessories' => ['cat' => 'accessories'],
        'Keyboard' => ['cat' => 'peripherals', 'sub' => 'keyboard'],
        'Mouse' => ['cat' => 'peripherals', 'sub' => 'mouse'],
        'Printers and Scanners' => ['cat' => 'peripherals', 'sub' => 'printer-scanner'],
        'GPU' => ['cat' => 'component', 'sub' => 'graphics-card'],
        'RAM' => ['cat' => 'component', 'sub' => 'memory'],
        'Motherboard' => ['cat' => 'component', 'sub' => 'motherboard'],
        'Processor' => ['cat' => 'component', 'q' => 'processor'],   // AMD, Intel and tray CPUs are three subs
        'Storage' => ['cat' => 'component', 'sub' => 'ssd'],
        'PSU' => ['cat' => 'component', 'sub' => 'power-supply'],
        'Monitor' => ['cat' => 'peripherals', 'sub' => 'monitor'],
        'Case' => ['cat' => 'component', 'sub' => 'pc-case'],
    ];
}

/**
 * The rows Shop Now shows for a filter, decided by the shop's own classifier (same checks as
 * shop.php), so "Showing 5 of the 9 laptops" and the Shop Now link always agree.
 *
 * @param list<array> $rows
 * @param array<string,string> $filter
 * @return list<array>
 */
function primo_shop_list_rows(array $rows, array $filter): array
{
    $out = [];
    foreach (ep_shop_attach_taxonomy($rows) as $p) {
        if (isset($filter['cat']) && $p['tax_parent'] !== $filter['cat']) {
            continue;
        }
        if (isset($filter['sub']) && $p['tax_sub'] !== $filter['sub']) {
            continue;
        }
        if (isset($filter['q'])) {
            $hay = mb_strtolower($p['name'] . ' ' . $p['brand'] . ' ' . $p['tax_parent_label'] . ' ' . $p['tax_sub_label']);
            if (!str_contains($hay, mb_strtolower($filter['q']))) {
                continue;
            }
        }
        $out[] = $p;
    }
    return $out;
}

/**
 * Shop Now address that opens the list Primo is talking about ("RAM", "Asus graphics cards"):
 * the category's Shop Now filter, plus the brand exactly as the shop classifies it (its brand
 * filter compares the shop's spelling).
 *
 * @param array<string,string> $brands
 * @param list<array{name:string,category:string}> $products products of that brand, to read the shop's brand from
 */
function primo_shop_url(?string $category, array $brands, array $products): string
{
    $params = $category !== null ? (primo_shop_filters()[$category] ?? []) : [];
    if ($brands !== [] && count($brands) === 1 && isset($products[0]['name'])) {
        $first = ['name' => $products[0]['name'], 'category' => $products[0]['category'] ?? ''];
        $info = ep_shop_classify_product($first);
        $brand = $info['brand'] !== '' ? $info['brand'] : ias_client_product_brand($first);
        if ($brand !== '') {
            $params['brand'] = $brand;
        }
    }
    return 'shop.php' . ($params !== [] ? '?' . http_build_query($params) : '');
}

/**
 * Listed products in the asked category (any category when null), best keyword match first.
 * With brands, only products whose name carries one of them. $total receives how many match in all,
 * so the reply can say "Showing 5 of the 9 laptops in stock".
 *
 * @param array<string,string> $brands
 * @return list<array>
 */
function primo_find_brand_products(PDO $db, string $message, array $brands, ?string $category, int $limit = 5, ?int &$total = null): array
{
    $params = [];
    $shopFilter = $category !== null ? (primo_shop_filters()[$category] ?? null) : null;
    $sql = 'SELECT p.id, p.name, p.description, p.price, p.stock, p.category, p.image, p.image_url
            FROM products p
            WHERE ' . ias_client_product_list_sql_condition('p');
    if ($category !== null && $shopFilter === null) {
        $sql .= ' AND ' . ias_category_in_sql('p.category', $category, $params);
    }
    $stmt = $db->prepare($sql . ' ORDER BY p.id DESC');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($shopFilter !== null) {
        $rows = primo_shop_list_rows($rows, $shopFilter);   // the same products Shop Now lists for it
    }
    if ($brands !== []) {
        $rows = array_values(array_filter($rows, static fn ($row) => primo_name_has_brand((string) $row['name'], $brands)));
    }
    $keywords = primo_search_keywords($message);
    if ($rows !== [] && $keywords !== []) {
        $rows = primo_rank_products($rows, $keywords);
    }
    $rows = ias_client_filter_products_for_display($rows);
    $total = count($rows);
    return primo_map_product_rows(array_slice($rows, 0, $limit));
}

/** "show all laptops", "lahat ng ram": the shopper wants the whole list, not the first few. */
function primo_wants_all(string $message): bool
{
    return (bool) preg_match('/\b(all|every|everything|lahat|whole|full\s+list|complete\s+list)\b/iu', $message);
}

/**
 * True when the message only names a category ("show all laptops", "do you have monitors"),
 * with no other detail such as a model or size that a keyword search should narrow on.
 */
function primo_is_category_listing(string $message): bool
{
    $filler = ['all', 'every', 'everything', 'lahat', 'whole', 'full', 'complete', 'list', 'see', 'category',
        'categories', 'ng', 'mga', 'meron', 'kayo', 'ba', 'po', 'ang', 'yung', 'nyo', 'inyo', 'sa', 'lang', 'only', 'there'];
    foreach (primo_search_keywords($message) as $word) {
        if (!in_array($word, $filler, true) && primo_match_category($word) === null) {
            return false;
        }
    }
    return true;
}

/**
 * Best-product, spec, description, and quality questions.
 * Price, stock, and description come from the live products table, so POS sales and stock-in show at once.
 * The warranty text comes from Oasis-Itemlist.csv (matched on MSKU) because products has no warranty column.
 * "Best" is the highest-priced in-stock item, unless the shopper asks for a budget pick or a price limit.
 */
function primo_is_itemlist_question(string $message): bool
{
    if (preg_match('/\bbrands?\b/i', $message)) {
        return false;
    }
    if (preg_match('/\b(order|refund|tracking|password)\b/i', $message)) {
        return false;
    }
    $asks = (bool) preg_match(
        '/\b(best|top|specs?|specifications?|description|quality|recommend|cheapest|most\s+affordable)\b/i',
        $message
    );
    $about = (bool) preg_match(
        '/\b(products?|items?|categor\w*|easypc|gpu|gpus|graphics|laptop|laptops|ram|memory|cpu|processor|ssd|hdd|monitor|keyboard|mouse|headset|headphone|earphones?|cooling|cooler|psu|motherboard|case|desktop|gaming)\b/i',
        $message
    );
    return $asks && $about;
}

/**
 * Warranty text per MSKU from the item list.
 *
 * @return array<string,string>
 */
function primo_itemlist_warranties(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $path = dirname(__DIR__, 2) . '/Oasis-Itemlist.csv';
    $handle = is_file($path) ? fopen($path, 'rb') : false;
    if ($handle === false) {
        return $map;
    }
    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        return $map;
    }
    $header = array_map(static fn ($col) => trim((string) $col), $header);
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
    }
    while (($data = fgetcsv($handle)) !== false) {
        if (!is_array($data) || count($data) < count($header)) {
            continue;
        }
        $row = array_combine($header, array_slice($data, 0, count($header)));
        if (!is_array($row)) {
            continue;
        }
        $sku = trim((string) ($row['MSKU'] ?? ''));
        $warranty = trim((string) ($row['Product/Warranty Type'] ?? ''));
        if ($sku !== '' && $warranty !== '') {
            $map[$sku] = $warranty;
        }
    }
    fclose($handle);
    return $map;
}

/**
 * Products the shop lists (seller and image set, same as Shop Now), in stock or not, with live price and stock.
 *
 * @return list<array{id:int,category:string,sku:string,name:string,description:string,warranty:string,price:float,stock:int,image:mixed,image_url:mixed}>
 */
function primo_itemlist_rows(PDO $db): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    $rows = [];
    $stmt = $db->query(
        "SELECT p.id, p.name, p.description, p.price, p.stock, p.category, p.sku, p.image, p.image_url
         FROM products p
         WHERE p.seller_id IS NOT NULL AND p.seller_id > 0
           AND p.image IS NOT NULL AND TRIM(p.image) <> ''
         ORDER BY p.id"
    );
    if (!$stmt) {
        return $rows;
    }
    $warranties = primo_itemlist_warranties();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($p['name'] ?? '')) ?? '');
        if ($name === '' || ias_client_product_image_url($p) === '') {
            continue;
        }
        $sku = trim((string) ($p['sku'] ?? ''));
        $rows[] = [
            'id' => (int) $p['id'],
            'category' => trim((string) ($p['category'] ?? '')),
            'sku' => $sku,
            'name' => $name,
            'description' => trim(preg_replace('/\s+/', ' ', (string) ($p['description'] ?? '')) ?? ''),
            'warranty' => $warranties[$sku] ?? '',
            'price' => (float) $p['price'],
            'stock' => max(0, (int) ($p['stock'] ?? 0)),
            'image' => $p['image'],
            'image_url' => $p['image_url'],
        ];
    }
    return $rows;
}

function primo_itemlist_topic(string $message): string
{
    if (preg_match('/\b(each|every|all)\b.{0,24}\bcategor/i', $message)) {
        return 'each';
    }
    $groups = [
        'gaming laptop' => 'gaming_laptop',
        'gaming laptops' => 'gaming_laptop',
        'graphics card' => 'gpu',
        'graphic card' => 'gpu',
        'power supply' => 'psu',
        'hard disk' => 'hdd',
        'headphones' => 'headset',
        'headphone' => 'headset',
        'earphones' => 'headset',
        'earphone' => 'headset',
        'headset' => 'headset',
        'headsets' => 'headset',
        'motherboard' => 'motherboard',
        'motherboards' => 'motherboard',
        'processor' => 'cpu',
        'processors' => 'cpu',
        'laptops' => 'laptop',
        'laptop' => 'laptop',
        'cooling' => 'cooling',
        'cooler' => 'cooling',
        'memory' => 'ram',
        'monitor' => 'monitor',
        'display' => 'monitor',
        'keyboard' => 'keyboard',
        'speaker' => 'speaker',
        'printer' => 'printer',
        'mini pc' => 'mini_pc',
        'desktop' => 'mini_pc',
        'graphics' => 'gpu',
        'gpu' => 'gpu',
        'gpus' => 'gpu',
        'rtx' => 'gpu',
        'gtx' => 'gpu',
        'ram' => 'ram',
        'cpu' => 'cpu',
        'ryzen' => 'cpu',
        'ssd' => 'ssd',
        'nvme' => 'ssd',
        'hdd' => 'hdd',
        'psu' => 'psu',
        'mouse' => 'mouse',
        'case' => 'case',
        'ups' => 'ups',
        'fan' => 'cooling',
    ];
    $found = 'catalog';
    $bestLen = 0;
    foreach ($groups as $needle => $topic) {
        if (!preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $message)) {
            continue;
        }
        $len = strlen($needle);
        if ($len > $bestLen) {
            $bestLen = $len;
            $found = $topic;
        }
    }
    return $found;
}

/**
 * Stored category names (current labels and legacy import names) per topic.
 *
 * @param list<array{category:string,name:string,description:string}> $rows
 * @return list<array>
 */
function primo_itemlist_filter(array $rows, string $topic): array
{
    $want = [
        'gpu' => ['graphics card', 'graphic card', 'gpu'],
        'ram' => ['memory', 'ram'],
        'cpu' => ['processor amd', 'processor intel', 'processor tray', 'processor'],
        'motherboard' => ['motherboard'],
        'ssd' => ['solid state drive', 'ssd'],
        'hdd' => ['hard disk', 'hdd'],
        'psu' => ['power supply', 'psu'],
        'cooling' => ['cpu cooling', 'chassis fan', 'cooling'],
        'case' => ['pc case', 'case'],
        'monitor' => ['monitor', 'display'],
        'keyboard' => ['keyboard', 'keyboard and mouse'],
        'mouse' => ['mouse', 'keyboard and mouse'],
        'speaker' => ['speaker'],
        'printer' => ['printer & scanner', 'printer and scanner', 'printers and scanners'],
        'mini_pc' => ['desktop', 'mini pc'],
        'ups' => ['ups & avr'],
        'headset' => ['headset', 'earphones', 'audio'],
        'laptop' => ['laptops', 'laptop'],
        'gaming_laptop' => ['laptops', 'laptop'],
    ];
    if (!isset($want[$topic])) {
        return [];
    }
    $cats = $want[$topic];
    $rows = array_values(array_filter($rows, static fn ($row) => in_array(strtolower($row['category']), $cats, true)));
    if ($topic === 'headset') {
        return array_values(array_filter($rows, static function ($row) {
            return strtolower($row['category']) !== 'audio'
                || (bool) preg_match('/headset|headphone|earphone|earbud/i', $row['name']);
        }));
    }
    if ($topic === 'gaming_laptop') {
        $gaming = array_values(array_filter($rows, static function ($row) {
            return (bool) preg_match('/gaming|rtx|gtx|geforce/i', $row['name'] . ' ' . $row['description']);
        }));
        return $gaming !== [] ? $gaming : $rows;
    }
    return $rows;
}

/**
 * What "best" means in this question: the top-end pick, the most affordable pick, or the top pick under a price.
 *
 * @return array{mode:string,max:?float}
 */
function primo_itemlist_preference(string $message): array
{
    $max = null;
    $amount = '(\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?)\s*(k)?';
    $hit = preg_match(
        '/\b(?:under|below|less\s+than|at\s+most|up\s+to|max(?:imum)?|within|not\s+more\s+than|hanggang|budget(?:\s+(?:of|is))?)\s*:?\s*(?:₱|php|p)?\s*' . $amount . '\b/iu',
        $message,
        $m
    ) || preg_match(
        // "20k budget", "₱15,000 or less": a bare number here could be a model number (RTX 4060), so it needs ₱ or k
        '/(?:(?:₱|php)\s*' . $amount . '|(\d+(?:\.\d+)?)\s*(k))\s*(?:budget|or\s+less|and\s+below|pababa)\b/iu',
        $message,
        $m
    );
    if ($hit) {
        $parts = array_values(array_filter(array_slice($m, 1), static fn ($v) => $v !== ''));
        $value = (float) str_replace(',', '', (string) ($parts[0] ?? '0'));
        if (isset($parts[1]) && strtolower((string) $parts[1]) === 'k') {
            $value *= 1000;
        }
        if ($value >= 100) {
            $max = $value;
        }
    }
    if ($max !== null) {
        return ['mode' => 'under', 'max' => $max];
    }
    $budget = (bool) preg_match(
        '/\b(budget|cheap|cheaper|cheapest|affordable|low[\s-]?cost|entry[\s-]?level|inexpensive|murang|mura|sulit|value\s+for\s+money)\b/iu',
        $message
    );
    return ['mode' => $budget ? 'budget' : 'top', 'max' => null];
}

/**
 * In-stock rows ranked for the preference. Falls back to out-of-stock listings only when nothing is on hand.
 *
 * @param list<array{price:float,stock:int}> $rows
 * @param array{mode:string,max:?float} $pref
 * @return array{picks:list<array>,none_under:bool}
 */
function primo_itemlist_pick(array $rows, array $pref, int $limit = 3): array
{
    $inStock = array_values(array_filter($rows, static fn ($row) => $row['stock'] > 0));
    $pool = $inStock !== [] ? $inStock : $rows;
    $noneUnder = false;
    if ($pref['max'] !== null && $inStock !== []) {
        $under = array_values(array_filter($inStock, static fn ($row) => $row['price'] <= $pref['max']));
        if ($under === []) {
            $noneUnder = true;
        } else {
            $pool = $under;
        }
    }
    $cheapFirst = $pref['mode'] === 'budget' || $noneUnder;
    usort($pool, static function ($a, $b) use ($cheapFirst) {
        if ($a['price'] != $b['price']) {
            return $cheapFirst ? $a['price'] <=> $b['price'] : $b['price'] <=> $a['price'];
        }
        return $b['stock'] <=> $a['stock'];
    });
    return ['picks' => array_slice($pool, 0, $limit), 'none_under' => $noneUnder];
}

function primo_peso(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

/**
 * @return array{reply:string,products:list<array>}
 */
function primo_itemlist_answer(PDO $db, string $message): array
{
    $rows = primo_itemlist_rows($db);
    if ($rows === []) {
        return [
            'reply' => 'I could not read the EasyPC catalog, so I cannot name a best product right now.',
            'products' => [],
        ];
    }
    $brands = primo_brands_in($db, $message);
    $brandNote = '';   // set when the asked brand has nothing here, so the other-brand picks are explained
    $topic = primo_itemlist_topic(primo_without_brands($message, $brands));
    $pref = primo_itemlist_preference($message);
    $limitText = $pref['max'] !== null ? primo_peso($pref['max']) : '';
    $labels = [
        'gpu' => 'graphics card',
        'ram' => 'RAM',
        'cpu' => 'processor',
        'motherboard' => 'motherboard',
        'ssd' => 'SSD',
        'hdd' => 'hard disk',
        'psu' => 'power supply',
        'cooling' => 'cooling',
        'case' => 'PC case',
        'monitor' => 'monitor',
        'keyboard' => 'keyboard',
        'mouse' => 'mouse',
        'speaker' => 'speaker',
        'printer' => 'printer',
        'mini_pc' => 'mini PC',
        'ups' => 'UPS',
        'headset' => 'headset',
        'laptop' => 'laptop',
        'gaming_laptop' => 'gaming laptop',
    ];

    if ($topic === 'each' || $topic === 'catalog') {
        if ($brands !== []) {
            $ofBrand = array_values(array_filter($rows, static fn ($row) => primo_name_has_brand($row['name'], $brands)));
            if ($ofBrand === []) {
                return [
                    'reply' => 'EasyPC has no ' . primo_brand_label($brands) . ' products listed right now. Try another brand, or open Shop Now to see everything in stock.',
                    'products' => [],
                ];
            }
            $rows = $ofBrand;
        }
        // "components for gaming", "parts for a build": PC parts only, not mice, tables or laptops
        $partsOnly = (bool) preg_match('/\b(components?|parts?|build|piyesa)\b/i', $message);
        $byCat = [];
        foreach ($rows as $row) {
            if ($row['category'] === '' || in_array(strtolower($row['category']), ['others', 'services'], true)) {
                continue;
            }
            if ($partsOnly && primo_compat_slot_for_category($row['category']) === null
                && !in_array(strtolower($row['category']), ['solid state drive', 'hard disk', 'chassis fan'], true)) {
                continue;
            }
            $byCat[$row['category']][] = $row;
        }
        ksort($byCat);
        $picks = [];
        foreach ($byCat as $group) {
            $pick = primo_itemlist_pick($group, $pref, 1);
            $best = $pick['picks'][0] ?? null;
            if ($best === null || $best['stock'] <= 0 || $pick['none_under']) {
                continue;
            }
            $picks[] = $best;
        }
        $what = $partsOnly ? 'PC part type' : 'category';
        if ($pref['mode'] === 'budget') {
            $intro = "Here is the most affordable in-stock product in each {$what} right now:";
        } elseif ($pref['mode'] === 'under') {
            $intro = "Here is the top in-stock product at or under {$limitText} in each {$what}:";
        } else {
            $intro = "Here is the top-end (highest-priced) in-stock product in each {$what} right now:";
        }
        if ($picks === []) {
            return [
                'reply' => "I couldn't find an in-stock product that matches. Try a higher price limit or one category, such as GPU or RAM.",
                'products' => [],
            ];
        }
        $note = $partsOnly
            ? 'Open Build a PC to check that the parts fit together, or ask about one part for its description and warranty.'
            : 'Ask about one category, such as GPU, laptop, or RAM, for its description and warranty.';
        return primo_cards_reply($intro, primo_map_product_rows($picks), $note);
    }

    $filtered = primo_itemlist_filter($rows, $topic);
    $label = $labels[$topic] ?? 'product';
    if ($filtered === []) {
        return [
            'reply' => "EasyPC has no {$label} listed right now. Ask for another category, such as GPU, laptop, or RAM.",
            'products' => [],
        ];
    }
    if ($brands !== []) {
        $ofBrand = array_values(array_filter($filtered, static fn ($row) => primo_name_has_brand($row['name'], $brands)));
        if ($ofBrand !== []) {
            $filtered = $ofBrand;
            $label = primo_brand_label($brands) . ' ' . $label;
        } else {
            $brandNote = 'EasyPC has no ' . primo_brand_label($brands) . " {$label} listed right now, so here are other brands. ";
        }
    }
    $pick = primo_itemlist_pick($filtered, $pref, 3);
    $picks = $pick['picks'];
    $best = $picks[0];
    // the name, price and stock are on the cards, so the text stays short and points at them
    $first = count($picks) > 1 ? 'the first one below' : 'the one below';

    if ($best['stock'] <= 0) {
        $lead = "EasyPC lists this {$label}, but none are in stock right now.";
        $outro = 'Ask me again later, or open Shop Now to see what is available.';
    } elseif ($pick['none_under']) {
        $lead = "There is no in-stock {$label} at or under {$limitText}. The most affordable one in stock is {$first}.";
        $outro = "Ask for a higher limit, like \"best {$label} under " . primo_peso(ceil($best['price'] / 1000) * 1000) . '".';
    } elseif ($pref['mode'] === 'budget') {
        $lead = "The most affordable in-stock {$label} right now is {$first}.";
        $outro = "Ask for the top-end {$label} if you want the highest specs.";
    } elseif ($pref['mode'] === 'under') {
        $lead = "The top in-stock {$label} at or under {$limitText} is {$first}.";
        $outro = "Ask for the top-end {$label} if you want the highest specs.";
    } else {
        $lead = "The top-end in-stock {$label} right now is {$first}: the highest-priced one, since higher-priced parts usually have higher specs.";
        $outro = "Want something cheaper? Ask for a budget {$label}, or one under a price, like \"best {$label} under 10k\".";
    }
    // the warranty is not on the cards; the description only when the shopper asks for it
    $warranty = rtrim(trim((string) ($best['warranty'] ?? '')), '.');
    $about = 'Warranty: ' . ($warranty !== '' ? $warranty : 'not listed') . '.';
    $desc = trim((string) ($best['description'] ?? ''));
    if ($desc !== '' && preg_match('/\b(specs?|specifications?|description|details?|about|features?|quality)\b/i', $message)) {
        $about .= ' ' . (mb_strlen($desc) > 160 ? rtrim(mb_substr($desc, 0, 157)) . '...' : $desc);
    }
    if (preg_match('/\bquality\b/i', $message)) {
        $about .= ' The catalog does not give a quality score; it gives the description, warranty, price, and stock.';
    }

    return primo_cards_reply($brandNote . $lead . "\n" . $about, primo_map_product_rows($picks), $outro);
}

/**
 * Compatibility questions. Uses the same tags as Build a PC (includes/pc_compatibility.php):
 * socket, RAM type, form factor, and wattage read from product names and descriptions.
 */
function primo_is_compat_question(string $message): bool
{
    return (bool) preg_match(
        '/\b(compatible|compatibility|incompatible|works?\s+(?:with|on|sa)|fits?\s+(?:in|on|with)|kasya|bagay\s+ba|pwede\s+ba\b.{0,60}\bsa)\b/iu',
        $message
    );
}

/** @return array<string,string> slot => regex alternation for words that name the slot */
function primo_compat_slot_words(): array
{
    return [
        'processor' => 'cpu|processor|ryzen|core\s+i[3579]|core\s+ultra|athlon',
        'motherboard' => 'motherboard|mobo|board|mainboard',
        'memory' => 'ram|memory|ddr[345]|sodimm|so-dimm',
        'case' => 'case|chassis|casing',
        'psu' => 'psu|power\s+supply',
        'gpu' => 'gpu|graphics\s+card|video\s+card|rtx|gtx|radeon',
        'cooler' => 'cooler|aio|heatsink',
    ];
}

/** @return list<string> slots the message names */
function primo_compat_mentioned(string $message): array
{
    $out = [];
    foreach (primo_compat_slot_words() as $slot => $words) {
        if (preg_match('/\b(?:' . $words . ')\b/i', $message)) {
            $out[] = $slot;
        }
    }
    return $out;
}

/** @return list<string> slots the shopper already owns ("my B450 motherboard") */
function primo_compat_owned(string $message): array
{
    $out = [];
    foreach (primo_compat_slot_words() as $slot => $words) {
        if (preg_match('/\b(?:my|aking)\s+(?:[\w\-]+\s+){0,3}?(?:' . $words . ')\b/i', $message)) {
            $out[] = $slot;
        }
    }
    return $out;
}

function primo_compat_slot_for_category(string $category): ?string
{
    $map = [
        'processor amd' => 'processor', 'processor intel' => 'processor', 'processor tray' => 'processor', 'processor' => 'processor',
        'motherboard' => 'motherboard',
        'memory' => 'memory', 'ram' => 'memory',
        'pc case' => 'case', 'case' => 'case',
        'power supply' => 'psu', 'psu' => 'psu',
        'graphics card' => 'gpu', 'graphic card' => 'gpu', 'gpu' => 'gpu',
        'cpu cooling' => 'cooler', 'cooling' => 'cooler',
    ];
    return $map[strtolower(trim($category))] ?? null;
}

function primo_compat_slot_label(string $slot): string
{
    $labels = [
        'processor' => 'processor', 'motherboard' => 'motherboard', 'memory' => 'RAM', 'case' => 'PC case',
        'psu' => 'power supply', 'gpu' => 'graphics card', 'cooler' => 'CPU cooler',
    ];
    return $labels[$slot] ?? $slot;
}

/** Socket from a typed CPU model: Ryzen 1000–5000 = AM4, 7000–9000 = AM5; Intel 10th–11th = LGA1200, 12th–14th = LGA1700. */
function primo_compat_cpu_socket(string $text): ?string
{
    $t = strtolower($text);
    if (preg_match('/\bryzen\s*[3579]\s*(?:pro\s*)?(\d)\d{3}/', $t, $m)) {
        $gen = (int) $m[1];
        return $gen >= 1 && $gen <= 5 ? 'AM4' : ($gen >= 7 ? 'AM5' : null);
    }
    if (preg_match('/\bcore\s+ultra\s*[3579]\s*2\d\d/', $t)) {
        return 'LGA1851';
    }
    if (preg_match('/\bi[3579][\s\-]*(1[0-4])\d{3}/', $t, $m)) {
        return (int) $m[1] >= 12 ? 'LGA1700' : 'LGA1200';
    }
    return null;
}

/**
 * Socket and RAM type from a typed motherboard chipset ("B450", "B550M").
 *
 * @return array{socket:string,ddr:?string,form:?string,chipset:string}|null
 */
function primo_compat_chipset(string $text): ?array
{
    $chipsets = [
        'AM4' => 'a320|b350|x370|b450|x470|a520|b550|x570',
        'AM5' => 'a620|b650e|b650|x670e|x670|b840|b850|x870e|x870',
        'LGA1200' => 'h410|b460|h470|z490|h510|b560|h570|z590',
        'LGA1700' => 'h610|b660|h670|z690|b760|z790',
        'LGA1851' => 'h810|b860|z890',
    ];
    $ddr = ['AM4' => 'DDR4', 'AM5' => 'DDR5', 'LGA1200' => 'DDR4', 'LGA1851' => 'DDR5'];
    foreach ($chipsets as $socket => $list) {
        if (preg_match('/\b(' . $list . ')(m)?(?:[\-\s]|\b)/i', $text, $m)) {
            return [
                'socket' => $socket,
                'ddr' => $ddr[$socket] ?? null,
                'form' => !empty($m[2]) ? 'MATX' : null,
                'chipset' => strtoupper($m[1] . ($m[2] ?? '')),
            ];
        }
    }
    return null;
}

/**
 * A shop product as a compatibility part.
 *
 * @return array{label:string,slot:string,tags:array,row:array}
 */
function primo_compat_from_row(array $row): array
{
    $slot = (string) $row['slot'];
    $tags = ep_pc_compat_tags($row['name'], $row['description'], $row['category']);
    if ($slot === 'processor' && $tags['socket'] === null) {
        $tags['socket'] = primo_compat_cpu_socket($row['name']);
    }
    if ($slot === 'motherboard' && ($tags['socket'] === null || $tags['ddr'] === null)) {
        $chip = primo_compat_chipset($row['name']);
        if ($chip !== null) {
            $tags['socket'] = $tags['socket'] ?? $chip['socket'];
            $tags['ddr'] = $tags['ddr'] ?? $chip['ddr'];
        }
    }
    return ['label' => $row['name'], 'slot' => $slot, 'tags' => $tags, 'row' => $row];
}

/**
 * Parts the shopper typed by spec instead of naming a shop product ("my B450 board", "DDR5 RAM", "650W PSU").
 *
 * @return array<string,array{label:string,slot:string,tags:array,row:null}>
 */
function primo_compat_typed_parts(string $message): array
{
    $text = ep_pc_compat_tags($message);
    $mentioned = primo_compat_mentioned($message);
    $blank = ['socket' => null, 'ddr' => null, 'form' => null, 'storage' => null, 'watts' => null, 'raw' => $text['raw']];
    $out = [];

    $cpuSocket = primo_compat_cpu_socket($message);
    if ($cpuSocket !== null && preg_match('/\b(ryzen\s*[3579][\s\w]{0,12}?\d{4}\w*|core\s+ultra\s*[3579]\s*2\d\d\w*|i[3579][\s\-]*1[0-4]\d{3}\w*)/i', $message, $m)) {
        $out['processor'] = ['label' => 'the ' . trim($m[1]), 'slot' => 'processor', 'tags' => ['socket' => $cpuSocket] + $blank, 'row' => null];
    }

    $chip = primo_compat_chipset($message);
    if ($chip !== null) {
        $tags = ['socket' => $chip['socket'], 'ddr' => $chip['ddr'], 'form' => $chip['form']] + $blank;
        $out['motherboard'] = ['label' => 'a ' . $chip['chipset'] . ' motherboard', 'slot' => 'motherboard', 'tags' => $tags, 'row' => null];
    } elseif ($text['socket'] !== null && (in_array('motherboard', $mentioned, true) || !isset($out['processor']))) {
        $out['motherboard'] = ['label' => 'an ' . $text['socket'] . ' motherboard', 'slot' => 'motherboard', 'tags' => ['socket' => $text['socket']] + $blank, 'row' => null];
    }

    if ($text['ddr'] !== null) {
        if (in_array('memory', $mentioned, true) && !preg_match('/\b(?:board|motherboard|mobo)\b[^.?!]{0,20}\b' . strtolower($text['ddr']) . '\b/i', $message)) {
            $out['memory'] = ['label' => $text['ddr'] . ' RAM', 'slot' => 'memory', 'tags' => ['ddr' => $text['ddr']] + $blank, 'row' => null];
        } elseif (isset($out['motherboard'])) {
            $out['motherboard']['tags']['ddr'] = $text['ddr'];
        }
    }

    if (preg_match('/\b((?:rtx|gtx|rx|arc)\s*[a-z]?\d{3,4}(?:\s*(?:ti|super|xt|xtx))?)\b/i', $message, $m)) {
        $out['gpu'] = ['label' => 'the ' . strtoupper(trim($m[1])), 'slot' => 'gpu', 'tags' => $blank, 'row' => null];
    }

    if ($text['watts'] !== null && in_array('psu', $mentioned, true)) {
        $out['psu'] = ['label' => 'a ' . $text['watts'] . ' W power supply', 'slot' => 'psu', 'tags' => ['watts' => $text['watts']] + $blank, 'row' => null];
    }

    if ($text['form'] !== null) {
        if (in_array('case', $mentioned, true)) {
            $out['case'] = ['label' => 'a ' . primo_compat_form_label($text['form']) . ' case', 'slot' => 'case', 'tags' => ['form' => $text['form']] + $blank, 'row' => null];
        } elseif (isset($out['motherboard'])) {
            $out['motherboard']['tags']['form'] = $text['form'];
        }
    }
    return $out;
}

/**
 * Model numbers in a product name ("b550m", "5600x", "4060"). Sizes, speeds and sockets describe a part but do not name it.
 *
 * @return list<string>
 */
function primo_compat_model_tokens(string $text): array
{
    $out = [];
    foreach (preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [] as $t) {
        if (strlen($t) < 3 || !preg_match('/\d/', $t)) {
            continue;
        }
        if (preg_match('/^(ddr\d|d\d|\d+(gb|tb|mb|mhz|ghz|w|hz|mm|cm|rpm|v|ms|inch|in)|\d+x\d+(gb)?|pcie\d*|gen\d+|m2|lga\d*|am\d|\d{1,2}th|20\d\d|\d{4}mhz)$/', $t)) {
            continue;
        }
        $out[$t] = true;
    }
    // PHP turns a numeric key like "5600" into int 5600; keep tokens as strings so === matches
    return array_map('strval', array_keys($out));
}

/**
 * The shop product the message names for each slot, by model number. A model number counts for one product only,
 * so "Ryzen 5 5600" is not also read as an RX 5600 graphics card.
 *
 * @param list<array> $parts shop rows with a 'slot'
 * @return array<string,array> slot => row (match_exact = a model number matched in full)
 */
function primo_compat_named_parts(array $parts, string $message): array
{
    $msgTokens = array_values(array_filter(
        preg_split('/[^a-z0-9]+/', strtolower($message)) ?: [],
        static fn ($t) => strlen($t) >= 3 && preg_match('/\d/', $t)
    ));
    if ($msgTokens === []) {
        return [];
    }
    $msgWords = array_flip(preg_split('/[^a-z0-9]+/', strtolower($message)) ?: []);
    $generic = [
        'socket', 'memory', 'motherboard', 'processor', 'black', 'white', 'gaming', 'wifi', 'with', 'cooler', 'cooling',
        'liquid', 'case', 'power', 'supply', 'graphics', 'card', 'videocard', 'edition', 'desktop', 'compatible', 'work',
        'will', 'this', 'that', 'board', 'heatsink', 'argb', 'micro', 'tower',
    ];
    $candidates = [];
    foreach ($parts as $row) {
        $score = 0.0;
        $used = [];
        $exact = false;
        foreach (primo_compat_model_tokens($row['name']) as $tok) {
            foreach ($msgTokens as $mt) {
                if ($mt === $tok) {
                    $score += 2;
                    $used[$mt] = true;
                    $exact = true;
                    break;
                }
                if (strlen($mt) >= 4 && str_starts_with($tok, $mt)) {
                    $score += 1;
                    $used[$mt] = true;
                    break;
                }
            }
        }
        if ($score <= 0) {
            // No model number: two distinctive name words, like "Teamgroup Vulcan" or "Hyper 212", still name the product
            $words = array_filter(preg_split('/[^a-z0-9]+/', strtolower($row['name'])) ?: [], static function ($w) use ($generic) {
                return strlen($w) >= 4 && !preg_match('/\d/', $w) && !in_array($w, $generic, true);
            });
            $hits = array_values(array_filter(array_unique($words), static fn ($w) => isset($msgWords[$w])));
            if (count($hits) < 2) {
                continue;
            }
            $score = 1.5;
            $used = array_flip($hits);
            $exact = true;
        }
        // Tie-break: the share of the product name the shopper typed ("MSI B550M-A PRO" beats "MSI B550M Pro VDH Wifi")
        $nameWords = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($row['name'])) ?: [], 'strlen'));
        if ($nameWords !== []) {
            $typed = count(array_filter($nameWords, static fn ($w) => isset($msgWords[$w])));
            $score += 0.5 * $typed / count($nameWords);
        }
        $candidates[] = ['score' => $score, 'used' => array_map('strval', array_keys($used)), 'exact' => $exact, 'row' => $row];
    }
    usort($candidates, static fn ($a, $b) => $b['score'] <=> $a['score']);
    $claimed = [];
    $out = [];
    foreach ($candidates as $c) {
        $slot = $c['row']['slot'];
        if (isset($out[$slot]) || array_diff($c['used'], $claimed) === []) {
            continue;
        }
        $out[$slot] = $c['row'] + ['match_exact' => $c['exact']];
        $claimed = array_merge($claimed, $c['used']);
    }
    return $out;
}

function primo_compat_form_label(string $form): string
{
    $labels = ['ITX' => 'Mini-ITX', 'MATX' => 'Micro-ATX', 'ATX' => 'ATX', 'EATX' => 'E-ATX'];
    return $labels[$form] ?? $form;
}

/**
 * Compare two parts with the rules Build a PC uses (includes/pc_compatibility.php).
 *
 * @param array{label:string,slot:string,tags:array} $a
 * @param array{label:string,slot:string,tags:array} $b
 * @return array{ok:bool,text:string}|null null when the product text does not give the spec needed
 */
function primo_compat_pair(array $a, array $b): ?array
{
    $p = [$a['slot'] => $a, $b['slot'] => $b];

    if (isset($p['processor'], $p['motherboard'])) {
        $cpu = $p['processor'];
        $mobo = $p['motherboard'];
        $s1 = $cpu['tags']['socket'] ?? null;
        $s2 = $mobo['tags']['socket'] ?? null;
        if (!$s1 || !$s2) {
            return null;
        }
        return $s1 === $s2
            ? ['ok' => true, 'text' => "{$cpu['label']} and {$mobo['label']} both use socket {$s1}."]
            : ['ok' => false, 'text' => "{$cpu['label']} uses socket {$s1}, but {$mobo['label']} is socket {$s2}."];
    }

    if (isset($p['motherboard'], $p['memory'])) {
        $mobo = $p['motherboard'];
        $ram = $p['memory'];
        if (preg_match('/\bso-?dimm\b/i', $ram['tags']['raw'] ?? '')) {
            return ['ok' => false, 'text' => "{$ram['label']} is SO-DIMM laptop memory. Desktop motherboards like {$mobo['label']} take full-size DIMM sticks."];
        }
        $d1 = $mobo['tags']['ddr'] ?? null;
        $d2 = $ram['tags']['ddr'] ?? null;
        if (!$d1 || !$d2) {
            return null;
        }
        return $d1 === $d2
            ? ['ok' => true, 'text' => "{$mobo['label']} takes {$d1} RAM, and {$ram['label']} is {$d2}."]
            : ['ok' => false, 'text' => "{$mobo['label']} takes {$d1} RAM, but {$ram['label']} is {$d2}."];
    }

    if (isset($p['processor'], $p['memory'])) {
        $cpu = $p['processor'];
        $ram = $p['memory'];
        $need = ep_pc_cpu_ddr($cpu['tags']);
        $have = $ram['tags']['ddr'] ?? null;
        if (!$need || !$have) {
            return null;
        }
        return $need === $have
            ? ['ok' => true, 'text' => "{$cpu['label']} uses {$need} RAM, and {$ram['label']} is {$have}."]
            : ['ok' => false, 'text' => "{$cpu['label']} needs {$need} RAM, but {$ram['label']} is {$have}."];
    }

    if (isset($p['case'], $p['motherboard'])) {
        $case = $p['case'];
        $mobo = $p['motherboard'];
        $rank = ['ITX' => 1, 'MATX' => 2, 'ATX' => 3, 'EATX' => 4];
        $cf = $case['tags']['form'] ?? null;
        $mf = $mobo['tags']['form'] ?? null;
        if (!isset($rank[$cf ?? ''], $rank[$mf ?? ''])) {
            return null;
        }
        return $rank[$cf] >= $rank[$mf]
            ? ['ok' => true, 'text' => "{$mobo['label']} (" . primo_compat_form_label($mf) . ") fits in {$case['label']} (" . primo_compat_form_label($cf) . ').']
            : ['ok' => false, 'text' => "{$mobo['label']} is " . primo_compat_form_label($mf) . ", too big for {$case['label']} (" . primo_compat_form_label($cf) . ').'];
    }

    if (isset($p['cooler']) && (isset($p['processor']) || isset($p['motherboard']))) {
        $cooler = $p['cooler'];
        $other = $p['processor'] ?? $p['motherboard'];
        $socket = $other['tags']['socket'] ?? null;
        $raw = (string) ($cooler['tags']['raw'] ?? '');
        if (!$socket || !preg_match('/\b(am[45]|lga\s*\d{3,4})\b/i', $raw)) {
            return null;
        }
        $pattern = str_starts_with($socket, 'LGA')
            ? '/\blga\b.{0,40}\b' . substr($socket, 3) . '\b/i'
            : '/\b' . strtolower($socket) . '\b/i';
        return preg_match($pattern, $raw)
            ? ['ok' => true, 'text' => "{$cooler['label']} lists support for socket {$socket}, which {$other['label']} uses."]
            : ['ok' => false, 'text' => "{$cooler['label']} does not list socket {$socket}, which {$other['label']} uses."];
    }

    if (isset($p['psu'], $p['gpu'])) {
        $psu = $p['psu'];
        $watts = $psu['tags']['watts'] ?? null;
        if (!$watts) {
            return null;
        }
        // Same per-part estimate as Build a PC: CPU 65, board 40, GPU 180, RAM 10, storage 15
        $estimate = 65 + 40 + 180 + 10 + 15;
        $name = $psu['row'] !== null ? "{$psu['label']} ({$watts} W)" : $psu['label'];
        return $watts >= $estimate
            ? ['ok' => true, 'text' => "{$name} covers the about {$estimate} W Build a PC estimates for a typical build with {$p['gpu']['label']}. Check the card's recommended PSU wattage too."]
            : ['ok' => false, 'text' => "{$name} is below the about {$estimate} W Build a PC estimates for a typical build with {$p['gpu']['label']}."];
    }

    return null;
}

/** What a single part needs, from its tags. */
function primo_compat_needs(array $part): string
{
    $t = $part['tags'];
    $needs = [];
    switch ($part['slot']) {
        case 'processor':
            if (!empty($t['socket'])) {
                $needs[] = 'a socket ' . $t['socket'] . ' motherboard';
            }
            if ($ddr = ep_pc_cpu_ddr($t)) {
                $needs[] = $ddr . ' RAM';
            }
            break;
        case 'motherboard':
            if (!empty($t['socket'])) {
                $needs[] = 'a socket ' . $t['socket'] . ' processor';
            }
            if (!empty($t['ddr'])) {
                $needs[] = $t['ddr'] . ' RAM';
            }
            if (!empty($t['form'])) {
                $needs[] = 'a case that fits ' . primo_compat_form_label($t['form']);
            }
            break;
        case 'memory':
            if (preg_match('/\bso-?dimm\b/i', $t['raw'] ?? '')) {
                return "{$part['label']} is SO-DIMM memory, made for laptops and mini PCs, not desktop motherboards.";
            }
            if (!empty($t['ddr'])) {
                $needs[] = 'a motherboard with ' . $t['ddr'] . ' slots';
            }
            break;
        case 'case':
            if (!empty($t['form'])) {
                $needs[] = 'a motherboard no bigger than ' . primo_compat_form_label($t['form']);
            }
            break;
        case 'gpu':
            return "{$part['label']} needs a power supply big enough for it. Build a PC estimates about 310 W for a typical build with one graphics card; check the card's recommended PSU wattage too.";
        case 'psu':
            if (!empty($t['watts'])) {
                $name = ($part['row'] ?? null) !== null ? "{$part['label']} ({$t['watts']} W)" : $part['label'];
                return $t['watts'] >= 310
                    ? "{$name} covers the about 310 W Build a PC estimates for a typical build with one graphics card."
                    : "{$name} is below the about 310 W Build a PC estimates for a typical build with one graphics card.";
            }
            break;
    }
    if ($needs === []) {
        return "I couldn't read the socket, RAM type, or size of {$part['label']} from the product details.";
    }
    return "{$part['label']} needs " . implode(' and ', $needs) . '.';
}

/**
 * Answer a compatibility question, or null when the message names no part Primo can read.
 *
 * @return array{reply:string,products:list<array>}|null
 */
function primo_compat_answer(PDO $db, string $message): ?array
{
    $shop = [];
    foreach (primo_itemlist_rows($db) as $row) {
        $slot = primo_compat_slot_for_category($row['category']);
        if ($slot !== null) {
            $row['slot'] = $slot;
            $shop[] = $row;
        }
    }

    $typed = primo_compat_typed_parts($message);
    $owned = primo_compat_owned($message);
    $mentioned = primo_compat_mentioned($message);
    $chosen = [];
    // "my B450 motherboard" is the shopper's own board, not a shop product with a similar name
    foreach ($typed as $slot => $part) {
        if (in_array($slot, $owned, true)) {
            $chosen[$slot] = $part;
        }
    }
    foreach (primo_compat_named_parts($shop, $message) as $slot => $row) {
        // "B550" only partly matches "B550M-A PRO": keep the typed chipset rather than pick one board for the shopper
        if (!isset($chosen[$slot]) && ($row['match_exact'] || !isset($typed[$slot]))) {
            $chosen[$slot] = primo_compat_from_row($row);
        }
    }
    // "will this work with ..." refers to the last product Primo showed
    $topic = trim((string) ($_SESSION['primo_ctx']['topic'] ?? ''));
    if ($topic !== '' && preg_match('/\b(this|that|it|ito|iyan|yan)\b/i', $message)) {
        foreach ($shop as $row) {
            if (strcasecmp($row['name'], $topic) !== 0 || isset($chosen[$row['slot']])) {
                continue;
            }
            // Only when the question names that part type ("this RAM") or pairs "this" with another part
            // ("will this work with my B550"); "does this work with Windows 11" is not a parts question
            $unfilled = array_diff($mentioned, array_keys($chosen));
            $pairedWith = $chosen !== [] || $typed !== [];
            if (in_array($row['slot'], $mentioned, true) || ($pairedWith && $unfilled === [])) {
                $chosen[$row['slot']] = primo_compat_from_row($row);
            }
            break;
        }
    }
    foreach ($typed as $slot => $part) {
        if (!isset($chosen[$slot])) {
            $chosen[$slot] = $part;
        }
    }
    if ($chosen === []) {
        return null;
    }

    $products = primo_map_product_rows(array_values(array_filter(array_map(static fn ($c) => $c['row'], $chosen))));
    $footer = 'I check socket, RAM type, size, and power from the product names and descriptions, like Build a PC does.';

    if (count($chosen) >= 2) {
        $ok = [];
        $bad = [];
        $list = array_values($chosen);
        for ($i = 0; $i < count($list); $i++) {
            for ($j = $i + 1; $j < count($list); $j++) {
                $r = primo_compat_pair($list[$i], $list[$j]);
                if ($r === null) {
                    continue;
                }
                if ($r['ok']) {
                    $ok[] = ucfirst($r['text']);
                } else {
                    $bad[] = ucfirst($r['text']);
                }
            }
        }
        if ($bad !== []) {
            $reply = "No, these parts don't work together:\n- " . implode("\n- ", $bad);
            if ($ok !== []) {
                $reply .= "\nWhat does match:\n- " . implode("\n- ", $ok);
            }
        } elseif ($ok !== []) {
            $reply = "Yes, these should work together:\n- " . implode("\n- ", $ok);
        } else {
            $reply = "I can't tell from the product details whether these match. Here is what I know:\n- "
                . implode("\n- ", array_map(static fn ($p) => ucfirst(primo_compat_needs($p)), $list))
                . "\nOpen Build a PC to check them in a full setup.";
        }
        return primo_cards_reply($reply, $products, $footer);
    }

    // One part: say what it needs and show in-stock parts that match it
    $part = reset($chosen);
    $partner = [
        'processor' => 'motherboard', 'motherboard' => 'processor', 'memory' => 'motherboard',
        'cooler' => 'processor', 'case' => 'motherboard', 'gpu' => 'psu', 'psu' => 'gpu',
    ];
    $target = null;
    foreach ($mentioned as $slot) {
        if ($slot !== $part['slot']) {
            $target = $slot;
            break;
        }
    }
    $target = $target ?? ($partner[$part['slot']] ?? null);

    $matches = [];
    if ($target !== null) {
        foreach ($shop as $row) {
            if ($row['slot'] !== $target || $row['stock'] <= 0) {
                continue;
            }
            $r = primo_compat_pair($part, primo_compat_from_row($row));
            if ($r !== null && $r['ok']) {
                $matches[] = $row;
            }
        }
        usort($matches, static fn ($a, $b) => $a['price'] <=> $b['price']);
        $matches = array_slice($matches, 0, 3);
    }

    $reply = ucfirst(primo_compat_needs($part));
    if ($matches !== []) {
        return primo_cards_reply(
            $reply . "\nIn-stock " . primo_compat_slot_label($target) . ' options that match, most affordable first:',
            primo_map_product_rows($matches),
            "Name a second part, like a motherboard or RAM, and I'll check the pair."
        );
    }
    if ($target !== null) {
        $reply .= "\nI couldn't confirm an in-stock " . primo_compat_slot_label($target) . ' that matches it. Open Build a PC to browse compatible parts.';
    }
    return primo_cards_reply($reply, $products, $footer);
}
