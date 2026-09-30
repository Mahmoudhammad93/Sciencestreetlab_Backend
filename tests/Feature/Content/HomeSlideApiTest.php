<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Modules\Content\Infrastructure\Persistence\Models\HomeSlide;
use App\Support\PublicMediaUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class HomeSlideApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoint_returns_only_active_slides_ordered(): void
    {
        Storage::fake('public');

        $inactive = HomeSlide::query()->create([
            'image' => 'home-slides/inactive.webp',
            'background_color' => '#111111',
            'link' => '/hidden',
            'link_target' => '_self',
            'is_active' => false,
            'sort_order' => 0,
        ]);

        $second = HomeSlide::query()->create([
            'image' => 'home-slides/second.webp',
            'background_color' => '#222222',
            'link' => 'https://example.com/a',
            'link_target' => '_blank',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $first = HomeSlide::query()->create([
            'image' => 'home-slides/first.webp',
            'background_color' => '#4B208C',
            'link' => '/store',
            'link_target' => '_self',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $tiedEarlier = HomeSlide::query()->create([
            'image' => 'home-slides/tied-a.webp',
            'background_color' => '#333333',
            'link' => null,
            'link_target' => '_self',
            'is_active' => true,
            'sort_order' => 5,
        ]);

        $tiedLater = HomeSlide::query()->create([
            'image' => 'home-slides/tied-b.webp',
            'background_color' => '#444444',
            'link' => null,
            'link_target' => '_self',
            'is_active' => true,
            'sort_order' => 5,
        ]);

        $response = $this->getJson('/api/v1/home/slides')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(
            [$first->id, $second->id, $tiedEarlier->id, $tiedLater->id],
            $ids
        );
        $this->assertNotContains($inactive->id, $ids);

        $response
            ->assertJsonPath('data.0.image_url', PublicMediaUrl::make('home-slides/first.webp'))
            ->assertJsonPath('data.0.background_color', '#4B208C')
            ->assertJsonPath('data.0.link', '/store')
            ->assertJsonPath('data.0.link_target', '_self')
            ->assertJsonPath('data.0.sort_order', 1)
            ->assertJsonPath('data.0.duration_seconds', 6)
            ->assertJsonPath('data.1.link', 'https://example.com/a')
            ->assertJsonPath('data.1.link_target', '_blank')
            ->assertJsonPath('data.2.link', null)
            ->assertJsonMissingPath('data.0.is_active')
            ->assertJsonMissingPath('data.0.image');
    }

    public function test_id_used_as_stable_secondary_ordering(): void
    {
        HomeSlide::query()->create([
            'image' => 'home-slides/b.webp',
            'background_color' => '#4B208C',
            'is_active' => true,
            'sort_order' => 10,
            'link_target' => '_self',
        ]);

        HomeSlide::query()->create([
            'image' => 'home-slides/a.webp',
            'background_color' => '#4B208C',
            'is_active' => true,
            'sort_order' => 10,
            'link_target' => '_self',
        ]);

        // Insert a lower sort_order with higher id to ensure sort_order wins first.
        HomeSlide::query()->create([
            'image' => 'home-slides/c.webp',
            'background_color' => '#4B208C',
            'is_active' => true,
            'sort_order' => 1,
            'link_target' => '_self',
        ]);

        $ids = collect($this->getJson('/api/v1/home/slides')->json('data'))->pluck('id')->all();

        $this->assertCount(3, $ids);
        $this->assertSame(3, $ids[0]);
        $this->assertSame([1, 2], array_slice($ids, 1));
    }

    public function test_safe_link_validation_helpers(): void
    {
        $this->assertTrue(HomeSlide::isSafeLink(null));
        $this->assertTrue(HomeSlide::isSafeLink(''));
        $this->assertTrue(HomeSlide::isSafeLink('/store'));
        $this->assertTrue(HomeSlide::isSafeLink('/courses/slug'));
        $this->assertTrue(HomeSlide::isSafeLink('https://example.com'));
        $this->assertTrue(HomeSlide::isSafeLink('http://example.com/path'));

        $this->assertFalse(HomeSlide::isSafeLink('javascript:alert(1)'));
        $this->assertFalse(HomeSlide::isSafeLink('data:text/html,hi'));
        $this->assertFalse(HomeSlide::isSafeLink('vbscript:msgbox(1)'));
        $this->assertFalse(HomeSlide::isSafeLink('//evil.example'));
        $this->assertFalse(HomeSlide::isSafeLink('ftp://example.com'));
    }

    public function test_background_color_validation_helper(): void
    {
        $this->assertTrue(HomeSlide::isValidBackgroundColor('#4B208C'));
        $this->assertTrue(HomeSlide::isValidBackgroundColor('#abc'));
        $this->assertTrue(HomeSlide::isValidBackgroundColor('#abcd'));
        $this->assertTrue(HomeSlide::isValidBackgroundColor('#aabbccdd'));

        $this->assertFalse(HomeSlide::isValidBackgroundColor('4B208C'));
        $this->assertFalse(HomeSlide::isValidBackgroundColor('#gg0000'));
        $this->assertFalse(HomeSlide::isValidBackgroundColor('purple'));
    }

    public function test_duration_seconds_returned_from_api(): void
    {
        HomeSlide::query()->create([
            'image' => 'home-slides/timed.webp',
            'background_color' => '#4B208C',
            'is_active' => true,
            'sort_order' => 1,
            'link_target' => '_self',
            'display_duration_seconds' => 95,
        ]);

        $this->getJson('/api/v1/home/slides')
            ->assertOk()
            ->assertJsonPath('data.0.duration_seconds', 95);
    }

    public function test_owned_image_deleted_with_slide(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('home-slides/gone.webp', 'fake');

        $slide = HomeSlide::query()->create([
            'image' => 'home-slides/gone.webp',
            'background_color' => '#4B208C',
            'is_active' => true,
            'sort_order' => 0,
            'link_target' => '_self',
        ]);

        $slide->delete();

        Storage::disk('public')->assertMissing('home-slides/gone.webp');
    }
}
