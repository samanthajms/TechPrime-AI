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

$forecastCss = <<<'CSS'
.card-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.filter-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:3000; align-items:center; justify-content:center; padding:20px; }
.filter-modal.open { display:flex; }
.filter-modal-card { background:#fff; border-radius:12px; padding:22px; width:min(460px,100%); max-height:90vh; overflow-y:auto; border:2px solid var(--ep-green); }
.filter-modal-card h4 { margin:0 0 14px; color:var(--ep-green-dark); }
.detail-panel { display:none; margin-top:14px; }
.detail-panel.open { display:block; }
CSS;

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Forecast',
    'active' => 'forecast',
    'heading' => 'Product Demand Forecast',
    'subtitle' => 'Projected product demand, 1–3 months ahead',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script><style>' . $forecastCss . '</style>',
]);

echo '<div style="padding:0 28px 28px;">';
ias_forecast_cards_render($fc, $categories, 'demand');
echo '</div>';

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
