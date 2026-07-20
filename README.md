# Science Street Lab — Laravel Backend

STEM E-learning + E-commerce platform scaffold based on the [Laravel Implementation Blueprint](../Laravel-11-Implementation-Blueprint.md).

## Stack

| Package | Purpose |
|---------|---------|
| Laravel 13 + PHP 8.3 | Application framework |
| Laravel Sanctum | API token authentication |
| Spatie Permission | Roles & permissions |
| Spatie Media Library | File uploads & media |
| Spatie Translatable | AR/EN content fields |

**Planned (not yet installed):** Horizon, Meilisearch

**Installed:** Filament v3, DomPDF (certificates)

## Project Structure

```
app/
├── Modules/                    # DDD bounded contexts
│   ├── Identity/               # Auth, users, profiles
│   ├── Catalog/                # Products, categories
│   ├── Commerce/               # Cart, orders, Paymob
│   ├── Learning/               # Courses, lessons, progress
│   ├── Assessment/             # Quizzes, grading
│   ├── Certification/          # PDF certificates
│   ├── Gamification/           # Badges, points
│   ├── Competition/            # 100-photo challenge
│   ├── Content/                # CMS pages, blog
│   ├── Notification/           # Email, SMS, push
│   ├── Media/                  # S3 upload workflow
│   └── Search/                 # Meilisearch indexing
├── Shared/
│   ├── Kernel/                 # ModuleServiceProvider, BaseRepository
│   └── Contracts/              # Cross-module interfaces (PaymentGateway)
└── Providers/
    └── ModuleAggregatorServiceProvider.php
```

Each module follows **Clean Architecture**:

```
Module/
├── Domain/          # Entities, Enums, Events, Repository interfaces
├── Application/     # Services, Actions, DTOs, Listeners
├── Infrastructure/  # Eloquent models, migrations, gateway impl
├── Http/            # API controllers, resources, requests
├── Filament/        # Admin resources (to be added)
└── Routes/api.php   # Module routes (auto-loaded under /api/v1)
```

## Quick Start

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### Verify API

```bash
# App health
curl http://localhost:8000/api/v1/health

# Module health checks
curl http://localhost:8000/api/v1/catalog/health
curl http://localhost:8000/api/v1/learning/health

# Register user
curl -X POST http://localhost:8000/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@example.com","password":"password","password_confirmation":"password"}'
```

## API Routes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/health` | Application health |
| POST | `/api/v1/auth/register` | Register |
| POST | `/api/v1/auth/login` | Login |
| POST | `/api/v1/auth/logout` | Logout (Sanctum) |
| GET | `/api/v1/auth/me` | Current user (Sanctum) |
| GET | `/api/v1/products` | Product list |
| GET | `/api/v1/products/{slug}` | Product detail |
| GET | `/api/v1/{module}/health` | Per-module health |

Full API spec: see blueprint §16–17.

## Implemented Scaffold

- [x] 12 module directories with DDD layer structure
- [x] `ModuleServiceProvider` auto-loads migrations + routes
- [x] Identity: auth controller, extended users migration, Sanctum
- [x] Catalog: Product model, repository, API controller
- [x] Learning: Course/Lesson/Topic/Enrollment models + progress service + API
- [x] Commerce: cart API, orders/payments migrations, Paymob stub
- [x] Competition: eligibility service, migrations, Competition model
- [x] Assessment: Quiz grading service stub
- [x] **Filament v3 admin panel** at `/admin`
- [x] Roles & permissions seeder
- [x] Sample products, course, competition seed data

## Admin Panel

**URL:** `http://localhost:8000/admin`

| Email | Password | Role |
|-------|----------|------|
| admin@sciencestreetlab.com | password | super_admin |

Resources: Users, Products, Courses, Orders, Coupons, Certificate Templates, Achievements, Competitions, Competition Review Queue + dashboard stats widget.

