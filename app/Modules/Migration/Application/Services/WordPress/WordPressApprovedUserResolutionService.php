<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Models\User;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Narrow, auditable approved legacy-user resolutions.
 *
 * Generic soft-deleted email collisions remain AMBIGUOUS and are never restored
 * by the normal WordPressUserImporter. Only config
 * wordpress.approved_user_resolutions may authorize restore + MAP_EXISTING.
 */
final class WordPressApprovedUserResolutionService
{
    public const ACTION_RESTORE_AND_MAP_EXISTING = 'RESTORE_AND_MAP_EXISTING';

    public const ACTION_MAP_EXISTING = 'MAP_EXISTING';

    public const ACTION_CREATE = 'CREATE';

    public function __construct(
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressRealPersistGate $persistGate,
        private readonly WordPressConnectionService $connection,
        private readonly WordPressUserImporter $userImporter,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function approvedEntries(): array
    {
        $entries = config('wordpress.approved_user_resolutions', []);

        return is_array($entries) ? array_values($entries) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function approvedEntry(string $legacyId): ?array
    {
        foreach ($this->approvedEntries() as $entry) {
            if ((string) ($entry['legacy_id'] ?? '') === $legacyId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Execute (or dry-run) an approved user resolution.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $legacyId, bool $dryRun = true): array
    {
        if ($block = $this->persistGate->importerBlockIfUnauthorized($dryRun, 'approved_user_resolution')) {
            return $block;
        }

        $entry = $this->approvedEntry($legacyId);
        if ($entry === null) {
            return [
                'status' => 'blocked',
                'code' => 'NO_APPROVED_RESOLUTION',
                'legacy_id' => $legacyId,
                'dry_run' => $dryRun,
                'wrote_to_database' => false,
                'message' => 'No approved_user_resolutions entry for this legacy id.',
            ];
        }

        $action = (string) ($entry['action'] ?? '');

        return match ($action) {
            self::ACTION_RESTORE_AND_MAP_EXISTING => $this->restoreAndMapExisting($legacyId, $entry, $dryRun),
            self::ACTION_MAP_EXISTING => $this->mapExisting($legacyId, $entry, $dryRun),
            self::ACTION_CREATE => $this->createMigratedUser($legacyId, $entry, $dryRun),
            default => [
                'status' => 'blocked',
                'code' => 'UNSUPPORTED_RESOLUTION_ACTION',
                'legacy_id' => $legacyId,
                'action' => $action,
                'dry_run' => $dryRun,
                'wrote_to_database' => false,
            ],
        };
    }

    /**
     * Active Laravel account map-only (e.g. WP27 → local 12). Never restore/create/overwrite.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function mapExisting(string $legacyId, array $entry, bool $dryRun): array
    {
        $expectedLocalId = (int) ($entry['local_user_id'] ?? 0);
        if ($expectedLocalId <= 0) {
            return $this->blocked($legacyId, 'INVALID_APPROVED_LOCAL_USER_ID', $dryRun);
        }

        $wpUser = $this->fetchWpUser($legacyId);
        if ($wpUser === null) {
            return $this->blocked($legacyId, 'WP_USER_MISSING', $dryRun, [
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        $normalizedEmail = WordPressUserImporter::normalizeEmailStatic((string) ($wpUser->user_email ?? ''));
        if ($normalizedEmail === '') {
            return $this->blocked($legacyId, 'WP_EMAIL_MISSING', $dryRun);
        }

        $target = User::query()->find($expectedLocalId);
        if ($target === null) {
            $trashed = User::onlyTrashed()->find($expectedLocalId);
            if ($trashed !== null) {
                return $this->blocked($legacyId, 'LOCAL_USER_SOFT_DELETED_USE_RESTORE_ACTION', $dryRun, [
                    'expected_local_user_id' => $expectedLocalId,
                ]);
            }

            return $this->blocked($legacyId, 'LOCAL_USER_MISSING', $dryRun, [
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        $targetEmail = WordPressUserImporter::normalizeEmailStatic((string) $target->email);
        if ($targetEmail !== $normalizedEmail) {
            return $this->blocked($legacyId, 'IDENTITY_EMAIL_MISMATCH', $dryRun, [
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        $activeDup = User::query()->where('email', $normalizedEmail)->where('id', '!=', $expectedLocalId)->exists();
        if ($activeDup) {
            return $this->blocked($legacyId, 'ACTIVE_DUPLICATE_EMAIL', $dryRun, [
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        $softDup = User::onlyTrashed()->where('email', $normalizedEmail)->exists();
        if ($softDup) {
            return $this->blocked($legacyId, 'SOFT_DELETED_EMAIL_COLLISION', $dryRun, [
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        $decision = $this->userImporter->classifyImportDecision($legacyId, $normalizedEmail);
        if ($decision === WordPressUserImporter::OUTCOME_SKIP_MAPPED) {
            $existingMap = $this->maps->find('user', $legacyId);
            if ($existingMap?->local_id && (int) $existingMap->local_id === $expectedLocalId) {
                return [
                    'status' => 'ok',
                    'code' => 'ALREADY_RESOLVED',
                    'legacy_id' => $legacyId,
                    'local_user_id' => $expectedLocalId,
                    'action' => self::ACTION_MAP_EXISTING,
                    'approval' => $entry['approval'] ?? null,
                    'dry_run' => $dryRun,
                    'wrote_to_database' => false,
                    'would_create_user' => false,
                    'would_map' => false,
                    'mapped_to_existing' => true,
                    'created_by_migration' => false,
                    'password_copied' => false,
                    'profile_overwritten' => false,
                ];
            }

            return $this->blocked($legacyId, 'EXISTING_MAP_POINTS_ELSEWHERE', $dryRun, [
                'mapped_local_id' => $existingMap?->local_id,
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        if ($decision !== WordPressUserImporter::OUTCOME_MAP_EXISTING_EMAIL) {
            return $this->blocked($legacyId, 'IMPORTER_DECISION_NOT_MAP_EXISTING', $dryRun, [
                'importer_decision' => $decision,
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        $originalPassword = (string) $target->password;
        $originalName = (string) $target->name;
        $originalEmail = (string) $target->email;

        $plan = [
            'status' => 'ok',
            'code' => $dryRun ? 'WOULD_MAP_EXISTING' : 'MAPPED_EXISTING',
            'legacy_id' => $legacyId,
            'local_user_id' => $expectedLocalId,
            'action' => self::ACTION_MAP_EXISTING,
            'approval' => $entry['approval'] ?? null,
            'dry_run' => $dryRun,
            'wrote_to_database' => false,
            'would_create_user' => false,
            'would_map' => true,
            'would_restore' => false,
            'mapped_to_existing' => true,
            'created_by_migration' => false,
            'password_copied' => false,
            'profile_overwritten' => false,
            'never_create_user' => true,
        ];

        if ($dryRun) {
            return $plan;
        }

        $result = $this->userImporter->importUserSilently($legacyId, [
            'email' => $normalizedEmail,
            'name' => (string) ($wpUser->display_name ?: $wpUser->user_login),
        ], false);

        $target->refresh();
        if ((string) $target->password !== $originalPassword
            || (string) $target->name !== $originalName
            || (string) $target->email !== $originalEmail) {
            throw new \RuntimeException('Approved MAP_EXISTING must not alter password/profile.');
        }

        if ($result['outcome'] !== WordPressUserImporter::OUTCOME_MAP_EXISTING_EMAIL
            || (int) ($result['user']?->id ?? 0) !== $expectedLocalId) {
            return $this->blocked($legacyId, 'MAP_EXISTING_UNEXPECTED_OUTCOME', $dryRun, [
                'outcome' => $result['outcome'] ?? null,
                'result_local_id' => $result['user']?->id,
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        // Enrich map metadata with approval provenance (map already written by importer).
        $this->maps->upsertMapping('user', $legacyId, [
            'local_id' => $expectedLocalId,
            'legacy_email' => $originalEmail,
            'imported_at' => now(),
            'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                'match' => 'normalized_email',
                'collision_decision' => self::ACTION_MAP_EXISTING,
                'approval' => $entry['approval'] ?? null,
                'approval_source' => 'config:wordpress.approved_user_resolutions',
                'legacy_wordpress_id' => $legacyId,
                'local_user_id' => $expectedLocalId,
                'password_strategy' => 'unchanged_destination',
                'password_copied' => false,
                'profile_overwritten' => false,
                'historical_import' => true,
                'overwrite' => false,
                'rollback_policy' => 'unmap_only_never_delete_user',
            ]),
        ]);

        $plan['wrote_to_database'] = true;
        $plan['map_local_id'] = $expectedLocalId;
        $plan['outcome'] = $result['outcome'];

        return $plan;
    }

    /**
     * Create exactly one migrated Laravel user (e.g. WP1641 / WP1642). Never copies WP password.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function createMigratedUser(string $legacyId, array $entry, bool $dryRun): array
    {
        $wpUser = $this->fetchWpUser($legacyId);
        if ($wpUser === null) {
            return $this->blocked($legacyId, 'WP_USER_MISSING', $dryRun);
        }

        $normalizedEmail = WordPressUserImporter::normalizeEmailStatic((string) ($wpUser->user_email ?? ''));
        if ($normalizedEmail === '') {
            return $this->blocked($legacyId, 'WP_EMAIL_MISSING', $dryRun);
        }

        $existingMap = $this->maps->find('user', $legacyId);
        if ($existingMap?->local_id) {
            $user = User::query()->find($existingMap->local_id);

            return [
                'status' => 'ok',
                'code' => 'ALREADY_RESOLVED',
                'legacy_id' => $legacyId,
                'local_user_id' => (int) $existingMap->local_id,
                'action' => self::ACTION_CREATE,
                'approval' => $entry['approval'] ?? null,
                'dry_run' => $dryRun,
                'wrote_to_database' => false,
                'would_create_user' => false,
                'created' => false,
                'mapped_to_existing' => false,
                'created_by_migration' => true,
                'password_copied' => false,
                'user_exists' => $user !== null,
            ];
        }

        $decision = $this->userImporter->classifyImportDecision($legacyId, $normalizedEmail);
        if ($decision !== WordPressUserImporter::OUTCOME_CREATE) {
            return $this->blocked($legacyId, 'IMPORTER_DECISION_NOT_CREATE', $dryRun, [
                'importer_decision' => $decision,
            ]);
        }

        if (User::withTrashed()->where('email', $normalizedEmail)->exists()) {
            return $this->blocked($legacyId, 'EMAIL_COLLISION_REFUSES_CREATE', $dryRun);
        }

        $conn = $this->connection->connectionName();
        $metaTable = $this->connection->table('usermeta');
        $first = $this->metaValue($conn, $metaTable, (int) $legacyId, 'first_name');
        $last = $this->metaValue($conn, $metaTable, (int) $legacyId, 'last_name');
        $name = trim(implode(' ', array_filter([$first, $last])))
            ?: ((string) $wpUser->display_name !== '' ? (string) $wpUser->display_name : (string) $wpUser->user_login);
        $phone = $this->metaValue($conn, $metaTable, (int) $legacyId, 'phone')
            ?? $this->metaValue($conn, $metaTable, (int) $legacyId, 'mobile_number');

        $plan = [
            'status' => 'ok',
            'code' => $dryRun ? 'WOULD_CREATE' : 'CREATED',
            'legacy_id' => $legacyId,
            'action' => self::ACTION_CREATE,
            'approval' => $entry['approval'] ?? null,
            'dry_run' => $dryRun,
            'wrote_to_database' => false,
            'would_create_user' => true,
            'created' => false,
            'mapped_to_existing' => false,
            'created_by_migration' => true,
            'password_copied' => false,
            'password_strategy' => 'reset_required',
            'profile_from_wordpress_name_only' => true,
        ];

        if ($dryRun) {
            return $plan;
        }

        $result = $this->userImporter->importUserSilently($legacyId, [
            'email' => $normalizedEmail,
            'name' => $name,
            'phone' => $phone,
        ], false);

        if ($result['outcome'] !== WordPressUserImporter::OUTCOME_CREATE || ! ($result['created'] ?? false)) {
            return $this->blocked($legacyId, 'CREATE_UNEXPECTED_OUTCOME', false, [
                'outcome' => $result['outcome'] ?? null,
            ]);
        }

        $localId = (int) $result['user']->id;

        // Enrich map metadata with approval provenance.
        $this->maps->upsertMapping('user', $legacyId, [
            'local_id' => $localId,
            'legacy_email' => $normalizedEmail,
            'imported_at' => now(),
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'password_strategy' => 'reset_required',
                'historical_import' => true,
                'approval' => $entry['approval'] ?? null,
                'approval_source' => 'config:wordpress.approved_user_resolutions',
                'legacy_wordpress_id' => $legacyId,
                'collision_decision' => self::ACTION_CREATE,
                'password_copied' => false,
            ]),
        ]);

        $plan['wrote_to_database'] = true;
        $plan['created'] = true;
        $plan['local_user_id'] = $localId;
        $plan['would_create_user'] = false;

        return $plan;
    }

    private function fetchWpUser(string $legacyId): ?object
    {
        $conn = $this->connection->connectionName();
        $usersTable = $this->connection->table('users');

        return DB::connection($conn)->table($usersTable)->where('ID', (int) $legacyId)->first();
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
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function restoreAndMapExisting(string $legacyId, array $entry, bool $dryRun): array
    {
        $expectedLocalId = (int) ($entry['local_user_id'] ?? 0);
        if ($expectedLocalId <= 0) {
            return $this->blocked($legacyId, 'INVALID_APPROVED_LOCAL_USER_ID', $dryRun);
        }

        $conn = $this->connection->connectionName();
        $usersTable = $this->connection->table('users');
        $wpUser = DB::connection($conn)->table($usersTable)->where('ID', (int) $legacyId)->first();
        if ($wpUser === null) {
            return $this->blocked($legacyId, 'WP_USER_MISSING', $dryRun, [
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        $normalizedEmail = WordPressUserImporter::normalizeEmailStatic((string) ($wpUser->user_email ?? ''));
        if ($normalizedEmail === '') {
            return $this->blocked($legacyId, 'WP_EMAIL_MISSING', $dryRun);
        }

        $target = User::withTrashed()->find($expectedLocalId);
        if ($target === null) {
            return $this->blocked($legacyId, 'LOCAL_USER_MISSING', $dryRun, [
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        if ((int) $target->id !== $expectedLocalId) {
            return $this->blocked($legacyId, 'LOCAL_USER_ID_MISMATCH', $dryRun);
        }

        $targetEmail = WordPressUserImporter::normalizeEmailStatic((string) $target->email);
        if ($targetEmail !== $normalizedEmail) {
            return $this->blocked($legacyId, 'IDENTITY_EMAIL_MISMATCH', $dryRun, [
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        $activeDup = User::query()->where('email', $normalizedEmail)->where('id', '!=', $expectedLocalId)->exists();
        if ($activeDup) {
            return $this->blocked($legacyId, 'ACTIVE_DUPLICATE_EMAIL', $dryRun, [
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        $existingMap = $this->maps->find('user', $legacyId);
        if ($existingMap?->local_id && (int) $existingMap->local_id !== $expectedLocalId) {
            return $this->blocked($legacyId, 'EXISTING_MAP_POINTS_ELSEWHERE', $dryRun, [
                'mapped_local_id' => (int) $existingMap->local_id,
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        $alreadyMappedCorrectly = $existingMap?->local_id && (int) $existingMap->local_id === $expectedLocalId;
        $requiresSoftDeleted = (bool) ($entry['requires_soft_deleted'] ?? true);

        if ($alreadyMappedCorrectly && ! $target->trashed()) {
            $tokens = $this->tokenCount($target);

            return [
                'status' => 'ok',
                'code' => 'ALREADY_RESOLVED',
                'legacy_id' => $legacyId,
                'local_user_id' => $expectedLocalId,
                'action' => self::ACTION_RESTORE_AND_MAP_EXISTING,
                'approval' => $entry['approval'] ?? null,
                'dry_run' => $dryRun,
                'wrote_to_database' => false,
                'would_revoke_tokens' => 0,
                'would_restore' => false,
                'would_map' => false,
                'would_create_user' => false,
                'tokens_present' => $tokens,
                'user_trashed' => false,
                'mapped_to_existing' => true,
                'created_by_migration' => false,
                'password_copied' => false,
                'profile_overwritten' => false,
            ];
        }

        if ($requiresSoftDeleted && ! $target->trashed() && ! $alreadyMappedCorrectly) {
            return $this->blocked($legacyId, 'LOCAL_USER_NOT_SOFT_DELETED', $dryRun, [
                'expected_local_user_id' => $expectedLocalId,
            ]);
        }

        $tokenCount = $this->tokenCount($target);
        $originalPassword = (string) $target->password;
        $originalName = (string) $target->name;
        $originalEmail = (string) $target->email;

        $plan = [
            'status' => 'ok',
            'code' => $dryRun ? 'WOULD_RESTORE_AND_MAP' : 'RESTORED_AND_MAPPED',
            'legacy_id' => $legacyId,
            'local_user_id' => $expectedLocalId,
            'action' => self::ACTION_RESTORE_AND_MAP_EXISTING,
            'approval' => $entry['approval'] ?? null,
            'dry_run' => $dryRun,
            'wrote_to_database' => false,
            'would_revoke_tokens' => $tokenCount,
            'would_restore' => $target->trashed(),
            'would_map' => ! $alreadyMappedCorrectly,
            'would_create_user' => false,
            'tokens_present' => $tokenCount,
            'user_trashed' => $target->trashed(),
            'mapped_to_existing' => true,
            'created_by_migration' => false,
            'password_copied' => false,
            'profile_overwritten' => false,
            'never_create_user' => true,
        ];

        if ($dryRun) {
            return $plan;
        }

        DB::transaction(function () use (
            $target,
            $legacyId,
            $entry,
            $expectedLocalId,
            $alreadyMappedCorrectly,
            $originalPassword,
            $originalName,
            $originalEmail,
        ): void {
            // Revoke tokens before/with restore.
            $target->tokens()->delete();

            if ($target->trashed()) {
                $target->restore();
            }

            $target->refresh();
            // Never overwrite password/profile from WordPress; assert unchanged.
            if ((string) $target->password !== $originalPassword
                || (string) $target->name !== $originalName
                || (string) $target->email !== $originalEmail) {
                throw new \RuntimeException('Approved user resolution must not alter password/profile.');
            }

            if (! $alreadyMappedCorrectly) {
                $this->maps->upsertMapping('user', $legacyId, [
                    'local_id' => $expectedLocalId,
                    'legacy_email' => $originalEmail,
                    'imported_at' => now(),
                    'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                        'collision_decision' => 'RESTORE_AND_MAP_EXISTING',
                        'approval' => $entry['approval'] ?? null,
                        'approval_source' => 'config:wordpress.approved_user_resolutions',
                        'legacy_wordpress_id' => $legacyId,
                        'local_user_id' => $expectedLocalId,
                        'tokens_revoked' => true,
                        'password_copied' => false,
                        'profile_overwritten' => false,
                        'rollback_policy' => 'unmap_only_never_delete_user',
                    ]),
                ]);
            }
        });

        $map = $this->maps->find('user', $legacyId);
        $fresh = User::query()->find($expectedLocalId);

        $plan['wrote_to_database'] = true;
        $plan['user_trashed'] = $fresh?->trashed() ?? false;
        $plan['tokens_present'] = $fresh ? $this->tokenCount($fresh) : 0;
        $plan['map_local_id'] = $map?->local_id;
        $plan['map_metadata'] = $map?->metadata;

        return $plan;
    }

    private function tokenCount(User $user): int
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function blocked(string $legacyId, string $code, bool $dryRun, array $extra = []): array
    {
        return array_merge([
            'status' => 'blocked',
            'code' => $code,
            'legacy_id' => $legacyId,
            'dry_run' => $dryRun,
            'wrote_to_database' => false,
            'would_create_user' => false,
            'password_copied' => false,
        ], $extra);
    }
}
