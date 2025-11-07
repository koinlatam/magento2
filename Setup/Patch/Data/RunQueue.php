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

namespace Koin\Payment\Setup\Patch\Data;

use Koin\Payment\Api\AntifraudRepositoryInterface;
use Koin\Payment\Api\QueueRepositoryInterface;
use Koin\Payment\Helper\Antifraud as AntifraudHelper;
use Koin\Payment\Helper\Data;
use Koin\Payment\Helper\Order as OrderHelper;
use Koin\Payment\Model\Antifraud as AntifraudModel;
use Koin\Payment\Model\Queue;
use Koin\Payment\Model\ResourceModel\Queue\CollectionFactory;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

class RunQueue implements DataPatchInterface
{
    public function __construct(
        protected CollectionFactory $collectionFactory,
        protected Data $helper,
        protected AntifraudHelper $helperAntifraud,
        protected QueueRepositoryInterface $queueRepository,
        protected AntifraudRepositoryInterface $antifraudRepository,
        protected LoggerInterface $logger,
        protected OrderHelper $helperOrder,
    ) {
    }

    /**
     * @return void
     */
    public function apply()
    {
            $collection = $this->collectionFactory->create();
            $collection
                ->addFieldToFilter('status', Queue::STATUS_PENDING)
                ->addFieldToFilter('resource', AntifraudModel::RESOURCE_CODE);

            /** @var Queue $queue */
            foreach ($collection as $queue) {
                try {
                    $antifraud = $this->antifraudRepository->get($queue->getResourceId());
                    if ($antifraud && $antifraud->getId()) {
                        $order = $this->helperOrder->loadOrder($antifraud->getIncrementId());
                        if (!$order->getId()) {
                            throw new \Exception('Order ' . $antifraud->getIncrementId() . ' not found.');
                        }
                        $this->helperAntifraud->sendAnalysis($order);
                        $queue->setStatus(Queue::STATUS_DONE);
                        $this->queueRepository->save($queue);
                    }
                } catch (\Exception $e) {
                    $errorMessage = 'Error processing antifraud analysis: ' . $e->getMessage();
                    $this->logger->error($errorMessage, [
                        'queue_id' => $queue->getEntityId(),
                        'resource_id' => $queue->getResourceId(),
                        'exception' => $e
                    ]);

                    try {
                        $queue->setStatus(Queue::STATUS_ERROR);
                        $this->queueRepository->save($queue);

                        if ($queue->getData('resource') === AntifraudModel::RESOURCE_CODE) {
                            try {
                                $antifraud = $this->antifraudRepository->get($queue->getResourceId());
                                $antifraud->setStatus('error');
                                $antifraud->setMessage($errorMessage);
                                $this->antifraudRepository->save($antifraud);
                            } catch (\Exception $antifraudException) {
                                $this->logger->error('Failed to update antifraud record: ' . $antifraudException->getMessage(), [
                                    'antifraud_id' => $queue->getResourceId(),
                                    'exception' => $antifraudException
                                ]);
                            }
                        }
                    } catch (\Exception $saveException) {
                        $this->logger->error('Failed to save error status: ' . $saveException->getMessage(), [
                            'queue_id' => $queue->getEntityId(),
                            'exception' => $saveException
                        ]);
                    }
                    continue;
                }
            }

    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }
}
