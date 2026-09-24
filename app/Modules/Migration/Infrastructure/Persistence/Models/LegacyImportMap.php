<?php

declare(strict_types=1);

namespace App\Modules\Migration\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyImportMap extends Model
{
    protected $table = 'legacy_import_maps';

    protected $fillable = [
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
        ];
    }
}
