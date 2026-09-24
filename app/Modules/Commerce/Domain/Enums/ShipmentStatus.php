<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum ShipmentStatus: string
{
    case Pending = 'pending';
    case Created = 'created';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function isTerminalDelivered(): bool
    {
        return $this === self::Delivered;
    }
}
