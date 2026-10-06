<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Modules\Commerce\Application\Services\BostaShipmentReconciliationService;
use App\Modules\Commerce\Application\Services\OrderFulfillmentService;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        /** @var Order $record */
        $record = parent::resolveRecord($key);
        $record->loadMissing(['user', 'items', 'bostaShipment']);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('syncBostaStatus')
                ->label(__('admin.orders.actions.sync_bosta'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->recordHasSyncableBostaShipment())
                ->requiresConfirmation()
                ->modalHeading(__('admin.orders.actions.sync_bosta'))
                ->modalDescription(__('admin.orders.actions.sync_bosta_help'))
                ->action(function (): void {
                    /** @var Order $order */
                    $order = $this->getRecord();
                    $order->loadMissing('bostaShipment');
                    $shipment = $order->bostaShipment;

                    if ($shipment === null || blank($shipment->external_shipment_id)) {
                        Notification::make()
                            ->title(__('admin.orders.notifications.no_bosta'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $previous = $shipment->status instanceof ShipmentStatus
                        ? $shipment->status->label()
                        : (string) $shipment->status;

                    try {
                        $result = app(BostaShipmentReconciliationService::class)->reconcileShipment($shipment);
                        $updated = $result->shipment ?? $shipment->fresh();
                        $label = $updated?->status instanceof ShipmentStatus
                            ? $updated->status->label()
                            : (string) ($updated?->status ?? '');

                        $this->refreshFormData(['status', 'notes']);
                        $this->record->refresh();

                        Notification::make()
                            ->title(__('admin.orders.notifications.bosta_synced'))
                            ->body(__('admin.orders.notifications.bosta_synced_body', [
                                'previous' => $previous,
                                'current' => $label,
                                'provider' => (string) ($result->providerStatus ?? '—'),
                            ]))
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title(__('admin.orders.notifications.bosta_failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  Order  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Order $record */
        return app(OrderFulfillmentService::class)->applyAdminUpdate($record, [
            'status' => (string) ($data['status'] ?? $record->status),
            'notes' => $data['notes'] ?? $record->notes,
        ]);
    }

    private function recordHasSyncableBostaShipment(): bool
    {
        /** @var Order $order */
        $order = $this->getRecord();
        $order->loadMissing('bostaShipment');

        return $order->bostaShipment !== null
            && filled($order->bostaShipment->external_shipment_id);
    }
}
