<?php
/**
 * storefront_components.php — markup for the storefront v2 components (CLIENT/storefront.css).
 * Every customer page has body class "sf-page" and loads storefront.css (both set by CLIENT/ep_header.php),
 * so any CLIENT page can use these helpers.
 */

if (!function_exists('sf_product_card')) {
    /**
     * One product card. The title link is stretched over the card; the buy form sits above it.
     * Posts the same fields as the legacy cards (product_id, return_to, csrf_token, add_to_cart|buy_now)
     * to products.php.
     *
     * $opts:
     *   'cat'    => string  label above the title (default: the product's category)
     *   'wish'   => bool    show the wishlist heart (posts toggle_wishlist to wishlist.php)
     *   'rating' => ?float  average rating; shows stars when not null
     */
    function sf_product_card(array $p, string $returnTo, string $csrf, array $opts = []): string
    {
        $id = (int)$p['id'];
        $name = (string)($p['name'] ?? '');
        $url = 'products.php?id=' . $id;
        $img = ias_client_product_image_url($p);
        $stock = (int)($p['stock'] ?? 0);
        $category = trim((string)($opts['cat'] ?? ($p['category'] ?? '')));
        $rating = isset($opts['rating']) && $opts['rating'] !== null ? (float)$opts['rating'] : null;
        $showWish = !empty($opts['wish']);
        $inWish = $showWish && function_exists('ep_wishlist_has') && ep_wishlist_has($id);

        ob_start();
        ?>
        <article class="sf-card<?php echo $stock <= 0 ? ' is-out' : ''; ?>">
            <div class="sf-card-media">
                <?php if ($img !== ''): ?>
                    <img src="<?php echo h($img); ?>" alt="" width="240" height="192" loading="lazy" decoding="async">
                <?php else: ?>
                    <i class="fas fa-box" aria-hidden="true"></i>
                <?php endif; ?>
            </div>
            <?php if ($showWish): ?>
                <form method="POST" action="wishlist.php" class="sf-card-wish">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="product_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">
                    <button type="submit" name="toggle_wishlist" value="1" class="sf-wish-btn<?php echo $inWish ? ' is-on' : ''; ?>"
                            aria-pressed="<?php echo $inWish ? 'true' : 'false'; ?>"
                            aria-label="<?php echo $inWish ? 'Remove ' . h($name) . ' from wishlist' : 'Save ' . h($name) . ' to wishlist'; ?>"
                            title="<?php echo $inWish ? 'Remove from wishlist' : 'Add to wishlist'; ?>">
                        <i class="<?php echo $inWish ? 'fas' : 'far'; ?> fa-heart" aria-hidden="true"></i>
                    </button>
                </form>
            <?php endif; ?>
            <div class="sf-card-body">
                <p class="sf-card-cat"><?php echo h($category !== '' ? $category : 'Uncategorized'); ?></p>
                <h3 class="sf-card-title"><a href="<?php echo h($url); ?>"><?php echo h($name); ?></a></h3>
                <?php if ($rating !== null): ?>
                    <p class="sf-card-rating" title="<?php echo number_format($rating, 1); ?> out of 5">
                        <span aria-hidden="true"><?php echo sf_stars($rating); ?></span>
                        <span class="sf-sr">Rated <?php echo number_format($rating, 1); ?> out of 5</span>
                    </p>
                <?php endif; ?>
                <div class="sf-card-meta">
                    <span class="sf-card-price"><span class="sf-sr">Price: </span>₱<?php echo number_format((float)($p['price'] ?? 0), 2); ?></span>
                    <?php if ($stock <= 0): ?>
                        <span class="sf-stock is-out"><i class="fas fa-times-circle" aria-hidden="true"></i>Out of stock</span>
                    <?php elseif ($stock <= 5): ?>
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
                            title="Add to cart" aria-label="Add <?php echo h($name); ?> to cart"<?php echo $stock <= 0 ? ' disabled' : ''; ?>>
                        <i class="fas fa-cart-plus" aria-hidden="true"></i>
                    </button>
                    <button type="submit" name="buy_now" value="1" class="sf-btn sf-btn-primary"
                            aria-label="Buy <?php echo h($name); ?> now"<?php echo $stock <= 0 ? ' disabled' : ''; ?>>Buy now</button>
                </form>
            </div>
        </article>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('sf_stars')) {
    /** Five Font Awesome stars, filled up to the rounded rating (decorative: callers add the text). */
    function sf_stars(?float $avg): string
    {
        $out = '';
        $rounded = $avg === null ? 0 : (int)round($avg);
        for ($i = 1; $i <= 5; $i++) {
            $out .= '<i class="' . ($i <= $rounded ? 'fas' : 'far') . ' fa-star" aria-hidden="true"></i>';
        }
        return $out;
    }
}

if (!function_exists('sf_section_head')) {
    /**
     * Section heading with optional "view all" link and rail arrows (wired by CLIENT/storefront.js).
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

if (!function_exists('sf_product_rail')) {
    /** A section with a heading and a horizontal rail of product cards (same as the homepage rails). */
    function sf_product_rail(string $id, string $eyebrow, string $title, array $products, string $returnTo,
                             string $csrf, string $linkHref = '', string $linkLabel = '', string $sub = ''): string
    {
        if (!$products) {
            return '';
        }
        $railId = $id . 'Rail';
        ob_start();
        ?>
        <section class="sf-wrap sf-section" aria-labelledby="<?php echo h($id); ?>">
            <?php echo sf_section_head($id, $eyebrow, $title, $sub, $railId, $linkHref, $linkLabel); ?>
            <div class="sf-rail" data-sf-rail>
                <ul class="sf-rail-track" id="<?php echo h($railId); ?>" data-sf-rail-track>
                    <?php foreach ($products as $p): ?>
                        <li><?php echo sf_product_card($p, $returnTo, $csrf, ['cat' => (string)($p['tax_sub_label'] ?? '') ?: (string)($p['category'] ?? '')]); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('sf_page_hero')) {
    /**
     * Light page header for inner pages: sits on the page background above the content
     * (the deep-green hero band belongs to the homepage only).
     *
     * $o:
     *   'title'   => string (required)      the page h1
     *   'id'      => string                 h1 id (default sfPageTitle)
     *   'eyebrow' => string                 small label above the title
     *   'sub'     => string                 one-line description (plain text)
     *   'crumbs'  => [[label, href], ...]   breadcrumb; the last item is the current page (href ignored)
     *   'back'    => [label, href]          back link shown instead of a breadcrumb (gets data-ep-back)
     *   'meta'    => string (HTML)          chips under the subtitle, e.g. counts
     *   'actions' => string (HTML)          buttons on the right
     *   'icon'    => string                 Font Awesome icon name shown in a tile beside the title
     *   'size'    => 'md' | 'sm'            sm = smaller title for task pages (cart, checkout, payment)
     *   'width'   => 'narrow' | 'xnarrow'   match a narrower page body (sf-wrap is-narrow / is-xnarrow)
     */
    function sf_page_hero(array $o): string
    {
        $title = (string)($o['title'] ?? '');
        $id = (string)($o['id'] ?? 'sfPageTitle');
        $crumbs = (array)($o['crumbs'] ?? []);
        $back = $o['back'] ?? null;
        $classes = 'sf-pagehead';
        if (($o['size'] ?? 'md') === 'sm') {
            $classes .= ' is-sm';
        }
        ob_start();
        ?>
        <section class="<?php echo $classes; ?>" aria-labelledby="<?php echo h($id); ?>">
            <div class="sf-wrap sf-pagehead-inner<?php echo in_array($o['width'] ?? '', ['narrow', 'xnarrow'], true) ? ' is-' . $o['width'] : ''; ?>">
                <?php if ($crumbs): ?>
                    <nav class="sf-crumbs" aria-label="Breadcrumb">
                        <ol>
                            <?php $last = count($crumbs) - 1; foreach ($crumbs as $i => $c): ?>
                                <li>
                                    <?php if ($i < $last && !empty($c[1])): ?>
                                        <a href="<?php echo h($c[1]); ?>"><?php echo h($c[0]); ?></a>
                                    <?php elseif ($i < $last): ?>
                                        <span><?php echo h($c[0]); ?></span>
                                    <?php else: ?>
                                        <span aria-current="page"><?php echo h($c[0]); ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </nav>
                <?php elseif (is_array($back) && count($back) === 2): ?>
                    <a class="sf-pagehead-back" href="<?php echo h($back[1]); ?>" data-ep-back>
                        <i class="fas fa-arrow-left" aria-hidden="true"></i> <?php echo h($back[0]); ?>
                    </a>
                <?php endif; ?>
                <div class="sf-pagehead-row">
                    <div class="sf-pagehead-copy">
                        <?php if (!empty($o['icon'])): ?>
                            <span class="sf-pagehead-icon" aria-hidden="true"><i class="fas <?php echo h($o['icon']); ?>"></i></span>
                        <?php endif; ?>
                        <div>
                            <?php if (!empty($o['eyebrow'])): ?><p class="sf-pagehead-eyebrow"><?php echo h($o['eyebrow']); ?></p><?php endif; ?>
                            <h1 class="sf-pagehead-title" id="<?php echo h($id); ?>"><?php echo h($title); ?></h1>
                            <?php if (!empty($o['sub'])): ?><p class="sf-pagehead-sub"><?php echo h($o['sub']); ?></p><?php endif; ?>
                            <?php if (!empty($o['meta'])): ?><div class="sf-pagehead-meta"><?php echo $o['meta']; ?></div><?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($o['actions'])): ?>
                        <div class="sf-pagehead-actions"><?php echo $o['actions']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('sf_pagination')) {
    /**
     * Page links. Keeps the legacy .ep-pagination / .ep-page-link / .active / .disabled classes because
     * shop.php's live-results script intercepts those links.
     * $url: fn(int $page): string
     */
    function sf_pagination(int $page, int $totalPages, callable $url, string $label = 'Pagination'): string
    {
        if ($totalPages <= 1) {
            return '';
        }
        $range = sf_pagination_range($page, $totalPages);
        ob_start();
        ?>
        <nav class="sf-pager ep-pagination" aria-label="<?php echo h($label); ?>">
            <?php if ($page > 1): ?>
                <a class="sf-pager-link is-nav ep-page-link ep-page-nav" href="<?php echo h($url($page - 1)); ?>" rel="prev">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i><span>Previous</span>
                </a>
            <?php else: ?>
                <span class="sf-pager-link is-nav is-disabled" aria-disabled="true">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i><span>Previous</span>
                </span>
            <?php endif; ?>
            <?php foreach ($range as $item): ?>
                <?php if ($item === '...'): ?>
                    <span class="sf-pager-gap" aria-hidden="true">&hellip;</span>
                <?php elseif ($item === $page): ?>
                    <a class="sf-pager-link ep-page-link active" href="<?php echo h($url($item)); ?>" aria-current="page"><span class="sf-sr">Page </span><?php echo (int)$item; ?></a>
                <?php else: ?>
                    <a class="sf-pager-link ep-page-link" href="<?php echo h($url($item)); ?>"><span class="sf-sr">Page </span><?php echo (int)$item; ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($page < $totalPages): ?>
                <a class="sf-pager-link is-nav ep-page-link ep-page-nav" href="<?php echo h($url($page + 1)); ?>" rel="next">
                    <span>Next</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                </a>
            <?php else: ?>
                <span class="sf-pager-link is-nav is-disabled" aria-disabled="true">
                    <span>Next</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                </span>
            <?php endif; ?>
        </nav>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('sf_pagination_range')) {
    /** 1 … 4 5 6 … 20 (a single hidden page is shown instead of an ellipsis). */
    function sf_pagination_range(int $current, int $total): array
    {
        if ($total <= 1) {
            return [1];
        }
        $range = [];
        for ($i = 1; $i <= $total; $i++) {
            if ($i === 1 || $i === $total || ($i >= $current - 1 && $i <= $current + 1)) {
                $range[] = $i;
            }
        }
        $out = [];
        $last = null;
        foreach ($range as $i) {
            if ($last !== null) {
                if ($i - $last === 2) {
                    $out[] = $last + 1;
                } elseif ($i - $last > 1) {
                    $out[] = '...';
                }
            }
            $out[] = $i;
            $last = $i;
        }
        return $out;
    }
}

if (!function_exists('sf_empty_state')) {
    /**
     * Empty state. 'center' = large centered block for an empty page; otherwise the compact row
     * used inside homepage sections. $actions is HTML (buttons/links).
     */
    function sf_empty_state(string $icon, string $title, string $text = '', string $actions = '', bool $center = true): string
    {
        ob_start();
        ?>
        <div class="sf-empty<?php echo $center ? ' is-center' : ''; ?>">
            <span class="sf-empty-icon"><i class="fas <?php echo h($icon); ?>" aria-hidden="true"></i></span>
            <span class="sf-empty-text">
                <strong><?php echo h($title); ?></strong>
                <?php if ($text !== ''): ?><span><?php echo h($text); ?></span><?php endif; ?>
            </span>
            <?php if ($actions !== ''): ?><span class="sf-empty-actions"><?php echo $actions; ?></span><?php endif; ?>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}
