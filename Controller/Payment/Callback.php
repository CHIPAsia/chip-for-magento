<?php
/**
 * CHIP callback (webhook) controller for Magento 2
 *
 * Compatible with Magento 2.0 - 2.4:
 *  - Magento 2.3+ has CSRF validation on frontend POST actions. The
 *    CsrfAwareActionInterface is implemented (via CallbackCsrf) so webhook
 *    POSTs are allowed without a form key (same pattern as Magento_Paypal Ipn).
 *  - Magento 2.0 - 2.2 has no CSRF validation, so the interface (which does
 *    not exist there) is not implemented.
 *
 * NOTE: This file intentionally contains NO return type declarations so it
 * parses on PHP 5.6/7.0 (Magento 2.0 - 2.2). The CSRF-aware variant lives in
 * CallbackCsrf.php which is only loaded on Magento 2.3+ (PHP 7.1+).
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Controller\Payment;

if (interface_exists('Magento\Framework\App\CsrfAwareActionInterface')) {
    class Callback extends CallbackCsrf
    {
    }
} else {
    class Callback extends CallbackBase
    {
    }
}
