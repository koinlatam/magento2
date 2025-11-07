var config = {
    map: {
        '*': {
            'koinFingerprint': 'Koin_Payment/js/fingerprint',
            'koinBnplBanner': 'Koin_Payment/js/koin-bnpl-banner',
            'koinBnplCart': 'Koin_Payment/js/koin-bnpl-cart',
            'placeOrderTest': 'Koin_Payment/js/model/place-order-mixin'
        }
    },
    config: {
        mixins: {
            'Magento_Checkout/js/model/place-order': {
                'Koin_Payment/js/model/place-order-mixin': true
            }
        }
    }
};
