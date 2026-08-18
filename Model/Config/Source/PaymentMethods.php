<?php
/**
 * CHIP payment method config source for Magento 2
 *
 * @category  Payment
 * @package   CHIPAsia_ChipPaymentGateway
 * @author    CHIPAsia
 * @copyright Copyright (c) 2026 CHIPAsia
 * @license   OSL-3.0
 */

namespace CHIPAsia\ChipPaymentGateway\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;
use CHIPAsia\ChipPaymentGateway\Model\Chip;

/**
 * Payment method whitelist options.
 */
class PaymentMethods implements ArrayInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        $options = array();

        foreach (Chip::PAYMENT_METHODS as $value => $label) {
            $options[] = array(
                'value' => $value,
                'label' => $label,
            );
        }

        return $options;
    }
}
