<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Services;

use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\AttributionSession;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\TrackingLink;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\TrackingLinkClick;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

final class RecordTrackingClick
{
    public function __construct(
        private readonly BotDetector $bots,
        private readonly AttributionHasher $hasher,
    ) {}

    /**
     * @param  array<string, string|null>  $utms
     */
    public function handle(
        TrackingLink $link,
        AttributionSession $session,
        Request $request,
        array $utms,
        bool $isBot,
    ): TrackingLinkClick {
        $now = CarbonImmutable::now();
        $visitorKey = $session->visitor_key;
        $day = $now->utc()->format('Y-m-d');
        $uniqueKey = $link->id.':'.$visitorKey.':'.$day;

        $base = [
            'tracking_link_id' => $link->id,
            'attribution_session_id' => $session->id,
            'visitor_key' => $visitorKey,
            'occurred_at' => $now,
            'is_bot' => $isBot,
            'referrer_host' => $this->referrerHost($request),
            'user_agent_hash' => $this->hasher->hash($request->userAgent()),
            'ip_hash' => $this->hasher->hash($request->ip()),
            'utm_source' => $utms['utm_source'] ?? null,
            'utm_medium' => $utms['utm_medium'] ?? null,
            'utm_campaign' => $utms['utm_campaign'] ?? null,
            'utm_content' => $utms['utm_content'] ?? null,
            'utm_term' => $utms['utm_term'] ?? null,
            'fbclid' => $this->clip($request->query('fbclid')),
            'gclid' => $this->clip($request->query('gclid')),
            'ttclid' => $this->clip($request->query('ttclid')),
            'request_path' => '/'.ltrim($request->path(), '/'),
            'metadata' => null,
            'created_at' => $now,
        ];

        if ($isBot) {
            return TrackingLinkClick::query()->create(array_merge($base, [
                'is_unique_human' => false,
                'unique_click_key' => null,
            ]));
        }

        try {
            return TrackingLinkClick::query()->create(array_merge($base, [
                'is_unique_human' => true,
                'unique_click_key' => $uniqueKey,
            ]));
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return TrackingLinkClick::query()->create(array_merge($base, [
                'is_unique_human' => false,
                'unique_click_key' => null,
            ]));
        }
    }

    private function referrerHost(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function clip(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $message = strtolower($e->getMessage());

        return $code === '1062'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
