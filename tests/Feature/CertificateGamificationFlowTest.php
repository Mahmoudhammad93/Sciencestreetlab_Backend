<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use App\Modules\Gamification\Infrastructure\Persistence\Models\UserAchievement;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CertificateGamificationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_completion_issues_certificate_and_unlocks_achievements(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $topic = Topic::query()->where('slug', 'what-is-microscope')->firstOrFail();
        $quiz = Quiz::query()->where('quizable_id', $topic->lesson_id)->firstOrFail();
        $correctOption = QuestionOption::query()
            ->where('question_id', $quiz->questions()->first()->id)
            ->where('is_correct', true)
            ->firstOrFail();

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id])->assertCreated();
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
        $this->postJson("/api/v1/payments/mock/{$paymentId}/complete")->assertOk();

        $this->postJson("/api/v1/topics/{$topic->id}/progress", [
            'watch_progress_percent' => 95,
        ])->assertOk();

        $attemptId = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->json('data.id');

        $this->postJson("/api/v1/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $quiz->questions()->first()->id, 'selected_option_ids' => [$correctOption->id]],
            ],
        ])->assertOk()->assertJsonPath('data.passed', true);

        $certificate = Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        $this->assertNotNull($certificate);
        $this->assertNotNull($certificate->pdf_path);
        $this->assertNotEmpty($certificate->verification_code);

        $this->getJson("/api/v1/certificates/verify/{$certificate->verification_code}")
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('student_name', $user->name);

        $this->getJson('/api/v1/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $achievementSlugs = UserAchievement::query()
            ->where('user_id', $user->id)
            ->with('achievement')
            ->get()
            ->pluck('achievement.slug')
            ->all();

        $this->assertContains('quiz-master', $achievementSlugs);
        $this->assertContains('course-graduate', $achievementSlugs);
        $this->assertContains('microscope-certified', $achievementSlugs);

        $this->getJson('/api/v1/me/achievements')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/v1/me/points')
            ->assertOk()
            ->assertJsonPath('data.total_points', 175);
    }
}
