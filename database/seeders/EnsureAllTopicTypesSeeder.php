<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Domain\Enums\TopicContentType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Database\Seeder;

/**
 * Makes sure published courses expose every topic content type:
 * video, text, PDF, and interactive (when a lab package exists).
 *
 * Skips microscope-course so the LearningFlow golden-path test stays a single video + quiz.
 */
final class EnsureAllTopicTypesSeeder extends Seeder
{
    private const SAMPLE_VIDEO = 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';

    private const SAMPLE_PDF = 'https://mozilla.github.io/pdf.js/web/compressed.tracemonkey-pldi-09.pdf';

    /**
     * Extra published courses that should each get a cloned HTML lab.
     *
     * @var array<string, string> course slug => source activity demo_key
     */
    private const COURSE_LABS = [
        'basic-chemistry-lab' => 'plant-growth',
        'electricity-basics' => 'rubber-race',
        'gear-mechanics' => 'rubber-castle',
        'school-lab-safety' => 'sound-lab',
        'intro-to-science' => 'light-lab',
        'full-learning-lab' => 'light-lab',
        'design-thinking-lab' => 'sound-lab',
        'creative-inventors' => 'rubber-castle',
    ];

    public function run(): void
    {
        $this->spreadInteractiveLabs();

        Course::query()
            ->where('is_published', true)
            ->with(['lessons.topics', 'lessons.interactiveActivities'])
            ->orderBy('id')
            ->each(function (Course $course): void {
                if ($course->slug === 'microscope-course') {
                    return;
                }

                foreach ($course->lessons as $lesson) {
                    if (! $lesson->is_published) {
                        continue;
                    }
                    $this->ensureTypes($lesson);
                }
            });

        $this->enrollDemoStudent();
    }

    private function spreadInteractiveLabs(): void
    {
        $packages = app(InteractiveActivityPackageService::class);

        foreach (self::COURSE_LABS as $courseSlug => $demoKey) {
            $course = Course::query()->where('slug', $courseSlug)->where('is_published', true)->first();
            $lesson = $course?->lessons()->where('is_published', true)->orderBy('sort_order')->first();
            if (! $course || ! $lesson) {
                continue;
            }

            $spreadKey = $courseSlug.':'.$demoKey;
            if (InteractiveActivity::query()
                ->where('lesson_id', $lesson->id)
                ->where('activity_config->spread_key', $spreadKey)
                ->exists()) {
                continue;
            }

            $source = InteractiveActivity::query()
                ->where('status', InteractiveActivityStatus::Published)
                ->where('activity_config->demo_key', $demoKey)
                ->whereNotNull('activity_package_path')
                ->orderBy('id')
                ->first();

            if (! $source) {
                $this->command?->warn('No HTML lab source for demo_key='.$demoKey);

                continue;
            }

            $clone = InteractiveActivity::query()->create([
                'lesson_id' => $lesson->id,
                'status' => InteractiveActivityStatus::Published,
                'is_required' => false,
                'activity_type' => $source->activity_type,
                'difficulty' => $source->difficulty,
                'points' => $source->points,
                'estimated_time_seconds' => $source->estimated_time_seconds,
                'version' => 1,
                'entry_file' => $source->entry_file ?: 'index.html',
                'activity_config' => array_merge(
                    is_array($source->activity_config) ? $source->activity_config : [],
                    [
                        'spread_key' => $spreadKey,
                        'cloned_from_activity_id' => $source->id,
                    ]
                ),
                'title' => $source->getTranslations('title'),
                'description' => $source->getTranslations('description'),
                'instructions' => $source->getTranslations('instructions'),
            ]);

            $packages->duplicatePackage($source, $clone);
        }
    }

    private function enrollDemoStudent(): void
    {
        $user = User::query()->where('email', 'demo@sciencestreetlab.com')->first();
        if (! $user) {
            return;
        }

        $slugs = array_keys(self::COURSE_LABS);
        Course::query()->whereIn('slug', $slugs)->where('is_published', true)->each(function (Course $course) use ($user): void {
            Enrollment::query()->updateOrCreate(
                ['user_id' => $user->id, 'course_id' => $course->id],
                [
                    'status' => EnrollmentStatus::Active,
                    'progress_percent' => 0,
                    'enrolled_at' => now(),
                    'started_at' => now(),
                ]
            );
        });
    }

