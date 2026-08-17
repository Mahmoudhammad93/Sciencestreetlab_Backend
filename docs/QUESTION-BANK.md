# Assessment System — Question Banks & Interactive Activities

## Architecture summary

```
Course
 └── Lesson
      ├── Topics (video)
      ├── QuestionBank → Questions (typed, difficulty, tags)
      ├── Quiz (fixed | generated) ──┬── frozen QuizAttempt questions
      │                              └── optional InteractiveActivities (mixed)
      └── InteractiveActivity (HTML/CSS/JS package)
           └── InteractiveActivityAttempt (client_score vs verified_score)
```

This extends the existing Assessment module. It does **not** replace quizzes, attempts, graders, or Learning Course/Lesson models.

## Standard question types

| Type | Value | Grader |
|------|-------|--------|
| Single choice | `single_choice` | `SingleChoiceGrader` |
| Multiple choice | `multiple_choice` | `MultipleChoiceGrader` |
| True / false | `true_false` | `TrueFalseGrader` |
| Short answer | `short_answer` | `ShortAnswerGrader` |
| Long answer | `long_answer` | `LongAnswerGrader` (manual review) |
| Fill blank | `fill_blank` | `FillBlankGrader` |
| Matching | `matching` | `MatchingGrader` |
| Ordering | `ordering` | `OrderingGrader` |
| Numeric | `numeric` | `NumericGrader` |
| Interactive HTML (legacy quiz item) | `interactive_html` | `InteractiveHtmlGrader` |
| Interactive activity link | `interactive_activity` | `InteractiveHtmlGrader` |

Difficulty: `easy` | `medium` | `hard`

Tags: optional many-to-many (`question_tags` / `question_tag`) used for filtering and generated selection (`tag_slugs`).

## Interactive Activity (first-class)

An Interactive Activity is a **complete educational game/package**, not a QuestionOption row.

Package layout example:

```
interactive-activities/{uuid}/v{n}/
  index.html
  css/game.css
  js/game.js
  images/...
  audio/...
```

- Admin uploads a ZIP (Filament) or seeders copy a directory.
- Extraction blocks `..`, forbidden extensions (php, sh, …).
- Served via signed URL: `GET /interactive-activities/{uuid}/v{version}/{path?}` (**no Sanctum cookies**).
- Frontend iframe: `sandbox="allow-scripts allow-forms"` — **do not** add `allow-same-origin` with scripts if that would expose auth cookies.

### postMessage protocol

Activity → parent:

`READY` · `STARTED` · `PROGRESS` · `QUESTION_STARTED` · `ANSWER_SUBMITTED` · `QUESTION_COMPLETED` · `ACTIVITY_COMPLETED` · `RETRY` · `ERROR`

Parent → activity:

`INIT` with `{ activityId, attemptId, origin }`

### Scoring trust model

| Field | Meaning |
|-------|---------|
| `client_score` | Reported by the HTML game — **untrusted** |
| `verified_score` | Computed when `activity_config.expected` is present |
| `score_verified` | `true` only when server verified |

Unverified scores must **not** be treated as authoritative for certificates/rankings unless explicitly allowed.

## Generated quizzes

`QuestionSelectionService` selects from attached banks using:

- `total_questions`
- `difficulty: { easy, medium, hard }`
- `question_types`, `tag_slugs`, `exclude_question_ids`

On start: freeze IDs in `quiz_attempt_questions`. Resume returns the same set (never re-roll mid-attempt).

## Frontend APIs (`/api/v1`, Sanctum)

### Banks / questions / quizzes (existing + tags)

| Feature | Method | Endpoint |
|---------|--------|----------|
| Lesson banks | GET | `/lessons/{lesson}/question-banks` |
| Bank | GET | `/question-banks/{questionBank}` |
| Bank questions | GET | `/question-banks/{questionBank}/questions` |
| Question | GET | `/questions/{question}` |
| Interactive HTML URL | GET | `/questions/{question}/interactive` |
| Quiz meta | GET | `/quizzes/{quiz}` |
| Start / resume attempt | POST | `/quizzes/{quiz}/attempts` |
| Attempt | GET | `/quiz-attempts/{attempt}` |
| Save answer | POST | `/quiz-attempts/{attempt}/answers` |
| Submit quiz | POST | `/quiz-attempts/{attempt}/submit` |
| Quiz result | GET | `/quiz-attempts/{attempt}/result` |

