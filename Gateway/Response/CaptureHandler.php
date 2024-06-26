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

namespace Koin\Payment\Gateway\Response;

use Koin\Payment\Gateway\Http\Client\Payments\Api;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;

class CaptureHandler implements HandlerInterface
{
    /**
     * Handles transaction id
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function handle(array $handlingSubject, array $response)
    {
        if (!isset($handlingSubject['payment'])
            || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
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
            throw new LocalizedException(__('There was an error processing your request.'));
        }

        /** @var $payment \Magento\Sales\Model\Order\Payment */
        $payment = $paymentData->getPayment();


        if (isset($transaction['status'])) {
            if (isset($transaction['status']['type'])) {
                $payment->setAdditionalInformation('status', $transaction['status']['type']);
            }
            if (isset($transaction['status']['date'])) {
                $payment->setAdditionalInformation('status_date', $transaction['status']['date']);
            }
        }

        if (isset($transaction['order_id'])) {
            $payment->setAdditionalInformation('order_id', $transaction['order_id']);
            $payment->setTransactionId($transaction['order_id']);
        }

        $payment->setAdditionalInformation('captured', true);
    }
}
