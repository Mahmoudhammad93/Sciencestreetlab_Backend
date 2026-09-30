<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LearnDash course CPT importer (sfwd-courses / lessons / topics).
 *
 * Dry-run writes NOTHING.
 * Tree policy is APPROVED_FOR_STAGING (config wordpress.course_tree.staging_approved).
 * Real persist creates courses/lessons/topics + legacy_import_maps with migration_run_id.
 *
 * Quizzes: inventoried only — Assessment quiz CPT import is NOT auto-implemented.
 * Course-level quizzes remain blocked if encountered.
 */
final class WordPressCourseImporter
{
    public const TREE_POLICY_SUPPRESS = 'SUPPRESS_TREE';

    public const TREE_POLICY_ABSORB = 'ABSORB_TREE';

    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressCourseTreeAnalyzer $treeAnalyzer,
        private readonly WordPressApprovedCollisionMapper $approvedCollisions,
        private readonly WordPressRealPersistGate $persistGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(bool $dryRun = false): array
    {
        $ready = $this->connection->assertReadyForImport('posts');
        if (! $ready['ok']) {
            return [
                'status' => 'blocked',
                'code' => $ready['reason'] ?? WordPressConnectionService::BLOCKED,
                'entity_type' => 'course',
                'dry_run' => $dryRun,
                'imported' => 0,
                'created' => 0,
                'wrote_to_database' => false,
                'message' => 'LearnDash course source not ready.',
                'inspect' => $ready['inspect'],
            ];
        }

        $this->connection->assertProductionPrefix();
        $conn = $this->connection->connectionName();
        $posts = $this->connection->table('posts');

        $counts = [];
        foreach (DB::connection($conn)->table($posts)
            ->whereIn('post_type', ['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'])
            ->select('post_type', 'post_status', DB::raw('COUNT(*) as c'))
            ->groupBy('post_type', 'post_status')
            ->get() as $row) {
            $counts[$row->post_type][$row->post_status] = (int) $row->c;
        }

        $tree = $this->treeAnalyzer->analyze();
        $treeApproved = ($tree['status'] ?? '') === 'COURSE_TREE_APPROVED_FOR_STAGING';
        $courseLevelBlocked = ($tree['status'] ?? '') === 'COURSE_LEVEL_QUIZ_REQUIRES_MODEL_DECISION'
            || (($tree['course_level_quizzes']['count'] ?? 0) > 0);

        if ($block = $this->persistGate->importerBlockIfUnauthorized($dryRun, 'course')) {
            $block['tree'] = $this->treeSummary($tree);
            $block['inspect'] = $ready['inspect'];

            return $block;
        }

        if (! $dryRun && ! $treeApproved) {
            return [
                'status' => 'blocked',
                'code' => $courseLevelBlocked
                    ? 'COURSE_LEVEL_QUIZ_REQUIRES_MODEL_DECISION'
                    : 'COURSE_TREE_REQUIRES_APPROVAL',
                'entity_type' => 'course',
                'dry_run' => false,
                'created' => 0,
                'imported' => 0,
                'wrote_to_database' => false,
                'tree' => $this->treeSummary($tree),
                'message' => 'Real course import blocked until tree approval / course-level quiz decision.',
                'inspect' => $ready['inspect'],
            ];
        }

        $postsById = $this->loadPostsById($conn, $posts, array_merge(
            array_map(fn ($c) => (int) $c['course_id'], $tree['courses'] ?? []),
            $this->collectLessonTopicIds($tree),
        ));

        $scanned = 0;
        $wouldCreate = 0;
        $wouldSkipMapped = 0;
        $wouldMapExisting = 0;
        $wouldFail = 0;
        $wouldDefer = 0;
        $slugMergeRequired = 0;
        $coursesCreated = 0;
        $lessonsCreated = 0;
        $topicsCreated = 0;
        $coursesSkipped = 0;
        $lessonsSkipped = 0;
        $topicsSkipped = 0;
        $lessonQuizzesInventoried = 0;
        $samples = [];
        $mergeSamples = [];
        $outcomeSamples = [];

        foreach ($tree['courses'] ?? [] as $courseNode) {
            $scanned++;
            $legacyId = (string) $courseNode['course_id'];
            $post = $postsById[(int) $legacyId] ?? null;
            $slug = (string) ($courseNode['slug'] ?? $post?->post_name ?? '');
            if ($slug === '') {
                $slug = 'wp-course-'.$legacyId;
            }

            $existingMap = $this->maps->find('course', $legacyId);
            $slugExists = Course::query()->where('slug', $slug)->exists();
            $approvedDecision = $this->approvedCollisions->courseDecision($legacyId);
            $treePolicy = $this->courseTreePolicy($legacyId);
            $outcome = MigrationImportOutcome::classifyCourseShell(
                $existingMap?->local_id !== null,
                $slugExists,
                $approvedDecision,
            );

            $payload = [
                'legacy_id' => $legacyId,
                'title' => (string) ($courseNode['title'] ?? $post?->post_title ?? ''),
                'slug' => $slug,
                'content' => (string) ($courseNode['content'] ?? $post?->post_content ?? ''),
                'status' => (string) ($courseNode['status'] ?? $post?->post_status ?? 'publish'),
                'relationship_source' => $courseNode['relationship_source'] ?? null,
                'flags' => $courseNode['flags'] ?? [],
                'lessons' => $courseNode['lessons'] ?? [],
                'tree_policy' => $treePolicy,
                'approved_decision' => $approvedDecision,
                'predicted_outcome' => $outcome,
            ];

            match ($outcome) {
                MigrationImportOutcome::WOULD_SKIP => $wouldSkipMapped++,
                MigrationImportOutcome::WOULD_MAP_EXISTING => $wouldMapExisting++,
                MigrationImportOutcome::WOULD_FAIL => $wouldFail++,
                MigrationImportOutcome::WOULD_DEFER => $wouldDefer++,
                default => $wouldCreate++,
            };

            if ($dryRun && count($outcomeSamples) < 12) {
                $outcomeSamples[] = [
                    'legacy_id' => $legacyId,
                    'slug' => $slug,
                    'outcome' => $outcome,
                    'tree_policy' => $treePolicy,
                    'approved_decision' => $approvedDecision,
                ];
            }

            if ($outcome === MigrationImportOutcome::WOULD_SKIP) {
                if (! $dryRun) {
                    $coursesSkipped++;
                    $result = $this->persistMappedCourseTree($payload, (int) $existingMap->local_id, $postsById);
                    $lessonsCreated += $result['lessons_created'];
                    $topicsCreated += $result['topics_created'];
                    $lessonsSkipped += $result['lessons_skipped'];
                    $topicsSkipped += $result['topics_skipped'];
                    $lessonQuizzesInventoried += $result['lesson_quizzes_inventoried'];
                } else {
                    foreach ($payload['lessons'] as $lessonNode) {
                        $lessonQuizzesInventoried += count($lessonNode['lesson_level_quiz_ids'] ?? []);
                    }
                }
            } elseif ($outcome === MigrationImportOutcome::WOULD_MAP_EXISTING) {
                // Map-only prediction / apply via approved local_slug; never CREATE_NEW.
                if (! $dryRun) {
                    $mapped = $this->applyApprovedMapExisting($legacyId, $payload);
                    if ($mapped !== null) {
                        $coursesSkipped++;
                        $result = $this->persistMappedCourseTree($payload, $mapped, $postsById);
                        $lessonsCreated += $result['lessons_created'];
                        $topicsCreated += $result['topics_created'];
                        $lessonsSkipped += $result['lessons_skipped'];
                        $topicsSkipped += $result['topics_skipped'];
                        $lessonQuizzesInventoried += $result['lesson_quizzes_inventoried'];
                    } else {
                        $wouldFail++;
                        $slugMergeRequired++;
                        if (count($mergeSamples) < 10) {
                            $mergeSamples[] = [
                                'legacy_id' => $legacyId,
                                'slug' => $slug,
                                'class' => 'APPROVED_MAP_LOCAL_SLUG_NOT_FOUND',
                                'outcome' => $outcome,
                            ];
                        }
                    }
                } else {
                    foreach ($payload['lessons'] as $lessonNode) {
                        $lessonQuizzesInventoried += count($lessonNode['lesson_level_quiz_ids'] ?? []);
                    }
                }
            } elseif ($outcome === MigrationImportOutcome::WOULD_FAIL) {
                $slugMergeRequired++;
                if (count($mergeSamples) < 10) {
                    $mergeSamples[] = [
                        'legacy_id' => $legacyId,
                        'slug' => $slug,
                        'class' => 'MERGE_REQUIRES_DECISION',
                        'outcome' => $outcome,
                    ];
                }
            } elseif ($outcome === MigrationImportOutcome::WOULD_CREATE) {
                if ($dryRun && count($samples) < 5) {
                    $samples[] = [
                        'legacy_id' => $legacyId,
                        'title' => $payload['title'],
                        'slug' => $slug,
                        'status' => $payload['status'],
                        'lesson_count' => count($payload['lessons']),
                        'flags' => $payload['flags'],
                        'outcome' => $outcome,
                        'tree_policy' => $treePolicy,
                    ];
                }
                if (! $dryRun) {
                    $result = $this->persistCourseTree($payload, $postsById);
                    if ($result['course_created']) {
                        $coursesCreated++;
                    }
                    $lessonsCreated += $result['lessons_created'];
                    $topicsCreated += $result['topics_created'];
                    $lessonsSkipped += $result['lessons_skipped'];
                    $topicsSkipped += $result['topics_skipped'];
                    $lessonQuizzesInventoried += $result['lesson_quizzes_inventoried'];
                } else {
                    foreach ($payload['lessons'] as $lessonNode) {
                        $lessonQuizzesInventoried += count($lessonNode['lesson_level_quiz_ids'] ?? []);
                    }
                }
            }
        }

        $wrote = ! $dryRun && ($coursesCreated + $lessonsCreated + $topicsCreated) > 0;
        $blockedByMerge = ! $dryRun && $slugMergeRequired > 0 && $coursesCreated === 0 && $wouldSkipMapped === 0;

        return [
            'status' => $dryRun ? 'ok' : ($blockedByMerge ? 'blocked' : 'ok'),
            'code' => $dryRun ? null : (
                $slugMergeRequired > 0 && $coursesCreated === 0 && $wouldSkipMapped === 0
                    ? 'COURSE_SLUG_MERGE_REQUIRES_DECISION'
                    : null
            ),
            'entity_type' => 'course',
            'dry_run' => $dryRun,
            'source' => 'wp_posts.post_type=sfwd-courses',
            'counts' => $counts,
            'scanned_published_courses' => $scanned,
            'would_create' => $wouldCreate,
            'would_map_existing' => $wouldMapExisting,
            'would_skip' => $wouldSkipMapped,
            'would_skip_mapped' => $wouldSkipMapped,
            'would_defer' => $wouldDefer,
            'would_fail' => $wouldFail,
            'outcome_samples' => $outcomeSamples,
            'slug_merge_requires_decision' => $slugMergeRequired,
            'slug_merge_samples' => $mergeSamples,
            'created' => $dryRun ? 0 : $coursesCreated,
            'courses_created' => $dryRun ? 0 : $coursesCreated,
            'courses_skipped_mapped' => $dryRun ? 0 : $coursesSkipped,
            'lessons_created' => $dryRun ? 0 : $lessonsCreated,
            'lessons_skipped_mapped' => $dryRun ? 0 : $lessonsSkipped,
            'topics_created' => $dryRun ? 0 : $topicsCreated,
            'topics_skipped_mapped' => $dryRun ? 0 : $topicsSkipped,
            'imported' => $dryRun ? 0 : ($coursesCreated + $lessonsCreated + $topicsCreated),
            'samples' => $samples,
            'quizzes' => [
                'lesson_level_inventoried' => $lessonQuizzesInventoried,
                'course_level_in_tree' => $tree['course_level_quizzes']['count'] ?? 0,
                'persist_policy' => 'NOT_AUTO_IMPLEMENTED',
                'assessment_note' => 'Quiz::quizable morph is Lesson-oriented; Pro Quiz field map still AMBIGUOUS — do not invent quiz rows here.',
                'course_level_policy' => 'COURSE_LEVEL_QUIZ_REQUIRES_MODEL_DECISION',
            ],
            'tree' => $this->treeSummary($tree),
            'wrote_to_database' => $wrote,
            'message' => $dryRun
                ? 'Dry-run course inventory + approved tree analysis. See docs/WORDPRESS-COURSE-TREE-MAPPING.md.'
                : 'Course/lesson/topic persist completed for approved in-tree nodes (orphans excluded; quizzes inventoried only).',
            'inspect' => $ready['inspect'],
        ];
    }

