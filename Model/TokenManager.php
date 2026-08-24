<?php
/**
 * CHIP recurring-token (Vault) manager for Magento 2
 *
 * Mirrors the CHIP WooCommerce plugin's tokenization flow:
 *   - store_recurring_token(): persist a CHIP recurring token as a Magento
 *     Vault PaymentToken (saved card) when the webhook reports
 *     is_recurring_token / recurring_token.
 *   - chargeWithToken(): create a purchase and charge it with a saved
 *     recurring token (renewal / saved-card checkout).
 *   - deleteToken(): delete the recurring token on the CHIP side.
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Model;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages CHIP recurring tokens stored as Magento Vault payment tokens.
 */
class TokenManager
{
    /**
     * @var PaymentTokenFactoryInterface
     */
    protected $paymentTokenFactory;

    /**
     * @var PaymentTokenManagementInterface
     */
    protected $paymentTokenManagement;

    /**
     * @var PaymentTokenRepositoryInterface
     */
    protected $paymentTokenRepository;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param PaymentTokenFactoryInterface $paymentTokenFactory
     * @param PaymentTokenManagementInterface $paymentTokenManagement
     * @param PaymentTokenRepositoryInterface $paymentTokenRepository
     * @param EncryptorInterface $encryptor
     * @param Api $api
     * @param LoggerInterface $logger
     */
    public function __construct(
        PaymentTokenFactoryInterface $paymentTokenFactory,
        PaymentTokenManagementInterface $paymentTokenManagement,
        PaymentTokenRepositoryInterface $paymentTokenRepository,
        EncryptorInterface $encryptor,
        Api $api,
        LoggerInterface $logger
    ) {
        $this->paymentTokenFactory = $paymentTokenFactory;
        $this->paymentTokenManagement = $paymentTokenManagement;
        $this->paymentTokenRepository = $paymentTokenRepository;
        $this->encryptor = $encryptor;
        $this->api = $api;
        $this->logger = $logger;
    }

    /**
     * Store a CHIP recurring token as a Magento Vault payment token.
     *
     * The CHIP purchase object carries the token in one of two shapes:
     *   - is_recurring_token === true  -> the purchase id IS the token.
     *   - recurring_token is set       -> that value is the token.
     *
     * @param array $paymentData CHIP purchase object (webhook payload).
     * @param OrderPaymentInterface $payment Magento order payment.
     * @return PaymentTokenInterface|null
     */
    public function storeRecurringToken(array $paymentData, OrderPaymentInterface $payment)
    {
        $order = $payment->getOrder();
        if (!$order || !$order->getId()) {
            return null;
        }

        $customerId = $order->getCustomerId();
        if (!$customerId) {
            // Guest checkout cannot save a card (no customer to attach it to).
            return null;
        }

        $tokenValue = $this->resolveTokenValue($paymentData);
        if (empty($tokenValue)) {
            return null;
        }

        // Deduplicate: if this token is already stored for the customer,
        // return the existing one.
        $existing = $this->paymentTokenManagement->getByGatewayToken(
            $tokenValue,
            Chip::CODE,
            $customerId
        );
        if ($existing) {
            return $existing;
        }

        $details = $this->extractCardDetails($paymentData);

        /** @var PaymentTokenInterface $token */
        $token = $this->paymentTokenFactory->create(PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
        $token->setGatewayToken($tokenValue);
        $token->setCustomerId($customerId);
        $token->setPaymentMethodCode(Chip::CODE);
        $token->setType(PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
        $token->setIsActive(true);
        $token->setIsVisible(true);
        $token->setWebsiteId($order->getStore()->getWebsiteId());
        $token->setTokenDetails(json_encode($details));
        $token->setExpiresAt($this->extractExpiry($details));
        $token->setPublicHash($this->generatePublicHash($token));

        try {
            $this->paymentTokenManagement->saveTokenWithPaymentLink($token, $payment);
            return $token;
        } catch (\Exception $e) {
            $this->logger->error(
                'CHIP tokenization: failed to store recurring token: ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Resolve the CHIP recurring token value from a purchase object.
     *
     * @param array $paymentData
     * @return string|null
     */
    protected function resolveTokenValue(array $paymentData)
    {
        if (!empty($paymentData['is_recurring_token'])) {
            return isset($paymentData['id']) ? $paymentData['id'] : null;
        }

        if (!empty($paymentData['recurring_token'])) {
            return $paymentData['recurring_token'];
        }

        return null;
    }

    /**
     * Extract card details from the CHIP purchase object.
     *
     * @param array $paymentData
     * @return array
     */
    protected function extractCardDetails(array $paymentData)
    {
        $extra = array();
        if (isset($paymentData['transaction_data']['extra'])
            && is_array($paymentData['transaction_data']['extra'])
        ) {
            $extra = $paymentData['transaction_data']['extra'];
        }

        $maskedPan = isset($extra['masked_pan']) ? $extra['masked_pan'] : '';
        $last4 = substr($maskedPan, -4);

        return array(
            'cc_last_4' => $last4,
            'cc_exp_month' => isset($extra['expiry_month']) ? $extra['expiry_month'] : '',
            'cc_exp_year' => isset($extra['expiry_year']) ? $extra['expiry_year'] : '',
            'cc_type' => $this->normalizeCardType(
                isset($extra['card_brand']) ? $extra['card_brand'] : ''
            ),
            'masked_pan' => $maskedPan,
            'cardholder_name' => isset($extra['cardholder_name']) ? $extra['cardholder_name'] : '',
        );
    }

    /**
     * Normalize a CHIP card brand to a Magento cc_type code.
     *
     * @param string $brand
     * @return string
     */
    protected function normalizeCardType($brand)
    {
        $map = array(
            'visa' => 'VI',
            'mastercard' => 'MC',
            'maestro' => 'MI',
            'amex' => 'AE',
            'american express' => 'AE',
            'discover' => 'DI',
            'jcb' => 'JCB',
        );

        $key = strtolower(trim($brand));
        return isset($map[$key]) ? $map[$key] : 'OT';
    }

    /**
     * Build an expiry date string from card details.
     *
     * @param array $details
     * @return string|null
     */
    protected function extractExpiry(array $details)
    {
        if (empty($details['cc_exp_year']) || empty($details['cc_exp_month'])) {
            return null;
        }

        $year = $details['cc_exp_year'];
        if (strlen((string) $year) === 2) {
            $year = '20' . $year;
        }

        $month = str_pad((string) $details['cc_exp_month'], 2, '0', STR_PAD_LEFT);

        return $year . '-' . $month . '-01 00:00:00';
    }

    /**
     * Generate the Vault public hash for a token.
     *
     * @param PaymentTokenInterface $token
     * @return string
     */
    protected function generatePublicHash(PaymentTokenInterface $token)
    {
        $hashKey = $token->getGatewayToken()
            . $token->getPaymentMethodCode()
            . $token->getType()
            . $token->getTokenDetails();

        return $this->encryptor->getHash($hashKey);
    }

    /**
     * Delete a recurring token on the CHIP side.
     *
     * @param string $purchaseId
     * @return bool
     */
    public function deleteToken($purchaseId)
    {
        $result = $this->api->deleteRecurringToken($purchaseId);
        return is_array($result);
    }
}
