<?php
/*
* 2007-2016 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2016 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

/**
 * @since 1.5.0
 */
class PaykeeperPaymentModuleFrontController extends ModuleFrontController
{
    private $order_total = 0;
    private $shipping_price = 0;
    private $fiscal_cart = array();
    private $use_taxes = false;
    private $use_delivery = false;
    private $delivery_index = -1;
    private $single_item_index = -1;
    private $more_then_one_item_index = -1;
    private $order_params = NULL;
    public $id_lang = 1;

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent()
	{
		$cart = $this->context->cart;
        $customer = new Customer((int)($cart->id_customer));
        $address  = new Address((int)($cart->id_address_invoice));
		$this->setOrderTotal($this->context->cart->getOrderTotal());
        $clientid     = $customer->firstname . " " . $customer->lastname;
        $client_phone = $address->phone;
        $client_email = $customer->email;
        $service_name = $customer->secure_key;
        $secret       = Configuration::get('PAYKEEPER_SECRET', null);
        $url          = Configuration::get('PAYKEEPER_URL', null);
		        //GENERATE FZ54 CART
        $this->setOrderParams(
            //sum
            $this->getOrderTotal(),
            //clientid
            $clientid,
            //orderid
            '',
            //client_email
            $client_email,
            //client_phone
            $client_phone,
            //service_name
            $secret,
            //payment form url
            Configuration::get('PAYKEEPER_URL', null),
            //secret key
            Configuration::get('PAYKEEPER_SECRET', null)
        );

        $cart_data = $cart->getProducts();
        $item_index = 0;

        foreach ($cart_data as $product) {
            $name = $product["name"];
            $price = number_format($product['price_wt'], 2, ".", "");
            $quantity = $product['quantity'];
            $sum = number_format($price*$quantity, 2, ".", "");
            if (isset($product['rate']) && $product['rate']>0){
                $this->setUseTaxes();
                $taxes = $this->setTaxes($product['rate']);
            } else {
                $taxes = $this->setTaxes($product['rate'],false);
            }
            
            if ($quantity == 1 && $this->single_item_index < 0) $this->single_item_index = $item_index;
            if ($quantity > 1 && $this->more_then_one_item_index < 0) $this->more_then_one_item_index = $item_index;
            $this->updateFiscalCart($this->getPaymentFormType(),$name, $price, $quantity, $sum, $taxes['tax'], "goods", "prepay");
            $item_index++;
        }

        //add shipping service
        if (isset($cart->id_carrier))
        {
            $carrier = new Carrier((int)$cart->id_carrier, $cart->id_lang);
            $shipping_name = $carrier->name;
            $shipping_tax = ["tax" => "none", "tax_sum" => 0];
            $shipping_price = $cart->getCarrierCost($cart->id_carrier);
            // 
            if ($shipping_price > 0){
                $this->setUseDelivery();
                $this->setShippingPrice($shipping_price);
                // determine shipping tax         
                if (isset($cart->id_address_delivery)){
                    $shipping_tax = $this->setTaxes($carrier->getTaxesRate($address));
                }
                $this->updateFiscalCart($this->getPaymentFormType(),$shipping_name, $shipping_price, 1, $shipping_price, $shipping_tax['tax'], "service", "prepay");
				$this->delivery_index = count($this->getFiscalCart())-1;
            }
          
        }

        $this->setDiscounts(Configuration::get('FORCE_DISCOUNT_CHECK', null));
        $this->correctPrecision();
        
        $fiscal_cart_encoded = json_encode($this->getFiscalCart());
        //создаем заказ
        $this->module->validateOrder((int)($this->context->cart->id), Configuration::get('STATE_BEFORE_PAYMENT'), 
                            (float)($this->getOrderTotal()), $this->module->displayName, 
                            "Произошел переход на страницу оплаты Paykeeper, после оплаты должно придти подтверждение из банка", 
                                array(), NULL, false,$cart->secure_key);

        //получаем id заказа по id корзины
        $orderid = Order::getOrderByCartId((int)($cart->id));
        $this->order_params['orderid'] = $orderid;
        $to_hash = number_format(   $this->getOrderTotal(), 2, ".", "") .
                                    $this->getOrderParams("clientid")     .
                                    $this->getOrderParams("orderid")      .
                                    $this->getOrderParams("service_name") .
                                    $this->getOrderParams("client_email") .
                                    $this->getOrderParams("client_phone") .
                                    $this->getOrderParams("secret_key");
        $sign = hash ('sha256', $to_hash);
        //lang parameter
        $lang = ($this->context->language->iso_code) ? $this->context->language->iso_code :"ru";
        $sb_title = ($lang == "ru") ?
                     "Оплата банковскими картами на сайте" :
                     "Pay with bank сard online";
        $postdata = array(
            'sum'             => $this->getOrderTotal(),
            'clientid'        => $clientid,
            'orderid'         => $orderid,
            'client_phone'    => $client_phone,
            'client_email'    => $client_email,
            'service_name'    => $service_name,
            'cart'            => htmlspecialchars($fiscal_cart_encoded,ENT_QUOTES),
            'sign'            => $sign,
            'url'             => $url,

        );
		$form = '
                <form name="payment" id="pay_form" action="'.$postdata['url'].'" accept-charset="utf-8" method="post">
                <input type="hidden" name="sum" value = "'.$postdata['sum'].'"/>
                <input type="hidden" name="orderid" value = "'.$postdata['orderid'].'"/>
                <input type="hidden" name="clientid" value = "'.$postdata['clientid'].'"/>
                <input type="hidden" name="client_email" value = "'.$postdata['client_email'].'"/>
                <input type="hidden" name="client_phone" value = "'.$postdata['client_phone'].'"/>
                <input type="hidden" name="service_name" value = "'.$postdata['service_name'].'"/>
                <input type="hidden" name="cart" value = \''.$postdata['cart'].'\' />
                <input type="hidden" name="sign" value = "'.$postdata['sign'].'"/>
                </form>
        <script>
        pay_form.submit();
        </script>';
		echo $form;
//         echo "<pre>";
//         print_r($this);
//         die;

	}
    public function updateFiscalCart($ftype, $name="", $price=0, $quantity=0, $sum=0, $tax="none", $item_type="goods",$payment_type ="prepay")
    {
        //update fz54 cart
        if ($ftype === "create") {
            $name = str_replace("\n ", "", $name);
            $name = str_replace("\r ", "", $name);
        }
        $this->fiscal_cart[] = array(
            "name" => $name,
            "price" => $price,
            "quantity" => $quantity,
            "sum" => $sum,
            "tax" => $tax,
            "item_type" => $item_type,
            "payment_type" => $payment_type
        );
    }

