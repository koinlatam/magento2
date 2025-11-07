<?php
/**
 * @package Koin\Payment
 * @copyright Copyright (c) 2021 Koin
 * @license https://opensource.org/licenses/OSL-3.0.php Open Software License 3.0
 */

namespace Koin\Payment\Observer\Sales;

use Koin\Payment\Helper\Antifraud;
use Koin\Payment\Helper\Data;
use Koin\Payment\Service\NotificationService;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class OrderSaveAfter implements ObserverInterface
{
    /**
     * @param Data $helper
     * @param Antifraud $helperAntifraud
     * @param NotificationService $notificationService
     */
    public function __construct(
        protected Data $helper,
        protected Antifraud $helperAntifraud,
        protected NotificationService $notificationService
    )
    {
    }

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getDataObject();
        $originalState = $order->getOrigData('state');

        try {
            if ($originalState != $order->getState()) {
                $this->notificationService->sendNotificationForOrderState($order);
            }

            $this->sendAntifraud($order);

        } catch (\Exception $e) {
            $this->helper->log($e->getMessage());
        }
    }

    /**
     * @param Order $order
     * @return void
     * @throws \Exception
     */
    protected function sendAntifraud($order): void
    {
        if ($this->helper->getAntifraudConfig('active')) {
            $paymentMethods = explode(',', $this->helper->getAntifraudConfig('payment_methods'));
            $statuses = explode(',', $this->helper->getAntifraudConfig('order_status'));
            $minOrderTotal = (float) $this->helper->getAntifraudConfig('min_order_total');

            //@phpstan-ignore-next-line
            if (!empty($paymentMethods)) {
                if (in_array($order->getPayment()->getMethod(), $paymentMethods)) {
                    if (in_array($order->getStatus(), $statuses)) {
                        if ($order->getGrandTotal() >= $minOrderTotal) {
                            $this->helperAntifraud->sendAnalysis($order);
                        }
                    }
                }
            }
        }
    }

}
