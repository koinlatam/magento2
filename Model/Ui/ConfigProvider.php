<?php
declare(strict_types=1);

/**
 *
 *
 *
 *
 *
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Koin
 * @package     Koin_Payment
 *
 *
 */

namespace Koin\Payment\Model\Ui;

use Koin\Payment\Helper\Data;
use Magento\Checkout\Model\ConfigProviderInterface;

class ConfigProvider implements ConfigProviderInterface
{
    const PAYMENT_KEY = 'koin';

    public function __construct(
        private readonly Data $helper
    ) {}

    public function getConfig(): array
    {
        return [
            'payment' => [
                self::PAYMENT_KEY => [
                    'taxvat_required' => (bool) $this->helper->getConfig('taxvat_required', 'general', 'koin'),
                ]
            ]
        ];
    }
}
