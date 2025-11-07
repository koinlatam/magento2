define([
    'mage/storage',
    'Magento_Checkout/js/model/error-processor',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Customer/js/customer-data',
    'Magento_Checkout/js/model/payment/place-order-hooks',
    'underscore',
    'jquery',
    'Magento_Ui/js/modal/modal'
], function (storage, errorProcessor, fullScreenLoader, customerData, hooks, _, $, modal) {
    'use strict';

    return function (placeOrderAction) {
        return function (serviceUrl, payload, messageContainer) {
            var method = payload?.paymentMethod?.method;

            if (!method || !method.includes('koin') || method === 'koin_redirect') {
                return placeOrderAction(serviceUrl, payload, messageContainer);
            }

            var headers = {}, redirectURL = '';

            fullScreenLoader.startLoader();
            _.each(hooks.requestModifiers, function (modifier) {
                modifier(headers, payload);
            });

            return storage.post(
                serviceUrl, JSON.stringify(payload), true, 'application/json', headers
            ).fail(
                function (response) {
                    errorProcessor.process(response, messageContainer);
                    redirectURL = response.getResponseHeader('errorRedirectAction');
                    var modalElement = $('#bnpl-modal');

                    if (modalElement.length > 0) {
                        var options = {
                            type: 'popup',
                            responsive: true,
                            innerScroll: true,
                            buttons: [
                                {
                                    text: $.mage.__('Close'),
                                    click: function () {
                                        this.closeModal();
                                    }
                                },
                                {
                                    text: $.mage.__('Select BNPL'),
                                    class: 'action primary select-bnpl',
                                    click: function () {
                                        $('#koin_redirect').click();
                                        this.closeModal();
                                    }
                                }
                            ]
                        };
                        modal(options, modalElement);
                        modalElement.modal('openModal');
                        return;
                    }

                    if (redirectURL) {
                        setTimeout(function () {
                            errorProcessor.redirectTo(redirectURL);
                        }, 3000);
                    }
                }
            ).done(
                function (response) {
                    var clearData = {
                        'selectedShippingAddress': null,
                        'shippingAddressFromData': null,
                        'newCustomerShippingAddress': null,
                        'selectedShippingRate': null,
                        'selectedPaymentMethod': null,
                        'selectedBillingAddress': null,
                        'billingAddressFromData': null,
                        'newCustomerBillingAddress': null
                    };

                    if (response.responseType !== 'error') {
                        customerData.set('checkout-data', clearData);
                    }
                }
            ).always(
                function () {
                    fullScreenLoader.stopLoader();
                    _.each(hooks.afterRequestListeners, function (listener) {
                        listener();
                    });
                }
            );
        };
    };
});
