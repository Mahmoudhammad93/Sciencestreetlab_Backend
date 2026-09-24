# EGYPT PLATFORM IMPLEMENTATION REPORT

**Target:** Egypt platform testing — October 1, 2026  
**Scope:** Backend only (Bosta, WordPress migration framework, Image+Written, Drag & Drop)  
**Commit/Push:** Not done — awaiting review

---

## 1. Bosta Shipping Integration

**Status:** PARTIAL

### Implemented

- Isolated Bosta boundary under Commerce (`BostaClientInterface`, Fake/Http clients, webhook verifier abstraction)
- `shipments` persistence + order flags `requires_delivery_fulfillment`, `fulfilled_at`
- Idempotent shipment creation at checkout for kit/bundle when `BOSTA_ENABLED`
- Webhook `POST /api/v1/webhooks/bosta`
- New domain event `OrderFulfilled` (separate from `OrderPaid`)
- Course unlock **only** on Bosta `DELIVERED` for gated orders
- Admin `Shipped` on Bosta orders does **not** enroll
- Online/digital (non-Bosta) still fulfills on payment via `OrderFulfilled`
- Confirmation/activation email moved to `OrderFulfilled` (idempotent claim)

### Files created

- `config/bosta.php`
- `app/Modules/Commerce/Domain/Enums/ShipmentStatus.php`
- `app/Modules/Commerce/Domain/Enums/ShipmentProvider.php`
- `app/Modules/Commerce/Domain/Events/OrderFulfilled.php`
- `app/Modules/Commerce/Domain/Events/ShipmentDelivered.php`
- `app/Modules/Commerce/Domain/Contracts/BostaClientInterface.php`
- `app/Modules/Commerce/Domain/Contracts/BostaWebhookVerifierInterface.php`
- `app/Modules/Commerce/Application/Services/BostaShipmentService.php`
- `app/Modules/Commerce/Application/Services/BostaWebhookService.php`
- `app/Modules/Commerce/Http/Controllers/Api/BostaWebhookController.php`
- `app/Modules/Commerce/Infrastructure/Persistence/Models/Shipment.php`
- `app/Modules/Commerce/Infrastructure/Persistence/Migrations/2026_10_01_100001_create_shipments_table.php`
- `app/Modules/Commerce/Infrastructure/Persistence/Migrations/2026_10_01_100002_add_fulfillment_columns_to_orders_table.php`
- `app/Modules/Commerce/Infrastructure/Shipping/Bosta/FakeBostaClient.php`
- `app/Modules/Commerce/Infrastructure/Shipping/Bosta/HttpBostaClient.php`
- `app/Modules/Commerce/Infrastructure/Shipping/Bosta/FakeBostaWebhookVerifier.php`
- `app/Modules/Commerce/Infrastructure/Shipping/Bosta/ConfiguredBostaWebhookVerifier.php`
- `app/Modules/Commerce/Infrastructure/Shipping/Bosta/BostaStatusMapper.php`
- `app/Modules/Learning/Application/Listeners/GrantEnrollmentOnOrderFulfilled.php`
- `tests/Feature/BostaShippingFulfillmentTest.php`

### Files modified

- `app/Modules/Commerce/Application/Services/OrderFulfillmentService.php`
- `app/Modules/Commerce/Application/Services/CheckoutService.php`
- `app/Modules/Commerce/Application/Listeners/SendOrderConfirmationEmail.php`
- `app/Modules/Commerce/Infrastructure/Persistence/Models/Order.php`
- `app/Modules/Commerce/Infrastructure/Providers/CommerceServiceProvider.php`
- `app/Modules/Commerce/Routes/api.php`
- `app/Modules/Learning/Infrastructure/Providers/LearningServiceProvider.php`
- `app/Modules/Learning/Application/Listeners/GrantEnrollmentOnOrderPaid.php` (no-op; enrollment moved)
- `.env.example`
- `tests/Feature/OrderConfirmationMailTest.php`

### Migrations

- `shipments` table
- `orders.fulfilled_at`, `orders.requires_delivery_fulfillment`

### Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/v1/webhooks/bosta` | Bosta shipment status webhooks |

### Tests

- `tests/Feature/BostaShippingFulfillmentTest.php`
  - Idempotent shipment creation on kit checkout
  - Online payment does not enroll until DELIVERED
  - Admin shipped does not unlock Bosta orders
  - Duplicate DELIVERED webhook idempotency
  - Invalid webhook secret rejected
  - Non-Bosta course product still enrolls on payment

### Course activation flow

```
Checkout
  → Order created
  → Bosta shipment created (idempotent)
  → (optional online payment) OrderPaid — accounting only; no enroll
  → Webhook status updates (created / picked up / in transit / …)
  → DELIVERED
  → OrderFulfillmentService::fulfillFromBostaDelivery
  → OrderFulfilled
  → GrantEnrollmentOnOrderFulfilled
  → EnrollUserService (+ CoursePlan entitlement snapshot)
  → SendOrderConfirmationEmail (once)
```

### Idempotency

