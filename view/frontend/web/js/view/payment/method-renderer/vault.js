/**
 * CHIP Vault (saved card) payment method renderer for Magento 2 checkout
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */
define([
    'Magento_Vault/js/view/payment/method-renderer/vault'
], function (VaultComponent) {
    'use strict';

    return VaultComponent.extend({
        defaults: {
            template: 'Magento_Vault/payment/form'
        },

        /**
         * Get the public hash of the saved card.
         *
         * @returns {String}
         */
        getToken: function () {
            return this.publicHash;
        },

        /**
         * Get last 4 digits of the saved card.
         *
         * @returns {String}
         */
        getMaskedCard: function () {
            return this.details['cc_last_4'];
        },

        /**
         * Get expiration date of the saved card.
         *
         * @returns {String}
         */
        getExpirationDate: function () {
            return this.details['cc_exp_month'] + '/' + this.details['cc_exp_year'];
        },

        /**
         * Get card type of the saved card.
         *
         * @returns {String}
         */
        getCardType: function () {
            return this.details['cc_type'];
        }
    });
});
