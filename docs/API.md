# Science Street Lab API

Base URL: `{{baseUrl}}/api/v1`  
Default local: `http://localhost:8000/api/v1`

All JSON responses wrap payload in `data` unless noted.

```json
{ "data": { } }
```

Errors:

```json
{ "message": "Human readable error", "code": "INVALID_CREDENTIALS" }
```

Validation (422):

```json
{
  "message": "Validation failed",
  "code": "VALIDATION_ERROR",
  "errors": { "email": ["The email field is required."] }
}
```

## Auth

Send the Sanctum token on protected routes:

```
Authorization: Bearer {{token}}
Accept: application/json
Content-Type: application/json
```

| Auth | Meaning |
|------|---------|
| Public | No token |
| Optional | Guest cart uses session cookie; logged-in cart uses `user_id` |
| Required | Bearer token |

### Demo accounts

| Role | Email | Password |
|------|--------|----------|
| Admin | `admin@sciencestreetlab.com` | `password` |
| Student | `demo@sciencestreetlab.com` | `password` |

---

## 1. Health & settings (public)

| Method | Path | Auth | What it does |
|-------|------|------|----------------|
| GET | `/health` | Public | App name, API version, locale |
| GET | `/settings` | Public | Website brand, colors, navbar, contact, social |
| GET | `/identity/health` | Public | Identity module ping |
| GET | `/media/health` | Public | Media module ping |
| GET | `/search/health` | Public | Search module ping |

`GET /settings` is what the storefront uses for logo, primary/accent/navbar colors, WhatsApp, and promo banner.

---

## 2. Auth

| Method | Path | Auth | What it does |
|-------|------|------|----------------|
| POST | `/auth/register` | Public | Create account + token; sends verification email |
| POST | `/auth/login` | Public | Login + token |
| POST | `/auth/forgot-password` | Public | Request password reset email |
| POST | `/auth/reset-password` | Public | Reset password with token |
| GET | `/auth/email/verify/{id}/{hash}` | Signed URL | Verify email address |
| POST | `/auth/email/verification-notification` | Required | Resend verification email |
| GET | `/auth/me` | Required | Current user |
| POST | `/auth/refresh` | Required | Rotate token |
| POST | `/auth/logout` | Required | Delete current token |
| DELETE | `/auth/me` | Required | Delete account (`confirmation: "DELETE"`) |

### Login

```http
POST /api/v1/auth/login
```

```json
{ "email": "demo@sciencestreetlab.com", "password": "password" }
```

```json
{
  "data": {
    "token": "1|xxxx",
    "user": {
      "id": 2,
      "name": "...",
      "email": "demo@sciencestreetlab.com",
      "email_verified": true
    }
  }
}
```

Save `data.token` as `{{token}}`.

Register also needs `name`, `password`, `password_confirmation`. Optional: `phone`, `locale` (`ar` \| `en`). New users receive `email_verified: false` until they click the signed verification link.

**Forgot password:** `POST /auth/forgot-password` with `{ "email": "..." }` — always returns a generic success message (no email enumeration).

**Reset password:** `POST /auth/reset-password` with `{ "token", "email", "password", "password_confirmation" }`.

Auth routes are rate-limited (register, login, password reset, resend verification).

Guest cart items are merged into the user cart on login/register when a session cookie is present.

---

## 3. Catalog (public)

| Method | Path | Auth | What it does |
|-------|------|------|----------------|
| GET | `/products` | Public | Product list |
| GET | `/products/{slug}` | Public | Product detail (price, course_id, images) |
| GET | `/wishlist` | Required | Wishlist |
| POST | `/wishlist/{product}` | Required | Toggle wishlist |

Microscope kit slug: `science-street-microscope` (SKU `SS-MICRO-001`, 3720 EGP). Buying it enrolls the linked microscope course after payment.

---

## 4. Cart & checkout

Cart is **optional auth**. Checkout requires login.

