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

namespace Koin\Payment\Gateway\Response\CreditCard;

use Koin\Payment\Gateway\Http\Client\Payments\Api;
use Koin\Payment\Gateway\Http\Client\Payments\Refund;
use Koin\Payment\Gateway\Http\Client\Payments\Capture;
use Koin\Payment\Helper\Data;
use Koin\Payment\Helper\Order as HelperOrder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;

class TransactionHandler implements HandlerInterface
{
    /** @var \Koin\Payment\Helper\Order  */
    protected $helperOrder;

    /** @var Data */
    protected $helper;

    /** @var SessionManagerInterface */
    protected $session;

    /** @var Api */
    protected $api;

    public function __construct(
        HelperOrder $helperOrder,
        Data $helper,
        SessionManagerInterface $session,
        Api $api
    ) {
        $this->helperOrder = $helperOrder;
        $this->helper = $helper;
        $this->session = $session;
        $this->api = $api;
    }

    /**
     * Handles transaction id
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     * @throws LocalizedException
     */
    public function handle(array $handlingSubject, array $response)
    {
        if (!isset($handlingSubject['payment'])
            || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException(__('Payment data object should be provided'));
        }

        /** @var PaymentDataObjectInterface $paymentData */
        $paymentData = $handlingSubject['payment'];
        $transaction = $response['transaction'];

        if (isset($response['status_code']) && $response['status_code'] >= 300) {
            throw new LocalizedException(__('There was an error processing your request.'));
        }

        $responseStatus = $transaction['status'] ?? [];
        $responseStatusType = $responseStatus['type'] ?? null;
        if (empty($responseStatus) || $responseStatusType === Api::STATUS_FAILED) {
            throw new LocalizedException($this->helper->getFailedCcTransactionMessage($responseStatus['reason'] ?? null));
        }

        if ($responseStatusType === Api::STATUS_VOIDED) {
            throw new LocalizedException(__('Your order wasn\'t accepted, please, review your payment\'s data or choose another payment method.'));
        }

        /** @var $payment Payment */
        $payment = $paymentData->getPayment();
        $payment = $this->helperOrder->updateDefaultAdditionalInfo($payment, $transaction);
        $payment = $this->helperOrder->updateCreditCardAdditionalInformation($payment, $transaction);
        $payment = $this->helperOrder->update3DSAdditionalInformation($payment, $transaction);
        $payment->setIsTransactionClosed(false);
        $status = $payment->getConfigData('order_status');
        $state = $this->helperOrder->getPaymentStatusState($payment);

        $this->session->unsKoinCcNumber();

        $payment->getOrder()->setState($state);
        $payment->getOrder()->setStatus($status);
        if ($this->helperOrder->canSkipOrderProcessing($state)) {
            $payment->setSkipOrderProcessing(true);
            $payment->addTransaction(TransactionInterface::TYPE_ORDER);
        }

        if ($responseStatusType === Api::STATUS_UNKNOWN || $responseStatusType === Api::STATUS_OPENED) {
            $payment->getOrder()->setState('new');
        }
    }

}
