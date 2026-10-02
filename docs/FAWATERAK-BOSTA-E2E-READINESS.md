# FAWATERAK-BOSTA-E2E-READINESS

## Architecture

Current production commerce path (code-verified):

```
POST /api/v1/checkout
  → CheckoutService::createOrderFromCart
      status = awaiting_payment
      → BostaShipmentService::ensureShipmentForOrder (kit/bundle only)
          → BostaClientInterface::createShipment
          → shipments row (or pending placeholder if create blocked)
          → orders.requires_delivery_fulfillment = true

POST /api/v1/checkout/{order}/pay
  → PaymentGatewayInterface (FawaterakGateway)
      → payments row (pending → processing)
      → Fawaterak createInvoiceLink
      → returns payment_url

Fawaterak hosted checkout
  → browser return URLs (frontend payment-return)  [hint only]
  → POST /api/v1/payments/fawaterak/webhook        [authoritative when signed]
  → GET  /api/v1/payments/fawaterak/confirm|callback
        → FawaterakGateway::handleReturn
            → getInvoiceData (server re-verify paid=1)

PaymentCompletionService::complete
  → payment.status = completed
  → OrderFulfillmentService::markPaid
      → paid_at set once, OrderPaid once
      → if requires_delivery_fulfillment: do NOT OrderFulfilled yet
      → else: fulfill → OrderFulfilled → enrollments/email

POST /api/v1/webhooks/bosta
  → ConfiguredBostaWebhookVerifier
  → BostaWebhookService
      → map status
      → on first Delivered: fulfillFromBostaDelivery → OrderFulfilled
```

Key files:

| Area | Path |
| --- | --- |
| Checkout | `CheckoutService`, `CheckoutController` |
| Payment gateway | `FawaterakGateway`, `FawaterakClient`, `FawaterakWebhookValidator` |
| Payment HTTP | `PaymentController` |
| Payment completion | `PaymentCompletionService` |
| Paid/fulfilled | `OrderFulfillmentService`, `OrderPaid`, `OrderFulfilled` |
| Bosta create | `BostaShipmentService`, `HttpBostaClient` (stub), `FakeBostaClient` |
| Bosta webhook | `BostaWebhookController`, `BostaWebhookService`, `ConfiguredBostaWebhookVerifier` |
| Status map | `BostaStatusMapper`, `ShipmentStatus` |
| Config | `config/fawaterak.php`, `config/bosta.php`, `config/commerce.php` |

## Fawaterak Flow

| Step | Behavior |
| --- | --- |
| Order create | `OrderStatus::AwaitingPayment` |
| Pay initiate | `Payment` with `gateway=fawaterak`, amount=order.total, currency=order.currency |
| Invoice storage | `payments.gateway_order_id` = Fawaterak `invoiceId` |
| Webhook URL sent to Fawaterak | `url('/api/v1/payments/fawaterak/webhook')` |
| Success/fail/pending return | Frontend `/checkout/payment-return?...` (not trusted alone) |
| Webhook auth | HMAC-SHA256(`InvoiceId&InvoiceKey&PaymentMethod`, `FAWATERAK_VENDOR_KEY`) vs `hashKey` |
| Return confirm | Server calls `getInvoiceData`; completes only if `paid=1` |
| Idempotency | Completed payment short-circuits; `markPaid` locks on `paid_at` |

**Gaps (also confirmed by architecture trace):**

- Webhook validates signature + `invoice_status=paid` but does **not** re-check amount/currency against local payment/order. Return path trusts `paid=1` without amount compare.
- Each `checkout/{order}/pay` creates a **new** `payments` row — no reuse of an existing pending Fawaterak payment.
- `FawaterakWebhookValidator::isValidCancelWebhook` exists but is unused by the gateway.
- No automated Fawaterak webhook/return tests in `tests/` (Bosta coverage exists separately).

Browser redirect alone does **not** mark paid — `handleReturn` re-queries Fawaterak.

## Bosta Flow

**Trigger today:** checkout (before payment), for kit/bundle items when `BOSTA_ENABLED=true`.

