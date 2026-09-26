<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Application\Services;

use App\Modules\SocialCommerce\Domain\Enums\ConnectionStatus;
use App\Modules\SocialCommerce\Domain\Enums\HealthStatus;
use App\Modules\SocialCommerce\Domain\Enums\PublicationStatus;
use App\Modules\SocialCommerce\Domain\Enums\SyncStatus;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;

final class ChannelHealthService
{
    public function __construct(
        private readonly HumanErrorMapper $errors,
    ) {}

    /**
     * @return array{
     *   connection_status: string,
     *   sync_status: string,
     *   health_status: string,
     *   connection_label: string,
     *   sync_label: string,
     *   health_label: string,
     *   summary: string,
     *   recommended_action: string,
     *   issue: array{title: string, message: string, action: string, code: ?string}|null,
     *   products_synced: int,
     *   products_needing_attention: int,
     *   products_total: int
     * }
     */
    public function summarize(SalesChannelIntegration $integration): array
    {
        $integration->loadMissing('products');

        $health = $this->resolveHealth($integration);
        $issue = null;

        if (in_array($health, [HealthStatus::NeedsAttention, HealthStatus::Unavailable], true)
            || $integration->connection_status === ConnectionStatus::Expired
            || $integration->sync_status === SyncStatus::Failed) {
            $issue = $this->errors->present($integration->last_error_code);
        }

        $synced = $integration->products
            ->where('publication_status', PublicationStatus::Published)
            ->count();
        $needsAttention = $integration->products
            ->where('publication_status', PublicationStatus::NeedsAttention)
            ->count();

        return [
            'connection_status' => $integration->connection_status->value,
            'sync_status' => $integration->sync_status->value,
            'health_status' => $health->value,
            'connection_label' => $integration->connection_status->label(),
            'sync_label' => $integration->sync_status->label(),
            'health_label' => $health->label(),
            'summary' => $this->summaryText($integration, $synced, $needsAttention),
            'recommended_action' => $this->recommendedAction($integration, $health),
            'issue' => $issue,
            'products_synced' => $synced,
            'products_needing_attention' => $needsAttention,
            'products_total' => $integration->products->count(),
        ];
    }

    public function resolveHealth(SalesChannelIntegration $integration): HealthStatus
    {
        if ($integration->connection_status === ConnectionStatus::NotConnected
            || $integration->connection_status === ConnectionStatus::Disconnected) {
            return HealthStatus::Unavailable;
        }

        if ($integration->connection_status === ConnectionStatus::Expired
            || $integration->connection_status === ConnectionStatus::Connecting) {
            return HealthStatus::NeedsAttention;
        }

        if ($integration->sync_status === SyncStatus::Failed
            || $integration->sync_status === SyncStatus::PartiallySynced) {
            return HealthStatus::NeedsAttention;
        }

        $needsAttention = $integration->products()
            ->where('publication_status', PublicationStatus::NeedsAttention->value)
            ->exists();

        if ($needsAttention) {
            return HealthStatus::NeedsAttention;
        }

        if ($integration->connection_status === ConnectionStatus::Connected
            && in_array($integration->sync_status, [SyncStatus::Synced, SyncStatus::NeverSynced, SyncStatus::Pending, SyncStatus::Syncing], true)) {
            return HealthStatus::Healthy;
        }

        return HealthStatus::NeedsAttention;
    }

    public function refresh(SalesChannelIntegration $integration): SalesChannelIntegration
    {
        $integration->health_status = $this->resolveHealth($integration);
        $integration->save();

        return $integration->fresh() ?? $integration;
    }

    private function summaryText(SalesChannelIntegration $integration, int $synced, int $needsAttention): string
    {
        if ($integration->connection_status === ConnectionStatus::NotConnected) {
            return (string) __('sales_channels.summary.not_connected');
        }

        if ($integration->connection_status === ConnectionStatus::Expired) {
            return (string) __('sales_channels.summary.expired');
        }

        if ($needsAttention > 0) {
            return (string) __('sales_channels.summary.needs_attention', [
                'synced' => $synced,
                'total' => max($synced + $needsAttention, $integration->products->count()),
                'count' => $needsAttention,
            ]);
        }

        if ($integration->sync_status === SyncStatus::Syncing) {
            return (string) __('sales_channels.summary.syncing');
        }

        return (string) __('sales_channels.summary.synced', ['count' => $synced]);
    }

    private function recommendedAction(SalesChannelIntegration $integration, HealthStatus $health): string
    {
        return match (true) {
            $integration->connection_status === ConnectionStatus::NotConnected => (string) __('sales_channels.actions.connect'),
            $integration->connection_status === ConnectionStatus::Expired => (string) __('sales_channels.actions.reconnect'),
            $health === HealthStatus::NeedsAttention => (string) __('sales_channels.actions.fix_issues'),
            $integration->sync_status === SyncStatus::NeverSynced => (string) __('sales_channels.actions.sync_now'),
            default => (string) __('sales_channels.actions.manage'),
        };
    }
}
