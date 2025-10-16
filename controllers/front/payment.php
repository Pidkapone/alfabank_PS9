<?php
/**
 * PayKeeper payment controller.
 */

declare(strict_types=1);

class PaykeeperPaymentModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    protected $ssl = true;

    private float $orderTotal = 0.0;
    private float $shippingPrice = 0.0;
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $fiscalCart = [];
    private bool $useTaxes = false;
    private bool $useDelivery = false;
    private int $deliveryIndex = -1;
    private int $singleItemIndex = -1;
    private int $moreThanOneItemIndex = -1;
    /**
     * @var array<string, mixed>
     */
    private array $orderParams = [];

    public function initContent(): void
    {
        parent::initContent();

        if (!$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $cart = $this->context->cart;
        if (!$cart->id || !$cart->id_customer || !$cart->id_address_invoice) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer((int) $cart->id_customer);
        $address = new Address((int) $cart->id_address_invoice);

        $clientId = trim(sprintf('%s %s', $customer->firstname, $customer->lastname));
        $clientPhone = $this->resolvePhone($address);
        $clientEmail = (string) $customer->email;
        $serviceName = (string) $customer->secure_key;
        $secret = (string) Configuration::get(Paykeeper::CONFIG_SECRET, '');
        $formUrl = (string) Configuration::get(Paykeeper::CONFIG_URL, '');

        $this->setOrderParams(
            (float) $cart->getOrderTotal(),
            $clientId,
            '',
            $clientEmail,
            $clientPhone,
            $secret,
            $formUrl,
            $secret
        );

        $this->buildFiscalCart($cart, $address);
        $this->applyDiscounts((bool) Configuration::get(Paykeeper::CONFIG_FORCE_DISCOUNT, false));
        $this->correctPrecision();

        $orderId = $this->createOrder($cart);
        $this->orderParams['orderid'] = $orderId;

        $payload = $this->buildPaymentPayload($clientId, $clientPhone, $clientEmail, $orderId, $serviceName, $secret);

        $this->context->smarty->assign([
            'paykeeperAction' => $formUrl,
            'paykeeperFields' => $payload,
        ]);

        $this->setTemplate('module:paykeeper/views/templates/front/payment_redirect.tpl');
    }

    private function resolvePhone(Address $address): string
    {
        if (!empty($address->phone)) {
            return (string) $address->phone;
        }

        if (!empty($address->phone_mobile)) {
            return (string) $address->phone_mobile;
        }

        return '';
    }

    private function buildFiscalCart(Cart $cart, Address $address): void
    {
        $products = $cart->getProducts();
        $itemIndex = 0;

        foreach ($products as $product) {
            $name = (string) $product['name'];
            $price = (float) $product['price_wt'];
            $quantity = (int) $product['quantity'];
            $sum = $price * $quantity;
            $taxRate = isset($product['rate']) ? (float) $product['rate'] : 0.0;

            if ($taxRate > 0) {
                $this->useTaxes = true;
            }

            $taxes = $this->resolveTaxes($taxRate, $taxRate > 0);

            if ($quantity === 1 && $this->singleItemIndex < 0) {
                $this->singleItemIndex = $itemIndex;
            }

            if ($quantity > 1 && $this->moreThanOneItemIndex < 0) {
                $this->moreThanOneItemIndex = $itemIndex;
            }

            $this->updateFiscalCart(
                $this->getPaymentFormType(),
                $name,
                $price,
                $quantity,
                $sum,
                $taxes['tax'],
                'goods',
                'prepay'
            );

            ++$itemIndex;
        }

        $this->appendShipping($cart, $address);
    }

    private function appendShipping(Cart $cart, Address $address): void
    {
        if (!$cart->id_carrier) {
            return;
        }

        $carrier = new Carrier((int) $cart->id_carrier, (int) $cart->id_lang);
        $shippingPrice = (float) $cart->getCarrierCost((int) $cart->id_carrier, true);

        if ($shippingPrice <= 0.0) {
            return;
        }

        $this->useDelivery = true;
        $this->shippingPrice = $shippingPrice;

        $taxRate = 0.0;
        if ($cart->id_address_delivery) {
            $taxRate = (float) $carrier->getTaxesRate($address);
        }

        $taxes = $this->resolveTaxes($taxRate, $taxRate > 0);

        $this->updateFiscalCart(
            $this->getPaymentFormType(),
            (string) $carrier->name,
            $shippingPrice,
            1,
            $shippingPrice,
            $taxes['tax'],
            'service',
            'prepay'
        );

        $this->deliveryIndex = count($this->fiscalCart) - 1;
    }

    private function createOrder(Cart $cart): int
    {
        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get(Paykeeper::CONFIG_STATE_BEFORE, null),
            (float) $this->orderTotal,
            $this->module->displayName,
            $this->module->l('Redirected to PayKeeper payment page, awaiting confirmation from the bank.', 'payment'),
            [],
            null,
            false,
            $cart->secure_key
        );

        return (int) Order::getOrderByCartId((int) $cart->id);
    }

    /**
     * @return array<string, string>
     */
    private function buildPaymentPayload(
        string $clientId,
        string $clientPhone,
        string $clientEmail,
        int $orderId,
        string $serviceName,
        string $secret
    ): array {
        $fiscalCart = json_encode($this->fiscalCart, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '';
        $orderTotal = number_format($this->orderTotal, 2, '.', '');

        $sign = hash(
            'sha256',
            $orderTotal
            . $clientId
            . (string) $orderId
            . $this->orderParams['service_name']
            . $clientEmail
            . $clientPhone
            . $secret
        );

        return [
            'sum' => $orderTotal,
            'clientid' => $clientId,
            'orderid' => $orderId,
            'client_phone' => $clientPhone,
            'client_email' => $clientEmail,
            'service_name' => $serviceName,
            'cart' => $fiscalCart,
            'sign' => $sign,
        ];
    }

    private function updateFiscalCart(
        string $formType,
        string $name,
        float $price,
        int $quantity,
        float $sum,
        string $tax,
        string $itemType = 'goods',
        string $paymentType = 'prepay'
    ): void {
        if ($formType === 'create') {
            $name = str_replace(["\n ", "\r "], '', $name);
        }

        $this->fiscalCart[] = [
            'name' => $name,
            'price' => number_format($price, 2, '.', ''),
            'quantity' => $quantity,
            'sum' => number_format($sum, 2, '.', ''),
            'tax' => $tax,
            'item_type' => $itemType,
            'payment_type' => $paymentType,
        ];
    }

    private function applyDiscounts(bool $discountEnabled): void
    {
        if (!$discountEnabled) {
            return;
        }

        $cartSumExcludingShipping = $this->getFiscalCartSum(false);
        if ($cartSumExcludingShipping <= 0.0) {
            return;
        }

        $discountModifier = ($this->orderTotal - $this->shippingPrice) / $cartSumExcludingShipping;
        if ($discountModifier >= 1) {
            return;
        }

        foreach ($this->fiscalCart as $index => &$item) {
            if ($index === $this->deliveryIndex) {
                continue;
            }

            $item['sum'] = number_format((float) $item['sum'] * $discountModifier, 2, '.', '');
            if ((int) $item['quantity'] > 0) {
                $item['price'] = number_format((float) $item['sum'] / (int) $item['quantity'], 2, '.', '');
            }
        }
        unset($item);
    }

    private function resolveTaxes(float $taxRate, bool $zeroValueAsNone = true): array
    {
        $tax = 'none';

        switch ((int) round($taxRate)) {
            case 0:
                if (!$zeroValueAsNone) {
                    $tax = 'vat0';
                }
                break;
            case 10:
                $tax = 'vat10';
                break;
            case 20:
                $tax = 'vat20';
                break;
            default:
                $tax = 'none';
        }

        return ['tax' => $tax, 'tax_sum' => 0];
    }

    private function setOrderParams(
        float $orderTotal,
        string $clientId,
        string $orderId,
        string $clientEmail,
        string $clientPhone,
        string $serviceName,
        string $formUrl,
        string $secretKey
    ): void {
        $this->orderTotal = $orderTotal;
        $this->orderParams = [
            'sum' => $orderTotal,
            'clientid' => $clientId,
            'orderid' => $orderId,
            'client_email' => $clientEmail,
            'client_phone' => $clientPhone,
            'service_name' => $serviceName,
            'form_url' => $formUrl,
            'secret_key' => $secretKey,
            'display_service_name' => $displayServiceName,
        ];
    }

    private function getPaymentFormType(): string
    {
        return strpos((string) $this->orderParams['form_url'], '/order/inline') !== false ? 'order' : 'create';
    }

    private function getFiscalCartSum(bool $includeDelivery): float
    {
        $sum = 0.0;
        foreach ($this->fiscalCart as $index => $item) {
            if (!$includeDelivery && $index === $this->deliveryIndex) {
                continue;
            }

            $sum += (float) $item['sum'];
        }

        return $sum;
    }

    private function correctPriceOfCartItem(float $priceDelta, int $itemPosition): void
    {
        $this->fiscalCart[$itemPosition]['price'] = number_format(
            (float) $this->fiscalCart[$itemPosition]['price'] + $priceDelta,
            2,
            '.',
            ''
        );

        $this->fiscalCart[$itemPosition]['sum'] = number_format(
            (float) $this->fiscalCart[$itemPosition]['price'] * (int) $this->fiscalCart[$itemPosition]['quantity'],
            2,
            '.',
            ''
        );
    }

    private function splitCartItem(int $itemPosition): void
    {
        $price = (float) $this->fiscalCart[$itemPosition]['price'];
        $quantity = (int) $this->fiscalCart[$itemPosition]['quantity'] - 1;

        $this->fiscalCart[$itemPosition]['quantity'] = $quantity;
        $this->fiscalCart[$itemPosition]['sum'] = number_format($price * $quantity, 2, '.', '');

        $this->updateFiscalCart(
            $this->getPaymentFormType(),
            (string) $this->fiscalCart[$itemPosition]['name'],
            $price,
            1,
            $price,
            (string) $this->fiscalCart[$itemPosition]['tax']
        );
    }

    private function correctPrecision(): void
    {
        $fiscalCartSum = $this->getFiscalCartSum(true);
        $diffValue = $this->orderTotal - $fiscalCartSum;

        if (abs($diffValue) < 0.005) {
            return;
        }

        $diffSum = (float) number_format($diffValue, 2, '.', '');

        if ($this->useDelivery && $this->deliveryIndex >= 0) {
            $this->correctPriceOfCartItem($diffSum, $this->deliveryIndex);

            return;
        }

        if ($this->singleItemIndex >= 0) {
            $this->correctPriceOfCartItem($diffSum, $this->singleItemIndex);

            return;
        }

        if ($this->moreThanOneItemIndex >= 0) {
            $this->splitCartItem($this->moreThanOneItemIndex);
            $this->correctPriceOfCartItem($diffSum, count($this->fiscalCart) - 1);

            return;
        }

        $cartSum = $this->getFiscalCartSum(true);
        $total = $this->orderTotal;

        if ($cartSum <= 0.0 || $total <= 0.0) {
            return;
        }

        $modifier = $diffSum > 0 ? $total / $cartSum : $cartSum / $total;

        foreach ($this->fiscalCart as &$item) {
            $quantity = (int) $item['quantity'];
            if ($quantity <= 0) {
                continue;
            }

            $sum = (float) $item['sum'] * $modifier;
            $item['sum'] = number_format($sum, 2, '.', '');
            $item['price'] = number_format($sum / $quantity, 2, '.', '');
        }
        unset($item);
    }
}
