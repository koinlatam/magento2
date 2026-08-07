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

namespace Koin\Payment\Api\Data;

interface InstallmentsRulesInterface
{
    /**
     * Constants for keys of data array. Identical to the name of the getter in snake case.
     */
    public const TITLE = 'title';

    public const DESCRIPTION = 'description';

    public const SHOW_INSTALLMENTS = 'show_installments';

    public const ACCOUNT_NUMBER = 'account_number';
    public const PAYMENT_METHODS = 'payment_methods';

    public const EXCEPT_PAYMENT_METHODS = 'except_payment_methods';
    public const MIN_INSTALLMENTS = 'min_installments';
    public const MAX_INSTALLMENTS = 'max_installments';
    public const MAX_INSTALLMENTS_WITHOUT_INTEREST = 'max_installments_without_interest';
    public const MINIMUM_INSTALLMENT_AMOUNT = 'minimum_installment_amount';
    public const HAS_INTEREST = 'has_interest';
    public const INTEREST_TYPE = 'interest_type';
    public const INTEREST_RATE = 'interest_rate';
    public const INTEREST_1_INSTALLMENTS = 'interest_1_installments';
    public const INTEREST_2_INSTALLMENTS = 'interest_2_installments';
    public const INTEREST_3_INSTALLMENTS = 'interest_3_installments';
    public const INTEREST_4_INSTALLMENTS = 'interest_4_installments';
    public const INTEREST_5_INSTALLMENTS = 'interest_5_installments';
    public const INTEREST_6_INSTALLMENTS = 'interest_6_installments';
    public const INTEREST_7_INSTALLMENTS = 'interest_7_installments';
    public const INTEREST_8_INSTALLMENTS = 'interest_8_installments';
    public const INTEREST_9_INSTALLMENTS = 'interest_9_installments';
    public const INTEREST_10_INSTALLMENTS = 'interest_10_installments';
    public const INTEREST_11_INSTALLMENTS = 'interest_11_installments';
    public const INTEREST_12_INSTALLMENTS = 'interest_12_installments';

    public const MAX_INSTALLMENTS_PER_RULE = 12;
    public const PRIORITY = 'priority';
    public const CUSTOMER_GROUP_IDS = 'customer_group_ids';
    public const PRODUCT_SET_IDS = 'product_set_ids';
    public const CREDIT_CARD_BRANDS = 'credit_card_brands';
    public const DAYS_OF_WEEK = 'days_of_week';
    public const START_DATE = 'start_date';
    public const END_DATE = 'end_date';
    public const MINIMUM_AMOUNT = 'minimum_amount';
    public const MAXIMUM_AMOUNT = 'maximum_amount';
    public const STATUS = 'status';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    public function getTitle(): string;

    public function setTitle(string $title): void;

    public function getDescription(): string;

    public function setDescription(string $description): void;

    public function getShowInstallments(): bool;

    public function setShowInstallments(bool $showInstallments): void;

    public function getAccountNumber(): string;

    public function setAccountNumber(string $accountNumber);

    public function getPaymentMethods(): string;

    public function setPaymentMethods(string $paymentMethods): void;

    public function getExceptPaymentMethods(): string;

    public function setExceptPaymentMethods(string $exceptPaymentMethods): void;

    public function getMinInstallments(): int;

    public function setMinInstallments(int $minInstallments);

    public function getMaxInstallments(): int;

    public function setMaxInstallments(int $maxInstallments);

    public function getMaxInstallmentsWithoutInterest(): int;

    public function setMaxInstallmentsWithoutInterest(int $maxInstallmentsWithoutInterest): void;

    public function getMinimumInstallmentAmount(): float;

    public function setMinimumInstallmentAmount(float $minimumInstallmentAmount): void;

    public function getHasInterest(): bool;

    public function setHasInterest(bool $hasInterest): void;

    public function getInterestType(): string;

    public function setInterestType(string $interestType): void;

    public function getInterestRate(): float;

    public function setInterestRate(float $interestRate): void;

    public function getInterestRateForInstallment(int $installments): ?float;

    public function setInterestRateForInstallment(int $installments, ?float $rate): void;

    public function getPriority(): int;

    public function setPriority(int $priority): void;

    public function getCustomerGroupIds(): string;

    public function setCustomerGroupIds(string $customerGroupIds): void;

    public function getProductSetIds(): string;

    public function setProductSetIds(string $productSetIds): void;

    public function getCreditCardBrands(): string;

    public function setCreditCardBrands(string $creditCardBrands): void;

    public function getDaysOfWeek(): string;

    public function setDaysOfWeek(string $daysOfWeek): void;

    public function getStartDate(): string;

    public function setStartDate(string $startDate): void;

    public function getEndDate(): string;

    public function setEndDate(string $endDate): void;

    public function getMinimumAmount(): float;

    public function setMinimumAmount(float $minimumAmount): void;

    public function getMaximumAmount(): float;

    public function setMaximumAmount(float $maximumAmount): void;

    public function getStatus(): bool;

    public function setStatus(bool $status): void;

    public function getCreatedAt(): string;

    public function setCreatedAt(string $createdAt): void;

    public function getUpdatedAt(): string;

    public function setUpdatedAt(string $updatedAt): void;
}
