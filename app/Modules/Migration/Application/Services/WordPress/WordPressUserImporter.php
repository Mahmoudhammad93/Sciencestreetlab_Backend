<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Imports WordPress users into local users.
 *
 * Password strategy: WordPress password hashes are NEVER copied into users.password.
 * Imported users receive a random unusable password and metadata
 * password_strategy=reset_required. Users must reset via forgot-password / OTP.
 *
 * Source SQL beyond core wp_users probes is BLOCKED_UNTIL_WORDPRESS_DB_DUMP.
 */
final class WordPressUserImporter
{
    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(bool $dryRun = false): array
    {
        $ready = $this->connection->assertReadyForImport('users');

        return $this->blockedResult($ready, $dryRun, 'user');
    }

    /**
     * Create a local user for a historical import without copying WP hashes.
     * Intended for use once source rows are mapped from a verified dump.
     *
     * @param  array{name?: string, email: string, phone?: string|null}  $attributes
     * @return array{user: User, map: \App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap, created: bool}
     */
    public function importUserSilently(string $legacyId, array $attributes, bool $dryRun = false): array
    {
        $existing = $this->maps->find('user', $legacyId);
        if ($existing?->local_id) {
            $user = User::query()->find($existing->local_id);
            if ($user !== null) {
                return ['user' => $user, 'map' => $existing, 'created' => false];
            }
        }

        if ($dryRun) {
            $placeholder = new User([
                'name' => $attributes['name'] ?? $attributes['email'],
                'email' => $attributes['email'],
            ]);

            return [
                'user' => $placeholder,
                'map' => $existing ?? $this->maps->findOrCreate('user', $legacyId, [
                    'legacy_email' => $attributes['email'],
                    'metadata' => ['dry_run' => true, 'password_strategy' => 'reset_required'],
                ]),
                'created' => false,
            ];
        }

        // NEVER import WP user_pass / password hashes — random unusable password.
        // User::$casts hashes via 'hashed'; do not Hash::make here.
        $user = User::query()->create([
            'name' => $attributes['name'] ?? $attributes['email'],
            'email' => $attributes['email'],
            'phone' => $attributes['phone'] ?? null,
            'password' => Str::password(64),
            'is_active' => true,
        ]);

        $map = $this->maps->upsertMapping('user', $legacyId, [
            'local_id' => $user->id,
            'legacy_email' => $attributes['email'],
            'imported_at' => now(),
            'metadata' => [
                'password_strategy' => 'reset_required',
                'historical_import' => true,
            ],
        ]);

        return ['user' => $user, 'map' => $map, 'created' => true];
    }

    /**
     * @param  array{ok: bool, reason: string|null, inspect: array<string, mixed>}  $ready
     * @return array<string, mixed>
     */
    private function blockedResult(array $ready, bool $dryRun, string $entity): array
    {
        return [
            'status' => 'blocked',
            'code' => $ready['reason'] ?? WordPressConnectionService::BLOCKED,
            'entity_type' => $entity,
            'dry_run' => $dryRun,
            'imported' => 0,
            'skipped' => 0,
            'created' => 0,
            'message' => 'WordPress user import is BLOCKED_UNTIL_WORDPRESS_DB_DUMP. Password strategy when unblocked: reset_required (WP hashes are not imported; users must reset password / use OTP).',
            'inspect' => $ready['inspect'],
        ];
    }
}
