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
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Exception\LocalizedException;
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
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var ProductMetadataInterface
     */
    protected $productMetadata;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     * @param UrlInterface $urlBuilder
     * @param CheckoutSession $checkoutSession
     * @param ProductMetadataInterface $productMetadata
     * @param Api $api
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = array(),
        UrlInterface $urlBuilder = null,
        CheckoutSession $checkoutSession = null,
        ProductMetadataInterface $productMetadata = null,
        Api $api = null
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->urlBuilder = $urlBuilder;
        $this->checkoutSession = $checkoutSession;
        $this->productMetadata = $productMetadata;
        $this->api = $api;
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
        $secretKey = $this->getConfigData('secret_key', $storeId);
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

        $secretKey = $this->getConfigData('secret_key');
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
            $params['payment_method_whitelist'] = $whitelist;
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

        $secretKey = $this->getConfigData('secret_key');
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