**Not** triggered by `OrderPaid`.

`HttpBostaClient::createShipment` **always throws** `BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS` until:

1. Official create-shipment API docs are implemented in the client
2. `BOSTA_API_URL` + `BOSTA_API_KEY` present
3. `BOSTA_API_CONTRACT_READY=true`

On throw, `BostaShipmentService` still inserts a **pending** shipment with `external_shipment_id=null` and metadata `blocked=BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS`, and sets `requires_delivery_fulfillment=true`.

**COD/prepaid:** Fake client returns no COD amount. Real HTTP mapping does not exist — prepaid vs COD semantics **cannot** be verified in production until `HttpBostaClient` is implemented from official docs. **Do not invent.**

Idempotency for create: unique `(order_id, provider)` path via lock + existing-shipment early return.

## Environment

Probed on production runtime **without printing secrets** (config not cached):

| Flag | Value |
| --- | --- |
| FAWATERAK_CONFIGURED | **YES** |
| FAWATERAK_MODE | **LIVE** (`app.fawaterk.com`) |
| PAYMENT_GATEWAY | `fawaterak` |
| BOSTA_ENABLED | **YES** |
| BOSTA_TOKEN_CONFIGURED (`config('bosta.api_key')`) | **NO** |
| BOSTA_BASE_URL_CONFIGURED | **NO** |
| BOSTA_MODE | **BLOCKED_UNTIL_CONTRACT** |
| BOSTA_API_CONTRACT_READY | **NO** |
| BOSTA_WEBHOOK_SECRET_CONFIGURED | **YES** |
| BOSTA_WEBHOOK_SIGNATURE_READY | **NO** |
| BOSTA_USE_FAKE | **NO** (correct for production) |
| APP_ENV | production |
| CONFIG_CACHED | false |

Host `.env` currently has Bosta **flags/secret** keys (`BOSTA_ENABLED`, `BOSTA_USE_FAKE`, `BOSTA_API_CONTRACT_READY`, `BOSTA_WEBHOOK_SIGNATURE_READY`, `BOSTA_WEBHOOK_SECRET`) but **no** `BOSTA_API_KEY` / `BOSTA_API_URL` lines.

Human note: if a token was added under a non-standard name, runtime will not see it — expected key is **`BOSTA_API_KEY`**, plus **`BOSTA_API_URL`**. After editing `.env`, recreate backend/queue so `env_file` reinjects (container does not mount `.env` as a file).

## Payment Verification

| Check | Status |
| --- | --- |
| Webhook HMAC | Implemented |
| Return re-query | Implemented |
| Duplicate webhook | Short-circuit on completed payment |
| Amount/currency match | **MISSING** (hardening recommended before live E2E) |
| Client success alone marks paid | **NO** (safe) |

## Fulfillment Trigger

| Event | Course unlock / OrderFulfilled |
| --- | --- |
| OrderPaid on digital/non-gated | Yes |
| OrderPaid on `requires_delivery_fulfillment=true` | **No** — waits for Bosta Delivered |
| Bosta Delivered webhook | Yes (`fulfillFromBostaDelivery`) |
| Admin Shipped on gated order | **No** unlock |

## Bosta Idempotency

| Layer | Status |
| --- | --- |
| Ensure shipment | Lock + existing row return |
| Pending placeholder on API block | Creates one pending row; retries return existing |
| Real provider id | Only after successful createShipment |
| Webhook delivered twice | `outcome=duplicate`; no second fulfill |
| Terminal regression | Ignored |

## Bosta Webhook

| Item | Value |
| --- | --- |
| Route | `POST /api/v1/webhooks/bosta` |
| Public URL | `https://app.sciencestreetlab.com/api/v1/webhooks/bosta` |
| Reachable | **YES** (HTTP **401** Unauthorized on empty/unsigned probe — route live) |
| Production verifier | `ConfiguredBostaWebhookVerifier` — **always false** until official signature algorithm implemented **and** `BOSTA_WEBHOOK_SIGNATURE_READY=true` |
| Dashboard registration | **NOT verifiable from Laravel** — ops must register URL in Bosta once verifier is real |

