<?php

/**
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Koin
 * @package     Koin_Payment
 *
 */

namespace Koin\Payment\Block\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Koin\Payment\Helper\Data;

class Fingerprint extends Template
{
    /**
     * @var CustomerSession $customerSession
     */
    protected $customerSession;

    /** @var CheckoutSession */
    protected $checkoutSession;

    /**
     * @var Data $helper
     */
    protected $helper;

    public function __construct(
        Template\Context $context,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        Data $helper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->helper = $helper;
        $this->clearInstallmentsSesion();
    }

    protected function clearInstallmentsSesion()
    {
        $this->checkoutSession->unsetData('koin_installments');
    }

    public function getFingerprintId()
    {
        return $this->customerSession->getSessionId();
    }

    public function getFingerprintUrl()
    {
        $configPath = $this->helper->getGeneralConfig('use_sandbox')
            ? 'fingerprint_url_sandbox'
            : 'fingerprint_url';
        return $this->helper->getEndpointConfig($configPath) ?: '';
    }

    public function getOrgId()
    {
        return trim((string) $this->helper->getGeneralConfig('org_id'));
    }
}
