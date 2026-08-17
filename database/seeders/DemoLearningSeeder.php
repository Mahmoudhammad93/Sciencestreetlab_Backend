<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\QuestionBankStatus;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Demo student + a free course with playable videos, classic quizzes,
 * and an interactive HTML assessment — ready for frontend testing.
 */
final class DemoLearningSeeder extends Seeder
{
    private const SAMPLE_VIDEOS = [
        'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
        'https://media.w3.org/2010/05/sintel/trailer.mp4',
        'https://test-videos.co.uk/vids/bigbuckbunny/mp4/h264/360/Big_Buck_Bunny_360_10s_1MB.mp4',
        'https://download.samplelib.com/mp4/sample-5s.mp4',
    ];

    private const IMAGE = 'https://sciencestreetlab.com/wp-content/uploads/2026/01/download-37.jpg';

    public function run(): void
    {
        $template = CertificateTemplate::query()->firstOrCreate(
            ['slug' => 'default'],
            [
                'is_active' => true,
                'name' => ['ar' => 'شهادة افتراضية', 'en' => 'Default Certificate'],
                'layout_config' => ['page_size' => 'A4_landscape'],
            ]
        );

        $demo = User::query()->updateOrCreate(
            ['email' => 'demo@sciencestreetlab.com'],
            [
                'name' => 'Demo Student',
                'password' => Hash::make('password'),
                'locale' => 'ar',
                'email_verified_at' => now(),
            ]
        );

        $course = Course::query()->updateOrCreate(
            ['slug' => 'full-learning-lab'],
            [
                'access_type' => AccessType::Free,
                'is_published' => true,
                'published_at' => now(),
                'estimated_hours' => 3.5,
                'sort_order' => 0,
                'image_url' => self::IMAGE,
                'certificate_template_id' => $template->id,
                'title' => [
                    'ar' => 'مختبر التعلم الكامل',
                    'en' => 'Full Learning Lab',
                ],
                'short_description' => [
                    'ar' => 'فيديوهات + اختبارات + نشاط تفاعلي — للتجربة',
                    'en' => 'Videos + quizzes + interactive activity — for demos',
                ],
                'description' => [
                    'ar' => 'كورس مجاني يحتوي على دروس فيديو، اختبار تقليدي، واختبار تفاعلي HTML.',
                    'en' => 'Free course with video lessons, a classic assessment, and an interactive HTML assessment.',
                ],
            ]
        );

        $videoLesson = $this->seedVideoLesson($course);
        $classicQuizLesson = $this->seedClassicAssessmentLesson($course);
        $interactiveLesson = $this->seedInteractiveAssessmentLesson($course);

        // Enrich Intro to Science Quick Check with every question type (same set)
        $this->enrichIntroToScienceQuiz();

        Enrollment::query()->updateOrCreate(
            ['user_id' => $demo->id, 'course_id' => $course->id],
            [
                'status' => EnrollmentStatus::Active,
                'progress_percent' => 0,
                'enrolled_at' => now(),
                'started_at' => now(),
                'last_accessed_lesson_id' => $videoLesson->id,
                'last_accessed_topic_id' => $videoLesson->topics()->orderBy('sort_order')->value('id'),
                'last_accessed_at' => now(),
            ]
        );

        // Also enroll demo into other free published courses when present
        foreach (['intro-to-science', 'creative-inventors'] as $slug) {
            $extra = Course::query()->where('slug', $slug)->where('is_published', true)->first();
            if (! $extra) {
                continue;
            }
            Enrollment::query()->updateOrCreate(
                ['user_id' => $demo->id, 'course_id' => $extra->id],
                [
                    'status' => EnrollmentStatus::Active,
                    'progress_percent' => 0,
                    'enrolled_at' => now(),
                    'started_at' => now(),
                ]
            );
        }

        $this->command?->info('Demo student: demo@sciencestreetlab.com / password');
        $this->command?->info('Demo course slug: full-learning-lab (videos + quizzes + interactive)');
        unset($classicQuizLesson, $interactiveLesson);
    }

