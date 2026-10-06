<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Services\Analytics;

final class UnavailableExternalPlatformMetricsProvider implements ExternalPlatformMetricsProvider
{
    public function metricsFor(string $platform): array
    {
        return [
            'available' => false,
            'views' => null,
            'reach' => null,
            'impressions' => null,
            'label' => 'unavailable',
        ];
    }
}
