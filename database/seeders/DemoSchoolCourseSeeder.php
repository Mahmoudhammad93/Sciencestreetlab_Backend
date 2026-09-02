<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizOfficialScore;
use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use App\Modules\Learning\Application\Services\CoursePlanEntitlementSyncService;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Domain\Enums\TopicContentType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo school course with configurable plans, full curriculum, and official-score QA data.
 *
 * Run: php artisan db:seed --class=DemoSchoolCourseSeeder
 * (Requires ScienceStreetSeeder / AssessmentDemoCoursesSeeder for HTML lab sources.)
 */
final class DemoSchoolCourseSeeder extends Seeder
{
    public const COURSE_SLUG = 'demo-school-physics';

    public const DEMO_STUDENT_EMAIL = 'school-demo@sciencestreetlab.com';

    private const IMAGE = 'https://sciencestreetlab.com/wp-content/uploads/2026/01/download-37.jpg';

    private const SAMPLE_VIDEO = 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';

    private const SAMPLE_PDF = 'https://mozilla.github.io/pdf.js/web/compressed.tracemonkey-pldi-09.pdf';

    /** @var array<string, Lesson> */
    private array $lessons = [];

    /** @var array<string, Quiz> */
    private array $quizzes = [];

    /** @var list<InteractiveActivity> */
    private array $activities = [];

    public function run(): void
    {
        $template = CertificateTemplate::query()->firstOrCreate(
            ['slug' => 'default'],
            [
                'is_active' => true,
                'name' => ['ar' => 'شهادة افتراضية', 'en' => 'Default Certificate'],
                'layout_config' => ['page_size' => 'A4_landscape'],
            ],
        );

        $course = Course::query()->updateOrCreate(
            ['slug' => self::COURSE_SLUG],
            [
                'access_type' => AccessType::School,
                'is_published' => true,
                'published_at' => now(),
                'estimated_hours' => 8,
                'sort_order' => 20,
                'image_url' => self::IMAGE,
                'certificate_template_id' => $template->id,
                'title' => ['ar' => 'دورة فيزياء المدرسة التجريبية', 'en' => 'Demo School Physics Course'],
                'short_description' => [
                    'ar' => 'دورة مدرسية تجريبية لاختبار الخطط والاختبارات',
                    'en' => 'Demo school course for testing plans, curriculum, and official quiz scores',
                ],
                'description' => [
                    'ar' => 'محتوى فيزياء واقعي مع خطط متعددة واختبارات ونشاطات تفاعلية.',
                    'en' => 'Realistic physics content with multiple plans, quizzes, and interactive labs.',
                ],
            ],
        );

        $this->seedLessonsAndTopics($course);
        $this->seedInteractiveActivities();
        $this->ensureAllTopicTypes();
        $this->seedQuizzes();
        $plans = $this->seedPlans($course);
        $user = $this->seedDemoStudent();
        $enrollment = $this->seedEnrollment($user, $course, $plans['complete']);
        $this->seedOfficialScoreDemo($user, $enrollment);

        $this->printSummary($course, $plans, $user, $enrollment);
    }

