<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\User;
use App\Modules\SocialCommerce\Application\Services\ChannelHealthService;
use App\Modules\SocialCommerce\Application\Services\HumanErrorMapper;
use App\Modules\SocialCommerce\Application\Services\SalesChannelManager;
use App\Modules\SocialCommerce\Domain\Enums\ConnectionStatus;
use App\Modules\SocialCommerce\Domain\Enums\PublicationStatus;
use App\Modules\SocialCommerce\Domain\Enums\SyncStatus;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelActivityLog;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class ManageSalesChannels extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-share';

    protected static ?string $navigationGroup = 'Commerce';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.manage-sales-channels';

    public ?int $wizardIntegrationId = null;

    public int $wizardStep = 1;

    public ?int $manageIntegrationId = null;

    public bool $showAdvanced = false;

    public ?int $advancedIntegrationId = null;

    /** @var array{external_account_id?: ?string, external_account_name?: ?string} */
    public array $advancedForm = [];

    public static function getNavigationLabel(): string
    {
        return (string) __('sales_channels.nav');
    }

    public function getTitle(): string
    {
        return (string) __('sales_channels.title');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ($user->can('sales_channels.view') || $user->hasRole('super_admin'));
    }

    public function mount(SalesChannelManager $manager): void
    {
        $manager->ensureDefaults();
    }

    /**
     * @return array{connected: int, synced: int, needs_attention: int}
     */
    public function getTotalsProperty(): array
    {
        return app(SalesChannelManager::class)->dashboardTotals();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getChannelsProperty(): Collection
    {
        $health = app(ChannelHealthService::class);

        return SalesChannelIntegration::query()
            ->with('products')
            ->orderBy('id')
            ->get()
            ->map(function (SalesChannelIntegration $integration) use ($health): array {
                $summary = $health->summarize($integration);

                return [
                    'id' => $integration->id,
                    'platform' => $integration->platform->value,
                    'name' => $integration->platform->label(),
                    'connection_status' => $summary['connection_status'],
                    'connection_label' => $summary['connection_label'],
                    'sync_status' => $summary['sync_status'],
                    'sync_label' => $summary['sync_label'],
                    'health_status' => $summary['health_status'],
                    'health_label' => $summary['health_label'],
                    'summary' => $summary['summary'],
                    'recommended_action' => $summary['recommended_action'],
                    'issue' => $summary['issue'],
                    'products_synced' => $summary['products_synced'],
                    'products_needing_attention' => $summary['products_needing_attention'],
                    'products_total' => $summary['products_total'],
                    'last_synced_at' => $integration->last_synced_at,
                    'last_synced_human' => $integration->last_synced_at
                        ? $integration->last_synced_at->diffForHumans()
                        : (string) __('sales_channels.never'),
                    'can_connect' => $this->canManage()
                        && in_array($integration->connection_status, [
                            ConnectionStatus::NotConnected,
                            ConnectionStatus::Disconnected,
                            ConnectionStatus::Expired,
                        ], true),
                    'can_sync' => $this->canSync()
                        && $integration->isConnected()
                        && $integration->sync_status !== SyncStatus::Syncing
                        && $integration->sync_status !== SyncStatus::Pending,
                    'sync_disabled_reason' => $this->syncDisabledReason($integration),
                    'can_manage' => $this->canManage() && $integration->isConnected(),
                ];
            });
    }

    /**
     * @return Collection<int, array{level: string, message: string, when: string, group: string}>
     */
    public function getActivityProperty(): Collection
    {
        return SalesChannelActivityLog::query()
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(function (SalesChannelActivityLog $log): array {
                $message = (string) __($log->message_key, $log->message_params ?? []);

                return [
                    'level' => $log->level,
                    'message' => $message,
                    'when' => $log->created_at?->format('g:i A') ?? '',
                    'group' => $log->created_at?->isToday()
                        ? (string) __('sales_channels.activity.today')
                        : (string) __('sales_channels.activity.earlier'),
                ];
            });
    }

    public function openWizard(int $integrationId): void
    {
        abort_unless($this->canConnect(), 403);

        $this->wizardIntegrationId = $integrationId;
        $this->wizardStep = 1;
        $this->manageIntegrationId = null;
        $this->showAdvanced = false;
    }

    public function closeWizard(): void
    {
        $this->wizardIntegrationId = null;
        $this->wizardStep = 1;
    }

    public function wizardNext(): void
    {
        if ($this->wizardStep < 4) {
            $this->wizardStep++;
        }
    }

    public function wizardBack(): void
    {
        if ($this->wizardStep > 1) {
            $this->wizardStep--;
        }
    }

    public function activateChannel(): void
    {
        abort_unless($this->canConnect(), 403);

        $integration = $this->wizardIntegration();
        if ($integration === null) {
            return;
        }

        $result = app(SalesChannelManager::class)->beginConnect($integration);

        if ($result->connection_status === ConnectionStatus::Connected) {
            Notification::make()
                ->title(__('sales_channels.notifications.connected'))
                ->success()
                ->send();
            $this->closeWizard();

            return;
        }

        Notification::make()
            ->title(__('sales_channels.notifications.connect_blocked'))
            ->warning()
            ->send();
        $this->closeWizard();
    }

    public function reconnect(int $integrationId): void
    {
        $this->openWizard($integrationId);
    }

    public function syncNow(int $integrationId): void
    {
        abort_unless($this->canSync(), 403);

        $integration = SalesChannelIntegration::query()->findOrFail($integrationId);

        try {
            app(SalesChannelManager::class)->requestSync($integration);
            Notification::make()
                ->title(__('sales_channels.notifications.sync_queued'))
                ->success()
                ->send();
        } catch (RuntimeException $e) {
            $issue = app(HumanErrorMapper::class)->present(
                $e->getMessage() === 'sync_in_progress' ? 'sync_in_progress' : 'generic'
            );
            Notification::make()
                ->title(__('sales_channels.notifications.sync_blocked'))
                ->body($issue['message'])
                ->warning()
                ->send();
        }
    }

    public function openManage(int $integrationId): void
    {
        abort_unless($this->canManage(), 403);
        $this->manageIntegrationId = $integrationId;
        $this->wizardIntegrationId = null;
        $this->showAdvanced = false;
    }

    public function closeManage(): void
    {
        $this->manageIntegrationId = null;
    }

    public function openAdvanced(int $integrationId): void
    {
        abort_unless($this->canViewTechnical(), 403);

        $integration = SalesChannelIntegration::query()->findOrFail($integrationId);
        $this->advancedIntegrationId = $integrationId;
        $this->showAdvanced = true;
        $this->advancedForm = [
            'external_account_id' => $integration->external_account_id,
            'external_account_name' => $integration->external_account_name,
        ];
    }

    public function closeAdvanced(): void
    {
        $this->showAdvanced = false;
        $this->advancedIntegrationId = null;
        $this->advancedForm = [];
    }

    public function saveAdvanced(): void
    {
        abort_unless($this->canViewTechnical(), 403);

        $integration = SalesChannelIntegration::query()->findOrFail($this->advancedIntegrationId);

        $integration->update([
            'external_account_id' => $this->advancedForm['external_account_id'] ?: null,
            'external_account_name' => $this->advancedForm['external_account_name'] ?: null,
        ]);

        Notification::make()
            ->title(__('sales_channels.notifications.advanced_saved'))
            ->success()
            ->send();

        $this->closeAdvanced();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getWizardChannelProperty(): ?array
    {
        $integration = $this->wizardIntegration();
        if ($integration === null) {
            return null;
        }

        return [
            'id' => $integration->id,
            'name' => $integration->platform->label(),
            'configured' => app(SalesChannelManager::class)->providerFor($integration)->isConfigured(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getManagePanelProperty(): ?array
    {
        if ($this->manageIntegrationId === null) {
            return null;
        }

        $integration = SalesChannelIntegration::query()
            ->with(['products.product'])
            ->find($this->manageIntegrationId);

        if ($integration === null) {
            return null;
        }

        $issues = $integration->products
            ->where('publication_status', PublicationStatus::NeedsAttention)
            ->map(function ($channelProduct): array {
                $product = $channelProduct->product;
                $issue = app(HumanErrorMapper::class)->present($channelProduct->last_error_code);

                return [
                    'product_id' => $channelProduct->product_id,
                    'product_name' => $product?->getTranslation('name', app()->getLocale())
                        ?: $product?->sku
                        ?: '#'.$channelProduct->product_id,
                    'message' => $issue['message'],
                    'edit_url' => $product
                        ? ProductResource::getUrl('edit', ['record' => $product])
                        : null,
                ];
            })
            ->values()
            ->all();

        return [
            'id' => $integration->id,
            'name' => $integration->platform->label(),
            'issues' => $issues,
            'can_advanced' => $this->canViewTechnical(),
        ];
    }

    public function canManage(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ($user->can('sales_channels.manage') || $user->hasRole('super_admin'));
    }

    public function canConnect(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ($user->can('sales_channels.connect') || $user->hasRole('super_admin'));
    }

    public function canSync(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ($user->can('sales_channels.sync') || $user->hasRole('super_admin'));
    }

    public function canViewTechnical(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ($user->can('sales_channels.view_technical_logs') || $user->hasRole('super_admin'));
    }

    private function wizardIntegration(): ?SalesChannelIntegration
    {
        if ($this->wizardIntegrationId === null) {
            return null;
        }

        return SalesChannelIntegration::query()->find($this->wizardIntegrationId);
    }

    private function syncDisabledReason(SalesChannelIntegration $integration): ?string
    {
        if (! $integration->isConnected()) {
            return (string) __('sales_channels.action_disabled.sync_not_connected');
        }

        if (in_array($integration->sync_status, [SyncStatus::Syncing, SyncStatus::Pending], true)) {
            return (string) __('sales_channels.action_disabled.sync_in_progress');
        }

        return null;
    }
}
