<?php

declare(strict_types=1);

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

namespace Koin\Payment\Controller\Payment;

use Koin\Payment\Helper\Antifraud as AntifraudHelper;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;

class AntifraudStrategy extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context                            $context,
        protected JsonFactory              $resultJsonFactory,
        protected Json                     $json,
        protected OrderRepositoryInterface $orderRepository,
        protected Http                     $response,
        protected AntifraudHelper          $antifraudHelper
    )
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $orderId = $this->getRequest()->getParam('oId');

        if (!$orderId || !$this->validateRequest($orderId)) {
            $this->response->setHttpResponseCode(403);
            return $this->response;
        }

        $this->response->setHeader('Content-Type', 'text/event-stream', true);
        $this->response->setHeader('Connection', 'keep-alive', true);
        $this->response->setHeader('Cache-Control', 'no-cache', true);
        $this->response->setHeader('X-Accel-Buffering', 'no', true);

        $limit = 60;
        for ($i = 0; $i < $limit; $i++) {
            try {
                $order = $this->orderRepository->get($orderId);
                $isApproved = $order->getData('koin_antifraud_status') == AntifraudHelper::APPROVED_STATUS;

                $result = [
                    'order_id' => $order->getId(),
                    'is_approved' => $isApproved,
                ];

                $data = "event: koin-payment-antifraud-strategy\n" .
                    "data: " . $this->json->serialize($result) . "\n\n";

                $this->response->appendBody($data);
                $this->response->sendResponse();

                ob_flush();
                flush();

                if ($isApproved) {
                    break;
                }

                sleep(5);

            } catch (\Exception $e) {
                $this->response->setBody("event: error\ndata: " . $this->json->serialize(['error' => $e->getMessage()]) . "\n\n");
                $this->response->sendResponse();
                return $this->response;
            }
        }

        return $this->response;
    }

    private function validateRequest(int|string $orderId): bool
    {
        try {
            $this->orderRepository->get($orderId);
            return true;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return false;
        }
    }
}
