<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompetitionSubmissionResource\Pages;

use App\Filament\Resources\CompetitionSubmissionResource;
use App\Models\User;
use App\Modules\Competition\Application\Services\SubmissionReviewService;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewCompetitionSubmission extends ViewRecord
{
    protected static string $resource = CompetitionSubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label(__('admin.competition_submissions.actions.approve'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === SubmissionStatus::Pending)
                ->action(function (): void {
                    /** @var User $reviewer */
                    $reviewer = auth()->user();
                    /** @var CompetitionSubmission $record */
                    $record = $this->record;
                    app(SubmissionReviewService::class)->approve($reviewer, $record);
                    Notification::make()->title(__('admin.competition_submissions.notifications.approved'))->success()->send();
                    $this->refreshFormData(['status', 'reviewed_at', 'rejection_reason']);
                }),
            Actions\Action::make('reject')
                ->label(__('admin.competition_submissions.actions.reject'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === SubmissionStatus::Pending)
                ->form([
                    Forms\Components\Textarea::make('rejection_reason')->label(__('admin.competition_submissions.fields.rejection_reason'))->required(),
                    Forms\Components\Textarea::make('notes')->label(__('admin.competition_submissions.fields.notes')),
                ])
                ->action(function (array $data): void {
                    /** @var User $reviewer */
                    $reviewer = auth()->user();
                    /** @var CompetitionSubmission $record */
                    $record = $this->record;
                    app(SubmissionReviewService::class)->reject(
                        $reviewer,
                        $record,
                        $data['rejection_reason'],
                        $data['notes'] ?? null
                    );
                    Notification::make()->title(__('admin.competition_submissions.notifications.rejected'))->warning()->send();
                    $this->refreshFormData(['status', 'reviewed_at', 'rejection_reason']);
                }),
            Actions\Action::make('request_revision')
                ->label(__('admin.competition_submissions.actions.request_revision'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === SubmissionStatus::Pending)
                ->form([
                    Forms\Components\Textarea::make('notes')->label(__('admin.competition_submissions.fields.notes'))->required(),
                ])
                ->action(function (array $data): void {
                    /** @var User $reviewer */
                    $reviewer = auth()->user();
                    /** @var CompetitionSubmission $record */
                    $record = $this->record;
                    app(SubmissionReviewService::class)->requestRevision($reviewer, $record, $data['notes']);
                    Notification::make()->title(__('admin.competition_submissions.notifications.revision_requested'))->info()->send();
                    $this->refreshFormData(['status', 'reviewed_at', 'rejection_reason']);
                }),
        ];
    }
}
