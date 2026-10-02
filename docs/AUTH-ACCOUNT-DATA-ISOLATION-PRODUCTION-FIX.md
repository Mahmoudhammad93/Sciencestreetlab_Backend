# AUTH ACCOUNT DATA ISOLATION PRODUCTION FIX

## Incident

Customer SPA on `https://app.sciencestreetlab.com/account` resolved as **Science Street Admin** (`admin@sciencestreetlab.com`) across different customer logins, including private **orders** and **enrollments**.

Authoritative analysis: `backend/docs/AUTH-ACCOUNT-DATA-ISOLATION-INCIDENT.md`

## Root Cause

**SHARED_SESSION_GUARD_BUG** → **CROSS_ACCOUNT_DATA_EXPOSURE**

Filament `/admin` and the customer API share `app.sciencestreetlab.com`. Sanctum previously checked the `web` session **before** Bearer tokens. An active Filament admin session made `auth:sanctum` resolve as Admin even when the SPA sent a customer Bearer token.

Initial middleware-only attempt (`forgetUser()`) was **insufficient** on real sessions because `SessionGuard` reloads the user from the session after `forgetUser()`. Production proof required the primary fix:

**`config/sanctum.php` → `'guard' => []`** (Bearer-only for `auth:sanctum`)

## Files Deployed

### Backend

| File | Change |
| --- | --- |
| `config/sanctum.php` | `'guard' => []` (primary fix) |
| `app/Http/Middleware/PreferBearerTokenOverSession.php` | defense-in-depth |
| `bootstrap/app.php` | register PreferBearer after stateful middleware |
| `tests/Feature/AuthAccountDataIsolationTest.php` | regression suite |

### Frontend

| File | Change |
| --- | --- |
| `src/features/auth/hooks/useLogin.ts` | clear orders/enrollments query caches |
| `src/features/auth/hooks/useLogout.ts` | clear orders/enrollments query caches |
| `src/features/auth/hooks/useRegister.ts` | clear caches + import `AUTH_ME_QUERY_KEY` |
| `src/features/auth/hooks/useAuthMe.ts` | token-fingerprint-scoped `["me"]` key |

**Not deployed:** WordPress migration preparation / importers / English parity / reviews / quiz / media.

## Pre-Deploy State

| Item | Value |
| --- | --- |
| Backend SHA | `0b962d35a42853d4ea4116a6852956b0daa1032c` |
| Frontend SHA | `5b95a22a04d1f3e46fc1e5b86abe80e87c8bf83e` |
| Containers | backend/queue/scheduler/nginx/mysql/frontend up |
| API health | 200 |
| Home | 200 |
| Admin login | 200 |

## Tests

Local (pre-final deploy):

| Suite | Result |
| --- | --- |
| `AuthAccountDataIsolationTest` | **5 tests / 40 assertions / PASS** |
| Related (`AuthAccountDataIsolationTest\|CartMergeTest\|OrderShippingStatusApiTest\|LearningFlowTest`) earlier | **28 / 290 / PASS** |

## Deployment

1. Copied hotfix files only to production host repos under `/home/ubuntu/apps/`.
2. Server-local commits (no remote push required for this hotfix path):
   - Backend final: `c97f83ea2c8e13cf716fe0970af7bd3e1ffc1550`
   - Frontend final: `8aca838da5e217c49767e6558fa61b8b747b7aff`
3. `docker compose build backend queue scheduler` + `up -d --no-deps`
4. Rebuilt `science-street-frontend:latest` and recreated frontend container
5. Restarted Nginx after backend/frontend recreate (FastCGI/DNS upstream recovery)
6. Confirmed `config('sanctum.guard') === []` inside running backend

DB migration: **NO**  
DB data mutation: **NO** (only ephemeral verification tokens created then deleted; name `auth-hotfix-*`)

## Production Verification

### Customer A

Ephemeral Bearer for user id **4**:

- `/api/v1/auth/me` → id 4  
- `/api/v1/orders` → all `user_id=4`  
- `/api/v1/enrollments` → all `user_id=4`  

### Customer B

Ephemeral Bearer for user id **6**:

- `/me` → id 6  
- orders → only B  
- enrollments → only B  

### Admin Session + Customer Bearer

In-container Kernel proof after final deploy:

| Check | Result |
| --- | --- |
| Admin web session established | yes |
| Customer Bearer present | yes |
| `/auth/me` | **customer id 4** (`bearer_wins: true`) |
| Resolved as Admin | **false** |
| Orders | customer only |
| Enrollments | customer only |
| No Bearer + admin session on API | **401 Unauthenticated** (API no longer uses Filament session — intended) |

### Orders Isolation

PASS — A sees only A; B sees only B; A→B order detail **404**

### Enrollment Isolation

PASS — A enrollments user_id={4}; B={6}

### Filament

`/admin/login` → **200**; Filament assets load. Admin panel route remains on web guard (not `auth:sanctum`).

### Cross Account

PASS — A cannot fetch B private order (404).

### Cache

Authenticated `/auth/me` returns `Cache-Control: no-cache, private`.

## Infrastructure Health

| Service | Status |
| --- | --- |
| backend | Up |
| frontend | Up |
| nginx | Up (restarted after recreate) |
| mysql | Up (healthy) |
| queue | Up |
| scheduler | Up |
| `/api/v1/health` | 200 |
| home | 200 |
| `/admin/login` | 200 |
| 502 | none observed |

## Security Result

| Question | Answer |
| --- | --- |
| Does Customer Bearer now win over Filament session? | **YES** |
| Can Customer A see Customer B private data? | **NO** |
| Can customer SPA resolve as Admin while customer Bearer is present? | **NO** |

## Migration Status

**WORDPRESS_MIGRATION_SAFE_TO_RESUME**

(Auth isolation verified in production. Do not auto-execute migrations; resume only under separate explicit authorization.)

## FINAL VERDICT

**AUTH_ISOLATION_FIXED_AND_VERIFIED_PRODUCTION**
