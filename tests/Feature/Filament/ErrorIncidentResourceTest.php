<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ErrorIncidentResource\Pages\ListErrorIncidents;
use App\Filament\Resources\ErrorIncidentResource\Pages\ViewErrorIncident;
use App\Models\User;
use App\Modules\Observability\Infrastructure\Persistence\Models\ErrorIncident;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

final class ErrorIncidentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_error_logs_list_shows_module_file_occurrences_and_user(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['name' => 'Nada Customer', 'email' => 'nada@example.com']);
        $this->incident([
            'message' => 'checkout exploded',
            'exception_class' => RuntimeException::class,
            'module' => 'Commerce',
            'file' => 'app/Modules/Commerce/Application/Services/CheckoutService.php',
            'line' => 88,
            'route_name' => 'api.v1.checkout',
            'request_id' => '01TESTREQUESTID',
            'user_id' => $customer->id,
            'user_type' => 'customer',
            'occurrences' => 12,
            'trace' => "app/Modules/Commerce/Application/Services/CheckoutService.php:88\nCheckoutService::createOrder",
        ]);

        $this->actingAs($admin)->get('/admin/error-logs')->assertOk();

        Livewire::actingAs($admin)
            ->test(ListErrorIncidents::class)
            ->assertSuccessful()
            ->assertSee('checkout exploded')
            ->assertSee('Commerce')
            ->assertSee('Nada Customer')
            ->assertSee('12');
    }

    public function test_search_and_filters(): void
    {
        $admin = $this->admin();
        $this->incident(['message' => 'alpha failure', 'module' => 'Learning', 'fingerprint' => 'fp-a', 'request_id' => 'req-alpha']);
        $this->incident(['message' => 'beta failure', 'module' => 'Catalog', 'fingerprint' => 'fp-b', 'resolved_at' => now()]);

        Livewire::actingAs($admin)
            ->test(ListErrorIncidents::class)
            ->searchTable('req-alpha')
            ->assertSee('alpha failure')
            ->assertDontSee('beta failure');

        Livewire::actingAs($admin)
            ->test(ListErrorIncidents::class)
            ->filterTable('module', 'Learning')
            ->assertSee('alpha failure')
            ->assertDontSee('beta failure');

        Livewire::actingAs($admin)
            ->test(ListErrorIncidents::class)
            ->filterTable('resolved', true)
            ->assertSee('beta failure')
            ->assertDontSee('alpha failure');
    }

    public function test_details_resolve_and_copy_debug_info_are_sanitized(): void
    {
        $admin = $this->admin();
        $incident = $this->incident([
            'message' => 'payment gateway failed',
            'exception_class' => RuntimeException::class,
            'module' => 'Commerce',
            'file' => 'app/Modules/Commerce/Application/Services/CheckoutService.php',
            'line' => 88,
            'application_class' => 'App\\Modules\\Commerce\\Application\\Services\\CheckoutService',
            'application_method' => 'createOrder',
            'eloquent_model' => 'Order',
            'trace' => "CheckoutService::createOrder\nBearer [REDACTED]",
            'context' => [
                'password' => '[REDACTED]',
                'api_key' => '[REDACTED]',
                'provider' => 'fawaterak',
            ],
        ]);

        $this->actingAs($admin)->get('/admin/error-logs/'.$incident->getKey())->assertOk();

        Livewire::actingAs($admin)
            ->test(ViewErrorIncident::class, ['record' => $incident->getKey()])
            ->assertSuccessful()
            ->assertSee('payment gateway failed')
            ->assertSee('CheckoutService')
            ->assertSee('createOrder')
            ->assertSee('Order')
            ->assertSee('app/Modules/Commerce/Application/Services/CheckoutService.php')
            ->assertSee('88')
            ->callAction('copyDebugInfo')
            ->assertSet('debugClipboard', function (?string $text): bool {
                if (! is_string($text)) {
                    return false;
                }
                $this->assertStringContainsString('payment gateway failed', $text);
                $this->assertStringContainsString('[REDACTED]', $text);
                $this->assertStringNotContainsString('sk-live', $text);
                $this->assertStringNotContainsString('hunter2', $text);

                return true;
            })
            ->callAction('resolve');

        $this->assertTrue($incident->fresh()?->isResolved());
        $this->assertSame($admin->id, $incident->fresh()?->resolved_by);
    }

    public function test_competition_moderator_cannot_open_error_logs(): void
    {
        $mod = User::factory()->create();
        $mod->assignRole('competition_moderator');
        $this->actingAs($mod)->get('/admin/error-logs')->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function incident(array $overrides = []): ErrorIncident
    {
        return ErrorIncident::query()->create(array_merge([
            'fingerprint' => 'fp-'.uniqid('', true),
            'level' => 'error',
            'exception_class' => RuntimeException::class,
            'message' => 'boom',
            'module' => 'System',
            'source' => 'http',
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $overrides));
    }
}