    private function seedVideoLesson(Course $course): Lesson
    {
        $lesson = Lesson::query()->updateOrCreate(
            ['course_id' => $course->id, 'slug' => 'video-lessons'],
            [
                'lesson_type' => LessonType::Theory->value,
                'sort_order' => 1,
                'is_published' => true,
                'title' => ['ar' => 'دروس الفيديو', 'en' => 'Video Lessons'],
                'content' => [
                    'ar' => 'شاهد الفيديوهات ثم انتقل للاختبارات.',
                    'en' => 'Watch the videos, then continue to assessments.',
                ],
            ]
        );

        $topics = [
            [
                'slug' => 'cells-intro-video',
                'title' => ['ar' => 'مقدمة عن الخلايا', 'en' => 'Intro to Cells'],
                'video' => self::SAMPLE_VIDEOS[0],
            ],
            [
                'slug' => 'lab-tools-video',
                'title' => ['ar' => 'أدوات المعمل', 'en' => 'Lab Tools'],
                'video' => self::SAMPLE_VIDEOS[1],
            ],
            [
                'slug' => 'safety-video',
                'title' => ['ar' => 'فيديو السلامة', 'en' => 'Safety Video'],
                'video' => self::SAMPLE_VIDEOS[2],
            ],
        ];

        foreach ($topics as $i => $topic) {
            Topic::query()->updateOrCreate(
                ['lesson_id' => $lesson->id, 'slug' => $topic['slug']],
                [
                    'sort_order' => $i + 1,
                    'content_type' => 'video',
                    'video_url' => $topic['video'],
                    'video_provider' => 's3',
                    'is_published' => true,
                    'title' => $topic['title'],
                    'content' => [
                        'ar' => 'فيديو تعليمي قابل للتشغيل.',
                        'en' => 'Playable educational video.',
                    ],
                ]
            );
        }

        return $lesson->load('topics');
    }

    private function seedClassicAssessmentLesson(Course $course): Lesson
    {
        $lesson = Lesson::query()->updateOrCreate(
            ['course_id' => $course->id, 'slug' => 'classic-assessment'],
            [
                'lesson_type' => LessonType::Theory->value,
                'sort_order' => 2,
                'is_published' => true,
                'title' => ['ar' => 'اختبار كلاسيكي', 'en' => 'Classic Assessment'],
                'content' => [
                    'ar' => 'كل أنواع الأسئلة: اختيار، صح/خطأ، قصير، طويل، فراغ، مطابقة، ترتيب، رقمي، وتفاعلي.',
                    'en' => 'All question types: choice, true/false, short, long, blank, matching, ordering, numeric, and interactive.',
                ],
            ]
        );

        Topic::query()->updateOrCreate(
            ['lesson_id' => $lesson->id, 'slug' => 'review-before-quiz'],
            [
                'sort_order' => 1,
                'content_type' => 'video',
                'video_url' => self::SAMPLE_VIDEOS[3],
                'video_provider' => 's3',
                'is_published' => true,
                'title' => ['ar' => 'مراجعة قبل الاختبار', 'en' => 'Review before quiz'],
            ]
        );

        $bank = QuestionBank::query()->updateOrCreate(
            ['lesson_id' => $lesson->id],
            [
                'status' => QuestionBankStatus::Active,
                'title' => ['ar' => 'بنك أسئلة المختبر', 'en' => 'Lab Question Bank'],
                'description' => [
                    'ar' => 'أسئلة للتجربة الأمامية',
                    'en' => 'Questions for frontend demos',
                ],
            ]
        );

        $quiz = Quiz::query()->firstOrCreate(
            [
                'quizable_type' => Lesson::class,
                'quizable_id' => $lesson->id,
            ],
            [
                'passing_score' => 60,
                'max_attempts' => 5,
                'time_limit_seconds' => 900,
                'is_required' => true,
                'selection_mode' => QuizSelectionMode::Fixed,
                'shuffle_questions' => false,
                'title' => ['ar' => 'اختبار المعرفة', 'en' => 'Knowledge Quiz'],
                'instructions' => [
                    'ar' => 'أجب عن كل الأسئلة. يمكنك إعادة المحاولة.',
                    'en' => 'Answer all questions. Retries are allowed.',
                ],
            ]
        );

        $quiz->update([
            'passing_score' => 60,
            'max_attempts' => 5,
            'time_limit_seconds' => 900,
            'is_required' => true,
            'selection_mode' => QuizSelectionMode::Fixed,
            'shuffle_questions' => false,
            'title' => ['ar' => 'اختبار المعرفة', 'en' => 'Knowledge Quiz'],
            'instructions' => [
                'ar' => 'أجب عن كل الأسئلة. يمكنك إعادة المحاولة.',
                'en' => 'Answer all questions. Retries are allowed.',
            ],
        ]);

        $quiz->questionBanks()->syncWithoutDetaching([$bank->id]);

        $this->fillAllQuestionTypes($bank, $quiz);
        $this->abandonInProgressAttempts($quiz);

        return $lesson;
    }

