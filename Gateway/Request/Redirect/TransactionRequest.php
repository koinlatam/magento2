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

namespace Koin\Payment\Gateway\Request\Redirect;

use Koin\Payment\Gateway\Request\PaymentsRequest;
use Koin\Payment\Helper\Data;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order;

class TransactionRequest extends PaymentsRequest implements BuilderInterface
{
    /**
     * Builds ENV request
     *
     * @param array $buildSubject
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function build(array $buildSubject)
    {
        if (
            !isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var \Magento\Sales\Model\Order\Payment\Interceptor $payment */
        $payment = $buildSubject['payment']->getPayment();
        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();

        $request = new \stdClass();

        $request->store = $this->getStoreData();
        $request->transaction = $this->getTransaction($order, $buildSubject['amount']);
        $request->payment_method = $this->getPaymentMethod($order, $payment->getMethod());
        $request->payer = $this->getPayerData($order);
        $request->items = $this->getItemsData($order);
        $request->country_code = $this->helper->getDefaultCountryCode();
        $request->descriptor = __('Order %1 on %2', $order->getRealOrderId(), $this->helper->getStoreName());
        $request->device_fingerprint = $this->customerSession->getSessionId();
        $request->verified_id = false;
        $request->ip_address = $this->helper->getCurrentIpAddress();
        $request->shipping = $this->getShippingData($order);
        $request->notification_url = [$this->helper->getPaymentsNotificationUrl($order)];
        $request->payment_url = $this->helper->getReturnUrl($order->getIncrementId());

        return ['request' => $request, 'client_config' => ['store_id' => $order->getStoreId()]];
    }

    protected function getItemsData($order): array
    {
        $items = [];
        $quoteItems = $order->getAllItems();

        /** @var \Magento\Sales\Api\Data\OrderItemInterface $quoteItem */
        foreach ($quoteItems as $quoteItem) {
            if ($quoteItem->getParentItemId() || $quoteItem->getParentItem() || $quoteItem->getPrice() == 0) {
                continue;
            }

            $price = new \stdClass();
            $price->currency_code = $this->getOrderCurrencyCode($order);
            $price->value = (float) $quoteItem->getPrice();

            $discountAmount = ((float) $quoteItem->getDiscountAmount() < 0)
                ? (float) $quoteItem->getDiscountAmount() * -1
                : (float) $quoteItem->getDiscountAmount();

            $item = new \stdClass();
            $item->reference = $quoteItem->getProductId();
            $item->name = $quoteItem->getName();
            $item->price = $price;
            $item->quantity = $quoteItem->getQtyOrdered();
            $item->discount = $discountAmount;
            $item->category = $this->getCategoryNameByQuoteItem($quoteItem) ?: 'N/A';

            $this->eventManager->dispatch('koin_payment_get_item', ['item' => &$item, 'quote_item' => $quoteItem]);

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param $paymentMethod
     * @return \stdClass
     */
    protected function getPaymentMethod($order, $paymentMethodCode): \stdClass
    {
        $payment = parent::getPaymentMethod($order, $paymentMethodCode);
        $payment->expiration_date = $this->getExpirationDate();
        return $payment;
    }

    /**
     * @param \Magento\Sales\Model\Order\Address $orderAddress
     * @return \stdClass
     */
    protected function getAddress($orderAddress): \stdClass
    {
        $address = parent::getAddress($orderAddress);
        $address->zip_code = preg_replace('/\D/', '', (string) $orderAddress->getPostcode());

        return $address;
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param float $amount
     * @return \stdClass
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getTransaction($order, $amount): \stdClass
    {
        $transaction = parent::getTransaction($order, $amount);
        $currencyCode = $this->getOrderCurrencyCode($order);

        $transaction->amount->breakdown = new \stdClass();
        $transaction->amount->breakdown->items = new \stdClass();
        $transaction->amount->breakdown->items->currency_code = $currencyCode;
        $transaction->amount->breakdown->items->value = $order->getSubtotal();
        $transaction->amount->breakdown->shipping = new \stdClass();
        $transaction->amount->breakdown->shipping->currency_code = $currencyCode;
        $transaction->amount->breakdown->shipping->value = $order->getShippingAmount();
        $transaction->amount->breakdown->taxes = new \stdClass();
        $transaction->amount->breakdown->taxes->currency_code = $currencyCode;
        $transaction->amount->breakdown->taxes->value = $order->getTaxAmount();

        return $transaction;
    }

    /**
     * @param Order $order
     * @return \stdClass
     */
    public function getShippingData($order): \stdClass
    {
        $shipping = new \stdClass();
        $shipping->delivery_date = $this->helper->getDeliveryDate($order);
        $shipping->address = $this->getAddress($order->getShippingAddress());

        return $shipping;
    }
}
