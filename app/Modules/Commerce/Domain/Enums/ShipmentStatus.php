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

    public function isTerminalFailure(): bool
    {
        return $this === self::Cancelled || $this === self::Failed;
    }

    /**
     * Progress rank for the happy-path shipping journey (excludes activated / terminal failures).
     * Unknown does not advance progress.
     */
    public function progressRank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Created => 1,
            self::PickedUp => 2,
            self::InTransit => 3,
            self::OutForDelivery => 4,
            self::Delivered => 5,
            self::Cancelled, self::Failed, self::Unknown => -1,
        };
    }

    public function label(): string
    {
        return (string) __('shipping.status.'.$this->value);
    }
}
