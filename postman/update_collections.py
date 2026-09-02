#!/usr/bin/env python3
"""Sync the single Science Street Lab Postman collection."""

from __future__ import annotations

import json
from copy import deepcopy
from pathlib import Path

COLLECTIONS = [
    Path(__file__).parent / "Science-Street-Lab-API.postman_collection.json",
]

MAIN_API_DESCRIPTION = """REST API collection for Science Street Lab (Laravel `/api/v1`). **All endpoints live in this one file.**

**Auth:** Laravel Sanctum Bearer token. Login/Register auto-save `token`.
**Base URL:** `{{baseUrl}}` (default `http://localhost:8000`).
**Environment:** import `Science-Street-Lab.local.postman_environment.json`.

**Demo accounts**
- Admin: `admin@sciencestreetlab.com` / `password`
- Student: `demo@sciencestreetlab.com` / `password`
- Plan demo: `plan-demo@sciencestreetlab.com` / `password` (Physics plan — run `CoursePlanDemoSeeder`)

**Suggested order**
1. Health → App Health / Public website settings
2. Auth → Login (or Login demo student)
3. Learning → Enroll → Course Access → Curriculum → Leaderboard
4. Catalog → Commerce (cart → checkout → mock pay) for paid course plans
5. Assessment → Start quiz → Save answers → Submit (with `time_spent_seconds`) → Get result

Assessment demo (`php artisan db:seed --class=AssessmentDemoCoursesSeeder`):
- Courses: `intro-biology-lab`, `basic-physics-lab`, `basic-chemistry-lab`, `microscope-course`

**Course plans & access**
- `GET /courses/{slug}/plans` — list active plans for a published course (public, no auth)
- `GET /courses/{slug}/access` — plan entitlements snapshot for enrolled user
- `POST /courses/{slug}/plans/{planId}/enroll` — enroll via free plan
- Quiz pass/progress uses **official score only** (first submitted attempt)

**Course leaderboard**
- `GET /courses/{slug}/leaderboard` — ranks by average official quiz % (optional auth for `current_user`)

**Auth email**
- Register sends verification email (`email_verified: false` until link clicked)
- `POST /auth/forgot-password`, `POST /auth/reset-password`, `POST /auth/email/verification-notification`

**Quiz official score**
- `GET /quizzes/{quiz}` (enrolled user) returns `official_score`, `official_attempt_number`, `official_attempt_id`, `official_passed`, `has_previous_attempt`
- First **submitted** attempt = official (`is_official`, `official_score`, `official_attempt_number`, `official_passed`)
- Retries never replace official score; `current_attempt_score` shows this attempt only
- Submit body may include `time_spent_seconds` (client-measured active time)

**Quiz result / post-review** (`POST /quiz-attempts/{id}/submit` and `GET /quiz-attempts/{id}/result`):
- `attempt_number`, `time_taken`, `time_taken_seconds`, `quiz_id`
- `is_official`, `official_score`, `official_attempt_number`, `official_attempt_id`, `official_passed`, `current_attempt_score`
- `question_results[].user_answer`, `question_results[].correct_answer`

**Start quiz when max attempts reached (422):**
- `code`: `MAX_ATTEMPTS_REACHED`
- `official_attempt_id`, `last_attempt_id` — use for GET result

Student question payloads never include `is_correct` or `answer_key`.
"""

NEW_VARS = [
    {"key": "light_lab_activity_id", "value": "4"},
    {"key": "sound_lab_activity_id", "value": "6"},
    {"key": "plant_growth_activity_id", "value": "5"},
    {"key": "rubber_castle_activity_id", "value": "7"},
    {"key": "rubber_race_activity_id", "value": "8"},
    {"key": "physics_light_mixed_quiz_id", "value": "1"},
    {"key": "physics_forces_mixed_quiz_id", "value": "1"},
    {"key": "physics_lesson_id", "value": "1"},
    {"key": "course_plan_id", "value": "1"},
    {"key": "official_attempt_id", "value": "1"},
    {"key": "plan_demo_course_slug", "value": "basic-physics-lab"},
]

QUIZ_SUBMIT_BODY = '{\n  "time_spent_seconds": 45\n}'

QUIZ_RESULT_TEST = [
    "pm.test('HTTP 2xx', function () {",
    "  pm.expect(pm.response.code).to.be.within(200, 299);",
    "});",
    "pm.test('JSON has data', function () {",
    "  const json = pm.response.json();",
    "  pm.expect(json).to.have.property('data');",
    "});",
    "const d = pm.response.json().data;",
    "pm.test('quiz result + post review fields', function () {",
    "  pm.expect(d).to.have.property('attempt_id');",
    "  pm.expect(d).to.have.property('attempt_number');",
    "  pm.expect(d).to.have.property('time_taken');",
    "  pm.expect(d).to.have.property('time_taken_seconds');",
    "  pm.expect(d).to.have.property('status');",
    "  pm.expect(d).to.have.property('score');",
    "  pm.expect(d).to.have.property('percentage');",
    "  pm.expect(d).to.have.property('quiz_id');",
    "  pm.expect(d).to.have.property('is_official');",
    "  pm.expect(d).to.have.property('official_score');",
    "  pm.expect(d).to.have.property('official_attempt_number');",
    "  pm.expect(d).to.have.property('official_attempt_id');",
    "  pm.expect(d).to.have.property('official_passed');",
    "  pm.expect(d).to.have.property('current_attempt_score');",
    "  pm.expect(d.question_results).to.be.an('array');",
    "  if (d.question_results.length) {",
    "    pm.expect(d.question_results[0]).to.have.property('user_answer');",
    "    pm.expect(d.question_results[0]).to.have.property('correct_answer');",
    "  }",
    "});",
    "if (d.is_official) pm.collectionVariables.set('official_attempt_id', String(d.attempt_id));",
    "",
]