| Method | Path | Auth | What it does |
|-------|------|------|----------------|
| GET | `/cart` | Optional | Cart items, subtotal, discount, total |
| POST | `/cart/items` | Optional | Add item (`product_id` **or** `slug`, `quantity`) |
| PUT | `/cart/items/{item}` | Optional | Update quantity (`0` removes) |
| DELETE | `/cart/items/{item}` | Optional | Remove item |
| POST | `/cart/coupon` | Optional | Apply coupon `{ "code": "SCIENCE10" }` |
| DELETE | `/cart/coupon` | Optional | Remove coupon |
| POST | `/checkout` | Required | Create order from cart |
| POST | `/checkout/{order}/pay` | Required | Start payment (MyFatoorah / mock) |
| GET | `/orders` | Required | My orders |
| GET | `/orders/{orderNumber}` | Required | Order detail |
| POST | `/payments/mock/{payment}/complete` | Public (local) | Complete mock payment |
| GET | `/payments/myfatoorah/callback` | Public | MyFatoorah return URL |
| POST | `/payments/paymob/callback` | Public | Paymob webhook |

### Add to cart

```json
{ "slug": "science-street-microscope", "quantity": 1 }
```

or `{ "product_id": 1, "quantity": 1 }`

### Checkout body

```json
{
  "billing_address": {
    "first_name": "Ahmed",
    "email": "demo@sciencestreetlab.com",
    "phone": "01012345678",
    "city": "Cairo",
    "country": "EG",
    "street": "Cairo, Egypt"
  },
  "shipping_address": { "city": "Cairo", "country": "EG", "street": "Cairo" },
  "notes": null
}
```

### Pay

`POST /checkout/{order_id}/pay` returns:

```json
{
  "data": {
    "payment_id": 1,
    "iframe_url": "https://...",
    "payment_url": "https://...",
    "gateway": "myfatoorah"
  }
}
```

If the URL contains `/payments/mock/`, complete it with:

```http
POST /api/v1/payments/mock/{{payment_id}}/complete
```

Paid course products auto-enroll the user.

**Empty cart:** checkout returns `422` `"Cannot checkout with an empty cart."` Add items **after login** (or login after adding so carts merge).

---

## 5. Learning (LMS)

| Method | Path | Auth | What it does |
|-------|------|------|----------------|
| GET | `/courses` | Public | Course catalog |
| GET | `/courses/{slug}` | Public | Course + lessons |
| GET | `/courses/{slug}/lessons` | Public | Lessons list |
| GET | `/courses/{slug}/plans` | Public | Active course plans |
| POST | `/courses/{slug}/enroll` | Required | Enroll (free / already paid) |
| GET | `/courses/{slug}/curriculum` | Required | Lessons, quizzes, lock state, activities |
| GET | `/courses/{slug}/enrollment` | Required | This course enrollment |
| GET | `/courses/{slug}/access` | Required | Plan-based access summary |
| GET | `/courses/{slug}/leaderboard` | Optional | Course leaderboard (official scores) |
| GET | `/courses/{slug}/progress` | Required | Progress % |
| POST | `/courses/{slug}/plans/{planId}/enroll` | Required | Enroll via free plan |
| GET | `/me/enrollments` | Required | All my enrollments (alias) |
| GET | `/me/enrollments/{id}` | Required | Enrollment detail (alias) |
| GET | `/enrollments` | Required | All my enrollments |
| GET | `/enrollments/{id}` | Required | Enrollment detail |
| GET | `/lessons/{lesson}` | Required | Lesson detail |
| GET | `/topics/{topic}/video-url` | Required | Signed video URL |
| POST | `/topics/{topic}/progress` | Required | Report watch progress |
| POST | `/topics/{topic}/heartbeat` | Required | Keep-alive while watching |

### Curriculum (important)

```http
GET /api/v1/courses/basic-physics-lab/curriculum
```

Returns lessons with `quizzes[]` and `interactive_activities[]`. Use this to fill:

| Variable | From |
|---------|------|
| `lesson_id` | first unlocked lesson |
| `quiz_id` | lesson quiz |
| `activity_id` | first interactive activity |
| `light_lab_activity_id` | Light Lab activity |

Demo course slugs:

| Slug | Content |
|------|---------|
| `intro-biology-lab` | Cells, plant growth HTML |
| `basic-physics-lab` | Light Lab, Sound Lab, Rubber Castle/Race |
| `basic-chemistry-lab` | Chemistry banks + quiz |
| `microscope-course` | Linked to microscope product |

