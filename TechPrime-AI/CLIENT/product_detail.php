<?php
/**
 * product_detail.php — Full product page for the Client storefront.
 * Included by products.php when ?id= is given; expects $db and $viewId.
 */
if (!isset($db, $viewId)) {
    header('Location: products.php');
    exit;
}

require_once __DIR__ . '/../includes/client_shop_taxonomy.php';
ep_ensure_session_wishlist($db);

$vis = ias_client_product_list_sql_condition('p');
$one = $db->prepare(
    "SELECT p.*, u.name AS seller_name FROM products p
     INNER JOIN users u ON p.seller_id = u.id
     WHERE p.id = ? AND {$vis}
     LIMIT 1"
);
$one->execute([$viewId]);
$product = $one->fetch(PDO::FETCH_ASSOC) ?: null;
if ($product && ias_client_product_image_url($product) === '') {
    $product = null;
}

$isLoggedIn  = isset($_SESSION['user_id']);
$activePage  = 'shop';
$searchQuery = '';
$bodyClass   = 'ep-pd-page-body';

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product not available';
    include __DIR__ . '/ep_header.php';
    ?>
<main class="ep-main ep-pd-main">
    <div class="ep-pd-missing">
        <i class="fas fa-box-open" aria-hidden="true"></i>
        <h1>This product is not available</h1>
        <p>It may be out of stock or no longer listed in our catalog.</p>
        <a href="shop.php" class="ep-btn ep-btn-primary">Continue shopping</a>
    </div>
</main>
<?php
    include __DIR__ . '/ep_footer.php';
    exit;
}

$pid   = (int)$product['id'];
$tax   = ep_shop_classify_product($product);
$brand = $tax['brand'] !== '' ? $tax['brand'] : ias_client_product_brand($product);
$parentLabel = (string)$tax['parent_label'];
$subLabel    = (string)$tax['sub_label'];
$stock = (int)($product['stock'] ?? 0);
$price = (float)$product['price'];
$image = ias_client_product_image_url($product);
$sku   = trim((string)($product['sku'] ?? ''));
$inWishlist = ep_wishlist_has($pid);
$returnTo = 'products.php?id=' . $pid;
$csrf = generateCsrfToken();

// Description: "Label: value" lines become spec rows, the rest stays as overview prose.
$descParas = [];
$specRows = [];
$descText = str_replace("\r\n", "\n", trim((string)($product['description'] ?? '')));
foreach (preg_split('/\n\s*\n/', $descText) as $para) {
    $prose = [];
    foreach (explode("\n", trim($para)) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^([A-Za-z][A-Za-z0-9 \/&().+\-]{1,40}):\s*(.+)$/', $line, $m)) {
            $specRows[] = [trim($m[1]), rtrim(trim($m[2]), '.')];
        } else {
            $prose[] = $line;
        }
    }
    if ($prose) {
        $descParas[] = implode("\n", $prose);
    }
}
$warranty = '';
foreach ($specRows as [$label, $value]) {
    if (stripos($label, 'warranty') !== false) {
        $warranty = $value;
        break;
    }
}
$summary = $descParas[0] ?? '';

$specTable = [];
if ($brand !== '') $specTable[] = ['Brand', $brand];
if ($sku !== '') $specTable[] = ['Item Code', $sku];
$specTable[] = ['Category', $parentLabel];
if ($subLabel !== '' && $subLabel !== $parentLabel) $specTable[] = ['Type', $subLabel];
foreach ($specRows as $row) {
    $specTable[] = $row;
}
$specTable[] = ['Availability', $stock > 0 ? 'In stock' : 'Out of stock'];

