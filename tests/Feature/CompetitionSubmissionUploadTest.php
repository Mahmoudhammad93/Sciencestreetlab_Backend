<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Competition\Domain\Enums\ParticipantStatus;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CompetitionSubmissionUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed();
    }

    public function test_show_exposes_authoritative_upload_rules(): void
    {
        $this->getJson('/api/v1/competitions/microscope-100-challenge')
            ->assertOk()
            ->assertJsonPath('data.max_sample_number', 50)
            ->assertJsonPath('data.max_photos_per_sample', 2)
            ->assertJsonPath('data.upload_rules.max_sample_number', 50)
            ->assertJsonPath('data.upload_rules.max_photos_per_sample', 2)
            ->assertJsonPath('data.upload_rules.max_file_kb', 10240)
            ->assertJsonPath('data.upload_rules.description_max', 2000)
            ->assertJsonPath('data.upload_rules.description_required', false);
    }

    public function test_valid_image_upload_persists_canonical_fields(): void
    {
        [$user] = $this->registeredParticipant();

        $response = $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 7,
            'description' => 'Onion epidermis',
            'photo' => UploadedFile::fake()->image('microscope.jpg', 800, 600),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.sample_number', 7)
            ->assertJsonPath('data.photo_index', 1)
            ->assertJsonPath('data.description', 'Onion epidermis')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['id', 'uuid', 'photo_url', 'photo_thumb_url', 'submitted_at']]);

        $submission = CompetitionSubmission::query()->firstOrFail();
        $this->assertSame(7, $submission->sample_number);
        $this->assertSame(1, $submission->photo_index);
        $this->assertSame($user->id, $submission->participant->user_id);
        $this->assertNotNull($submission->getFirstMedia('photo'));
        $this->assertStringStartsWith('competition-', $submission->getFirstMedia('photo')->file_name);
        $this->assertStringNotContainsString('microscope', $submission->getFirstMedia('photo')->file_name);
    }

    public function test_omitted_photo_index_auto_assigns_next_free_slot(): void
    {
        $this->registeredParticipant();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 3,
            'photo' => UploadedFile::fake()->image('a.jpg', 400, 400),
        ])->assertCreated()->assertJsonPath('data.photo_index', 1);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 3,
            'photo' => UploadedFile::fake()->image('b.jpg', 400, 400),
        ])->assertCreated()->assertJsonPath('data.photo_index', 2);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 3,
            'photo' => UploadedFile::fake()->image('c.jpg', 400, 400),
        ])->assertStatus(422)->assertJsonPath('code', 'sample_photos_full');
    }

    public function test_sample_number_bounds_are_enforced(): void
    {
        $this->registeredParticipant();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 0,
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertStatus(422);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 51,
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertStatus(422);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 'abc',
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertStatus(422);
    }

    public function test_missing_photo_is_rejected(): void
    {
        $this->registeredParticipant();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
        ])->assertStatus(422);
    }

    public function test_invalid_mime_and_executable_disguises_are_rejected(): void
    {
        $this->registeredParticipant();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
        ])->assertStatus(422);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->create('shell.php', 20, 'application/x-php'),
        ])->assertStatus(422);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->create('shell.php.jpg', 20, 'application/x-php'),
        ])->assertStatus(422);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->create('vector.svg', 20, 'image/svg+xml'),
        ])->assertStatus(422);
    }

    public function test_oversized_image_is_rejected(): void
    {
        $this->registeredParticipant();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->image('huge.jpg', 200, 200)->size(11001),
        ])->assertStatus(422);
    }

    public function test_unauthenticated_upload_is_rejected(): void
    {
        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertUnauthorized();
    }

    public function test_non_participant_cannot_upload(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertStatus(422)->assertJsonPath('code', 'not_registered');
    }

    public function test_participant_cannot_upload_to_another_competition(): void
    {
        $this->registeredParticipant();
        $other = $this->otherCompetition();

        $this->postJson("/api/v1/competitions/{$other->slug}/submissions", [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertStatus(422)->assertJsonPath('code', 'not_registered');
    }

    public function test_inactive_competition_rejects_upload(): void
    {
        $this->registeredParticipant();
        Competition::query()->where('slug', 'microscope-100-challenge')->update([
            'status' => 'completed',
        ]);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertStatus(422)->assertJsonPath('code', 'competition_not_active');
    }

    public function test_closed_submission_window_rejects_upload(): void
    {
        $this->registeredParticipant();
        Competition::query()->where('slug', 'microscope-100-challenge')->update([
            'ends_at' => now()->subDay(),
        ]);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertStatus(422)->assertJsonPath('code', 'competition_not_active');
    }

    public function test_description_max_length_is_enforced(): void
    {
        $this->registeredParticipant();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'description' => str_repeat('x', 2001),
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertStatus(422);
    }

    public function test_duplicate_explicit_slot_is_rejected_until_revision(): void
    {
        $this->registeredParticipant();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 4,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertCreated();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 4,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('b.jpg', 200, 200),
        ])->assertStatus(422)->assertJsonPath('code', 'slot_already_submitted');

        $submission = CompetitionSubmission::query()->firstOrFail();
        $submission->update(['status' => SubmissionStatus::RevisionRequested]);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 4,
            'photo_index' => 1,
            'description' => 'Revised',
            'photo' => UploadedFile::fake()->image('c.jpg', 200, 200),
        ])->assertCreated()->assertJsonPath('data.description', 'Revised');

        $this->assertSame(1, CompetitionSubmission::query()->count());
    }

    public function test_list_is_scoped_to_current_user_and_optional_competition(): void
    {
        [$userA] = $this->registeredParticipant();
        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertCreated();

        $userB = User::factory()->create();
        $this->registerParticipant($userB);
        Sanctum::actingAs($userB);
        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 2,
            'description' => 'Only B',
            'photo' => UploadedFile::fake()->image('b.jpg', 200, 200),
        ])->assertCreated();

        $mine = $this->getJson('/api/v1/submissions?competition=microscope-100-challenge')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $mine);
        $this->assertSame('Only B', $mine[0]['description']);
        $this->assertSame(2, $mine[0]['sample_number']);

        Sanctum::actingAs($userA);
        $other = $this->getJson('/api/v1/submissions')->assertOk()->json('data');
        $this->assertCount(1, $other);
        $this->assertNotSame('Only B', $other[0]['description']);
    }

    public function test_historical_nullable_slots_remain_valid_and_do_not_block_new_slots(): void
    {
        [$user, $participant] = $this->registeredParticipant();

        $historical = CompetitionSubmission::query()->create([
            'participant_id' => $participant->id,
            'sample_number' => null,
            'photo_index' => null,
            'status' => SubmissionStatus::Approved,
            'description' => 'WordPress import',
            'submitted_at' => now()->subYear(),
        ]);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->image('new.jpg', 200, 200),
        ])->assertCreated()->assertJsonPath('data.sample_number', 1)->assertJsonPath('data.photo_index', 1);

        $historical->refresh();
        $this->assertNull($historical->sample_number);
        $this->assertNull($historical->photo_index);
        $this->assertSame('WordPress import', $historical->description);
        $this->assertSame($user->id, $historical->participant->user_id);
        $this->assertSame(2, CompetitionSubmission::query()->count());
    }

    public function test_path_traversal_original_filename_is_not_used(): void
    {
        $this->registeredParticipant();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo' => UploadedFile::fake()->image('../../etc/passwd.jpg', 200, 200),
        ])->assertCreated();

        $fileName = CompetitionSubmission::query()->firstOrFail()->getFirstMedia('photo')->file_name;
        $this->assertStringNotContainsString('..', $fileName);
        $this->assertStringNotContainsString('passwd', $fileName);
        $this->assertStringNotContainsString('/', $fileName);
    }

    /**
     * @return array{0: User, 1: CompetitionParticipant}
     */
    private function registeredParticipant(): array
    {
        $user = User::factory()->create();
        $participant = $this->registerParticipant($user);
        Sanctum::actingAs($user);

        return [$user, $participant];
    }

    private function registerParticipant(User $user): CompetitionParticipant
    {
        $competition = Competition::query()->where('slug', 'microscope-100-challenge')->firstOrFail();

        return CompetitionParticipant::query()->create([
            'competition_id' => $competition->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Registered,
            'registered_at' => now(),
        ]);
    }

    private function otherCompetition(): Competition
    {
        $source = Competition::query()->where('slug', 'microscope-100-challenge')->firstOrFail();

        return Competition::query()->create([
            'slug' => 'other-lab-challenge',
            'prerequisite_course_id' => $source->prerequisite_course_id,
            'required_photos' => 100,
            'photos_per_sample' => 2,
            'max_photos_per_sample' => 2,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => 'active',
            'title' => ['en' => 'Other', 'ar' => 'أخرى'],
        ]);
    }
}
