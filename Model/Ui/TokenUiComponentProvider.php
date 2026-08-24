<?php
/**
 * CHIP Vault token UI component provider for Magento 2
 *
 * Renders saved CHIP cards at checkout as Vault payment methods.
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Model\Ui;

use CHIPAsia\ChipPaymentGateway\Model\Chip;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Model\Ui\TokenUiComponentInterface;
use Magento\Vault\Model\Ui\TokenUiComponentInterfaceFactory;
use Magento\Vault\Model\Ui\TokenUiComponentProviderInterface;

/**
 * Provides the UI component for a saved CHIP card token.
 */
class TokenUiComponentProvider implements TokenUiComponentProviderInterface
{
    /**
     * @var TokenUiComponentInterfaceFactory
     */
    protected $componentFactory;

    /**
     * @param TokenUiComponentInterfaceFactory $componentFactory
     */
    public function __construct(
        TokenUiComponentInterfaceFactory $componentFactory
    ) {
        $this->componentFactory = $componentFactory;
    }

    /**
     * Get UI component for a saved CHIP card token.
     *
     * @param PaymentTokenInterface $paymentToken
     * @return TokenUiComponentInterface
     */
    public function getComponentForToken(PaymentTokenInterface $paymentToken)
    {
        $jsonDetails = json_decode($paymentToken->getTokenDetails() ?: '{}', true);
        if (!is_array($jsonDetails)) {
            $jsonDetails = array();
        }

        $component = $this->componentFactory->create(
            array(
                'config' => array(
                    'code' => Chip::VAULT_CODE,
                    TokenUiComponentProviderInterface::COMPONENT_DETAILS => $jsonDetails,
                    TokenUiComponentProviderInterface::COMPONENT_PUBLIC_HASH => $paymentToken->getPublicHash(),
                ),
                'name' => 'CHIPAsia_ChipPaymentGateway/js/view/payment/method-renderer/vault',
            )
        );

        return $component;
    }
}
