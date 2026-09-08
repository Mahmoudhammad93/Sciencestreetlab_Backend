<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Domain\Enums\TopicContentType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;

/**
 * Adds 4 practice lessons per station course, each with text topics
 * introducing different question types plus a matching quiz.
 */
trait SeedsStationPracticeLessons
{
    /**
     * @return array{lesson_ids: list<int>, topic_ids: list<int>, quiz_ids: list<int>}
     */
    protected function seedPracticeLessons(Course $course, string $courseSlug): array
    {
        $lessonIds = [];
        $topicIds = [];
        $quizIds = [];

        foreach ($this->practiceLessonDefinitions() as $index => $def) {
            $lesson = Lesson::query()->updateOrCreate(
                ['course_id' => $course->id, 'slug' => $courseSlug.'-'.$def['slug']],
                [
                    'title' => $def['title'],
                    'content' => $def['content'],
                    'lesson_type' => LessonType::Theory->value,
                    'sort_order' => 2 + $index,
                    'is_published' => true,
                ],
            );
            $lessonIds[] = $lesson->id;

            $keptTopicIds = [];
            foreach ($def['topics'] as $topicIndex => $topicDef) {
                $topic = Topic::query()->updateOrCreate(
                    ['lesson_id' => $lesson->id, 'slug' => $lesson->slug.'-'.$topicDef['slug']],
                    [
                        'sort_order' => $topicIndex + 1,
                        'content_type' => TopicContentType::Text->value,
                        'is_published' => true,
                        'title' => $topicDef['title'],
                        'content' => $topicDef['content'],
                    ],
                );
                $keptTopicIds[] = $topic->id;
                $topicIds[] = $topic->id;
            }

            Topic::query()
                ->where('lesson_id', $lesson->id)
                ->whereNotIn('id', $keptTopicIds)
                ->delete();

            $quiz = Quiz::query()->updateOrCreate(
                [
                    'quizable_type' => Lesson::class,
                    'quizable_id' => $lesson->id,
                ],
                [
                    'passing_score' => 60,
                    'max_attempts' => 5,
                    'is_required' => false,
                    'title' => $def['quiz_title'],
                    'instructions' => $def['quiz_instructions'],
                ],
            );
            $quizIds[] = $quiz->id;

            $this->seedPracticeQuestions($quiz, $def['questions']);
        }

        return [
            'lesson_ids' => $lessonIds,
            'topic_ids' => $topicIds,
            'quiz_ids' => $quizIds,
        ];
    }

