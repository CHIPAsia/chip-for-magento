# CHIP for Magento — Implementation Plan

> **Audience:** Future AI agents (or human developers) who will be implementing or
> extending this Magento 2 payment gateway module for [chip-in.asia](https://chip-in.asia).
> This document is the single source of truth. Read it fully before writing any code.
>
> **Status:** v1.0.0 implemented, live-tested on Magento 2.4.8-p5 (PHP 8.2). Items in
> "Open items" are not yet done.

---

## 1. What this repo is

Official CHIP payment gateway module for Magento 2 — a Malaysian payment aggregator
(DuitNow QR, FPX, MPGS cards, ShopeePay, Touch 'n Go, Atome, GrabPay, Maybank QR,
Google Pay, Apple Pay, crypto) exposed to merchants as a single integration.

**Module name:** `CHIPAsia_ChipPaymentGateway` (vendor `CHIPAsia`).
**Composer package:** `chipasia/chip-payment-gateway` (type `magento2-module`).

---

## 2. Version strategy (DECIDED — do not revisit)

**One repo, one code base, one module covering Magento 2.0 – 2.4.4p5+ (PHP 7.0 – 8.5).**
No per-version forks, no one-repo-per-version.

Rationale (verified against `magento/magento2` tags 2.0.0 → 2.4.9):

- The Magento Payment API (`AbstractMethod`, `Curl`, `Order\Payment`,
  `CsrfAwareActionInterface`) is stable across 2.0 – 2.4.
- Version-specific differences are handled internally (see "Compatibility notes").

### Compatibility notes (all verified live or against tags)

| Difference | Handling |
|---|---|
| CSRF on frontend POST exists only 2.3+ | `CallbackCsrf.php` implements `CsrfAwareActionInterface`, guarded by `interface_exists()`; 2.0–2.2 use `CallbackBase`. `CallbackCsrf.php` (has return types) only loads on 2.3+ (PHP 7.1+). |
| `Curl::post()` only accepts arrays (form-encoded) on 2.0–2.2 | JSON body sent via `Curl::setOption(CURLOPT_POSTFIELDS, $json)` after `post()` — `_curlUserOptions` overrides POSTFIELDS on every version. Reset with `setOptions([])` after each call (shared-singleton leak — see bug history). |
| `Magento\Framework\Serialize\Serializer\Json` exists only 2.2+ | Native `json_encode`/`json_decode`. |
| PHP 7.0 vs PHP 8.0+ return-type variance | No scalar/return types in files loaded on all versions; `CallbackCsrf.php` only loads on 2.3+. |
| Encrypted secret key | `Magento\Config\Model\Config\Backend\Encrypted` stores ciphertext (`\d+:\d+:...`). The read pipeline decrypts on some versions, not others; `getSecretKey()` decrypts only when the value looks like ciphertext, otherwise uses it as-is. |
| `Config\TypePool` sensitive fields | `etc/di.xml` marks `payment/chip/secret_key` sensitive. |

### Magento CLI quirk

`bin/magento config:set` / `cache:*` may report "no commands defined" on a fresh
install until `setup:di:compile` succeeds. Not module-specific.

---

## 3. Payment flow (redirect-based)

```
Checkout (place order)
  → order created with state pending_payment (isInitializeNeeded, initialize())
  → frontend JS (view/frontend/web/js/view/payment/method-renderer/chip.js)
    overrides afterPlaceOrder() → GET /chip/payment/redirect
  → Controller/Payment/Redirect.php
      creates CHIP purchase via Model/Api (POST /purchases/)
      stores chip_purchase_id + chip_checkout_url in payment additional_information
      302 → CHIP hosted page (checkout_url)
  → CHIP sends async webhook → /chip/payment/callback
      verifies X-Signature (RSA sha256WithRSAEncryption) against CHIP public key
      acquires MySQL GET_LOCK(chip_payment_<order_id>, 15s)
      applies state transition (paid → processing, cancelled/expired/failed → canceled,
      refunded → closed) only from pending/new (idempotent)
      releases lock
  → customer returns to /chip/payment/returnaction
      fetches purchase status, redirects to checkout success/failure page
```

- **The webhook is the source of truth** for order state. The return controller only
  *displays* the result — it never flips an order to paid by itself.

### Webhook security

1. `X-Signature` header verified with `openssl_verify($body, base64_decode($sig), $publicKey, 'sha256WithRSAEncryption')`.
2. Public key fetched from `GET /public_key/` — the gateway returns a **JSON-encoded PEM
   string** (`"-----BEGIN PUBLIC KEY-----..."`), not a `{"key":...}` object. `getPublicKey()`
   json_decodes first, falls back to raw body.
3. Missing/bad signature → 401. Invalid JSON → 400. Unknown order reference → 404.
4. CSRF: 2.3+ bypassed via `CsrfAwareActionInterface` (same pattern as `Magento_Paypal` Ipn).

### Idempotency & concurrency

- MySQL advisory lock `GET_LOCK('chip_payment_<order_id>', 15)` / `RELEASE_LOCK` wraps
  every state transition (same pattern as the CHIP WooCommerce plugin). Concurrent
  webhook retries are serialized; repeat callbacks on an already-transitioned order are
  no-ops.
- State transitions only fire from `pending_payment`/`new` — a second `paid` webhook
  after the order is `processing` does nothing.

---

## 4. Code structure (current)

```
app/code/CHIPAsia/ChipPaymentGateway/
├── registration.php
├── composer.json              (chipasia/chip-payment-gateway)
├── etc/
│   ├── module.xml             (CHIPAsia_ChipPaymentGateway)
│   ├── config.xml             (default payment/chip values)
│   ├── di.xml                 (sensitive config: secret_key)
│   ├── adminhtml/system.xml   (admin config UI)
│   └── frontend/
│       ├── routes.xml         (frontName chip: /chip/payment/...)
│       └── events.xml         (checkout_submit_all_after → registry order)
├── Model/
│   ├── Chip.php               (AbstractMethod: payment method, createPurchase,
│   │                           refund, getSecretKey, getPaymentMethodWhitelist,
│   │                           resolvePaymentMethodGroups)
│   ├── Api.php                (CHIP API client; JSON body via curl setOption;
│   │                           reset curl options after each call)
│   ├── SignatureVerifier.php  (webhook X-Signature RSA verification)
│   ├── OrderUpdater.php       (state machine + GET_LOCK/RELEASE_LOCK)
│   └── Config/Source/PaymentMethods.php (admin multiselect options)
├── Controller/Payment/
│   ├── Redirect.php           (create purchase + 302 to CHIP)
│   ├── ReturnAction.php       (after CHIP redirect → success/failure)
│   ├── Callback.php           (branch: CallbackCsrf on 2.3+, CallbackBase else)
│   ├── CallbackBase.php       (webhook logic; no return types – PHP 7.0 safe)
│   └── CallbackCsrf.php       (2.3+ CsrfAwareActionInterface; return types)
├── Observer/
│   └── SaveOrderAfterSubmitObserver.php (registers chip_order for redirect)
├── view/frontend/
│   ├── layout/checkout_index_index.xml   (registers checkout renderer)
│   └── web/js/view/payment/method-renderer/chip.js (+ template)
└── tests/smoke.php            (pure-logic smoke tests, PHP CLI runnable)
```

Note: no `Block/Payment/Info` admin order block yet; order details show via
`additional_information` on the payment (see Open items).

---

## 5. Payment-method whitelist & groups (DECIDED)

The module uses a **single `Chip` method + `payment_method_whitelist` multiselect**
(admin config) — same pattern as WooCommerce/GiveWP. No per-method classes.

`resolvePaymentMethodGroups()` (mirrors chip-for-woocommerce / -prestashop):

- **DuitNow QR group** `{duitnow_qr, dnqr}` → `dnqr` wins when both available;
  if the brand only exposes `dnqr`, a merchant who configured `duitnow_qr` still gets QR.
- **Shopee group** `{razer_shopeepay, shopee_pay}` → `shopee_pay` wins.
- **Card aggregator** `card` → expanded to `{visa, mastercard, maestro}` — the gateway
  rejects the literal `card` key with 400 `invalid_choice` (verified live).
- **Short-circuit:** whitelists with no group member are returned untouched (no API
  call, no method injection). A `fpx+shopee_pay` whitelist never leaks `dnqr`/`card`.

Resolution calls `GET /payment_methods/` with `amount` (always sent; some methods are
hidden below their minimum) — safe default `amount=1000` (RM 10) for availability checks
that are not tied to a real order, real amount for actual checkout.

---

## 6. Admin configuration (`payment/chip/*`)

| Key | Purpose |
|---|---|
| `active` | Enable/disable |
| `title` | Checkout display title |
| `secret_key` | Encrypted via `Encrypted` backend model; read through `getSecretKey()` |
| `brand_id` | Brand UUID (query param on /payment_methods/ etc.) |
| `payment_method_whitelist` | Multiselect; empty = all available |
| `due_strict` / `due_strict_timing` | Payment expiry (minutes) |
| `send_receipt` | Whether CHIP emails receipt |
| `debug` | API logs |

### Refunds

`Chip::refund()` is invoked by Magento when an online credit memo is created from an
invoice → `POST /purchases/{id}/refund/` with `amount` in sen. `chip_purchase_id` must
be present on the payment (orders created before the module shipped cannot be refunded
online — use offline credit memos).

---

## 7. Testing strategy

- **Smoke tests** (`tests/smoke.php`): signature verification (valid/tampered/empty),
  JSON encoding, whitelist parsing, amount conversion, refund conversion, API error
  handling, secret-key format detection, group resolution (dnqr priority, card
  expansion), short-circuit.
- **Live testing** (already done once, repeat before every release):
  - deploy a throwaway Magento (e.g. `shinsenter/magento` image, Dokploy compose)
  - exercise `/payment_methods/`, create purchase, webhook (missing/bad/good
    signature), order state transitions, concurrent webhooks (pcntl fork),
    refund (if a payable purchase exists)
  - Results from Magento 2.4.8-p5 are documented in `README.md` → "Live Testing".

---

## 8. Open items (not yet implemented)

- [ ] **Admin order view block** (`Block/Payment/Info`) — show CHIP purchase id /
  transaction details on the admin order page.
- [ ] **Void/capture release flows** — currently only refund; `capture`/`release`
  endpoints exist in `Api` but are not wired to admin actions.
- [ ] **Recurring/token flows** (`force_recurring`, `delete_recurring_token`) —
  API methods exist; not exposed in UI.
- [ ] **Live test on older majors** — 2.0/2.2 (PHP 7.0) and 2.3 (PHP 7.1) still
  need a live instance; static verification done against tags only.
- [ ] **Magento Marketplace submission prep** (composer package + README polish).
- [ ] **CI workflow** (PHP lint + Magento coding standard) in `.github/workflows/`.

## 9. Done (implemented)

- [x] **Cron `PendingOrderChecker`** (`Cron/PendingOrderChecker.php`,
  `etc/crontab.xml` job `chip_pending_order_check`, every 15 min) — finds orders in
  `pending_payment` older than `payment/chip/pending_threshold` (default 120 min),
  queries `GET /purchases/{id}/`, applies the final state via `OrderUpdater`
  (same idempotent GET_LOCK path as the webhook). Verified live on 2.4.8-p5.

---

## 10. Anti-patterns (avoid)

- ❌ Direct `ObjectManager` use — constructor DI only.
- ❌ Logging API keys / full webhook bodies with secrets — mask them.
- ❌ Modifying core Magento files.
- ❌ Trusting the browser return URL for order state — webhook is authoritative.
- ❌ Plain-text secret key storage — encrypted config field + `getSecretKey()`.
- ❌ Per-Magento-version modules — single code base (see §2).
- ❌ Sending `card` literally in the whitelist — expand to networks.

---

## 11. Document history

| Date | Change | Author |
|---|---|---|
| 2026-06-13 | Initial plan drafted (doc-only PR, superseded by implementation) | AI-assisted (Claude) |
| 2026-08-19 | Rewritten to match implemented module (single repo 2.0–2.4, whitelist approach, GET_LOCK idempotency, live-test results) | AI-assisted (Claude) |
| 2026-08-19 | PendingOrderChecker cron implemented and live-verified; moved to Done | AI-assisted (Claude) |
