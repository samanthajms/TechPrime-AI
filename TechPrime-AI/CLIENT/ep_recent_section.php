<?php
/**
 * ep_recent_section.php — "Recently viewed" rail (product page). The homepage renders its own rail.
 * Expects $epRecent (rows from ep_recent_products()) and $returnTo. Renders nothing when empty.
 */
if (empty($epRecent)) {
    return;
}
echo sf_product_rail('epRecentTitle', 'Pick up where you left off', 'Recently viewed', $epRecent, $returnTo, generateCsrfToken());
