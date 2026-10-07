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
use PHPUnit\Framework\Attributes\DataProvider;
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

        Livewire::actingAs($admin)
            ->test(ViewErrorIncident::class, ['record' => $incident->getKey()])
            ->assertSuccessful()
            ->callAction('reopen');

        $this->assertFalse($incident->fresh()?->isResolved());
        $this->assertNull($incident->fresh()?->resolved_by);
    }

    public function test_details_page_renders_nested_array_context_like_production(): void
    {
        $admin = $this->admin();
        $incident = $this->incident([
            'message' => 'The "--columns" option does not exist.',
            'exception_class' => \Symfony\Component\Console\Exception\RuntimeException::class,
            'module' => 'System',
            'source' => 'command',
            'user_type' => 'system',
            'file' => 'vendor/symfony/console/Input/ArgvInput.php',
            'line' => 226,
            'trace' => "vendor/symfony/console/Input/ArgvInput.php:226\nArgvInput::addLongOption",
            'context' => [
                'command' => 'route:list',
                'exception' => \Symfony\Component\Console\Exception\RuntimeException::class,
                'request' => [
                    'query_keys' => ['foo'],
                    'input_keys' => ['bar'],
                    'content_type' => 'application/json',
                ],
            ],
        ]);

        $this->actingAs($admin)->get('/admin/error-logs/'.$incident->getKey())->assertOk();

        Livewire::actingAs($admin)
            ->test(ViewErrorIncident::class, ['record' => $incident->getKey()])
            ->assertSuccessful()
            ->assertSee('The "--columns" option does not exist.')
            ->assertSee('query_keys')
            ->assertSee('ArgvInput.php')
            ->assertSee('226')
            ->assertSee('System')
            ->callAction('copyDebugInfo')
            ->assertSet('debugClipboard', function (?string $text): bool {
                return is_string($text)
                    && str_contains($text, 'query_keys')
                    && str_contains($text, 'ArgvInput.php');
            });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('malformedIncidentProvider')]
    public function test_details_page_survives_malformed_incident_shapes(array $overrides, string $needle): void
    {
        $admin = $this->admin();
        $incident = $this->incident($overrides);

        $this->actingAs($admin)->get('/admin/error-logs')->assertOk();
        $this->actingAs($admin)->get('/admin/error-logs/'.$incident->getKey())->assertOk();

        Livewire::actingAs($admin)
            ->test(ListErrorIncidents::class)
            ->assertSuccessful()
            ->assertSee($needle);

        Livewire::actingAs($admin)
            ->test(ViewErrorIncident::class, ['record' => $incident->getKey()])
            ->assertSuccessful()
            ->assertSee($needle)
            ->callAction('copyDebugInfo')
            ->assertSet('debugClipboard', fn (?string $text): bool => is_string($text) && str_contains($text, $needle));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedIncidentProvider(): array
    {
        return [
            'null_trace' => [['message' => 'null trace case', 'trace' => null], 'null trace case'],
            'string_trace' => [['message' => 'string trace case', 'trace' => "app/Foo.php:1\nFoo::bar"], 'string trace case'],
            'array_json_trace' => [['message' => 'array json trace', 'trace' => '[{"file":"app/Foo.php","line":1}]'], 'array json trace'],
            'customer_user' => [['message' => 'customer incident', 'user_type' => 'customer'], 'customer incident'],
            'malformed_json_trace' => [['message' => 'bad json trace', 'trace' => '{"not": json'], 'bad json trace'],
            'null_context' => [['message' => 'null context case', 'context' => null], 'null context case'],
            'array_context' => [['message' => 'array context case', 'context' => ['provider' => 'bosta', 'nested' => ['a' => 1]]], 'array context case'],
            'guest' => [['message' => 'guest incident', 'user_id' => null, 'user_type' => 'guest'], 'guest incident'],
            'system' => [['message' => 'system incident', 'user_id' => null, 'user_type' => 'system'], 'system incident'],
            'admin_user' => [['message' => 'admin incident', 'user_type' => 'admin'], 'admin incident'],
            'null_model_module_route_file' => [[
                'message' => 'sparse incident',
                'module' => null,
                'eloquent_model' => null,
                'application_class' => null,
                'application_method' => null,
                'route_name' => null,
                'file' => null,
                'line' => null,
            ], 'sparse incident'],
        ];
    }

    public function test_details_page_survives_malformed_json_columns_and_deleted_user(): void
    {
        $admin = $this->admin();
        $gone = User::factory()->create(['name' => 'Gone User', 'email' => 'gone@example.com']);
        $incident = $this->incident([
            'message' => 'deleted user incident',
            'user_id' => $gone->id,
            'user_type' => 'customer',
            'context' => ['ok' => true],
            'trace' => 'ok',
        ]);
        $gone->delete();

        \Illuminate\Support\Facades\DB::table('error_incidents')->where('id', $incident->id)->update([
            'context' => '{not-json',
            'trace' => '[{"file":"app/Foo.php"}',
        ]);

        $this->actingAs($admin)->get('/admin/error-logs/'.$incident->getKey())->assertOk();

        Livewire::actingAs($admin)
            ->test(ViewErrorIncident::class, ['record' => $incident->getKey()])
            ->assertSuccessful()
            ->assertSee('deleted user incident')
            ->assertSee('N/A')
            ->callAction('copyDebugInfo')
            ->assertSet('debugClipboard', function (?string $text): bool {
                return is_string($text)
                    && str_contains($text, 'deleted user incident')
                    && ! str_contains($text, 'gone@example.com');
            });
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
