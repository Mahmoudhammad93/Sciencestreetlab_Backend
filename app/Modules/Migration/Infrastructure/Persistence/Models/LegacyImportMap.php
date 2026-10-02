<?php

declare(strict_types=1);

namespace App\Modules\Migration\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyImportMap extends Model
{
    protected $table = 'legacy_import_maps';

    protected $fillable = [
        'migration_run_id',
        'source',
        'entity_type',
        'legacy_id',
        'local_id',
        'legacy_email',
        'checksum',
        'metadata',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'imported_at' => 'datetime',
            'local_id' => 'integer',
            'migration_run_id' => 'integer',
        ];
    }

    /**
     * True when rollback may soft/hard-delete the local business row.
     *
     * Prefers explicit metadata.created_by_migration. Falls back to collision
     * markers so MAP_EXISTING maps never delete pre-seeded catalog/AQ shells.
     *
     * C-A: authoritative accepted competitions (and explicit unmap-only policies)
     * are never treated as migration-deletable even if historical metadata still
     * says created_by_migration=true.
     */
    public function wasCreatedByMigration(): bool
    {
        if ($this->isRollbackProtected()) {
            return false;
        }

        $meta = is_array($this->metadata) ? $this->metadata : [];

        if (array_key_exists('created_by_migration', $meta)) {
            return (bool) $meta['created_by_migration'];
        }

        if ($this->wasMappedToExisting()) {
            return false;
        }

        // Historical create maps without the flag: eligible when a local_id exists.
        return $this->local_id !== null;
    }

    /**
     * Unmap-only / authoritative rows must survive migration rollback deletes.
     */
    public function isRollbackProtected(): bool
    {
        if ($this->wasMappedToExisting()) {
            return true;
        }

        $meta = is_array($this->metadata) ? $this->metadata : [];
        $policy = $meta['rollback_policy'] ?? null;
        if (is_string($policy) && str_starts_with($policy, 'unmap_only')) {
            return true;
        }

        if (($meta['authoritative_accepted'] ?? false) === true) {
            return true;
        }

        if ($this->entity_type === 'competition' && $this->local_id !== null) {
            $entries = config('wordpress.approved_map_existing.competitions', []);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    if (! ($entry['authoritative_accepted'] ?? false)) {
                        continue;
                    }
                    $approvedId = isset($entry['local_id']) ? (int) $entry['local_id'] : 0;
                    if ($approvedId > 0 && $approvedId === (int) $this->local_id) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * True when the map only bridges to a pre-existing Laravel record.
     */
    public function wasMappedToExisting(): bool
    {
        $meta = is_array($this->metadata) ? $this->metadata : [];

        if (array_key_exists('mapped_to_existing', $meta)) {
            return (bool) $meta['mapped_to_existing'];
        }

        $decision = $meta['collision_decision'] ?? $meta['collision'] ?? null;

        return in_array($decision, [
            'MAP_EXISTING',
            'SAFE_MATCH',
            'EXISTING_USER_COURSE_PAIR',
            'EXISTING_PARTICIPANT_PAIR',
        ], true);
    }
}