QUIZ_SHOW_TEST = [
    "pm.test('HTTP 2xx', function () {",
    "  pm.expect(pm.response.code).to.be.within(200, 299);",
    "});",
    "pm.test('JSON has data', function () {",
    "  const json = pm.response.json();",
    "  pm.expect(json).to.have.property('data');",
    "});",
    "const d = pm.response.json().data;",
    "pm.test('quiz metadata', function () {",
    "  pm.expect(d).to.have.property('id');",
    "  pm.expect(d).to.have.property('title');",
    "  pm.expect(d.interactive_activities).to.be.an('array');",
    "});",
    "pm.test('official score fields present', function () {",
    "  pm.expect(d).to.have.property('official_score');",
    "  pm.expect(d).to.have.property('official_attempt_number');",
    "  pm.expect(d).to.have.property('official_attempt_id');",
    "  pm.expect(d).to.have.property('official_passed');",
    "  pm.expect(d).to.have.property('has_previous_attempt');",
    "});",
    "if (d.official_attempt_id) pm.collectionVariables.set('official_attempt_id', String(d.official_attempt_id));",
    "",
]

START_QUIZ_TEST = [
    "if (pm.response.code === 422) {",
    "  const json = pm.response.json();",
    "  pm.test('max attempts returns attempt ids', function () {",
    "    pm.expect(json.code).to.eql('MAX_ATTEMPTS_REACHED');",
    "    pm.expect(json).to.have.property('official_attempt_id');",
    "    pm.expect(json).to.have.property('last_attempt_id');",
    "  });",
    "  if (json.official_attempt_id) pm.collectionVariables.set('official_attempt_id', String(json.official_attempt_id));",
    "  if (json.last_attempt_id) pm.collectionVariables.set('attempt_id', String(json.last_attempt_id));",
    "  return;",
    "}",
    "pm.test('HTTP 2xx', function () {",
    "  pm.expect(pm.response.code).to.be.within(200, 299);",
    "});",
    "pm.test('JSON has data', function () {",
    "  const json = pm.response.json();",
    "  pm.expect(json).to.have.property('data');",
    "});",
    "function assertStudentSafe(payload) {",
    "  const raw = JSON.stringify(payload);",
    "  pm.expect(raw).to.not.include('\"is_correct\"');",
    "  pm.expect(raw).to.not.include('\"answer_key\"');",
    "  pm.expect(raw).to.not.include('activity_package_path');",
    "  pm.expect(raw).to.not.include('correct_option');",
    "}",
    "const d = pm.response.json().data;",
    "pm.test('attempt_id present', function () {",
    "  pm.expect(d.attempt_id || d.id).to.be.a('number');",
    "});",
    "pm.test('questions are student-safe', function () {",
    "  pm.expect(d.questions).to.be.an('array');",
    "  assertStudentSafe(d.questions);",
    "});",
    "pm.test('interactive_activities is an array', function () {",
    "  pm.expect(d.interactive_activities).to.be.an('array');",
    "});",
    "if (d.attempt_id || d.id) pm.collectionVariables.set('attempt_id', String(d.attempt_id || d.id));",
    "if (d.questions && d.questions[0] && d.questions[0].id) {",
    "  pm.collectionVariables.set('question_id', String(d.questions[0].id));",
    "  const opts = d.questions[0].options || [];",
    "  if (opts[0] && opts[0].id) pm.collectionVariables.set('option_id', String(opts[0].id));",
    "}",
    "if (d.interactive_activities && d.interactive_activities[0]) {",
    "  pm.collectionVariables.set('activity_id', String(d.interactive_activities[0].id));",
    "}",
    "",
]

