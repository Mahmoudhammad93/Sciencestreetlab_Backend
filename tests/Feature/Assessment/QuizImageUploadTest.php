<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Application\Services\ManualQuizReviewService;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Support\ImageUploadQuestionConfig;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class QuizImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_upload_question_can_be_created_with_config(): void
    {
        [$quiz] = $this->seedQuizContext();

        $question = Question::query()->create([
            'quiz_id' => $quiz->id,
            'question_type' => QuestionType::ImageUpload,
            'points' => 5,
            'sort_order' => 1,
            'body' => ['ar' => 'ارفع صورة', 'en' => 'Upload an image'],
            'interactive_config' => ImageUploadQuestionConfig::mergeIntoInteractiveConfig([], 1, 5, true),
        ]);

        $config = ImageUploadQuestionConfig::fromQuestion($question->fresh());
        $this->assertSame(1, $config->maxImages);
        $this->assertSame(5.0, $config->maxSizeMb);
        $this->assertTrue($config->required);
        $this->assertContains('image/jpeg', $config->allowedMimes);
    }

    public function test_existing_question_types_still_valid(): void
    {
        $this->assertSame('long_answer', QuestionType::LongAnswer->value);
        $this->assertSame('single_choice', QuestionType::SingleChoice->value);
        $this->assertSame('image_upload', QuestionType::ImageUpload->value);
        $this->assertTrue(QuestionType::ImageUpload->isAssessmentQuestion());
    }

    public function test_valid_jpeg_upload_and_manual_grade_flow(): void
    {
        Storage::fake('local');
        [$quiz, $user, $enrollment, $question] = $this->seedQuizContext(withImageQuestion: true);

        Sanctum::actingAs($user);
        $attempt = app(QuizAttemptService::class)->start($user, $quiz, $enrollment);

        $file = UploadedFile::fake()->image('sample.jpg', 400, 300);

        $upload = $this->post(
            "/api/v1/quiz-attempts/{$attempt->id}/questions/{$question->id}/image",
            ['image' => $file, 'replace' => true],
            ['Accept' => 'application/json']
        )->assertCreated()
            ->assertJsonPath('data.saved', true);

        $mediaId = (int) $upload->json('data.media_id');
        $this->assertGreaterThan(0, $mediaId);
        $this->assertStringContainsString('/api/v1/quiz-attempts/', (string) $upload->json('data.images.0.url'));
        $this->assertStringNotContainsString(storage_path(), (string) json_encode($upload->json()));

        $graded = $this->postJson("/api/v1/quiz-attempts/{$attempt->id}/submit", [
            'answers' => [['question_id' => $question->id]],
        ])->assertOk();

        $this->assertSame('pending_review', $graded->json('data.status') ?? $graded->json('status'));

        $answer = QuizAttemptAnswer::query()
            ->where('quiz_attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->firstOrFail();

        $this->assertTrue((bool) $answer->needs_manual_review);
        $this->assertSame(0.0, (float) $answer->points_awarded);

        $final = app(ManualQuizReviewService::class)->gradeAnswer($answer, 5, true);
        $this->assertSame(AttemptStatus::Graded, $final->status);
        $this->assertSame(100.0, (float) $final->percentage);
    }

    public function test_png_and_webp_accepted_php_rejected(): void
    {
        Storage::fake('local');
        [$quiz, $user, $enrollment, $question] = $this->seedQuizContext(withImageQuestion: true);
        Sanctum::actingAs($user);
        $attempt = app(QuizAttemptService::class)->start($user, $quiz, $enrollment);

        $this->post(
            "/api/v1/quiz-attempts/{$attempt->id}/questions/{$question->id}/image",
            ['image' => UploadedFile::fake()->image('a.png'), 'replace' => true],
            ['Accept' => 'application/json']
        )->assertCreated();

        $this->post(
            "/api/v1/quiz-attempts/{$attempt->id}/questions/{$question->id}/image",
            ['image' => UploadedFile::fake()->image('b.webp'), 'replace' => true],
            ['Accept' => 'application/json']
        )->assertCreated();

        $this->post(
            "/api/v1/quiz-attempts/{$attempt->id}/questions/{$question->id}/image",
            ['image' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php'), 'replace' => true],
            ['Accept' => 'application/json']
        )->assertStatus(422);
    }

    public function test_oversized_image_rejected(): void
    {
        Storage::fake('local');
        [$quiz, $user, $enrollment, $question] = $this->seedQuizContext(withImageQuestion: true);
        Sanctum::actingAs($user);
        $attempt = app(QuizAttemptService::class)->start($user, $quiz, $enrollment);

        $big = UploadedFile::fake()->create('big.jpg', 6 * 1024, 'image/jpeg');

        $this->post(
            "/api/v1/quiz-attempts/{$attempt->id}/questions/{$question->id}/image",
            ['image' => $big, 'replace' => true],
            ['Accept' => 'application/json']
        )->assertStatus(422);
    }

    public function test_required_image_missing_on_submit_rejected(): void
    {
        [$quiz, $user, $enrollment, $question] = $this->seedQuizContext(withImageQuestion: true);
        Sanctum::actingAs($user);
        $attempt = app(QuizAttemptService::class)->start($user, $quiz, $enrollment);

        $this->postJson("/api/v1/quiz-attempts/{$attempt->id}/submit", [
            'answers' => [['question_id' => $question->id]],
        ])->assertStatus(422);
    }

    public function test_student_cannot_upload_to_another_attempt(): void
    {
        Storage::fake('local');
        [$quiz, $userA, $enrollmentA, $question] = $this->seedQuizContext(withImageQuestion: true);
        $userB = User::factory()->create();
        Enrollment::query()->create([
            'user_id' => $userB->id,
            'course_id' => $enrollmentA->course_id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);

        $attemptA = app(QuizAttemptService::class)->start($userA, $quiz, $enrollmentA);

        Sanctum::actingAs($userB);
        $this->post(
            "/api/v1/quiz-attempts/{$attemptA->id}/questions/{$question->id}/image",
            ['image' => UploadedFile::fake()->image('x.jpg'), 'replace' => true],
            ['Accept' => 'application/json']
        )->assertStatus(403);
    }

    public function test_grade_above_max_rejected(): void
    {
        Storage::fake('local');
        [$quiz, $user, $enrollment, $question] = $this->seedQuizContext(withImageQuestion: true);
        Sanctum::actingAs($user);

        $service = app(QuizAttemptService::class);
        $attempt1 = $service->start($user, $quiz, $enrollment);

        $this->post(
            "/api/v1/quiz-attempts/{$attempt1->id}/questions/{$question->id}/image",
            ['image' => UploadedFile::fake()->image('one.jpg'), 'replace' => true],
            ['Accept' => 'application/json']
        )->assertCreated();

        $submitted = $service->submit($attempt1, [['question_id' => $question->id]]);
        $answer1 = $submitted->answers()->firstOrFail();

        $this->expectException(\DomainException::class);
        app(ManualQuizReviewService::class)->gradeAnswer($answer1, 99, true);
    }

    public function test_retake_does_not_reuse_previous_attempt_media(): void
    {
        Storage::fake('local');
        [$quiz, $user, $enrollment, $question] = $this->seedQuizContext(withImageQuestion: true);
        Sanctum::actingAs($user);

        $service = app(QuizAttemptService::class);
        $attempt1 = $service->start($user, $quiz, $enrollment);
        $this->post(
            "/api/v1/quiz-attempts/{$attempt1->id}/questions/{$question->id}/image",
            ['image' => UploadedFile::fake()->image('one.jpg'), 'replace' => true],
            ['Accept' => 'application/json']
        )->assertCreated();
        $submitted = $service->submit($attempt1, [['question_id' => $question->id]]);
        $answer1 = $submitted->answers()->firstOrFail();
        $media1Id = $answer1->getMedia(QuizAttemptAnswer::MEDIA_COLLECTION)->first()?->id;
        app(ManualQuizReviewService::class)->gradeAnswer($answer1, 5, true);

        $attempt2 = $service->start($user, $quiz, $enrollment);
        $this->assertNotSame($attempt1->id, $attempt2->id);
        $answer2 = QuizAttemptAnswer::query()
            ->where('quiz_attempt_id', $attempt2->id)
            ->where('question_id', $question->id)
            ->first();
        $this->assertNull($answer2);
        $this->assertNotNull($media1Id);
        $this->assertTrue(
            QuizAttemptAnswer::query()->find($answer1->id)?->getMedia(QuizAttemptAnswer::MEDIA_COLLECTION)->isNotEmpty()
        );
    }

    public function test_student_question_payload_exposes_upload_config_only(): void
    {
        [$quiz, $user, $enrollment, $question] = $this->seedQuizContext(withImageQuestion: true);
        Sanctum::actingAs($user);
        $attempt = app(QuizAttemptService::class)->start($user, $quiz, $enrollment);

        $response = $this->getJson("/api/v1/quiz-attempts/{$attempt->id}")->assertOk();
        $payload = json_encode($response->json());
        $this->assertStringContainsString('image_upload', (string) $payload);
        $this->assertStringContainsString('max_images', (string) $payload);
        $this->assertStringNotContainsString(storage_path('app'), (string) $payload);
        $this->assertStringNotContainsString('answer_key', (string) $payload);
    }

    public function test_type_label_includes_image_upload(): void
    {
        $this->assertSame(
            'رفع صورة',
            __('admin.questions.types.image_upload', [], 'ar')
        );
        $this->assertSame(
            'Image upload',
            __('admin.questions.types.image_upload', [], 'en')
        );
    }

    /**
     * @return array{0: Quiz, 1: User, 2: Enrollment, 3?: Question}
     */
    private function seedQuizContext(bool $withImageQuestion = false): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'img-course-'.uniqid(),
            'title' => ['en' => 'Course', 'ar' => 'دورة'],
            'is_published' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'img-lesson-'.uniqid(),
            'title' => ['en' => 'Lesson', 'ar' => 'درس'],
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
            'title' => ['en' => 'Quiz', 'ar' => 'اختبار'],
            'passing_score' => 50,
            'is_required' => true,
            'max_attempts' => 5,
        ]);

        if (! $withImageQuestion) {
            return [$quiz, $user, $enrollment];
        }

        $question = Question::query()->create([
            'quiz_id' => $quiz->id,
            'question_type' => QuestionType::ImageUpload,
            'points' => 5,
            'sort_order' => 1,
            'status' => 'published',
            'body' => ['ar' => 'ارفع صورة العينة', 'en' => 'Upload sample'],
            'interactive_config' => ImageUploadQuestionConfig::mergeIntoInteractiveConfig([], 1, 5, true),
        ]);

        return [$quiz, $user, $enrollment, $question];
    }
}
