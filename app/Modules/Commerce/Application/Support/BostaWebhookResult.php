<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;

/**
 * Structured outcome of a Bosta webhook processing attempt.
 */
final class BostaWebhookResult
{
    public const OUTCOME_PROCESSED = 'processed';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_IGNORED_UNKNOWN_SHIPMENT = 'ignored_unknown_shipment';

    public const OUTCOME_IGNORED_UNKNOWN_STATUS = 'ignored_unknown_status';

    public const OUTCOME_IGNORED_TERMINAL = 'ignored_terminal_regression';

    public function __construct(
        public readonly string $outcome,
        public readonly ?Shipment $shipment = null,
        public readonly ?string $externalShipmentId = null,
        public readonly ?string $providerStatus = null,
        public readonly ?ShipmentStatus $mappedStatus = null,
        public readonly bool $duplicate = false,
        public readonly bool $fulfilled = false,
    ) {}
}