## API Routes (new)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/v1/courses` | — | Published courses |
| GET | `/api/v1/courses/{slug}` | — | Course with lessons |
| GET | `/api/v1/cart` | optional | View cart (subtotal, discount, total) |
| POST | `/api/v1/cart/items` | optional | Add to cart |
| PUT | `/api/v1/cart/items/{id}` | optional | Update quantity |
| DELETE | `/api/v1/cart/items/{id}` | optional | Remove item |
| POST | `/api/v1/cart/coupon` | optional | Apply coupon code |
| DELETE | `/api/v1/cart/coupon` | optional | Remove coupon |
| POST | `/api/v1/checkout` | ✓ | Create order from cart |
| POST | `/api/v1/checkout/{order}/pay` | ✓ | Initiate Paymob (mock if no keys) |
| POST | `/api/v1/payments/mock/{id}/complete` | — | Complete mock payment (local) |
| POST | `/api/v1/payments/paymob/callback` | — | Paymob webhook |
| GET | `/api/v1/orders` | ✓ | Order history |
| GET | `/api/v1/orders/{orderNumber}` | ✓ | Order detail |
| GET | `/api/v1/wishlist` | ✓ | Wishlist items |
| POST | `/api/v1/wishlist/{product}` | ✓ | Toggle wishlist |

### Sample coupon (seeded)

- Code: `SCIENCE10` — 10% off orders over 100 EGP

### Checkout flow (local dev)

```bash
# 1. Login / register → get token
# 2. Add product to cart
# 3. POST /api/v1/checkout
# 4. POST /api/v1/checkout/{order_id}/pay → get iframe_url (mock URL locally)
# 5. POST /api/v1/payments/mock/{payment_id}/complete → marks paid + enrolls course
```

## Phase 3 — LMS API

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/v1/enrollments` | ✓ | My enrollments |
| GET | `/api/v1/enrollments/{id}` | ✓ | Enrollment detail |
| GET | `/api/v1/courses/{slug}/curriculum` | ✓ | Lessons, topics, quizzes with lock states |
| POST | `/api/v1/topics/{id}/progress` | ✓ | Report video watch progress (≥90% completes) |
| GET | `/api/v1/topics/{id}/video-url` | ✓ | Get video URL (gated) |
| GET | `/api/v1/quizzes/{id}` | ✓ | Quiz questions (no correct answers exposed in options) |
| POST | `/api/v1/quizzes/{id}/attempts` | ✓ | Start quiz attempt |
| POST | `/api/v1/attempts/{id}/submit` | ✓ | Submit answers → auto-grade |
| GET | `/api/v1/attempts/{id}/result` | ✓ | Attempt result with explanations |

### Learning flow

1. Purchase course product → auto-enrollment on payment
2. Fetch curriculum → first lesson unlocked
3. Watch topic (POST progress ≥ 90%)
4. Pass required lesson quiz
5. Course marked **completed** when all lessons done

## Phase 4 — Certificates & Gamification

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/v1/certificates` | ✓ | My issued certificates |
| GET | `/api/v1/certificates/{uuid}/download` | ✓ | Download certificate PDF |
| GET | `/api/v1/certificates/verify/{code}` | — | Public verification |
| GET | `/api/v1/me/achievements` | ✓ | Unlocked achievements |
| GET | `/api/v1/me/points` | ✓ | Points balance + recent transactions |

### Certificate flow

1. User completes course → `CourseCompleted` event
2. `CertificateIssuanceService` creates record + dispatches PDF job
3. DomPDF renders Blade template → stored at `storage/app/certificates/{uuid}.pdf`
4. Public verify endpoint validates by `verification_code`

### Default achievements (seeded)

| Slug | Trigger | Points |
|------|---------|--------|
| `course-graduate` | Any course completed | 50 |
| `microscope-certified` | Microscope course completed | 100 |
| `quiz-master` | First quiz passed | 25 |
| `photo-pioneer` | First competition photo approved | 30 |

