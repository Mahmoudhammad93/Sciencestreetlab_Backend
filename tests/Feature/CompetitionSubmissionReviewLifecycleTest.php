<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Competition\Application\Services\SubmissionReviewService;
use App\Modules\Competition\Domain\Enums\ParticipantStatus;
use App\Modules\Competition\Domain\Enums\ReviewAction;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use App\Modules\Competition\Infrastructure\Persistence\Models\SubmissionReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CompetitionSubmissionReviewLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed();
    }

    public function test_participant_sees_pending_then_approved_status(): void
    {
        [$user] = $this->registeredParticipant();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 1,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.can_replace', false)
            ->assertJsonMissingPath('data.reviewed_by');

        $submission = CompetitionSubmission::query()->firstOrFail();
        app(SubmissionReviewService::class)->approve($this->admin(), $submission);

        $this->getJson('/api/v1/submissions?competition=microscope-100-challenge')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.0.can_replace', false)
            ->assertJsonPath('data.0.sample_number', 1)
            ->assertJsonPath('data.0.photo_index', 1)
            ->assertJsonMissingPath('data.0.reviewed_by');
    }

    public function test_participant_sees_rejected_status_and_reason_then_can_replace(): void
    {
        [$user] = $this->registeredParticipant();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 2,
            'photo_index' => 1,
            'description' => 'Original B',
            'photo' => UploadedFile::fake()->image('b.jpg', 200, 200),
        ])->assertCreated();

        $original = CompetitionSubmission::query()->firstOrFail();
        $originalUuid = $original->uuid;
        $originalId = $original->id;
        app(SubmissionReviewService::class)->reject($this->admin(), $original, 'Image is blurry');

        $listed = $this->getJson('/api/v1/submissions?competition=microscope-100-challenge')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.rejection_reason', 'Image is blurry')
            ->assertJsonPath('data.0.can_replace', true)
            ->json('data.0');

        $this->assertNotNull($listed['reviewed_at']);
        $this->assertArrayNotHasKey('reviewed_by', $listed);

        $this->putJson('/api/v1/submissions/'.$originalUuid, [
            'description' => 'Replacement C',
            'photo' => UploadedFile::fake()->image('c.jpg', 200, 200),
        ])->assertOk()
            ->assertJsonPath('data.uuid', $originalUuid)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.sample_number', 2)
            ->assertJsonPath('data.photo_index', 1)
            ->assertJsonPath('data.can_replace', false)
            ->assertJsonPath('data.rejection_reason', null);

        $this->assertSame(1, CompetitionSubmission::query()->count());
        $replaced = CompetitionSubmission::query()->firstOrFail();
        $this->assertSame($originalId, $replaced->id);
        $this->assertSame(SubmissionStatus::Pending, $replaced->status);
        $this->assertNull($replaced->reviewed_at);
        $this->assertNull($replaced->reviewed_by);
        $this->assertSame(1, SubmissionReview::query()->where('submission_id', $originalId)->where('action', ReviewAction::Reject)->count());

        app(SubmissionReviewService::class)->approve($this->admin(), $replaced->fresh());

        $this->getJson('/api/v1/submissions?competition=microscope-100-challenge')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.0.can_replace', false);
        $this->assertSame(1, CompetitionSubmission::query()->count());
    }

    public function test_historical_null_slot_rejected_photo_can_be_replaced_in_place(): void
    {
        [$user, $participant] = $this->registeredParticipant();

        $historical = CompetitionSubmission::query()->create([
            'participant_id' => $participant->id,
            'sample_number' => null,
            'photo_index' => null,
            'status' => SubmissionStatus::Rejected,
            'rejection_reason' => 'Imported reject',
            'description' => 'WordPress photo',
            'submitted_at' => now()->subYear(),
        ]);

        $this->getJson('/api/v1/submissions?competition=microscope-100-challenge')
            ->assertOk()
            ->assertJsonPath('data.0.sample_number', null)
            ->assertJsonPath('data.0.photo_index', null)
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.can_replace', true);

        $this->putJson('/api/v1/submissions/'.$historical->uuid, [
            'photo' => UploadedFile::fake()->image('new.jpg', 200, 200),
        ])->assertOk()
            ->assertJsonPath('data.uuid', $historical->uuid)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.sample_number', null)
            ->assertJsonPath('data.photo_index', null);

        $historical->refresh();
        $this->assertNull($historical->sample_number);
        $this->assertNull($historical->photo_index);
        $this->assertSame('WordPress photo', $historical->description);
        $this->assertSame(1, CompetitionSubmission::query()->count());
    }

    public function test_cannot_replace_approved_or_pending_submission(): void
    {
        $this->registeredParticipant();

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 3,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('p.jpg', 200, 200),
        ])->assertCreated();

        $pending = CompetitionSubmission::query()->firstOrFail();
        $this->putJson('/api/v1/submissions/'.$pending->uuid, [
            'photo' => UploadedFile::fake()->image('again.jpg', 200, 200),
        ])->assertStatus(422)->assertJsonPath('code', 'slot_already_submitted');

        app(SubmissionReviewService::class)->approve($this->admin(), $pending->fresh());

        $this->putJson('/api/v1/submissions/'.$pending->uuid, [
            'photo' => UploadedFile::fake()->image('after-approve.jpg', 200, 200),
        ])->assertStatus(422)->assertJsonPath('code', 'slot_already_submitted');

        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 3,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('slot.jpg', 200, 200),
        ])->assertStatus(422)->assertJsonPath('code', 'slot_already_submitted');
    }

    public function test_cannot_replace_another_users_or_other_competition_submission(): void
    {
        [$userA] = $this->registeredParticipant();
        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 5,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertCreated();
        $submissionA = CompetitionSubmission::query()->firstOrFail();
        app(SubmissionReviewService::class)->reject($this->admin(), $submissionA, 'No');

        $userB = User::factory()->create();
        $this->registerParticipant($userB);
        Sanctum::actingAs($userB);

        $this->putJson('/api/v1/submissions/'.$submissionA->uuid, [
            'photo' => UploadedFile::fake()->image('steal.jpg', 200, 200),
        ])->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->getJson('/api/v1/submissions?competition=microscope-100-challenge')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $other = $this->otherCompetition();
        $otherParticipant = CompetitionParticipant::query()->create([
            'competition_id' => $other->id,
            'user_id' => $userB->id,
            'status' => ParticipantStatus::Registered,
            'registered_at' => now(),
        ]);
        $otherSubmission = CompetitionSubmission::query()->create([
            'participant_id' => $otherParticipant->id,
            'sample_number' => 1,
            'photo_index' => 1,
            'status' => SubmissionStatus::Rejected,
            'rejection_reason' => 'Other',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($userA);
        $this->putJson('/api/v1/submissions/'.$otherSubmission->uuid, [
            'photo' => UploadedFile::fake()->image('cross.jpg', 200, 200),
        ])->assertForbidden();
    }

    public function test_cannot_replace_after_competition_deadline(): void
    {
        $this->registeredParticipant();
        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 6,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('late.jpg', 200, 200),
        ])->assertCreated();
        $submission = CompetitionSubmission::query()->firstOrFail();
        app(SubmissionReviewService::class)->reject($this->admin(), $submission, 'Retry');

        Competition::query()->where('slug', 'microscope-100-challenge')->update([
            'status' => 'completed',
            'ends_at' => now()->subDay(),
        ]);

        $this->putJson('/api/v1/submissions/'.$submission->uuid, [
            'photo' => UploadedFile::fake()->image('too-late.jpg', 200, 200),
        ])->assertStatus(422)->assertJsonPath('code', 'competition_not_active');
    }

    public function test_duplicate_replacement_keeps_a_single_pending_row(): void
    {
        $this->registeredParticipant();
        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 8,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('first.jpg', 200, 200),
        ])->assertCreated();
        $submission = CompetitionSubmission::query()->firstOrFail();
        app(SubmissionReviewService::class)->reject($this->admin(), $submission, 'Retry');

        $this->putJson('/api/v1/submissions/'.$submission->uuid, [
            'photo' => UploadedFile::fake()->image('v2.jpg', 200, 200),
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        $this->putJson('/api/v1/submissions/'.$submission->uuid, [
            'photo' => UploadedFile::fake()->image('v3.jpg', 200, 200),
        ])->assertStatus(422)->assertJsonPath('code', 'slot_already_submitted');

        $this->assertSame(1, CompetitionSubmission::query()->count());
        $this->assertSame(SubmissionStatus::Pending, CompetitionSubmission::query()->firstOrFail()->status);
    }

    public function test_invalid_replacement_image_is_rejected(): void
    {
        $this->registeredParticipant();
        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 9,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('ok.jpg', 200, 200),
        ])->assertCreated();
        $submission = CompetitionSubmission::query()->firstOrFail();
        app(SubmissionReviewService::class)->reject($this->admin(), $submission, 'Retry');

        $this->putJson('/api/v1/submissions/'.$submission->uuid, [
            'photo' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_review_service_rejects_non_pending_and_requires_reason(): void
    {
        $this->registeredParticipant();
        $this->postJson('/api/v1/competitions/microscope-100-challenge/submissions', [
            'sample_number' => 10,
            'photo_index' => 1,
            'photo' => UploadedFile::fake()->image('ok.jpg', 200, 200),
        ])->assertCreated();
        $submission = CompetitionSubmission::query()->firstOrFail();
        $admin = $this->admin();
        app(SubmissionReviewService::class)->approve($admin, $submission);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('submission_not_pending');
        app(SubmissionReviewService::class)->reject($admin, $submission->fresh(), 'Too late');
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

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
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
