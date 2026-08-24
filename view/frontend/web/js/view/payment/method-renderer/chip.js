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
        'Magento_Checkout/js/model/payment/renderer-list',
        'mage/url'
    ],
    function (Component, rendererList, url) {
        'use strict';

        rendererList.push(
            {
                type: 'chip',
                component: 'CHIPAsia_ChipPaymentGateway/js/view/payment/method-renderer/chip'
            }
        );

        return Component.extend({
            defaults: {
                template: 'CHIPAsia_ChipPaymentGateway/payment/chip'
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
