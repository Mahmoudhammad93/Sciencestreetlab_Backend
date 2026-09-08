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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Production demo: 5 courses × 1 lesson × 5 stations (video + interactive HTML each).
 *
 * Configure via Config (set by production:reset-and-seed-courses):
 * - production.examples_dir
 * - production.bunny_video_id
 * - production.bunny_playback_url
 */
final class ProductionFiveStationsCoursesSeeder extends Seeder
{
    /**
     * @var list<array{slug: string, title: array{ar: string, en: string}, description: array{ar: string, en: string}}>
     */
    public const COURSES = [
        [
            'slug' => 'prod-station-light-sound',
            'title' => ['ar' => 'محطات الضوء والصوت', 'en' => 'Light & Sound Stations'],
            'description' => [
                'ar' => 'خمس محطات علمية: كل محطة فيديو + نشاط HTML تفاعلي.',
                'en' => 'Five science stations: each has a video and an interactive HTML lab.',
            ],
        ],
        [
            'slug' => 'prod-station-forces-motion',
            'title' => ['ar' => 'محطات القوى والحركة', 'en' => 'Forces & Motion Stations'],
            'description' => [
                'ar' => 'استكشف القوى والحركة عبر فيديو وتجارب تفاعلية.',
                'en' => 'Explore forces and motion with video and interactive labs.',
            ],
        ],
        [
            'slug' => 'prod-station-plants-nature',
            'title' => ['ar' => 'محطات النباتات والطبيعة', 'en' => 'Plants & Nature Stations'],
            'description' => [
                'ar' => 'خمس محطات عن النباتات والطبيعة مع فيديو وتفاعل.',
                'en' => 'Five stations about plants and nature with video and interactives.',
            ],
        ],
        [
            'slug' => 'prod-station-energy-lab',
            'title' => ['ar' => 'مختبر الطاقة', 'en' => 'Energy Lab'],
            'description' => [
                'ar' => 'رحلة في مفاهيم الطاقة عبر خمس محطات تفاعلية.',
                'en' => 'A journey through energy concepts across five interactive stations.',
            ],
        ],
        [
            'slug' => 'prod-station-science-street',
            'title' => ['ar' => 'شارع العلوم — المحطات الخمس', 'en' => 'Science Street — Five Stations'],
            'description' => [
                'ar' => 'المحطات الخمس الكاملة لشارع العلوم: فيديو + HTML في كل محطة.',
                'en' => 'The full Science Street five stations: video + HTML in each station.',
            ],
        ],
    ];

    /**
     * Five HTML labs from new-examples/ — one per station.
     *
     * @var list<array{slug: string, html: string, title: array{ar: string, en: string}}>
     */
    private const STATIONS = [
        [
            'slug' => 'atom-building',
            'html' => 'atom-building-unit.html',
            'title' => ['ar' => 'الذرة — وحدة بناء المادة', 'en' => 'The Atom — Building Block of Matter'],
        ],
        [
            'slug' => 'atom-structure',
            'html' => 'atom-structure-discovery.html',
            'title' => ['ar' => 'تركيب الذرة — رحلة اكتشاف', 'en' => 'Atomic Structure — Discovery'],
        ],
        [
            'slug' => 'electron-distribution',
            'html' => 'electron-distribution.html',
            'title' => ['ar' => 'التوزيع الإلكتروني', 'en' => 'Electron Distribution'],
        ],
        [
            'slug' => 'element-symbols',
            'html' => 'element-symbols-z-a.html',
            'title' => ['ar' => 'رموز العناصر — Z و A', 'en' => 'Element Symbols — Z & A'],
        ],
        [
            'slug' => 'isotopes',
            'html' => 'isotopes.html',
            'title' => ['ar' => 'النظائر', 'en' => 'Isotopes'],
        ],
    ];

    private const SAMPLE_VIDEO = 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';

