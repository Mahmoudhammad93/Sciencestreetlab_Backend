<?php

declare(strict_types=1);

namespace App\Modules\Certification\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class CertificateTemplate extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = [
        'slug', 'background_path', 'layout_config', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'layout_config' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
