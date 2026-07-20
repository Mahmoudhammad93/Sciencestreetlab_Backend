<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Persistence\Models;

use App\Modules\Commerce\Domain\Enums\CouponType;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'min_order_amount', 'max_uses',
        'used_count', 'starts_at', 'expires_at', 'is_active', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(float $subtotal): float
    {
        return match ($this->type) {
            CouponType::Fixed => min((float) $this->value, $subtotal),
            CouponType::Percentage => round($subtotal * ((float) $this->value / 100), 2),
            CouponType::FreeShipping => 0,
        };
    }
}
