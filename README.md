# CHIP for Magento

CHIP payment gateway module for Magento 2 (2.0 - 2.4).

## Compatibility

| Magento | PHP | Status |
|---------|-----|--------|
| 2.0.x | 5.5 - 7.0 | Supported |
| 2.1.x | 5.6 - 7.0 | Supported |
| 2.2.x | 7.0 - 7.1 | Supported |
| 2.3.x | 7.1 - 7.4 | Supported |
| 2.4.x | 7.3 - 8.5 | Supported |

One module covers all Magento 2 versions. The Magento Payment Gateway API
(`Magento\Payment\Gateway`, `AbstractMethod`, `Curl`) is stable across
2.0 - 2.4. Version-specific differences handled internally:

- **CSRF**: Magento 2.3+ validates CSRF on frontend POST actions. The webhook
  controller implements `CsrfAwareActionInterface` (same pattern as
  `Magento_Paypal` Ipn) to allow CHIP callbacks. Magento 2.0 - 2.2 has no
  CSRF validation, so the interface is not implemented there.
- **HTTP client**: `Curl::post()` only accepts arrays (form-encoded) on
  2.0 - 2.2. JSON bodies are sent via `Curl::setOption(CURLOPT_POSTFIELDS, ...)`
  which works on all versions.
- **PHP syntax**: no scalar type hints, no return types, no null coalescing
  operator - compatible with PHP 5.6 through 8.5.

## Installation

### Composer (recommended)

```bash
composer require chipasia/chip-payment-gateway
bin/magento module:enable CHIPAsia_ChipPaymentGateway
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

### Manual

Copy the module to `app/code/CHIPAsia/ChipPaymentGateway/`, then run the
same `bin/magento` commands above.

## Configuration

Stores > Configuration > Sales > Payment Methods > CHIP Payment Gateway

| Setting | Description |
|---------|-------------|
| Enabled | Enable/disable the payment method |
| Title | Checkout display title |
| Secret Key | CHIP brand secret key (encrypted at rest) |
| Brand ID | CHIP brand ID |
| Payment Method Whitelist | Restrict to specific methods (FPX, DuitNow QR, etc.). Empty = all available |
| Due Strict | Block payments after the due time has passed |
| Due Strict Timing | Payment expiry in minutes |
| Send Receipt | Send receipt email from CHIP |
| Debug | Log API requests to `var/log/chip.log` |

## How it works

1. Customer selects CHIP at checkout and places the order.
2. Order is created with `pending_payment` status.
3. Customer is redirected to `chip/payment/redirect` which creates a CHIP
   purchase and redirects to the CHIP payment page.
4. CHIP sends a webhook to `chip/payment/callback` (verified with
   `X-Signature` + public key).
5. On success the order is set to `processing`; on failure/cancel it is
   cancelled. Customer is redirected back to the success/failure page.

## Webhook URL

```
https://your-store.com/chip/payment/callback
```

Return URL (customer lands here after paying): `https://your-store.com/chip/payment/returnaction`

Register the callback URL in the CHIP dashboard. The callback verifies the
`X-Signature` header against the CHIP public key before updating the order.

## Refunds

Refunds are processed from the Magento admin (Sales > Orders > Invoice >
Credit Memo). The module calls `POST /purchases/{id}/refund/` with the
refund amount.

## Development

```bash
# PHP syntax check (all files)
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;

# PHPCS (Magento coding standard)
vendor/bin/phpcs --standard=Magento2 app/code/CHIPAsia/ChipPaymentGateway
```

## Live Testing

Tested on a live Magento 2.4.8-p5 instance (PHP 8.2) deployed on Dokploy
against the CHIP production API:

| Scenario | Result |
|----------|--------|
| GET /payment_methods/ | 200, all methods listed |
| POST /purchases/ (create purchase) | 201, checkout_url returned |
| GET /purchases/{id}/ after POST | 200 (Curl singleton POSTFIELDS leak fixed) |
| GET /public_key/ | returns JSON-encoded PEM (parsed correctly) |
| Webhook without signature | 401 |
| Webhook with bad signature | 401 |
| Webhook with invalid JSON | 400 |
| Order paid | pending_payment → processing |
| Order refunded | → closed |
| Order cancelled/expired | → canceled |
| Concurrent webhooks (same order) | serialized via GET_LOCK, no double-processing |
| Signature verify (valid/tampered/empty) | true / false / false |

### Known Magento CLI quirk

`bin/magento config:set` and `cache:*` commands may report "no commands
defined" on a fresh install until `setup:di:compile` has been run
successfully (the compile is what registers the command classes).

## License

OSL-3.0
