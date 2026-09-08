<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
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
 * One course with 3 stations (topics). Each station = video topic + interactive HTML.
 *
 * HTML sources: New/interactive examples/
 *
 * Idempotent: php artisan db:seed --class=ThreeStationsCourseSeeder
 */
final class ThreeStationsCourseSeeder extends Seeder
{
    public const COURSE_SLUG = 'science-street-three-stations';

    private const SAMPLE_VIDEO = 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';

    /**
     * @var list<array{
     *   slug: string,
     *   html: string,
     *   title: array{ar: string, en: string},
     *   video_title: array{ar: string, en: string},
     *   interactive_title: array{ar: string, en: string}
     * }>
     */
    private const STATIONS = [
        [
            'slug' => 'light',
            'html' => 'الضوء 3.html',
            'title' => ['ar' => 'الضوء', 'en' => 'Light'],
            'video_title' => ['ar' => 'فيديو: الضوء', 'en' => 'Video: Light'],
            'interactive_title' => ['ar' => 'مختبر الضوء التفاعلي', 'en' => 'Interactive Light Lab'],
        ],
        [
            'slug' => 'sound',
            'html' => 'الصوت.html',
            'title' => ['ar' => 'الصوت', 'en' => 'Sound'],
            'video_title' => ['ar' => 'فيديو: الصوت', 'en' => 'Video: Sound'],
            'interactive_title' => ['ar' => 'مختبر الصوت التفاعلي', 'en' => 'Interactive Sound Lab'],
        ],
        [
            'slug' => 'plants',
            'html' => 'كيف تنمو النباتات.html',
            'title' => ['ar' => 'كيف تنمو النباتات', 'en' => 'How Plants Grow'],
            'video_title' => ['ar' => 'فيديو: كيف تنمو النباتات', 'en' => 'Video: How Plants Grow'],
            'interactive_title' => ['ar' => 'مختبر نمو النباتات التفاعلي', 'en' => 'Interactive Plant Growth Lab'],
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
                        'ar' => 'محطات شارع العلوم',
                        'en' => 'Science Street Stations',
                    ],
                    'description' => [
                        'ar' => 'ثلاث محطات: كل محطة فيها فيديو ونشاط HTML تفاعلي (ضوء، صوت، نباتات).',
                        'en' => 'Three stations: each has a video and an interactive HTML lab (light, sound, plants).',
                    ],
                    'short_description' => [
                        'ar' => '3 محطات × فيديو + تفاعلي',
                        'en' => '3 stations × video + interactive',
                    ],
                    'access_type' => AccessType::School,
                    'is_published' => true,
                    'sort_order' => 20,
                    'published_at' => now(),
                    'estimated_hours' => 3,
                ],
            );

            $packages = app(InteractiveActivityPackageService::class);
            $allLessonIds = [];
            $allTopicIds = [];
            $allActivityIds = [];

            foreach (self::STATIONS as $index => $station) {
                $htmlPath = $examplesRoot.DIRECTORY_SEPARATOR.$station['html'];
                if (! is_file($htmlPath)) {
                    throw new \RuntimeException("Missing interactive HTML: {$htmlPath}");
                }

                $order = $index + 1;
                $lesson = Lesson::query()->updateOrCreate(
                    ['course_id' => $course->id, 'slug' => self::COURSE_SLUG.'-'.$station['slug']],
                    [
                        'title' => $station['title'],
                        'content' => [
                            'ar' => 'محطة '.$order.': شاهد الفيديو ثم أكمل النشاط التفاعلي.',
                            'en' => 'Station '.$order.': watch the video, then complete the interactive lab.',
                        ],
                        'lesson_type' => LessonType::Theory->value,
                        'sort_order' => $order,
                        'is_published' => true,
                    ],
                );

                $videoTopic = Topic::query()->updateOrCreate(
                    ['lesson_id' => $lesson->id, 'slug' => $lesson->slug.'-video'],
                    [
                        'sort_order' => 1,
                        'content_type' => TopicContentType::Video->value,
                        'video_url' => self::SAMPLE_VIDEO,
                        'video_provider' => 'external',
                        'is_published' => true,
                        'title' => $station['video_title'],
                        'content' => [
                            'ar' => 'شاهد فيديو مقدمة المحطة.',
                            'en' => 'Watch the station intro video.',
                        ],
                    ],
                );

                $interactiveTopic = Topic::query()->updateOrCreate(
                    ['lesson_id' => $lesson->id, 'slug' => $lesson->slug.'-interactive'],
                    [
                        'sort_order' => 2,
                        'content_type' => TopicContentType::Interactive->value,
                        'is_published' => true,
                        'title' => $station['interactive_title'],
                        'content' => [
                            'ar' => 'توقّع ← جرّب ← لاحظ ← فسّر',
                            'en' => 'Predict → Try → Observe → Explain',
                        ],
                    ],
                );

                $activity = InteractiveActivity::query()->updateOrCreate(
                    [
                        'lesson_id' => $lesson->id,
                        'activity_config->station_key' => $station['slug'],
                    ],
                    [
                        'topic_id' => $interactiveTopic->id,
                        'activity_type' => InteractiveActivityType::VirtualLab->value,
                        'status' => InteractiveActivityStatus::Published,
                        'difficulty' => QuestionDifficulty::Medium,
                        'points' => 50,
                        'estimated_time_seconds' => 900,
                        'version' => 1,
                        'entry_file' => 'index.html',
                        'activity_config' => [
                            'station_key' => $station['slug'],
                            'source_html' => $station['html'],
                        ],
                        'title' => $station['interactive_title'],
                        'description' => [
                            'ar' => 'نشاط HTML من مجلد interactive examples.',
                            'en' => 'HTML activity from the interactive examples folder.',
                        ],
                        'instructions' => [
                            'ar' => 'أكمل المحطات داخل النشاط التفاعلي.',
                            'en' => 'Complete the stations inside the interactive activity.',
                        ],
                    ],
                );

                $packages->storeFromHtmlFile($activity->fresh(), $htmlPath);

                // Lesson player reads topic.video_url as file_url; signed URL is also resolved in LessonController.
                $launch = $packages->signedLaunchUrl($activity->fresh());
                if ($launch) {
                    $interactiveTopic->update([
                        'video_url' => $launch,
                        'video_provider' => 'interactive_package',
                    ]);
                }

                $allLessonIds[] = $lesson->id;
                $allTopicIds[] = $videoTopic->id;
                $allTopicIds[] = $interactiveTopic->id;
                $allActivityIds[] = $activity->id;
            }

            $this->seedPlans($course, $allLessonIds, $allTopicIds, $allActivityIds);
        });

        $this->command?->info('Seeded course: '.self::COURSE_SLUG.' (3 stations × video + interactive HTML).');
    }

    /**
     * @param  list<int>  $lessonIds
     * @param  list<int>  $topicIds
     * @param  list<int>  $activityIds
     */
    private function seedPlans(Course $course, array $lessonIds, array $topicIds, array $activityIds): void
    {
        $sync = app(CoursePlanEntitlementSyncService::class);

        $starter = CoursePlan::query()->updateOrCreate(
            ['course_id' => $course->id, 'name->en' => 'Starter Plan'],
            [
                'name' => ['ar' => 'خطة البداية', 'en' => 'Starter Plan'],
                'description' => [
                    'ar' => 'وصول كامل للمحطات الثلاث.',
                    'en' => 'Full access to all three stations.',
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

        $complete = CoursePlan::query()->updateOrCreate(
            ['course_id' => $course->id, 'name->en' => 'Complete Plan'],
            [
                'name' => ['ar' => 'الخطة الكاملة', 'en' => 'Complete Plan'],
                'description' => [
                    'ar' => 'وصول كامل مع شهادة.',
                    'en' => 'Full access with certificate.',
                ],
                'price' => 99,
                'currency' => 'SAR',
                'is_active' => true,
                'is_lifetime' => true,
                'duration_days' => null,
                'max_quiz_attempts' => 5,
                'grant_certificate' => true,
                'sort_order' => 2,
            ],
        );

        $entitlements = [
            'lesson_ids' => $lessonIds,
            'topic_ids' => $topicIds,
            'interactive_activity_ids' => $activityIds,
        ];

        $sync->syncPlanEntitlements($starter, $entitlements);
        $sync->syncPlanEntitlements($complete, $entitlements);

        $this->seedPlanProducts($course, $starter, $complete);
    }

    private function seedPlanProducts(Course $course, CoursePlan $starter, CoursePlan $complete): void
    {
        $titleAr = (string) $course->getTranslation('title', 'ar');
        $titleEn = (string) $course->getTranslation('title', 'en');

        foreach (
            [
                ['plan' => $starter, 'sku' => 'SS-THREE-STATIONS-STARTER', 'slug' => 'science-street-three-stations-starter'],
                ['plan' => $complete, 'sku' => 'SS-THREE-STATIONS-COMPLETE', 'slug' => 'science-street-three-stations-complete'],
            ] as $row
        ) {
            /** @var CoursePlan $plan */
            $plan = $row['plan'];
            $labelAr = $plan->getTranslation('name', 'ar');
            $labelEn = $plan->getTranslation('name', 'en');

            Product::query()->updateOrCreate(
                ['sku' => $row['sku']],
                [
                    'slug' => $row['slug'],
                    'type' => ProductType::Course,
                    'status' => ProductStatus::Published,
                    'price' => $plan->price,
                    'currency' => $plan->currency,
                    'manage_stock' => false,
                    'is_featured' => false,
                    'course_id' => $course->id,
                    'course_plan_id' => $plan->id,
                    'sort_order' => (int) $plan->sort_order,
                    'published_at' => now(),
                    'name' => [
                        'ar' => $titleAr.' — '.$labelAr,
                        'en' => $titleEn.' — '.$labelEn,
                    ],
                    'short_description' => [
                        'ar' => 'منتج كتالوج للخطة.',
                        'en' => 'Catalog product for the plan.',
                    ],
                    'description' => [
                        'ar' => 'منتج كتالوج للخطة.',
                        'en' => 'Catalog product for the plan.',
                    ],
                ],
            );
        }
    }
}
