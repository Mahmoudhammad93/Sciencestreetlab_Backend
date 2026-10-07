<?php

declare(strict_types=1);

namespace Tests\Feature\Certification;

use App\Models\User;
use App\Modules\Certification\Application\Services\CertificateIssuanceService;
use App\Modules\Certification\Application\Services\CertificatePdfGenerator;
use App\Modules\Certification\Application\Services\CertificateTemplateRenderer;
use App\Modules\Certification\Application\Support\CertificateLayoutPresets;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DynamicCertificateTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuance_snapshots_resolved_fields_and_is_idempotent(): void
    {
        $layout = CertificateLayoutPresets::layout(CertificateLayoutPresets::CYAN_LANDSCAPE_COMPLETION);
        $template = CertificateTemplate::query()->create([
            'slug' => 'cyan-completion',
            'name' => ['en' => 'Cyan', 'ar' => 'سماوي'],
            'is_active' => true,
            'layout_config' => $layout,
        ]);

        [$user, $enrollment, $course] = $this->completedEnrollment('Microscope Course', $template->id);

        $service = app(CertificateIssuanceService::class);
        $first = $service->issue($enrollment);
        $second = $service->issue($enrollment->fresh());

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, Certificate::query()->count());
        $this->assertMatchesRegularExpression('/^SSL-\d{4}-\d{6}$/', $first->certificate_number);

        $meta = $first->metadata;
        $this->assertSame($user->name, $meta['student_name']);
        $this->assertSame('Microscope Course', $meta['course_name']);
        $this->assertSame($first->certificate_number, $meta['certificate_number']);
        $this->assertArrayHasKey('layout_snapshot', $meta);
        $this->assertSame('landscape', $meta['orientation']);
        $this->assertSame(297.0, (float) $meta['page_width_mm']);
        $this->assertSame(210.0, (float) $meta['page_height_mm']);
        $this->assertStringContainsString('Microscope Course', (string) $meta['achievement_text']);

        // Later template edits must not mutate historical snapshot.
        $template->update([
            'layout_config' => array_replace_recursive($layout, [
                'defaults' => ['certificate_title' => 'CHANGED TITLE'],
            ]),
        ]);
        $first->refresh();
        $this->assertNotSame('CHANGED TITLE', data_get($first->metadata, 'certificate_title'));
        $this->assertSame('CERTIFICATE', data_get($first->metadata, 'certificate_title'));
    }

    public function test_pdf_generation_supports_landscape_portrait_and_arabic(): void
    {
        Storage::fake('local');

        foreach ([
            CertificateLayoutPresets::CYAN_LANDSCAPE_COMPLETION => 'landscape',
            CertificateLayoutPresets::YELLOW_PORTRAIT_ACHIEVEMENT => 'portrait',
        ] as $preset => $orientation) {
            $layout = CertificateLayoutPresets::layout($preset);
            $template = CertificateTemplate::query()->create([
                'slug' => 'tpl-'.$preset,
                'name' => ['en' => $preset, 'ar' => $preset],
                'is_active' => true,
                'layout_config' => $layout,
            ]);

            [$user, $enrollment] = $this->completedEnrollment('دورة المجهر', $template->id, 'أحمد محمد');
            $certificate = app(CertificateIssuanceService::class)->issue($enrollment);
            $this->assertNotNull($certificate);

            $path = app(CertificatePdfGenerator::class)->generate($certificate->fresh(['user', 'course', 'template']));
            $this->assertNotEmpty($path);
            Storage::disk('local')->assertExists($path);
            $bytes = Storage::disk('local')->get($path);
            $this->assertStringStartsWith('%PDF', $bytes);
            $this->assertSame(1, $this->pdfPageCount($bytes));
            $this->assertSame($orientation, data_get($certificate->metadata, 'orientation'));

            $html = app(CertificateTemplateRenderer::class)->renderForCertificate(
                $certificate->fresh(['user', 'course', 'template']),
                forBrowser: true,
            );
            $this->assertStringContainsString('أحمد محمد', $html);
            $this->assertStringContainsString('دورة المجهر', $html);
            $this->assertStringContainsString('data:image/png;base64,', $html);
        }
    }

    public function test_preview_pdf_does_not_create_certificate_records(): void
    {
        $template = CertificateTemplate::query()->create([
            'slug' => 'preview-only',
            'name' => ['en' => 'Preview', 'ar' => 'معاينة'],
            'is_active' => true,
            'layout_config' => CertificateLayoutPresets::layout(CertificateLayoutPresets::NAVY_LANDSCAPE_COMPLETION),
        ]);

        $pdf = app(CertificatePdfGenerator::class)->previewPdf($template);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(1, $this->pdfPageCount($pdf));
        $this->assertSame(0, Certificate::query()->count());
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_two_courses_never_receive_each_others_templates(): void
    {
        Storage::fake('local');

        $templateA = CertificateTemplate::query()->create([
            'slug' => 'template-a',
            'name' => ['en' => 'Template A', 'ar' => 'قالب أ'],
            'is_active' => true,
            'layout_config' => CertificateLayoutPresets::layout(CertificateLayoutPresets::CYAN_LANDSCAPE_COMPLETION),
        ]);
        $templateB = CertificateTemplate::query()->create([
            'slug' => 'template-b',
            'name' => ['en' => 'Template B', 'ar' => 'قالب ب'],
            'is_active' => true,
            'layout_config' => CertificateLayoutPresets::layout(CertificateLayoutPresets::NAVY_LANDSCAPE_COMPLETION),
        ]);

        [$userA, $enrollmentA] = $this->completedEnrollment('Course A', $templateA->id, 'Student Alpha');
        [$userB, $enrollmentB] = $this->completedEnrollment('Course B', $templateB->id, 'Student Beta');

        $service = app(CertificateIssuanceService::class);
        $certA = $service->issue($enrollmentA);
        $certB = $service->issue($enrollmentB);

        $this->assertNotNull($certA);
        $this->assertNotNull($certB);
        $this->assertSame($templateA->id, $certA->template_id);
        $this->assertSame($templateB->id, $certB->template_id);
        $this->assertNotSame($certA->template_id, $certB->template_id);
        $this->assertSame('Student Alpha', data_get($certA->metadata, 'student_name'));
        $this->assertSame('Student Beta', data_get($certB->metadata, 'student_name'));
        $this->assertSame('Course A', data_get($certA->metadata, 'course_name'));
        $this->assertSame('Course B', data_get($certB->metadata, 'course_name'));
        $this->assertSame($templateA->slug, data_get($certA->metadata, 'template_slug'));
        $this->assertSame($templateB->slug, data_get($certB->metadata, 'template_slug'));

        $htmlA = app(CertificateTemplateRenderer::class)->renderForCertificate($certA->fresh(['user', 'course', 'template']));
        $htmlB = app(CertificateTemplateRenderer::class)->renderForCertificate($certB->fresh(['user', 'course', 'template']));
        $this->assertStringContainsString('Student Alpha', $htmlA);
        $this->assertStringContainsString('Course A', $htmlA);
        $this->assertStringNotContainsString('Student Beta', $htmlA);
        $this->assertStringNotContainsString('Template B', $htmlA);
        $this->assertStringContainsString('Student Beta', $htmlB);
        $this->assertStringContainsString('Course B', $htmlB);
        $this->assertStringNotContainsString('Student Alpha', $htmlB);

        $pathA = app(CertificatePdfGenerator::class)->generate($certA->fresh(['user', 'course', 'template']));
        $pathB = app(CertificatePdfGenerator::class)->generate($certB->fresh(['user', 'course', 'template']));
        $this->assertSame(1, $this->pdfPageCount(Storage::disk('local')->get($pathA)));
        $this->assertSame(1, $this->pdfPageCount(Storage::disk('local')->get($pathB)));
    }

    public function test_certificate_index_is_scoped_to_authenticated_student(): void
    {
        $template = CertificateTemplate::query()->create([
            'slug' => 'list-tpl',
            'name' => ['en' => 'List', 'ar' => 'قائمة'],
            'is_active' => true,
            'layout_config' => CertificateLayoutPresets::layout(CertificateLayoutPresets::CYAN_LANDSCAPE_COMPLETION),
        ]);

        [$owner, $enrollment] = $this->completedEnrollment('Owned Course', $template->id, 'Owner Student');
        $certificate = app(CertificateIssuanceService::class)->issue($enrollment);
        $this->assertNotNull($certificate);

        [$other] = $this->completedEnrollment('Other Course', $template->id, 'Other Student');

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/certificates')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $certificate->uuid)
            ->assertJsonPath('data.0.student_name', 'Owner Student')
            ->assertJsonPath('data.0.certificate_number', $certificate->certificate_number);
    }

    public function test_malformed_template_does_not_break_progress(): void
    {
        Log::spy();

        $template = CertificateTemplate::query()->create([
            'slug' => 'broken',
            'name' => ['en' => 'Broken', 'ar' => 'معطوب'],
            'is_active' => true,
            'layout_config' => ['elements' => 'not-an-array', 'page' => 'bad'],
        ]);

        [$user, $topic, $enrollment, $course] = $this->singleTopicCourse();
        $course->update(['certificate_template_id' => $template->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/topics/{$topic->id}/progress", [
            'watch_progress_percent' => 100,
            'completed' => true,
        ])->assertOk()->assertJsonPath('data.completed', true);

        $enrollment->refresh();
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
    }

    public function test_verification_api_and_web_page_hide_sensitive_fields(): void
    {
        $template = CertificateTemplate::query()->create([
            'slug' => 'verify-tpl',
            'name' => ['en' => 'Verify', 'ar' => 'تحقق'],
            'is_active' => true,
            'layout_config' => CertificateLayoutPresets::layout(CertificateLayoutPresets::CYAN_LANDSCAPE_COMPLETION),
        ]);

        [$user, $enrollment] = $this->completedEnrollment('Course X', $template->id);
        $user->forceFill(['email' => 'secret-student@example.com', 'phone' => '01099999999'])->save();
        $certificate = app(CertificateIssuanceService::class)->issue($enrollment);
        $this->assertNotNull($certificate);

        $api = $this->getJson('/api/v1/certificates/verify/'.$certificate->verification_code)
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('certificate_number', $certificate->certificate_number);

        $apiJson = $api->json();
        $this->assertArrayNotHasKey('email', $apiJson);
        $this->assertArrayNotHasKey('phone', $apiJson);
        $this->assertArrayNotHasKey('user_id', $apiJson);
        $this->assertStringNotContainsString('secret-student@example.com', $api->getContent());

        $this->getJson('/api/v1/certificates/verify/not-a-real-code')
            ->assertNotFound()
            ->assertJsonPath('valid', false);

        $web = $this->get('/certificates/verify/'.$certificate->verification_code)
            ->assertOk();
        $web->assertSee($user->name, false);
        $web->assertSee($certificate->certificate_number, false);
        $web->assertDontSee('secret-student@example.com', false);
        $web->assertDontSee('01099999999', false);

        $this->get('/certificates/verify/invalid-code')->assertNotFound();
    }

    public function test_student_can_only_access_own_certificate(): void
    {
        $template = CertificateTemplate::query()->create([
            'slug' => 'own-tpl',
            'name' => ['en' => 'Own', 'ar' => 'خاص'],
            'is_active' => true,
            'layout_config' => CertificateLayoutPresets::layout(CertificateLayoutPresets::CYAN_LANDSCAPE_COMPLETION),
        ]);

        [$owner, $enrollment] = $this->completedEnrollment('Course Y', $template->id);
        $certificate = app(CertificateIssuanceService::class)->issue($enrollment);
        $this->assertNotNull($certificate);

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/certificates/'.$certificate->uuid)->assertNotFound();
        $this->getJson('/api/v1/certificates/'.$certificate->uuid.'/download')->assertNotFound();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/certificates/'.$certificate->uuid)
            ->assertOk()
            ->assertJsonPath('data.certificate_number', $certificate->certificate_number)
            ->assertJsonPath('data.verification_url', url('/certificates/verify/'.$certificate->verification_code));
    }

    public function test_admin_can_manage_templates_guest_cannot(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->get('/admin/certificate-templates')->assertRedirect();

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($admin)
            ->get('/admin/certificate-templates')
            ->assertOk();

        $inactive = CertificateTemplate::query()->create([
            'slug' => 'inactive-admin',
            'name' => ['en' => 'Inactive', 'ar' => 'غير نشط'],
            'is_active' => false,
            'layout_config' => CertificateLayoutPresets::layout(CertificateLayoutPresets::CYAN_LANDSCAPE_COMPLETION),
        ]);
        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_inactive_and_missing_templates_do_not_break_progress(): void
    {
        Log::spy();
        [$user, $topic, $enrollment] = $this->singleTopicCourse();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/topics/{$topic->id}/progress", [
            'watch_progress_percent' => 100,
            'completed' => true,
        ])->assertOk();

        $enrollment->refresh();
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_does_not_issue_using_unrelated_active_template(): void
    {
        CertificateTemplate::query()->create([
            'slug' => 'unrelated-active',
            'name' => ['en' => 'Unrelated', 'ar' => 'آخر'],
            'is_active' => true,
            'layout_config' => CertificateLayoutPresets::layout(CertificateLayoutPresets::CYAN_LANDSCAPE_COMPLETION),
        ]);

        [$user, $enrollment] = $this->completedEnrollment('No Link Course', templateId: null);
        $issued = app(CertificateIssuanceService::class)->issue($enrollment);
        $this->assertNull($issued);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_admin_web_session_does_not_own_student_api_certificate(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $template = CertificateTemplate::query()->create([
            'slug' => 'iso-tpl',
            'name' => ['en' => 'Iso', 'ar' => 'عزل'],
            'is_active' => true,
            'layout_config' => CertificateLayoutPresets::layout(CertificateLayoutPresets::CYAN_LANDSCAPE_COMPLETION),
        ]);

        $student = User::factory()->create(['name' => 'Mahmoud Hammad']);
        $admin = User::factory()->create(['name' => 'Admin User']);
        $admin->assignRole('super_admin');

        $course = Course::query()->create([
            'slug' => 'iso-course-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'certificate_template_id' => $template->id,
            'title' => ['en' => 'Microscope Course', 'ar' => 'دورة المجهر'],
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'l1',
            'lesson_type' => 'theory',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'L', 'ar' => 'د'],
        ]);
        $topic = Topic::query()->create([
            'lesson_id' => $lesson->id,
            'slug' => 't1',
            'sort_order' => 1,
            'content_type' => 'video',
            'is_published' => true,
            'title' => ['en' => 'T', 'ar' => 'م'],
        ]);
        $enrollment = Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 0,
            'enrolled_at' => now(),
            'started_at' => now(),
            'grant_certificate' => true,
        ]);

        $token = $student->createToken('api')->plainTextToken;
        $this->actingAs($admin, 'web');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/topics/{$topic->id}/progress", [
                'watch_progress_percent' => 100,
                'completed' => true,
            ])
            ->assertOk();

        $certificate = Certificate::query()->where('enrollment_id', $enrollment->id)->first();
        $this->assertNotNull($certificate);
        $this->assertSame($student->id, $certificate->user_id);
        $this->assertNotSame($admin->id, $certificate->user_id);
        $this->assertSame('Mahmoud Hammad', data_get($certificate->metadata, 'student_name'));
        $this->assertSame(1, Certificate::query()->count());

        $html = app(CertificateTemplateRenderer::class)->renderForCertificate($certificate->fresh(['user', 'course', 'template']));
        $this->assertStringContainsString('Mahmoud Hammad', $html);
        $this->assertStringNotContainsString('Admin User', $html);
        $this->assertStringNotContainsString('Ahmed Mohamed', $html);

        app(CertificateIssuanceService::class)->issue($enrollment->fresh(['course', 'user']));
        $this->assertSame(1, Certificate::query()->count());
    }

    /**
     * @return array{0: User, 1: Enrollment, 2: Course}
     */
    private function completedEnrollment(string $courseTitle, ?int $templateId = null, string $studentName = 'Ahmed Mohamed'): array
    {
        $user = User::factory()->create(['name' => $studentName]);
        $course = Course::query()->create([
            'slug' => 'course-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'certificate_template_id' => $templateId,
            'title' => ['en' => $courseTitle, 'ar' => $courseTitle],
        ]);
        $enrollment = Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Completed,
            'progress_percent' => 100,
            'enrolled_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
            'grant_certificate' => true,
        ]);

        return [$user, $enrollment, $course];
    }

    /**
     * @return array{0: User, 1: Topic, 2: Enrollment, 3: Course}
     */
    private function singleTopicCourse(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'dyn-cert-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'certificate_template_id' => null,
            'title' => ['en' => 'Dyn', 'ar' => 'ديناميك'],
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

    public function test_html_preview_is_owned_json_not_pdf(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $template = CertificateTemplate::query()->create([
            'slug' => 'html-preview-tpl',
            'name' => ['en' => 'Preview Tpl', 'ar' => 'معاينة'],
            'is_active' => true,
            'layout_config' => CertificateLayoutPresets::layout(CertificateLayoutPresets::CYAN_LANDSCAPE_COMPLETION),
        ]);

        [$owner, $enrollment] = $this->completedEnrollment('HTML Course', $template->id, 'Nour Hassan');
        $certificate = app(CertificateIssuanceService::class)->issue($enrollment);
        $this->assertNotNull($certificate);

        $this->getJson('/api/v1/certificates/'.$certificate->uuid.'/preview')
            ->assertUnauthorized();

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/certificates/'.$certificate->uuid.'/preview')->assertNotFound();
        $this->getJson('/api/v1/certificates/'.$certificate->uuid.'/download')->assertNotFound();

        Sanctum::actingAs($owner);
        $preview = $this->getJson('/api/v1/certificates/'.$certificate->uuid.'/preview')
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('data.student_name', 'Nour Hassan')
            ->assertJsonPath('data.course_title', 'HTML Course')
            ->assertJsonPath('data.certificate_number', $certificate->certificate_number)
            ->assertJsonPath('data.template_id', $template->id);

        $this->assertStringNotContainsString('application/pdf', (string) $preview->headers->get('content-type'));
        $html = (string) $preview->json('data.html');
        $this->assertStringContainsString('Nour Hassan', $html);
        $this->assertStringContainsString('HTML Course', $html);
        $this->assertStringContainsString($certificate->certificate_number, $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('Science Street Admin', $html);
        $this->assertStringNotContainsString('Certificate of Completion', $html);

        $download = $this->get("/api/v1/certificates/{$certificate->uuid}/download")
            ->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $download->headers->get('content-type'));
        $this->assertSame(1, $this->pdfPageCount($download->streamedContent()));
        $this->assertSame($template->id, (int) $preview->json('data.template_id'));
    }

    public function test_stale_generic_pdf_is_rebuilt_from_assigned_template(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $template = CertificateTemplate::query()->create([
            'slug' => 'stale-tpl',
            'name' => ['en' => 'Stale', 'ar' => 'قديم'],
            'is_active' => true,
            'background_path' => 'certificates/blue.jpeg',
            'layout_config' => [],
        ]);
        Storage::disk('public')->put('certificates/blue.jpeg', 'fake-image');

        [$owner, $enrollment] = $this->completedEnrollment('Microscope Course', $template->id, 'Toka Student');
        $certificate = app(CertificateIssuanceService::class)->issue($enrollment);
        $this->assertNotNull($certificate);

        $stalePath = "certificates/{$certificate->uuid}.pdf";
        Storage::disk('local')->put($stalePath, "%PDF-1.4\n1 0 obj<</Type /Pages /Count 2>>endobj\n2 0 obj<</Type /Page>>endobj\n3 0 obj<</Type /Page>>endobj\n%%EOF");
        $certificate->update([
            'pdf_path' => $stalePath,
            'metadata' => [
                'student_name' => 'Toka Student',
                'course_title' => 'Microscope Course',
            ],
        ]);

        Sanctum::actingAs($owner);
        $preview = $this->getJson('/api/v1/certificates/'.$certificate->uuid.'/preview')->assertOk();
        $this->assertStringContainsString('Toka Student', (string) $preview->json('data.html'));
        $this->assertSame($template->id, (int) $preview->json('data.template_id'));
        $this->assertSame('template-v2', data_get($certificate->fresh()->metadata, 'renderer'));

        $bytes = $this->get("/api/v1/certificates/{$certificate->uuid}/download")->assertOk()->streamedContent();
        $this->assertSame(1, $this->pdfPageCount($bytes));
        $this->assertGreaterThan(200, strlen($bytes));
    }

    private function pdfPageCount(string $bytes): int
    {
        if (preg_match_all('/\/Type\s*\/Page(?!s)\b/', $bytes, $matches)) {
            return count($matches[0]);
        }

        return 0;
    }
}
