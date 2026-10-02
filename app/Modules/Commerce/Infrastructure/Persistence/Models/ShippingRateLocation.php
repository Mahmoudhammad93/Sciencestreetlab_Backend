<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Persistence\Models;

use App\Modules\Commerce\Domain\Enums\ShippingLocationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRateLocation extends Model
{
    protected $fillable = [
        'shipping_rate_group_id',
        'scope_type',
        'bosta_location_id',
        'bosta_location_name',
        'bosta_location_name_ar',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ShippingLocationScope::class,
            'is_active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ShippingRateGroup::class, 'shipping_rate_group_id');
    }
}
