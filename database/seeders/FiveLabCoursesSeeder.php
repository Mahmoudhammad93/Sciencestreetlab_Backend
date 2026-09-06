<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
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
 * Five school courses: one lesson each with video + external interactive + quiz, plus plans.
 *
 * Interactive URL: http://13.39.47.202/storage/interactive/index.html
 *
 * Idempotent: php artisan db:seed --class=FiveLabCoursesSeeder
 */
class FiveLabCoursesSeeder extends Seeder
{
    public const INTERACTIVE_URL = 'http://13.39.47.202/storage/interactive/index.html';

    private const SAMPLE_VIDEO = 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';

    /** @var list<string> */
    public const COURSE_SLUGS = [
        'lab-atom-building-blocks',
        'lab-atomic-discovery',
        'lab-matter-particles',
        'lab-elements-periodic',
        'lab-chemical-bonds',
    ];

    /** @var list<array{slug: string, sort: int, title: array{ar: string, en: string}, description: array{ar: string, en: string}, lesson: array{ar: string, en: string}}> */
    private const COURSES = [
        [
            'slug' => 'lab-atom-building-blocks',
            'sort' => 31,
            'title' => [
                'ar' => 'الذرة: وحدة بناء المادة',
                'en' => 'The Atom: Building Block of Matter',
            ],
            'description' => [
                'ar' => 'تعرّف على الذرة كوحدة بناء المادة عبر فيديو ونشاط تفاعلي واختبار.',
                'en' => 'Discover the atom as the building block of matter through video, interactive lab, and a quiz.',
            ],
            'lesson' => [
                'ar' => 'محطة الذرة',
                'en' => 'Atom Station',
            ],
        ],
        [
            'slug' => 'lab-atomic-discovery',
            'sort' => 32,
            'title' => [
                'ar' => 'تاريخ اكتشاف الذرة',
                'en' => 'History of Atomic Discovery',
            ],
            'description' => [
                'ar' => 'رحلة في تاريخ اكتشاف الذرة مع فيديو وتجربة تفاعلية واختبار.',
                'en' => 'A journey through the history of atomic discovery with video, interactive, and quiz.',
            ],
            'lesson' => [
                'ar' => 'محطة الاكتشاف',
                'en' => 'Discovery Station',
            ],
        ],
        [
            'slug' => 'lab-matter-particles',
            'sort' => 33,
            'title' => [
                'ar' => 'المادة والجسيمات',
                'en' => 'Matter and Particles',
            ],
            'description' => [
                'ar' => 'استكشف المادة والجسيمات عبر فيديو ومختبر تفاعلي واختبار.',
                'en' => 'Explore matter and particles with video, an interactive lab, and a quiz.',
            ],
            'lesson' => [
                'ar' => 'محطة المادة',
                'en' => 'Matter Station',
            ],
        ],
        [
            'slug' => 'lab-elements-periodic',
            'sort' => 34,
            'title' => [
                'ar' => 'العناصر والجدول الدوري',
                'en' => 'Elements and the Periodic Table',
            ],
            'description' => [
                'ar' => 'تعرّف على العناصر والجدول الدوري عبر فيديو ونشاط تفاعلي واختبار.',
                'en' => 'Learn elements and the periodic table through video, interactive activity, and a quiz.',
            ],
            'lesson' => [
                'ar' => 'محطة العناصر',
                'en' => 'Elements Station',
            ],
        ],
        [
            'slug' => 'lab-chemical-bonds',
            'sort' => 35,
            'title' => [
                'ar' => 'مقدمة في الروابط الكيميائية',
                'en' => 'Introduction to Chemical Bonds',
            ],
            'description' => [
                'ar' => 'مقدمة في الروابط الكيميائية مع فيديو وتجربة تفاعلية واختبار.',
                'en' => 'An introduction to chemical bonds with video, interactive lab, and a quiz.',
            ],
            'lesson' => [
                'ar' => 'محطة الروابط',
                'en' => 'Bonds Station',
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::COURSES as $def) {
                $this->seedCourse($def);
            }
        });