Question list filters: `difficulty`, `type`/`question_type`, `interactive_type`, `tag`, `tags[]`, pagination.

### Interactive activities (new)

| Feature | Method | Endpoint |
|---------|--------|----------|
| Lesson activities | GET | `/lessons/{lesson}/interactive-activities` |
| Activity | GET | `/interactive-activities/{activity}` |
| Launch | GET | `/interactive-activities/{activity}/launch` |
| Start attempt | POST | `/interactive-activities/{activity}/attempts` |
| Attempt | GET | `/interactive-activity-attempts/{attempt}` |
| Progress | POST | `/interactive-activity-attempts/{attempt}/progress` |
| Submit result | POST | `/interactive-activity-attempts/{attempt}/result` |
| Get result | GET | `/interactive-activity-attempts/{attempt}/result` |

**Opaque HTML activities:** The platform hosts complete standalone HTML/JS packages (e.g. **مختبر الضوء**) inside a sandboxed iframe. It does **not** parse Canvas logic, challenges, or scoring rules. The activity owns its UI; the platform only stores/uploads packages, authorizes launch, records attempts, and persists standardized postMessage events/results.

Upload: single `.html` **or** `.zip` package via Filament. Sandbox: `allow-scripts` (no cookies/session access).

## Demo data

```bash
php artisan db:seed --class=AssessmentDemoCoursesSeeder
```

| Course slug | Content |
|-------------|---------|
| `intro-biology-lab` | 3 lessons, full banks, Human Body + Cell games, **Plant Growth** (`plant-growth`), fixed/generated/mixed quizzes |
| `basic-physics-lab` | Introduction to Physics — **Light Lab**, **Sound Lab**, **Rubber Castle**, **Rubber Race**, Force Simulation; mixed quizzes per lesson |
| `basic-chemistry-lab` | 3 lessons, banks, fixed quiz |

Login: `demo@sciencestreetlab.com` / `password`

Each bank seeds **all standard types × all difficulties** (multiple examples) plus tags.

## Filament

Assessment group:

- Question Banks
- Questions (tags + optional linked activity)
- Quizzes (banks + interactive activities)
- **Interactive Activities** (ZIP upload, versioned package, preview, duplicate)

## Security checklist

- [x] Untrusted HTML/JS isolated from Sanctum cookies
- [x] Signed temporary launch URLs
- [x] ZIP path traversal / forbidden extensions blocked
- [x] Student payloads never include `is_correct` / `answer_key`
- [x] Activity attempt IDOR checks (owner only)
- [x] Client scores stored separately from verified scores

## Postman

Collections (import both; they share `{{baseUrl}}` and `{{token}}`):

- `postman/Science-Street-Lab-API.postman_collection.json` — full platform API
- `postman/Question-Bank.postman_collection.json` — student assessment contract
- `postman/Science-Street-Lab.local.postman_environment.json` — local variables

**Suggested run order (assessment):**

1. Auth → Login (demo student) — `demo@sciencestreetlab.com` / `password`
2. Learning / Courses & Lessons → Enroll in Biology Lab
3. Biology Lab Curriculum — saves `lesson_id`, `quiz_id`, `mixed_quiz_id`, `activity_id`
4. Interactive Activities → List → Launch → Start attempt → Submit result → Get result
5. Mixed Quiz → Start quiz → Start activity with `quiz_attempt_id` → Submit activity → Submit quiz → Result

Tests assert HTTP status, required IDs, signed launch URLs, and that student question payloads do **not** include `is_correct`, `answer_key`, or private storage paths.

Variables: `baseUrl`, `token`, `course_id`, `lesson_id`, `question_bank_id`, `question_id`, `quiz_id`, `mixed_quiz_id`, `attempt_id`, `activity_id`, `activity_attempt_id`, `biology_course_slug`.
