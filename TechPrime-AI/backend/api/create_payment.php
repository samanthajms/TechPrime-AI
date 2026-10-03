<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paymongo.php';
require_once __DIR__ . '/../../includes/address_helpers.php';

if (empty($_SESSION['user_id']) || empty($_POST['total'])) {
    header('Location: ../../CLIENT/checkout.php');
    exit;
}

// Saved profile address or a one-off address for this order (same rules as COD).
$phone    = trim((string)($_POST['phone'] ?? ''));
$resolved = ep_checkout_resolve_address(getDbConnection(), (int)$_SESSION['user_id'], $_POST);
$addrErr  = ep_phone_error($phone) ?: ($resolved['ok'] ? '' : $resolved['error']);
if ($addrErr !== '') {
    $_SESSION['checkout_flash_error'] = $addrErr;
    header('Location: ../../CLIENT/checkout.php');
    exit;
}

$total          = (float) $_POST['total'];
$address        = $resolved['address'];
$amountCentavos = (int) round($total * 100);

$_SESSION['pending_order'] = [
    'address' => $address,
    'phone'   => $phone,
    'total'   => $total,
];

$response = paymongoRequest('POST', '/links', [
    'data' => [
        'attributes' => [
            'amount'      => $amountCentavos,
            'description' => 'EasyPC Ecommerce Order',
            'remarks'     => 'Order for user ' . $_SESSION['user_id'],
        ]
    ]
]);

if (isset($response['data']['attributes']['checkout_url'])) {
    $_SESSION['paymongo_link_id'] = $response['data']['id'];
    header('Location: ' . $response['data']['attributes']['checkout_url']);
    exit;
} else {
    error_log('PayMongo error: ' . json_encode($response));
    header('Location: ../../CLIENT/checkout.php?error=payment_failed');
    exit;
}