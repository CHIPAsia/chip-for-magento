<?php
/**
 * CHIP payment method model for Magento 2
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\DataObject;

/**
 * CHIP payment method.
 *
 * Redirect-based payment method compatible with Magento 2.0 - 2.4.
 */
class Chip extends AbstractMethod
{
    const CODE = 'chip';

    /**
     * Interchangeable payment-method groups (same as WooCommerce/GiveWP).
     * dnqr is preferred over duitnow_qr; shopee_pay over razer_shopeepay.
     */
    const DUITNOW_GROUP = array('duitnow_qr', 'dnqr');

    const SHOPEE_GROUP = array('razer_shopeepay', 'shopee_pay');

    /**
     * The 'card' key is an aggregator shown in admin; the gateway only
     * accepts the individual card networks.
     */
    const CARD_GROUP = array('visa', 'mastercard', 'maestro');

    const PAYMENT_METHODS = array(
        'fpx' => 'FPX',
        'fpx_b2b1' => 'FPX B2B1',
        'card' => 'Card (Visa, Mastercard, Maestro)',
        'mpgs_google_pay' => 'Google Pay',
        'mpgs_apple_pay' => 'Apple Pay',
        'razer_atome' => 'Atome',
        'razer_grabpay' => 'GrabPay',
        'razer_maybankqr' => 'Maybank QRPay',
        'shopee_pay' => 'ShopeePay',
        'razer_tng' => "Touch 'n Go eWallet",
        'duitnow_qr' => 'DuitNow QR',
        'crypto_coin' => 'Crypto Coin',
    );

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isGateway = true;

    /**
     * @var bool
     */
    protected $_canAuthorize = true;

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canCapturePartial = false;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @var bool
     */
    protected $_canVoid = true;

    /**
     * @var bool
     */
    protected $_canCancel = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = false;

    /**
     * @var bool
     */
    protected $_canUseCheckout = true;

