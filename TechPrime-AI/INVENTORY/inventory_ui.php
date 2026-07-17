<?php
/**
 * @deprecated Use includes/staff_layout.php — staff_page_start() / staff_page_end()
 */
require_once __DIR__ . '/../includes/staff_layout.php';

function inventory_page_start(
    string $pageTitle,
    string $activeNav,
    string $topbarTitle,
    string $topbarSubtitle = '',
    string $extraStyles = ''
): void {
    staff_page_start([
        'role' => $_SESSION['role'] ?? 'inventory_custodian',
        'title' => $pageTitle,
        'active' => $activeNav,
        'heading' => $topbarTitle,
        'subtitle' => $topbarSubtitle,
        'extra_head' => $extraStyles !== '' ? '<style>' . $extraStyles . '</style>' : '',
    ]);
}

function inventory_page_end(): void
{
    staff_page_end();
}