- One shipment per `(order_id, provider)` unique constraint
- `paid_at` / `fulfilled_at` gates prevent re-dispatch of `OrderPaid` / `OrderFulfilled`
- Email claim via `confirmation_email_sent_at` / `confirmation_email_claimed_at`
- Duplicate DELIVERED webhooks do not re-enroll or re-mail
- Shipment status never downgrades from `delivered`

### Blocked items

Marked `BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS`:

- Real Bosta create-shipment HTTP URL and payload contract
- Production webhook signature algorithm

**Need from ops:**

- `BOSTA_API_URL`
- `BOSTA_API_KEY`
- `BOSTA_WEBHOOK_SECRET`
- Official API + webhook signature documentation  
  Then set `BOSTA_API_CONTRACT_READY=true` and `BOSTA_WEBHOOK_SIGNATURE_READY=true` after implementing the real mapping.

---

## 2. WordPress Migration

**Status:** PARTIAL

### Implemented

- Migration module + `legacy_import_maps` table
- Optional `wordpress` DB connection (`WORDPRESS_DB_*`)
- Connection inspect + blocked importers (users / courses / enrollments / orders)
- Silent helpers for users/orders (no WP password hashes; no commerce events)
- Audit with optional JSON/CSV export
- Dry-run supported on all import commands

### Commands

```bash
php artisan migration:wordpress:inspect
php artisan migration:wordpress:users {--dry-run}
php artisan migration:wordpress:courses {--dry-run}
php artisan migration:wordpress:enrollments {--dry-run}
php artisan migration:wordpress:orders {--dry-run}
php artisan migration:wordpress:audit {--json=} {--csv=}
```

### Imported entities (framework ready)

| Entity | Dry-run | Live source SQL | Silent helper |
|--------|---------|-----------------|---------------|
| Users | YES | BLOCKED until dump | `importUserSilently` |
| Courses | YES | BLOCKED until dump | — |
| Enrollments | YES | BLOCKED until dump | — |
| Transactions/Orders | YES | BLOCKED until dump | `importHistoricalOrderSilently` |

**Dry-run support:** YES  
**Idempotent:** YES (via `legacy_import_maps` unique on `source + entity_type + legacy_id`)

### Integrity audit

`WordPressAuditService` reports:

- Map counts per entity type
- Local table counts (users / courses / enrollments / orders)
- Duplicate emails in user maps
- Orphan enrollments (missing user/course map references)
- Optional JSON / CSV write

### Password strategy

WordPress password hashes are **never** copied into `users.password`.  
Imported users get a random unusable password + metadata `password_strategy=reset_required` (must reset / OTP).

### Historical orders

`importHistoricalOrderSilently`:

- Sets `historical_import` in notes/metadata
- Sets `paid_at` / `fulfilled_at` without calling `OrderFulfillmentService`
- Uses `Order::withoutEvents` — does **not** fire `OrderPaid` / `OrderFulfilled`
- Does **not** create Bosta shipments or send mail

### Blocked items

`BLOCKED_UNTIL_WORDPRESS_DB_DUMP`:

- LearnDash / WooCommerce table → column mappings
- Full live import of source rows

**Need:** staging WordPress DB dump + schema/access (`WORDPRESS_DB_HOST`, `WORDPRESS_DB_DATABASE`, `WORDPRESS_DB_USERNAME`, `WORDPRESS_DB_PASSWORD`, `WORDPRESS_DB_PREFIX`)

### Key files

- `config/wordpress.php`
- `config/database.php` (`wordpress` connection)
- `app/Modules/Migration/**`
- `app/Console/Commands/MigrateWordPress/**`
- `tests/Feature/WordPressMigrationFrameworkTest.php`

---

## 3A. Image + Written Description

**Status:** DONE

### Implemented

- Spatie Media Library collection `question_image` on `Question`
- MIME validation: JPEG / PNG / WebP / GIF; Filament max 5MB
- Public/API-safe URL via `imageUrl()` — no filesystem paths
- Existing LongAnswer flow unchanged (submit → PendingReview → manual grade → score / progress)

### Admin

- Filament `QuestionResource`: Spatie upload + image preview
- Visible for LongAnswer (also ShortAnswer / DragDrop)
- Editing preserves existing image unless replaced/removed

### API

Student question payload includes:

```json
{
  "id": 10,
  "question_type": "long_answer",
  "body": "...",
  "image_url": "https://…/storage/…",
  "points": 10
}
```

`image_url` is `null` when no image (existing questions unbroken).

### Student submission

Unchanged text answer:

```json
{ "answer": { "text": "..." } }
```

### Manual review

Existing `ManualQuizReviewService` + Filament written reviews.

### Tests

`tests/Feature/Assessment/LongAnswerImageAndDragDropTest.php`:

- Long answer without image → PendingReview
- Long answer with image → public URL + manual grading + final score

---

## 3B. Drag & Drop Question

**Status:** DONE

### Implemented