        $this->command?->info('Seeded 5 lab courses (video + interactive + quiz) with Starter/Complete plans.');
        foreach (self::COURSES as $def) {
            $this->command?->line('  - '.$def['slug']);
        }
    }

    /**
     * @param  array{slug: string, sort: int, title: array{ar: string, en: string}, description: array{ar: string, en: string}, lesson: array{ar: string, en: string}}  $def
     */
    private function seedCourse(array $def): void
    {
        $course = Course::query()->updateOrCreate(
            ['slug' => $def['slug']],
            [
                'title' => $def['title'],
                'description' => $def['description'],
                'short_description' => $def['description'],
                'access_type' => AccessType::School,
                'is_published' => true,
                'sort_order' => $def['sort'],
                'published_at' => now(),
                'estimated_hours' => 1.5,
            ],
        );

        $lessonSlug = $def['slug'].'-lesson';
        $lesson = Lesson::query()->updateOrCreate(
            ['course_id' => $course->id, 'slug' => $lessonSlug],
            [
                'title' => $def['lesson'],
                'content' => [
                    'ar' => 'درس واحد: فيديو ثم نشاط تفاعلي ثم اختبار.',
                    'en' => 'One lesson: video, then interactive lab, then quiz.',
                ],
                'lesson_type' => LessonType::Theory->value,
                'sort_order' => 1,
                'is_published' => true,
            ],
        );

        $videoTopic = Topic::query()->updateOrCreate(
            ['lesson_id' => $lesson->id, 'slug' => $lessonSlug.'-video'],
            [
                'title' => [
                    'ar' => 'فيديو الدرس',
                    'en' => 'Lesson video',
                ],
                'content_type' => TopicContentType::Video->value,
                'content' => [
                    'ar' => 'شاهد الفيديو التمهيدي للدرس.',
                    'en' => 'Watch the introductory lesson video.',
                ],
                'video_url' => self::SAMPLE_VIDEO,
                'video_provider' => 'external',
                'sort_order' => 1,
                'is_published' => true,
            ],
        );

        $interactiveTopic = Topic::query()->updateOrCreate(
            ['lesson_id' => $lesson->id, 'slug' => $lessonSlug.'-interactive'],
            [
                'title' => [
                    'ar' => 'المختبر التفاعلي — الذرة',
                    'en' => 'Interactive lab — The Atom',
                ],
                'content_type' => TopicContentType::Interactive->value,
                'content' => [
                    'ar' => 'توقّع ← جرّب ← لاحظ ← فسّر',
                    'en' => 'Predict → Try → Observe → Explain',
                ],
                // Lesson API exposes this as file_url for the interactive iframe.
                'video_url' => self::INTERACTIVE_URL,
                'video_provider' => 'external',
                'sort_order' => 2,
                'is_published' => true,
            ],
        );

        $activity = InteractiveActivity::query()->updateOrCreate(
            [
                'lesson_id' => $lesson->id,
                'activity_config->lab_key' => $def['slug'],
            ],
            [
                'topic_id' => $interactiveTopic->id,
                'activity_type' => InteractiveActivityType::Simulation->value,
                'status' => InteractiveActivityStatus::Published,
                'difficulty' => QuestionDifficulty::Medium,
                'points' => 50,
                'estimated_time_seconds' => 900,
                'version' => 1,
                'entry_file' => 'index.html',
                'activity_config' => [
                    'lab_key' => $def['slug'],
                    'external_url' => self::INTERACTIVE_URL,
                ],
                'title' => [
                    'ar' => 'نشاط تفاعلي — الذرة',
                    'en' => 'Interactive activity — The Atom',
                ],
                'description' => [
                    'ar' => 'مختبر شارع العلوم التفاعلي.',
                    'en' => 'Science Street interactive lab.',
                ],
                'instructions' => [
                    'ar' => 'افتح النشاط وأكمل المحطات.',
                    'en' => 'Open the activity and complete the stations.',
                ],
            ],
        );

        $quiz = Quiz::query()->updateOrCreate(
            [
                'quizable_type' => Lesson::class,
                'quizable_id' => $lesson->id,
            ],
            [
                'passing_score' => 60,
                'max_attempts' => 3,
                'is_required' => true,
                'title' => [
                    'ar' => 'اختبار '.$def['title']['ar'],
                    'en' => $def['title']['en'].' Quiz',
                ],
                'instructions' => [
                    'ar' => 'أجب على الأسئلة بعد مشاهدة الفيديو وإكمال النشاط التفاعلي.',
                    'en' => 'Answer after watching the video and completing the interactive lab.',
                ],
            ],
        );

        $this->seedQuizQuestions($quiz, $def['title']);
        $this->seedPlans($course, $lesson, $videoTopic, $interactiveTopic, $quiz, $activity);
    }

    /**
     * @param  array{ar: string, en: string}  $courseTitle
     */
    private function seedQuizQuestions(Quiz $quiz, array $courseTitle): void
    {
        $questions = [
            [
                'ar' => 'ما وحدة بناء المادة؟',
                'en' => 'What is the building block of matter?',
                'correct' => ['ar' => 'الذرة', 'en' => 'The atom'],
                'wrong' => ['ar' => 'الكوكب', 'en' => 'The planet'],
            ],
            [
                'ar' => 'أي ترتيب صحيح للتعلّم في المختبر التفاعلي؟',
                'en' => 'Which learning order is used in the interactive lab?',
                'correct' => [
                    'ar' => 'توقّع ← جرّب ← لاحظ ← فسّر',
                    'en' => 'Predict → Try → Observe → Explain',
                ],
                'wrong' => [
                    'ar' => 'احفظ ← انسخ ← انسَ',
                    'en' => 'Memorize → Copy → Forget',
                ],
            ],
            [
                'ar' => 'ما الهدف من نشاط «'.$courseTitle['ar'].'»؟',
                'en' => 'What is the goal of the “'.$courseTitle['en'].'” activity?',
                'correct' => [
                    'ar' => 'فهم المفاهيم عبر التجربة والملاحظة',
                    'en' => 'Understand concepts through experiment and observation',
                ],
                'wrong' => [
                    'ar' => 'حفظ التعريفات فقط دون تجربة',
                    'en' => 'Only memorize definitions without experimenting',
                ],
            ],
            [
                'ar' => 'أين يُعرض المحتوى التفاعلي لهذا الدرس؟',
                'en' => 'Where is this lesson’s interactive content shown?',
                'correct' => [
                    'ar' => 'داخل موضوع المختبر التفاعلي',
                    'en' => 'Inside the interactive lab topic',
                ],
                'wrong' => [
                    'ar' => 'في صفحة الدفع فقط',
                    'en' => 'Only on the payment page',
                ],
            ],
            [
                'ar' => 'بعد الفيديو والنشاط، ماذا يأتي؟',
                'en' => 'After the video and activity, what comes next?',
                'correct' => ['ar' => 'الاختبار', 'en' => 'The quiz'],
                'wrong' => ['ar' => 'لا شيء', 'en' => 'Nothing'],
            ],
        ];

        foreach ($questions as $i => $q) {
            $sort = $i + 1;
            $question = Question::query()->updateOrCreate(
                ['quiz_id' => $quiz->id, 'sort_order' => $sort],
                [
                    'question_type' => QuestionType::SingleChoice,
                    'points' => 1,
                    'body' => ['ar' => $q['ar'], 'en' => $q['en']],
                ],
            );

            QuestionOption::query()->updateOrCreate(
                ['question_id' => $question->id, 'sort_order' => 1],
                ['is_correct' => true, 'label' => $q['correct']],
            );
            QuestionOption::query()->updateOrCreate(
                ['question_id' => $question->id, 'sort_order' => 2],
                ['is_correct' => false, 'label' => $q['wrong']],
            );
        }
    }

    private function seedPlans(
        Course $course,
        Lesson $lesson,
        Topic $videoTopic,
        Topic $interactiveTopic,
        Quiz $quiz,
        InteractiveActivity $activity,
    ): void {
        $sync = app(CoursePlanEntitlementSyncService::class);

        $starter = CoursePlan::query()->updateOrCreate(
            ['course_id' => $course->id, 'name->en' => 'Starter Plan'],
            [
                'name' => ['ar' => 'خطة البداية', 'en' => 'Starter Plan'],
                'description' => [
                    'ar' => 'يشمل الفيديو والنشاط التفاعلي والاختبار.',
                    'en' => 'Includes video, interactive lab, and quiz.',
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
                    'ar' => 'وصول كامل للدرس مع شهادة.',
                    'en' => 'Full lesson access with certificate.',
                ],
                'price' => 79,
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
            'lesson_ids' => [$lesson->id],
            'topic_ids' => [$videoTopic->id, $interactiveTopic->id],
            'quiz_ids' => [$quiz->id],
            'interactive_activity_ids' => [$activity->id],
        ];

        $sync->syncPlanEntitlements($starter, $entitlements);
        $sync->syncPlanEntitlements($complete, $entitlements);

        $this->seedPlanProducts($course, $starter, $complete);
    }

    private function seedPlanProducts(Course $course, CoursePlan $starter, CoursePlan $complete): void
    {
        $titleAr = (string) $course->getTranslation('title', 'ar');
        $titleEn = (string) $course->getTranslation('title', 'en');
        $skuBase = strtoupper(str_replace('-', '_', $course->slug));

        Product::query()->updateOrCreate(
            ['sku' => 'SS-'.$skuBase.'-STARTER'],
            [
                'slug' => $course->slug.'-starter-plan',
                'type' => ProductType::Course,
                'status' => ProductStatus::Published,
                'price' => $starter->price,
                'currency' => $starter->currency,
                'manage_stock' => false,
                'is_featured' => false,
                'course_id' => $course->id,
                'course_plan_id' => $starter->id,
                'sort_order' => (int) $starter->sort_order,
                'published_at' => now(),
                'name' => [
                    'ar' => $titleAr.' — خطة البداية',
                    'en' => $titleEn.' — Starter Plan',
                ],
                'short_description' => [
                    'ar' => 'منتج كتالوج لخطة البداية.',
                    'en' => 'Catalog product for the starter plan.',
                ],
                'description' => [
                    'ar' => 'منتج كتالوج لخطة البداية.',
                    'en' => 'Catalog product for the starter plan.',
                ],
            ],
        );

        Product::query()->updateOrCreate(
            ['sku' => 'SS-'.$skuBase.'-COMPLETE'],
            [
                'slug' => $course->slug.'-complete-plan',
                'type' => ProductType::Course,
                'status' => ProductStatus::Published,
                'price' => $complete->price,
                'currency' => $complete->currency,
                'manage_stock' => false,
                'is_featured' => false,
                'course_id' => $course->id,
                'course_plan_id' => $complete->id,
                'sort_order' => (int) $complete->sort_order,
                'published_at' => now(),
                'name' => [
                    'ar' => $titleAr.' — الخطة الكاملة',
                    'en' => $titleEn.' — Complete Plan',
                ],
                'short_description' => [
                    'ar' => 'منتج كتالوج للخطة الكاملة.',
                    'en' => 'Catalog product for the complete plan.',
                ],
                'description' => [
                    'ar' => 'منتج كتالوج للخطة الكاملة.',
                    'en' => 'Catalog product for the complete plan.',
                ],
            ],
        );
    }
}
