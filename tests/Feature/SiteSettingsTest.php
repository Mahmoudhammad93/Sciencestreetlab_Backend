<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Forms\Components\ImageDropzone;
use App\Filament\Pages\ManageSettings;
use App\Models\User;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_image_dropzone_normalizes_external_logo_url_to_empty_upload_state(): void
    {
        $state = ImageDropzone::normalizeUploadState(
            'https://sciencestreetlab.com/wp-content/uploads/2026/01/4-2-e1768660519978.png'
        );

        $this->assertSame([], $state);
    }

    public function test_image_dropzone_keeps_public_disk_path_as_keyed_array(): void
    {
        $state = ImageDropzone::normalizeUploadState('brand/logo.png');

        $this->assertCount(1, $state);
        $this->assertSame('brand/logo.png', array_values($state)[0]);
    }

    public function test_manage_settings_page_loads_with_external_logo_url(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        SiteSettings::save([
            'logo_url' => 'https://sciencestreetlab.com/wp-content/uploads/2026/01/4-2-e1768660519978.png',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test(ManageSettings::class)
            ->assertSuccessful()
            ->assertSee('Website & dashboard settings');
    }
}
