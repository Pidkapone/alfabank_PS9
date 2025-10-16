<?php
/**
 * Legacy callback endpoint kept for backward compatibility.
 */

declare(strict_types=1);

require dirname(__FILE__) . '/../../config/config.inc.php';
require dirname(__FILE__) . '/../../init.php';

if (!Module::isInstalled('paykeeper')) {
    exit;
}

$secret = (string) Configuration::get(Paykeeper::CONFIG_SECRET, '');
$orderId = (int) Tools::getValue('orderid');
$paymentId = (string) Tools::getValue('id');
$sum = (float) Tools::getValue('sum');
$clientId = (string) Tools::getValue('clientid');
$providedSignature = (string) Tools::getValue('key');

$calculatedSignature = md5(
    $paymentId
    . number_format($sum, 2, '.', '')
    . $clientId
    . (string) $orderId
    . $secret
);

if (!hash_equals($calculatedSignature, $providedSignature)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Error! Hash mismatch');
}

$order = new Order($orderId);
if (!Validate::isLoadedObject($order)) {
    header('HTTP/1.1 404 Not Found');
    exit('Error. Order not found');
}

if (abs((float) $order->total_paid - $sum) > 0.01) {
    header('HTTP/1.1 400 Bad Request');
    exit('Error. Sums are not equal');
}

$history = new OrderHistory();
$history->id_order = $orderId;
$history->changeIdOrderState((int) Configuration::get(Paykeeper::CONFIG_STATE_AFTER, null), $orderId);
$history->save();

echo 'OK ' . md5($paymentId . $secret);
