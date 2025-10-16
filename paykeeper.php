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

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Paykeeper extends PaymentModule
{


    protected $_html = '';
    protected $_postErrors = array();

    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;

    public function __construct()
    {
        $this->name = 'paykeeper';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => '1.7.9.9');
        $this->author = 'PayKeeper';
        $this->controllers = array('payment');

        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('PayKeeper');
        $this->description = $this->l('PayKeeper payment module');
        $this->id_lang = $this->context->language->id;
        // $this->displayName = $this->trans('PayKeeper', array(), 'Modules.PayKeeper.Admin');
        // $this->description = $this->trans('Accepting payments via online payment cards', array(), 'Modules.PayKeeper.Admin');
        // $this->confirmUninstall = $this->trans('Are you sure about removing these details?', array(), 'Modules.Wirepayment.Admin');

    }

    public function install()
    {
        return parent::install() && $this->registerHook('paymentOptions');
    }

    public function uninstall()
    {
        Configuration::deleteByName('PAYKEEPER_URL');
        Configuration::deleteByName('PAYKEEPER_SECRET');
        Configuration::deleteByName('STATE_BEFORE_PAYMENT');
        Configuration::deleteByName('STATE_AFTER_PAYMENT');

        return parent::uninstall();
    }

    protected function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            if (!Tools::getValue('PAYKEEPER_URL')) {
                $this->_postErrors[] = $this->trans('Paykeeper payment reference required.', array(), 'Modules.Paykeeper.Admin');
            } elseif (!Tools::getValue('PAYKEEPER_SECRET')) {
                $this->_postErrors[] = $this->trans('Secret word for Paykeeper is required.', array(), "Modules.Paykeeper.Admin");
            } elseif (!Tools::getValue('STATE_BEFORE_PAYMENT')) {
                $this->_postErrors[] = $this->trans('order state before is required.', array(), "Modules.Paykeeper.Admin");
            } elseif (!Tools::getValue('STATE_AFTER_PAYMENT')) {
                $this->_postErrors[] = $this->trans('order state after is required.', array(), "Modules.Paykeeper.Admin");
            }
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('submitPaykeeperModule')) {
            $form_values = $this->getConfigFormValues();

            foreach (array_keys($form_values) as $key) {
                Configuration::updateValue($key, Tools::getValue($key));
            }
        }
    }

    public function getContent()
    {
        if (Tools::isSubmit('submitPaykeeperModule')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } 
        return $this->renderForm();
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }
        $newOption = new PaymentOption();
        $newOption->setModuleName($this->name)
                ->setCallToActionText($this->trans('Оплата картами банка на сайте', array(), 'Modules.Paykeeper.Shop'))
                ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true));
        $newOption->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/logo.png'));
        $payment_options = [
            $newOption,
        ];

        return $payment_options;
    }

    public function renderForm()
    {
        $options = [] ;
        $states_ar = new OrderStateCore();
        foreach ($states_ar->getOrderStates($this->context->language->id) as $state) {
            $options[] = ['status_id'=> $state['id_order_state'],'status_name'=> $state['name']];
        }
        $fields = array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'name' => 'PAYKEEPER_URL',
                        'label' => $this->l('URL формы'),
                    ),
                    array(
                        'type' => 'text',
                        'name' => 'PAYKEEPER_SECRET',
                        'label' => $this->l('Секретный ключ'),
                    ),
                    array(
						'type' => 'switch',
						'label' => $this->l('Принудительное использование скидок'),
						'name' => 'FORCE_DISCOUNT_CHECK',
						'values' => array(
									array(
										'id' => 'active_on',
										'value' => 1,
										'label' => $this->l('Enabled')
									),
									array(
										'id' => 'active_off',
										'value' => 0,
										'label' => $this->l('Disabled')
									)
								),
					),
                    array(
                        'type' => 'select',
                        'name' => 'STATE_BEFORE_PAYMENT',
                        'label' => $this->l('Статус заказа до подтверждения оплаты'),
                        'options' => array(
                            'query' => $options,                           // $options contains the data itself.
                            'id' => 'status_id',                           // The value of the 'id' key must be the same as the key for 'value' attribute of the <option> tag in each $options sub-array.
                            'name' => 'status_name'                               // The value of the 'name' key must be the same as the key for the text content of the <option> tag in each $options sub-array.
                          )
                    ),
                    array(
                        'type' => 'select',
                        'name' => 'STATE_AFTER_PAYMENT',
                        'label' => $this->l('Статус заказа после подтверждения оплаты'),
                        'options' => array(
                            'query' => $options,                           // $options contains the data itself.
                            'id' => 'status_id',                           // The value of the 'id' key must be the same as the key for 'value' attribute of the <option> tag in each $options sub-array.
                            'name' => 'status_name'                               // The value of the 'name' key must be the same as the key for the text content of the <option> tag in each $options sub-array.
                          )
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );

        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPaykeeperModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($fields));
    }

    protected function getConfigFormValues()
    {
        return array(            
            'PAYKEEPER_URL' => Configuration::get('PAYKEEPER_URL', null),
            'PAYKEEPER_SECRET' => Configuration::get('PAYKEEPER_SECRET', null),
            'STATE_BEFORE_PAYMENT'=> Configuration::get('STATE_BEFORE_PAYMENT', null),
            'STATE_AFTER_PAYMENT'=> Configuration::get('STATE_AFTER_PAYMENT', null),
            'FORCE_DISCOUNT_CHECK'=> Configuration::get('FORCE_DISCOUNT_CHECK', null)
        );
    }
}
