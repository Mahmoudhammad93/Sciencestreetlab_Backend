<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Application\Listeners;

use App\Modules\SocialCommerce\Application\Events\ProductSalesChannelSyncRequested;
use App\Modules\SocialCommerce\Application\Services\SalesChannelManager;
use App\Modules\SocialCommerce\Domain\Enums\ConnectionStatus;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Soft-queues sync for connected channels when a product changes.
 * Uses SalesChannelManager so duplicate concurrent syncs are blocked.
 */
final class QueueConnectedChannelSync implements ShouldQueue
{
    public function __construct(
        private readonly SalesChannelManager $manager,
    ) {}

    public function handle(ProductSalesChannelSyncRequested $event): void
    {
        $integrations = SalesChannelIntegration::query()
            ->where('connection_status', ConnectionStatus::Connected->value)
            ->get();

        foreach ($integrations as $integration) {
            try {
                $this->manager->requestSync($integration);
            } catch (Throwable) {
                // Already syncing or transient — skip without failing the product save path.
            }
        }
    }
}
