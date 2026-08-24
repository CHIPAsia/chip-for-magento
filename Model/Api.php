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
 * Compatible with Magento 2.0 - 2.4 and PHP 7.0 - 8.5.
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
     * Charge a payment using a saved recurring token.
     *
     * @param string $purchaseId
     * @param array $params
     * @return array|null
     */
    public function chargePayment($purchaseId, $params)
    {
        return $this->call('POST', '/purchases/' . $purchaseId . '/charge/', $params);
    }

    /**
     * Delete a recurring token.
     *
     * @param string $purchaseId
     * @return array|null
     */
    public function deleteRecurringToken($purchaseId)
    {
        return $this->call('POST', '/purchases/' . $purchaseId . '/delete_recurring_token/');
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
     * The gateway returns the key as a JSON-encoded PEM string
     * ("-----BEGIN PUBLIC KEY-----..."). Handle both the raw-body
     * form and a JSON wrapper just in case.
     *
     * @return string|null
     */
    public function getPublicKey()
    {
        $raw = $this->callRaw('GET', '/public_key/');
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['key'])) {
            $key = $decoded['key'];
        } elseif (is_string($decoded)) {
            $key = $decoded;
        } else {
            $key = $raw;
        }

        if (is_string($key) && strpos($key, 'BEGIN PUBLIC KEY') !== false) {
            return trim($key);
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
        $body = $this->callRaw($method, $path, $params);
        if ($body === null) {
            return null;
        }

        $result = json_decode($body, true);
        if (!is_array($result)) {
            $this->logger->error('CHIP API invalid response: ' . $body);
            return null;
        }

        return $result;
    }

    /**
     * Perform an API call and return the raw response body.
     *
     * Resets curl user options after every call because the Curl client is
     * a shared singleton: CURLOPT_POSTFIELDS set for a JSON POST would leak
     * into the next GET request and turn it into a POST (405 on the gateway).
     *
     * @param string $method
     * @param string $path
     * @param array $params
     * @return string|null
     */
    protected function callRaw($method, $path, $params = array())
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
                $this->curl->setOptions(array());
                $this->curl->get($url);
            } else {
                $body = json_encode($params);
                $this->curl->setOption(CURLOPT_POSTFIELDS, $body);
                $this->curl->post($url, array());
                $this->curl->setOptions(array());
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

            return $body;
        } catch (\Exception $e) {
            $this->logger->error('CHIP API exception: ' . $e->getMessage());
            return null;
        }
    }
}