- First-class `QuestionType::DragDrop` (`drag_drop`)
- Server config in `answer_key`: `items`, `zones`, `correct_mappings`
- `DragDropGrader` registered in `QuestionGraderRegistry`
- Student submit via `answer.mappings` → stored as `matching_answer`
- Filament repeaters for items, drop zones, correct mappings

### Admin builder

- Draggable items (key + AR/EN labels)
- Drop zones (key + AR/EN labels)
- Correct mappings (item_key → zone_key)
- Points / prompt / optional image
- Invalid mappings to nonexistent keys are dropped on save

### Student API

```json
{
  "question_type": "drag_drop",
  "body": "...",
  "items": [{ "key": "item_1", "label": "Nucleus" }],
  "drop_zones": [{ "key": "zone_a", "label": "Control" }],
  "image_url": null
}
```

### Backend grading

- Validates item/zone keys
- Rejects partial / invalid / duplicate / extra mappings
- Compares against `correct_mappings`
- Awards points via existing quiz attempt pipeline

### Security

- `answer_key` remains `$hidden` on the model
- Student resource never returns `correct_mappings` / `answer_key`
- Matching option `match_key` also stripped from public option meta

### Tests

- Correct / incorrect / partial / invalid item / invalid zone
- Answer key not exposed
- Quiz total score on correct submit

---

## TEST RESULTS

| Metric | Value |
|--------|-------|
| Total tests | **206** |
| Passed | **206** |
| Failed | **0** |
| Skipped | **0** |

**New implementation failures:** none  

**Pre-existing failures:** none observed in this run

Focused suites exercised: Bosta, COD fulfillment, order confirmation mail, enrollment verification, checkout flow, WordPress migration, LongAnswer image, DragDrop, manual review.

---

## EXTERNAL REQUIREMENTS

### Bosta

- [ ] API credentials (`BOSTA_API_KEY`)
- [ ] API base URL (`BOSTA_API_URL`)
- [ ] Webhook secret (`BOSTA_WEBHOOK_SECRET`)
- [ ] Official create-shipment API documentation
- [ ] Official webhook signature documentation

### WordPress

- [ ] Staging DB dump or live read-only credentials
- [ ] Schema / access for LearnDash + WooCommerce tables
- [ ] Confirm password-reset / OTP UX for migrated users

### Anything else

- [ ] Confirm Egypt checkout should set `BOSTA_ENABLED=true` in staging
- [ ] After real Bosta mapping: implement `HttpBostaClient` + `ConfiguredBostaWebhookVerifier`, flip `BOSTA_API_CONTRACT_READY` / `BOSTA_WEBHOOK_SIGNATURE_READY`

---

## FRONTEND REQUIREMENTS

### Bosta tracking

- Webhook is server-only; no FE change required for course unlock.
- Optional later: show `tracking_number` / `tracking_url` on order detail if exposed by order API.

### Image written question

**Response field added:**

| Field | Type | Notes |
|-------|------|-------|
| `image_url` | `string \| null` | Public media URL; null if no image |

**Submit:** unchanged `{ "answer": { "text": "..." } }`

### Drag & drop question

**New `question_type`:** `drag_drop`

**Student GET (render only):**

```json
{
  "id": 12,
  "question_type": "drag_drop",
  "body": "Match the organelles",
  "points": 5,
  "items": [
    { "key": "item_1", "label": "Nucleus" },
    { "key": "item_2", "label": "Membrane" }
  ],
  "drop_zones": [
    { "key": "zone_a", "label": "Control" },
    { "key": "zone_b", "label": "Boundary" }
  ],
  "image_url": null
}
```

**Do not expect in student payload:** `answer_key`, `correct_mappings`, `correct_mapping`

**Student submit:**

```json
{
  "question_id": 12,
  "answer": {
    "mappings": {
      "item_1": "zone_a",
      "item_2": "zone_b"
    }
  }
}
```

---

## FINAL STATUS

| Task | Status | Backend Complete | Needs External Data | Needs Frontend |
|------|--------|------------------|---------------------|----------------|
| Bosta | PARTIAL | Yes (boundary + DELIVERED flow) | Yes (creds/docs) | Optional tracking UI |
| WordPress Migration | PARTIAL | Yes (framework) | Yes (DB dump) | No |
| Image Written Question | DONE | Yes | No | Yes (`image_url`) |
| Drag & Drop | DONE | Yes | No | Yes (new type UI) |

---

## Config reference (`.env.example`)

```env
# Bosta shipping (Egypt)
BOSTA_ENABLED=false
BOSTA_API_URL=
BOSTA_API_KEY=
BOSTA_WEBHOOK_SECRET=
BOSTA_USE_FAKE=false
BOSTA_API_CONTRACT_READY=false
BOSTA_WEBHOOK_SIGNATURE_READY=false

# WordPress legacy DB
WORDPRESS_DB_HOST=
WORDPRESS_DB_PORT=3306
WORDPRESS_DB_DATABASE=
WORDPRESS_DB_USERNAME=
WORDPRESS_DB_PASSWORD=
WORDPRESS_DB_PREFIX=wp_
```

---

*Generated after Egypt tasks implementation. No commit/push performed.*
