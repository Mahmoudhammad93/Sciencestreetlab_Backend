<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Shipping\Bosta;

use App\Modules\Commerce\Domain\Contracts\BostaClientInterface;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use RuntimeException;

/**
 * Production-facing client shell.
 *
 * BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS — refuses real HTTP until
 * BOSTA_API_CONTRACT_READY=true and credentials/docs are supplied.
 */
final class HttpBostaClient implements BostaClientInterface
{
    public function createShipment(Order $order): array
    {
        throw new RuntimeException(
            'BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS: Bosta createShipment HTTP mapping is not configured. '
            .'Provide BOSTA_API_URL, BOSTA_API_KEY, official API documentation, then set BOSTA_API_CONTRACT_READY=true.'
        );
    }
}