### Course plans & access

Admins configure **Course Plans** per course in Filament (`Courses → Edit → Course Plans`). Each plan defines price, duration, max quiz attempts, certificate access, and specific entitlements (lessons, topics, quizzes, interactive activities).

**Flow:** `Course → Course Plan → Product → Order → Payment → Enrollment (with entitlement snapshot)`

When a student enrolls, entitlements are **snapshotted** into `enrollment_entitlements` so later plan edits do not change existing enrollments.

```http
GET /api/v1/courses/microscope-course/access
Authorization: Bearer {token}
```

```json
{
  "enrolled": true,
  "active": true,
  "uses_plan": true,
  "enrollment": { "id": 123, "status": "active", "started_at": "...", "expires_at": null },
  "plan": { "id": 5, "name": "Exam Preparation" },
  "access": {
    "course": true,
    "certificate": false,
    "lesson_ids": [1, 2],
    "quiz_ids": [3]
  }
}
```

**Quiz official score:** The first **successfully submitted** attempt is the official score. Retries may score higher but never replace the official result. `GET /quizzes/{quiz}` (authenticated + enrolled) and quiz result APIs return `official_score`, `official_attempt_number`, `official_attempt_id`, `official_passed`, and `has_previous_attempt` (on quiz show).

### Course plans (public)

List active plans for a published course (no auth required):

```http
GET /api/v1/courses/{slug}/plans
```

```json
{
  "data": [
    {
      "id": 1,
      "name": "Basic Plan",
      "description": "Basic course access",
      "price": "299.00",
      "currency": "EGP",
      "is_lifetime": false,
      "duration_days": 30,
      "max_quiz_attempts": 2,
      "grant_certificate": false
    }
  ]
}
```

Enroll via a plan: `POST /api/v1/courses/{slug}/plans/{planId}/enroll` (auth required).

### Course leaderboard

Ranks enrolled learners by **official quiz scores only** (average of official attempt percentages per course quiz). Retries never improve leaderboard rank.

```http
GET /api/v1/courses/microscope-course/leaderboard?page=1&per_page=20
Authorization: Bearer {token}   # optional — includes current_user rank
```

```json
{
  "course_id": 1,
  "leaderboard": [
    {
      "rank": 1,
      "user": { "id": 25, "name": "Ahmed", "avatar_url": null },
      "score": 95,
      "completion_percentage": 100,
      "is_current_user": false
    }
  ],
  "current_user": { "rank": 7, "score": 82.5, "completion_percentage": 60, "is_current_user": true },
  "meta": { "current_page": 1, "per_page": 20, "total": 15, "last_page": 1 }
}
```

Tie-breakers: score → completion % → earliest official submit → user id. Excludes expired, suspended, and cancelled enrollments.

---

## 6. Assessment — quizzes

Student payloads **never** include `is_correct` or `answer_key`.

| Method | Path | Auth | What it does |
|-------|------|------|----------------|
| GET | `/quizzes/{quiz}` | Required | Quiz meta + official score fields + `interactive_activities` |
| POST | `/quizzes/{quiz}/attempts` | Required | Start or resume attempt |
| GET | `/quiz-attempts/{attempt}` | Required | Attempt + questions (no answers) |
| POST | `/quiz-attempts/{attempt}/answers` | Required | Save one answer |
| POST | `/quiz-attempts/{attempt}/submit` | Required | Finish + grade |
| GET | `/quiz-attempts/{attempt}/result` | Required | Score, pass/fail, review |
| POST | `/quiz-attempts/{attempt}/questions/{question}/interactive-result` | Required | Legacy interactive HTML question |
| GET | `/lessons/{lesson}/question-banks` | Required | Banks on a lesson |
| GET | `/question-banks/{id}` | Required | Bank meta |
| GET | `/question-banks/{id}/questions` | Required | Filterable questions |
| GET | `/questions/{id}` | Required | One question (no key) |
| GET | `/questions/{id}/interactive` | Required | Signed URL for legacy HTML question |

Legacy aliases (same auth):

- `GET /attempts/{attempt}`
- `POST /attempts/{attempt}/submit`
- `GET /attempts/{attempt}/result`

