/*browser:true*/
/*global define*/

define(
    [
        'Magento_Checkout/js/view/payment/default',
        'mage/url',
        'jquery',
        'jquery/jquery-storageapi'
    ],
    function (Component, url, $) {
        'use strict';

        return Component.extend({
            defaults: {
                redirectAfterPlaceOrder: false,
                template: 'Koin_Payment/payment/form/redirect',
                taxvat: window.checkoutConfig.payment.koin_redirect.customer_taxvat.replace(/[^0-9]/g, "")
            },

            /** @inheritdoc */
            initObservable: function () {
                this._super().observe([
                    'taxvat'
                ]);

                // Auto-select this payment method if stored in checkout data
                this.checkForPaymentPreselection();

                return this;
            },

            /**
             * Check checkout data for payment preselection using direct localStorage
             */
            checkForPaymentPreselection: function () {
                try {
                    var storageApi = $.initNamespaceStorage('mage-cache-storage').localStorage;
                    var checkoutData = storageApi.get('checkout-data') || {};
                    var storedPaymentMethod = checkoutData.selectedPaymentMethod;

                    if (storedPaymentMethod === 'koin_redirect') {
                        var self = this;
                        setTimeout(function() {
                            self.selectPaymentMethod();

                            try {
                                checkoutData.selectedPaymentMethod = null;
                                storageApi.set('checkout-data', checkoutData);
                            } catch (error) {
                                console.warn('Koin BNPL: Could not clear stored payment method:', error);
                            }
                        }, 1000);
                    }
                } catch (error) {
                    console.warn('Koin BNPL: Could not read stored payment method:', error);
                }
            },

            getCode: function() {
                return 'koin_redirect';
            },

            showTaxvatField: function() {
                return !!window.checkoutConfig.payment?.koin?.taxvat_required;
            },

            logoOnCheckout: function() {
                var inputClasses = 'radio';
                if (window.checkoutConfig.payment.koin_redirect.show_logo_on_checkout) {
                    inputClasses = 'radio koin-input';
                }
                return inputClasses;
            },

            useDefaultInstructions: function () {
                return window.checkoutConfig.payment.koin_redirect.use_default_checkout_instructions;
            },

            getInstructions: function () {
                return window.checkoutConfig.payment.koin_redirect.checkout_instructions;
            },

            getCheckoutImage: function () {
                return window.checkoutConfig.payment.koin_redirect.checkout_image;
            },

            getData: function() {
                return {
                    'method': this.item.method,
                    'additional_data': {
                        'taxvat': this.taxvat()
                    }
                };
            },

            afterPlaceOrder: function () {
                window.location.href = url.build('koin/redirect');
            }
        });
    }
);

