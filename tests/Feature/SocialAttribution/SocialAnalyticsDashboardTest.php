<?php

declare(strict_types=1);

namespace Tests\Feature\SocialAttribution;

use App\Filament\Pages\SocialAnalyticsDashboard;
use App\Models\User;
use App\Modules\SocialAttribution\Application\Services\Analytics\AnalyticsFilters;
use App\Modules\SocialAttribution\Application\Services\Analytics\SocialAnalyticsService;
use App\Modules\SocialAttribution\Application\Services\Analytics\UnavailableExternalPlatformMetricsProvider;
use App\Modules\SocialAttribution\Domain\Enums\AttributionPlatform;
use App\Modules\SocialAttribution\Domain\Enums\CampaignStatus;
use App\Modules\SocialAttribution\Domain\Enums\ChannelKind;
use App\Modules\SocialAttribution\Domain\Enums\ContentType;
use App\Modules\SocialAttribution\Domain\Enums\ConversionEventName;
use App\Modules\SocialAttribution\Domain\Enums\DestinationType;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\AttributionConversion;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\OrderAttribution;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\SocialCampaign;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\SocialContent;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\TrackingLink;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\TrackingLinkClick;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class SocialAnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private SocialAnalyticsService $analytics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analytics = new SocialAnalyticsService(new UnavailableExternalPlatformMetricsProvider);
    }

    public function test_raw_human_unique_clicks_and_platform_grouping(): void
    {
        $ig = $this->seedLink(AttributionPlatform::Instagram, 'ig-a');
        $fb = $this->seedLink(AttributionPlatform::Facebook, 'fb-a');
        $yt = $this->seedLink(AttributionPlatform::YouTube, 'yt-a');

        $this->click($ig, bot: false, unique: true);
        $this->click($ig, bot: false, unique: true);
        $this->click($ig, bot: true, unique: false);
        $this->click($ig, bot: false, unique: false);
        $this->click($fb, bot: false, unique: true);
        $this->click($yt, bot: false, unique: true);
        $this->click($yt, bot: false, unique: true);

        $filters = AnalyticsFilters::fromRange('all');
        $clicks = $this->analytics->clickStatsByPlatform($filters);

        $this->assertSame(4, $clicks['instagram']['raw']);
        $this->assertSame(3, $clicks['instagram']['human']);
        $this->assertSame(2, $clicks['instagram']['unique']);
        $this->assertSame(1, $clicks['facebook']['raw']);
        $this->assertSame(2, $clicks['youtube']['raw']);
        $this->assertSame(2, $clicks['youtube']['unique']);
    }

    public function test_purchase_revenue_conversion_aov_and_duplicate_click_safety(): void
    {
        $ig = $this->seedLink(AttributionPlatform::Instagram, 'ig-buy');
        // Many clicks must not multiply one purchase.
        $this->click($ig, unique: true);
        $this->click($ig, unique: false);
        $this->click($ig, unique: false);

        $order = $this->makePaidOrder(total: 250, currency: 'EGP');
        $this->attributeAndConvert($order, $ig, platform: 'instagram', value: 250);

        $filters = AnalyticsFilters::fromRange('all');
        $dash = $this->analytics->dashboard($filters);
        $igRow = collect($dash['platforms'])->firstWhere('platform', 'instagram');

        $this->assertSame(1, $igRow['purchases']);
        $this->assertSame(250.0, $igRow['revenue']['amount']);
        $this->assertSame(1, $dash['kpis']['purchases']);
        $this->assertSame(250.0, $dash['kpis']['revenue']['amount']);
        $this->assertSame(100.0, $dash['kpis']['conversion_rate']); // 1 / 1 unique
        $this->assertSame(250.0, $dash['kpis']['aov']);
    }

    public function test_date_and_platform_filters(): void
    {
        $ig = $this->seedLink(AttributionPlatform::Instagram, 'ig-date');
        $yt = $this->seedLink(AttributionPlatform::YouTube, 'yt-date');

        TrackingLinkClick::query()->create([
            'uuid' => (string) Str::uuid(),
            'tracking_link_id' => $ig->id,
            'visitor_key' => (string) Str::uuid(),
            'is_bot' => false,
            'is_unique_human' => true,
            'occurred_at' => now()->subDays(40),
            'unique_click_key' => 'old-ig',
        ]);
        $this->click($ig, unique: true);
        $this->click($yt, unique: true);

        $last30 = $this->analytics->clickStatsByPlatform(AnalyticsFilters::fromRange('30d'));
        $this->assertSame(1, $last30['instagram']['raw'] ?? 0);
        $this->assertSame(1, $last30['youtube']['raw'] ?? 0);

        $igOnly = $this->analytics->dashboard(AnalyticsFilters::fromRange('30d', 'instagram'));
        $this->assertCount(1, $igOnly['platforms']);
        $this->assertSame('instagram', $igOnly['platforms'][0]['platform']);
        $this->assertSame(1, $igOnly['kpis']['raw_clicks']);
    }

    public function test_first_vs_last_touch_attribution_not_double_counted(): void
    {
        $ig = $this->seedLink(AttributionPlatform::Instagram, 'ig-ft');
        $yt = $this->seedLink(AttributionPlatform::YouTube, 'yt-lt');
        $this->click($ig, unique: true);
        $this->click($yt, unique: true);

        $order = $this->makePaidOrder(total: 500, currency: 'EGP');
        OrderAttribution::query()->create([
            'order_id' => $order->id,
            'order_uuid' => $order->uuid,
            'visitor_key' => (string) Str::uuid(),
            'converting_platform' => 'youtube',
            'converting_campaign_id' => $yt->campaign_id,
            'converting_content_id' => $yt->social_content_id,
            'converting_tracking_link_id' => $yt->id,
            'converting_code' => $yt->code,
            'first_touch' => [
                'tracking_link_id' => $ig->id,
                'tracking_code' => $ig->code,
                'platform' => 'instagram',
                'campaign_id' => $ig->campaign_id,
                'content_id' => $ig->social_content_id,
            ],
            'last_touch' => [
                'tracking_link_id' => $yt->id,
                'tracking_code' => $yt->code,
                'platform' => 'youtube',
                'campaign_id' => $yt->campaign_id,
                'content_id' => $yt->social_content_id,
            ],
            'attribution_model' => 'last_touch_v1',
            'window_days' => 30,
            'is_attributed' => true,
            'captured_at' => now(),
        ]);
        AttributionConversion::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'order_uuid' => $order->uuid,
            'event_name' => ConversionEventName::Purchase->value,
            'event_id' => 'purchase:'.$order->uuid,
            'platform' => 'youtube',
            'campaign_id' => $yt->campaign_id,
            'content_id' => $yt->social_content_id,
            'tracking_link_id' => $yt->id,
            'value' => 500,
            'currency' => 'EGP',
            'occurred_at' => now(),
            'is_attributed' => true,
        ]);

        $last = $this->analytics->dashboard(AnalyticsFilters::fromRange('all', null, 'last_touch'));
        $first = $this->analytics->dashboard(AnalyticsFilters::fromRange('all', null, 'first_touch'));

        $lastYt = collect($last['platforms'])->firstWhere('platform', 'youtube');
        $lastIg = collect($last['platforms'])->firstWhere('platform', 'instagram');
        $firstYt = collect($first['platforms'])->firstWhere('platform', 'youtube');
        $firstIg = collect($first['platforms'])->firstWhere('platform', 'instagram');

        $this->assertSame(1, $lastYt['purchases']);
        $this->assertSame(500.0, $lastYt['revenue']['amount']);
        $this->assertSame(0, $lastIg['purchases']);
        $this->assertSame(1, $firstIg['purchases']);
        $this->assertSame(500.0, $firstIg['revenue']['amount']);
        $this->assertSame(0, $firstYt['purchases']);
        // Never double-count inside one attribution view.
        $this->assertSame(1, $last['kpis']['purchases']);
        $this->assertSame(1, $first['kpis']['purchases']);
        $this->assertSame(500.0, $last['kpis']['revenue']['amount']);
        $this->assertSame(500.0, $first['kpis']['revenue']['amount']);
    }

    public function test_unattributed_purchase_not_assigned_to_social_platform(): void
    {
        $order = $this->makePaidOrder(total: 80, currency: 'EGP');
        AttributionConversion::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'order_uuid' => $order->uuid,
            'event_name' => ConversionEventName::Purchase->value,
            'event_id' => 'purchase:'.$order->uuid,
            'platform' => null,
            'value' => 80,
            'currency' => 'EGP',
            'occurred_at' => now(),
            'is_attributed' => false,
        ]);

        $dash = $this->analytics->dashboard(AnalyticsFilters::fromRange('all'));
        foreach ($dash['platforms'] as $p) {
            $this->assertSame(0, $p['purchases']);
            $this->assertSame(0.0, $p['revenue']['amount']);
        }
        $this->assertSame(1, $dash['unattributed']['purchases']);
        $this->assertSame(80.0, $dash['unattributed']['revenues'][0]['amount']);
        $this->assertSame(0, $dash['kpis']['purchases']);
    }

    public function test_views_unavailable_not_zero(): void
    {
        $dash = $this->analytics->dashboard(AnalyticsFilters::fromRange('all'));
        foreach ($dash['platforms'] as $p) {
            $this->assertFalse($p['views_available']);
            $this->assertNull($p['views']);
            $this->assertSame('unavailable', $p['views_label']);
        }
    }

    public function test_multi_currency_not_blindly_summed(): void
    {
        $ig = $this->seedLink(AttributionPlatform::Instagram, 'ig-fx');
        $o1 = $this->makePaidOrder(total: 100, currency: 'EGP');
        $o2 = $this->makePaidOrder(total: 20, currency: 'USD');
        $this->attributeAndConvert($o1, $ig, platform: 'instagram', value: 100, currency: 'EGP');
        $this->attributeAndConvert($o2, $ig, platform: 'instagram', value: 20, currency: 'USD');

        $dash = $this->analytics->dashboard(AnalyticsFilters::fromRange('all'));
        $this->assertTrue($dash['kpis']['revenue_multi_currency']);
        $this->assertFalse($dash['kpis']['revenue']['displayable']);
        $this->assertCount(2, $dash['kpis']['revenues']);
        $igRow = collect($dash['platforms'])->firstWhere('platform', 'instagram');
        $this->assertFalse($igRow['revenue']['displayable']);
    }

    public function test_campaign_content_and_tracking_link_aggregation(): void
    {
        $ig = $this->seedLink(AttributionPlatform::Instagram, 'ig-agg');
        $this->click($ig, unique: true);
        $this->click($ig, unique: true);
        $order = $this->makePaidOrder(total: 120, currency: 'EGP');
        $this->attributeAndConvert($order, $ig, platform: 'instagram', value: 120);

        $dash = $this->analytics->dashboard(AnalyticsFilters::fromRange('all'));

        $this->assertNotEmpty($dash['campaigns']);
        $camp = $dash['campaigns'][0];
        $this->assertSame(2, $camp['raw_clicks']);
        $this->assertSame(1, $camp['purchases']);
        $this->assertSame(120.0, $camp['revenue']['amount']);

        $this->assertNotEmpty($dash['contents']);
        $this->assertFalse($dash['contents'][0]['views_available']);
        $this->assertNull($dash['contents'][0]['views']);

        $this->assertNotEmpty($dash['tracking_links']);
        $link = collect($dash['tracking_links'])->firstWhere('code', 'ig-agg');
        $this->assertSame(2, $link['raw_clicks']);
        $this->assertSame(1, $link['purchases']);
        $this->assertSame('/go/ig-agg', parse_url((string) $link['tracking_url'], PHP_URL_PATH));
    }

    public function test_admin_can_access_dashboard_guest_cannot(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->get('/admin/social-analytics')->assertRedirect();

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test(SocialAnalyticsDashboard::class)
            ->assertSuccessful()
            ->assertSee(__('admin.social_analytics.kpis.raw_clicks'))
            ->assertSee(__('admin.social_analytics.views_unavailable'));

        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->assertFalse($customer->canAccessPanel(filament()->getPanel('admin')));
        $this->assertFalse(SocialAnalyticsDashboard::canAccess());
    }

    public function test_zero_denominator_conversion_rate_safe(): void
    {
        $filters = AnalyticsFilters::fromRange('all');
        $dash = $this->analytics->dashboard($filters);
        $this->assertNull($dash['kpis']['conversion_rate']);
        $this->assertNull($dash['kpis']['aov']);
    }

    private function seedLink(AttributionPlatform $platform, string $code): TrackingLink
    {
        $campaign = SocialCampaign::query()->create([
            'platform' => $platform,
            'name' => 'Campaign '.$code,
            'code' => 'camp-'.$code,
            'channel_kind' => ChannelKind::Paid,
            'status' => CampaignStatus::Active,
        ]);
        $content = SocialContent::query()->create([
            'campaign_id' => $campaign->id,
            'platform' => $platform,
            'content_type' => match ($platform) {
                AttributionPlatform::YouTube => ContentType::YoutubeVideo,
                AttributionPlatform::Facebook => ContentType::FacebookPost,
                default => ContentType::InstagramReel,
            },
            'title' => 'Content '.$code,
            'status' => CampaignStatus::Active,
            'destination_type' => DestinationType::LandingPage,
            'destination_path' => '/microscope-landing-page',
        ]);

        return TrackingLink::query()->create([
            'code' => $code,
            'platform' => $platform,
            'campaign_id' => $campaign->id,
            'social_content_id' => $content->id,
            'label' => $code,
            'destination_type' => DestinationType::LandingPage,
            'destination_path' => '/microscope-landing-page',
            'is_enabled' => true,
            'status' => CampaignStatus::Active,
        ]);
    }

    private function click(TrackingLink $link, bool $bot = false, bool $unique = true): void
    {
        TrackingLinkClick::query()->create([
            'uuid' => (string) Str::uuid(),
            'tracking_link_id' => $link->id,
            'visitor_key' => (string) Str::uuid(),
            'is_bot' => $bot,
            'is_unique_human' => $unique && ! $bot,
            'occurred_at' => now(),
            'unique_click_key' => $unique ? (string) Str::uuid() : null,
        ]);
    }

    private function makePaidOrder(float $total, string $currency = 'EGP'): Order
    {
        $user = User::factory()->create();

        return Order::query()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Paid->value,
            'subtotal' => $total,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => $total,
            'currency' => $currency,
            'paid_at' => now(),
            'billing_address' => [
                'first_name' => 'A',
                'last_name' => 'B',
                'email' => 'a@example.com',
                'phone' => '01000000000',
                'city' => 'Cairo',
                'country' => 'EG',
                'address' => 'St',
            ],
            'shipping_address' => [
                'first_name' => 'A',
                'last_name' => 'B',
                'email' => 'a@example.com',
                'phone' => '01000000000',
                'city' => 'Cairo',
                'country' => 'EG',
                'address' => 'St',
            ],
        ]);
    }

    private function attributeAndConvert(
        Order $order,
        TrackingLink $link,
        string $platform,
        float $value,
        string $currency = 'EGP',
    ): void {
        OrderAttribution::query()->create([
            'order_id' => $order->id,
            'order_uuid' => $order->uuid,
            'visitor_key' => (string) Str::uuid(),
            'converting_platform' => $platform,
            'converting_campaign_id' => $link->campaign_id,
            'converting_content_id' => $link->social_content_id,
            'converting_tracking_link_id' => $link->id,
            'converting_code' => $link->code,
            'first_touch' => [
                'tracking_link_id' => $link->id,
                'platform' => $platform,
                'campaign_id' => $link->campaign_id,
                'content_id' => $link->social_content_id,
            ],
            'last_touch' => [
                'tracking_link_id' => $link->id,
                'platform' => $platform,
                'campaign_id' => $link->campaign_id,
                'content_id' => $link->social_content_id,
            ],
            'attribution_model' => 'last_touch_v1',
            'window_days' => 30,
            'is_attributed' => true,
            'captured_at' => now(),
        ]);

        AttributionConversion::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'order_uuid' => $order->uuid,
            'event_name' => ConversionEventName::Purchase->value,
            'event_id' => 'purchase:'.$order->uuid,
            'platform' => $platform,
            'campaign_id' => $link->campaign_id,
            'content_id' => $link->social_content_id,
            'tracking_link_id' => $link->id,
            'value' => $value,
            'currency' => $currency,
            'occurred_at' => $order->paid_at ?? now(),
            'is_attributed' => true,
        ]);
    }
}
