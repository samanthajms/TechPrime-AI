<?php
/**
 * Shared Trend Analysis page (Admin, Retail Officer, Inventory Custodian); files keep the old "forecast" names.
 * Every role sees both views: Trend Analysis > Product Trends | Sales Trends (?view=demand|sales).
 * Role pages are thin wrappers: guards first (checkSessionTimeout + checkRole), then staff_forecast_page().
 * Cards, filters and data loading: includes/trends_cards.php (data via trends_api.php).
 */
require_once __DIR__ . '/staff_layout.php';
require_once __DIR__ . '/product_categories.php';
require_once __DIR__ . '/trends_cards.php';

function staff_forecast_page(PDO $db, string $role): void
{
    $categories = ias_category_groups();   // 8 Client menu groups (shared with the forecast service filter)
    $view = (($_GET['view'] ?? '') === 'sales') ? 'sales' : 'demand';
    $fc = ias_forecast_selection($categories);

    logActivity($db, (int)$_SESSION['user_id'], 'view_forecast',
        staff_role_label($role) . ' viewed ' . ($view === 'sales' ? 'sales trends' : 'product trends'));

    // Printed letterhead: the same filters the card uses (formal layout: includes/staff_print.css §11)
    $sel = $view === 'sales' ? $fc['sf'] : $fc['df'];
    $lines = [
        'Sales history shown' => $sel['range']['label'] . ' (' . $sel['range']['from']->format('M j, Y') . ' – ' . $sel['range']['to']->format('M j, Y') . ')',
        'Projection window' => 'Next ' . $sel['months'] . ($sel['months'] === 1 ? ' month' : ' months'),
    ];
    if ($view !== 'sales') {
        $lines['Category'] = $sel['category'] !== '' ? $sel['category'] : 'All products';
    }
    // Downloads with the same filters (trends_export.php); the forecast service can take a while to wake up
    $exportLink = function (string $format, string $icon, string $label) use ($view): string {
        $qs = http_build_query(['view' => $view, 'format' => $format] + array_diff_key($_GET, ['view' => 1, 'format' => 1, 'export_failed' => 1]));
        return '<a class="cx-btn cx-btn-outline no-print" href="../trends_export.php?' . h($qs) . '" data-fc-export'
            . ' title="Download the ' . h($view === 'sales' ? 'sales' : 'product') . ' trends as ' . h($label) . '">'
            . '<i class="ph ' . $icon . '" aria-hidden="true"></i> ' . h($label) . '</a>';
    };

    // Card, dialog and print styles: includes/staff_app.css (§10, §11)
    staff_page_start([
        'role' => $role,
        'title' => $view === 'sales' ? 'Sales Trends' : 'Product Trends',
        'active' => 'forecast',
        'active_child' => $view,
        'heading' => $view === 'sales' ? 'Sales Trends' : 'Product Trends',
        'subtitle' => $view === 'sales' ? 'Store-wide sales trend, projected 1–3 months ahead' : 'Store-wide product sales trend, projected 1–3 months ahead',
        'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>',
        'actions' => $exportLink('csv', 'ph-file-csv', 'CSV') . $exportLink('xlsx', 'ph-file-xls', 'Excel') . $exportLink('pdf', 'ph-file-pdf', 'PDF')
            . '<button type="button" class="cx-btn cx-btn-outline no-print" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>',
        'report' => [
            'title' => $view === 'sales' ? 'Sales Trend Analysis Report' : 'Product Trend Analysis Report',
            'lines' => $lines,
        ],
    ]);

    ias_forecast_cards_render($fc, $categories, $view);

    $exportFailed = isset($_GET['export_failed'])
        ? '<script>document.addEventListener("DOMContentLoaded",function(){if(typeof IAS_UI!=="undefined"){IAS_UI.alert('
          . '"The trend analysis service did not answer, so the file could not be made. Wait a minute, then try the download again.","error",0);}});</script>'
        : '';

    staff_page_end(ias_forecast_cards_script($fc, $view) . $exportFailed . <<<'SCRIPTS'
<script>
/* Downloads: the trend analysis service may need up to a minute to wake up, so say the file is on its way */
document.querySelectorAll('[data-fc-export]').forEach(function (a) {
    a.addEventListener('click', function () {
        if (typeof IAS_UI !== 'undefined') {
            IAS_UI.alert('Preparing your ' + a.textContent.trim() + ' file. It downloads on its own; this can take up to a minute.', 'info', 6000);
        }
    });
});
function openFilterModal(id){ document.getElementById(id).classList.add('open'); }
function closeFilterModal(id){ document.getElementById(id).classList.remove('open'); }
function toggleDetail(id){ document.getElementById(id).classList.toggle('open'); }
function toggleSectionCustom(sel, rowId){
    document.getElementById(rowId).style.display = (sel.value === 'custom') ? '' : 'none';
}
</script>
SCRIPTS);
}
