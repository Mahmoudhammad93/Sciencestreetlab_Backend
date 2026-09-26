<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\SocialCommerce\Domain\Contracts\SalesChannelProviderInterface;
use App\Modules\SocialCommerce\Domain\Enums\ConnectionStatus;
use App\Modules\SocialCommerce\Domain\Enums\HealthStatus;
use App\Modules\SocialCommerce\Domain\Enums\PublicationStatus;
use App\Modules\SocialCommerce\Domain\Enums\SalesChannelPlatform;
use App\Modules\SocialCommerce\Domain\Enums\SyncStatus;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelActivityLog;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelProduct;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

final class SalesChannelManager
{
    /** @param  array<string, SalesChannelProviderInterface>  $providers */
    public function __construct(
        private readonly array $providers,
        private readonly ProductChannelMapper $mapper,
        private readonly ProductReadinessService $readiness,
        private readonly ChannelHealthService $health,
    ) {}

    public function ensureDefaults(): void
    {
        foreach ([
            SalesChannelPlatform::GoogleMerchant,
            SalesChannelPlatform::YouTubeShopping,
        ] as $platform) {
            SalesChannelIntegration::query()->firstOrCreate(
                ['platform' => $platform->value, 'name' => $platform->label()],
                [
                    'connection_status' => ConnectionStatus::NotConnected,
                    'sync_status' => SyncStatus::NeverSynced,
                    'health_status' => HealthStatus::Unavailable,
                    'settings' => [],
                ]
            );
        }
    }

    public function providerFor(SalesChannelIntegration $integration): SalesChannelProviderInterface
    {
        $key = $integration->platform->value;
        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException('No provider registered for '.$key);
        }

