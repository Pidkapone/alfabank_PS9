<?php
/**
 * PayKeeper callback controller.
 */

declare(strict_types=1);

class PaykeeperCallbackModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    protected $ssl = true;
    /** @var bool */
    protected $display_header = false;
    /** @var bool */
    protected $display_footer = false;
    /** @var bool */
    protected $content_only = true;

    public function postProcess(): void
    {
        $this->ajaxRender($this->handleCallback());
    }

    private function handleCallback(): string
    {
        header('Content-Type: text/plain; charset=utf-8');

        $orderId = (int) Tools::getValue('orderid');
        $paymentId = (string) Tools::getValue('id');
        $sum = (float) Tools::getValue('sum');
        $clientId = (string) Tools::getValue('clientid');
        $providedSignature = (string) Tools::getValue('key');
        $secret = (string) Configuration::get(Paykeeper::CONFIG_SECRET, '');

        $calculatedSignature = md5(
            $paymentId
            . number_format($sum, 2, '.', '')
            . $clientId
            . (string) $orderId
            . $secret
        );

        if (!hash_equals($calculatedSignature, $providedSignature)) {
            http_response_code(400);

            return 'Error! Hash mismatch';
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            http_response_code(404);

            return 'Error. Order not found';
        }

        if (abs((float) $order->total_paid - $sum) > 0.01) {
            http_response_code(400);

            return 'Error. Sums are not equal';
        }

        $history = new OrderHistory();
        $history->id_order = $orderId;
        $history->changeIdOrderState((int) Configuration::get(Paykeeper::CONFIG_STATE_AFTER, null), $orderId);
        $history->save();

        return 'OK ' . md5($paymentId . $secret);
    }
}
