<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EnrollmentEntitlement extends Model
{
    protected $fillable = [
        'enrollment_id', 'entitleable_type', 'entitleable_id',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function entitleable(): MorphTo
    {
        return $this->morphTo();
    }
}
