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

namespace Koin\Payment\Gateway\Request;

use Koin\Payment\Gateway\Http\Client\Payments\Api;
use Koin\Payment\Helper\Data;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;

class PaymentsRequest
{
    public const DEFAULT_TYPE = 'Generic';

    protected $categories = [];

    /**
     * @var ConfigInterface
     */
    protected $config;

    /**
     * @var DateTime
     */
    protected $date;

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var CustomerSession $customerSession
     */
    protected $customerSession;

    /**
     * @var ManagerInterface
     */
    protected $eventManager;

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var CategoryRepositoryInterface
     */
    protected $categoryRepository;

    /**
     * @var string
     */
    protected $currencyCode;

    /**
     * @var Api
     */
    protected $api;

    public function __construct(
        ManagerInterface $eventManager,
        Data $helper,
        DateTime $date,
        ConfigInterface $config,
        CustomerSession $customerSession,
        DateTime $dateTime,
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        Api $api
    ) {
        $this->eventManager = $eventManager;
        $this->helper = $helper;
        $this->date = $date;
        $this->config = $config;
        $this->customerSession = $customerSession;
        $this->dateTime = $dateTime;
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->api = $api;
    }

    /**
     * @return \stdClass
     */
    protected function getStoreData(): \stdClass
    {
        $store = new \stdClass();
        $store->code = $this->helper->getGeneralConfig('store_code') ?: Data::DEFAULT_MERCHANT_NAME;
        return $store;
    }

    protected function getOrderCurrencyCode(Order $order): string
    {
        if (!$this->currencyCode) {
            $this->currencyCode = $order->getBaseCurrencyCode() ?: $this->helper->getStoreCurrencyCode();
        }
        return (string) $this->currencyCode;
    }

    protected function getPaymentMethod(Order $order, string $paymentMethodCode): \stdClass
    {
        $payment = new \stdClass();

        $methodCode = $this->helper->getConfig('code', $paymentMethodCode);
        $payment->code = $methodCode;

        return $payment;
    }

    protected function getTransaction(Order $order, float $amount): \stdClass
    {
        $currencyCode = $order->getBaseCurrencyCode() ?: $this->helper->getStoreCurrencyCode();

        $transaction = new \stdClass();
        $transaction->reference_id = $order->getRealOrderId();
        $transaction->business_id = $order->getRealOrderId();
        $transaction->account = $this->helper->getAccountNumber($order);

        $transaction->amount = new \stdClass();
        $transaction->amount->currency_code = $currencyCode;
        $transaction->amount->value = round((float) $amount, 2);

        return $transaction;
    }

    public function getPayerData(Order $order): \stdClass
    {
        $payerData = new \stdClass();

        $address = $order->getBillingAddress();
        $customerTaxVat = $address->getVatId() ?: $order->getCustomerTaxvat();
        $koinCustomerTaxVat = $order->getPayment()->getAdditionalInformation('koin_customer_taxvat');
        if ($koinCustomerTaxVat) {
            $customerTaxVat = $koinCustomerTaxVat;
        }

        $fullName = (string) $order->getPayment()->getCcOwner();
        if (!$fullName) {
            $firstName = $address->getFirstname() ?: $order->getCustomerFirstname();
            $lastName = $address->getLastname() ?: $order->getCustomerLastname();
            $fullName = $order->getCustomerName() ?: $firstName . ' ' . $lastName;
        }

        $payerData->full_name = $fullName;
        $payerData->email = $order->getCustomerEmail();
        $payerData->document = $this->getDocument($customerTaxVat);

        $phoneNumber = $this->helper->formatPhoneNumber($address->getTelephone());
        $payerData->phone = new \stdClass();
        $payerData->phone->area = substr($phoneNumber, 0, 2);
        $payerData->phone->number = substr($phoneNumber, 2, 9);

        $payerData->address = $this->getAddress($address);

        return $payerData;
    }

    /**
     * @param Order $order
     */
    public function getBuyerData(Order $order): \stdClass
    {
        $address = $order->getShippingAddress() ?: $order->getBillingAddress();

        $customerTaxVat = $order->getCustomerTaxvat();
        if (!$customerTaxVat) {
            $customerTaxVat = $address->getVatId() ?: $order->getPayment()->getAdditionalInformation('koin_customer_taxvat');
        }

        $buyerData = new \stdClass();
        $buyerData->first_name = $order->getCustomerFirstname();
        $buyerData->last_name = $order->getCustomerLastname();
        $buyerData->email = $order->getCustomerEmail();
        $buyerData->document = $this->getDocument($customerTaxVat);

        $phoneNumber = $this->helper->formatPhoneNumber($address->getTelephone());
        $buyerData->phone = new \stdClass();
        $buyerData->phone->area = substr($phoneNumber, 0, 2);
        $buyerData->phone->number = substr($phoneNumber, 2, 9);

        $buyerData->address = $this->getAddress($address);

        return $buyerData;
    }

    /**
     * @param \Magento\Sales\Model\Order\Address $orderAddress
     * @return \stdClass
     */
    protected function getAddress($orderAddress): \stdClass
    {
        $address = new \stdClass();
        $address->country_code = $orderAddress->getCountryId();
        $address->state = $orderAddress->getRegionCode() ?: $orderAddress->getRegion();
        $address->city_name = $orderAddress->getCity();
        $address->zip_code = $orderAddress->getPostcode();
        $address->street = $orderAddress->getStreetLine($this->getStreetField('street'));
        $address->number = $orderAddress->getStreetLine($this->getStreetField('number'));
        $address->district = $orderAddress->getStreetLine($this->getStreetField('district'));
        $address->complement = $orderAddress->getStreetLine($this->getStreetField('complement'));

        return $address;
    }

