# Sales Channels

## Purpose

Sales Channels (technical domain: **SocialCommerce**) lets non-technical staff connect ScienceStreetLab products to external shopping surfaces and keep listings in sync.

Phase 1 ships the architecture plus readiness for:

- Google Merchant Center
- YouTube Shopping

Future providers (Facebook, Instagram, TikTok) can plug into the same contracts without redesigning admin UX.

## UX Philosophy

The module is designed for **non-technical users**.

Staff should never need to understand OAuth, API keys, webhooks, tokens, Merchant APIs, sync jobs, or technical error codes.

Principles:

- Extremely simple, visually clean, self-explanatory
- Status-driven and action-oriented
- Difficult to misuse
- Every problem answers: what happened, what it affects, what to do next
- Never communicate state with color alone (icons + text + badges)
- Technical configuration lives under **Advanced Settings** only

## Architecture

```
Product Updated
     ↓
ProductSalesChannelSyncRequested (event)
     ↓
QueueConnectedChannelSync (listener)
     ↓
SyncSalesChannelProductsJob (queue)
     ↓
SalesChannelManager
     ↓
SalesChannelProviderInterface adapter
     ↓
External channel (when credentials/API contract ready)
```

Module path: `app/Modules/SocialCommerce/`

Key pieces:

| Layer | Responsibility |
| --- | --- |
| Domain enums | `connection_status`, `sync_status`, `health_status`, `publication_status`, platform |
| Models | `SalesChannelIntegration`, `SalesChannelProduct`, `SalesChannelActivityLog` |
| `SalesChannelProductData` | Provider-neutral product payload |
| `ProductChannelMapper` | Single Product → DTO mapping |
| `ProductReadinessService` | Human checklist before publish |
| `HumanErrorMapper` | Technical codes → staff-friendly copy |
| `ChannelHealthService` | Central health / recommended action |
| `SalesChannelManager` | Connect, sync, logs, dashboard totals |
| Provider adapters | Google / YouTube stubs behind interface |

## Integration Statuses

Do **not** collapse everything into one status.

### connection_status

- `not_connected`
- `connecting`
- `connected`
- `expired`
- `disconnected`

### sync_status

- `never_synced`
- `pending`
- `syncing`
- `synced`
- `partially_synced`
- `failed`

### health_status

- `healthy`
- `needs_attention`
- `unavailable`

Resolved by `ChannelHealthService` — Filament pages must not invent health rules.

## Human-Friendly Error Mapping

`HumanErrorMapper` maps technical codes to translatable title / message / action.

Examples:

| Technical | User title | Action |
| --- | --- | --- |
| `token_refresh_failed` | Connection expired | Reconnect |
| `missing_main_image` | Product needs attention | Fix Product |
| `provider_not_configured` | Channel not ready | Connect (after admin setup) |

Primary UI must never show raw codes like `oauth_failed`, `invalid_grant`, or `merchant_api_403`.

## Provider Architecture

`SalesChannelProviderInterface` methods:

- `platform()`
- `isConfigured()`
- `testConnection()`
- `syncProduct()`
- `syncProducts()`

Implementations:

- `GoogleMerchantProvider`
- `YouTubeShoppingProvider`

Both extend `BlockedProviderAdapter`, which refuses live work until:

1. `enabled` is true
2. client id/secret are present
3. `api_contract_ready` is true

Google/YouTube HTTP contracts and OAuth scopes are **not invented** in this codebase.

## Product Mapping

`ProductChannelMapper` is the only place that maps catalog products into `SalesChannelProductData`:

- title, description, price, currency
- availability, url, main_image, additional_images
- brand, sku, category, condition

Providers must consume the DTO — do not re-map inside adapters.

## Product Readiness

`ProductReadinessService` produces a checklist (required vs optional):

- Product name
- Price
- Product URL
- Main image
- Availability
- Brand (optional warning)

Staff see **Needs Attention** with Fix Product — not API errors.

## Background Sync

