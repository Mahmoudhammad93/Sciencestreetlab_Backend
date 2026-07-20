<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_home_returns_aggregated_payload(): void
    {
        $this->seed();

        $user = User::factory()->create(['locale' => 'ar']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/mobile/home', [
            'X-App-Version' => '1.0.0',
            'X-Platform' => 'web',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user' => ['name', 'points', 'locale'],
                    'featured_products',
                    'recent_achievements',
                    'competition',
                ],
            ]);
    }

    public function test_content_page_and_blog_are_public(): void
    {
        $this->seed();

        $this->getJson('/api/v1/pages/about')
            ->assertOk()
            ->assertJsonPath('data.slug', 'about');

        $this->getJson('/api/v1/blog')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_device_registration_stores_fcm_token(): void
    {
        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/devices', [
            'device_id' => 'test-device-001',
            'fcm_token' => 'fcm-token-abc',
            'platform' => 'android',
            'app_version' => '1.0.0',
        ])->assertCreated();

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_id' => 'test-device-001',
            'fcm_token' => 'fcm-token-abc',
        ]);
    }
}
