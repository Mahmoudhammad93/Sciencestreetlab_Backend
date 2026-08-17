<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionBankStatus;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionTag;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

final class InteractiveActivitySystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_lesson_lists_published_activities_and_launch_requires_auth(): void
    {
        [$user, $lesson, $activity] = $this->seedActivityFixture();

        $this->getJson("/api/v1/lessons/{$lesson->id}/interactive-activities")
            ->assertUnauthorized();

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/lessons/{$lesson->id}/interactive-activities")
            ->assertOk()
            ->assertJsonPath('data.0.id', $activity->id)
            ->assertJsonMissingPath('data.0.activity_package_path');

        $launch = $this->getJson("/api/v1/interactive-activities/{$activity->id}/launch")
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'sandbox', 'protocol', 'expires_at']]);

        $this->assertStringContainsString('/interactive-activities/', $launch->json('data.url'));
        $this->assertStringContainsString('signature=', $launch->json('data.url'));
    }

    public function test_activity_attempt_resume_result_and_unverified_score(): void
    {
        [$user, $lesson, $activity] = $this->seedActivityFixture();
        Sanctum::actingAs($user);

        $start = $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['attempt_id', 'launch' => ['url']]]);

        $attemptId = $start->json('data.attempt_id');

        // Resume same in-progress attempt
        $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [])
            ->assertCreated()
            ->assertJsonPath('data.attempt_id', $attemptId);

        $this->postJson("/api/v1/interactive-activity-attempts/{$attemptId}/result", [
            'completed' => true,
            'result' => [
                'score' => 999,
                'max_score' => 100,
                'percentage' => 999,
                'time_spent_seconds' => 42,
                'answers' => ['q1' => 'wrong'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.client_score', 999)
            ->assertJsonPath('data.score_verified', false);

        $this->getJson("/api/v1/interactive-activity-attempts/{$attemptId}/result")
            ->assertOk()
            ->assertJsonPath('data.attempt_id', $attemptId);
    }

    public function test_activity_verifies_score_when_expected_answers_configured(): void
    {
        [$user, $lesson, $activity] = $this->seedActivityFixture([
            'expected' => ['q1' => 'Nucleus', 'q2' => 'Mitochondria'],
        ]);
        Sanctum::actingAs($user);

        $attemptId = $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts")
            ->json('data.attempt_id');

        $this->postJson("/api/v1/interactive-activity-attempts/{$attemptId}/result", [
            'completed' => true,
            'result' => [
                'score' => 100,
                'max_score' => 10,
                'answers' => ['q1' => 'Nucleus', 'q2' => 'Mitochondria'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.score_verified', true)
            ->assertJsonPath('data.verified_score', 10);
    }

    public function test_idor_and_unpublished_blocked(): void
    {
        [$user, $lesson, $activity] = $this->seedActivityFixture();
        $other = User::factory()->create();
        Sanctum::actingAs($user);
        $attemptId = $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts")
            ->json('data.attempt_id');

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/interactive-activity-attempts/{$attemptId}")
            ->assertForbidden();

        $activity->update(['status' => InteractiveActivityStatus::Draft]);
        Sanctum::actingAs($user);
        $this->getJson("/api/v1/interactive-activities/{$activity->id}/launch")
            ->assertForbidden();
    }

    public function test_activity_progress_endpoint(): void
    {
        [$user, $lesson, $activity] = $this->seedActivityFixture();
        Sanctum::actingAs($user);

        $attemptId = $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts")
            ->json('data.attempt_id');

        $this->postJson("/api/v1/interactive-activity-attempts/{$attemptId}/progress", [
            'completed_challenges' => 2,
            'total_challenges' => 5,
            'percentage' => 40,
        ])->assertOk()
            ->assertJsonPath('data.progress.completed_challenges', 2)
            ->assertJsonPath('data.progress.total_challenges', 5)
            ->assertJsonPath('data.progress.percentage', 40);
    }

    public function test_html_file_upload_stored_as_index_html(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        [$user, $lesson, $activity] = $this->seedActivityFixture(withPackage: false);
        $htmlPath = storage_path('app/demo-activity.html');
        file_put_contents($htmlPath, '<html><body><h1>Light Lab</h1></body></html>');
        $upload = new UploadedFile($htmlPath, 'demo-activity.html', 'text/html', null, true);

        app(InteractiveActivityPackageService::class)->storeHtmlFile($activity, $upload);

        $activity->refresh();
        $this->assertSame('index.html', $activity->entry_file);
        $this->assertStringContainsString('/v1/index.html', (string) $activity->activity_package_path);
        Storage::disk('public')->assertExists($activity->activity_package_path);
    }

    public function test_zip_path_traversal_rejected(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        [$user, $lesson, $activity] = $this->seedActivityFixture(withPackage: false);
        $zipPath = storage_path('app/evil.zip');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('../evil.php', '<?php');
        $zip->close();

        $upload = new UploadedFile($zipPath, 'evil.zip', 'application/zip', null, true);

        $this->expectException(\DomainException::class);
        app(InteractiveActivityPackageService::class)->storeZipPackage($activity, $upload);
    }

    public function test_tag_filter_on_question_bank(): void
    {
        [$user, $lesson, $bank] = $this->seedBankWithTaggedQuestion();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/question-banks/{$bank->id}/questions?tag=biology")
            ->assertOk()
            ->assertJsonPath('data.0.tags.0.slug', 'biology');

        $payload = $this->getJson("/api/v1/question-banks/{$bank->id}/questions?tag=missing")
            ->assertOk()
            ->json('data');
        $this->assertSame([], $payload);
    }

    public function test_generated_quiz_freezes_and_student_hides_is_correct(): void
    {
        [$user, $lesson, $bank] = $this->seedBankWithTaggedQuestion();
        for ($i = 0; $i < 8; $i++) {
            $this->makeChoice($bank, $i + 2, QuestionDifficulty::Easy);
        }

        $quiz = Quiz::query()->create([
            'quizable_type' => Lesson::class,
            'quizable_id' => $lesson->id,
            'passing_score' => 50,
            'selection_mode' => QuizSelectionMode::Generated,
            'selection_config' => ['total_questions' => 5, 'difficulty' => ['easy' => 5]],
            'title' => ['en' => 'Gen', 'ar' => 'مولد'],
        ]);
        $quiz->questionBanks()->attach($bank->id);

        Sanctum::actingAs($user);
        $first = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertCreated();
        $ids = collect($first->json('data.questions'))->pluck('id')->sort()->values()->all();
        $this->assertCount(5, $ids);

        $option = $first->json('data.questions.0.options.0');
        $this->assertArrayNotHasKey('is_correct', $option);

        $resume = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertCreated();
        $ids2 = collect($resume->json('data.questions'))->pluck('id')->sort()->values()->all();
        $this->assertSame($ids, $ids2);
    }

    /**
     * @return array{0:User,1:Lesson,2:InteractiveActivity}
     */
    private function seedActivityFixture(array $config = [], bool $withPackage = true): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'act-course-'.uniqid(),
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'C', 'ar' => 'ك'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'act-lesson',
            'lesson_type' => LessonType::Theory,
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'L', 'ar' => 'د'],
        ]);
        Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
            'started_at' => now(),
        ]);

        $activity = InteractiveActivity::query()->create([
            'lesson_id' => $lesson->id,
            'status' => InteractiveActivityStatus::Published,
            'activity_type' => InteractiveActivityType::Custom->value,
            'difficulty' => QuestionDifficulty::Medium,
            'points' => 10,
            'version' => 1,
            'entry_file' => 'index.html',
            'activity_config' => $config,
            'title' => ['en' => 'Game', 'ar' => 'لعبة'],
            'description' => ['en' => 'Demo', 'ar' => 'تجربة'],
        ]);

        if ($withPackage) {
            Storage::disk('public')->put(
                "interactive-activities/{$activity->uuid}/v1/index.html",
                '<html><body>ok</body></html>'
            );
            $activity->update([
                'activity_package_path' => "interactive-activities/{$activity->uuid}/v1/index.html",
            ]);
        }

        return [$user, $lesson, $activity];
    }

    /**
     * @return array{0:User,1:Lesson,2:QuestionBank}
     */
    private function seedBankWithTaggedQuestion(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'bank-course-'.uniqid(),
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'C', 'ar' => 'ك'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'bank-lesson',
            'lesson_type' => LessonType::Theory,
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'L', 'ar' => 'د'],
        ]);
        Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
            'started_at' => now(),
        ]);
        $bank = QuestionBank::query()->create([
            'lesson_id' => $lesson->id,
            'status' => QuestionBankStatus::Active,
            'title' => ['en' => 'Bank', 'ar' => 'بنك'],
        ]);
        $q = $this->makeChoice($bank, 1, QuestionDifficulty::Easy);
        $tag = QuestionTag::query()->create([
            'slug' => 'biology',
            'name' => ['en' => 'Biology', 'ar' => 'أحياء'],
        ]);
        $q->tags()->attach($tag->id);

        return [$user, $lesson, $bank];
    }

    private function makeChoice(QuestionBank $bank, int $sort, QuestionDifficulty $diff): Question
    {
        $q = Question::query()->create([
            'question_bank_id' => $bank->id,
            'question_type' => QuestionType::SingleChoice,
            'difficulty' => $diff,
            'status' => QuestionStatus::Published,
            'points' => 1,
            'sort_order' => $sort,
            'body' => ['en' => "Q{$sort}", 'ar' => "س{$sort}"],
        ]);
        QuestionOption::query()->create([
            'question_id' => $q->id,
            'is_correct' => true,
            'sort_order' => 1,
            'label' => ['en' => 'A', 'ar' => 'أ'],
        ]);
        QuestionOption::query()->create([
            'question_id' => $q->id,
            'is_correct' => false,
            'sort_order' => 2,
            'label' => ['en' => 'B', 'ar' => 'ب'],
        ]);

        return $q;
    }
}
