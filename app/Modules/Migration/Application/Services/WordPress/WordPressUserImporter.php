<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports WordPress Multisite site-1 users (wp_capabilities) into local users.
 *
 * Also includes users who hold LearnDash course_*_access_from / AQ enrollment
 * evidence even when they only have a non-site-1 capabilities key (e.g. wp_3_*).
 *
 * Password policy: NEVER copy wp_users.user_pass. password_strategy=reset_required.
 * Dry-run writes NOTHING (no users, no legacy_import_maps).
 *
 * Dry-run and real persist share classifyImportDecision() so email decisions
 * cannot diverge between prediction and mutation.
 */
final class WordPressUserImporter
{
    public const OUTCOME_CREATE = 'create';

    public const OUTCOME_SKIP_MAPPED = 'skip_mapped';

    /** @deprecated Prefer OUTCOME_MAP_EXISTING_EMAIL; kept for report compatibility. */
    public const OUTCOME_SKIP_EXISTING_EMAIL = 'skip_existing_email';

    public const OUTCOME_MAP_EXISTING_EMAIL = 'map_existing_email';

    public const OUTCOME_AMBIGUOUS_SOFT_DELETED_EMAIL = 'ambiguous_soft_deleted_email';

    public const OUTCOME_SKIP_NO_EMAIL = 'skip_no_email';

    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressRealPersistGate $persistGate,
    ) {}

    /**
     * Normalize source/destination emails identically for dry-run and persist.
     */
    public function normalizeEmail(?string $email): string
    {
        return self::normalizeEmailStatic($email);
    }

    public static function normalizeEmailStatic(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    /**
     * Shared collision decision for dry-run prediction and real persist.
     *
     * - Exact legacy map wins.
     * - Active Laravel user with same normalized email → SAFE_MATCH_EXISTING (map only).
     * - Soft-deleted Laravel email match → AMBIGUOUS (no map, no create, no restore).
     * - Otherwise CREATE (never copies WP password hashes).
     */
    public function classifyImportDecision(string $legacyId, string $normalizedEmail): string
    {
        if ($normalizedEmail === '') {
            return self::OUTCOME_SKIP_NO_EMAIL;
        }

        if ($this->maps->find('user', $legacyId)?->local_id) {
            return self::OUTCOME_SKIP_MAPPED;
        }

        $existing = User::withTrashed()->where('email', $normalizedEmail)->first();
        if ($existing !== null) {
            if ($existing->trashed()) {
                return self::OUTCOME_AMBIGUOUS_SOFT_DELETED_EMAIL;
            }

            return self::OUTCOME_MAP_EXISTING_EMAIL;
        }

        return self::OUTCOME_CREATE;
    }

    /**
     * Classify a legacy WP user for access/competition resolution reports.
     *
     * @return array{
     *     legacy_id: string,
     *     classification: string,
     *     outcome: string|null,
     *     wp_exists: bool,
     *     site1_capabilities: bool,
     *     access_bearer: bool,
     *     laravel_local_id: int|null,
     *     soft_deleted_match: bool
     * }
     */
    public function classifyLegacyUser(string $legacyId): array
    {
        $conn = $this->connection->connectionName();
        $usersTable = $this->connection->table('users');
        $metaTable = $this->connection->table('usermeta');

        $row = DB::connection($conn)->table($usersTable)->where('ID', (int) $legacyId)->first();
        $site1 = DB::connection($conn)->table($metaTable)
            ->where('user_id', (int) $legacyId)
            ->where('meta_key', 'wp_capabilities')
            ->exists();
        $accessBearer = DB::connection($conn)->table($metaTable)
            ->where('user_id', (int) $legacyId)
            ->where('meta_key', 'like', 'course_%_access_from')
            ->exists();

        if ($row === null) {
            return [
                'legacy_id' => $legacyId,
                'classification' => 'SOURCE_ORPHAN',
                'outcome' => null,
                'wp_exists' => false,
                'site1_capabilities' => false,
                'access_bearer' => $accessBearer,
                'laravel_local_id' => null,
                'soft_deleted_match' => false,
            ];
        }

        $email = $this->normalizeEmail((string) ($row->user_email ?? ''));
        $outcome = $this->classifyImportDecision($legacyId, $email);
        $existing = $email !== ''
            ? User::withTrashed()->where('email', $email)->first()
            : null;

        $classification = match ($outcome) {
            self::OUTCOME_SKIP_MAPPED,
            self::OUTCOME_MAP_EXISTING_EMAIL => 'SAFE_MATCH_EXISTING',
            self::OUTCOME_CREATE => 'SAFE_CREATE_NEW',
            self::OUTCOME_AMBIGUOUS_SOFT_DELETED_EMAIL => 'AMBIGUOUS_REQUIRES_HUMAN_DECISION',
            self::OUTCOME_SKIP_NO_EMAIL => 'AMBIGUOUS_REQUIRES_HUMAN_DECISION',
            default => 'AMBIGUOUS_REQUIRES_HUMAN_DECISION',
        };

        return [
            'legacy_id' => $legacyId,
            'classification' => $classification,
            'outcome' => $outcome,
            'wp_exists' => true,
            'site1_capabilities' => $site1,
            'access_bearer' => $accessBearer,
            'laravel_local_id' => $existing && ! $existing->trashed() ? (int) $existing->id : null,
            'soft_deleted_match' => (bool) ($existing?->trashed()),
        ];
    }

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
        $wouldMapExistingEmail = 0;
        $wouldAmbiguousSoftDeleted = 0;
        $skippedNoEmail = 0;
        $created = 0;
        $skippedMapped = 0;
        $mappedExistingEmail = 0;
        $ambiguousSoftDeleted = 0;
        $failed = 0;
        $samples = [];
        $accessBearerExtras = 0;

        $userIds = $this->eligibleLegacyUserIds($conn, $metaTable);
        foreach (array_chunk($userIds, 200) as $chunk) {
            $users = DB::connection($conn)
                ->table($usersTable)
                ->whereIn('ID', $chunk)
                ->where(function ($q): void {
                    $q->whereNull('deleted')->orWhere('deleted', 0);
                })
                ->where(function ($q): void {
                    $q->whereNull('spam')->orWhere('spam', 0);
                })
                ->get()
                ->keyBy('ID');

            foreach ($chunk as $userId) {
                $scanned++;
                $row = $users->get($userId);
                if ($row === null) {
                    continue;
                }

                $legacyId = (string) $row->ID;
                $email = $this->normalizeEmail((string) $row->user_email);
                $decision = $this->classifyImportDecision($legacyId, $email);

                $hasSite1 = DB::connection($conn)->table($metaTable)
                    ->where('user_id', (int) $userId)
                    ->where('meta_key', 'wp_capabilities')
                    ->exists();
                if (! $hasSite1) {
                    $accessBearerExtras++;
                }

                if ($decision === self::OUTCOME_SKIP_NO_EMAIL) {
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
                    match ($decision) {
                        self::OUTCOME_SKIP_MAPPED => $wouldSkipMapped++,
                        self::OUTCOME_MAP_EXISTING_EMAIL => $wouldMapExistingEmail++,
                        self::OUTCOME_AMBIGUOUS_SOFT_DELETED_EMAIL => $wouldAmbiguousSoftDeleted++,
                        default => $wouldCreate++,
                    };
                    if ($decision === self::OUTCOME_CREATE && count($samples) < 5) {
                        $samples[] = [
                            'legacy_id' => $legacyId,
                            'email' => $email,
                            'name' => $name,
                            'password_strategy' => 'reset_required',
                            'predicted_outcome' => 'would_create',
                        ];
                    }

                    continue;
                }

                try {
                    $result = $this->importUserSilently($legacyId, $attrs, false);
                    match ($result['outcome']) {
                        self::OUTCOME_CREATE => $created++,
                        self::OUTCOME_SKIP_MAPPED => $skippedMapped++,
                        self::OUTCOME_MAP_EXISTING_EMAIL => $mappedExistingEmail++,
                        self::OUTCOME_AMBIGUOUS_SOFT_DELETED_EMAIL => $ambiguousSoftDeleted++,
                        self::OUTCOME_SKIP_EXISTING_EMAIL => $ambiguousSoftDeleted++,
                        default => $failed++,
                    };
                } catch (QueryException $e) {
                    if ($this->isUniqueEmailViolation($e)) {
                        // Race: treat as map-existing if active user now exists.
                        $existing = User::query()->where('email', $email)->first();
                        if ($existing !== null) {
                            $this->maps->upsertMapping('user', $legacyId, [
                                'local_id' => $existing->id,
                                'legacy_email' => $email,
                                'imported_at' => now(),
                                'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                                    'match' => 'normalized_email',
                                    'password_strategy' => 'unchanged_destination',
                                    'historical_import' => true,
                                    'race_unique_email' => true,
                                ]),
                            ]);
                            $mappedExistingEmail++;

                            continue;
                        }
                        $ambiguousSoftDeleted++;

                        continue;
                    }
                    throw $e;
                }
            }
        }

        return [
            'status' => 'ok',
            'entity_type' => 'user',
            'dry_run' => $dryRun,
            'membership_rule' => 'wp_capabilities (site 1) OR course_%_access_from / aq_enrollments bearer',
            'scanned' => $scanned,
            'eligible' => $eligible,
            'would_create' => $dryRun ? $wouldCreate : 0,
            'would_skip_mapped' => $dryRun ? $wouldSkipMapped : 0,
            'would_map_existing_email' => $dryRun ? $wouldMapExistingEmail : 0,
            'would_ambiguous_soft_deleted_email' => $dryRun ? $wouldAmbiguousSoftDeleted : 0,
            // Backward-compatible alias: dry-run "would_skip_existing_email" now means map-existing.
            'would_skip_existing_email' => $dryRun ? $wouldMapExistingEmail : 0,
            'skipped_no_email' => $skippedNoEmail,
            'created' => $dryRun ? 0 : $created,
            'skip_mapped' => $dryRun ? 0 : $skippedMapped,
            'map_existing_email' => $dryRun ? 0 : $mappedExistingEmail,
            'ambiguous_soft_deleted_email' => $dryRun ? 0 : $ambiguousSoftDeleted,
            'skip_existing_email' => $dryRun ? 0 : $mappedExistingEmail,
            'failed' => $dryRun ? 0 : $failed,
            'access_bearer_extras_scanned' => $accessBearerExtras,
            'password_policy' => 'never_copy_wp_user_pass; create=reset_required; map_existing=unchanged_destination',
            'samples' => $samples,
            'wrote_to_database' => ! $dryRun && ($created > 0 || $mappedExistingEmail > 0),
            'inspect' => $ready['inspect'],
        ];
    }

    /**
     * Eligible legacy user IDs: site-1 members plus access/competition bearers.
     *
     * @return list<int>
     */
    public function eligibleLegacyUserIds(string $connection, string $metaTable): array
    {
        $site1 = DB::connection($connection)
            ->table($metaTable)
            ->where('meta_key', 'wp_capabilities')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $accessBearers = DB::connection($connection)
            ->table($metaTable)
            ->where('meta_key', 'like', 'course_%_access_from')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $aqBearers = [];
        try {
            $aqBearers = DB::connection($connection)
                ->table($this->connection->table('aq_enrollments'))
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable) {
            $aqBearers = [];
        }

        $ids = array_values(array_unique(array_filter(
            array_merge($site1, $accessBearers, $aqBearers),
            fn (int $id) => $id > 0,
        )));
        sort($ids);

        return $ids;
    }

    /**
     * @param  array{name?: string, email: string, phone?: string|null}  $attributes
     * @return array{
     *     user: User|null,
     *     map: \App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap|null,
     *     created: bool,
     *     outcome: string
     * }
     */
    public function importUserSilently(string $legacyId, array $attributes, bool $dryRun = false): array
    {
        $email = $this->normalizeEmail($attributes['email'] ?? null);
        $decision = $this->classifyImportDecision($legacyId, $email);

        if ($decision === self::OUTCOME_SKIP_MAPPED) {
            $existing = $this->maps->find('user', $legacyId);
            $user = $existing?->local_id ? User::query()->find($existing->local_id) : null;

            return [
                'user' => $user ?? new User(['email' => $email, 'name' => $attributes['name'] ?? $email]),
                'map' => $existing,
                'created' => false,
                'outcome' => self::OUTCOME_SKIP_MAPPED,
            ];
        }

        if ($decision === self::OUTCOME_SKIP_NO_EMAIL) {
            return [
                'user' => new User([
                    'name' => $attributes['name'] ?? $email,
                    'email' => $email,
                ]),
                'map' => null,
                'created' => false,
                'outcome' => self::OUTCOME_SKIP_NO_EMAIL,
            ];
        }

        if ($decision === self::OUTCOME_AMBIGUOUS_SOFT_DELETED_EMAIL) {
            $existingUser = User::withTrashed()->where('email', $email)->first();

            return [
                'user' => $existingUser,
                'map' => null,
                'created' => false,
                'outcome' => self::OUTCOME_AMBIGUOUS_SOFT_DELETED_EMAIL,
            ];
        }

        if ($decision === self::OUTCOME_MAP_EXISTING_EMAIL) {
            $existingUser = User::query()->where('email', $email)->first();
            if ($existingUser === null) {
                // Should not happen; fall through as ambiguous.
                return [
                    'user' => null,
                    'map' => null,
                    'created' => false,
                    'outcome' => self::OUTCOME_AMBIGUOUS_SOFT_DELETED_EMAIL,
                ];
            }

            if ($dryRun) {
                return [
                    'user' => $existingUser,
                    'map' => null,
                    'created' => false,
                    'outcome' => self::OUTCOME_MAP_EXISTING_EMAIL,
                ];
            }

            $map = $this->maps->upsertMapping('user', $legacyId, [
                'local_id' => $existingUser->id,
                'legacy_email' => $email,
                'imported_at' => now(),
                'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                    'match' => 'normalized_email',
                    'password_strategy' => 'unchanged_destination',
                    'historical_import' => true,
                    'overwrite' => false,
                ]),
            ]);

            return [
                'user' => $existingUser,
                'map' => $map,
                'created' => false,
                'outcome' => self::OUTCOME_MAP_EXISTING_EMAIL,
            ];
        }

        if ($dryRun) {
            $placeholder = new User([
                'name' => $attributes['name'] ?? $email,
                'email' => $email,
            ]);

            return [
                'user' => $placeholder,
                'map' => null,
                'created' => false,
                'outcome' => self::OUTCOME_CREATE,
            ];
        }

        $user = User::query()->create([
            'name' => $attributes['name'] ?? $email,
            'email' => $email,
            'phone' => $attributes['phone'] ?? null,
            'password' => Str::password(64),
            'is_active' => true,
        ]);

        $map = $this->maps->upsertMapping('user', $legacyId, [
            'local_id' => $user->id,
            'legacy_email' => $email,
            'imported_at' => now(),
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'password_strategy' => 'reset_required',
                'historical_import' => true,
            ]),
        ]);

        return [
            'user' => $user,
            'map' => $map,
            'created' => true,
            'outcome' => self::OUTCOME_CREATE,
        ];
    }

    private function isUniqueEmailViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'users_email_unique')
            || (str_contains($message, 'unique') && str_contains($message, 'email'));
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
            'wrote_to_database' => false,
            'inspect' => $ready['inspect'],
        ];
    }
}
