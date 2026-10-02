<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Shipping\Bosta;

use App\Modules\Commerce\Domain\Enums\ShipmentStatus;

/**
 * Maps official Bosta numeric state codes (and legacy string aliases) to
 * internal ShipmentStatus for outbound SEND / Deliver (type=10) orders.
 *
 * @see https://docs.bosta.co/docs/how-to/get-delivery-status-via-webhook/
 */
final class BostaStatusMapper
{
    /**
     * Official SEND/Deliver (type 10) mapping.
     * Unknown codes → Unknown (never fulfill).
     */
    public function map(?string $providerStatus, ?string $deliveryType = 'SEND'): ShipmentStatus
    {
        if ($providerStatus === null || trim($providerStatus) === '') {
            return ShipmentStatus::Unknown;
        }

        $raw = trim($providerStatus);
        $type = strtoupper(trim((string) ($deliveryType ?? 'SEND')));

        if (ctype_digit($raw) || (is_numeric($raw) && (string) (int) $raw === $raw)) {
            return $this->mapNumericState((int) $raw, $type);
        }

        return $this->mapLegacyString($raw);
    }

    public function mapNumericState(int $code, string $deliveryType = 'SEND'): ShipmentStatus
    {
        $isSendLike = in_array($deliveryType, ['SEND', 'FXF_SEND', ''], true)
            || str_starts_with($deliveryType, 'SEND');

        return match ($code) {
            // Pickup requested / New
            10 => ShipmentStatus::Created,
            // Waiting for route (Cash Collection)
            11 => ShipmentStatus::Created,
            // Route Assigned
            20 => ShipmentStatus::Created,
            // Picked up from business (Send, Exchange)
            21 => ShipmentStatus::PickedUp,
            // Picking up from consignee (CRP, Exchange)
            22 => ShipmentStatus::OutForDelivery,
            // Picked up from consignee (CRP, Exchange)
            23 => ShipmentStatus::PickedUp,
            // Received at warehouse
            24 => ShipmentStatus::InTransit,
            // Fulfilled (Fulfillment)
            25 => ShipmentStatus::InTransit,
            // In transit between Hubs
            30 => ShipmentStatus::InTransit,
            // Picking up (Cash Collection)
            40 => ShipmentStatus::OutForDelivery,
            // 41: SEND → out for delivery; CRP/RTO/Exchange → return path (treat as in transit)
            41 => $isSendLike ? ShipmentStatus::OutForDelivery : ShipmentStatus::InTransit,
            // Delivered — ONLY for Send / Fulfillment Send / Cash Collection
            45 => $isSendLike || $deliveryType === 'CASH_COLLECTION'
                ? ShipmentStatus::Delivered
                : ShipmentStatus::Unknown,
            // Returned to business (Exchange, CRP, RTO) — not our outbound unlock
            46 => ShipmentStatus::Failed,
            // Exception (In progress) — store metadata via webhook service; do not fulfill
            47 => ShipmentStatus::Unknown,
            // Terminated
            48 => ShipmentStatus::Cancelled,
            // Canceled
            49 => ShipmentStatus::Cancelled,
            // Returned to stock (Fulfillment)
            60 => ShipmentStatus::Failed,
            // Lost / Damaged
            100, 101 => ShipmentStatus::Failed,
            // Investigation / Awaiting action / Archived / On hold — store but do not fulfill
            102, 103, 104, 105 => ShipmentStatus::Unknown,
            default => ShipmentStatus::Unknown,
        };
    }

    private function mapLegacyString(string $providerStatus): ShipmentStatus
    {
        $normalized = strtolower($providerStatus);
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'pending', 'new', 'created', 'pickup_requested', 'awaiting_pickup' => ShipmentStatus::Created,
            'picked_up', 'pickedup', 'collected' => ShipmentStatus::PickedUp,
            'in_transit', 'intransit', 'shipped', 'on_the_way', 'received_at_warehouse' => ShipmentStatus::InTransit,
            'out_for_delivery', 'outfordelivery' => ShipmentStatus::OutForDelivery,
            'delivered', 'delivery_confirmed', 'completed' => ShipmentStatus::Delivered,
            'cancelled', 'canceled', 'terminated' => ShipmentStatus::Cancelled,
            'failed', 'returned', 'rejected', 'exception', 'lost', 'damaged' => ShipmentStatus::Failed,
            default => ShipmentStatus::Unknown,
        };
    }
}
