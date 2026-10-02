# AUTH ACCOUNT DATA ISOLATION INCIDENT

## Severity

**HIGH** — authenticated customer SPA can resolve as Filament **Science Street Admin** when an admin web session cookie is present on the same host, exposing that admin’s private profile / orders / enrollments.

## Observed Behavior

Production `https://app.sciencestreetlab.com/account` showed the same identity across different customer logins:

- Name: Science Street Admin  
- Email: admin@sciencestreetlab.com  
- Orders (“طلباتي”) and courses (“دوراتي”) matched that same account  

## Authentication Architecture

| Layer | Mechanism |
| --- | --- |
| Customer SPA auth | Laravel Sanctum **personal access tokens** (Bearer) |
| Login | `POST /api/v1/auth/login` → `{ token, user }` |
| Profile | `GET /api/v1/auth/me` (`auth:sanctum`) |
| Orders | `GET /api/v1/orders` scoped `where('user_id', $request->user()->id)` |
| Courses | `GET /api/v1/enrollments` via `$request->user()->enrollments()` |
| Admin | Filament at **`/admin`** on **same host** `app.sciencestreetlab.com` |
| API middleware | `EnsureFrontendRequestsAreStateful` + SPA `withCredentials` |
| Production | `APP_URL=https://app.sciencestreetlab.com`, `SANCTUM_STATEFUL_DOMAINS=app.sciencestreetlab.com`, `SESSION_DOMAIN=null` |

## Login Trace

Frontend:

1. Login form → `loginFn` → `POST /api/v1/auth/login`  
2. `useLogin` stores user + token in Zustand persist key **`auth`**, and `persistAuthToken` → `localStorage.auth_token`  
3. Clears React Query `["me"]` (and after fix: orders/enrollments)  
4. Navigates to `/account`  
5. `RequireAuth` / `useAuthMe` → `GET /api/v1/auth/me`  
6. Account UI renders `/me` payload (not a hardcoded admin string)

Backend `AuthController::login`:

- Loads user by email  
- Validates password (or legacy WP upgrade)  
- `createToken('api')` for **that** user  
- Does **not** call `Auth::login()` (token-only)  
- No `User::first()` / admin fallback found  

## Token Ownership

- Tokens are `personal_access_tokens` with `tokenable_type = App\Models\User`  
- Login response token owner matches credentials (verified by tests)  
- No frontend hardcoded Bearer / admin token found for `admin@sciencestreetlab.com` (only seeders/docs/postman/tests)  

## /me Trace

```php
public function me(Request $request): JsonResponse
{
    return response()->json(['data' => new UserAuthResource($request->user())]);
}
```

Correct ownership **if** `$request->user()` is the Bearer user. Bug was **wrong authenticated identity**, not a broken resource.

## Orders Trace

`OrderController::index` / `show` filter by `$request->user()->id`.  

**Cause of wrong orders:** `AUTH_CONTEXT_WRONG` (not an unscoped `Order::query()->get()`).

## Courses Trace

`EnrollmentController::index` uses `$request->user()->enrollments()`.  

**Cause:** `AUTH_CONTEXT_WRONG` (not a separate enrollments ACL hole).

## Frontend Auth State

| Store | Key |
| --- | --- |
| Zustand persist | `localStorage.auth` → `{ state: { user: { id, name, email, token }, isLoggedIn } }` |
| Legacy mirror | `localStorage.auth_token` |
| Axios | Reads token per request via `getAuthToken()` (store → `auth` → `auth_token`) |

Secondary hygiene issues (not primary root cause):

- Query keys `["me"]`, `["orders"]`, `["enrollments"]` were not identity-scoped (stale UI risk on soft account switch)  
- Logout cleared token but not orders/enrollments query caches (full page reload mitigated)  

No hardcoded Admin UI user in account pages.

## Cache Analysis