    public function run(): void
    {
        $examplesDir = (string) (Config::get('production.examples_dir')
            ?: env('PRODUCTION_EXAMPLES_DIR')
            ?: dirname(base_path()).DIRECTORY_SEPARATOR.'new-examples');
        $bunnyVideoId = (string) (Config::get('production.bunny_video_id') ?: env('PRODUCTION_BUNNY_VIDEO_ID') ?: '');
        $playbackUrl = (string) (Config::get('production.bunny_playback_url') ?: env('PRODUCTION_BUNNY_PLAYBACK_URL') ?: '');

        // Local/dev fallback when Bunny is not configured.
        if ($playbackUrl === '') {
            $playbackUrl = self::SAMPLE_VIDEO;
            $bunnyVideoId = '';
            $this->command?->warn('Bunny playback URL not set — using sample MP4 for video topics.');
        }

        if (! is_dir($examplesDir)) {
            throw new \RuntimeException("Interactive examples directory missing: {$examplesDir}");
        }

        foreach (self::STATIONS as $station) {
            $path = $examplesDir.DIRECTORY_SEPARATOR.$station['html'];
            if (! is_file($path)) {
                throw new \RuntimeException("Missing HTML: {$path}");
            }
        }

        $videoProvider = $bunnyVideoId !== '' ? 'bunny' : 'external';
        $videoStatus = $bunnyVideoId !== '' ? 'ready' : 'external';

        DB::transaction(function () use ($examplesDir, $bunnyVideoId, $playbackUrl, $videoProvider, $videoStatus): void {
            $packages = app(InteractiveActivityPackageService::class);
            $sync = app(CoursePlanEntitlementSyncService::class);

            foreach (self::COURSES as $courseIndex => $courseDef) {
                $course = Course::query()->updateOrCreate(
                    ['slug' => $courseDef['slug']],
                    [
                        'title' => $courseDef['title'],
                        'description' => $courseDef['description'],
                        'short_description' => $courseDef['description'],
                        'access_type' => AccessType::School,
                        'is_published' => true,
                        'sort_order' => 10 + $courseIndex,
                        'published_at' => now(),
                        'estimated_hours' => 2.5,
                    ],
                );

                $lesson = Lesson::query()->updateOrCreate(
                    ['course_id' => $course->id, 'slug' => $courseDef['slug'].'-lesson'],
                    [
                        'title' => [
                            'ar' => 'الدرس الرئيسي',
                            'en' => 'Main Lesson',
                        ],
                        'content' => [
                            'ar' => 'خمس محطات: كل محطة فيها فيديو ثم نشاط HTML تفاعلي.',
                            'en' => 'Five stations: each has a video then an interactive HTML lab.',
                        ],
                        'lesson_type' => LessonType::Theory->value,
                        'sort_order' => 1,
                        'is_published' => true,
                    ],
                );

                $topicIds = [];
                $activityIds = [];
                $sort = 0;

                foreach (self::STATIONS as $station) {
                    $sort++;
                    $videoTopic = Topic::query()->updateOrCreate(
                        ['lesson_id' => $lesson->id, 'slug' => $lesson->slug.'-'.$station['slug'].'-video'],
                        [
                            'sort_order' => $sort,
                            'content_type' => TopicContentType::Video->value,
                            'bunny_video_id' => $bunnyVideoId !== '' ? $bunnyVideoId : null,
                            'video_provider' => $videoProvider,
                            'video_status' => $videoStatus,
                            'video_url' => $playbackUrl,
                            'is_published' => true,
                            'title' => [
                                'ar' => 'فيديو: '.$station['title']['ar'],
                                'en' => 'Video: '.$station['title']['en'],
                            ],
                            'content' => [
                                'ar' => 'شاهد فيديو المحطة ثم انتقل للنشاط التفاعلي.',
                                'en' => 'Watch the station video, then open the interactive lab.',
                            ],
                        ],
                    );
                    $topicIds[] = $videoTopic->id;

                    $sort++;
                    $interactiveTopic = Topic::query()->updateOrCreate(
                        ['lesson_id' => $lesson->id, 'slug' => $lesson->slug.'-'.$station['slug'].'-interactive'],
                        [
                            'sort_order' => $sort,
                            'content_type' => TopicContentType::Interactive->value,
                            'is_published' => true,
                            'title' => [
                                'ar' => 'تفاعلي: '.$station['title']['ar'],
                                'en' => 'Interactive: '.$station['title']['en'],
                            ],
                            'content' => [
                                'ar' => 'توقّع ← جرّب ← لاحظ ← فسّر',
                                'en' => 'Predict → Try → Observe → Explain',
                            ],
                        ],
                    );

                    $activity = InteractiveActivity::query()->updateOrCreate(
                        [
                            'lesson_id' => $lesson->id,
                            'activity_config->station_key' => $courseDef['slug'].':'.$station['slug'],
                        ],
                        [
                            'topic_id' => $interactiveTopic->id,
                            'activity_type' => InteractiveActivityType::VirtualLab->value,
                            'status' => InteractiveActivityStatus::Published,
                            'difficulty' => QuestionDifficulty::Medium,
                            'points' => 40,
                            'estimated_time_seconds' => 600,
                            'version' => 1,
                            'entry_file' => 'index.html',
                            'activity_config' => [
                                'station_key' => $courseDef['slug'].':'.$station['slug'],
                                'source_html' => $station['html'],
                            ],
                            'title' => [
                                'ar' => 'تفاعلي: '.$station['title']['ar'],
                                'en' => 'Interactive: '.$station['title']['en'],
                            ],
                            'description' => [
                                'ar' => 'نشاط HTML من new-examples.',
                                'en' => 'HTML activity from new-examples.',
                            ],
                            'instructions' => [
                                'ar' => 'أكمل النشاط التفاعلي.',
                                'en' => 'Complete the interactive activity.',
                            ],
                        ],
                    );

                    $htmlPath = $examplesDir.DIRECTORY_SEPARATOR.$station['html'];
                    $activity->update(['version' => max(1, (int) $activity->version) + 1]);
                    $packages->storeFromHtmlFile($activity->fresh(), $htmlPath);

                    $launch = $packages->signedLaunchUrl($activity->fresh());
                    if ($launch) {
                        $interactiveTopic->update([
                            'video_url' => $launch,
                            'video_provider' => 'interactive_package',
                        ]);
                    }

                    $topicIds[] = $interactiveTopic->id;
                    $activityIds[] = $activity->id;
                }

                // Drop leftover stations from previous HTML sets (e.g. light/sound/plants).
                Topic::query()
                    ->where('lesson_id', $lesson->id)
                    ->whereNotIn('id', $topicIds)
                    ->delete();
                InteractiveActivity::query()
                    ->where('lesson_id', $lesson->id)
                    ->whereNotIn('id', $activityIds)
                    ->delete();

                $this->seedPlansAndProducts($course, $lesson, $topicIds, $activityIds, $sync);

                $this->command?->info('  ✓ '.$courseDef['slug'].' ('.count($topicIds).' topics, 3 EGP plans)');
            }
        });

        $this->command?->info('Seeded 5 production courses (video + interactive × 5 stations, 3 EGP plans each).');
    }

