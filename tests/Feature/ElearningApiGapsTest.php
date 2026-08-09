<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ElearningApiGapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_list_returns_enriched_payload_without_video_urls(): void
    {
        $this->seed();

        $response = $this->getJson('/api/v1/courses')->assertOk();

        $course = collect($response->json('data'))->firstWhere('slug', 'microscope-course');

        $this->assertNotNull($course);
        $this->assertArrayHasKey('short_description', $course);
        $this->assertArrayHasKey('long_description', $course);
        $this->assertArrayHasKey('access_type', $course);
        $this->assertArrayHasKey('price', $course);
        $this->assertArrayHasKey('lessons_count', $course);
        $this->assertArrayHasKey('estimated_time', $course);
        $this->assertSame('not_enrolled', $course['enrollment_status']);
        $this->assertArrayNotHasKey('video_url', $course);
    }

    public function test_course_detail_hides_protected_topic_video_urls(): void
    {
        $this->seed();

        $response = $this->getJson('/api/v1/courses/microscope-course')->assertOk();

        $this->assertSame('not_enrolled', $response->json('data.enrollment_status'));
        $this->assertIsArray($response->json('data.lessons'));
        $this->assertArrayNotHasKey('topics', $response->json('data.lessons.0') ?? []);
        $json = $response->getContent();
        $this->assertStringNotContainsString('video_url', $json);
        $this->assertStringNotContainsString('example.com/videos', $json);
    }

    public function test_free_course_enrollment_and_progress_endpoints(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/courses/intro-to-science/enroll')
            ->assertCreated()
            ->assertJsonPath('data.already_enrolled', false);

        $this->postJson('/api/v1/courses/intro-to-science/enroll')
            ->assertOk()
            ->assertJsonPath('data.already_enrolled', true);

        $this->getJson('/api/v1/courses/intro-to-science/enrollment')
            ->assertOk()
            ->assertJsonPath('data.course.slug', 'intro-to-science');

        $this->getJson('/api/v1/courses/intro-to-science/progress')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'course_id',
                    'progress',
                    'completed_lessons',
                    'total_lessons',
                    'last_lesson_id',
                    'continue_from',
                ],
            ]);
    }

    public function test_paid_course_enroll_requires_payment(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/courses/microscope-course/enroll')
            ->assertStatus(402)
            ->assertJsonPath('code', 'PAYMENT_REQUIRED');
    }

    public function test_lesson_show_requires_enrollment_and_tracks_last_accessed(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $course = Course::query()->where('slug', 'intro-to-science')->firstOrFail();
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'free-intro',
            'lesson_type' => 'theory',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['ar' => 'مقدمة', 'en' => 'Intro'],
            'content' => ['ar' => 'محتوى', 'en' => 'Content'],
        ]);
        $topic = Topic::query()->create([
            'lesson_id' => $lesson->id,
            'slug' => 'free-topic',
            'sort_order' => 1,
            'content_type' => 'video',
            'video_url' => 'https://example.com/free.mp4',
            'is_published' => true,
            'title' => ['ar' => 'فيديو', 'en' => 'Video'],
        ]);

        $this->getJson("/api/v1/lessons/{$lesson->id}")->assertForbidden();

        $this->postJson('/api/v1/courses/intro-to-science/enroll')->assertCreated();

        $this->getJson("/api/v1/lessons/{$lesson->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $lesson->id)
            ->assertJsonPath('data.topics.0.has_video', true)
            ->assertJsonMissingPath('data.topics.0.video_url');

        $this->getJson('/api/v1/courses/intro-to-science/progress')
            ->assertOk()
            ->assertJsonPath('data.last_lesson_id', $lesson->id);

        $this->postJson("/api/v1/topics/{$topic->id}/progress", [
            'watched_seconds' => 90,
            'duration' => 120,
        ])->assertOk()
            ->assertJsonPath('data.watch_progress_percent', 75)
            ->assertJsonPath('data.watched_seconds', 90)
            ->assertJsonPath('data.last_position_seconds', 90);

        $this->getJson("/api/v1/topics/{$topic->id}/video-url")
            ->assertOk()
            ->assertJsonPath('data.last_position_seconds', 90);
    }

    public function test_quiz_submit_returns_correct_and_wrong_counts(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = \App\Modules\Catalog\Infrastructure\Persistence\Models\Product::query()
            ->where('sku', 'SS-MICRO-001')
            ->firstOrFail();
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
            'watched_seconds' => 600,
            'duration_seconds' => 600,
            'completed' => true,
        ])->assertOk();

        $attemptId = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->json('data.id');

        $this->postJson("/api/v1/attempts/{$attemptId}/submit", [
            'answers' => [
                [
                    'question_id' => $quiz->questions()->first()->id,
                    'selected_option_ids' => [$correctOption->id],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.correct_answers', 1)
            ->assertJsonPath('data.wrong_answers', 0)
            ->assertJsonPath('data.total_questions', 1)
            ->assertJsonPath('data.status', 'passed');
    }

    public function test_unpublished_course_returns_404(): void
    {
        $this->seed();

        Course::query()->where('slug', 'microscope-course')->update(['is_published' => false]);

        $this->getJson('/api/v1/courses/microscope-course')->assertNotFound();
    }
}
