<?php
/**
 * CHIP callback (webhook) controller for Magento 2
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
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use CHIPAsia\ChipPaymentGateway\Model\Api;
use CHIPAsia\ChipPaymentGateway\Model\SignatureVerifier;
use CHIPAsia\ChipPaymentGateway\Model\OrderUpdater;
use CHIPAsia\ChipPaymentGateway\Model\TokenManager;

/**
 * Base callback logic shared by all Magento versions.
 */
class CallbackBase extends Action
{
    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var OrderResource
     */
    protected $orderResource;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var SignatureVerifier
     */
    protected $signatureVerifier;

    /**
     * @var OrderUpdater
     */
    protected $orderUpdater;

    /**
     * @var TokenManager
     */
    protected $tokenManager;

    /**
     * @param Context $context
     * @param OrderFactory $orderFactory
     * @param OrderResource $orderResource
     * @param Api $api
     * @param SignatureVerifier $signatureVerifier
     * @param OrderUpdater $orderUpdater
     * @param TokenManager $tokenManager
     */
    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        OrderResource $orderResource,
        Api $api,
        SignatureVerifier $signatureVerifier,
        OrderUpdater $orderUpdater,
        TokenManager $tokenManager
    ) {
        $this->orderFactory = $orderFactory;
        $this->orderResource = $orderResource;
        $this->api = $api;
        $this->signatureVerifier = $signatureVerifier;
        $this->orderUpdater = $orderUpdater;
        $this->tokenManager = $tokenManager;
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|ResultInterface
     */
    public function execute()
    {
        $content = $this->getRequest()->getContent();
        $signature = $this->getRequest()->getHeader('X-Signature');

        if (empty($content)) {
            return $this->getResponse()->setHttpResponseCode(400);
        }

        $paymentData = json_decode($content, true);
        if (!is_array($paymentData) || !isset($paymentData['id'])) {
            return $this->getResponse()->setHttpResponseCode(400);
        }

        if (!$this->signatureVerifier->verify($content, $signature)) {
            return $this->getResponse()->setHttpResponseCode(401);
        }

        $reference = isset($paymentData['reference']) ? $paymentData['reference'] : null;
        if (empty($reference)) {
            return $this->getResponse()->setHttpResponseCode(400);
        }

        $order = $this->orderFactory->create()->loadByIncrementId($reference);
        if (!$order || !$order->getId()) {
            return $this->getResponse()->setHttpResponseCode(404);
        }

        // Store a recurring token (saved card) when CHIP reports one.
        if (!empty($paymentData['is_recurring_token']) || !empty($paymentData['recurring_token'])) {
            $this->tokenManager->storeRecurringToken($paymentData, $order->getPayment());
        }

        $this->orderUpdater->update($order, $paymentData);

        return $this->getResponse()->setHttpResponseCode(200);
    }
}
