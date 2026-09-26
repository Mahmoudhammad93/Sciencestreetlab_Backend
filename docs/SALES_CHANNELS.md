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
| `provider_not_configured` | Setup required | Setup Channel |
| `merchant_access_denied` | Couldn't connect to Merchant Center | Try Again |
| `invalid_service_account` | Invalid service account | Try Again |

Primary UI must never show raw codes like `oauth_failed`, `invalid_grant`, or `merchant_api_403`.

## Provider Architecture

`SalesChannelProviderInterface` methods:

- `platform()`
- `isConfigured()`
- `testConnection()`
- `syncProduct()`
- `syncProducts()`

Implementations:

- `GoogleMerchantProvider` — live Merchant API (Accounts, Data Sources, Product Inputs)
- `YouTubeShoppingProvider` — readiness / dependency only (no fabricated YouTube product API)

HTTP and auth live under `Infrastructure/Google/` (`GoogleServiceAccountTokenProvider`, `GoogleMerchantApiClient`, `ServiceAccountCredentialParser`).

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

Products that fail readiness are **never** submitted to Merchant API.

## Background Sync

- `SyncSalesChannelProductsJob` is queued, retryable, and updates integration + product statuses
- `SalesChannelManager::requestSync()` uses a cache lock to prevent duplicate concurrent syncs
- Connection testing runs server-side from the Super Admin setup wizard (not from normal staff actions)

Automatic path: product save → `ProductSalesChannelSyncRequested` → listener queues sync for connected channels.

## Product-Level Status

Table `sales_channel_products` stores per-product publication and sync state with a unique `(product_id, integration_id)` constraint.

## Admin UX

Filament page: **Sales Channels** (`ManageSalesChannels`) under Commerce.

Landing page:

1. KPI summary — Connected / Synced / Needs Attention
2. Large channel cards with status + actions
3. Simplified activity feed

### Google Merchant card states

| State | Badge | Primary action (Super Admin) |
| --- | --- | --- |
| A Setup Required | ⚠ Setup Required | Setup Channel |
| B Ready to connect | ○ Not Connected | Test & Connect |
| C Connected | ✓ Connected | Sync Now / Manage |
| D Needs Attention | ⚠ Needs Attention | Fix Connection |

Normal staff never see service-account JSON, private keys, API endpoints, or Google exception bodies.

### Super Admin setup wizard (5 steps)

1. Merchant Center Account ID
2. Service account JSON (encrypted at rest; never redisplayed)
3. Test Connection (real `accounts.get` + ensure API data source)
4. Product Readiness summary
5. Activate Google Shopping

**Advanced Settings** remains secondary and permission-gated.

Product edit form includes a **Sales Channels** section (Website + each channel).

## Permissions

| Permission | Purpose |
| --- | --- |
| `sales_channels.view` | Open dashboard |
| `sales_channels.manage` | Manage / fix product issues |
| `sales_channels.connect` | Legacy connect permission (Google setup uses technical) |
| `sales_channels.sync` | Sync Now |
| `sales_channels.view_technical_logs` | Credential setup wizard + advanced settings |

`super_admin` receives all. `content_manager` gets view + sync only (no credentials).

## Localization

- English: `lang/en/sales_channels.php`
- Arabic: `lang/ar/sales_channels.php`
- Admin views set `dir` for RTL
- Do not translate external technical identifiers stored in the database

## Security

- Service-account credentials cast as `encrypted:array` and `$hidden` on the model
- Never returned from API resources or Livewire public props after save
- Never printed in logs (sanitized codes only)
- Never placed in frontend JavaScript or public storage
- Never committed to the repository
- Public metadata for technical admins: `client_email` / `project_id` only (no `private_key`)

## Google Merchant Production Setup

ScienceStreetLab manages its **own** Merchant Center account (in-house), not arbitrary customer accounts.

### Authentication architecture

```
Google Cloud Project
       ↓
Merchant API enabled
       ↓
Service Account
       ↓
Service Account granted access in Merchant Center
       ↓
ScienceStreetLab backend (encrypted credentials)
       ↓
Merchant API (JWT → OAuth token, scope auth/content)
```

Do **not** use API keys for Merchant API authentication.

Official pieces used by this codebase:

