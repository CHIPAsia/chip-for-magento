<?php
/**
 * CHIP return controller for Magento 2
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use CHIPAsia\ChipPaymentGateway\Model\Api;
use CHIPAsia\ChipPaymentGateway\Model\OrderUpdater;

/**
 * Handles the customer return from the CHIP payment page.
 */
class ReturnAction extends Action
{
    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var OrderUpdater
     */
    protected $orderUpdater;

    /**
     * @param Context $context
     * @param CheckoutSession $checkoutSession
     * @param OrderFactory $orderFactory
     * @param Api $api
     * @param EncryptorInterface $encryptor
     * @param OrderUpdater $orderUpdater
     */
    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        OrderFactory $orderFactory,
        Api $api,
        EncryptorInterface $encryptor,
        OrderUpdater $orderUpdater
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->orderFactory = $orderFactory;
        $this->api = $api;
        $this->encryptor = $encryptor;
        $this->orderUpdater = $orderUpdater;
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|ResultInterface
     */
    public function execute()
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId()) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        $payment = $order->getPayment();
        $purchaseId = $payment ? $payment->getAdditionalInformation('chip_purchase_id') : null;

        if ($purchaseId) {
            $secretKey = $order->getStore()->getConfig('payment/chip/secret_key');
            $brandId = $order->getStore()->getConfig('payment/chip/brand_id');

            if (preg_match('/^[0-9]+:[0-9]+:/', $secretKey)) {
                $decrypted = $this->encryptor->decrypt($secretKey);
                if ($decrypted !== '') {
                    $secretKey = $decrypted;
                }
            }

            $this->api->setCredentials($secretKey, $brandId);

            $paymentData = $this->api->getPayment($purchaseId);
            if (is_array($paymentData)) {
                $this->orderUpdater->update($order, $paymentData);
            }
        }

        if ($order->getState() === Order::STATE_PROCESSING) {
            return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
        }

        return $this->resultRedirectFactory->create()->setPath('checkout/onepage/failure');
    }
}
