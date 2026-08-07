<?php

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

namespace Koin\Payment\Helper;

use Koin\Payment\Api\Data\InstallmentsRulesInterface;
use Koin\Payment\Model\InstallmentsRules\Validator;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Cart;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote\Item;

/**
 * Installments data helper, prepared for Koin Transparent
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Installments extends AbstractHelper
{
    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var PriceCurrencyInterface
     */
    protected $priceCurrency;

    /** @var Validator */
    protected $ruleValidator;

    /** @var Cart */
    protected $cart;

    /** @var array */
    protected $cartAttributeSetIds = [];

    public function __construct(
        Context $context,
        PriceCurrencyInterface $priceCurrency,
        Validator $ruleValidator,
        Data $helper,
        Cart $cart
    ) {
        $this->priceCurrency = $priceCurrency;
        $this->helper = $helper;
        $this->ruleValidator = $ruleValidator;
        $this->cart = $cart;
        parent::__construct($context);
    }

    public function getAllInstallments(float $total = 0, string $ccNumber = '', int $storeId = 0): array
    {
        $allInstallments = [];

        if ($this->helper->getCcConfig('enable_default_installment')) {
            $allInstallments = $this->getDefaultInstallments($total);
        }

        try {
            $rules = $this->ruleValidator->getRules($total, $ccNumber, $storeId);
            if ($rules->count() > 0) {
                /** @var \Koin\Payment\Model\InstallmentsRules $rule */
                foreach ($rules as $rule) {
                    if ($rule->getProductSetIds()) {
                        $attributeSetIds = $this->getCartAttributeSetIds();
                        $productSetIds = explode(',', $rule->getProductSetIds());
                        if (!array_intersect($attributeSetIds, $productSetIds)) {
                            continue;
                        }
                    }

                    $interestType = $this->helper->getCcConfig('interest_type');
                    $ruleInterestType = $rule->getInterestType() ?: $interestType;

                    $ruleInstallments = $this->getInstallments(
                        $rule->getMinimumInstallmentAmount(),
                        $total,
                        $rule->getMaxInstallments() ?: 1,
                        $rule->getMaxInstallmentsWithoutInterest() ?: 1,
                        $rule->getMinInstallments() ?: 1,
                        $rule->getHasInterest(),
                        $rule->getInterestRate(),
                        $ruleInterestType,
                        $rule->getId(),
                        $rule->getShowInstallments(),
                        $rule->getDescription(),
                        $this->getRulePerInstallmentRates($rule, $ruleInterestType)
                    );
                    $allInstallments = array_merge($allInstallments, $ruleInstallments);
                }

                ksort($allInstallments);
            }
        } catch (\Exception $e) {
            $this->logError($e->getMessage());
        }

        return array_values($allInstallments);
    }

    public function getDefaultInstallments(float $total = 0): array
    {
        $allInstallments = [];
        try {
            if ($total > 0) {
                $minimumInstallments = (int) $this->helper->getCcConfig('min_installments') ?: 1;
                $maxInstallments = (int) $this->helper->getCcConfig('max_installments') ?: 1;
                $minInstallmentAmount = (float) $this->helper->getCcConfig('minimum_installment_amount');
                $hasInterest = (bool) $this->helper->getCcConfig('has_interest');
                $installmentsWithoutInterest = $this->getInstallmentsWithoutInterest();
                $interestType = $this->helper->getCcConfig('interest_type');
                $defaultInterestRate = (float) $this->helper->getCcConfig('interest_rate');

                $allInstallments = $this->getInstallments(
                    $minInstallmentAmount,
                    $total,
                    $maxInstallments,
                    $installmentsWithoutInterest,
                    $minimumInstallments,
                    $hasInterest,
                    $defaultInterestRate,
                    $interestType
                );
            }
        } catch (\Exception $e) {
            $this->logError($e->getMessage());
        }

        return $allInstallments;
    }

    public function getInstallmentItem(
        int $installments,
        float $interestRate,
        float $value,
        float $total,
        int $ruleId = 0,
        bool $showInstallments = false,
        string $description = ''
    ): array {
        return [
            'id' => (string) $installments . '-' . (string) $ruleId,
            'installments' => $installments,
            'interest_rate' => $interestRate,
            'installment_price' => $value,
            'total' => $total,
            'formatted_installments_price' => $this->priceCurrency->format($value, false),
            'formatted_total' => $this->priceCurrency->format($total, false),
            'rule' => $ruleId,
            'text' => $this->getInterestText(
                $installments,
                $value,
                $interestRate,
                $total,
                $showInstallments,
                $description
            )
        ];
    }

    public function getInterestText(
        int $installments,
        float $value,
        float $interestRate,
        float $grandTotal,
        bool $showInstallments,
        string $description
    ): string {
        $interestText = __('%1x of %2 (%3). Total: %4');
        if (trim($description)) {
            $interestText = $description . ' - ' . $interestText;
            if (!$showInstallments) {
                $interestText = $description;
            }
        }

        $interestExtra = __('without interest');
        if ($interestRate > 0) {
            $interestExtra = __('with interest');
        } elseif ($interestRate < 0) {
            $interestExtra = __('with discount');
        }

        return __(
            $interestText,
            $installments,
            $this->priceCurrency->format($value, false),
            $interestExtra,
            $this->priceCurrency->format($grandTotal, false)
        );
    }

    public function getInstallmentPrice(
        float $total,
        int $installment,
        bool $hasInterest,
        float $interestRate,
        string $interestType
    ): float {
        $installmentAmount = $total / $installment;
        try {
            if ($hasInterest && $interestRate > 0) {
                switch ($interestType) {
                    case 'price':
                        //Amortization with price table
                        $part1 = $interestRate * pow((1 + $interestRate), $installment);
                        $part2 = pow((1 + $interestRate), $installment) - 1;
                        $installmentAmount = round($total * ($part1 / $part2), 2);
                        break;
                    case 'compound':
                        //M = C * (1 + i)^n
                        $installmentAmount = ($total * pow(1 + $interestRate, $installment)) / $installment;
                        break;
                    case 'simple':
                        //M = C * ( 1 + ( i * n ) )
                        $installmentAmount = ($total * (1 + ($installment * $interestRate))) / $installment;
                        break;
                    case 'per_installments':
                        $installmentAmount = ($total * (1 + $interestRate)) / $installment;
                        break;
                }
            }
        } catch (\Exception $e) {
            $this->logError($e->getMessage());
        }

        return round($installmentAmount, 2);
    }

    public function getInterestRateByInstallment(
        int $installment,
        float $interestRate = 0,
        string $interestType = '',
        int $installmentsWithoutInterest = 0,
        array $perInstallmentRates = []
    ): float {
        if ($installment > $installmentsWithoutInterest) {
            if ($interestType == 'per_installments') {
                if (array_key_exists($installment, $perInstallmentRates)) {
                    $interestRate = (float)$perInstallmentRates[$installment];
                } else {
                    $interestRate = (float)$this->helper->getCcConfig('interest_' . $installment . '_installments');
                }
            }
            return $interestRate / 100;
        }

        return 0;
    }

    public function getInstallmentsWithoutInterest(): int
    {
        return (int) $this->helper->getCcConfig('max_installments_without_interest') ?: 1;
    }

    public function getInstallments(
        float $minInstallmentAmount,
        float $total,
        int $maxInstallments,
        int $installmentsWithoutInterest,
        int $minimumInstallments,
        bool $hasInterest,
        float $defaultInterestRate,
        string $interestType,
        int $ruleId = 0,
        bool $showInstallments = false,
        string $description = '',
        array $perInstallmentRates = []
    ): array {
        $allInstallments = [];
        if ($minInstallmentAmount > 0) {
            while ($maxInstallments > ($total / $minInstallmentAmount)) {
                $maxInstallments--;
            }

            while ($installmentsWithoutInterest > ($total / $minInstallmentAmount)) {
                $installmentsWithoutInterest--;
            }
        }

        $maxInstallments = ($maxInstallments == 0) ? 1 : $maxInstallments;
        for ($i = $minimumInstallments; $i <= $maxInstallments; $i++) {
            $interestRate = $this->getInterestRateByInstallment(
                $i,
                $defaultInterestRate,
                $interestType,
                $installmentsWithoutInterest,
                $perInstallmentRates
            );
            $value = $this->getInstallmentPrice($total, $i, $hasInterest, $interestRate, $interestType);
            $grandTotal = $total;

            if (!$hasInterest) {
                $interestRate = 0;
            } elseif ($hasInterest && $interestRate > 0) {
                $grandTotal = round($value * $i, 2);
            }

            $allInstallments[] = $this->getInstallmentItem(
                $i,
                $interestRate,
                $value,
                $grandTotal,
                $ruleId,
                $showInstallments,
                $description
            );
        }
        return $allInstallments;
    }

    public function getFirstInstallment(
        float $total,
        int $ruleId = 0,
        bool $showInstallments = false,
        string $description = ''
    ): array {
        return $this->getInstallmentItem(
            1,
            $this->getInterestRateByInstallment(1),
            $total,
            $total,
            $ruleId,
            $showInstallments,
            $description
        );
    }

    public function getRuleIdByOrderData(
        int $installments,
        float $total,
        string $ccNumber = '',
        int $storeId = 0
    ): int {
        try {
            $allInstallments = $this->getAllInstallments($total, $ccNumber, $storeId);
            foreach ($allInstallments as $installmentItem) {
                if ($installmentItem['installments'] == $installments) {
                    return $installmentItem['rule'];
                }
            }
        } catch (\Exception $e) {
            $this->logError($e->getMessage());
        }
        return 0;
    }

    protected function getRulePerInstallmentRates(
        InstallmentsRulesInterface $rule,
        string $interestType
    ): array {
        if ($interestType !== 'per_installments') {
            return [];
        }

        $rates = [];
        for ($i = 1; $i <= InstallmentsRulesInterface::MAX_INSTALLMENTS_PER_RULE; $i++) {
            $value = $rule->getInterestRateForInstallment($i);
            if ($value !== null) {
                $rates[$i] = $value;
            }
        }
        return $rates;
    }

    protected function getCartAttributeSetIds(): array
    {
        if (empty($this->cartAttributeSetIds)) {
            $cart = $this->cart->getQuote();
            if ($cart) {
                /** @var Item $item */
                foreach ($cart->getAllItems() as $item) {
                    /** @var Product $product */
                    $product = $item->getProduct();
                    if ($product) {
                        $productSetId = $product->getAttributeSetId();
                        if ($productSetId) {
                            $this->cartAttributeSetIds[] = $productSetId;
                        }
                    }
                }
            }
        }
        return $this->cartAttributeSetIds;
    }

    protected function logError(string $message): void
    {
        $this->_logger->error($message);
    }
}
