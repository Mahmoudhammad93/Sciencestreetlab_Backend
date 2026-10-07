<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Models\User;
use App\Modules\Observability\Application\Services\ErrorIncidentRecorder;
use App\Modules\Observability\Infrastructure\Persistence\Models\ErrorIncident;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BoomJob;
use Tests\TestCase;

final class ErrorIncidentCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        ErrorIncidentRecorder::resetRuntimeState();
    }

    public function test_public_api_500_is_captured_with_request_id_and_safe_json(): void
    {
        $response = $this->getJson('/api/v1/__observability/boom?password=super-secret&api_key=sk-live');

        $response->assertStatus(500)
            ->assertJsonPath('message', __('errors.unexpected'))
            ->assertJsonStructure(['message', 'request_id']);
        $this->assertArrayNotHasKey('file', $response->json());
        $this->assertArrayNotHasKey('trace', $response->json());
        $this->assertStringNotContainsString('probe', (string) $response->json('message'));

        $incident = ErrorIncident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame('RuntimeException', class_basename($incident->exception_class));
        $this->assertSame('observability public api probe', $incident->message);
        $this->assertSame('guest', $incident->user_type);
        $this->assertNull($incident->user_id);
        $this->assertSame($response->json('request_id'), $incident->request_id);
        $this->assertSame($response->headers->get('X-Request-Id'), $incident->request_id);
        $this->assertNotNull($incident->file);
        $this->assertDoesNotMatchRegularExpression('#^/#', (string) $incident->file);
        $this->assertNotNull($incident->line);
        $this->assertSame('ErrorProbeController', $incident->displayApplicationClass());
        $this->assertSame('boom', $incident->application_method);
        $this->assertSame('N/A', $incident->displayModel());
        $this->assertSame('Observability', $incident->module);
        $this->assertSame(500, $incident->http_status);
        $this->assertSame('/api/v1/__observability/boom', $incident->request_path);
        $this->assertStringNotContainsString('super-secret', (string) json_encode($incident->context));
        $this->assertStringNotContainsString('sk-live', (string) json_encode($incident->context));
        $this->assertStringNotContainsString('super-secret', (string) $incident->trace);
    }

    public function test_authenticated_customer_is_captured_from_bearer_not_session(): void
    {
        $customer = User::factory()->create(['name' => 'Customer Probe', 'email' => 'customer-probe@example.com']);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $token = $customer->createToken('test')->plainTextToken;

        $this->actingAs($admin);
        $response = $this->getJson('/api/v1/__observability/boom-auth', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(500);
        $incident = ErrorIncident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame('customer', $incident->user_type);
        $this->assertSame($customer->id, $incident->user_id);
        $this->assertNotSame($admin->id, $incident->user_id);
    }

    public function test_filament_admin_probe_captures_admin_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $response = $this->actingAs($admin)->get('/admin/__observability/boom');

        $response->assertStatus(500);
        $incident = ErrorIncident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame('admin', $incident->user_type);
        $this->assertSame($admin->id, $incident->user_id);
        $this->assertSame('filament', $incident->source);
        $this->assertSame('observability filament admin probe', $incident->message);
    }

    public function test_guest_has_null_user(): void
    {
        $this->getJson('/api/v1/__observability/boom')->assertStatus(500);

        $incident = ErrorIncident::query()->first();
        $this->assertSame('guest', $incident?->user_type);
        $this->assertNull($incident?->user_id);
    }

    public function test_duplicate_error_increments_occurrences_instead_of_flooding(): void
    {
        $this->getJson('/api/v1/__observability/boom')->assertStatus(500);
        $this->getJson('/api/v1/__observability/boom')->assertStatus(500);
        $this->getJson('/api/v1/__observability/boom')->assertStatus(500);

        $this->assertSame(1, ErrorIncident::query()->count());
        $this->assertSame(3, (int) ErrorIncident::query()->first()?->occurrences);
    }

    public function test_resolved_incident_reopens_on_repeat(): void
    {
        $admin = User::factory()->create();
        $this->getJson('/api/v1/__observability/boom')->assertStatus(500);
        $incident = ErrorIncident::query()->first();
        $this->assertNotNull($incident);
        $incident->markResolved($admin->id);
        $this->assertTrue($incident->fresh()->isResolved());

        $this->getJson('/api/v1/__observability/boom')->assertStatus(500);

        $this->assertSame(1, ErrorIncident::query()->count());
        $fresh = ErrorIncident::query()->first();
        $this->assertFalse($fresh?->isResolved());
        $this->assertNull($fresh?->resolved_at);
        $this->assertNull($fresh?->resolved_by);
        $this->assertSame(2, (int) $fresh?->occurrences);
    }

    public function test_authorization_cookie_and_password_headers_are_not_stored(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer super-secret-token',
            'Cookie' => 'laravel_session=session-id-value; XSRF-TOKEN=csrf-secret',
        ])->getJson('/api/v1/__observability/boom?password=hunter2')->assertStatus(500);

        $incident = ErrorIncident::query()->first();
        $blob = strtolower((string) json_encode($incident?->toArray()));
        $this->assertStringNotContainsString('super-secret-token', $blob);
        $this->assertStringNotContainsString('session-id-value', $blob);
        $this->assertStringNotContainsString('csrf-secret', $blob);
        $this->assertStringNotContainsString('hunter2', $blob);
        $this->assertStringNotContainsString('bearer super-secret-token', $blob);
    }

    public function test_validation_422_is_ignored(): void
    {
        $this->postJson('/api/v1/auth/login', [])->assertStatus(422);
        $this->assertSame(0, ErrorIncident::query()->count());
    }

    public function test_expected_404_is_ignored(): void
    {
        $this->getJson('/api/v1/this-route-does-not-exist-error-monitoring')->assertStatus(404);
        $this->assertSame(0, ErrorIncident::query()->count());
    }

    public function test_queue_failure_is_captured(): void
    {
        try {
            BoomJob::dispatchSync();
            $this->fail('Job should throw');
        } catch (RuntimeException $e) {
            $this->assertSame('observability queue probe', $e->getMessage());
            report($e);
        }

        $incident = ErrorIncident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame('observability queue probe', $incident->message);
        $this->assertSame('queue', $incident->source);
        $this->assertSame('system', $incident->user_type);
        $this->assertSame(BoomJob::class, $incident->context['job'] ?? null);
    }

    public function test_command_failure_is_captured(): void
    {
        try {
            Artisan::call('observability:boom');
            $this->fail('Command should throw');
        } catch (RuntimeException $e) {
            $this->assertSame('observability command probe', $e->getMessage());
            ErrorIncidentRecorder::setCommandContext('observability:boom');
            ErrorIncidentRecorder::setSourceHint('scheduler');
            report($e);
        }

        $incident = ErrorIncident::query()->where('message', 'observability command probe')->first();
        $this->assertNotNull($incident);
        $this->assertSame('scheduler', $incident->source);
        $this->assertSame('system', $incident->user_type);
        $this->assertSame('observability:boom', $incident->context['command'] ?? null);
    }

    public function test_integration_context_is_sanitized(): void
    {
        app(ErrorIncidentRecorder::class)->record(new RuntimeException('bosta delivery failed'), [
            'provider' => 'bosta',
            'operation' => 'create_delivery',
            'external_status' => 500,
            'external_id' => 'trk-safe-1',
            'context' => [
                'api_key' => 'bosta-secret-key',
                'Authorization' => 'Bearer abc',
                'password' => 'pw',
                'card_number' => '4242424242424242',
                'cookie' => 'sid=abc',
            ],
        ]);

        $incident = ErrorIncident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame('bosta', $incident->context['provider'] ?? null);
        $this->assertSame('create_delivery', $incident->context['operation'] ?? null);
        $this->assertSame('trk-safe-1', $incident->context['external_id'] ?? null);
        $extra = $incident->context['extra'] ?? [];
        $this->assertSame('[REDACTED]', $extra['api_key'] ?? null);
        $this->assertSame('[REDACTED]', $extra['Authorization'] ?? null);
        $this->assertSame('[REDACTED]', $extra['password'] ?? null);
        $this->assertSame('[REDACTED]', $extra['card_number'] ?? null);
        $this->assertSame('[REDACTED]', $extra['cookie'] ?? null);
        $blob = (string) json_encode($incident->context);
        $this->assertStringNotContainsString('bosta-secret-key', $blob);
        $this->assertStringNotContainsString('4242424242424242', $blob);
    }

    public function test_reporter_caps_persists_per_request_without_recursion(): void
    {
        $recorder = app(ErrorIncidentRecorder::class);
        ErrorIncidentRecorder::resetRuntimeState();
        for ($i = 0; $i < 8; $i++) {
            $recorder->record(new RuntimeException('budget probe '.$i));
        }

        $this->assertLessThanOrEqual(3, ErrorIncident::query()->count());
    }

    public function test_database_reporter_failure_does_not_recurse(): void
    {
        Schema::rename('error_incidents', 'error_incidents_hidden');
        try {
            $this->getJson('/api/v1/__observability/boom')->assertStatus(500);
        } finally {
            Schema::rename('error_incidents_hidden', 'error_incidents');
        }

        $this->assertSame(0, ErrorIncident::query()->count());
    }

    public function test_frontend_client_error_is_captured_without_trusting_browser_identity(): void
    {
        $response = $this->postJson('/api/v1/client-errors', [
            'message' => 'TypeError: boom',
            'stack' => 'at CheckoutPage (src/pages/checkout/CheckoutPage.tsx:10:2)\nAuthorization: Bearer leaked-token',
            'pathname' => '/checkout',
            'kind' => 'error-boundary',
            'user_id' => 999,
            'module' => 'Commerce',
            'email' => 'attacker@example.com',
        ]);

        $response->assertStatus(202)->assertJsonStructure(['ok', 'request_id']);
        $incident = ErrorIncident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame('frontend', $incident->source);
        $this->assertSame('Frontend', $incident->module);
        $this->assertSame('guest', $incident->user_type);
        $this->assertNull($incident->user_id);
        $this->assertSame('TypeError: boom', $incident->message);
        $this->assertStringNotContainsString('leaked-token', (string) json_encode($incident->context));
    }

    public function test_frontend_client_error_rate_limit(): void
    {
        RateLimiter::clear('client-errors');
        $payload = ['message' => 'js rate limit probe', 'kind' => 'error'];
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/client-errors', $payload)->assertStatus(202);
        }
        $this->postJson('/api/v1/client-errors', $payload)->assertStatus(429);
        $this->assertSame(1, ErrorIncident::query()->count());
        $this->assertSame(20, (int) ErrorIncident::query()->first()?->occurrences);
    }

    public function test_normal_user_cannot_access_error_logs(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin/error-logs')->assertForbidden();
    }

    public function test_retention_prunes_only_old_resolved_incidents(): void
    {
        $open = ErrorIncident::query()->create($this->incidentAttrs(['fingerprint' => 'open-1', 'resolved_at' => null]));
        $recentResolved = ErrorIncident::query()->create($this->incidentAttrs([
            'fingerprint' => 'resolved-recent',
            'resolved_at' => now()->subDay(),
        ]));
        $oldResolved = ErrorIncident::query()->create($this->incidentAttrs([
            'fingerprint' => 'resolved-old',
            'resolved_at' => now()->subDays(120),
        ]));

        $this->artisan('observability:prune-error-incidents', ['--days' => 90])->assertSuccessful();

        $this->assertNotNull($open->fresh());
        $this->assertNotNull($recentResolved->fresh());
        $this->assertNull($oldResolved->fresh());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function incidentAttrs(array $overrides): array
    {
        return array_merge([
            'fingerprint' => 'fp-'.uniqid(),
            'level' => 'error',
            'exception_class' => RuntimeException::class,
            'message' => 'sample',
            'module' => 'System',
            'source' => 'http',
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $overrides);
    }
}
