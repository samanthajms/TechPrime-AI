<?php
/**
 * ep_recent_section.php — "Recently Viewed" carousel (homepage and product page).
 * Expects $epRecent (rows from ep_recent_products()) and $returnTo. Renders nothing when empty.
 */
if (empty($epRecent)) {
    return;
}
$epRecentCsrf = generateCsrfToken();
?>
<section class="ep-section ep-featured-section ep-recent-section" aria-labelledby="epRecentTitle">
    <div class="ep-featured-head">
        <div>
            <p class="ep-featured-kicker">Pick up where you left off</p>
            <h3 id="epRecentTitle">Recently Viewed</h3>
        </div>
    </div>
    <div class="ep-carousel-wrap">
        <button class="ep-arrow ep-arrow-left" onclick="epScroll('epRecentRow', -1)" aria-label="Scroll left"><i class="fas fa-arrow-left"></i></button>
        <div class="ep-carousel" id="epRecentRow">
            <?php foreach ($epRecent as $p): ?>
                <div class="ep-product-card ep-featured-card">
                    <div class="ep-featured-card-media">
                        <a href="products.php?id=<?php echo (int)$p['id']; ?>">
                            <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="ep-product-img" alt="<?php echo h($p['name']); ?>" loading="lazy" decoding="async">
                        </a>
                    </div>
                    <div class="ep-featured-card-body">
                        <a class="ep-product-name" href="products.php?id=<?php echo (int)$p['id']; ?>"><?php echo h($p['name']); ?></a>
                        <div class="ep-product-cat"><?php echo h($p['category'] ?: 'Uncategorized'); ?></div>
                        <div class="ep-product-price">₱<?php echo number_format((float)$p['price'], 2); ?></div>
                        <div class="ep-card-actions">
                            <form action="products.php" method="POST" class="ep-buy-form">
                                <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo h($epRecentCsrf); ?>">
                                <button type="submit" name="add_to_cart" value="1" class="ep-cart-icon" title="Add to cart"><i class="fas fa-shopping-cart"></i></button>
                                <button type="submit" name="buy_now" value="1" class="ep-buy-btn">BUY NOW</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button class="ep-arrow ep-arrow-right" onclick="epScroll('epRecentRow', 1)" aria-label="Scroll right"><i class="fas fa-arrow-right"></i></button>
    </div>
</section>
