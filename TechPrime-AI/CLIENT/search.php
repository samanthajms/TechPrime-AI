<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';

$db = getDbConnection();
if (is_file(__DIR__ . '/../includes/client_shop_taxonomy.php')) {
    require_once __DIR__ . '/../includes/client_shop_taxonomy.php';
}

$query       = isset($_GET['q']) ? trim($_GET['q']) : '';
$searchQuery = $query;
$isLoggedIn  = isset($_SESSION['user_id']);
$activePage  = '';
$pageTitle   = 'Search Results';
$peripheralCategories = ['Mobile', 'Cameras', 'Accessories'];

$displayProducts = [];
if (!empty($query)) {
    $searchTerm = '%' . $query . '%';
    $vis = ias_client_product_list_sql_condition('p');
    $stmt = $db->prepare(
        "SELECT p.*, u.name as seller_name
         FROM products p
         JOIN users u ON p.seller_id = u.id
         WHERE (p.name ILIKE ? OR p.description ILIKE ?) AND {$vis}
         ORDER BY p.id DESC
         LIMIT 48"
    );
    $stmt->execute([$searchTerm, $searchTerm]);
    $displayProducts = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    if (function_exists('ep_shop_classify_product') && count($displayProducts) < 48) {
        $qLower = mb_strtolower($query);
        $seen = [];
        foreach ($displayProducts as $row) {
            $seen[(int)$row['id']] = true;
        }
        $more = $db->query(
            "SELECT p.*, u.name as seller_name
             FROM products p
             JOIN users u ON p.seller_id = u.id
             WHERE {$vis}
             ORDER BY p.id DESC
             LIMIT 400"
        );
        if ($more) {
            while ($p = $more->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int)$p['id'];
                if (isset($seen[$pid])) {
                    continue;
                }
                $tax = ep_shop_classify_product($p);
                $labels = [
                    (string)($tax['parent_label'] ?? ''),
                    (string)($tax['sub_label'] ?? ''),
                ];
                $hit = false;
                foreach ($labels as $label) {
                    $ll = mb_strtolower(trim($label));
                    if ($ll !== '' && ($ll === $qLower || (mb_strlen($qLower) >= 4 && str_starts_with($ll, $qLower)))) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                $seen[$pid] = true;
                $displayProducts[] = $p;
                if (count($displayProducts) >= 48) {
                    break;
                }
            }
        }
    }
}
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<?php
$searchCsrf = generateCsrfToken();
$searchReturn = 'search.php?q=' . rawurlencode($query);
$searchCount = count($displayProducts);
?>
<main class="ep-main sf-main" id="main">
    <?php echo sf_page_hero([
        'crumbs' => [['Home', 'index.php'], ['Shop', 'shop.php'], ['Search', '']],
        'eyebrow' => 'Search',
        'title' => $query !== '' ? 'Results for “' . $query . '”' : 'Search the store',
        'sub' => $query !== ''
            ? ($searchCount > 0
                ? number_format($searchCount) . ' product' . ($searchCount === 1 ? '' : 's') . ' found' . ($searchCount >= 48 ? ' (showing the first 48)' : '') . '.'
                : 'No products matched your search.')
            : 'Type a product, brand or category in the search bar above.',
    ]); ?>

    <div class="sf-wrap sf-body">
        <?php if (!empty($displayProducts)): ?>
            <ul class="sf-grid">
                <?php foreach ($displayProducts as $p): ?>
                    <li><?php echo sf_product_card($p, $searchReturn, $searchCsrf); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php elseif ($query !== ''): ?>
            <?php echo sf_empty_state('fa-search', 'No products found for “' . $query . '”',
                'Check the spelling, try a shorter or more general word, or browse the catalog by category.',
                '<a href="shop.php" class="sf-btn sf-btn-primary">Browse all products</a>'
                . '<a href="build_a_pc.php" class="sf-btn sf-btn-outline">Build a PC</a>'); ?>
        <?php else: ?>
            <?php echo sf_empty_state('fa-search', 'What are you looking for?',
                'Search by product name, brand or category, for example “SSD”, “Ryzen” or “keyboard”.',
                '<a href="shop.php" class="sf-btn sf-btn-primary">Browse all products</a>'); ?>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/ep_footer.php'; ?>