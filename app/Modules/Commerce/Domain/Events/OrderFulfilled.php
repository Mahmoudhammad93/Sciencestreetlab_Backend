<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Events;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Business fact: the order is fulfilled enough to grant course access and
 * send activation/confirmation email. Distinct from OrderPaid (accounting).
 */
final class OrderFulfilled
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