    /**
     * @var bool
     */
    protected $_canUseForMultishipping = false;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * @var bool
     */
    protected $_canFetchTransactionInfo = true;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var ProductMetadataInterface
     */
    protected $productMetadata;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param UrlInterface $urlBuilder
     * @param ProductMetadataInterface $productMetadata
     * @param Api $api
     * @param EncryptorInterface $encryptor
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        UrlInterface $urlBuilder,
        ProductMetadataInterface $productMetadata,
        Api $api,
        EncryptorInterface $encryptor,
        array $data = array()
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            null,
            null,
            $data
        );

        $this->urlBuilder = $urlBuilder;
        $this->productMetadata = $productMetadata;
        $this->api = $api;
        $this->encryptor = $encryptor;
    }

    /**
     * Check whether payment method can be used.
     *
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }

        $storeId = $quote ? $quote->getStoreId() : null;
        $secretKey = $this->getSecretKey($storeId);
        $brandId = $this->getConfigData('brand_id', $storeId);

        return !empty($secretKey) && !empty($brandId);
    }

    /**
     * Initialize payment.
     *
     * @param string $paymentAction
     * @param \Magento\Framework\DataObject $stateObject
     * @return $this
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();

        $order->setCanSendNewEmailFlag(false);
        $payment->setAmountAuthorized($order->getTotalDue());
        $payment->setBaseAmountAuthorized($order->getBaseTotalDue());

        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * Get redirect URL after order placement.
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        if ($this->urlBuilder) {
            return $this->urlBuilder->getUrl('chip/payment/redirect');
        }
        return 'chip/payment/redirect';
    }

    /**
     * Create CHIP purchase and return checkout URL.
     *
     * @param Order $order
     * @return string
     * @throws LocalizedException
     */
    public function createPurchase(Order $order)
    {
        $this->setStore($order->getStoreId());

        $secretKey = $this->getSecretKey();
        $brandId = $this->getConfigData('brand_id');

        if (empty($secretKey) || empty($brandId)) {
            throw new LocalizedException(__('CHIP payment gateway is not configured.'));
        }

        $this->api->setCredentials($secretKey, $brandId);

        $storeId = $order->getStoreId();
        $baseUrl = $this->urlBuilder->getBaseUrl(array('_scope' => $storeId));

        $callbackUrl = $baseUrl . 'chip/payment/callback';
        $returnUrl = $baseUrl . 'chip/payment/returnaction';

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $params = array(
            'success_callback' => $callbackUrl,
            'success_redirect' => $returnUrl,
            'failure_redirect' => $returnUrl,
            'cancel_redirect' => $returnUrl,
            'force_recurring' => false,
            'send_receipt' => (bool) $this->getConfigData('send_receipt'),
            'creator_agent' => 'Magento: ' . $this->getModuleVersion(),
            'reference' => $order->getIncrementId(),
            'platform' => 'magento',
            'purchase' => array(
                'total_override' => (int) round($order->getGrandTotal() * 100),
                'due_strict' => (bool) $this->getConfigData('due_strict'),
                'timezone' => 'Asia/Kuala_Lumpur',
                'currency' => $order->getOrderCurrencyCode(),
                'language' => 'en',
                'products' => $this->getProducts($order),
            ),
            'brand_id' => $brandId,
            'client' => array(
                'email' => $order->getCustomerEmail(),
                'phone' => $billingAddress ? substr($billingAddress->getTelephone(), 0, 32) : '',
                'full_name' => $billingAddress ? substr(
                    trim($billingAddress->getFirstname() . ' ' . $billingAddress->getLastname()),
                    0,
                    128
                ) : '',
                'street_address' => $billingAddress ? substr(
                    $billingAddress->getStreetLine(1) . ' ' . $billingAddress->getStreetLine(2),
                    0,
                    128
                ) : '',
                'country' => $billingAddress ? substr($billingAddress->getCountryId(), 0, 2) : '',
                'city' => $billingAddress ? substr($billingAddress->getCity(), 0, 128) : '',
                'zip_code' => $billingAddress ? substr($billingAddress->getPostcode(), 0, 32) : '',
                'state' => $billingAddress ? substr($billingAddress->getRegion(), 0, 128) : '',
                'shipping_street_address' => $shippingAddress ? substr(
                    $shippingAddress->getStreetLine(1) . ' ' . $shippingAddress->getStreetLine(2),
                    0,
                    128
                ) : '',
                'shipping_country' => $shippingAddress ? substr($shippingAddress->getCountryId(), 0, 2) : '',
                'shipping_city' => $shippingAddress ? substr($shippingAddress->getCity(), 0, 128) : '',
                'shipping_zip_code' => $shippingAddress ? substr($shippingAddress->getPostcode(), 0, 32) : '',
                'shipping_state' => $shippingAddress ? substr($shippingAddress->getRegion(), 0, 128) : '',
            ),
        );

        $whitelist = $this->getPaymentMethodWhitelist();
        if (!empty($whitelist)) {
            $params['payment_method_whitelist'] = $this->resolvePaymentMethodGroups(
                $whitelist,
                $order->getOrderCurrencyCode(),
                (int) round($order->getGrandTotal() * 100)
            );
        }

        $payment = $this->api->createPayment($params);

        if (!is_array($payment) || !isset($payment['id']) || !isset($payment['checkout_url'])) {
            throw new LocalizedException(__('Unable to create CHIP payment. Please try again.'));
        }

        $paymentInfo = $order->getPayment();
        $paymentInfo->setAdditionalInformation('chip_purchase_id', $payment['id']);
        $paymentInfo->setAdditionalInformation('chip_checkout_url', $payment['checkout_url']);
        $paymentInfo->setTransactionId($payment['id']);
        $paymentInfo->save();

        return $payment['checkout_url'];
    }

    /**
     * Refund the payment via CHIP API.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        if (!$order || !$order->getId()) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Unable to process refund: order not found.')
            );
        }

        $purchaseId = $payment->getAdditionalInformation('chip_purchase_id');
        if (empty($purchaseId)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Unable to process refund: CHIP purchase ID not found.')
            );
        }

        $this->setStore($order->getStoreId());

        $secretKey = $this->getSecretKey();
        $brandId = $this->getConfigData('brand_id');

        if (empty($secretKey) || empty($brandId)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('CHIP payment gateway is not configured.')
            );
        }

        $this->api->setCredentials($secretKey, $brandId);

        $params = array(
            'amount' => (int) round($amount * 100),
        );

        $result = $this->api->refundPayment($purchaseId, $params);

        if (!is_array($result) || !isset($result['id'])) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Unable to refund CHIP payment. Please try again.')
            );
        }

        $payment->setTransactionId($result['id']);
        $payment->setIsTransactionClosed(true);
        $payment->setShouldCloseParentTransaction(true);

        return $this;
    }

    /**
     * Get the CHIP secret key, decrypting it if necessary.
     *
     * The stored value is encrypted by Magento's Encrypted backend model.
     * On some versions/paths the config pipeline returns the raw ciphertext
     * and on others the decrypted value; decrypt only when the value looks
     * like ciphertext (Magento ciphertext has a key:crypt prefix).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSecretKey($storeId = null)
    {
        $value = $this->getConfigData('secret_key', $storeId);
        if (empty($value)) {
            return '';
        }

        if ($this->encryptor && preg_match('/^[0-9]+:[0-9]+:/', $value)) {
            $decrypted = $this->encryptor->decrypt($value);
            if ($decrypted !== '') {
                return $decrypted;
            }
        }

        return $value;
    }

    /**
     * Get configured payment method whitelist.
     *
     * @return array
     */
    public function getPaymentMethodWhitelist()
    {
        $value = $this->getConfigData('payment_method_whitelist');
        if (empty($value)) {
            return array();
        }

        if (is_array($value)) {
            return $value;
        }

        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Resolve the configured payment method whitelist against the merchant's
     * actual /payment_methods/ response, with preferred-method priority for
     * the DuitNow QR and Shopee Pay groups (same as WooCommerce/GiveWP):
     *
     *   - DuitNow QR group: dnqr wins when both {duitnow_qr, dnqr} are present.
     *   - Shopee Pay group: shopee_pay wins when both {razer_shopeepay, shopee_pay}
     *     are present; razer_shopeepay is the fallback.
     *   - 'card' aggregator is expanded to the individual card networks
     *     (visa/mastercard/maestro) which is what the gateway accepts.
     *
     * @param array  $whitelist Configured payment_method_whitelist.
     * @param string $currency  Order currency code (e.g. 'MYR').
     * @param int    $amount    Order total in sen (e.g. 12345 = RM 123.45).
     * @return array Final whitelist to send to CHIP.
     */
    public function resolvePaymentMethodGroups($whitelist, $currency, $amount)
    {
        $has_dnqr = count(array_intersect($whitelist, self::DUITNOW_GROUP)) > 0;
        $has_shopee = count(array_intersect($whitelist, self::SHOPEE_GROUP)) > 0;
        $has_card = in_array('card', $whitelist, true);

        // Short-circuit: no group member configured -> return untouched
        // (group members can never appear unless the merchant configured them).
        if (!$has_dnqr && !$has_shopee && !$has_card) {
            return $whitelist;
        }

        $expanded = $whitelist;
        if ($has_dnqr) {
            $expanded = array_values(array_unique(array_merge($expanded, self::DUITNOW_GROUP)));
        }
        if ($has_shopee) {
            $expanded = array_values(array_unique(array_merge($expanded, self::SHOPEE_GROUP)));
        }
        if ($has_card) {
            $expanded = array_values(array_unique(array_merge($expanded, self::CARD_GROUP)));
        }

        $response = $this->api->getPaymentMethods($currency, 'en', $amount);
        if (!is_array($response) || !isset($response['available_payment_methods'])) {
            // API failed -> fallback to expanded whitelist unchanged.
            return $expanded;
        }
        $available = $response['available_payment_methods'];

        $resolved_dnqr = $has_dnqr ? array_values(array_intersect(self::DUITNOW_GROUP, $available)) : array();
        $resolved_shopee = $has_shopee ? array_values(array_intersect(self::SHOPEE_GROUP, $available)) : array();
        $resolved_card = $has_card ? array_values(array_intersect(self::CARD_GROUP, $available)) : array();

        // Priority: dnqr wins over duitnow_qr; shopee_pay wins over razer_shopeepay.
        if (in_array('dnqr', $resolved_dnqr, true)) {
            $resolved_dnqr = array_values(array_diff($resolved_dnqr, array('duitnow_qr')));
        }
        if (in_array('shopee_pay', $resolved_shopee, true)) {
            $resolved_shopee = array_values(array_diff($resolved_shopee, array('razer_shopeepay')));
        }

        $all_groups = array_merge(self::DUITNOW_GROUP, self::SHOPEE_GROUP, self::CARD_GROUP, array('card'));
        $final = array_values(array_diff($expanded, $all_groups));
        $final = array_merge($final, $resolved_dnqr, $resolved_shopee, $resolved_card);

        return $final;
    }

    /**
     * Get order products for CHIP purchase.
     *
     * @param Order $order
     * @return array
     */
    protected function getProducts(Order $order)
    {
        $products = array();

        foreach ($order->getAllVisibleItems() as $item) {
            $products[] = array(
                'name' => substr($item->getName(), 0, 256),
                'price' => (int) round($item->getPrice() * 100),
                'quantity' => (int) $item->getQtyOrdered(),
            );
        }

        if (empty($products)) {
            $products[] = array(
                'name' => 'Order ' . $order->getIncrementId(),
                'price' => (int) round($order->getGrandTotal() * 100),
                'quantity' => 1,
            );
        }

        return $products;
    }

    /**
     * Get module version.
     *
     * @return string
     */
    protected function getModuleVersion()
    {
        if ($this->productMetadata) {
            return $this->productMetadata->getVersion();
        }
        return '2.x';
    }
}