    public function ensureTypes(Lesson $lesson): void
    {
        $maxOrder = (int) $lesson->topics()->max('sort_order');

        if (! $lesson->topics()->where('content_type', TopicContentType::Video->value)->exists()) {
            $this->createTopic($lesson, ++$maxOrder, 'video-intro', TopicContentType::Video, [
                'ar' => 'فيديو: '.$this->lessonTitle($lesson, 'ar'),
                'en' => 'Video: '.$this->lessonTitle($lesson, 'en'),
            ], [
                'video_url' => self::SAMPLE_VIDEO,
                'video_provider' => 'external',
            ]);
        }

        if (! $lesson->topics()->where('content_type', TopicContentType::Text->value)->exists()) {
            $this->createTopic($lesson, ++$maxOrder, 'reading', TopicContentType::Text, [
                'ar' => 'قراءة: '.$this->lessonTitle($lesson, 'ar'),
                'en' => 'Reading: '.$this->lessonTitle($lesson, 'en'),
            ], [
                'content' => [
                    'ar' => '<p>اقرأ هذه الملاحظات ثم علّم الموضوع كمكتمل.</p><p>يغطي هذا الدرس الأفكار الأساسية ويجهّزك للتجربة التفاعلية والاختبار.</p>',
                    'en' => '<p>Read these notes, then mark the topic complete.</p><p>This lesson covers the core ideas and prepares you for the interactive lab and the quiz.</p>',
                ],
            ]);
        }

        if (! $lesson->topics()->where('content_type', TopicContentType::Pdf->value)->exists()) {
            $this->createTopic($lesson, ++$maxOrder, 'worksheet', TopicContentType::Pdf, [
                'ar' => 'ورقة عمل PDF',
                'en' => 'PDF worksheet',
            ], [
                'video_url' => self::SAMPLE_PDF,
                'video_provider' => 'external',
            ]);
        }

        $activity = $lesson->interactiveActivities()->first()
            ?? InteractiveActivity::query()->where('lesson_id', $lesson->id)->first();

        $interactiveTopic = $lesson->topics()->where('content_type', TopicContentType::Interactive->value)->first();

        if ($activity && ! $interactiveTopic) {
            $interactiveTopic = $this->createTopic($lesson, ++$maxOrder, 'interactive-lab', TopicContentType::Interactive, [
                'ar' => $activity->getTranslation('title', 'ar') ?: 'نشاط تفاعلي',
                'en' => $activity->getTranslation('title', 'en') ?: 'Interactive lab',
            ]);
        }

        if ($activity && $interactiveTopic && ($activity->topic_id === null || (int) $activity->topic_id !== (int) $interactiveTopic->id)) {
            $linkedElsewhere = $activity->topic_id
                ? Topic::query()->where('id', $activity->topic_id)->where('lesson_id', $lesson->id)->exists()
                : false;
            if (! $linkedElsewhere) {
                $activity->update(['topic_id' => $interactiveTopic->id]);
            }
        }
    }

    /**
     * @param  array{ar:string,en:string}  $title
     * @param  array<string, mixed>  $extra
     */
    private function createTopic(Lesson $lesson, int $sort, string $slugSuffix, TopicContentType $type, array $title, array $extra = []): Topic
    {
        return Topic::query()->updateOrCreate(
            [
                'lesson_id' => $lesson->id,
                'slug' => $lesson->slug.'-'.$slugSuffix,
            ],
            array_merge([
                'sort_order' => $sort,
                'content_type' => $type->value,
                'is_published' => true,
                'title' => $title,
            ], $extra)
        );
    }

    private function lessonTitle(Lesson $lesson, string $locale): string
    {
        return (string) ($lesson->getTranslation('title', $locale) ?: $lesson->slug);
    }
}