| Layer | Finding |
| --- | --- |
| Nginx | `/api` proxied; **no** `proxy_cache` observed on API |
| Laravel | No shared `Cache::remember` on `/me` / orders / enrollments found |
| CDN | N/A for private API in inspected nginx config |
| React Query | In-memory only; secondary stale-key risk |

Not classified as `SHARED_RESPONSE_CACHE_BUG`.

## Admin/Customer Guard Analysis

**Proven shared-host session collision:**

1. Filament panel path: `/admin` on `app.sciencestreetlab.com`  
2. SPA + API on same host  
3. Sanctum `Guard` checks **`web` session user before Bearer token** (`vendor/laravel/sanctum/src/Guard.php`)  
4. `EnsureFrontendRequestsAreStateful` boots session for stateful domain `app.sciencestreetlab.com`  
5. Same-origin browser requests **always send** the Filament session cookie to `/api/*`  
6. Production `sessions` table showed **active rows for user_id=1 / admin@sciencestreetlab.com`**  

Therefore any browser that is (or was) logged into Filament will make customer Bearer calls resolve as Admin for `/me`, orders, and enrollments.

## Root Cause

**Primary:** `SHARED_SESSION_GUARD_BUG`

Evidence:

- Same host for Filament + SPA + API  
- Sanctum session-before-token order  
- Stateful API middleware  
- Live admin sessions  
- Orders/enrollments correctly scoped to `$request->user()` → wrong data follows wrong identity  

**Secondary (hardening):** frontend query-key / cache hygiene on login/logout (`FRONTEND_STALE_USER_STATE` risk on soft switches, not the Admin-everywhere pattern).

Not found:

- `FRONTEND_HARDCODED_AUTH`  
- `BACKEND_LOGIN_WRONG_USER`  
- `ME_ENDPOINT_BUG` / `ORDERS_AUTHORIZATION_BUG` / `ENROLLMENTS_AUTHORIZATION_BUG` as unscoped queries  
- `SHARED_RESPONSE_CACHE_BUG`  

## Security Impact

**CROSS_ACCOUNT_DATA_EXPOSURE**

Not UI-only. When an admin session cookie is present, private **profile, orders, and enrollments** of the Admin account are returned to the SPA while a customer token is stored client-side.

Impact scope: browsers that share Filament admin session with the storefront (typical for staff testing / shared machines). Still HIGH because private order/course data leaves the admin context into the customer UI.

## Code Fix

### Backend (required)

`App\Http\Middleware\PreferBearerTokenOverSession`  
— if `Authorization: Bearer …` is present, `Auth::guard('web')->forgetUser()` before Sanctum resolves identity.

Registered in `bootstrap/app.php` **after** `EnsureFrontendRequestsAreStateful`.

### Frontend (defense in depth)

- `useLogin` / `useLogout` / `useRegister`: clear `["me"]`, `["orders"]`, `["enrollments"]`  
- `useAuthMe`: query key includes token fingerprint  

### Not done (optional later)

- Split Filament to `admin.` subdomain / separate session cookie  
- Drop stateful Sanctum for pure token SPA  

## Regression Tests

`tests/Feature/AuthAccountDataIsolationTest.php`

| Result | Value |
| --- | --- |
| Tests | **4** |
| Assertions | **39** |
| Outcome | **PASS** |

Covers:

- Login token owner = credentials user  
- Admin web session + customer Bearer → `/me`, orders, enrollments = **customer**  
- A cannot read B orders/enrollments (and vice versa)  
- Logout A → login B → `/me` is B  

## Production Status

| State | Value |
| --- | --- |
| LOCAL_FIXED | **YES** |
| DEPLOYED | **NO** |
| VERIFIED_PRODUCTION | **NO** |

Do not claim production fixed until authorized deploy + browser verification (login customer while Filament session exists → account must show customer, not Admin).

## Migration Safety

WordPress migration work remains **PAUSED** until this incident is resolved in production.

## FINAL VERDICT

**AUTH_ISOLATION_ROOT_CAUSE_FOUND_LOCAL_FIX_READY**
