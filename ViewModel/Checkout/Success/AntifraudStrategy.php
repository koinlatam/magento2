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

declare(strict_types=1);

namespace Koin\Payment\ViewModel\Checkout\Success;

use Koin\Payment\Helper\Data as HelperData;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class AntifraudStrategy implements ArgumentInterface
{
    private const ROUTE_CHECKOUT = 'checkout';
    private const ROUTE_ORDER_VIEW = 'sales/order/view';
    private const STRATEGY_ROUTE_PATH = 'koin/payment/antifraudstrategy';
    private const PAYMENT_INFO_KEY = 'koin_antifraud_strategy_link';

    public function __construct(
        private Session                  $checkoutSession,
        private UrlInterface             $urlBuilder,
        private HelperData               $helperData,
        private OrderRepositoryInterface $orderRepository,
        private RequestInterface         $request,
        private LoggerInterface          $logger,
        private ?OrderInterface          $order = null
    )
    {
    }

    private function getOrder(): ?OrderInterface
    {
        if ($this->order) {
            return $this->order;
        }

        $currentUrl = $this->urlBuilder->getCurrentUrl();

        if (str_contains($currentUrl, self::ROUTE_ORDER_VIEW)) {
            $this->order = $this->getOrderFromRequest();
        }

        if (!$this->order && str_contains($currentUrl, self::ROUTE_CHECKOUT)) {
            $this->order = $this->getOrderFromCheckoutSession();
        }

        return $this->order;
    }

    private function getOrderFromRequest(): ?OrderInterface
    {
        $orderId = $this->request->getParam('order_id');

        if (!$orderId) {
            return null;
        }

        try {
            return $this->orderRepository->get((int)$orderId);
        } catch (NoSuchEntityException $e) {
            $this->logger->warning('Order not found in repository', [
                'order_id' => $orderId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error loading order from repository', [
                'order_id' => $orderId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    private function getOrderFromCheckoutSession(): ?OrderInterface
    {
        try {
            $order = $this->checkoutSession->getLastRealOrder();

            if (!$order || !$order->getEntityId()) {
                $this->logger->debug('No valid order found in checkout session');
                return null;
            }

            return $order;
        } catch (LocalizedException $e) {
            $this->logger->warning('Error retrieving order from checkout session', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    public function getStrategyLink(): string
    {
        $order = $this->getOrder();

        if (!$order) {
            return '';
        }

        $payment = $order->getPayment();

        if (!$payment) {
            $this->logger->debug('No payment found for order', [
                'order_id' => $order->getEntityId()
            ]);
            return '';
        }

        $strategyLink = $payment->getAdditionalInformation(self::PAYMENT_INFO_KEY);

        return $strategyLink ? (string)$strategyLink : '';
    }

    public function getAntifraudStrategyUrl(): string
    {
        $order = $this->getOrder();

        if (!$order || !$order->getEntityId()) {
            return '';
        }

        return $this->urlBuilder->getUrl(
            self::STRATEGY_ROUTE_PATH,
            ['oId' => $order->getEntityId()]
        );
    }

    public function isPending(): bool
    {
        $order = $this->getOrder();

        if (!$order) {
            return false;
        }

        $orderStatus = $order->getStatus();
        $pendingStatuses = $this->getPendingStatuses();

        return in_array($orderStatus, $pendingStatuses, true);
    }

    public function isStrategiesEnabled(): bool
    {
        return (bool)$this->helperData->getAntifraudConfig('active_strategy');
    }

    public function getPendingStatuses(): array
    {
        $statusConfig = $this->helperData->getAntifraudConfig('order_status');

        if (empty($statusConfig) || !is_string($statusConfig)) {
            return [];
        }

        return explode(',', $statusConfig);
    }
}
