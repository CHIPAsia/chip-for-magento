<?php
/**
 * CHIP callback (webhook) controller for Magento 2.3+
 *
 * Implements CsrfAwareActionInterface to bypass CSRF validation for
 * webhook POSTs (same pattern as Magento_Paypal Ipn).
 *
 * This class is ONLY declared when the interface exists (Magento 2.3+,
 * PHP 7.1+). On Magento 2.0 - 2.2 the interface does not exist, so this
 * file defines nothing and Callback falls back to CallbackBase.
 *
 * NOTE: Return types match the interface exactly (?InvalidRequestException,
 * ?bool) which is mandatory on PHP 8.0+. This file never loads on
 * PHP 7.0 (Magento 2.0 - 2.2) thanks to the interface_exists guard in
 * Callback.php, so those PHP versions never see this syntax.
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Controller\Payment;

if (interface_exists('Magento\Framework\App\CsrfAwareActionInterface')) {
    /**
     * CSRF-aware callback for Magento 2.3+.
     */
    class CallbackCsrf extends CallbackBase implements
        \Magento\Framework\App\CsrfAwareActionInterface
    {
        /**
         * @param \Magento\Framework\App\RequestInterface $request
         * @return \Magento\Framework\App\Request\InvalidRequestException|null
         */
        public function createCsrfValidationException(
            \Magento\Framework\App\RequestInterface $request
        ): ?\Magento\Framework\App\Request\InvalidRequestException {
            return null;
        }

        /**
         * @param \Magento\Framework\App\RequestInterface $request
         * @return bool|null
         */
        public function validateForCsrf(\Magento\Framework\App\RequestInterface $request): ?bool
        {
            return true;
        }
    }
}
