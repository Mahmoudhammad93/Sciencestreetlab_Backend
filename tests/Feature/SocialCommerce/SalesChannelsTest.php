<?php

declare(strict_types=1);

namespace Tests\Feature\SocialCommerce;

use App\Filament\Pages\ManageSalesChannels;
use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\SocialCommerce\Application\Services\ChannelHealthService;
use App\Modules\SocialCommerce\Application\Services\GoogleMerchantSetupService;
use App\Modules\SocialCommerce\Application\Services\HumanErrorMapper;
use App\Modules\SocialCommerce\Application\Services\ProductChannelMapper;
use App\Modules\SocialCommerce\Application\Services\ProductReadinessService;
use App\Modules\SocialCommerce\Application\Services\SalesChannelManager;
use App\Modules\SocialCommerce\Domain\Enums\ConnectionStatus;
use App\Modules\SocialCommerce\Domain\Enums\HealthStatus;
use App\Modules\SocialCommerce\Domain\Enums\PublicationStatus;
use App\Modules\SocialCommerce\Domain\Enums\SalesChannelPlatform;
use App\Modules\SocialCommerce\Domain\Enums\SyncStatus;
use App\Modules\SocialCommerce\Infrastructure\Google\ServiceAccountCredentialParser;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelProduct;
use App\Modules\SocialCommerce\Infrastructure\Providers\Adapters\GoogleMerchantProvider;
use App\Modules\SocialCommerce\Infrastructure\Providers\Adapters\YouTubeShoppingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_missing_configuration_shows_setup_required(): void
    {
        app(SalesChannelManager::class)->ensureDefaults();

        $integration = SalesChannelIntegration::query()
            ->where('platform', SalesChannelPlatform::GoogleMerchant->value)
            ->firstOrFail();

        $state = app(GoogleMerchantSetupService::class)->cardState($integration);

        $this->assertSame('setup_required', $state);

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test(ManageSalesChannels::class)
            ->assertSee(__('sales_channels.states.setup_required'))
            ->assertSee(__('sales_channels.actions.setup_channel'));
    }

    public function test_credentials_are_hidden_from_serialization_and_never_include_private_key_in_public_meta(): void
    {
        $parsed = app(ServiceAccountCredentialParser::class)->parse($this->fakeServiceAccountJson());

        $integration = $this->makeIntegration([
            'external_account_id' => '1234567890',
            'credentials' => $parsed,
        ]);

        $array = $integration->fresh()->toArray();
        $this->assertArrayNotHasKey('credentials', $array);

        $meta = app(ServiceAccountCredentialParser::class)->publicMetadata($integration->fresh()->credentials);
        $this->assertTrue($meta['configured']);
        $this->assertSame('ssl@ssl-test.iam.gserviceaccount.com', $meta['client_email']);
        $this->assertArrayNotHasKey('private_key', $meta);
        $this->assertNotNull($integration->fresh()->credentials['private_key']);
    }

    public function test_unauthorized_employee_cannot_access_credentials_or_setup(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('content_manager');

        $this->actingAs($staff);

        Livewire::actingAs($staff)
            ->test(ManageSalesChannels::class)
            ->assertSuccessful()
            ->assertDontSee(__('sales_channels.actions.setup_channel'))
            ->call('openGoogleSetup')
            ->assertForbidden();
    }

    public function test_super_admin_can_configure_google_merchant(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test(ManageSalesChannels::class)
            ->assertSuccessful()
            ->call('openGoogleSetup')
            ->assertSet('showGoogleSetup', true)
            ->set('setupMerchantId', '9876543210')
            ->call('googleSetupNext')
            ->assertSet('googleSetupStep', 2)
            ->set('setupServiceAccountJson', $this->fakeServiceAccountJson())
            ->call('googleSetupNext')
            ->assertSet('googleSetupStep', 3)
            ->assertSet('setupServiceAccountJson', '');

        $integration = SalesChannelIntegration::query()
            ->where('platform', SalesChannelPlatform::GoogleMerchant->value)
            ->firstOrFail();

        $this->assertSame('9876543210', $integration->external_account_id);
        $this->assertTrue(app(GoogleMerchantProvider::class)->hasCredentials($integration));
        $this->assertArrayNotHasKey('credentials', $integration->toArray());
    }

    public function test_invalid_credential_returns_human_friendly_failure(): void
    {
        $setup = app(GoogleMerchantSetupService::class);
        $integration = $setup->integration();
        $setup->saveMerchantId($integration, '1234567890');

        try {
            $setup->saveServiceAccountJson($integration, '{"type":"service_account"}');
            $this->fail('Expected invalid credential exception');
        } catch (\InvalidArgumentException $e) {
            $issue = app(HumanErrorMapper::class)->present($e->getMessage());
            $this->assertSame(__('sales_channels.errors.invalid_credentials.title'), $issue['title']);
            $this->assertStringNotContainsString('private_key', $issue['message']);
            $this->assertStringNotContainsString('BEGIN PRIVATE', $issue['message']);
        }
    }

    public function test_merchant_access_denied_returns_human_friendly_failure(): void
    {
        $this->fakeGoogleHttp([
            'token' => true,
            'account_status' => 403,
        ]);

        $setup = app(GoogleMerchantSetupService::class);
        $integration = $setup->integration();
        $setup->saveMerchantId($integration, '1234567890');
        $integration = $setup->saveServiceAccountJson($integration, $this->fakeServiceAccountJson());

        $result = $setup->testAndPrepare($integration);

        $this->assertFalse($result['ok']);
        $this->assertSame('merchant_access_denied', $result['code']);
        $this->assertSame(
            __('sales_channels.errors.merchant_access_denied.title'),
            $result['issue']['title']
        );
        $this->assertStringNotContainsString('403', $result['issue']['message']);
        $this->assertStringNotContainsString('Permission denied', $result['issue']['message']);
    }

    public function test_successful_connection_test_marks_connected(): void
    {
        $this->fakeGoogleHttp([
            'token' => true,
            'account_status' => 200,
            'datasources' => true,
        ]);

        $setup = app(GoogleMerchantSetupService::class);
        $integration = $setup->integration();
        $setup->saveMerchantId($integration, '1234567890');
        $integration = $setup->saveServiceAccountJson($integration, $this->fakeServiceAccountJson());

        $result = $setup->testAndPrepare($integration);

        $this->assertTrue($result['ok']);
        $this->assertSame('ScienceStreetLab Store', $result['account_name']);
        $this->assertSame('******7890', $result['masked_merchant_id']);

        $fresh = $integration->fresh();
        $this->assertSame(ConnectionStatus::Connected, $fresh->connection_status);
        $this->assertSame('connected', $setup->cardState($fresh));
    }

    public function test_product_readiness_failure_prevents_submission(): void
    {
        $this->fakeGoogleHttp([
            'token' => true,
            'account_status' => 200,
            'datasources' => true,
            'insert' => true,
        ]);

        $integration = $this->makeConnectedGoogleIntegration();

        $product = Product::query()->create([
            'sku' => 'SC-BAD-1',
            'slug' => 'sc-bad-1',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 0,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => '', 'ar' => ''],
            'manage_stock' => false,
            'stock_quantity' => 0,
        ]);

        $result = app(SalesChannelManager::class)->runSync($integration);

        $channelProduct = SalesChannelProduct::query()
            ->where('product_id', $product->id)
            ->where('integration_id', $integration->id)
            ->firstOrFail();

        $this->assertSame(PublicationStatus::NeedsAttention, $channelProduct->publication_status);
        $this->assertSame(SyncStatus::Failed, $channelProduct->sync_status);
        $this->assertNotSame(SyncStatus::Synced, $result->sync_status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'productInputs:insert'));
    }

    public function test_ready_product_can_reach_provider_sync(): void
    {
        $this->fakeGoogleHttp([
            'token' => true,
            'account_status' => 200,
            'datasources' => true,
            'insert' => true,
        ]);
        Storage::fake('public');

        $integration = $this->makeConnectedGoogleIntegration();
        $product = $this->makeReadyProduct();

        $result = app(SalesChannelManager::class)->runSync($integration);

        $this->assertSame(SyncStatus::Synced, $result->sync_status);

        $channelProduct = SalesChannelProduct::query()
            ->where('product_id', $product->id)
            ->where('integration_id', $integration->id)
            ->firstOrFail();

        $this->assertSame(PublicationStatus::Published, $channelProduct->publication_status);
        $this->assertSame(SyncStatus::Synced, $channelProduct->sync_status);
        $this->assertNotNull($channelProduct->external_product_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'productInputs:insert'));
    }

    public function test_provider_failure_is_sanitized_for_staff(): void
    {
        $issue = app(HumanErrorMapper::class)->present('merchant_access_denied');

        $this->assertStringNotContainsString('merchant_access_denied', $issue['title']);
        $this->assertStringNotContainsString('merchant_access_denied', $issue['message']);
        $this->assertStringNotContainsString('403', $issue['message']);
        $this->assertSame('merchant_access_denied', $issue['code']);
    }

    public function test_duplicate_sync_remains_protected(): void
    {
        Queue::fake();

        $integration = $this->makeConnectedGoogleIntegration();
        $manager = app(SalesChannelManager::class);

        $manager->requestSync($integration);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sync_in_progress');
        $manager->requestSync($integration->fresh() ?? $integration);
    }

    public function test_youtube_does_not_become_connected_when_merchant_connects(): void
    {
        $this->fakeGoogleHttp([
            'token' => true,
            'account_status' => 200,
            'datasources' => true,
        ]);

        $setup = app(GoogleMerchantSetupService::class);
        $google = $setup->integration();
        $setup->saveMerchantId($google, '1234567890');
        $google = $setup->saveServiceAccountJson($google, $this->fakeServiceAccountJson());
        $setup->testAndPrepare($google);

        $youtube = SalesChannelIntegration::query()
            ->where('platform', SalesChannelPlatform::YouTubeShopping->value)
            ->firstOrFail();

        $this->assertSame(ConnectionStatus::NotConnected, $youtube->fresh()->connection_status);
        $this->assertFalse(app(YouTubeShoppingProvider::class)->isConfigured());
    }

    public function test_youtube_shows_merchant_dependency_correctly(): void
    {
        Config::set('sales_channels.youtube_shopping.eligibility_confirmed', false);
        Config::set('sales_channels.youtube_shopping.store_linked', false);

        app(SalesChannelManager::class)->ensureDefaults();

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test(ManageSalesChannels::class)
            ->assertSee(__('sales_channels.youtube.status.merchant_required'))
            ->assertSee(__('sales_channels.youtube.summary.merchant_required'));

        $this->fakeGoogleHttp([
            'token' => true,
            'account_status' => 200,
            'datasources' => true,
        ]);

        $setup = app(GoogleMerchantSetupService::class);
        $google = $setup->integration();
        $setup->saveMerchantId($google, '1234567890');
        $google = $setup->saveServiceAccountJson($google, $this->fakeServiceAccountJson());
        $setup->testAndPrepare($google);

        Livewire::actingAs($admin)
            ->test(ManageSalesChannels::class)
            ->assertSee(__('sales_channels.youtube.status.eligibility_pending'))
            ->assertSee(__('sales_channels.youtube.summary.eligibility_pending'));
    }

    public function test_expired_connection_maps_to_human_reconnect_copy(): void
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

    public function test_duplicate_product_mappings_protected(): void
    {
        $integration = $this->makeConnectedGoogleIntegration();
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

    public function test_arabic_localization_works(): void
    {
        app()->setLocale('ar');

        $this->assertSame('قنوات البيع', __('sales_channels.title'));
        $this->assertSame('متصل', __('sales_channels.connection.connected'));
        $this->assertSame('يتطلب الإعداد', __('sales_channels.states.setup_required'));
        $this->assertSame('إعداد القناة', __('sales_channels.actions.setup_channel'));

        $issue = app(HumanErrorMapper::class)->present('merchant_access_denied');
        $this->assertSame('تعذر الاتصال بـ Google Merchant Center', $issue['title']);
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

    public function test_unauthorized_users_cannot_open_sales_channels_page(): void
    {
        $user = User::factory()->create();
        $user->assignRole('order_manager');

        Livewire::actingAs($user)
            ->test(ManageSalesChannels::class)
            ->assertForbidden();
    }

    /**
     * @param  array{token?: bool, account_status?: int, datasources?: bool, insert?: bool}  $opts
     */
    private function fakeGoogleHttp(array $opts): void
    {
        $responses = [];

        if ($opts['token'] ?? false) {
            $responses['https://oauth2.googleapis.com/token'] = Http::response([
                'access_token' => 'ya29.fake-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200);
        }

        $accountStatus = (int) ($opts['account_status'] ?? 200);
        $responses['https://merchantapi.googleapis.com/accounts/v1/accounts/*'] = Http::response(
            $accountStatus === 200
                ? ['name' => 'accounts/1234567890', 'accountName' => 'ScienceStreetLab Store']
                : ['error' => ['message' => 'Permission denied', 'status' => 'PERMISSION_DENIED']],
            $accountStatus
        );

        if ($opts['datasources'] ?? false) {
            $responses['https://merchantapi.googleapis.com/datasources/v1/accounts/*/dataSources'] = Http::response([
                'dataSources' => [[
                    'name' => 'accounts/1234567890/dataSources/1',
                    'input' => 'API',
                    'primaryProductDataSource' => ['countries' => ['EG']],
                ]],
            ], 200);
        }

        if ($opts['insert'] ?? false) {
            $responses['https://merchantapi.googleapis.com/products/v1/accounts/*/productInputs:insert*'] = Http::response([
                'name' => 'accounts/1234567890/productInputs/offer-1',
                'offerId' => 'offer-1',
                'product' => 'accounts/1234567890/products/en~EG~offer-1',
            ], 200);
        }

        Http::fake($responses);
    }

    private function makeConnectedGoogleIntegration(): SalesChannelIntegration
    {
        $parsed = app(ServiceAccountCredentialParser::class)->parse($this->fakeServiceAccountJson());

        return $this->makeIntegration([
            'external_account_id' => '1234567890',
            'external_account_name' => 'ScienceStreetLab Store',
            'credentials' => $parsed,
            'connection_status' => ConnectionStatus::Connected,
            'sync_status' => SyncStatus::NeverSynced,
            'health_status' => HealthStatus::Healthy,
            'settings' => [
                'merchant_id' => '1234567890',
                'has_service_account' => true,
                'data_source_name' => 'accounts/1234567890/dataSources/1',
                'activated' => true,
                'automatic_sync' => true,
            ],
        ]);
    }

    private function fakeServiceAccountJson(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);

        return json_encode([
            'type' => 'service_account',
            'project_id' => 'ssl-test',
            'private_key_id' => 'abc123',
            'private_key' => $privateKey,
            'client_email' => 'ssl@ssl-test.iam.gserviceaccount.com',
            'client_id' => '123456789',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR);
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
