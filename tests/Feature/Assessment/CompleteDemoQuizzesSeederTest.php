<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionBankStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Database\Seeders\CompleteDemoQuizzesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CompleteDemoQuizzesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_six_quizzes_include_all_types_and_all_activities(): void
    {
        Storage::fake('public');

        $course = Course::query()->create([
            'slug' => 'complete-demo',
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Demo', 'ar' => 'تجريبي'],
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'lesson-1',
            'lesson_type' => 'theory',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'Lesson', 'ar' => 'درس'],
        ]);
        $bank = QuestionBank::query()->create([
            'lesson_id' => $lesson->id,
            'status' => QuestionBankStatus::Active,
            'title' => ['en' => 'Bank', 'ar' => 'بنك'],
        ]);
        $quiz = Quiz::query()->create([
            'quizable_type' => Lesson::class,
            'quizable_id' => $lesson->id,
            'passing_score' => 50,
            'selection_mode' => QuizSelectionMode::Fixed,
            'title' => ['en' => 'Quiz 1', 'ar' => 'اختبار 1'],
        ]);
        $quiz->questionBanks()->sync([$bank->id]);

        InteractiveActivity::query()->create([
            'lesson_id' => $lesson->id,
            'status' => InteractiveActivityStatus::Published,
            'activity_type' => InteractiveActivityType::VirtualLab,
            'title' => ['en' => 'Light Lab', 'ar' => 'مختبر الضوء'],
            'activity_package_path' => 'interactive-activities/demo/v1/index.html',
            'entry_file' => 'index.html',
            'version' => 1,
        ]);

        $this->seed(CompleteDemoQuizzesSeeder::class);

        $this->assertGreaterThanOrEqual(6, Quiz::query()->count());

        $types = collect(QuestionType::assessmentCases())->map(fn (QuestionType $t) => $t->value)->sort()->values();

        Quiz::query()
            ->where('selection_config->demo_key', 'like', 'complete-%')
            ->orderBy('id')
            ->limit(6)
            ->each(function (Quiz $quiz) use ($types): void {
            $quizTypes = Question::query()
                ->where('quiz_id', $quiz->id)
                ->pluck('question_type')
                ->map(fn ($t) => $t instanceof QuestionType ? $t->value : (string) $t)
                ->unique()
                ->sort()
                ->values();

            $this->assertEquals($types, $quizTypes, 'Quiz #'.$quiz->id.' is missing question types');
        });

        $this->assertGreaterThan(0, InteractiveActivity::query()->whereNotNull('topic_id')->count());
    }
}
