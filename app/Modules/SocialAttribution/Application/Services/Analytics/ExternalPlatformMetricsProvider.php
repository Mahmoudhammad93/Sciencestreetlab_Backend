<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Services\Analytics;

/**
 * External platform Views/Reach/Impressions.
 * Internal clicks/purchases never come from this provider.
 */
interface ExternalPlatformMetricsProvider
{
    /**
     * @return array{available: bool, views: ?int, reach: ?int, impressions: ?int, label: string}
     */
    public function metricsFor(string $platform): array;
}
