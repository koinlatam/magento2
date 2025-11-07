define([
    'jquery',
    'Magento_Ui/js/modal/modal'
], function ($, modal) {
    'use strict';

    return function (config) {
        const modalId = '#koin-modal-antifraud-strategy';
        let eventSource;

        const koinModal = {
            init: function () {
                this.setupModal();
                this.setupSSE();
            },

            setupModal: function () {
                const options = {
                    type: 'popup',
                    modalClass: 'koin-modal-antifraud-strategy',
                    responsive: true,
                    innerScroll: false,
                    buttons: [{
                        text: $.mage.__('Close'),
                        class: 'action secondary',
                        click: () => this.closeModal()
                    }]
                };

                modal(options, $(modalId));
                $(modalId).modal('openModal');

                $(modalId).on('modalclosed', () => {
                    this.destroySSE();
                });
            },

            setupSSE: function () {
                eventSource = new EventSource(config.antifraudStrategyUrl);

                eventSource.addEventListener('koin-payment-antifraud-strategy', (event) => {
                    const data = JSON.parse(event.data);
                    if (data?.is_approved) {
                        this.closeModal();
                        $(document.body).trigger('processStop');
                    }
                });

                eventSource.onerror = (e) => {
                    console.log('SSE connection error', e);
                    this.destroySSE();
                };
            },

            closeModal: function () {
                $(modalId).modal('closeModal');
                this.destroySSE();
            },

            destroySSE: function () {
                if (eventSource) {
                    eventSource.close();
                }
            }
        };

        koinModal.init();
    };
});