`BOSTA_WEBHOOK_SECRET_CONFIGURED = YES` but **NOT_SUFFICIENT**: code intentionally does not invent HMAC; secret alone cannot accept webhooks.

## Status Mapping

| Bosta provider examples (provisional) | Local `ShipmentStatus` | Order effect |
| --- | --- | --- |
| pending / new / created / pickup_requested | `created` | none |
| picked_up / collected | `picked_up` | may set shipped_at |
| in_transit / shipped / on_the_way | `in_transit` | may set shipped_at |
| out_for_delivery | `out_for_delivery` | may set shipped_at |
| delivered / delivery_confirmed / completed | `delivered` | once: order → delivered + fulfill |
| cancelled / canceled | `cancelled` | no fulfill |
| failed / returned / rejected | `failed` | no fulfill |
| unknown / empty | `unknown` | metadata only; never fulfill |

Mappings remain provisional until official Bosta status docs confirm.

## Tests

Not re-run as a green-light for payment in this phase because **production Bosta create path is unimplemented**. Existing suite (documented historically):

- `BostaShippingFulfillmentTest` (21)
- `OrderShippingStatusApiTest` (19)
- Fawaterak/MyFatoorah payment tests present in repo

**E2E readiness tests for amount mismatch / prepaid COD / real HttpBostaClient:** blocked until API contract exists.

## Deployment

**No Commerce/Bosta/Fawaterak code deploy in this phase.**

Reason: cannot implement `HttpBostaClient` or webhook signature without guessing endpoints/payloads (explicitly forbidden). WordPress attempt runtime left untouched.

## Production Readiness

| Check | Result |
| --- | --- |
| backend/frontend/nginx/queue/scheduler/mysql | Up / healthy (prior probe) |
| `/api/v1/health` | 200 (prior) |
| Fawaterak configured | YES LIVE |
| Bosta token visible to runtime | **NO** |
| Bosta base URL | **NO** |
| HttpBostaClient real HTTP | **NO** (stub throws) |
| Fawaterak webhook reachable | YES |
| Bosta webhook reachable | YES (rejects unauthorized) |
| Safe for real paid → Bosta create | **NO** |

## Webhook URLs

FAWATERAK_WEBHOOK_URL:
`https://app.sciencestreetlab.com/api/v1/payments/fawaterak/webhook`

BOSTA_WEBHOOK_URL:
`https://app.sciencestreetlab.com/api/v1/webhooks/bosta`

## Real Test Order

**NOT CREATED.**

## Human Payment Checkpoint

**NOT OPENED** — readiness failed before checkout.

## Remaining Blockers (must clear before TASK_READY_FOR_PAYMENT)

1. Add to production `.env` (then recreate backend + queue):
   - `BOSTA_API_KEY=<token>`
   - `BOSTA_API_URL=<official base URL>`
2. Provide / confirm **official Bosta create-shipment** request/response docs (path, headers, body, COD vs prepaid fields).
3. Implement `HttpBostaClient` from those docs (no guessing); set `BOSTA_API_CONTRACT_READY=true` only after.
4. Provide / confirm **official Bosta webhook signature** algorithm; implement `ConfiguredBostaWebhookVerifier`; set `BOSTA_WEBHOOK_SIGNATURE_READY=true` only after.
5. Register `https://app.sciencestreetlab.com/api/v1/webhooks/bosta` in Bosta dashboard once verifier accepts real signatures.
6. Recommended hardening: Fawaterak amount/currency verification + payment-row locking before live E2E.
7. Decide whether shipment should remain checkout-time or move to post-`OrderPaid` (current design = checkout; preferred invariant in this task = after verified payment) — requires explicit product decision + code change.

## FINAL VERDICT

**FAWATERAK_BOSTA_E2E_NOT_READY — STOPPED BEFORE PAYMENT**

Fawaterak live config and webhook route look usable. Bosta cannot safely create a real delivery or accept authenticated webhooks with the current production code/config. Creating a paid test order now would leave payment successful and shipment stuck `pending` with null provider id (or risk duplicate retries later).
