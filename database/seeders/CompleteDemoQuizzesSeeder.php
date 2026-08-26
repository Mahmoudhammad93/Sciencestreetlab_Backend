<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Ensures quizzes 1–6 each contain every question type and every HTML activity.
 */
final class CompleteDemoQuizzesSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensureSixQuizzes();
        $this->importFolderHtmlExamples();

        $activities = InteractiveActivity::query()
            ->where('status', InteractiveActivityStatus::Published)
            ->orderBy('id')
            ->get();

        $quizzes = Quiz::query()
            ->where('selection_config->demo_key', 'like', 'complete-%')
            ->orderBy('id')
            ->limit(6)
            ->get();

        foreach ($quizzes as $index => $quiz) {
            $this->attachAllQuestionTypes($quiz, $index + 1);
            $this->attachAllActivities($quiz, $activities);
        }

        $this->command?->info('Complete-demo quizzes now include all assessment question types. HTML labs are lesson topics, not quiz questions.');
    }

    /**
     * Import the 5 standalone HTML labs from /interactive examples.
     */
    private function importFolderHtmlExamples(): void
    {
        $lesson = $this->demoLesson();
        if (! $lesson) {
            return;
        }

        $packages = app(InteractiveActivityPackageService::class);
        $folder = dirname(base_path()).'/interactive examples';

        foreach ($this->folderExamples() as $key => $meta) {
            $htmlPath = $this->resolveExampleHtml($folder, $meta);
            if ($htmlPath === null) {
                $this->command?->warn('Missing interactive example: '.$meta['file']);

                continue;
            }

            $activity = InteractiveActivity::query()
                ->where('activity_config->demo_key', $key)
                ->first();

            $payload = [
                'lesson_id' => $activity?->lesson_id ?? $lesson->id,
                'activity_type' => $meta['type']->value,
                'status' => InteractiveActivityStatus::Published,
                'difficulty' => QuestionDifficulty::Medium,
                'points' => $meta['points'],
                'estimated_time_seconds' => $meta['minutes'] * 60,
                'version' => max(1, (int) ($activity?->version ?? 1)),
                'entry_file' => 'index.html',
                'activity_config' => ['demo_key' => $key, 'source' => 'interactive-examples-folder'],
                'title' => $meta['title'],
                'description' => $meta['description'],
                'instructions' => [
                    'ar' => 'شغّل النشاط داخل الصفحة وأكمل التحديات.',
                    'en' => 'Play the activity in the page and complete the challenges.',
                ],
            ];

            if ($activity) {
                $activity->update($payload);
            } else {
                $activity = InteractiveActivity::query()->create($payload);
            }

            $packages->storeFromHtmlFile($activity, $htmlPath);
        }
    }

    /**
     * @return array<string, array{file:string, folder:string, type:InteractiveActivityType, points:int, minutes:int, title:array{ar:string,en:string}, description:array{ar:string,en:string}}>
     */
    private function folderExamples(): array
    {
        return [
            'light-lab' => [
                'file' => 'الضوء 3.html',
                'folder' => 'light-lab',
                'type' => InteractiveActivityType::VirtualLab,
                'points' => 50,
                'minutes' => 15,
                'title' => ['ar' => 'مختبر الضوء', 'en' => 'Light Lab'],
                'description' => ['ar' => 'مختبر الضوء التفاعلي من مجلد الأمثلة.', 'en' => 'Interactive light lab from the examples folder.'],
            ],
            'sound-lab' => [
                'file' => 'الصوت.html',
                'folder' => 'sound-lab',
                'type' => InteractiveActivityType::VirtualLab,
                'points' => 50,
                'minutes' => 12,
                'title' => ['ar' => 'مختبر الصوت', 'en' => 'Sound Lab'],
                'description' => ['ar' => 'مختبر الصوت التفاعلي من مجلد الأمثلة.', 'en' => 'Interactive sound lab from the examples folder.'],
            ],
            'plant-growth' => [
                'file' => 'كيف تنمو النباتات.html',
                'folder' => 'plant-growth',
                'type' => InteractiveActivityType::Simulation,
                'points' => 40,
                'minutes' => 10,
                'title' => ['ar' => 'كيف تنمو النباتات', 'en' => 'How Plants Grow'],
                'description' => ['ar' => 'رحلة نمو النبات التفاعلية.', 'en' => 'Interactive plant-growth journey.'],
            ],
            'rubber-castle' => [
                'file' => 'قوة المطاط.html',
                'folder' => 'rubber-castle',
                'type' => InteractiveActivityType::Simulation,
                'points' => 40,
                'minutes' => 10,
                'title' => ['ar' => 'قوة المطاط', 'en' => 'Rubber Band Force'],
                'description' => ['ar' => 'تحدي هدم القلعة بالطاقة المرنة.', 'en' => 'Rubber-band castle demolition challenge.'],
            ],
            'rubber-race' => [
                'file' => 'قوة المطاط (سباق عربية).html',
                'folder' => 'rubber-race',
                'type' => InteractiveActivityType::Simulation,
                'points' => 30,
                'minutes' => 8,
                'title' => ['ar' => 'سباق عربية المطاط', 'en' => 'Rubber Band Car Race'],
                'description' => ['ar' => 'سباق العربية بالطاقة المرنة.', 'en' => 'Rubber-band car race.'],
            ],
        ];
    }

    /**
     * @param  array{file:string, folder:string}  $meta
     */
    private function resolveExampleHtml(string $folder, array $meta): ?string
    {
        $fromFolder = $folder.'/'.$meta['file'];
        if (is_file($fromFolder)) {
            return $fromFolder;
        }

        $fromResources = base_path('resources/examples/interactive-activities/'.$meta['folder'].'/index.html');

        return is_file($fromResources) ? $fromResources : null;
    }

    private function demoLesson(): ?Lesson
    {
        return Lesson::query()
            ->whereHas('course', fn ($q) => $q->whereIn('slug', [
                'basic-physics-lab',
                'intro-biology-lab',
                'basic-chemistry-lab',
            ]))
            ->orderBy('id')
            ->first()
            ?? Lesson::query()->orderBy('id')->first();
    }

    private function ensureSixQuizzes(): void
    {
        $count = Quiz::query()
            ->where('selection_config->demo_key', 'like', 'complete-%')
            ->count();
        if ($count >= 6) {
            return;
        }

        $lesson = $this->demoLesson();
        $bank = QuestionBank::query()->where('lesson_id', $lesson?->id)->orderBy('id')->first()
            ?? QuestionBank::query()->orderBy('id')->first();
        if (! $lesson || ! $bank) {
            return;
        }

        for ($i = $count + 1; $i <= 6; $i++) {
            Quiz::query()->create([
                'quizable_type' => Lesson::class,
                'quizable_id' => $lesson->id,
                'passing_score' => 50,
                'max_attempts' => 10,
                'is_required' => false,
                'selection_mode' => QuizSelectionMode::Fixed,
                'shuffle_questions' => false,
                'title' => [
                    'ar' => "اختبار شامل {$i}",
                    'en' => "Complete quiz {$i}",
                ],
                'instructions' => [
                    'ar' => 'كل أنواع الأسئلة + كل الأنشطة التفاعلية',
                    'en' => 'All question types plus every interactive activity',
                ],
                'selection_config' => ['demo_key' => 'complete-'.$i],
            ]);
        }
    }

    private function attachAllQuestionTypes(Quiz $quiz, int $quizNumber): void
    {
        $bank = $quiz->questionBanks()->first()
            ?? QuestionBank::query()->orderBy('id')->first();

        if (! $bank) {
            return;
        }

        $sort = 1;
        foreach (QuestionType::assessmentCases() as $type) {
            $question = Question::query()
                ->where('quiz_id', $quiz->id)
                ->where('question_type', $type)
                ->first();

            if (! $question) {
                $question = $this->makeQuestion($quiz, $bank, $type, $quizNumber, $sort);
            } else {
                $question->update(['sort_order' => $sort]);
            }

            $sort++;
        }
    }

    private function makeQuestion(
        Quiz $quiz,
        QuestionBank $bank,
        QuestionType $type,
        int $quizNumber,
        int $sort,
    ): Question {
        $label = strtoupper(str_replace('_', ' ', $type->value));
        $question = Question::query()->create([
            'question_bank_id' => $bank->id,
            'quiz_id' => $quiz->id,
            'question_type' => $type,
            'difficulty' => QuestionDifficulty::Easy,
            'status' => QuestionStatus::Published,
            'points' => 1,
            'sort_order' => $sort,
            'body' => [
                'ar' => "[Quiz {$quizNumber}] {$label}",
                'en' => "[Quiz {$quizNumber}] {$label}",
            ],
            'explanation' => [
                'ar' => 'سؤال تجريبي لكل نوع',
                'en' => 'Demo question for this type',
            ],
            'answer_key' => $this->answerKeyFor($type),
            'interactive_type' => $type === QuestionType::InteractiveHtml ? 'html' : null,
        ]);

        match ($type) {
            QuestionType::SingleChoice, QuestionType::TrueFalse => $this->options($question, [
                ['Yes / True / Nucleus', true],
                ['No / False / Cytoplasm', false],
            ]),
            QuestionType::MultipleChoice => $this->options($question, [
                ['Water', true],
                ['Sunlight', true],
                ['Plastic', false],
            ]),
            QuestionType::Matching => $this->options($question, [
                ['Nucleus', false, ['side' => 'left', 'match_key' => 'dna']],
                ['Mitochondria', false, ['side' => 'left', 'match_key' => 'energy']],
                ['Contains DNA', false, ['side' => 'right', 'match_key' => 'dna']],
                ['Makes energy', false, ['side' => 'right', 'match_key' => 'energy']],
            ]),
            QuestionType::Ordering => $this->options($question, [
                ['Observe', false],
                ['Hypothesize', false],
                ['Experiment', false],
                ['Conclude', false],
            ]),
            QuestionType::InteractiveHtml => $this->storeMiniHtml($question, $quizNumber),
            QuestionType::InteractiveActivity => $this->linkFirstActivity($question),
            default => null,
        };

        return $question;
    }

    /**
     * @return array<string, mixed>
     */
    private function answerKeyFor(QuestionType $type): array
    {
        return match ($type) {
            QuestionType::ShortAnswer, QuestionType::FillBlank => ['accepted' => ['Cairo', 'cairo', 'life']],
            QuestionType::LongAnswer => ['manual' => true],
            QuestionType::Numeric => ['value' => 12, 'tolerance' => 0],
            QuestionType::InteractiveHtml => ['expected' => ['done' => true]],
            default => [],
        };
    }

    /**
     * @param  list<array{0:string,1:bool,2?:array<string,mixed>}>  $rows
     */
    private function options(Question $question, array $rows): void
    {
        foreach ($rows as $i => $row) {
            QuestionOption::query()->updateOrCreate(
                ['question_id' => $question->id, 'sort_order' => $i + 1],
                [
                    'is_correct' => $row[1],
                    'label' => ['ar' => $row[0], 'en' => $row[0]],
                    'meta' => $row[2] ?? null,
                ]
            );
        }
    }

    private function storeMiniHtml(Question $question, int $quizNumber): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><title>Interactive {$quizNumber}</title>
