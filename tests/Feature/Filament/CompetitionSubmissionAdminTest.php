<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\CompetitionSubmissionResource\Pages\ListCompetitionSubmissions;
use App\Filament\Resources\CompetitionSubmissionResource\Pages\ViewCompetitionSubmission;
use App\Models\User;
use App\Modules\Competition\Domain\Enums\ParticipantStatus;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use App\Modules\Competition\Application\Services\SubmissionReviewService;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use App\Modules\Competition\Infrastructure\Persistence\Models\SubmissionReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CompetitionSubmissionAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_review_queue_shows_sample_photo_participant_and_description(): void
    {
        $admin = $this->admin();
        $participant = $this->participant();
        CompetitionSubmission::query()->create([
            'participant_id' => $participant->id,
            'sample_number' => 12,
            'photo_index' => 2,
            'status' => SubmissionStatus::Pending,
            'description' => 'Leaf stomata',
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/competition-submissions')
            ->assertOk()
            ->assertSee(__('admin.competition_submissions.table.sample_number'), false)
            ->assertSee(__('admin.competition_submissions.table.photo_index'), false);

        Livewire::actingAs($admin)
            ->test(ListCompetitionSubmissions::class)
            ->assertSuccessful()
            ->assertSee('#12')
            ->assertSee('#2')
            ->assertSee('Leaf stomata')
            ->assertSee($participant->user->name)
            ->assertSee($participant->user->email);
    }

    public function test_historical_nullable_slots_do_not_crash_admin(): void
    {
        $admin = $this->admin();
        $participant = $this->participant();
        CompetitionSubmission::query()->create([
            'participant_id' => $participant->id,
            'sample_number' => null,
            'photo_index' => null,
            'status' => SubmissionStatus::Pending,
            'description' => 'Migrated WordPress row',
            'submitted_at' => now()->subYear(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListCompetitionSubmissions::class)
            ->assertSuccessful()
            ->assertSee('Migrated WordPress row')
            ->assertSee($participant->user->name);
    }

    public function test_admin_can_filter_by_sample_number_and_open_view(): void
    {
        $admin = $this->admin();
        $participant = $this->participant();
        $keep = CompetitionSubmission::query()->create([
            'participant_id' => $participant->id,
            'sample_number' => 8,
            'photo_index' => 1,
            'status' => SubmissionStatus::Pending,
            'description' => 'Keep this sample',
            'submitted_at' => now(),
        ]);
        CompetitionSubmission::query()->create([
            'participant_id' => $participant->id,
            'sample_number' => 9,
            'photo_index' => 1,
            'status' => SubmissionStatus::Pending,
            'description' => 'Hide this sample',
            'submitted_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListCompetitionSubmissions::class)
            ->filterTable('sample_number', ['sample_number' => 8])
            ->assertSee('Keep this sample')
            ->assertDontSee('Hide this sample');

        Livewire::actingAs($admin)
            ->test(ViewCompetitionSubmission::class, ['record' => $keep->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('#8')
            ->assertSee('Keep this sample');
    }

    public function test_admin_can_approve_and_reject_from_review_queue(): void
    {
        $admin = $this->admin();
        $participant = $this->participant();
        $approve = CompetitionSubmission::query()->create([
            'participant_id' => $participant->id,
            'sample_number' => 1,
            'photo_index' => 1,
            'status' => SubmissionStatus::Pending,
            'description' => 'Approve me',
            'submitted_at' => now(),
        ]);
        $reject = CompetitionSubmission::query()->create([
            'participant_id' => $participant->id,
            'sample_number' => 2,
            'photo_index' => 1,
            'status' => SubmissionStatus::Pending,
            'description' => 'Reject me',
            'submitted_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListCompetitionSubmissions::class)
            ->callTableAction('approve', $approve)
            ->assertHasNoTableActionErrors();

        $this->assertSame(SubmissionStatus::Approved, $approve->fresh()->status);
        $this->assertNotNull($approve->fresh()->reviewed_at);
        $this->assertSame($admin->id, $approve->fresh()->reviewed_by);

        Livewire::actingAs($admin)
            ->test(ListCompetitionSubmissions::class)
            ->callTableAction('reject', $reject, data: [
                'rejection_reason' => 'Out of focus',
                'notes' => 'Retake with better light',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(SubmissionStatus::Rejected, $reject->fresh()->status);
        $this->assertSame('Out of focus', $reject->fresh()->rejection_reason);
    }

    public function test_admin_sees_replacement_history_after_reject_and_reupload(): void
    {
        $admin = $this->admin();
        $participant = $this->participant();
        $submission = CompetitionSubmission::query()->create([
            'participant_id' => $participant->id,
            'sample_number' => 12,
            'photo_index' => 1,
            'status' => SubmissionStatus::Pending,
            'description' => 'Needs replacement',
            'submitted_at' => now(),
        ]);

        app(SubmissionReviewService::class)->reject($admin, $submission, 'Too dark');
        $submission->update([
            'status' => SubmissionStatus::Pending,
            'rejection_reason' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'submitted_at' => now(),
        ]);

        $this->assertTrue($submission->fresh()->isReplacementPendingReview());
        $this->assertSame(1, SubmissionReview::query()->where('submission_id', $submission->id)->count());

        Livewire::actingAs($admin)
            ->test(ListCompetitionSubmissions::class)
            ->assertSuccessful()
            ->assertSee(__('admin.competition_submissions.replacement_pending'), false);

        Livewire::actingAs($admin)
            ->test(ViewCompetitionSubmission::class, ['record' => $submission->getRouteKey()])
            ->assertSuccessful()
            ->assertSee(__('admin.competition_submissions.fields.review_history'), false)
            ->assertSee(__('admin.competition_submissions.replacement_pending'), false);
    }

    public function test_non_admin_cannot_open_review_queue(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/competition-submissions')
            ->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function participant(): CompetitionParticipant
    {
        $user = User::factory()->create([
            'name' => 'Review Student',
            'email' => 'review-student@example.test',
        ]);
        $competition = Competition::query()->where('slug', 'microscope-100-challenge')->firstOrFail();

        return CompetitionParticipant::query()->create([
            'competition_id' => $competition->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Registered,
            'registered_at' => now(),
        ]);
    }
}