- `SyncSalesChannelProductsJob` is queued, retryable, and updates integration + product statuses
- `SalesChannelManager::requestSync()` uses a cache lock to prevent duplicate concurrent syncs
- Filament never calls external APIs in the request lifecycle

Automatic path: product save → `ProductSalesChannelSyncRequested` → listener queues sync for connected channels.

## Product-Level Status

Table `sales_channel_products` stores per-product publication and sync state with a unique `(product_id, integration_id)` constraint.

## Admin UX

Filament page: **Sales Channels** (`ManageSalesChannels`) under Commerce.

Landing page:

1. KPI summary — Connected / Synced / Needs Attention
2. Large channel cards with status + actions
3. Simplified activity feed

Connecting uses a 4-step wizard (progress: ✓ / ● / ○).

**Advanced Settings** is secondary and permission-gated; secrets are never redisplayed after save.

Product edit form includes a **Sales Channels** section (Website + each channel).

## Permissions

| Permission | Purpose |
| --- | --- |
| `sales_channels.view` | Open dashboard |
| `sales_channels.manage` | Manage / fix issues |
| `sales_channels.connect` | Connect / reconnect wizard |
| `sales_channels.sync` | Sync Now |
| `sales_channels.view_technical_logs` | Advanced settings / technical context |

`super_admin` receives all. `content_manager` gets view + sync only (no credentials).

## Localization

- English: `lang/en/sales_channels.php`
- Arabic: `lang/ar/sales_channels.php`
- Admin views set `dir` for RTL
- Do not translate external technical identifiers stored in the database

## Security

- Credentials cast as `encrypted:array` and `$hidden` on the model
- Env-based client secrets (`config/sales_channels.php`) — not editable in normal UI
- Advanced settings limited to technical permission / super_admin
- No complete secrets shown after save

## Google Merchant Readiness

Implemented:

- Integration row, statuses, health, readiness, mapper, job, admin card/wizard
- Provider adapter boundary

Blocked until external prerequisites:

- Official Google Cloud OAuth client
- Confirmed Merchant Center account + eligibility
- Confirmed Content API / Merchant API contract and scopes
- `SALES_CHANNEL_GOOGLE_*` env flags

## YouTube Shopping Readiness

Implemented: same architecture as Google Merchant via `YouTubeShoppingProvider`.

Blocked until:

- Confirmed YouTube Shopping / affiliate onboarding path for this account
- Eligibility for the intended Merchant ↔ YouTube linkage
- Official API/docs for the chosen path
- `SALES_CHANNEL_YOUTUBE_*` env flags

Do **not** assume ScienceStreetLab is eligible for a specific YouTube Shopping onboarding path.

## External Prerequisites

Still required before live publish:

1. Google OAuth client ID + secret (and any Merchant-specific credentials)
2. Google Merchant Center account ID / access
3. Documented OAuth scopes and API endpoints from Google
4. Confirmation of YouTube Shopping eligibility and onboarding docs
5. Set in `.env`:

```env
SALES_CHANNEL_GOOGLE_MERCHANT_ENABLED=true
SALES_CHANNEL_GOOGLE_CLIENT_ID=...
SALES_CHANNEL_GOOGLE_CLIENT_SECRET=...
SALES_CHANNEL_GOOGLE_API_CONTRACT_READY=true

SALES_CHANNEL_YOUTUBE_ENABLED=true
SALES_CHANNEL_YOUTUBE_API_CONTRACT_READY=true
```

Flip `api_contract_ready` only after the real contract is implemented in the adapter.

## Testing

Focused suite: `tests/Feature/SocialCommerce/SalesChannelsTest.php`

Covers defaults, human statuses, reconnect mapping, syncing/failed health, successful sync, hidden credentials, permissions, duplicate mappings, readiness, error mapping, Arabic labels, product mapping, duplicate sync lock.

Run:

```bash
php artisan test --filter=SalesChannelsTest
php artisan test
```

## Future Providers

Add a platform enum case (if needed), config block, adapter implementing `SalesChannelProviderInterface`, register it in `SocialCommerceServiceProvider`, and ensureDefaults / UI will pick it up without changing health or mapping cores.
