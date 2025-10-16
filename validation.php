<?php
/*
* 2007-2015 PrestaShop
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
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/paykeeper.php');

$pk = new Paykeeper();
$secret_seed = Configuration::get('PAYKEEPER_SECRET', null);
$id = $_POST['id'];
$sum = $_POST['sum'];
$clientid = $_POST['clientid'];
$orderid = $_POST['orderid'];
$secure_key = $_POST['service_name'];
$key = $_POST['key'];
if ($key != md5 ($id . number_format($sum, 2, ".", "").
                             $clientid.$orderid.$secret_seed))
{
  echo "Error! Hash mismatch";
  exit;
}
//проверка совпадения суммы
$order = new OrderCore($orderid,$pk->id_lang);
if ($order->total_paid != $sum)
{
  die("Error. Sums are not equal");
}
$history = new OrderHistory();
$history->id_order = (int)$orderid;
$history->changeIdOrderState(Configuration::get('STATE_AFTER_PAYMENT'), (int)$orderid);
$history->save();
$message = "OK ".md5($id.$secret_seed);
echo $message;
