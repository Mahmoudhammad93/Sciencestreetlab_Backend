<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tracks WordPress→Laravel migration batches for safe staging rollback.
 *
 * Rollback deletes ONLY local rows that were created_by_migration for a given run.
 * Maps with mapped_to_existing=true lose the map row only — never the business record.
 */
final class MigrationRunService
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public function __construct(
        private readonly ?WordPressApprovedCollisionMapper $approvedCollisions = null,
    ) {}

    public function start(string $environment = 'staging', array $notes = []): LegacyMigrationRun
    {
        $run = LegacyMigrationRun::query()->create([
            'source' => LegacyImportMapRepository::SOURCE_WORDPRESS,
            'environment' => $environment,
            'status' => self::STATUS_RUNNING,
            'notes' => $notes,
            'started_at' => now(),
        ]);

        ActiveMigrationRun::set($run);

        return $run;
    }

    /**
     * Bind a persisted running migration run into this process (cross-process safe).
     */
    public function bindRunning(int $runId): LegacyMigrationRun
    {
        $run = LegacyMigrationRun::query()->find($runId);
        if ($run === null) {
            throw new RuntimeException("Migration run {$runId} not found.");
        }

        if ($run->source !== LegacyImportMapRepository::SOURCE_WORDPRESS) {
            throw new RuntimeException("Migration run {$runId} is not a wordpress run.");
        }

        if ($run->status !== self::STATUS_RUNNING) {
            throw new RuntimeException(
                "Migration run {$runId} is not active (status={$run->status}). Only running runs may receive imports."
            );
        }

        ActiveMigrationRun::set($run);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    public function statusReport(int $runId): array
    {
        $run = LegacyMigrationRun::query()->find($runId);
        if ($run === null) {
            throw new RuntimeException("Migration run {$runId} not found.");
        }

        $maps = LegacyImportMap::query()->where('migration_run_id', $run->id)->get();
        $byType = [];
        $created = 0;
        $mappedExisting = 0;
        foreach ($maps as $map) {
            /** @var LegacyImportMap $map */
            $byType[$map->entity_type] = ($byType[$map->entity_type] ?? 0) + 1;
            if ($map->wasCreatedByMigration()) {
                $created++;
            } elseif ($map->wasMappedToExisting()) {
                $mappedExisting++;
            }
        }

        return [
            'id' => $run->id,
            'uuid' => $run->uuid,
            'source' => $run->source,
            'environment' => $run->environment,
            'status' => $run->status,
            'started_at' => optional($run->started_at)?->toIso8601String(),
            'completed_at' => optional($run->completed_at)?->toIso8601String(),
            'rolled_back_at' => optional($run->rolled_back_at)?->toIso8601String(),
            'notes' => $run->notes,
            'map_rows' => $maps->count(),
            'map_counts_by_entity' => $byType,
            'ownership' => [
                'created_by_migration' => $created,
                'mapped_to_existing' => $mappedExisting,
            ],
            'wrote_to_database' => false,
        ];
    }

    public function complete(LegacyMigrationRun $run): LegacyMigrationRun
    {
        if ($run->status !== self::STATUS_RUNNING) {
            throw new RuntimeException(
                "Cannot complete migration run {$run->id}: status is {$run->status}, expected running."
            );
        }

        $run->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        if (ActiveMigrationRun::id() === $run->id) {
            ActiveMigrationRun::clear();
        }

        return $run->fresh() ?? $run;
    }

    /**
     * Roll back a completed/running WordPress migration run.
     *
     * - created_by_migration maps → delete/soft-delete local entity, then remove map
     * - mapped_to_existing maps → remove map only (NEVER delete local business row)
     *
     * @return array<string, mixed>
     */
    public function rollback(LegacyMigrationRun $run, bool $dryRun = true): array
    {
        if ($run->source !== LegacyImportMapRepository::SOURCE_WORDPRESS) {
            throw new RuntimeException('Only wordpress migration runs can be rolled back by this service.');
        }

        $maps = LegacyImportMap::query()
            ->where('migration_run_id', $run->id)
            ->orderByDesc('id')
            ->get();

        $wouldDelete = [];
        $wouldUnmapOnly = [];
        foreach ($maps->groupBy('entity_type') as $type => $group) {
            foreach ($group as $map) {
                /** @var LegacyImportMap $map */
                if ($map->local_id === null) {
                    continue;
                }
                if ($map->wasCreatedByMigration() && ! $this->isAuthoritativeCompetitionGraphProtected($map)) {
                    $wouldDelete[$type][] = (int) $map->local_id;
                } else {
                    $wouldUnmapOnly[$type][] = (int) $map->local_id;
                }
            }
            if (isset($wouldDelete[$type])) {
                $wouldDelete[$type] = array_values(array_unique($wouldDelete[$type]));
            }
            if (isset($wouldUnmapOnly[$type])) {
                $wouldUnmapOnly[$type] = array_values(array_unique($wouldUnmapOnly[$type]));
            }
        }

        if ($dryRun) {
            return [
                'status' => 'dry_run',
                'run_id' => $run->id,
                'run_uuid' => $run->uuid,
                'would_delete_local_ids' => $wouldDelete,
                'would_unmap_only_local_ids' => $wouldUnmapOnly,
                'map_rows' => $maps->count(),
                'wrote_to_database' => false,
            ];
        }

        DB::transaction(function () use ($run, $maps): void {
            $priority = [
                'competition_submission',
                'competition_participant',
                'competition',
                'product_review',
                'order',
                'enrollment',
                'product',
                'category',
                'topic',
                'lesson',
                'course',
                'user',
            ];

            $byType = $maps->groupBy('entity_type');
            foreach ($priority as $type) {
                foreach ($byType->get($type, collect()) as $map) {
                    /** @var LegacyImportMap $map */
                    if ($map->local_id === null) {
                        continue;
                    }
                    // Critical: never delete pre-existing Laravel rows linked via MAP_EXISTING
                    // or C-A authoritative competition (+ participant/submission graph).
                    if (! $map->wasCreatedByMigration() || $this->isAuthoritativeCompetitionGraphProtected($map)) {
                        continue;
                    }
                    $this->deleteLocalIfExists($type, (int) $map->local_id);
                }
            }

            LegacyImportMap::query()->where('migration_run_id', $run->id)->delete();
            $run->update([
                'status' => self::STATUS_ROLLED_BACK,
                'rolled_back_at' => now(),
            ]);
        });

        if (ActiveMigrationRun::id() === $run->id) {
            ActiveMigrationRun::clear();
        }

        return [
            'status' => 'rolled_back',
            'run_id' => $run->id,
            'run_uuid' => $run->uuid,
            'deleted_local_ids' => $wouldDelete,
            'unmapped_only_local_ids' => $wouldUnmapOnly,
            'wrote_to_database' => true,
        ];
    }

    private function deleteLocalIfExists(string $entityType, int $localId): void
    {
        $table = match ($entityType) {
            'user' => 'users',
            'product' => 'products',
            'category' => 'categories',
            'course' => 'courses',
            'lesson' => 'lessons',
            'topic' => 'topics',
            'enrollment' => 'enrollments',
            'order' => 'orders',
            'product_review' => 'product_reviews',
            'competition' => 'competitions',
            'competition_participant' => 'competition_participants',
            'competition_submission' => 'competition_submissions',
            default => null,
        };

        if ($table === null) {
            return;
        }

        // Soft-delete aware tables use deleted_at; forceDelete only for migration-owned rows.
        if (in_array($table, ['users', 'products', 'courses'], true)) {
            DB::table($table)->where('id', $localId)->update(['deleted_at' => now()]);
        } else {
            DB::table($table)->where('id', $localId)->delete();
        }
    }

    /**
     * C-A: never delete accepted AQ competition id or its imported participant/submission graph.
     */
    private function isAuthoritativeCompetitionGraphProtected(LegacyImportMap $map): bool
    {
        $mapper = $this->approvedCollisions ?? app(WordPressApprovedCollisionMapper::class);

        if ($map->entity_type === 'competition' && $map->local_id !== null) {
            return $mapper->isAuthoritativeAcceptedCompetitionLocalId((int) $map->local_id);
        }

        if (! in_array($map->entity_type, ['competition_participant', 'competition_submission'], true)) {
            return false;
        }

        $meta = is_array($map->metadata) ? $map->metadata : [];
        $competitionLocalId = isset($meta['competition_local_id']) ? (int) $meta['competition_local_id'] : 0;
        if ($competitionLocalId > 0 && $mapper->protectsCompetitionParticipantSubmissionGraph($competitionLocalId)) {
            return true;
        }

        if ($map->entity_type === 'competition_participant' && $map->local_id !== null) {
            $compId = (int) (DB::table('competition_participants')->where('id', $map->local_id)->value('competition_id') ?? 0);
            if ($compId > 0 && $mapper->protectsCompetitionParticipantSubmissionGraph($compId)) {
                return true;
            }
        }

        if ($map->entity_type === 'competition_submission' && $map->local_id !== null) {
            $participantId = (int) (DB::table('competition_submissions')->where('id', $map->local_id)->value('participant_id') ?? 0);
            if ($participantId > 0) {
                $compId = (int) (DB::table('competition_participants')->where('id', $participantId)->value('competition_id') ?? 0);
                if ($compId > 0 && $mapper->protectsCompetitionParticipantSubmissionGraph($compId)) {
                    return true;
                }
            }
        }

        return false;
    }
}
