<?php
/**
 * Cashier: current low/critical/out-of-stock products, polled by the dashboard and
 * the Stock Alerts page to keep them in sync with the live stock.
 * GET → {ok, out, critical, low, items:[{id,name,category,price,stock,status,barcode}]}
 *
 * Polling must not keep an idle session alive, so unlike pos_api_require_cashier()
 * this does NOT count as activity (same approach as session.php?action=status).
 * An idle-expired session is ended and answered with 200 {ok:false, error:'session_expired'};
 * includes/session_timeout.js shows the dialog.
 */
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../includes/pos_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (ias_session_idle_expired()) {
    ias_expire_session();
    pos_json(200, ['ok' => false, 'error' => 'session_expired', 'message' => 'Your session expired due to inactivity.']);
}
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'cashier') {
    pos_json(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'Cashier access only.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pos_json(405, ['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Method not allowed.']);
}

pos_json(200, ['ok' => true] + pos_stock_alerts(getDbConnection()));
