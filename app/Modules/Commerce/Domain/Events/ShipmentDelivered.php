<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Events;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ShipmentDelivered
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Shipment $shipment) {}
}
