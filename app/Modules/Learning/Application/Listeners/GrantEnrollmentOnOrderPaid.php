<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Listeners;

use App\Modules\Commerce\Domain\Events\OrderPaid;

/**
 * @deprecated Enrollment is driven by OrderFulfilled. Kept as a no-op so any
 *             lingering OrderPaid→this binding cannot double-enroll.
 */
final class GrantEnrollmentOnOrderPaid
{
    public function handle(OrderPaid $event): void
    {
        // Intentionally empty — see GrantEnrollmentOnOrderFulfilled.
    }
}