    /**
     * @param  list<int>  $topicIds
     * @param  list<int>  $activityIds
     */
    private function seedPlansAndProducts(
        Course $course,
        Lesson $lesson,
        array $topicIds,
        array $activityIds,
        CoursePlanEntitlementSyncService $sync,
    ): void {
        // Deactivate any leftover free-only plan from earlier seeds.
        CoursePlan::query()
            ->where('course_id', $course->id)
            ->where('name->en', 'Free Plan')
            ->update(['is_active' => false]);

        $starter = CoursePlan::query()->updateOrCreate(
            ['course_id' => $course->id, 'name->en' => 'Starter Plan'],
            [
                'name' => ['ar' => 'خطة البداية', 'en' => 'Starter Plan'],
                'description' => [
                    'ar' => 'وصول 30 يوم لأول محطتين (ضوء + صوت).',
                    'en' => '30-day access to the first two stations (light + sound).',
                ],
                'price' => 199,
                'currency' => 'EGP',
                'is_active' => true,
                'is_lifetime' => false,
                'duration_days' => 30,
                'max_quiz_attempts' => 2,
                'grant_certificate' => false,
                'sort_order' => 1,
            ],
        );

        $complete = CoursePlan::query()->updateOrCreate(
            ['course_id' => $course->id, 'name->en' => 'Complete Plan'],
            [
                'name' => ['ar' => 'الخطة الكاملة', 'en' => 'Complete Plan'],
                'description' => [
                    'ar' => 'وصول كامل مدى الحياة لكل المحطات مع شهادة.',
                    'en' => 'Full lifetime access to all stations with certificate.',
                ],
                'price' => 499,
                'currency' => 'EGP',
                'is_active' => true,
                'is_lifetime' => true,
                'duration_days' => null,
                'max_quiz_attempts' => 5,
                'grant_certificate' => true,
                'sort_order' => 2,
            ],
        );

        $exam = CoursePlan::query()->updateOrCreate(
            ['course_id' => $course->id, 'name->en' => 'Exam Preparation Plan'],
            [
                'name' => ['ar' => 'خطة الاستعداد للامتحان', 'en' => 'Exam Preparation Plan'],
                'description' => [
                    'ar' => 'وصول 60 يوم لمحطات المراجعة (ضوء، نباتات، سباق).',
                    'en' => '60-day access to revision stations (light, plants, race).',
                ],
                'price' => 299,
                'currency' => 'EGP',
                'is_active' => true,
                'is_lifetime' => false,
                'duration_days' => 60,
                'max_quiz_attempts' => 3,
                'grant_certificate' => false,
                'sort_order' => 3,
            ],
        );

        // topic order: light-v, light-i, sound-v, sound-i, plants-v, plants-i, rubber-v, rubber-i, race-v, race-i
        $starterTopicIds = array_slice($topicIds, 0, 4);
        $starterActivityIds = array_slice($activityIds, 0, 2);

        $examTopicIds = array_values(array_filter([
            $topicIds[0] ?? null, $topicIds[1] ?? null, // light
            $topicIds[4] ?? null, $topicIds[5] ?? null, // plants
            $topicIds[8] ?? null, $topicIds[9] ?? null, // race
        ], fn ($id) => $id !== null));
        $examActivityIds = array_values(array_filter([
            $activityIds[0] ?? null, // light
            $activityIds[2] ?? null, // plants
            $activityIds[4] ?? null, // race
        ], fn ($id) => $id !== null));

        $sync->syncPlanEntitlements($starter, [
            'lesson_ids' => [$lesson->id],
            'topic_ids' => $starterTopicIds,
            'interactive_activity_ids' => $starterActivityIds,
        ]);

        $sync->syncPlanEntitlements($complete, [
            'lesson_ids' => [$lesson->id],
            'topic_ids' => $topicIds,
            'interactive_activity_ids' => $activityIds,
        ]);

        $sync->syncPlanEntitlements($exam, [
            'lesson_ids' => [$lesson->id],
            'topic_ids' => $examTopicIds,
            'interactive_activity_ids' => $examActivityIds,
        ]);

        $this->seedPlanProducts($course, [
            'starter' => $starter,
            'complete' => $complete,
            'exam' => $exam,
        ]);
    }

