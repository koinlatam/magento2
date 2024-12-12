<?php

/**
 * Koin
 *
 * @category    Koin
 * @package     Koin_Payment
 */

namespace Koin\Payment\Helper;

use BaconQrCode\Renderer\ImageRenderer as QrCodeImageRenderer;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd as QrCodeImagickImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle as QrCodeRendererStyle;
use BaconQrCode\Writer as QrCodeWritter;
use Koin\Payment\Helper\Data as HelperData;
use Koin\Payment\Gateway\Http\Client;
use Koin\Payment\Gateway\Http\Client\Payments\Api;
use Koin\Payment\Model\Ui\CreditCard\ConfigProvider as CcConfigProvider;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\LayoutFactory;
use Magento\Payment\Model\Config;
use Magento\Payment\Model\Method\Factory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\InvoiceRepository;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\ResourceModel\Order\Payment as ResourcePayment;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Store\Model\App\Emulation;

class Order extends \Magento\Payment\Helper\Data
{
    const STATUS_APPROVED = 'approved';
    const STATUS_DENIED = 'denied';

    const STATUS_REFUNDED = 'refunded';
    const DEFAULT_QRCODE_WIDTH = 400;
    const DEFAULT_QRCODE_HEIGHT = 400;
    const DEFAULT_EXPIRATION_TIME = 30;

    /**
     * @var HelperData
     */
    protected $helperData;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var InvoiceRepository
     */
    protected $invoiceRepository;

    /**
     * @var CreditmemoFactory
     */
    protected $creditmemoFactory;

    /**
     * @var CreditmemoService
     */
    protected $creditmemoService;

    /**
     * @var ResourcePayment
     */
    protected $resourcePayment;

    /**
     * @var CollectionFactory
     */
    protected $orderStatusCollectionFactory;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /** @var Client */
    protected $client;

    /** @var Api */
    protected $api;

    /**
     * @var DateTime
     */
    protected $dateTime;

    /** @var CustomerSession $customerSession */
    protected $customerSession;

    public function __construct(
        Context $context,
        LayoutFactory $layoutFactory,
        Factory $paymentMethodFactory,
        Emulation $appEmulation,
        Config $paymentConfig,
        Initial $initialConfig,
        OrderFactory $orderFactory,
        CreditmemoFactory $creditmemoFactory,
        OrderRepository $orderRepository,
        InvoiceRepository $invoiceRepository,
        CreditmemoService $creditmemoService,
        ResourcePayment $resourcePayment,
        CollectionFactory $orderStatusCollectionFactory,
        Filesystem $filesystem,
        Client $client,
        Api $api,
        CustomerSession $customerSession,
        DateTime $dateTime,
        HelperData $helperData
    ) {
        parent::__construct($context, $layoutFactory, $paymentMethodFactory, $appEmulation, $paymentConfig, $initialConfig);

        $this->helperData = $helperData;
        $this->orderFactory = $orderFactory;
        $this->orderRepository = $orderRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->resourcePayment = $resourcePayment;
        $this->filesystem = $filesystem;
        $this->dateTime = $dateTime;
        $this->client = $client;
        $this->api = $api;
        $this->customerSession = $customerSession;
        $this->orderStatusCollectionFactory = $orderStatusCollectionFactory;
    }

