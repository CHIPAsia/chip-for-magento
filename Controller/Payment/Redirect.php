<?php
/**
 * CHIP redirect controller for Magento 2
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
use Magento\Framework\Exception\LocalizedException;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;
use CHIPAsia\ChipPaymentGateway\Model\Chip;

/**
 * Redirects the customer to the CHIP payment page.
 */
class Redirect extends Action
{
    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var Registry
     */
    protected $coreRegistry;

    /**
     * @param Context $context
     * @param CheckoutSession $checkoutSession
     * @param Registry $coreRegistry
     */
    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        Registry $coreRegistry
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->coreRegistry = $coreRegistry;
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|ResultInterface
     */
    public function execute()
    {
        $order = $this->coreRegistry->registry('chip_order');

        if (!$order || !$order->getId()) {
            $order = $this->checkoutSession->getLastRealOrder();
        }

        if (!$order || !$order->getId()) {
            $this->messageManager->addErrorMessage(
                __('No order found. Please try again.')
            );
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== Chip::CODE) {
            $this->messageManager->addErrorMessage(
                __('Invalid payment method.')
            );
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        $checkoutUrl = $payment->getAdditionalInformation('chip_checkout_url');

        if (empty($checkoutUrl)) {
            try {
                $method = $payment->getMethodInstance();
                $checkoutUrl = $method->createPurchase($order);
            } catch (LocalizedException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
                return $this->resultRedirectFactory->create()->setPath('checkout/cart');
            }
        }

        return $this->resultRedirectFactory->create()->setUrl($checkoutUrl);
    }
}
