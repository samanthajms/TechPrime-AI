<?php
/**
 * @deprecated Use includes/staff_layout.php — staff_page_start() / staff_page_end()
 * Thin compatibility wrappers for any legacy callers.
 */
require_once __DIR__ . '/../includes/staff_layout.php';

function courier_page_start(
    string $pageTitle,
    string $activeNav,
    string $topbarTitle,
    string $topbarSubtitle = '',
    string $extraStyles = ''
): void {
    staff_page_start([
        'role' => 'courier',
        'title' => $pageTitle,
        'active' => $activeNav,
        'heading' => $topbarTitle,
        'subtitle' => $topbarSubtitle,
        'extra_head' => $extraStyles !== '' ? '<style>' . $extraStyles . '</style>' : '',
    ]);
}

function courier_page_end(): void
{
    staff_page_end();
}
