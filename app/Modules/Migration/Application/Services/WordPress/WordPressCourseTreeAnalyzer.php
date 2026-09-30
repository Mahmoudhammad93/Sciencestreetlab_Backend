<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use Illuminate\Support\Facades\DB;

/**
 * Discover LearnDash course → lesson → topic → quiz trees from wordpress_legacy.
 *
 * Authoritative structure/order: wp_postmeta.ld_course_steps (serialized).
 * Fallback parent: wp_postmeta.course_id on lessons when steps empty (approved course ids only).
 */
final class WordPressCourseTreeAnalyzer
{
    public function __construct(
        private readonly WordPressConnectionService $connection,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(): array
    {
        $this->connection->assertProductionPrefix();
        $conn = $this->connection->connectionName();
        $posts = $this->connection->table('posts');
        $postmeta = $this->connection->table('postmeta');

        $courses = DB::connection($conn)->table($posts)
            ->where('post_type', 'sfwd-courses')
            ->where('post_status', 'publish')
            ->orderBy('ID')
            ->get(['ID', 'post_title', 'post_name', 'post_content', 'post_status']);

        $allLessonIds = DB::connection($conn)->table($posts)
            ->where('post_type', 'sfwd-lessons')->where('post_status', 'publish')
            ->orderBy('ID')
            ->pluck('ID')->map(fn ($id) => (int) $id)->all();
        $allTopicIds = DB::connection($conn)->table($posts)
            ->where('post_type', 'sfwd-topic')->where('post_status', 'publish')
            ->orderBy('ID')
            ->pluck('ID')->map(fn ($id) => (int) $id)->all();
        $allQuizIds = DB::connection($conn)->table($posts)
            ->where('post_type', 'sfwd-quiz')->where('post_status', 'publish')
            ->orderBy('ID')
            ->pluck('ID')->map(fn ($id) => (int) $id)->all();

        $lessonCourseMeta = $this->loadMetaMap($conn, $postmeta, $allLessonIds, 'course_id');
        $topicCourseMeta = $this->loadMetaMap($conn, $postmeta, $allTopicIds, 'course_id');
        $topicLessonMeta = $this->loadMetaMap($conn, $postmeta, $allTopicIds, 'lesson_id');
        $quizCourseMeta = $this->loadMetaMap($conn, $postmeta, $allQuizIds, 'course_id');
        $quizLessonMeta = $this->loadMetaMap($conn, $postmeta, $allQuizIds, 'lesson_id');

        $lessonsInSteps = [];
        $topicsInSteps = [];
        $quizzesInSteps = [];
        $conflicts = [];
        $courseReports = [];
        $fallbackCourses = [];
        $approvedFallback = array_map('intval', config('wordpress.course_tree.fallback_course_ids', [38266]));
        $emptySourceIds = array_map('intval', config('wordpress.course_tree.empty_source_course_ids', [38568]));

        foreach ($courses as $course) {
            $cid = (int) $course->ID;
            $raw = DB::connection($conn)->table($postmeta)
                ->where('post_id', $cid)
                ->where('meta_key', 'ld_course_steps')
                ->value('meta_value');
            $data = $this->unserializeArray($raw);

            $lessonOrder = [];
            $topicCount = 0;
            $quizCount = 0;
            $courseLevelQuizzes = [];
            $lessonQuizCount = 0;
            $source = 'ld_course_steps';
            $flags = [];

            $stepsLessons = $data['steps']['h']['sfwd-lessons'] ?? null;
            if (is_array($stepsLessons) && $stepsLessons !== []) {
                $sort = 0;
                foreach ($stepsLessons as $lid => $children) {
                    $lid = (int) $lid;
                    $topicsForLesson = [];
                    $quizzesForLesson = [];
                    $topicSort = 0;

                    if (is_array($children)) {
                        foreach (($children['sfwd-topic'] ?? []) as $tid => $_) {
                            $tid = (int) $tid;
                            $topicsInSteps[$tid] = $lid;
                            $topicsForLesson[] = ['legacy_id' => $tid, 'sort_order' => $topicSort++];
                            $topicCount++;
                            if (isset($topicLessonMeta[$tid]) && $topicLessonMeta[$tid] !== $lid && $topicLessonMeta[$tid] !== 0) {
                                $conflicts[] = [
                                    'code' => 'TREE_SOURCE_CONFLICT',
                                    'entity' => 'topic',
                                    'id' => $tid,
                                    'steps_lesson' => $lid,
                                    'meta_lesson' => $topicLessonMeta[$tid],
                                ];
                            }
                        }
                        foreach (($children['sfwd-quiz'] ?? []) as $qid => $_) {
                            $qid = (int) $qid;
                            $quizzesInSteps[$qid] = "lesson:{$lid}";
                            $quizzesForLesson[] = $qid;
                            $lessonQuizCount++;
                            $quizCount++;
                        }
                    }

                    $lessonOrder[] = [
                        'legacy_id' => $lid,
                        'sort_order' => $sort++,
                        'topics' => $topicsForLesson,
                        'lesson_level_quiz_ids' => $quizzesForLesson,
                    ];
                    $lessonsInSteps[$lid] = $cid;

                    if (isset($lessonCourseMeta[$lid]) && $lessonCourseMeta[$lid] !== $cid && $lessonCourseMeta[$lid] !== 0) {
                        $conflicts[] = [
                            'code' => 'TREE_SOURCE_CONFLICT',
                            'entity' => 'lesson',
                            'id' => $lid,
                            'steps_course' => $cid,
                            'meta_course' => $lessonCourseMeta[$lid],
                        ];
                    }
                }
                foreach (($data['steps']['h']['sfwd-quiz'] ?? []) as $qid => $_) {
                    $qid = (int) $qid;
                    $quizzesInSteps[$qid] = "course:{$cid}";
                    $courseLevelQuizzes[] = $qid;
                    $quizCount++;
                }
            } elseif (in_array($cid, $approvedFallback, true)) {
                // Approved fallback only (course 38266): course_id meta, sort by lesson ID ascending.
                $source = 'course_id_meta_fallback';
                $fallbackCourses[] = $cid;
                $fallbackLessonIds = [];
                foreach ($allLessonIds as $lid) {
                    if (($lessonCourseMeta[$lid] ?? 0) === $cid) {
                        $fallbackLessonIds[] = $lid;
                    }
                }
                sort($fallbackLessonIds, SORT_NUMERIC);
                $sort = 0;
                foreach ($fallbackLessonIds as $lid) {
                    $lessonOrder[] = [
                        'legacy_id' => $lid,
                        'sort_order' => $sort++,
                        'topics' => [],
                        'lesson_level_quiz_ids' => [],
                    ];
                    $lessonsInSteps[$lid] = $cid;
                }
            } elseif (in_array($cid, $emptySourceIds, true) || $lessonOrder === []) {
                $flags[] = 'EMPTY_SOURCE_COURSE';
                $source = 'empty_published_course';
            }

            if ($lessonOrder === [] && in_array($cid, $emptySourceIds, true) && ! in_array('EMPTY_SOURCE_COURSE', $flags, true)) {
                $flags[] = 'EMPTY_SOURCE_COURSE';
                $source = 'empty_published_course';
            }

            $courseReports[] = [
                'course_id' => $cid,
                'title' => $course->post_title,
                'slug' => $course->post_name,
                'content' => $course->post_content,
                'status' => $course->post_status,
                'relationship_source' => $source,
                'lesson_count' => count($lessonOrder),
                'topic_count' => $topicCount,
                'quiz_count' => $quizCount,
                'lesson_level_quizzes' => $lessonQuizCount,
                'course_level_quizzes' => $courseLevelQuizzes,
                'course_level_quiz_policy' => $courseLevelQuizzes !== []
                    ? 'COURSE_LEVEL_QUIZ_REQUIRES_MODEL_DECISION'
                    : null,
                'flags' => $flags,
                'lessons' => $lessonOrder,
            ];
        }

        $orphanLessons = [];
        foreach ($allLessonIds as $lid) {
            if (! isset($lessonsInSteps[$lid])) {
                $orphanLessons[] = [
                    'id' => $lid,
                    'course_id_meta' => $lessonCourseMeta[$lid] ?? null,
                    'code' => 'ORPHAN_LESSON',
                ];
            }
        }
        $orphanTopics = [];
        foreach ($allTopicIds as $tid) {
            if (! isset($topicsInSteps[$tid])) {
                $orphanTopics[] = [
                    'id' => $tid,
                    'lesson_id_meta' => $topicLessonMeta[$tid] ?? null,
                    'course_id_meta' => $topicCourseMeta[$tid] ?? null,
                    'code' => empty($topicLessonMeta[$tid]) ? 'MISSING_PARENT' : 'ORPHAN_TOPIC',
                ];
            }
        }
        $orphanQuizzes = [];
        foreach ($allQuizIds as $qid) {
            if (! isset($quizzesInSteps[$qid])) {
                $orphanQuizzes[] = [
                    'id' => $qid,
                    'lesson_id_meta' => $quizLessonMeta[$qid] ?? null,
                    'course_id_meta' => $quizCourseMeta[$qid] ?? null,
                    'code' => 'ORPHAN_QUIZ',
                ];
            }
        }

        $lessonsInTree = array_sum(array_map(fn ($c) => $c['lesson_count'], $courseReports));
        $topicsInTree = array_sum(array_map(fn ($c) => $c['topic_count'], $courseReports));
        $quizzesInTree = array_sum(array_map(fn ($c) => $c['quiz_count'], $courseReports));
        $courseLevelTotal = array_sum(array_map(fn ($c) => count($c['course_level_quizzes'] ?? []), $courseReports));
        $emptyTrees = array_values(array_filter($courseReports, fn ($c) => in_array('EMPTY_SOURCE_COURSE', $c['flags'] ?? [], true)));

        $stagingApproved = (bool) config('wordpress.course_tree.staging_approved', false);
        if ($conflicts !== []) {
            $status = 'COURSE_TREE_STILL_BLOCKED';
        } elseif ($courseLevelTotal > 0) {
            $status = 'COURSE_LEVEL_QUIZ_REQUIRES_MODEL_DECISION';
        } elseif ($stagingApproved) {
            $status = 'COURSE_TREE_APPROVED_FOR_STAGING';
        } else {
            $status = 'COURSE_TREE_READY_FOR_APPROVAL';
        }

        return [
            'authoritative_source' => 'wp_postmeta.meta_key=ld_course_steps (APPROVED)',
            'fallback_source' => 'wp_postmeta.course_id on sfwd-lessons when ld_course_steps empty (APPROVED for 38266)',
            'ordering' => [
                'source' => 'array key order inside ld_course_steps.steps.h.sfwd-lessons / sfwd-topic',
                'fallback_order' => 'lesson ID ascending for approved fallback courses',
                'laravel_field' => 'sort_order',
                'menu_order' => 'NOT USED',
            ],
            'approved_policy' => [
                'exclude_orphans' => true,
                'empty_course_38568' => 'EMPTY_SOURCE_COURSE — preserve course with 0 lessons',
                'course_level_quizzes' => 'COURSE_LEVEL_QUIZ_REQUIRES_MODEL_DECISION — do not attach to last lesson',
                'lesson_level_quizzes' => 'INVENTORIED_ONLY — Assessment quiz CPT import not auto-implemented',
            ],
            'totals' => [
                'courses' => count($courseReports),
                'lessons_published' => count($allLessonIds),
                'topics_published' => count($allTopicIds),
                'quizzes_published' => count($allQuizIds),
                'lessons_in_tree' => $lessonsInTree,
                'topics_in_tree' => $topicsInTree,
                'quizzes_in_tree' => $quizzesInTree,
                'course_level_quizzes_in_tree' => $courseLevelTotal,
            ],
            'courses' => $courseReports,
            'fallback_course_ids' => $fallbackCourses,
            'empty_tree_courses' => $emptyTrees,
            'orphan_lessons_count' => count($orphanLessons),
            'orphan_topics_count' => count($orphanTopics),
            'orphan_quizzes_count' => count($orphanQuizzes),
            'orphan_lesson_ids' => array_column($orphanLessons, 'id'),
            'orphan_topic_ids' => array_column($orphanTopics, 'id'),
            'orphan_quiz_ids' => array_column($orphanQuizzes, 'id'),
            'orphan_lessons_sample' => array_slice($orphanLessons, 0, 20),
            'orphan_topics' => $orphanTopics,
            'conflicts' => $conflicts,
            'course_level_quizzes' => [
                'count' => $courseLevelTotal,
                'laravel_support' => 'Quiz::quizable morph is Lesson-oriented in Assessment services',
                'decision' => $courseLevelTotal > 0
                    ? 'COURSE_LEVEL_QUIZ_REQUIRES_MODEL_DECISION'
                    : 'NONE_IN_PUBLISHED_TREES',
            ],
            'proposed_laravel_mapping' => [
                'sfwd-courses' => 'courses (title/slug/is_published)',
                'sfwd-lessons in steps' => 'lessons.course_id + lessons.sort_order',
                'sfwd-topic in steps' => 'topics.lesson_id + topics.sort_order',
                'sfwd-quiz under lesson' => 'INVENTORIED_ONLY — do not auto-persist without Assessment mapping decision',
                'sfwd-quiz under course' => 'BLOCKED COURSE_LEVEL_QUIZ_REQUIRES_MODEL_DECISION',
                'orphan_*' => 'NOT MIGRATED (IDs preserved in report only)',
            ],
            'status' => $status,
            'wrote_to_database' => false,
        ];
    }

    /**
     * @param  list<int>  $postIds
     * @return array<int, int>
     */
    private function loadMetaMap(string $conn, string $postmeta, array $postIds, string $key): array
    {
        $out = [];
        if ($postIds === []) {
            return $out;
        }
        foreach (array_chunk($postIds, 500) as $chunk) {
            foreach (DB::connection($conn)->table($postmeta)
                ->whereIn('post_id', $chunk)
                ->where('meta_key', $key)
                ->get(['post_id', 'meta_value']) as $row) {
                $out[(int) $row->post_id] = (int) $row->meta_value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function unserializeArray(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($decoded) ? $decoded : null;
    }
}
