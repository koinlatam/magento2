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

namespace Koin\Payment\ViewModel\Checkout;

use Koin\Payment\Helper\Data;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class BnplModal implements ArgumentInterface
{
    public function __construct(
        private Data $helper
    ) {
    }

    public function getInstructionsContent(): string
    {
        return trim((string) $this->helper->getConfig('payment_error_modal_instructions'));
    }

    public function canShowModal(): bool
    {
        return (bool) $this->helper->getConfig('show_payment_error_modal') && $this->helper->getConfig('active');
    }
}