### Interactive HTML is not a quiz question type

`interactive_html` is **not** an assessment `question_type`. HTML labs are **lesson topics** (`content_type: interactive`) with their own attempts. Quiz JSON returns `interactive_activities: []`. Quiz score, pass mark, and duration ignore labs.

After demo seed, open a lab from **curriculum topics**, not from the quiz:

| Course slug | Lab topic | Typical `activity_id` |
|-------------|-----------|------------------------|
| `basic-physics-lab` | Light Lab, Sound Lab, Rubber Castle, Rubber Race | 4, 6, 7, 8 |
| `intro-biology-lab` | Plant Growth | 5 |

`GET /quizzes/{id}` does **not** include HTML activities. Use `GET /courses/{slug}/curriculum` → `lessons[].topics[]` where `content_type` is `interactive`.

### Submit answer shapes

```json
{ "question_id": 1, "answer": { "option_id": 10 } }
```

```json
{ "question_id": 2, "answer": { "option_ids": [25, 26] } }
```

```json
{ "question_id": 3, "answer": { "text": "nucleus" } }
```

```json
{ "question_id": 4, "answer": { "numeric": 9.8 } }
```

```json
{ "question_id": 5, "answer": { "matches": { "1": 4, "2": 3 } } }
```

```json
{ "question_id": 6, "answer": { "order": [3, 1, 2] } }
```

### Quiz result / post review

`POST /quiz-attempts/{id}/submit` and `GET /quiz-attempts/{id}/result` now include:

```json
{
  "data": {
    "attempt_id": 12,
    "attempt_number": 1,
    "time_taken": 45,
    "time_taken_seconds": 45,
    "status": "passed",
    "score": 10,
    "max_score": 10,
    "percentage": 100,
    "passed": true,
    "question_results": [
      {
        "question_id": 1,
        "is_correct": true,
        "user_answer": { "option_id": 10, "option_ids": [10] },
        "correct_answer": { "option_id": 10, "option_ids": [10], "labels": [{ "id": 10, "label": "Nucleus" }] }
      }
    ]
  }
}
```

`correct_answer` is only returned after submit (never while the attempt is in progress).

---

### Quizzes 1–6 (demo)

After `php artisan db:seed --class=AssessmentDemoCoursesSeeder` (or `CompleteDemoQuizzesSeeder`):

Each of **quiz IDs 1–6** includes assessment question types only
(`single_choice`, `multiple_choice`, `true_false`, `short_answer`, `long_answer`, `fill_blank`, `matching`, `ordering`, `numeric`).

**Interactive HTML is not a question type.** It is lesson **topic** content (`content_type: interactive`) with its own attempts. Curriculum topics include `interactive.can_start`, `can_resume`, `is_completed`, and `launch` is fetched from `/interactive-activities/{id}/launch`.

HTML labs from `interactive examples/` (Light, Sound, Plant Growth, Rubber Castle, Rubber Race) are attached as interactive topics, not quiz questions. They do **not** affect quiz score.

### How to show interactive HTML in React

**Do not** put the HTML document inside the JSON. Games include JS/CSS/images. The safe way is a **sandboxed iframe** pointed at the signed `launch_url`:

```tsx
<iframe
  src={activity.launch_url}
  sandbox="allow-scripts"
  title={activity.title}
  style={{ width: '100%', height: 640, border: 0 }}
/>
```

Then listen for `window.addEventListener('message', ...)` and POST progress/result to the API.

`sandbox="allow-scripts"` without `allow-same-origin` keeps the game from reading the user session.

---

## 7. Interactive HTML activities

These are **full HTML/JS games**, not quiz options. The API hosts a signed iframe URL and stores progress/results from `postMessage`.

| Method | Path | Auth | What it does |
|-------|------|------|----------------|
| GET | `/lessons/{lesson}/interactive-activities` | Required | Activities on a lesson |
| GET | `/interactive-activities/{activity}` | Required | Activity meta |
| GET | `/interactive-activities/{activity}/launch` | Required | Signed iframe URL + protocol |
| POST | `/interactive-activities/{activity}/attempts` | Required | Start attempt (optional `quiz_attempt_id`) |
| GET | `/interactive-activity-attempts/{attempt}` | Required | Attempt + progress |
| POST | `/interactive-activity-attempts/{attempt}/progress` | Required | Save in-progress state |
| POST | `/interactive-activity-attempts/{attempt}/result` | Required | Finish (client score) |
| GET | `/interactive-activity-attempts/{attempt}/result` | Required | Stored result |

