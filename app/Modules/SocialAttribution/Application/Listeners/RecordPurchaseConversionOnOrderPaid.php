<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Listeners;

use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\SocialAttribution\Application\Jobs\RecordPurchaseConversionJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued, fail-soft, afterCommit. Never affects payment or Bosta.
 */
final class RecordPurchaseConversionOnOrderPaid implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public function handle(OrderPaid $event): void
    {
        try {
            RecordPurchaseConversionJob::dispatch($event->order->id);
        } catch (Throwable $e) {
            Log::error('Failed to queue purchase conversion job', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(OrderPaid $event, Throwable $e): void
    {
        Log::error('RecordPurchaseConversionOnOrderPaid failed', [
            'order_id' => $event->order->id,
            'error' => $e->getMessage(),
        ]);
    }
}
