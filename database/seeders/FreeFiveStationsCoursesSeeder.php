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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Five FREE courses × 1 lesson × 5 stations (video + interactive HTML).
 * Same labs as production, free access + Free Plan (0 EGP).
 *
 * php artisan db:seed --class=FreeFiveStationsCoursesSeeder
 */
final class FreeFiveStationsCoursesSeeder extends Seeder
{
    /** @var list<string> */
    public const COURSE_SLUGS = [
        'free-station-discover',
        'free-station-explore',
        'free-station-observe',
        'free-station-experiment',
        'free-station-explain',
    ];

    /**
     * @var list<array{slug: string, title: array{ar: string, en: string}, description: array{ar: string, en: string}}>
     */
    private const COURSES = [
        [
            'slug' => 'free-station-discover',
            'title' => ['ar' => 'اكتشف — مجاني', 'en' => 'Discover — Free'],
            'description' => [
                'ar' => 'كورس مجاني بخمس محطات: فيديو + HTML تفاعلي.',
                'en' => 'Free course with five stations: video + interactive HTML.',
            ],
        ],
        [
            'slug' => 'free-station-explore',
            'title' => ['ar' => 'استكشف — مجاني', 'en' => 'Explore — Free'],
            'description' => [
                'ar' => 'رحلة مجانية عبر مختبرات شارع العلوم التفاعلية.',
                'en' => 'A free journey through Science Street interactive labs.',
            ],
        ],
        [
            'slug' => 'free-station-observe',
            'title' => ['ar' => 'لاحظ — مجاني', 'en' => 'Observe — Free'],
            'description' => [
                'ar' => 'تعلّم بالملاحظة عبر فيديو وأنشطة HTML مجانية.',
                'en' => 'Learn by observation with free video and HTML activities.',
            ],
        ],
        [
            'slug' => 'free-station-experiment',
            'title' => ['ar' => 'جرّب — مجاني', 'en' => 'Experiment — Free'],
            'description' => [
                'ar' => 'خمس تجارب تفاعلية مجانية بالكامل.',
                'en' => 'Five fully free interactive experiments.',
            ],
        ],
        [
            'slug' => 'free-station-explain',
            'title' => ['ar' => 'فسّر — مجاني', 'en' => 'Explain — Free'],
            'description' => [
                'ar' => 'من التجربة إلى التفسير — كورس مجاني كامل.',
                'en' => 'From experiment to explanation — a complete free course.',
            ],
        ],
    ];

    /**
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

        if ($playbackUrl === '') {
            $playbackUrl = self::SAMPLE_VIDEO;
            $bunnyVideoId = '';
            $this->command?->warn('Bunny playback URL not set — using sample MP4 for free courses.');
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
                        'access_type' => AccessType::Free,
                        'is_published' => true,
                        'sort_order' => 40 + $courseIndex,
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
                            'ar' => 'خمس محطات مجانية: فيديو ثم نشاط HTML تفاعلي.',
                            'en' => 'Five free stations: video then interactive HTML.',
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
                                'ar' => 'نشاط HTML مجاني من new-examples.',
                                'en' => 'Free HTML activity from new-examples.',
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

                $plan = CoursePlan::query()->updateOrCreate(
                    ['course_id' => $course->id, 'name->en' => 'Free Plan'],
                    [
                        'name' => ['ar' => 'خطة مجانية', 'en' => 'Free Plan'],
                        'description' => [
                            'ar' => 'وصول مجاني كامل لكل المحطات.',
                            'en' => 'Full free access to all stations.',
                        ],
                        'price' => 0,
                        'currency' => 'EGP',
                        'is_active' => true,
                        'is_lifetime' => true,
                        'duration_days' => null,
                        'max_quiz_attempts' => 5,
                        'grant_certificate' => false,
                        'sort_order' => 1,
                    ],
                );

                // Keep only the free plan active on these courses.
                CoursePlan::query()
                    ->where('course_id', $course->id)
                    ->where('id', '!=', $plan->id)
                    ->update(['is_active' => false]);

                $sync->syncPlanEntitlements($plan, [
                    'lesson_ids' => [$lesson->id],
                    'topic_ids' => $topicIds,
                    'interactive_activity_ids' => $activityIds,
                ]);

                $this->command?->info('  ✓ '.$courseDef['slug'].' (free, '.count($topicIds).' topics)');
            }
        });

        $this->command?->info('Seeded 5 FREE station courses.');
    }
}
