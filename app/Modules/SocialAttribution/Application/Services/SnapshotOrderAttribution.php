<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Services;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\SocialAttribution\Domain\Data\AttributionContext;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\AttributionSession;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\OrderAttribution;
use Illuminate\Support\Facades\Log;
use Throwable;

class SnapshotOrderAttribution
{
    public function handle(Order $order, ?AttributionContext $context): void
    {
        try {
            $this->snapshot($order, $context);
        } catch (Throwable $e) {
            Log::error('Order attribution snapshot failed (checkout continues)', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function snapshot(Order $order, ?AttributionContext $context): void
    {
        if (OrderAttribution::query()->where('order_id', $order->id)->exists()) {
            return;
        }

        $session = null;
        if ($context?->hasVisitor()) {
            $session = AttributionSession::query()
                ->where('visitor_key', $context->visitorKey)
                ->first();
        }

        $isOpen = $session !== null && $session->isOpen() && ! empty($session->last_touch);
        $windowDays = max(1, (int) config('social_attribution.window_days', 30));

        OrderAttribution::query()->create([
            'order_id' => $order->id,
            'order_uuid' => $order->uuid,
            'visitor_key' => $context?->visitorKey,
            'attribution_session_id' => $session?->id,
            'converting_platform' => $isOpen ? $session?->last_platform : null,
            'converting_campaign_id' => $isOpen ? $session?->last_campaign_id : null,
            'converting_content_id' => $isOpen ? $session?->last_content_id : null,
            'converting_tracking_link_id' => $isOpen ? $session?->last_tracking_link_id : null,
            'converting_code' => $isOpen ? data_get($session?->last_touch, 'tracking_code') : null,
            'first_touch' => $isOpen ? ($session?->first_touch ?? []) : [],
            'last_touch' => $isOpen ? ($session?->last_touch ?? []) : [],
            'attribution_model' => (string) config('social_attribution.attribution_model', 'last_touch_v1'),
            'window_days' => $windowDays,
            'is_attributed' => $isOpen,
            'captured_at' => now(),
        ]);
    }
}
