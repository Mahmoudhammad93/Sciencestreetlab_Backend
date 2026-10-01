<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Identity\Application\Services\LegacyWordPressAuthEligibility;
use App\Modules\Identity\Application\Services\LegacyWordPressCredentialReader;
use App\Modules\Identity\Application\Services\LegacyWordPressPasswordUpgradeService;
use App\Modules\Identity\Application\Services\WordPressCompatiblePasswordVerifier;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class LegacyWordPressFirstLoginAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $wpDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpDbPath = storage_path('framework/testing/wordpress-legacy-'.Str::uuid().'.sqlite');
        @mkdir(dirname($this->wpDbPath), 0777, true);
        if (file_exists($this->wpDbPath)) {
            unlink($this->wpDbPath);
        }
        touch($this->wpDbPath);

        config([
            'database.connections.wordpress' => [
                'driver' => 'sqlite',
                'database' => $this->wpDbPath,
                'prefix' => 'wp_',
                'foreign_key_constraints' => false,
            ],
            'wordpress.connection' => 'wordpress',
            'wordpress.host' => '127.0.0.1',
            'wordpress.database' => $this->wpDbPath,
            'wordpress.username' => 'test',
            'wordpress.password' => '',
            'wordpress.prefix' => 'wp_',
            'wordpress.legacy_auth.migration_run_id' => 1,
            'wordpress.legacy_auth.enabled' => true,
        ]);

        // Rebind eligibility with run id 1 after config set.
        $this->app->singleton(
            LegacyWordPressAuthEligibility::class,
            fn () => LegacyWordPressAuthEligibility::fromConfig(),
        );

        DB::purge('wordpress');
        DB::reconnect('wordpress');

        DB::connection('wordpress')->getSchemaBuilder()->create('users', function ($table): void {
            $table->increments('ID');
            $table->string('user_login')->nullable();
            $table->string('user_pass');
            $table->string('user_email')->nullable();
        });
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('wordpress');
        } catch (\Throwable) {
        }
        if (isset($this->wpDbPath) && file_exists($this->wpDbPath)) {
            @unlink($this->wpDbPath);
        }
        parent::tearDown();
    }

    private function seedRun(): LegacyMigrationRun
    {
        $run = LegacyMigrationRun::query()->create([
            'source' => 'wordpress',
            'environment' => 'testing',
            'status' => 'running',
            'notes' => ['note' => 'r81d'],
            'started_at' => now(),
        ]);

        config(['wordpress.legacy_auth.migration_run_id' => $run->id]);
        $this->app->singleton(
            LegacyWordPressAuthEligibility::class,
            fn () => LegacyWordPressAuthEligibility::fromConfig(),
        );
        $this->app->forgetInstance(LegacyWordPressPasswordUpgradeService::class);

        return $run;
    }

    private function mapUser(
        int $runId,
        User $user,
        string $legacyId,
        string $strategy = LegacyWordPressAuthEligibility::STRATEGY_RESET_REQUIRED,
    ): LegacyImportMap {
        return LegacyImportMap::query()->create([
            'migration_run_id' => $runId,
            'source' => LegacyImportMapRepository::SOURCE_WORDPRESS,
            'entity_type' => 'user',
            'legacy_id' => $legacyId,
            'local_id' => $user->id,
            'legacy_email' => $user->email,
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'password_strategy' => $strategy,
                'historical_import' => true,
            ]),
            'imported_at' => now(),
        ]);
    }

    private function insertWpUser(int $id, string $userPass, string $email = 'wp@example.com'): void
    {
        DB::connection('wordpress')->table('users')->insert([
            'ID' => $id,
            'user_login' => 'wpuser'.$id,
            'user_pass' => $userPass,
            'user_email' => $email,
        ]);
    }

    public function test_wp_modern_hash_test_vector(): void
    {
        $plain = 'Deterministic-WP-Vector-R81D!';
        $hash = WordPressCompatiblePasswordVerifier::makeModernTestHash($plain, 4);
        $this->assertStringStartsWith('$wp$2y$', $hash);
        $this->assertSame(63, strlen($hash));

        $verifier = app(WordPressCompatiblePasswordVerifier::class);
        $this->assertTrue($verifier->verify($plain, $hash));
        $this->assertFalse($verifier->verify('wrong-password', $hash));
        $this->assertFalse($verifier->verify($plain, 'not-a-hash'));
        $this->assertFalse($verifier->verify($plain, '$wp$bogus'));
        // Naive strip must fail — HMAC prehash is required.
        $this->assertFalse(password_verify($plain, substr($hash, 3)));
    }

    public function test_native_laravel_user_correct_password_does_not_query_legacy_db(): void
    {
        $this->seedRun();
        $user = User::factory()->create([
            'email' => 'native@example.com',
            'password' => Hash::make('Password123!'),
        ]);

        $spy = Mockery::mock(LegacyWordPressCredentialReader::class);
        $spy->shouldNotReceive('fetchUserPass');
        $this->app->instance(LegacyWordPressCredentialReader::class, $spy);
        $this->app->forgetInstance(LegacyWordPressPasswordUpgradeService::class);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_native_laravel_user_wrong_password_no_fallback(): void
    {
        $this->seedRun();
        User::factory()->create([
            'email' => 'native-wrong@example.com',
            'password' => Hash::make('Password123!'),
        ]);

        $spy = Mockery::mock(LegacyWordPressCredentialReader::class);
        $spy->shouldNotReceive('fetchUserPass');
        $this->app->instance(LegacyWordPressCredentialReader::class, $spy);
        $this->app->forgetInstance(LegacyWordPressPasswordUpgradeService::class);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'native-wrong@example.com',
            'password' => 'WrongPassword!',
        ])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_migrated_user_correct_wp_password_upgrades_and_issues_token(): void
    {
        $run = $this->seedRun();
        $plain = 'Old-WordPress-Secret-99!';
        $wpHash = WordPressCompatiblePasswordVerifier::makeModernTestHash($plain, 4);

        $user = User::factory()->create([
            'email' => 'migrated@example.com',
            'password' => Hash::make(Str::password(64)),
        ]);
        $originalHash = $user->password;
        $map = $this->mapUser($run->id, $user, '85001');
        $this->insertWpUser(85001, $wpHash, $user->email);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $plain,
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.token'));
        $user->refresh();
        $this->assertNotSame($originalHash, $user->password);
        $this->assertTrue(Hash::check($plain, $user->password));
        $this->assertStringStartsNotWith('$wp$', $user->password);
        $map->refresh();
        $this->assertSame('upgraded', $map->metadata['password_strategy'] ?? null);
        $this->assertArrayHasKey('password_upgraded_at', $map->metadata);
    }

    public function test_second_login_uses_laravel_hash_without_legacy_query(): void
    {
        $run = $this->seedRun();
        $plain = 'Old-WordPress-Secret-99!';
        $wpHash = WordPressCompatiblePasswordVerifier::makeModernTestHash($plain, 4);
        $user = User::factory()->create([
            'email' => 'second-login@example.com',
            'password' => Hash::make(Str::password(64)),
        ]);
        $this->mapUser($run->id, $user, '85002');
        $this->insertWpUser(85002, $wpHash, $user->email);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $plain,
        ])->assertOk();

        $spy = Mockery::mock(LegacyWordPressCredentialReader::class);
        $spy->shouldNotReceive('fetchUserPass');
        $this->app->instance(LegacyWordPressCredentialReader::class, $spy);
        $this->app->forgetInstance(LegacyWordPressPasswordUpgradeService::class);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $plain,
        ])->assertOk();
    }

    public function test_migrated_user_wrong_wp_password_no_writes(): void
    {
        $run = $this->seedRun();
        $plain = 'Correct-WP-Pass!';
        $wpHash = WordPressCompatiblePasswordVerifier::makeModernTestHash($plain, 4);
        $user = User::factory()->create([
            'email' => 'wrong-wp@example.com',
            'password' => Hash::make('migration-random-placeholder'),
        ]);
        $originalHash = $user->password;
        $map = $this->mapUser($run->id, $user, '85003');
        $this->insertWpUser(85003, $wpHash, $user->email);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Totally-Wrong!',
        ])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertSame('reset_required', $map->fresh()->metadata['password_strategy'] ?? null);
    }

    public function test_missing_source_wp_row_fails_without_writes(): void
    {
        $run = $this->seedRun();
        $user = User::factory()->create([
            'email' => 'missing-src@example.com',
            'password' => Hash::make(Str::password(32)),
        ]);
        $originalHash = $user->password;
        $map = $this->mapUser($run->id, $user, '85004');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Anything123!',
        ])->assertUnauthorized();

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertSame('reset_required', $map->fresh()->metadata['password_strategy'] ?? null);
    }

    public function test_malformed_wp_hash_fails_without_writes(): void
    {
        $run = $this->seedRun();
        $user = User::factory()->create([
            'email' => 'bad-hash@example.com',
            'password' => Hash::make(Str::password(32)),
        ]);
        $originalHash = $user->password;
        $map = $this->mapUser($run->id, $user, '85005');
        $this->insertWpUser(85005, '$wp$not-valid', $user->email);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Anything123!',
        ])->assertUnauthorized();

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertSame('reset_required', $map->fresh()->metadata['password_strategy'] ?? null);
    }

    public function test_no_user_map_means_no_fallback(): void
    {
        $this->seedRun();
        $user = User::factory()->create([
            'email' => 'collision@example.com',
            'password' => Hash::make('NativePass123!'),
        ]);
        // Intentionally no USER map (existing-email collision policy).

        $reader = Mockery::mock(LegacyWordPressCredentialReader::class);
        $reader->shouldNotReceive('fetchUserPass');
        $this->app->instance(LegacyWordPressCredentialReader::class, $reader);
        $this->app->forgetInstance(LegacyWordPressPasswordUpgradeService::class);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Old-WP-Would-Not-Matter!',
        ])->assertUnauthorized();
    }

    public function test_wrong_migration_run_map_no_fallback(): void
    {
        $this->seedRun();
        $other = LegacyMigrationRun::query()->create([
            'source' => 'wordpress',
            'environment' => 'testing',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $plain = 'Old-WordPress-Secret-99!';
        $wpHash = WordPressCompatiblePasswordVerifier::makeModernTestHash($plain, 4);
        $user = User::factory()->create([
            'email' => 'wrong-run@example.com',
            'password' => Hash::make(Str::password(32)),
        ]);
        $this->mapUser($other->id, $user, '85006');
        $this->insertWpUser(85006, $wpHash, $user->email);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $plain,
        ])->assertUnauthorized();
    }

    public function test_wrong_entity_type_map_no_fallback(): void
    {
        $run = $this->seedRun();
        $plain = 'Old-WordPress-Secret-99!';
        $wpHash = WordPressCompatiblePasswordVerifier::makeModernTestHash($plain, 4);
        $user = User::factory()->create([
            'email' => 'wrong-type@example.com',
            'password' => Hash::make(Str::password(32)),
        ]);
        LegacyImportMap::query()->create([
            'migration_run_id' => $run->id,
            'source' => LegacyImportMapRepository::SOURCE_WORDPRESS,
            'entity_type' => 'course',
            'legacy_id' => '85007',
            'local_id' => $user->id,
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'password_strategy' => 'reset_required',
            ]),
            'imported_at' => now(),
        ]);
        $this->insertWpUser(85007, $wpHash, $user->email);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $plain,
        ])->assertUnauthorized();
    }

    public function test_non_reset_required_strategy_no_fallback(): void
    {
        $run = $this->seedRun();
        $plain = 'Old-WordPress-Secret-99!';
        $wpHash = WordPressCompatiblePasswordVerifier::makeModernTestHash($plain, 4);
        $user = User::factory()->create([
            'email' => 'already-upgraded@example.com',
            'password' => Hash::make(Str::password(32)),
        ]);
        $this->mapUser($run->id, $user, '85008', LegacyWordPressAuthEligibility::STRATEGY_UPGRADED);
        $this->insertWpUser(85008, $wpHash, $user->email);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $plain,
        ])->assertUnauthorized();
    }

    public function test_password_reset_revokes_legacy_and_blocks_old_wp_password(): void
    {
        $run = $this->seedRun();
        $wpPlain = 'Old-WordPress-Secret-99!';
        $wpHash = WordPressCompatiblePasswordVerifier::makeModernTestHash($wpPlain, 4);
        $user = User::factory()->create([
            'email' => 'reset-user@example.com',
            'password' => Hash::make(Str::password(32)),
        ]);
        $map = $this->mapUser($run->id, $user, '85009');
        $this->insertWpUser(85009, $wpHash, $user->email);

        $token = Password::broker()->createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'BrandNewPass123!',
            'password_confirmation' => 'BrandNewPass123!',
        ])->assertOk();

        $this->assertSame('reset', $map->fresh()->metadata['password_strategy'] ?? null);
        $this->assertTrue(Hash::check('BrandNewPass123!', $user->fresh()->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'BrandNewPass123!',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $wpPlain,
        ])->assertUnauthorized();
    }

    public function test_password_change_revocation_blocks_legacy_fallback(): void
    {
        $run = $this->seedRun();
        $wpPlain = 'Old-WordPress-Secret-99!';
        $wpHash = WordPressCompatiblePasswordVerifier::makeModernTestHash($wpPlain, 4);
        $user = User::factory()->create([
            'email' => 'changed-user@example.com',
            'password' => Hash::make(Str::password(32)),
        ]);
        $map = $this->mapUser($run->id, $user, '85010');
        $this->insertWpUser(85010, $wpHash, $user->email);

        $user->forceFill(['password' => Hash::make('ChosenPass123!')])->save();
        app(LegacyWordPressPasswordUpgradeService::class)->revokeAfterPasswordChange($user);

        $this->assertSame('changed', $map->fresh()->metadata['password_strategy'] ?? null);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $wpPlain,
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'ChosenPass123!',
        ])->assertOk();
    }

    public function test_historical_db_unavailable_fails_safely_native_still_works(): void
    {
        $run = $this->seedRun();
        $migrated = User::factory()->create([
            'email' => 'hist-down@example.com',
            'password' => Hash::make(Str::password(32)),
        ]);
        $this->mapUser($run->id, $migrated, '85011');

        $native = User::factory()->create([
            'email' => 'native-still@example.com',
            'password' => Hash::make('Password123!'),
        ]);

        config([
            'wordpress.host' => null,
            'wordpress.database' => null,
            'wordpress.username' => null,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $migrated->email,
            'password' => 'Whatever123!',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => $native->email,
            'password' => 'Password123!',
        ])->assertOk();
    }

    public function test_concurrent_first_login_ends_upgraded(): void
    {
        $run = $this->seedRun();
        $plain = 'Old-WordPress-Secret-99!';
        $wpHash = WordPressCompatiblePasswordVerifier::makeModernTestHash($plain, 4);
        $user = User::factory()->create([
            'email' => 'concurrent@example.com',
            'password' => Hash::make(Str::password(32)),
        ]);
        $map = $this->mapUser($run->id, $user, '85012');
        $this->insertWpUser(85012, $wpHash, $user->email);

        $service = app(LegacyWordPressPasswordUpgradeService::class);
        $a = $service->attemptUpgrade($user, $plain);
        $b = $service->attemptUpgrade($user->fresh(), $plain);

        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $this->assertSame('upgraded', $map->fresh()->metadata['password_strategy'] ?? null);
        $this->assertTrue(Hash::check($plain, $user->fresh()->password));
    }

    public function test_login_route_has_auth_login_throttle(): void
    {
        $found = false;
        foreach (Route::getRoutes() as $r) {
            if ($r->uri() === 'api/v1/auth/login' && in_array('POST', $r->methods(), true)) {
                $m = implode(',', $r->gatherMiddleware());
                $this->assertStringContainsString('throttle:auth-login', $m);
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function test_legacy_login_merges_guest_cart(): void
    {
        $this->seed();
        $run = $this->seedRun();
        $plain = 'Old-WordPress-Secret-99!';
        $wpHash = WordPressCompatiblePasswordVerifier::makeModernTestHash($plain, 4);
        $user = User::factory()->create([
            'email' => 'cart-wp@example.com',
            'password' => Hash::make(Str::password(32)),
        ]);
        $this->mapUser($run->id, $user, '85013');
        $this->insertWpUser(85013, $wpHash, $user->email);

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();
        $guestKey = 'guestwpcartsession1';

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-Cart-Session' => $guestKey,
        ])->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $plain,
        ], [
            'X-Cart-Session' => $guestKey,
        ])->assertOk();

        $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('carts', ['session_id' => $guestKey]);
    }

    public function test_no_plaintext_or_hash_logging_on_legacy_success(): void
    {
        Log::spy();
        $run = $this->seedRun();
        $plain = 'Old-WordPress-Secret-99!';
        $wpHash = WordPressCompatiblePasswordVerifier::makeModernTestHash($plain, 4);
        $user = User::factory()->create([
            'email' => 'log-safe@example.com',
            'password' => Hash::make(Str::password(32)),
        ]);
        $this->mapUser($run->id, $user, '85014');
        $this->insertWpUser(85014, $wpHash, $user->email);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $plain,
        ])->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                $encoded = json_encode($context) ?: '';
                $this->assertStringNotContainsString('Old-WordPress-Secret-99!', $encoded);
                $this->assertStringNotContainsString('$wp$', $encoded);
                $this->assertStringNotContainsString('$2y$', $encoded);

                return $message === 'legacy_auth_success';
            })
            ->once();
    }
}
