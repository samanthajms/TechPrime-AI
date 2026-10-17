<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/product_categories.php';
require_once __DIR__ . '/../includes/forecast_cards.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$categories = ias_category_groups();
$fc = ias_forecast_selection($categories);

logActivity($db, (int)$_SESSION['user_id'], 'view_forecast', 'Inventory Custodian viewed product demand forecast');

// Card, dialog and print styles: includes/staff_app.css (§10, §11)
staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Forecast',
    'active' => 'forecast',
    'heading' => 'Product Demand Forecast',
    'subtitle' => 'Projected product demand, 1–3 months ahead',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>',
]);

ias_forecast_cards_render($fc, $categories, 'demand');

staff_page_end(ias_forecast_cards_script($fc, 'demand') . <<<'SCRIPTS'
<script>
function openFilterModal(id){ document.getElementById(id).classList.add('open'); }
function closeFilterModal(id){ document.getElementById(id).classList.remove('open'); }
function toggleDetail(id){ document.getElementById(id).classList.toggle('open'); }
function toggleSectionCustom(sel, rowId){
    document.getElementById(rowId).style.display = (sel.value === 'custom') ? '' : 'none';
}
</script>
SCRIPTS);
