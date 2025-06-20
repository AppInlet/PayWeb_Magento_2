<?php
/** @noinspection PhpMissingFieldTypeInspection */

/** @noinspection PhpPropertyOnlyWrittenInspection */

/** @noinspection PhpUnused */

/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

// @codingStandardsIgnoreFile

namespace PayGate\PayWeb\Model;

use JetBrains\PhpStorm\Pure;
use Magento\Directory\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Payment\Model\MethodInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Config model that is aware of all \PayGate\PayWeb payment methods
 * Works with Paygate-specific system configuration
 * @SuppressWarnings(PHPMD.ExcesivePublicCount)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Config extends AbstractConfig
{

    /**
     * @var Paygate this is a model which we will use.
     */
    const METHOD_CODE = 'paygate';

    /**
     * Core
     * data @var Data
     */
    protected Data $directoryHelper;

    /**
     * @var StoreManagerInterface
     */
    protected StoreManagerInterface $_storeManager;

    protected array $_supportedBuyerCountryCodes = ['ZA'];

    /**
     * Currency codes supported by Paygate methods
     * @var string[]
     */
    protected array $_supportedCurrencyCodes = ['USD', 'EUR', 'GPD', 'ZAR', 'BWP', 'NAD'];

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $_logger;

    /**
     * @var UrlInterface
     */
    protected UrlInterface $_urlBuilder;

    /**
     * @var Repository
     */
    protected Repository $_assetRepo;

    /**
     * @var ScopeConfigInterface
     */
    protected ScopeConfigInterface $scopeConfig;
    private $_supportedImageLocales;
    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Data $directoryHelper,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        Repository $assetRepo,
        EncryptorInterface $encryptor
    ) {
        $this->_logger = $logger;
        parent::__construct($scopeConfig);
        $this->directoryHelper = $directoryHelper;
        $this->_storeManager   = $storeManager;
        $this->_assetRepo      = $assetRepo;
        $this->scopeConfig     = $scopeConfig;
        $this->encryptor       = $encryptor;

        $this->setMethod('paygate');
        $currentStoreId = $this->_storeManager->getStore()->getStoreId();
        $this->setStoreId($currentStoreId);
    }

    /**
     * Check whether method available for checkout or not
     * Logic based on merchant country, methods dependence
     *
     * @param string|null $methodCode
     *
     * @return bool
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function isMethodAvailable(string $methodCode = null): bool
    {
        return parent::isMethodAvailable($methodCode);
    }

    /**
     * Return buyer country codes supported by Paygate
     *
     * @return string[]
     */
    public function getSupportedBuyerCountryCodes(): array
    {
        return $this->_supportedBuyerCountryCodes;
    }

    /**
     * Return merchant country code, use default country if it is not specified in General settings
     *
     * @return string
     */
    public function getMerchantCountry(): string
    {
        return $this->directoryHelper->getDefaultCountry($this->_storeId);
    }

    /**
     * Check whether method supported for specified country or not
     * Use $_methodCode and merchant country by default
     *
     * @param string|null $method
     * @param string|null $countryCode
     *
     * @return bool
     */
    public function isMethodSupportedForCountry(string $method = null, string $countryCode = null): bool
    {
        if ($method === null) {
            $method = $this->getMethodCode();
        }

        if ($countryCode === null) {
            $countryCode = $this->getMerchantCountry();
        }

        return in_array($method, $this->getCountryMethods($countryCode));
    }

    /**
     * Return list of allowed methods for specified country iso code
     *
     * @param string|null $countryCode 2-letters iso code
     *
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getCountryMethods(string $countryCode = null): array
    {
        $countryMethods = [
            'other' => [
                self::METHOD_CODE,
            ],

        ];
        if ($countryCode === null) {
            return $countryMethods;
        }

        return $countryMethods[$countryCode] ?? $countryMethods['other'];
    }

    /**
     * Get Paygate "mark" image URL
     *
     * @return string
     */
    public function getPaymentMarkImageUrl(): string
    {
        return $this->_assetRepo->getUrl('PayGate_PayWeb::images/paygate.svg');
    }

    /**
     * Get "What Is Paygate" localized URL
     * Supposed to be used with "mark" as popup window
     *
     * @return string
     */
    public function getPaymentMarkWhatIsPaygate(): string
    {
        return 'Paygate Payment gateway';
    }

    /**
     * Mapper from Paygate-specific payment actions to Magento payment actions
     *
     * @return string|null
     */
    public function getPaymentAction(): ?string
    {
        $paymentAction = null;
        $pre           = __METHOD__ . ' : ';
        $this->_logger->debug($pre . 'bof');

        $action = $this->getValue('paymentAction');

        switch ($action) {
            case self::PAYMENT_ACTION_AUTH:
                $paymentAction = MethodInterface::ACTION_AUTHORIZE;
                break;
            case self::PAYMENT_ACTION_SALE:
                $paymentAction = MethodInterface::ACTION_AUTHORIZE_CAPTURE;
                break;
            case self::PAYMENT_ACTION_ORDER:
                $paymentAction = MethodInterface::ACTION_ORDER;
                break;
            default:
                $this->_logger->error(
                    "$action not " . self::PAYMENT_ACTION_AUTH . " or " . self::PAYMENT_ACTION_SALE . " " . self::PAYMENT_ACTION_ORDER
                );
        }

        $this->_logger->debug($pre . 'eof : paymentAction is ' . $paymentAction);

        return $paymentAction;
    }

    /**
     * Check whether specified currency code is supported
     *
     * @param string $code
     *
     * @return bool
     */
    public function isCurrencyCodeSupported(string $code): bool
    {
        $supported = false;
        $pre       = __METHOD__ . ' : ';

        $this->_logger->debug($pre . "bof and code: $code");

        if (in_array($code, $this->_supportedCurrencyCodes)) {
            $supported = true;
        }

        $this->_logger->debug($pre . "eof and supported : $supported");

        return $supported;
    }

    public function getConfig($field, $store_id = null)
    {
        $path       = "payment/paygate/$field";
        $storeScope = ScopeInterface::SCOPE_STORE;

        $value = $this->scopeConfig->getValue($path, $storeScope);

        if ($store_id) {
            $value = $this->scopeConfig->getValue($path, $storeScope, $store_id);
        }

        return $value;
    }

    /**
     * Check isVault condition
     **/
    public function isVault()
    {
        return $this->getConfig('paygate_cc_vault_active');
    }

    /**
     * Get payment types
     **/
    public function getPaymentTypes()
    {
        return $this->getConfig('enable_payment_types');
    }

    /**
     * Check Payment Types Enabled in admin or not
     **/
    public function isEnabledPaymentTypes()
    {
        return $this->getConfig('paygate_pay_method_active');
    }

    /**
     * Check Force Payment Type Enabled in admin or not
     **/
    public function isForcePaymentType()
    {
        return $this->getConfig('paygate_force_payment_type');
    }

    /**
     * Check is test mode or live
     **/
    public function isTestMode(): bool
    {
        if ($this->getConfig('test_mode') == '1') {
            return true;
        }

        return false;
    }

    /**
     * Get Encryption key from configuration
     **/
    public function getEncryptionKey($store_id = null): string
    {
        $encryptionKey = $this->getConfig('encryption_key');
        if ($store_id) {
            $encryptionKey = $this->getConfig('encryption_key', $store_id);
        }
        $encryptionKey = $this->encryptor->decrypt($encryptionKey);
        if ($this->isTestMode()) {
            $encryptionKey = 'secret';
        }

        return $encryptionKey;
    }

    /**
     * Get Paygate id from configuration
     **/
    public function getPaygateId($store_id = null): string
    {
        $paygateId = trim($this->getConfig('paygate_id'));
        if ($store_id) {
            $paygateId = trim($this->getConfig('paygate_id', $store_id));
        }

        if ($this->isTestMode()) {
            $paygateId = '10011072130';
        }

        return $paygateId;
    }

    /**
     * Get Paygate id from configuration
     **/
    public function getEnableLogging(): string
    {
        return trim($this->getConfig('enable_logging'));
    }

    /**
     * Check whether specified locale code is supported. Fallback to en_US
     *
     * @param string|null $localeCode
     *
     * @return string
     */
    protected function _getSupportedLocaleCode(string $localeCode = null): string
    {
        if (!$localeCode || !in_array($localeCode, $this->_supportedImageLocales)) {
            return 'en_US';
        }

        return $localeCode;
    }

    /**
     * _mapPayGateFieldset
     * Map Paygate config fields
     *
     * @param string $fieldName
     *
     * @return string|null
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _mapPayGateFieldset(string $fieldName): ?string
    {
        return "payment/$this->_methodCode/$fieldName";
    }

    /**
     * Map any supported payment method into a config path by specified field name
     *
     * @param string $fieldName
     *
     * @return string|null
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    #[Pure] protected function _getSpecificConfigPath(string $fieldName): ?string
    {
        return $this->_mapPayGateFieldset($fieldName);
    }
}
