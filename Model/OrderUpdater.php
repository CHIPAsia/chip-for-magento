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

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

/**
 * Updates Magento order state/status based on CHIP payment status.
 *
 * Uses MySQL GET_LOCK/RELEASE_LOCK (same pattern as the CHIP WooCommerce
 * plugin) so concurrent webhook callbacks for the same order are processed
 * one at a time.
 */
class OrderUpdater
{
    /**
     * Lock acquisition timeout in seconds.
     */
    const LOCK_TIMEOUT = 15;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param OrderFactory $orderFactory
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderFactory $orderFactory,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->orderFactory = $orderFactory;
        $this->resourceConnection = $resourceConnection;
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

        $lockName = 'chip_payment_' . $order->getId();
        $connection = $this->resourceConnection->getConnection();

        try {
            $lockAcquired = (bool) $connection->fetchOne(
                'SELECT GET_LOCK(?, ?)',
                array($lockName, self::LOCK_TIMEOUT)
            );

            if (!$lockAcquired) {
                $this->logger->warning(
                    'CHIP callback: could not acquire lock for order ' . $order->getIncrementId()
                );
                return;
            }

            $this->updateOrderState($order, $paymentData);

            $connection->query('SELECT RELEASE_LOCK(?)', array($lockName));
        } catch (\Exception $e) {
            $this->logger->error(
                'CHIP callback error for order ' . $order->getIncrementId() . ': ' . $e->getMessage()
            );
            try {
                $connection->query('SELECT RELEASE_LOCK(?)', array($lockName));
            } catch (\Exception $ignored) {
            }
        }
    }

    /**
     * Apply the state transition (guarded by the lock).
     *
     * @param Order $order
     * @param array $paymentData
     * @return void
     */
    protected function updateOrderState(Order $order, $paymentData)
    {
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
