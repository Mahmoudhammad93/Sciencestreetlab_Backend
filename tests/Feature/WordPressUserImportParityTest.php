<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\WordPressUserImporter;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Dry-run / persist parity for WordPress user email collisions (R8.1A).
 */
final class WordPressUserImportParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_email_trims_and_lowercases(): void
    {
        $importer = app(WordPressUserImporter::class);

        $this->assertSame('user@example.com', $importer->normalizeEmail('  User@Example.COM  '));
        $this->assertSame('', $importer->normalizeEmail('   '));
        $this->assertSame('', $importer->normalizeEmail(null));
    }

    public function test_classify_new_email_is_create(): void
    {
        $importer = app(WordPressUserImporter::class);
        $email = $importer->normalizeEmail('new-user@example.com');

        $this->assertSame(
            WordPressUserImporter::OUTCOME_CREATE,
            $importer->classifyImportDecision('1001', $email),
        );
    }

    public function test_classify_existing_email_is_skip_existing_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $importer = app(WordPressUserImporter::class);
        $email = $importer->normalizeEmail('  TAKEN@example.com ');

        $this->assertSame(
            WordPressUserImporter::OUTCOME_SKIP_EXISTING_EMAIL,
            $importer->classifyImportDecision('1002', $email),
        );
    }

    public function test_classify_soft_deleted_email_is_skip_existing_email(): void
    {
        $user = User::factory()->create(['email' => 'trashed@example.com']);
        $user->delete();
        $this->assertTrue($user->fresh()->trashed());

        $importer = app(WordPressUserImporter::class);
        $email = $importer->normalizeEmail('  TRASHED@example.com ');

        $this->assertSame(
            WordPressUserImporter::OUTCOME_SKIP_EXISTING_EMAIL,
            $importer->classifyImportDecision('1004', $email),
        );
        $this->assertSame(0, User::query()->where('email', 'trashed@example.com')->count());
        $this->assertSame(1, User::withTrashed()->where('email', 'trashed@example.com')->count());
    }

    public function test_classify_mapped_legacy_id_is_skip_mapped(): void
    {
        $user = User::factory()->create(['email' => 'mapped@example.com']);
        app(LegacyImportMapRepository::class)->upsertMapping('user', '1003', [
            'local_id' => $user->id,
            'legacy_email' => $user->email,
        ]);

        $importer = app(WordPressUserImporter::class);
        $this->assertSame(
            WordPressUserImporter::OUTCOME_SKIP_MAPPED,
            $importer->classifyImportDecision('1003', $importer->normalizeEmail($user->email)),
        );
    }

    public function test_real_path_creates_user_and_map_for_new_email(): void
    {
        $importer = app(WordPressUserImporter::class);
        $beforeMaps = LegacyImportMap::query()->count();

        $result = $importer->importUserSilently('2001', [
            'email' => '  Fresh@Example.com ',
            'name' => 'Fresh User',
        ]);

        $this->assertTrue($result['created']);
        $this->assertSame(WordPressUserImporter::OUTCOME_CREATE, $result['outcome']);
        $this->assertSame('fresh@example.com', $result['user']->email);
        $this->assertNotNull($result['map']);
        $this->assertSame($result['user']->id, $result['map']->local_id);
        $this->assertSame('reset_required', $result['map']->metadata['password_strategy'] ?? null);
        $this->assertSame(1, User::query()->where('email', 'fresh@example.com')->count());
        $this->assertSame($beforeMaps + 1, LegacyImportMap::query()->count());
        $this->assertNotSame('$P$Bwordpresshash', $result['user']->password);
    }

    public function test_real_path_skips_existing_email_without_map_or_overwrite(): void
    {
        $existing = User::factory()->create([
            'email' => 'collision@example.com',
            'name' => 'Keep Me',
            'password' => Hash::make('original-secret'),
        ]);
        $originalHash = $existing->password;
        $importer = app(WordPressUserImporter::class);
        $beforeMaps = LegacyImportMap::query()->where('entity_type', 'user')->count();
        $beforeUsers = User::query()->count();

        $result = $importer->importUserSilently('2002', [
            'email' => 'Collision@Example.com',
            'name' => 'Should Not Overwrite',
            'phone' => '01000000000',
        ]);

        $existing->refresh();
        $this->assertFalse($result['created']);
        $this->assertSame(WordPressUserImporter::OUTCOME_SKIP_EXISTING_EMAIL, $result['outcome']);
        $this->assertNull($result['map']);
        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertSame($beforeMaps, LegacyImportMap::query()->where('entity_type', 'user')->count());
        $this->assertSame('Keep Me', $existing->name);
        $this->assertSame($originalHash, $existing->password);
        $this->assertSame(1, User::query()->where('email', 'collision@example.com')->count());
        $this->assertDatabaseMissing('legacy_import_maps', [
            'entity_type' => 'user',
            'legacy_id' => '2002',
        ]);
    }

    public function test_dry_run_and_persist_agree_on_existing_email_decision(): void
    {
        User::factory()->create(['email' => 'parity@example.com']);
        $importer = app(WordPressUserImporter::class);
        $email = $importer->normalizeEmail(' PARITY@example.com ');

        $dryDecision = $importer->classifyImportDecision('3001', $email);
        $persist = $importer->importUserSilently('3001', [
            'email' => ' PARITY@example.com ',
            'name' => 'Parity',
        ]);

        $this->assertSame(WordPressUserImporter::OUTCOME_SKIP_EXISTING_EMAIL, $dryDecision);
        $this->assertSame(WordPressUserImporter::OUTCOME_SKIP_EXISTING_EMAIL, $persist['outcome']);
        $this->assertFalse($persist['created']);
        $this->assertNull($persist['map']);
    }

    public function test_soft_deleted_email_dry_run_and_persist_skip_without_restore_or_map(): void
    {
        $existing = User::factory()->create([
            'email' => 'soft-collision@example.com',
            'name' => 'Soft Deleted Keep',
            'password' => Hash::make('original-secret'),
        ]);
        $originalHash = $existing->password;
        $existing->delete();
        $this->assertNotNull($existing->fresh()->deleted_at);

        $importer = app(WordPressUserImporter::class);
        $email = $importer->normalizeEmail(' Soft-Collision@Example.com ');
        $beforeMaps = LegacyImportMap::query()->where('entity_type', 'user')->count();
        $beforeUsers = User::withTrashed()->count();
        $beforeActive = User::query()->count();

        $dryDecision = $importer->classifyImportDecision('3003', $email);
        $persist = $importer->importUserSilently('3003', [
            'email' => ' Soft-Collision@Example.com ',
            'name' => 'Should Not Restore Or Create',
            'phone' => '01000000000',
        ]);

        $existing->refresh();
        $this->assertSame(WordPressUserImporter::OUTCOME_SKIP_EXISTING_EMAIL, $dryDecision);
        $this->assertSame(WordPressUserImporter::OUTCOME_SKIP_EXISTING_EMAIL, $persist['outcome']);
        $this->assertFalse($persist['created']);
        $this->assertNull($persist['map']);
        $this->assertSame($beforeUsers, User::withTrashed()->count());
        $this->assertSame($beforeActive, User::query()->count());
        $this->assertSame($beforeMaps, LegacyImportMap::query()->where('entity_type', 'user')->count());
        $this->assertTrue($existing->trashed());
        $this->assertSame('Soft Deleted Keep', $existing->name);
        $this->assertSame($originalHash, $existing->password);
        $this->assertDatabaseMissing('legacy_import_maps', [
            'entity_type' => 'user',
            'legacy_id' => '3003',
        ]);
        $this->assertSame(0, User::query()->where('email', 'soft-collision@example.com')->count());
        $this->assertSame(1, User::withTrashed()->where('email', 'soft-collision@example.com')->count());
    }

    public function test_dry_run_and_persist_agree_on_new_email_decision(): void
    {
        $importer = app(WordPressUserImporter::class);
        $email = $importer->normalizeEmail('brand-new@example.com');

        $dryDecision = $importer->classifyImportDecision('3002', $email);
        $drySilent = $importer->importUserSilently('3002', [
            'email' => 'brand-new@example.com',
            'name' => 'Brand New',
        ], true);
        $persist = $importer->importUserSilently('3002', [
            'email' => 'brand-new@example.com',
            'name' => 'Brand New',
        ], false);

        $this->assertSame(WordPressUserImporter::OUTCOME_CREATE, $dryDecision);
        $this->assertSame(WordPressUserImporter::OUTCOME_CREATE, $drySilent['outcome']);
        $this->assertFalse($drySilent['created']);
        $this->assertNull($drySilent['map']);
        $this->assertTrue($persist['created']);
        $this->assertSame(WordPressUserImporter::OUTCOME_CREATE, $persist['outcome']);
    }

    public function test_never_copies_wordpress_password_hash(): void
    {
        $importer = app(WordPressUserImporter::class);
        $wpHash = '$P$BNotALaravelHashXXXXXXXXXXXXXX';

        $result = $importer->importUserSilently('4001', [
            'email' => 'hash-safe@example.com',
            'name' => 'Hash Safe',
            // Intentionally unused — importer must ignore WP hashes entirely.
            'user_pass' => $wpHash,
        ]);

        $this->assertTrue($result['created']);
        $this->assertNotSame($wpHash, $result['user']->password);
        // Password is a random Laravel hash, never the WP phpass blob.
        $this->assertStringStartsNotWith('$P$', (string) $result['user']->password);
        $this->assertTrue(strlen((string) $result['user']->password) > 20);
        $this->assertSame('reset_required', $result['map']->metadata['password_strategy'] ?? null);
    }

    public function test_idempotent_mapped_source_does_not_create_another_user(): void
    {
        $importer = app(WordPressUserImporter::class);
        $first = $importer->importUserSilently('5001', [
            'email' => 'once@example.com',
            'name' => 'Once',
        ]);
        $second = $importer->importUserSilently('5001', [
            'email' => 'once@example.com',
            'name' => 'Once Again',
        ]);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(WordPressUserImporter::OUTCOME_SKIP_MAPPED, $second['outcome']);
        $this->assertSame($first['user']->id, $second['user']->id);
        $this->assertSame(1, User::query()->where('email', 'once@example.com')->count());
        $this->assertSame(1, LegacyImportMap::query()->where('entity_type', 'user')->where('legacy_id', '5001')->count());
    }

    public function test_aggregate_fixture_three_existing_emails_among_many_creates(): void
    {
        // Fixture shape mirrors R7: 10 "source" identities, 3 colliding emails → 7 creates.
        User::factory()->create(['email' => 'overlap-a@example.com']);
        User::factory()->create(['email' => 'overlap-b@example.com']);
        User::factory()->create(['email' => 'overlap-c@example.com']);

        $importer = app(WordPressUserImporter::class);
        $sources = [];
        for ($i = 1; $i <= 10; $i++) {
            $sources[] = [
                'legacy_id' => (string) (6000 + $i),
                'email' => match ($i) {
                    2 => 'Overlap-A@example.com',
                    5 => ' overlap-b@example.com ',
                    9 => 'OVERLAP-C@EXAMPLE.COM',
                    default => "fresh-{$i}@example.com",
                },
                'name' => "User {$i}",
            ];
        }

        $wouldCreate = 0;
        $wouldSkipExisting = 0;
        $created = 0;
        $skipExisting = 0;

        foreach ($sources as $source) {
            $email = $importer->normalizeEmail($source['email']);
            $decision = $importer->classifyImportDecision($source['legacy_id'], $email);
            if ($decision === WordPressUserImporter::OUTCOME_CREATE) {
                $wouldCreate++;
            } elseif ($decision === WordPressUserImporter::OUTCOME_SKIP_EXISTING_EMAIL) {
                $wouldSkipExisting++;
            }

            $result = $importer->importUserSilently($source['legacy_id'], [
                'email' => $source['email'],
                'name' => $source['name'],
            ]);
            if ($result['created']) {
                $created++;
            } elseif ($result['outcome'] === WordPressUserImporter::OUTCOME_SKIP_EXISTING_EMAIL) {
                $skipExisting++;
            }
        }

        $this->assertSame(7, $wouldCreate);
        $this->assertSame(3, $wouldSkipExisting);
        $this->assertSame(7, $created);
        $this->assertSame(3, $skipExisting);
        $createdLegacyIds = ['6001', '6003', '6004', '6006', '6007', '6008', '6010'];
        $this->assertSame(
            7,
            LegacyImportMap::query()->where('entity_type', 'user')->whereIn('legacy_id', $createdLegacyIds)->count(),
        );
        $this->assertSame(0, LegacyImportMap::query()->whereIn('legacy_id', ['6002', '6005', '6009'])->count());
    }
}
