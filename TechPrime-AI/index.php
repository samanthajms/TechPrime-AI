<?php
// Site root: visitors land on the public storefront (guests can browse without logging in).
// Staff who are still signed in go straight to their own dashboard instead.
session_start();

$staffHome = [
    'admin'               => 'ADMIN/admin_dashboard.php',
    'retail_officer'      => 'RETAIL/retail_dashboard.php',
    'inventory_custodian' => 'INVENTORY/inventory_dashboard.php',
    'cashier'             => 'CASHIER/cashier_dashboard.php',
];

$role = !empty($_SESSION['user_id']) ? (string)($_SESSION['role'] ?? '') : '';
header('Location: ' . ($staffHome[$role] ?? 'CLIENT/index.php'));
exit;
