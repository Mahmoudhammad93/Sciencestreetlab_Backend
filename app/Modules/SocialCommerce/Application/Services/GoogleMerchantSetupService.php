<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\SocialCommerce\Domain\Enums\ConnectionStatus;
use App\Modules\SocialCommerce\Domain\Enums\HealthStatus;
use App\Modules\SocialCommerce\Domain\Enums\SalesChannelPlatform;
use App\Modules\SocialCommerce\Domain\Enums\SyncStatus;
use App\Modules\SocialCommerce\Infrastructure\Google\ServiceAccountCredentialParser;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;
use App\Modules\SocialCommerce\Infrastructure\Providers\Adapters\GoogleMerchantProvider;
use InvalidArgumentException;

final class GoogleMerchantSetupService
{
    public function __construct(
        private readonly SalesChannelManager $manager,
        private readonly ServiceAccountCredentialParser $parser,
        private readonly GoogleMerchantProvider $provider,
        private readonly ProductReadinessService $readiness,
        private readonly ChannelHealthService $health,
    ) {}

    public function integration(): SalesChannelIntegration
    {
        $this->manager->ensureDefaults();

        return SalesChannelIntegration::query()
            ->where('platform', SalesChannelPlatform::GoogleMerchant->value)
            ->firstOrFail();
    }

    public function cardState(SalesChannelIntegration $integration): string
    {
        if (! $this->provider->hasCredentials($integration)) {
            return 'setup_required';
        }

        if ($integration->connection_status === ConnectionStatus::Connected) {
            if ($integration->health_status === HealthStatus::NeedsAttention
                || $integration->sync_status === SyncStatus::Failed
                || $integration->sync_status === SyncStatus::PartiallySynced) {
                return 'needs_attention';
            }

            return 'connected';
        }

        if (in_array($integration->connection_status, [ConnectionStatus::Expired], true)
            || filled($integration->last_error_code)) {
            return 'needs_attention';
        }

        return 'ready_to_connect';
    }

    public function saveMerchantId(SalesChannelIntegration $integration, string $merchantId): SalesChannelIntegration
    {
        $merchantId = trim($merchantId);
        if ($merchantId === '' || ! ctype_digit($merchantId)) {
            throw new InvalidArgumentException('invalid_merchant_id');
        }

        $settings = $integration->settings ?? [];
        $settings['merchant_id'] = $merchantId;

        $integration->update([
            'external_account_id' => $merchantId,
            'settings' => $settings,
        ]);

        return $integration->fresh() ?? $integration;
    }

    public function saveServiceAccountJson(SalesChannelIntegration $integration, string $rawJson): SalesChannelIntegration
    {
        $parsed = $this->parser->parse($rawJson);

        $settings = $integration->settings ?? [];
        $settings['service_account_email'] = $parsed['client_email'];
        $settings['has_service_account'] = true;

        $integration->update([
            'credentials' => $parsed,
            'settings' => $settings,
            // Saving credentials does not auto-connect.
            'connection_status' => ConnectionStatus::NotConnected,
            'health_status' => HealthStatus::Unavailable,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);

        return $integration->fresh() ?? $integration;
    }

    /**
     * @return array{
     *   ok: bool,
     *   code: string,
     *   account_name?: string,
     *   merchant_id?: string,
     *   masked_merchant_id?: string,
     *   issue?: array{title: string, message: string, action: string, code: ?string}
     * }
     */
    public function testAndPrepare(SalesChannelIntegration $integration): array
    {
        $result = $this->provider->testConnection($integration);
        $errors = app(HumanErrorMapper::class);

        if (! $result['ok']) {
            $integration->update([
                'connection_status' => ConnectionStatus::NotConnected,
                'health_status' => HealthStatus::NeedsAttention,
                'last_error_code' => $result['code'],
                'last_error_at' => now(),
                'last_error_message' => $result['code'],
            ]);

            $this->manager->log(
                $integration,
                'error',
                'connect_failed',
                'sales_channels.activity.connect_failed',
                [],
                ['code' => $result['code']]
            );

            $this->health->refresh($integration->fresh() ?? $integration);

            return [
                'ok' => false,
                'code' => $result['code'],
                'issue' => $errors->present($result['code']),
            ];
        }

        $settings = $integration->settings ?? [];
        if (filled($result['data_source_name'] ?? null)) {
            $settings['data_source_name'] = $result['data_source_name'];
        }
        $settings['automatic_sync'] = true;
        $accountName = (string) ($result['account_name'] ?? 'ScienceStreetLab');

        $integration->update([
            'connection_status' => ConnectionStatus::Connected,
            'external_account_name' => $accountName,
            'settings' => $settings,
            'last_connected_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ]);

        $this->manager->log($integration, 'success', 'connected', 'sales_channels.activity.google_connected');
        $this->health->refresh($integration->fresh() ?? $integration);

        $merchantId = (string) $integration->external_account_id;

        return [
            'ok' => true,
            'code' => 'ok',
            'account_name' => $accountName,
            'merchant_id' => $merchantId,
            'masked_merchant_id' => $this->maskMerchantId($merchantId),
        ];
    }

    /**
     * @return array{ready: int, needs_attention: int, total: int}
     */
    public function productReadinessSummary(): array
    {
        $products = Product::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->get();

        $ready = 0;
        $needsAttention = 0;

        foreach ($products as $product) {
            if ($this->readiness->isReady($product)) {
                $ready++;
            } else {
                $needsAttention++;
            }
        }

        return [
            'ready' => $ready,
            'needs_attention' => $needsAttention,
            'total' => $products->count(),
        ];
    }

    public function activate(SalesChannelIntegration $integration): SalesChannelIntegration
    {
        $settings = $integration->settings ?? [];
        $settings['activated'] = true;
        $settings['automatic_sync'] = true;

        $integration->update([
            'settings' => $settings,
            'connection_status' => ConnectionStatus::Connected,
        ]);

        $this->manager->log($integration, 'success', 'activated', 'sales_channels.activity.google_activated');

        return $this->health->refresh($integration->fresh() ?? $integration);
    }

    public function maskMerchantId(string $merchantId): string
    {
        if (strlen($merchantId) <= 4) {
            return str_repeat('*', max(0, strlen($merchantId) - 1)).substr($merchantId, -1);
        }

        return str_repeat('*', strlen($merchantId) - 4).substr($merchantId, -4);
    }
}