| Piece | Value |
| --- | --- |
| OAuth scope | `https://www.googleapis.com/auth/content` |
| Token URI | `https://oauth2.googleapis.com/token` |
| Accounts API | `https://merchantapi.googleapis.com/accounts/v1` |
| Products API | `https://merchantapi.googleapis.com/products/v1` |
| Data Sources API | `https://merchantapi.googleapis.com/datasources/v1` |
| Connection test | `accounts.get` |
| Product sync | `productInputs.insert` into an API primary data source |

### Steps for the Super Admin (Mahmoud)

1. **Google Cloud**
   - Create/select a Cloud project for ScienceStreetLab
   - Enable **Merchant API**
   - Create a **service account**
   - Download the JSON key (keep offline; do not commit)
2. **Merchant Center**
   - Note the **Merchant Center Account ID** (top-right / Settings)
   - Users → add the service account email → Admin (or access sufficient for Accounts + Products + Data Sources)
3. **ScienceStreetLab admin**
   - Open Sales Channels → Google Merchant → **Setup Channel**
   - Enter Merchant ID → paste service-account JSON → **Test Connection**
   - Review product readiness → **Activate Google Shopping**

### Credential security

Credentials are stored only in `sales_channel_integrations.credentials` (`encrypted:array`). After save, the wizard clears the textarea and never redisplays `private_key`.

### Test Connection

Success → `connection_status = connected`, activity: “Google Merchant Center connected”.

Failure → human-friendly “We couldn't connect…” (access denied / invalid merchant / invalid credentials). Technical codes stay in logs/DB for authorized users only.

### Product sync

```
Product → ProductReadinessService → ProductChannelMapper → GoogleMerchantProvider → Merchant API → SalesChannelProduct
```

Optional env defaults (not secrets):

```env
SALES_CHANNEL_GOOGLE_CONTENT_LANGUAGE=en
SALES_CHANNEL_GOOGLE_FEED_LABEL=EG
SALES_CHANNEL_GOOGLE_PRIMARY_COUNTRY=EG
SALES_CHANNEL_GOOGLE_DATA_SOURCE_NAME="ScienceStreetLab API Primary"
```

### Troubleshooting

| Symptom | Check |
| --- | --- |
| Setup Required | Merchant ID + service account not saved yet |
| Invalid service account | JSON type must be `service_account` with usable private key |
| Couldn't connect / access | Service account email must be a Merchant Center user |
| Merchant not found | Confirm Account ID digits |
| Products Need Attention | Fix catalog fields (image, price, title) before sync |

## YouTube Shopping Dependency

Merchant product sync and YouTube Shopping channel/store onboarding are **separate**.

- Connecting Google Merchant does **not** mark YouTube as Connected
- This app does **not** invent a direct YouTube Shopping product-publish API
- YouTube card states:
  - Before Merchant: “Google Merchant setup required”
  - After Merchant: “Ready for eligibility check”
- Eligibility / store linkage flags (ops-confirmed only):

```env
SALES_CHANNEL_YOUTUBE_ELIGIBILITY_CONFIRMED=false
SALES_CHANNEL_YOUTUBE_STORE_LINKED=false
```

Flip these only after YouTube Studio / official onboarding confirms eligibility and store linkage. Never claim eligibility without that confirmation.

### YouTube Studio (external)

1. Confirm the brand YouTube channel is eligible for Shopping
2. Link the Merchant product source through Google’s supported onboarding path
3. Set the env flags above when confirmed

## Testing

Focused suite: `tests/Feature/SocialCommerce/SalesChannelsTest.php`

Covers setup-required state, hidden credentials, unauthorized staff, Super Admin configure, invalid credentials, access denied, successful test → Connected, readiness blocks submit, ready product sync (Http fake), sanitized errors, duplicate sync lock, YouTube dependency (no auto-connect), Arabic labels, product mapping.

Run:

```bash
php artisan test --filter=SalesChannels
php artisan test
```

Never require real Google credentials in automated tests.

## Future Providers

Add a platform enum case (if needed), config block, adapter implementing `SalesChannelProviderInterface`, register it in `SocialCommerceServiceProvider`, and ensureDefaults / UI will pick it up without changing health or mapping cores.