    /**
     * Put every question type on Intro to Science → Quick Check so demos work on that course too.
     */
    private function enrichIntroToScienceQuiz(): void
    {
        $course = Course::query()->where('slug', 'intro-to-science')->first();
        if (! $course) {
            return;
        }

        $lesson = $course->lessons()->where('slug', 'welcome')->first();
        if (! $lesson) {
            return;
        }

        $quiz = Quiz::query()
            ->where('quizable_type', Lesson::class)
            ->where('quizable_id', $lesson->id)
            ->first();

        if (! $quiz) {
            return;
        }

        $bank = QuestionBank::query()->updateOrCreate(
            ['lesson_id' => $lesson->id],
            [
                'status' => QuestionBankStatus::Active,
                'title' => [
                    'ar' => 'بنك اختبار سريع — كل الأنواع',
                    'en' => 'Quick Check bank — all types',
                ],
                'description' => [
                    'ar' => 'كل أنواع الأسئلة لاختبار الواجهة على Intro to Science',
                    'en' => 'All question types for Intro to Science UI testing',
                ],
            ]
        );

        $quiz->questionBanks()->syncWithoutDetaching([$bank->id]);
        $quiz->update([
            'passing_score' => 40,
            'max_attempts' => 20,
            'is_required' => false,
            'selection_mode' => QuizSelectionMode::Fixed,
            'shuffle_questions' => false,
            'title' => ['ar' => 'اختبار سريع — كل الأنواع', 'en' => 'Quick Check — All Types'],
            'instructions' => [
                'ar' => 'يحتوي على كل أنواع الأسئلة بما فيها التفاعلي.',
                'en' => 'Includes every question type, including interactive HTML.',
            ],
        ]);

        $this->fillAllQuestionTypes($bank, $quiz);
        $this->abandonInProgressAttempts($quiz);

        $quiz->update([
            'title' => ['ar' => 'اختبار سريع — كل الأنواع', 'en' => 'Quick Check — All Types'],
            'is_required' => false,
        ]);

        $this->command?->info('Intro to Science Quick Check enriched with all question types (quiz #'.$quiz->id.')');
    }

    /**
     * Drop open attempts so the next start freezes the newly seeded question set.
     */
    private function abandonInProgressAttempts(Quiz $quiz): void
    {
        \App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('status', \App\Modules\Assessment\Domain\Enums\AttemptStatus::InProgress)
            ->update([
                'status' => \App\Modules\Assessment\Domain\Enums\AttemptStatus::Abandoned,
                'submitted_at' => now(),
            ]);
    }

