<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    protected $fillable = [
        'product_id',
        'user_id',
        'is_legacy_guest',
        'guest_display_name',
        'is_verified_purchase',
        'rating',
        'review',
        'status',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ProductReviewStatus::class,
            'approved_at' => 'datetime',
            'is_legacy_guest' => 'boolean',
            'is_verified_purchase' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === ProductReviewStatus::Approved;
    }

    public function publicReviewerName(): string
    {
        if ($this->user?->name) {
            return (string) $this->user->name;
        }

        $guest = trim((string) ($this->guest_display_name ?? ''));

        return $guest !== '' ? $guest : 'Customer';
    }
}
