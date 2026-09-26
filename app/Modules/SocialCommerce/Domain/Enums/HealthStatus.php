<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Domain\Enums;

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case NeedsAttention = 'needs_attention';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return (string) __('sales_channels.health.'.$this->value);
    }
}