## Phase 5 — Competition (100 Photo Challenge)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/v1/competitions/{slug}` | — | Competition info + rules |
| GET | `/api/v1/competitions/{slug}/eligibility` | ✓ | Check eligibility |
| POST | `/api/v1/competitions/{slug}/register` | ✓ | Register participant |
| GET | `/api/v1/competitions/{slug}/dashboard` | ✓ | Progress counts |
| POST | `/api/v1/competitions/{slug}/submissions` | ✓ | Upload photo (multipart) |
| GET | `/api/v1/submissions` | ✓ | My submissions |
| PUT | `/api/v1/submissions/{uuid}` | ✓ | Update / re-upload |

### Competition flow

1. Complete prerequisite course (microscope course)
2. Check eligibility → register
3. Upload photos (sample 1–50, up to 2 photos per sample)
4. Admin reviews in Filament **Review Queue** (approve / reject / request revision)
5. When 100 photos approved → eligible for shortlist → winner selection

### Admin (Filament)

- **Competition** group: Competitions, Review Queue
- Review actions: Approve, Reject, Request Revision

## Phase 6 — Mobile API, React Frontend & SEO

### Mobile API

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/v1/mobile/home` | ✓ | Aggregated home feed |
| GET | `/api/v1/mobile/learning-dashboard` | ✓ | Enrollments + next lesson |
| GET | `/api/v1/sync/enrollments` | ✓ | Delta sync (`?since=`) |
| POST | `/api/v1/topics/{id}/heartbeat` | ✓ | Lightweight video progress |
| GET | `/api/v1/competitions/{slug}/submissions/summary` | ✓ | Counts only |
| POST | `/api/v1/devices` | ✓ | Register FCM device token |
| DELETE | `/api/v1/devices/{id}` | ✓ | Unregister device |
| POST | `/api/v1/auth/refresh` | ✓ | Refresh Sanctum token |
| DELETE | `/api/v1/auth/me` | ✓ | Delete account (`confirmation: DELETE`) |

### Content CMS API

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/pages/{slug}` | CMS page |
| GET | `/api/v1/blog` | Blog list |
| GET | `/api/v1/blog/{slug}` | Blog post |

### React frontend

See [../frontend/README.md](../frontend/README.md). Run `npm run dev` in `frontend/` (port 5173).

### SEO redirects

Old WordPress paths (`/shop/`, `/quest-dashboard/`, etc.) → React routes via `routes/web.php` (301).

Set `FRONTEND_URL=http://localhost:5173` in `.env`.

## Next Steps (per blueprint phases)

1. ~~**Phase 1**~~ — Filament admin, role seeder, remaining migrations ✅
2. ~~**Phase 2**~~ — Cart, checkout, Paymob implementation ✅
3. ~~**Phase 3**~~ — LMS player API, quiz engine, video signed URLs ✅
4. ~~**Phase 4**~~ — Certificates, achievements ✅
5. ~~**Phase 5**~~ — Competition upload/review ✅
6. ~~**Phase 6**~~ — Mobile API, React frontend, SEO redirects ✅
7. **Production** — WordPress data migration, FCM push, load testing

## Configuration

| File | Purpose |
|------|---------|
| `config/sciencestreet.php` | App name, locales, module list |
| `config/paymob.php` | Paymob payment gateway |
| `config/permission.php` | Spatie roles |

## Development

```bash
# Run tests
php artisan test

# Code style
./vendor/bin/pint

# List all routes
php artisan route:list --path=api
```

## WordPress Migration

Migration commands will live under `app/Console/Commands/MigrateWordPress/` (to be implemented).

See blueprint §21 for table mapping from WooCommerce + LearnDash + `al-arcade-100-quest`.

---

Built for [sciencestreetlab.com](https://sciencestreetlab.com) — مختبر شارع العلوم