### Typical flow (Light Lab)

1. Login as demo student  
2. `GET /courses/basic-physics-lab/curriculum` → set `activity_id` (usually `4`)  
3. `GET /interactive-activities/4/launch` → `data.url` is the iframe `src`  
4. `POST /interactive-activities/4/attempts` → `activity_attempt_id`  
5. Play in iframe (`sandbox="allow-scripts"`)  
6. Progress:

```json
{ "completed_challenges": 2, "total_challenges": 5, "percentage": 40 }
```

7. Result:

```json
{
  "completed": true,
  "score": 40,
  "max_score": 50,
  "percentage": 80,
  "time_spent_seconds": 320,
  "challenges_completed": 5,
  "total_challenges": 5,
  "result": {}
}
```

`client_score` is **untrusted**. `verified_score` is used only if the package has expected answers.

### Mixed quiz (questions + HTML)

1. `POST /quizzes/22/attempts` → `attempt_id`  
2. `POST /interactive-activities/4/attempts` with `{ "quiz_attempt_id": {{attempt_id}} }`  
3. Submit activity result  
4. Submit remaining quiz answers  
5. `POST /quiz-attempts/{attempt_id}/submit`

---

## 8. Certificates & gamification

| Method | Path | Auth | What it does |
|-------|------|------|----------------|
| GET | `/certificates` | Required | My certificates |
| GET | `/certificates/{uuid}/download` | Required | PDF |
| GET | `/certificates/verify/{code}` | Public | Verify by code |
| GET | `/me/achievements` | Required | Unlocked badges |
| GET | `/me/points` | Required | Points + history |

---

## 9. Competition (100 Photos Challenge)

Slug: `microscope-100-challenge`

| Method | Path | Auth | What it does |
|-------|------|------|----------------|
| GET | `/competitions/{slug}` | Public | Rules + status |
| GET | `/competitions/{slug}/eligibility` | Required | Can the user join? |
| POST | `/competitions/{slug}/register` | Required | Register |
| GET | `/competitions/{slug}/dashboard` | Required | Progress counts |
| GET | `/competitions/{slug}/submissions/summary` | Required | Counts by status |
| POST | `/competitions/{slug}/submissions` | Required | Upload photo (multipart) |
| GET | `/submissions` | Required | My submissions |
| PUT | `/submissions/{uuid}` | Required | Update / re-upload |

Prerequisite: complete the microscope course (usually by buying the kit).

---

## 10. Content (public)

| Method | Path | Auth | What it does |
|-------|------|------|----------------|
| GET | `/pages/{slug}` | Public | CMS page (`about`, etc.) |
| GET | `/blog` | Public | Blog list |
| GET | `/blog/{slug}` | Public | Blog post |

---

## 11. Mobile

| Method | Path | Auth | What it does |
|-------|------|------|----------------|
| GET | `/mobile/home` | Required | Home widgets |
| GET | `/mobile/learning-dashboard` | Required | Continue learning |
| GET | `/sync/enrollments` | Required | Offline sync payload |
| POST | `/devices` | Required | Register push device |
| DELETE | `/devices/{deviceId}` | Required | Unregister device |

---

## Suggested Postman order

Import:

- `postman/Science-Street-Lab-API.postman_collection.json`
- `postman/Science-Street-Lab.local.postman_environment.json`

Then run:

1. **Health** → App Health, Public website settings  
2. **Auth** → Login (demo student)  
3. **Catalog** → Get microscope product  
4. **Commerce** → Add to cart → Checkout → Pay → Mock complete  
5. **Learning** → Physics lab curriculum (fills IDs)  
6. **Assessment** → `GET /quizzes/22` → Interactive Activities workflow  
7. **Competition** (after microscope course is complete)

---

## Locale

Send `Accept-Language: ar` or `en`. Translatable fields (`title`, `name`) are returned in that locale.
