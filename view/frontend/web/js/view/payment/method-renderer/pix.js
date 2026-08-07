/*browser:true*/
/*global define*/

define(
    [
        'Magento_Checkout/js/view/payment/default',
        'mage/url'
    ],
    function (Component, url) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Koin_Payment/payment/form/pix',
                taxvat: window.checkoutConfig.payment.koin_pix.customer_taxvat.replace(/[^0-9]/g, "")
            },

            /** @inheritdoc */
            initObservable: function () {
                this._super().observe([
                    'taxvat'
                ]);

                return this;
            },

            getCode: function() {
                return 'koin_pix';
            },

            showTaxvatField: function() {
                return !!window.checkoutConfig.payment?.koin?.taxvat_required;
            },

            getData: function() {
                return {
                    'method': this.item.method,
                    'additional_data': {
                        'taxvat': this.taxvat()
                    }
                };
            },

            logoOnCheckout: function() {
                var inputClasses = 'radio';
                if (window.checkoutConfig.payment.koin_pix.show_logo_on_checkout) {
                    inputClasses = 'radio koin-input';
                }
                return inputClasses;
            },

            hasInstructions: function () {
                return (window.checkoutConfig.payment.koin_pix.checkout_instructions.length > 0);
            },

            getInstructions: function () {
                return window.checkoutConfig.payment.koin_pix.checkout_instructions;
            }
        });
    }
);

