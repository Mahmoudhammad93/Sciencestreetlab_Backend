<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports WordPress Multisite site-1 users (wp_capabilities) into local users.
 *
 * Password policy: NEVER copy wp_users.user_pass. password_strategy=reset_required.
 * Dry-run writes NOTHING (no users, no legacy_import_maps).
 */
final class WordPressUserImporter
{
    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressRealPersistGate $persistGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(bool $dryRun = false): array
    {
        if ($block = $this->persistGate->importerBlockIfUnauthorized($dryRun, 'user')) {
            return $block;
        }

        $ready = $this->connection->assertReadyForImport('users');
        if (! $ready['ok']) {
            return $this->blockedResult($ready, $dryRun);
        }

        $this->connection->assertProductionPrefix();

        $usersTable = $this->connection->table('users');
        $metaTable = $this->connection->table('usermeta');
        $conn = $this->connection->connectionName();

        $scanned = 0;
        $eligible = 0;
        $wouldCreate = 0;
        $wouldSkipMapped = 0;
        $wouldSkipExistingEmail = 0;
        $skippedNoEmail = 0;
        $created = 0;
        $samples = [];

        // Site 1 membership: usermeta.meta_key = 'wp_capabilities' (not wp_3_capabilities).
        DB::connection($conn)
            ->table($metaTable)
            ->where('meta_key', 'wp_capabilities')
            ->orderBy('umeta_id')
            ->select(['umeta_id', 'user_id'])
            ->chunkById(200, function ($rows) use (
                $dryRun,
                &$scanned,
                &$eligible,
                &$wouldCreate,
                &$wouldSkipMapped,
                &$wouldSkipExistingEmail,
                &$skippedNoEmail,
                &$created,
                &$samples,
                $usersTable,
                $metaTable,
                $conn,
            ): void {
                $userIds = $rows->pluck('user_id')->unique()->filter()->values()->all();
                if ($userIds === []) {
                    return;
                }

                $users = DB::connection($conn)
                    ->table($usersTable)
                    ->whereIn('ID', $userIds)
                    ->where(function ($q): void {
                        $q->whereNull('deleted')->orWhere('deleted', 0);
                    })
                    ->where(function ($q): void {
                        $q->whereNull('spam')->orWhere('spam', 0);
                    })
                    ->get()
                    ->keyBy('ID');

                foreach ($userIds as $userId) {
                    $scanned++;
                    $row = $users->get($userId);
                    if ($row === null) {
                        continue;
                    }

                    $legacyId = (string) $row->ID;
                    $email = strtolower(trim((string) $row->user_email));
                    if ($email === '') {
                        $skippedNoEmail++;

                        continue;
                    }

                    $eligible++;
                    $first = $this->metaValue($conn, $metaTable, (int) $row->ID, 'first_name');
                    $last = $this->metaValue($conn, $metaTable, (int) $row->ID, 'last_name');
                    $name = trim(implode(' ', array_filter([$first, $last])))
                        ?: ((string) $row->display_name !== '' ? (string) $row->display_name : (string) $row->user_login);

                    $attrs = [
                        'email' => $email,
                        'name' => $name,
                        'phone' => $this->metaValue($conn, $metaTable, (int) $row->ID, 'phone')
                            ?? $this->metaValue($conn, $metaTable, (int) $row->ID, 'mobile_number'),
                    ];

                    if ($dryRun) {
                        if ($this->maps->find('user', $legacyId)?->local_id) {
                            $wouldSkipMapped++;
                        } elseif (User::query()->where('email', $email)->exists()) {
                            $wouldSkipExistingEmail++;
                        } else {
                            $wouldCreate++;
                            if (count($samples) < 5) {
                                $samples[] = [
                                    'legacy_id' => $legacyId,
                                    'email' => $email,
                                    'name' => $name,
                                    'password_strategy' => 'reset_required',
                                ];
                            }
                        }

                        continue;
                    }

                    $result = $this->importUserSilently($legacyId, $attrs, false);
                    if ($result['created']) {
                        $created++;
                    } else {
                        $wouldSkipMapped++;
                    }
                }
            }, 'umeta_id');

        return [
            'status' => 'ok',
            'entity_type' => 'user',
            'dry_run' => $dryRun,
            'membership_rule' => 'usermeta.meta_key=wp_capabilities (Multisite site 1 / sciencestreetlab.com)',
            'password_strategy' => 'reset_required',
            'scanned' => $scanned,
            'eligible' => $eligible,
            'would_create' => $wouldCreate,
            'would_skip_mapped' => $wouldSkipMapped,
            'would_skip_existing_email' => $wouldSkipExistingEmail,
            'skipped_no_email' => $skippedNoEmail,
            'created' => $dryRun ? 0 : $created,
            'imported' => $dryRun ? 0 : $created,
            'skipped' => $wouldSkipMapped + $wouldSkipExistingEmail + $skippedNoEmail,
            'samples' => $samples,
            'wrote_to_database' => ! $dryRun && $created > 0,
            'inspect' => $ready['inspect'],
        ];
    }

    /**
     * @param  array{name?: string, email: string, phone?: string|null}  $attributes
     * @return array{user: User, map: \App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap|null, created: bool}
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
                'map' => null,
                'created' => false,
            ];
        }

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
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'password_strategy' => 'reset_required',
                'historical_import' => true,
            ]),
        ]);

        return ['user' => $user, 'map' => $map, 'created' => true];
    }

    private function metaValue(string $connection, string $metaTable, int $userId, string $key): ?string
    {
        $value = DB::connection($connection)
            ->table($metaTable)
            ->where('user_id', $userId)
            ->where('meta_key', $key)
            ->value('meta_value');

        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * @param  array{ok: bool, reason: string|null, inspect: array<string, mixed>}  $ready
     * @return array<string, mixed>
     */
    private function blockedResult(array $ready, bool $dryRun): array
    {
        return [
            'status' => 'blocked',
            'code' => $ready['reason'] ?? WordPressConnectionService::BLOCKED,
            'entity_type' => 'user',
            'dry_run' => $dryRun,
            'imported' => 0,
            'skipped' => 0,
            'created' => 0,
            'wrote_to_database' => false,
            'message' => 'WordPress user import blocked: legacy connection not ready.',
            'inspect' => $ready['inspect'],
        ];
    }
}
