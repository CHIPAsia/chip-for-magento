<?php
/**
 * CHIP pending order checker cron for Magento 2
 *
 * Reconciles orders stuck in pending_payment with the CHIP API:
 * the webhook is the source of truth, but a webhook can be lost
 * (network, downtime). This cron polls CHIP for any purchase that
 * is older than the configured threshold and applies the final
 * state through OrderUpdater (same idempotent, lock-protected path
 * as the webhook callback).
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Cron;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Psr\Log\LoggerInterface;
use CHIPAsia\ChipPaymentGateway\Model\Api;
use CHIPAsia\ChipPaymentGateway\Model\OrderUpdater;

/**
 * Reconciles old pending_payment orders against the CHIP gateway.
 */
class PendingOrderChecker
{
    /**
     * Default age threshold (minutes) before an order is queried.
     */
    const DEFAULT_THRESHOLD_MINUTES = 120;

    /**
     * @var CollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var OrderUpdater
     */
    protected $orderUpdater;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param CollectionFactory $orderCollectionFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param Api $api
     * @param OrderUpdater $orderUpdater
     * @param LoggerInterface $logger
     */
    public function __construct(
        CollectionFactory $orderCollectionFactory,
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        Api $api,
        OrderUpdater $orderUpdater,
        LoggerInterface $logger
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->api = $api;
        $this->orderUpdater = $orderUpdater;
        $this->logger = $logger;
    }

    /**
     * Check pending orders and reconcile with CHIP.
     *
     * @return void
     */
    public function execute()
    {
        $threshold = (int) $this->scopeConfig->getValue(
            'payment/chip/pending_threshold',
            ScopeInterface::SCOPE_STORE
        );
        if ($threshold <= 0) {
            $threshold = self::DEFAULT_THRESHOLD_MINUTES;
        }

        $cutoff = new \DateTime('-' . $threshold . ' minutes');

        $collection = $this->orderCollectionFactory->create();
        $collection->addAttributeToFilter('state', Order::STATE_PENDING_PAYMENT);
        $collection->addAttributeToFilter('updated_at', array('lt' => $cutoff->format('Y-m-d H:i:s')));
        $collection->getSelect()->limit(50);

        $processed = 0;
        foreach ($collection as $order) {
            if ($this->reconcile($order)) {
                $processed++;
            }
        }

        if ($processed > 0) {
            $this->logger->info('CHIP pending order check: reconciled ' . $processed . ' order(s)');
        }
    }

    /**
     * Reconcile a single order against the CHIP gateway.
     *
     * @param Order $order
     * @return bool True when the order was checked (not necessarily changed).
     */
    protected function reconcile(Order $order)
    {
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== \CHIPAsia\ChipPaymentGateway\Model\Chip::CODE) {
            return false;
        }

        $purchaseId = $payment->getAdditionalInformation('chip_purchase_id');
        if (empty($purchaseId)) {
            return false;
        }

        $storeId = $order->getStoreId();
        $secretKey = $this->scopeConfig->getValue(
            'payment/chip/secret_key',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $brandId = $this->scopeConfig->getValue(
            'payment/chip/brand_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (empty($secretKey) || empty($brandId)) {
            return false;
        }

        if (preg_match('/^[0-9]+:[0-9]+:/', $secretKey)) {
            $decrypted = $this->encryptor->decrypt($secretKey);
            if ($decrypted !== '') {
                $secretKey = $decrypted;
            }
        }

        $this->api->setCredentials($secretKey, $brandId);
        $purchase = $this->api->getPayment($purchaseId);
        if (!is_array($purchase) || !isset($purchase['status'])) {
            return false;
        }

        $this->orderUpdater->update($order, $purchase);

        return true;
    }
}
