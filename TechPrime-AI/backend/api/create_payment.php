<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paymongo.php';

if (empty($_SESSION['user_id']) || empty($_POST['total'])) {
    header('Location: ../../CLIENT/checkout.php');
    exit;
}

$total          = (float) $_POST['total'];
$address        = $_POST['address'] ?? '';
$phone          = $_POST['phone'] ?? '';
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