AUTH_EMAIL_ITEMS = [
    {
        "name": "Forgot Password",
        "request": {
            "method": "POST",
            "header": [
                {"key": "Accept", "value": "application/json"},
                {"key": "Content-Type", "value": "application/json"},
            ],
            "url": "{{baseUrl}}/api/v1/auth/forgot-password",
            "description": "Sends reset link if account exists. Always returns generic success (no email enumeration).",
            "auth": {"type": "noauth"},
            "body": {
                "mode": "raw",
                "raw": '{\n  "email": "demo@sciencestreetlab.com"\n}',
                "options": {"raw": {"language": "json"}},
            },
        },
        "response": [],
        "event": [
            {
                "listen": "test",
                "script": {
                    "type": "text/javascript",
                    "exec": [
                        "pm.test('HTTP 2xx', () => pm.expect(pm.response.code).to.be.within(200, 299));",
                        "",
                    ],
                },
            }
        ],
    },
    {
        "name": "Reset Password",
        "request": {
            "method": "POST",
            "header": [
                {"key": "Accept", "value": "application/json"},
                {"key": "Content-Type", "value": "application/json"},
            ],
            "url": "{{baseUrl}}/api/v1/auth/reset-password",
            "description": "Reset with token from email. Replace `token` with value from mail/log.",
            "auth": {"type": "noauth"},
            "body": {
                "mode": "raw",
                "raw": '{\n  "token": "paste-token-from-email",\n  "email": "demo@sciencestreetlab.com",\n  "password": "password",\n  "password_confirmation": "password"\n}',
                "options": {"raw": {"language": "json"}},
            },
        },
        "response": [],
    },
    {
        "name": "Resend Email Verification",
        "request": {
            "method": "POST",
            "header": [
                {"key": "Accept", "value": "application/json"},
                {"key": "Content-Type", "value": "application/json"},
            ],
            "url": "{{baseUrl}}/api/v1/auth/email/verification-notification",
            "description": "Resend verification email for authenticated user with unverified email. Rate limited.",
        },
        "response": [],
        "event": [
            {
                "listen": "test",
                "script": {
                    "type": "text/javascript",
                    "exec": [
                        "pm.test('HTTP 2xx or 429', () => pm.expect(pm.response.code).to.be.oneOf([200, 202, 204, 429]));",
                        "",
                    ],
                },
            }
        ],
    },
    {
        "name": "Login (plan demo student)",
        "request": {
            "method": "POST",
            "header": [
                {"key": "Accept", "value": "application/json"},
                {"key": "Content-Type", "value": "application/json"},
            ],
            "url": "{{baseUrl}}/api/v1/auth/login",
            "description": "Student with Physics Demo Plan (`CoursePlanDemoSeeder`). Saves `token`.",
            "auth": {"type": "noauth"},
            "body": {
                "mode": "raw",
                "raw": '{\n  "email": "plan-demo@sciencestreetlab.com",\n  "password": "password"\n}',
                "options": {"raw": {"language": "json"}},
            },
        },
        "response": [],
        "event": [
            {
                "listen": "test",
                "script": {
                    "type": "text/javascript",
                    "exec": [
                        "pm.test('HTTP 2xx', () => pm.expect(pm.response.code).to.be.within(200, 299));",
                        "const json = pm.response.json();",
                        "if (json.data && json.data.token) pm.collectionVariables.set('token', json.data.token);",
                        "if (json.data && json.data.user && json.data.user.id) pm.collectionVariables.set('user_id', String(json.data.user.id));",
                        "pm.test('email_verified flag present', () => {",
                        "  pm.expect(json.data.user).to.have.property('email_verified');",
                        "});",
                        "",
                    ],
                },
            }
        ],
    },
]

LEARNING_PLAN_ITEMS = [
    {
        "name": "Get Course Access",
        "request": {
            "method": "GET",
            "header": [{"key": "Accept", "value": "application/json"}],
            "url": "{{baseUrl}}/api/v1/courses/{{course_slug}}/access",
            "description": "Plan-based access summary: enrolled, active, uses_plan, plan name, entitled lesson/topic/quiz IDs.",
        },
        "response": [],
        "event": [
            {
                "listen": "test",
                "script": {
                    "type": "text/javascript",
                    "exec": [
                        "pm.test('HTTP 2xx', () => pm.expect(pm.response.code).to.be.within(200, 299));",
                        "const d = pm.response.json().data;",
                        "pm.test('access payload', () => {",
                        "  pm.expect(d).to.have.property('enrolled');",
                        "  pm.expect(d).to.have.property('uses_plan');",
                        "  if (d.plan && d.plan.id) pm.collectionVariables.set('course_plan_id', String(d.plan.id));",
                        "});",
                        "",
                    ],
                },
            }
        ],
    },
    {
        "name": "Course Leaderboard",
        "request": {
            "method": "GET",
            "header": [{"key": "Accept", "value": "application/json"}],
            "url": {
                "raw": "{{baseUrl}}/api/v1/courses/{{course_slug}}/leaderboard?page=1&per_page=20",
                "host": ["{{baseUrl}}"],
                "path": ["api", "v1", "courses", "{{course_slug}}", "leaderboard"],
                "query": [
                    {"key": "page", "value": "1"},
                    {"key": "per_page", "value": "20"},
                ],
            },
            "description": "Ranks learners by average **official** quiz score. Auth optional — include Bearer for `current_user` rank.",
            "auth": {"type": "noauth"},
        },
        "response": [],
        "event": [
            {
                "listen": "test",
                "script": {
                    "type": "text/javascript",
                    "exec": [
                        "pm.test('HTTP 2xx', () => pm.expect(pm.response.code).to.be.within(200, 299));",
                        "const body = pm.response.json();",
                        "pm.test('leaderboard shape', () => {",
                        "  pm.expect(body).to.have.property('leaderboard');",
                        "  pm.expect(body.leaderboard).to.be.an('array');",
                        "  pm.expect(body).to.have.property('meta');",
                        "});",
                        "",
                    ],
                },
            }
        ],
    },
    {
        "name": "Get Course Plans",
        "request": {
            "method": "GET",
            "header": [{"key": "Accept", "value": "application/json"}],
            "url": {
                "raw": "{{baseUrl}}/api/v1/courses/{{course_slug}}/plans",
                "host": ["{{baseUrl}}"],
                "path": ["api", "v1", "courses", "{{course_slug}}", "plans"],
            },
            "description": "Public listing of active course plans for a published course. No auth required.\nReturns id, name, description, price, currency, is_lifetime, duration_days, max_quiz_attempts, grant_certificate.",
            "auth": {"type": "noauth"},
        },
        "response": [],
        "event": [
            {
                "listen": "test",
                "script": {
                    "type": "text/javascript",
                    "exec": [
                        "pm.test('HTTP 2xx', () => pm.expect(pm.response.code).to.be.within(200, 299));",
                        "const body = pm.response.json();",
                        "pm.test('plans array', () => {",
                        "  pm.expect(body).to.have.property('data');",
                        "  pm.expect(body.data).to.be.an('array');",
                        "});",
                        "if (body.data.length) {",
                        "  pm.collectionVariables.set('course_plan_id', String(body.data[0].id));",
                        "  const plan = body.data[0];",
                        "  pm.test('plan fields', () => {",
                        "    pm.expect(plan).to.have.property('price');",
                        "    pm.expect(plan).to.have.property('currency');",
                        "    pm.expect(plan).to.have.property('is_lifetime');",
                        "  });",
                        "}",
                        "",
                    ],
                },
            }
        ],
    },
    {
        "name": "Enroll via Course Plan",
        "request": {
            "method": "POST",
            "header": [
                {"key": "Accept", "value": "application/json"},
                {"key": "Content-Type", "value": "application/json"},
            ],
            "url": "{{baseUrl}}/api/v1/courses/{{plan_demo_course_slug}}/plans/{{course_plan_id}}/enroll",
            "description": "Enroll via a free course plan (snapshots entitlements). Use after Filament creates a plan or `CoursePlanDemoSeeder`.",
            "body": {"mode": "raw", "raw": "{}", "options": {"raw": {"language": "json"}}},
        },
        "response": [],
        "event": [
            {
                "listen": "test",
                "script": {
                    "type": "text/javascript",
                    "exec": [
                        "pm.test('HTTP 2xx or already enrolled', () => pm.expect(pm.response.code).to.be.oneOf([200, 201, 409, 422]));",
                        "if (pm.response.code < 300) {",
                        "  const d = pm.response.json().data;",
                        "  if (d && d.id) pm.collectionVariables.set('enrollment_id', String(d.id));",
                        "}",
                        "",
                    ],
                },
            }
        ],
    },
]