    private function seedLessonsAndTopics(Course $course): void
    {
        $defs = [
            'intro-physics' => ['ar' => 'مقدمة في الفيزياء', 'en' => 'Introduction to Physics'],
            'motion' => ['ar' => 'الحركة', 'en' => 'Motion'],
            'forces' => ['ar' => 'القوى', 'en' => 'Forces'],
            'energy' => ['ar' => 'الطاقة', 'en' => 'Energy'],
            'electricity' => ['ar' => 'الكهرباء', 'en' => 'Electricity'],
        ];

        $order = 0;
        foreach ($defs as $slug => $title) {
            $order++;
            $lesson = Lesson::query()->updateOrCreate(
                ['course_id' => $course->id, 'slug' => $slug],
                [
                    'lesson_type' => LessonType::Theory->value,
                    'sort_order' => $order,
                    'is_published' => true,
                    'title' => $title,
                    'content' => [
                        'ar' => 'محتوى الدرس: '.$title['ar'],
                        'en' => 'Lesson content: '.$title['en'],
                    ],
                ],
            );

            Topic::query()->updateOrCreate(
                ['lesson_id' => $lesson->id, 'slug' => $slug.'-video'],
                [
                    'sort_order' => 1,
                    'content_type' => TopicContentType::Video->value,
                    'video_url' => self::SAMPLE_VIDEO,
                    'video_provider' => 'external',
                    'is_published' => true,
                    'title' => ['ar' => 'فيديو: '.$title['ar'], 'en' => 'Video: '.$title['en']],
                ],
            );

            Topic::query()->updateOrCreate(
                ['lesson_id' => $lesson->id, 'slug' => $slug.'-reading'],
                [
                    'sort_order' => 2,
                    'content_type' => TopicContentType::Text->value,
                    'is_published' => true,
                    'title' => ['ar' => 'قراءة: '.$title['ar'], 'en' => 'Reading: '.$title['en']],
                    'content' => [
                        'ar' => '<p>ملاحظات الدرس التجريبية.</p>',
                        'en' => '<p>Demo lesson reading notes for frontend QA.</p>',
                    ],
                ],
            );

            if (in_array($slug, ['intro-physics', 'motion', 'forces'], true)) {
                Topic::query()->updateOrCreate(
                    ['lesson_id' => $lesson->id, 'slug' => $slug.'-worksheet'],
                    [
                        'sort_order' => 3,
                        'content_type' => TopicContentType::Pdf->value,
                        'video_url' => self::SAMPLE_PDF,
                        'video_provider' => 'external',
                        'is_published' => true,
                        'title' => ['ar' => 'ورقة عمل PDF', 'en' => 'PDF worksheet'],
                    ],
                );
            }

            $this->lessons[$slug] = $lesson;
        }
    }

    private function seedInteractiveActivities(): void
    {
        $packages = app(InteractiveActivityPackageService::class);

        $motionActivity = $this->cloneInteractiveLab(
            $this->lessons['motion'],
            'light-lab',
            'school-demo-light-lab',
            ['ar' => 'مختبر الضوء — فيزياء المدرسة', 'en' => 'Light Lab — School Physics'],
            InteractiveActivityType::VirtualLab,
            $packages,
            base_path('resources/examples/interactive-activities/light-lab'),
        );

        $forcesActivity = $this->cloneInteractiveLab(
            $this->lessons['forces'],
            'sound-lab',
            'school-demo-sound-lab',
            ['ar' => 'مختبر الصوت — فيزياء المدرسة', 'en' => 'Sound Lab — School Physics'],
            InteractiveActivityType::VirtualLab,
            $packages,
            base_path('resources/examples/interactive-activities/sound-lab'),
        );

        $this->activities = [$motionActivity, $forcesActivity];
    }

    /**
     * @param  array{ar:string,en:string}  $title
     */
    private function cloneInteractiveLab(
        Lesson $lesson,
        string $sourceDemoKey,
        string $spreadKey,
        array $title,
        InteractiveActivityType $type,
        InteractiveActivityPackageService $packages,
        string $fallbackSourceDir,
    ): InteractiveActivity {
        $fullSpreadKey = self::COURSE_SLUG.':'.$spreadKey;

        $existing = InteractiveActivity::query()
            ->where('lesson_id', $lesson->id)
            ->where('activity_config->spread_key', $fullSpreadKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $source = InteractiveActivity::query()
            ->where('status', InteractiveActivityStatus::Published)
            ->where('activity_config->demo_key', $sourceDemoKey)
            ->whereNotNull('activity_package_path')
            ->orderBy('id')
            ->first();

        if ($source) {
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
                    ['spread_key' => $fullSpreadKey, 'cloned_from_activity_id' => $source->id],
                ),
                'title' => $title,
                'description' => $source->getTranslations('description'),
                'instructions' => $source->getTranslations('instructions'),
            ]);
            $packages->duplicatePackage($source, $clone);

