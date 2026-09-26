# Product Reviews

## Overview

Authenticated customers can submit product reviews (1–5 stars + text). Reviews start as **pending** and become publicly visible only after an admin approves them in Filament.

## Review Lifecycle

```
pending → approved
        ↘ rejected
```

- New reviews always start as `pending`.
- Clients cannot set `status`, `approved_at`, or `approved_by`.
- Public Product Details API returns **approved reviews only**.

## Database

Table: `product_reviews`

| Column | Notes |
|--------|--------|
| `product_id` | FK → products |
| `user_id` | FK → users |
| `rating` | 1–5 |
| `review` | text |
| `status` | `pending` \| `approved` \| `rejected` |
| `approved_at` | set on approve |
| `approved_by` | admin user id |

Unique constraint: `(product_id, user_id)` — **one review per user per product**.

## Submission API

```http
POST /api/v1/products/{slug}/reviews
Authorization: Bearer {sanctum}
```

```json
{ "rating": 5, "review": "Excellent science kit." }
```

Response `201` includes pending status and a moderation message.

## Public Product API

`GET /api/v1/products/{slug}` includes:

- `reviews` — approved only (safe fields: id, user.name, rating, review, created_at)
- `reviews_summary.average_rating`
- `reviews_summary.reviews_count`

List endpoint omits the full `reviews` array but still exposes denormalized rating counters on the product.

## Approved-Only Rule

Pending and rejected reviews are filtered **server-side** in `ProductPresenter` via `approvedReviews`. Frontend must not rely on client-side status filtering for security.

## Rating Calculation

`ProductReviewService::recalculateProductRatings` updates `products.average_rating` and `products.review_count` from **approved** reviews only after approve/reject/delete.

## Admin Moderation

Filament resource: **Catalog → Product reviews**

Actions: Approve, Reject, Delete  
Filters: Status, Product, Rating

## Authorization

- Submit: Sanctum authenticated user
- Moderate: existing Filament panel roles (`canAccessPanel`)
- No separate auth system

## Frontend Integration

Single Product page renders `ProductReviewsSection`:

- Shows approved reviews + summary from Product Details API
- Logged-in users can submit a review
- Pending reviews are **not** appended to the public list after submit

## Validation

- `rating`: required, integer, 1–5
- `review`: required, string, 3–2000 chars
- Duplicate `(product_id, user_id)` rejected

## Tests

`tests/Feature/Catalog/ProductReviewApiTest.php`

## Current Limitations

- Verified purchase is **not** required (not specified in requirements).
- No edit/update of an existing review by the customer.
- Re-approval after reject is supported via admin Approve action.
