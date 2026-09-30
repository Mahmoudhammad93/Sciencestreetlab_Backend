<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Media M2 rollback planner/executor.
 * Default dry-run. Never deletes Product/Course/Lesson/Topic rows.
 */
final class WordPressMediaRollbackService
{
    public function __construct(
        private readonly WordPressRealPersistGate $persistGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function planOrExecute(int $migrationRunId, bool $dryRun = true): array
    {
        $run = LegacyMigrationRun::query()->find($migrationRunId);
        if ($run === null) {
            throw new RuntimeException("Migration run {$migrationRunId} not found.");
        }

        $ownershipPath = storage_path('app/private/migration/wordpress-media/'.$migrationRunId.'/ownership.json');
        $ownership = is_file($ownershipPath)
            ? (json_decode((string) file_get_contents($ownershipPath), true) ?: [])
            : [];

        $spatieIds = array_values(array_unique(array_map('intval', $ownership['spatie_media_ids'] ?? [])));
        // Also discover via custom property / maps
        $mapMediaIds = LegacyImportMap::query()
            ->where('migration_run_id', $migrationRunId)
            ->where('entity_type', 'media_attachment')
            ->pluck('local_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();
        $spatieIds = array_values(array_unique(array_merge($spatieIds, $mapMediaIds)));

        $physical = array_values(array_unique(array_filter($ownership['physical_paths'] ?? [])));
        foreach ($ownership['course_image_changes'] ?? [] as $chg) {
            if (! empty($chg['absolute_path'])) {
                $physical[] = $chg['absolute_path'];
            }
        }
        // staged transfer files under work root
        $workRoot = storage_path('app/private/migration/wordpress-media/'.$migrationRunId);
        if (is_dir($workRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($workRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $physical[] = $file->getPathname();
                }
            }
        }
        $physical = array_values(array_unique($physical));

        $courseRestores = $ownership['course_image_changes'] ?? [];
        $lessonVideos = $ownership['lesson_video_changes'] ?? [];
        $topicVideos = $ownership['topic_video_changes'] ?? [];
        $contentRestores = $ownership['content_rewrites'] ?? [];

        $plan = [
            'status' => $dryRun ? 'dry_run' : 'executed',
            'migration_run_id' => $migrationRunId,
            'run_status' => $run->status,
            'dry_run' => $dryRun,
            'wrote_to_database' => false,
            'would_delete_spatie_media' => $spatieIds,
            'would_delete_physical_files' => $physical,
            'would_restore_course_image_urls' => array_map(static fn (array $c): array => [
                'course_id' => $c['course_id'] ?? null,
                'restore_to' => $c['before'] ?? null,
                'clear_from' => $c['after'] ?? null,
            ], $courseRestores),
            'would_clear_or_restore_lesson_videos' => array_map(static fn (array $c): array => [
                'lesson_id' => $c['lesson_id'] ?? null,
                'restore_url' => $c['before_url'] ?? null,
                'restore_provider' => $c['before_provider'] ?? null,
            ], $lessonVideos),
            'would_clear_or_restore_topic_video' => array_map(static fn (array $c): array => [
                'topic_id' => $c['topic_id'] ?? null,
                'restore_url' => $c['before_url'] ?? null,
                'restore_provider' => $c['before_provider'] ?? null,
            ], $topicVideos),
            'would_restore_content_fields' => array_map(static fn (array $c): array => [
                'lesson_id' => $c['lesson_id'] ?? null,
                'locales' => array_keys($c['locales'] ?? []),
            ], $contentRestores),
            'would_delete_products' => 0,
            'would_delete_courses' => 0,
            'would_delete_lessons' => 0,
            'would_delete_topics' => 0,
            'map_rows_for_run' => LegacyImportMap::query()->where('migration_run_id', $migrationRunId)->count(),
        ];

        if ($dryRun) {
            return $plan;
        }

        $auth = $this->persistGate->authorizeRollbackExecute($migrationRunId);
        if (! ($auth['ok'] ?? false)) {
            return $auth['result'];
        }

        DB::transaction(function () use ($spatieIds, $courseRestores, $lessonVideos, $topicVideos, $contentRestores, $migrationRunId, $physical): void {
            foreach ($spatieIds as $mediaId) {
                $media = Media::query()->find($mediaId);
                $media?->delete();
            }

            foreach ($courseRestores as $chg) {
                $course = Course::query()->find((int) ($chg['course_id'] ?? 0));
                if ($course && ($course->image_url === ($chg['after'] ?? null))) {
                    $course->image_url = $chg['before'] ?? null;
                    $course->save();
                }
            }

            foreach ($lessonVideos as $chg) {
                $lesson = Lesson::query()->find((int) ($chg['lesson_id'] ?? 0));
                if (! $lesson) {
                    continue;
                }
                if ($lesson->video_url === ($chg['after_url'] ?? null)) {
                    $lesson->video_url = $chg['before_url'] ?? null;
                    $lesson->video_provider = $chg['before_provider'] ?? null;
                    $lesson->save();
                }
            }

            foreach ($topicVideos as $chg) {
                $topic = Topic::query()->find((int) ($chg['topic_id'] ?? 0));
                if (! $topic) {
                    continue;
                }
                if ($topic->video_url === ($chg['after_url'] ?? null)) {
                    $topic->video_url = $chg['before_url'] ?? null;
                    $topic->video_provider = $chg['before_provider'] ?? null;
                    $topic->save();
                }
            }

            foreach ($contentRestores as $chg) {
                $lesson = Lesson::query()->find((int) ($chg['lesson_id'] ?? 0));
                if (! $lesson) {
                    continue;
                }
                foreach ($chg['locales'] ?? [] as $locale => $meta) {
                    if (($meta['before_content'] ?? null) !== null) {
                        $lesson->setTranslation('content', (string) $locale, (string) $meta['before_content']);
                    }
                }
                $lesson->save();
            }

            foreach ($physical as $path) {
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }

            LegacyImportMap::query()
                ->where('migration_run_id', $migrationRunId)
                ->whereIn('entity_type', [
                    'media_attachment',
                    'media_course_image',
                    'media_lesson_video',
                    'media_topic_video',
                    'media_inline_rewrite',
                ])
                ->delete();
        });

        $plan['wrote_to_database'] = true;
        $plan['status'] = 'executed';

        return $plan;
    }
}
