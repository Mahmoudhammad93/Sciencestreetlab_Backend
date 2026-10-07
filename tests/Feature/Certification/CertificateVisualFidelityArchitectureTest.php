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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CertificateVisualFidelityArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_layout_replaces_name_box_and_keeps_static_achievement(): void
    {
        Storage::fake('local');
        $imported = $this->importedYellowTemplate();
        [$user, $enrollment] = $this->completedEnrollment('كورس الميكروسكوب', $imported->id, 'Ahmed Mohamed');

        $certificate = app(CertificateIssuanceService::class)->issue($enrollment);
        $this->assertNotNull($certificate);

        $html = app(CertificateTemplateRenderer::class)->renderForCertificate(
            $certificate->fresh(['user', 'course', 'template']),
            forBrowser: true,
            embed: true,
        );

        $this->assertStringContainsString('Ahmed Mohamed', $html);
        $this->assertStringContainsString('HAS COMPLETED SCIENCE STREET', $html);
        $this->assertStringNotContainsString('DOAA ELSAYED', $html);
        $this->assertStringNotContainsString('كورس الميكروسكوب', $html);
        $this->assertStringContainsString('left:8.08', $html);
        $this->assertStringContainsString('%', $html);
        $this->assertStringNotContainsString('background-size: cover', $html);
        $this->assertMatchesRegularExpression("/font-family:'DejaVu Sans'|font-family:Cairo/", $html);

        $preview = app(CertificateTemplateRenderer::class)->canonicalView($certificate->fresh(['user', 'course', 'template']), true);
        $pdfHtml = app(CertificateTemplateRenderer::class)->renderForCertificate($certificate->fresh(['user', 'course', 'template']));
        $this->assertSame($preview['render_fingerprint'], app(CertificateTemplateRenderer::class)->renderFingerprint($certificate->fresh()));
        $this->assertSame(CertificateTemplateRenderer::RENDERER_VERSION, $preview['template_version']);
        $this->assertStringContainsString('Ahmed Mohamed', $pdfHtml);
        $this->assertStringContainsString('HAS COMPLETED SCIENCE STREET', $pdfHtml);

        $path = app(CertificatePdfGenerator::class)->generate($certificate->fresh(['user', 'course', 'template']));
        $bytes = Storage::disk('local')->get($path);
        $this->assertSame(1, $this->pdfPageCount($bytes));
    }

    public function test_image_only_template_uses_dashboard_artwork_plus_name_and_course(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->importedYellowTemplate();
        $this->putArtworkJpeg('certificates/blue.jpeg', 1024, 578);

        $jpeg = CertificateTemplate::query()->create([
            'slug' => 'blue-certificate',
            'name' => ['en' => 'Blue', 'ar' => 'أزرق'],
            'is_active' => true,
            'background_path' => 'certificates/blue.jpeg',
            'layout_config' => [],
        ]);

        [$user, $enrollment] = $this->completedEnrollment('كورس الميكروسكوب', $jpeg->id, 'Nour Hassan');
        $certificate = app(CertificateIssuanceService::class)->issue($enrollment);
        $this->assertNotNull($certificate);
        $this->assertSame('artwork_landscape_overlay', data_get($certificate->metadata, 'layout_snapshot.page.preset'));
        $this->assertSame('certificates/blue.jpeg', data_get($certificate->metadata, 'background_path'));
        $this->assertEqualsWithDelta(1024 * 25.4 / 96, (float) data_get($certificate->metadata, 'page_width_mm'), 0.2);
        $this->assertEqualsWithDelta(578 * 25.4 / 96, (float) data_get($certificate->metadata, 'page_height_mm'), 0.2);

        $html = app(CertificateTemplateRenderer::class)->renderForCertificate($certificate->fresh(['user', 'course', 'template']), true, true);
        $this->assertStringContainsString('Nour Hassan', $html);
        $this->assertStringContainsString('كورس الميكروسكوب', $html);
        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
        $this->assertStringContainsString('ssl-cert-artwork', $html);
        $this->assertStringNotContainsString('DOAA ELSAYED', $html);
        $this->assertStringNotContainsString('HAS COMPLETED SCIENCE STREET', $html);

        $path = app(CertificatePdfGenerator::class)->generate($certificate->fresh(['user', 'course', 'template']));
        $bytes = Storage::disk('local')->get($path);
        $this->assertSame(1, $this->pdfPageCount($bytes));
    }

    public function test_overlay_snapshot_is_rebuilt_and_pdf_cache_invalidates(): void
    {
        Storage::fake('local');

        [$user, $enrollment] = $this->completedEnrollment('Microscope Course', $this->importedYellowTemplate('imported-yellow-b')->id, 'Toka Student');
        $certificate = app(CertificateIssuanceService::class)->issue($enrollment);
        $this->assertNotNull($certificate);

        $stalePath = "certificates/{$certificate->uuid}.pdf";
        Storage::disk('local')->put($stalePath, "%PDF-1.4\n1 0 obj<</Type /Pages /Count 2>>endobj\n2 0 obj<</Type /Page>>endobj\n3 0 obj<</Type /Page>>endobj\n%%EOF");
        $certificate->update([
            'pdf_path' => $stalePath,
            'metadata' => array_merge($certificate->metadata ?? [], [
                'renderer' => 'template-v1',
                'pdf_renderer' => 'template-v1',
                'render_fingerprint' => 'stale',
                'layout_snapshot' => CertificateLayoutPresets::artworkLandscapeOverlay(),
                'student_name' => 'Toka Student',
            ]),
        ]);

        $this->assertTrue(app(CertificateTemplateRenderer::class)->snapshotNeedsRebuild($certificate->fresh()));

        Sanctum::actingAs($user);
        $preview = $this->getJson('/api/v1/certificates/'.$certificate->uuid.'/preview')->assertOk();
        $this->assertStringContainsString('Toka Student', (string) $preview->json('data.html'));
        $this->assertSame('template-v5', $preview->json('data.template_version'));
        $this->assertSame($preview->json('data.render_fingerprint'), data_get($certificate->fresh()->metadata, 'layout_snapshot') ? $preview->json('data.render_fingerprint') : null);

        $bytes = $this->get("/api/v1/certificates/{$certificate->uuid}/download")->assertOk()->streamedContent();
        $this->assertSame(1, $this->pdfPageCount($bytes));
        $this->assertSame('template-v5', data_get($certificate->fresh()->metadata, 'pdf_renderer'));
        $this->assertNotSame('stale', data_get($certificate->fresh()->metadata, 'render_fingerprint'));
    }

    public function test_identity_is_certificate_owner_not_admin_session(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $template = $this->importedYellowTemplate();
        $student = User::factory()->create(['name' => 'Mahmoud Hammad']);
        $admin = User::factory()->create(['name' => 'Science Street Admin']);
        $admin->assignRole('super_admin');

        [$enrollment] = $this->completedEnrollmentFor($student, 'كورس الميكروسكوب', $template->id);
        $this->actingAs($admin, 'web');
        $certificate = app(CertificateIssuanceService::class)->issue($enrollment);
        $this->assertNotNull($certificate);
        $this->assertSame($student->id, $certificate->user_id);
        $this->assertSame('Mahmoud Hammad', data_get($certificate->metadata, 'student_name'));

        $html = app(CertificateTemplateRenderer::class)->renderForCertificate($certificate->fresh(['user', 'course', 'template']), true);
        $this->assertStringContainsString('Mahmoud Hammad', $html);
        $this->assertStringNotContainsString('Science Street Admin', $html);
        $this->assertStringNotContainsString('Ahmed Mohamed', $html);
    }

    public function test_name_variants_stay_inside_the_configured_box(): void
    {
        $template = $this->importedYellowTemplate();
        foreach (['Jo', 'Alexandria Christopher Montgomery', 'سارة', 'عبد الرحمن محمد علي حسن'] as $name) {
            [$user, $enrollment] = $this->completedEnrollment('Microscope Course', $template->id, $name);
            $certificate = app(CertificateIssuanceService::class)->issue($enrollment);
            $html = app(CertificateTemplateRenderer::class)->renderForCertificate($certificate->fresh(['user', 'course', 'template']), true, true);
            $this->assertStringContainsString($name, $html);
            $this->assertMatchesRegularExpression('/left:8\.\d+%;top:39\.\d+%;width:83\.\d+%;height:8\.\d+%;/', $html);
        }
    }

    public function test_html_preview_and_pdf_share_the_same_snapshot(): void
    {
        Storage::fake('local');
        $template = $this->importedYellowTemplate();
        [$user, $enrollment] = $this->completedEnrollment('كورس الميكروسكوب', $template->id, 'Ahmed Mohamed');
        $certificate = app(CertificateIssuanceService::class)->issue($enrollment);

        Sanctum::actingAs($user);
        $preview = $this->getJson('/api/v1/certificates/'.$certificate->uuid.'/preview')->assertOk();
        $this->assertStringContainsString('application/json', (string) $preview->headers->get('content-type'));
        $this->get("/api/v1/certificates/{$certificate->uuid}/download")->assertOk();

        $fresh = $certificate->fresh();
        $this->assertSame(
            $preview->json('data.render_fingerprint'),
            app(CertificateTemplateRenderer::class)->renderFingerprint($fresh)
        );
        $this->assertSame($preview->json('data.template_version'), data_get($fresh->metadata, 'pdf_renderer'));
        $this->assertSame(data_get($fresh->metadata, 'layout_snapshot.page.width_mm'), $preview->json('data.page.width_mm'));
    }

    private function putArtworkJpeg(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $yellow = imagecolorallocate($image, 253, 215, 0);
        imagefilledrectangle($image, 0, 0, $width, $height, $yellow);
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        Storage::disk('public')->put($path, $bytes);
    }

    private function importedYellowTemplate(string $slug = 'imported-yellow'): CertificateTemplate
    {
        return CertificateTemplate::query()->create([
            'slug' => $slug,
            'name' => ['en' => 'Imported Yellow', 'ar' => 'أصفر'],
            'is_active' => true,
            'layout_config' => [
                'page' => [
                    'orientation' => 'landscape',
                    'width_mm' => 297,
                    'height_mm' => 210,
                    'background_color' => '#FDD700',
                    'border_color' => '#FDD700',
                    'border_width_mm' => 0,
                    'preset' => 'imported',
                ],
                'defaults' => [],
                'elements' => [
                    ['id' => 'title', 'type' => 'text', 'content' => 'CERTIFICATE', 'x' => 20, 'y' => 34, 'width' => 257, 'height' => 16, 'font_size' => 36, 'text_align' => 'center', 'color' => '#1a2a5c'],
                    ['id' => 'subtitle', 'type' => 'text', 'content' => 'OF COMPLETION', 'x' => 20, 'y' => 53, 'width' => 257, 'height' => 10, 'font_size' => 14, 'text_align' => 'center', 'color' => '#1a2a5c'],
                    ['id' => 'intro', 'type' => 'text', 'content' => 'THIS IS TO CERTIFY THAT', 'x' => 20, 'y' => 74, 'width' => 257, 'height' => 8, 'font_size' => 12, 'text_align' => 'center', 'color' => '#1a2a5c'],
                    ['id' => 'p_name', 'type' => 'text', 'content' => 'DOAA ELSAYED', 'x' => 24, 'y' => 82.95, 'width' => 249, 'height' => 18, 'font_size' => 44.62, 'text_align' => 'center', 'color' => '#1a2a5c'],
                    ['id' => 'body', 'type' => 'text', 'content' => 'HAS COMPLETED SCIENCE STREET SUMMER CAMP 2024 WITH EXCELLENCE!', 'x' => 30, 'y' => 108, 'width' => 237, 'height' => 16, 'font_size' => 13, 'text_align' => 'center', 'color' => '#1a2a5c'],
                    ['id' => 'brand', 'type' => 'text', 'content' => 'شارع العلوم', 'x' => 220, 'y' => 12, 'width' => 60, 'height' => 12, 'font_size' => 14, 'text_align' => 'right', 'dir' => 'rtl', 'color' => '#1a2a5c'],
                ],
            ],
        ]);
    }

    /**
     * @return array{0: User, 1: Enrollment, 2: Course}
     */
    private function completedEnrollment(string $courseTitle, ?int $templateId, string $studentName = 'Ahmed Mohamed'): array
    {
        $user = User::factory()->create(['name' => $studentName]);
        [$enrollment, $course] = $this->completedEnrollmentFor($user, $courseTitle, $templateId);

        return [$user, $enrollment, $course];
    }

    /**
     * @return array{0: Enrollment, 1: Course}
     */
    private function completedEnrollmentFor(User $user, string $courseTitle, ?int $templateId): array
    {
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

        return [$enrollment, $course];
    }

    private function pdfPageCount(string $bytes): int
    {
        if (preg_match_all('/\/Type\s*\/Page(?!s)\b/', $bytes, $matches)) {
            return count($matches[0]);
        }

        return 0;
    }
}
