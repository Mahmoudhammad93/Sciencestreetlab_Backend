<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Modules\Commerce\Application\Services\BostaWebhookService;
use App\Modules\Commerce\Application\Services\OrderFulfillmentService;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('updateBostaShippingStatus')
                ->label('Update Bosta shipping')
                ->icon('heroicon-o-truck')
                ->color('warning')
                ->visible(fn (): bool => $this->recordHasBostaShipment())
                ->form([
                    Forms\Components\Placeholder::make('current_shipping')
                        ->label('Current shipping status')
                        ->content(function (): string {
                            /** @var Order $order */
                            $order = $this->getRecord();
                            $shipment = $order->bostaShipment;
                            if ($shipment === null) {
                                return 'No Bosta shipment';
                            }

                            $status = $shipment->status instanceof ShipmentStatus
                                ? $shipment->status->label()
                                : (string) $shipment->status;

                            return sprintf(
                                '%s · tracking: %s · external: %s',
                                $status,
                                $shipment->tracking_number ?: '—',
                                $shipment->external_shipment_id ?: '—',
                            );
                        }),
                    Forms\Components\Select::make('status')
                        ->label('New Bosta status')
                        ->helperText('Delivered unlocks course access. Delivered cannot be downgraded.')
                        ->options([
                            'Created' => 'Shipment Created',
                            'Picked Up' => 'Picked Up',
                            'In Transit' => 'In Transit',
                            'Out for Delivery' => 'Out for Delivery',
                            'Delivered' => 'Delivered (unlocks course)',
                            'Cancelled' => 'Cancelled',
                            'Failed' => 'Failed',
                        ])
                        ->required()
                        ->native(false),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    /** @var Order $order */
                    $order = $this->getRecord();
                    $order->loadMissing('bostaShipment');
                    $shipment = $order->bostaShipment;

                    if ($shipment === null || blank($shipment->external_shipment_id)) {
                        Notification::make()
                            ->title('No Bosta shipment on this order')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $updated = app(BostaWebhookService::class)->handle([
                            'external_shipment_id' => $shipment->external_shipment_id,
                            'status' => (string) $data['status'],
                        ]);

                        $this->refreshFormData(['status', 'notes']);
                        $this->record->refresh();

                        $label = $updated->status instanceof ShipmentStatus
                            ? $updated->status->label()
                            : (string) $updated->status;

                        Notification::make()
                            ->title('Bosta shipping updated')
                            ->body('Status is now: '.$label)
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Failed to update Bosta shipping')
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

    private function recordHasBostaShipment(): bool
    {
        /** @var Order $order */
        $order = $this->getRecord();
        $order->loadMissing('bostaShipment');

        return $order->bostaShipment !== null;
    }
}
