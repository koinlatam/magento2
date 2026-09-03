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

namespace Koin\Payment\Observer;

use Koin\Payment\Model\Ui\Redirect\ConfigProvider as RedirectConfigProvider;
use Koin\Payment\Model\Ui\CreditCard\ConfigProvider as CreditCardConfigProvider;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Koin\Payment\Helper\Data as HelperData;
use Koin\Payment\Helper\Installments as HelperInstallments;

class PaymentMethodIsActive implements ObserverInterface
{
    protected $helper;

    protected $helperInstallments;

    public function __construct(
        HelperData $helper,
        HelperInstallments $helperInstallments
    ) {
        $this->helper = $helper;
        $this->helperInstallments = $helperInstallments;
    }

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        $methodCode = $event->getMethodInstance()->getCode();

        if ($methodCode == RedirectConfigProvider::CODE) {
            if ($this->helper->isCompanyCustomer()) {
                /** @var DataObject $result */
                $result = $observer->getEvent()->getResult();
                $result->setData('is_available', false);
            }
        } else if ($methodCode == CreditCardConfigProvider::CODE) {
            if (!$this->helper->getCcConfig('enable_default_installment')) {
                $installmentsRules = $this->helperInstallments->getAllInstallments(total: $event->getQuote()->getGrandTotal());
                if (empty($installmentsRules)) {
                    /** @var DataObject $result */
                    $result = $observer->getEvent()->getResult();
                    $result->setData('is_available', false);
                }
            }
        }
    }
}