    private function fillAllQuestionTypes(QuestionBank $bank, Quiz $quiz): void
    {
        // Clear old options when re-seeding type-complete quiz
        Question::query()->where('quiz_id', $quiz->id)->each(function (Question $q): void {
            $q->options()->delete();
        });

        // 1) Single choice
        $q1 = $this->publishQuestion($bank, $quiz, 1, [
            'question_type' => QuestionType::SingleChoice,
            'difficulty' => QuestionDifficulty::Easy,
            'points' => 1,
            'body' => ['ar' => 'ما وحدة قياس التيار؟', 'en' => 'What is the unit of electric current?'],
            'explanation' => ['ar' => 'الأمبير هو وحدة التيار.', 'en' => 'Ampere is the unit of current.'],
        ]);
        $this->options($q1, [
            ['ar' => 'فولت', 'en' => 'Volt', 'correct' => false],
            ['ar' => 'أمبير', 'en' => 'Ampere', 'correct' => true],
            ['ar' => 'واط', 'en' => 'Watt', 'correct' => false],
        ]);

        // 2) Multiple choice
        $q2 = $this->publishQuestion($bank, $quiz, 2, [
            'question_type' => QuestionType::MultipleChoice,
            'difficulty' => QuestionDifficulty::Medium,
            'points' => 2,
            'body' => ['ar' => 'اختر أدوات السلامة', 'en' => 'Select safety tools'],
            'explanation' => ['ar' => 'النظارات والقفازات.', 'en' => 'Goggles and gloves.'],
        ]);
        $this->options($q2, [
            ['ar' => 'نظارات واقية', 'en' => 'Safety goggles', 'correct' => true],
            ['ar' => 'قفازات', 'en' => 'Gloves', 'correct' => true],
            ['ar' => 'هاتف محمول', 'en' => 'Mobile phone', 'correct' => false],
        ]);

        // 3) True / false
        $q3 = $this->publishQuestion($bank, $quiz, 3, [
            'question_type' => QuestionType::TrueFalse,
            'difficulty' => QuestionDifficulty::Easy,
            'points' => 1,
            'body' => [
                'ar' => 'الماء مذيب جيد للمواد القطبية.',
                'en' => 'Water is a good solvent for polar substances.',
            ],
            'explanation' => ['ar' => 'صحيح.', 'en' => 'True.'],
        ]);
        $this->options($q3, [
            ['ar' => 'صح', 'en' => 'True', 'correct' => true],
            ['ar' => 'خطأ', 'en' => 'False', 'correct' => false],
        ]);

        // 4) Short answer
        $this->publishQuestion($bank, $quiz, 4, [
            'question_type' => QuestionType::ShortAnswer,
            'difficulty' => QuestionDifficulty::Easy,
            'points' => 1,
            'body' => ['ar' => 'ما عاصمة مصر؟', 'en' => 'Capital of Egypt?'],
            'answer_key' => ['accepted' => ['Cairo', 'cairo', 'القاهرة']],
            'explanation' => ['ar' => 'القاهرة', 'en' => 'Cairo'],
        ]);

        // 5) Long answer (manual review)
        $this->publishQuestion($bank, $quiz, 5, [
            'question_type' => QuestionType::LongAnswer,
            'difficulty' => QuestionDifficulty::Hard,
            'points' => 3,
            'body' => [
                'ar' => 'اشرح باختصار لماذا نستخدم الميكروسكوب في المختبر.',
                'en' => 'Briefly explain why we use a microscope in the lab.',
            ],
            'answer_key' => ['manual' => true],
            'explanation' => [
                'ar' => 'يحتاج مراجعة يدوية.',
                'en' => 'Needs manual review.',
            ],
        ]);

        // 6) Fill blank
        $this->publishQuestion($bank, $quiz, 6, [
            'question_type' => QuestionType::FillBlank,
            'difficulty' => QuestionDifficulty::Easy,
            'points' => 1,
            'body' => [
                'ar' => 'الخلية هي وحدة ___ الأساسية.',
                'en' => 'The cell is the basic unit of ___.',
            ],
            'answer_key' => ['accepted' => ['life', 'الحياة', 'الحياه']],
            'explanation' => ['ar' => 'الحياة', 'en' => 'life'],
        ]);

        // 7) Matching
        $q7 = $this->publishQuestion($bank, $quiz, 7, [
            'question_type' => QuestionType::Matching,
            'difficulty' => QuestionDifficulty::Medium,
            'points' => 2,
            'body' => [
                'ar' => 'طابق العضية مع وظيفتها',
                'en' => 'Match the organelle with its function',
            ],
        ]);
        $this->options($q7, [
            ['ar' => 'النواة', 'en' => 'Nucleus', 'correct' => false, 'meta' => ['side' => 'left', 'match_key' => 'dna']],
            ['ar' => 'الميتوكوندريا', 'en' => 'Mitochondria', 'correct' => false, 'meta' => ['side' => 'left', 'match_key' => 'energy']],
            ['ar' => 'تحتوي على DNA', 'en' => 'Contains DNA', 'correct' => false, 'meta' => ['side' => 'right', 'match_key' => 'dna']],
            ['ar' => 'إنتاج الطاقة', 'en' => 'Produces energy', 'correct' => false, 'meta' => ['side' => 'right', 'match_key' => 'energy']],
        ]);

        // 8) Ordering
        $q8 = $this->publishQuestion($bank, $quiz, 8, [
            'question_type' => QuestionType::Ordering,
            'difficulty' => QuestionDifficulty::Medium,
            'points' => 2,
            'body' => [
                'ar' => 'رتب خطوات التجربة',
                'en' => 'Order the experiment steps',
            ],
        ]);
        $this->options($q8, [
            ['ar' => 'ارتدِ معدات السلامة', 'en' => 'Put on safety gear', 'correct' => false],
            ['ar' => 'حضّر الأدوات', 'en' => 'Prepare tools', 'correct' => false],
            ['ar' => 'نفّذ التجربة', 'en' => 'Run the experiment', 'correct' => false],
            ['ar' => 'سجّل النتائج', 'en' => 'Record results', 'correct' => false],
        ]);

        // 9) Numeric
        $this->publishQuestion($bank, $quiz, 9, [
            'question_type' => QuestionType::Numeric,
            'difficulty' => QuestionDifficulty::Easy,
            'points' => 1,
            'body' => ['ar' => 'كم تساوي 7 + 5؟', 'en' => 'What is 7 + 5?'],
            'answer_key' => ['value' => 12, 'tolerance' => 0],
        ]);

        // 10) Interactive HTML
        $q10 = $this->publishQuestion($bank, $quiz, 10, [
            'question_type' => QuestionType::InteractiveHtml,
            'difficulty' => QuestionDifficulty::Medium,
            'points' => 5,
            'body' => [
                'ar' => 'نشاط تفاعلي: اختر النواة',
                'en' => 'Interactive: select the nucleus',
            ],
            'interactive_type' => 'html',
            'interactive_config' => [
                'sandbox' => 'allow-scripts',
                'protocol' => 'postMessage',
            ],
            'answer_key' => ['expected' => ['organelle' => 'nucleus']],
            'explanation' => [
                'ar' => 'النواة تحتوي على DNA.',
                'en' => 'The nucleus contains DNA.',
            ],
        ]);
        $this->storeInteractiveActivity($q10);

        Question::query()
            ->where('quiz_id', $quiz->id)
            ->where('sort_order', '>', 10)
            ->delete();

        $quiz->update([
            'passing_score' => 40,
            'max_attempts' => 20,
            'title' => ['ar' => 'اختبار كل الأنواع', 'en' => 'All Question Types Quiz'],
            'instructions' => [
                'ar' => 'يحتوي هذا الاختبار على كل أنواع الأسئلة بما فيها التفاعلي.',
                'en' => 'This quiz includes every question type, including interactive HTML.',
            ],
        ]);
    }

