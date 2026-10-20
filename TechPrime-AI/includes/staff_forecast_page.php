<?php
/**
 * Shared Forecast page (Admin, Retail Officer, Inventory Custodian).
 * Every role sees both views: Forecast > Product Demand | Sales (?view=demand|sales).
 * Role pages are thin wrappers: guards first (checkSessionTimeout + checkRole), then staff_forecast_page().
 * Cards, filters and data loading: includes/forecast_cards.php (data via forecast_api.php).
 */
require_once __DIR__ . '/staff_layout.php';
require_once __DIR__ . '/product_categories.php';
require_once __DIR__ . '/forecast_cards.php';

function staff_forecast_page(PDO $db, string $role): void
{
    $categories = ias_category_groups();   // 8 Client menu groups (shared with the forecast service filter)
    $view = (($_GET['view'] ?? '') === 'sales') ? 'sales' : 'demand';
    $fc = ias_forecast_selection($categories);

    logActivity($db, (int)$_SESSION['user_id'], 'view_forecast',
        staff_role_label($role) . ' viewed ' . ($view === 'sales' ? 'sales' : 'product demand') . ' forecast');

    // Card, dialog and print styles: includes/staff_app.css (§10, §11)
    staff_page_start([
        'role' => $role,
        'title' => $view === 'sales' ? 'Sales Forecast' : 'Product Demand Forecast',
        'active' => 'forecast',
        'active_child' => $view,
        'heading' => $view === 'sales' ? 'Sales Forecast' : 'Product Demand Forecast',
        'subtitle' => $view === 'sales' ? 'Store-wide sales projections, 1–3 months ahead' : 'Store-wide product demand projections, 1–3 months ahead',
        'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>',
    ]);

    ias_forecast_cards_render($fc, $categories, $view);

    staff_page_end(ias_forecast_cards_script($fc, $view) . <<<'SCRIPTS'
<script>
function openFilterModal(id){ document.getElementById(id).classList.add('open'); }
function closeFilterModal(id){ document.getElementById(id).classList.remove('open'); }
function toggleDetail(id){ document.getElementById(id).classList.toggle('open'); }
function toggleSectionCustom(sel, rowId){
    document.getElementById(rowId).style.display = (sel.value === 'custom') ? '' : 'none';
}
</script>
SCRIPTS);
}
