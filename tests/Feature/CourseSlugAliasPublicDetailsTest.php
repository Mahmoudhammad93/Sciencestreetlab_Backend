<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CourseSlugAliasPublicDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_microscope_course_english_alias_returns_public_details(): void
    {
        $course = $this->seedArabicMicroscopeShell();

        $response = $this->getJson('/api/v1/courses/microscope-course')->assertOk();

        $response->assertJsonPath('data.id', $course->id);
        $this->assertSame('not_enrolled', $response->json('data.enrollment_status'));
        $this->assertIsArray($response->json('data.lessons'));
    }

    public function test_arabic_slug_and_seeded_english_slug_both_work(): void
    {
        $this->seed();
        $this->getJson('/api/v1/courses/microscope-course')->assertOk();

        $arabic = $this->seedArabicMicroscopeShell(id: 26);
        $this->getJson('/api/v1/courses/'.rawurlencode('كورس-الميكروسكوب'))->assertOk()
            ->assertJsonPath('data.id', $arabic->id);
        $this->getJson('/api/v1/courses/microscope-course')->assertOk()
            ->assertJsonPath('data.id', $arabic->id);
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->seedArabicMicroscopeShell();
        $this->getJson('/api/v1/courses/does-not-exist-course')
            ->assertNotFound()
            ->assertJsonPath('message', 'Course not found');
    }

    public function test_guest_and_authenticated_public_details_resolve_consistently(): void
    {
        $course = $this->seedArabicMicroscopeShell();

        $guest = $this->getJson('/api/v1/courses/microscope-course')->assertOk();
        $this->assertSame($course->id, (int) $guest->json('data.id'));
        $this->assertSame('not_enrolled', $guest->json('data.enrollment_status'));

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $auth = $this->getJson('/api/v1/courses/microscope-course')->assertOk();
        $this->assertSame($course->id, (int) $auth->json('data.id'));
        $this->assertSame('not_enrolled', $auth->json('data.enrollment_status'));
    }

    public function test_admin_web_session_plus_customer_bearer_does_not_corrupt_identity(): void
    {
        $course = $this->seedArabicMicroscopeShell();
        $admin = User::factory()->create(['email' => 'admin-course@example.com']);
        $customer = User::factory()->create(['email' => 'customer-course@example.com']);
        $token = $customer->createToken('api')->plainTextToken;

        $this->actingAs($admin, 'web');

        $me = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
        $this->assertSame($customer->id, (int) $me->json('data.id'));

        $courseRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/courses/microscope-course')
            ->assertOk();
        $this->assertSame($course->id, (int) $courseRes->json('data.id'));
        $this->assertSame('not_enrolled', $courseRes->json('data.enrollment_status'));
    }

    public function test_curriculum_still_requires_enrollment_after_alias_resolve(): void
    {
        $this->seedArabicMicroscopeShell();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/courses/microscope-course/curriculum')
            ->assertStatus(403)
            ->assertJsonPath('code', 'NOT_ENROLLED');
    }

    public function test_course_26_legacy_map_and_tree_counts_unchanged_by_alias(): void
    {
        $course = $this->seedArabicMicroscopeShell(id: 26);
        for ($i = 1; $i <= 44; $i++) {
            Lesson::query()->create([
                'course_id' => $course->id,
                'slug' => 'lesson-'.$i,
                'sort_order' => $i,
                'is_published' => true,
                'title' => ['en' => 'L'.$i, 'ar' => 'د'.$i],
            ]);
        }

        $run = LegacyMigrationRun::query()->create([
            'source' => 'wordpress',
            'environment' => 'testing',
            'status' => 'running',
            'started_at' => now(),
        ]);

        DB::table('legacy_import_maps')->insert([
            'migration_run_id' => $run->id,
            'source' => 'wordpress',
            'entity_type' => 'course',
            'legacy_id' => '8507',
            'local_id' => 26,
            'metadata' => json_encode([
                'mapped_to_existing' => false,
                'created_by_migration' => true,
                'legacy_wordpress_id' => '8507',
            ]),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeSlug = $course->fresh()->slug;
        $beforeLessons = Lesson::query()->where('course_id', 26)->count();
        $beforeMap = DB::table('legacy_import_maps')->where('entity_type', 'course')->where('legacy_id', '8507')->first();

        $this->getJson('/api/v1/courses/microscope-course')->assertOk()->assertJsonPath('data.id', 26);

        $this->assertSame($beforeSlug, Course::query()->findOrFail(26)->slug);
        $this->assertSame(44, Lesson::query()->where('course_id', 26)->count());
        $this->assertSame($beforeLessons, Lesson::query()->where('course_id', 26)->count());
        $afterMap = DB::table('legacy_import_maps')->where('entity_type', 'course')->where('legacy_id', '8507')->first();
        $this->assertSame((int) $beforeMap->local_id, (int) $afterMap->local_id);
        $this->assertSame('كورس-الميكروسكوب', Course::query()->findOrFail(26)->slug);
    }

    public function test_unpublished_alias_target_still_404(): void
    {
        Course::query()->create([
            'slug' => 'كورس-الميكروسكوب',
            'access_type' => AccessType::Paid,
            'is_published' => false,
            'published_at' => null,
            'title' => ['en' => 'Microscope', 'ar' => 'الميكروسكوب'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);

        $this->getJson('/api/v1/courses/microscope-course')->assertNotFound();
    }

    private function seedArabicMicroscopeShell(?int $id = null): Course
    {
        // Avoid colliding with seeded microscope-course when both exist.
        Course::query()->where('slug', 'microscope-course')->update(['slug' => 'microscope-course-seed-disabled', 'is_published' => false]);

        $attrs = [
            'slug' => 'كورس-الميكروسكوب',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'The Microscope Course', 'ar' => 'كورس الميكروسكوب'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ];

        if ($id !== null) {
            $existing = Course::query()->find($id);
            if ($existing) {
                $existing->forceFill($attrs)->save();

                return $existing->fresh();
            }

            $course = new Course($attrs);
            $course->id = $id;
            $course->save();

            return $course->fresh();
        }

        return Course::query()->create($attrs);
    }
}