<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Services;

use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\AttributionSession;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\TrackingLink;
use Carbon\CarbonImmutable;

final class AttributionSessionManager
{
    public function resolveOrCreate(
        string $visitorKey,
        ?string $cartSessionId = null,
        ?int $authenticatedUserId = null,
    ): AttributionSession {
        $windowDays = max(1, (int) config('social_attribution.window_days', 30));
        $now = CarbonImmutable::now();

        $session = AttributionSession::query()->where('visitor_key', $visitorKey)->first();

        if ($session === null) {
            return AttributionSession::query()->create([
                'visitor_key' => $visitorKey,
                'cart_session_id' => $cartSessionId,
                'user_id' => $authenticatedUserId,
                'window_starts_at' => $now,
                'window_ends_at' => $now->addDays($windowDays),
                'first_touch' => [],
                'last_touch' => [],
            ]);
        }

        $updates = [];
        if ($cartSessionId !== null && $session->cart_session_id !== $cartSessionId) {
            $updates['cart_session_id'] = $cartSessionId;
        }
        // Never take user_id from Filament/web — only explicit Sanctum-authenticated id.
        if ($authenticatedUserId !== null && $session->user_id === null) {
            $updates['user_id'] = $authenticatedUserId;
        }

        if ($session->window_ends_at === null || $session->window_ends_at->isPast()) {
            $updates['window_starts_at'] = $now;
            $updates['window_ends_at'] = $now->addDays($windowDays);
            $updates['first_tracking_link_id'] = null;
            $updates['first_campaign_id'] = null;
            $updates['first_content_id'] = null;
            $updates['first_platform'] = null;
            $updates['first_touched_at'] = null;
            $updates['first_touch'] = [];
            $updates['last_tracking_link_id'] = null;
            $updates['last_campaign_id'] = null;
            $updates['last_content_id'] = null;
            $updates['last_platform'] = null;
            $updates['last_touched_at'] = null;
            $updates['last_touch'] = [];
        }

        if ($updates !== []) {
            $session->update($updates);
        }

        return $session->fresh() ?? $session;
    }

    /**
     * @param  array<string, mixed>  $touchPayload
     */
    public function applyHumanTouch(AttributionSession $session, TrackingLink $link, array $touchPayload): AttributionSession
    {
        $now = CarbonImmutable::now();
        if ($session->window_ends_at === null || $session->window_ends_at->isPast()) {
            $session = $this->resolveOrCreate($session->visitor_key, $session->cart_session_id, $session->user_id);
        }

        $payload = array_merge($touchPayload, [
            'tracking_link_id' => $link->id,
            'tracking_code' => $link->code,
            'platform' => $link->platform->value,
            'campaign_id' => $link->campaign_id,
            'content_id' => $link->social_content_id,
            'touched_at' => $now->toIso8601String(),
        ]);

        $updates = [
            'last_tracking_link_id' => $link->id,
            'last_campaign_id' => $link->campaign_id,
            'last_content_id' => $link->social_content_id,
            'last_platform' => $link->platform->value,
            'last_touched_at' => $now,
            'last_touch' => $payload,
            'window_ends_at' => $now->addDays(max(1, (int) config('social_attribution.window_days', 30))),
        ];

        if ($session->first_touched_at === null || empty($session->first_touch)) {
            $updates['first_tracking_link_id'] = $link->id;
            $updates['first_campaign_id'] = $link->campaign_id;
            $updates['first_content_id'] = $link->social_content_id;
            $updates['first_platform'] = $link->platform->value;
            $updates['first_touched_at'] = $now;
            $updates['first_touch'] = $payload;
        }

        $session->update($updates);

        return $session->fresh() ?? $session;
    }
}