        return $this->providers[$key];
    }

    /**
     * Soft-connect for demo/readiness until real OAuth credentials exist.
     * Marks connection as connected only when provider reports configured,
     * otherwise stores connecting + blocked reason.
     */
    public function beginConnect(SalesChannelIntegration $integration): SalesChannelIntegration
    {
        $provider = $this->providerFor($integration);

        $integration->update([
            'connection_status' => ConnectionStatus::Connecting,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);

        if (! $provider->isConfigured()) {
            $integration->update([
                'connection_status' => ConnectionStatus::NotConnected,
                'health_status' => HealthStatus::Unavailable,
                'last_error_code' => 'provider_not_configured',
                'last_error_at' => now(),
                'last_error_message' => 'Provider credentials are not configured.',
            ]);

            $this->log($integration, 'warning', 'connect_blocked', 'sales_channels.activity.connect_blocked');

            return $this->health->refresh($integration->fresh() ?? $integration);
        }

        $result = $provider->testConnection($integration);
        if (! $result['ok']) {
            $integration->update([
                'connection_status' => ConnectionStatus::Expired,
                'health_status' => HealthStatus::NeedsAttention,
                'last_error_code' => $result['code'],
                'last_error_at' => now(),
                'last_error_message' => $result['code'],
            ]);
            $this->log($integration, 'error', 'connect_failed', 'sales_channels.activity.connect_failed', [], ['code' => $result['code']]);

            return $this->health->refresh($integration->fresh() ?? $integration);
        }

        $integration->update([
            'connection_status' => ConnectionStatus::Connected,
            'last_connected_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ]);

        $this->log($integration, 'success', 'connected', 'sales_channels.activity.connected');

        return $this->health->refresh($integration->fresh() ?? $integration);
    }

    public function markExpired(SalesChannelIntegration $integration, string $code = 'token_refresh_failed'): SalesChannelIntegration
    {
        $integration->update([
            'connection_status' => ConnectionStatus::Expired,
            'health_status' => HealthStatus::NeedsAttention,
            'last_error_code' => $code,
            'last_error_at' => now(),
            'last_error_message' => $code,
        ]);

        $this->log($integration, 'warning', 'connection_expired', 'sales_channels.activity.connection_expired');

        return $integration->fresh() ?? $integration;
    }

    public function requestSync(SalesChannelIntegration $integration): void
    {
        if (! $integration->isConnected()) {
            throw new RuntimeException('Channel is not connected.');
        }

        $lockKey = 'sales-channel-sync:'.$integration->id;
        if (! Cache::add($lockKey, 1, now()->addMinutes(10))) {
            throw new RuntimeException('sync_in_progress');
        }

        $integration->update([
            'sync_status' => SyncStatus::Pending,
        ]);

        \App\Modules\SocialCommerce\Application\Jobs\SyncSalesChannelProductsJob::dispatch($integration->id);
    }

    public function runSync(SalesChannelIntegration $integration): SalesChannelIntegration
    {
        $lockKey = 'sales-channel-sync:'.$integration->id;

        try {
            $integration->update(['sync_status' => SyncStatus::Syncing]);
            $this->log($integration, 'info', 'sync_started', 'sales_channels.activity.sync_started');

            $provider = $this->providerFor($integration);
            $products = Product::query()
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->orderBy('id')
                ->get();

            $synced = 0;
            $failed = 0;

            foreach ($products as $product) {
                $channelProduct = $this->upsertChannelProduct($integration, $product);
                $issues = $this->readiness->blockingIssueCodes($product);

                if ($issues !== []) {
                    $channelProduct->update([
                        'publication_status' => PublicationStatus::NeedsAttention,
                        'sync_status' => SyncStatus::Failed,
                        'last_error_code' => $issues[0] === 'main_image' ? 'missing_main_image' : 'missing_'.$issues[0],
                        'last_error_message' => $issues[0],
                        'readiness_issues' => $issues,
                    ]);
                    $failed++;
                    continue;
                }

                $payload = $this->mapper->map($product);
                $result = $provider->syncProduct($integration, $payload);

                if ($result['ok']) {
                    $channelProduct->update([
                        'external_product_id' => $result['external_id'] ?? $channelProduct->external_product_id ?? $product->sku,
                        'publication_status' => PublicationStatus::Published,
                        'sync_status' => SyncStatus::Synced,
                        'last_synced_at' => now(),
                        'last_error_code' => null,
                        'last_error_message' => null,
                        'readiness_issues' => [],
                    ]);
                    $synced++;
                } else {
                    $channelProduct->update([
                        'publication_status' => PublicationStatus::NeedsAttention,
                        'sync_status' => SyncStatus::Failed,
                        'last_error_code' => $result['code'],
                        'last_error_message' => $result['code'],
                    ]);
                    $failed++;
                }
            }

            $status = match (true) {
                $failed === 0 => SyncStatus::Synced,
                $synced === 0 => SyncStatus::Failed,
                default => SyncStatus::PartiallySynced,
            };

            $integration->update([
                'sync_status' => $status,
                'last_synced_at' => now(),
                'last_successful_sync_at' => $synced > 0 ? now() : $integration->last_successful_sync_at,
                'last_error_code' => $failed > 0 ? 'partial_or_failed_sync' : null,
                'last_error_at' => $failed > 0 ? now() : null,
                'last_error_message' => $failed > 0 ? 'partial_or_failed_sync' : null,
            ]);

            $this->log($integration, $failed > 0 ? 'warning' : 'success', 'sync_finished', 'sales_channels.activity.sync_finished', [
                'synced' => $synced,
                'failed' => $failed,
            ]);

            if ($failed > 0) {
                $this->log($integration, 'warning', 'products_need_attention', 'sales_channels.activity.products_need_attention', [
                    'count' => $failed,
                ]);
            }

            return $this->health->refresh($integration->fresh() ?? $integration);
        } finally {
            Cache::forget($lockKey);
        }
    }

    public function upsertChannelProduct(SalesChannelIntegration $integration, Product $product): SalesChannelProduct
    {
        return SalesChannelProduct::query()->firstOrCreate(
            [
                'product_id' => $product->id,
                'integration_id' => $integration->id,
            ],
            [
                'publication_status' => PublicationStatus::NotPublished,
                'sync_status' => SyncStatus::NeverSynced,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $technical
     */
    public function log(
        ?SalesChannelIntegration $integration,
        string $level,
        string $eventCode,
        string $messageKey,
        array $params = [],
        ?array $technical = null,
    ): void {
        SalesChannelActivityLog::query()->create([
            'integration_id' => $integration?->id,
            'level' => $level,
            'event_code' => $eventCode,
            'message_key' => $messageKey,
            'message_params' => $params,
            'technical_context' => $technical,
        ]);
    }

    /**
     * @return array{connected: int, synced: int, needs_attention: int}
     */
    public function dashboardTotals(): array
    {
        $this->ensureDefaults();

        $integrations = SalesChannelIntegration::query()->with('products')->get();

        return [
            'connected' => $integrations->where('connection_status', ConnectionStatus::Connected)->count(),
            'synced' => $integrations->sum(fn (SalesChannelIntegration $i) => $i->products
                ->where('publication_status', PublicationStatus::Published)->count()),
            'needs_attention' => $integrations->sum(fn (SalesChannelIntegration $i) => $i->products
                ->where('publication_status', PublicationStatus::NeedsAttention)->count()),
        ];
    }
}
