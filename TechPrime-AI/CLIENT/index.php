<?php
session_start();
// Correct paths to reach includes/backend from the CLIENT folder
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userName = $isLoggedIn ? h($_SESSION['name']) : "Guest";

// Categories list matching your buttons
$categories = ['Laptops', 'Desktop', 'Mobile', 'Cameras', 'Accessories'];

// Fetch Discover Products from Database (joining users to get the Seller's name)
$productQuery = "SELECT p.*, u.name AS seller_name
                 FROM products p
                 INNER JOIN users u ON p.seller_id = u.id
                 WHERE " . ias_client_product_list_sql_condition('p') . "
                 ORDER BY p.id DESC
                 LIMIT 40";
$productResult = $db->query($productQuery);
$displayProducts = ias_client_filter_products_for_display(
    $productResult ? $productResult->fetch_all(MYSQLI_ASSOC) : [],
    8
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IAS E-commerce Home</title>
    <link rel="stylesheet" href="../styles.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
</head>
<body>

    <header class="top-header full-width">
        <div class="logo" onclick="location.href='index.php'">IAS</div>
        <div class="search-wrap">
            <form action="search.php" method="GET">
                <input name="q" type="text" placeholder="Search products...">
                <button type="submit" class="search-icon">⌕</button>
            </form>
        </div>
        <div class="header-icons">
            <button id="cartBtn" class="icon-badge-btn" onclick="location.href='cart.php'">
                <span class="icon-main">🛒</span>
                <?php if(isset($_SESSION['cart']) && count($_SESSION['cart']) > 0): ?>
                    <span class="badge"><?php echo count($_SESSION['cart']); ?></span>
                <?php endif; ?>
            </button>
           <button id="profileBtn" class="icon-badge-btn profile-outline-btn" 
    onclick="location.href='<?php echo isset($_SESSION['user_id']) ? 'user_dashboard.php' : '../login.php'; ?>'">
    <span class="icon-main">👤</span> </button>
        </div>
    </header>

    <main class="dashboard-content">
        <div class="welcome-row">
            <span class="welcome-user-icon">◻</span>
            <h2 id="welcomeText">Welcome, <?php echo $userName; ?></h2>
        </div>

        <section class="categories-section">
            <h3>CATEGORIES</h3>
            <div class="category-scroll-wrap centered">
                <div id="categoriesRow" class="categories-row">
                    <?php foreach($categories as $cat): ?>
                        <button class="cat-pill" id="pill-<?php echo urlencode($cat); ?>" onclick="loadCategory('<?php echo h($cat); ?>', this)">
                            <?php echo $cat; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Inline category products panel (hidden until a category is picked) -->
            <div id="categoryPanel" style="display:none; margin-top: 20px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                    <h3 id="categoryPanelTitle" style="margin:0;"></h3>
                    <button onclick="closeCategory()" style="background:none;border:none;cursor:pointer;color:#888;font-size:13px;font-weight:700;">✕ Close</button>
                </div>
                <div id="categoryProducts" class="products-grid"></div>
            </div>
        </section>

        <section class="discover-section">
            <h3>DISCOVER</h3>
            <div class="products-grid">
                <?php if (!empty($displayProducts)): ?>
                    <?php foreach ($displayProducts as $p): ?>
                        <div class="product-card">
                            <div>
                                <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="product-img" alt="">
                                <div class="product-name"><?php echo h($p['name']); ?></div>
                                <div class="seller-tag">Store: <?php echo h($p['seller_name']); ?></div>
                                <div class="product-price">₱<?php echo number_format($p['price'], 2); ?></div>
                            </div>
                            
                            <form action="products.php" method="POST">
                                <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                <input type="hidden" name="add_to_cart" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <button type="submit" class="add-btn">Add to Cart</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No products available yet. Check back later!</div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <button id="messagesFloatBtn" class="messages-float-btn" onclick="location.href='messages.php'">
        <span class="chat-icon">💬</span> Messages
    </button>

    <footer class="full-width">
        &copy; 2026 IAS E-Commerce Client Center. All Rights Reserved.
    </footer>

<<<<<<< Updated upstream
    <script src="../includes/ui_alerts.js"></script>
    <script>
        // Check for "added to cart" message from products.php
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('added') && typeof IAS_UI !== 'undefined') {
                IAS_UI.alert('Added to cart!', 'success');
            }
        });

        // ── Category inline loader ──────────────────────────────────────────
        function loadCategory(cat, btn) {
            // Update active pill styling
            document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active-pill'));
            btn.classList.add('active-pill');

            const panel = document.getElementById('categoryPanel');
            const grid  = document.getElementById('categoryProducts');
            const title = document.getElementById('categoryPanelTitle');

            if (cat === 'all') {
                panel.style.display = 'none';
                return;
            }

            title.textContent = cat;
            grid.innerHTML = '<p style="color:#aaa;padding:20px;">Loading...</p>';
            panel.style.display = 'block';
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

            fetch('fetch_category.php?type=' + encodeURIComponent(cat))
                .then(r => r.json())
                .then(products => {
                    if (!products.length) {
                        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#999;"><h4>No products in ' + cat + ' yet.</h4><p>Check back later for new arrivals!</p></div>';
                        return;
                    }
                    grid.innerHTML = products.map(p => `
                        <div class="product-card">
                            <div>
                                <img src="${p.image || 'https://via.placeholder.com/200'}" class="product-img" style="width:100%;height:180px;object-fit:cover;border-radius:8px;">
                                <div class="product-name" style="font-weight:700;margin-top:10px;font-size:15px;height:40px;overflow:hidden;">${p.name}</div>
                                <div class="seller-tag" style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px;">Store: ${p.seller_name}</div>
                                <div class="product-price" style="color:#0998a8;font-weight:800;margin:5px 0;font-size:1.1rem;">₱${parseFloat(p.price).toLocaleString('en-PH', {minimumFractionDigits:2})}</div>
                            </div>
                            <form action="products.php" method="POST">
                                <input type="hidden" name="product_id" value="${p.id}">
                                <input type="hidden" name="add_to_cart" value="1">
                                <input type="hidden" name="csrf_token" value="${p.csrf}">
                                <button type="submit" class="add-btn" style="width:100%;background:#0998a8;color:white;border:none;padding:10px;border-radius:8px;font-weight:700;cursor:pointer;">Add to Cart</button>
                            </form>
                        </div>`).join('');
                })
                .catch(() => {
                    grid.innerHTML = '<p style="color:#e74c3c;padding:20px;">Failed to load products. Please try again.</p>';
                });
        }

        function closeCategory() {
            document.getElementById('categoryPanel').style.display = 'none';
            document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active-pill'));
            document.getElementById('pill-all').classList.add('active-pill');
        }
    </script>
=======
<?php ias_alert_footer(); ?>
>>>>>>> Stashed changes
</body>
</html>