    /**
     * Test/helper persist path for a single approved course tree payload (no WP connection).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, object>  $postsById
     * @return array<string, mixed>
     */
    public function persistCourseTree(array $payload, array $postsById = []): array
    {
        $existingMap = $this->maps->find('course', $payload['legacy_id']);
        if ($existingMap?->local_id) {
            return $this->persistMappedCourseTree($payload, (int) $existingMap->local_id, $postsById);
        }

        $slug = $payload['slug'] !== '' ? $payload['slug'] : 'wp-course-'.$payload['legacy_id'];
        if (Course::query()->where('slug', $slug)->exists()) {
            return [
                'course_created' => false,
                'course_id' => null,
                'lessons_created' => 0,
                'topics_created' => 0,
                'lessons_skipped' => 0,
                'topics_skipped' => 0,
                'lesson_quizzes_inventoried' => 0,
                'code' => 'MERGE_REQUIRES_DECISION',
            ];
        }

        $isPublished = ($payload['status'] ?? 'publish') === 'publish';
        $flags = $payload['flags'] ?? [];

        return DB::transaction(function () use ($payload, $postsById, $slug, $isPublished, $flags): array {
            $course = Course::query()->create([
                'uuid' => (string) Str::uuid(),
                'slug' => $slug,
                'access_type' => AccessType::Paid,
                'is_published' => $isPublished,
                'sort_order' => (int) $payload['legacy_id'],
                'published_at' => $isPublished ? now() : null,
                'title' => ['ar' => $payload['title']],
                'description' => $payload['content'] !== '' ? ['ar' => $payload['content']] : null,
            ]);

            $this->maps->upsertMapping('course', $payload['legacy_id'], [
                'local_id' => $course->id,
                'imported_at' => now(),
                'metadata' => LegacyImportMapRepository::ownershipCreated([
                    'legacy_wordpress_id' => $payload['legacy_id'],
                    'relationship_source' => $payload['relationship_source'] ?? null,
                    'flags' => $flags,
                    'empty_source_course' => in_array('EMPTY_SOURCE_COURSE', $flags, true),
                ]),
            ]);

            $child = $this->persistLessonsAndTopics((int) $course->id, $payload['lessons'] ?? [], $postsById);

            return [
                'course_created' => true,
                'course_id' => $course->id,
                ...$child,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, object>  $postsById
     * @return array<string, mixed>
     */
    private function persistMappedCourseTree(array $payload, int $courseLocalId, array $postsById): array
    {
        $legacyId = (string) ($payload['legacy_id'] ?? '');
        $treePolicy = $payload['tree_policy'] ?? $this->courseTreePolicy($legacyId);

        // SUPPRESS_TREE: map shell / enrollments only (no lesson/topic creates).
        if ($treePolicy === self::TREE_POLICY_SUPPRESS) {
            $quizInventory = 0;
            foreach ($payload['lessons'] ?? [] as $lessonNode) {
                $quizInventory += count($lessonNode['lesson_level_quiz_ids'] ?? []);
            }

            return [
                'course_created' => false,
                'course_id' => $courseLocalId,
                'lessons_created' => 0,
                'topics_created' => 0,
                'lessons_skipped' => 0,
                'topics_skipped' => 0,
                'lesson_quizzes_inventoried' => $quizInventory,
                'tree_policy' => self::TREE_POLICY_SUPPRESS,
                'tree_persist_suppressed' => true,
            ];
        }

        // ABSORB_TREE / MAP_EXISTING_AND_IMPORT_CONTENT (course 8507 Option A):
        // Preserve the existing course + native/seeded lessons; import WP children
        // with created_by_migration ownership so rollback never deletes the shell.
        $child = $this->persistLessonsAndTopics(
            $courseLocalId,
            $payload['lessons'] ?? [],
            $postsById,
            [
                'mapped_to_existing_parent' => true,
                'parent_course_legacy_id' => $legacyId,
                'parent_course_local_id' => $courseLocalId,
                'import_strategy' => 'MAP_EXISTING_AND_IMPORT_CONTENT',
            ],
        );

        return [
            'course_created' => false,
            'course_id' => $courseLocalId,
            'tree_policy' => $treePolicy ?? self::TREE_POLICY_ABSORB,
            'tree_persist_suppressed' => false,
            'native_lessons_preserved' => true,
            ...$child,
        ];
    }

    /**
     * Resolve approved MAP_EXISTING local course by config slug and stamp ownership.
     */
    private function applyApprovedMapExisting(string $legacyId, array $payload): ?int
    {
        $localSlug = $this->approvedCollisions->approvedCourseLocalSlug($legacyId);
        if ($localSlug === null || $localSlug === '') {
            return null;
        }

        $local = Course::query()->where('slug', $localSlug)->first(['id']);
        if ($local === null) {
            return null;
        }

        $this->mapExistingCourse($legacyId, (int) $local->id, [
            'local_slug' => $localSlug,
            'tree_policy' => $payload['tree_policy'] ?? $this->courseTreePolicy($legacyId),
            'note' => 'approved_map_existing',
            'approval_source' => 'config:wordpress.approved_map_existing.courses',
        ]);

        return (int) $local->id;
    }

    public function courseTreePolicy(string $legacyId): ?string
    {
        foreach ($this->approvedCollisions->approvedCourseEntries() as $entry) {
            if ((string) ($entry['legacy_id'] ?? '') === $legacyId) {
                $fromEntry = $entry['tree_policy'] ?? null;
                if (is_string($fromEntry) && $fromEntry !== '') {
                    return $fromEntry;
                }
            }
        }

        $policies = config('wordpress.course_tree_policies', []);
        if (is_array($policies) && isset($policies[$legacyId]) && is_string($policies[$legacyId])) {
            return $policies[$legacyId];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $lessons
     * @param  array<int, object>  $postsById
     * @param  array{
     *     mapped_to_existing_parent?: bool,
     *     parent_course_legacy_id?: string,
     *     parent_course_local_id?: int,
     *     import_strategy?: string
     * }  $parentContext
     * @return array{lessons_created: int, topics_created: int, lessons_skipped: int, topics_skipped: int, lesson_quizzes_inventoried: int, sort_order_offset: int}
     */
    private function persistLessonsAndTopics(
        int $courseLocalId,
        array $lessons,
        array $postsById,
        array $parentContext = [],
    ): array {
        $lessonsCreated = 0;
        $topicsCreated = 0;
        $lessonsSkipped = 0;
        $topicsSkipped = 0;
        $lessonQuizzesInventoried = 0;
        $mappedToExistingParent = (bool) ($parentContext['mapped_to_existing_parent'] ?? false);
        $sortOrderOffset = $mappedToExistingParent
            ? $this->nativeLessonSortOrderOffset($courseLocalId)
            : 0;

        foreach ($lessons as $lessonNode) {
            $lessonLegacyId = (string) $lessonNode['legacy_id'];
            $lessonQuizzesInventoried += count($lessonNode['lesson_level_quiz_ids'] ?? []);
            $wpSortOrder = (int) ($lessonNode['sort_order'] ?? 0);
            // Preserve WP relative order; when absorbing into a mapped shell, place
            // imported lessons after native/seeded lessons (stable offset).
            $resolvedSortOrder = $mappedToExistingParent
                ? $sortOrderOffset + 1 + $wpSortOrder
                : $wpSortOrder;

            $existingLessonMap = $this->maps->find('lesson', $lessonLegacyId);
            if ($existingLessonMap?->local_id) {
                $lessonsSkipped++;
                $lessonLocalId = (int) $existingLessonMap->local_id;
            } else {
                $post = $postsById[(int) $lessonLegacyId] ?? null;
                $slug = $this->uniqueLessonSlug(
                    $courseLocalId,
                    (string) ($post?->post_name ?: 'wp-lesson-'.$lessonLegacyId),
                    $lessonLegacyId,
                );
                $lesson = Lesson::query()->create([
                    'course_id' => $courseLocalId,
                    'slug' => $slug,
                    'lesson_type' => LessonType::Theory,
                    'sort_order' => $resolvedSortOrder,
                    'is_published' => ($post?->post_status ?? 'publish') === 'publish',
                    'title' => ['ar' => (string) ($post?->post_title ?? 'Lesson '.$lessonLegacyId)],
                    'content' => filled($post?->post_content) ? ['ar' => (string) $post->post_content] : null,
                ]);
                $this->maps->upsertMapping('lesson', $lessonLegacyId, [
                    'local_id' => $lesson->id,
                    'imported_at' => now(),
                    'metadata' => LegacyImportMapRepository::ownershipCreated(array_filter([
                        'legacy_wordpress_id' => $lessonLegacyId,
                        'course_local_id' => $courseLocalId,
                        'sort_order' => $resolvedSortOrder,
                        'wp_sort_order' => $wpSortOrder,
                        'mapped_to_existing_parent' => $mappedToExistingParent ?: null,
                        'parent_course_legacy_id' => $parentContext['parent_course_legacy_id'] ?? null,
                        'import_strategy' => $parentContext['import_strategy'] ?? null,
                    ], static fn ($v) => $v !== null && $v !== false)),
                ]);
                $lessonLocalId = (int) $lesson->id;
                $lessonsCreated++;
            }

            // Quiz field/question mapping remains AMBIGUOUS — preserve relationship IDs only.
            $this->preserveLessonQuizMetadata(
                $lessonLegacyId,
                array_values(array_map('intval', $lessonNode['lesson_level_quiz_ids'] ?? [])),
            );

            foreach ($lessonNode['topics'] ?? [] as $topicNode) {
                $topicLegacyId = (string) $topicNode['legacy_id'];
                if ($this->maps->find('topic', $topicLegacyId)?->local_id) {
                    $topicsSkipped++;

                    continue;
                }
                $topicPost = $postsById[(int) $topicLegacyId] ?? null;
                $topicSlug = $this->uniqueTopicSlug(
                    $lessonLocalId,
                    (string) ($topicPost?->post_name ?: 'wp-topic-'.$topicLegacyId),
                    $topicLegacyId,
                );
                $topicWpSort = (int) ($topicNode['sort_order'] ?? 0);
                $topic = Topic::query()->create([
                    'lesson_id' => $lessonLocalId,
                    'slug' => $topicSlug,
                    'sort_order' => $topicWpSort,
                    'content_type' => 'video',
                    'is_published' => ($topicPost?->post_status ?? 'publish') === 'publish',
                    'title' => ['ar' => (string) ($topicPost?->post_title ?? 'Topic '.$topicLegacyId)],
                    'content' => filled($topicPost?->post_content) ? ['ar' => (string) $topicPost->post_content] : null,
                ]);
                $this->maps->upsertMapping('topic', $topicLegacyId, [
                    'local_id' => $topic->id,
                    'imported_at' => now(),
                    'metadata' => LegacyImportMapRepository::ownershipCreated(array_filter([
                        'legacy_wordpress_id' => $topicLegacyId,
                        'lesson_local_id' => $lessonLocalId,
                        'sort_order' => $topicWpSort,
                        'mapped_to_existing_parent' => $mappedToExistingParent ?: null,
                        'parent_course_legacy_id' => $parentContext['parent_course_legacy_id'] ?? null,
                        'import_strategy' => $parentContext['import_strategy'] ?? null,
                    ], static fn ($v) => $v !== null && $v !== false)),
                ]);
                $topicsCreated++;
            }
        }

        return [
            'lessons_created' => $lessonsCreated,
            'topics_created' => $topicsCreated,
            'lessons_skipped' => $lessonsSkipped,
            'topics_skipped' => $topicsSkipped,
            'lesson_quizzes_inventoried' => $lessonQuizzesInventoried,
            'sort_order_offset' => $sortOrderOffset,
        ];
    }

    /**
     * Max sort_order among native (non-migration-mapped) lessons under a course.
     * Used so ABSORB_TREE places WP lessons after the seeded shell without
     * shifting relative WP order across idempotent re-runs.
     */
    private function nativeLessonSortOrderOffset(int $courseLocalId): int
    {
        $mappedLessonIds = LegacyImportMap::query()
            ->where('source', LegacyImportMapRepository::SOURCE_WORDPRESS)
            ->where('entity_type', 'lesson')
            ->whereNotNull('local_id')
            ->pluck('local_id')
            ->all();

        $query = Lesson::query()->where('course_id', $courseLocalId);
        if ($mappedLessonIds !== []) {
            $query->whereNotIn('id', $mappedLessonIds);
        }

        return (int) ($query->max('sort_order') ?? 0);
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    private function treeSummary(array $tree): array
    {
        return [
            'status' => $tree['status'] ?? null,
            'authoritative_source' => $tree['authoritative_source'] ?? null,
            'fallback_source' => $tree['fallback_source'] ?? null,
            'ordering' => $tree['ordering'] ?? null,
            'totals' => $tree['totals'] ?? null,
            'conflicts' => $tree['conflicts'] ?? [],
            'orphan_lessons_count' => $tree['orphan_lessons_count'] ?? 0,
            'orphan_topics_count' => $tree['orphan_topics_count'] ?? 0,
            'orphan_quizzes_count' => $tree['orphan_quizzes_count'] ?? 0,
            'fallback_course_ids' => $tree['fallback_course_ids'] ?? [],
            'empty_tree_courses' => $tree['empty_tree_courses'] ?? [],
            'orphans_excluded' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return list<int>
     */
    private function collectLessonTopicIds(array $tree): array
    {
        $ids = [];
        foreach ($tree['courses'] ?? [] as $course) {
            foreach ($course['lessons'] ?? [] as $lesson) {
                $ids[] = (int) $lesson['legacy_id'];
                foreach ($lesson['topics'] ?? [] as $topic) {
                    $ids[] = (int) $topic['legacy_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, object>
     */
    private function loadPostsById(string $conn, string $posts, array $ids): array
    {
        $out = [];
        $ids = array_values(array_unique(array_filter($ids)));
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::connection($conn)->table($posts)
                ->whereIn('ID', $chunk)
                ->get(['ID', 'post_title', 'post_name', 'post_content', 'post_status']) as $row) {
                $out[(int) $row->ID] = $row;
            }
        }

        return $out;
    }

    /**
     * Store lesson→quiz CPT relationship metadata for a later quiz migration phase.
     * Does not create quiz/question rows (QUIZ_PERSIST_REQUIRES_SEPARATE_PHASE).
     *
     * @param  list<int>  $quizIds
     */
    private function preserveLessonQuizMetadata(string $lessonLegacyId, array $quizIds): void
    {
        $map = $this->maps->find('lesson', $lessonLegacyId);
        if ($map === null || $map->local_id === null) {
            return;
        }

        $metadata = is_array($map->metadata) ? $map->metadata : [];
        $metadata['lesson_level_quiz_ids'] = $quizIds;
        $metadata['quiz_persist'] = 'QUIZ_PERSIST_REQUIRES_SEPARATE_PHASE';
        // Preserve ownership stamps when refreshing quiz inventory metadata.
        // Never invent created_by_migration over an existing mapped_to_existing map.
        if (! array_key_exists(LegacyImportMapRepository::META_CREATED_BY_MIGRATION, $metadata)
            && ! array_key_exists(LegacyImportMapRepository::META_MAPPED_TO_EXISTING, $metadata)) {
            $metadata = $map->wasMappedToExisting()
                ? LegacyImportMapRepository::ownershipMappedExisting($metadata)
                : LegacyImportMapRepository::ownershipCreated($metadata);
        } elseif ($map->wasMappedToExisting()) {
            $metadata = LegacyImportMapRepository::ownershipMappedExisting($metadata);
        }

        $this->maps->upsertMapping('lesson', $lessonLegacyId, [
            'local_id' => $map->local_id,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Map a WP course onto a pre-existing Laravel course without overwriting it.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function mapExistingCourse(string $legacyId, int $localId, array $metadata = []): void
    {
        $this->maps->upsertMapping('course', $legacyId, [
            'local_id' => $localId,
            'imported_at' => now(),
            'metadata' => LegacyImportMapRepository::ownershipMappedExisting(array_merge([
                'collision_decision' => 'MAP_EXISTING',
                'overwrite' => false,
            ], $metadata)),
        ]);
    }

    private function uniqueLessonSlug(int $courseId, string $base, string $legacyId): string
    {
        $slug = $base !== '' ? $base : 'wp-lesson-'.$legacyId;
        if (! Lesson::query()->where('course_id', $courseId)->where('slug', $slug)->exists()) {
            return $slug;
        }

        return 'wp-lesson-'.$legacyId;
    }

    private function uniqueTopicSlug(int $lessonId, string $base, string $legacyId): string
    {
        $slug = $base !== '' ? $base : 'wp-topic-'.$legacyId;
        if (! Topic::query()->where('lesson_id', $lessonId)->where('slug', $slug)->exists()) {
            return $slug;
        }

        return 'wp-topic-'.$legacyId;
    }
}