LEARNING_ACCESS_ITEMS = LEARNING_PLAN_ITEMS[:3]
OFFICIAL_RESULT_ITEM = {
    "name": "Get Official Quiz Result",
    "request": {
        "method": "GET",
        "header": [{"key": "Accept", "value": "application/json"}],
        "url": "{{baseUrl}}/api/v1/quiz-attempts/{{official_attempt_id}}/result",
        "description": "Fetch the **official** (first submitted) attempt result. Uses `official_attempt_id` from submit/start max-attempts response.",
    },
    "response": [],
    "event": [
        {
            "listen": "test",
            "script": {"type": "text/javascript", "exec": QUIZ_RESULT_TEST[:]},
        }
    ],
}

CURRICULUM_TEST = [
    "pm.test('HTTP 2xx', function () {",
    "  pm.expect(pm.response.code).to.be.within(200, 299);",
    "});",
    "pm.test('JSON has data', function () {",
    "  const json = pm.response.json();",
    "  pm.expect(json).to.have.property('data');",
    "});",
    "const data = pm.response.json().data;",
    "pm.test('curriculum has lessons', function () {",
    "  pm.expect(data.lessons).to.be.an('array');",
    "});",
    "pm.test('lessons include interactive_activities', function () {",
    "  const lesson = data.lessons.find(l => Array.isArray(l.interactive_activities) && l.interactive_activities.length);",
    "  pm.expect(lesson, 'at least one lesson with activities').to.exist;",
    "});",
    "function lessonBySlug(slug) {",
    "  return (data.lessons || []).find(l => l.slug === slug);",
    "}",
    "function firstActivity(lesson) {",
    "  return lesson && lesson.interactive_activities && lesson.interactive_activities[0];",
    "}",
    "function mixedQuizForLesson(lesson) {",
    "  if (!lesson || !lesson.quizzes || !lesson.quizzes.length) return null;",
    "  const mixed = lesson.quizzes.find(q => String(q.title || '').toLowerCase().includes('mixed'));",
    "  return mixed || lesson.quizzes[lesson.quizzes.length - 1];",
    "}",
    "function setActivityVars(lesson, slugKey, activityKey) {",
    "  const act = firstActivity(lesson);",
    "  if (act && act.id) {",
    "    pm.collectionVariables.set(activityKey, String(act.id));",
    "    if (lesson && lesson.id) pm.collectionVariables.set(slugKey + '_lesson_id', String(lesson.id));",
    "  }",
    "}",
    "const plantLesson = lessonBySlug('intro-cells');",
    "setActivityVars(plantLesson, 'plant_growth', 'plant_growth_activity_id');",
    "const lightLesson = lessonBySlug('light-reflection');",
    "setActivityVars(lightLesson, 'light', 'light_lab_activity_id');",
    "const soundLesson = lessonBySlug('energy');",
    "setActivityVars(soundLesson, 'sound', 'sound_lab_activity_id');",
    "const forcesLesson = lessonBySlug('forces');",
    "if (forcesLesson && forcesLesson.interactive_activities) {",
    "  forcesLesson.interactive_activities.forEach(function (a) {",
    "    const t = String(a.title || '').toLowerCase();",
    "    if (t.includes('castle') || t.includes('قلعة')) pm.collectionVariables.set('rubber_castle_activity_id', String(a.id));",
    "    if (t.includes('race') || t.includes('سباق') || t.includes('car')) pm.collectionVariables.set('rubber_race_activity_id', String(a.id));",
    "  });",
    "  if (forcesLesson.id) pm.collectionVariables.set('forces_lesson_id', String(forcesLesson.id));",
    "  const fq = mixedQuizForLesson(forcesLesson);",
    "  if (fq) pm.collectionVariables.set('physics_forces_mixed_quiz_id', String(fq.id));",
    "}",
    "if (lightLesson) {",
    "  const lq = mixedQuizForLesson(lightLesson);",
    "  if (lq) pm.collectionVariables.set('physics_light_mixed_quiz_id', String(lq.id));",
    "  if (lightLesson.id) pm.collectionVariables.set('physics_lesson_id', String(lightLesson.id));",
    "}",
    "const firstLesson = data.lessons.find(l => !l.is_locked) || data.lessons[0];",
    "if (firstLesson) {",
    "  pm.collectionVariables.set('lesson_id', String(firstLesson.id));",
    "  if (firstLesson.quizzes && firstLesson.quizzes.length) {",
    "    pm.collectionVariables.set('quiz_id', String(firstLesson.quizzes[0].id));",
    "  }",
    "}",
    "const mixed = data.lessons.find(l => (l.interactive_activities || []).length && (l.quizzes || []).length);",
    "if (mixed && mixed.quizzes && mixed.quizzes.length) {",
    "  const mq = mixedQuizForLesson(mixed);",
    "  if (mq) pm.collectionVariables.set('mixed_quiz_id', String(mq.id));",
    "}",
    "const activityLesson = lightLesson || plantLesson || data.lessons.find(l => (l.interactive_activities || []).length);",
    "if (activityLesson) {",
    "  pm.collectionVariables.set('lesson_id', String(activityLesson.id));",
    "  const act = firstActivity(activityLesson);",
    "  if (act) pm.collectionVariables.set('activity_id', String(act.id));",
    "}",
    "if (data.course_id) pm.collectionVariables.set('course_id', String(data.course_id));",
    "if (data.enrollment_id) pm.collectionVariables.set('enrollment_id', String(data.enrollment_id));",
    "",
]

