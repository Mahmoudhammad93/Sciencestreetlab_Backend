<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_dashboard_http_returns_ok(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk();
    }

    public function test_admin_dashboard_livewire_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertSuccessful();
    }

    public function test_compiled_views_directory_is_writable_by_app(): void
    {
        $viewsPath = storage_path('framework/views');
        $this->assertDirectoryExists($viewsPath);
        $this->assertTrue(is_writable($viewsPath), 'storage/framework/views must be writable for Blade compilation');

        $probe = $viewsPath.'/__dashboard_writable_probe_'.uniqid('', true);
        $this->assertNotFalse(@file_put_contents($probe, 'ok'), 'must be able to create compiled view files');
        $this->assertTrue(@touch($probe) !== false, 'must be able to touch compiled view mtimes (BladeCompiler)');
        @unlink($probe);
    }

    public function test_user_without_admin_role_is_redirected_from_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }
}
