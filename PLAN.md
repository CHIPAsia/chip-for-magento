# CHIP for Magento — Implementation Plan

> **Audience:** Future AI agents (or human developers) who will be implementing this Magento 2 payment gateway module for [chip-in.asia](https://chip-in.asia). This document is the single source of truth. Read it fully before writing any code.

---

## 1. What this repo is

This repository contains the **official Magento 2 payment gateway module for chip-in.asia** — a payment gateway company based in Malaysia that aggregates local payment methods (DuitNow QR, FPX, MPGS cards, Atome, ShopeePay, GrabPay, TouchNGo, Maybank QR, crypto, Google Pay, Apple Pay) into a single integration for merchants.

**Brand name for the module:** "CHIP for Magento" (matches the existing `CHIPAsia/chip-for-magento` repo name).

**Composer package name (proposed):** `chipin/chip-for-magento`

---

## 2. Is Magento still active? (2026 context)

**Yes, but in maintenance mode — not growth mode.** This matters because it shapes how we build.

- **Current version line: Magento 2.4.x** (latest confirmed stable in our training data: 2.4.7 + patch releases).
- The free version is called **"Magento Open Source"**; the paid version is **"Adobe Commerce"**. Both share the same code base, same APIs, same module system. **One module targets both.**
- Adobe has shifted focus to composable services (App Builder, API Mesh) rather than core platform rewrites.
- Magento is no longer the #1 new-ecommerce choice (Shopify leads SMB), but it has a sticky, high-value merchant base (B2B, mid-market). Malaysian merchants still adopt Magento, especially for B2B and higher GMV stores.
- **Practical implication:** Build for 2.4.x. Don't try to support 2.3.x. Don't wait for a "Magento 3" — the platform is stable and merchants are still upgrading to 2.4.

**Sources to verify current state (do this before major work):**
- https://github.com/magento/magento2/releases — latest release tags and dates
- https://business.adobe.com/products/magento — official Adobe Commerce news
- https://developer.adobe.com/commerce/php/ — current DevDocs

---

## 3. Repository strategy

**Decision: One repo, one code base, multiple long-lived branches.**

This is what Stripe, Adyen, Razorpay, PayPal, and Braintree all do. We will not use "one repo per Magento version" — that's the wrong axis. We will use **branches** to manage the (small) version differences.

### Branch strategy

| Branch | Purpose | Supported Magento | Supported PHP |
|---|---|---|---|
| `main` | Active development against the latest stable Magento | 2.4.6+ | 8.1, 8.2, 8.3 |
| `release/2.4.x` | Stable release branch (tag from `main`) | 2.4.6+ | 8.1, 8.2, 8.3 |
| `legacy/2.3.x` *(only if demand exists)* | LTS backport for merchants on Magento 2.3 | 2.3.x | 7.4, 8.0, 8.1 |

**Do NOT create** a separate repo per Magento version. That's an anti-pattern that fragments CI, releases, security patches, and contributor knowledge.

### Composer constraints

Use **major version constraints** that are forward-compatible across Magento 2.4 patch releases:

```json
{
  "name": "chipin/chip-for-magento",
  "description": "CHIP payment gateway integration for Magento 2",
  "type": "magento2-module",
  "license": "proprietary",
  "version": "1.0.0",
  "require": {
    "php": "~8.1.0||~8.2.0||~8.3.0",
    "magento/framework": "^103.0",
    "magento/module-sales": "^103.0",
    "magento/module-payment": "^103.0",
    "magento/module-checkout": "^100.0",
    "magento/module-customer": "^103.0",
    "magento/module-quote": "^101.0",
    "magento/module-store": "^101.0",
    "magento/module-config": "^101.0",
    "magento/module-directory": "^100.0"
  },
  "autoload": {
    "files": [
      "registration.php"
    ],
    "psr-4": {
      "Chipin\\Payment\\": ""
    }
  }
}
```

The `^103.0` constraint covers Magento 2.4.x patches without forcing a major version bump for every minor Magento release.

---

## 4. Code structure

### Final directory layout (target end-state for MVP)

```
chip-for-magento/
├── composer.json
├── registration.php
├── README.md
├── LICENSE
├── CHANGELOG.md
├── PLAN.md                          ← this file
├── CONTRIBUTING.md
├── .gitignore
├── .github/
│   └── workflows/
│       └── ci.yml                   ← PHP syntax + Magento coding standard checks
├── src/                             ← (we may keep code at root instead — see note)
└── (or at root, no src/)
    ├── Block/
    │   └── Payment/
    │       └── Info.php             ← admin order view: shows CHIP transaction details
    ├── Controller/
    │   ├── Payment/
    │   │   └── Redirect.php         ← controller that redirects to CHIP hosted page
    │   └── Callback/
    │       ├── Success.php          ← user returns from CHIP → mark order paid
    │       ├── Cancel.php           ← user cancels at CHIP
    │       └── Notify.php           ← IPN/webhook endpoint for async notifications
    ├── Cron/
    │   └── PendingOrderChecker.php  ← polls for orders stuck in pending_payment
    ├── Gateway/
    │   ├── Command/
    │   │   ├── InitializeCommand.php
    │   │   ├── CaptureCommand.php
    │   │   ├── RefundCommand.php
    │   │   └── VoidCommand.php
    │   ├── Request/
    │   │   ├── CreatePurchaseRequest.php
    │   │   ├── RefundRequest.php
    │   │   └── CaptureRequest.php
    │   ├── Response/
    │   │   ├── TxnIdHandler.php
    │   │   └── FraudHandler.php
    │   ├── Validator/
    │   │   ├── CurrencyValidator.php
    │   │   └── CountryValidator.php
    │   ├── Http/
    │   │   └── Client/
    │   │       └── ChipClient.php   ← HTTP client wrapping CHIP REST API
    │   └── Config/
    │       └── Config.php
    ├── Model/
    │   ├── Payment/
    │   │   ├── AbstractMethod.php  ← base class for all CHIP payment methods
    │   │   ├── DuitNowQr.php
    │   │   ├── Fpx.php
    │   │   ├── Card.php            ← MPGS-backed cards
    │   │   ├── Atome.php
    │   │   ├── ShopeePay.php
    │   │   ├── GrabPay.php
    │   │   ├── TouchNgo.php
    │   │   ├── MaybankQr.php
    │   │   ├── Crypto.php
    │   │   ├── GooglePay.php
    │   │   ├── ApplePay.php
    │   │   └── (reserved for future payment methods)
    │   ├── Adminhtml/
    │   │   └── Source/
    │   │       └── PaymentAction.php
    │   ├── ConfigProvider.php       ← pushes payment method data to checkout JS
    │   ├── MethodList.php           ← filters methods by currency/country/cart
    │   └── Webhook/
    │       └── Handler.php          ← business logic for incoming webhooks
    ├── Observer/
    │   └── DataAssignObserver.php   ← standard Magento payment observer
    ├── etc/
    │   ├── module.xml
    │   ├── config.xml               ← default config values
    │   ├── payment.xml              ← declares payment methods
    │   ├── di.xml                   ← main DI wiring
    │   ├── frontend/
    │   │   └── di.xml               ← checkout DI overrides
    │   ├── adminhtml/
    │   │   ├── di.xml
    │   │   └── system.xml           ← admin config UI
    │   ├── crontab.xml              ← registers the pending-order cron
    │   └── webhook.xml              ← optional: route for webhook security
    └── view/
        ├── frontend/
        │   ├── layout/
        │   │   ├── checkout_index_index.xml
        │   │   └── chipin_payment_redirect.xml
        │   ├── templates/
        │   │   └── payment/
        │   │       ├── redirect.phtml
        │   │       └── info/
        │   │           └── default.phtml
        │   └── web/
        │       ├── js/
        │       │   └── view/
        │       │       └── payment/
        │       │           └── method-renderer.js
        │       └── template/
        │           └── payment/
        │               └── chipin.html
        └── adminhtml/
            └── templates/
                └── info/
                    └── default.phtml
```

**Note on `src/` vs root:** The official Magento module skeleton places code at root. We'll follow that convention (no `src/` wrapper). Adjust the `psr-4` autoload accordingly.

### Module namespace

**`Chipin\Payment\`** for the base namespace. Sub-namespaces for sub-modules if needed (e.g. `Chipin\Payment\Gateway\Command`).

### Vendor name in `etc/module.xml`

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="Chipin_Payment" setup_version="1.0.0">
        <sequence>
            <module name="Magento_Sales"/>
            <module name="Magento_Payment"/>
            <module name="Magento_Checkout"/>
            <module name="Magento_Directory"/>
        </sequence>
    </module>
</config>
```

---

## 5. Integration architecture

### 5.1 Payment flow (redirect-based — used for ALL methods in MVP)

```
┌─────────────┐     1. checkout    ┌──────────┐
│   Customer  │ ─────────────────► │ Magento  │
│  (browser)  │ ◄───────────────── │ checkout │
└─────┬───────┘  2. order created  └─────┬────┘
      │                                  │
      │ 3. redirect to                   │
      │    CHIP hosted page              │
      │                                  ▼
      │                            ┌──────────┐
      │                            │  Order   │
      │                            │ (pending)│
      │                            └─────┬────┘
      │                                  │
      │           ┌──────────────────────┘
      │           │ 4. POST /purchases/ → CHIP API
      ▼           ▼
┌──────────┐  ┌──────────┐
│  CHIP    │  │ Magento  │
│  hosted  │  │ callback │
│  page    │  │ /notify  │
└────┬─────┘  └────┬─────┘
     │             │
     │ 5. user     │ 7. async webhook
     │ pays (QR,   │    with txn status
     │ FPX, card)  │
     │             ▼
     │       ┌──────────┐
     │       │ Magento  │
     │       │ order    │
     │       │ updated  │
     │       │ (paid)   │
     │       └──────────┘
     │ 6. redirect back to
     │    /chipin/payment/success
     ▼
┌──────────────┐
│  Success     │
│  page        │
└──────────────┘
```

**Key endpoints:**

- `POST /chipin/payment/redirect` — Magento controller that creates the CHIP purchase and 302-redirects the customer to CHIP's hosted page
- `GET/POST /chipin/callback/success` — customer returns from CHIP after paying (or cancelling)
- `POST /chipin/callback/notify` — **server-to-server webhook** from CHIP; this is the authoritative source of truth (do not trust the success/cancel redirects for order state changes)

### 5.2 Why redirect-based for MVP

- **Simpler.** No PCI scope expansion, no inline card forms, no JS SDK integration.
- **Faster to ship.** All 11+ payment methods funnel through one flow.
- **Matches Malaysian payment UX.** Customers expect to see bank pages (FPX), QR codes (DNQR), or wallet SDKs (ShopeePay) on the gateway's page, not embedded in the merchant's site.
- **Trade-off:** Customer leaves the merchant site during payment. This is normal in MY/SG/TH ecommerce and is the same UX as other Malaysian payment gateways.

**For V2:** Consider inline card form using MPGS hosted fields for the cards payment method. Defer to phase 2.

### 5.3 Payment method class pattern

Each payment method (DuitNow QR, FPX, etc.) extends a common `AbstractMethod` that declares:

- `isAvailable()` — checks if the method is enabled in admin config
- `canUseForCountry()` — restricts by billing country (e.g. FPX only for MY)
- `canUseForCurrency()` — restricts by currency (e.g. FPX only for MYR)
- The list of CHIP "brand" codes to send in the API request (e.g. `duitnowqr`, `fpx`, `mpgs`)

Concrete example for FPX:

```php
<?php
namespace Chipin\Payment\Model\Payment;

use Chipin\Payment\Model\Payment\AbstractMethod;

class Fpx extends AbstractMethod
{
    public const CODE = 'chipin_fpx';

    protected $_code = self::CODE;
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canVoid = true;
    protected $_isOffline = false;

    public function getChipBrand(): string
    {
        return 'fpx';
    }
}
```

### 5.4 Order state machine — critical rules

1. **Order created with `status = pending_payment`** (not "processing" or "complete").
2. **The webhook is authoritative.** Do not mark the order paid based on the customer's browser returning to `/chipin/callback/success` — that page can be reached without payment.
3. **Idempotency:** Webhooks can fire multiple times for the same transaction. Use a unique `chipin_transaction_id` column on `sales_order_payment` and check it before applying state changes. Add a database upgrade script in `Setup/InstallData.php` to add the column.
4. **Stuck-orders safety net:** A cron job (`Chipin\Payment\Cron\PendingOrderChecker`) that checks CHIP for any orders in `pending_payment` for >2 hours and queries CHIP's API for the final state.

### 5.5 Webhook security

- **Verify CHIP's signature** on every webhook. CHIP signs the request body with a secret; reject any webhook where the signature is missing or invalid.
- **IP allowlisting** as a secondary defense (CHIP will publish their webhook IP ranges).
- **Use HTTPS** for the webhook endpoint — never HTTP.
- **Use a dedicated controller route** (not the same controller as the customer's success/cancel) to apply different security policies.

### 5.6 Refunds, partial captures, voids

For V1, support:
- **Full refund** from Magento admin → calls CHIP refund API → updates order state.
- **Void** (cancel an authorization before capture) → calls CHIP void API.
- **Capture** (if merchant chooses "authorize" mode) → manual capture from admin.

Defer to V2: partial captures, multi-capture, partial refunds with split shipments.

### 5.7 Admin configuration

Config goes in `etc/adminhtml/system.xml` with sections for:

- **General:** Enable/disable module, test mode toggle, API key (encrypted), brand key
- **Payment methods:** Per-method enable/disable, title, sort order, applicable countries, applicable currencies
- **Advanced:** Webhook URL display, debug logging toggle, payment action (authorize vs authorize_capture), order status mapping

**Encrypt the API key** with `Magento\Framework\Encryption\EncryptorInterface` — never store it as plain text in the database.

### 5.8 Logging

- Use `Psr\Log\LoggerInterface` (Magento convention) for all log messages.
- Log to a dedicated file: `var/log/chipin.log`.
- Log webhook payloads, API requests/responses (with sensitive fields masked), and state transitions.
- Make logging configurable (off / errors only / verbose).

---

## 6. Payment methods — implementation priority

| Priority | Method | Brand code | Pattern | V1 effort |
|---|---|---|---|---|
| 1 | **DuitNow QR** | `duitnowqr` | QR-display on success page (after redirect) | 1 week |
| 1 | **FPX** | `fpx` | Redirect to FPX bank selector | 1 week |
| 2 | **MPGS Cards** | `mpgs` | Redirect to CHIP-hosted MPGS page | 1 week |
| 3 | **Maybank QR** | `maybankqr` | QR-display | 3 days |
| 3 | **Atome** | `atome` | Redirect to Atome installment page | 3 days |
| 4 | **ShopeePay** | `shopeepay` | Redirect | 2 days |
| 4 | **GrabPay** | `grabpay` | Redirect | 2 days |
| 4 | **TouchNGo** | `tng` | Redirect or QR (depends on CHIP API) | 2 days |
| 5 | **Google Pay** | `googlepay` | JS SDK on merchant page (inline) | 1 week |
| 5 | **Apple Pay** | `applepay` | JS SDK on merchant page (inline) | 1 week |
| 5 | **Crypto** | `crypto` | QR display with wallet address | 3 days |

**Phase 1 (MVP, weeks 1-6):** DuitNow QR + FPX + MPGS Cards. Three methods covers ~80% of Malaysian ecommerce volume.

**Phase 2 (weeks 6-12):** Maybank QR, Atome, ShopeePay, GrabPay, TouchNGo.

**Phase 3 (weeks 12+):** Google Pay, Apple Pay (these need inline JS SDK, more complex), Crypto.

---

## 7. Testing strategy

- **Unit tests** (`Test/Unit/`) for: payment method classes, validators, request builders, response handlers, webhook handler.
- **Integration tests** (`Test/Integration/`) for: database migrations, config flow, order state transitions.
- **Manual test plan** covering:
  - Successful payment (each method)
  - Failed payment (declined, timeout, user cancel)
  - Webhook delivery (success, failure, duplicate)
  - Refund (full, then partial in V2)
  - Multi-currency (MYR, USD, SGD at minimum)
  - Idempotency (double-clicking pay button, double webhook)
  - Compatibility with common third-party checkout modules (e.g. OneStepCheckout, Amasty Checkout)

---

## 8. Reference implementations to study

Before writing code, study these existing Magento 2 payment plugins:

1. **Stripe** — https://github.com/stripe/stripe-magento2 — gold standard for modern Magento 2 payment architecture
2. **Adyen** — https://github.com/Adyen/adyen-magento2 — multi-method plugin, handles 20+ payment methods in one module
3. **Razorpay** — https://github.com/razorpay/razorpay-magento — Indian payment gateway, similar aggregator model to CHIP
4. **PayPal** — https://github.com/paypal/paypal-magento2 — official, comprehensive

Study their: module structure, DI wiring, payment.xml, config.xml, controller design, webhook handling, refund flow.

---

## 9. Things to avoid (anti-patterns)

- ❌ Direct use of `ObjectManager` (always use constructor injection)
- ❌ `Mage::log()` calls (deprecated, use PSR-3 LoggerInterface)
- ❌ Modifying core Magento files
- ❌ Storing card data (use CHIP's tokenization / MPGS hosted fields / Vault)
- ❌ Trusting the customer's browser return URL for payment confirmation
- ❌ Plain-text storage of API keys
- ❌ Synchronous webhook processing (queue if possible)
- ❌ Single big payment method class — split by payment method

---

## 10. Open questions for the team

These should be resolved before / during implementation:

1. **What is the CHIP API base URL and authentication scheme?** (Bearer token? HMAC-signed requests? OAuth?)
2. **What webhook events does CHIP send?** (purchase.created, purchase.paid, purchase.failed, refund.completed, etc.)
3. **What is the exact JSON schema of the "create purchase" request and response?**
4. **Which payment methods are sandbox-testable today?** (FPX sandbox, DuitNow QR sandbox, MPGS test cards)
5. **Does CHIP support multi-currency at the API level, or do merchants need separate API keys per currency?**
6. **What is the refund API — synchronous or async? Do refunds fire a webhook when complete?**
7. **Merchant onboarding flow:** Is the API key generated in the CHIP merchant dashboard, or programmatically? Are sub-merchants supported?

---

## 11. Phased delivery plan

### Phase 0 — Repo setup (week 1)
- [ ] Finalize composer.json, registration.php, module.xml
- [ ] Set up CI (GitHub Actions) for PHP syntax + Magento coding standard
- [ ] Write README with installation instructions
- [ ] Set up dev environment with a sample Magento 2.4.7 store

### Phase 1 — MVP (weeks 2-6)
- [ ] Implement `AbstractMethod` base class
- [ ] Implement `DuitNowQr` and `Fpx` payment methods
- [ ] Implement `Gateway\Http\Client\ChipClient`
- [ ] Implement `Redirect` controller
- [ ] Implement `Notify` (webhook) controller with signature verification
- [ ] Implement `ConfigProvider` and checkout JS renderer
- [ ] Implement admin config UI in `system.xml`
- [ ] Add `chipin_transaction_id` column to `sales_order_payment`
- [ ] Implement idempotent webhook handler
- [ ] Implement pending-order cron job
- [ ] Write unit tests for the core flow
- [ ] Manual end-to-end test on a real Magento store with sandbox CHIP account

### Phase 2 — More methods (weeks 6-12)
- [ ] Add MPGS Cards, Maybank QR, Atome
- [ ] Add ShopeePay, GrabPay, TouchNGo
- [ ] Refund and void flows
- [ ] Manual capture mode
- [ ] Magento Marketplace submission prep

### Phase 3 — Inline methods (weeks 12+)
- [ ] Google Pay inline JS SDK
- [ ] Apple Pay inline JS SDK
- [ ] Crypto payment method
- [ ] Multi-currency improvements
- [ ] Performance / load testing
- [ ] Magento Marketplace publication

---

## 12. Useful links

### Official Magento / Adobe Commerce
- DevDocs (Payment Gateway): https://developer.adobe.com/commerce/php/extensions/payment/
- DevDocs (Sales): https://developer.adobe.com/commerce/php/module-reference/module-sales/
- Coding standards: https://developer.adobe.com/commerce/php/coding-standards/
- GitHub: https://github.com/magento/magento2

### Reference plugins
- Stripe: https://github.com/stripe/stripe-magento2
- Adyen: https://github.com/Adyen/adyen-magento2
- Razorpay: https://github.com/razorpay/razorpay-magento
- PayPal: https://github.com/paypal/paypal-magento2

### CHIP (chip-in.asia)
- Main site: https://chip-in.asia
- (Developer docs link to be added when confirmed)

---

## 13. Document history

| Date | Change | Author |
|---|---|---|
| 2026-06-13 | Initial plan drafted from research session | AI-assisted (Claude) |

---

**For any future agent reading this:** the goal of this document is to be a complete, self-contained brief. If something is unclear, fix the doc first (in a PR), then write the code. Keep `PLAN.md` updated as decisions evolve.
