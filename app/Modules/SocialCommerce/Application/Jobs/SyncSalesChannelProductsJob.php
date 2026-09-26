<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Application\Jobs;

use App\Modules\SocialCommerce\Application\Services\SalesChannelManager;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncSalesChannelProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $integrationId,
    ) {}

    public function handle(SalesChannelManager $manager): void
    {
        $integration = SalesChannelIntegration::query()->find($this->integrationId);
        if ($integration === null) {
            return;
        }

        $manager->runSync($integration);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Sales channel sync job failed', [
            'integration_id' => $this->integrationId,
            'error' => $exception?->getMessage(),
        ]);

        $integration = SalesChannelIntegration::query()->find($this->integrationId);
        if ($integration === null) {
            return;
        }

        $integration->update([
            'sync_status' => \App\Modules\SocialCommerce\Domain\Enums\SyncStatus::Failed,
            'health_status' => \App\Modules\SocialCommerce\Domain\Enums\HealthStatus::NeedsAttention,
            'last_error_code' => 'sync_job_failed',
            'last_error_at' => now(),
            'last_error_message' => 'sync_job_failed',
        ]);
    }
}
