<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class LearningFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrolled_user_can_complete_topic_pass_quiz_and_finish_course(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $topic = Topic::query()->where('slug', 'what-is-microscope')->firstOrFail();
        $quiz = Quiz::query()->where('quizable_id', $topic->lesson_id)->firstOrFail();
        $correctOption = QuestionOption::query()->where('question_id', $quiz->questions()->first()->id)->where('is_correct', true)->firstOrFail();

        // Enroll via checkout + mock payment
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

        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);

        // Curriculum shows unlocked first lesson
        $curriculum = $this->getJson('/api/v1/courses/microscope-course/curriculum')->assertOk();
        $this->assertFalse($curriculum->json('data.lessons.0.is_locked'));

        // Complete topic video
        $this->postJson("/api/v1/topics/{$topic->id}/progress", [
            'watch_progress_percent' => 95,
        ])->assertOk();

        // Start and pass quiz
        $attemptId = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->json('data.id');

        $this->postJson("/api/v1/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $quiz->questions()->first()->id, 'selected_option_ids' => [$correctOption->id]],
            ],
        ])->assertOk()->assertJsonPath('data.passed', true);

        $enrollment = Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->first();
        $this->assertSame('completed', $enrollment->fresh()->status->value);
        $this->assertGreaterThanOrEqual(100, (float) $enrollment->fresh()->progress_percent);
    }

    public function test_locked_topic_returns_forbidden(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();

        // Add second lesson/topic without completing first
        $lesson2 = \App\Modules\Learning\Infrastructure\Persistence\Models\Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'advanced',
            'lesson_type' => 'theory',
            'sort_order' => 2,
            'title' => ['ar' => 'متقدم', 'en' => 'Advanced'],
        ]);

        $lockedTopic = Topic::query()->create([
            'lesson_id' => $lesson2->id,
            'slug' => 'advanced-topic',
            'sort_order' => 1,
            'content_type' => 'video',
            'title' => ['ar' => 'درس متقدم', 'en' => 'Advanced lesson'],
        ]);

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();
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

        $this->postJson("/api/v1/topics/{$lockedTopic->id}/progress", [
            'watch_progress_percent' => 50,
        ])->assertForbidden();
    }
}
