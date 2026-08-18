<?php
/**
 * CHIP API client for Magento 2
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * CHIP API client.
 *
 * Handles all communication with the CHIP payment gateway.
 * Compatible with Magento 2.0 - 2.4 and PHP 5.6 - 8.4.
 */
class Api
{
    const API_BASE_URL = 'https://gate.chip-in.asia/api/v1';

    const PLATFORM = 'magento';

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $secretKey;

    /**
     * @var string
     */
    protected $brandId;

    /**
     * @var bool
     */
    protected $debug;

    /**
     * @param Curl $curl
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->curl = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->debug = (bool) $this->scopeConfig->getValue(
            'payment/chip/debug',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Set API credentials.
     *
     * @param string $secretKey
     * @param string $brandId
     * @return $this
     */
    public function setCredentials($secretKey, $brandId)
    {
        $this->secretKey = $secretKey;
        $this->brandId = $brandId;
        return $this;
    }

    /**
     * Create a payment (purchase).
     *
     * @param array $params
     * @return array|null
     */
    public function createPayment($params)
    {
        return $this->call('POST', '/purchases/?time=' . time(), $params);
    }

    /**
     * Get payment status.
     *
     * @param string $purchaseId
     * @return array|null
     */
    public function getPayment($purchaseId)
    {
        return $this->call('GET', '/purchases/' . $purchaseId . '/?time=' . time());
    }

    /**
     * Refund a payment.
     *
     * @param string $purchaseId
     * @param array $params
     * @return array|null
     */
    public function refundPayment($purchaseId, $params)
    {
        return $this->call('POST', '/purchases/' . $purchaseId . '/refund/', $params);
    }

    /**
     * Capture a payment.
     *
     * @param string $purchaseId
     * @param array $params
     * @return array|null
     */
    public function capturePayment($purchaseId, $params = array())
    {
        return $this->call('POST', '/purchases/' . $purchaseId . '/capture/', $params);
    }

    /**
     * Cancel a payment.
     *
     * @param string $purchaseId
     * @return array|null
     */
    public function cancelPayment($purchaseId)
    {
        return $this->call('POST', '/purchases/' . $purchaseId . '/cancel/');
    }

    /**
     * Get public key for webhook signature verification.
     *
     * @return string|null
     */
    public function getPublicKey()
    {
        $result = $this->call('GET', '/public_key/');
        if (is_array($result) && isset($result['key'])) {
            return $result['key'];
        }
        return null;
    }

    /**
     * Get available payment methods.
     *
     * @param string $currency
     * @param string $language
     * @param int $amount
     * @return array|null
     */
    public function getPaymentMethods($currency, $language, $amount)
    {
        $url = '/payment_methods/?brand_id=' . $this->brandId
            . '&currency=' . $currency
            . '&language=' . $language
            . '&amount=' . $amount;

        return $this->call('GET', $url);
    }

    /**
     * Perform an API call.
     *
     * @param string $method
     * @param string $path
     * @param array $params
     * @return array|null
     */
    protected function call($method, $path, $params = array())
    {
        $url = self::API_BASE_URL . $path;

        try {
            $this->curl->setTimeout(30);
            $this->curl->setHeaders(array(
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ));

            if ($method === 'GET') {
                $this->curl->get($url);
            } else {
                $body = json_encode($params);
                $this->curl->setOption(CURLOPT_POSTFIELDS, $body);
                $this->curl->post($url, array());
            }

            $status = $this->curl->getStatus();
            $body = $this->curl->getBody();

            if ($this->debug) {
                $this->logger->debug(
                    'CHIP API ' . $method . ' ' . $path . ' status=' . $status
                );
            }

            if ($status < 200 || $status >= 300) {
                $this->logger->error(
                    'CHIP API error: ' . $method . ' ' . $path . ' status=' . $status . ' body=' . $body
                );
                return null;
            }

            $result = json_decode($body, true);
            if (!is_array($result)) {
                $this->logger->error('CHIP API invalid response: ' . $body);
                return null;
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('CHIP API exception: ' . $e->getMessage());
            return null;
        }
    }
}