FLAT_RESULT_BODY = (
    '{\n'
    '  "completed": true,\n'
    '  "score": 40,\n'
    '  "max_score": 50,\n'
    '  "percentage": 80,\n'
    '  "time_spent_seconds": 320,\n'
    '  "challenges_completed": 5,\n'
    '  "total_challenges": 5,\n'
    '  "result": {}\n'
    '}'
)

PROGRESS_ITEM = {
    "name": "Submit linked activity progress",
    "request": {
        "method": "POST",
        "header": [
            {"key": "Accept", "value": "application/json"},
            {"key": "Content-Type", "value": "application/json"},
        ],
        "body": {
            "mode": "raw",
            "raw": '{\n  "completed_challenges": 2,\n  "total_challenges": 5,\n  "percentage": 40\n}',
            "options": {"raw": {"language": "json"}},
        },
        "url": "{{baseUrl}}/api/v1/interactive-activity-attempts/{{activity_attempt_id}}/progress",
        "description": "Simulates postMessage PROGRESS from the HTML activity iframe.\nPlatform stores client-reported counts only — no game logic on server.",
    },
    "response": [],
    "event": [
        {
            "listen": "test",
            "script": {
                "type": "text/javascript",
                "exec": [
                    "pm.test('HTTP 2xx', function () { pm.expect(pm.response.code).to.be.within(200, 299); });",
                    "const d = pm.response.json().data;",
                    "pm.test('progress saved', function () {",
                    "  pm.expect(d.progress.completed_challenges).to.eql(2);",
                    "  pm.expect(d.progress.total_challenges).to.eql(5);",
                    "  pm.expect(d.progress.percentage).to.eql(40);",
                    "});",
                    "",
                ],
            },
        }
    ],
}

PHYSICS_CURRICULUM = {
    "name": "Physics lab curriculum",
    "request": {
        "method": "GET",
        "header": [{"key": "Accept", "value": "application/json"}],
        "url": "{{baseUrl}}/api/v1/courses/{{physics_course_slug}}/curriculum",
        "description": "Populates light_lab_activity_id (#4), sound_lab_activity_id (#6), rubber_castle/race IDs, physics_lesson_id, mixed quiz IDs.\nLessons: motion, forces, light-reflection, energy.",
    },
    "response": [],
    "event": [
        {
            "listen": "test",
            "script": {"type": "text/javascript", "exec": CURRICULUM_TEST},
        }
    ],
}

