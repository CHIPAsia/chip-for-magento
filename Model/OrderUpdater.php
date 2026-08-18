<?php
/**
 * CHIP order status updater for Magento 2
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Psr\Log\LoggerInterface;

/**
 * Updates Magento order state/status based on CHIP payment status.
 */
class OrderUpdater
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
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param OrderFactory $orderFactory
     * @param OrderResource $orderResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderFactory $orderFactory,
        OrderResource $orderResource,
        LoggerInterface $logger
    ) {
        $this->orderFactory = $orderFactory;
        $this->orderResource = $orderResource;
        $this->logger = $logger;
    }

    /**
     * Update order state based on CHIP payment status.
     *
     * @param Order $order
     * @param array $paymentData
     * @return void
     */
    public function update(Order $order, $paymentData)
    {
        if (!is_array($paymentData) || !isset($paymentData['status'])) {
            return;
        }

        $status = $paymentData['status'];
        $payment = $order->getPayment();

        switch ($status) {
            case 'paid':
                if ($order->getState() === Order::STATE_PENDING_PAYMENT
                    || $order->getState() === Order::STATE_NEW
                ) {
                    $order->setState(Order::STATE_PROCESSING);
                    $order->setStatus(Order::STATE_PROCESSING);
                    $order->addStatusHistoryComment(
                        __('CHIP payment received. Purchase ID: %1', $paymentData['id'])
                    );
                    $order->save();
                }
                break;

            case 'cancelled':
            case 'expired':
            case 'failed':
                if ($order->getState() === Order::STATE_PENDING_PAYMENT
                    || $order->getState() === Order::STATE_NEW
                ) {
                    $order->setState(Order::STATE_CANCELED);
                    $order->setStatus(Order::STATE_CANCELED);
                    $order->addStatusHistoryComment(
                        __('CHIP payment %1. Purchase ID: %2', $status, $paymentData['id'])
                    );
                    $order->save();
                }
                break;

            case 'refunded':
                if ($order->getState() === Order::STATE_PROCESSING
                    || $order->getState() === Order::STATE_COMPLETE
                ) {
                    $order->setState(Order::STATE_CLOSED);
                    $order->setStatus(Order::STATE_CLOSED);
                    $order->addStatusHistoryComment(
                        __('CHIP payment refunded. Purchase ID: %1', $paymentData['id'])
                    );
                    $order->save();
                }
                break;
        }
    }
}
