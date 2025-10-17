<?php
/**
 * PayKeeper payment module for PrestaShop 9
 *
 * @author    PayKeeper
 * @copyright 2024
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Paykeeper extends PaymentModule
{
    public const CONFIG_URL = 'PAYKEEPER_URL';
    public const CONFIG_SECRET = 'PAYKEEPER_SECRET';
    public const CONFIG_STATE_BEFORE = 'STATE_BEFORE_PAYMENT';
    public const CONFIG_STATE_AFTER = 'STATE_AFTER_PAYMENT';
    public const CONFIG_FORCE_DISCOUNT = 'FORCE_DISCOUNT_CHECK';

    private const DEFAULT_FORM_URL = 'http://planetofstones.server.paykeeper.ru/create';
    private const DEFAULT_SECRET_WORD = '2]yGy2-W.G5gCOoKd';

    public static function getDefaultFormUrl(): string
    {
        return self::DEFAULT_FORM_URL;
    }

    public static function getDefaultSecretWord(): string
    {
        return self::DEFAULT_SECRET_WORD;
    }

    /**
     * @var array<int, string>
     */
    private $postErrors = [];

    public function __construct()
    {
        $this->name = 'paykeeper';
        $this->tab = 'payments_gateways';
        $this->version = '3.0.0';
        $this->author = 'PayKeeper';
        $this->need_instance = 0;
        $this->controllers = ['payment', 'callback'];
        $this->ps_versions_compliancy = [
            'min' => '9.0.0',
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('PayKeeper', [], 'Modules.Paykeeper.Admin');
        $this->description = $this->trans('Accept payments with PayKeeper bank cards.', [], 'Modules.Paykeeper.Admin');
        $this->confirmUninstall = $this->trans('Are you sure you want to remove the PayKeeper integration?', [], 'Modules.Paykeeper.Admin');
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->setDefaultConfiguration();
    }

    public function uninstall(): bool
    {
        foreach ($this->getConfigKeys() as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function getContent(): string
    {
        if (Tools::isSubmit('submitPaykeeperModule')) {
            $this->validateConfiguration();
            if (empty($this->postErrors)) {
                $this->saveConfiguration();
                $this->context->controller->confirmations[] = $this->trans('Settings updated successfully.', [], 'Admin.Notifications.Success');
            } else {
                foreach ($this->postErrors as $error) {
                    $this->context->controller->errors[] = $error;
                }
            }
        }

        return $this->renderForm();
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<int, PaymentOption>
     */
    public function hookPaymentOptions(array $params): array
    {
        if (!$this->active) {
            return [];
        }

        $paymentOption = (new PaymentOption())
            ->setModuleName($this->name)
            ->setCallToActionText($this->trans('Оплата банковскими картами на сайте', [], 'Modules.Paykeeper.Shop'))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', [], true));

        $logoPath = _PS_MODULE_DIR_ . $this->name . '/logo.png';
        if (is_file($logoPath)) {
            $paymentOption->setLogo(Media::getMediaPath($logoPath));
        }

        return [$paymentOption];
    }

    private function setDefaultConfiguration(): bool
    {
        Configuration::updateValue(self::CONFIG_URL, self::DEFAULT_FORM_URL);
        Configuration::updateValue(self::CONFIG_SECRET, self::DEFAULT_SECRET_WORD);
        Configuration::updateValue(self::CONFIG_STATE_BEFORE, (int) Configuration::get('PS_OS_PAYMENT', null));
        Configuration::updateValue(self::CONFIG_STATE_AFTER, (int) Configuration::get('PS_OS_PAYMENT', null));
        Configuration::updateValue(self::CONFIG_FORCE_DISCOUNT, 0);

        return true;
    }

    private function validateConfiguration(): void
    {
        $this->postErrors = [];

        if (!Tools::getValue(self::CONFIG_URL)) {
            $this->postErrors[] = $this->trans('The PayKeeper form URL is required.', [], 'Modules.Paykeeper.Admin');
        }

        if (!Tools::getValue(self::CONFIG_SECRET)) {
            $this->postErrors[] = $this->trans('The secret word for PayKeeper is required.', [], 'Modules.Paykeeper.Admin');
        }

        if (!(int) Tools::getValue(self::CONFIG_STATE_BEFORE)) {
            $this->postErrors[] = $this->trans('Select the order state before payment confirmation.', [], 'Modules.Paykeeper.Admin');
        }

        if (!(int) Tools::getValue(self::CONFIG_STATE_AFTER)) {
            $this->postErrors[] = $this->trans('Select the order state after successful payment.', [], 'Modules.Paykeeper.Admin');
        }
    }

    private function saveConfiguration(): void
    {
        foreach ($this->getConfigKeys() as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    private function renderForm(): string
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPaykeeperModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfigFormValues(): array
    {
        $formUrl = (string) Configuration::get(self::CONFIG_URL, '');
        $secret = (string) Configuration::get(self::CONFIG_SECRET, '');

        if ($formUrl === '') {
            $formUrl = self::getDefaultFormUrl();
        }

        if ($secret === '') {
            $secret = self::getDefaultSecretWord();
        }

        return [
            self::CONFIG_URL => $formUrl,
            self::CONFIG_SECRET => $secret,
            self::CONFIG_STATE_BEFORE => (int) Configuration::get(self::CONFIG_STATE_BEFORE, null),
            self::CONFIG_STATE_AFTER => (int) Configuration::get(self::CONFIG_STATE_AFTER, null),
            self::CONFIG_FORCE_DISCOUNT => (int) Configuration::get(self::CONFIG_FORCE_DISCOUNT, 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfigForm(): array
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Modules.Paykeeper.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'name' => self::CONFIG_URL,
                        'label' => $this->trans('Payment form URL', [], 'Modules.Paykeeper.Admin'),
                        'required' => true,
                        'desc' => $this->trans('The PayKeeper payment form endpoint provided by the bank.', [], 'Modules.Paykeeper.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'name' => self::CONFIG_SECRET,
                        'label' => $this->trans('Secret key', [], 'Modules.Paykeeper.Admin'),
                        'required' => true,
                        'desc' => $this->trans('Use the secret word from your PayKeeper back office.', [], 'Modules.Paykeeper.Admin'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Force discount recalculation', [], 'Modules.Paykeeper.Admin'),
                        'name' => self::CONFIG_FORCE_DISCOUNT,
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', [], 'Admin.Global'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', [], 'Admin.Global'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'select',
                        'name' => self::CONFIG_STATE_BEFORE,
                        'label' => $this->trans('Order state before payment', [], 'Modules.Paykeeper.Admin'),
                        'options' => [
                            'query' => $this->getOrderStates(),
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'name' => self::CONFIG_STATE_AFTER,
                        'label' => $this->trans('Order state after payment', [], 'Modules.Paykeeper.Admin'),
                        'options' => [
                            'query' => $this->getOrderStates(),
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getOrderStates(): array
    {
        $orderState = new OrderState();
        $states = $orderState->getOrderStates((int) $this->context->language->id);

        return is_array($states) ? $states : [];
    }

    /**
     * @return array<int, string>
     */
    private function getConfigKeys(): array
    {
        return [
            self::CONFIG_URL,
            self::CONFIG_SECRET,
            self::CONFIG_STATE_BEFORE,
            self::CONFIG_STATE_AFTER,
            self::CONFIG_FORCE_DISCOUNT,
        ];
    }
}