FULL_WORKFLOW = {
    "name": "Full Activity Workflow (Light Lab)",
    "description": "End-to-end flow for standalone HTML activity #4 (مختبر الضوء).\nRun after Login + Physics lab curriculum.\nOrder: Launch → Start attempt → Progress → Result → Get result.",
    "item": [
        {
            "name": "1. Launch Light Lab",
            "request": {
                "method": "GET",
                "header": [{"key": "Accept", "value": "application/json"}],
                "url": "{{baseUrl}}/api/v1/interactive-activities/{{light_lab_activity_id}}/launch",
            },
            "event": [
                {
                    "listen": "test",
                    "script": {
                        "type": "text/javascript",
                        "exec": [
                            "pm.test('HTTP 2xx', () => pm.expect(pm.response.code).to.be.within(200, 299));",
                            "const d = pm.response.json().data;",
                            "pm.test('signed url + postMessage protocol', () => {",
                            "  pm.expect(d.url).to.include('signature=');",
                            "  pm.expect(d.sandbox).to.eql('allow-scripts');",
                            "  pm.expect(d.protocol).to.eql('postMessage');",
                            "  pm.expect(d.post_message_events).to.be.an('array');",
                            "  pm.expect(d.post_message_events).to.include('ACTIVITY_COMPLETED');",
                            "});",
                            "pm.collectionVariables.set('activity_id', pm.collectionVariables.get('light_lab_activity_id'));",
                            "",
                        ],
                    },
                }
            ],
        },
        {
            "name": "2. Start Light Lab attempt",
            "request": {
                "method": "POST",
                "header": [
                    {"key": "Accept", "value": "application/json"},
                    {"key": "Content-Type", "value": "application/json"},
                ],
                "body": {"mode": "raw", "raw": "{}"},
                "url": "{{baseUrl}}/api/v1/interactive-activities/{{light_lab_activity_id}}/attempts",
            },
            "event": [
                {
                    "listen": "test",
                    "script": {
                        "type": "text/javascript",
                        "exec": [
                            "pm.test('HTTP 201', () => pm.expect(pm.response.code).to.eql(201));",
                            "const d = pm.response.json().data;",
                            "pm.test('attempt + launch_url', () => {",
                            "  pm.expect(d.attempt_id).to.be.a('number');",
                            "  pm.expect(d.launch_url || d.launch.url).to.include('signature=');",
                            "});",
                            "pm.collectionVariables.set('activity_attempt_id', String(d.attempt_id));",
                            "pm.collectionVariables.set('activity_id', String(d.activity_id));",
                            "",
                        ],
                    },
                }
            ],
        },
        {
            "name": "3. Submit progress (2/5 challenges)",
            "request": {
                "method": "POST",
                "header": [
                    {"key": "Accept", "value": "application/json"},
                    {"key": "Content-Type", "value": "application/json"},
                ],
                "body": {
                    "mode": "raw",
                    "raw": '{\n  "completed_challenges": 2,\n  "total_challenges": 5,\n  "percentage": 40\n}',
                },
                "url": "{{baseUrl}}/api/v1/interactive-activity-attempts/{{activity_attempt_id}}/progress",
            },
            "event": [
                {
                    "listen": "test",
                    "script": {
                        "type": "text/javascript",
                        "exec": [
                            "pm.test('HTTP 2xx', () => pm.expect(pm.response.code).to.be.within(200, 299));",
                            "pm.expect(pm.response.json().data.progress.percentage).to.eql(40);",
                            "",
                        ],
                    },
                }
            ],
        },
        {
            "name": "4. Submit ACTIVITY_COMPLETED result",
            "request": {
                "method": "POST",
                "header": [
                    {"key": "Accept", "value": "application/json"},
                    {"key": "Content-Type", "value": "application/json"},
                ],
                "body": {"mode": "raw", "raw": FLAT_RESULT_BODY},
                "url": "{{baseUrl}}/api/v1/interactive-activity-attempts/{{activity_attempt_id}}/result",
                "description": "Flat payload matching postMessage ACTIVITY_COMPLETED from HTML activity.",
            },
            "event": [
                {
                    "listen": "test",
                    "script": {
                        "type": "text/javascript",
                        "exec": [
                            "pm.test('HTTP 2xx', () => pm.expect(pm.response.code).to.be.within(200, 299));",
                            "const d = pm.response.json().data;",
                            "pm.test('completion stored', () => {",
                            "  pm.expect(d.status).to.eql('completed');",
                            "  pm.expect(d.client_score).to.eql(40);",
                            "  pm.expect(d.max_score).to.eql(50);",
                            "  pm.expect(d.percentage).to.eql(80);",
                            "});",
                            "",
                        ],
                    },
                }
            ],
        },
        {
            "name": "5. Get stored result",
            "request": {
                "method": "GET",
                "header": [{"key": "Accept", "value": "application/json"}],
                "url": "{{baseUrl}}/api/v1/interactive-activity-attempts/{{activity_attempt_id}}/result",
            },
            "event": [
                {
                    "listen": "test",
                    "script": {
                        "type": "text/javascript",
                        "exec": [
                            "pm.test('HTTP 2xx', () => pm.expect(pm.response.code).to.be.within(200, 299));",
                            "const d = pm.response.json().data;",
                            "pm.test('final result', () => {",
                            "  pm.expect(d.attempt_id).to.be.a('number');",
                            "  pm.expect(d.activity_id).to.eql(Number(pm.collectionVariables.get('light_lab_activity_id')));",
                            "  pm.expect(d).to.include.keys('client_score', 'verified_score', 'percentage');",
                            "});",
                            "",
                        ],
                    },
                }
            ],
        },
    ],
}


def merge_variables(col: dict) -> None:
    existing = {v["key"] for v in col.get("variable", [])}
    for var in NEW_VARS:
        if var["key"] not in existing:
            col.setdefault("variable", []).append(deepcopy(var))


