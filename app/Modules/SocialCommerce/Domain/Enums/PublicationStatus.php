<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Domain\Enums;

enum PublicationStatus: string
{
    case NotPublished = 'not_published';
    case Ready = 'ready';
    case Published = 'published';
    case NeedsAttention = 'needs_attention';
    case Blocked = 'blocked';

    public function label(): string
    {
        return (string) __('sales_channels.publication.'.$this->value);
    }
}
