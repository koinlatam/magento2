/**
 * Biz
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Biz.com license that is
 * available through the world-wide-web at this URL:
 * https://www.bizcommerce.com.br/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Biz
 * @package     Koin_Payment
 * @copyright   Copyright (c) Biz (https://www.bizcommerce.com.br/)
 * @license     https://www.bizcommerce.com.br/LICENSE.txt
 */
/*browser:true*/
/*global define*/

define([
        'underscore',
        'ko',
        'jquery',
        'mage/translate',
        'Magento_SalesRule/js/action/set-coupon-code',
        'Magento_SalesRule/js/action/cancel-coupon',
        'Magento_Customer/js/model/customer',
        'Magento_Payment/js/view/payment/cc-form',
        'Koin_Payment/js/model/credit-card-validation/credit-card-number-validator',
        'Magento_Checkout/js/model/totals',
        'Magento_Checkout/js/action/get-payment-information',
        'Magento_Checkout/js/action/set-payment-information',
        'Magento_Payment/js/model/credit-card-validation/validator',
        'Magento_Checkout/js/model/payment/additional-validators',
        'mage/mage',
        'mage/validation',
        'koin/validation'
    ],
    function (
        _,
        ko,
        $,
        $t,
        setCouponCodeAction,
        cancelCouponCodeAction,
        customer,
        Component,
        cardNumberValidator,
        totals,
        getPaymentInformationAction,
        setPaymentInformationAction
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Koin_Payment/payment/form/cc',
                taxvat: window.checkoutConfig.payment.koin_cc.customer_taxvat.replace(/[^0-9]/g, ""),
                creditCardOwner: '',
                creditCardInstallments: '',
                koinCreditCardNumber: '',
                creditCardType: '',
                showCardData: ko.observable(true),
                installments: ko.observableArray([]),
                hasInstallments: ko.observable(false),
                installmentsUrl: '',
                installmentsId: '',
                showInstallmentsWarning: ko.observable(true),
                debounceTimer: null,
                isPciCompliance: window.checkoutConfig.payment.koin_cc.enable_pci_compliance || false,
                pciClientKey: window.checkoutConfig.payment.koin_cc.pci_client_key || '',
                pciLanguage: window.checkoutConfig.payment.koin_cc.pci_language || 'pt',
                koinCheckout: null,
                cardToken: '',
                cardBin: '',
                cardLast4: '',
                isKoinSdkLoaded: ko.observable(false),
                isCardConfirmed: ko.observable(false),
                confirmedCardDisplay: ko.observable(''),
                showPciForm: ko.observable(true),
                isPlacingOrder: false
            },

            /** @inheritdoc */
            initObservable: function () {
                var self = this;
                this._super()
                    .observe([
                        'taxvat',
                        'creditCardType',
                        'creditCardExpYear',
                        'creditCardExpMonth',
                        'koinCreditCardNumber',
                        'creditCardType',
                        'creditCardVerificationNumber',
                        'selectedCardType',
                        'creditCardOwner',
                        'creditCardInstallments',
                        'installmentsId',
                        'cardToken',
                        'cardBin',
                        'cardLast4',
                        'isCardConfirmed',
                        'confirmedCardDisplay',
                        'showPciForm'
                    ]);

                this.creditCardVerificationNumber('');

                if (this.isPciCompliance && this.pciClientKey) {
                    this.loadKoinSdk();
                }

                setCouponCodeAction.registerSuccessCallback(function() {
                    self.updateInstallmentsValues();
                });

                cancelCouponCodeAction.registerSuccessCallback(function() {
                    self.updateInstallmentsValues();
                });

                //Set credit card number to credit card data object
                this.koinCreditCardNumber.subscribe(function (value) {
                    let result;

                    self.installments.removeAll();
                    self.hasInstallments(false);
                    self.showInstallmentsWarning(true);

                    if (value === '' || value === null) {
                        return false;
                    }

                    self.updateInstallmentsValues();
                });

                // Refresh checkout totals when the customer changes installments,
                // so the koin_interest_amount is persisted to the quote before placeOrder.
                this.installmentsId.subscribe(function (value) {
                    if (!value || value === '0') {
                        return;
                    }
                    if (self.getCode() !== self.isChecked()) {
                        return;
                    }
                    self.refreshTotalsFromSelectedInstallment();
                });

                return this;
            },

            /**
             * Persist payment data into the quote and reload totals after the
             * customer selects a different installment option.
             */
            refreshTotalsFromSelectedInstallment: function () {
                var self = this;

                if (self.isPlacingOrder) {
                    return;
                }

                if (!self.installmentsId() || self.installmentsId() === '0') {
                    return;
                }
                if (self.getCode() !== self.isChecked()) {
                    return;
                }

                if (self.isPlaceOrderActionAllowed && self.isPlaceOrderActionAllowed() === false) {
                    return;
                }

                totals.isLoading(true);
                setPaymentInformationAction(self.messageContainer, self.getData())
                    .done(function () {
                        getPaymentInformationAction()
                            .always(function () {
                                totals.isLoading(false);
                            });
                    })
                    .fail(function () {
                        totals.isLoading(false);
                    });
            },

            logoOnCheckout: function() {
                var inputClasses = 'radio';
                if (window.checkoutConfig.payment.koin_cc.show_logo_on_checkout) {
                    inputClasses = 'radio koin-input';
                }
                return inputClasses;
            },

            getCode: function() {
                return this.item.method;
            },

            showTaxvatField: function() {
                return !!window.checkoutConfig.payment?.koin?.taxvat_required;
            },

            /**
             * Get data
             * @returns {Object}
             */
            getData: function () {

                const iRule = this.installmentsId() ? this.installmentsId().split('-') : '';
                const installments = iRule[0] || '1';
                const ruleId = iRule[1] || '0';
                if (this.isPciCompliance) {
                    return {
                        'method': this.item.method,
                        'additional_data': {
                            'taxvat': this.taxvat(),
                            'cc_type': this.creditCardType(),
                            'cc_exp_year': this.creditCardExpYear(),
                            'cc_exp_month': this.creditCardExpMonth(),
                            'cc_owner': this.creditCardOwner(),
                            'installments': installments,
                            'rule_id': ruleId,
                            'card_token': this.cardToken(),
                            'cc_bin': this.cardBin() || this.koinCreditCardNumber()?.replace(/\D/g, '')?.substring(0, 6),
                            'cc_last4': this.cardLast4() || this.koinCreditCardNumber()?.replace(/\D/g, '')?.slice(-4),
                            'is_pci_compliance': true
                        }
                    };
                } else {
                    return {
                        'method': this.item.method,
                        'additional_data': {
                            'taxvat': this.taxvat(),
                            'cc_cid': this.creditCardVerificationNumber(),
                            'cc_type': this.creditCardType(),
                            'cc_exp_year': this.creditCardExpYear(),
                            'cc_exp_month': this.creditCardExpMonth(),
                            'cc_number': this.koinCreditCardNumber(),
                            'cc_owner': this.creditCardOwner(),
                            'installments': installments,
                            'rule_id': ruleId
                        }
                    };
                }
            },

            /**
             * Check if payment is active
             *
             * @returns {Boolean}
             */
            isActive: function() {
                return this.getCode() === this.isChecked();
            },

            /**
             * @return {Boolean}
             */
            validate: function () {
                if (this.isPciCompliance) {
                    return this.validatePciForm();
                }
                const $form = $('#' + 'form_' + this.getCode());
                return ($form.validation() && $form.validation('isValid'));
            },

            /**
             * @returns {boolean|*}
             */
            retrieveInstallmentsUrl: function() {
                try {
                    this.installmentsUrl = window.checkoutConfig.payment.ccform.urls[this.getCode()].retrieve_installments;
                    return this.installmentsUrl;
                } catch (e) {
                    console.log('Installments URL not defined');
                }
                return false;
            },

            isLoggedIn: function() {
                return customer.isLoggedIn();
            },

            updateInstallmentsValues: function() {

                var self = this;
                if (self.koinCreditCardNumber().length >= 6) {

                    if (self.debounceTimer !== null) {
                        clearTimeout(self.debounceTimer);
                    }

                    //I need to change it to a POST with body
                    self.debounceTimer = setTimeout(() => {
                        if (self.isPlacingOrder) {
                            return;
                        }
                        totals.isLoading(true);
                        fetch(self.retrieveInstallmentsUrl(), {
                            method: 'POST',
                            cache: 'no-cache',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                form_key: window.checkoutConfig.formKey,
                                cc_number: self.koinCreditCardNumber()
                            })
                        }).then((response) => {
                            self.installments.removeAll();
                            return response.json();
                        }).then(json => {
                            json.forEach(function (installment) {
                                self.installments.push(installment);
                                self.hasInstallments(true);
                                self.showInstallmentsWarning(false);
                            });

                            getPaymentInformationAction().done(function () {
                                totals.isLoading(false);
                            });
                        });
                    }, 500);
                }
            },

            /**
             * Get list of available credit card types
             * @returns {Object}
             */
            getKoinCcAvailableTypes: function () {
                return window.checkoutConfig.payment.koin_cc.availableTypes;
            },

            /**
             * Get list of available credit card types values
             * @returns {Object}
             */
            getKoinCcAvailableTypesValues: function () {
                return _.map(this.getKoinCcAvailableTypes(), function (value, key) {
                    return {
                        'value': key,
                        'type': value
                    };
                });
            },

            /**
             * Get available credit card type by code
             * @param {String} code
             * @returns {String}
             */
            getKoinCcTypeTitleByCode: function (code) {
                var title = '',
                    keyValue = 'value',
                    keyType = 'type';

                _.each(this.getKoinCcAvailableTypesValues(), function (value) {
                    if (value[keyValue] === code) {
                        title = value[keyType];
                    }
                });

                return title;
            },

            loadKoinSdk: function() {
                var self = this;
                if (!window.koinCheckout) {
                    var script = document.createElement('script');
                    script.src = window.checkoutConfig.payment.koin_cc.pci_sdk_url;
                    script.onload = function() {
                        self.initializeKoinCheckout();
                    };
                    document.head.appendChild(script);
                } else {
                    this.initializeKoinCheckout();
                }
            },

            initializeKoinCheckout: function() {
                var self = this;
                var containerId = '#koin-checkout-container-' + this.getCode();
                var retryCount = 0;
                var maxRetries = 10;

                var initSdk = function() {
                    try {
                        var container = document.querySelector(containerId);
                        if (!container) {
                            retryCount++;
                            if (retryCount < maxRetries) {
                                console.warn('Container not found, retrying in 100ms:', containerId, 'attempt:', retryCount);
                                setTimeout(initSdk, 100);
                                return;
                            } else {
                                console.error('Container not found after', maxRetries, 'attempts:', containerId);
                                return;
                            }
                        }

                        self.koinCheckout = window.koinCheckout
                            .initialize({
                                clientKey: self.pciClientKey,
                                language: self.pciLanguage
                            })
                            .mount(containerId)
                            .onSuccess(function(data) {
                                self.handleKoinSuccess(data);
                            })
                            .onError(function(error) {
                                self.handleKoinError(error);
                            });
                        self.isKoinSdkLoaded(true);
                        console.log('Koin SDK initialized successfully');
                    } catch (error) {
                        retryCount++;
                        console.error('Failed to initialize Koin Checkout:', error);
                        if (retryCount < maxRetries && !self.isKoinSdkLoaded()) {
                            console.log('Retrying Koin SDK initialization... attempt:', retryCount);
                            setTimeout(initSdk, 500);
                        }
                    }
                };

                // Wait a bit to ensure DOM is ready, then start initialization
                setTimeout(initSdk, 100);
            },

            handleKoinSuccess: function(data) {
                if (!data || !data.secure_token) {
                    return;
                }

                this.cardToken(data.secure_token);
                this.cardBin(data.bin || '');
                this.cardLast4(data.last_four || '');
                this.creditCardType(data.card_brand || '');

                // Set confirmed state and create display string
                this.isCardConfirmed(true);
                this.showPciForm(false);
                this.confirmedCardDisplay(this.formatConfirmedCard());

                // Now fetch installments with the card data
                if (data.bin && data.bin.length >= 6) {
                    this.koinCreditCardNumber(data.bin);
                    this.updateInstallmentsValues();
                }
            },

            handleKoinError: function(error) {
                console.error('Koin Checkout Error:', error);
            },

            validatePciForm: function() {
                if (!this.isCardConfirmed()) {
                    alert('Please confirm your card data first.');
                    return false;
                }
                if (!this.cardToken()) {
                    alert('Card token is missing. Please confirm your card data again.');
                    return false;
                }
                if (!this.installmentsId()) {
                    alert('Please select an installment option.');
                    return false;
                }
                return true;
            },

            confirmCardData: function() {
                var self = this;
                if (this.isPciCompliance && this.koinCheckout) {
                    this.koinCheckout.tokenize();
                }
            },

            editCardData: function() {
                // Reset to edit mode
                this.isCardConfirmed(false);
                this.showPciForm(true);
                this.confirmedCardDisplay('');
                this.cardToken('');
                this.cardBin('');
                this.cardLast4('');
                this.creditCardType('');

                // Reset installments dropdown
                this.installments.removeAll();
                this.hasInstallments(false);
                this.showInstallmentsWarning(true);
                this.installmentsId('');
            },

            formatConfirmedCard: function() {
                const cardBin = this.cardBin().substring(0, 4) + " " + this.cardBin().substring(4, 6);
                return cardBin + '** **** ' + (this.cardLast4() || '');
            },

            /**
             * @inheritdoc
             */
            placeOrder: function () {
                this.isPlacingOrder = true;
                return this._super.apply(this, arguments);
            },

            /** @inheritdoc */
            getPlaceOrderDeferredObject: function () {
                var self = this;

                return this._super().fail(function () {
                    self.isPlacingOrder = false;
                });
            },

            tokenizeAndPlaceOrder: function() {
                var self = this;

                if (this.isPlaceOrderActionAllowed && this.isPlaceOrderActionAllowed() === false) {
                    return;
                }

                if (!this.isPciCompliance) {
                    this.placeOrder();
                    return;
                }

                if (!this.koinCheckout) {
                    return;
                }

                // Confirm card first, then place order
                this.koinCheckout.tokenize().then(function (data) {
                    self.handleKoinSuccess(data);
                    self.placeOrder();
                }).catch(function (error) {
                    self.handleKoinError(error);
                });
            },

            getCcIcons: function(type) {
                return window.checkoutConfig.payment.koin_cc.icons.hasOwnProperty(type) ?
                    window.checkoutConfig.payment.koin_cc.icons[type]
                    : false;
            },

            /**
             * Get credit card details
             * @returns {Array}
             */
            getInfo: function () {
                return [
                    {
                        'name': 'Credit Card Type', value: this.getKoinCcTypeTitleByCode(this.creditCardType())
                    },
                    {
                        'name': 'Card Number', value: this.formatDisplayCcNumber(this.koinCreditCardNumber())
                    }
                ];
            }
        });
    }
);