def walk_items(items: list, callback) -> None:
    for item in items:
        callback(item)
        if "item" in item:
            walk_items(item["item"], callback)


def update_curriculum_tests(col: dict) -> None:
    def patch(item: dict) -> None:
        name = item.get("name", "")
        if "curriculum" not in name.lower():
            return
        for ev in item.get("event", []):
            if ev.get("listen") == "test":
                ev["script"]["exec"] = CURRICULUM_TEST[:]

    walk_items(col.get("item", []), patch)


def update_result_bodies(col: dict) -> None:
    def patch(item: dict) -> None:
        req = item.get("request")
        if not req:
            return
        url = req.get("url", "")
        if isinstance(url, dict):
            url = url.get("raw", "")
        if "/interactive-activity-attempts/" in str(url) and str(url).endswith("/result"):
            if req.get("method") == "POST":
                body = req.setdefault("body", {})
                body["mode"] = "raw"
                body["raw"] = FLAT_RESULT_BODY
                body.setdefault("options", {"raw": {"language": "json"}})

    walk_items(col.get("item", []), patch)


def update_launch_tests_and_descriptions(col: dict) -> None:
    def patch(item: dict) -> None:
        req = item.get("request")
        if not req:
            return
        url = req.get("url", "")
        if isinstance(url, dict):
            url = url.get("raw", "")
        if "/interactive-activities/" in str(url) and str(url).endswith("/launch"):
            desc = req.get("description", "")
            req["description"] = (
                "Returns signed sandboxed iframe URL + postMessage protocol.\n"
                "Sandbox: `allow-scripts` only (no cookies/session/parent DOM).\n"
                "postMessage events: READY, STARTED, PROGRESS, CHALLENGE_*, ACTIVITY_COMPLETED, RETRY, ERROR."
            )
            for ev in item.get("event", []):
                if ev.get("listen") != "test":
                    continue
                exec_lines = ev["script"]["exec"]
                if any("post_message_events" in line for line in exec_lines):
                    continue
                insert_at = next(
                    (i for i, line in enumerate(exec_lines) if "does not expose filesystem" in line),
                    len(exec_lines) - 1,
                )
                exec_lines[insert_at:insert_at] = [
                    "pm.test('postMessage protocol listed', function () {",
                    "  pm.expect(d.sandbox).to.eql('allow-scripts');",
                    "  pm.expect(d.protocol).to.eql('postMessage');",
                    "  pm.expect(d.post_message_events).to.be.an('array');",
                    "  pm.expect(d.post_message_events).to.include('PROGRESS');",
                    "  pm.expect(d.post_message_events).to.include('ACTIVITY_COMPLETED');",
                    "});",
                ]

    walk_items(col.get("item", []), patch)


def add_progress_to_mixed_quiz(col: dict) -> None:
    def patch_folder(item: dict) -> None:
        if item.get("name") != "Mixed Quiz (questions + activities)":
            return
        names = {i.get("name") for i in item.get("item", [])}
        if "Submit linked activity progress" in names:
            return
        items = item["item"]
        idx = next(
            (i for i, x in enumerate(items) if x.get("name") == "Submit linked activity result"),
            len(items),
        )
        items.insert(idx, deepcopy(PROGRESS_ITEM))
        desc = item.get("description", "")
        if "Submit activity progress" not in desc:
            item["description"] = desc.replace(
                "3) Submit activity result",
                "3) Submit activity progress  4) Submit activity result",
            ).replace(
                "4) Submit quiz  5) Get result",
                "5) Submit quiz  6) Get result",
            )

    walk_items(col.get("item", []), patch_folder)


def find_folder(items: list, name: str) -> dict | None:
    for item in items:
        if item.get("name") == name:
            return item
        if "item" in item:
            found = find_folder(item["item"], name)
            if found:
                return found
    return None


def insert_physics_curriculum(col: dict) -> None:
    courses = find_folder(col.get("item", []), "Courses & Lessons")
    if not courses:
        courses = find_folder(col.get("item", []), "Learning")
    if not courses:
        return
    names = {i.get("name") for i in courses.get("item", [])}
    if "Physics lab curriculum" in names:
        return
    bio_idx = next(
        (i for i, x in enumerate(courses["item"]) if "biology lab curriculum" in x.get("name", "").lower()),
        len(courses["item"]),
    )
    courses["item"].insert(bio_idx + 1, deepcopy(PHYSICS_CURRICULUM))


def insert_full_workflow(col: dict) -> None:
    folder = find_folder(col.get("item", []), "Interactive Activities")
    if not folder:
        return
    names = {i.get("name") for i in folder.get("item", [])}
    if "Full Activity Workflow (Light Lab)" in names:
        return
    folder["item"].insert(0, deepcopy(FULL_WORKFLOW))


def update_progress_tests(col: dict) -> None:
    def patch(item: dict) -> None:
        if item.get("name") != "Submit activity progress":
            return
        for ev in item.get("event", []):
            if ev.get("listen") == "test":
                ev["script"]["exec"] = PROGRESS_ITEM["event"][0]["script"]["exec"][:]

    walk_items(col.get("item", []), patch)