// Variants: listed products sharing the name before the last comma (e.g. "…, Black" / "…, White").
$variants = [];
if (preg_match('/^(.{10,}),\s*([^,]{1,24})$/u', (string)$product['name'], $vm) && str_word_count($vm[2]) <= 3) {
    $stem = $vm[1];
    $vs = $db->prepare(
        "SELECT p.id, p.name, p.image, p.image_url FROM products p
         WHERE {$vis} AND p.name LIKE ? ORDER BY p.name"
    );
    $vs->execute([str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $stem) . ', %']);
    foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $label = trim(substr((string)$v['name'], strlen($stem) + 1));
        if (strpos($label, ',') !== false || ias_client_product_image_url($v) === '') {
            continue;
        }
        $variants[] = ['id' => (int)$v['id'], 'label' => $label, 'image' => ias_client_product_image_url($v)];
    }
    if (count($variants) < 2) {
        $variants = [];
    }
}

// Reviews (table may be empty or missing on older databases).
$reviewAvg = null;
$reviewCount = 0;
$reviews = [];
try {
    $rs = $db->prepare(
        'SELECT r.rating, r.comment, r.seller_reply, r.created_at, u.name AS user_name
         FROM reviews r LEFT JOIN users u ON u.id = r.user_id
         WHERE r.product_id = ? ORDER BY r.created_at DESC'
    );
    $rs->execute([$pid]);
    $reviews = $rs->fetchAll(PDO::FETCH_ASSOC);
    $reviewCount = count($reviews);
    if ($reviewCount > 0) {
        $reviewAvg = array_sum(array_map(fn($r) => (int)$r['rating'], $reviews)) / $reviewCount;
    }
} catch (Throwable $e) {
    $reviews = [];
}

// Related: same sub-category first, then same parent category.
$related = [];
$rel = $db->prepare(
    "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url
     FROM products p WHERE {$vis} AND p.id <> ? ORDER BY p.id DESC"
);
$rel->execute([$pid]);
$sameSub = [];
$sameParent = [];
foreach (ep_shop_attach_taxonomy(ias_client_filter_products_for_display($rel->fetchAll(PDO::FETCH_ASSOC))) as $r) {
    if ($r['tax_parent'] !== $tax['parent']) {
        continue;
    }
    if ($tax['sub'] !== '' && $r['tax_sub'] === $tax['sub']) {
        $sameSub[] = $r;
    } else {
        $sameParent[] = $r;
    }
}
$related = array_slice(array_merge($sameSub, $sameParent), 0, 10);

$pageTitle = (string)$product['name'];
$stars = function (?float $avg): string {
    $out = '';
    $rounded = $avg === null ? 0 : (int)round($avg);
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<i class="' . ($i <= $rounded ? 'fas' : 'far') . ' fa-star" aria-hidden="true"></i>';
    }
    return $out;
};

include __DIR__ . '/ep_header.php';
?>

