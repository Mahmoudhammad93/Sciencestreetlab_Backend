<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class ShippingRateGroup extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = [
        'code',
        'name',
        'price',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ShippingRateLocation::class);
    }
}