<style>body{font-family:sans-serif;padding:24px;background:#2828a0;color:#fcd500;text-align:center}</style>
</head>
<body>
  <h1>نشاط تفاعلي — اختبار {$quizNumber}</h1>
  <p>اضغط إنهاء لإرسال النتيجة إلى المنصة.</p>
  <button id="done">إنهاء</button>
  <script>
    window.parent.postMessage({ type: 'READY' }, '*');
    document.getElementById('done').onclick = function () {
      window.parent.postMessage({ type: 'ACTIVITY_COMPLETED', completed: true, score: 10, max_score: 10, percentage: 100 }, '*');
    };
  </script>
</body>
</html>
HTML;

        $dir = 'interactive-questions/'.$question->uuid;
        Storage::disk('public')->makeDirectory($dir);
        Storage::disk('public')->put($dir.'/activity.html', $html);
        $question->update(['interactive_path' => $dir.'/activity.html']);
    }

    private function linkFirstActivity(Question $question): void
    {
        $activity = InteractiveActivity::query()->orderBy('id')->first();
        if ($activity) {
            $question->update(['interactive_activity_id' => $activity->id]);
        }
    }

    private function attachAllActivities(Quiz $quiz, $activities): void
    {
        $lesson = $quiz->quizable instanceof Lesson ? $quiz->quizable : Lesson::query()->orderBy('id')->first();
        if (! $lesson) {
            return;
        }

        foreach ($activities as $i => $activity) {
            $this->ensureInteractiveTopic($activity, $lesson, $i + 1);
        }
    }

    private function ensureInteractiveTopic(InteractiveActivity $activity, Lesson $lesson, int $sort): void
    {
        if ($activity->topic_id) {
            return;
        }

        $slug = 'interactive-'.$activity->id;
        $topic = Topic::query()->firstOrCreate(
            ['lesson_id' => $lesson->id, 'slug' => $slug],
            [
                'sort_order' => 800 + $sort,
                'content_type' => 'interactive',
                'is_published' => true,
                'title' => $activity->getTranslations('title') ?: ['en' => 'Interactive', 'ar' => 'تفاعلي'],
            ]
        );

        $activity->update(['topic_id' => $topic->id, 'lesson_id' => $activity->lesson_id ?: $lesson->id]);
    }
}
