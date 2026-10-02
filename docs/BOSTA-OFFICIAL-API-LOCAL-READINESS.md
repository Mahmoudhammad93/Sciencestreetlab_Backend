# BOSTA Official API — Local Readiness

## Official Documentation Used

- [Create your first delivery](https://docs.bosta.co/docs/how-to/create-your-first-delivery/)
- [Get your API key](https://docs.bosta.co/docs/how-to/get-your-api-key/)
- [Get delivery status via webhook](https://docs.bosta.co/docs/how-to/get-delivery-status-via-webhook/)

Contract facts applied:

| Fact | Value |
| --- | --- |
| Host | `https://app.bosta.co` |
| Create | `POST /api/v2/deliveries?apiVersion=1` |
| Auth | `Authorization: <BOSTA_API_KEY>` (raw key, no `Bearer`) |
| Outbound type | `10` (Deliver / SEND) |
| Webhook auth | Dashboard custom header name + value (`hash_equals`) |
| Webhook body | Official fields (`_id`, `trackingNumber`, `state`, `type`, …) |
| States | Official numeric codes (e.g. `45` = Delivered for SEND) |

No undocumented endpoints or invented HMAC algorithms were used.

## Existing Architecture

Commerce order path (updated):

```
Checkout → Order awaiting_payment + requires_delivery_fulfillment (kit/bundle)
→ Fawaterak payment
→ HMAC webhook / getInvoiceData return (server-verified)
→ amount + currency + ownership checks
→ PaymentCompletionService (idempotent)
→ OrderPaid (once)
→ CreateBostaShipmentOnOrderPaid → HttpBostaClient create (once)
→ Bosta webhook (authenticated header)
→ state 45 Delivered → fulfillFromBostaDelivery (once)
→ OrderFulfilled → enrollment + email
```

## Changes

- `config/bosta.php` normalized to official host, raw API key aliases, webhook auth header, contract flags.
- Checkout no longer creates external Bosta deliveries.
- Post-payment listener creates Bosta delivery once.
- `HttpBostaClient` real HTTP create + cities lookup.
- Fawaterak amount/currency/ownership verification before complete.
- `ConfiguredBostaWebhookVerifier` uses configured header + secret.
- `BostaStatusMapper` maps official numeric SEND states.
- Shipment creation states: `pending_creation` / `created` / `creation_failed` (via `ShipmentStatus` + metadata).
- Local non-secret `.env` flags normalized (`BOSTA_API_URL`, contract/auth ready). API key left as manually configured.

## Post-Payment Trigger

`CreateBostaShipmentOnOrderPaid` listens to `OrderPaid`.

`BostaShipmentService::ensureShipmentForOrder` refuses unpaid orders (`paid_at === null`) and skips already-delivered orders.

Checkout only calls `markRequiresDeliveryIfNeeded`.

## Fawaterak Verification

On webhook + return completion paths:

1. Invoice/payment reference ownership (`gateway=fawaterak`, `gateway_order_id` match, order present).
2. Amount match vs local payment/order (tolerant to 0.01).
3. Currency match (case-insensitive).
4. Existing HMAC validation retained.
5. Prefer `getInvoiceData` re-query when client configured.
6. Frontend redirect remains non-authoritative.
7. `PaymentCompletionService` locks payment row; duplicate webhook short-circuits when already completed.

Mismatch → payment **not** completed → **zero** Bosta create.

## HttpBostaClient

- URL: `{BOSTA_API_URL}/api/v2/deliveries?apiVersion=1`
- Headers: raw `Authorization`, `Content-Type`/`Accept` JSON
- Timeout + connection retries from config
- Never logs Authorization
- Requires `BOSTA_API_CONTRACT_READY=true` for create
- Cities read (`GET /api/v2/cities`) used for auth checks + city name resolution

## Create Delivery Payload

Built from Order / customer / address:

- `type: 10`
- `businessReference`: order number
- `receiver`: firstName, lastName, phone, email
- `dropOffAddress`: resolved Bosta city id + firstLine (+ optional zone/district if present)
- `specs.packageDetails`: description + itemsCount
- `notes`: order reference
- optional `webhookUrl` / `webhookCustomHeaders` when configured

Does **not** invent zone/district IDs. Unresolvable city → clear validation/configuration error (Bosta 3009 family).

## Prepaid/COD Handling

Fawaterak-paid orders are prepaid outside Bosta.

Create payload always sends `cod: 0` for these deliveries.

Does **not** use Bosta `escrowInfo` prepaid feature.

Test proves: order total > 0 → Bosta collectible COD = 0.

## Response Handling

Parses `success`, `data._id`, `trackingNumber`, `state`.

Persists external id / tracking / provider status / sanitized raw metadata.

Non-2xx or `success=false` → shipment stays retryable (`creation_failed`); payment remains paid.

## Idempotency

- Unique `(order_id, provider)` + row locks
- Existing `external_shipment_id` → no second create
- Placeholder / failed row without external id → retry **same** local shipment
- Duplicate Fawaterak webhook / duplicate `OrderPaid` → one Bosta create
- Duplicate Delivered webhook → one fulfillment / enrollment / email
- Terminal delivered not regressed by later mid-journey events

## Webhook Authentication

```
BOSTA_WEBHOOK_AUTH_HEADER=Authorization
BOSTA_WEBHOOK_SECRET=<secret>
BOSTA_WEBHOOK_AUTH_READY=true
```

Verifier: read configured header → `hash_equals` to secret → reject missing/wrong. Secret never logged.

Production URL remains `https://app.sciencestreetlab.com/api/v1/webhooks/bosta`. Localhost is not assumed reachable by Bosta; webhook covered by unit/feature tests.

## Official State Mapping

SEND/Deliver mapping includes documented codes (10, 20, 21, 24, 30, 41, 45, 47, 48, 49, …).

- `45` + type SEND → Delivered → fulfill once
- `47` Exception → Unknown (no fulfill)
- Unknown codes → Unknown (no fulfill)

## Tests

Ran (TEST DB only):

| Suite | Result |
| --- | --- |
| `BostaShippingFulfillmentTest` + `BostaOfficialApiIntegrationTest` | **33 passed / 196 assertions** |
| `OrderShippingStatusApiTest` + `CheckoutFlowTest` + `GuestCheckoutTest` | included in broader commerce run (**PASS**) |
| `CodOrderFulfillmentEnrollmentTest` + `OrderConfirmationMailTest` + `AuthAccountDataIsolationTest` + `EnrollmentVerificationTest` | **21 passed / 116 assertions** |

Covered assertions include: create URL/headers/type/businessReference/receiver/address/itemsCount, prepaid COD=0, non-2xx/`success=false`, unpaid→0 creates, paid physical→1 create, duplicate payment webhook→1 create, duplicate OrderPaid→1 create, digital→0 create, existing external id→0 second create, failed local→safe retry, webhook auth missing/wrong/valid, numeric state map, Delivered fulfill once, out-of-order no regression, unknown no fulfill, Fawaterak amount/currency mismatch→no pay→0 Bosta.

## Local Bosta Authentication

Safe read check: `GET https://app.bosta.co/api/v2/cities` with configured key.

Result recorded in final response fields (`BOSTA_AUTH_CHECK`, `HTTP_STATUS`).

**No real Bosta delivery created.**

## Production Deployment Requirements

1. Set production `BOSTA_API_KEY`, `BOSTA_API_URL=https://app.bosta.co`, `BOSTA_API_CONTRACT_READY=true`.
2. `BOSTA_USE_FAKE=false` (already forbidden in production binding).
3. Configure Bosta dashboard webhook URL + auth header/secret; set `BOSTA_WEBHOOK_AUTH_*`.
4. Confirm business location exists in Bosta account (error 1073 otherwise).
5. Ensure checkout shipping stores address line + resolvable city (or explicit `bosta_city_id` / zone / district).
6. Deploy backend; do **not** rely on localhost webhook reachability.
7. Controlled staging create-delivery test before broad enablement.
8. Keep Fawaterak amount/currency verification enabled.

## Remaining Blockers

- Checkout currently stores city **name** only; zone/district IDs not collected. City name resolves via official cities list when matched; unmatched cities fail create with clear error.
- Production Bosta account webhook registration not done in this task.
- No production deploy / no real Fawaterak charge / no real delivery in this task.
- Local `BOSTA_USE_FAKE=true` still recommended for app runtime until a controlled create-delivery test is explicitly approved.

## FINAL VERDICT

**BOSTA_OFFICIAL_API_LOCAL_READY** for local implementation, tests, and API authentication.

Not production-deployed. Not ready to flip production traffic until production env + webhook registration + address/city readiness are confirmed.
