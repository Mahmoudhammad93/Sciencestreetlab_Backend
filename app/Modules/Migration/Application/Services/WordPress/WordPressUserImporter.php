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
 * Password policy: NEVER copy wp_users.user_pass. password_strategy=reset_required.
 * Dry-run writes NOTHING (no users, no legacy_import_maps).
 *
 * Dry-run and real persist share classifyImportDecision() so existing-email
 * collisions cannot diverge between prediction and mutation.
 */
final class WordPressUserImporter
{
    public const OUTCOME_CREATE = 'create';

    public const OUTCOME_SKIP_MAPPED = 'skip_mapped';

    public const OUTCOME_SKIP_EXISTING_EMAIL = 'skip_existing_email';

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
        return strtolower(trim((string) $email));
    }

    /**
     * Shared collision decision for dry-run prediction and real persist.
     *
     * Existing destination emails are SKIPPED (no User::create, no ownership map).
     */
    public function classifyImportDecision(string $legacyId, string $normalizedEmail): string
    {
        if ($normalizedEmail === '') {
            return self::OUTCOME_SKIP_NO_EMAIL;
        }

        if ($this->maps->find('user', $legacyId)?->local_id) {
            return self::OUTCOME_SKIP_MAPPED;
        }

        if (User::query()->where('email', $normalizedEmail)->exists()) {
            return self::OUTCOME_SKIP_EXISTING_EMAIL;
        }

        return self::OUTCOME_CREATE;
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
        $wouldSkipExistingEmail = 0;
        $skippedNoEmail = 0;
        $created = 0;
        $skippedMapped = 0;
        $skippedExistingEmail = 0;
        $failed = 0;
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
                &$skippedMapped,
                &$skippedExistingEmail,
                &$failed,
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
                    $email = $this->normalizeEmail((string) $row->user_email);
                    $decision = $this->classifyImportDecision($legacyId, $email);

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
                        if ($decision === self::OUTCOME_SKIP_MAPPED) {
                            $wouldSkipMapped++;
                        } elseif ($decision === self::OUTCOME_SKIP_EXISTING_EMAIL) {
                            $wouldSkipExistingEmail++;
                        } else {
                            $wouldCreate++;
                            if (count($samples) < 5) {
                                $samples[] = [
                                    'legacy_id' => $legacyId,
                                    'email' => $email,
                                    'name' => $name,
                                    'password_strategy' => 'reset_required',
                                    'predicted_outcome' => 'would_create',
                                ];
                            }
                        }

                        continue;
                    }

                    try {
                        $result = $this->importUserSilently($legacyId, $attrs, false);
                        match ($result['outcome']) {
                            self::OUTCOME_CREATE => $created++,
                            self::OUTCOME_SKIP_MAPPED => $skippedMapped++,
                            self::OUTCOME_SKIP_EXISTING_EMAIL => $skippedExistingEmail++,
                            default => $failed++,
                        };
                    } catch (QueryException $e) {
                        // Race: another request created the email after classify.
                        if ($this->isUniqueEmailViolation($e)) {
                            $skippedExistingEmail++;

                            continue;
                        }
                        // No outer transaction: earlier users in this run stay committed.
                        throw $e;
                    }
                }
            }, 'umeta_id');

        $skippedMappedTotal = $dryRun ? $wouldSkipMapped : $skippedMapped;
        $skippedExistingTotal = $dryRun ? $wouldSkipExistingEmail : $skippedExistingEmail;

        return [
            'status' => 'ok',
            'entity_type' => 'user',
            'dry_run' => $dryRun,
            'membership_rule' => 'usermeta.meta_key=wp_capabilities (Multisite site 1 / sciencestreetlab.com)',
            'password_strategy' => 'reset_required',
            'existing_email_policy' => 'SKIP_EXISTING_EMAIL',
            'existing_email_map_policy' => 'NO_MAP_FOR_SKIPPED_EXISTING_EMAIL',
            'scanned' => $scanned,
            'eligible' => $eligible,
            'would_create' => $wouldCreate,
            'would_skip_mapped' => $wouldSkipMapped,
            'would_skip_existing_email' => $wouldSkipExistingEmail,
            'skipped_no_email' => $skippedNoEmail,
            'created' => $dryRun ? 0 : $created,
            'imported' => $dryRun ? 0 : $created,
            'skipped_mapped' => $dryRun ? 0 : $skippedMapped,
            'skip_existing_email' => $dryRun ? 0 : $skippedExistingEmail,
            'failed' => $dryRun ? 0 : $failed,
            'skipped' => $skippedMappedTotal + $skippedExistingTotal + $skippedNoEmail,
            'samples' => $samples,
            'wrote_to_database' => ! $dryRun && $created > 0,
            'inspect' => $ready['inspect'],
        ];
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

        if ($decision === self::OUTCOME_SKIP_EXISTING_EMAIL || $decision === self::OUTCOME_SKIP_NO_EMAIL) {
            $existingUser = $email !== ''
                ? User::query()->where('email', $email)->first()
                : null;

            return [
                'user' => $existingUser ?? new User([
                    'name' => $attributes['name'] ?? $email,
                    'email' => $email,
                ]),
                'map' => null,
                'created' => false,
                'outcome' => $decision === self::OUTCOME_SKIP_NO_EMAIL
                    ? self::OUTCOME_SKIP_NO_EMAIL
                    : self::OUTCOME_SKIP_EXISTING_EMAIL,
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
            'imported' => 0,
            'skipped' => 0,
            'created' => 0,
            'wrote_to_database' => false,
            'message' => 'WordPress user import blocked: legacy connection not ready.',
            'inspect' => $ready['inspect'],
        ];
    }
}
