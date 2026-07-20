<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Competition\Application\Services\SubmissionReviewService;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use App\Modules\Gamification\Infrastructure\Persistence\Models\UserAchievement;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CompetitionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_user_can_register_submit_and_get_photo_approved(): void
    {
        Storage::fake('public');
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->completeMicroscopeCourse($user);

        $this->getJson('/api/v1/competitions/microscope-100-challenge/eligibility')
            ->assertOk()
            ->assertJsonPath('data.eligible', true);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/register')
            ->assertCreated();

        $photo = UploadedFile::fake()->image('microscope.jpg', 800, 600);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo_index' => 1,
            'description' => 'Onion cell sample',
            'photo' => $photo,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->getJson('/api/v1/competitions/microscope-100-challenge/dashboard')
            ->assertOk()
            ->assertJsonPath('data.pending_count', 1);

        $submission = CompetitionSubmission::query()->firstOrFail();
        $admin = User::query()->where('email', 'admin@sciencestreetlab.com')->firstOrFail();

        app(SubmissionReviewService::class)->approve($admin, $submission);

        $this->getJson('/api/v1/competitions/microscope-100-challenge/dashboard')
            ->assertOk()
            ->assertJsonPath('data.approved_count', 1)
            ->assertJsonPath('data.pending_count', 0);

        $this->assertTrue(
            UserAchievement::query()
                ->where('user_id', $user->id)
                ->whereHas('achievement', fn ($q) => $q->where('slug', 'photo-pioneer'))
                ->exists()
        );
    }

    public function test_ineligible_user_cannot_register_before_course_completion(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/competitions/microscope-100-challenge/eligibility')
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason', 'course_not_completed');

        $this->postJson('/api/v1/competitions/microscope-100-challenge/register')
            ->assertForbidden();
    }

    public function test_duplicate_submission_slot_is_rejected(): void
    {
        Storage::fake('public');
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->completeMicroscopeCourse($user);
        $this->postJson('/api/v1/competitions/microscope-100-challenge/register')->assertCreated();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('sample-a.jpg', 800, 600),
        ])->assertCreated();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('sample-b.jpg', 800, 600),
        ])->assertStatus(422);
    }

    private function completeMicroscopeCourse(User $user): void
    {
        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();
        $topic = Topic::query()->where('slug', 'what-is-microscope')->firstOrFail();
        $quiz = Quiz::query()->where('quizable_id', $topic->lesson_id)->firstOrFail();
        $correctOption = QuestionOption::query()
            ->where('question_id', $quiz->questions()->first()->id)
            ->where('is_correct', true)
            ->firstOrFail();

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id]);
        $orderId = $this->postJson('/api/v1/checkout', [
            'billing_address' => [
                'first_name' => 'Student',
                'email' => $user->email,
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
            ],
            'shipping_address' => ['city' => 'Cairo', 'country' => 'EG'],
        ])->json('data.id');

        $paymentId = $this->postJson("/api/v1/checkout/{$orderId}/pay")->json('data.payment_id');
        $this->postJson("/api/v1/payments/mock/{$paymentId}/complete");

        $this->postJson("/api/v1/topics/{$topic->id}/progress", ['watch_progress_percent' => 95]);

        $attemptId = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->json('data.id');
        $this->postJson("/api/v1/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $quiz->questions()->first()->id, 'selected_option_ids' => [$correctOption->id]],
            ],
        ]);
    }
}
