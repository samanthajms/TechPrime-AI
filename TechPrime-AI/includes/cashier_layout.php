<?php
/**
 * Superseded by includes/staff_app_layout.php, the role-aware app shell that grew out
 * of the cashier shell. staff_page_start() / staff_page_end() call that file directly;
 * these wrappers only keep the old function names working.
 */
require_once __DIR__ . '/staff_app_layout.php';

if (!function_exists('cashier_shell_start')) {
    function cashier_shell_start(array $opts): void
    {
        staff_app_shell_start(['role' => 'cashier'] + $opts);
    }
}

if (!function_exists('cashier_shell_end')) {
    function cashier_shell_end(string $extraScripts = ''): void
    {
        staff_app_shell_end($extraScripts);
    }
}
