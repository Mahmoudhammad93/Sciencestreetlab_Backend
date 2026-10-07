<?php

declare(strict_types=1);

namespace App\Filament\Resources\ErrorIncidentResource\Pages;

use App\Filament\Resources\ErrorIncidentResource;
use App\Modules\Observability\Infrastructure\Persistence\Models\ErrorIncident;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewErrorIncident extends ViewRecord
{
    protected static string $resource = ErrorIncidentResource::class;

    public ?string $debugClipboard = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('copyDebugInfo')
                ->label(__('admin.error_incidents.actions.copy_debug'))
                ->icon('heroicon-o-clipboard-document')
                ->action(function (): void {
                    $record = $this->getRecord();
                    $this->debugClipboard = $record instanceof ErrorIncident
                        ? $record->toDebugClipboard()
                        : 'N/A';
                    $this->js('window.navigator?.clipboard?.writeText('.json_encode($this->debugClipboard).')');
                    Notification::make()
                        ->title(__('admin.error_incidents.notifications.copied'))
                        ->success()
                        ->send();
                }),
            Actions\Action::make('resolve')
                ->label(__('admin.error_incidents.actions.resolve'))
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord() instanceof ErrorIncident && ! $this->getRecord()->isResolved())
                ->action(function (): void {
                    $record = $this->getRecord();
                    if ($record instanceof ErrorIncident) {
                        $id = auth()->id();
                        $record->markResolved(is_numeric($id) ? (int) $id : null);
                    }
                    Notification::make()
                        ->title(__('admin.error_incidents.notifications.resolved'))
                        ->success()
                        ->send();
                }),
            Actions\Action::make('reopen')
                ->label(__('admin.error_incidents.actions.reopen'))
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->getRecord() instanceof ErrorIncident && $this->getRecord()->isResolved())
                ->action(function (): void {
                    $record = $this->getRecord();
                    if ($record instanceof ErrorIncident) {
                        $record->reopen();
                    }
                    Notification::make()
                        ->title(__('admin.error_incidents.notifications.reopened'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