    private function seedInteractiveAssessmentLesson(Course $course): Lesson
    {
        $lesson = Lesson::query()->updateOrCreate(
            ['course_id' => $course->id, 'slug' => 'interactive-assessment'],
            [
                'lesson_type' => LessonType::Theory->value,
                'sort_order' => 3,
                'is_published' => true,
                'title' => ['ar' => 'اختبار تفاعلي', 'en' => 'Interactive Assessment'],
                'content' => [
                    'ar' => 'نشاط HTML داخل iframe مع تقييم من الخادم.',
                    'en' => 'HTML activity in an iframe with server-side grading.',
                ],
            ]
        );

        Topic::query()->updateOrCreate(
            ['lesson_id' => $lesson->id, 'slug' => 'interactive-intro'],
            [
                'sort_order' => 1,
                'content_type' => 'text',
                'video_url' => null,
                'video_provider' => null,
                'is_published' => true,
                'title' => ['ar' => 'تعليمات النشاط التفاعلي', 'en' => 'Interactive activity instructions'],
                'content' => [
                    'ar' => 'افتح الاختبار واختر النواة (nucleus) داخل النشاط.',
                    'en' => 'Open the quiz and pick the nucleus inside the activity.',
                ],
            ]
        );

        $bank = QuestionBank::query()->updateOrCreate(
            [
                'lesson_id' => $lesson->id,
            ],
            [
                'status' => QuestionBankStatus::Active,
                'title' => ['ar' => 'بنك الأنشطة التفاعلية', 'en' => 'Interactive Activities Bank'],
                'description' => [
                    'ar' => 'أسئلة HTML تفاعلية',
                    'en' => 'Interactive HTML questions',
                ],
            ]
        );

        $quiz = Quiz::query()->firstOrCreate(
            [
                'quizable_type' => Lesson::class,
                'quizable_id' => $lesson->id,
            ],
            [
                'passing_score' => 50,
                'max_attempts' => 10,
                'time_limit_seconds' => null,
                'is_required' => true,
                'selection_mode' => QuizSelectionMode::Fixed,
                'title' => ['ar' => 'نشاط الخلية التفاعلي', 'en' => 'Interactive Cell Activity'],
                'instructions' => [
                    'ar' => 'أكمل النشاط داخل الإطار ثم سلّم الاختبار.',
                    'en' => 'Complete the activity in the iframe, then submit the quiz.',
                ],
            ]
        );

        $quiz->update([
            'passing_score' => 50,
            'max_attempts' => 10,
            'is_required' => true,
            'selection_mode' => QuizSelectionMode::Fixed,
            'title' => ['ar' => 'نشاط الخلية التفاعلي', 'en' => 'Interactive Cell Activity'],
            'instructions' => [
                'ar' => 'أكمل النشاط داخل الإطار ثم سلّم الاختبار.',
                'en' => 'Complete the activity in the iframe, then submit the quiz.',
            ],
        ]);

        $quiz->questionBanks()->syncWithoutDetaching([$bank->id]);

        $question = $this->publishQuestion($bank, $quiz, 1, [
            'question_type' => QuestionType::InteractiveHtml,
            'difficulty' => QuestionDifficulty::Medium,
            'points' => 5,
            'body' => [
                'ar' => 'اختر العضية التي تحتوي على DNA',
                'en' => 'Select the organelle that contains DNA',
            ],
            'interactive_type' => 'html',
            'interactive_config' => [
                'sandbox' => 'allow-scripts',
                'protocol' => 'postMessage',
            ],
            'answer_key' => ['expected' => ['organelle' => 'nucleus']],
            'explanation' => [
                'ar' => 'النواة تحتوي على المادة الوراثية.',
                'en' => 'The nucleus contains genetic material.',
            ],
        ]);

        $this->storeInteractiveActivity($question);

        // Second interactive for richer testing
        $question2 = $this->publishQuestion($bank, $quiz, 2, [
            'question_type' => QuestionType::InteractiveHtml,
            'difficulty' => QuestionDifficulty::Easy,
            'points' => 3,
            'body' => [
                'ar' => 'نشاط تفاعلي إضافي: اختر النواة مرة أخرى',
                'en' => 'Extra interactive: pick the nucleus again',
            ],
            'interactive_type' => 'html',
            'interactive_config' => [
                'sandbox' => 'allow-scripts',
                'protocol' => 'postMessage',
            ],
            'answer_key' => ['expected' => ['organelle' => 'nucleus']],
        ]);
        $this->storeInteractiveActivity($question2);

        Question::query()
            ->where('quiz_id', $quiz->id)
            ->where('sort_order', '>', 2)
            ->delete();

        return $lesson;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function publishQuestion(QuestionBank $bank, Quiz $quiz, int $sort, array $attrs): Question
    {
        return Question::query()->updateOrCreate(
            [
                'quiz_id' => $quiz->id,
                'sort_order' => $sort,
            ],
            array_merge([
                'question_bank_id' => $bank->id,
                'status' => QuestionStatus::Published,
                'difficulty' => QuestionDifficulty::Easy,
                'points' => 1,
            ], $attrs)
        );
    }

    /**
     * @param  list<array{ar: string, en: string, correct: bool, meta?: array<string, mixed>}>  $options
     */
    private function options(Question $question, array $options): void
    {
        foreach ($options as $i => $option) {
            QuestionOption::query()->updateOrCreate(
                ['question_id' => $question->id, 'sort_order' => $i + 1],
                [
                    'is_correct' => $option['correct'],
                    'label' => ['ar' => $option['ar'], 'en' => $option['en']],
                    'meta' => $option['meta'] ?? null,
                ]
            );
        }
    }

    private function storeInteractiveActivity(Question $question): void
    {
        $example = base_path('resources/examples/interactive-question/activity.html');
        if (! File::exists($example)) {
            $this->command?->warn('Interactive example HTML missing — skipped file copy.');

            return;
        }

        $relativeDir = 'interactive-questions/'.$question->uuid;
        $relativePath = $relativeDir.'/activity.html';

        Storage::disk('public')->makeDirectory($relativeDir);
        Storage::disk('public')->put($relativePath, File::get($example));

        $question->update([
            'interactive_path' => $relativePath,
        ]);
    }
}
