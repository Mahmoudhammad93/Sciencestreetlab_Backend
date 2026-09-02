<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CoursePlanEntitlement extends Model
{
    protected $fillable = [
        'course_plan_id', 'entitleable_type', 'entitleable_id',
    ];

    public function coursePlan(): BelongsTo
    {
        return $this->belongsTo(CoursePlan::class);
    }

    public function entitleable(): MorphTo
    {
        return $this->morphTo();
    }
}
