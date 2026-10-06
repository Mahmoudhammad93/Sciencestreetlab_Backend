<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Jobs;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\SocialAttribution\Domain\Enums\ConversionEventName;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\AttributionConversion;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\OrderAttribution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecordPurchaseConversionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $orderId) {}

    public function handle(): void
    {
        try {
            $this->record();
        } catch (Throwable $e) {
            Log::error('RecordPurchaseConversionJob failed (payment unaffected)', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
            // Fail-soft: do not retry forever on logic errors; release only unexpected DB blips.
            if ($e instanceof QueryException && ! $this->isDuplicate($e)) {
                throw $e;
            }
        }
    }

    private function record(): void
    {
        $order = Order::query()->with('items')->find($this->orderId);
        if ($order === null || $order->paid_at === null) {
            return;
        }

        $eventId = AttributionConversion::purchaseEventId((string) $order->uuid);
        if (AttributionConversion::query()->where('event_id', $eventId)->exists()) {
            return;
        }

        $attribution = OrderAttribution::query()->where('order_id', $order->id)->first();
        $isAttributed = (bool) ($attribution?->is_attributed);

        $contentIds = $order->items->pluck('product_id')->filter()->values()->all();

        try {
            AttributionConversion::query()->create([
                'order_id' => $order->id,
                'order_uuid' => $order->uuid,
                'event_name' => ConversionEventName::Purchase->value,
                'event_id' => $eventId,
                'platform' => $isAttributed ? $attribution?->converting_platform : null,
                'campaign_id' => $isAttributed ? $attribution?->converting_campaign_id : null,
                'content_id' => $isAttributed ? $attribution?->converting_content_id : null,
                'tracking_link_id' => $isAttributed ? $attribution?->converting_tracking_link_id : null,
                'value' => $order->total,
                'currency' => $order->currency,
                'occurred_at' => $order->paid_at,
                'is_attributed' => $isAttributed,
                'payload_snapshot' => [
                    'order_number' => $order->order_number,
                    'converting_code' => $attribution?->converting_code,
                    'first_touch' => $attribution?->first_touch ?? [],
                    'last_touch' => $attribution?->last_touch ?? [],
                    'content_ids' => $contentIds,
                    'attribution_model' => $attribution?->attribution_model,
                ],
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return;
            }
            throw $e;
        }
    }

    private function isDuplicate(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $message = strtolower($e->getMessage());

        return $code === '1062'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