    public function setDiscounts($discount_enabled_flag)
    {
        $discount_modifier_value = 1;
        //set discounts
        if ($discount_enabled_flag) {

            if ($this->getFiscalCartSum(false) > 0)
                $discount_modifier_value = ($this->getOrderTotal() - $this->getShippingPrice())/$this->getFiscalCartSum(false);
            if ($discount_modifier_value < 1) {
                for ($pos=0; $pos<count($this->getFiscalCart()); $pos++) {//iterate fiscal cart without shipping
                    if ($pos != $this->delivery_index) {
                        $this->fiscal_cart[$pos]["sum"] *= $discount_modifier_value;
                        $this->fiscal_cart[$pos]["price"] = $this->fiscal_cart[$pos]["sum"]/$this->fiscal_cart[$pos]["quantity"];
                    }
                }
            }
        }
    }

    public function setTaxes($tax_rate, $zero_value_as_none = true)
    {
        $taxes = array("tax" => "none", "tax_sum" => 0);
        switch(number_format(floatval($tax_rate), 0, ".", "")) {
            case 0:
                if (!$zero_value_as_none) {
                    $taxes["tax"] = "vat0";
                }
                break;
            case 10:
                $taxes["tax"] = "vat10";
                break;
            case 20:
                $taxes["tax"] = "vat20";
                break;
            default:
                $taxes["tax"] = "none";
                break;
        }
        return $taxes;
        
    }
    public function setOrderParams($order_total = 0, $clientid="", $orderid="", $client_email="", $client_phone="", $service_name="", $form_url="", $secret_key="")
    {
        $this->setOrderTotal($order_total);
            $this->order_params = array(
            "sum" => $order_total,
            "clientid" => $clientid,
            "orderid" => $orderid,
            "client_email" => $client_email,
            "client_phone" => $client_phone,
            "service_name" => $service_name,
            "form_url" => $form_url,
            "secret_key" => $secret_key,
            );
    }

    private function setOrderTotal($value)
    {
        $this->order_total = $value;
    }
    public function getFiscalCart()
    {
        return $this->fiscal_cart;
    }
    private function getOrderTotal()
    {
        return $this->order_total;
    }
    public function setShippingPrice($value)
    {
        $this->shipping_price = $value;
    }

    public function getShippingPrice()
    {
        return $this->shipping_price;
    }

    public function getOrderParams($value)
    {
        return array_key_exists($value, $this->order_params) ? $this->order_params["$value"] : False;
    }
    public function setUseTaxes()
    {
        $this->use_taxes = True;
    }

