<?php
/**
 * CHIP webhook signature verifier for Magento 2
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies CHIP webhook signatures using the gateway public key.
 */
class SignatureVerifier
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $cachedPublicKey;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Api $api
     * @param EncryptorInterface $encryptor
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Api $api,
        EncryptorInterface $encryptor,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->api = $api;
        $this->encryptor = $encryptor;
        $this->logger = $logger;
    }

    /**
     * Verify the X-Signature header against the raw request body.
     *
     * @param string $content Raw request body.
     * @param string $signature Base64-encoded signature from X-Signature header.
     * @return bool
     */
    public function verify($content, $signature)
    {
        if (empty($content) || empty($signature)) {
            return false;
        }

        $publicKey = $this->getPublicKey();
        if (empty($publicKey)) {
            return false;
        }

        $decoded = base64_decode($signature);
        if ($decoded === false) {
            return false;
        }

        $result = openssl_verify(
            $content,
            $decoded,
            $publicKey,
            'sha256WithRSAEncryption'
        );

        return $result === 1;
    }

    /**
     * Get the CHIP public key (cached per request).
     *
     * @return string|null
     */
    protected function getPublicKey()
    {
        if ($this->cachedPublicKey !== null) {
            return $this->cachedPublicKey;
        }

        $secretKey = $this->scopeConfig->getValue(
            'payment/chip/secret_key',
            ScopeInterface::SCOPE_STORE
        );
        $brandId = $this->scopeConfig->getValue(
            'payment/chip/brand_id',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($secretKey) || empty($brandId)) {
            return null;
        }

        if (preg_match('/^[0-9]+:[0-9]+:/', $secretKey)) {
            $decrypted = $this->encryptor->decrypt($secretKey);
            if ($decrypted !== '') {
                $secretKey = $decrypted;
            }
        }

        $this->api->setCredentials($secretKey, $brandId);
        $this->cachedPublicKey = $this->api->getPublicKey();

        return $this->cachedPublicKey;
    }
}
