<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Learning\Application\Services\CoursePlanEntitlementSyncService;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Domain\Enums\TopicContentType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Free course: one lesson with 3 interactive-only topics from interactive examples/.
 *
 * Idempotent: php artisan db:seed --class=FreeInteractiveLabsSeeder
 */
final class FreeInteractiveLabsSeeder extends Seeder
{
    public const COURSE_SLUG = 'free-interactive-labs';

    /**
     * @var list<array{slug: string, html: string, title: array{ar: string, en: string}}>
     */
    private const TOPICS = [
        [
            'slug' => 'rubber-force',
            'html' => 'قوة المطاط.html',
            'title' => ['ar' => 'قوة المطاط', 'en' => 'Rubber Force'],
        ],
        [
            'slug' => 'rubber-race',
            'html' => 'قوة المطاط (سباق عربية).html',
            'title' => ['ar' => 'قوة المطاط — سباق العربية', 'en' => 'Rubber Force — Car Race'],
        ],
        [
            'slug' => 'light',
            'html' => 'الضوء 3.html',
            'title' => ['ar' => 'الضوء', 'en' => 'Light'],
        ],
    ];

    public function run(): void
    {
        $examplesRoot = dirname(base_path()).DIRECTORY_SEPARATOR.'interactive examples';

        if (! is_dir($examplesRoot)) {
            $this->command?->error("Interactive examples folder missing: {$examplesRoot}");

            return;
        }

        DB::transaction(function () use ($examplesRoot): void {
            $course = Course::query()->updateOrCreate(
                ['slug' => self::COURSE_SLUG],
                [
                    'title' => [
                        'ar' => 'مختبرات تفاعلية مجانية',
                        'en' => 'Free Interactive Labs',
                    ],
                    'description' => [
                        'ar' => 'كورس مجاني بثلاث أنشطة HTML تفاعلية فقط (بدون فيديو).',
                        'en' => 'A free course with three interactive HTML labs only (no video).',
                    ],
                    'short_description' => [
                        'ar' => '3 أنشطة تفاعلية مجانية',
                        'en' => '3 free interactive labs',
                    ],
                    'access_type' => AccessType::Free,
                    'is_published' => true,
                    'sort_order' => 21,
                    'published_at' => now(),
                    'estimated_hours' => 1.5,
                ],
            );

            $lesson = Lesson::query()->updateOrCreate(
                ['course_id' => $course->id, 'slug' => self::COURSE_SLUG.'-labs'],
                [
                    'title' => [
                        'ar' => 'الأنشطة التفاعلية',
                        'en' => 'Interactive Activities',
                    ],
                    'content' => [
                        'ar' => 'ثلاث محطات تفاعلية — افتح كل نشاط وأكمله.',
                        'en' => 'Three interactive stations — open each lab and complete it.',
                    ],
                    'lesson_type' => LessonType::Theory->value,
                    'sort_order' => 1,
                    'is_published' => true,
                ],
            );

            $packages = app(InteractiveActivityPackageService::class);
            $topicIds = [];
            $activityIds = [];

            foreach (self::TOPICS as $index => $def) {
                $htmlPath = $examplesRoot.DIRECTORY_SEPARATOR.$def['html'];
                if (! is_file($htmlPath)) {
                    throw new \RuntimeException("Missing interactive HTML: {$htmlPath}");
                }

                $sort = $index + 1;
                $topic = Topic::query()->updateOrCreate(
                    ['lesson_id' => $lesson->id, 'slug' => $lesson->slug.'-'.$def['slug']],
                    [
                        'sort_order' => $sort,
                        'content_type' => TopicContentType::Interactive->value,
                        'is_published' => true,
                        'title' => $def['title'],
                        'content' => [
                            'ar' => 'نشاط تفاعلي HTML — توقّع ← جرّب ← لاحظ ← فسّر',
                            'en' => 'Interactive HTML lab — Predict → Try → Observe → Explain',
                        ],
                    ],
                );

                $activity = InteractiveActivity::query()->updateOrCreate(
                    [
                        'lesson_id' => $lesson->id,
                        'activity_config->free_lab_key' => $def['slug'],
                    ],
                    [
                        'topic_id' => $topic->id,
                        'activity_type' => InteractiveActivityType::VirtualLab->value,
                        'status' => InteractiveActivityStatus::Published,
                        'difficulty' => QuestionDifficulty::Medium,
                        'points' => 40,
                        'estimated_time_seconds' => 600,
                        'version' => 1,
                        'entry_file' => 'index.html',
                        'activity_config' => [
                            'free_lab_key' => $def['slug'],
                            'source_html' => $def['html'],
                        ],
                        'title' => $def['title'],
                        'description' => [
                            'ar' => 'نشاط مجاني من مجلد interactive examples.',
                            'en' => 'Free activity from the interactive examples folder.',
                        ],
                        'instructions' => [
                            'ar' => 'افتح النشاط وأكمل التحديات.',
                            'en' => 'Open the activity and complete the challenges.',
                        ],
                    ],
                );

                $packages->storeFromHtmlFile($activity->fresh(), $htmlPath);

                $launch = $packages->signedLaunchUrl($activity->fresh());
                if ($launch) {
                    $topic->update([
                        'video_url' => $launch,
                        'video_provider' => 'interactive_package',
                    ]);
                }

                $topicIds[] = $topic->id;
                $activityIds[] = $activity->id;
            }

            $this->seedFreePlan($course, $lesson->id, $topicIds, $activityIds);
        });

        $this->command?->info('Seeded free course: '.self::COURSE_SLUG.' (3 interactive topics).');
    }

    /**
     * @param  list<int>  $topicIds
     * @param  list<int>  $activityIds
     */
    private function seedFreePlan(Course $course, int $lessonId, array $topicIds, array $activityIds): void
    {
        $sync = app(CoursePlanEntitlementSyncService::class);

        $plan = CoursePlan::query()->updateOrCreate(
            ['course_id' => $course->id, 'name->en' => 'Free Plan'],
            [
                'name' => ['ar' => 'خطة مجانية', 'en' => 'Free Plan'],
                'description' => [
                    'ar' => 'وصول مجاني كامل للأنشطة الثلاثة.',
                    'en' => 'Full free access to all three labs.',
                ],
                'price' => 0,
                'currency' => 'SAR',
                'is_active' => true,
                'is_lifetime' => true,
                'duration_days' => null,
                'max_quiz_attempts' => 3,
                'grant_certificate' => false,
                'sort_order' => 1,
            ],
        );

        $sync->syncPlanEntitlements($plan, [
            'lesson_ids' => [$lessonId],
            'topic_ids' => $topicIds,
            'interactive_activity_ids' => $activityIds,
        ]);

        // Remove any leftover paid plans from earlier experiments on this slug.
        CoursePlan::query()
            ->where('course_id', $course->id)
            ->where('id', '!=', $plan->id)
            ->update(['is_active' => false]);
    }
}
