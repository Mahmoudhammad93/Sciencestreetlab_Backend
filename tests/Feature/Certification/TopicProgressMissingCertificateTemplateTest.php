<?php

declare(strict_types=1);

namespace Tests\Feature\Certification;

use App\Models\User;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TopicProgressMissingCertificateTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_topic_progress_succeeds_when_no_certificate_template_exists(): void
    {
        Log::spy();

        [$user, $topic, $enrollment] = $this->singleTopicCourse();
        Sanctum::actingAs($user);

        $this->assertSame(0, CertificateTemplate::query()->count());

        $response = $this->postJson("/api/v1/topics/{$topic->id}/progress", [
            'watch_progress_percent' => 95,
            'completed' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.topic_id', $topic->id)
            ->assertJsonPath('data.completed', true);

        $body = $response->getContent();
        $this->assertStringNotContainsString('CertificateTemplate', $body);
        $this->assertStringNotContainsString('No query results for model', $body);

        $this->assertDatabaseHas('topic_completions', [
            'enrollment_id' => $enrollment->id,
            'topic_id' => $topic->id,
        ]);

        $enrollment->refresh();
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertEquals(100.0, (float) $enrollment->progress_percent);
        $this->assertSame(0, Certificate::query()->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'certificate.template_missing'))
            ->atLeast()->once();
    }

    public function test_duplicate_progress_request_is_idempotent_without_template(): void
    {
        [$user, $topic] = $this->singleTopicCourse();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/topics/{$topic->id}/progress", [
            'watch_progress_percent' => 95,
        ])->assertOk();

        $this->postJson("/api/v1/topics/{$topic->id}/progress", [
            'watch_progress_percent' => 100,
            'completed' => true,
        ])->assertOk()->assertJsonPath('data.completed', true);

        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_course_completion_with_valid_template_still_issues_certificate(): void
    {
        $template = CertificateTemplate::query()->create([
            'slug' => 'default-cert',
            'name' => ['ar' => 'شهادة', 'en' => 'Certificate'],
            'is_active' => true,
            'layout_config' => [],
        ]);

        [$user, $topic, $enrollment, $course] = $this->singleTopicCourse();
        $course->update(['certificate_template_id' => $template->id]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/topics/{$topic->id}/progress", [
            'watch_progress_percent' => 100,
            'completed' => true,
        ])->assertOk();

        $certificate = Certificate::query()
            ->where('enrollment_id', $enrollment->id)
            ->first();

        $this->assertNotNull($certificate);
        $this->assertSame($template->id, $certificate->template_id);
    }

    public function test_inactive_linked_template_falls_back_without_failing_progress(): void
    {
        $inactive = CertificateTemplate::query()->create([
            'slug' => 'inactive-cert',
            'name' => ['ar' => 'غير نشط', 'en' => 'Inactive'],
            'is_active' => false,
            'layout_config' => [],
        ]);

        [$user, $topic, $enrollment, $course] = $this->singleTopicCourse();
        $course->update(['certificate_template_id' => $inactive->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/topics/{$topic->id}/progress", [
            'watch_progress_percent' => 100,
            'completed' => true,
        ])->assertOk()->assertJsonPath('data.completed', true);

        $enrollment->refresh();
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_bearer_student_identity_not_overridden_by_admin_web_session(): void
    {
        [$student, $topic, $enrollment] = $this->singleTopicCourse();
        $admin = User::factory()->create(['email' => 'admin-cert@example.com']);

        $token = $student->createToken('api')->plainTextToken;

        $this->actingAs($admin, 'web');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/topics/{$topic->id}/progress", [
                'watch_progress_percent' => 95,
            ])
            ->assertOk()
            ->assertJsonPath('data.topic_id', $topic->id);

        $this->assertDatabaseHas('topic_completions', [
            'enrollment_id' => $enrollment->id,
            'topic_id' => $topic->id,
        ]);
        $this->assertDatabaseMissing('topic_completions', [
            'enrollment_id' => Enrollment::query()->where('user_id', $admin->id)->value('id') ?? 0,
        ]);
    }

    /**
     * @return array{0: User, 1: Topic, 2: Enrollment, 3: Course}
     */
    private function singleTopicCourse(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'cert-gap-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'certificate_template_id' => null,
            'title' => ['en' => 'Cert Gap', 'ar' => 'فجوة شهادة'],
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'lesson-1',
            'lesson_type' => 'theory',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'Lesson', 'ar' => 'درس'],
        ]);
        $topic = Topic::query()->create([
            'lesson_id' => $lesson->id,
            'slug' => 'topic-1',
            'sort_order' => 1,
            'content_type' => 'video',
            'is_published' => true,
            'title' => ['en' => 'Topic', 'ar' => 'موضوع'],
        ]);
        $enrollment = Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 0,
            'enrolled_at' => now(),
            'started_at' => now(),
            'grant_certificate' => true,
        ]);

        return [$user, $topic, $enrollment, $course];
    }
}
