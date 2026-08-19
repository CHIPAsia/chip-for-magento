/**
 * CHIP payment method renderer for Magento 2 checkout
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */
define(
    [
        'Magento_Checkout/js/view/payment/default',
        'mage/url'
    ],
    function (Component, url) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Magento_Checkout/payment/default'
            },

            /**
             * Disable the default success-page redirect so we can send the
             * customer to the CHIP payment page instead.
             */
            redirectAfterPlaceOrder: false,

            /**
             * Redirect to the CHIP payment page after order placement.
             */
            afterPlaceOrder: function () {
                window.location.replace(url.build('chip/payment/redirect'));
            }
        });
    }
);