    /**
     * Update Order Status
     *
     * @param SalesOrder $order
     * @param string $koinStatus
     * @param array $content
     * @param float $amount
     * @param bool $callback
     * @return bool
     */
    public function updateOrder(
        SalesOrder $order,
        string $koinStatus,
        string $koinState,
        array $content,
        float $amount,
        bool $callback = false
    ): bool {
        try {
            /** @var Payment $payment */
            $payment = $order->getPayment();
            $orderStatus = $payment->getAdditionalInformation('status');
            $order->addCommentToStatusHistory(__('Callback received %1 -> %2', $orderStatus, $koinStatus));

            if ($koinStatus != $orderStatus) {
                if ($koinState == self::STATUS_APPROVED) {
                    if ($koinStatus == Api::STATUS_COLLECTED) {
                        if ($order->canInvoice()) {
                            $order = $this->invoiceOrder($order, $amount);
                        }
                    }
                    $order = $this->addPaidComment($order);
                } else {
                    if ($koinState == self::STATUS_DENIED) {
                        $order = $this->cancelOrder($order, $amount, $callback);
                    } elseif ($koinState == self::STATUS_REFUNDED) {
                        $order = $this->refundOrder($order, $amount, $callback);
                    }
                }
            }

            $payment = $this->updateAdditionalInfo($payment, $content);
            $order->setData('koin_last_callback_date', $this->dateTime->gmtDate());
            $this->orderRepository->save($order);
            $this->savePayment($payment);

            if ($koinStatus == Api::STATUS_AUTHORIZED) {
                if (
                    $payment->getMethod() == CcConfigProvider::CODE
                    && $this->helperData->getCcConfig('auto_capture', $order->getStoreId())
                ) {
                    $this->captureOrder($order);
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->helperData->log($e->getMessage());
        }

        return false;
    }

    /**
     * @param Payment $payment
     * @return void
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function savePayment($payment)
    {
        $this->resourcePayment->save($payment);
    }

    /**
     * @param Payment $payment
     * @param $content
     * @return Payment|mixed
     */
    protected function updateAdditionalInfo(Payment $payment, array $content): Payment
    {
        $payment = $this->updateDefaultAdditionalInfo($payment, $content);
        if ($payment->getMethod() == \Koin\Payment\Model\Ui\Pix\ConfigProvider::CODE) {
            $payment = $this->updatePixAdditionalInfo($payment, $content);
        } elseif ($payment->getMethod() == \Koin\Payment\Model\Ui\Redirect\ConfigProvider::CODE) {
            $payment = $this->updateRedirectAdditionalInfo($payment, $content);
        }

        return $payment;
    }

    public function invoiceOrder(SalesOrder $order, $amount): SalesOrder
    {
        if ($amount == $order->getBaseGrandTotal()) {
            /** @var Invoice $invoice */
            $invoice = $order->prepareInvoice();
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
            $invoice->register();
            $invoice->pay();
            $this->invoiceRepository->save($invoice);
            $order = $invoice->getOrder();

            // Update the order
            $order->getPayment()->setAdditionalInformation('captured', true);
        }
        return $order;
    }

    /**
     * @param SalesOrder $order
     * @param $content
     * @return SalesOrder
     */
    public function updateInterestRate(SalesOrder $order, array $content): SalesOrder
    {
        if (isset($content['amount']) && isset($content['amount']['value'])) {
            $amountValue = (float)$content['amount']['value'];
            if ($amountValue > $order->getBaseGrandTotal()) {
                $currency = $content['amount']['currency'] ?: $order->getBaseCurrencyCode();
                if ($order->getBaseCurrencyCode() == $currency) {
                    $interest = $amountValue - $order->getBaseGrandTotal();
                    $order->setData('koin_interest_amount', $interest);
                    $order->setData('base_koin_interest_amount', $interest);
                    $order->setGrandTotal($amountValue);
                    $order->setBaseGrandTotal($amountValue);
                }
            }
        }

        return $order;
    }

    /**
     * @param SalesOrder $order
     * @param float $amount
     * @param boolean $callback
     * @return SalesOrder $order
     *@throws \Magento\Framework\Exception\LocalizedException
     */
    public function cancelOrder(SalesOrder $order, float $amount, bool $callback = false): SalesOrder
    {
        if (
            $order->canCreditmemo()
            && (!$callback && $this->helperData->getGeneralConfig('refund_on_cancel'))
        ) {
            $creditMemo = $this->creditmemoFactory->createByOrder($order);
            $this->creditmemoService->refund($creditMemo, true);
        } elseif ($order->canCancel()) {
            if ($callback) {
                $order->registerCancellation();
            } else {
                $order->cancel();
            }
        }

        $method = $order->getPayment()->getMethod();
        $cancelledStatus = $this->helperData->getConfig('cancelled_order_status', $method) ?: false;
        $message = __('The order %1 was cancelled. Amount of %2', $order->getIncrementId(), $amount);

        $order->addCommentToStatusHistory($message, $cancelledStatus);

        return $order;
    }

    public function refundOrder(SalesOrder $order, float $amount, bool $callback = false): SalesOrder
    {
        if ($order->getBaseGrandTotal() == $amount) {
            return $this->cancelOrder($order, $amount, $callback);
        }

        $totalRefunded = (float) $order->getTotalRefunded() + $amount;
        $order->setTotalRefunded($totalRefunded);
        $order->addCommentToStatusHistory(__('The order had the amount refunded by Koin. Amount of %1', $amount));

        return $order;
    }

    /**
     * @param $order
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function credimemoOrder(SalesOrder $order): void
    {
        $creditMemo = $this->creditmemoFactory->createByOrder($order);
        $this->creditmemoService->refund($creditMemo);
    }

    /**
     * @throws \Exception
     */
    public function captureOrder(SalesOrder $order, string $captureCase = 'online'): void
    {
        if ($order->canInvoice()) {
            /** @var Invoice $invoice */
            $invoice = $order->prepareInvoice();
            $invoice->setRequestedCaptureCase($captureCase);
            $invoice->register();
            $invoice->pay();

            $this->invoiceRepository->save($invoice);

            // Update the order
            $invoicedOrder = $invoice->getOrder();
            $invoicedOrder->getPayment()->setAdditionalInformation('captured', true);
            $invoicedOrder = $this->addPaidComment($invoicedOrder);

            $this->orderRepository->save($invoicedOrder);
        }
    }

    protected function addPaidComment(OrderInterface $order): OrderInterface
    {
        $paymentMethod = $order->getPayment()->getMethod();
        $paidStatus = $order->getIsVirtual()
            ? $this->helperData->getConfig('paid_virtual_order_status', $paymentMethod)
            : $this->helperData->getConfig('paid_order_status', $paymentMethod);

        $message = __('Your payment for the order %1 was confirmed', $order->getIncrementId());
        $order->addCommentToStatusHistory($message, $paidStatus, true);
        $order->setState($this->getStatusState($paidStatus));

        return $order;
    }

    public function notification(SalesOrder $order, $status = 'FINALIZED'): void
    {
        $requestData = [
            'type' => 'STATUS',
            'sub_type' => $status,
            'notification_date' => $this->dateTime->gmtDate('Y-m-d\TH:i:s') . '.000Z'
        ];
        $this->notify($order->getIncrementId(), $requestData, $order->getStoreId());
    }

    public function notify(string $referenceId, array $requestData, $storeId = null): void
    {
        $queryParams = ['field' => 'REFERENCE_ID'];
        $urlPath = $this->api->notification()->getEndpointPath('payments/notifications', $referenceId);
        $this->api->logRequest($urlPath, 'koin-payments');
        $this->api->logRequest($requestData, 'koin-payments');
        $response = $this->api->notification()->notify($referenceId, $requestData, $queryParams, $storeId);
        $this->api->logResponse($response, 'koin-payments');

        $urlLog = $urlPath;
        $urlLog .= '?' . http_build_query($queryParams);

        $body = $this->helperData->jsonEncode($requestData);
        $requestLog = "URL: {$urlLog} \n <br>BODY: {$body}";
        $this->api->saveRequest($requestLog, $response['response'], $response['status'], 'koin-payments');
    }

    public function updateRedirectAdditionalInfo(Payment $payment, array $content): Payment
    {
        try {
            if (isset($content['return_url'])) {
                $payment->setAdditionalInformation('return_url', $content['return_url']);
            }
        } catch (\Exception $e) {
            $this->_logger->warning($e->getMessage());
        }

        return $payment;
    }

    public function updateDefaultAdditionalInfo(Payment $payment, array $content): Payment
    {
        try {
            if (isset($content['order_id'])) {
                if (!$payment->getAdditionalInformation('order_id')) {
                    $payment->setTransactionId($content['order_id']);
                    $payment->setLastTransId($content['order_id']);
                    $payment->setAdditionalInformation('order_id', $content['order_id']);
                }
            }

            if (isset($content['tid'])) {
                if (!$payment->getAdditionalInformation('tid')) {
                    $payment->setAdditionalInformation('tid', $content['tid']);
                    $payment->setTransactionId($content['tid']);
                    $payment->setLastTransId($content['tid']);
                }
            }

            if (isset($content['installments'])) {
                $payment->setAdditionalInformation('installments', $content['installments']);
            }

            if (isset($content['installments'])) {
                $payment->setAdditionalInformation('installments', $content['installments']);
            } else if (isset($content['installment_option']) && isset($content['installment_option']['installments'])) {
                $payment->setAdditionalInformation('installments', $content['installment_option']['installments']);
            }

            if (isset($content['transaction']['reference_id'])) {
                $payment->setAdditionalInformation('reference_id', $content['transaction']['reference_id']);
            }

            if (isset($content['transaction']['business_id'])) {
                $payment->setAdditionalInformation('business_id', $content['transaction']['business_id']);
            }

            if (isset($content['transaction']['account'])) {
                $payment->setAdditionalInformation('account', $content['transaction']['account']);
            }

            if (isset($content['transaction']['amount'])) {
                if (isset($content['transaction']['amount']['value'])) {
                    $payment->setAdditionalInformation('total_amount', $content['transaction']['amount']['value']);
                }

                if (isset($content['transaction']['amount']['currency_code'])) {
                    $payment->setAdditionalInformation(
                        'currency_code',
                        $content['transaction']['amount']['currency_code']
                    );
                }
            }

            //Set an order status, if it doesn't have the status in response, set the Published status
            $status = \Koin\Payment\Gateway\Http\Client\Payments\Api::STATUS_PENDING;
            if (isset($content['status'])) {
                if (isset($content['status']['type'])) {
                    $status = $content['status']['type'];
                }
                if (isset($content['status']['reason'])) {
                    $payment->setAdditionalInformation('status_reason', $content['status']['reason']);
                }
                if (isset($content['status']['date'])) {
                    $payment->setAdditionalInformation('status_date', $content['status']['date']);
                }
            }

            $payment->setAdditionalInformation('status', $status);
            $payment->setIsTransactionClosed(false);
        } catch (\Exception $e) {
            $this->_logger->warning($e->getMessage());
        }

        return $payment;
    }

    public function updateRequestAdditionalData(Payment $payment, array $additionalData): Payment
    {
        try {
            if (!empty($additionalData)) {
                foreach ($additionalData as $key => $value) {
                    $payment->setAdditionalInformation($key, $value);
                }
            }
        } catch (\Exception $e) {
            $this->_logger->warning($e->getMessage());
        }

        return $payment;
    }

    /**
     * @throws LocalizedException
     */
    public function updateCreditCardAdditionalInformation(Payment $payment, $transaction): Payment
    {
        $payment = $this->verifyCcData($payment, [
            'cc_type',
            'cc_owner',
            'cc_last_4',
            'cc_exp_month',
            'cc_exp_year',
            'cc_installments',
            'installments',
            'rule_id',
            'rule_account_number',
            'rule_data',
            'payment_method'
        ]);

        if (!$payment->getCcType()) {
            $payment->setCcType($this->customerSession->getData('cc_type'));
        }

        if (!$payment->getCcOwner()) {
            $payment->setCcOwner($this->customerSession->getData('cc_owner'));
        }

        if (!$payment->getCcLast4()) {
            $payment->setCcLast4($this->customerSession->getData('cc_last_4'));
        }

        if (!$payment->getCcExpMonth()) {
            $payment->setCcExpMonth($this->customerSession->getData('cc_exp_month'));
        }

        if (!$payment->getCcExpYear()) {
            $payment->setCcExpYear($this->customerSession->getData('cc_exp_year'));
        }

        $ccNumber = (string) $payment->getAdditionalInformation('cc_bin') . '****' . $payment->getCcLast4();
        $this->customerSession->setData('cc_number', $ccNumber);
        $payment->setAdditionalInformation('cc_number', $ccNumber);
        $payment->setAdditionalInformation('credit_card_number', $ccNumber);

        $ccCid = preg_replace('/[^0-9]/', '****', (string) $this->customerSession->getData('cc_exp_year'));
        $this->customerSession->setData('cc_cid', $ccNumber);
        $payment->setAdditionalInformation('cc_cid', $ccCid);
        $payment->setAdditionalInformation('credit_card_cvv', $ccCid);

        return $payment;
    }

    protected function verifyCcData($payment, $infoData): Payment
    {
        foreach ($infoData as $key) {
            if (!$payment->getAdditionalInformation($key)) {
                $payment->setAdditionalInformation($key, $this->customerSession->getData($key));
            }
        }
        return $payment;
    }

    public function updatePixAdditionalInfo(Payment $payment, array $content): Payment
    {
        try {
            if (isset($content['locations'])) {
                if (isset($content['locations'][0])) {
                    $location = $content['locations'][0];

                    if (isset($location['location']['qr_code'])) {
                        $payment->setAdditionalInformation('qr_code', $location['location']['qr_code']);
                    }

                    if (isset($location['location']['emv'])) {
                        $payment->setAdditionalInformation('qr_code_emv', $location['location']['emv']);
                        if (isset($location['location']['url'])) {
                            $qrCodeUrl = $location['location']['url'];
                        } else {
                            $qrCodeUrl = $this->generateQrCode($payment, $location['location']['emv']);
                        }
                        $payment->setAdditionalInformation('qr_code_url', $qrCodeUrl);
                    }
                }
            }

            $payment->setIsTransactionClosed(false);
        } catch (\Exception $e) {
            $this->_logger->warning($e->getMessage());
        }

        return $payment;
    }

    public function updateRefundedAdditionalInformation(Payment $payment, $transaction): Payment
    {
        if (isset($transaction['business_id'])) {
            $payment->setAdditionalInformation('business_id', $transaction['business_id']);
        }

        if (isset($transaction['status'])) {
            if (isset($transaction['status']['type'])) {
                $payment->setAdditionalInformation('refund_status', $transaction['status']['type']);
            }
        }

        if (isset($transaction['amount'])) {
            if (isset($transaction['amount']['value'])) {
                $refundAmount = (float) $transaction['amount']['value'];
                $orderRefunded = (float) $payment->getAdditionalInformation('total_refunded');
                $payment->setAdditionalInformation('total_refunded', $refundAmount + $orderRefunded);
            }
        }

        if (isset($transaction['refund_id'])) {
            $payment->setAdditionalInformation('refund_id', $transaction['refund_id']);
            $payment->setAdditionalInformation('refunded', true);
            $payment->addTransaction('refund');
        }
        $payment->setAdditionalInformation('voided', true);

        return $payment;
    }

    public function generateQrCode($payment, $qrCode): string
    {
        $pixUrl = '';
        if ($qrCode) {
            try {
                $renderer = new QrCodeImageRenderer(
                    new QrCodeRendererStyle(self::DEFAULT_QRCODE_WIDTH),
                    new QrCodeImagickImageBackEnd()
                );
                $writer = new QrCodeWritter($renderer);
                $pixQrCode = $writer->writeString($qrCode);

                $filename = 'koin/pix-' . $payment->getOrder()->getIncrementId() . '.png';
                $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
                $media->writeFile($filename, $pixQrCode);

                $pixUrl = $this->helperData->getMediaUrl() . $filename;
            } catch (\Exception $e) {
                $this->helperData->log($e->getMessage());
            }
        }

        return $pixUrl;
    }

    public function loadOrder(string $incrementId): SalesOrder
    {
        $order = $this->orderFactory->create();
        if ($incrementId) {
            $order->loadByIncrementId($incrementId);
        }

        return $order;
    }

    public function getStatusState($status): string
    {
        if ($status) {
            $statuses = $this->orderStatusCollectionFactory
                ->create()
                ->joinStates()
                ->addFieldToFilter('main_table.status', $status);

            if ($statuses->getSize()) {
                return $statuses->getFirstItem()->getState();
            }
        }

        return '';
    }

    /**
     * @param $payment
     * @return string
     */
    public function getPaymentStatusState($payment): string
    {
        $defaultState = $payment->getOrder()->getState();
        $paymentMethod = $payment->getMethodInstance();
        if (!$paymentMethod) {
            return $defaultState;
        }

        $status = $paymentMethod->getConfigData('order_status');
        if (!$status) {
            return $defaultState;
        }

        $state = $this->getStatusState($status);
        if (!$state) {
            return $defaultState;
        }

        return $state;
    }

    public function queryStatus(string $orderId, $storeId = null): ?string
    {
        try {
            $response = $this->api->query()->execute($orderId, $storeId);
            $responseStatus = $response['response']['status'] ?? [];
            return $responseStatus['type'] ?? null;
        } catch (\Exception $e) {
            $this->helperData->log($e->getMessage());
        }
        return null;
    }

    /**
     * @param $state
     * @return bool
     */
    public function canSkipOrderProcessing($state): bool
    {
        return $state != SalesOrder::STATE_PROCESSING;
    }


    /**
     * @param string $callbackStatus
     * @return string
     */
    public function getStatus(string $callbackStatus): string
    {
        switch ($callbackStatus) {
            case Api::STATUS_COLLECTED:
            case Api::STATUS_AUTHORIZED:
                $status = Order::STATUS_APPROVED;
                break;

            case Api::STATUS_REFUNDED:
                $status = Order::STATUS_REFUNDED;
                break;

            case Api::STATUS_CANCELLED:
            case Api::STATUS_FAILED:
            case Api::STATUS_VOIDED:
                $status = Order::STATUS_DENIED;
                break;

            default:
                $status = $callbackStatus;
        }

        return $status;
    }

    /**
     * @param $order
     * @param float $amountValue
     * @return \stdClass
     */
    public function getRefundRequest($order, float $amountValue): \stdClass
    {
        $request = new \stdClass();
        $request->transaction = new \stdClass();
        $request->transaction->reference_id = $order->getIncrementId();
        $request->amount = new \stdClass();
        $request->amount->currency_code = $order->getBaseCurrencyCode() ?: $this->helperData->getStoreCurrencyCode();
        $request->amount->value = round($amountValue, 2);
        return $request;
    }
}
