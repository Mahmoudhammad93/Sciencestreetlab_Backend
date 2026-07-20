<?php

declare(strict_types=1);

namespace App\Modules\Content\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Page extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['title', 'content', 'meta'];

    protected $fillable = [
        'slug', 'template', 'is_published', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
