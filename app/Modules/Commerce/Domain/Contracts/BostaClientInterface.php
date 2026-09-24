<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Contracts;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;

/**
 * Boundary for Bosta HTTP APIs.
 *
 * BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS: do not invent endpoint URLs or payloads.
 * Implementations must either use FakeBostaClient (tests) or refuse real calls
 * until official documentation + credentials are supplied.
 */
interface BostaClientInterface
{
    /**
     * Create a delivery shipment for the order. Must be idempotent at the
     * service layer (one external shipment per order).
     *
     * @return array{
     *     external_shipment_id: string,
     *     tracking_number: ?string,
     *     tracking_url: ?string,
     *     provider_status: ?string,
     *     raw: array<string, mixed>
     * }
     */
    public function createShipment(Order $order): array;
}
