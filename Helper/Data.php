<?php

/**
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Koin
 * @package     Koin_Payment
 *
 */

namespace Koin\Payment\Helper;

use Koin\Payment\Logger\Logger;
use Koin\Payment\Api\RequestRepositoryInterface;
use Koin\Payment\Model\RequestFactory;
use Laminas\Http\Client as HttpClient;
use Laminas\Http\Request;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Directory\Helper\Data as DirectoryData;
use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\LayoutFactory;
use Magento\Payment\Model\Config;
use Magento\Payment\Model\Method\Factory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Customer\Model\Session as CustomerSession;
use Psr\Log\LoggerInterface;

/**
 * Class Data
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Data extends \Magento\Payment\Helper\Data
{
    public const ROUND_FACTOR = 100;
    public const DEFAULT_MERCHANT_NAME = 'adobe';

    /**
     * Forwarded client-IP headers in priority order ($_SERVER keys), checked before REMOTE_ADDR.
     */
    private const CLIENT_IP_HEADERS = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_TRUE_CLIENT_IP',   // Cloudflare Enterprise / Akamai
        'HTTP_X_FORWARDED_FOR',  // Generic proxy / WAF chain
    ];

    public const DEFAULT_DELIVERY_TYPE = 'NORMAL';
    public const FINGERPRINT_URL = 'https://securegtm.despegar.com/risk/fingerprint/statics/track-min.js';

    public const DEFAULT_DELIVERY_DAYS = 10;

    public const DEFAULT_CURRENCY = 'BRL';

    public const REQUEST_SALT = 'koin_request';

    public const PLACE_ORDER_LOCK_PREFIX = 'PLACE_ORDER_';

    public const CAPTUE_ORDER_LOCK_PREFIX = 'CAPTURE_ORDER_';

    public const LOCK_TIMEOUT = 15;

    /** @var ResourceConnection */
    protected $resourceConnection;

    /** @var \Koin\Payment\Logger\Logger */
    protected $logger;

    /** @var OrderInterface  */
    protected $order;

    /** @var RequestRepositoryInterface  */
    protected $requestRepository;

    /** @var RequestFactory  */
    protected $requestFactory;

    /** @var WriterInterface */
    private $configWriter;

    /** @var Json */
    private $json;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var RemoteAddress */
    private $remoteAddress;

    /** @var CategoryRepositoryInterface  */
    protected $categoryRepository;

    /** @var CustomerSession  */
    protected $customerSession;

    /**
     * @var DirectoryData
     */
    protected $helperDirectory;

    /**
     * @var ComponentRegistrar
     */
    protected $componentRegistrar;

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var File
     */
    protected $file;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var LockManagerInterface
     */
    protected $lockManager;

    public function __construct(
        Context $context,
        LayoutFactory $layoutFactory,
        Factory $paymentMethodFactory,
        Emulation $appEmulation,
        Config $paymentConfig,
        Initial $initialConfig,
        ResourceConnection $resourceConnection,
        Logger $logger,
        WriterInterface $configWriter,
        Json $json,
        StoreManagerInterface $storeManager,
        RemoteAddress $remoteAddress,
        CustomerSession $customerSession,
        CategoryRepositoryInterface $categoryRepository,
        RequestRepositoryInterface $requestRepository,
        RequestFactory $requestFactory,
        OrderInterface $order,
        ComponentRegistrar $componentRegistrar,
        DateTime $dateTime,
        DirectoryData $helperDirectory,
        File $file,
        HttpClient $httpClient,
        LockManagerInterface $lockManager
    ) {
        parent::__construct($context, $layoutFactory, $paymentMethodFactory, $appEmulation, $paymentConfig, $initialConfig);

        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
        $this->configWriter = $configWriter;
        $this->json = $json;
        $this->storeManager = $storeManager;
        $this->remoteAddress = $remoteAddress;
        $this->customerSession = $customerSession;
        $this->categoryRepository = $categoryRepository;
        $this->requestRepository = $requestRepository;
        $this->requestFactory = $requestFactory;
        $this->order = $order;
        $this->componentRegistrar = $componentRegistrar;
        $this->dateTime = $dateTime;
        $this->helperDirectory = $helperDirectory;
        $this->file = $file;
        $this->httpClient = $httpClient;
        $this->lockManager = $lockManager;
    }

    public function getAllowedMethods(): array
    {
        return [
            \Koin\Payment\Model\Ui\Redirect\ConfigProvider::CODE,
            \Koin\Payment\Model\Ui\Pix\ConfigProvider::CODE,
            \Koin\Payment\Model\Ui\CreditCard\ConfigProvider::CODE
        ];
    }

    public function getFinalStates(): array
    {
        return [
            Order::STATE_CANCELED,
            Order::STATE_CLOSED,
            Order::STATE_COMPLETE
        ];
    }

    /**
     * Log custom message using Koin logger instance
     *
     * @param $message
     * @param string $name
     * @param void
     */
    public function log($message, $name = 'koin'): void
    {
        if ($this->getGeneralConfig('debug')) {
            if (!is_string($message)) {
                $message = $this->json->serialize($message);
            }

            $this->logger->setName($name);
            $this->logger->debug($this->mask($message));
        }
    }

    /**
     * @param string $message
     * @return string
     */
    public function mask(string $message): string
    {
        $message = preg_replace('/"secure_token":\s?"([^"]+)"/', '"secure_token":"*********"', $message);
        $message = preg_replace('/"security_code":\s?"([^"]+)"/', '"security_code":"***"', $message);
        $message = preg_replace('/"expiration_month":\s?"([^"]+)"/', '"expiration_month":"**"', $message);
        $message = preg_replace('/"expiration_year":\s?"([^"]+)"/', '"expiration_year":"****"', $message);
        $message = preg_replace('/(hash=)[^&"]+/', 'hash=******', $message);
        return preg_replace('/"number":\s?"(\d{6})\d{3,9}(\d{4})"/', '"number":"$1******$2"', $message);
    }

    /**
     * @param $message
     * @return string
     */
    public function jsonEncode($message): string
    {
        try {
            return $this->json->serialize($message);
        } catch (\Exception $e) {
            $this->log($e->getMessage());
        }
        return (string) $message;
    }

    /**
     * @param string $message
     * @return array
     */
    public function jsonDecode(string $message): array
    {
        try {
            return $this->json->unserialize($message);
        } catch (\Exception $e) {
            $this->log($e->getMessage());
        }
        return [];
    }

    public function getAccountNumber(Order $order): string
    {
        $ruleAccountNumber = (string) $order->getPayment()->getAdditionalInformation('rule_account_number');
        if (!$ruleAccountNumber) {
            $ruleAccountNumber = (string) $this->customerSession->getData('rule_account_number');
        }
        return $ruleAccountNumber ?: $this->getGeneralConfig('account_number', $order->getStoreId());
    }

    /**
    * @param $request
    * @param $response
    * @param $statusCode
    * @param $method
    * @return void
     */
    public function saveRequest($request, $response, $statusCode, string $method = 'koin'): void
    {
        if ($this->getGeneralConfig('debug')) {
            try {
                if (!is_string($request)) {
                    $request = $this->json->serialize($request);
                }
                if (!is_string($response)) {
                    $response = $this->json->serialize($response);
                }
                $request = $this->mask($request);
                $response = $this->mask($response);

                $requestModel = $this->requestFactory->create();
                $requestModel->setRequest($request);
                $requestModel->setResponse($response);
                $requestModel->setMethod($method);
                $requestModel->setStatusCode($statusCode);
                $this->requestRepository->save($requestModel);

            } catch (\Exception $e) {
                $this->log($e->getMessage());
            }
        }
    }

    public function saveRequestAsync($request, $response, $statusCode, string $method = 'koin'): void
    {
        if ($this->getGeneralConfig('debug')) {
            try {
                $url = $this->getUrl(
                    'koin/request/save',
                    ['hash' => sha1($this->getHash(0) . self::REQUEST_SALT)]
                );
                $this->log($url);
                $client = new HttpClient();
                $client->setUri($url);
                $client->setMethod(Request::METHOD_POST);
                $client->setRawBody($this->json->serialize([
                    'method' => $method,
                    'request' => $request,
                    'response' => $response,
                    'status_code' => $statusCode
                ]));
                $client->setEncType('application/json');
                $client->send();
            } catch (\Exception $e) {
                $this->log($e->getMessage());
            }
        }
    }

    public function getConfig(
        string $config,
        string $group = 'koin_redirect',
        string $section = 'payment',
        $scopeCode = null
    ): string {
        return (string) $this->scopeConfig->getValue(
            $section . '/' . $group . '/' . $config,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
    }

    public function getCcConfig(string $config, $scopeCode = null): string
    {
        return $this->getConfig($config, 'koin_cc', 'payment', $scopeCode);
    }

    public function getAntifraudConfig(string $config): string
    {
        return $this->getConfig($config, 'fraud_analysis', 'koin');
    }

    public function saveConfig(
        string $value,
        string $config,
        string $group = 'general',
        string $section = 'koin'
    ): void {
        $this->configWriter->save(
            $section . '/' . $group . '/' . $config,
            $value
        );
    }

    public function getGeneralConfig(string $config, $scopeCode = null): string
    {
        return $this->getConfig($config, 'general', 'koin', $scopeCode);
    }

    public function getPaymentsConfig(string $config, $scopeCode = null): string
    {
        return $this->getConfig($config, 'payments', 'koin', $scopeCode);
    }

    public function getPaymentsNotificationUrl(Order $order): string
    {
        $storeId = $order->getStoreId() ?: $this->storeManager->getDefaultStoreView()->getId();
        return $this->getUrl(
            'koin/callback/payments',
            [
                '_type' => UrlInterface::URL_TYPE_LINK,
                '_scope' => $storeId,
                '_query' => ['hash' => $this->getHash(0)],
                '_secure' => true
            ]
        );
    }

    public function getHash($scopeCode = null): string
    {
        return sha1($this->getGeneralConfig('private_key', $scopeCode));
    }

    public function getAntifraudCallbackUrl(Order $order): string
    {
        $storeId = $order->getStoreId() ?: $this->storeManager->getDefaultStoreView()->getId();
        return $this->getUrl(
            'koin/callback/risk',
            [
                '_type' => UrlInterface::URL_TYPE_LINK,
                '_scope' => $storeId,
                '_query' => ['hash' => $this->getHash(0)],
                '_secure' => true
            ]
        );
    }

    public function getReturnUrl(string $incrementId): string
    {
        return $this->_urlBuilder->getRouteUrl(
            'koin/success/',
            [
                '_query' => [
                    'hash' => $this->getHash(0),
                    'increment_id' => $incrementId
                ],
                '_secure' => true
            ]
        );
    }

    public function getEndpointConfig(string $config, $scopeCode = null): string
    {
        return $this->getConfig($config, 'endpoints', 'koin', $scopeCode);
    }

    public function getStoreCurrencyCode(): string
    {
        try {
            return $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (\Exception $e) {
            $this->log($e->getMessage());
        }
        return $this->getDefaultCurrency();
    }

    /**
     * Public method to allow plugins if necessary
     */
    public function getDefaultCurrency(): string
    {
        return self::DEFAULT_CURRENCY;
    }

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getMediaUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
    }

    public function getCurrentIpAddress(): string
    {
        $request = $this->_getRequest();

        foreach (self::CLIENT_IP_HEADERS as $serverKey) {
            $headerValue = (string) $request->getServer($serverKey, '');
            if ($headerValue === '') {
                continue;
            }

            // X-Forwarded-For can be a comma-separated chain; the left-most entry is the client.
            foreach (explode(',', $headerValue) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return (string) $this->remoteAddress->getRemoteAddress();
    }

    public function getStoreName(): string
    {
        return $this->getConfig('name', 'store_information', 'general');
    }

    public function getDefaultCountryCode(): string
    {
        return (string) $this->helperDirectory->getDefaultCountry();
    }

    public function getCategoryName(int $categoryId): string
    {
        $categoryName = '';
        try {
            if ($categoryId) {
                $category = $this->categoryRepository->get($categoryId);
                if ($category->getId()) {
                    $categoryName = $category->getName();
                }
            }
        } catch (\Exception $e) {
            $this->log($e->getMessage());
        }
        return $categoryName;
    }

    public function getUrl(string $route, array $params = []): string
    {
        return $this->_getUrl($route, $params);
    }

    public function getLogger(): LoggerInterface
    {
        return $this->_logger;
    }

    public function digits(string $string): string
    {
        return preg_replace('/\D/', '', (string) $string);
    }

    public function isCompanyCustomer(): bool
    {
        $customer = $this->customerSession->getCustomer();
        $customerTypeAttr = $this->getConfig('customer_type_attribute', 'customer', 'koin');
        if ($customer->getId() && $customerTypeAttr) {
            $customerCompanyTypeValue = $this->getConfig(
                'customer_type_company_value',
                'customer',
                'koin'
            );
            if ($customerCompanyTypeValue) {
                $customerType = $customer->getData($customerTypeAttr);
                if ($customerType && $customerType == $customerCompanyTypeValue) {
                    return true;
                }
            }
        }

        return false;
    }

    public function formatPhoneNumber(string $phoneNumber): string
    {
        return $this->clearNumber($phoneNumber);
    }

    public function clearNumber(string $string): string
    {
        return preg_replace('/\D/', '', (string) $string);
    }

    /**
     * @param string $incrementId
     * @return \Magento\Sales\Model\Order|OrderInterface
     */
    public function loadOrder(string $incrementId): OrderInterface
    {
        return $this->order->loadByIncrementId($incrementId);
    }

    public function getModuleVersion(): string
    {
        $modulePath = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'Koin_Payment');
        $composerJsonPath = $modulePath . '/composer.json';

        if ($this->file->fileExists($composerJsonPath)) {
            $composerJsonContent = $this->file->read($composerJsonPath);
            $composerData = json_decode($composerJsonContent, true);

            if (isset($composerData['version'])) {
                return $composerData['version'];
            }
        }

        return '*.*.*';
    }

    public function validateCpf(string $taxVat): bool
    {
        try {
            $cpf = preg_replace('/[^0-9]/', '', $taxVat);
            if (strlen($cpf) != 11) {
                return false;
            }

            if (preg_match('/^(\d)\1*$/', $cpf)) {
                return false;
            }

            $soma = 0;
            for ($i = 0; $i < 9; $i++) {
                $soma += intval($cpf[$i]) * (10 - $i);
            }
            $resto = $soma % 11;
            $digito1 = ($resto < 2) ? 0 : 11 - $resto;

            $soma = 0;
            for ($i = 0; $i < 9; $i++) {
                $soma += intval($cpf[$i]) * (11 - $i);
            }
            $soma += $digito1 * 2;
            $resto = $soma % 11;
            $digito2 = ($resto < 2) ? 0 : 11 - $resto;

            if ($cpf[9] != $digito1 || $cpf[10] != $digito2) {
                return false;
            }
        } catch (\Exception $e) {
            $this->log($e->getMessage());
            return false;
        }

        return true;
    }

    public function validateCnpj(string $vatId): bool
    {
        try {
            $cnpj = preg_replace('/[^0-9]/', '', $vatId);
            if (strlen($cnpj) != 14) {
                return false;
            }

            if (preg_match('/^(\d)\1*$/', $cnpj)) {
                return false;
            }

            $soma = 0;
            for ($i = 0, $j = 5; $i < 12; $i++) {
                $soma += intval($cnpj[$i]) * $j;
                $j = ($j === 2) ? 9 : $j - 1;
            }
            $resto = $soma % 11;
            $digito1 = ($resto < 2) ? 0 : 11 - $resto;

            $soma = 0;
            for ($i = 0, $j = 6; $i < 13; $i++) {
                $soma += intval($cnpj[$i]) * $j;
                $j = ($j === 2) ? 9 : $j - 1;
            }
            $resto = $soma % 11;
            $digito2 = ($resto < 2) ? 0 : 11 - $resto;

            if ($cnpj[12] != $digito1 || $cnpj[13] != $digito2) {
                return false;
            }

        } catch (\Exception $e) {
            $this->log($e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * This method is public to allow plugins and to add a custom implementation, Magento usually doesn't have delivery date as default
     */
    public function getDeliveryDate(Order $order): string
    {
        $expirationDays = self::DEFAULT_DELIVERY_DAYS;
        $days = "+{$expirationDays} days";
        $timeStamp = $this->dateTime->timestamp($days);
        return $this->dateTime->gmtDate('Y-m-d\TH:i:s', $timeStamp) . '.000Z';
    }

    public function getOrderCurrencyCode(Order $order): string
    {
        $currencyCode = $order->getGlobalCurrencyCode();
        try {
            $currencyCode = $order->getOrderCurrency()->getCode();
        } catch (\Exception $e) {
            $this->log($e->getMessage());
        }

        return (string) $currencyCode;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderAddressInterface $address
     * @return array
     */
    public function getAddressData($address): array
    {
        $fullStreet = $address->getStreet();
        $street = $this->getConfig('street', 'address', 'koin');
        $streetNumber = $this->getConfig('number', 'address', 'koin');
        $neighborhood = $this->getConfig('district', 'address', 'koin');
        $complement = $this->getConfig('complement', 'address', 'koin');

        return [
            'zip_code' => $address->getPostcode(),
            'street' => $fullStreet[$street] ?? 'N/A',
            'number' => $fullStreet[$streetNumber] ?? 'N/A',
            'complement' => $fullStreet[$complement] ?? 'N/A',
            'neighborhood' => $fullStreet[$neighborhood] ?? 'N/A',
            'city' => $address->getCity(),
            'state' => $address->getRegionCode() ?: $address->getRegion(),
            'country_code' => $address->getCountryId(),
        ];
    }

    /**
     * @return string
     */
    public function getExpirationDate(int $time = 1440): string
    {
        $expirationTime = (int) $time;
        $expirationTime = $expirationTime > 0 ? $expirationTime : \Koin\Payment\Helper\Order::DEFAULT_EXPIRATION_TIME;
        $minutes = "+{$expirationTime} minutes";
        $timeStamp = $this->dateTime->timestamp($minutes);
        return (string) $this->dateTime->gmtDate('Y-m-d\TH:i:s', $timeStamp) . '.000Z';
    }
}
