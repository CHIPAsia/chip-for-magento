# Changelog

All notable changes to the CHIP for Magento module are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-08-24

### Added
- Magento Vault tokenization (saved cards): store CHIP recurring tokens as
  Vault `PaymentToken`s, one-click checkout via **Stored Cards (CHIP)**.
- `Chip::chargeWithToken()` for renewal / saved-card charging
  (`POST /purchases/{id}/charge/`).
- `Api::chargePayment()` and `Api::deleteRecurringToken()`.
- `enable_tokenization` admin config (drives `force_recurring`).

### Fixed
- CHIP payment method not rendering at checkout (renderer registration via
  `rendererList.push()`, correct template path, missing Place Order button).
- `getModuleVersion()` no longer triggers Composer (crashed when the web
  process could not read `~/.composer/config.json`).
- Handle `error` / `rejected` / `canceled` terminal statuses in `OrderUpdater`
  (CHIP returns `error` for failed test payments, not `failed`).

### Security
- Redact `cardholder_name` and `masked_pan` (PII) from API error/debug logs.

## [1.0.0] - 2026-08-19

### Added
- Initial release: CHIP payment gateway for Magento 2.0 - 2.4.
- Redirect-based checkout, webhook callback with `X-Signature` verification,
  online refunds, pending-order reconciliation cron.
