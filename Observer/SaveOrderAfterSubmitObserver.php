<?php
/**
 * CHIP save order after submit observer for Magento 2
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Registry;

/**
 * Saves the order into the registry so the redirect controller can access it.
 */
class SaveOrderAfterSubmitObserver implements ObserverInterface
{
    /**
     * @var Registry
     */
    protected $coreRegistry;

    /**
     * @param Registry $coreRegistry
     */
    public function __construct(
        Registry $coreRegistry
    ) {
        $this->coreRegistry = $coreRegistry;
    }

    /**
     * @param EventObserver $observer
     * @return $this
     */
    public function execute(EventObserver $observer)
    {
        $order = $observer->getEvent()->getData('order');
        if ($order && $order->getId()) {
            $payment = $order->getPayment();
            if ($payment && $payment->getMethod() === \CHIPAsia\ChipPaymentGateway\Model\Chip::CODE) {
                $this->coreRegistry->register('chip_order', $order, true);
            }
        }

        return $this;
    }
}
