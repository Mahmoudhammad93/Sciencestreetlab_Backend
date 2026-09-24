<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Shipping\Bosta;

use App\Modules\Commerce\Domain\Enums\ShipmentStatus;

/**
 * Maps provider status strings to internal ShipmentStatus.
 * Unknown provider values become Unknown (never invent delivery).
 */
final class BostaStatusMapper
{
    public function map(?string $providerStatus): ShipmentStatus
    {
        if ($providerStatus === null || trim($providerStatus) === '') {
            return ShipmentStatus::Unknown;
        }

        $normalized = strtolower(trim($providerStatus));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'pending', 'new', 'created', 'pickup_requested', 'awaiting_pickup' => ShipmentStatus::Created,
            'picked_up', 'pickedup', 'collected' => ShipmentStatus::PickedUp,
            'in_transit', 'intransit', 'shipped', 'on_the_way' => ShipmentStatus::InTransit,
            'out_for_delivery', 'outfordelivery' => ShipmentStatus::OutForDelivery,
            'delivered', 'delivery_confirmed', 'completed' => ShipmentStatus::Delivered,
            'cancelled', 'canceled' => ShipmentStatus::Cancelled,
            'failed', 'returned', 'rejected' => ShipmentStatus::Failed,
            default => ShipmentStatus::Unknown,
        };
    }
}
