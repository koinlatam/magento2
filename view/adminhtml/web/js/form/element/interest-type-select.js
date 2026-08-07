define([
    'Magento_Ui/js/form/element/select'
], function (Select) {
    'use strict';

    return Select.extend({
        defaults: {
            isPerInstallments: false,
            isNotPerInstallments: true,
            tracks: {
                isPerInstallments: true,
                isNotPerInstallments: true
            },
            listens: {
                value: 'updateIsPerInstallments'
            }
        },

        initialize: function () {
            this._super();
            this.updateIsPerInstallments();

            return this;
        },

        updateIsPerInstallments: function () {
            this.isPerInstallments = this.value() === 'per_installments';
            this.isNotPerInstallments = !this.isPerInstallments;
        }
    });
});
