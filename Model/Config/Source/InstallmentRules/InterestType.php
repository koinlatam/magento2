<?php

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

namespace Koin\Payment\Model\Config\Source\InstallmentRules;

use Magento\Framework\Data\OptionSourceInterface;

class InterestType implements OptionSourceInterface
{
    private \Koin\Payment\Model\Config\Source\InterestType $interestType;

    public function __construct(
        \Koin\Payment\Model\Config\Source\InterestType $interestType
    ) {
        $this->interestType = $interestType;
    }

    public function toOptionArray()
    {
        $options = [];
        foreach ($this->interestType->toOptionArray() as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $label
            ];
        }
        return $options;
    }
}
