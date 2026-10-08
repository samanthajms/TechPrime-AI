<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/product_categories.php';
require_once __DIR__ . '/../includes/forecast_cards.php';

date_default_timezone_set('Asia/Manila');

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];

$categories = ias_category_groups();   // 8 Client menu groups (shared with the forecast service filter)
$fc = ias_forecast_selection($categories);

logActivity($db, $admin_id, 'view_forecast', 'Admin viewed forecast');

$forecastCss = <<<'CSS'
.card-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.detail-panel { display:none; margin-top:14px; }
.detail-panel.open { display:block; }
CSS;

staff_page_start([
    'role' => 'admin',
    'title' => 'Forecast',
    'active' => 'forecast',
    'heading' => 'Sales & Product Demand Forecast',
    'subtitle' => 'Store-wide demand and sales projections, 1–3 months ahead',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script><style>' . $forecastCss . '</style>',
]);
?>

<?php
ias_forecast_cards_render($fc, $categories);

staff_page_end(ias_forecast_cards_script($fc) . <<<'SCRIPTS'
<script>
function openFilterModal(id){ document.getElementById(id).classList.add('open'); }
function closeFilterModal(id){ document.getElementById(id).classList.remove('open'); }
function toggleDetail(id){ document.getElementById(id).classList.toggle('open'); }
function toggleSectionCustom(sel, rowId){
    document.getElementById(rowId).style.display = (sel.value === 'custom') ? '' : 'none';
}
</script>
SCRIPTS);
