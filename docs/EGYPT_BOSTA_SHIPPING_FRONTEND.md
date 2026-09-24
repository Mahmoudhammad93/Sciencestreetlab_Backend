# Egypt Shipping Tracker — Frontend Integration

Practical guide for rendering the customer shipping progress tracker from the ScienceStreetLab order API.

Related backend docs: [`EGYPT_BOSTA_SHIPPING.md`](EGYPT_BOSTA_SHIPPING.md)

---

## API

```http
GET /api/v1/orders/{orderNumber}
GET /api/v1/orders
```

**Authentication:** existing Sanctum Bearer token (`Authorization: Bearer …`).

**Authorization:** the authenticated user may only see their own orders. Another user’s `orderNumber` returns `404`.

Optional: `Accept-Language: en` or `Accept-Language: ar` for localized `status_label` and `steps[].label`.

---

## Response Contract

Orders that require Bosta delivery include `shipping.required = true`.

Real shape (example: `in_transit`):

```json
{
  "data": {
    "order_number": "ORD-…",
    "shipping": {
      "required": true,
      "provider": "bosta",
      "status": "in_transit",
      "status_label": "In Transit",
      "tracking_number": "fake-track-123",
      "tracking_url": "https://bosta.example/track/…",
      "shipped_at": "2026-09-24T10:00:00+00:00",
      "delivered_at": null,
      "course_access": {
        "status": "locked",
        "unlocks_on": "delivered",
        "message": "Course access unlocks when the shipment is delivered."
      },
      "progress": {
        "current_step": 4,
        "total_steps": 7,
        "percentage": 43,
        "halted": false,
        "halt_reason": null
      },
      "steps": [
        { "key": "order_placed", "label": "Order Placed", "status": "completed", "completed_at": "…" },
        { "key": "shipment_created", "label": "Shipment Created", "status": "completed", "completed_at": "…" },
        { "key": "picked_up", "label": "Picked Up", "status": "completed", "completed_at": "…" },
        { "key": "in_transit", "label": "In Transit", "status": "current", "completed_at": null },
        { "key": "out_for_delivery", "label": "Out for Delivery", "status": "pending", "completed_at": null },
        { "key": "delivered", "label": "Delivered", "status": "pending", "completed_at": null },
        { "key": "course_activated", "label": "Course Activated", "status": "pending", "completed_at": null }
      ]
    }
  }
}
```

When `shipping.required === false` (digital / non-gated order), `steps` is empty and `progress` is `null`. Hide the tracker.

Do **not** expect customer responses to include `provider_status`, `metadata`, webhook payloads, or credentials.

---

## Tracker Rendering

Iterate `shipping.steps`. Do **not** hardcode progression rules from `shipping.status`.

```tsx
{shipping.required && shipping.steps.map((step) => {
  switch (step.status) {
    case 'completed':
      return <Step key={step.key} icon="✓" label={step.label} />;
    case 'current':
      return <Step key={step.key} icon="●" label={step.label} active />;
    case 'failed':
      return <Step key={step.key} icon="!" label={step.label} error />;
    case 'pending':
    default:
      return <Step key={step.key} icon="○" label={step.label} muted />;
  }
})}
```

| `steps[].status` | UI meaning |
|------------------|------------|
| `completed` | ✓ done |
| `current` | ● active / highlighted (matches current shipment state) |
| `pending` | ○ future |
| `failed` | error / halted (cancelled or failed delivery) |

`completed_at` is only set when the backend can prove a timestamp from persisted data. Do not invent timestamps on the client.

---

## Progress Bar

Use:

```ts
shipping.progress.percentage
shipping.progress.current_step
shipping.progress.total_steps
```

Do **not** recalculate percentage from step counts on the frontend.

If `shipping.progress.halted === true`, show an error/halted state using `halt_reason` (`cancelled` | `failed`) and keep course locked messaging.

---

## Tracking Button

Show **Track Shipment** only when:

```ts
shipping.tracking_url !== null
```

Open `shipping.tracking_url` (new tab). Optionally display `shipping.tracking_number` as secondary text.

**Never** construct a Bosta tracking URL on the frontend.

---

## Course Access UI

Use `shipping.course_access.status` only — **not** `order.status`.

Suggested copy (or use `course_access.message` from the API):

| `course_access.status` | Suggested UI |
|------------------------|--------------|
| `locked` | “Your course will be available after your kit is delivered.” |
| `processing` | “Your kit has been delivered. We are activating your course.” |
| `active` | “Your course is ready.” |

Then use enrollment / course APIs to open the learning experience when access is actually granted.

**Frontend MUST NOT** unlock course screens because the order is Paid or Shipped.

---

## Error States

| `shipping.status` | Behavior |
|-------------------|----------|
| `cancelled` | Tracker halted; a step may be `failed`; course stays locked |
| `failed` | Delivery problem; course stays locked |
| `unknown` | Status updating; do not treat as delivered; course stays locked |

Show `shipping.status_label` for a human-readable summary.

---

## Arabic / English

Prefer labels returned by the backend:

- `shipping.status_label`
- `shipping.steps[].label`
- `shipping.course_access.message`

Send `Accept-Language: ar` or `en`. Do not duplicate shipping translation tables on the frontend unless product copy needs custom marketing text.

---

## Suggested UI

### Desktop

```
Order Tracking

✓ Order Placed
│
✓ Shipment Created
│
✓ Picked Up
│
● In Transit
│
○ Out for Delivery
│
○ Delivered
│
○ Course Activated
```

### Mobile

Same vertical step list. Keep one column; avoid horizontal steppers that hide labels.

Optional progress bar above the list using `shipping.progress.percentage`.

---

## Example States

### created

```
✓ Order Placed
● Shipment Created
○ Picked Up
○ In Transit
○ Out for Delivery
○ Delivered
○ Course Activated
```

`current_step = 2`, `course_access.status = locked`

### picked_up

```
✓ Order Placed
✓ Shipment Created
● Picked Up
○ In Transit
○ Out for Delivery
○ Delivered
○ Course Activated
```

`current_step = 3`, locked

### in_transit

```
✓ Order Placed
✓ Shipment Created
✓ Picked Up
● In Transit
○ Out for Delivery
○ Delivered
○ Course Activated
```

`current_step = 4`, locked

### out_for_delivery

```
✓ Order Placed
✓ Shipment Created
✓ Picked Up
✓ In Transit
● Out for Delivery
○ Delivered
○ Course Activated
```

`current_step = 5`, locked

### delivered / processing

```
✓ Order Placed
✓ Shipment Created
✓ Picked Up
✓ In Transit
✓ Out for Delivery
✓ Delivered
● Course Activated
```

`course_access.status = processing`

### active (fulfilled)

```
✓ Order Placed
✓ Shipment Created
✓ Picked Up
✓ In Transit
✓ Out for Delivery
✓ Delivered
✓ Course Activated
```

`course_access.status = active`, `percentage = 100`

### cancelled

Halted tracker; `progress.halted = true`, `halt_reason = cancelled`, course locked. Show cancellation messaging; do not offer course access.

### failed

Halted tracker; `halt_reason = failed`, course locked. Offer support CTA if product requires it.

---

## Checklist for FE PR

- [ ] Renders only from `shipping.steps` (+ optional progress bar)
- [ ] Track button only if `tracking_url` present
- [ ] Course gate uses `course_access.status` (+ enrollment API)
- [ ] Handles `halted` / `failed` / `unknown`
- [ ] Respects `Accept-Language` labels from API
- [ ] No fabricated Bosta URLs or unlock rules
