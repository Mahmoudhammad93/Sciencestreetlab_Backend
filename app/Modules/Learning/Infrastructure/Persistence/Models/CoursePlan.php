<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

class CoursePlan extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'course_id', 'name', 'description', 'price', 'currency', 'is_active',
        'is_lifetime', 'duration_days', 'max_quiz_attempts', 'grant_certificate', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_lifetime' => 'boolean',
            'grant_certificate' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(CoursePlanEntitlement::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function isFree(): bool
    {
        return (float) $this->price <= 0;
    }

    public function calculateExpiresAt(?\DateTimeInterface $startedAt = null): ?Carbon
    {
        if ($this->is_lifetime || ! $this->duration_days) {
            return null;
        }

        return Carbon::parse($startedAt ?? now())->addDays((int) $this->duration_days);
    }
}
