<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Application\Services\ManualQuizReviewService;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Http\Resources\StudentQuestionResource;
use App\Modules\Assessment\Infrastructure\Grading\DragDropGrader;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class LongAnswerImageAndDragDropTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_answer_without_image_still_goes_pending_review(): void
    {
        [$user, $quiz, $enrollment, $question] = $this->quizWithLongAnswer();

        $attempt = app(QuizAttemptService::class)->start($user, $quiz, $enrollment);
        $graded = app(QuizAttemptService::class)->submit($attempt, [
            ['question_id' => $question->id, 'text_answer' => 'Written answer'],
        ]);

        $this->assertSame(AttemptStatus::PendingReview, $graded->status);
        $payload = (new StudentQuestionResource($question))->toArray(request());
        $this->assertNull($payload['image_url']);
        $this->assertSame('long_answer', $payload['question_type']);
    }

    public function test_long_answer_with_image_exposes_public_url_and_manual_grading(): void
    {
        Storage::fake('public');
        [$user, $quiz, $enrollment, $question] = $this->quizWithLongAnswer();

        $question->addMedia(UploadedFile::fake()->image('prompt.jpg', 200, 150))
            ->toMediaCollection('question_image');

        $payload = (new StudentQuestionResource($question->fresh()))->toArray(request());
        $this->assertNotEmpty($payload['image_url']);
        $this->assertStringNotContainsString('/var/', (string) $payload['image_url']);

        $attempt = app(QuizAttemptService::class)->start($user, $quiz, $enrollment);
        $graded = app(QuizAttemptService::class)->submit($attempt, [
            ['question_id' => $question->id, 'answer' => ['text' => 'Detailed description']],
        ]);
        $this->assertSame(AttemptStatus::PendingReview, $graded->status);

        $final = app(ManualQuizReviewService::class)->gradeAnswer(
            $graded->answers()->firstOrFail(),
            10,
            true,
        );
        $this->assertSame(AttemptStatus::Graded, $final->status);
        $this->assertSame(100.0, (float) $final->percentage);
    }

    public function test_drag_drop_student_payload_hides_answer_key(): void
    {
        $question = $this->dragDropQuestion();

        $payload = (new StudentQuestionResource($question))->toArray(request());

        $this->assertSame('drag_drop', $payload['question_type']);
        $this->assertCount(2, $payload['items']);
        $this->assertCount(2, $payload['drop_zones']);
        $this->assertArrayNotHasKey('answer_key', $payload);
        $this->assertArrayNotHasKey('correct_mappings', $payload);
        $json = json_encode($payload);
        $this->assertStringNotContainsString('correct_mappings', (string) $json);
        $this->assertStringNotContainsString('"item_1":"zone_a"', (string) $json);
        $this->assertStringNotContainsString('item_1\":\"zone_a', (string) $json);
    }

    public function test_drag_drop_grader_correct_incorrect_partial_and_invalid(): void
    {
        $question = $this->dragDropQuestion();
        $grader = new DragDropGrader;

        $this->assertTrue($grader->grade($question, $this->answer(['item_1' => 'zone_a', 'item_2' => 'zone_b'])));
        $this->assertFalse($grader->grade($question, $this->answer(['item_1' => 'zone_b', 'item_2' => 'zone_a'])));
        $this->assertFalse($grader->grade($question, $this->answer(['item_1' => 'zone_a']))); // partial
        $this->assertFalse($grader->grade($question, $this->answer(['item_1' => 'zone_a', 'item_2' => 'zone_b', 'item_x' => 'zone_a'])));
        $this->assertFalse($grader->grade($question, $this->answer(['item_1' => 'zone_a', 'item_2' => 'nope'])));
        $this->assertFalse($grader->grade($question, $this->answer(['item_1' => 'zone_a', 'item_1' => 'zone_b']))); // PHP array can't duplicate keys — simulate via overwrite already false path
        $this->assertFalse($grader->grade($question, $this->answer(['bad' => 'zone_a', 'item_2' => 'zone_b'])));
    }

    public function test_drag_drop_quiz_submit_awards_points(): void
    {
        [$user, $quiz, $enrollment] = $this->baseEnrollment();
        $question = Question::query()->create([
            'quiz_id' => $quiz->id,
            'question_type' => QuestionType::DragDrop,
            'points' => 5,
            'sort_order' => 1,
            'body' => ['en' => 'Match cells', 'ar' => 'طابق'],
            'answer_key' => [
                'items' => [
                    ['key' => 'item_1', 'label' => ['en' => 'Nucleus', 'ar' => 'نواة']],
                    ['key' => 'item_2', 'label' => ['en' => 'Membrane', 'ar' => 'غشاء']],
                ],
                'zones' => [
                    ['key' => 'zone_a', 'label' => ['en' => 'Control', 'ar' => 'تحكم']],
                    ['key' => 'zone_b', 'label' => ['en' => 'Boundary', 'ar' => 'حد']],
                ],
                'correct_mappings' => ['item_1' => 'zone_a', 'item_2' => 'zone_b'],
            ],
        ]);

        $attempt = app(QuizAttemptService::class)->start($user, $quiz, $enrollment);
        $graded = app(QuizAttemptService::class)->submit($attempt, [
            [
                'question_id' => $question->id,
                'answer' => ['mappings' => ['item_1' => 'zone_a', 'item_2' => 'zone_b']],
            ],
        ]);

        $this->assertSame(AttemptStatus::Graded, $graded->status);
        $this->assertSame(5.0, (float) $graded->score);
        $this->assertTrue((bool) $graded->answers()->firstOrFail()->is_correct);
    }

    /**
     * @return array{0: User, 1: Quiz, 2: Enrollment, 3: Question}
     */
    private function quizWithLongAnswer(): array
    {
        [$user, $quiz, $enrollment] = $this->baseEnrollment();
        $question = Question::query()->create([
            'quiz_id' => $quiz->id,
            'question_type' => QuestionType::LongAnswer,
            'points' => 10,
            'sort_order' => 1,
            'body' => ['en' => 'Describe the image', 'ar' => 'صف'],
            'answer_key' => ['manual' => true],
        ]);

        return [$user, $quiz, $enrollment, $question];
    }

    private function dragDropQuestion(): Question
    {
        [$user, $quiz] = array_slice($this->baseEnrollment(), 0, 2);

        return Question::query()->create([
            'quiz_id' => $quiz->id,
            'question_type' => QuestionType::DragDrop,
            'points' => 5,
            'sort_order' => 1,
            'body' => ['en' => 'Drag', 'ar' => 'اسحب'],
            'answer_key' => [
                'items' => [
                    ['key' => 'item_1', 'label' => ['en' => 'A', 'ar' => 'أ']],
                    ['key' => 'item_2', 'label' => ['en' => 'B', 'ar' => 'ب']],
                ],
                'zones' => [
                    ['key' => 'zone_a', 'label' => ['en' => 'Zone A', 'ar' => 'منطقة أ']],
                    ['key' => 'zone_b', 'label' => ['en' => 'Zone B', 'ar' => 'منطقة ب']],
                ],
                'correct_mappings' => ['item_1' => 'zone_a', 'item_2' => 'zone_b'],
            ],
        ]);
    }

    /**
     * @return array{0: User, 1: Quiz, 2: Enrollment}
     */
    private function baseEnrollment(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'assess-'.uniqid(),
            'title' => ['en' => 'C', 'ar' => 'ك'],
            'is_published' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'l-'.uniqid(),
            'title' => ['en' => 'L', 'ar' => 'د'],
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $enrollment = Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);
        $quiz = Quiz::query()->create([
            'quizable_type' => Lesson::class,
            'quizable_id' => $lesson->id,
            'title' => ['en' => 'Q', 'ar' => 'ا'],
            'passing_score' => 50,
            'is_required' => true,
        ]);

        return [$user, $quiz, $enrollment];
    }

    /**
     * @param  array<string, string>  $mappings
     */
    private function answer(array $mappings): QuizAttemptAnswer
    {
        $answer = new QuizAttemptAnswer;
        $answer->matching_answer = $mappings;

        return $answer;
    }
}
