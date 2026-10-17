<?php
/**
 * storefront_components.php — markup for the storefront v2 components (CLIENT/storefront.css).
 * Used by CLIENT/index.php. Pages that use these need body class "sf-page" and storefront.css.
 */

if (!function_exists('sf_product_card')) {
    /**
     * One product card. The title link is stretched over the card; the buy form sits above it.
     * Posts the same fields as the legacy cards (product_id, return_to, csrf_token, add_to_cart|buy_now)
     * to products.php.
     */
    function sf_product_card(array $p, string $returnTo, string $csrf): string
    {
        $id = (int)$p['id'];
        $name = (string)($p['name'] ?? '');
        $url = 'products.php?id=' . $id;
        $img = ias_client_product_image_url($p);
        $stock = (int)($p['stock'] ?? 0);
        $category = trim((string)($p['category'] ?? ''));

        ob_start();
        ?>
        <article class="sf-card">
            <div class="sf-card-media">
                <?php if ($img !== ''): ?>
                    <img src="<?php echo h($img); ?>" alt="" width="240" height="192" loading="lazy" decoding="async">
                <?php else: ?>
                    <i class="fas fa-box" aria-hidden="true"></i>
                <?php endif; ?>
            </div>
            <div class="sf-card-body">
                <p class="sf-card-cat"><?php echo h($category !== '' ? $category : 'Uncategorized'); ?></p>
                <h3 class="sf-card-title"><a href="<?php echo h($url); ?>"><?php echo h($name); ?></a></h3>
                <div class="sf-card-meta">
                    <span class="sf-card-price"><span class="sf-sr">Price: </span>₱<?php echo number_format((float)($p['price'] ?? 0), 2); ?></span>
                    <?php if ($stock <= 5): ?>
                        <span class="sf-stock is-low"><i class="fas fa-exclamation-circle" aria-hidden="true"></i>Only <?php echo $stock; ?> left</span>
                    <?php else: ?>
                        <span class="sf-stock"><i class="fas fa-check-circle" aria-hidden="true"></i>In stock</span>
                    <?php endif; ?>
                </div>
                <form action="products.php" method="POST" class="sf-card-buy">
                    <input type="hidden" name="product_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <button type="submit" name="add_to_cart" value="1" class="sf-icon-btn"
                            title="Add to cart" aria-label="Add <?php echo h($name); ?> to cart">
                        <i class="fas fa-cart-plus" aria-hidden="true"></i>
                    </button>
                    <button type="submit" name="buy_now" value="1" class="sf-btn sf-btn-primary"
                            aria-label="Buy <?php echo h($name); ?> now">Buy now</button>
                </form>
            </div>
        </article>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('sf_section_head')) {
    /**
     * Section heading with optional "view all" link and rail arrows (wired by the rail script in index.php).
     * $railId: id of the rail track the arrows control, or '' for no arrows.
     */
    function sf_section_head(string $id, string $eyebrow, string $title, string $sub = '', string $railId = '',
                             string $linkHref = '', string $linkLabel = ''): string
    {
        ob_start();
        ?>
        <div class="sf-section-head">
            <div>
                <?php if ($eyebrow !== ''): ?><p class="sf-eyebrow"><?php echo h($eyebrow); ?></p><?php endif; ?>
                <h2 class="sf-section-title" id="<?php echo h($id); ?>"><?php echo h($title); ?></h2>
                <?php if ($sub !== ''): ?><p class="sf-section-sub"><?php echo h($sub); ?></p><?php endif; ?>
            </div>
            <?php if ($linkHref !== '' || $railId !== ''): ?>
                <div class="sf-section-tools">
                    <?php if ($linkHref !== ''): ?>
                        <a class="sf-link" href="<?php echo h($linkHref); ?>"><?php echo h($linkLabel); ?> <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    <?php endif; ?>
                    <?php if ($railId !== ''): ?>
                        <div class="sf-rail-arrows" data-sf-rail-arrows="<?php echo h($railId); ?>" hidden>
                            <button type="button" class="sf-icon-btn is-round" data-sf-rail-prev="<?php echo h($railId); ?>"
                                    aria-controls="<?php echo h($railId); ?>" aria-label="Scroll <?php echo h($title); ?> back" disabled>
                                <i class="fas fa-chevron-left" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="sf-icon-btn is-round" data-sf-rail-next="<?php echo h($railId); ?>"
                                    aria-controls="<?php echo h($railId); ?>" aria-label="Scroll <?php echo h($title); ?> forward">
                                <i class="fas fa-chevron-right" aria-hidden="true"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}
