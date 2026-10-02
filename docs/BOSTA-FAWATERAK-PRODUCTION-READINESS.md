# BOSTA + FAWATERAK — Production Readiness

## Deployment

| Item | Value |
| --- | --- |
| PRE_DEPLOY_SHA | `8e00cccb1aa27188b278624e7a22c721651a606a` |
| POST_DEPLOY_SHA | `9a0d7d9c08d9a64ec018e1f33e05e6416e9f478d` |
| Method | Narrow SCP → server git commit → `docker compose build backend queue scheduler` → `up -d --no-deps --force-recreate` backend/queue/scheduler/nginx → `optimize:clear` |
| Schema migration | **None** (code-only; no DB DDL) |
| WordPress migration files | **Not deployed** |

### Exact files deployed (16)

1. `config/bosta.php`
2. `.env.example`
3. `app/Modules/Commerce/Application/Listeners/CreateBostaShipmentOnOrderPaid.php`
4. `app/Modules/Commerce/Application/Services/BostaShipmentService.php`
5. `app/Modules/Commerce/Application/Services/BostaWebhookService.php`
6. `app/Modules/Commerce/Application/Services/CheckoutService.php`
7. `app/Modules/Commerce/Application/Services/PaymentCompletionService.php`
8. `app/Modules/Commerce/Http/Controllers/Api/BostaWebhookController.php`
9. `app/Modules/Commerce/Infrastructure/Payment/FawaterakGateway.php`
10. `app/Modules/Commerce/Infrastructure/Payment/FawaterakWebhookValidator.php` (rename from mis-cased file)
11. `app/Modules/Commerce/Infrastructure/Providers/CommerceServiceProvider.php`
12. `app/Modules/Commerce/Infrastructure/Shipping/Bosta/BostaStatusMapper.php`
13. `app/Modules/Commerce/Infrastructure/Shipping/Bosta/ConfiguredBostaWebhookVerifier.php`
14. `app/Modules/Commerce/Infrastructure/Shipping/Bosta/FakeBostaClient.php`
15. `app/Modules/Commerce/Infrastructure/Shipping/Bosta/HttpBostaClient.php`
16. `docs/BOSTA-OFFICIAL-API-LOCAL-READINESS.md`

Local TEST DB suite before deploy: **88 passed / 636 assertions**.

## Production Environment

Non-secret flags updated on host `.env` (secrets not copied from local):

| Key | Value |
| --- | --- |
| BOSTA_ENABLED | true |
| BOSTA_USE_FAKE | false |
| BOSTA_API_URL | https://app.bosta.co |
| BOSTA_API_CONTRACT_READY | true |
| BOSTA_WEBHOOK_AUTH_HEADER | Authorization |
| BOSTA_WEBHOOK_AUTH_READY | true |
| BOSTA_WEBHOOK_SECRET_CONFIGURED | **YES** |
| BOSTA_API_KEY_CONFIGURED | **NO** (line absent from production `.env`) |

Backend + queue both see the same non-secret config after recreate.

Bound client: `HttpBostaClient` (not Fake).

### STOP — human secret required

Add to production `/home/ubuntu/apps/Sciencestreetlab_Backend/.env`:

```
BOSTA_API_KEY=<your official Bosta API key>
```

Do **not** leave it empty. Then recreate backend + queue so `env_file` reinjects:

```bash
cd ~/apps/Sciencestreetlab_Backend
docker compose up -d --no-deps --force-recreate backend queue
docker compose exec -T backend php artisan optimize:clear
```

Until that is done, production cannot authenticate to Bosta or create deliveries.

## Bosta Authentication

Production auth check was **not** executed against Bosta because `BOSTA_API_KEY` is missing.

| Field | Value |
| --- | --- |
| PRODUCTION_BOSTA_AUTH_CHECK | FAIL (blocked: key missing) |
| BOSTA_AUTH_HTTP_STATUS | N/A (no request sent) |
| CITIES_RESPONSE_VALID | NO (not attempted) |

Local auth previously PASSED (cities HTTP 200). Production must re-check after key is added.

## Bosta Webhook

| Item | Status |
| --- | --- |
| Public URL | `https://app.sciencestreetlab.com/api/v1/webhooks/bosta` |
| Route reachable | YES |
| Unsigned POST | **401** `{"message":"Unauthorized"}` (expected) |
| Configured header name | `Authorization` |
| Header value | Use the same secret configured in production `BOSTA_WEBHOOK_SECRET` (do not print) |
| Dashboard registration | **NEEDS_HUMAN_CONFIGURATION** — cannot be verified from here |

