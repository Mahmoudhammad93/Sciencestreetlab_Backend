<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
