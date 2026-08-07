<?php

/**
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

namespace Koin\Payment\Gateway\Request\CreditCard;

use Koin\Payment\Gateway\Request\PaymentsRequest;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Payment\Interceptor;
use stdClass;

class TransactionRequest extends PaymentsRequest implements BuilderInterface
{
    /** @var array */
    protected array $inputData = [];

    protected $payment;

    /**
     * Builds ENV request
     *
     * @param array $buildSubject
     * @return array
     * @throws LocalizedException
     */
    public function build(array $buildSubject): array
    {
        if (
            !isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var Interceptor $payment */
        $this->setPayment($buildSubject['payment']->getPayment());

        /** @var Order $order */
        $order = $this->getPayment()->getOrder();

        $request = new stdClass();

        try {
            $request->store = $this->getStoreData();
            $request->transaction = $this->getTransaction($order, $buildSubject['amount']);
            $request->payment_method = $this->getPaymentMethod($order, $this->getPayment()->getMethod());
            $request->payer = $this->getPayerData($order);
            $request->buyer = $this->getBuyerData($order);
            $request->items = $this->getItemsData($order);
            $request->country_code = $this->helper->getConfig('default', 'country', 'general');
            $request->descriptor = __('Order %1 on %2', $order->getRealOrderId(), $this->helper->getStoreName());
            $request->notification_url = [$this->helper->getPaymentsNotificationUrl($order)];
            if ($order->getShippingAddress()) {
                $request->shipping = $this->getShippingData($order);
            }
            $request->ip_address = $this->helper->getCurrentIpAddress();
            $request->session_id = $this->customerSession->getSessionId();
        } catch (\Exception $e) {
            $this->helper->log($e->getMessage());
            throw new LocalizedException(__($e->getMessage()));
        }

        return ['request' => $request, 'client_config' => ['store_id' => $order->getStoreId()]];
    }

    protected function getTransaction(Order $order, float $amount): \stdClass
    {
        $transaction = parent::getTransaction($order, $amount);
        $transaction->amount->breakdown = $this->getBreakdown($order);

        return $transaction;
    }

    /**
     * @param $order
     * @param $paymentMethodCode
     * @return stdClass
     * @throws LocalizedException
     */
    protected function getPaymentMethod($order, $paymentMethodCode): stdClass
    {
        $payment = parent::getPaymentMethod($order, $paymentMethodCode);

        $payment->secure_token = $this->getTokenData($order);
        $payment->installments = $this->getInstallments();

        return $payment;
    }

    /**
     * @param $order
     * @return int
     */
    protected function getInstallments(): int
    {
        $installments = (int) $this->getCardData('cc_installments');
        if (!$installments) {
            $installments = (int) $this->getCardData('installments') ?: 0;
        }
        return $installments;
    }

    /**
     * @param Order $order
     * @return string
     * @throws LocalizedException
     */
    protected function getTokenData(Order $order): string
    {
        $secureToken = '';
        $isPciCompliance = (bool) $this->getCardData('is_pci_compliance');
        
        if ($isPciCompliance) {
            $secureToken = $this->getCardData('card_token');
            if (empty($secureToken)) {
                throw new LocalizedException(__('PCI card token is required but not provided.'));
            }
        } else {
            $token = new stdClass();
            $token->transaction = new stdClass();
            $token->transaction->reference_id = $order->getRealOrderId();

            $token->card = new stdClass();
            $token->card->brand_code = $this->getCardData('cc_type') ?: 'CC';
            $token->card->number = $this->getCardData('cc_number');
            $token->card->holder_name = $this->getCardData('cc_owner');
            $token->card->expiration_month = $this->getCardData('cc_exp_month');
            $token->card->expiration_year = $this->getCardData('cc_exp_year');
            $token->card->security_code = $this->getCardData('cc_cid');

            $response = $this->api->tokenize()->execute($token, $order->getStoreId());
            $this->helper->saveRequest($token, $response['response'], $response['status'], 'tokenize');
            if (isset($response['status']) && $response['status'] >= 300) {
                throw new LocalizedException(__('There was an error processing your request.'));
            }

            if (isset($response['response'])) {
                $secureToken = $response['response']['secure_token'] ?? '';
            }
        }

        return $secureToken;
    }

    /**
     * @param $order
     * @param $code
     * @return string
     */
    protected function getCardData($code): string
    {
        $result = (string) $this->getPayment()->getAdditionalInformation($code);
        if (!$result) {
            $result =  (string) $this->customerSession->getData($code);
            if (!$result) {
                $input = $this->getInputData();
                if ($input) {
                    $result = $input['paymentMethod']['additional_data'][$code] ?? '';
                }
            }
        }

        return $result;
    }

    /**
     * @return array
     */
    protected function getInputData(): array
    {
        if (!$this->inputData) {
            $input = file_get_contents('php://input'); // @codingStandardsIgnoreLine
            if ($input) {
                $this->inputData = json_decode($input, true);
            }
        }

        return $this->inputData;
    }

    /**
     * @return mixed
     */
    public function getPayment()
    {
        return $this->payment;
    }

    public function setPayment($payment): void
    {
        $this->payment = $payment;
    }

    /**
     * @param Address $orderAddress
     * @return stdClass
     */
    protected function getAddress($orderAddress): stdClass
    {
        $address = parent::getAddress($orderAddress);
        $address->city = $orderAddress->getCity();
        $address->neighborhood = $orderAddress->getStreetLine($this->getStreetField('district'));
        $address->full_address = $address->street . ', ' . $address->number . ' ' . $address->complement;
        if (isset($address->city_name)) {
            unset($address->city_name);
        }

        if (isset($address->district)) {
            unset($address->district);
        }

        return $address;
    }
}
