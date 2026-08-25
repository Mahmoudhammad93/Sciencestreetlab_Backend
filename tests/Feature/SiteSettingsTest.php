<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_settings_endpoint_returns_website_payload(): void
    {
        $response = $this->getJson('/api/v1/settings')->assertOk();

        $response
            ->assertJsonPath('data.site_name_en', 'Science Street Lab')
            ->assertJsonPath('data.primary_color', '#2828a0')
            ->assertJsonPath('data.navbar_color', '#fcd500')
            ->assertJsonPath('data.navbar_text_color', '#3030d0')
            ->assertJsonMissingPath('data.admin_layout');
    }

    public function test_saving_settings_updates_public_payload(): void
    {
        SiteSettings::save([
            'site_name_en' => 'Science Street',
            'primary_color' => '#112233',
            'navbar_color' => '#ffaa00',
            'admin_layout' => 'fluid',
        ]);

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.site_name_en', 'Science Street')
            ->assertJsonPath('data.primary_color', '#112233')
            ->assertJsonPath('data.navbar_color', '#ffaa00');

        $this->assertSame('fluid', SiteSettings::get()['admin_layout']);
    }
}
