<?php

declare(strict_types=1);

namespace App\Modules\Migration\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LegacyMigrationRun extends Model
{
    protected $table = 'legacy_migration_runs';

    protected $fillable = [
        'uuid',
        'source',
        'environment',
        'status',
        'notes',
        'started_at',
        'completed_at',
        'rolled_back_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (LegacyMigrationRun $run): void {
            if (empty($run->uuid)) {
                $run->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'notes' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function maps(): HasMany
    {
        return $this->hasMany(LegacyImportMap::class, 'migration_run_id');
    }
}
