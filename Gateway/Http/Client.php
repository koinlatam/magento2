<?php

/**
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Koin
 * @package     Koin_Payment
 */

namespace Koin\Payment\Gateway\Http;

use Magento\Framework\Encryption\EncryptorInterface;
use Laminas\Http\Client as HttpClient;
use Magento\Framework\Serialize\Serializer\Json;
use Koin\Payment\Helper\Data;

class Client
{
    public const STATUS_UNDEFINED = 'undefined';

    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED = 'denied';

    public const STATUS_REASON_EMAIL_VALIDATION = 'EmailValidation';
    public const STATUS_REASON_PROVIDER_REVIEW = 'ProviderReview';
    public const STATUS_REASON_FIRST_PAYMENT = 'FirstPayment';

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var HttpClient
     */
    protected $api;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var string
     */
    protected $token;

    protected $apiType = 'payments';


    /**
     * @param Data $helper
     * @param EncryptorInterface $encryptor
     * @param Json $json
     */
    public function __construct(
        Data $helper,
        EncryptorInterface $encryptor,
        Json $json
    ) {
        $this->helper = $helper;
        $this->encryptor = $encryptor;
        $this->json = $json;
    }

    public function getApiType(): string
    {
        return $this->apiType;
    }

    public function setApiType(string $apiType): void
    {
        $this->apiType = $apiType;
    }

    /**
     * @return string[]
     */
    protected function getDefaultHeaders($storeId = null): array
    {
        $privateKey = $this->helper->getGeneralConfig('private_key', $storeId);

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->encryptor->decrypt($privateKey),
            'X-Module-Version' => $this->helper->getModuleVersion()
        ];

        if ($this->helper->getGeneralConfig('use_sandbox')) {
            $headers['User-Agent'] = 'koin-oficial';
        }

        return $headers;
    }

    protected function get3DSHeaders($storeId = null): array
    {
        $headers = $this->getDefaultHeaders($storeId);
        $headers['xdesp-mock-risk-juggler'] = 'verdict=inprogress|strategy=3DS2CHALLENGE';
        return $headers;
    }

    /**
     * @return array
     */
    protected function getDefaultOptions(): array
    {
        return [
            'timeout' => 30
        ];
    }

    /**
     * @param string $endpoint
     * @param string $orderId
     * @param string $evaluationId
     * @return string
     */
    public function getEndpointPath(string $endpoint, $orderId = null, $evaluationId = null): string
    {
        $fullEndpoint = $this->helper->getEndpointConfig($endpoint);
        return str_replace(
            ['{order_id}', '{evaluation_id}'],
            [$orderId, $evaluationId],
            $fullEndpoint
        );
    }

    /**
     * @return HttpClient
     */
    public function getApi($path, $type = 'payments', $storeId = null): HttpClient
    {
        $uri = $this->helper->getEndpointConfig($type . '_uri');

        if ($this->helper->getGeneralConfig('use_sandbox')) {
            $uri = $this->helper->getEndpointConfig($type . '_uri_sandbox');
        }

        $this->api = new HttpClient(
            $uri . $path,
            $this->getDefaultOptions()
        );

        $this->api->setHeaders($this->getDefaultHeaders($storeId));
        $this->api->setEncType('application/json');

        return $this->api;
    }

    /**
     * @param string $path
     * @param string $method
     * @param array|object $data
     * @param int|null $storeId
     * @return array
     */
    protected function makeRequest(string $path, string $method, $data = [], $storeId = null): array
    {
        $apiType = $this->getApiType();
        $api = $this->getApi($path, $apiType, $storeId);

        $api->setMethod($method);
        if (!empty($data)) {
            $api->setRawBody($this->json->serialize($data));
        }
        $response = $api->send();
        $content = $response->getBody();
        if ($content && $response->getStatusCode() != 204) {
            try {
                $content = $this->json->unserialize($content);
            } catch (\Exception $e) {
                $content = (string) $response->getBody();
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'response' => $content
        ];
    }
}
