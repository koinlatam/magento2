<?php

declare(strict_types=1);

/**
 *
 *
 *
 * @category    Koin
 * @package     Koin_Payment
 */

namespace Koin\Payment\Controller\Callback;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Koin\Payment\Controller\Callback;

class Risk extends Callback
{
    /**
     * @var string
     */
    protected $eventName = 'risk';

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $hash = $request->getParam('hash');
        $storeHash = $this->helperData->getHash(0);
        return ($hash == $storeHash);
    }

    /**
     * Exemplo:
        {
            "id": "VEFRUD8HUR25N4992221",
            "evaluation_id": "31ff2cc8-199e-4c3c-ba2d-4d85b88bd9f2",
            "status": "received",
            "score": 50,
            "analysis_type": "automatic"
        }
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $this->helperData->log(__('Webhook %1', __CLASS__), self::LOG_NAME);

        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $statusCode = 500;

        try {
            $content = $this->getContent($this->getRequest());
            $params = $this->getRequest()->getParams();
            $this->logParams($content, $params);

            if (isset($content['id'])) {
                $this->helperAntifraud->updateOrderByAnalysis(
                    $content['evaluation_id'],
                    $content['status'],
                    $content['score'],
                    $content['analysis_type'],
                    $content['strategies'][0]['link'] ?? null
                );

                /** @var \Koin\Payment\Model\Callback $callBack */
                $callBack = $this->callbackFactory->create();
                $callBack->setStatus($content['status']);
                $callBack->setIncrementId($content['id']);
                $callBack->setMethod(\Koin\Payment\Model\Antifraud::RESOURCE_CODE);
                $callBack->setPayload($this->json->serialize($content));
                $this->callbackResourceModel->save($callBack);

                $statusCode = 204;
            }
        } catch (\Exception $e) {
            $this->helperData->getLogger()->error($e->getMessage());
        }

        $result->setHttpResponseCode($statusCode);
        return $result;
    }
}
