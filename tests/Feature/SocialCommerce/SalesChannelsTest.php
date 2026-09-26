<?php

declare(strict_types=1);

namespace Tests\Feature\SocialCommerce;

use App\Filament\Pages\ManageSalesChannels;
use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\SocialCommerce\Application\Services\ChannelHealthService;
use App\Modules\SocialCommerce\Application\Services\HumanErrorMapper;
use App\Modules\SocialCommerce\Application\Services\ProductChannelMapper;
use App\Modules\SocialCommerce\Application\Services\ProductReadinessService;
use App\Modules\SocialCommerce\Application\Services\SalesChannelManager;
use App\Modules\SocialCommerce\Domain\Enums\ConnectionStatus;
use App\Modules\SocialCommerce\Domain\Enums\HealthStatus;
use App\Modules\SocialCommerce\Domain\Enums\PublicationStatus;
use App\Modules\SocialCommerce\Domain\Enums\SalesChannelPlatform;
use App\Modules\SocialCommerce\Domain\Enums\SyncStatus;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class SalesChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_integration_defaults_to_not_connected(): void
    {
        app(SalesChannelManager::class)->ensureDefaults();

        $integration = SalesChannelIntegration::query()
            ->where('platform', SalesChannelPlatform::GoogleMerchant->value)
            ->firstOrFail();

        $this->assertSame(ConnectionStatus::NotConnected, $integration->connection_status);
        $this->assertSame(SyncStatus::NeverSynced, $integration->sync_status);
        $this->assertSame(HealthStatus::Unavailable, $integration->health_status);
    }

    public function test_connected_integration_displays_correct_human_status(): void
    {
        $integration = $this->makeIntegration([
            'connection_status' => ConnectionStatus::Connected,
            'sync_status' => SyncStatus::Synced,
            'health_status' => HealthStatus::Healthy,
        ]);

        $summary = app(ChannelHealthService::class)->summarize($integration);

        $this->assertSame('connected', $summary['connection_status']);
        $this->assertSame(__('sales_channels.connection.connected'), $summary['connection_label']);
        $this->assertSame(__('sales_channels.actions.manage'), $summary['recommended_action']);
    }

    public function test_expired_connection_maps_to_reconnect_action(): void
    {
        $integration = $this->makeIntegration([
            'connection_status' => ConnectionStatus::Expired,
            'sync_status' => SyncStatus::Failed,
            'health_status' => HealthStatus::NeedsAttention,
            'last_error_code' => 'token_refresh_failed',
        ]);

        $summary = app(ChannelHealthService::class)->summarize($integration);
        $issue = app(HumanErrorMapper::class)->present('token_refresh_failed');

        $this->assertSame(__('sales_channels.actions.reconnect'), $summary['recommended_action']);
        $this->assertSame(__('sales_channels.errors.connection_expired.title'), $issue['title']);
        $this->assertStringContainsString('Reconnect', $issue['message']);
        $this->assertSame(__('sales_channels.actions.reconnect'), $issue['action']);
    }

    public function test_syncing_state_represented_correctly(): void
    {
        $integration = $this->makeIntegration([
            'connection_status' => ConnectionStatus::Connected,
            'sync_status' => SyncStatus::Syncing,
            'health_status' => HealthStatus::Healthy,
        ]);

        $summary = app(ChannelHealthService::class)->summarize($integration);

        $this->assertSame('syncing', $summary['sync_status']);
        $this->assertSame(__('sales_channels.sync.syncing'), $summary['sync_label']);
        $this->assertSame(__('sales_channels.summary.syncing'), $summary['summary']);
    }

    public function test_failed_sync_becomes_needs_attention(): void
    {
        $integration = $this->makeIntegration([
            'connection_status' => ConnectionStatus::Connected,
            'sync_status' => SyncStatus::Failed,
            'last_error_code' => 'partial_or_failed_sync',
        ]);

        $health = app(ChannelHealthService::class)->resolveHealth($integration);

        $this->assertSame(HealthStatus::NeedsAttention, $health);
    }

    public function test_successful_sync_updates_timestamps_and_status(): void
    {
        Config::set('sales_channels.google_merchant.enabled', true);
        Config::set('sales_channels.google_merchant.client_id', 'test-client');
        Config::set('sales_channels.google_merchant.client_secret', 'test-secret');
        Config::set('sales_channels.google_merchant.api_contract_ready', true);
        Storage::fake('public');

        $integration = $this->makeIntegration([
            'connection_status' => ConnectionStatus::Connected,
            'sync_status' => SyncStatus::NeverSynced,
        ]);

        $product = $this->makeReadyProduct();

        $result = app(SalesChannelManager::class)->runSync($integration);

        $this->assertSame(SyncStatus::Synced, $result->sync_status);
        $this->assertNotNull($result->last_synced_at);
        $this->assertNotNull($result->last_successful_sync_at);
        $this->assertSame(HealthStatus::Healthy, $result->health_status);

        $channelProduct = SalesChannelProduct::query()
            ->where('product_id', $product->id)
            ->where('integration_id', $integration->id)
            ->firstOrFail();

        $this->assertSame(PublicationStatus::Published, $channelProduct->publication_status);
        $this->assertSame(SyncStatus::Synced, $channelProduct->sync_status);
    }

    public function test_credentials_are_not_exposed_in_model_array(): void
    {
        $integration = $this->makeIntegration([
            'credentials' => [
                'access_token' => 'super-secret-token',
                'refresh_token' => 'super-secret-refresh',
            ],
        ]);

        $array = $integration->fresh()->toArray();

        $this->assertArrayNotHasKey('credentials', $array);
        $this->assertSame('super-secret-token', $integration->fresh()->credentials['access_token']);
    }

    public function test_unauthorized_users_cannot_manage_integrations(): void
    {
        $user = User::factory()->create();
        $user->assignRole('order_manager');

        $this->assertFalse(ManageSalesChannels::canAccess());

        $this->actingAs($user);
        $this->assertFalse(ManageSalesChannels::canAccess());

        Livewire::actingAs($user)
            ->test(ManageSalesChannels::class)
            ->assertForbidden();
    }

    public function test_authorized_admin_can_manage_integrations(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($admin);
        $this->assertTrue(ManageSalesChannels::canAccess());

        Livewire::actingAs($admin)
            ->test(ManageSalesChannels::class)
            ->assertSuccessful()
            ->assertSee(__('sales_channels.title'));
    }

    public function test_duplicate_sync_cannot_create_duplicate_product_mappings(): void
    {
        $integration = $this->makeIntegration([
            'connection_status' => ConnectionStatus::Connected,
        ]);
        $product = $this->makeReadyProduct();
        $manager = app(SalesChannelManager::class);

        $first = $manager->upsertChannelProduct($integration, $product);
        $second = $manager->upsertChannelProduct($integration, $product);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SalesChannelProduct::query()
            ->where('product_id', $product->id)
            ->where('integration_id', $integration->id)
            ->count());
    }

    public function test_product_readiness_identifies_missing_required_fields(): void
    {
        $product = Product::query()->create([
            'sku' => 'SC-READY-1',
            'slug' => 'sc-ready-1',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 0,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => '', 'ar' => ''],
            'manage_stock' => false,
            'stock_quantity' => 0,
        ]);

        $issues = app(ProductReadinessService::class)->blockingIssueCodes($product);

        $this->assertContains('title', $issues);
        $this->assertContains('price', $issues);
        $this->assertContains('main_image', $issues);
        $this->assertFalse(app(ProductReadinessService::class)->isReady($product));
    }

    public function test_product_status_maps_to_understandable_issue(): void
    {
        $issue = app(HumanErrorMapper::class)->present('missing_main_image');

        $this->assertSame(__('sales_channels.errors.missing_image.title'), $issue['title']);
        $this->assertStringContainsString('main image', strtolower($issue['message']));
        $this->assertSame(__('sales_channels.actions.fix_product'), $issue['action']);
        $this->assertSame('missing_main_image', $issue['code']);
    }

    public function test_provider_technical_errors_are_not_directly_exposed_to_normal_users(): void
    {
        $issue = app(HumanErrorMapper::class)->present('token_refresh_failed');

        $this->assertStringNotContainsString('token_refresh_failed', $issue['title']);
        $this->assertStringNotContainsString('token_refresh_failed', $issue['message']);
        $this->assertStringNotContainsString('oauth', strtolower($issue['message']));
        $this->assertSame('token_refresh_failed', $issue['code']); // internal only
    }

    public function test_arabic_localization_works(): void
    {
        app()->setLocale('ar');

        $this->assertSame('قنوات البيع', __('sales_channels.title'));
        $this->assertSame('متصل', __('sales_channels.connection.connected'));
        $this->assertSame('يحتاج متابعة', __('sales_channels.health.needs_attention'));
        $this->assertSame('إعادة الاتصال', __('sales_channels.actions.reconnect'));

        $issue = app(HumanErrorMapper::class)->present('token_refresh_failed');
        $this->assertSame('انتهت صلاحية الاتصال', $issue['title']);
    }

    public function test_product_mapping_produces_expected_provider_neutral_data(): void
    {
        Config::set('sciencestreet.frontend_url', 'https://shop.example.test');
        Config::set('sciencestreet.name', 'Science Street Lab');
        Storage::fake('public');

        $product = $this->makeReadyProduct();

        $data = app(ProductChannelMapper::class)->map($product);

        $this->assertSame($product->sku, $data->sku);
        $this->assertSame('Sales Channel Kit', $data->title);
        $this->assertSame('199.00', $data->price);
        $this->assertSame('EGP', $data->currency);
        $this->assertSame('in_stock', $data->availability);
        $this->assertSame('https://shop.example.test/shop/'.$product->slug, $data->url);
        $this->assertNotNull($data->mainImage);
        $this->assertSame('Science Street Lab', $data->brand);
        $this->assertSame('new', $data->condition);
    }

    public function test_request_sync_prevents_duplicate_concurrent_jobs(): void
    {
        Queue::fake();

        $integration = $this->makeIntegration([
            'connection_status' => ConnectionStatus::Connected,
        ]);
        $manager = app(SalesChannelManager::class);

        $manager->requestSync($integration);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sync_in_progress');
        $manager->requestSync($integration->fresh() ?? $integration);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeIntegration(array $overrides = []): SalesChannelIntegration
    {
        return SalesChannelIntegration::query()->create(array_merge([
            'platform' => SalesChannelPlatform::GoogleMerchant->value,
            'name' => 'Google Merchant Center',
            'connection_status' => ConnectionStatus::NotConnected,
            'sync_status' => SyncStatus::NeverSynced,
            'health_status' => HealthStatus::Unavailable,
            'settings' => [],
        ], $overrides));
    }

    private function makeReadyProduct(): Product
    {
        $product = Product::query()->create([
            'sku' => 'SC-KIT-'.uniqid(),
            'slug' => 'sc-kit-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 199,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Sales Channel Kit', 'ar' => 'طقم قنوات البيع'],
            'description' => ['en' => 'A kit for labs.', 'ar' => 'طقم للمعامل.'],
            'manage_stock' => true,
            'stock_quantity' => 10,
        ]);

        $product->addMedia(UploadedFile::fake()->image('main.jpg'))
            ->toMediaCollection('image');

        return $product->fresh() ?? $product;
    }
}