    /**
     * @return list<array{
     *   slug: string,
     *   title: array{ar: string, en: string},
     *   content: array{ar: string, en: string},
     *   quiz_title: array{ar: string, en: string},
     *   quiz_instructions: array{ar: string, en: string},
     *   topics: list<array{slug: string, title: array{ar: string, en: string}, content: array{ar: string, en: string}}>,
     *   questions: list<array<string, mixed>>
     * }>
     */
    private function practiceLessonDefinitions(): array
    {
        return [
            [
                'slug' => 'practice-choice',
                'title' => ['ar' => 'تدريب: أسئلة الاختيار', 'en' => 'Practice: Choice Questions'],
                'content' => [
                    'ar' => 'تدرب على الاختيار من متعدد وصح/خطأ.',
                    'en' => 'Practice single choice, multiple choice, and true/false.',
                ],
                'quiz_title' => ['ar' => 'اختبار الاختيار', 'en' => 'Choice Quiz'],
                'quiz_instructions' => [
                    'ar' => 'أجب عن أسئلة الاختيار بعد قراءة المواضيع.',
                    'en' => 'Answer the choice questions after reading the topics.',
                ],
                'topics' => [
                    [
                        'slug' => 'single-choice',
                        'title' => ['ar' => 'سؤال اختيار واحد', 'en' => 'Single Choice'],
                        'content' => [
                            'ar' => '<p>اختر إجابة واحدة صحيحة فقط من بين الخيارات.</p>',
                            'en' => '<p>Select exactly one correct answer from the options.</p>',
                        ],
                    ],
                    [
                        'slug' => 'multiple-choice',
                        'title' => ['ar' => 'سؤال اختيار متعدد', 'en' => 'Multiple Choice'],
                        'content' => [
                            'ar' => '<p>يمكن أن تكون أكثر من إجابة صحيحة — حدّد كل الإجابات الصحيحة.</p>',
                            'en' => '<p>More than one answer may be correct — select all that apply.</p>',
                        ],
                    ],
                    [
                        'slug' => 'true-false',
                        'title' => ['ar' => 'سؤال صح أو خطأ', 'en' => 'True / False'],
                        'content' => [
                            'ar' => '<p>اقرأ العبارة وحدّد إن كانت صحيحة أم خاطئة.</p>',
                            'en' => '<p>Read the statement and decide if it is true or false.</p>',
                        ],
                    ],
                ],
                'questions' => [
                    [
                        'type' => QuestionType::SingleChoice,
                        'body' => [
                            'ar' => 'ما وحدة بناء المادة؟',
                            'en' => 'What is the building block of matter?',
                        ],
                        'options' => [
                            ['label' => ['ar' => 'الذرة', 'en' => 'The atom'], 'correct' => true],
                            ['label' => ['ar' => 'الكوكب', 'en' => 'The planet'], 'correct' => false],
                            ['label' => ['ar' => 'المجرة', 'en' => 'The galaxy'], 'correct' => false],
                        ],
                    ],
                    [
                        'type' => QuestionType::MultipleChoice,
                        'body' => [
                            'ar' => 'أي الجسيمات توجد داخل نواة الذرة؟',
                            'en' => 'Which particles are found in the atomic nucleus?',
                        ],
                        'options' => [
                            ['label' => ['ar' => 'البروتون', 'en' => 'Proton'], 'correct' => true],
                            ['label' => ['ar' => 'النيوترون', 'en' => 'Neutron'], 'correct' => true],
                            ['label' => ['ar' => 'الإلكترون', 'en' => 'Electron'], 'correct' => false],
                        ],
                    ],
                    [
                        'type' => QuestionType::TrueFalse,
                        'body' => [
                            'ar' => 'الإلكترونات تدور حول نواة الذرة.',
                            'en' => 'Electrons orbit around the atomic nucleus.',
                        ],
                        'options' => [
                            ['label' => ['ar' => 'صح', 'en' => 'True'], 'correct' => true],
                            ['label' => ['ar' => 'خطأ', 'en' => 'False'], 'correct' => false],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'practice-written',
                'title' => ['ar' => 'تدريب: الإجابات المكتوبة', 'en' => 'Practice: Written Answers'],
                'content' => [
                    'ar' => 'تدرب على الإجابة القصيرة والطويلة وملء الفراغ.',
                    'en' => 'Practice short answer, long answer, and fill-in-the-blank.',
                ],
                'quiz_title' => ['ar' => 'اختبار الإجابات المكتوبة', 'en' => 'Written Answers Quiz'],
                'quiz_instructions' => [
                    'ar' => 'اكتب إجاباتك بعناية — بعضها يُراجع يدوياً.',
                    'en' => 'Write carefully — some answers need manual review.',
                ],
                'topics' => [
                    [
                        'slug' => 'short-answer',
                        'title' => ['ar' => 'إجابة قصيرة', 'en' => 'Short Answer'],
                        'content' => [
                            'ar' => '<p>أجب بكلمة أو جملة قصيرة. قارن النظام إجابتك بقائمة إجابات مقبولة.</p>',
                            'en' => '<p>Answer with a word or short phrase. The system matches accepted answers.</p>',
                        ],
                    ],
                    [
                        'slug' => 'fill-blank',
                        'title' => ['ar' => 'املأ الفراغ', 'en' => 'Fill in the Blank'],
                        'content' => [
                            'ar' => '<p>أكمل الجملة بالكلمة المناسبة في الفراغ.</p>',
                            'en' => '<p>Complete the sentence with the correct word in the blank.</p>',
                        ],
                    ],
                    [
                        'slug' => 'long-answer',
                        'title' => ['ar' => 'إجابة طويلة', 'en' => 'Long Answer'],
                        'content' => [
                            'ar' => '<p>اشرح الفكرة بجملتين أو أكثر. يحتاج هذا النوع مراجعة من المعلم.</p>',
                            'en' => '<p>Explain in two or more sentences. This type needs teacher review.</p>',
                        ],
                    ],
                ],
                'questions' => [
                    [
                        'type' => QuestionType::ShortAnswer,
                        'body' => [
                            'ar' => 'ما الرمز الكيميائي للهيدروجين؟',
                            'en' => 'What is the chemical symbol for hydrogen?',
                        ],
                        'answer_key' => ['accepted' => ['H', 'h', 'هيدروجين']],
                        'options' => [],
                    ],
                    [
                        'type' => QuestionType::FillBlank,
                        'body' => [
                            'ar' => 'الذرة هي وحدة بناء ___.',
                            'en' => 'The atom is the building block of ___.',
                        ],
                        'answer_key' => ['accepted' => ['matter', 'المادة', 'الماده']],
                        'options' => [],
                    ],
                    [
                        'type' => QuestionType::LongAnswer,
                        'body' => [
                            'ar' => 'اشرح باختصار الفرق بين العدد الذري وكتلة الذرة.',
                            'en' => 'Briefly explain the difference between atomic number and atomic mass.',
                        ],
                        'answer_key' => ['manual' => true],
                        'options' => [],
                    ],
                ],
            ],
            [
                'slug' => 'practice-relate',
                'title' => ['ar' => 'تدريب: المطابقة والترتيب', 'en' => 'Practice: Matching & Ordering'],
                'content' => [
                    'ar' => 'اربط المفاهيم ببعضها ورتّب الخطوات بالترتيب الصحيح.',
                    'en' => 'Match related concepts and put steps in the correct order.',
                ],
                'quiz_title' => ['ar' => 'اختبار المطابقة والترتيب', 'en' => 'Matching & Ordering Quiz'],
                'quiz_instructions' => [
                    'ar' => 'طابق العناصر ثم رتّب الخطوات.',
                    'en' => 'Match the items, then order the steps.',
                ],
                'topics' => [
                    [
                        'slug' => 'matching',
                        'title' => ['ar' => 'سؤال مطابقة', 'en' => 'Matching'],
                        'content' => [
                            'ar' => '<p>اربط كل عنصر في العمود الأيسر بما يناسبه في العمود الأيمن.</p>',
                            'en' => '<p>Connect each left-side item with its match on the right.</p>',
                        ],
                    ],
                    [
                        'slug' => 'ordering',
                        'title' => ['ar' => 'سؤال ترتيب', 'en' => 'Ordering'],
                        'content' => [
                            'ar' => '<p>رتّب الخطوات من الأولى إلى الأخيرة بالترتيب الصحيح.</p>',
                            'en' => '<p>Arrange the steps from first to last in the correct order.</p>',
                        ],
                    ],
                ],
                'questions' => [
                    [
                        'type' => QuestionType::Matching,
                        'body' => [
                            'ar' => 'طابق الجسيم مع شحنته',
                            'en' => 'Match each particle with its charge',
                        ],
                        'options' => [
                            [
                                'label' => ['ar' => 'بروتون', 'en' => 'Proton'],
                                'correct' => false,
                                'meta' => ['side' => 'left', 'match_key' => 'positive'],
                            ],
                            [
                                'label' => ['ar' => 'إلكترون', 'en' => 'Electron'],
                                'correct' => false,
                                'meta' => ['side' => 'left', 'match_key' => 'negative'],
                            ],
                            [
                                'label' => ['ar' => 'شحنة موجبة', 'en' => 'Positive charge'],
                                'correct' => false,
                                'meta' => ['side' => 'right', 'match_key' => 'positive'],
                            ],
                            [
                                'label' => ['ar' => 'شحنة سالبة', 'en' => 'Negative charge'],
                                'correct' => false,
                                'meta' => ['side' => 'right', 'match_key' => 'negative'],
                            ],
                        ],
                    ],
                    [
                        'type' => QuestionType::Ordering,
                        'body' => [
                            'ar' => 'رتّب خطوات التعلّم في المختبر التفاعلي',
                            'en' => 'Order the interactive lab learning steps',
                        ],
                        'options' => [
                            ['label' => ['ar' => 'توقّع', 'en' => 'Predict'], 'correct' => false],
                            ['label' => ['ar' => 'جرّب', 'en' => 'Try'], 'correct' => false],
                            ['label' => ['ar' => 'لاحظ', 'en' => 'Observe'], 'correct' => false],
                            ['label' => ['ar' => 'فسّر', 'en' => 'Explain'], 'correct' => false],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'practice-numeric',
                'title' => ['ar' => 'تدريب: الأسئلة الرقمية', 'en' => 'Practice: Numeric Questions'],
                'content' => [
                    'ar' => 'احسب وأدخل قيمة رقمية صحيحة.',
                    'en' => 'Calculate and enter the correct numeric value.',
                ],
                'quiz_title' => ['ar' => 'اختبار رقمي', 'en' => 'Numeric Quiz'],
                'quiz_instructions' => [
                    'ar' => 'أدخل الأرقام فقط دون وحدات.',
                    'en' => 'Enter numbers only, without units.',
                ],
                'topics' => [
                    [
                        'slug' => 'numeric',
                        'title' => ['ar' => 'سؤال رقمي', 'en' => 'Numeric'],
                        'content' => [
                            'ar' => '<p>أدخل الرقم الصحيح. قد يُقبل فرق صغير حسب إعداد التسامح.</p>',
                            'en' => '<p>Enter the correct number. A small tolerance may be allowed.</p>',
                        ],
                    ],
                    [
                        'slug' => 'numeric-tips',
                        'title' => ['ar' => 'نصائح الحساب', 'en' => 'Calculation Tips'],
                        'content' => [
                            'ar' => '<p>العدد الذري = عدد البروتونات. في الذرة المتعادلة: الإلكترونات = البروتونات.</p>',
                            'en' => '<p>Atomic number = protons. In a neutral atom: electrons = protons.</p>',
                        ],
                    ],
                ],
                'questions' => [
                    [
                        'type' => QuestionType::Numeric,
                        'body' => [
                            'ar' => 'ذرة هيدروجين متعادلة فيها بروتون واحد. كم عدد الإلكترونات؟',
                            'en' => 'A neutral hydrogen atom has 1 proton. How many electrons does it have?',
                        ],
                        'answer_key' => ['value' => 1, 'tolerance' => 0],
                        'options' => [],
                    ],
                    [
                        'type' => QuestionType::Numeric,
                        'body' => [
                            'ar' => 'إذا كان العدد الكتلي = 12 وعدد البروتونات = 6، فكم عدد النيوترونات؟',
                            'en' => 'If mass number = 12 and protons = 6, how many neutrons are there?',
                        ],
                        'answer_key' => ['value' => 6, 'tolerance' => 0],
                        'options' => [],
                    ],
                    [
                        'type' => QuestionType::SingleChoice,
                        'body' => [
                            'ar' => 'النظائر تختلف في عدد…',
                            'en' => 'Isotopes differ in the number of…',
                        ],
                        'options' => [
                            ['label' => ['ar' => 'النيوترونات', 'en' => 'Neutrons'], 'correct' => true],
                            ['label' => ['ar' => 'البروتونات', 'en' => 'Protons'], 'correct' => false],
                            ['label' => ['ar' => 'الإلكترونات فقط دائماً', 'en' => 'Electrons only, always'], 'correct' => false],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    private function seedPracticeQuestions(Quiz $quiz, array $questions): void
    {
        $keptIds = [];

        foreach ($questions as $index => $questionData) {
            /** @var QuestionType $type */
            $type = $questionData['type'];
            $sort = $index + 1;

            $question = Question::query()->updateOrCreate(
                ['quiz_id' => $quiz->id, 'sort_order' => $sort],
                [
                    'question_type' => $type,
                    'difficulty' => QuestionDifficulty::Medium,
                    'status' => QuestionStatus::Published,
                    'points' => $type === QuestionType::LongAnswer ? 3 : ($type === QuestionType::MultipleChoice ? 2 : 1),
                    'body' => $questionData['body'],
                    'answer_key' => $questionData['answer_key'] ?? [],
                    'explanation' => $questionData['explanation'] ?? null,
                ],
            );
            $keptIds[] = $question->id;

            $question->options()->delete();

            foreach ($questionData['options'] ?? [] as $optionIndex => $optionData) {
                QuestionOption::query()->create([
                    'question_id' => $question->id,
                    'sort_order' => $optionIndex + 1,
                    'is_correct' => (bool) ($optionData['correct'] ?? false),
                    'label' => $optionData['label'],
                    'meta' => $optionData['meta'] ?? null,
                ]);
            }
        }

        Question::query()
            ->where('quiz_id', $quiz->id)
            ->whereNotIn('id', $keptIds)
            ->each(function (Question $question): void {
                $question->options()->delete();
                $question->delete();
            });
    }
}
