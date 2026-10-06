<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Services\Analytics;

use Carbon\CarbonImmutable;

final class AnalyticsFilters
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $platform,
        public readonly string $attributionModel, // last_touch|first_touch
        public readonly string $rangeKey = '30d',
    ) {}

    public static function fromRange(
        string $rangeKey,
        ?string $platform = null,
        string $attributionModel = 'last_touch',
        ?string $customFrom = null,
        ?string $customTo = null,
    ): self {
        $now = CarbonImmutable::now();
        [$from, $to] = match ($rangeKey) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            '7d' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            '30d' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'month' => [$now->startOfMonth(), $now->endOfDay()],
            'all' => [CarbonImmutable::createFromTimestamp(0), $now->endOfDay()],
            'custom' => [
                $customFrom ? CarbonImmutable::parse($customFrom)->startOfDay() : $now->subDays(29)->startOfDay(),
                $customTo ? CarbonImmutable::parse($customTo)->endOfDay() : $now->endOfDay(),
            ],
            default => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
        };

        $platform = is_string($platform) && $platform !== '' && $platform !== 'all' ? $platform : null;
        $model = $attributionModel === 'first_touch' ? 'first_touch' : 'last_touch';

        return new self($from, $to, $platform, $model, $rangeKey === 'custom' ? 'custom' : $rangeKey);
    }
}