<main class="ep-main ep-pd-main">
    <nav class="ep-pd-crumbs" aria-label="Breadcrumb">
        <a href="index.php">Home</a>
        <i class="fas fa-chevron-right" aria-hidden="true"></i>
        <a href="shop.php">Shop</a>
        <i class="fas fa-chevron-right" aria-hidden="true"></i>
        <a href="<?php echo h(ep_shop_url(['cat' => $tax['parent']], [])); ?>"><?php echo h($parentLabel); ?></a>
        <?php if ($tax['sub'] !== '' && $subLabel !== ''): ?>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="<?php echo h(ep_shop_url(['cat' => $tax['parent'], 'sub' => $tax['sub']], [])); ?>"><?php echo h($subLabel); ?></a>
        <?php endif; ?>
        <i class="fas fa-chevron-right" aria-hidden="true"></i>
        <span aria-current="page"><?php echo h($product['name']); ?></span>
    </nav>

    <section class="ep-pd-hero">
        <div class="ep-pd-gallery">
            <div class="ep-pd-stage" id="epPdStage">
                <img src="<?php echo h($image); ?>" alt="<?php echo h($product['name']); ?>" id="epPdImage">
                <span class="ep-pd-zoom-hint" aria-hidden="true"><i class="fas fa-search-plus"></i> Hover to zoom</span>
            </div>
        </div>

        <div class="ep-pd-info">
            <?php if ($brand !== ''): ?>
                <a class="ep-pd-brand" href="<?php echo h(ep_shop_url(['brand' => $brand], [])); ?>"><?php echo h($brand); ?></a>
            <?php endif; ?>
            <h1 class="ep-pd-title"><?php echo h($product['name']); ?></h1>

            <div class="ep-pd-meta">
                <?php if ($sku !== ''): ?>
                    <span>Item code: <strong><?php echo h($sku); ?></strong></span>
                <?php endif; ?>
                <a href="#epPdTabs" class="ep-pd-rating" data-pd-tab-link="reviews">
                    <span class="ep-pd-stars"><?php echo $stars($reviewAvg); ?></span>
                    <?php echo $reviewCount > 0
                        ? h(number_format($reviewAvg, 1) . ' (' . $reviewCount . ' review' . ($reviewCount === 1 ? '' : 's') . ')')
                        : 'No reviews yet'; ?>
                </a>
            </div>

            <?php if ($summary !== ''): ?>
                <p class="ep-pd-summary"><?php echo nl2br(h($summary)); ?></p>
                <a href="#epPdTabs" class="ep-pd-more" data-pd-tab-link="overview">See full details <i class="fas fa-angle-down" aria-hidden="true"></i></a>
            <?php endif; ?>

            <div class="ep-pd-buybox">
                <div class="ep-pd-price-row">
                    <div class="ep-pd-price">₱<?php echo number_format($price, 2); ?></div>
                    <div class="ep-pd-vat">VAT inclusive</div>
                </div>

                <?php if ($stock <= 0): ?>
                    <div class="ep-pd-stock is-out"><i class="fas fa-times-circle" aria-hidden="true"></i> Out of stock</div>
                <?php elseif ($stock <= 5): ?>
                    <div class="ep-pd-stock is-low"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> Only <?php echo $stock; ?> left in stock — order soon</div>
                <?php else: ?>
                    <div class="ep-pd-stock"><i class="fas fa-check-circle" aria-hidden="true"></i> In stock · <?php echo $stock; ?> available</div>
                <?php endif; ?>

                <?php if ($variants): ?>
                    <div class="ep-pd-variants">
                        <div class="ep-pd-label">Variant: <strong><?php
                            foreach ($variants as $v) { if ($v['id'] === $pid) { echo h($v['label']); } }
                        ?></strong></div>
                        <div class="ep-pd-variant-list">
                            <?php foreach ($variants as $v): ?>
                                <a href="products.php?id=<?php echo $v['id']; ?>"
                                   class="ep-pd-variant<?php echo $v['id'] === $pid ? ' is-active' : ''; ?>"
                                   <?php echo $v['id'] === $pid ? 'aria-current="true"' : ''; ?>>
                                    <img src="<?php echo h($v['image']); ?>" alt="" loading="lazy">
                                    <span><?php echo h($v['label']); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="products.php" class="ep-pd-cart-form" id="epPdCartForm">
                    <input type="hidden" name="product_id" value="<?php echo $pid; ?>">
                    <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

                    <div class="ep-pd-label">Quantity</div>
                    <div class="ep-pd-qty-row">
                        <div class="ep-pd-qty">
                            <button type="button" data-pd-qty="-1" aria-label="Decrease quantity"><i class="fas fa-minus" aria-hidden="true"></i></button>
                            <input type="number" name="quantity" id="epPdQty" value="1" min="1"
                                   max="<?php echo max(1, $stock); ?>" inputmode="numeric" aria-label="Quantity"
                                   <?php echo $stock <= 0 ? 'disabled' : ''; ?>>
                            <button type="button" data-pd-qty="1" aria-label="Increase quantity"><i class="fas fa-plus" aria-hidden="true"></i></button>
                        </div>
                        <span class="ep-pd-qty-note">Max <?php echo max(0, $stock); ?> per order</span>
                    </div>

                    <div class="ep-pd-actions">
                        <button type="submit" name="add_to_cart" value="1" class="ep-pd-btn ep-pd-btn-cart" id="epPdAddCart"
                                <?php echo $stock <= 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-cart-plus" aria-hidden="true"></i> Add to Cart
                        </button>
                        <button type="submit" name="buy_now" value="1" class="ep-pd-btn ep-pd-btn-buy"
                                <?php echo $stock <= 0 ? 'disabled' : ''; ?>>
                            Buy Now
                        </button>
                    </div>
                </form>

                <form method="POST" action="wishlist.php" class="ep-pd-wish-form" id="epPdWishForm">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="product_id" value="<?php echo $pid; ?>">
                    <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">
                    <button type="submit" name="toggle_wishlist" value="1"
                            class="ep-pd-wish<?php echo $inWishlist ? ' is-on' : ''; ?>" id="epPdWish"
                            aria-pressed="<?php echo $inWishlist ? 'true' : 'false'; ?>">
                        <i class="<?php echo $inWishlist ? 'fas' : 'far'; ?> fa-heart" aria-hidden="true"></i>
                        <span><?php echo $inWishlist ? 'Saved to Wishlist' : 'Add to Wishlist'; ?></span>
                    </button>
                </form>
            </div>

            <ul class="ep-pd-assurance">
                <li><i class="fas fa-store" aria-hidden="true"></i>
                    <div><strong>Sold by EasyPC One Oasis</strong><span>Rosario, Pasig City</span></div></li>
                <li><i class="fas fa-shield-alt" aria-hidden="true"></i>
                    <div><strong>Warranty</strong><span><?php echo h($warranty !== '' ? $warranty : 'Standard store warranty'); ?></span></div></li>
                <li><i class="fas fa-money-bill-wave" aria-hidden="true"></i>
                    <div><strong>Flexible payment</strong><span>Cash on Delivery or online payment</span></div></li>
                <li><i class="fas fa-lock" aria-hidden="true"></i>
                    <div><strong>Secure checkout</strong><span>Your order and details are protected</span></div></li>
            </ul>
        </div>
    </section>

    <section class="ep-pd-details" id="epPdTabs">
        <div class="ep-pd-tabs" role="tablist" aria-label="Product information">
            <button type="button" role="tab" id="epPdTab-overview" aria-controls="epPdPanel-overview" aria-selected="true" data-pd-tab="overview">Overview</button>
            <button type="button" role="tab" id="epPdTab-specs" aria-controls="epPdPanel-specs" aria-selected="false" data-pd-tab="specs" tabindex="-1">Specifications</button>
            <button type="button" role="tab" id="epPdTab-reviews" aria-controls="epPdPanel-reviews" aria-selected="false" data-pd-tab="reviews" tabindex="-1">Reviews (<?php echo $reviewCount; ?>)</button>
        </div>

        <div class="ep-pd-panel" role="tabpanel" id="epPdPanel-overview" aria-labelledby="epPdTab-overview">
            <h2>About this product</h2>
            <?php if ($descParas): ?>
                <?php foreach ($descParas as $para): ?>
                    <p><?php echo nl2br(h($para)); ?></p>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="ep-pd-muted">No description has been added for this product yet.</p>
            <?php endif; ?>
        </div>

        <div class="ep-pd-panel" role="tabpanel" id="epPdPanel-specs" aria-labelledby="epPdTab-specs" hidden>
            <h2>Specifications</h2>
            <table class="ep-pd-spec-table">
                <tbody>
                    <?php foreach ($specTable as [$label, $value]): ?>
                        <tr><th scope="row"><?php echo h($label); ?></th><td><?php echo h($value); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="ep-pd-panel" role="tabpanel" id="epPdPanel-reviews" aria-labelledby="epPdTab-reviews" hidden>
            <h2>Customer reviews</h2>
            <?php if ($reviewCount > 0): ?>
                <div class="ep-pd-review-summary">
                    <div class="ep-pd-review-score"><?php echo number_format($reviewAvg, 1); ?></div>
                    <div>
                        <div class="ep-pd-stars"><?php echo $stars($reviewAvg); ?></div>
                        <div class="ep-pd-muted">Based on <?php echo $reviewCount; ?> review<?php echo $reviewCount === 1 ? '' : 's'; ?></div>
                    </div>
                </div>
                <ul class="ep-pd-review-list">
                    <?php foreach ($reviews as $r): ?>
                        <li>
                            <div class="ep-pd-review-head">
                                <strong><?php echo h($r['user_name'] ?: 'Customer'); ?></strong>
                                <span class="ep-pd-stars"><?php echo $stars((float)$r['rating']); ?></span>
                                <?php if (!empty($r['created_at'])): ?>
                                    <span class="ep-pd-muted"><?php echo h(date('M j, Y', strtotime($r['created_at'] . ' UTC'))); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (trim((string)$r['comment']) !== ''): ?>
                                <p><?php echo nl2br(h($r['comment'])); ?></p>
                            <?php endif; ?>
                            <?php if (trim((string)$r['seller_reply']) !== ''): ?>
                                <div class="ep-pd-review-reply"><strong>EasyPC replied:</strong> <?php echo nl2br(h($r['seller_reply'])); ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="ep-pd-review-empty">
                    <span class="ep-pd-stars"><?php echo $stars(null); ?></span>
                    <p>No reviews yet for this product.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($related): ?>
        <section class="ep-section ep-featured-section ep-pd-related">
            <div class="ep-featured-head">
                <div>
                    <p class="ep-featured-kicker">You may also like</p>
                    <h3>Related Products</h3>
                </div>
            </div>
            <div class="ep-carousel-wrap">
                <button class="ep-arrow ep-arrow-left" onclick="epScroll('epPdRelatedRow', -1)" aria-label="Scroll left"><i class="fas fa-arrow-left"></i></button>
                <div class="ep-carousel" id="epPdRelatedRow">
                    <?php foreach ($related as $p): ?>
                        <div class="ep-product-card ep-featured-card">
                            <div class="ep-featured-card-media">
                                <a href="products.php?id=<?php echo (int)$p['id']; ?>">
                                    <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="ep-product-img" alt="<?php echo h($p['name']); ?>" loading="lazy" decoding="async">
                                </a>
                            </div>
                            <div class="ep-featured-card-body">
                                <a class="ep-product-name" href="products.php?id=<?php echo (int)$p['id']; ?>"><?php echo h($p['name']); ?></a>
                                <div class="ep-product-cat"><?php echo h($p['tax_sub_label'] ?: $p['tax_parent_label']); ?></div>
                                <div class="ep-product-price">₱<?php echo number_format((float)$p['price'], 2); ?></div>
                                <div class="ep-card-actions">
                                    <form action="products.php" method="POST" class="ep-buy-form">
                                        <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                        <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                        <button type="submit" name="add_to_cart" value="1" class="ep-cart-icon" title="Add to cart"><i class="fas fa-shopping-cart"></i></button>
                                        <button type="submit" name="buy_now" value="1" class="ep-buy-btn">BUY NOW</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="ep-arrow ep-arrow-right" onclick="epScroll('epPdRelatedRow', 1)" aria-label="Scroll right"><i class="fas fa-arrow-right"></i></button>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php