    public function getStreetField(string $config): int
    {
        return (int) $this->helper->getConfig($config, 'address', 'koin') + 1;
    }

    protected function getItemsData(Order $order): array
    {
        $items = [];
        $quoteItems = $order->getAllItems();

        /** @var OrderItemInterface $quoteItem */
        foreach ($quoteItems as $quoteItem) {
            if ($quoteItem->getParentItemId() || $quoteItem->getParentItem() || $quoteItem->getPrice() == 0) {
                continue;
            }

            $item = new \stdClass();
            $item->type = self::DEFAULT_TYPE;
            $item->id = $quoteItem->getSku();
            $item->name = $quoteItem->getName();
            $item->price = $this->getPriceItem($quoteItem, $order);
            $item->quantity = (int) $quoteItem->getQtyOrdered();
            $item->discount_amount = $this->getDiscountItem($quoteItem, $order);
            $item->category = $this->getCategoryByQuoteItem($quoteItem);

            $this->eventManager->dispatch('koin_payment_get_item', ['item' => &$item, 'quote_item' => $quoteItem]);

            $items[] = $item;
        }

        return $items;
    }

    protected function getPriceItem($quoteItem, Order $order): \stdClass
    {
        $price = new \stdClass();
        $price->currency_code = $this->getOrderCurrencyCode($order);
        $price->value = (float) $quoteItem->getPrice();
        return $price;
    }

    protected function getDiscountItem($quoteItem, $order): \stdClass
    {
        $discountAmount = ((float) $quoteItem->getDiscountAmount() < 0)
            ? (float) $quoteItem->getDiscountAmount() * -1
            : (float) $quoteItem->getDiscountAmount();

        $discount = new \stdClass();
        $discount->currency_code = $this->getOrderCurrencyCode($order);
        $discount->value = $discountAmount;

        return $discount;
    }

    /**
     * @param \Magento\Sales\Model\Order\Item $quoteItem
     * @return string|null
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getCategoryByQuoteItem($quoteItem): \stdClass
    {
        $category = new \stdClass();
        $category->id = 1;
        $category->name = 'N/A';
        try {
            $productId = $quoteItem->getProductId();
            $product = $this->productRepository->getById($productId);
            $categoryIds = $product->getCategoryIds();

            if (!empty($categoryIds)) {
                $categoryId = $categoryIds[0];
                if (!isset($this->categories[$categoryId])) {
                    $this->categories[$categoryId] = $this->categoryRepository->get($categoryId);
                }
                $category->id = $this->categories[$categoryId]->getId();
                $category->name = $this->categories[$categoryId]->getName();
            }
        } catch (\Exception $e) {
            $this->helper->log($e->getMessage());
        }

        return $category;
    }

    /**
     * @param \Magento\Sales\Model\Order\Item $quoteItem
     * @return string|null
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getCategoryNameByQuoteItem($quoteItem): ?string
    {
        $category = $this->getCategoryByQuoteItem($quoteItem);
        if ($category) {
            return $category->name;
        }
        return 'N/A';
    }

    /**
     * @param string $customerTaxVat
     * @param string $country
     * @return \stdClass
     */
    protected function getDocument(string $customerTaxVat): \stdClass
    {
        $document = new \stdClass();
        $document->type = 'dni';
        $document->number = $customerTaxVat;

        if ($this->helper->validateCnpj($customerTaxVat)) {
            $document->type = 'cnpj';
            $document->number = preg_replace('/[^0-9]/', '', $customerTaxVat);
        } elseif ($this->helper->validateCpf($customerTaxVat)) {
            $document->type = 'cpf';
            $document->number = preg_replace('/[^0-9]/', '', $customerTaxVat);
        }
        return $document;
    }

    /**
     * @return string
     */
    protected function getExpirationDate(int $time = 1440): string
    {
        $expirationTime = (int) $time;
        $expirationTime = $expirationTime > 0 ? $expirationTime : \Koin\Payment\Helper\Order::DEFAULT_EXPIRATION_TIME;
        $minutes = "+{$expirationTime} minutes";
        $timeStamp = $this->dateTime->timestamp($minutes);
        return (string) $this->dateTime->gmtDate('Y-m-d\TH:i:s', $timeStamp) . '.000Z';
    }

    /**
     * @param Order $order
     * @return \stdClass
     */
    public function getShippingData($order): \stdClass
    {
        $shipping = new \stdClass();
        $shipping->address = $this->getAddress($order->getShippingAddress());
        $shipping->delivery = new \stdClass();
        $shipping->delivery->by = $order->getShippingDescription();
        $shipping->delivery->date = $this->helper->getDeliveryDate($order);
        $shipping->delivery->type = Data::DEFAULT_DELIVERY_TYPE;
        $shipping->price = new \stdClass();
        $shipping->price->currency_code = $this->helper->getOrderCurrencyCode($order);
        $shipping->price->value = (float) $order->getShippingAmount();

        return $shipping;
    }
}