def insert_items_after(folder: dict, after_name: str, new_items: list) -> None:
    names = {i.get("name") for i in folder.get("item", [])}
    to_add = [deepcopy(i) for i in new_items if i.get("name") not in names]
    if not to_add:
        return
    items = folder["item"]
    idx = next((i for i, x in enumerate(items) if x.get("name") == after_name), len(items))
    for offset, item in enumerate(to_add):
        items.insert(idx + 1 + offset, item)


def insert_auth_email_items(col: dict) -> None:
    auth = find_folder(col.get("item", []), "Auth")
    if not auth:
        return
    insert_items_after(auth, "Login (demo student)", AUTH_EMAIL_ITEMS)


def insert_learning_access_items(col: dict) -> None:
    learning = find_folder(col.get("item", []), "Learning")
    if not learning:
        return
    insert_items_after(learning, "Get Course Progress", LEARNING_ACCESS_ITEMS)
    insert_items_after(learning, "Get Course Plans", [deepcopy(LEARNING_PLAN_ITEMS[3])])


def insert_official_result_item(col: dict) -> None:
    assessment = find_folder(col.get("item", []), "Assessment")
    if not assessment:
        return
    names = {i.get("name") for i in assessment.get("item", [])}
    if OFFICIAL_RESULT_ITEM["name"] in names:
        return
    idx = next(
        (i for i, x in enumerate(assessment["item"]) if x.get("name") == "Quiz result (contract path)"),
        len(assessment["item"]),
    )
    assessment["item"].insert(idx + 1, deepcopy(OFFICIAL_RESULT_ITEM))


def update_get_quiz_tests(col: dict) -> None:
    def patch(item: dict) -> None:
        if item.get("name") != "Get Quiz":
            return
        item["request"]["description"] = (
            "Quiz metadata including interactive_activities.\n"
            "When authenticated with enrollment, also returns official_score, official_attempt_number, "
            "official_attempt_id, official_passed, has_previous_attempt (first successfully submitted attempt only)."
        )
        for ev in item.get("event", []):
            if ev.get("listen") == "test":
                ev["script"]["exec"] = QUIZ_SHOW_TEST[:]

    walk_items(col.get("item", []), patch)


def update_start_quiz_tests(col: dict) -> None:
    def patch(item: dict) -> None:
        if item.get("name") != "Start Quiz Attempt":
            return
        desc = item.get("request", {}).get("description", "")
        item["request"]["description"] = (
            "POST /quizzes/{quiz}/attempts — starts or resumes (resuming resets session clock).\n"
            "422 MAX_ATTEMPTS_REACHED returns official_attempt_id + last_attempt_id.\n"
            "Freezes questions."
        )
        for ev in item.get("event", []):
            if ev.get("listen") == "test":
                ev["script"]["exec"] = START_QUIZ_TEST[:]

    walk_items(col.get("item", []), patch)


def update_quiz_submit_and_result(col: dict) -> None:
    def patch(item: dict) -> None:
        name = item.get("name", "")
        req = item.get("request")
        if not req:
            return
        if name == "Submit quiz (contract path)":
            req["description"] = (
                "Finish + grade. Optional `time_spent_seconds` (client active time).\n"
                "Returns official score fields + question_results."
            )
            body = req.setdefault("body", {})
            body["mode"] = "raw"
            body["raw"] = QUIZ_SUBMIT_BODY
            body.setdefault("options", {"raw": {"language": "json"}})
            for ev in item.get("event", []):
                if ev.get("listen") == "test":
                    ev["script"]["exec"] = QUIZ_RESULT_TEST[:]
        elif name == "Quiz result (contract path)":
            req["description"] = (
                "Post-review result. Includes is_official, official_score, official_attempt_number, "
                "official_passed, current_attempt_score, question_results."
            )
            for ev in item.get("event", []):
                if ev.get("listen") == "test":
                    ev["script"]["exec"] = QUIZ_RESULT_TEST[:]

    walk_items(col.get("item", []), patch)


def update_login_tests(col: dict) -> None:
    def patch(item: dict) -> None:
        if item.get("name") not in ("Login", "Login (demo student)", "Register"):
            return
        for ev in item.get("event", []):
            if ev.get("listen") != "test":
                continue
            exec_lines = ev["script"]["exec"]
            if any("email_verified present" in line for line in exec_lines):
                continue
            exec_lines.extend(
                [
                    "try {",
                    "  const _j = pm.response.json();",
                    "  if (_j.data && _j.data.user) pm.test('email_verified present', () => pm.expect(_j.data.user).to.have.property('email_verified'));",
                    "} catch (e) {}",
                    "",
                ]
            )

    walk_items(col.get("item", []), patch)


def process_collection(path: Path) -> None:
    col = json.loads(path.read_text(encoding="utf-8"))
    col["info"]["description"] = MAIN_API_DESCRIPTION
    merge_variables(col)
    update_curriculum_tests(col)
    update_result_bodies(col)
    update_launch_tests_and_descriptions(col)
    update_progress_tests(col)
    add_progress_to_mixed_quiz(col)
    insert_physics_curriculum(col)
    insert_full_workflow(col)
    insert_auth_email_items(col)
    insert_learning_access_items(col)
    insert_official_result_item(col)
    update_get_quiz_tests(col)
    update_start_quiz_tests(col)
    update_quiz_submit_and_result(col)
    update_login_tests(col)
    path.write_text(json.dumps(col, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"updated {path.name}")


def main() -> None:
    for path in COLLECTIONS:
        process_collection(path)


if __name__ == "__main__":
    main()