    public function getUseTaxes()
    {
        return $this->use_taxes;
    }

    public function setUseDelivery()
    {
        $this->use_delivery = True;
    }

    public function getUseDelivery()
    {
        return $this->use_delivery;
    }
    public function getPaymentFormType()
    {
        if (strpos($this->order_params["form_url"], "/order/inline") == True)
            return "order";
        else
            return "create";
    }
    public function getFiscalCartSum($delivery_included) {
        $fiscal_cart_sum = 0;
        $index = 0;
        foreach ($this->getFiscalCart() as $item) {
            if (!$delivery_included && $index == $this->delivery_index)
                continue;
            $fiscal_cart_sum += $item["sum"];
            $index++;
        }
        return $fiscal_cart_sum;
    }
    public function checkDeliveryIncluded($delivery_price, $delivery_name) {
        $index = 0;
        foreach ($this->getFiscalCart() as $item) {
            if ($item["name"] == $delivery_name
                && $item["price"] == $delivery_price
                && $item["quantity"] == 1) {
                $this->delivery_index = $index;
                return true;

            }
            $index++;
        }
        return false;
    }
    public function correctPriceOfCartItem($corr_price_to_add, $item_position)
    {
        $item_sum = 0;
        $this->fiscal_cart[$item_position]["price"] += $corr_price_to_add;
        $item_sum = $this->fiscal_cart[$item_position]["price"]*$this->fiscal_cart[$item_position]["quantity"];
        $this->fiscal_cart[$item_position]["sum"] = $item_sum;
    }
    public function splitCartItem($cart_item_position)
    {
        $item_sum = 0;
        $item_price = 0;
        $item_quantity = 0;
        $item_price = $this->fiscal_cart[$cart_item_position]["price"];
        $item_quantity = $this->fiscal_cart[$cart_item_position]["quantity"]-1;
        $this->fiscal_cart[$cart_item_position]["quantity"] = $item_quantity; //decreese quantity by one
        $this->fiscal_cart[$cart_item_position]["sum"] = $item_price*$item_quantity; //new sum
        //add one cart item to the end of cart
        $this->updateFiscalCart(
            $this->getPaymentFormType(),
            $this->fiscal_cart[$cart_item_position]["name"],
            $item_price, 1, $item_price,
            $this->fiscal_cart[$cart_item_position]["tax"]);
    }
    public function correctPrecision()
    {
        //handle possible precision problem
        $fiscal_cart_sum = $this->getFiscalCartSum(true);
        $total_sum = $this->getOrderTotal();
        $diff_value = $total_sum - $fiscal_cart_sum;
        //debug_info
        //echo "\ntotal: $total_sum - cart: $fiscal_cart_sum - diff: $diff_sum";
        if (abs($diff_value) >= 0.005) {
            $diff_sum = number_format($diff_value, 2, ".", "");
            if ($this->getUseDelivery()) { //delivery is used
                $this->correctPriceOfCartItem($diff_sum, count($this->fiscal_cart)-1);
            }
            else {
                if ($this->single_item_index >= 0) { //we got single cart element
                    $this->correctPriceOfCartItem($diff_sum, $this->single_item_index);
                }
                else if ($this->more_then_one_item_index >= 0) { //we got cart element with more then one quantity
                    $this->splitCartItem($this->more_then_one_item_index);
                    //add diff_sum to the last element (just separated) of fiscal cart
                    $this->correctPriceOfCartItem($diff_sum, count($this->fiscal_cart)-1);
                }
                else { //we only got cart elements with less than one quantity
                    $modify_value = ($diff_sum > 0) ? $total_sum/$fiscal_cart_sum : $fiscal_cart_sum/$total_sum;
                    if ($diff_sum > 0) {
                        if ($fiscal_cart_sum > 0) { //divide by zero error
                            $modify_value = $total_sum/$fiscal_cart_sum;
                        }
                    }
                    else {
                        if ($total_sum > 0) { //divide by zero error
                            $modify_value = $fiscal_cart_sum/$total_sum;
                        }
                    }
                    for ($pos=0; $pos<count($this->getFiscalCart()); $pos++) {
                        if ($this->fiscal_cart[$pos]["quantity"] > 0) { //divide by zero error
                            $sum = $this->fiscal_cart[$pos]["sum"]*$modify_value;
                            $this->fiscal_cart[$pos]["sum"] *= number_format($sum, 2, ".", "");
                            $price = $this->fiscal_cart[$pos]["sum"]/$this->fiscal_cart[$pos]["quantity"];
                            $this->fiscal_cart[$pos]["price"] = number_format($price, 2, ".", "");
                        }
                    }
                }
            }
        }
    }
}
