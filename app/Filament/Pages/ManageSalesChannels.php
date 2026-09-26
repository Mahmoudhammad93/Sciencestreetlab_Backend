<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\User;
use App\Modules\SocialCommerce\Application\Services\ChannelHealthService;
use App\Modules\SocialCommerce\Application\Services\GoogleMerchantSetupService;
use App\Modules\SocialCommerce\Application\Services\HumanErrorMapper;
use App\Modules\SocialCommerce\Application\Services\SalesChannelManager;
use App\Modules\SocialCommerce\Domain\Enums\ConnectionStatus;
use App\Modules\SocialCommerce\Domain\Enums\PublicationStatus;
use App\Modules\SocialCommerce\Domain\Enums\SalesChannelPlatform;
use App\Modules\SocialCommerce\Domain\Enums\SyncStatus;
use App\Modules\SocialCommerce\Infrastructure\Google\ServiceAccountCredentialParser;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelActivityLog;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;
use App\Modules\SocialCommerce\Infrastructure\Providers\Adapters\GoogleMerchantProvider;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

class ManageSalesChannels extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-share';

    protected static ?string $navigationGroup = 'Commerce';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.manage-sales-channels';

    public ?int $manageIntegrationId = null;

    public bool $showAdvanced = false;

    public ?int $advancedIntegrationId = null;

    /** @var array{external_account_id?: ?string, external_account_name?: ?string} */
    public array $advancedForm = [];

    public bool $showGoogleSetup = false;

    public int $googleSetupStep = 1;

    public string $setupMerchantId = '';

    public string $setupServiceAccountJson = '';

    /** @var array<string, mixed>|null */
    public ?array $setupTestResult = null;

    /** @var array{ready: int, needs_attention: int, total: int}|null */
    public ?array $setupReadiness = null;

    public bool $showYoutubeGuide = false;

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
        $googleSetup = app(GoogleMerchantSetupService::class);
        $googleProvider = app(GoogleMerchantProvider::class);

        $google = SalesChannelIntegration::query()
            ->where('platform', SalesChannelPlatform::GoogleMerchant->value)
            ->first();

        return SalesChannelIntegration::query()
            ->with('products')
            ->orderBy('id')
            ->get()
            ->map(function (SalesChannelIntegration $integration) use ($health, $googleSetup, $googleProvider, $google): array {
                $summary = $health->summarize($integration);
                $platform = $integration->platform->value;

                if ($platform === SalesChannelPlatform::GoogleMerchant->value) {
                    $cardState = $googleSetup->cardState($integration);
                    $presentation = $this->googlePresentation($integration, $cardState, $summary);

                    return array_merge($presentation, [
                        'id' => $integration->id,
                        'platform' => $platform,
                        'name' => $integration->platform->label(),
                        'card_state' => $cardState,
                        'has_credentials' => $googleProvider->hasCredentials($integration),
                        'credential_meta' => $this->canConfigureGoogle()
                            ? app(ServiceAccountCredentialParser::class)->publicMetadata($integration->credentials)
                            : ['configured' => $googleProvider->hasCredentials($integration), 'client_email' => null, 'project_id' => null],
                        'merchant_label' => $integration->external_account_name,
                        'masked_merchant_id' => filled($integration->external_account_id)
                            ? $googleSetup->maskMerchantId((string) $integration->external_account_id)
                            : null,
                        'products_synced' => $summary['products_synced'],
                        'products_needing_attention' => $summary['products_needing_attention'],
                        'last_synced_human' => $integration->last_synced_at
                            ? $integration->last_synced_at->diffForHumans()
                            : (string) __('sales_channels.never'),
                        'can_setup' => $this->canConfigureGoogle(),
                        'can_sync' => $this->canSync()
                            && $integration->isConnected()
                            && $integration->sync_status !== SyncStatus::Syncing
                            && $integration->sync_status !== SyncStatus::Pending,
                        'sync_disabled_reason' => $this->syncDisabledReason($integration),
                        'can_manage' => $this->canManage() && $integration->isConnected(),
                    ]);
                }

                // YouTube Shopping — dependency + eligibility only.
                $merchantConnected = $google?->isConnected() ?? false;
                $eligibility = (bool) config('sales_channels.youtube_shopping.eligibility_confirmed', false);
                $storeLinked = (bool) config('sales_channels.youtube_shopping.store_linked', false);

                if (! $merchantConnected) {
                    $cardState = 'merchant_required';
                    $statusLabel = (string) __('sales_channels.youtube.status.merchant_required');
                    $summaryText = (string) __('sales_channels.youtube.summary.merchant_required');
                } elseif (! $eligibility || ! $storeLinked) {
                    $cardState = 'eligibility_pending';
                    $statusLabel = (string) __('sales_channels.youtube.status.eligibility_pending');
                    $summaryText = (string) __('sales_channels.youtube.summary.eligibility_pending');
                } else {
                    $cardState = 'eligibility_ready';
                    $statusLabel = (string) __('sales_channels.youtube.status.ready');
                    $summaryText = (string) __('sales_channels.youtube.summary.ready');
                }

                return [
                    'id' => $integration->id,
                    'platform' => $platform,
                    'name' => $integration->platform->label(),
                    'card_state' => $cardState,
                    'connection_status' => $integration->connection_status->value,
                    'connection_label' => $statusLabel,
                    'summary' => $summaryText,
                    'badge_class' => $cardState === 'eligibility_ready' ? 'is-connected' : 'is-idle',
                    'products_synced' => 0,
                    'products_needing_attention' => 0,
                    'last_synced_human' => (string) __('sales_channels.never'),
                    'youtube_checklist' => [
                        'merchant' => $merchantConnected,
                        'feed' => $merchantConnected,
                        'eligibility' => $eligibility,
                        'store' => $storeLinked,
                    ],
                    'can_setup' => false,
                    'can_sync' => false,
                    'can_manage' => false,
                ];
            });
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function googlePresentation(SalesChannelIntegration $integration, string $cardState, array $summary): array
    {
        return match ($cardState) {
            'setup_required' => [
                'connection_status' => 'not_connected',
                'connection_label' => (string) __('sales_channels.states.setup_required'),
                'summary' => (string) __('sales_channels.states.setup_required_summary'),
                'badge_class' => 'is-attention',
            ],
            'ready_to_connect' => [
                'connection_status' => 'not_connected',
                'connection_label' => (string) __('sales_channels.connection.not_connected'),
                'summary' => (string) __('sales_channels.states.ready_to_connect_summary'),
                'badge_class' => 'is-idle',
            ],
            'needs_attention' => [
                'connection_status' => $summary['connection_status'],
                'connection_label' => (string) __('sales_channels.health.needs_attention'),
                'summary' => $summary['issue']['message']
                    ?? (string) __('sales_channels.states.needs_attention_summary'),
                'badge_class' => 'is-attention',
            ],
            default => [
                'connection_status' => 'connected',
                'connection_label' => (string) __('sales_channels.connection.connected'),
                'summary' => $summary['summary'],
                'badge_class' => 'is-connected',
            ],
        };
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
                return [
                    'level' => $log->level,
                    'message' => (string) __($log->message_key, $log->message_params ?? []),
                    'when' => $log->created_at?->format('g:i A') ?? '',
                    'group' => $log->created_at?->isToday()
                        ? (string) __('sales_channels.activity.today')
                        : (string) __('sales_channels.activity.earlier'),
                ];
            });
    }

    public function openGoogleSetup(): void
    {
        abort_unless($this->canConfigureGoogle(), 403);

        $integration = app(GoogleMerchantSetupService::class)->integration();
        $this->showGoogleSetup = true;
        $hasCredentials = app(GoogleMerchantProvider::class)->hasCredentials($integration);
        $this->googleSetupStep = $hasCredentials ? 3 : 1;
        $this->setupMerchantId = (string) ($integration->external_account_id ?? '');
        $this->setupServiceAccountJson = '';
        $this->setupTestResult = null;
        $this->setupReadiness = null;
        $this->manageIntegrationId = null;
        $this->showYoutubeGuide = false;
    }

    public function closeGoogleSetup(): void
    {
        $this->showGoogleSetup = false;
        $this->googleSetupStep = 1;
        $this->setupServiceAccountJson = '';
        $this->setupTestResult = null;
    }

    public function continueAfterGoogleTest(): void
    {
        abort_unless($this->canConfigureGoogle(), 403);

        if (! ($this->setupTestResult['ok'] ?? false)) {
            return;
        }

        $this->googleSetupStep = 4;
        $this->setupReadiness = app(GoogleMerchantSetupService::class)->productReadinessSummary();
    }

    public function googleSetupNext(): void
    {
        abort_unless($this->canConfigureGoogle(), 403);
        $setup = app(GoogleMerchantSetupService::class);
        $integration = $setup->integration();

        try {
            if ($this->googleSetupStep === 1) {
                $setup->saveMerchantId($integration, $this->setupMerchantId);
                $this->googleSetupStep = 2;

                return;
            }

            if ($this->googleSetupStep === 2) {
                if (trim($this->setupServiceAccountJson) !== '') {
                    $setup->saveServiceAccountJson($integration, $this->setupServiceAccountJson);
                    $this->setupServiceAccountJson = '';
                } elseif (! app(GoogleMerchantProvider::class)->hasCredentials($integration)) {
                    Notification::make()
                        ->title(__('sales_channels.errors.invalid_credentials.title'))
                        ->body(__('sales_channels.setup.service_account_required'))
                        ->warning()
                        ->send();

                    return;
                }
                $this->googleSetupStep = 3;
                $this->setupTestResult = null;

                return;
            }

            if ($this->googleSetupStep === 4) {
                $this->googleSetupStep = 5;

                return;
            }
        } catch (InvalidArgumentException $e) {
            $issue = app(HumanErrorMapper::class)->present($e->getMessage());
            Notification::make()->title($issue['title'])->body($issue['message'])->warning()->send();
        }
    }

    public function googleSetupBack(): void
    {
        if ($this->googleSetupStep > 1) {
            $this->googleSetupStep--;
        }
    }

    public function runGoogleConnectionTest(): void
    {
        abort_unless($this->canConfigureGoogle(), 403);

        $setup = app(GoogleMerchantSetupService::class);
        $integration = $setup->integration();

        if (trim($this->setupServiceAccountJson) !== '') {
            try {
                $integration = $setup->saveServiceAccountJson($integration, $this->setupServiceAccountJson);
                $this->setupServiceAccountJson = '';
            } catch (InvalidArgumentException $e) {
                $issue = app(HumanErrorMapper::class)->present($e->getMessage());
                $this->setupTestResult = ['ok' => false, 'issue' => $issue];

                return;
            }
        }

        $this->setupTestResult = $setup->testAndPrepare($integration);

        if ($this->setupTestResult['ok'] ?? false) {
            Notification::make()
                ->title(__('sales_channels.setup.test_success_title'))
                ->success()
                ->send();
        }
    }

    public function activateGoogleChannel(): void
    {
        abort_unless($this->canConfigureGoogle(), 403);

        $setup = app(GoogleMerchantSetupService::class);
        $setup->activate($setup->integration());

        Notification::make()
            ->title(__('sales_channels.setup.activated'))
            ->success()
            ->send();

        $this->closeGoogleSetup();
    }

    public function openYoutubeGuide(): void
    {
        $this->showYoutubeGuide = true;
        $this->showGoogleSetup = false;
    }

    public function closeYoutubeGuide(): void
    {
        $this->showYoutubeGuide = false;
    }

    public function openGoogleAttentionProducts(): void
    {
        $this->redirect(ProductResource::getUrl('index'));
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
        $this->showGoogleSetup = false;
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

    public function canConfigureGoogle(): bool
    {
        // Credential paste + Merchant setup is technical/admin only.
        return $this->canViewTechnical();
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