    /**
     * @param  array{starter: CoursePlan, complete: CoursePlan, exam: CoursePlan}  $plans
     */
    private function seedPlanProducts(Course $course, array $plans): void
    {
        $titleAr = (string) $course->getTranslation('title', 'ar');
        $titleEn = (string) $course->getTranslation('title', 'en');
        $skuBase = strtoupper(str_replace('-', '_', $course->slug));

        $definitions = [
            'starter' => [
                'sku' => 'SS-'.$skuBase.'-STARTER',
                'slug' => $course->slug.'-starter-plan',
                'name' => [
                    'ar' => $titleAr.' — خطة البداية',
                    'en' => $titleEn.' — Starter Plan',
                ],
                'short' => [
                    'ar' => 'وصول 30 يوم لأول محطتين',
                    'en' => '30-day access to the first two stations',
                ],
            ],
            'complete' => [
                'sku' => 'SS-'.$skuBase.'-COMPLETE',
                'slug' => $course->slug.'-complete-plan',
                'name' => [
                    'ar' => $titleAr.' — الخطة الكاملة',
                    'en' => $titleEn.' — Complete Plan',
                ],
                'short' => [
                    'ar' => 'وصول كامل مدى الحياة مع شهادة',
                    'en' => 'Full lifetime access with certificate',
                ],
            ],
            'exam' => [
                'sku' => 'SS-'.$skuBase.'-EXAM',
                'slug' => $course->slug.'-exam-plan',
                'name' => [
                    'ar' => $titleAr.' — خطة الامتحان',
                    'en' => $titleEn.' — Exam Preparation Plan',
                ],
                'short' => [
                    'ar' => 'وصول 60 يوم لمحطات المراجعة',
                    'en' => '60-day access to revision stations',
                ],
            ],
        ];

        foreach ($definitions as $key => $definition) {
            $plan = $plans[$key];

            Product::query()->updateOrCreate(
                ['sku' => $definition['sku']],
                [
                    'slug' => $definition['slug'],
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
                    'name' => $definition['name'],
                    'short_description' => $definition['short'],
                    'description' => $definition['short'],
                ],
            );
        }
    }
}