            return $clone->fresh();
        }

        $activity = InteractiveActivity::query()->updateOrCreate(
            ['lesson_id' => $lesson->id, 'activity_config->spread_key' => $fullSpreadKey],
            [
                'activity_type' => $type->value,
                'status' => InteractiveActivityStatus::Published,
                'difficulty' => QuestionDifficulty::Medium,
                'points' => 40,
                'estimated_time_seconds' => 600,
                'version' => 1,
                'entry_file' => 'index.html',
                'activity_config' => ['spread_key' => $fullSpreadKey, 'demo_key' => $spreadKey],
                'title' => $title,
                'description' => [
                    'ar' => 'نشاط تفاعلي HTML',
                    'en' => 'Interactive HTML activity',
                ],
                'instructions' => [
                    'ar' => 'أكمل النشاط التفاعلي.',
                    'en' => 'Complete the interactive activity.',
                ],
            ],
        );

        if ($activity->activity_package_path === null && is_dir($fallbackSourceDir)) {
            $packages->storeFromDirectory($activity, $fallbackSourceDir, 'index.html');
        }

        return $activity->fresh();
    }

    private function ensureAllTopicTypes(): void
    {
        $seeder = new EnsureAllTopicTypesSeeder();
        foreach ($this->lessons as $lesson) {
            $seeder->ensureTypes($lesson->fresh(['topics', 'interactiveActivities']));
        }
    }

    private function seedQuizzes(): void
    {
        $quizDefs = [
            'intro-physics' => ['ar' => 'اختبار مقدمة الفيزياء', 'en' => 'Introduction to Physics Quiz'],
            'motion' => ['ar' => 'اختبار الحركة', 'en' => 'Motion Quiz'],
            'forces' => ['ar' => 'اختبار القوى', 'en' => 'Forces Quiz'],
            'energy' => ['ar' => 'اختبار الطاقة', 'en' => 'Energy Quiz'],
        ];

        foreach ($quizDefs as $lessonSlug => $title) {
            $lesson = $this->lessons[$lessonSlug];
            $questionCount = $lessonSlug === 'intro-physics' ? 10 : 5;

            $quiz = Quiz::query()->updateOrCreate(
                [
                    'quizable_type' => Lesson::class,
                    'quizable_id' => $lesson->id,
                ],
                [
                    'passing_score' => 60,
                    'max_attempts' => 3,
                    'is_required' => true,
                    'title' => $title,
                    'instructions' => [
                        'ar' => 'أجب على الأسئلة التالية',
                        'en' => 'Answer the following questions',
                    ],
                ],
            );

            for ($i = 1; $i <= $questionCount; $i++) {
                $question = Question::query()->updateOrCreate(
                    ['quiz_id' => $quiz->id, 'sort_order' => $i],
                    [
                        'question_type' => QuestionType::SingleChoice,
                        'points' => 1,
                        'body' => [
                            'ar' => "سؤال {$i}: ما المفهوم الصحيح في {$title['ar']}?",
                            'en' => "Question {$i}: What is the correct concept in {$title['en']}?",
                        ],
                    ],
                );

                QuestionOption::query()->updateOrCreate(
                    ['question_id' => $question->id, 'sort_order' => 1],
                    ['is_correct' => true, 'label' => ['ar' => 'الإجابة الصحيحة', 'en' => 'Correct answer']],
                );
                QuestionOption::query()->updateOrCreate(
                    ['question_id' => $question->id, 'sort_order' => 2],
                    ['is_correct' => false, 'label' => ['ar' => 'إجابة خاطئة', 'en' => 'Wrong answer']],
                );
            }

            $this->quizzes[$lessonSlug] = $quiz->fresh();
        }
    }

    /**
     * @return array{starter: CoursePlan, complete: CoursePlan, exam: CoursePlan, inactive: CoursePlan}
     */
    private function seedPlans(Course $course): array
    {
        $sync = app(CoursePlanEntitlementSyncService::class);

        $starter = CoursePlan::query()->updateOrCreate(
            ['course_id' => $course->id, 'name->en' => 'Starter Plan'],
            [
                'name' => ['ar' => 'خطة البداية', 'en' => 'Starter Plan'],
                'description' => ['ar' => 'دروس واختبارات محدودة', 'en' => 'Limited lessons and quizzes'],
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
                'description' => ['ar' => 'وصول كامل مدى الحياة', 'en' => 'Full lifetime access'],
                'price' => 499,
                'currency' => 'EGP',
                'is_active' => true,
                'is_lifetime' => true,
                'duration_days' => null,
                'max_quiz_attempts' => 3,
                'grant_certificate' => true,
                'sort_order' => 2,
            ],
        );

        $exam = CoursePlan::query()->updateOrCreate(
            ['course_id' => $course->id, 'name->en' => 'Exam Preparation Plan'],
            [
                'name' => ['ar' => 'خطة الاستعداد للامتحان', 'en' => 'Exam Preparation Plan'],
                'description' => ['ar' => 'وصول جزئي للمراجعة', 'en' => 'Partial access for exam prep'],
                'price' => 299,
                'currency' => 'EGP',
                'is_active' => true,
                'is_lifetime' => false,
                'duration_days' => 60,
                'max_quiz_attempts' => 1,
                'grant_certificate' => false,
                'sort_order' => 3,
            ],
        );

        $inactive = CoursePlan::query()->updateOrCreate(
            ['course_id' => $course->id, 'name->en' => 'Legacy Inactive Plan'],
            [
                'name' => ['ar' => 'خطة قديمة', 'en' => 'Legacy Inactive Plan'],
                'description' => ['ar' => 'غير متاحة', 'en' => 'Not available'],
                'price' => 99,
                'currency' => 'EGP',
                'is_active' => false,
                'is_lifetime' => false,
                'duration_days' => 14,
                'max_quiz_attempts' => 1,
                'grant_certificate' => false,
                'sort_order' => 99,
            ],
        );

        $allLessonIds = collect($this->lessons)->pluck('id')->all();
        $allQuizIds = collect($this->quizzes)->pluck('id')->all();
        $allActivityIds = collect($this->activities)->pluck('id')->all();
        $allTopicIds = Topic::query()
            ->whereIn('lesson_id', $allLessonIds)
            ->pluck('id')
            ->all();

        $sync->syncPlanEntitlements($starter, [
            'lesson_ids' => [
                $this->lessons['intro-physics']->id,
                $this->lessons['motion']->id,
            ],
            'quiz_ids' => [
                $this->quizzes['intro-physics']->id,
                $this->quizzes['motion']->id,
            ],
        ]);

        $sync->syncPlanEntitlements($complete, [
            'lesson_ids' => $allLessonIds,
            'topic_ids' => $allTopicIds,
            'quiz_ids' => $allQuizIds,
            'interactive_activity_ids' => $allActivityIds,
        ]);

        $motionTopics = Topic::query()
            ->where('lesson_id', $this->lessons['motion']->id)
            ->pluck('id')
            ->all();

        $sync->syncPlanEntitlements($exam, [
            'lesson_ids' => [
                $this->lessons['intro-physics']->id,
                $this->lessons['forces']->id,
                $this->lessons['electricity']->id,
            ],
            'topic_ids' => $motionTopics,
            'quiz_ids' => [
                $this->quizzes['intro-physics']->id,
                $this->quizzes['forces']->id,
            ],
            'interactive_activity_ids' => [$this->activities[0]->id],
        ]);

        return compact('starter', 'complete', 'exam', 'inactive');
    }

    private function seedDemoStudent(): User
    {
        return User::query()->updateOrCreate(
            ['email' => self::DEMO_STUDENT_EMAIL],
            [
                'name' => 'School Demo Student',
                'password' => Hash::make('password'),
                'locale' => 'en',
                'email_verified_at' => now(),
            ],
        );
    }

    private function seedEnrollment(User $user, Course $course, CoursePlan $plan): Enrollment
    {
        Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->delete();

        return app(EnrollUserService::class)->enroll($user, $course, null, $plan);
    }

    private function seedOfficialScoreDemo(User $user, Enrollment $enrollment): void
    {
        $quiz = $this->quizzes['intro-physics'];
        $questions = $quiz->questions()->with('options')->orderBy('sort_order')->get();

        $quiz->attempts()->where('user_id', $user->id)->delete();
        QuizOfficialScore::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('quiz_id', $quiz->id)
            ->delete();

        $service = app(QuizAttemptService::class);

        $attempt1 = $service->start($user, $quiz, $enrollment);
        $answers60 = $questions->map(function (Question $question, int $index) {
            $correct = $question->options->firstWhere('is_correct', true);
            $wrong = $question->options->firstWhere('is_correct', false);
            $pickCorrect = $index < 6;

            return [
                'question_id' => $question->id,
                'selected_option_ids' => [($pickCorrect ? $correct : $wrong)?->id],
            ];
        })->all();
        $service->submit($attempt1, $answers60);

        $attempt2 = $service->start($user, $quiz, $enrollment);
        $answers90 = $questions->map(function (Question $question, int $index) {
            $correct = $question->options->firstWhere('is_correct', true);
            $wrong = $question->options->firstWhere('is_correct', false);
            $pickCorrect = $index < 9;

            return [
                'question_id' => $question->id,
                'selected_option_ids' => [($pickCorrect ? $correct : $wrong)?->id],
            ];
        })->all();
        $service->submit($attempt2, $answers90);

        $attempt1->refresh();
        $attempt2->refresh();

        $this->command?->info("Official score demo: attempt #{$attempt1->attempt_number} = {$attempt1->percentage}% (official), attempt #{$attempt2->attempt_number} = {$attempt2->percentage}% (retry)");
    }

    /**
     * @param  array{starter: CoursePlan, complete: CoursePlan, exam: CoursePlan, inactive: CoursePlan}  $plans
     */
    private function printSummary(Course $course, array $plans, User $user, Enrollment $enrollment): void
    {
        $introQuiz = $this->quizzes['intro-physics'];

        $this->command?->info('');
        $this->command?->info('=== Demo School Physics Course Ready ===');
        $this->command?->info("Course: {$course->getTranslation('title', 'en')} (slug: ".self::COURSE_SLUG.", id: {$course->id}, access_type: school)");
        $this->command?->info('Plans: Starter (id '.$plans['starter']->id.'), Complete (id '.$plans['complete']->id.'), Exam Prep (id '.$plans['exam']->id.')');
        $this->command?->info('Student: '.self::DEMO_STUDENT_EMAIL.' / password');
        $this->command?->info("Enrollment id: {$enrollment->id} on Complete Plan (lifetime, expires_at: null)");
        $this->command?->info("Intro quiz id: {$introQuiz->id} — official score demo (60% official, 90% retry)");
        $this->command?->info('');
        $this->command?->info('API examples:');
        $this->command?->info('  GET /api/v1/courses/'.self::COURSE_SLUG);
        $this->command?->info('  GET /api/v1/courses/'.self::COURSE_SLUG.'/plans');
        $this->command?->info('  GET /api/v1/courses/'.self::COURSE_SLUG.'/access');
        $this->command?->info('  GET /api/v1/courses/'.self::COURSE_SLUG.'/enrollment');
        $this->command?->info("  GET /api/v1/quizzes/{$introQuiz->id}");
        $this->command?->info('');
    }
}
