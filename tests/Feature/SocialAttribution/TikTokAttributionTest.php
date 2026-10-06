<?php

declare(strict_types=1);

namespace Tests\Feature\SocialAttribution;

use App\Filament\Pages\SocialAnalyticsDashboard;
use App\Filament\Resources\SocialCampaignResource;
use App\Filament\Resources\SocialContentResource;
use App\Filament\Resources\TrackingLinkResource;
use App\Models\User;
use App\Modules\SocialAttribution\Application\Services\Analytics\AnalyticsFilters;
use App\Modules\SocialAttribution\Application\Services\Analytics\SocialAnalyticsService;
use App\Modules\SocialAttribution\Application\Services\Analytics\UnavailableExternalPlatformMetricsProvider;
use App\Modules\SocialAttribution\Application\Support\AttributionCookie;
use App\Modules\SocialAttribution\Domain\Enums\AttributionPlatform;
use App\Modules\SocialAttribution\Domain\Enums\CampaignStatus;
use App\Modules\SocialAttribution\Domain\Enums\ChannelKind;
use App\Modules\SocialAttribution\Domain\Enums\ContentType;
use App\Modules\SocialAttribution\Domain\Enums\ConversionEventName;
use App\Modules\SocialAttribution\Domain\Enums\DestinationType;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\AttributionConversion;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\AttributionSession;
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

final class TikTokAttributionTest extends TestCase
{
    use RefreshDatabase;

