#!/usr/bin/env python3
"""Sync Postman collections with Interactive Activity API updates."""

from __future__ import annotations

import json
from copy import deepcopy
from pathlib import Path

COLLECTIONS = [
    Path(__file__).parent / "Question-Bank.postman_collection.json",
    Path(__file__).parent / "Science-Street-Lab-API.postman_collection.json",
]

DESCRIPTION = """Student-facing Question Bank, Quiz, and Interactive Activity APIs under `/api/v1` (Sanctum).

Never returns correct answers, answer keys, or grading internals on question payloads.
Interactive Activities are **complete standalone HTML/JS packages** (not a single MCQ). The platform hosts them in a sandboxed iframe (`allow-scripts`) and receives standardized postMessage events only.

**Demo seed:** `php artisan db:seed --class=AssessmentDemoCoursesSeeder`
**Login:** `demo@sciencestreetlab.com` / `password`

**Demo courses:** `intro-biology-lab`, `basic-physics-lab`, `basic-chemistry-lab`

**Seeded HTML activities (from `interactive examples/`):**
| ID | Key | Lesson slug | Title |
|----|-----|-------------|-------|
| #5 | plant-growth | intro-cells | رحلة نمو البذور |
| #4 | light-lab | light-reflection | مختبر الضوء |
| #6 | sound-lab | energy | مختبر الصوت |
| #7 | rubber-castle | forces | تحدي هدم القلعة |
| #8 | rubber-race | forces | سباق العربية |

**Recommended flow:** Login → Biology/Physics Lab Curriculum → Interactive Activities folder (or Full Activity Workflow).

**Variables:** `activity_id`, `activity_attempt_id` (interactive activity attempt; distinct from quiz `attempt_id`).

**Interactive Activity endpoints:**
- GET `/lessons/{lesson}/interactive-activities`
- GET `/interactive-activities/{activity}`
- GET `/interactive-activities/{activity}/launch`
- POST `/interactive-activities/{activity}/attempts`
- GET `/interactive-activity-attempts/{attempt}`
- POST `/interactive-activity-attempts/{attempt}/progress`
- POST `/interactive-activity-attempts/{attempt}/result`
- GET `/interactive-activity-attempts/{attempt}/result`

**postMessage events:** READY, STARTED, PROGRESS, CHALLENGE_STARTED, CHALLENGE_COMPLETED, ANSWER_SUBMITTED, ACTIVITY_COMPLETED, RETRY, ERROR"""

MAIN_API_DESCRIPTION = """REST API collection for Science Street Lab (Laravel `/api/v1`).

**Auth:** Laravel Sanctum Bearer token. Login/Register auto-save `token`.
**Base URL:** `{{baseUrl}}` (default `http://localhost:8000`).

Assessment demo (`php artisan db:seed --class=AssessmentDemoCoursesSeeder`):
- Login: `demo@sciencestreetlab.com` / `password`
- Courses: `intro-biology-lab`, `basic-physics-lab`, `basic-chemistry-lab`
- Run **Physics Lab Curriculum** or **Biology Lab Curriculum** to populate IDs.

**Interactive Activities** are complete HTML games from `interactive examples/` (Light Lab, Sound Lab, Plant Growth, Rubber Castle, Rubber Race). Hosted in sandboxed iframe; POST `.../progress` and `.../result` for client-reported state.

Student question payloads never include `is_correct` or `answer_key`."""

NEW_VARS = [
    {"key": "light_lab_activity_id", "value": "4"},
    {"key": "sound_lab_activity_id", "value": "6"},
    {"key": "plant_growth_activity_id", "value": "5"},
    {"key": "rubber_castle_activity_id", "value": "7"},
    {"key": "rubber_race_activity_id", "value": "8"},
    {"key": "physics_light_mixed_quiz_id", "value": "1"},
    {"key": "physics_forces_mixed_quiz_id", "value": "1"},
    {"key": "physics_lesson_id", "value": "1"},
]

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


def process_collection(path: Path, is_main: bool) -> None:
    col = json.loads(path.read_text(encoding="utf-8"))
    col["info"]["description"] = MAIN_API_DESCRIPTION if is_main else DESCRIPTION
    merge_variables(col)
    update_curriculum_tests(col)
    update_result_bodies(col)
    update_launch_tests_and_descriptions(col)
    update_progress_tests(col)
    add_progress_to_mixed_quiz(col)
    insert_physics_curriculum(col)
    insert_full_workflow(col)
    path.write_text(json.dumps(col, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"updated {path.name}")


def main() -> None:
    for path in COLLECTIONS:
        process_collection(path, is_main="Science-Street-Lab" in path.name)


if __name__ == "__main__":
    main()