### Human action — Bosta dashboard

1. Settings → API Integration → Set Up Your Webhook
2. Webhook URL: `https://app.sciencestreetlab.com/api/v1/webhooks/bosta`
3. Custom header name: `Authorization`
4. Custom header value: same as production `BOSTA_WEBHOOK_SECRET`

## Fawaterak

| Item | Status |
| --- | --- |
| FAWATERAK_CONFIGURED | YES |
| FAWATERAK_MODE | LIVE (`https://app.fawaterk.com`) |
| PAYMENT_GATEWAY | fawaterak |
| Webhook URL | `https://app.sciencestreetlab.com/api/v1/payments/fawaterak/webhook` |
| Route | POST reachable (empty body → 400 missing invoice_id; GET → 405) |

Deployed code includes:

- HMAC webhook signature validation
- `getInvoiceData` re-query on return (and preferred on webhook when configured)
- invoice/payment ownership checks
- amount verification
- currency verification
- `PaymentCompletionService` row locking / completed short-circuit

Browser success redirect alone cannot mark paid.

## Post-Payment Trigger

Verified in deployed code:

| Behavior | Status |
| --- | --- |
| Checkout | `markRequiresDeliveryIfNeeded` only — **no** external Bosta create |
| Kit/bundle | `requires_delivery_fulfillment=true` |
| `OrderPaid` → `CreateBostaShipmentOnOrderPaid` | registered in `CommerceServiceProvider` |
| UNPAID_ORDER_BOSTA_CREATE | NO |
| PAID_PHYSICAL_ORDER_BOSTA_CREATE | YES (after key present) |
| PAID_DIGITAL_ORDER_BOSTA_CREATE | NO |

## COD / Prepaid

`HttpBostaClient` create payload sets `cod: 0` for prepaid Fawaterak orders. Does not use Bosta escrow prepaid feature.

## Idempotency

- Unique `(order_id, provider)` + locks
- Existing `external_shipment_id` → no second create
- Failed placeholder retries same row
- Duplicate Fawaterak webhook / OrderPaid → one create
- Duplicate Delivered webhook → one fulfill

## Queue

`CreateBostaShipmentOnOrderPaid` is **synchronous** (does not implement `ShouldQueue`).

Queue worker is **Up**; failed jobs list empty (`[]`). Worker not required for this listener specifically.

## Address / City

Frontend checkout (`DEFAULT_CITIES`) sends English city **names**:

| Frontend value | Bosta cities name match |
| --- | --- |
| Cairo | YES |
| Alexandria | YES |
| Giza | YES |
| Sharqia | YES |
| Qalyubia | **NO** (Bosta name is `El Kalioubia`) |

Production recent physical orders store `city=Cairo|Alexandria` with `address` present — those resolve.

Controlled E2E must use Cairo / Alexandria / Giza / Sharqia only until Qalyubia mapping is fixed.

## Tests

Local TEST DB only (no RefreshDatabase on production):

**88 passed / 636 assertions** covering BostaOfficialApiIntegrationTest, BostaShippingFulfillmentTest, CheckoutFlowTest, GuestCheckoutTest, OrderShippingStatusApiTest, AuthAccountDataIsolationTest, EnrollmentVerificationTest, CodOrderFulfillmentEnrollmentTest, OrderConfirmationMailTest.

## Health

| Check | Result |
| --- | --- |
| `/api/v1/health` | 200 `status=ok` |
| Frontend `/` | 200 |
| Admin login | 200 |
| Containers | backend/queue/scheduler/nginx Up; mysql healthy |
| 502 | None observed |

## Remaining Human Actions

1. **Add `BOSTA_API_KEY` to production `.env`** and recreate backend + queue.
2. Re-run production read-only `GET /api/v2/cities` auth check (must PASS).
3. Configure Bosta dashboard webhook URL + `Authorization` header = `BOSTA_WEBHOOK_SECRET`.
4. Prefer Cairo/Alexandria/Giza/Sharqia for first controlled E2E (avoid Qalyubia until mapped).
5. Only then create one controlled real order/payment (separate authorized task).

## FINAL VERDICT

**BOSTA_FAWATERAK_PRODUCTION_HAS_BLOCKERS**

Code is deployed and Fawaterak/webhook routes/health look good, but production cannot authenticate to Bosta until `BOSTA_API_KEY` is added manually, and Bosta dashboard webhook registration still needs human configuration. No real order/payment/delivery was created.
