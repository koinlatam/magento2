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

namespace Koin\Payment\Model\Total\Quote;

use Koin\Payment\Helper\Installments;
use Koin\Payment\Model\Ui\CreditCard\ConfigProvider;
use Magento\Checkout\Model\Session;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;

class Interest extends AbstractTotal
{
    /**  @var Session */
    protected $checkoutSession;

    /** @var SessionManagerInterface  */
    protected $session;

    /** @var Installments */
    protected $helperInstallments;

    /** @var CartRepositoryInterface */
    protected $quoteRepository;

    public function __construct(
        Session $checkoutSession,
        SessionManagerInterface $session,
        Installments $helperInstallments
    ) {
        $this->setCode('koin_interest');
        $this->checkoutSession = $checkoutSession;
        $this->session = $session;
        $this->helperInstallments = $helperInstallments;
    }

    /***
     * Calculate interest rate amount
     *
     * @param $quote
     * @return float
     */
    protected function getInterestAmount($quote): float
    {
        $installments = (int)$this->checkoutSession->getData('koin_installments');
        if ($installments > 1) {
            $grandTotal = $quote->getGrandTotal() - $quote->getKoinInterestAmount();
            $installmentsPrice = $this->getInstallmentsPrice($quote, $grandTotal, $installments);
            if ($installmentsPrice > $grandTotal) {
                return $installmentsPrice - $grandTotal;
            }
        }

        return 0;
    }

    protected function getInstallmentsPrice(Quote $quote, float $grandTotal, int $installmentsNumber): float
    {
        try {
            $ccNumber = (string) $this->session->getKoinCcNumber();
            $allInstallments = $this->helperInstallments->getAllInstallments($grandTotal, $ccNumber, $quote->getStoreId());

            $selectedInstallment = array_filter(
                $allInstallments,
                function ($installment) use ($installmentsNumber) {
                    return $installment['installments'] == $installmentsNumber;
                }
            );

            $installment = $selectedInstallment;
            if (!empty($installment) && !isset($installment['total'])) {
                $installment = $this->arrayFirst($selectedInstallment);
            }

            return $installment['total'] ?? $grandTotal;
        } catch (\Exception $e) {
            return $grandTotal;
        }
    }

    public function arrayFirst(array $array, $default = null)
    {
        if (empty($array)) {
            return $default;
        }

        return reset($array);
    }

    /**
     * Collect address discount amount
     *
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function collect(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ) {

        if ($quote->getPayment()->getMethod() == ConfigProvider::CODE) {
            parent::collect($quote, $shippingAssignment, $total);

            $items = $shippingAssignment->getItems();
            if (!count($items)) {
                return $this;
            }

            $interest = $this->getInterestAmount($quote);

            $quote->setKoinInterestAmount($interest);
            $quote->setBaseKoinInterestAmount($interest);

            $total->setKoinInterestDescription($this->getCode());
            $total->setKoinInterestAmount($interest);
            $total->setBaseKoinInterestAmount($interest);

            $total->addTotalAmount($this->getCode(), $interest);
            $total->addBaseTotalAmount($this->getCode(), $interest);

            return $this;
        }
    }

    /**
     * @param Quote $quote
     * @param Total $total
     *
     * @return array
     */
    public function fetch(Quote $quote, Total $total)
    {
        $result = null;
        $amount = $total->getKoinInterestAmount();

        if ($amount) {
            $result = [
                'code' => $this->getCode(),
                'title' => __('Interest Rate'),
                'value' => $amount
            ];
        }

        return $result;
    }
}