    private string $frontend = 'https://store.test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'sciencestreet.frontend_url' => $this->frontend,
            'social_attribution.window_days' => 30,
            'social_attribution.hash_pepper' => 'test-pepper',
            'social_attribution.cookie.secure' => false,
        ]);
    }

    public function test_tiktok_is_first_class_platform_in_enum_and_filament_options(): void
    {
        $this->assertSame('tiktok', AttributionPlatform::TikTok->value);
        $this->assertSame('TikTok', AttributionPlatform::TikTok->label());
        $this->assertSame('TikTok Video', ContentType::TiktokVideo->label());
        $this->assertContains(AttributionPlatform::TikTok->value, AttributionPlatform::values());

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test(SocialAnalyticsDashboard::class)
            ->assertSuccessful()
            ->assertSee('TikTok')
            ->assertSee(__('admin.social_analytics.landing_views_unavailable'))
            ->assertSee(__('admin.social_analytics.views_unavailable'));
    }

    public function test_tiktok_campaign_content_tracking_link_and_redirect(): void
    {
        $bundle = $this->makeTikTokBundle(code: 'tt-micro-'.Str::lower(Str::random(4)));

        $this->assertDatabaseHas('social_campaigns', [
            'id' => $bundle['campaign']->id,
            'platform' => 'tiktok',
            'name' => 'Microscope TikTok Test',
        ]);
        $this->assertDatabaseHas('social_contents', [
            'id' => $bundle['content']->id,
            'platform' => 'tiktok',
            'content_type' => ContentType::TiktokVideo->value,
        ]);
        $this->assertSame('tiktok', $bundle['link']->utm_source);
        $this->assertSame('social', $bundle['link']->utm_medium);

        $normalized = TrackingLinkResource::normalize([
            'platform' => 'tiktok',
            'destination_path' => '/microscope-landing-page',
            'utm_source' => null,
            'utm_medium' => null,
        ]);
        $this->assertSame('tiktok', $normalized['utm_source']);
        $this->assertSame('social', $normalized['utm_medium']);

        $cookie = app(AttributionCookie::class)->name();
        $response = $this->get('/go/'.$bundle['link']->code);
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString($this->frontend.'/microscope-landing-page', $location);
        $this->assertStringContainsString('utm_source=tiktok', $location);
        $this->assertStringContainsString('utm_medium=social', $location);
        $this->assertNotNull($response->getCookie($cookie, decrypt: false));

        $this->assertSame(1, TrackingLinkClick::query()->where('tracking_link_id', $bundle['link']->id)->count());
        $this->assertTrue(
            (bool) TrackingLinkClick::query()
                ->where('tracking_link_id', $bundle['link']->id)
                ->where('is_unique_human', true)
                ->exists()
        );

        $visitor = $response->getCookie($cookie, decrypt: false)?->getValue();
        $this->assertIsString($visitor);

        $again = $this->withUnencryptedCookie($cookie, $visitor)->get('/go/'.$bundle['link']->code);
        $again->assertRedirect();
        $this->assertSame(2, TrackingLinkClick::query()->where('tracking_link_id', $bundle['link']->id)->count());
        $this->assertSame(
            1,
            TrackingLinkClick::query()
                ->where('tracking_link_id', $bundle['link']->id)
                ->where('is_unique_human', true)
                ->count()
        );
    }

    public function test_tiktok_first_touch_youtube_last_touch_and_purchase_not_double_counted(): void
    {
        $tt = $this->makeLink(AttributionPlatform::TikTok, 'tt-ft', ContentType::TiktokVideo);
        $ig = $this->makeLink(AttributionPlatform::Instagram, 'ig-mid', ContentType::InstagramReel);
        $yt = $this->makeLink(AttributionPlatform::YouTube, 'yt-lt', ContentType::YoutubeVideo);

        $cookie = app(AttributionCookie::class)->name();
        $r1 = $this->get('/go/'.$tt->code);
        $visitor = $r1->getCookie($cookie, decrypt: false)?->getValue();
        $this->assertIsString($visitor);

        $this->withUnencryptedCookie($cookie, $visitor)->get('/go/'.$ig->code)->assertRedirect();
        $this->withUnencryptedCookie($cookie, $visitor)->get('/go/'.$yt->code)->assertRedirect();

        $session = AttributionSession::query()->where('visitor_key', $visitor)->firstOrFail();
        $this->assertSame('tiktok', $session->first_platform);
        $this->assertSame('youtube', $session->last_platform);
        $this->assertSame($tt->id, $session->first_tracking_link_id);
        $this->assertSame($yt->id, $session->last_tracking_link_id);

        $order = Order::query()->create([
            'user_id' => User::factory()->create()->id,
            'status' => OrderStatus::Paid->value,
            'subtotal' => 300,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 300,
            'currency' => 'EGP',
            'paid_at' => now(),
            'billing_address' => $this->address(),
            'shipping_address' => $this->address(),
        ]);

        OrderAttribution::query()->create([
            'order_id' => $order->id,
            'order_uuid' => $order->uuid,
            'visitor_key' => $visitor,
            'converting_platform' => 'youtube',
            'converting_campaign_id' => $yt->campaign_id,
            'converting_content_id' => $yt->social_content_id,
            'converting_tracking_link_id' => $yt->id,
            'converting_code' => $yt->code,
            'first_touch' => [
                'tracking_link_id' => $tt->id,
                'platform' => 'tiktok',
                'campaign_id' => $tt->campaign_id,
                'content_id' => $tt->social_content_id,
            ],
            'last_touch' => [
                'tracking_link_id' => $yt->id,
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
            'value' => 300,
            'currency' => 'EGP',
            'occurred_at' => now(),
            'is_attributed' => true,
        ]);

        $analytics = new SocialAnalyticsService(new UnavailableExternalPlatformMetricsProvider);
        $last = $analytics->dashboard(AnalyticsFilters::fromRange('all', null, 'last_touch'));
        $first = $analytics->dashboard(AnalyticsFilters::fromRange('all', null, 'first_touch'));

        $lastYt = collect($last['platforms'])->firstWhere('platform', 'youtube');
        $lastTt = collect($last['platforms'])->firstWhere('platform', 'tiktok');
        $firstYt = collect($first['platforms'])->firstWhere('platform', 'youtube');
        $firstTt = collect($first['platforms'])->firstWhere('platform', 'tiktok');

        $this->assertSame(1, $lastYt['purchases']);
        $this->assertSame(300.0, $lastYt['revenue']['amount']);
        $this->assertSame(0, $lastTt['purchases']);
        $this->assertSame(1, $firstTt['purchases']);
        $this->assertSame(300.0, $firstTt['revenue']['amount']);
        $this->assertSame(0, $firstYt['purchases']);
        $this->assertSame(1, $last['kpis']['purchases']);
        $this->assertSame(1, $first['kpis']['purchases']);
    }

    public function test_dashboard_includes_tiktok_filter_and_unavailable_views(): void
    {
        $tt = $this->makeLink(AttributionPlatform::TikTok, 'tt-dash', ContentType::TiktokVideo);
        TrackingLinkClick::query()->create([
            'uuid' => (string) Str::uuid(),
            'tracking_link_id' => $tt->id,
            'visitor_key' => (string) Str::uuid(),
            'is_bot' => false,
            'is_unique_human' => true,
            'occurred_at' => now(),
            'unique_click_key' => $tt->id.':'.Str::uuid().':'.now()->format('Y-m-d'),
        ]);

        $analytics = new SocialAnalyticsService(new UnavailableExternalPlatformMetricsProvider);
        $all = $analytics->dashboard(AnalyticsFilters::fromRange('all'));
        $platforms = collect($all['platforms'])->pluck('platform')->all();
        $this->assertSame(['instagram', 'facebook', 'youtube', 'tiktok'], $platforms);

        $ttRow = collect($all['platforms'])->firstWhere('platform', 'tiktok');
        $this->assertFalse($ttRow['views_available']);
        $this->assertNull($ttRow['views']);
        $this->assertFalse($ttRow['landing_page_views_available']);
        $this->assertNull($ttRow['landing_page_views']);
        $this->assertSame(1, $ttRow['raw_clicks']);
        $this->assertSame(1, $ttRow['unique_clicks']);
        $this->assertSame(0, $ttRow['purchases']);

        $filtered = $analytics->dashboard(AnalyticsFilters::fromRange('all', 'tiktok'));
        $this->assertCount(1, $filtered['platforms']);
        $this->assertSame('tiktok', $filtered['platforms'][0]['platform']);
        $this->assertSame(1, $filtered['kpis']['raw_clicks']);
    }

    public function test_instagram_facebook_youtube_still_supported(): void
    {
        foreach ([
            [AttributionPlatform::Instagram, 'ig-reg', ContentType::InstagramReel],
            [AttributionPlatform::Facebook, 'fb-reg', ContentType::FacebookPost],
            [AttributionPlatform::YouTube, 'yt-reg', ContentType::YoutubeVideo],
        ] as [$platform, $code, $type]) {
            $link = $this->makeLink($platform, $code, $type);
            $this->get('/go/'.$code)->assertRedirect();
            $this->assertDatabaseHas('tracking_link_clicks', [
                'tracking_link_id' => $link->id,
                'is_unique_human' => 1,
            ]);
        }

        $this->assertTrue(class_exists(SocialCampaignResource::class));
        $this->assertTrue(class_exists(SocialContentResource::class));
        $this->assertTrue(class_exists(TrackingLinkResource::class));
    }

    /**
     * @return array{campaign: SocialCampaign, content: SocialContent, link: TrackingLink}
     */
    private function makeTikTokBundle(string $code): array
    {
        $campaign = SocialCampaign::query()->create([
            'platform' => AttributionPlatform::TikTok,
            'name' => 'Microscope TikTok Test',
            'code' => 'camp-'.$code,
            'channel_kind' => ChannelKind::Organic,
            'status' => CampaignStatus::Active,
        ]);
        $content = SocialContent::query()->create([
            'campaign_id' => $campaign->id,
            'platform' => AttributionPlatform::TikTok,
            'content_type' => ContentType::TiktokVideo,
            'title' => 'Microscope TikTok Video Test',
            'status' => CampaignStatus::Active,
            'destination_type' => DestinationType::LandingPage,
            'destination_path' => '/microscope-landing-page',
        ]);
        $payload = TrackingLinkResource::normalize([
            'platform' => AttributionPlatform::TikTok->value,
            'campaign_id' => $campaign->id,
            'social_content_id' => $content->id,
            'destination_path' => '/microscope-landing-page',
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => $campaign->code,
            'utm_content' => 'tiktok_video_test',
        ]);
        $link = TrackingLink::query()->create([
            'code' => $code,
            'platform' => AttributionPlatform::TikTok,
            'campaign_id' => $campaign->id,
            'social_content_id' => $content->id,
            'label' => 'TikTok Microscope',
            'destination_type' => DestinationType::LandingPage,
            'destination_path' => $payload['destination_path'],
            'is_enabled' => true,
            'status' => CampaignStatus::Active,
            'utm_source' => $payload['utm_source'],
            'utm_medium' => $payload['utm_medium'],
            'utm_campaign' => $payload['utm_campaign'],
            'utm_content' => $payload['utm_content'],
        ]);

        return compact('campaign', 'content', 'link');
    }

    private function makeLink(
        AttributionPlatform $platform,
        string $code,
        ContentType $type,
    ): TrackingLink {
        $campaign = SocialCampaign::query()->create([
            'platform' => $platform,
            'name' => 'Campaign '.$code,
            'code' => 'camp-'.$code,
            'channel_kind' => ChannelKind::Organic,
            'status' => CampaignStatus::Active,
        ]);
        $content = SocialContent::query()->create([
            'campaign_id' => $campaign->id,
            'platform' => $platform,
            'content_type' => $type,
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
            'utm_source' => $platform->value,
            'utm_medium' => 'social',
            'utm_campaign' => $campaign->code,
        ]);
    }

    /** @return array<string, string> */
    private function address(): array
    {
        return [
            'first_name' => 'A',
            'last_name' => 'B',
            'email' => 'a@example.com',
            'phone' => '01000000000',
            'city' => 'Cairo',
            'country' => 'EG',
            'address' => 'St',
        ];
    }
}