$extraScripts = <<<'JS'
<script>
(function () {
    // Image zoom: follow the pointer while hovering the stage.
    var stage = document.getElementById('epPdStage');
    var img = document.getElementById('epPdImage');
    if (stage && img && window.matchMedia('(hover: hover)').matches) {
        stage.addEventListener('mousemove', function (e) {
            var r = stage.getBoundingClientRect();
            img.style.transformOrigin = ((e.clientX - r.left) / r.width * 100) + '% ' + ((e.clientY - r.top) / r.height * 100) + '%';
            stage.classList.add('is-zoomed');
        });
        stage.addEventListener('mouseleave', function () { stage.classList.remove('is-zoomed'); });
    }

    // Quantity stepper.
    var qty = document.getElementById('epPdQty');
    function clampQty() {
        if (!qty) return 1;
        var max = parseInt(qty.max, 10) || 1;
        var v = parseInt(qty.value, 10);
        if (!v || v < 1) v = 1;
        if (v > max) v = max;
        qty.value = v;
        return v;
    }
    document.querySelectorAll('[data-pd-qty]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!qty || qty.disabled) return;
            qty.value = (parseInt(qty.value, 10) || 1) + parseInt(btn.getAttribute('data-pd-qty'), 10);
            clampQty();
        });
    });
    if (qty) qty.addEventListener('change', clampQty);

    // Tabs.
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-pd-tab]'));
    function showTab(name, focus) {
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-pd-tab') === name;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
            if (on && focus) t.focus();
        });
    }
    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { showTab(t.getAttribute('data-pd-tab')); });
        t.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
            var next = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            showTab(next.getAttribute('data-pd-tab'), true);
        });
    });
    document.querySelectorAll('[data-pd-tab-link]').forEach(function (a) {
        a.addEventListener('click', function () { showTab(a.getAttribute('data-pd-tab-link')); });
    });

    function alertMsg(msg, type) {
        if (typeof IAS_UI !== 'undefined') IAS_UI.alert(msg, type, type === 'error' ? 0 : 2500);
    }
    if (/[?&]added=1\b/.test(window.location.search)) alertMsg('Added to cart!', 'success');

    // Add to Cart without leaving the page (Buy Now still submits normally).
    var cartForm = document.getElementById('epPdCartForm');
    var cartBtn = document.getElementById('epPdAddCart');
    if (cartForm && cartBtn) {
        cartBtn.addEventListener('click', function (e) {
            e.preventDefault();
            clampQty();
            var body = new URLSearchParams(new FormData(cartForm));
            body.set('ajax', '1');
            body.set('add_to_cart', '1');
            cartBtn.disabled = true;
            fetch('products.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body: body.toString()
            }).then(function (r) { return r.json(); })
              .then(function (data) {
                  if (data && data.ok) {
                      if (data.cart && typeof window.epUpdateCartPreview === 'function') window.epUpdateCartPreview(data.cart);
                      alertMsg('Added to cart!', 'success');
                  } else {
                      alertMsg('Could not add this product to cart.', 'error');
                  }
              })
              .catch(function () { alertMsg('Could not add this product to cart.', 'error'); })
              .then(function () { cartBtn.disabled = false; });
        });
    }

    // Wishlist toggle without a page reload.
    var wishForm = document.getElementById('epPdWishForm');
    var wishBtn = document.getElementById('epPdWish');
    if (wishForm && wishBtn) {
        wishForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var body = new URLSearchParams(new FormData(wishForm));
            body.set('ajax', '1');
            body.set('toggle_wishlist', '1');
            wishBtn.disabled = true;
            fetch('wishlist.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body: body.toString()
            }).then(function (r) { return r.json(); })
              .then(function (data) {
                  if (!data || !data.ok) return;
                  var on = !!data.in_wishlist;
                  wishBtn.classList.toggle('is-on', on);
                  wishBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
                  wishBtn.innerHTML = '<i class="' + (on ? 'fas' : 'far') + ' fa-heart" aria-hidden="true"></i> <span>' +
                      (on ? 'Saved to Wishlist' : 'Add to Wishlist') + '</span>';
              })
              .catch(function () {})
              .then(function () { wishBtn.disabled = false; });
        });
    }
})();
</script>
JS;
include __DIR__ . '/ep_footer.php';
