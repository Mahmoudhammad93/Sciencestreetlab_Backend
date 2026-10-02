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

    public function __construct(
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressRealPersistGate $persistGate,
        private readonly WordPressConnectionService $connection,
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
        if ($action !== self::ACTION_RESTORE_AND_MAP_EXISTING) {
            return [
                'status' => 'blocked',
                'code' => 'UNSUPPORTED_RESOLUTION_ACTION',
                'legacy_id' => $legacyId,
                'action' => $action,
                'dry_run' => $dryRun,
                'wrote_to_database' => false,
            ];
        }

        return $this->restoreAndMapExisting($legacyId, $entry, $dryRun);
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
